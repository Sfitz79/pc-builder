/**
 * MASTER COMBINED - Unified Product Data Pipeline
 * Combines: PCPartPicker scraper + Image scraping + Price scraping + Discontinued filtering
 *
 * Pipeline:
 *   1. Scrape PCPartPicker UK for product data → JSON + CSV
 *   2. Filter discontinued / legacy items
 *   3. Download product images (PCPP CDN → retailers → web search)
 *   4. Scrape current UK retailer prices
 *   5. Live dashboard at http://localhost:3333
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { execSync } from 'child_process';
import http from 'http';
import sharp from 'sharp';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.join(__dirname, 'src', 'data');
const SCRAPED_DIR = path.join(__dirname, 'scraped_data');
const THUMBNAILS_DIR = path.join(__dirname, 'public', 'thumbnails');
const LOG_FILE = path.join(__dirname, 'master-combined.log');
const PROGRESS_JSON = path.join(__dirname, 'master-combined-progress.json');
const DASHBOARD_HTML = path.join(__dirname, 'master-combined-dashboard.html');
const DOWNLOADED_CSV_DIR = 'C:\\Users\\simon\\Downloads';

const BATCH_SIZE = 100;
const DELAY_BETWEEN_ITEMS = 300;
const DELAY_BETWEEN_CATEGORIES = 5000;
const MAX_IMAGES_PER_CATEGORY = 200;

const MODERN_SOCKETS = new Set(['AM4', 'AM5', 'LGA1700', 'LGA1851']);

const DOWNLOADED_CSV_MAP = [
  { file: 'uk-pcpartpicker-com-2026-06-09.csv', category: 'cpu' },
  { file: 'uk-pcpartpicker-com-2026-06-09 (1).csv', category: 'cooler' },
  { file: 'uk-pcpartpicker-com-2026-06-09 (2).csv', category: 'ram' },
  { file: 'uk-pcpartpicker-com-2026-06-09 (3).csv', category: 'storage' },
  { file: 'uk-pcpartpicker-com-2026-06-09 (4).csv', category: 'storage' },
  { file: 'uk-pcpartpicker-com-2026-06-09 (5).csv', category: 'gpu' },
  { file: 'uk-pcpartpicker-com-2026-06-09 (6).csv', category: 'power-supply' },
];

const RETAILERS = {
  'scan.co.uk': (q) => `https://www.scan.co.uk/search#q=${encodeURIComponent(q)}`,
  'overclockers.co.uk': (q) => `https://www.overclockers.co.uk/search?search=${encodeURIComponent(q)}`,
  'box.co.uk': (q) => `https://www.box.co.uk/search?q=${encodeURIComponent(q)}`,
  'ebuyer.com': (q) => `https://www.ebuyer.com/search?q=${encodeURIComponent(q)}`,
  'amazon.co.uk': (q) => `https://www.amazon.co.uk/s?k=${encodeURIComponent(q)}`,
  'cclonline.com': (q) => `https://www.cclonline.com/catalogsearch/result/?q=${encodeURIComponent(q)}`,
};

const EXCLUDE_PATTERNS = [
  /pixel|tracking|analytics|logo|icon|placeholder|banner|sponsor/i,
  /badge|star|rating|review|avatar|profile|colour_swatch/i,
  /checkout|captcha|transparent|empty|no_image|not_found|coming_soon/i,
  /data:image|svg\+xml|\.svg/i,
  /wishlist|compare|cart_badge|newsletter/i,
  /social|facebook|twitter|instagram|linkedin|youtube|pinterest/i,
  /favicon|apple-touch|manifest|mask-icon|loader|spinner|loading|ajax/i,
  /thumb|thumbnail|_tn|\/small\/|\/tiny\/|\/mini\/|\/x[0-9]{2,3}\.jpg/i,
  /50x50|75x75|100x100|150x150|200x200|badge_/i,
];

const CATEGORY_IMAGE_KEYWORDS = {
  'case': ['case', 'chassis', 'tower'],
  'case-with-images': ['case', 'chassis', 'tower'],
  'cpu': ['cpu', 'processor', 'ryzen', 'core'],
  'gpu': ['gpu', 'graphics', 'geforce', 'radeon'],
  'motherboard': ['motherboard', 'mainboard'],
  'ram': ['ram', 'memory', 'ddr'],
  'storage': ['ssd', 'hard drive', 'nvme'],
  'power-supply': ['psu', 'power supply'],
  'cooler': ['cooler', 'fan', 'liquid', 'aio', 'heatsink'],
  'case-fan': ['case fan', 'fan'],
  'monitor': ['monitor', 'display'],
  'keyboard': ['keyboard'],
  'mouse': ['mouse'],
  'headphones': ['headphone', 'headset'],
  'speakers': ['speaker'],
  'webcam': ['webcam', 'camera'],
  'wireless-network-card': ['wireless', 'wifi'],
  'wired-network-card': ['network', 'ethernet'],
  'sound-card': ['sound card', 'audio'],
  'external-hard-drive': ['external hard', 'portable ssd', 'usb drive'],
  'optical-drive': ['optical', 'blu-ray', 'dvd'],
  'ups': ['ups', 'battery backup'],
  'case-accessory': ['case accessory'],
  'fan-controller': ['fan controller'],
  'thermal-paste': ['thermal paste'],
  'os': ['windows'],
};

const startTime = Date.now();

// ============ HELPERS ============

function log(msg) {
  const ts = new Date().toISOString();
  console.log(`[${ts}] ${msg}`);
  try { fs.appendFileSync(LOG_FILE, `[${ts}] ${msg}\n`); } catch {}
}

function normalizeToken(str) { return (str || '').toString().toUpperCase().trim(); }
function sanitizeFilename(name) { return name.replace(/[^a-z0-9]/gi, '_').toLowerCase().substring(0, 50); }

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

// ============ DISCONTINUED FILTER ============

function isModernComponent(categoryId, item) {
  if (!item) return true;
  switch (categoryId) {
    case 'cpu': {
      const name = normalizeToken(item.name);
      if (name.includes('RYZEN')) {
        const match = name.match(/RYZEN\s+(\d)/);
        if (match && parseInt(match[1]) < 5) return false;
        if (name.includes('3200') || name.includes('2200') || name.includes('1200') || name.includes('1600')) return false;
      }
      if (name.includes('I3') || name.includes('I5') || name.includes('I7') || name.includes('I9')) {
        const match = name.match(/(\d{4})/);
        if (match) { const gen = parseInt(match[1].charAt(0)); if (gen < 10) return false; }
      }
      return true;
    }
    case 'motherboard': {
      const socket = normalizeToken(item.socket);
      if (socket && !MODERN_SOCKETS.has(socket)) return false;
      return true;
    }
    case 'ram': {
      const speed = normalizeToken(item.speed);
      if (speed && !speed.includes('DDR4') && !speed.includes('DDR5')) {
        if (speed.includes('DDR') && !speed.includes('DDR4') && !speed.includes('DDR5')) return false;
      }
      return true;
    }
    case 'power-supply': { const wattage = parseFloat(item.wattage); if (wattage > 0 && wattage < 450) return false; return true; }
    case 'gpu': {
      const name = normalizeToken(item.name); const memory = parseFloat(item.memory);
      if (name.includes('GTX') && !name.includes('1660') && !name.includes('1630')) return false;
      if (name.includes('RTX')) { const m = name.match(/RTX\s*(\d+)/); if (m && parseInt(m[1]) < 2000) return false; }
      if (name.includes('RX')) { const m = name.match(/RX\s*(\d+)/); if (m && parseInt(m[1]) < 500) return false; }
      if (memory > 0 && memory < 6) return false; return true;
    }
    case 'storage': { const cap = parseFloat(item.capacity); if (cap > 0 && cap < 120) return false; return true; }
    case 'monitor': { const res = normalizeToken(item.resolution); if (res && (res.includes('1366') || res.includes('1280'))) return false; return true; }
    default: return true;
  }
}

// ============ PROGRESS STATE ============

const progress = {
  categories: {},
  currentCategory: '',
  currentItem: 0,
  totalItems: 0,
  overallProcessed: 0,
  overallTotal: 0,
  imagesFound: 0,
  imagesSkipped: 0,
  imagesFailed: 0,
  pricesFound: 0,
  filteredRemoved: 0,
  discontinuedRemoved: 0,
  startTime: startTime,
  status: 'initialising',
};

function writeProgress() {
  try {
    progress.elapsed = (Date.now() - startTime) / 1000;
    progress.elapsedFormatted = `${Math.floor(progress.elapsed / 60)}m ${Math.floor(progress.elapsed % 60)}s`;
    fs.writeFileSync(PROGRESS_JSON, JSON.stringify(progress, null, 2));
  } catch {}
}

// ============ DASHBOARD ============

let dashboardServer = null;

function startDashboard() {
  const PORT = 3334;
  generateDashboardHTML();
  dashboardServer = http.createServer((req, res) => {
    if (req.url === '/progress') {
      try {
        res.writeHead(200, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' });
        res.end(fs.readFileSync(PROGRESS_JSON, 'utf-8'));
      } catch { res.end(JSON.stringify(progress)); }
    } else {
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end(fs.readFileSync(DASHBOARD_HTML, 'utf-8'));
    }
  });
  dashboardServer.listen(PORT, () => {
    log(`Dashboard: http://localhost:${PORT}`);
    try { execSync(`start http://localhost:${PORT}`, { shell: 'cmd.exe', timeout: 3000 }); } catch {}
  });
}

function stopDashboard() { if (dashboardServer) { dashboardServer.close(); dashboardServer = null; } }

function generateDashboardHTML() {
  const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Master Combined - Progress</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Segoe UI', system-ui, sans-serif; background: #0d1117; color: #c9d1d9; padding: 24px; }
  h1 { font-size: 24px; color: #58a6ff; margin-bottom: 8px; }
  .subtitle { color: #8b949e; font-size: 14px; margin-bottom: 24px; }
  .grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 24px; }
  .card { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 20px; }
  .card h3 { font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: #8b949e; margin-bottom: 12px; }
  .stat-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #21262d; }
  .stat-row:last-child { border-bottom: none; }
  .stat-label { color: #8b949e; }
  .stat-value { font-weight: 600; }
  .stat-value.green { color: #3fb950; } .stat-value.red { color: #f85149; }
  .stat-value.blue { color: #58a6ff; } .stat-value.yellow { color: #d29922; }
  .stat-value.purple { color: #bc8cff; }
  .bar-container { background: #21262d; border-radius: 6px; height: 24px; overflow: hidden; margin: 8px 0; }
  .bar { height: 100%; background: linear-gradient(90deg, #1f6feb, #58a6ff); transition: width 0.5s ease; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; color: #fff; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th { text-align: left; padding: 8px 12px; color: #8b949e; border-bottom: 2px solid #30363d; }
  td { padding: 8px 12px; border-bottom: 1px solid #21262d; }
  .cat-name { font-weight: 500; }
  .mini-bar { background: #21262d; border-radius: 4px; height: 16px; overflow: hidden; min-width: 80px; }
  .mini-fill { height: 100%; background: linear-gradient(90deg, #1f6feb, #58a6ff); border-radius: 4px; transition: width 0.5s ease; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
  .badge.done { background: #1b3a1d; color: #3fb950; }
  .badge.active { background: #1c3a5e; color: #58a6ff; }
  .badge.pending { background: #2d2416; color: #d29922; }
  .text-right { text-align: right; }
  .mt-24 { margin-top: 24px; }
  #status { font-family: monospace; }
  @media (max-width: 768px) { .grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>
  <h1>&#9881; Master Combined Pipeline</h1>
  <div class="subtitle" id="status">Connecting...</div>
  <div class="grid">
    <div class="card">
      <h3>Progress</h3>
      <div class="stat-row"><span class="stat-label">Status</span><span class="stat-value blue" id="overallStatus">-</span></div>
      <div class="stat-row"><span class="stat-label">Elapsed</span><span class="stat-value" id="elapsed">-</span></div>
      <div class="bar-container"><div class="bar" id="overallBar" style="width:0%">0%</div></div>
      <div class="stat-row"><span class="stat-label">Items</span><span class="stat-value" id="itemsProgress">-</span></div>
    </div>
    <div class="card">
      <h3>Images</h3>
      <div class="stat-row"><span class="stat-label">Downloaded</span><span class="stat-value green" id="imagesFound">0</span></div>
      <div class="stat-row"><span class="stat-label">Skipped</span><span class="stat-value blue" id="imagesSkipped">0</span></div>
      <div class="stat-row"><span class="stat-label">Failed</span><span class="stat-value red" id="imagesFailed">0</span></div>
    </div>
    <div class="card">
      <h3>Data</h3>
      <div class="stat-row"><span class="stat-label">Prices Found</span><span class="stat-value green" id="pricesFound">0</span></div>
      <div class="stat-row"><span class="stat-label">Discontinued</span><span class="stat-value yellow" id="filteredRemoved">0</span></div>
      <div class="stat-row"><span class="stat-label">Current Action</span><span class="stat-value purple" id="currentAction">-</span></div>
    </div>
  </div>
  <div class="card">
    <h3>Categories</h3>
    <table>
      <thead><tr><th>Category</th><th>Status</th><th>Progress</th><th class="text-right">Images</th><th class="text-right">Prices</th><th class="text-right">Items</th></tr></thead>
      <tbody id="catTable"></tbody>
    </table>
  </div>
  <div class="subtitle mt-24" id="currentActionDetail">-</div>
  <script>
    async function poll() {
      try {
        const r = await fetch('/progress'); const d = await r.json();
        const total = d.overallTotal || 1; const pct = Math.round((d.overallProcessed / total) * 100);
        document.getElementById('overallStatus').textContent = d.status || '-';
        document.getElementById('elapsed').textContent = d.elapsedFormatted || '-';
        document.getElementById('itemsProgress').textContent = d.overallProcessed + ' / ' + total + ' (' + pct + '%)';
        document.getElementById('overallBar').style.width = pct + '%';
        document.getElementById('overallBar').textContent = pct + '%';
        document.getElementById('imagesFound').textContent = d.imagesFound || 0;
        document.getElementById('imagesSkipped').textContent = d.imagesSkipped || 0;
        document.getElementById('imagesFailed').textContent = d.imagesFailed || 0;
        document.getElementById('pricesFound').textContent = d.pricesFound || 0;
        document.getElementById('filteredRemoved').textContent = d.filteredRemoved || 0;
        document.getElementById('currentAction').textContent = d.currentCategory || '-';
        document.getElementById('currentActionDetail').textContent = d.currentCategory ? '> ' + d.currentCategory + ': item ' + (d.currentItem || 0) + '/' + (d.totalItems || 0) : '-';
        const cats = d.categories || {}; const keys = Object.keys(cats);
        let html = '';
        for (const key of keys) {
          const c = cats[key]; const cpct = c.total > 0 ? Math.round((c.processed / c.total) * 100) : 0;
          const badge = c.done ? '<span class="badge done">DONE</span>' : (c.active ? '<span class="badge active">ACTIVE</span>' : '<span class="badge pending">PENDING</span>');
          html += '<tr><td class="cat-name">' + key + '</td><td>' + badge + '</td><td><div class="mini-bar"><div class="mini-fill" style="width:' + cpct + '%">&nbsp;</div></div></td><td class="text-right">' + (c.images || 0) + '</td><td class="text-right">' + (c.prices || 0) + '</td><td class="text-right">' + (c.processed || 0) + '/' + (c.total || 0) + '</td></tr>';
        }
        document.getElementById('catTable').innerHTML = html || '<tr><td colspan="6" style="text-align:center;color:#8b949e;">Waiting...</td></tr>';
        if (d.status === 'complete') document.getElementById('status').textContent = 'COMPLETED - Close this window when done';
        else if (d.status === 'error') document.getElementById('status').textContent = 'ERROR - ' + (d.error || '');
        else document.getElementById('status').textContent = 'Last updated: ' + new Date().toLocaleTimeString() + ' (auto-refreshing)';
      } catch { document.getElementById('status').textContent = 'Waiting for server...'; }
    }
    setInterval(poll, 1000); poll();
  </script>
</body>
</html>`;
  fs.writeFileSync(DASHBOARD_HTML, html);
}

// ============ NETWORK ============

async function fetchWithTimeout(url, timeout = 10000) {
  const controller = new AbortController();
  const id = setTimeout(() => controller.abort(), timeout);
  try {
    const res = await fetch(url, { signal: controller.signal, headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' } });
    clearTimeout(id); return res;
  } catch (e) { clearTimeout(id); throw e; }
}

function isRealProductImage(url, category) {
  if (EXCLUDE_PATTERNS.some(p => p.test(url))) return false;
  if (url.length < 50) return false;
  if (!/\.(jpg|jpeg|png|webp|avif)(\?.*)?$/i.test(url)) return false;
  if (/[?&](width|w|h|height|s|size)=[0-9]{1,2}([^0-9]|$)/i.test(url)) return false;
  if (/\/thumbnails\/|\/thumbnail\/|\/_thumb\/|\/small\/|\/tiny\/|\/mini\//i.test(url)) return false;
  const keywords = CATEGORY_IMAGE_KEYWORDS[category] || [];
  if (keywords.length > 0) {
    const urlLower = url.toLowerCase();
    if (!keywords.some(k => urlLower.includes(k.replace(/\s+/g, '')) || urlLower.includes(k.replace(/\s+/g, '_')) || urlLower.includes(k.replace(/\s+/g, '-')))) {
      return keywords.length <= 2;
    }
  }
  return true;
}

async function verifyAndDownload(url, dest) {
  try {
    const controller = new AbortController();
    const id = setTimeout(() => controller.abort(), 8000);
    const resp = await fetch(url, { method: 'HEAD', signal: controller.signal, headers: { 'User-Agent': 'Mozilla/5.0' }, redirect: 'follow' });
    clearTimeout(id);
    if (!resp.ok) return false;
    const ct = resp.headers.get('content-type') || '';
    const cl = parseInt(resp.headers.get('content-length') || '0');
    if (!ct.startsWith('image/') || (cl > 0 && cl < 2048)) return false;
  } catch { return false; }

  try {
    const controller = new AbortController();
    const id = setTimeout(() => controller.abort(), 15000);
    const resp = await fetch(url, { signal: controller.signal, headers: { 'User-Agent': 'Mozilla/5.0', 'Accept': 'image/webp,image/apng,image/*,*/*' }, redirect: 'follow' });
    clearTimeout(id);
    if (!resp.ok) return false;
    const buffer = Buffer.from(await resp.arrayBuffer());
    if (buffer.length < 4096) return false;
    try {
      const meta = await sharp(buffer).metadata();
      if (meta.width < 200 || meta.height < 200) return false;
    } catch { return false; }
    fs.writeFileSync(dest, buffer);
    return true;
  } catch { return false; }
}

// ============ DOWNLOADED CSV PARSER ============

const SKIP_COLUMNS = new Set(['web_scraper_order', 'web_scraper_start_url', 'pagination', 'name2', 'name3']);

function deriveLabel(columnName) {
  return columnName.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
}

function parseSpecValue(columnName, rawValue) {
  if (!rawValue) return '';
  const label = deriveLabel(columnName);
  const v = rawValue.trim();
  if (v.length >= label.length && v.substring(0, label.length).toLowerCase() === label.toLowerCase()) {
    return v.substring(label.length).trim();
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

function parseDownloadedCSV(category) {
  const entries = DOWNLOADED_CSV_MAP.filter(e => e.category === category);
  if (entries.length === 0) return [];

  const seen = new Set();
  const items = [];
  const priceCols = ['price', 'price2', 'price3', 'price4'];
  const ratingCols = ['rating', 'name3'];

  for (const entry of entries) {
    const filePath = path.join(DOWNLOADED_CSV_DIR, entry.file);
    if (!fs.existsSync(filePath)) {
      log(`  Downloaded CSV not found: ${filePath}`);
      continue;
    }

    const content = fs.readFileSync(filePath, 'utf-8');
    const lines = content.split(/\r?\n/).filter(l => l.trim());
    if (lines.length < 2) continue;

    let headerIdx = 0;
    if (lines.length >= 2 && lines[0] === lines[1]) headerIdx = 1;

    const header = parseCSVLine(lines[headerIdx]);

    const specCols = [];
    for (let i = 0; i < header.length; i++) {
      const h = header[i].trim();
      if (!h || SKIP_COLUMNS.has(h)) continue;
      if (h === 'name' || h === 'name4' || priceCols.includes(h) || ratingCols.includes(h)) continue;
      if (h.endsWith('2')) continue;
      specCols.push({ index: i, name: h });
    }

    for (let i = headerIdx + 1; i < lines.length; i++) {
      const parts = parseCSVLine(lines[i]);
      if (parts.length < header.length) continue;

      const name = (parts[header.indexOf('name')] || '').trim();
      if (!name || seen.has(name)) continue;
      seen.add(name);

      const name4Idx = header.indexOf('name4');
      const imageUrl = (name4Idx >= 0 && name4Idx < parts.length) ? (parts[name4Idx] || '').trim() : '';

      let price = null;
      for (const pc of priceCols) {
        const idx = header.indexOf(pc);
        if (idx >= 0 && idx < parts.length) {
          price = extractPrice(parts[idx]);
          if (price) break;
        }
      }

      let rating = null;
      for (const rc of ratingCols) {
        const idx = header.indexOf(rc);
        if (idx >= 0 && idx < parts.length) {
          const rm = parts[idx].match(/(\d+)/);
          if (rm) { rating = parseInt(rm[1]); break; }
        }
      }

      const specs = {};
      for (const col of specCols) {
        if (col.index < parts.length) {
          const raw = parts[col.index] || '';
          const val = parseSpecValue(col.name, raw);
          if (val) specs[col.name] = val;
        }
      }

      items.push({
        productName: name,
        name: name,
        category,
        country: 'uk',
        price,
        priceCurrency: price ? '£' : null,
        rating,
        ratingCount: null,
        specs,
        imageUrl: imageUrl || null,
        url: null,
        scrapedAt: new Date().toISOString(),
        source: 'downloaded-csv',
      });
    }

    log(`  Parsed ${seen.size} unique products from ${entry.file}`);
  }

  return items;
}

// ============ RETAILER PRICE SCRAPING ============

async function scrapeRetailerPrices(name) {
  const searchName = name.replace(/[^\w\s]/g, ' ').trim().substring(0, 100);
  const allPrices = [];

  for (const [retailer, urlFunc] of Object.entries(RETAILERS)) {
    try {
      const url = urlFunc(searchName);
      const response = await fetchWithTimeout(url, 8000);
      if (!response.ok) continue;
      const html = await response.text();

      const priceMatches = html.match(/\xA3\s*(\d+(?:,\d{3})*(?:\.\d{2})?)/g);
      if (priceMatches) {
        for (const pm of priceMatches) {
          const price = parseFloat(pm.replace(/\xA3|,/g, ''));
          if (price > 1 && price < 20000) allPrices.push(price);
        }
      }

      const jsonLdMatches = html.matchAll(/<script[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi);
      for (const jm of jsonLdMatches) {
        try {
          const obj = JSON.parse(jm[1]);
          const offers = obj.offers || obj.mainEntity?.offers;
          if (offers) {
            const price = parseFloat(offers.price || offers.lowPrice || 0);
            if (price > 1 && price < 20000) allPrices.push(price);
          }
        } catch {}
      }
    } catch {}
  }

  return allPrices;
}

// ============ RETAILER IMAGE SCRAPING ============

async function scrapeRetailerImages(name, category) {
  const searchName = name.replace(/[^\w\s]/g, ' ').trim().substring(0, 100);
  const allUrls = new Map();

  for (const [retailer, urlFunc] of Object.entries(RETAILERS)) {
    if (allUrls.size >= 5) break;
    try {
      const url = urlFunc(searchName);
      const response = await fetchWithTimeout(url);
      if (!response.ok) continue;
      const html = await response.text();

      const patterns = [
        /<img[^>]+src="(https?:\/\/[^"']+\.(?:jpg|jpeg|png|webp|avif)[^"']*)"/gi,
        /<img[^>]+data-src="(https?:\/\/[^"']+\.(?:jpg|jpeg|png|webp|avif)[^"']*)"/gi,
      ];
      for (const pattern of patterns) {
        const matches = html.matchAll(pattern);
        for (const match of matches) {
          if (allUrls.size >= 10) break;
          const u = match[1].split(',')[0].trim().split(' ')[0];
          if (u && u.length > 50 && isRealProductImage(u, category)) allUrls.set(u, (allUrls.get(u) || 0) + 1);
        }
      }

      const links = [...html.matchAll(/href="(https?:\/\/[^"']+\/(?:[^"']*?(?:product|item|p|dp)[^"']*))"/gi)];
      const productLinks = new Set();
      for (const m of links) { if (productLinks.size >= 2) break; const l = m[1]; if (l && !/\/cart|\/login|\/wishlist/i.test(l)) productLinks.add(l); }

      for (const pl of productLinks) {
        if (allUrls.size >= 10) break;
        try {
          const resp = await fetchWithTimeout(pl);
          if (!resp.ok) continue;
          const pageHtml = await resp.text();

          const zoomImgs = [...pageHtml.matchAll(/(?:data-zoom-image|data-large-image|data-zoom|data-big|data-fullsize)=["'](https?:\/\/[^"']+\.(?:jpg|jpeg|png|webp|avif)[^"']*)["']/gi)];
          for (const z of zoomImgs) { if (allUrls.size >= 10) break; const u = z[1]; if (u && u.length > 80 && isRealProductImage(u, category)) allUrls.set(u, (allUrls.get(u) || 0) + 3); }

          const og = pageHtml.match(/<meta[^>]*property=["']og:image["'][^>]*content=["']([^"']+)["'][^>]*>/i);
          if (og && og[1] && og[1].length > 80 && isRealProductImage(og[1], category)) allUrls.set(og[1], (allUrls.get(og[1]) || 0) + 2);

          const gm = pageHtml.matchAll(/<img[^>]+(?:data-src|src)=["'](https?:\/\/[^"']+\.(?:jpg|jpeg|png|webp|avif)[^"']*)["'][^>]*>/gi);
          for (const g of gm) { if (allUrls.size >= 10) break; const u = g[1]; if (u && u.length > 50 && isRealProductImage(u, category)) allUrls.set(u, (allUrls.get(u) || 0) + 1); }
        } catch {}
      }
    } catch {}
  }

  return [...allUrls.entries()].sort((a, b) => b[1] - a[1]).map(([url]) => url);
}

async function searchWebImages(name, category) {
  const searchName = name.replace(/[^\w\s]/g, ' ').trim().substring(0, 80);
  const allUrls = new Set();

  const queries = [
    `https://r.jina.ai/http://www.bing.com/images/search?q=${encodeURIComponent(searchName + ' ' + (category || '') + ' product')}`,
    `https://r.jina.ai/https://duckduckgo.com/?q=${encodeURIComponent(searchName + ' ' + (category || ''))}&ia=images`,
  ];

  for (const jinaUrl of queries) {
    if (allUrls.size >= 3) break;
    try {
      const response = await fetchWithTimeout(jinaUrl, 15000);
      if (!response.ok) continue;
      const text = await response.text();
      const matches = text.matchAll(/https?:\/\/[^\s"'<>]+\.(?:jpg|jpeg|png|webp)/gi);
      for (const match of matches) { const url = match[0]; if (!/pixel|tracking|analytics|logo|icon|placeholder|jina|bing|google\./i.test(url) && url.length > 50 && allUrls.size < 5) allUrls.add(url); }
    } catch {}
  }

  return Array.from(allUrls);
}

// ============ DOWNLOAD IMAGE FOR ITEM ============

async function downloadImageForItem(name, category, existingUrl) {
  const sanitized = sanitizeFilename(name);

  if (existingUrl && /^https?:\/\//i.test(existingUrl)) {
    const ext = (existingUrl.match(/\.(jpg|jpeg|png|webp|avif)/i) || [])[0] || '.jpg';
    const local = `${category}_${sanitized}${ext}`;
    const dest = path.join(THUMBNAILS_DIR, local);
    log(`  Trying existing URL: ${existingUrl.substring(0, 80)}`);
    if (await verifyAndDownload(existingUrl, dest)) {
      return `thumbnails/${local}`;
    }
  }

  const retailerUrls = await scrapeRetailerImages(name, category);
  for (const url of retailerUrls) {
    const ext = (url.match(/\.(jpg|jpeg|png|webp|avif)/i) || [])[0] || '.jpg';
    const local = `${category}_${sanitized}${ext}`;
    const dest = path.join(THUMBNAILS_DIR, local);
    if (await verifyAndDownload(url, dest)) {
      return `thumbnails/${local}`;
    }
  }

  const webUrls = await searchWebImages(name, category);
  for (const url of webUrls) {
    const ext = (url.match(/\.(jpg|jpeg|png|webp)/i) || [])[0] || '.jpg';
    const local = `${category}_${sanitized}${ext}`;
    const dest = path.join(THUMBNAILS_DIR, local);
    if (await verifyAndDownload(url, dest)) {
      return `thumbnails/${local}`;
    }
  }

  return null;
}

// ============ SAVE ENRICHED RESULTS ============

function saveEnrichedJSON(category, items) {
  if (!fs.existsSync(SCRAPED_DIR)) fs.mkdirSync(SCRAPED_DIR, { recursive: true });
  const filePath = path.join(SCRAPED_DIR, `${category}.json`);
  fs.writeFileSync(filePath, JSON.stringify(items, null, 2), 'utf-8');
}

function saveEnrichedCSV(category, items) {
  const filePath = path.join(DATA_DIR, `${category}.csv`);
  if (items.length === 0) return;

  const allKeys = new Set(['name', 'price', 'image']);
  for (const item of items) {
    if (item.specs) Object.keys(item.specs).forEach(k => allKeys.add(k));
  }
  const headers = Array.from(allKeys);
  const lines = [headers.join(',')];

  for (const item of items) {
    const row = headers.map(h => {
      if (h === 'name') return item.productName || item.name || '';
      if (h === 'price') return item.price || '';
      if (h === 'image') return item.localImage || item.imageUrl || '';
      if (item.specs) return item.specs[h] || '';
      return '';
    });
    lines.push(row.join(','));
  }

  fs.writeFileSync(filePath, lines.join('\n'), 'utf-8');
}

// ============ PROCESS CATEGORY ============

async function processCategory(category) {
  progress.currentCategory = category;
  writeProgress();

  const jsonFile = path.join(SCRAPED_DIR, `${category}.json`);

  // Phase 1: Parse downloaded CSV
  log(`[Phase 1] Parsing downloaded CSV for ${category}...`);
  const csvItems = parseDownloadedCSV(category);

  // Phase 2: Fallback to existing JSON if CSV failed
  log(`[Phase 2] Loading existing data for ${category}...`);
  let existingItems = csvItems;

  if (existingItems.length === 0 && fs.existsSync(jsonFile)) {
    try {
      existingItems = JSON.parse(fs.readFileSync(jsonFile, 'utf-8'));
      existingItems = Array.isArray(existingItems) ? existingItems : [existingItems];
      log(`  Loaded ${existingItems.length} items from scraped JSON (fallback)`);
    } catch {}
  }

  // Phase 3: Filter discontinued / legacy items
  log(`[Phase 3] Filtering discontinued items for ${category}...`);
  const modernItems = [];
  let filteredCount = 0;
  for (const item of existingItems) {
    if (isModernComponent(category, item)) {
      modernItems.push(item);
    } else {
      filteredCount++;
    }
  }
  progress.filteredRemoved += filteredCount;
  log(`  Removed ${filteredCount} discontinued, ${modernItems.length} remain`);

  const items = modernItems;

  // Phase 4: Enrich with images + prices
  const parseOnly = process.argv.includes('--parse-only');
  if (!parseOnly) {
    log(`[Phase 4] Enriching ${items.length} items with images and prices...`);

    const batch = items.slice(0, MAX_IMAGES_PER_CATEGORY);
    progress.totalItems = batch.length;
    progress.currentItem = 0;

    let imagesFound = 0, imagesSkipped = 0, imagesFailed = 0, pricesFound = 0;

    for (let j = 0; j < batch.length; j++) {
      const item = batch[j];
      progress.currentItem = j + 1;
      progress.overallProcessed++;
      writeProgress();

      const name = item.productName || item.name;
      process.stdout.write(`\r  [${j + 1}/${batch.length}] ${(name || '').substring(0, 45)}...`);

      const existingImage = item.imageUrl || item.image || '';
      if (existingImage) {
        const localRef = await downloadImageForItem(name, category, existingImage);
        if (localRef) {
          item.localImage = localRef;
          imagesFound++;
          progress.imagesFound++;
        } else {
          item.localImage = existingImage;
          imagesFailed++;
          progress.imagesFailed++;
        }
      } else {
        const localRef = await downloadImageForItem(name, category, '');
        if (localRef) {
          item.localImage = localRef;
          imagesFound++;
          progress.imagesFound++;
        } else {
          imagesFailed++;
          progress.imagesFailed++;
        }
      }

      // Scrape price if missing
      const currentPrice = parseFloat(item.price);
      if (!currentPrice || currentPrice <= 0) {
        const prices = await scrapeRetailerPrices(name);
        if (prices.length > 0) {
          const avgPrice = prices.reduce((a, b) => a + b, 0) / prices.length;
          item.price = avgPrice;
          if (item.priceCurrency === '$') item.priceCurrency = '£';
          pricesFound++;
          progress.pricesFound++;
        }
      }

      progress.status = 'running';
      writeProgress();

      await new Promise(r => setTimeout(r, DELAY_BETWEEN_ITEMS));
    }

    progress.categories[category] = {
      processed: batch.length,
      total: items.length,
      images: imagesFound,
      prices: pricesFound,
      active: false,
      done: true,
    };

    log(`  Done: ${imagesFound} images, ${pricesFound} prices (${imagesSkipped} skipped, ${imagesFailed} failed)`);
    return { images: imagesFound, prices: pricesFound, filtered: filteredCount };
  } else {
    log(`[Phase 4] Skipping enrichment (--parse-only mode)`);
    progress.categories[category] = {
      processed: items.length,
      total: items.length,
      images: 0,
      prices: 0,
      active: false,
      done: true,
    };
  }

  // Save enriched data
  saveEnrichedJSON(category, items);
  saveEnrichedCSV(category, items);
  log(`  Saved ${items.length} items to JSON + CSV`);
  return { images: 0, prices: 0, filtered: filteredCount };
}

function mergeDeduplicate(csvItems, pcppItems) {
  const seen = new Set();
  const merged = [];

  for (const item of [...pcppItems, ...csvItems]) {
    const name = item.productName || item.name || '';
    const key = normalizeToken(name);
    if (key && !seen.has(key)) {
      seen.add(key);
      merged.push(item);
    }
  }

  return merged;
}

// ============ MAIN ============

async function main() {
  log('\n' + '='.repeat(60));
  log('MASTER COMBINED - Unified Product Data Pipeline');
  log('='.repeat(60));

  if (!fs.existsSync(THUMBNAILS_DIR)) fs.mkdirSync(THUMBNAILS_DIR, { recursive: true });
  if (!fs.existsSync(SCRAPED_DIR)) fs.mkdirSync(SCRAPED_DIR, { recursive: true });

  startDashboard();
  writeProgress();

  const categories = [...new Set(DOWNLOADED_CSV_MAP.map(e => e.category))];
  progress.overallTotal = categories.length;
  progress.status = 'running';
  writeProgress();

  let totalImages = 0, totalPrices = 0, totalFiltered = 0;

  for (let ci = 0; ci < categories.length; ci++) {
    const cat = categories[ci];

    if (!progress.categories[cat]) {
      progress.categories[cat] = { processed: 0, total: 0, images: 0, prices: 0, active: false, done: false };
    }
    progress.categories[cat].active = true;
    progress.categories[cat].done = false;
    writeProgress();

    const result = await processCategory(cat);
    totalImages += result.images;
    totalPrices += result.prices;
    totalFiltered += result.filtered;

    progress.categories[cat].active = false;
    progress.categories[cat].done = true;
    writeProgress();

    if (ci < categories.length - 1) {
      log(`Cooling down ${DELAY_BETWEEN_CATEGORIES / 1000}s...`);
      await new Promise(r => setTimeout(r, DELAY_BETWEEN_CATEGORIES));
    }
  }

  progress.status = 'complete';
  writeProgress();

  const elapsed = Math.round((Date.now() - startTime) / 60);
  log('\n' + '='.repeat(60));
  log(`MASTER COMBINED COMPLETE - ${elapsed} minutes`);
  log(`Images: ${totalImages} | Prices: ${totalPrices} | Discontinued: ${totalFiltered}`);
  log('='.repeat(60));

  const summary = [
    '=== MASTER COMBINED SUMMARY ===',
    `Duration: ${elapsed} minutes`,
    `Images downloaded: ${totalImages}`,
    `Prices scraped: ${totalPrices}`,
    `Discontinued removed: ${totalFiltered}`,
    `Completed: ${new Date().toISOString()}`,
  ];
  fs.writeFileSync(path.join(__dirname, 'master-combined-summary.txt'), summary.join('\n'));
  console.log('\n' + summary.join('\n'));

  await new Promise(r => setTimeout(r, 10000));
  stopDashboard();
}

main().catch(err => {
  progress.status = 'error';
  progress.error = err.message;
  writeProgress();
  log(`Fatal: ${err.message}\n${err.stack}`);
  stopDashboard();
  process.exit(1);
});
