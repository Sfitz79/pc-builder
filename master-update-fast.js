/**
 * MASTER UPDATE FAST - Product Data Scraper with Image Search & Live Dashboard
 *
 * Default mode:
 *   1. Filters discontinued items from CSVs
 *   2. Downloads genuine product images for items that need them
 *      (up to MAX_IMAGES_PER_CATEGORY per run, saves progress)
 *   3. Opens live progress dashboard in browser
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { execSync } from 'child_process';
import http from 'http';
import sharp from 'sharp';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.join(__dirname, 'src', 'data');
const THUMBNAILS_DIR = path.join(__dirname, 'public', 'thumbnails');
const LOG_FILE = path.join(__dirname, 'master-update-fast.log');
const PROGRESS_JSON = path.join(__dirname, 'master-update-progress.json');
const DASHBOARD_HTML = path.join(__dirname, 'master-update-dashboard.html');

const PCPP_CSV_DIR = path.join(__dirname, 'scraped_data');
const MODERN_SOCKETS = new Set(['AM4', 'AM5', 'LGA1700', 'LGA1851']);
const BATCH_SIZE = 500; // Items per category per pass

// PCPartPicker CSV import mapping: { file: string, category: string }[]
const PCPP_CSV_MAP = []; // populate via CLI or config file

const RETAILERS = {
  'scan.co.uk': (q) => `https://www.scan.co.uk/search#q=${encodeURIComponent(q)}`,
  'overclockers.co.uk': (q) => `https://www.overclockers.co.uk/search?search=${encodeURIComponent(q)}`,
  'box.co.uk': (q) => `https://www.box.co.uk/search?q=${encodeURIComponent(q)}`,
  'ebuyer.com': (q) => `https://www.ebuyer.com/search?q=${encodeURIComponent(q)}`,
  'amazon.co.uk': (q) => `https://www.amazon.co.uk/s?k=${encodeURIComponent(q)}`,
  'cclonline.com': (q) => `https://www.cclonline.com/catalogsearch/result/?q=${encodeURIComponent(q)}`,
};

const CONFIG = {
  TIMEOUT: 10000,
  IMAGE_TIMEOUT: 15000,
  MIN_IMAGE_WIDTH: 200,
  MIN_IMAGE_HEIGHT: 200,
};

if (!fs.existsSync(THUMBNAILS_DIR)) fs.mkdirSync(THUMBNAILS_DIR, { recursive: true });

const startTime = Date.now();

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

// ============ HELPERS ============

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

// ============ MODERN COMPONENT FILTER ============

function isModernComponent(categoryId, item) {
  if (!item) return true;
  switch (categoryId) {
    case 'cpu': {
      const name = normalizeToken(item.name);
      const microarch = normalizeToken(item.microarchitecture);

      if (name.includes('RYZEN')) {
        if (microarch && (microarch.includes('ZEN 2') || microarch.includes('ZEN+') || microarch === 'ZEN')) return false;
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

      if (socket === 'AM4') {
        const chipset = normalizeToken(item.chipset);
        const name = normalizeToken(item.name);
        const searchStr = chipset || name;
        if (/[ABX]3\d{2}/.test(searchStr)) return false;
      }

      return true;
    }
    case 'ram': {
      const ramType = normalizeToken(item.ram_type || item.type);
      if (ramType && !['DDR4', 'DDR5'].includes(ramType)) return false;

      if (ramType === 'DDR4') {
        const speed = parseFloat(item.speed);
        if (speed > 0 && speed <= 3000) return false;
      }

      return true;
    }
    case 'psu': { const wattage = parseFloat(item.wattage); if (wattage > 0 && wattage < 450) return false; return true; }
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

// ============ LOGGING & PROGRESS ============

function log(msg) {
  const ts = new Date().toISOString();
  console.log(`[${ts}] ${msg}`);
  try { fs.appendFileSync(LOG_FILE, `[${ts}] ${msg}\n`); } catch {}
}

const progressState = {
  categories: {},
  currentCategory: '',
  currentItem: 0,
  totalItems: 0,
  overallProcessed: 0,
  overallTotal: 0,
  imagesFound: 0,
  imagesSkipped: 0,
  imagesFailed: 0,
  filteredRemoved: 0,
  startTime: startTime,
  currentPass: 1,
  totalRemaining: 0,
  status: 'initialising',
};

function writeProgress() {
  try {
    progressState.elapsed = (Date.now() - startTime) / 1000;
    progressState.elapsedFormatted = `${Math.floor(progressState.elapsed / 60)}m ${Math.floor(progressState.elapsed % 60)}s`;
    fs.writeFileSync(PROGRESS_JSON, JSON.stringify(progressState, null, 2));
  } catch {}
}

// ============ DASHBOARD ============

let dashboardServer = null;

function startDashboard() {
  const PORT = 3333;
  generateDashboardHTML();
  dashboardServer = http.createServer((req, res) => {
    if (req.url === '/progress') {
      try {
        res.writeHead(200, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' });
        res.end(fs.readFileSync(PROGRESS_JSON, 'utf-8'));
      } catch { res.end(JSON.stringify(progressState)); }
    } else {
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end(fs.readFileSync(DASHBOARD_HTML, 'utf-8'));
    }
  });
  dashboardServer.listen(PORT, () => {
    const url = `http://localhost:${PORT}`;
    log(`Dashboard: ${url}`);
    if (process.env.ELECTRON_RUN !== '1') {
      try { execSync(`start ${url}`, { shell: 'cmd.exe', timeout: 3000 }); } catch {}
    } else {
      log('Electron window will load dashboard instead of opening browser');
    }
  });
}

function stopDashboard() { if (dashboardServer) { dashboardServer.close(); dashboardServer = null; } }

function generateDashboardHTML() {
  const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Master Update - Progress</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: 'Segoe UI', system-ui, sans-serif; background: #0d1117; color: #c9d1d9; padding: 24px; }
  h1 { font-size: 24px; color: #58a6ff; margin-bottom: 8px; }
  .subtitle { color: #8b949e; font-size: 14px; margin-bottom: 24px; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; }
  .card { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 20px; }
  .card h3 { font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: #8b949e; margin-bottom: 12px; }
  .stat-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #21262d; }
  .stat-row:last-child { border-bottom: none; }
  .stat-label { color: #8b949e; }
  .stat-value { font-weight: 600; }
  .stat-value.green { color: #3fb950; } .stat-value.red { color: #f85149; }
  .stat-value.blue { color: #58a6ff; } .stat-value.yellow { color: #d29922; }
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
   <h1>&#9881; Master Update Fast</h1>
  <div class="subtitle" id="status">Connecting...</div>
  <div class="grid">
    <div class="card">
      <h3>Overall Progress</h3>
      <div class="stat-row"><span class="stat-label">Status</span><span class="stat-value blue" id="overallStatus">-</span></div>
      <div class="stat-row"><span class="stat-label">Pass</span><span class="stat-value blue" id="currentPass">1</span></div>
      <div class="stat-row"><span class="stat-label">Remaining</span><span class="stat-value yellow" id="totalRemaining">0</span></div>
      <div class="stat-row"><span class="stat-label">Elapsed</span><span class="stat-value" id="elapsed">-</span></div>
      <div class="bar-container"><div class="bar" id="overallBar" style="width:0%">0%</div></div>
      <div class="stat-row"><span class="stat-label">Items</span><span class="stat-value" id="itemsProgress">-</span></div>
    </div>
    <div class="card">
      <h3>Images</h3>
      <div class="stat-row"><span class="stat-label">Downloaded</span><span class="stat-value green" id="imagesFound">0</span></div>
      <div class="stat-row"><span class="stat-label">Skipped (had)</span><span class="stat-value blue" id="imagesSkipped">0</span></div>
      <div class="stat-row"><span class="stat-label">Failed</span><span class="stat-value red" id="imagesFailed">0</span></div>
      <div class="stat-row"><span class="stat-label">Discontinued Removed</span><span class="stat-value yellow" id="filteredRemoved">0</span></div>
    </div>
  </div>
  <div class="card">
    <h3>Categories</h3>
    <table>
      <thead><tr><th>Category</th><th>Status</th><th>Progress</th><th class="text-right">Images</th><th class="text-right">Items</th></tr></thead>
      <tbody id="catTable"></tbody>
    </table>
  </div>
  <div class="subtitle mt-24" id="currentAction">-</div>
  <script>
    async function poll() {
      try {
        const r = await fetch('/progress'); const d = await r.json();
        const total = d.overallTotal || 1; const pct = Math.round((d.overallProcessed / total) * 100);
        document.getElementById('overallStatus').textContent = d.status || '-';
        document.getElementById('currentPass').textContent = 'Pass ' + (d.currentPass || 1);
        document.getElementById('totalRemaining').textContent = (d.totalRemaining || 0) + ' items';
        document.getElementById('elapsed').textContent = d.elapsedFormatted || '-';
        document.getElementById('itemsProgress').textContent = d.overallProcessed + ' / ' + total + ' (' + pct + '%)';
        document.getElementById('overallBar').style.width = pct + '%';
        document.getElementById('overallBar').textContent = pct + '%';
        document.getElementById('imagesFound').textContent = d.imagesFound || 0;
        document.getElementById('imagesSkipped').textContent = d.imagesSkipped || 0;
        document.getElementById('imagesFailed').textContent = d.imagesFailed || 0;
        document.getElementById('filteredRemoved').textContent = d.filteredRemoved || 0;
        document.getElementById('currentAction').textContent = d.currentCategory ? '> ' + d.currentCategory + ': ' + (d.currentItem || 0) + '/' + (d.totalItems || 0) : '-';
        const cats = d.categories || {}; const keys = Object.keys(cats);
        let html = '';
        for (const key of keys) {
          const c = cats[key]; const cpct = c.total > 0 ? Math.round((c.processed / c.total) * 100) : 0;
          const badge = c.done ? '<span class="badge done">DONE</span>' : (c.active ? '<span class="badge active">ACTIVE</span>' : '<span class="badge pending">PENDING</span>');
          html += '<tr><td class="cat-name">' + key + '</td><td>' + badge + '</td><td><div class="mini-bar"><div class="mini-fill" style="width:' + cpct + '%">&nbsp;</div></div></td><td class="text-right">' + (c.images || 0) + '</td><td class="text-right">' + (c.processed || 0) + '/' + (c.total || 0) + '</td></tr>';
        }
        document.getElementById('catTable').innerHTML = html || '<tr><td colspan="5" style="text-align:center;color:#8b949e;">Waiting...</td></tr>';
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

// ============ NETWORK HELPERS ============

async function fetchWithTimeout(url, timeout = CONFIG.TIMEOUT) {
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
  // Reject explicit thumbnail sizing in URL params (sizes 1-99px)
  if (/[?&](width|w|h|height|s|size)=[0-9]{1,2}([^0-9]|$)/i.test(url)) return false;
  // Reject URLs with common thumbnail path segments
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
    const id = setTimeout(() => controller.abort(), CONFIG.IMAGE_TIMEOUT);
    const resp = await fetch(url, { signal: controller.signal, headers: { 'User-Agent': 'Mozilla/5.0', 'Accept': 'image/webp,image/apng,image/*,*/*' }, redirect: 'follow' });
    clearTimeout(id);
    if (!resp.ok) return false;
    const buffer = Buffer.from(await resp.arrayBuffer());
    if (buffer.length < 4096) return false;
    // Check actual image dimensions — reject tiny thumbnails
    try {
      const meta = await sharp(buffer).metadata();
      if (meta.width < CONFIG.MIN_IMAGE_WIDTH || meta.height < CONFIG.MIN_IMAGE_HEIGHT) {
        return false;
      }
    } catch {
      return false;
    }
    fs.writeFileSync(dest, buffer);
    return true;
  } catch { return false; }
}

// ============ IMAGE SCRAPING ============

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

          // Highest priority: zoom / large / hi-res image attributes
          const zoomImgs = [...pageHtml.matchAll(/(?:data-zoom-image|data-large-image|data-zoom|data-big|data-fullsize)=["'](https?:\/\/[^"']+\.(?:jpg|jpeg|png|webp|avif)[^"']*)["']/gi)];
          for (const z of zoomImgs) { if (allUrls.size >= 10) break; const u = z[1]; if (u && u.length > 80 && isRealProductImage(u, category)) allUrls.set(u, (allUrls.get(u) || 0) + 3); }

          // OG image (social share — usually full product image)
          const og = pageHtml.match(/<meta[^>]*property=["']og:image["'][^>]*content=["']([^"']+)["'][^>]*>/i);
          if (og && og[1] && og[1].length > 80 && isRealProductImage(og[1], category)) allUrls.set(og[1], (allUrls.get(og[1]) || 0) + 2);

          // JSON-LD structured data with product image
          const jd = [...pageHtml.matchAll(/<script[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi)];
          for (const j of jd) {
            try {
              const parsed = JSON.parse(j[1]);
              const images = parsed.image || (parsed.mainEntity && parsed.mainEntity.image) || [];
              const urls = Array.isArray(images) ? images : [images];
              for (const iu of urls) { if (typeof iu === 'string' && iu.length > 80 && isRealProductImage(iu, category) && allUrls.size < 10) allUrls.set(iu, (allUrls.get(iu) || 0) + 2); }
            } catch {}
          }

          // General image tags
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
    `https://r.jina.ai/https://www.google.com/search?tbm=isch&q=${encodeURIComponent(searchName + ' ' + (category || ''))}`,
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

  // Direct DuckDuckGo image search (no proxy) as additional fallback
  if (allUrls.size < 3) {
    try {
      const ddgUrl = `https://html.duckduckgo.com/html/?q=${encodeURIComponent(searchName + ' ' + (category || '') + ' product image')}`;
      const resp = await fetchWithTimeout(ddgUrl, 10000);
      if (resp.ok) {
        const html = await resp.text();
        const imgUrls = [...html.matchAll(/<img[^>]+src="(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/gi)];
        for (const m of imgUrls) {
          const u = m[1].split('?')[0];
          if (u.length > 50 && !/pixel|tracking|logo|icon|duckduckgo/i.test(u) && allUrls.size < 5) allUrls.add(u);
        }
      }
    } catch {}
  }

  return Array.from(allUrls);
}

async function downloadImageForItem(name, category, lineIdx, lines, imageIdx, existingUrl) {
  const sanitized = sanitizeFilename(name);

  // Try existing URL from CSV first (pcpartpicker CDN, Amazon, etc.)
  if (existingUrl && /^https?:\/\//i.test(existingUrl)) {
    const ext = (existingUrl.match(/\.(jpg|jpeg|png|webp|avif)/i) || [])[0] || '.jpg';
    const local = `${category}_${sanitized}${ext}`;
    const dest = path.join(THUMBNAILS_DIR, local);
    log(`  Trying existing URL: ${existingUrl.substring(0, 80)}`);
    if (await verifyAndDownload(existingUrl, dest)) {
      updateCSVLine(lines, lineIdx, imageIdx, `thumbnails/${local}`);
      return true;
    }
    log(`  Existing URL failed, falling back...`);
  }

  // Try retailers first
  const retailerUrls = await scrapeRetailerImages(name, category);
  for (const url of retailerUrls) {
    const ext = (url.match(/\.(jpg|jpeg|png|webp|avif)/i) || [])[0] || '.jpg';
    const local = `${category}_${sanitized}${ext}`;
    const dest = path.join(THUMBNAILS_DIR, local);
    if (await verifyAndDownload(url, dest)) {
      updateCSVLine(lines, lineIdx, imageIdx, `thumbnails/${local}`);
      return true;
    }
  }

  // Fallback: web search
  const webUrls = await searchWebImages(name, category);
  for (const url of webUrls) {
    const ext = (url.match(/\.(jpg|jpeg|png|webp)/i) || [])[0] || '.jpg';
    const local = `${category}_${sanitized}${ext}`;
    const dest = path.join(THUMBNAILS_DIR, local);
    if (await verifyAndDownload(url, dest)) {
      updateCSVLine(lines, lineIdx, imageIdx, `thumbnails/${local}`);
      return true;
    }
  }
  return false;
}

function updateCSVLine(lines, lineIdx, colIdx, value) {
  const parts = parseCSVLine(lines[lineIdx]);
  while (parts.length <= colIdx) parts.push('');
  parts[colIdx] = value;
  lines[lineIdx] = parts.join(',');
}

function getImageColumnIndex(header, lines) {
  let idx = header.indexOf('image');
  if (idx === -1) {
    idx = header.length;
    header.push('image');
    lines[0] = header.join(',');
    for (let i = 1; i < lines.length; i++) {
      const line = lines[i].trim();
      if (line) lines[i] = line + ',';
    }
  }
  return idx;
}

// ============ CATEGORY PROCESSING ============

async function processCategory(filename) {
  const filePath = path.join(DATA_DIR, filename);
  const category = filename.replace('.csv', '');
  progressState.currentCategory = category;

  if (!fs.existsSync(filePath)) { log(`Skipping ${filename}`); return { total: 0, filtered: 0, images: 0, skipped: 0, failed: 0, remaining: 0 }; }

  log(`\n${'='.repeat(60)}\nProcessing ${filename}\n${'='.repeat(60)}`);

  const content = fs.readFileSync(filePath, 'utf-8');
  let lines = content.split(/\r?\n/).filter(l => l.trim());
  if (lines.length <= 1) return { total: 0, filtered: 0, images: 0, skipped: 0, failed: 0, remaining: 0 };

  const header = lines[0].split(',').map(h => h.trim());
  const imageIdx = getImageColumnIndex(header, lines);

  // --- Step 1: Filter discontinued items ---
  const validLineIndices = new Set();
  const discontinuedIndices = [];
  let modernCount = 0;

  for (let i = 1; i < lines.length; i++) {
    const line = lines[i].trim();
    if (!line) continue;
    const parts = parseCSVLine(line);
    const name = parts[0]?.replace(/^"|"$/g, '') || '';
    if (!name) continue;
    const itemObj = {};
    header.forEach((h, idx) => { itemObj[h.trim()] = (parts[idx] || '').replace(/^"|"$/g, '').trim(); });
    if (isModernComponent(category, itemObj)) { validLineIndices.add(i); modernCount++; }
    else { discontinuedIndices.push(i); }
  }

  progressState.filteredRemoved += discontinuedIndices.length;
  log(`Modern: ${modernCount}, Discontinued: ${discontinuedIndices.length}`);

  if (discontinuedIndices.length > 0) {
    const newLines = [lines[0]];
    for (let i = 1; i < lines.length; i++) { if (validLineIndices.has(i)) newLines.push(lines[i]); }
    fs.writeFileSync(filePath, newLines.join('\n'), 'utf-8');
    log(`Removed ${discontinuedIndices.length} discontinued`);
    lines = newLines;
  }

  // --- Step 2: Find items that need images ---
  const needsImage = [];
  let skippedCount = 0;

  for (let i = 1; i < lines.length; i++) {
    const line = lines[i].trim();
    if (!line) continue;
    const parts = parseCSVLine(line);
    const name = parts[0]?.replace(/^"|"$/g, '') || '';
    if (!name) continue;

    const currentImage = (parts[imageIdx] || '').trim();
    if (currentImage) {
      if (currentImage.startsWith('thumbnails/')) {
        const imgPath = path.join(THUMBNAILS_DIR, currentImage.replace('thumbnails/', ''));
        if (fs.existsSync(imgPath)) { skippedCount++; continue; }
      }
      // Has URL but no local file -> try to download
    }
    needsImage.push({ i, name, existingUrl: currentImage });
  }

  // Limit to BATCH_SIZE per pass
  const batch = needsImage.slice(0, BATCH_SIZE);
  const batchRemaining = Math.max(0, needsImage.length - BATCH_SIZE);
  const total = batch.length;

  progressState.totalItems = batchRemaining > 0 ? BATCH_SIZE : total;
  progressState.currentItem = 0;

  log(`${total} to process (${skippedCount} skipped, ${batchRemaining} remaining for next pass)`);

  if (total === 0) {
    progressState.imagesSkipped += skippedCount;
    writeProgress();
    return { total: lines.length - 1, filtered: discontinuedIndices.length, images: 0, skipped: skippedCount, failed: 0, remaining: 0 };
  }

  let imagesFound = 0, imagesFailed = 0;

  for (let j = 0; j < batch.length; j++) {
    const item = batch[j];
    progressState.currentItem = j + 1;
    progressState.overallProcessed++;

    process.stdout.write(`\r  [${j + 1}/${total}] ${item.name.substring(0, 45)}...`);

    if (await downloadImageForItem(item.name, category, item.i, lines, imageIdx, item.existingUrl)) {
      imagesFound++; progressState.imagesFound++;
    } else {
      imagesFailed++; progressState.imagesFailed++;
    }

    progressState.imagesSkipped = skippedCount;
    progressState.status = 'running';
    writeProgress();
  }

  process.stdout.write('\n');

  fs.writeFileSync(filePath, lines.join('\n'), 'utf-8');
  // Remaining = items beyond batch + items that failed (need retry)
  const totalRemaining = batchRemaining + imagesFailed;
  log(`Done: ${imagesFound} found, ${imagesFailed} failed, ${skippedCount} skipped, ${batchRemaining} queued, ${totalRemaining} total remaining after this pass`);

  return { total: lines.length - 1, filtered: discontinuedIndices.length, images: imagesFound, skipped: skippedCount, failed: imagesFailed, remaining: totalRemaining };
}

// ============ MAIN ============

function updateSummary(results, passCount) {
  const totalFiltered = Object.values(results).reduce((sum, r) => sum + (r.filtered || 0), 0);
  const totalImages = Object.values(results).reduce((sum, r) => sum + (r.images || 0), 0);
  const totalSkipped = Object.values(results).reduce((sum, r) => sum + (r.skipped || 0), 0);
  const totalFailed = Object.values(results).reduce((sum, r) => sum + (r.failed || 0), 0);
  const totalRemaining = Object.values(results).reduce((sum, r) => sum + (r.remaining || 0), 0);

  const lines = [
    '=== MASTER UPDATE FAST SUMMARY ===',
    `Passes completed: ${passCount}`,
    `Duration: ${Math.round((Date.now() - startTime) / 60)} minutes`,
    `Discontinued removed: ${totalFiltered}`,
    `Images downloaded: ${totalImages}`,
    `Images skipped (had): ${totalSkipped}`,
    `Images failed (exhausted): ${totalFailed}`,
    `Images remaining: ${totalRemaining} (no more sources found)`,
    '',
    'Category breakdown:',
  ];

  for (const [file, r] of Object.entries(results)) {
    const kept = Math.max(0, r.total - (r.filtered || 0));
    lines.push(`  ${file}: ${kept} items, +${r.images || 0} images (${r.skipped || 0} skipped, ${r.failed || 0} failed, ${r.remaining || 0} remaining)`);
  }

  lines.push('', `Completed: ${new Date().toISOString()}`);
  fs.writeFileSync(path.join(__dirname, 'master-update-summary.txt'), lines.join('\n'));
  log('\n' + lines.join('\n'));
  console.log('\n' + lines.join('\n'));
}

async function importPcppData() {
  if (!fs.existsSync(PCPP_CSV_DIR)) {
    log(`PCPartPicker data dir not found: ${PCPP_CSV_DIR}`);
    log('Create scraped_data/ and place PCPartPicker CSVs there, or run without --pcpp');
    return;
  }
  const csvFiles = fs.readdirSync(PCPP_CSV_DIR).filter(f => f.endsWith('.csv')).sort();
  if (csvFiles.length === 0) { log('No CSV files found in scraped_data/'); return; }

  log(`\n${'='.repeat(60)}\nImporting ${csvFiles.length} PCPartPicker CSV files\n${'='.repeat(60)}`);

  for (const csvFile of csvFiles) {
    const filePath = path.join(PCPP_CSV_DIR, csvFile);
    const content = fs.readFileSync(filePath, 'utf-8');
    const lines = content.split('\n').filter(Boolean);
    if (lines.length < 2) continue;
    const headers = lines[0].split(',').map(h => h.trim().replace(/^"|"$/g, ''));
    const nameIdx = headers.indexOf('name');
    const imageIdx = headers.indexOf('image') !== -1 ? headers.indexOf('image') : headers.length;
    if (nameIdx === -1 && imageIdx === -1) { log(`  Skipping ${csvFile}: no name/image columns`); continue; }

    // Append images from PCPP CSVs into the matching src/data CSV
    const category = csvFile.replace(/\.csv$/, '').replace(/^.*?[_-]/, '');
    const catFile = path.join(DATA_DIR, `${category}.csv`);
    if (!fs.existsSync(catFile)) { log(`  No matching data file for ${csvFile} -> ${category}.csv`); continue; }

    const catContent = fs.readFileSync(catFile, 'utf-8');
    let catLines = catContent.split('\n').filter(Boolean);
    const catHeaders = catLines[0].split(',').map(h => h.trim());
    let catImageIdx = catHeaders.indexOf('image');
    if (catImageIdx === -1) { catHeaders.push('image'); catImageIdx = catHeaders.length - 1; catLines[0] = catHeaders.join(','); }

    let matched = 0;
    for (let i = 1; i < lines.length; i++) {
      const parts = lines[i].split(',').map(p => p.trim().replace(/^"|"$/g, ''));
      const pcppName = (parts[nameIdx] || '').toLowerCase();
      if (!pcppName) continue;
      const pcppImage = imageIdx < headers.length ? (parts[imageIdx] || '') : '';

      // Find matching item in category CSV by name substring
      for (let j = 1; j < catLines.length; j++) {
        const catParts = catLines[j].split(',');
        const catName = (catParts[0] || '').replace(/^"|"$/g, '').toLowerCase();
        if (!catName) continue;
        if (catName.includes(pcppName) || pcppName.includes(catName)) {
          const existing = (catParts[catImageIdx] || '').trim();
          if (pcppImage && !existing) {
            while (catParts.length <= catImageIdx) catParts.push('');
            catParts[catImageIdx] = pcppImage;
            catLines[j] = catParts.join(',');
            matched++;
          }
          break;
        }
      }
    }
    fs.writeFileSync(catFile, catLines.join('\n'), 'utf-8');
    log(`  ${csvFile}: matched ${matched} items`);
  }
  log('PCPartPicker import complete\n');
}

async function main() {
  const args = process.argv.slice(2);

  log('\n' + '='.repeat(60));
  log('MASTER UPDATE FAST - Starting (multi-pass, batch size = 500)');
  log('='.repeat(60));

  if (args.includes('--pcpp')) {
    log('PCPartPicker import mode enabled');
    await importPcppData();
  }

  if (!args.includes('--no-dashboard')) {
    startDashboard();
  } else {
    log('Dashboard disabled (--no-dashboard)');
  }
  
  writeProgress();

  let categories = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv')).sort();
  
  const categoryArg = args.find(a => a.startsWith('--category='));
  if (categoryArg) {
    const targetCat = categoryArg.split('=')[1];
    categories = categories.filter(c => c === targetCat || c === `${targetCat}.csv`);
    log(`Targeting category: ${targetCat}`);
  }
  const runningTotals = {};

  progressState.overallTotal = categories.length;
  progressState.status = 'running';
  writeProgress();

  let pass = 1;
  let anyRemaining = true;

  while (anyRemaining) {
    anyRemaining = false;
    progressState.currentPass = pass;
    progressState.totalRemaining = 0;
    log(`\n${'='.repeat(60)}\n=== PASS ${pass} ===\n${'='.repeat(60)}`);

    for (let ci = 0; ci < categories.length; ci++) {
      const cat = categories[ci];

      if (!progressState.categories[cat]) {
        progressState.categories[cat] = { processed: 0, total: 0, images: 0, active: false, done: false, remaining: 0 };
      }
      progressState.categories[cat].active = true;
      progressState.categories[cat].done = false;
      progressState.overallProcessed = ci;
      writeProgress();

      const result = await processCategory(cat);

      // Accumulate into running totals
      if (!runningTotals[cat]) runningTotals[cat] = { total: 0, filtered: 0, images: 0, skipped: 0, failed: 0, remaining: 0 };
      runningTotals[cat].total = result.total;
      runningTotals[cat].filtered = (runningTotals[cat].filtered || 0) + (result.filtered || 0);
      runningTotals[cat].images = (runningTotals[cat].images || 0) + (result.images || 0);
      runningTotals[cat].skipped = (runningTotals[cat].skipped || 0) + (result.skipped || 0);
      runningTotals[cat].failed = (runningTotals[cat].failed || 0) + (result.failed || 0);
      runningTotals[cat].remaining = result.remaining;

      const catDone = result.remaining === 0;
      progressState.categories[cat] = {
        processed: result.total || 0,
        total: result.total || 0,
        images: runningTotals[cat].images || 0,
        active: false,
        done: catDone,
        remaining: result.remaining || 0,
      };
      if (result.remaining > 0) {
        anyRemaining = true;
        progressState.totalRemaining += result.remaining;
      }
      writeProgress();
    }

    progressState.overallProcessed = categories.length;
    writeProgress();

    if (anyRemaining) {
      log(`\n--- Pass ${pass} complete — ${progressState.totalRemaining} items still need images, starting next pass ---`);
      pass++;
      // Brief pause so dashboard can refresh before next pass
      await new Promise(r => setTimeout(r, 3000));
    }
  }

  progressState.status = 'complete';
  writeProgress();

  updateSummary(runningTotals, pass);

  log('\n' + '='.repeat(60));
  log('MASTER UPDATE FAST COMPLETE - All possible images sourced');
  log('='.repeat(60));

  if (!args.includes('--no-dashboard')) {
    await new Promise(r => setTimeout(r, 30000));
    stopDashboard();
  }
}

main().catch(err => {
  progressState.status = 'error';
  progressState.error = err.message;
  writeProgress();
  log(`Fatal: ${err.message}\n${err.stack}`);
  if (typeof dashboardServer !== 'undefined' && dashboardServer) {
    stopDashboard();
  }
  process.exit(1);
});
