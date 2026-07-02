import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import sharp from 'sharp';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.join(__dirname, 'src', 'data');
const THUMB_DIR = path.join(__dirname, 'public', 'thumbnails');
const CONCURRENCY = 10;
const TIMEOUT = 15000;

fs.mkdirSync(THUMB_DIR, { recursive: true });

const categories = ['cpu.csv', 'cooler.csv', 'ram.csv', 'storage.csv', 'gpu.csv', 'power-supply.csv'];

function parseCSVLine(line) {
  const values = [];
  let current = '', inQuotes = false;
  for (const char of line) {
    if (char === '"') inQuotes = !inQuotes;
    else if (char === ',' && !inQuotes) { values.push(current); current = ''; }
    else { current += char; }
  }
  values.push(current);
  return values;
}

function sanitizeFilename(name) {
  return name.replace(/[^a-z0-9]/gi, '_').toLowerCase().substring(0, 50);
}

async function downloadImage(url, dest) {
  try {
    const headResp = await fetch(url, {
      method: 'HEAD',
      signal: AbortSignal.timeout(8000),
      headers: { 'User-Agent': 'Mozilla/5.0' },
      redirect: 'follow',
    });
    if (!headResp.ok) return false;
    const ct = headResp.headers.get('content-type') || '';
    if (!ct.startsWith('image/')) return false;

    const resp = await fetch(url, {
      signal: AbortSignal.timeout(TIMEOUT),
      headers: { 'User-Agent': 'Mozilla/5.0', 'Accept': 'image/webp,image/apng,image/*,*/*' },
      redirect: 'follow',
    });
    if (!resp.ok) return false;
    const buf = Buffer.from(await resp.arrayBuffer());
    if (buf.length < 4096) return false;

    const meta = await sharp(buf).metadata();
    if (meta.width < 200 || meta.height < 200) return false;

    fs.writeFileSync(dest, buf);
    return true;
  } catch {
    return false;
  }
}

async function processCSV(filename) {
  const filePath = path.join(DATA_DIR, filename);
  const category = filename.replace('.csv', '');
  const content = fs.readFileSync(filePath, 'utf-8');
  const rawLines = content.split('\n');
  if (rawLines.length <= 1) { console.log(`  ${filename}: empty`); return; }

  const lines = [];
  for (const l of rawLines) {
    const t = l.trim();
    if (t) lines.push(t);
  }

  const header = parseCSVLine(lines[0]);
  const imgIdx = header.indexOf('image');
  if (imgIdx === -1) { console.log(`  ${filename}: no image column`); return; }

  const items = [];
  for (let i = 1; i < lines.length; i++) {
    const parts = parseCSVLine(lines[i]);
    const name = (parts[0] || '').replace(/^"|"$/g, '');
    if (!name) continue;
    const url = (parts[imgIdx] || '').trim();
    if (!url || !url.startsWith('http')) continue;
    const clean = parts.map((p, idx) => {
      if (idx !== imgIdx) return p;
      return p;
    });
    items.push({ i, name, url });
  }

  console.log(`  ${filename}: ${items.length} items with image URLs`);

  let done = 0, failed = 0;
  const updateCSV = () => {
    const out = [lines[0]];
    for (let i = 1; i < lines.length; i++) {
      out.push(lines[i]);
    }
    fs.writeFileSync(filePath, out.join('\n'), 'utf-8');
  };

  const queue = [...items];
  async function worker() {
    while (queue.length > 0) {
      const item = queue.shift();
      const sanitized = sanitizeFilename(item.name);
      const ext = (item.url.match(/\.(jpg|jpeg|png|webp|avif)/i) || [])[0] || '.jpg';
      const localName = `${category}_${sanitized}${ext}`;
      const dest = path.join(THUMB_DIR, localName);

      if (fs.existsSync(dest)) {
        const parts = parseCSVLine(lines[item.i]);
        parts[imgIdx] = `thumbnails/${localName}`;
        lines[item.i] = parts.join(',');
        done++;
        continue;
      }

      if (await downloadImage(item.url, dest)) {
        const parts = parseCSVLine(lines[item.i]);
        parts[imgIdx] = `thumbnails/${localName}`;
        lines[item.i] = parts.join(',');
        done++;
      } else {
        failed++;
      }

      if ((done + failed) % 50 === 0) {
        updateCSV();
        process.stdout.write(`\r  ${filename}: ${done} OK, ${failed} FAIL / ${items.length}`);
      }
    }
  }

  const workers = Array.from({ length: CONCURRENCY }, () => worker());
  await Promise.all(workers);

  updateCSV();
  console.log(`\r  ${filename}: ${done} OK, ${failed} FAIL / ${items.length}        `);
}

async function main() {
  console.log('Downloading thumbnails from PCPP CDN for 6 CSV files...\n');
  const start = Date.now();
  for (const cat of categories) {
    await processCSV(cat);
  }
  const elapsed = ((Date.now() - start) / 1000 / 60).toFixed(1);
  const total = fs.readdirSync(THUMB_DIR).filter(f => f.endsWith('.jpg') || f.endsWith('.png') || f.endsWith('.webp')).length;
  console.log(`\nDone in ${elapsed} min. Total thumbnails: ${total}`);
}

main().catch(e => { console.error(e); process.exit(1); });
