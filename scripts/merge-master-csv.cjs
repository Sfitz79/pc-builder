const fs = require('fs');
const path = require('path');

const DATA_DIR = path.join(__dirname, '..', 'src', 'data');
const SCRAPED_DIR = path.join(__dirname, '..', 'scraped_data');
const MASTER_DIR = path.join(__dirname, '..', '..', 'pc-builder-restore', 'pc-builder', 'src', 'data');

const APIFY_TO_MASTER_FILE = {
  'cpu.json': 'cpu.csv',
  'cooler.json': 'cooler.csv',
  'motherboard.json': 'motherboard.csv',
  'ram.json': 'ram.csv',
};

function escapeCSV(value) {
  if (value === null || value === undefined) return '';
  const str = String(value);
  if (str.includes(',') || str.includes('"') || str.includes('\n')) {
    return `"${str.replace(/"/g, '""')}"`;
  }
  return str;
}

function parseCSVLine(line) {
  const values = [];
  let current = '';
  let inQuotes = false;
  for (let i = 0; i < line.length; i++) {
    const char = line[i];
    const next = line[i + 1];
    if (char === '"') {
      if (inQuotes && next === '"') { current += '"'; i++; }
      else { inQuotes = !inQuotes; }
      continue;
    }
    if (char === ',' && !inQuotes) { values.push(current); current = ''; continue; }
    current += char;
  }
  values.push(current);
  return values;
}

function parseCSV(text) {
  const lines = text.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
  if (lines.length < 2) return { headers: [], rows: [] };
  const headers = parseCSVLine(lines[0]);
  const rows = [];
  for (let i = 1; i < lines.length; i++) {
    const vals = parseCSVLine(lines[i]);
    const row = {};
    headers.forEach((h, idx) => { row[h] = (vals[idx] || '').trim(); });
    rows.push(row);
  }
  return { headers, rows };
}

function rowsToCSV(headers, rows) {
  const lines = [headers.join(',')];
  for (const row of rows) {
    const vals = headers.map(h => escapeCSV(row[h] ?? ''));
    lines.push(vals.join(','));
  }
  return lines.join('\n');
}

function getNestedValue(obj, path) {
  return path.split('.').reduce((acc, key) => {
    if (acc && typeof acc === 'object') {
      const exact = acc[key];
      if (exact !== undefined) return exact;
      const found = Object.keys(acc).find(k => k.toLowerCase() === key.toLowerCase());
      return found ? acc[found] : undefined;
    }
    return undefined;
  }, obj);
}

const APIFY_FIELD_MAP = {
  cpu: {
    name: 'productName',
    price: item => item.price !== null && item.price !== undefined ? parseFloat(item.price).toFixed(2) : '',
    core_count: item => getNestedValue(item, 'specs.coreCount') || '',
    core_clock: item => getNestedValue(item, 'specs.performanceCoreClock') || '',
    boost_clock: item => getNestedValue(item, 'specs.performanceCoreBoostClock') || '',
    microarchitecture: item => getNestedValue(item, 'specs.microarchitecture') || '',
    tdp: item => getNestedValue(item, 'specs.tdp') || '',
    graphics: item => getNestedValue(item, 'specs.integratedGraphics') || '',
    image: item => item.imageUrl || '',
  },
  cooler: {
    name: 'productName',
    price: item => item.price !== null && item.price !== undefined ? parseFloat(item.price).toFixed(2) : '',
    rpm: item => getNestedValue(item, 'specs.fanRpm') || '',
    noise_level: item => getNestedValue(item, 'specs.noiseLevel') || '',
    color: item => getNestedValue(item, 'specs.color') || '',
    size: item => '',
    image: item => item.imageUrl || '',
  },
  motherboard: {
    name: 'productName',
    price: item => item.price !== null && item.price !== undefined ? parseFloat(item.price).toFixed(2) : '',
    socket: item => getNestedValue(item, 'specs.socket/Cpu') || '',
    form_factor: item => getNestedValue(item, 'specs.formFactor') || '',
    max_memory: item => getNestedValue(item, 'specs.memoryMax') || '',
    memory_slots: item => getNestedValue(item, 'specs.memorySlots') || '',
    color: item => getNestedValue(item, 'specs.color') || '',
    wifi: item => '',
    usb_c: item => '',
    image: item => item.imageUrl || '',
  },
  ram: {
    name: 'productName',
    price: item => item.price !== null && item.price !== undefined ? parseFloat(item.price).toFixed(2) : '',
    speed: item => getNestedValue(item, 'specs.speed') || '',
    modules: item => getNestedValue(item, 'specs.modules') || '',
    price_per_gb: item => getNestedValue(item, 'specs.price/Gb') || '',
    color: item => getNestedValue(item, 'specs.color') || '',
    first_word_latency: item => getNestedValue(item, 'specs.firstWordLatency') || '',
    cas_latency: item => getNestedValue(item, 'specs.casLatency') || '',
    image: item => item.imageUrl || '',
  },
};

const CATEGORY_HEADERS = {
  cpu: ['name', 'price', 'core_count', 'core_clock', 'boost_clock', 'microarchitecture', 'tdp', 'graphics', 'image'],
  cooler: ['name', 'price', 'rpm', 'noise_level', 'color', 'size', 'image'],
  motherboard: ['name', 'price', 'socket', 'form_factor', 'max_memory', 'memory_slots', 'color', 'wifi', 'usb_c', 'image'],
  ram: ['name', 'price', 'speed', 'modules', 'price_per_gb', 'color', 'first_word_latency', 'cas_latency', 'image'],
};

async function main() {
  // Backup current CSVs first
  const backupDir = path.join(__dirname, '..', 'scraped_data', 'fallback');
  if (!fs.existsSync(backupDir)) fs.mkdirSync(backupDir, { recursive: true });

  // Step 1: Copy all master CSVs to data dir
  const masterFiles = fs.readdirSync(MASTER_DIR).filter(f => f.endsWith('.csv'));
  for (const file of masterFiles) {
    const src = path.join(MASTER_DIR, file);
    const dst = path.join(DATA_DIR, file);
    // Backup current if exists
    if (fs.existsSync(dst)) {
      fs.copyFileSync(dst, path.join(backupDir, file));
    }
    fs.copyFileSync(src, dst);
    const lines = fs.readFileSync(src, 'utf-8').split(/\r?\n/).filter(Boolean).length - 1;
    console.log(`[MASTER] ${file}: ${lines} rows`);
  }

  // Step 2: Overlay Apify data for scraped categories (only available items)
  for (const [apifyFile, csvFile] of Object.entries(APIFY_TO_MASTER_FILE)) {
    const apifyPath = path.join(SCRAPED_DIR, apifyFile);
    if (!fs.existsSync(apifyPath)) {
      console.log(`[SKIP] ${apifyFile}: not found`);
      continue;
    }

    let raw = fs.readFileSync(apifyPath, 'utf-8');
    if (raw.charCodeAt(0) === 0xFEFF) raw = raw.slice(1);
    const items = JSON.parse(raw);
    if (!Array.isArray(items) || items.length === 0) {
      console.log(`[SKIP] ${apifyFile}: empty`);
      continue;
    }

    const categoryKey = csvFile.replace('.csv', '');
    const fieldMap = APIFY_FIELD_MAP[categoryKey];
    const headers = CATEGORY_HEADERS[categoryKey];
    if (!fieldMap || !headers) {
      console.log(`[SKIP] ${csvFile}: no mapping defined`);
      continue;
    }

    const rows = items.map(item => {
      const row = {};
      for (const [csvField, apifyAccessor] of Object.entries(fieldMap)) {
        if (typeof apifyAccessor === 'function') {
          row[csvField] = apifyAccessor(item);
        } else {
          row[csvField] = item[apifyAccessor] || '';
        }
      }
      return row;
    });

    const csv = rowsToCSV(headers, rows);
    const dst = path.join(DATA_DIR, csvFile);
    // Backup current (which is now the master copy)
    if (fs.existsSync(dst)) {
      fs.copyFileSync(dst, path.join(backupDir, `${csvFile}.master`));
    }
    fs.writeFileSync(dst, csv, 'utf-8');
    console.log(`[APIFY] ${csvFile}: ${rows.length} available items`);
  }

  console.log('\nDone!');
}

main().catch(console.error);
