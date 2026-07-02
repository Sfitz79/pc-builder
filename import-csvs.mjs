/**
 * IMPORT CSVs - Parse downloaded PCPartPicker CSVs and save clean data to src/data/ and scraped_data/
 * Extracts image URLs from name4 column, parses LabelValue specs, writes clean CSV + JSON
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.join(__dirname, 'src', 'data');
const SCRAPED_DIR = path.join(__dirname, 'scraped_data');
const DOWNLOADED_CSV_DIR = 'C:\\Users\\simon\\Downloads';

const CSV_MAP = [
  { file: 'uk-pcpartpicker-com-2026-06-09.csv', category: 'cpu' },
  { file: 'uk-pcpartpicker-com-2026-06-09 (1).csv', category: 'cooler' },
  { file: 'uk-pcpartpicker-com-2026-06-09 (2).csv', category: 'ram' },
  { file: 'uk-pcpartpicker-com-2026-06-09 (3).csv', category: 'storage' },
  { file: 'uk-pcpartpicker-com-2026-06-09 (4).csv', category: 'storage' },
  { file: 'uk-pcpartpicker-com-2026-06-09 (5).csv', category: 'gpu' },
  { file: 'uk-pcpartpicker-com-2026-06-09 (6).csv', category: 'power-supply' },
];

const SKIP_COLUMNS = new Set(['web_scraper_order', 'web_scraper_start_url', 'pagination', 'name2', 'name3']);
const PRICE_COLS = ['price', 'price2', 'price3', 'price4'];
const RATING_COLS = ['rating', 'name3'];

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

function deriveLabel(columnName) {
  return columnName.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
}

function parseSpecValue(columnName, rawValue) {
  if (!rawValue) return '';
  const label = deriveLabel(columnName);
  let v = rawValue.trim();
  if (v.length >= label.length && v.substring(0, label.length).toLowerCase() === label.toLowerCase()) {
    v = v.substring(label.length).trim();
  }
  // Strip trailing units (GHz, MHz, W, mm, TB, GB, etc.) so app doesn't double-add them
  if (/^\d/.test(v)) {
    v = v.replace(UNIT_RE, '');
  }
  return v;
}

function extractPrice(rawValue) {
  if (!rawValue) return null;
  const m = rawValue.match(/[£€$]\s*(\d+(?:,\d{3})*(?:\.\d{1,2})?)/);
  if (m) return parseFloat(m[1].replace(/,/g, ''));
  const n = rawValue.match(/(\d+(?:\.\d{1,2})?)/);
  if (n) { const p = parseFloat(n[1]); if (p > 1 && p < 20000) return p; }
  return null;
}

const SNES = {
  'performance_core_clock': 'core_clock',
  'performance_core_boost_clock': 'boost_clock',
  'core_count': 'core_count',
  'microarchitecture': 'microarchitecture',
  'integrated_graphics': 'integrated_graphics',
  'tdp': 'tdp',
  'chipset': 'chipset',
  'memory': 'memory',
  'core_clock': 'core_clock',
  'boost_clock': 'boost_clock',
  'color': 'color',
  'length': 'length',
  'wattage': 'wattage',
  'efficiency_rating': 'efficiency_rating',
  'modular': 'modular',
  'type': 'type',
  'capacity': 'capacity',
  'interface': 'interface',
  'price_gb': 'price_gb',
  'cache': 'cache',
  'form_factor': 'form_factor',
  'speed': 'speed',
  'modules': 'modules',
  'first_word_latency': 'first_word_latency',
  'cas_latency': 'cas_latency',
  'fan_rpm': 'fan_rpm',
  'noise_level': 'noise_level',
  'radiator_size': 'radiator_size',
};

const UNIT_RE = /\s+(GHz|MHz|W|mm|cm|TB|GB|MB|KB)\s*$/i;

function parseCategory(category) {
  const entries = CSV_MAP.filter(e => e.category === category);
  if (entries.length === 0) return [];
  const seen = new Set();
  const items = [];
  for (const entry of entries) {
    const fp = path.join(DOWNLOADED_CSV_DIR, entry.file);
    if (!fs.existsSync(fp)) { console.log(`  Skipping ${entry.file} - not found`); continue; }
    const content = fs.readFileSync(fp, 'utf-8');
    const lines = content.split(/\r?\n/).filter(l => l.trim());
    if (lines.length < 2) continue;
    let hi = 0;
    if (lines.length >= 2 && lines[0] === lines[1]) hi = 1;
    const header = parseCSVLine(lines[hi]);
    const specCols = [];
    for (let i = 0; i < header.length; i++) {
      const h = header[i].trim();
      if (!h || SKIP_COLUMNS.has(h)) continue;
      if (h === 'name' || h === 'name4' || PRICE_COLS.includes(h) || RATING_COLS.includes(h)) continue;
      if (h.endsWith('2')) continue;
      specCols.push({ i, n: h });
    }
    for (let i = hi + 1; i < lines.length; i++) {
      const parts = parseCSVLine(lines[i]);
      if (parts.length < header.length) continue;
      const name = (parts[header.indexOf('name')] || '').trim();
      if (!name || seen.has(name)) continue;
      seen.add(name);
      const n4 = header.indexOf('name4');
      const imageUrl = (n4 >= 0 && n4 < parts.length) ? (parts[n4] || '').trim() : '';
      let price = null;
      for (const pc of PRICE_COLS) {
        const idx = header.indexOf(pc);
        if (idx >= 0 && idx < parts.length) { price = extractPrice(parts[idx]); if (price) break; }
      }
      const specs = {};
      for (const col of specCols) {
        if (col.i < parts.length) {
          const val = parseSpecValue(col.n, parts[col.i] || '');
          if (val) specs[col.n] = val;
        }
      }
      items.push({ name, price, imageUrl, specs, category });
    }
    console.log(`  ${entry.file}: ${seen.size} unique (${items.length} total)`);
  }
  return items;
}

function saveJSON(category, items) {
  if (!fs.existsSync(SCRAPED_DIR)) fs.mkdirSync(SCRAPED_DIR, { recursive: true });
  const fp = path.join(SCRAPED_DIR, `${category}.json`);
  const data = items.map(item => ({
    name: item.name,
    category,
    country: 'uk',
    price: item.price,
    priceCurrency: item.price ? '£' : null,
    imageUrl: item.imageUrl || null,
    specs: item.specs,
    scrapedAt: new Date().toISOString(),
    source: 'downloaded-csv',
  }));
  fs.writeFileSync(fp, JSON.stringify(data, null, 2), 'utf-8');
  console.log(`  Wrote ${data.length} items to ${category}.json`);
}

function saveCSV(category, items) {
  if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
  const fp = path.join(DATA_DIR, `${category}.csv`);
  // Collect all spec keys across items, renaming with SNES map
  const specKeys = new Set();
  const renameMap = {};
  for (const item of items) {
    for (const [orig, renamed] of Object.entries(SNES)) {
      if (item.specs[orig] !== undefined) {
        renameMap[orig] = renamed;
        specKeys.add(renamed);
      }
    }
    for (const key of Object.keys(item.specs)) {
      if (!SNES[key]) specKeys.add(key);
    }
  }
  const headers = ['name', 'price', 'image', ...Array.from(specKeys).sort()];
  const csvLines = [headers.join(',')];
  for (const item of items) {
    const row = headers.map(h => {
      if (h === 'name') return item.name || '';
      if (h === 'price') return item.price !== null ? item.price.toString() : '';
      if (h === 'image') return item.imageUrl || '';
      // Find original spec key that maps to this header
      for (const [orig, renamed] of Object.entries(SNES)) {
        if (renamed === h && item.specs[orig] !== undefined) return item.specs[orig];
      }
      if (item.specs[h] !== undefined) return item.specs[h];
      return '';
    });
    // Quote values containing commas
    const quoted = row.map(v => v.includes(',') || v.includes('"') ? `"${v.replace(/"/g, '""')}"` : v);
    csvLines.push(quoted.join(','));
  }
  fs.writeFileSync(fp, csvLines.join('\n'), 'utf-8');
  console.log(`  Wrote ${items.length} items to ${category}.csv`);
}

// Main
console.log('\n=== IMPORT CSVs - PCPartPicker Data Pipeline ===\n');
const categories = [...new Set(CSV_MAP.map(e => e.category))];
let total = 0;
for (const cat of categories) {
  console.log(`[${cat}]`);
  const items = parseCategory(cat);
  if (items.length === 0) { console.log('  No items found\n'); continue; }
  saveJSON(cat, items);
  saveCSV(cat, items);
  total += items.length;
  console.log('');
}
console.log(`=== Done: ${total} products across ${categories.length} categories ===`);
