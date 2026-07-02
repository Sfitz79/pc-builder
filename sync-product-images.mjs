import fs from 'fs';
import path from 'path';
import sharp from 'sharp';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.join(__dirname, 'src', 'data');
const SCRAPED_DIR = path.join(__dirname, 'scraped_data');
const THUMB_DIR = path.join(__dirname, 'public', 'thumbnails');
const CONCURRENCY = 8;
const TIMEOUT = 20000;

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
    if (meta.width < 100 || meta.height < 100) return false;

    fs.writeFileSync(dest, buf);
    return true;
  } catch {
    return false;
  }
}

function loadJSON(name) {
  const p = path.join(SCRAPED_DIR, name);
  if (!fs.existsSync(p)) return null;
  try {
    return JSON.parse(fs.readFileSync(p, 'utf-8'));
  } catch {
    return null;
  }
}

function loadCSVLines(filePath) {
  const content = fs.readFileSync(filePath, 'utf-8');
  const raw = content.split('\n');
  const lines = raw.map(l => l.trim()).filter(l => l);
  if (lines.length < 1) return null;
  const header = parseCSVLine(lines[0]);
  return { header, lines };
}

async function syncCategory(csvFile, jsonFile, nameKey, imageKey) {
  const csvPath = path.join(DATA_DIR, csvFile);
  if (!fs.existsSync(csvPath)) {
    console.log(`  SKIP ${csvFile}: CSV not found`);
    return;
  }

  const jsonData = loadJSON(jsonFile);
  if (!jsonData || !Array.isArray(jsonData) || jsonData.length === 0) {
    console.log(`  SKIP ${csvFile}: no JSON data`);
    return;
  }

  const parsed = loadCSVLines(csvPath);
  if (!parsed) {
    console.log(`  SKIP ${csvFile}: empty CSV`);
    return;
  }

  const { header, lines } = parsed;
  const imgIdx = header.indexOf('image');
  if (imgIdx === -1) {
    console.log(`  SKIP ${csvFile}: no image column`);
    return;
  }

  // Build name -> imageUrl map from JSON
  const nameToUrl = new Map();
  for (const item of jsonData) {
    const name = (nameKey ? item[nameKey] : item.name) || '';
    const url = (imageKey ? item[imageKey] : item.imageUrl) || item.image || '';
    if (name && url && url.startsWith('http')) {
      nameToUrl.set(name.toLowerCase().trim(), url);
    }
  }

  console.log(`  ${csvFile}: ${nameToUrl.size} image URLs in JSON, ${lines.length - 1} rows in CSV`);

  // Match and update CSV lines
  let matched = 0, alreadySet = 0, downloaded = 0, failed = 0;

  for (let i = 1; i < lines.length; i++) {
    const parts = parseCSVLine(lines[i]);
    const name = (parts[0] || '').replace(/^"|"$/g, '').trim();
    if (!name) continue;

    const existing = (parts[imgIdx] || '').trim();
    if (existing) {
      if (existing.startsWith('thumbnails/')) {
        const thumbPath = path.join(THUMB_DIR, existing.replace('thumbnails/', ''));
        if (fs.existsSync(thumbPath)) {
          alreadySet++;
          continue;
        }
      }
    }

    const url = nameToUrl.get(name.toLowerCase());
    if (!url) continue;

    matched++;
    const sanitized = sanitizeFilename(name);
    const ext = (url.match(/\.(jpg|jpeg|png|webp|avif)/i) || [])[0] || '.jpg';
    const localName = `${csvFile.replace('.csv', '')}_${sanitized}${ext}`;
    const dest = path.join(THUMB_DIR, localName);

    if (fs.existsSync(dest)) {
      parts[imgIdx] = `thumbnails/${localName}`;
      lines[i] = parts.join(',');
      alreadySet++;
      continue;
    }

    if (await downloadImage(url, dest)) {
      parts[imgIdx] = `thumbnails/${localName}`;
      lines[i] = parts.join(',');
      downloaded++;
    } else {
      failed++;
    }

    if ((downloaded + failed) % 30 === 0) {
      fs.writeFileSync(csvPath, lines.join('\n'), 'utf-8');
      process.stdout.write(`\r  ${csvFile}: ${downloaded} OK, ${failed} FAIL, ${alreadySet} cached`);
    }
  }

  fs.writeFileSync(csvPath, lines.join('\n'), 'utf-8');
  console.log(`\r  ${csvFile}: ${downloaded} downloaded, ${failed} failed, ${alreadySet} already set / ${matched} matched`);
}

// Try alternative JSON key formats
function trySync(csvFile, jsonFile) {
  const data = loadJSON(jsonFile);
  if (!data || !Array.isArray(data)) {
    console.log(`  SKIP ${csvFile}: no JSON array`);
    return Promise.resolve();
  }

  // Determine key format
  const first = data[0];
  const nameKey = first.name !== undefined ? 'name' : (first.Name !== undefined ? 'Name' : null);
  const imageKey = first.imageUrl !== undefined ? 'imageUrl' : (first.ImageUrl !== undefined ? 'ImageUrl' : (first.image !== undefined ? 'image' : null));

  if (!nameKey || !imageKey) {
    console.log(`  SKIP ${csvFile}: unknown keys in JSON (${Object.keys(first).join(',')})`);
    return Promise.resolve();
  }

  return syncCategory(csvFile, jsonFile, nameKey, imageKey);
}

async function main() {
  console.log('=== Syncing Product Images from Scraped Data ===\n');
  const start = Date.now();

  const categories = [
    ['cpu.csv', 'cpu.json'],
    ['cooler.csv', 'cooler.json'],
    ['gpu.csv', 'gpu.json'],
    ['ram.csv', 'ram.json'],
    ['storage.csv', 'storage.json'],
    ['power-supply.csv', 'power-supply.json'],
    ['motherboard.csv', 'motherboard.json'],
    ['case.csv', 'case-with-images.json'],
    ['case-fan.csv', 'case-fan.json'],
    ['case-accessory.csv', 'case-accessory.json'],
    ['external-hard-drive.csv', 'external-hard-drive.json'],
    ['fan-controller.csv', 'fan-controller.json'],
    ['headphones.csv', 'headphones.json'],
    ['keyboard.csv', 'keyboard.json'],
    ['monitor.csv', 'monitor.json'],
    ['mouse.csv', 'mouse.json'],
    ['optical-drive.csv', 'optical-drive.json'],
    ['os.csv', 'os.json'],
    ['sound-card.csv', 'sound-card.json'],
    ['speakers.csv', 'speakers.json'],
    ['thermal-paste.csv', 'thermal-paste.json'],
    ['ups.csv', 'ups.json'],
    ['webcam.csv', 'webcam.json'],
    ['wired-network-card.csv', 'wired-network-card.json'],
    ['wireless-network-card.csv', 'wireless-network-card.json'],
  ];

  for (const [csv, json] of categories) {
    await trySync(csv, json);
  }

  const elapsed = ((Date.now() - start) / 1000).toFixed(1);
  const totalFiles = fs.readdirSync(THUMB_DIR).filter(f => /\.(jpg|jpeg|png|webp)$/i.test(f)).length;
  console.log(`\nDone in ${elapsed}s. Total thumbnails: ${totalFiles}`);
}

main().catch(e => { console.error(e); process.exit(1); });
