import fs from 'fs';
import path from 'path';

/**
 * docyx pc-part-dataset Importer (MIT)
 * https://github.com/docyx/pc-part-dataset
 *
 * Downloads per-category JSON, enriches local CSVs with
 * spec data, and attempts PCPartPicker CDN image lookup.
 */

const ROOT = path.resolve(import.meta.dirname, '.');
const DATA_DIR = path.join(ROOT, 'src', 'data');
const THUMB_DIR = path.join(ROOT, 'public', 'thumbnails');
const CACHE_DIR = path.join(ROOT, '.docyx-cache');
const TIMEOUT = 15000;
const CONCURRENCY = 5;

fs.mkdirSync(THUMB_DIR, { recursive: true });
fs.mkdirSync(CACHE_DIR, { recursive: true });

const DOCYX_BASE = 'https://raw.githubusercontent.com/docyx/pc-part-dataset/main/data/json';

// Map local CSV names to docyx JSON filenames
const DOCYX_FILES = {
  'cpu': 'cpu.json',
  'cooler': 'cpu-cooler.json',
  'motherboard': 'motherboard.json',
  'ram': 'memory.json',
  'storage': 'internal-hard-drive.json',
  'gpu': 'video-card.json',
  'case': 'case.json',
  'power-supply': 'power-supply.json',
  'case-fan': 'case-fan.json',
  'case-accessory': 'case-accessory.json',
  'fan-controller': 'fan-controller.json',
  'thermal-paste': 'thermal-paste.json',
  'os': 'os.json',
  'optical-drive': 'optical-drive.json',
  'sound-card': 'sound-card.json',
  'wired-network-card': 'wired-network-card.json',
  'wireless-network-card': 'wireless-network-card.json',
  'ups': 'ups.json',
  'monitor': 'monitor.json',
  'headphones': 'headphones.json',
  'keyboard': 'keyboard.json',
  'mouse': 'mouse.json',
  'speakers': 'speakers.json',
  'webcam': 'webcam.json',
  'external-hard-drive': 'external-hard-drive.json',
};

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
  return name.replace(/[^a-z0-9]/gi, '_').toLowerCase().substring(0, 50);
}

async function downloadJSON(url, cachePath) {
  if (fs.existsSync(cachePath)) {
    return JSON.parse(fs.readFileSync(cachePath, 'utf-8'));
  }
  try {
    const resp = await fetch(url, {
      signal: AbortSignal.timeout(TIMEOUT),
      headers: { 'User-Agent': 'PCTG-PC-Builder/1.0' },
    });
    if (!resp.ok) return null;
    const data = await resp.json();
    fs.writeFileSync(cachePath, JSON.stringify(data));
    return data;
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
    if (buf.length < 1024) return false;
    fs.writeFileSync(dest, buf);
    return true;
  } catch {
    return false;
  }
}

function normalizeName(name) {
  return name.toLowerCase().replace(/[^a-z0-9]/g, '');
}

async function main() {
  console.log('=== docyx pc-part-dataset Importer ===\n');

  // Download all docyx category files
  console.log('Downloading docyx datasets...');
  const docyxData = {};
  for (const [category, filename] of Object.entries(DOCYX_FILES)) {
    const url = `${DOCYX_BASE}/${filename}`;
    const cachePath = path.join(CACHE_DIR, filename);
    const data = await downloadJSON(url, cachePath);
    if (data && Array.isArray(data)) {
      docyxData[category] = data;
    }
  }
  console.log(`  Loaded ${Object.keys(docyxData).length} categories, ${Object.values(docyxData).reduce((s, a) => s + a.length, 0)} total items\n`);

  // Process each local CSV that lacks images
  const IMAGE_LESS_CSVS = new Set([
    'external-hard-drive.csv', 'fan-controller.csv', 'headphones.csv',
    'keyboard.csv', 'monitor.csv', 'mouse.csv', 'optical-drive.csv',
    'os.csv', 'sound-card.csv', 'speakers.csv', 'thermal-paste.csv',
    'ups.csv', 'webcam.csv', 'wired-network-card.csv', 'wireless-network-card.csv',
  ]);

  const csvFiles = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  let totalImagesFound = 0, totalImagesSaved = 0;

  for (const csvFile of csvFiles) {
    if (!IMAGE_LESS_CSVS.has(csvFile)) continue;

    const category = csvFile.replace('.csv', '');
    const docyxItems = docyxData[category] || [];
    if (docyxItems.length === 0) continue;

    const content = fs.readFileSync(path.join(DATA_DIR, csvFile), 'utf-8');
    const lines = content.split('\n').filter(l => l.trim());
    if (lines.length < 2) continue;

    const header = parseCSVLine(lines[0]);
    const nameIdx = header.indexOf('name');
    const brandIdx = header.indexOf('brand');
    if (nameIdx === -1) continue;

    let matched = 0;

    for (let i = 1; i < lines.length; i++) {
      const parts = parseCSVLine(lines[i]);
      const name = (parts[nameIdx] || '').replace(/^"|"$/g, '').trim();
      if (!name) continue;

      const localBrand = brandIdx !== -1 ? (parts[brandIdx] || '').replace(/^"|"$/g, '').trim() : '';
      const nameNorm = normalizeName(name);

      // Find matching docyx item by name
      const match = docyxItems.find(d => {
        const dName = normalizeName(d.name || '');
        return dName.includes(nameNorm) || nameNorm.includes(dName);
      });
      if (!match) continue;

      matched++;

      // Try PCPartPicker CDN for image
      const sanitized = sanitizeFilename(name);
      const destJpg = path.join(THUMB_DIR, `${category}_${sanitized}.jpg`);
      const destPng = path.join(THUMB_DIR, `${category}_${sanitized}.png`);
      if (fs.existsSync(destJpg) || fs.existsSync(destPng)) continue;

      // PCPartPicker CDN pattern
      const docyxCat = Object.entries(DOCYX_FILES).find(([, v]) => v === DOCYX_FILES[category])?.[0] || category;
      const pcppPartsPath = {
        'monitor': 'monitor', 'keyboard': 'keyboard', 'mouse': 'mouse',
        'headphones': 'headphones', 'speakers': 'speakers', 'webcam': 'webcam',
        'ups': 'ups', 'external-hard-drive': 'external-hard-drive',
        'fan-controller': 'fan-controller', 'thermal-paste': 'thermal-compound',
        'os': 'os', 'optical-drive': 'optical-drive',
        'sound-card': 'sound-card', 'wired-network-card': 'wired-network-card',
        'wireless-network-card': 'wireless-network-card',
      }[category];

      if (!pcppPartsPath) continue;

      // Try constructing PCPartPicker CDN URL (may not work)
      const pcppId = match.id || match.product_id || match.part_id;
      if (!pcppId) continue;

      const pcppUrl = `https://cdnd.pcpartpicker.com/images/static/parts/${pcppPartsPath}/${pcppId}.jpg`;
      if (await downloadImage(pcppUrl, destJpg)) {
        totalImagesSaved++;
      }
    }

    if (matched > 0) {
      console.log(`  ${csvFile}: ${matched} docyx matches, ${totalImagesSaved} images so far`);
    }
  }

  // Also try PCPP CDN for categories that have images but missing files
  console.log('\nChecking all CSVs for PCPartPicker CDN images...');
  let pcppFound = 0;

  for (const csvFile of csvFiles) {
    const category = csvFile.replace('.csv', '');
    const docyxItems = docyxData[category] || [];
    if (docyxItems.length === 0) continue;

    const pcppPartsPath = category === 'cooler' ? 'cpu-cooler' :
      category === 'ram' ? 'memory' :
      category === 'storage' ? 'internal-hard-drive' :
      category === 'gpu' ? 'video-card' : category;
    if (!pcppPartsPath) continue;

    const content = fs.readFileSync(path.join(DATA_DIR, csvFile), 'utf-8');
    const lines = content.split('\n').filter(l => l.trim());
    if (lines.length < 2) continue;
    const header = parseCSVLine(lines[0]);
    const nameIdx = header.indexOf('name');
    if (nameIdx === -1) continue;

    for (let i = 1; i < Math.min(lines.length, 50); i++) {
      const parts = parseCSVLine(lines[i]);
      const name = (parts[nameIdx] || '').replace(/^"|"$/g, '').trim();
      if (!name) continue;

      const sanitized = sanitizeFilename(name);
      const destJpg = path.join(THUMB_DIR, `${category}_${sanitized}.jpg`);
      const destPng = path.join(THUMB_DIR, `${category}_${sanitized}.png`);
      if (fs.existsSync(destJpg) || fs.existsSync(destPng)) continue;

      const nameNorm = normalizeName(name);
      const match = docyxItems.find(d => {
        const dName = normalizeName(d.name || '');
        return dName.includes(nameNorm) || nameNorm.includes(dName);
      });
      if (!match) continue;

      const pcppId = match.id || match.product_id || match.part_id;
      if (!pcppId) continue;

      const pcppUrl = `https://cdnd.pcpartpicker.com/images/static/parts/${pcppPartsPath}/${pcppId}.jpg`;
      if (await downloadImage(pcppUrl, destJpg)) {
        pcppFound++;
        process.stdout.write(`+`);
      }
    }
  }

  const totalThumbs = fs.readdirSync(THUMB_DIR).filter(f => /\.(jpg|png|webp)$/i.test(f)).length;
  console.log(`\n\n=== Summary ===`);
  console.log(`  Images from PCPartPicker CDN: ${pcppFound}`);
  console.log(`  Total thumbnails on disk: ${totalThumbs}`);
}

main().catch(e => { console.error(e); process.exit(1); });
