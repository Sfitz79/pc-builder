const fs = require('fs');
const path = require('path');

const DATA_DIR = path.join(__dirname, '..', 'src', 'data');
const SCRAPED_DIR = path.join(__dirname, '..', 'scraped_data');
const FALLBACK_DIR = path.join(__dirname, '..', 'scraped_data', 'fallback');

const CATEGORY_MAP = {
  cpu: { file: 'cpu.csv', source: 'cpu.json' },
  cooler: { file: 'cooler.csv', source: 'cooler.json' },
  motherboard: { file: 'motherboard.csv', source: 'motherboard.json' },
  ram: { file: 'ram.csv', source: 'ram.json' },
};

const FIELD_MAP = {
  cpu: {
    productName: 'name',
    price: 'price',
    'specs.coreCount': 'core_count',
    'specs.performanceCoreClock': 'core_clock',
    'specs.performanceCoreBoostClock': 'boost_clock',
    'specs.microarchitecture': 'microarchitecture',
    'specs.tdp': 'tdp',
    'specs.integratedGraphics': 'graphics',
    imageUrl: 'image',
  },
  cooler: {
    productName: 'name',
    price: 'price',
    'specs.fanRpm': 'rpm',
    'specs.noiseLevel': 'noise_level',
    'specs.color': 'color',
    imageUrl: 'image',
  },
  motherboard: {
    productName: 'name',
    price: 'price',
    'specs.socket/Cpu': 'socket',
    'specs.formFactor': 'form_factor',
    'specs.memoryMax': 'max_memory',
    'specs.memorySlots': 'memory_slots',
    'specs.color': 'color',
    imageUrl: 'image',
  },
  ram: {
    productName: 'name',
    price: 'price',
    'specs.speed': 'speed',
    'specs.modules': 'modules',
    'specs.price/Gb': 'price_per_gb',
    'specs.color': 'color',
    'specs.firstWordLatency': 'first_word_latency',
    'specs.casLatency': 'cas_latency',
    imageUrl: 'image',
  },
};

const CSV_HEADERS = {
  cpu: ['name', 'price', 'core_count', 'core_clock', 'boost_clock', 'microarchitecture', 'tdp', 'graphics', 'image'],
  cooler: ['name', 'price', 'rpm', 'noise_level', 'color', 'size', 'image'],
  motherboard: ['name', 'price', 'socket', 'form_factor', 'max_memory', 'memory_slots', 'color', 'wifi', 'usb_c', 'image'],
  ram: ['name', 'price', 'speed', 'modules', 'price_per_gb', 'color', 'first_word_latency', 'cas_latency', 'image'],
};

function getNestedValue(obj, path) {
  return path.split('.').reduce((acc, key) => {
    if (acc && typeof acc === 'object' && key in acc) return acc[key];
    const foundKey = Object.keys(acc || {}).find(k => k.toLowerCase() === key.toLowerCase());
    return foundKey ? acc[foundKey] : undefined;
  }, obj);
}

function escapeCSV(value) {
  if (value === null || value === undefined) return '';
  const str = String(value);
  if (str.includes(',') || str.includes('"') || str.includes('\n')) {
    return `"${str.replace(/"/g, '""')}"`;
  }
  return str;
}

function formatPrice(value) {
  if (value === null || value === undefined || value === '') return '';
  const num = parseFloat(value);
  return isNaN(num) ? String(value) : num.toFixed(2);
}

function cleanImageUrl(url) {
  if (!url) return '';
  return url;
}

function convertItem(category, item) {
  const fieldMap = FIELD_MAP[category];
  const headers = CSV_HEADERS[category];
  const row = {};

  for (const header of headers) {
    row[header] = '';
  }

  for (const [apifyField, csvField] of Object.entries(fieldMap)) {
    let value;
    if (apifyField.startsWith('specs.')) {
      value = getNestedValue(item, apifyField);
    } else if (apifyField === 'price') {
      value = formatPrice(item.price);
    } else if (apifyField === 'imageUrl') {
      value = cleanImageUrl(item.imageUrl);
    } else if (apifyField === 'productName') {
      value = item.productName || '';
    } else {
      value = item[apifyField];
    }

    const idx = headers.indexOf(csvField);
    if (idx !== -1) {
      row[csvField] = value !== undefined && value !== null ? value : '';
    }
  }

  return row;
}

function rowsToCSV(headers, rows) {
  const lines = [headers.join(',')];
  for (const row of rows) {
    const vals = headers.map(h => escapeCSV(row[h] ?? ''));
    lines.push(vals.join(','));
  }
  return lines.join('\n');
}

function readFallbackCSV(file) {
  const fp = path.join(FALLBACK_DIR, file);
  if (!fs.existsSync(fp)) return [];
  const text = fs.readFileSync(fp, 'utf-8');
  const lines = text.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
  if (lines.length < 2) return [];
  const headers = lines[0].split(',');
  const rows = [];
  for (let i = 1; i < lines.length; i++) {
    const vals = lines[i].split(',');
    const row = {};
    headers.forEach((h, idx) => { row[h.trim()] = (vals[idx] || '').trim(); });
    rows.push(row);
  }
  return { headers, rows };
}

async function main() {
  if (!fs.existsSync(SCRAPED_DIR)) {
    fs.mkdirSync(SCRAPED_DIR, { recursive: true });
  }
  if (!fs.existsSync(FALLBACK_DIR)) {
    fs.mkdirSync(FALLBACK_DIR, { recursive: true });
  }

  for (const [category, config] of Object.entries(CATEGORY_MAP)) {
    const jsonPath = path.join(SCRAPED_DIR, config.source);
    const csvPath = path.join(DATA_DIR, config.file);

    if (!fs.existsSync(jsonPath)) {
      console.log(`[SKIP] ${config.source} not found, keeping existing ${config.file}`);
      continue;
    }

    let raw = fs.readFileSync(jsonPath, 'utf-8');
    if (raw.charCodeAt(0) === 0xFEFF) raw = raw.slice(1);
    const items = JSON.parse(raw);

    if (!Array.isArray(items) || items.length === 0) {
      console.log(`[SKIP] ${config.source} is empty, keeping existing ${config.file}`);
      continue;
    }

    const headers = CSV_HEADERS[category];
    const rows = items.map(item => convertItem(category, item));

    const csv = rowsToCSV(headers, rows);
    fs.writeFileSync(csvPath, csv, 'utf-8');

    console.log(`[OK] ${config.file}: ${rows.length} rows written`);
  }
}

main().catch(console.error);
