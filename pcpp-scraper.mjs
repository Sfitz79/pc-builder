import fs from 'fs';
import path from 'path';
import sharp from 'sharp';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.join(__dirname, 'src', 'data');
const THUMB_DIR = path.join(__dirname, 'public', 'thumbnails');
fs.mkdirSync(THUMB_DIR, { recursive: true });

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

function parseCSVLine(line) {
  const values = [];
  let current = '', inQuotes = false;
  for (const char of line) {
    if (char === '"') inQuotes = !inQuotes;
    else if (char === ',' && !inQuotes) { values.push(current); current = ''; }
    else current += char;
  }
  values.push(current);
  return values;
}

export function sanitizeFilename(name) {
  return name.replace(/[^a-z0-9]/gi, '_').toLowerCase().substring(0, 55);
}

// Source: PCPartPicker UK search - this is the most likely source of product images
async function searchPCPP(name, category) {
  const catMap = {
    'cpu': 'cpu', 'cooler': 'cpu-cooler', 'motherboard': 'motherboard', 'ram': 'memory',
    'storage': 'internal-hard-drive', 'gpu': 'video-card', 'case': 'case', 'power-supply': 'power-supply',
    'case-fan': 'case-fan', 'monitor': 'monitor', 'keyboard': 'keyboard', 'mouse': 'mouse',
    'headphones': 'headphones', 'speakers': 'speakers', 'webcam': 'webcam',
    'os': 'os', 'wired-network-card': 'wired-network-card', 'wireless-network-card': 'wireless-network-card',
    'optical-drive': 'optical-drive', 'sound-card': 'sound-card', 'ups': 'ups',
    'case-accessory': 'case-accessory', 'fan-controller': 'fan-controller',
    'thermal-paste': 'thermal-paste', 'external-hard-drive': 'external-hard-drive',
  };
  const pcppCat = catMap[category] || category;

  // Construct search URL for PCPP UK
  const searchUrl = `https://uk.pcpartpicker.com/products/${pcppCat}/?search=${encodeURIComponent(name)}`;

  try {
    const resp = await fetch(searchUrl, {
      signal: AbortSignal.timeout(15000),
      headers: {
        'User-Agent': UA,
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language': 'en-GB,en;q=0.9',
        'Cookie': 'region=uk; currency=gbp',
      },
    });
    if (!resp.ok) return null;
    const html = await resp.text();

    // PCPP shows product images as img tags with src pointing to their CDN
    // Look for product table image URLs
    const patterns = [
      /<img[^>]+src="(https?:\/\/cdna\.pcpartpicker\.com\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/gi,
      /<img[^>]+data-src="(https?:\/\/cdna\.pcpartpicker\.com\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/gi,
      /(https?:\/\/cdna\.pcpartpicker\.com\/static\/forever\/images\/product\/[a-f0-9]+\.\w+\.(?:jpg|jpeg|png|webp))/gi,
    ];

    for (const pat of patterns) {
      const matches = [...html.matchAll(pat)];
      for (const m of matches) {
        let u = m[1] || m[0];
        if (u.length > 40 && u.length < 500 && !u.includes('pixel') && !u.includes('logo')) {
          return u;
        }
      }
    }

    // Try to find product links and get the first result's page
    const linkMatch = html.match(/href="(\/product\/[^"]+)"[^>]*>/gi);
    if (linkMatch && linkMatch.length > 0) {
      const firstLink = linkMatch[0].match(/href="(\/product\/[^"]+)"/);
      if (firstLink) {
        const productUrl = `https://uk.pcpartpicker.com${firstLink[1]}`;
        const prodResp = await fetch(productUrl, {
          signal: AbortSignal.timeout(15000),
          headers: { 'User-Agent': UA, 'Accept': 'text/html,*/*', 'Cookie': 'region=uk; currency=gbp' },
        });
        if (prodResp.ok) {
          const prodHtml = await prodResp.text();
          const imgMatch = prodHtml.match(/<img[^>]+src="(https?:\/\/cdna\.pcpartpicker\.com\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/i);
          if (imgMatch) return imgMatch[1];
        }
      }
    }
  } catch {}
  return null;
}

async function downloadImage(url, dest) {
  try {
    const resp = await fetch(url, {
      signal: AbortSignal.timeout(15000),
      headers: { 'User-Agent': UA, 'Accept': 'image/webp,image/apng,image/*,*/*' },
      redirect: 'follow',
    });
    if (!resp.ok) return false;
    const buf = Buffer.from(await resp.arrayBuffer());
    if (buf.length < 4096) return false;
    const meta = await sharp(buf).metadata();
    if (meta.width < 100 || meta.height < 100) return false;
    await sharp(buf).jpeg({ quality: 85 }).toFile(dest);
    return true;
  } catch { return false; }
}

async function processCSV(csvFile, batchSize = 200, maxBatches = null) {
  const csvPath = path.join(DATA_DIR, csvFile);
  if (!fs.existsSync(csvPath)) return { total: 0, done: 0, failed: 0 };

  const content = fs.readFileSync(csvPath, 'utf-8');
  const rawLines = content.split('\n');
  const lines = rawLines.map(l => l.trim()).filter(l => l);
  if (lines.length < 2) return { total: 0, done: 0, failed: 0 };

  const header = parseCSVLine(lines[0]);
  const imgIdx = header.indexOf('image');
  const nameIdx = header.indexOf('name');
  if (imgIdx === -1 || nameIdx === -1) {
    console.log(`  SKIP ${csvFile}: no image or name column`);
    return { total: 0, done: 0, failed: 0 };
  }

  const category = csvFile.replace('.csv', '');
  const allItems = [];

  for (let i = 1; i < lines.length; i++) {
    const parts = parseCSVLine(lines[i]);
    const name = (parts[nameIdx] || '').replace(/^"|"$/g, '').trim();
    if (!name) continue;
    const existing = (parts[imgIdx] || '').trim();

    // Skip if already has a valid thumbnail
    if (existing.startsWith('thumbnails/')) {
      const thumbFile = existing.replace('thumbnails/', '');
      if (fs.existsSync(path.join(THUMB_DIR, thumbFile))) continue;
    }
    // Skip if has a PCPP URL (these are fine)
    if (existing.startsWith('http')) continue;

    allItems.push({ i, name });
  }

  console.log(`  ${csvFile}: ${allItems.length} products need images`);

  let totalDone = 0, totalFailed = 0;
  let batchCount = 0;

  for (let start = 0; start < allItems.length; start += batchSize) {
    batchCount++;
    if (maxBatches && batchCount > maxBatches) {
      console.log(`    Reached max ${maxBatches} batches, stopping`);
      break;
    }

    const batch = allItems.slice(start, start + batchSize);
    console.log(`    Batch ${batchCount}: items ${start + 1}-${start + batch.length}...`);

    let done = 0, failed = 0;

    for (const item of batch) {
      const sanitized = sanitizeFilename(item.name);
      const dest = path.join(THUMB_DIR, `${category}_${sanitized}.jpg`);

      // Check if already downloaded from a previous run
      if (fs.existsSync(dest)) {
        // Update CSV
        const p = parseCSVLine(lines[item.i]);
        p[imgIdx] = `thumbnails/${category}_${sanitized}.jpg`;
        lines[item.i] = p.join(',');
        done++;
        continue;
      }

      // Try PCPP search
      const url = await searchPCPP(item.name, category);
      if (url && await downloadImage(url, dest)) {
        const p = parseCSVLine(lines[item.i]);
        p[imgIdx] = `thumbnails/${category}_${sanitized}.jpg`;
        lines[item.i] = p.join(',');
        done++;
        process.stdout.write('.');
      } else {
        failed++;
        process.stdout.write('x');
      }

      await new Promise(r => setTimeout(r, 2000 + Math.random() * 2000));
    }

    // Save progress after each batch
    fs.writeFileSync(csvPath, lines.join('\n'), 'utf-8');
    console.log(`\n    Batch ${batchCount}: ${done} OK, ${failed} FAIL`);

    totalDone += done;
    totalFailed += failed;

    // 1 minute cooldown between batches to avoid rate limiting
    if (start + batchSize < allItems.length && (!maxBatches || batchCount < maxBatches)) {
      console.log('    Cooling down 60s...');
      await new Promise(r => setTimeout(r, 60000));
    }
  }

  return { total: allItems.length, done: totalDone, failed: totalFailed };
}

async function main() {
  const batchSize = parseInt(process.argv[2] || '200');
  const maxBatches = process.argv[3] ? parseInt(process.argv[3]) : null;
  const singleCategory = process.argv[4] || null;

  console.log(`=== PCPP Image Scraper ===`);
  console.log(`Batch size: ${batchSize}, Max batches: ${maxBatches || 'unlimited'}\n`);

  const categories = singleCategory
    ? [singleCategory]
    : [
        'case-fan.csv',   // 2121 missing + 67 broken
        'case.csv',       // 6626 missing
        'power-supply.csv', // 379 missing
        'cpu.csv',        // 14 missing
        'gpu.csv',        // 2 missing
        'ram.csv',        // 11 missing
        'storage.csv',    // 8 missing
        'motherboard.csv', // 5 missing
      ];

  const start = Date.now();
  let grandTotal = 0, grandDone = 0;

  for (const cat of categories) {
    console.log(`\n--- ${cat} ---`);
    const r = await processCSV(cat, batchSize, maxBatches);
    grandTotal += r.total;
    grandDone += r.done;
    console.log(`  Result: ${r.done} OK, ${r.failed} FAIL (${r.total} total needed)`);
  }

  const elapsed = ((Date.now() - start) / 1000 / 60).toFixed(1);
  const totalFiles = fs.readdirSync(THUMB_DIR).filter(f => /\.(jpg|jpeg|png|webp)$/i.test(f)).length;
  console.log(`\n=== DONE in ${elapsed} min ===`);
  console.log(`Found images: ${grandDone}/${grandTotal}`);
  console.log(`Total thumbnails on disk: ${totalFiles}`);
}

main().catch(e => { console.error(e); process.exit(1); });
