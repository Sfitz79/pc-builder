import fs from 'fs';
import path from 'path';
import sharp from 'sharp';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.join(__dirname, 'src', 'data');
const THUMB_DIR = path.join(__dirname, 'public', 'thumbnails');
const CONCURRENCY = 6;
const TIMEOUT = 15000;

fs.mkdirSync(THUMB_DIR, { recursive: true });

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

function sanitizeFilename(name) {
  return name.replace(/[^a-z0-9]/gi, '_').toLowerCase().substring(0, 55);
}

async function scrapeBingImages(productName, category) {
  const query = `${productName} ${category} pc component`;
  const url = `https://r.jina.ai/http://www.bing.com/images/search?q=${encodeURIComponent(query)}`;

  try {
    const resp = await fetch(url, {
      signal: AbortSignal.timeout(10000),
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language': 'en-GB,en;q=0.9',
      }
    });
    if (!resp.ok) return null;

    const text = await resp.text();
    const urls = new Set();

    // Extract image URLs from the HTML
    const matches = text.matchAll(/https?:\/\/[^\s"'<>()]+?\.(?:jpg|jpeg|png|webp)(?:\?[^\s"'<>]*)?/gi);
    for (const match of matches) {
      const u = match[0];
      if (u.length > 40 && u.length < 500 &&
          !u.includes('bing.com') && !u.includes('jina.ai') &&
          !/pixel|tracking|logo|icon|avatar|placeholder|spacer|banner/i.test(u)) {
        urls.add(u);
      }
      if (urls.size >= 5) break;
    }

    return urls.size > 0 ? Array.from(urls)[0] : null;
  } catch {
    return null;
  }
}

async function downloadImage(url, dest) {
  try {
    const resp = await fetch(url, {
      signal: AbortSignal.timeout(TIMEOUT),
      headers: { 'User-Agent': 'Mozilla/5.0', 'Accept': 'image/webp,image/apng,image/*,*/*' },
      redirect: 'follow',
    });
    if (!resp.ok) return false;
    const buf = Buffer.from(await resp.arrayBuffer());
    if (buf.length < 4096) return false;

    const meta = await sharp(buf).metadata();
    if (meta.width < 100 || meta.height < 100) return false;

    fs.writeFileSync(dest, buf);
    return true;
  } catch {
    return false;
  }
}

async function processCategory(csvFile, mode = 'missing') {
  const csvPath = path.join(DATA_DIR, csvFile);
  if (!fs.existsSync(csvPath)) {
    console.log(`SKIP ${csvFile}: not found`);
    return;
  }

  const content = fs.readFileSync(csvPath, 'utf-8');
  const rawLines = content.split('\n');
  const lines = rawLines.map(l => l.trim()).filter(l => l);
  if (lines.length < 2) return;

  const header = parseCSVLine(lines[0]);
  const imgIdx = header.indexOf('image');
  const nameIdx = header.indexOf('name');
  if (imgIdx === -1 || nameIdx === -1) {
    console.log(`SKIP ${csvFile}: no image/name column`);
    return;
  }

  const category = csvFile.replace('.csv', '');
  const items = [];

  for (let i = 1; i < lines.length; i++) {
    const parts = parseCSVLine(lines[i]);
    const name = (parts[nameIdx] || '').replace(/^"|"$/g, '').trim();
    if (!name) continue;

    const existing = (parts[imgIdx] || '').trim();

    if (mode === 'missing') {
      // Only process items that have a thumbnails/ reference but file is missing
      if (!existing.startsWith('thumbnails/')) continue;
      const thumbFile = existing.replace('thumbnails/', '');
      if (fs.existsSync(path.join(THUMB_DIR, thumbFile))) continue;
    } else if (mode === 'all') {
      // Process all items without a valid image
      if (existing.startsWith('thumbnails/')) {
        const thumbFile = existing.replace('thumbnails/', '');
        if (fs.existsSync(path.join(THUMB_DIR, thumbFile))) continue;
      }
      if (existing.startsWith('http') && existing.includes('pcpartpicker')) continue; // skip PCPP URLs
    }

    items.push({ i, name, parts });
  }

  if (items.length === 0) {
    console.log(`  ${csvFile}: nothing to do`);
    return;
  }

  console.log(`  ${csvFile}: scraping images for ${items.length} products...`);

  let done = 0, failed = 0;
  const queue = [...items];

  async function worker() {
    while (queue.length > 0) {
      const item = queue.shift();
      const sanitized = sanitizeFilename(item.name);
      const dest = path.join(THUMB_DIR, `${category}_${sanitized}.jpg`);

      // Skip if already exists from another run
      if (fs.existsSync(dest)) {
        const parts = parseCSVLine(lines[item.i]);
        parts[imgIdx] = `thumbnails/${category}_${sanitized}.jpg`;
        lines[item.i] = parts.join(',');
        done++;
        continue;
      }

      const url = await scrapeBingImages(item.name, category);
      if (url && await downloadImage(url, dest)) {
        const parts = parseCSVLine(lines[item.i]);
        parts[imgIdx] = `thumbnails/${category}_${sanitized}.jpg`;
        lines[item.i] = parts.join(',');
        done++;
      } else {
        failed++;
      }

      if ((done + failed) % 15 === 0) {
        fs.writeFileSync(csvPath, lines.join('\n'), 'utf-8');
        process.stdout.write(`\r  ${csvFile}: ${done} OK, ${failed} FAIL / ${items.length}`);
      }
    }
  }

  const workers = Array.from({ length: CONCURRENCY }, () => worker());
  await Promise.all(workers);

  fs.writeFileSync(csvPath, lines.join('\n'), 'utf-8');
  console.log(`\r  ${csvFile}: ${done} OK, ${failed} FAIL / ${items.length}        `);
}

async function main() {
  const mode = process.argv[2] || 'missing'; // 'missing' or 'all'
  console.log(`=== Scraping missing product images (mode: ${mode}) ===\n`);

  const start = Date.now();

  // Categories ordered by priority for the UI
  const categories = [
    'case-fan.csv',    // 67 missing files
    'case.csv',
    'gpu.csv',
    'cpu.csv',
    'cooler.csv',
    'motherboard.csv',
    'ram.csv',
    'storage.csv',
    'power-supply.csv',
    'monitor.csv',
    'keyboard.csv',
    'mouse.csv',
    'headphones.csv',
  ];

  for (const cat of categories) {
    await processCategory(cat, mode);
  }

  const elapsed = ((Date.now() - start) / 1000 / 60).toFixed(1);
  const totalFiles = fs.readdirSync(THUMB_DIR).filter(f => /\.(jpg|jpeg|png|webp)$/i.test(f)).length;
  console.log(`\nDone in ${elapsed} min. Total thumbnails: ${totalFiles}`);
}

main().catch(e => { console.error(e); process.exit(1); });
