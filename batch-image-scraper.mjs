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

// Source 1: Amazon UK product search
async function searchAmazon(name) {
  const queries = [
    `https://www.amazon.co.uk/s?k=${encodeURIComponent(name.replace(/[^a-z0-9 ]/gi, ' '))}&rh=n%3A430329031`,
    `https://www.amazon.co.uk/s?k=${encodeURIComponent(name.replace(/[^a-z0-9 ]/gi, ' ').split(' ').slice(0, 6).join(' '))}`,
  ];

  for (const url of queries) {
    try {
      const resp = await fetch(url, {
        signal: AbortSignal.timeout(10000),
        headers: {
          'User-Agent': UA,
          'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
          'Accept-Language': 'en-GB,en;q=0.9',
        },
        redirect: 'follow',
      });
      if (!resp.ok) continue;
      const html = await resp.text();

      // Amazon product images: m.media-amazon.com/images/I/*.jpg
      const imgRe = /src="(https:\/\/m\.media-amazon\.com\/images\/I\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/gi;
      const matches = [...html.matchAll(imgRe)];

      for (const m of matches) {
        let u = m[1];
        // Remove size suffix like _AC_UL320_ to get full size
        u = u.replace(/\._AC_UL\d+_/, '.')
             .replace(/\._AC_SX\d+_/, '.')
             .replace(/\._SY\d+_/, '.')
             .replace(/\._SX\d+_/, '.');
        // Exclude UI/logos
        if (!u.includes('images/G/') && !u.includes('images/S/') && !u.includes('nav-sprite') && !u.includes('gno/sprites')) {
          return u;
        }
      }
    } catch {}
  }
  return null;
}

// Source 2: Newegg product search
async function searchNewegg(name) {
  try {
    const url = `https://www.newegg.com/p/pl?d=${encodeURIComponent(name.replace(/[^a-z0-9 ]/gi, ' ').split(' ').slice(0, 5).join(' '))}`;
    const resp = await fetch(url, {
      signal: AbortSignal.timeout(10000),
      headers: { 'User-Agent': UA, 'Accept': 'text/html,*/*' },
      redirect: 'follow',
    });
    if (!resp.ok) return null;
    const html = await resp.text();

    // Newegg product images
    const imgRe = /src="(https:\/\/c\d+\.neweggimages\.com\/productimage\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/gi;
    const matches = [...html.matchAll(imgRe)];
    for (const m of matches) {
      return m[1]; // Take first product image
    }
  } catch {}
  return null;
}

// Source 3: eBay UK
async function searchEbay(name) {
  try {
    const url = `https://www.ebay.co.uk/sch/i.html?_nkw=${encodeURIComponent(name.replace(/[^a-z0-9 ]/gi, ' ').split(' ').slice(0, 6).join(' '))}`;
    const resp = await fetch(url, {
      signal: AbortSignal.timeout(10000),
      headers: { 'User-Agent': UA, 'Accept': 'text/html,*/*' },
      redirect: 'follow',
    });
    if (!resp.ok) return null;
    const html = await resp.text();

    // eBay product images
    const imgRe = /src="(https:\/\/i\.ebayimg\.com\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/gi;
    const matches = [...html.matchAll(imgRe)];
    for (const m of matches) {
      const u = m[1];
      if (u.includes('images/g') || u.includes('1x1')) continue;
      return u;
    }
  } catch {}
  return null;
}

export async function downloadImage(url, dest) {
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

export async function findImage(name) {
  const sources = [
    { name: 'Amazon', fn: () => searchAmazon(name) },
    { name: 'eBay', fn: () => searchEbay(name) },
    { name: 'Newegg', fn: () => searchNewegg(name) },
  ];

  for (const src of sources) {
    try {
      const url = await src.fn();
      if (url) {
        console.error(`    [${src.name}] ${url.slice(0, 120)}`);
        return url;
      }
    } catch {}
  }
  return null;
}

export function generatePlaceholder(name, category, dest) {
  // Generate a simple colored placeholder with product name
  const sanitized = sanitizeFilename(name);
  const text = `${category}: ${name}`.slice(0, 50);
  // SVG placeholder
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="240" height="140">
    <rect width="240" height="140" fill="#1a1a2e"/>
    <text x="120" y="70" text-anchor="middle" dominant-baseline="middle" fill="#00eaff" font-family="monospace" font-size="10" textLength="220">${text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</text>
  </svg>`;
  try {
    sharp(Buffer.from(svg)).jpeg({ quality: 60 }).toFile(dest);
    return `thumbnails/${category}_${sanitized}.jpg`;
  } catch { return null; }
}

async function processCategory(csvFile, batchSize = 200, maxBatches = null, noPlaceholder = false) {
  const csvPath = path.join(DATA_DIR, csvFile);
  if (!fs.existsSync(csvPath)) return { total: 0, done: 0, failed: 0, placeholder: 0 };

  const content = fs.readFileSync(csvPath, 'utf-8');
  const rawLines = content.split('\n');
  const lines = rawLines.map(l => l.trim()).filter(l => l);
  if (lines.length < 2) return { total: 0, done: 0, failed: 0, placeholder: 0 };

  const header = parseCSVLine(lines[0]);
  const imgIdx = header.indexOf('image');
  const nameIdx = header.indexOf('name');
  if (imgIdx === -1 || nameIdx === -1) {
    console.log(`  SKIP ${csvFile}: no image or name column`);
    return { total: 0, done: 0, failed: 0, placeholder: 0 };
  }

  const category = csvFile.replace('.csv', '');
  const allItems = [];

  for (let i = 1; i < lines.length; i++) {
    const parts = parseCSVLine(lines[i]);
    const name = (parts[nameIdx] || '').replace(/^"|"$/g, '').trim();
    if (!name) continue;
    const existing = parts.length > imgIdx ? (parts[imgIdx] || '').trim() : '';

    // Skip if already has a valid image (file exists on disk or is URL)
    if (existing.startsWith('thumbnails/')) {
      const thumbFile = existing.replace('thumbnails/', '');
      if (fs.existsSync(path.join(THUMB_DIR, thumbFile))) continue;
    }
    if (existing.startsWith('http')) continue;

    allItems.push({ i, name });
  }

  console.log(`  ${csvFile}: ${allItems.length} products need images`);

  let totalDone = 0, totalFailed = 0, totalPlaceholder = 0;
  let batchCount = 0;

  for (let start = 0; start < allItems.length; start += batchSize) {
    batchCount++;
    if (maxBatches && batchCount > maxBatches) {
      console.log(`    Reached max ${maxBatches} batches, stopping`);
      break;
    }

    const batch = allItems.slice(start, start + batchSize);
    console.log(`    Batch ${batchCount}: items ${start + 1}-${start + batch.length}...`);

    let done = 0, failed = 0, placeholder = 0;

    for (const item of batch) {
      const sanitized = sanitizeFilename(item.name);
      const dest = path.join(THUMB_DIR, `${category}_${sanitized}.jpg`);
      const csvImgVal = `thumbnails/${category}_${sanitized}.jpg`;

      // Check if already downloaded
      if (fs.existsSync(dest)) {
        const p = parseCSVLine(lines[item.i]);
        while (p.length <= imgIdx) p.push('');
        p[imgIdx] = csvImgVal;
        lines[item.i] = p.join(',');
        done++;
        continue;
      }

      // Try to find image via sources
      const url = await findImage(item.name);

      if (url) {
        if (await downloadImage(url, dest)) {
          const p = parseCSVLine(lines[item.i]);
          while (p.length <= imgIdx) p.push('');
          p[imgIdx] = csvImgVal;
          lines[item.i] = p.join(',');
          done++;
          process.stdout.write('.');
        } else {
          failed++;
          process.stdout.write('d');
        }
      } else if (!noPlaceholder) {
        // Generate placeholder as last resort
        generatePlaceholder(item.name, category, dest);
        const p = parseCSVLine(lines[item.i]);
        while (p.length <= imgIdx) p.push('');
        p[imgIdx] = csvImgVal;
        lines[item.i] = p.join(',');
        placeholder++;
        process.stdout.write('p');
      } else {
        failed++;
        process.stdout.write('x');
      }

      // Delay to avoid rate limiting
      await new Promise(r => setTimeout(r, 500 + Math.random() * 1000));
    }

    // Save progress after each batch
    fs.writeFileSync(csvPath, lines.join('\n'), 'utf-8');
    console.log(`\n    Batch ${batchCount}: ${done} OK, ${failed} FAIL, ${placeholder} placeholder`);

    totalDone += done;
    totalFailed += failed;
    totalPlaceholder += placeholder;

    // Cooldown between batches
    if (start + batchSize < allItems.length && (!maxBatches || batchCount < maxBatches)) {
      const cooldown = 15;
      console.log(`    Cooling down ${cooldown}s...`);
      await new Promise(r => setTimeout(r, cooldown * 1000));
    }
  }

  return { total: allItems.length, done: totalDone, failed: totalFailed, placeholder: totalPlaceholder };
}

async function main() {
  const batchSize = parseInt(process.argv[2] || '200');
  const maxBatches = process.argv[3] ? parseInt(process.argv[3]) : null;
  const singleCategory = process.argv[4] || null;
  const noPlaceholder = process.argv.includes('--no-placeholder');

  console.log(`=== Category-by-Category Image Scraper ===`);
  console.log(`Batch: ${batchSize}, Max batches: ${maxBatches || 'all'}, Placeholder: ${!noPlaceholder}\n`);

  const categories = singleCategory
    ? [singleCategory + '.csv']
    : [
        'case-fan.csv',   // Missing images
        'case.csv',
        'power-supply.csv',
        'cpu.csv',
        'gpu.csv',
        'ram.csv',
        'storage.csv',
        'motherboard.csv',
      ];

  const start = Date.now();
  let grandTotal = 0, grandDone = 0, grandFailed = 0, grandPlaceholder = 0;

  for (const cat of categories) {
    console.log(`\n--- ${cat} ---`);
    const r = await processCategory(cat, batchSize, maxBatches, noPlaceholder);
    grandTotal += r.total;
    grandDone += r.done;
    grandFailed += r.failed;
    grandPlaceholder += r.placeholder;
    console.log(`  >> ${cat}: ${r.done} OK, ${r.failed} FAIL, ${r.placeholder} PLACEHOLDER (of ${r.total})`);

    // Save audit after each category
    console.log(`  Progress saved to CSVs.`);
  }

  const elapsed = ((Date.now() - start) / 1000 / 60).toFixed(1);
  const totalFiles = fs.readdirSync(THUMB_DIR).filter(f => /\.(jpg|jpeg|png|webp)$/i.test(f)).length;
  console.log(`\n=== COMPLETE in ${elapsed} min ===`);
  console.log(`Total: ${grandDone} OK, ${grandFailed} FAIL, ${grandPlaceholder} PLACEHOLDER (${grandTotal} needed)`);
  console.log(`Total thumbnails on disk: ${totalFiles}`);
}

main().catch(e => { console.error(e); process.exit(1); });
