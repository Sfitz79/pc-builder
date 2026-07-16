import fs from 'fs';
import path from 'path';
import os from 'os';
import { fileURLToPath } from 'url';
import { execSync } from 'child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const DATA_DIR = path.join(ROOT, 'src', 'data');
const scrapedDir = path.join(ROOT, 'scraped_data');
const LOG_FILE = path.join(ROOT, 'priceupd.log');
const STATE_FILE = path.join(ROOT, 'priceupd-state.json');

const DATA_SOURCES = {
  open_source_datasets: {
    hardwaredealsco_gpu: 'https://hardwaredeals.co/datasets/gpu.json',
    hardwaredealsco_ram: 'https://hardwaredeals.co/datasets/ram.json',
    hardwaredealsco_ssd: 'https://hardwaredeals.co/datasets/drives.json',
    hardwaredealsco_monitors: 'https://hardwaredeals.co/datasets/monitors.json',
  },
};

const startTime = Date.now();

const state = {
  lastRun: null, lastSuccess: null, pid: null, status: 'idle',
  restartCount: 0, phase: '', phaseNumber: 0,
};

function log(msg) {
  const ts = new Date().toISOString().replace('T', ' ').substring(0, 19);
  const line = `[${ts}] ${msg}`;
  console.log(line);
  try { fs.appendFileSync(LOG_FILE, line + '\n'); } catch {}
}

function loadState() {
  try {
    if (fs.existsSync(STATE_FILE)) Object.assign(state, JSON.parse(fs.readFileSync(STATE_FILE, 'utf-8')));
  } catch {}
}

function saveState() {
  try { fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2)); } catch {}
}

function parseCSVLine(line) {
  const values = []; let current = '', inQuotes = false;
  for (const char of line) {
    if (char === '"') inQuotes = !inQuotes;
    else if (char === ',' && !inQuotes) { values.push(current); current = ''; }
    else current += char;
  }
  values.push(current);
  return values;
}

function escapeCSV(value) {
  if (value === null || value === undefined) return '';
  const str = String(value);
  if (str.includes(',') || str.includes('"') || str.includes('\n')) return `"${str.replace(/"/g, '""')}"`;
  return str;
}

function readCSV(filePath) {
  if (!fs.existsSync(filePath)) return null;
  const text = fs.readFileSync(filePath, 'utf-8');
  const lines = text.split(/\r?\n/).filter(l => l.trim());
  if (lines.length < 2) return null;
  const header = parseCSVLine(lines[0]);
  return { header, lines, filePath };
}

function writeCSV(filePath, header, rows) {
  const out = [header.join(',')];
  for (const row of rows) {
    const vals = header.map(h => escapeCSV(row[h] ?? ''));
    out.push(vals.join(','));
  }
  fs.writeFileSync(filePath, out.join('\n'), 'utf-8');
}

function bumpVersion() {
  const pkgPath = path.join(ROOT, 'package.json');
  if (!fs.existsSync(pkgPath)) { log('  package.json not found, skipping version bump'); return; }
  try {
    const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf8'));
    const parts = pkg.version.split('.').map(Number);
    parts[2] = (parts[2] || 0) + 1;
    if (parts[2] >= 100) { parts[2] = 0; parts[1] = (parts[1] || 0) + 1; }
    pkg.version = parts.join('.');
    fs.writeFileSync(pkgPath, JSON.stringify(pkg, null, 2) + '\n');
    const versionInfo = { version: pkg.version, buildDate: new Date().toISOString() };
    const vPath = path.join(ROOT, 'src', 'version.json');
    fs.writeFileSync(vPath, JSON.stringify(versionInfo, null, 2) + '\n');
    log(`  Version bumped to ${pkg.version}`);
  } catch (e) { log(`  Version bump failed: ${e.message}`); }
}

// ─── Import Functions ────────────────────────────────────────────

async function importApifyData() {
  log('\n=== Phase: Import Apify Data ===');
  if (!fs.existsSync(scrapedDir)) { log('  scraped_data/ not found, skipping'); return; }

  const APIFY_MAP = {
    'cpu.json': { file: 'cpu.csv', headers: ['name','price','core_count','core_clock','boost_clock','microarchitecture','tdp','graphics','image'] },
    'cooler.json': { file: 'cooler.csv', headers: ['name','price','rpm','noise_level','color','size','image'] },
    'motherboard.json': { file: 'motherboard.csv', headers: ['name','price','socket','form_factor','max_memory','memory_slots','color','wifi','usb_c','image'] },
    'ram.json': { file: 'ram.csv', headers: ['name','price','speed','modules','price_per_gb','color','first_word_latency','cas_latency','image'] },
  };

  for (const [jsonFile, cfg] of Object.entries(APIFY_MAP)) {
    const jsonPath = path.join(scrapedDir, jsonFile);
    if (!fs.existsSync(jsonPath)) { log(`  ${jsonFile}: not found`); continue; }
    try {
      let raw = fs.readFileSync(jsonPath, 'utf-8');
      if (raw.charCodeAt(0) === 0xFEFF) raw = raw.slice(1);
      const items = JSON.parse(raw);
      if (!items.length) { log(`  ${jsonFile}: empty`); continue; }

      const csvPath = path.join(DATA_DIR, cfg.file);
      const existing = readCSV(csvPath);
      if (!existing) { log(`  ${cfg.file}: no existing CSV, skipping`); continue; }

      const nameIdx = existing.header.indexOf('name');
      const existingNames = new Set();
      for (let i = 1; i < existing.lines.length; i++) {
        const parts = parseCSVLine(existing.lines[i]);
        existingNames.add((parts[nameIdx]||'').toLowerCase());
      }

      let added = 0, updated = 0;
      for (const item of items) {
        const name = item.productName || item.name || '';
        if (!name) continue;
        const key = name.toLowerCase();
        const row = {};
        for (const h of existing.header) {
          if (h === 'image' && item.imageUrl) { row[h] = item.imageUrl; }
          else if (h === 'price' && item.price != null) { row[h] = parseFloat(item.price).toFixed(2); }
          else { row[h] = ''; }
        }
        row['name'] = name;

        if (existingNames.has(key)) {
          const lineIdx = [...existingNames].indexOf(key) + 1;
          const parts = parseCSVLine(existing.lines[lineIdx]);
          const priceIdx = existing.header.indexOf('price');
          const imgIdx = existing.header.indexOf('image');
          if (priceIdx >= 0 && (!parts[priceIdx] || parts[priceIdx] === '0') && row['price']) {
            parts[priceIdx] = row['price'];
            existing.lines[lineIdx] = parts.join(',');
            updated++;
          }
          if (imgIdx >= 0 && (!parts[imgIdx] || parts[imgIdx] === '""') && row['image']) {
            parts[imgIdx] = `"${row['image']}"`;
            existing.lines[lineIdx] = parts.join(',');
            updated++;
          }
        } else {
          const lineParts = existing.header.map(h => escapeCSV(row[h] || ''));
          existing.lines.push(lineParts.join(','));
          existingNames.add(key);
          added++;
        }
      }
      fs.writeFileSync(csvPath, existing.lines.join('\n'), 'utf-8');
      log(`  ${cfg.file}: ${added} added, ${updated} updated`);
    } catch (e) { log(`  ${jsonFile}: error - ${e.message}`); }
  }
}

async function importDocyxData() {
  log('\n=== Phase: Import Docyx Dataset ===');
  const docyxDir = path.join(scrapedDir, 'docyx');
  if (!fs.existsSync(docyxDir)) { log('  scraped_data/docyx/ not found, skipping'); return; }

  const FILE_MAP = { 'cpu-cooler.csv': 'cooler.csv', 'memory.csv': 'ram.csv', 'video-card.csv': 'gpu.csv', 'internal-hard-drive.csv': 'storage.csv' };
  const files = fs.readdirSync(docyxDir).filter(f => f.endsWith('.csv'));
  let totalAdded = 0, totalUpdated = 0;

  for (const df of files) {
    const targetFile = FILE_MAP[df] || df;
    const docyxPath = path.join(docyxDir, df);
    const targetPath = path.join(DATA_DIR, targetFile);
    const docyx = readCSV(docyxPath);
    if (!docyx || docyx.lines.length < 2) continue;
    const existing = readCSV(targetPath);
    if (!existing) {
      fs.writeFileSync(targetPath, docyx.lines.join('\n'), 'utf-8');
      log(`  ${targetFile}: NEW (${docyx.lines.length - 1} rows)`);
      continue;
    }
    const nameIdx = existing.header.indexOf('name');
    const nameMap = new Map();
    for (let i = 1; i < existing.lines.length; i++) {
      const parts = parseCSVLine(existing.lines[i]);
      const n = (parts[nameIdx]||'').toLowerCase();
      if (n) nameMap.set(n, i);
    }
    let added = 0, updated = 0;
    for (let i = 1; i < docyx.lines.length; i++) {
      const dParts = parseCSVLine(docyx.lines[i]);
      const dName = (dParts[0]||'').trim();
      if (!dName) continue;
      const key = dName.toLowerCase();
      if (nameMap.has(key)) {
        const ourIdx = nameMap.get(key);
        const ourParts = parseCSVLine(existing.lines[ourIdx]);
        let modified = false;
        for (let h = 0; h < existing.header.length; h++) {
          if (h === 0 || h === nameIdx) continue;
          if ((!ourParts[h] || ourParts[h] === '') && dParts[h]) {
            ourParts[h] = dParts[h];
            modified = true;
          }
        }
        if (modified) { existing.lines[ourIdx] = ourParts.join(','); updated++; }
      } else {
        const extraParts = [];
        for (let h = 0; h < existing.header.length; h++) {
          extraParts.push(dParts[h] || '');
        }
        existing.lines.push(extraParts.join(','));
        nameMap.set(key, existing.lines.length - 1);
        added++;
      }
    }
    fs.writeFileSync(targetPath, existing.lines.join('\n'), 'utf-8');
    totalAdded += added; totalUpdated += updated;
    log(`  ${targetFile}: ${added} added, ${updated} updated`);
  }
  log(`  Total: ${totalAdded} added, ${totalUpdated} updated`);
}

async function importPCPPData() {
  log('\n=== Phase: Import PCPartPicker CSVs ===');
  const downloadDir = path.join(os.homedir(), 'Downloads');
  const pcppFiles = fs.existsSync(downloadDir) ? fs.readdirSync(downloadDir).filter(f => f.startsWith('uk-pcpartpicker-com-') && f.endsWith('.csv')) : [];
  if (pcppFiles.length === 0) { log('  No PCPP CSVs in Downloads'); return; }

  const COLUMN_SIGNATURES = [
    { match: ['performance_core_clock', 'core_count', 'tdp', 'integrated_graphics'], file: 'cpu.csv', urlCol: 'Image URL', priceCol: 'Sale Price' },
    { match: ['chipset', 'memory', 'core_clock', 'boost_clock'], file: 'gpu.csv', urlCol: 'Image URL', priceCol: 'Sale Price' },
    { match: ['fan_rpm', 'noise_level', 'radiator_size'], file: 'cooler.csv', urlCol: 'Image URL', priceCol: 'Sale Price' },
    { match: ['speed', 'modules', 'cas_latency', 'first_word_latency'], file: 'ram.csv', urlCol: 'Image URL', priceCol: 'Sale Price' },
    { match: ['capacity', 'interface', 'type', 'form_factor'], file: 'storage.csv', urlCol: 'Image URL', priceCol: 'Sale Price' },
    { match: ['wattage', 'efficiency_rating', 'modular'], file: 'power-supply.csv', urlCol: 'Image URL', priceCol: 'Sale Price' },
    { match: ['chipset', 'form_factor', 'socket'], file: 'motherboard.csv', urlCol: 'Image URL', priceCol: 'Sale Price' },
    { match: ['case_fan'], file: 'case-fan.csv', urlCol: 'Image URL', priceCol: 'Sale Price' },
  ];

  for (const pf of pcppFiles) {
    const pcppText = fs.readFileSync(path.join(downloadDir, pf), 'utf-8');
    const pcppLines = pcppText.split(/\r?\n/).filter(l => l.trim());
    if (pcppLines.length < 2) continue;
    const pcppHeader = parseCSVLine(pcppLines[0]);
    const headerSet = new Set(pcppHeader.map(h => h.toLowerCase().replace(/[0-9]/g, '').trim()));

    let cfg = null;
    for (const sig of COLUMN_SIGNATURES) {
      if (sig.match.every(col => headerSet.has(col))) { cfg = sig; break; }
    }
    if (!cfg) { log(`  ${pf}: unrecognised columns, skipping`); continue; }
    log(`  ${pf}: detected as ${cfg.file}`);
    const csvPath = path.join(DATA_DIR, cfg.file);
    const existing = readCSV(csvPath);
    if (!existing) continue;
    const urlIdx = pcppHeader.indexOf(cfg.urlCol);
    const priceIdx = pcppHeader.indexOf(cfg.priceCol);
    const pNameIdx = pcppHeader.indexOf('Product Name');
    if (pNameIdx < 0) continue;

    const existingMap = new Map();
    for (let i = 1; i < existing.lines.length; i++) {
      const parts = parseCSVLine(existing.lines[i]);
      existingMap.set((parts[0]||'').toLowerCase(), i);
    }
    let updated = 0, added = 0;
    for (let i = 1; i < pcppLines.length; i++) {
      const parts = parseCSVLine(pcppLines[i]);
      const pName = (parts[pNameIdx]||'').trim();
      if (!pName) continue;
      const key = pName.toLowerCase();
      const imgUrl = (urlIdx >= 0 && urlIdx < parts.length) ? parts[urlIdx].replace(/^"|"$/g,'') : '';
      const price = (priceIdx >= 0 && priceIdx < parts.length) ? parts[priceIdx].replace(/^"|"$/g,'') : '';
      if (!imgUrl && !price) continue;
      if (existingMap.has(key)) {
        const ourIdx = existingMap.get(key);
        const ourParts = parseCSVLine(existing.lines[ourIdx]);
        const imgCol = existing.header.indexOf('image');
        const priceCol = existing.header.indexOf('price');
        let modified = false;
        if (imgCol >= 0 && imgUrl && (!ourParts[imgCol] || ourParts[imgCol] === '')) {
          ourParts[imgCol] = `"${imgUrl}"`;
          modified = true;
        }
        if (priceCol >= 0 && price && (!ourParts[priceCol] || ourParts[priceCol] === '0' || ourParts[priceCol] === '')) {
          ourParts[priceCol] = price;
          modified = true;
        }
        if (modified) { existing.lines[ourIdx] = ourParts.join(','); updated++; }
      } else {
        const newParts = existing.header.map(() => '');
        newParts[0] = `"${pName}"`;
        const imgCol = existing.header.indexOf('image');
        const priceCol = existing.header.indexOf('price');
        if (imgCol >= 0 && imgUrl) newParts[imgCol] = `"${imgUrl}"`;
        if (priceCol >= 0 && price) newParts[priceCol] = price;
        existing.lines.push(newParts.join(','));
        added++;
      }
    }
    fs.writeFileSync(csvPath, existing.lines.join('\n'), 'utf-8');
    log(`  ${cfg.file}: ${updated} updated, ${added} added`);
  }
}

async function importOpenSourceDatasets() {
  log('\n=== Phase: Import Open Source Datasets ===');
  const datasets = DATA_SOURCES.open_source_datasets;
  const CATEGORY_MAP = {
    gpu: 'hardwaredealsco_gpu',
    ram: 'hardwaredealsco_ram',
    storage: 'hardwaredealsco_ssd',
    monitor: 'hardwaredealsco_monitors',
  };

  for (const [csvName, sourceKey] of Object.entries(CATEGORY_MAP)) {
    const url = datasets[sourceKey];
    if (!url) continue;
    const targetFile = `${csvName}.csv`;
    const targetPath = path.join(DATA_DIR, targetFile);
    const existing = readCSV(targetPath);
    if (!existing) { log(`  ${targetFile}: no existing CSV, skipping`); continue; }

    try {
      const resp = await fetch(url);
      if (!resp.ok) { log(`  ${sourceKey}: HTTP ${resp.status}`); continue; }
      const items = await resp.json();
      if (!Array.isArray(items) || items.length === 0) { log(`  ${sourceKey}: empty`); continue; }

      const nameIdx = existing.header.indexOf('name');
      const priceIdx = existing.header.indexOf('price');
      const existingMap = new Map();
      for (let i = 1; i < existing.lines.length; i++) {
        const parts = parseCSVLine(existing.lines[i]);
        existingMap.set((parts[nameIdx] || '').toLowerCase(), i);
      }

      let updated = 0, added = 0;
      for (const item of items) {
        const name = item.name || item.title || item.product_name || '';
        if (!name) continue;
        const key = name.toLowerCase();
        const price = item.price || item.sale_price || item.current_price || '';

        if (existingMap.has(key)) {
          const ourIdx = existingMap.get(key);
          const ourParts = parseCSVLine(existing.lines[ourIdx]);
          let modified = false;
          if (priceIdx >= 0 && price && (!ourParts[priceIdx] || ourParts[priceIdx] === '' || ourParts[priceIdx] === '0')) {
            ourParts[priceIdx] = String(price);
            modified = true;
          }
          if (modified) { existing.lines[ourIdx] = ourParts.join(','); updated++; }
        } else if (price) {
          const newParts = existing.header.map(() => '');
          newParts[nameIdx] = `"${name}"`;
          if (priceIdx >= 0) newParts[priceIdx] = String(price);
          existing.lines.push(newParts.join(','));
          added++;
        }
      }

      fs.writeFileSync(targetPath, existing.lines.join('\n'), 'utf-8');
      log(`  ${targetFile}: ${updated} updated, ${added} added`);
    } catch (e) {
      log(`  ${sourceKey}: error - ${e.message}`);
    }
  }
}

// ─── Price Scraping ──────────────────────────────────────────────

const PRICE_RETAILERS = {
  'scan.co.uk': (q) => `https://www.scan.co.uk/search#q=${encodeURIComponent(q)}`,
  'overclockers.co.uk': (q) => `https://www.overclockers.co.uk/search?search=${encodeURIComponent(q)}`,
  'box.co.uk': (q) => `https://www.box.co.uk/search?q=${encodeURIComponent(q)}`,
  'ebuyer.com': (q) => `https://www.ebuyer.com/search?q=${encodeURIComponent(q)}`,
  'amazon.co.uk': (q) => `https://www.amazon.co.uk/s?k=${encodeURIComponent(q)}`,
  'cclonline.com': (q) => `https://www.cclonline.com/catalogsearch/result/?q=${encodeURIComponent(q)}`,
  'novatech.co.uk': (q) => `https://www.novatech.co.uk/search/?q=${encodeURIComponent(q)}`,
  'awd-it.co.uk': (q) => `https://www.awd-it.co.uk/catalogsearch/result/?q=${encodeURIComponent(q)}`,
};

async function fetchWithTimeout(url, timeout = 8000) {
  const controller = new AbortController();
  const id = setTimeout(() => controller.abort(), timeout);
  try {
    const res = await fetch(url, { signal: controller.signal, headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' } });
    clearTimeout(id); return res;
  } catch (e) { clearTimeout(id); throw e; }
}

async function scrapeRetailerPrices(name) {
  const searchName = name.replace(/[^\w\s]/g, ' ').trim().substring(0, 100);
  const retailerPrices = [];
  for (const [retailer, urlFunc] of Object.entries(PRICE_RETAILERS)) {
    let retailerLowest = Infinity;
    try {
      const url = urlFunc(searchName);
      const response = await fetchWithTimeout(url, 8000);
      if (!response.ok) continue;
      const html = await response.text();

      const priceMatches = html.match(/\xA3\s*(\d+(?:,\d{3})*(?:\.\d{2})?)/g);
      if (priceMatches) {
        for (const pm of priceMatches) {
          const price = parseFloat(pm.replace(/\xA3|,/g, ''));
          if (price > 1 && price < 20000 && price < retailerLowest) retailerLowest = price;
        }
      }

      const jsonLdMatches = html.matchAll(/<script[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi);
      for (const jm of jsonLdMatches) {
        try {
          const obj = JSON.parse(jm[1]);
          const offers = obj.offers || obj.mainEntity?.offers;
          if (offers) {
            const price = parseFloat(offers.price || offers.lowPrice || 0);
            if (price > 1 && price < 20000 && price < retailerLowest) retailerLowest = price;
          }
        } catch {}
      }
    } catch {}

    if (retailerLowest < Infinity) retailerPrices.push(retailerLowest);
  }
  return retailerPrices;
}

async function scrapeMissingPrices() {
  log('\n=== Phase: Scrape Missing Prices ===');
  const csvFiles = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  let totalChecked = 0, totalUpdated = 0, totalSkipped = 0;

  for (const file of csvFiles) {
    const csvPath = path.join(DATA_DIR, file);
    const csv = readCSV(csvPath);
    if (!csv) continue;
    const nameIdx = csv.header.indexOf('name');
    const priceIdx = csv.header.indexOf('price');
    if (nameIdx < 0 || priceIdx < 0) continue;

    let updated = 0, skipped = 0;
    const rows = [];
    for (let i = 1; i < csv.lines.length; i++) {
      const parts = parseCSVLine(csv.lines[i]);
      const name = (parts[nameIdx] || '').trim();
      const currentPrice = parts[priceIdx] ? parseFloat(parts[priceIdx]) : NaN;
      rows.push({ name, currentPrice, parts, lineIdx: i });
    }

    for (const row of rows) {
      if (row.currentPrice && !isNaN(row.currentPrice) && row.currentPrice > 0) {
        skipped++;
        continue;
      }
      totalChecked++;
      const prices = await scrapeRetailerPrices(row.name);
      if (prices.length > 0) {
        const avgPrice = prices.reduce((a, b) => a + b, 0) / prices.length;
        row.parts[priceIdx] = avgPrice.toFixed(2);
        csv.lines[row.lineIdx] = row.parts.join(',');
        updated++;
        totalUpdated++;
        log(`  [PRICE] ${file.replace('.csv','')}: ${row.name} => £${avgPrice.toFixed(2)}`);
      }
    }

    if (updated > 0) {
      fs.writeFileSync(csvPath, csv.lines.join('\n'), 'utf-8');
      log(`  ${file}: ${updated} prices updated, ${skipped} already had prices`);
    } else {
      log(`  ${file}: ${skipped} already had prices, 0 missing`);
    }
  }
  log(`  Total: ${totalUpdated} prices updated, ${totalSkipped + (totalChecked - totalUpdated)} already had prices`);
}

// ─── Main ────────────────────────────────────────────────────────

async function runUpdate() {
  state.status = 'running';
  state.restartCount = (state.restartCount || 0) + 1;
  state.lastRun = Date.now();
  state.pid = process.pid;
  saveState();

  log(`Running price/data update (attempt ${state.restartCount})...`);

  try {
    state.phase = 'Version Bump'; saveState();
    bumpVersion();

    state.phase = 'Import Apify Data'; saveState();
    await importApifyData();

    state.phase = 'Import PCPP CSVs'; saveState();
    await importPCPPData();

    state.phase = 'Import Docyx Data'; saveState();
    await importDocyxData();

    state.phase = 'Import Open Source Datasets'; saveState();
    await importOpenSourceDatasets();

    state.phase = 'Scrape Missing Prices'; saveState();
    await scrapeMissingPrices();

    state.status = 'complete';
    state.lastSuccess = Date.now();
    state.restartCount = 0;
    state.phase = 'All data & prices updated';
    log('Price/data update complete.');
  } catch (err) {
    log(`Update failed: ${err.message}`);
    state.status = 'error';
    state.phase = `Error: ${err.message}`;
  }
  saveState();
}

function main() {
  const args = process.argv.slice(2);
  if (args.includes('--help') || args.includes('-h')) {
    console.log(`
Usage: node scripts/priceupd.js [options]

Options:
  --help            Show this help
    `);
    process.exit(0);
  }

  loadState();
  runUpdate().then(() => process.exit(0)).catch(err => {
    log(`FATAL: ${err.message}`);
    process.exit(1);
  });
}

main();
