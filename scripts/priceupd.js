import fs from 'fs';
import path from 'path';
import os from 'os';
import http from 'http';
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
const DASHBOARD_PORT = 3336;

const state = {
  lastRun: null, lastSuccess: null, pid: null, status: 'idle',
  restartCount: 0, phase: '', phaseNumber: 0,
  pricesChecked: 0, pricesUpdated: 0, pricesSkipped: 0, pricesFound: 0,
  totalItems: 0, completedItems: 0, currentItem: '', lastProgressTime: null,
  dashboardLogs: [],
};

function log(msg) {
  const ts = new Date().toISOString().replace('T', ' ').substring(0, 19);
  const line = `[${ts}] ${msg}`;
  console.log(line);
  try { fs.appendFileSync(LOG_FILE, line + '\n'); } catch {}
  state.dashboardLogs.push(line);
  if (state.dashboardLogs.length > 500) state.dashboardLogs.splice(0, state.dashboardLogs.length - 500);
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
    { match: ['performance_core_clock', 'core_count', 'tdp', 'integrated_graphics'], file: 'cpu.csv', urlCol: '', priceCol: 'price' },
    { match: ['chipset', 'memory', 'core_clock', 'boost_clock'], file: 'gpu.csv', urlCol: '', priceCol: 'price' },
    { match: ['fan_rpm', 'noise_level', 'radiator_size'], file: 'cooler.csv', urlCol: '', priceCol: 'price' },
    { match: ['speed', 'modules', 'cas_latency', 'first_word_latency'], file: 'ram.csv', urlCol: '', priceCol: 'price' },
    { match: ['capacity', 'interface', 'type', 'form_factor'], file: 'storage.csv', urlCol: '', priceCol: 'price' },
    { match: ['wattage', 'efficiency_rating', 'modular'], file: 'power-supply.csv', urlCol: '', priceCol: 'price' },
    { match: ['chipset', 'form_factor', 'socket'], file: 'motherboard.csv', urlCol: '', priceCol: 'price' },
    { match: ['case_fan'], file: 'case-fan.csv', urlCol: '', priceCol: 'price' },
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
    const urlIdx = cfg.urlCol ? pcppHeader.indexOf(cfg.urlCol) : -1;
    const priceIdx = pcppHeader.indexOf(cfg.priceCol);
    const pNameIdx = pcppHeader.indexOf('name');
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

// ─── Data Sources (data-sources.json) ──────────────────────

const DATA_SOURCES_FILE = path.join(ROOT, 'src', 'data-sources.json');

function getValue(obj, pathStr) {
  if (!pathStr || !obj) return undefined;
  const parts = pathStr.split('.');
  let val = obj;
  for (let p of parts) {
    if (val == null) return undefined;
    const m = p.match(/^(\w+)\[(\d+)\]$/);
    if (m) {
      val = val[m[1]];
      if (val == null) return undefined;
      val = val[parseInt(m[2])];
    } else {
      val = val[p];
    }
  }
  return val;
}

function extractPriceFromHtml(html) {
  const priceMatch = html.match(/\xA3\s*(\d+(?:,\d{3})*(?:\.\d{2})?)/);
  if (priceMatch) {
    const p = parseFloat(priceMatch[1].replace(/,/g, ''));
    if (p > 1 && p < 20000) return p;
  }
  const jsonLd = html.match(/<script[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/i);
  if (jsonLd) {
    try {
      const obj = JSON.parse(jsonLd[1]);
      const price = parseFloat(obj.offers?.price || obj.offers?.lowPrice || obj.price || 0);
      if (price > 1 && price < 20000) return price;
    } catch {}
  }
  const priceInText = html.match(/price[:\s]*\xA3\s*(\d+(?:\.\d{2})?)/i);
  if (priceInText) {
    const p = parseFloat(priceInText[1]);
    if (p > 1 && p < 20000) return p;
  }
  return null;
}

async function fetchDataSource(source) {
  const { name, type, endpoint, auth, params, mapping, token, method, responseKey } = source;

  if (auth && typeof auth === 'object') {
    const { access_key, apiKey } = auth;
    if ((access_key && access_key.startsWith('YOUR_')) || (apiKey && apiKey.startsWith('${'))) {
      log(`  ${name}: skipped (credentials not configured)`);
      return [];
    }
  }

  const headers = { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' };

  try {
    // ── scraper / msrp: search for each product query individually ──
    if (type === 'scraper' || type === 'msrp') {
      const queries = source.queries || [];
      if (queries.length === 0) return [];
      const results = [];
      for (const q of queries) {
        const url = endpoint + encodeURIComponent(q.term);
        try {
          const resp = await fetchWithTimeout(url, 10000, { headers });
          if (resp.ok) {
            const html = await resp.text();
            const price = extractPriceFromHtml(html);
            if (price) {
              results.push({ title: q.term, price: String(price), image: '', category: q.category });
            }
          }
          await new Promise(r => setTimeout(r, 500));
        } catch {}
      }
      return results;
    }

    // ── public_feed: fetch XML or JSON ──
    if (type === 'public_feed') {
      const resp = await fetchWithTimeout(endpoint, 15000, { headers });
      if (!resp.ok) { log(`  ${name}: HTTP ${resp.status}`); return []; }
      const text = await resp.text();
      const ct = (resp.headers.get('content-type') || '').toLowerCase();
      if (ct.includes('json') || text.trim().startsWith('[') || text.trim().startsWith('{')) {
        const data = JSON.parse(text);
        return Array.isArray(data) ? data : (data.products || data.results || data.items || data.data || [data]);
      }
      // XML — simple product extraction
      const items = [];
      const nameMatch = text.matchAll(/<name[^>]*>([^<]+)<\/name>/gi);
      const priceMatch = text.matchAll(/<price[^>]*>([^<]+)<\/price>/gi);
      const names = [...nameMatch].map(m => m[1]);
      const prices = [...priceMatch].map(m => parseFloat(m[1]));
      for (let i = 0; i < Math.min(names.length, prices.length); i++) {
        if (prices[i] > 0) items.push({ title: names[i], price: String(prices[i]) });
      }
      if (items.length === 0) log(`  ${name}: no products found in XML`);
      return items;
    }

    // ── google: query Custom Search API ──
    if (type === 'google') {
      const apiKey = auth?.apiKey || process.env.GOOGLE_API_KEY;
      const cx = auth?.cx || process.env.GOOGLE_CX;
      if (!apiKey || !cx || apiKey.startsWith('${')) {
        log(`  ${name}: skipped (GOOGLE_API_KEY or GOOGLE_CX not set)`);
        return [];
      }
      const queries = source.queries || [];
      if (queries.length === 0) return [];
      const items = [];
      for (const q of queries) {
        let url = `https://www.googleapis.com/customsearch/v1?key=${encodeURIComponent(apiKey)}&cx=${encodeURIComponent(cx)}&q=${encodeURIComponent(q.term)}`;
        if (source.searchType === 'image') url += '&searchType=image';
        try {
          const resp = await fetchWithTimeout(url, 10000);
          if (resp.ok) {
            const data = await resp.json();
            if (data.items) items.push(...data.items.map(i => ({ ...i, _category: q.category })));
          }
        } catch {}
        await new Promise(r => setTimeout(r, 200));
      }
      return items;
    }

    // ── api / apify_actor / feed ──
    let response;
    let url = endpoint;
    const httpMethod = (method || 'GET').toUpperCase();

    if (type === 'apify_actor') {
      if (token) url += `?token=${encodeURIComponent(token)}`;
      headers['Content-Type'] = 'application/json';
      response = await fetchWithTimeout(url, 30000, { method: 'POST', headers, body: JSON.stringify(params || {}) });
    } else if (type === 'feed') {
      if (token) url += (url.includes('?') ? '&' : '?') + `token=${encodeURIComponent(token)}`;
      response = await fetchWithTimeout(url, 30000);
    } else {
      if (typeof auth === 'string') headers['Authorization'] = auth.startsWith('Bearer ') ? auth : `Bearer ${auth}`;
      if (httpMethod === 'POST') {
        headers['Content-Type'] = 'application/json';
        response = await fetchWithTimeout(url, 30000, { method: 'POST', headers, body: JSON.stringify(params || {}) });
      } else {
        if (params) {
          const paramStr = Object.entries(params).map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&');
          url += (url.includes('?') ? '&' : '?') + paramStr;
        }
        response = await fetchWithTimeout(url, 30000, { headers });
      }
    }

    if (!response.ok) { log(`  ${name}: HTTP ${response.status}`); return []; }

    const data = await response.json();
    let items;
    if (responseKey) { items = data[responseKey] || []; }
    else { items = Array.isArray(data) ? data : (data.products || data.results || data.data || data.items || [data]); }
    if (!Array.isArray(items)) items = [items];
    return items;
  } catch (e) {
    log(`  ${name}: error - ${e.message}`);
    return [];
  }
}

function mapSourceItem(item, mapping) {
  const result = {};
  for (const [key, pathExpr] of Object.entries(mapping)) {
    if (key === 'retailers') {
      if (Array.isArray(pathExpr)) {
        result.retailers = pathExpr.map(r => ({
          name: r.name,
          price: getValue(item, r.price),
          currency: r.currency,
          url: getValue(item, r.url),
        })).filter(r => r.price != null);
      } else {
        result.retailers = getValue(item, pathExpr) || [];
      }
    } else {
      result[key] = getValue(item, pathExpr);
    }
  }
  return result;
}

const CATEGORY_CSV_MAP = {
  'cpu': 'cpu.csv', 'processor': 'cpu.csv', 'processors': 'cpu.csv',
  'gpu': 'gpu.csv', 'graphics card': 'gpu.csv', 'graphics cards': 'gpu.csv', 'video card': 'gpu.csv', 'video-card': 'gpu.csv',
  'memory': 'ram.csv', 'ram': 'ram.csv',
  'motherboard': 'motherboard.csv', 'motherboards': 'motherboard.csv',
  'storage': 'storage.csv', 'drive': 'storage.csv', 'drives': 'storage.csv', 'ssd': 'storage.csv', 'hard drive': 'storage.csv', 'internal-hard-drive': 'storage.csv',
  'cooler': 'cooler.csv', 'cpu cooler': 'cooler.csv', 'cpu-cooler': 'cooler.csv', 'cooling': 'cooler.csv',
  'case': 'case.csv', 'cases': 'case.csv', 'computer case': 'case.csv',
  'case fan': 'case-fan.csv', 'case-fan': 'case-fan.csv', 'case fans': 'case-fan.csv',
  'power supply': 'power-supply.csv', 'psu': 'power-supply.csv', 'power-supply': 'power-supply.csv',
  'monitor': 'monitor.csv', 'monitors': 'monitor.csv', 'display': 'monitor.csv',
  'keyboard': 'keyboard.csv', 'keyboards': 'keyboard.csv',
  'mouse': 'mouse.csv', 'mice': 'mouse.csv',
  'headphones': 'headphones.csv', 'headset': 'headphones.csv', 'headsets': 'headphones.csv',
  'speakers': 'speakers.csv', 'speaker': 'speakers.csv',
  'webcam': 'webcam.csv', 'webcams': 'webcam.csv', 'camera': 'webcam.csv',
  'fan controller': 'fan-controller.csv', 'fan-controller': 'fan-controller.csv',
  'thermal paste': 'thermal-paste.csv', 'thermal-paste': 'thermal-paste.csv', 'thermal compound': 'thermal-paste.csv',
  'wired network card': 'wired-network-card.csv', 'wired-network-card': 'wired-network-card.csv', 'ethernet': 'wired-network-card.csv',
  'wireless network card': 'wireless-network-card.csv', 'wireless-network-card': 'wireless-network-card.csv', 'wifi card': 'wireless-network-card.csv',
  'sound card': 'sound-card.csv', 'sound-card': 'sound-card.csv',
  'optical drive': 'optical-drive.csv', 'optical-drive': 'optical-drive.csv', 'dvd': 'optical-drive.csv',
  'os': 'os.csv', 'operating system': 'os.csv',
  'ups': 'ups.csv', 'battery': 'ups.csv',
  'case accessory': 'case-accessory.csv', 'case-accessory': 'case-accessory.csv',
  'external hard drive': 'external-hard-drive.csv', 'external-hard-drive': 'external-hard-drive.csv', 'external drive': 'external-hard-drive.csv',
};

function mapCategoryToFile(category) {
  if (!category) return null;
  const key = category.toLowerCase().trim();
  if (CATEGORY_CSV_MAP[key]) return CATEGORY_CSV_MAP[key];
  // Try partial match
  for (const [pattern, file] of Object.entries(CATEGORY_CSV_MAP)) {
    if (key.includes(pattern)) return file;
  }
  return null;
}

async function importDataSources() {
  log('\n=== Phase: Import Data Sources ===');
  if (!fs.existsSync(DATA_SOURCES_FILE)) { log('  src/data-sources.json not found, skipping'); return; }

  let config;
  try { config = JSON.parse(fs.readFileSync(DATA_SOURCES_FILE, 'utf-8')); }
  catch (e) { log(`  Failed to parse data-sources.json: ${e.message}`); return; }

  let sources = config.sources || [];
  sources = sources.filter(s => s.enabled !== false);
  if (sources.length === 0) { log('  No enabled sources configured'); return; }

  // Build dynamic queries for scraper/google/msrp sources from CSV items missing prices
  const allCsvFiles = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  const missingPriceItems = [];
  for (const file of allCsvFiles) {
    const csv = readCSV(path.join(DATA_DIR, file));
    if (!csv) continue;
    const nameIdx = csv.header.indexOf('name');
    const priceIdx = csv.header.indexOf('price');
    if (nameIdx < 0 || priceIdx < 0) continue;
    for (let i = 1; i < csv.lines.length; i++) {
      const parts = parseCSVLine(csv.lines[i]);
      const name = (parts[nameIdx] || '').trim();
      const currentPrice = parts[priceIdx] ? parseFloat(parts[priceIdx]) : NaN;
      if (name && (!currentPrice || isNaN(currentPrice) || currentPrice === 0)) {
        missingPriceItems.push({ term: name + ' UK price buy', category: file.replace('.csv', '') });
      }
    }
  }

  for (const source of sources) {
    if ((source.type === 'scraper' || source.type === 'msrp' || source.type === 'google') && missingPriceItems.length > 0) {
      source.queries = missingPriceItems;
    }
  }

  const sourceResults = await Promise.all(sources.map(async (source) => {
    const items = await fetchDataSource(source);
    return { source, items };
  }));

  // Collect all mapped products (with full fields) and a name-keyed map for CSV updates
  const allProducts = [];
  const productMap = new Map();
  for (const { source, items } of sourceResults) {
    if (items.length === 0) continue;
    let mapped = items.map(item => mapSourceItem(item, source.mapping)).filter(p => p.name);
    log(`  ${source.name}: ${mapped.length} products`);

    for (const p of mapped) {
      const retailers = Array.isArray(p.retailers) ? p.retailers : [];
      allProducts.push({
        id: p.id,
        brand: p.brand,
        model: p.model,
        category: p.category,
        name: p.name,
        image: p.image,
        mpn: p.mpn,
        sku: p.sku,
        retailers,
        source: source.name,
      });

      const key = p.name.toLowerCase().trim();
      if (!productMap.has(key) || p.price) {
        productMap.set(key, { name: p.name, price: p.price, image: p.image, category: p.category, source: p.source || source.name, mpn: p.mpn, sku: p.sku, brand: p.brand, model: p.model });
      } else {
        // merge identifiers if missing on existing entry
        const existing = productMap.get(key);
        if (p.mpn && !existing.mpn) existing.mpn = p.mpn;
        if (p.sku && !existing.sku) existing.sku = p.sku;
        if (p.brand && !existing.brand) existing.brand = p.brand;
        if (p.model && !existing.model) existing.model = p.model;
      }
    }
  }

  if (productMap.size === 0) { log('  No products fetched from any source'); return; }

  // Update CSVs
  const csvFiles = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  let totalUpdated = 0, totalAdded = 0;

  for (const file of csvFiles) {
    const csvPath = path.join(DATA_DIR, file);
    const csv = readCSV(csvPath);
    if (!csv) continue;
    csv.filePath = csvPath;
    const nameIdx = csv.header.indexOf('name');
    const priceIdx = csv.header.indexOf('price');
    const imgIdx = csv.header.indexOf('image');
    if (nameIdx < 0) continue;

    // Ensure identifier columns exist
    const idIdx = ensureIdentifierColumns(csv);

    // Build existing name index
    const existingMap = new Map();
    for (let i = 1; i < csv.lines.length; i++) {
      const parts = parseCSVLine(csv.lines[i]);
      existingMap.set((parts[nameIdx]||'').toLowerCase().trim(), { lineIdx: i, parts });
    }

    let updated = 0, added = 0;
    for (const [key, product] of productMap) {
      if (categoryMismatch(product.category, file)) continue;

      if (existingMap.has(key)) {
        const { lineIdx, parts } = existingMap.get(key);
        let modified = false;

        if (priceIdx >= 0 && product.price != null) {
          const p = parseFloat(product.price);
          if (!isNaN(p) && p > 0) {
            const current = parts[priceIdx] ? parseFloat(parts[priceIdx]) : NaN;
            if (!current || isNaN(current) || current === 0) {
              parts[priceIdx] = p.toFixed(2);
              modified = true;
            }
          }
        }

        if (imgIdx >= 0 && product.image && (!parts[imgIdx] || parts[imgIdx] === '' || parts[imgIdx] === '""')) {
          parts[imgIdx] = `"${product.image}"`;
          modified = true;
        }

        // Write identifiers if available in source and missing in CSV
        for (const col of IDENTIFIER_COLUMNS) {
          const idx = idIdx[col];
          if (idx >= 0 && product[col] && (!parts[idx] || parts[idx] === '')) {
            parts[idx] = product[col];
            modified = true;
          }
        }

        if (modified) {
          csv.lines[lineIdx] = parts.join(',');
          updated++;
        }
      } else if (product.price != null || product.image || product.mpn || product.sku) {
        // Add new row
        const newParts = csv.header.map(() => '');
        newParts[nameIdx] = `"${product.name}"`;
        if (priceIdx >= 0 && product.price != null) {
          const p = parseFloat(product.price);
          if (!isNaN(p) && p > 0) newParts[priceIdx] = p.toFixed(2);
        }
        if (imgIdx >= 0 && product.image) newParts[imgIdx] = `"${product.image}"`;
        for (const col of IDENTIFIER_COLUMNS) {
          const idx = idIdx[col];
          if (idx >= 0 && product[col]) newParts[idx] = product[col];
        }
        csv.lines.push(newParts.join(','));
        existingMap.set(key, { lineIdx: csv.lines.length - 1, parts: newParts });
        added++;
      }
    }

    if (updated > 0 || added > 0) {
      fs.writeFileSync(csvPath, csv.lines.join('\n'), 'utf-8');
      log(`  ${file}: ${updated} updated, ${added} added`);
    }
    totalUpdated += updated;
    totalAdded += added;
  }

  log(`  Sources total: ${totalUpdated} updated, ${totalAdded} added`);

  // Write merged prices.json
  if (allProducts.length > 0) {
    const mergeKey = p => `${p.sku || ''}|${p.mpn || ''}|${p.brand || ''}|${p.model || ''}`;
    const mergeMap = new Map();
    for (const p of allProducts) {
      const key = mergeKey(p);
      if (!mergeMap.has(key)) {
        mergeMap.set(key, { ...p, retailers: [...p.retailers] });
      } else {
        mergeMap.get(key).retailers.push(...p.retailers);
      }
    }

    const merged = [];
    for (const [, p] of mergeMap) {
      const validPrices = p.retailers.filter(r => r.price != null);
      const lowest = validPrices.length ? validPrices.reduce((a, b) => (a.price < b.price ? a : b)) : null;
      merged.push({
        id: p.id, brand: p.brand, model: p.model, category: p.category, name: p.name, image: p.image,
        mpn: p.mpn, sku: p.sku,
        lowest_price: lowest ? lowest.price : null,
        lowest_price_currency: lowest ? lowest.currency : 'GBP',
        lowest_price_retailer: lowest ? lowest.name : null,
        lowest_price_url: lowest ? lowest.url : null,
        stock_status: lowest ? lowest.stock : 'unknown',
        retailers: p.retailers,
      });
    }

    const pricesPath = path.join(ROOT, 'public', 'prices.json');
    try {
      if (!fs.existsSync(path.dirname(pricesPath))) fs.mkdirSync(path.dirname(pricesPath), { recursive: true });
      fs.writeFileSync(pricesPath, JSON.stringify(merged, null, 2));
      log(`  Wrote ${merged.length} merged products to public/prices.json`);
    } catch (e) { log(`  Failed to write prices.json: ${e.message}`); }
  }
}

function categoryMismatch(category, csvFile) {
  if (!category) return false; // apply to all if no category
  const expectedFile = mapCategoryToFile(category);
  if (!expectedFile) return false; // unknown category, apply broadly
  return expectedFile !== csvFile;
}

// ─── Price Scraping ──────────────────────────────────────────────

const PRICE_RETAILERS = {
  'amazon.co.uk': (q) => `https://www.amazon.co.uk/s?k=${encodeURIComponent(q)}`,
  'awd-it.co.uk': (q) => `https://www.awd-it.co.uk/catalogsearch/result/?q=${encodeURIComponent(q)}`,
};

const UPCITEMDB_API = 'https://api.upcitemdb.com/prod/trial/search?s=';

const IDENTIFIER_COLUMNS = ['mpn', 'sku', 'brand', 'model', 'ean', 'upc'];

// Extract model/sku tokens from product names for better search matching
function extractIdentifiersFromName(name, category) {
  const identifiers = { brand: '', model: '' };
  const clean = name.replace(/[""]/g, '').trim();

  // Known brand prefixes (case-insensitive match)
  const brands = ['Intel', 'AMD', 'NVIDIA', 'Asus', 'MSI', 'Gigabyte', 'ASRock', 'Corsair',
    'G.Skill', 'Kingston', 'Crucial', 'Samsung', 'WD', 'Western Digital', 'Seagate',
    'EVGA', 'Cooler Master', 'Noctua', 'be quiet', 'Fractal Design', 'NZXT', 'Lian Li',
    'Thermaltake', 'Deepcool', 'ARCTIC', 'Sapphire', 'PowerColor', 'XFX', 'PNY', 'Zotac',
    'Patriot', 'TEAMGROUP', 'Silicon Power', 'ADATA', 'Lexar', 'Micron', 'Sabrent',
    'SK hynix', 'Solidigm', 'Phanteks', 'Montech', 'Silverstone', 'Antec', 'Corsair',
    'Alphacool', 'EK', 'Razer', 'Logitech', 'SteelSeries', 'HyperX', 'Roccat',
    'AOC', 'Dell', 'LG', 'Samsung', 'BenQ', 'ViewSonic', 'Gigabyte', 'Acer', 'ASUS',
    'Super Flower', 'SeaSonic', 'Fractal', 'Cooler Master', 'be quiet!',
    'Cougar', 'BitFenix', 'AeroCool', 'GameMax', 'CiT', 'SHARKOON', 'KOLINK'];

  // Try to match brand prefix
  const lower = clean.toLowerCase();
  for (const b of brands) {
    if (lower.startsWith(b.toLowerCase())) {
      identifiers.brand = b;
      const rest = clean.substring(b.length).trim();
      identifiers.model = rest;
      break;
    }
  }

  // If no brand matched, try first word as brand
  if (!identifiers.brand) {
    const firstWord = clean.split(/\s+/)[0] || '';
    identifiers.brand = firstWord;
    identifiers.model = clean.substring(firstWord.length).trim();
  }

  // Extract specific model numbers (e.g., "7600X", "13600K", "RTX 4080", "CX650")
  // This helps with retailers that recognize these as search terms
  const modelTokens = [];

  // Match GPU chipset references
  const gpuChipset = clean.match(/(GeForce\s+RTX\s+\d+\s*\w*|Radeon\s+RX\s+[\d\s]+\w*|Arc\s+[AB]\d+|RTX\s+(?:PRO\s+)?\d+|GTX\s+\d+)/i);
  if (gpuChipset) modelTokens.push(gpuChipset[1]);

  // Match CPU model patterns: digits + optional X3D/X/K/etc
  const cpuModel = clean.match(/\b(\d{4,5}[A-Za-z0-9]*)\b/);
  if (cpuModel) modelTokens.push(cpuModel[1]);

  // Match specific product codes (e.g., CX650, RM850x, etc.)
  const productCode = clean.match(/\b([A-Z]{2,}\d{2,}[A-Za-z0-9]*)\b/);
  if (productCode) modelTokens.push(productCode[1]);

  // Generate MPN-like search tokens from model
  if (modelTokens.length > 0) {
    identifiers.mpn = modelTokens.join(' ');
  }

  // For GPUs, append chipset to model if available and category matches
  if (category && (category.includes('gpu') || category.includes('graphics'))) {
    // Don't override - the model already has the full product name
  }

  return identifiers;
}

// Look up product identifiers and prices via UPCitemdb API (free tier, rate-limited)
let _lastUPCItemdbCall = 0;
const upcItemdbEnriched = new Set();

async function fetchUPCItemdbIdentifiers(productName, identifiers = {}) {
  if (identifiers.mpn || identifiers.ean || identifiers.upc) return identifiers;
  if (upcItemdbEnriched.has(productName.toLowerCase())) return identifiers;
  upcItemdbEnriched.add(productName.toLowerCase());
  // Rate limit: ensure at least 1200ms between calls
  const now = Date.now();
  const elapsed = now - _lastUPCItemdbCall;
  if (elapsed < 1200) await new Promise(r => setTimeout(r, 1200 - elapsed));
  _lastUPCItemdbCall = Date.now();
  const query = encodeURIComponent(productName.replace(/[""]/g, '').trim().substring(0, 100));
  try {
    const resp = await fetchWithTimeout(`${UPCITEMDB_API}${query}`, 8000);
    if (!resp.ok) return identifiers;
    const data = await resp.json();
    if (data.code !== 'OK' || !data.items || data.items.length === 0) return identifiers;
    const item = data.items[0];
    if (item.ean && !identifiers.ean) identifiers.ean = item.ean;
    if (item.upc && !identifiers.upc) identifiers.upc = item.upc;
    if (item.model && !identifiers.mpn) identifiers.mpn = item.model;
    if (item.brand && !identifiers.brand) identifiers.brand = item.brand;
    if (item.offers && item.offers.length > 0) {
      const prices = item.offers.map(o => parseFloat(o.price)).filter(p => !isNaN(p) && p > 0);
      if (prices.length > 0) identifiers._upcitemdbLowest = Math.min(...prices);
    }
    if (identifiers.mpn || identifiers.ean) log(`  UPCitemdb: ${productName} => mpn=${identifiers.mpn || ''} ean=${identifiers.ean || ''}`);
  } catch {}
  return identifiers;
}

function ensureIdentifierColumns(csv) {
  let modified = false;
  for (const col of IDENTIFIER_COLUMNS) {
    if (!csv.header.includes(col)) {
      csv.header.push(col);
      // add empty column to all existing rows
      for (let i = 1; i < csv.lines.length; i++) {
        csv.lines[i] += ',';
      }
      modified = true;
    }
  }
  if (modified) {
    // rebuild header line
    csv.lines[0] = csv.header.join(',');
    fs.writeFileSync(csv.filePath, csv.lines.join('\n'), 'utf-8');
  }
  // Build index map
  const idxMap = {};
  for (const col of IDENTIFIER_COLUMNS) {
    idxMap[col] = csv.header.indexOf(col);
  }
  return idxMap;
}

async function fetchWithTimeout(url, timeout = 8000, opts = {}) {
  const controller = new AbortController();
  const id = setTimeout(() => controller.abort(), timeout);
  try {
    const fetchOpts = { signal: controller.signal, ...opts };
    fetchOpts.headers = { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', ...(opts.headers || {}) };
    const res = await fetch(url, fetchOpts);
    clearTimeout(id); return res;
  } catch (e) { clearTimeout(id); throw e; }
}

const CONDITION_USED = /\b(used|refurbished|open\s*box|pre-?owned|grade\s*[ab])\b/i;

function isNewCondition(html, priceIndex) {
  const before = html.slice(Math.max(0, priceIndex - 300), priceIndex);
  const after = html.slice(priceIndex, priceIndex + 300);
  const context = before + after;
  return !CONDITION_USED.test(context);
}

// ─── Master Pricing CSV (per-retailer columns + average) ──────────

const MASTER_PRICES_FILE = path.join(DATA_DIR, 'master-prices.csv');
const RETAILER_COLUMNS = Object.keys(PRICE_RETAILERS);

function getMasterPriceColumns() {
  return ['name', ...RETAILER_COLUMNS.map(r => r.replace(/[.-]/g, '_') + '_price'), 'price_sources', 'average_price'];
}

// In-memory master pricing data
let masterData = null;
let masterHeader = null;

function initMasterPrices(productNames) {
  masterHeader = getMasterPriceColumns();
  masterData = new Map();
  for (const name of productNames) {
    const row = { name };
    for (const col of masterHeader) {
      if (col !== 'name') row[col] = '';
    }
    masterData.set(name.toLowerCase().trim(), row);
  }
}

function loadOrInitMasterPrices(productNames) {
  if (fs.existsSync(MASTER_PRICES_FILE)) {
    try {
      const csv = readCSV(MASTER_PRICES_FILE);
      if (csv && csv.lines.length > 1) {
        masterHeader = csv.header;
        masterData = new Map();
        const nameIdx = csv.header.indexOf('name');
        if (nameIdx >= 0) {
          for (let i = 1; i < csv.lines.length; i++) {
            const parts = parseCSVLine(csv.lines[i]);
            const name = (parts[nameIdx] || '').trim();
            if (name) {
              const row = { name };
              for (let j = 0; j < csv.header.length; j++) {
                if (j !== nameIdx) row[csv.header[j]] = parts[j] || '';
              }
              masterData.set(name.toLowerCase().trim(), row);
            }
          }
          return;
        }
      }
    } catch {}
  }
  initMasterPrices(productNames);
}

function setProductRetailerPrice(productName, retailer, price) {
  const key = productName.toLowerCase().trim();
  const row = masterData.get(key);
  if (!row) return;

  const col = retailer.replace(/[.-]/g, '_') + '_price';
  row[col] = (price != null && price > 0) ? price.toFixed(2) : '';

  // Recalculate average across all retailers that have prices
  let total = 0, count = 0;
  for (const r of RETAILER_COLUMNS) {
    const c = r.replace(/[.-]/g, '_') + '_price';
    const v = parseFloat(row[c]);
    if (!isNaN(v) && v > 0) { total += v; count++; }
  }
  row.average_price = count > 0 ? (total / count).toFixed(2) : '';
  row.price_sources = count.toString();
}

function flushMasterPrices() {
  const header = getMasterPriceColumns();
  // Ensure header is current (add any new retailers that might have been added)
  if (masterHeader && masterHeader.length !== header.length) {
    // Rebuild: add missing columns to existing rows
    const newHeader = header;
    for (const [, row] of masterData) {
      for (const col of newHeader) {
        if (col !== 'name' && row[col] === undefined) row[col] = '';
      }
    }
    masterHeader = newHeader;
  } else {
    masterHeader = header;
  }

  const lines = [masterHeader.join(',')];
  for (const [, row] of masterData) {
    const vals = masterHeader.map(h => escapeCSV(row[h] ?? ''));
    lines.push(vals.join(','));
  }
  const dir = path.dirname(MASTER_PRICES_FILE);
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(MASTER_PRICES_FILE, lines.join('\n'), 'utf-8');
}

function getAveragePrice(productName) {
  const key = productName.toLowerCase().trim();
  if (!masterData) return null;
  const row = masterData.get(key);
  if (!row) return null;
  const avg = parseFloat(row.average_price);
  return !isNaN(avg) && avg > 0 ? avg : null;
}

// ─── Single-Retailer Single-Product Scraper ─────────────────────

// Verify the search result page actually contains the product we're looking for
function pageMatchesProduct(html, productName) {
  // Reject bot challenge pages (Cloudflare, etc.)
  if (/Enable JavaScript and cookies to continue|c='_cf_chl_opt'|cloudflare|challenge-platform/i.test(html)) return false;
  if (/attention required|verify you are human|captcha|access denied/i.test(html)) return false;

  const lowerHtml = html.toLowerCase();
  const lowerName = productName.toLowerCase();

  // Check for "no results" indicators
  const noResultPattern = /no\s+(results?|products?|items?|matches?)|0\s+(results?|products?|items?|matches?)|did\s+you\s+mean|search\s+again|your search.*did not match/i;
  if (noResultPattern.test(html)) return false;

  // Extract significant tokens from product name (skip brand prefixes, short words, common terms)
  const skipWords = new Set([
    'with', 'and', 'for', 'the', 'series', 'edition', 'gen', 'socket', 'cpu', 'gpu', 'ram',
    'module', 'kit', 'dual', 'quad', 'single', 'channel', 'desktop', 'notebook', 'laptop',
    'processor', 'graphics', 'memory', 'storage', 'drive', 'cooler', 'fan', 'case', 'board',
    'revision', 'version', 'model', 'part', 'number', 'black', 'white', 'red', 'blue',
    'silver', 'gray', 'grey', 'rgb', 'argb', 'pwm', 'led', 'plus', 'pro', 'max', 'mini',
    'ultra', 'extreme', 'slim', 'lite', 'v2', 'v3', 'v4', 'v5',
  ]);

  const tokens = lowerName.split(/[\s,()-]+/).filter(t => t.length >= 3 && !skipWords.has(t) && !/^\d$/.test(t));
  if (tokens.length === 0) return true; // can't verify, accept

  // Count token matches in meaningful HTML contexts (product links, titles, alt text)
  // rather than just anywhere (which catches search box text)
  const productContext = (html.match(/<(?:a|h[1-6]|img|div|span|title)[^>]*[^<]*/gi) || []).join(' ').toLowerCase();

  let matchCount = 0;
  for (const token of tokens) {
    if (productContext.includes(token)) matchCount++;
  }

  // Require at least 50% of significant tokens to match in product context
  const threshold = Math.max(1, Math.ceil(tokens.length * 0.5));
  return matchCount >= threshold;
}

async function scrapeSingleRetailerPrice(retailer, urlFunc, productName, identifiers = {}) {
  let { mpn, sku, brand, model, ean, upc } = identifiers;

  // Build search queries from current identifiers
  const seen = new Set();
  function buildQueries() {
    const qs = [];
    if (mpn) qs.push(mpn);
    if (brand && model) qs.push(`${brand} ${model}`);
    if (ean) qs.push(ean);
    if (upc) qs.push(upc);
    if (sku) qs.push(sku);
    qs.push(productName.replace(/[^\w\s]/g, ' ').trim().substring(0, 100));
    return qs.filter(q => { const k = q.toLowerCase().trim(); if (seen.has(k)) return false; seen.add(k); return true; });
  }

  let lowest = null;
  let queries = buildQueries();

  for (const q of queries) {
    try {
      const url = urlFunc(q);
      const response = await fetchWithTimeout(url, 8000);
      if (!response.ok) continue;
      const html = await response.text();

      // Verify the search result page actually contains our product
      if (!pageMatchesProduct(html, productName)) continue;

      let localLowest = Infinity;

      const priceMatches = html.match(/\xA3\s*(\d+(?:,\d{3})*(?:\.\d{2})?)/g);
      if (priceMatches) {
        for (const pm of priceMatches) {
          const idx = html.indexOf(pm);
          if (idx >= 0 && !isNewCondition(html, idx)) continue;
          const price = parseFloat(pm.replace(/\xA3|,/g, ''));
          if (price > 1 && price < 20000 && price < localLowest) localLowest = price;
        }
      }

      const jsonLdMatches = html.matchAll(/<script[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi);
      for (const jm of jsonLdMatches) {
        try {
          const obj = JSON.parse(jm[1]);
          const offers = obj.offers || obj.mainEntity?.offers;
          if (offers) {
            const condition = (offers.itemCondition || '').toLowerCase();
            if (condition && !condition.includes('newcondition')) continue;
            const price = parseFloat(offers.price || offers.lowPrice || 0);
            if (price > 1 && price < 20000 && price < localLowest) localLowest = price;
          }
        } catch {}
      }

      if (localLowest < Infinity) {
        if (lowest === null || localLowest < lowest) lowest = localLowest;
        if (q !== queries[queries.length - 1]) break;
      }
    } catch {
      continue;
    }
  }

  // If no price found and identifiers are weak, try enriching via UPCitemdb and retry
  if (lowest === null && !identifiers.mpn && !identifiers.ean && !identifiers.upc) {
    await fetchUPCItemdbIdentifiers(productName, identifiers);
    ({ mpn, sku, brand, model, ean, upc } = identifiers);
    queries = buildQueries();
    // Only retry if we got new specific queries beyond the name fallback
    if (queries.length > 1 || (queries.length === 1 && queries[0].toLowerCase() !== productName.trim().toLowerCase())) {
      for (const q of queries) {
        try {
          const url = urlFunc(q);
          const response = await fetchWithTimeout(url, 8000);
          if (!response.ok) continue;
          const html = await response.text();

          if (!pageMatchesProduct(html, productName)) continue;

          let localLowest = Infinity;
          const priceMatches = html.match(/\xA3\s*(\d+(?:,\d{3})*(?:\.\d{2})?)/g);
          if (priceMatches) {
            for (const pm of priceMatches) {
              const idx = html.indexOf(pm);
              if (idx >= 0 && !isNewCondition(html, idx)) continue;
              const price = parseFloat(pm.replace(/\xA3|,/g, ''));
              if (price > 1 && price < 20000 && price < localLowest) localLowest = price;
            }
          }

          const jsonLdMatches = html.matchAll(/<script[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi);
          for (const jm of jsonLdMatches) {
            try {
              const obj = JSON.parse(jm[1]);
              const offers = obj.offers || obj.mainEntity?.offers;
              if (offers) {
                const condition = (offers.itemCondition || '').toLowerCase();
                if (condition && !condition.includes('newcondition')) continue;
                const price = parseFloat(offers.price || offers.lowPrice || 0);
                if (price > 1 && price < 20000 && price < localLowest) localLowest = price;
              }
            } catch {}
          }

          if (localLowest < Infinity) {
            if (lowest === null || localLowest < lowest) lowest = localLowest;
            if (q !== queries[queries.length - 1]) break;
          }
        } catch { continue; }
      }
    }
  }

  return lowest;
}

// ─── Retailer-by-Retailer Price Scraping ─────────────────────────

async function scrapeMissingPrices(categories) {
  log('\n=== Phase: Scrape Prices (Retailer by Retailer) ===');
  let csvFiles = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv') && f !== 'master-prices.csv');
  if (categories) {
    const cats = categories.split(',').map(c => c.trim()).filter(Boolean);
    csvFiles = csvFiles.filter(f => cats.includes(f.replace('.csv','')));
    log(`  Filtered to categories: ${cats.join(', ')}`);
  }

  // Collect all products across all CSVs
  const allProducts = [];
  for (const file of csvFiles) {
    const csvPath = path.join(DATA_DIR, file);
    const csv = readCSV(csvPath);
    if (!csv) continue;
    const nameIdx = csv.header.indexOf('name');
    const priceIdx = csv.header.indexOf('price');
    if (nameIdx < 0) continue;
    // Get identifier column indices
    const idIdx = {};
    for (const col of IDENTIFIER_COLUMNS) {
      idIdx[col] = csv.header.indexOf(col);
    }
    for (let i = 1; i < csv.lines.length; i++) {
      const parts = parseCSVLine(csv.lines[i]);
      const name = (parts[nameIdx] || '').trim();
      if (!name) continue;
      // Extract identifiers from CSV row, or generate from name as fallback
      const identifiers = {};
      let hasAny = false;
      for (const col of IDENTIFIER_COLUMNS) {
        const idx = idIdx[col];
        if (idx >= 0 && idx < parts.length && parts[idx]) {
          identifiers[col] = parts[idx].replace(/^"|"$/g, '');
          if (identifiers[col]) hasAny = true;
        }
      }
      if (!hasAny) {
        // Generate identifiers from product name when not in CSV
        Object.assign(identifiers, extractIdentifiersFromName(name, file));
      }
      allProducts.push({ file, name, lineIdx: i, parts, csv, priceIdx, identifiers });
    }
  }

  state.totalItems = allProducts.length * RETAILER_COLUMNS.length;
  state.completedItems = 0;
  state.pricesChecked = 0; state.pricesUpdated = 0; state.pricesSkipped = 0; state.pricesFound = 0;

  // Load or initialise in-memory master pricing table
  log('  Initialising master pricing table...');
  loadOrInitMasterPrices(allProducts.map(p => p.name));

  let grandTotalFound = 0;
  const CONCURRENCY = 5;

  // One pass per retailer — scrape every product from that single retailer
  for (const [retailer, urlFunc] of Object.entries(PRICE_RETAILERS)) {
    log(`\n  ─── Retailer: ${retailer} ───`);
    state.phase = `Scrape: ${retailer}`;
    saveState();

    let retailerFound = 0;

    for (let batchStart = 0; batchStart < allProducts.length; batchStart += CONCURRENCY) {
      const batch = allProducts.slice(batchStart, batchStart + CONCURRENCY);

      await Promise.all(batch.map(async (product) => {
        state.currentItem = `${retailer} > ${product.name}`;
        state.lastProgressTime = Date.now();

        const price = await scrapeSingleRetailerPrice(retailer, urlFunc, product.name, product.identifiers);

        if (price != null && price > 0) {
          setProductRetailerPrice(product.name, retailer, price);
          retailerFound++;
          grandTotalFound++;
          state.pricesFound++;
          log(`  [${retailer}] ${product.file.replace('.csv','')}: ${product.name} => £${price.toFixed(2)}`);
        } else {
          state.pricesSkipped++;
        }

        state.pricesChecked++;
        state.completedItems++;
        saveState();
      }));

      // Polite delay between batches to the same retailer
      await new Promise(r => setTimeout(r, 300));
    }

    // Persist master CSV after each retailer completes
    flushMasterPrices();
    log(`  ${retailer}: ${retailerFound} prices found (${allProducts.length} products searched)`);
  }

  state.currentItem = '';
  log(`\n  Total across all retailers: ${grandTotalFound} prices found`);

  // Write average prices back to component CSVs
  log('\n  Writing average prices to component CSVs...');
  let csvUpdated = 0;
  for (const file of csvFiles) {
    const csvPath = path.join(DATA_DIR, file);
    const csv = readCSV(csvPath);
    if (!csv) continue;
    csv.filePath = csvPath;
    const nameIdx = csv.header.indexOf('name');
    const priceIdx = csv.header.indexOf('price');
    if (nameIdx < 0 || priceIdx < 0) continue;

    let fileUpdated = 0;
    for (let i = 1; i < csv.lines.length; i++) {
      const parts = parseCSVLine(csv.lines[i]);
      const name = (parts[nameIdx] || '').trim();
      if (!name) continue;

      const avg = getAveragePrice(name);
      if (avg != null) {
        const oldPrice = parts[priceIdx];
        parts[priceIdx] = avg.toFixed(2);
        csv.lines[i] = parts.join(',');
        fileUpdated++;
        log(`  [AVG] ${file.replace('.csv','')}: ${name} => £${avg.toFixed(2)}${oldPrice ? ` (was £${oldPrice})` : ''}`);
      }
    }

    if (fileUpdated > 0) {
      fs.writeFileSync(csvPath, csv.lines.join('\n'), 'utf-8');
      log(`  ${file}: ${fileUpdated} average prices written`);
    }
    csvUpdated += fileUpdated;
  }

  // Write enriched identifiers back to CSVs
  log('\n  Writing enriched identifiers to component CSVs...');
  let idWritten = 0;
  for (const file of csvFiles) {
    const csvPath = path.join(DATA_DIR, file);
    const csv = readCSV(csvPath);
    if (!csv) continue;
    csv.filePath = csvPath;
    const nameIdx = csv.header.indexOf('name');
    if (nameIdx < 0) continue;
    ensureIdentifierColumns(csv);
    const idIdx = {};
    for (const col of IDENTIFIER_COLUMNS) {
      idIdx[col] = csv.header.indexOf(col);
    }
    let fileIdWritten = 0;
    for (let i = 1; i < csv.lines.length; i++) {
      const parts = parseCSVLine(csv.lines[i]);
      const name = (parts[nameIdx] || '').trim();
      if (!name) continue;
      const match = allProducts.find(p => p.file === file && p.name === name);
      if (!match) continue;
      for (const col of IDENTIFIER_COLUMNS) {
        const idx = idIdx[col];
        if (idx < 0 || idx >= parts.length) continue;
        if (match.identifiers[col] && !parts[idx]) {
          parts[idx] = match.identifiers[col];
          fileIdWritten++;
        }
      }
      if (fileIdWritten > 0) csv.lines[i] = parts.join(',');
    }
    if (fileIdWritten > 0) {
      fs.writeFileSync(csvPath, csv.lines.join('\n'), 'utf-8');
      log(`  ${file}: ${fileIdWritten} identifier cells written`);
    }
    idWritten += fileIdWritten;
  }
  if (idWritten > 0) log(`  Total: ${idWritten} identifier cells written to CSVs`);

  state.pricesUpdated = csvUpdated;
  log(`  Total: ${csvUpdated} component prices updated with averages`);

  // Write public/prices.json from all CSV data
  try {
    const allProductsOut = [];
    csvFiles = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv') && f !== 'master-prices.csv');
    for (const file of csvFiles) {
      const csvPath = path.join(DATA_DIR, file);
      const csv = readCSV(csvPath);
      if (!csv) continue;
      const nameIdx = csv.header.indexOf('name');
      const priceIdx = csv.header.indexOf('price');
      const imgIdx = csv.header.indexOf('image');
      if (nameIdx < 0) continue;
      for (let i = 1; i < csv.lines.length; i++) {
        const parts = parseCSVLine(csv.lines[i]);
        const name = (parts[nameIdx] || '').trim();
        if (!name) continue;
        const price = priceIdx >= 0 ? parts[priceIdx] : null;
        const image = imgIdx >= 0 ? parts[imgIdx] : null;
        if (price) {
          allProductsOut.push({
            category: file.replace('.csv', ''),
            name,
            price: parseFloat(price),
            image: image ? image.replace(/^"|"$/g, '') : null,
            currency: 'GBP',
          });
        }
      }
    }
    const pricesPath = path.join(ROOT, 'public', 'prices.json');
    fs.writeFileSync(pricesPath, JSON.stringify(allProductsOut, null, 2));
    log(`  Wrote ${allProductsOut.length} products to public/prices.json`);
  } catch (e) {
    log(`  Failed to write prices.json: ${e.message}`);
  }
}

// ─── Dashboard ────────────────────────────────────────────

let dashboardServer = null;
let dashboardUpdateInterval = null;

function getPriceCategories() {
  const files = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  const cats = [];
  for (const file of files) {
    const csv = readCSV(path.join(DATA_DIR, file));
    if (!csv) continue;
    const nameIdx = csv.header.indexOf('name');
    const priceIdx = csv.header.indexOf('price');
    if (nameIdx < 0 || priceIdx < 0) continue;
    let items = 0, withPrice = 0, needsPrice = 0;
    for (let i = 1; i < csv.lines.length; i++) {
      const parts = parseCSVLine(csv.lines[i]);
      const name = (parts[nameIdx] || '').trim();
      if (!name) continue;
      items++;
      const p = parts[priceIdx] ? parseFloat(parts[priceIdx]) : NaN;
      if (p && !isNaN(p) && p > 0) withPrice++;
      else needsPrice++;
    }
    cats.push({
      name: file.replace('.csv', ''),
      items, withPrice, needsPrice,
    });
  }
  return cats;
}

function generateDashboardHTML() {
  const uptime = Math.floor((Date.now() - startTime) / 1000);
  const uptimeStr = `${Math.floor(uptime / 3600)}h ${Math.floor((uptime % 3600) / 60)}m ${uptime % 60}s`;

  const totalItems = state.totalItems || 0;
  const completedItems = state.completedItems || 0;
  const pct = totalItems > 0 ? Math.round((completedItems / totalItems) * 100) : 0;

  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>PriceUpd Dashboard</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',system-ui,sans-serif;background:#0d1117;color:#c9d1d9;padding:24px}
h1{font-size:24px;color:#58a6ff;margin-bottom:4px;display:flex;align-items:center;gap:12px}
h1 small{font-size:13px;color:#8b949e;font-weight:400}
.top-bar{display:flex;align-items:center;gap:16px;margin-bottom:20px;flex-wrap:wrap}
.live-indicator{display:flex;align-items:center;gap:8px;padding:6px 14px;border-radius:20px;border:1px solid #30363d;background:#161b22;font-size:13px;font-weight:600}
.live-dot{width:14px;height:14px;border-radius:50%;transition:all .3s ease;flex-shrink:0}
.live-dot.green{background:#3fb950;box-shadow:0 0 8px rgba(63,185,80,.6)}
.live-dot.red{background:#f85149;box-shadow:0 0 8px rgba(248,81,73,.6)}
.live-dot.yellow{background:#d29922;box-shadow:0 0 8px rgba(210,153,34,.6)}
.live-dot.blue{background:#58a6ff;box-shadow:0 0 8px rgba(88,166,255,.6)}
.sched-info{color:#8b949e;font-size:13px;font-family:monospace;margin-left:auto}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
.card{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:20px}
.card h3{font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#8b949e;margin-bottom:12px}
.stat-row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #21262d;font-size:14px}
.stat-row:last-child{border-bottom:none}
.stat-label{color:#8b949e}
.stat-value{font-weight:600}
.green{color:#3fb950}.red{color:#f85149}.blue{color:#58a6ff}.yellow{color:#d29922}.magenta{color:#bc8cff}
.bar-container{background:#21262d;border-radius:6px;height:24px;overflow:hidden;margin:8px 0;position:relative}
.bar{height:100%;background:linear-gradient(90deg,#1f6feb,#58a6ff);transition:width .5s ease;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;color:#fff;min-width:fit-content;padding:0 8px}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;padding:6px 8px;color:#8b949e;border-bottom:2px solid #30363d;font-size:11px;text-transform:uppercase;letter-spacing:.3px}
td{padding:6px 8px;border-bottom:1px solid #21262d;font-size:12px}
.cat-name{font-weight:500;color:#c9d1d9}
.text-right{text-align:right}
.mini-bar{background:#21262d;border-radius:4px;height:14px;overflow:hidden;min-width:60px}
.mini-fill{height:100%;background:linear-gradient(90deg,#1f6feb,#58a6ff);border-radius:4px;transition:width .5s ease}
.mini-fill.green{background:linear-gradient(90deg,#1b5e2a,#3fb950)}
.log-box{background:#0d1117;border:1px solid #21262d;border-radius:6px;padding:12px;font-family:monospace;font-size:11px;color:#8b949e;height:160px;overflow-y:auto;line-height:1.6;margin-top:8px}
.log-box .line-new{color:#c9d1d9}
.mt-16{margin-top:16px}
@media(max-width:768px){.grid{grid-template-columns:1fr}.top-bar{flex-direction:column;align-items:flex-start}.sched-info{margin-left:0}}
</style>
</head>
<body>
<h1>&#9881; PriceUpd <small id="schedInfo"></small></h1>
<div class="top-bar">
  <div class="live-indicator">
    <span class="live-dot red" id="liveDot"></span>
    <span id="liveLabel">STOPPED</span>
    <span style="color:#8b949e;font-weight:400;font-size:12px" id="pidLabel"></span>
  </div>
  <span class="sched-info" id="schedText">Waiting...</span>
</div>
<div class="grid">
  <div class="card">
    <h3>&#9201; Overall Progress</h3>
    <div class="stat-row"><span class="stat-label">Phase</span><span class="stat-value blue" id="phase">-</span> <span class="stat-label" id="phaseNumber" style="font-size:11px;color:#58a6ff;margin-left:8px"></span></div>
    <div class="stat-row"><span class="stat-label">Category</span><span class="stat-value" id="currentCategory">-</span></div>
    <div class="stat-row"><span class="stat-label">Batch</span><span class="stat-value yellow" id="batchInfo">-</span></div>
    <div class="stat-row"><span class="stat-label">Scraping</span><span class="stat-value" id="scrapeItem" style="font-size:11px;color:#58a6ff;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">-</span></div>
    <div class="stat-row"><span class="stat-label">Restarts</span><span class="stat-value" id="restartCount">0</span></div>
    <div class="stat-row"><span class="stat-label">Elapsed</span><span class="stat-value" id="elapsed">-</span></div>
    <div class="stat-row"><span class="stat-label">ETA</span><span class="stat-value" id="etaDisplay" style="color:#d29922">-</span></div>
    <div class="bar-container"><div class="bar" id="progressBar" style="width:${Math.max(pct||0,0.5)}%">${pct||0}%</div></div>
    <div class="stat-row"><span class="stat-label">Items</span><span class="stat-value" id="itemsInfo">-</span></div>
  </div>
  <div class="card">
    <h3>&#128176; Prices</h3>
    <div class="stat-row"><span class="stat-label">Checked</span><span class="stat-value yellow" id="pricesChecked">0</span></div>
    <div class="stat-row"><span class="stat-label">Found</span><span class="stat-value green" id="pricesFound">0</span></div>
    <div class="stat-row"><span class="stat-label">Updated</span><span class="stat-value" id="pricesUpdated" style="color:#f59e0b">0</span></div>
    <div class="stat-row"><span class="stat-label">Skipped (no match)</span><span class="stat-value blue" id="pricesSkipped">0</span></div>
    <div class="stat-row"><span class="stat-label">Missing</span><span class="stat-value red" id="pricesNeeded">0</span></div>
    <div class="stat-row" style="border-bottom:none">
      <span class="stat-label">Last Check</span>
      <span class="stat-value" id="lastProgressTime" style="font-size:11px;color:#8b949e">-</span>
    </div>
  </div>
</div>
<div class="card">
  <h3>&#128202; Categories</h3>
  <table>
    <thead><tr>
      <th>Category</th>
      <th>Items</th>
      <th>With Price</th>
      <th>Needs Price</th>
      <th>Progress</th>
    </tr></thead>
    <tbody id="catTable"></tbody>
  </table>
</div>
<div class="card mt-16" style="border-color:#30363d">
  <h3>&#9000; Live Console <span style="font-weight:400;text-transform:none;color:#8b949e;font-size:11px" id="logCount">(0 lines)</span></h3>
  <div style="display:flex;justify-content:space-between;margin-bottom:6px">
    <span style="color:#8b949e;font-size:11px">&#9654; Streaming live prices</span>
    <span style="color:#8b949e;font-size:11px">PID: <span id="pidDisplay" style="color:#58a6ff">-</span></span>
  </div>
  <div class="log-box" id="logBox"></div>
</div>
<script>
const POLL = 2000;
let lastLines = 0;
async function poll() {
  try {
    const r = await fetch('/status');
    const d = await r.json();
    document.getElementById('liveDot').className = 'live-dot ' + (d.status==='running'?'green':d.status==='frozen'?'yellow':d.status==='complete'?'blue':'red');
    const label = document.getElementById('liveLabel');
    label.textContent = d.status==='running'?'RUNNING':d.status==='frozen'?'FROZEN':d.status==='complete'?'COMPLETE':d.status==='error'?'ERROR':'STOPPED';
    label.style.color = d.status==='running'?'#3fb950':d.status==='frozen'?'#d29922':d.status==='complete'?'#58a6ff':'#f85149';
    document.getElementById('pidLabel').textContent = d.pid?'PID: '+d.pid:'';
    document.getElementById('pidDisplay').textContent = d.pid||'-';
    document.getElementById('schedText').textContent = d.status==='running'?'Processing...':d.status==='complete'?'All done':d.status==='error'?(d.phase||'Error'):'Idle';
    document.getElementById('phase').textContent = d.phase||'-';
    document.getElementById('phaseNumber').textContent = d.phase ? 'Phase '+d.phaseNumber : '';
    document.getElementById('currentCategory').textContent = d.category||'-';
    document.getElementById('restartCount').textContent = d.restartCount||0;
    document.getElementById('elapsed').textContent = d.elapsedFormatted||'-';
    document.getElementById('etaDisplay').textContent = d.etaFormatted||'-';
    document.getElementById('scrapeItem').textContent = d.currentItem || '-';
    document.getElementById('batchInfo').textContent = d.batchTotal > 0 ? (d.batchDone||0)+' / '+d.batchTotal : '-';
    const pct = Math.min(100,Math.max(0,d.progressPct||0));
    const pctDisplay = pct < 1 ? pct.toFixed(1) : Math.round(pct);
    document.getElementById('progressBar').style.width = Math.max(pct,0.5)+'%';
    document.getElementById('progressBar').textContent = pctDisplay+'%';
    document.getElementById('itemsInfo').textContent = d.itemsTotal>0 ? d.itemsProcessed+'/'+d.itemsTotal+' ('+(()=>{const r=(d.itemsProcessed/d.itemsTotal)*100;return r<1?r.toFixed(1):Math.round(r)})()+'%)' : '-';
    document.getElementById('pricesChecked').textContent = d.pricesChecked||0;
    document.getElementById('pricesFound').textContent = d.pricesFound||0;
    document.getElementById('pricesUpdated').textContent = d.pricesUpdated||0;
    document.getElementById('pricesSkipped').textContent = d.pricesSkipped||0;
    document.getElementById('pricesNeeded').textContent = d.pricesNeeded||0;
    document.getElementById('lastProgressTime').textContent = d.lastProgressTime?new Date(d.lastProgressTime).toLocaleString():'-';
    document.getElementById('schedInfo').textContent = d.lastSuccess ? 'Last: '+new Date(d.lastSuccess).toLocaleDateString() : '';
    if (d.categories && d.categories.length) {
      let html = '';
      for (const c of d.categories) {
        const pct2 = c.items > 0 ? Math.round(((c.items-c.needsPrice)/c.items)*100) : 0;
        html += '<tr><td class="cat-name">'+c.name+'</td><td class="text-right">'+(c.items||0)+'</td><td class="text-right">'+(c.withPrice||0)+'</td><td class="text-right'+(c.needsPrice>0?' yellow':'')+'">'+(c.needsPrice||0)+'</td><td style="min-width:100px"><div class="mini-bar"><div class="mini-fill '+(pct2===100?'green':'')+'" style="width:'+pct2+'%"></div></div></td></tr>';
      }
      document.getElementById('catTable').innerHTML = html;
    }
    if (d.logLines && d.logLines.length>0 && d.logLines.length!==lastLines) {
      const box = document.getElementById('logBox');
      box.innerHTML = d.logLines.slice(-50).map(l=>'<div>'+(l.replace(/</g,'&lt;'))+'</div>').join('');
      box.scrollTop = box.scrollHeight;
      lastLines = d.logLines.length;
      document.getElementById('logCount').textContent = '('+d.logLines.length+' lines)';
    }
  } catch(e) {
    document.getElementById('schedText').textContent = 'Dashboard offline';
    document.getElementById('liveDot').className = 'live-dot red';
    document.getElementById('liveLabel').textContent = 'OFFLINE';
    document.getElementById('liveLabel').style.color = '#f85149';
  }
}
setInterval(poll, POLL);
poll();
</script>
</body>
</html>`;
}

function startDashboard() {
  if (dashboardServer) return;
  dashboardServer = http.createServer((req, res) => {
    if (req.url === '/status') {
      try {
        const categories = getPriceCategories();
        const itemsTotal = categories.reduce((s, c) => s + c.items, 0);
        const itemsProcessed = categories.reduce((s, c) => s + c.withPrice, 0);
        const pricesNeeded = categories.reduce((s, c) => s + c.needsPrice, 0);
        const pct = itemsTotal > 0 ? (itemsProcessed / itemsTotal) * 100 : 100;
        const elapsedSec = (Date.now() - startTime) / 1000;
        const elapsedMin = elapsedSec / 60;
        let etaFormatted = '-';
        if (state.pricesFound > 0 && pricesNeeded > 0 && elapsedMin > 0) {
          const rate = state.pricesFound / elapsedMin;
          const etaMin = pricesNeeded / rate;
          etaFormatted = etaMin >= 60 ? `${Math.floor(etaMin / 60)}h ${Math.floor(etaMin % 60)}m` : `${Math.floor(etaMin)}m ${Math.floor((etaMin % 1) * 60)}s`;
        }
        const elapsedFormatted = `${Math.floor(elapsedSec / 60)}m ${Math.floor(elapsedSec % 60)}s`;

        const logLines = [];
        try {
          const lf = fs.readFileSync(LOG_FILE, 'utf-8');
          logLines.push(...lf.split(/\r?\n/).filter(Boolean));
        } catch {}

        const data = {
          status: state.status, phase: state.phase, phaseNumber: state.phaseNumber || 0,
          category: state.category || null, pid: state.pid,
          pricesChecked: state.pricesChecked || 0,
          pricesFound: state.pricesFound || 0,
          pricesUpdated: state.pricesUpdated || 0,
          pricesSkipped: state.pricesSkipped || 0,
          pricesNeeded,
          itemsProcessed, itemsTotal, progressPct: pct,
          etaFormatted, restartCount: state.restartCount || 0,
          currentItem: state.currentItem || '',
          batchDone: state.pricesChecked % 5 || 0,
          batchTotal: 5,
          elapsedFormatted,
          lastProgressTime: state.lastProgressTime,
          lastSuccess: state.lastSuccess,
          categories, logLines,
        };
        res.writeHead(200, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' });
        res.end(JSON.stringify(data));
      } catch {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: state.status, phase: state.phase, pid: state.pid, categories: [], logLines: [] }));
      }
      return;
    }
    res.writeHead(200, { 'Content-Type': 'text/html' });
    res.end(generateDashboardHTML());
  });
  dashboardServer.listen(DASHBOARD_PORT, () => {
    log(`Dashboard: http://localhost:${DASHBOARD_PORT}`);
  });
  dashboardUpdateInterval = setInterval(() => {
    if (dashboardServer) {
      try { dashboardServer.closeAllConnections?.(); } catch {}
    }
  }, 30000);
}

function stopDashboard() {
  if (dashboardUpdateInterval) { clearInterval(dashboardUpdateInterval); dashboardUpdateInterval = null; }
  if (dashboardServer) {
    try { dashboardServer.closeAllConnections?.(); } catch {}
    dashboardServer.close(() => { dashboardServer = null; });
    dashboardServer = null;
  }
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
    if (!state.scrapeOnly) {
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

      state.phase = 'Import Data Sources'; saveState();
      await importDataSources();
    } else {
      log('  --scrape-only: skipping import phases');
    }

    state.phase = 'Scrape Missing Prices'; saveState();
    await scrapeMissingPrices(state.categories);

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
  --no-dashboard    Disable dashboard server
  --dashboard-only  Start dashboard only (no update)
  --scrape-only     Skip import phases, only scrape missing prices
  --categories X    Only scrape prices for given CSV categories (comma-sep, e.g. cpu,gpu,case)
    `);
    process.exit(0);
  }

  const noDashboard = args.includes('--no-dashboard');
  const dashboardOnly = args.includes('--dashboard-only');
  const scrapeOnly = args.includes('--scrape-only');
  const catIdx = args.indexOf('--categories');
  const categories = catIdx >= 0 && catIdx + 1 < args.length ? args[catIdx + 1] : null;

  loadState();
  state.categories = categories;
  state.scrapeOnly = scrapeOnly;

  if (!noDashboard) startDashboard();

  if (dashboardOnly) {
    log(`Dashboard-only mode on port ${DASHBOARD_PORT} (Ctrl+C to stop)`);
    state.status = 'idle';
    state.pid = process.pid;
    saveState();
    process.on('SIGINT', () => { stopDashboard(); process.exit(0); });
    process.on('SIGTERM', () => { stopDashboard(); process.exit(0); });
    return;
  }

  runUpdate().then(() => {
    if (noDashboard) process.exit(0);
  }).catch(err => {
    log(`FATAL: ${err.message}`);
    if (noDashboard) process.exit(1);
  });
}

main();
