/**
 * MERGE SCRAPED JSON → src/data CSVs
 *
 * Regenerates src/data/*.csv (clean format) from the Byparr-scraped
 * scraped_data/*.json datasets, making them the single source of truth.
 *
 * - Clean format: no web_scraper_order column, headers are app field names,
 *   so loadCSV.js parseClean maps them directly to item keys.
 * - Dedupes by case-insensitive productName (prefers price > image > rating).
 * - Splits storage.json into ssd.csv (type "SSD") and mass-storage.csv (rest).
 * - Writes WITHOUT BOM.
 *
 * Usage: node merge-scraped-json-to-csv.mjs
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SCRAPED_DIR = path.join(__dirname, 'scraped_data');
const DATA_DIR = path.join(__dirname, 'src', 'data');

const SPEC_KEY = {
  name: 'productName',
  price: 'price',
  image: 'imageUrl',
};

// ─── Catalog shaping: curated + top-selling caps ───────────────────────────
//
// Core categories (cpu, gpu, motherboard, ram, storage) keep every item.
// Every other category is capped to the TOP_SELLING_CAP "best" items, ranked
// by (has price, most recent price check, then original order). Cases are
// additionally restricted to the curated modern-case list in
// modern_pc_parts.json before ranking.
const TOP_SELLING_CAP = 200;
const CAPPED_CATEGORIES = new Set([
  'case', 'power-supply', 'cooler', 'case-fan', 'monitor', 'keyboard',
  'mouse', 'headphones', 'speakers', 'webcam', 'external-hard-drive', 'os',
  'optical-drive', 'ups', 'fan-controller', 'thermal-paste',
  'wired-network-card', 'wireless-network-card', 'sound-card', 'case-accessory',
]);

const MODERN_CASES = (() => {
  try {
    const parts = JSON.parse(fs.readFileSync(path.join(__dirname, 'modern_pc_parts.json'), 'utf-8')).modern_relevant_pc_tech;
    return (parts.cases || []).map(t => String(t).toUpperCase());
  } catch {
    return [];
  }
})();

function rankSelling(item) {
  const hasPrice = item.price != null && item.price !== '' ? 1 : 0;
  const t = Date.parse(item.priceCheckedAt || item.priceUpdatedAt || '');
  return (hasPrice * 1e15) + (t > 0 ? t : 0);
}

function shapeCatalog(items, categoryKey) {
  if (categoryKey === 'case') {
    items = items.filter(i => MODERN_CASES.some(t => String(i.productName || '').toUpperCase().includes(t)));
  }
  return items;
}

// After dedup: cap capped categories to the top-selling items (exactly 200).
function capKept(kept, categoryKey) {
  if (categoryKey === 'case') return capCaseKept(kept);
  if (!CAPPED_CATEGORIES.has(categoryKey) || kept.size <= TOP_SELLING_CAP) return kept;
  const entries = [...kept.entries()]
    .sort((a, b) => rankSelling(b[1]) - rankSelling(a[1]))
    .slice(0, TOP_SELLING_CAP);
  return new Map(entries);
}

// Cases: after the curated modern-case filter, also guarantee coverage per
// motherboard form factor (ATX / mATX / ITX) and per size/form factor
// (full/mid/mini tower, desktop, slim, HTPC, test bench, rackmount, generic
// tower), keeping the top CASE_GROUP_PER selling items in each group. The
// union is still bounded by TOP_SELLING_CAP.
const CASE_GROUP_PER = 20;

function caseGroups(item) {
  const t = String(item.specs?.type || item.type || '').toLowerCase();
  const groups = [];
  if (t.includes('microatx')) groups.push('mb:matx');
  else if (t.includes('mini itx')) groups.push('mb:itx');
  else if (t.includes('atx')) groups.push('mb:atx');
  if (t.includes('full tower')) groups.push('size:full');
  else if (t.includes('mid tower')) groups.push('size:mid');
  else if (t.includes('mini tower')) groups.push('size:mini');
  else if (t.includes('rackmount')) groups.push('size:rack');
  else if (t.includes('test bench')) groups.push('size:bench');
  else if (t.includes('desktop')) groups.push('size:desktop');
  else if (t.includes('slim')) groups.push('size:slim');
  else if (t.includes('htpc')) groups.push('size:htpc');
  else if (t.includes('tower')) groups.push('size:tower');
  return groups;
}

function capCaseKept(kept) {
  const entries = [...kept.entries()].sort((a, b) => rankSelling(b[1]) - rankSelling(a[1]));
  const selected = new Map();
  const perGroup = {};
  for (const [key, item] of entries) {
    for (const g of caseGroups(item)) {
      perGroup[g] = (perGroup[g] || 0) + 1;
      if (perGroup[g] <= CASE_GROUP_PER && !selected.has(key)) selected.set(key, item);
    }
  }
  return selected.size > TOP_SELLING_CAP ? new Map([...selected].slice(0, TOP_SELLING_CAP)) : selected;
}

// scraped spec key → output column (order matters for column layout)
export const CATEGORY_DEFS = {
  'cpu.json': {
    out: ['cpu.csv'],
    cols: ['name', 'price', 'image', 'core_count', 'core_clock', 'boost_clock', 'microarchitecture', 'tdp', 'graphics', 'rating'],
    map: {
      core_count: 'coreCount',
      core_clock: 'performanceCoreClock',
      boost_clock: 'performanceCoreBoostClock',
      microarchitecture: 'microarchitecture',
      tdp: 'tdp',
      graphics: 'integratedGraphics',
    },
  },
  'motherboard.json': {
    out: ['motherboard.csv'],
    cols: ['name', 'price', 'image', 'socket', 'form_factor', 'memory_max', 'memory_slots', 'color', 'rating', 'rating_count'],
    map: {
      socket: 'socketCPU',
      form_factor: 'formFactor',
      memory_max: 'memoryMax',
      memory_slots: 'memorySlots',
      color: 'color',
      rating_count: 'ratingCount',
    },
  },
  'ram.json': {
    out: ['ram.csv'],
    cols: ['name', 'price', 'image', 'speed', 'ram_type', 'modules', 'capacity', 'first_word_latency', 'cas_latency', 'price_gb', 'color', 'rating'],
    map: {
      speed: 'speed',
      modules: 'modules',
      first_word_latency: 'firstWordLatency',
      cas_latency: 'cASLatency',
      price_gb: 'priceGB',
      color: 'color',
    },
    derive(item) {
      const speed = String(item.specs.speed || '');
      const m = speed.match(/^(DDR\d*)/i);
      if (m) item.ram_type = m[1].toUpperCase();
      const mod = String(item.specs.modules || '');
      const modMatch = mod.match(/(\d+)\s*x\s*(\d+)\s*GB/i);
      if (modMatch) item.capacity = (parseInt(modMatch[1], 10) * parseInt(modMatch[2], 10)) + 'GB';
    },
  },
  'case.json': {
    out: ['case.csv'],
    cols: ['name', 'price', 'image', 'type', 'color', 'side_panel', 'internal_35_bays', 'power_supply', 'external_volume', 'rating'],
    map: {
      type: 'type',
      color: 'color',
      side_panel: 'sidePanel',
      internal_35_bays: 'internal35Bays',
      power_supply: 'powerSupply',
      external_volume: 'externalVolume',
    },
  },
  'cooler.json': {
    out: ['cooler.csv'],
    cols: ['name', 'price', 'image', 'fan_rpm', 'noise_level', 'color', 'radiator_size', 'size', 'rating'],
    map: {
      fan_rpm: 'fanRPM',
      noise_level: 'noiseLevel',
      color: 'color',
      radiator_size: 'radiatorSize',
    },
    derive(item) {
      const rad = item.specs.radiatorSize;
      item.size = rad || '';
    },
  },
  'gpu.json': {
    out: ['gpu.csv'],
    cols: ['name', 'price', 'image', 'chipset', 'memory', 'core_clock', 'boost_clock', 'color', 'length', 'rating'],
    map: {
      chipset: 'chipset',
      memory: 'memory',
      core_clock: 'coreClock',
      boost_clock: 'boostClock',
      color: 'color',
      length: 'length',
    },
  },
  'storage.json': {
    out: ['ssd.csv', 'mass-storage.csv'],
    combinedOut: 'storage.csv',
    cols: ['name', 'price', 'image', 'capacity', 'interface', 'type', 'form_factor', 'cache', 'price_gb', 'rating'],
    map: {
      capacity: 'capacity',
      interface: 'interface',
      type: 'type',
      form_factor: 'formFactor',
      cache: 'cache',
      price_gb: 'priceGB',
    },
    split(item) {
      return String(item.specs.type || '').toLowerCase().includes('ssd') ? 'ssd.csv' : 'mass-storage.csv';
    },
  },
  'power-supply.json': {
    out: ['power-supply.csv'],
    cols: ['name', 'price', 'image', 'type', 'wattage', 'efficiency', 'modular', 'color', 'rating'],
    map: {
      type: 'type',
      wattage: 'wattage',
      efficiency: 'efficiencyRating',
      modular: 'modular',
      color: 'color',
    },
  },
  'case-fan.json': {
    out: ['case-fan.csv'],
    cols: ['name', 'price', 'image', 'airflow', 'color', 'noise_level', 'pwm', 'rpm', 'size', 'rating'],
    map: {
      airflow: 'airflow',
      color: 'color',
      noise_level: 'noiseLevel',
      pwm: 'pwm',
      rpm: 'rpm',
      size: 'size',
    },
  },
  'fan-controller.json': {
    out: ['fan-controller.csv'],
    cols: ['name', 'price', 'image', 'channels', 'channel_wattage', 'pwm_(4-pin)', 'form_factor', 'color', 'rating'],
    map: {
      channels: 'channels',
      channel_wattage: 'channelWattage',
      'pwm_(4-pin)': 'pWM4Pin)',
      form_factor: 'formFactor',
      color: 'color',
    },
  },
  'monitor.json': {
    out: ['monitor.csv'],
    cols: ['name', 'price', 'image', 'screen_size', 'resolution', 'refresh_rate', 'response_time', 'panel_type', 'aspect_ratio', 'rating'],
    map: {
      screen_size: 'screenSize',
      resolution: 'resolution',
      refresh_rate: 'refreshRate',
      response_time: 'responseTimeG2G)',
      panel_type: 'panelType',
      aspect_ratio: 'aspectRatio',
    },
  },
  'os.json': {
    out: ['os.csv'],
    cols: ['name', 'price', 'image', 'mode', 'maximum_supported_memory', 'rating'],
    map: {
      mode: 'mode',
      maximum_supported_memory: 'maximumSupportedMemory',
    },
  },
  'keyboard.json': {
    out: ['keyboard.csv'],
    cols: ['name', 'price', 'image', 'style', 'backlit', 'connection_type', 'color', 'switch_type', 'tenkeyless', 'rating'],
    map: {
      style: 'style',
      backlit: 'backlit',
      connection_type: 'connectionType',
      color: 'color',
      switch_type: 'switchType',
      tenkeyless: 'tenkeyless',
    },
  },
  'mouse.json': {
    out: ['mouse.csv'],
    cols: ['name', 'price', 'image', 'connection_type', 'color', 'maximum_dpi', 'tracking_method', 'hand_orientation', 'rating'],
    map: {
      connection_type: 'connectionType',
      color: 'color',
      maximum_dpi: 'maximumDPI',
      tracking_method: 'trackingMethod',
      hand_orientation: 'handOrientation',
    },
  },
  'speakers.json': {
    out: ['speakers.csv'],
    cols: ['name', 'price', 'image', 'configuration', 'total_wattage', 'frequency_response', 'color', 'rating'],
    map: {
      configuration: 'configuration',
      total_wattage: 'totalWattage',
      frequency_response: 'frequencyResponse',
      color: 'color',
    },
  },
  'headphones.json': {
    out: ['headphones.csv'],
    cols: ['name', 'price', 'image', 'type', 'frequency_response', 'microphone', 'wireless', 'enclosure_type', 'color', 'rating'],
    map: {
      type: 'type',
      frequency_response: 'frequencyResponse',
      microphone: 'microphone',
      wireless: 'wireless',
      enclosure_type: 'enclosureType',
      color: 'color',
    },
  },
  'webcam.json': {
    out: ['webcam.csv'],
    cols: ['name', 'price', 'image', 'resolution', 'connection', 'focus_type', 'operating_system', 'fov_angle', 'rating'],
    map: {
      resolution: 'resolution',
      connection: 'connection',
      focus_type: 'focusType',
      operating_system: 'operatingSystem',
      fov_angle: 'fOVAngle',
    },
  },
  'sound-card.json': {
    out: ['sound-card.csv'],
    cols: ['name', 'price', 'image', 'channels', 'digital_audio', 'interface', 'chipset', 'snr', 'sample_rate', 'rating'],
    map: {
      channels: 'channels',
      digital_audio: 'digitalAudio',
      interface: 'interface',
      chipset: 'chipset',
      snr: 'snr',
      sample_rate: 'sampleRate',
    },
  },
  'wired-network-card.json': {
    out: ['wired-network-card.csv'],
    cols: ['name', 'price', 'image', 'interface', 'color', 'rating'],
    map: {
      interface: 'interface',
      color: 'color',
    },
  },
  'wireless-network-card.json': {
    out: ['wireless-network-card.csv'],
    cols: ['name', 'price', 'image', 'protocol', 'interface', 'color', 'rating'],
    map: {
      protocol: 'protocol',
      interface: 'interface',
      color: 'color',
    },
  },
  'external-hard-drive.json': {
    out: ['external-hard-drive.csv'],
    cols: ['name', 'price', 'image', 'capacity', 'interface', 'type', 'price_gb', 'color', 'rating'],
    map: {
      capacity: 'capacity',
      interface: 'interface',
      type: 'type',
      price_gb: 'priceGB',
      color: 'color',
    },
  },
  'optical-drive.json': {
    out: ['optical-drive.csv'],
    cols: ['name', 'price', 'image', 'dvd', 'cd', 'dvd_write', 'cd_write', 'bd_write', 'bd', 'rating'],
    map: {
      dvd: 'dvd',
      cd: 'cd',
      dvd_write: 'dVDWrite',
      cd_write: 'cDWrite',
      bd_write: 'bDWrite',
      bd: 'bd',
    },
  },
  'thermal-paste.json': {
    out: ['thermal-paste.csv'],
    cols: ['name', 'price', 'image', 'amount', 'rating'],
    map: {
      amount: 'amount',
    },
  },
  'ups.json': {
    out: ['ups.csv'],
    cols: ['name', 'price', 'image', 'capacity_(w)', 'capacity_(va)', 'rating'],
    map: {
      'capacity_(w)': 'capacityW)',
      'capacity_(va)': 'capacityVA)',
    },
  },
  'case-accessory.json': {
    out: ['case-accessory.csv'],
    cols: ['name', 'price', 'image', 'type', 'form_factor', 'rating'],
    map: {
      type: 'type',
      form_factor: 'formFactor',
    },
  },
};

function toRow(item, def) {
  const row = {};
  for (const col of def.cols) {
    if (col === 'rating') {
      row[col] = typeof item.rating === 'number' ? String(item.rating) : '';
      continue;
    }
    const specKey = def.map[col];
    let val = '';
    if (col === 'name') val = item.productName || '';
    else if (col === 'price') val = typeof item.price === 'number' ? String(item.price) : '';
    else if (col === 'image') val = item.imageUrl || '';
    else if (specKey !== undefined) val = item.specs?.[specKey] ?? '';
    else val = item[col] ?? '';
    row[col] = String(val).trim();
  }
  return row;
}

function escapeCSV(value) {
  if (value.includes(',') || value.includes('"') || value.includes('\n')) {
    return `"${value.replace(/"/g, '""')}"`;
  }
  return value;
}

function writeCSV(filePath, header, rows) {
  const lines = [header.join(',')];
  for (const row of rows) {
    lines.push(header.map(h => escapeCSV(row[h] ?? '')).join(','));
  }
  fs.writeFileSync(filePath, lines.join('\n'), 'utf-8');
}

// Per-category merge → writes CSVs, returns kept rows
export function mergeCategory(def, items) {
  const kept = buildKeptMap(def, items);
  const perFile = {};
  for (const out of def.out) perFile[out] = [];

  for (const item of kept.values()) {
    const target = def.split ? def.split(item) : def.out[0];
    perFile[target].push(toRow(item, def));
  }

  for (const out of def.out) {
    const filePath = path.join(DATA_DIR, out);
    writeCSV(filePath, def.cols, perFile[out]);
    const withPrice = perFile[out].filter(r => r.price).length;
    const withImage = perFile[out].filter(r => r.image).length;
    console.log(`  → ${out}: ${perFile[out].length} rows (${withPrice} priced, ${withImage} with image)`);
  }

  if (def.combinedOut) {
    const combined = [];
    for (const out of def.out) combined.push(...perFile[out]);
    const filePath = path.join(DATA_DIR, def.combinedOut);
    writeCSV(filePath, def.cols, combined);
    const withPrice = combined.filter(r => r.price).length;
    const withImage = combined.filter(r => r.image).length;
    console.log(`  → ${def.combinedOut}: ${combined.length} rows (${withPrice} priced, ${withImage} with image)`);
  }

  return perFile;
}

// Dedup by (target, lower productName) keeping the richest row; returns Map
// "target|lowerName" → the kept item (derive already applied).
export function buildKeptMap(def, items) {
  const kept = new Map();
  const catKey = def.out[0].replace(/\.csv$/, '');
  items = shapeCatalog(items, catKey);
  for (const item of items) {
    const target = def.split ? def.split(item) : def.out[0];
    const key = String(item.productName || '').trim().toLowerCase();
    if (!key) continue;
    if (def.derive) def.derive(item);
    const mapKey = target + '|' + key;
    if (kept.has(mapKey)) {
      if (shouldReplace(toRow(kept.get(mapKey), def), toRow(item, def))) {
        kept.set(mapKey, item);
      }
      continue;
    }
    kept.set(mapKey, item);
  }
  return capKept(kept, catKey);
}

// Main
function main() {
  console.log('\n=== Merge Scraped JSON → src/data CSVs ===\n');

  for (const [jsonFile, def] of Object.entries(CATEGORY_DEFS)) {
    const srcPath = path.join(SCRAPED_DIR, jsonFile);
    if (!fs.existsSync(srcPath)) {
      console.log(`  SKIP ${jsonFile}: not found`);
      continue;
    }
    const items = JSON.parse(fs.readFileSync(srcPath, 'utf-8'));
    console.log(`[${jsonFile}] ${items.length} items`);
    mergeCategory(def, items);
  }

  console.log('\nDone. Regenerated CSVs are in clean format (no BOM).');
  console.log('Next: run npm run dev and verify ComponentSelector / AI build output.');
}

const isMain = process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (isMain) main();

function shouldReplace(existing, candidate) {
  const eHas = { price: existing.price, image: existing.image };
  const cHas = { price: candidate.price, image: candidate.image };
  const score = (r) => (r.price ? 2 : 0) + (r.image ? 1 : 0);
  return score(candidate) > score(existing);
}
