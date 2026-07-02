/**
 * UPDATE ALL — Unified PC Builder Data Orchestrator
 * 
 * Single entry point for all data update tasks:
 *   1. Audit CSVs + thumbnails
 *   2. Fix broken image paths
 *   3. Filter discontinued items
 *   4. Download product images (retailer scrapers + web search)
 *   5. Import docyx dataset + PCPP CDN images
 *   6. Resolve brand logos (VectorLogoZone → Wikimedia → Clearbit → Favicons)
 *   7. Import PCPartPicker CSVs (--pcpp)
 *   8. Generate AI data status report
 * 
 * Usage:  node update-all.mjs [--flags]
 * Flags:  --quick         audit + brand logos only (skip heavy image scraping)
 *         --brand-logos   resolve brand logos only
 *         --images        download product images only
 *         --docyx         download docyx dataset only
 *         --pcpp          import PCPartPicker CSVs from scraped_data/
 *         --fix-paths     fix broken image path references
 *         --dashboard     show live progress dashboard
 *         --category=X    target specific category
 *         --no-scrape     skip retailer scraping (web search only)
 */

import fs from 'fs';
import path from 'path';
import http from 'http';
import { execSync } from 'child_process';
import { createHash } from 'node:crypto';
import sharp from 'sharp';

const ROOT = path.resolve(import.meta.dirname, '.');
const DATA_DIR = path.join(ROOT, 'src', 'data');
const THUMB_DIR = path.join(ROOT, 'public', 'thumbnails');
const LOGO_DIR = path.join(ROOT, 'public', 'brand-logos');
const LOG_FILE = path.join(ROOT, 'update-all.log');
const PROGRESS_JSON = path.join(ROOT, 'update-all-progress.json');
const DASHBOARD_HTML = path.join(ROOT, 'update-all-dashboard.html');
const AI_STATUS_FILE = path.join(ROOT, 'public', 'data-status.json');
const DOCYX_CACHE = path.join(ROOT, '.docyx-cache');

const CONCURRENCY = 5;
const BATCH_SIZE = 100;
const TIMEOUT = 15000;
const startTime = Date.now();

const MODERN_SOCKETS = new Set(['AM4', 'AM5', 'LGA1700', 'LGA1851']);

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

const RETAILERS = [
  ['amazon.co.uk', (q) => `https://www.amazon.co.uk/s?k=${encodeURIComponent(q)}`],
  ['ebuyer.com', (q) => `https://www.ebuyer.com/search?q=${encodeURIComponent(q)}`],
  ['box.co.uk', (q) => `https://www.box.co.uk/search?q=${encodeURIComponent(q)}`],
  ['scan.co.uk', (q) => `https://www.scan.co.uk/search#q=${encodeURIComponent(q)}`],
  ['cclonline.com', (q) => `https://www.cclonline.com/catalogsearch/result/?q=${encodeURIComponent(q)}`],
  ['overclockers.co.uk', (q) => `https://www.overclockers.co.uk/search?search=${encodeURIComponent(q)}`],
];

const EXCLUDE_PATTERNS = [
  /pixel|tracking|analytics|logo|icon|placeholder|banner|sponsor/i,
  /badge|star|rating|review|avatar|profile|colour_swatch/i,
  /checkout|captcha|transparent|empty|no_image|not_found|coming_soon/i,
  /data:image|svg\+xml|\.svg/i,
  /wishlist|compare|cart_badge|newsletter/i,
  /social|facebook|twitter|instagram|linkedin|youtube|pinterest/i,
  /favicon|apple-touch|manifest|mask-icon|loader|spinner|loading|ajax/i,
  /thumb|thumbnail|_tn|\/small\/|\/tiny\/|\/mini\//i,
  /50x50|75x75|100x100|150x150|200x200|badge_/i,
];

/* ── Helpers ── */

function sanitize(name) { return name.replace(/[^a-z0-9]/gi, '_').toLowerCase().substring(0, 50); }

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

function log(msg) {
  const ts = new Date().toISOString().replace('T', ' ').substring(0, 19);
  const line = `[${ts}] ${msg}`;
  console.log(line);
  try { fs.appendFileSync(LOG_FILE, line + '\n'); } catch {}
}

function readCSV(filename) {
  const fp = path.join(DATA_DIR, filename);
  if (!fs.existsSync(fp)) return null;
  const content = fs.readFileSync(fp, 'utf-8');
  const lines = content.split(/\r?\n/).filter(l => l.trim());
  if (lines.length < 2) return null;
  return { header: parseCSVLine(lines[0]), lines, filePath: fp };
}

function writeCSV(fp, lines) { fs.writeFileSync(fp, lines.join('\n'), 'utf-8'); }

async function fetchWithTimeout(url, timeout = TIMEOUT) {
  const ctrl = new AbortController();
  const id = setTimeout(() => ctrl.abort(), timeout);
  try {
    const res = await fetch(url, { signal: ctrl.signal, headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) PCTG-Update-All/1.0' } });
    clearTimeout(id); return res;
  } catch { clearTimeout(id); throw 'timeout'; }
}

async function downloadFile(url, dest, minSize = 1024) {
  try {
    const resp = await fetch(url, {
      signal: AbortSignal.timeout(TIMEOUT),
      headers: { 'User-Agent': 'Mozilla/5.0 PCTG-Update-All/1.0', 'Accept': 'image/webp,image/apng,image/*,*/*' },
      redirect: 'follow',
    });
    if (!resp.ok) return false;
    const ct = resp.headers.get('content-type') || '';
    if (!ct.startsWith('image/')) return false;
    const buf = Buffer.from(await resp.arrayBuffer());
    if (buf.length < minSize) return false;
    fs.writeFileSync(dest, buf);
    return true;
  } catch { return false; }
}

const seenImageHashes = new Set();

async function verifyAndDownload(url, dest) {
  try {
    const resp = await fetch(url, {
      method: 'HEAD',
      signal: AbortSignal.timeout(8000),
      headers: { 'User-Agent': 'Mozilla/5.0' },
      redirect: 'follow',
    });
    if (!resp.ok) return false;
    const ct = resp.headers.get('content-type') || '';
    const cl = parseInt(resp.headers.get('content-length') || '0');
    if (!ct.startsWith('image/') || (cl > 0 && cl < 2048)) return false;
  } catch { return false; }

  try {
    const resp = await fetch(url, {
      signal: AbortSignal.timeout(TIMEOUT),
      headers: { 'User-Agent': 'Mozilla/5.0', 'Accept': 'image/webp,image/apng,image/*,*/*' },
      redirect: 'follow',
    });
    if (!resp.ok) return false;
    const buf = Buffer.from(await resp.arrayBuffer());
    if (buf.length < 4096) return false;
    try {
      const meta = await sharp(buf).metadata();
      if (meta.width < 200 || meta.height < 200) return false;
      const pixels = await sharp(buf).raw().toBuffer();
      const w = meta.width, h = meta.height;
      const channels = meta.channels || 3;
      let sampleCount = 0;
      const rSum = [], gSum = [], bSum = [];
      for (let y = 0; y < h; y += Math.max(1, Math.floor(h / 20))) {
        for (let x = 0; x < w; x += Math.max(1, Math.floor(w / 20))) {
          const idx = (y * w + x) * channels;
          rSum.push(pixels[idx]); gSum.push(pixels[idx + 1]); bSum.push(pixels[idx + 2]);
          sampleCount++;
        }
      }
      const rMean = rSum.reduce((a, b) => a + b, 0) / sampleCount;
      const gMean = gSum.reduce((a, b) => a + b, 0) / sampleCount;
      const bMean = bSum.reduce((a, b) => a + b, 0) / sampleCount;
      const rVar = rSum.reduce((a, b) => a + (b - rMean) ** 2, 0) / sampleCount;
      const gVar = gSum.reduce((a, b) => a + (b - gMean) ** 2, 0) / sampleCount;
      const bVar = bSum.reduce((a, b) => a + (b - bMean) ** 2, 0) / sampleCount;
      const avgVariance = (rVar + gVar + bVar) / 3;
      if (avgVariance < 50) return false;
      if (channels >= 4) {
        let transparentPixels = 0;
        for (let y = 0; y < h; y += Math.max(1, Math.floor(h / 10))) {
          for (let x = 0; x < w; x += Math.max(1, Math.floor(w / 10))) {
            const idx = (y * w + x) * channels;
            if (pixels[idx + 3] < 20) transparentPixels++;
          }
        }
        if (transparentPixels > sampleCount * 0.5) return false;
      }
    } catch { return false; }
    const hash = createHash('sha256').update(buf.slice(0, Math.min(8192, buf.length))).digest('base64');
    if (seenImageHashes.has(hash)) return false;
    seenImageHashes.add(hash);
    fs.writeFileSync(dest, buf);
    return true;
  } catch { return false; }
}

function isRealProductImage(url, category) {
  if (EXCLUDE_PATTERNS.some(p => p.test(url))) return false;
  if (url.length < 50) return false;
  if (!/\.(jpg|jpeg|png|webp|avif)(\?.*)?$/i.test(url)) return false;
  const keywords = CATEGORY_IMAGE_KEYWORDS[category] || [];
  if (keywords.length > 2) {
    const ul = url.toLowerCase();
    if (!keywords.some(k => ul.includes(k.replace(/\s+/g, '')) || ul.includes(k.replace(/\s+/g, '_')))) return false;
  }
  return true;
}

/* ── Progress / Dashboard ── */

const PHASE_MAP = {
  'Audit': 0,
  'Fix Paths': 1,
  'Filter Discontinued': 2,
  'PCPP + docyx CDN': 3,
  'docyx Dataset': 3,
  'Product Images': 4,
  'Link Thumbnails': 5,
  'Brand Logos': 6,
  'AI Status': 7,
};

const progress = {
  phases: {}, currentPhase: '', currentPhaseNumber: 0, status: 'initialising', startTime,
  overallProcessed: 0, overallTotal: 0, imagesFound: 0, imagesSkipped: 0, imagesFailed: 0, filteredRemoved: 0, elapsed: 0,
  currentItem: '', currentCategory: '', batchDone: 0, batchTotal: 0, latestThumbnail: '',
};

function writeProgress() {
  progress.elapsed = (Date.now() - startTime) / 1000;
  try { fs.writeFileSync(PROGRESS_JSON, JSON.stringify(progress, null, 2)); } catch {}
}

let dashboardServer = null;

function startDashboard() {
  const PORT = 3334;
  const html = `<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Update All</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;background:#0d1117;color:#c9d1d9;padding:24px}
h1{font-size:24px;color:#58a6ff}
.subtitle{color:#8b949e;font-size:14px;margin:8px 0 24px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px}
.card{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:20px}
.card h3{font-size:13px;text-transform:uppercase;color:#8b949e;margin-bottom:12px}
.stat-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #21262d}
.stat-row:last-child{border-bottom:none}.stat-label{color:#8b949e}.stat-value{font-weight:600}
.green{color:#3fb950}.red{color:#f85149}.blue{color:#58a6ff}.yellow{color:#d29922}
.bar-container{background:#21262d;border-radius:6px;height:24px;overflow:hidden;margin:8px 0}
.bar{height:100%;background:linear-gradient(90deg,#1f6feb,#58a6ff);transition:width .5s;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;color:#fff}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;padding:8px 12px;color:#8b949e;border-bottom:2px solid #30363d}
td{padding:8px 12px;border-bottom:1px solid #21262d}
.pre{font-family:monospace;font-size:12px;white-space:pre-wrap;color:#8b949e;max-height:300px;overflow-y:auto}
</style></head>
<body>
<h1>🔄 Update All</h1>
<div class="subtitle" id="status">Initialising...</div>
<div class="grid">
  <div class="card">
    <h3>Progress</h3>
    <div class="stat-row"><span class="stat-label">Status</span><span class="stat-value blue" id="phase">-</span></div>
    <div class="stat-row"><span class="stat-label">Elapsed</span><span class="stat-value" id="elapsed">-</span></div>
    <div class="bar-container"><div class="bar" id="bar" style="width:0%">0%</div></div>
  </div>
  <div class="card">
    <h3>Results</h3>
    <div class="stat-row"><span class="stat-label">Images Downloaded</span><span class="stat-value green" id="imagesFound">0</span></div>
    <div class="stat-row"><span class="stat-label">Skipped (had)</span><span class="stat-value blue" id="imagesSkipped">0</span></div>
    <div class="stat-row"><span class="stat-label">Failed</span><span class="stat-value red" id="imagesFailed">0</span></div>
    <div class="stat-row"><span class="stat-label">Discontinued Removed</span><span class="stat-value yellow" id="filteredRemoved">0</span></div>
  </div>
</div>
<div class="card"><h3>Phases</h3><div class="pre" id="phases">-</div></div>
<script>
async function poll(){try{
const r=await fetch('/progress'),d=await r.json();
document.getElementById('status').textContent=d.currentPhase||'-';
document.getElementById('elapsed').textContent=Math.floor(d.elapsed/60)+'m '+Math.floor(d.elapsed%60)+'s';
document.getElementById('imagesFound').textContent=d.imagesFound||0;
document.getElementById('imagesSkipped').textContent=d.imagesSkipped||0;
document.getElementById('imagesFailed').textContent=d.imagesFailed||0;
document.getElementById('filteredRemoved').textContent=d.filteredRemoved||0;
const p=d.phases||{},keys=Object.keys(p),total=keys.length,done=keys.filter(k=>p[k].done).length;
document.getElementById('bar').style.width=total>0?Math.round(done/total*100)+'%':'0%';
document.getElementById('bar').textContent=done+'/'+total;
document.getElementById('phases').textContent=keys.map(k=>'  '+(p[k].done?'✅':'⏳')+' '+k+(p[k].msg?' - '+p[k].msg:'')).join('\n');
if(d.status==='complete')document.getElementById('status').textContent='✅ COMPLETE';
}catch{}}setInterval(poll,1000);poll();
</script></body></html>`;
  fs.writeFileSync(DASHBOARD_HTML, html);

  dashboardServer = http.createServer((req, res) => {
    if (req.url === '/progress') {
      try { res.writeHead(200, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' }); res.end(fs.readFileSync(PROGRESS_JSON, 'utf-8')); } catch { res.end(JSON.stringify(progress)); }
    } else {
      res.writeHead(200, { 'Content-Type': 'text/html' }); res.end(fs.readFileSync(DASHBOARD_HTML, 'utf-8'));
    }
  });
  dashboardServer.listen(PORT, () => {
    const url = `http://localhost:${PORT}`;
    log(`Dashboard: ${url}`);
    try { execSync(`start ${url}`, { shell: 'cmd.exe', timeout: 3000 }); } catch {}
  });
}

function stopDashboard() { if (dashboardServer) { dashboardServer.close(); dashboardServer = null; } }

function markPhase(name, done = true, msg = '') {
  progress.phases[name] = { done, msg };
  progress.currentPhase = name;
  progress.currentPhaseNumber = PHASE_MAP[name] ?? 0;
  writeProgress();
}

/* ── Phase 0: Audit ── */

function auditCSVs() {
  log('\n═══ Phase 0: Audit ═══');
  const csvFiles = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  const thmFiles = fs.readdirSync(THUMB_DIR).filter(f => /\.(jpg|png|webp)$/i.test(f));
  const logoFiles = fs.existsSync(LOGO_DIR) ? fs.readdirSync(LOGO_DIR).filter(f => /\.(png|svg)$/i.test(f)) : [];

  let totalItems = 0, totalWithImg = 0, totalCached = 0;

  for (const f of csvFiles) {
    const csv = readCSV(f);
    if (!csv) continue;
    const imgIdx = csv.header.indexOf('image');
    let items = 0, withImg = 0, cached = 0;

    for (let i = 1; i < csv.lines.length; i++) {
      const parts = parseCSVLine(csv.lines[i]);
      const name = (parts[0] || '').replace(/^"|"$/g, '').trim();
      if (!name) continue;
      items++;
      if (imgIdx !== -1) {
        const img = (parts[imgIdx] || '').trim();
        if (img) {
          withImg++;
          if (img.startsWith('thumbnails/')) {
            const thumb = path.join(THUMB_DIR, img.replace('thumbnails/', ''));
            if (fs.existsSync(thumb)) cached++;
          }
        }
      }
    }
    totalItems += items; totalWithImg += withImg; totalCached += cached;
    log(`  ${f}: ${items} items, ${withImg} with img (${cached} cached)`);
  }

  log(`\n  Summary: ${totalItems} total items, ${totalWithImg} with image refs, ${totalCached} cached`);
  log(`  Thumbnails on disk: ${thmFiles.length}`);
  log(`  Brand logos on disk: ${logoFiles.length}`);

  return { totalItems, totalWithImg, totalCached, thumbnails: thmFiles.length, logos: logoFiles.length };
}

/* ── Phase 1: Fix image paths ── */

function fixImagePaths() {
  log('\n═══ Phase 1: Fix Image Paths ═══');
  const thumbFiles = fs.readdirSync(THUMB_DIR);
  let totalFixed = 0;

  const csvFiles = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  for (const file of csvFiles) {
    const csv = readCSV(file);
    if (!csv) continue;
    const imgIdx = csv.header.indexOf('image');
    if (imgIdx === -1) continue;
    let modified = false;

    for (let i = 1; i < csv.lines.length; i++) {
      const parts = parseCSVLine(csv.lines[i]);
      const imgField = (parts[imgIdx] || '').replace(/^"|"$/g, '');
      if (!imgField) continue;

      const imgs = imgField.split(',').map(s => s.trim()).filter(Boolean);
      let needsFix = false;
      const newImgs = imgs.map(img => {
        if (!img || img.startsWith('http')) return img;
        const baseName = path.basename(img);
        const imgPath = path.join(THUMB_DIR, baseName);
        if (fs.existsSync(imgPath)) return img;

        // Try to find a matching file
        const found = thumbFiles.find(f => {
          const fLower = f.toLowerCase();
          return baseName.toLowerCase() === fLower ||
            sanitize(f.replace(/\.(jpg|png|webp)$/i, '')) === sanitize(baseName.replace(/\.(jpg|png|webp)$/i, ''));
        });
        if (found) { needsFix = true; return `thumbnails/${found}`; }
        return img;
      });

      if (needsFix) {
        const outParts = parseCSVLine(csv.lines[i]);
        outParts[imgIdx] = `"${newImgs.join(',')}"`;
        csv.lines[i] = outParts.join(',');
        modified = true; totalFixed++;
      }
    }
    if (modified) { writeCSV(csv.filePath, csv.lines); log(`  Fixed: ${file}`); }
  }
  log(`  Total paths fixed: ${totalFixed}`);
}

/* ── Phase 2: Filter discontinued ── */

function isModern(categoryId, item) {
  if (!item) return true;
  switch (categoryId) {
    case 'cpu': {
      const name = (item.name || '').toUpperCase();
      const microarch = (item.microarchitecture || '').toUpperCase();
      if (name.includes('RYZEN')) {
        if (microarch && (microarch.includes('ZEN 2') || microarch.includes('ZEN+') || microarch === 'ZEN')) return false;
        const m = name.match(/RYZEN\s+(\d)/);
        if (m && parseInt(m[1]) < 5) return false;
        if (name.includes('3200') || name.includes('2200') || name.includes('1200') || name.includes('1600')) return false;
      }
      if (name.includes('I3') || name.includes('I5') || name.includes('I7') || name.includes('I9')) {
        const m = name.match(/(\d{4})/);
        if (m) { const gen = parseInt(m[1].charAt(0)); if (gen < 10) return false; }
      }
      return true;
    }
    case 'motherboard': {
      const socket = ((item.socket || item.socket || '')).toUpperCase();
      if (socket && !MODERN_SOCKETS.has(socket)) return false;
      if (socket === 'AM4') {
        const chipset = ((item.chipset || '')).toUpperCase();
        const name = ((item.name || '')).toUpperCase();
        if (/[ABX]3\d{2}/.test(chipset || name)) return false;
      }
      return true;
    }
    case 'ram': {
      const ramType = ((item.ram_type || item.type || '')).toUpperCase();
      if (ramType && !['DDR4', 'DDR5'].includes(ramType)) return false;
      if (ramType === 'DDR4') { const s = parseFloat(item.speed); if (s > 0 && s <= 3000) return false; }
      return true;
    }
    case 'psu': { const w = parseFloat(item.wattage); if (w > 0 && w < 450) return false; return true; }
    case 'gpu': {
      const n = (item.name || '').toUpperCase();
      const mem = parseFloat(item.memory);
      if (n.includes('GTX') && !n.includes('1660') && !n.includes('1630')) return false;
      if (n.includes('RTX')) { const m = n.match(/RTX\s*(\d+)/); if (m && parseInt(m[1]) < 2000) return false; }
      if (n.includes('RX')) { const m = n.match(/RX\s*(\d+)/); if (m && parseInt(m[1]) < 500) return false; }
      if (mem > 0 && mem < 6) return false; return true;
    }
    case 'storage': { const c = parseFloat(item.capacity); if (c > 0 && c < 120) return false; return true; }
    case 'monitor': { const r = (item.resolution || '').toUpperCase(); if (r && (r.includes('1366') || r.includes('1280'))) return false; return true; }
    default: return true;
  }
}

function filterDiscontinued() {
  log('\n═══ Phase 2: Remove Discontinued ═══');
  let totalRemoved = 0;

  const csvFiles = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  for (const file of csvFiles) {
    const csv = readCSV(file);
    if (!csv) continue;
    const category = file.replace('.csv', '');

    const valid = [0];
    let removed = 0;
    for (let i = 1; i < csv.lines.length; i++) {
      const parts = parseCSVLine(csv.lines[i]);
      const item = {};
      csv.header.forEach((h, idx) => { item[h.trim()] = (parts[idx] || '').replace(/^"|"$/g, '').trim(); });
      if (isModern(category, item)) { valid.push(i); } else { removed++; }
    }

    if (removed > 0) {
      const newLines = valid.map(idx => csv.lines[idx]);
      writeCSV(csv.filePath, newLines);
      log(`  ${file}: removed ${removed} discontinued items`);
      totalRemoved += removed;
    }
  }
  progress.filteredRemoved += totalRemoved;
  log(`  Total removed: ${totalRemoved}`);
}

/* ── Phase 3: Download product images ── */

async function scrapeRetailerImages(name, category) {
  const searchName = name.replace(/[^\w\s]/g, ' ').trim().substring(0, 100);
  const allUrls = new Map();

  for (const [retailer, urlFunc] of RETAILERS) {
    if (allUrls.size >= 5) break;
    try {
      const url = urlFunc(searchName);
      const response = await fetchWithTimeout(url);
      if (!response || !response.ok) continue;
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
          if (!resp || !resp.ok) continue;
          const pageHtml = await resp.text();
          const zoomImgs = [...pageHtml.matchAll(/(?:data-zoom-image|data-large-image|data-zoom|data-big|data-fullsize)=["'](https?:\/\/[^"']+\.(?:jpg|jpeg|png|webp|avif)[^"']*)["']/gi)];
          for (const z of zoomImgs) { if (allUrls.size >= 10) break; const u = z[1]; if (u && u.length > 80 && isRealProductImage(u, category)) allUrls.set(u, (allUrls.get(u) || 0) + 3); }
          const og = pageHtml.match(/<meta[^>]*property=["']og:image["'][^>]*content=["']([^"']+)["'][^>]*>/i);
          if (og && og[1] && og[1].length > 80 && isRealProductImage(og[1], category)) allUrls.set(og[1], (allUrls.get(og[1]) || 0) + 2);
          const jd = [...pageHtml.matchAll(/<script[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi)];
          for (const j of jd) {
            try {
              const parsed = JSON.parse(j[1]);
              const images = parsed.image || (parsed.mainEntity && parsed.mainEntity.image) || [];
              (Array.isArray(images) ? images : [images]).forEach(iu => { if (typeof iu === 'string' && iu.length > 80 && isRealProductImage(iu, category) && allUrls.size < 10) allUrls.set(iu, (allUrls.get(iu) || 0) + 2); });
            } catch {}
          }
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
      if (!response || !response.ok) continue;
      const text = await response.text();
      const matches = text.matchAll(/https?:\/\/[^\s"'<>]+\.(?:jpg|jpeg|png|webp)/gi);
      for (const match of matches) { const url = match[0]; if (isRealProductImage(url, category) && allUrls.size < 5) allUrls.add(url); }
    } catch {}
  }
  if (allUrls.size < 3) {
    try {
      const ddgUrl = `https://html.duckduckgo.com/html/?q=${encodeURIComponent(searchName + ' ' + (category || '') + ' product image')}`;
      const resp = await fetchWithTimeout(ddgUrl, 10000);
      if (resp && resp.ok) {
        const html = await resp.text();
        const imgUrls = [...html.matchAll(/<img[^>]+src="(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/gi)];
        for (const m of imgUrls) { const u = m[1].split('?')[0]; if (isRealProductImage(u, category) && allUrls.size < 5) allUrls.add(u); }
      }
    } catch {}
  }
  return Array.from(allUrls);
}

async function downloadProductImages(categories, noScrape = false) {
  log('\n═══ Phase 3: Product Images ═══');

  let total = 0, found = 0, failed = 0, skipped = 0;

  // Pre-scan: count total items needing images across all categories
  let totalNeedingImages = 0;
  for (const file of categories) {
    const csv = readCSV(file);
    if (!csv) continue;
    const imgIdx = csv.header.indexOf('image');
    for (let i = 1; i < csv.lines.length; i++) {
      const parts = parseCSVLine(csv.lines[i]);
      const currentImage = (parts[imgIdx] || '').trim();
      if (currentImage && currentImage.startsWith('thumbnails/')) {
        const ip = path.join(THUMB_DIR, currentImage.replace('thumbnails/', ''));
        if (fs.existsSync(ip)) continue;
      }
      totalNeedingImages++;
    }
  }
  progress.overallTotal = totalNeedingImages;
  writeProgress();
  log(`  Total items needing images: ${totalNeedingImages}`);

  for (const file of categories) {
    const csv = readCSV(file);
    if (!csv) continue;
    const category = file.replace('.csv', '');
    const imgIdx = csv.header.indexOf('image');
    if (imgIdx === -1 && csv.header.push('image')) { }
    const actualImgIdx = csv.header.indexOf('image');
    if (actualImgIdx === -1) continue;
    if (csv.lines[0] !== csv.header.join(',')) {
      csv.lines[0] = csv.header.join(',');
    }

    // Build list of items needing images
    const needsImage = [];
    const skipIdx = csv.header.indexOf('skip');
    for (let i = 1; i < csv.lines.length; i++) {
      const parts = parseCSVLine(csv.lines[i]);
      const name = (parts[0] || '').replace(/^"|"$/g, '').trim();
      if (!name) continue;
      if (skipIdx !== -1 && (parts[skipIdx] || '').trim() === '1') continue;
      const currentImage = (parts[actualImgIdx] || '').trim();
      if (currentImage) {
        if (currentImage.startsWith('thumbnails/')) {
          const ip = path.join(THUMB_DIR, currentImage.replace('thumbnails/', ''));
          if (fs.existsSync(ip)) { skipped++; continue; }
        }
      }
      needsImage.push({ i, name, existingUrl: currentImage });
    }

    progress.currentCategory = category;
    progress.currentItem = `Starting ${file}...`;
    writeProgress();
    const batch = needsImage.slice(0, BATCH_SIZE);
    log(`  ${file}: ${needsImage.length} need images (processing ${batch.length})`);

    const batchTotal = batch.length;
    const batchStart = Date.now();
    let batchDone = 0;

    for (const item of batch) {
      const sanitized = sanitize(item.name);
      const shortName = item.name.length > 55 ? item.name.substring(0, 52) + '...' : item.name;
      const destExts = ['.jpg', '.png', '.webp'];
      const existing = destExts.find(e => fs.existsSync(path.join(THUMB_DIR, `${category}_${sanitized}${e}`)));
      if (existing) { skipped++; batchDone++; continue; }

      // Show current item being processed
      progress.currentItem = shortName;
      progress.batchTotal = batchTotal;
      if (batchDone % 5 === 0 || batchDone === 0) {
        log(`  [SCRAPE] ${category} [${batchDone}/${batchTotal}] ${shortName}`);
      }

      let saved = false;
      let source = '';

      // Try existing URL first
      if (item.existingUrl && /^https?:\/\//i.test(item.existingUrl)) {
        const ext = (item.existingUrl.match(/\.(jpg|jpeg|png|webp|avif)/i) || [])[0] || '.jpg';
        const dest = path.join(THUMB_DIR, `${category}_${sanitized}${ext}`);
        if (await verifyAndDownload(item.existingUrl, dest)) { saved = true; source = 'url'; }
      }

      // Try retailer scraping
      if (!saved && !noScrape) {
        const retailerUrls = await scrapeRetailerImages(item.name, category);
        for (const url of retailerUrls) {
          const ext = (url.match(/\.(jpg|jpeg|png|webp|avif)/i) || [])[0] || '.jpg';
          const dest = path.join(THUMB_DIR, `${category}_${sanitized}${ext}`);
          if (await verifyAndDownload(url, dest)) { saved = true; source = 'retailer'; break; }
        }
      }

      // Try web search
      if (!saved) {
        const webUrls = await searchWebImages(item.name, category);
        for (const url of webUrls) {
          const ext = (url.match(/\.(jpg|jpeg|png|webp)/i) || [])[0] || '.jpg';
          const dest = path.join(THUMB_DIR, `${category}_${sanitized}${ext}`);
          if (await verifyAndDownload(url, dest)) { saved = true; source = 'web'; break; }
        }
      }

      if (saved) {
        found++; progress.imagesFound++;
        log(`  [OK] ${category}: ${shortName} (${source})`);
        const outParts = parseCSVLine(csv.lines[item.i]);
        while (outParts.length <= actualImgIdx) outParts.push('');
        const ext = destExts.find(e => fs.existsSync(path.join(THUMB_DIR, `${category}_${sanitized}${e}`))) || '.jpg';
        outParts[actualImgIdx] = `thumbnails/${category}_${sanitized}${ext}`;
        csv.lines[item.i] = outParts.join(',');
        // Write CSV immediately so dashboard sees live updates
        writeCSV(csv.filePath, csv.lines);
        progress.latestThumbnail = `thumbnails/${category}_${sanitized}${ext}`;
      } else {
        failed++; progress.imagesFailed++;
      }
      total++;
      batchDone++;
      progress.batchDone = batchDone;
      progress.overallProcessed++;
      writeProgress();
    }

    const batchElapsed = ((Date.now() - batchStart) / 1000).toFixed(0);
    const rate = batchTotal > 0 ? (batchDone / (batchElapsed / 60)).toFixed(1) : '?';
    log(`  Batch done: ${found} found, ${failed} failed, ${skipped} skipped in ${batchElapsed}s (${rate}/min)`);

    if (found > 0 || failed > 0) writeCSV(csv.filePath, csv.lines);
  }
  log(`  Images: ${found} found, ${failed} failed, ${skipped} skipped`);
}

/* ── Phase 4: docyx dataset + PCPP CDN images ── */

async function downloadDocyxDataset() {
  log('\n═══ Phase 4: docyx Dataset ═══');
  const DOCYX_BASE = 'https://raw.githubusercontent.com/docyx/pc-part-dataset/main/data/json';

  const DOCYX_FILES = {
    'cpu': 'cpu.json', 'cooler': 'cpu-cooler.json', 'motherboard': 'motherboard.json',
    'ram': 'memory.json', 'storage': 'internal-hard-drive.json', 'gpu': 'video-card.json',
    'case': 'case.json', 'power-supply': 'power-supply.json', 'case-fan': 'case-fan.json',
    'case-accessory': 'case-accessory.json', 'fan-controller': 'fan-controller.json',
    'thermal-paste': 'thermal-paste.json', 'os': 'os.json', 'optical-drive': 'optical-drive.json',
    'sound-card': 'sound-card.json', 'wired-network-card': 'wired-network-card.json',
    'wireless-network-card': 'wireless-network-card.json', 'ups': 'ups.json',
    'monitor': 'monitor.json', 'headphones': 'headphones.json', 'keyboard': 'keyboard.json',
    'mouse': 'mouse.json', 'speakers': 'speakers.json', 'webcam': 'webcam.json',
    'external-hard-drive': 'external-hard-drive.json',
  };
  const PCPP_PATH = {
    'monitor': 'monitor', 'keyboard': 'keyboard', 'mouse': 'mouse',
    'headphones': 'headphones', 'speakers': 'speakers', 'webcam': 'webcam',
    'ups': 'ups', 'external-hard-drive': 'hard-drive',
    'cpu': 'cpu', 'cooler': 'cpu-cooler', 'gpu': 'video-card',
    'motherboard': 'motherboard', 'ram': 'memory', 'storage': 'hard-drive',
    'case': 'case', 'power-supply': 'power-supply', 'case-fan': 'case-fan',
    'case-accessory': 'case-accessory', 'fan-controller': 'fan-controller',
    'thermal-paste': 'thermal-compound', 'os': 'os', 'optical-drive': 'optical-drive',
    'sound-card': 'sound-card', 'wired-network-card': 'wired-network-card',
    'wireless-network-card': 'wireless-network-card',
  };

  fs.mkdirSync(DOCYX_CACHE, { recursive: true });
  let totalDownloaded = 0;

  // Download per-category JSON (cached)
  for (const [category, filename] of Object.entries(DOCYX_FILES)) {
    const url = `${DOCYX_BASE}/${filename}`;
    const cachePath = path.join(DOCYX_CACHE, filename);
    if (!fs.existsSync(cachePath)) {
      try {
        const resp = await fetchWithTimeout(url, TIMEOUT);
        if (resp && resp.ok) {
          const data = await resp.json();
          fs.writeFileSync(cachePath, JSON.stringify(data));
        }
      } catch { log(`  Warning: failed to download ${filename}`); }
    }
  }

  // Always try PCPP CDN images for all categories
  for (const [category, filename] of Object.entries(DOCYX_FILES)) {
    const cachePath = path.join(DOCYX_CACHE, filename);
    if (!fs.existsSync(cachePath)) continue;
    let data;
    try { data = JSON.parse(fs.readFileSync(cachePath, 'utf-8')); } catch { continue; }
    if (!data || data.length === 0) continue;

    const pcppCat = PCPP_PATH[category];
    if (!pcppCat) continue;

    const csvFile = `${category}.csv`;
    const csv = readCSV(csvFile);
    if (!csv) continue;
    const imgIdx = csv.header.indexOf('image');
    if (imgIdx === -1) continue;
    const skipIdx = csv.header.indexOf('skip');
    let matched = 0, downloaded = 0;
    for (let i = 1; i < csv.lines.length; i++) {
      const parts = parseCSVLine(csv.lines[i]);
      if (skipIdx !== -1 && (parts[skipIdx] || '').trim() === '1') continue;
      const name = (parts[0] || '').replace(/^"|"$/g, '').trim();
      if (!name) continue;
      const existingImg = (parts[imgIdx] || '').trim();
      if (existingImg && existingImg.startsWith('thumbnails/')) {
        const thumbPath = path.join(THUMB_DIR, existingImg.replace('thumbnails/', ''));
        if (fs.existsSync(thumbPath)) continue;
      }

      const nameNorm = name.toLowerCase().replace(/[^a-z0-9]/g, '');
      const match = data.find(d => {
        const dName = ((d.manufacturer || '') + ' ' + (d.model || '')).toLowerCase().replace(/[^a-z0-9]/g, '');
        return dName.includes(nameNorm) || nameNorm.includes(dName);
      });
      if (!match) continue;

      const pcppId = match.id || match.product_id;
      if (!pcppId) continue;
      matched++;

      const sanitized = sanitize(name);
      const dest = path.join(THUMB_DIR, `${category}_${sanitized}.jpg`);
      if (fs.existsSync(dest)) continue;

      const pcppUrl = `https://cdnd.pcpartpicker.com/images/static/parts/${pcppCat}/${pcppId}.jpg`;
      if (await verifyAndDownload(pcppUrl, dest)) {
        totalDownloaded++; downloaded++;
        const outParts = parseCSVLine(csv.lines[i]);
        outParts[imgIdx] = `thumbnails/${category}_${sanitized}.jpg`;
        csv.lines[i] = outParts.join(',');
      }
    }
    if (matched > 0) {
      writeCSV(csv.filePath, csv.lines);
      log(`  ${csvFile}: ${matched} docyx matches, ${downloaded} PCPP images`);
    }
  }
  log(`  PCPP CDN images downloaded: ${totalDownloaded}`);
}

/* ── Phase 5: Brand Logos ── */

async function resolveBrandLogos() {
  log('\n═══ Phase 5: Brand Logos ═══');
  fs.mkdirSync(LOGO_DIR, { recursive: true });

  const BRAND_DOMAINS = {
    intel: 'intel.com', amd: 'amd.com', nvidia: 'nvidia.com', asus: 'asus.com',
    msi: 'msi.com', gigabyte: 'gigabyte.com', corsair: 'corsair.com',
    evga: 'evga.com', samsung: 'samsung.com', kingston: 'kingston.com',
    wd: 'wd.com', seagate: 'seagate.com', crucial: 'crucial.com',
    noctua: 'noctua.at', lian: 'lian-li.com', nzxt: 'nzxt.com',
    phanteks: 'phanteks.com', fractal: 'fractal-design.com',
    cooler: 'coolermaster.com', thermaltake: 'thermaltake.com',
    bequiet: 'bequiet.com', asrock: 'asrock.com', pny: 'pny.com',
    zotac: 'zotac.com', sapphire: 'sapphiretech.com',
    powercolor: 'powercolor.com', xfx: 'xfxforce.com',
    gskill: 'gskill.com', team: 'teamgroupinc.com', adata: 'adata.com',
    lexar: 'lexar.com', sabrent: 'sabrent.com', deepcool: 'deepcool.com',
    arctic: 'arctic.de', scythe: 'scythe-eu.com',
    ekwb: 'ekwb.com', silverstone: 'silverstonetek.com',
    antec: 'antec.com', inwin: 'in-win.com', razer: 'razer.com',
    logitech: 'logitech.com', steelseries: 'steelseries.com',
    hyperx: 'hyperxgaming.com', li: 'lian-li.com', lianli: 'lian-li.com',
    western: 'wd.com', thermalright: 'thermalright.com',
    seasonic: 'seasonic.com', superflower: 'super-flower.com',
    fsp: 'fsp-group.com', enermax: 'enermax.com',
    bitfenix: 'bitfenix.com', idcooling: 'idcooling.com',
    viewsonic: 'viewsonic.com', lg: 'lg.com', dell: 'dell.com',
    hp: 'hp.com', acer: 'acer.com', lenovo: 'lenovo.com',
    benq: 'benq.com', aoc: 'aoc.com', palit: 'palit.com',
    gainward: 'gainward.com', inno3d: 'inno3d.com',
    kfa: 'kfa2.com', galax: 'galax.com', colorful: 'colorful.com',
    maxsun: 'maxsun.com', yeston: 'yeston.com', oloy: 'oloy.com',
    newegg: 'newegg.com', patriot: 'patriotmemory.com',
    siliconpower: 'silicon-power.com', timetec: 'timetec.com',
    klevv: 'klevv.com', pccooler: 'pccooler.com',
    jonsbo: 'jonsbo.com', metallic: 'metallicgear.com',
    coolman: 'coolman.com', ssupd: 'ssupd.com', hyte: 'hyte.com',
    matorx: 'matorx.com', streacom: 'streacom.com',
    akasa: 'akasa.com', alphel: 'alphel.com',
    alphacool: 'alphacool.com', watercool: 'watercool.de',
    blacknoise: 'blacknoise.de', noiseblocker: 'noiseblocker.de',
    prolimatech: 'prolimatech.com', raijintek: 'raijintek.com',
    reeven: 'reeven.com', swiftech: 'swiftech.com',
    thermal: 'thermalright.com',
  };

  // Extract unique brands
  const brandSet = new Set();
  const files = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  for (const f of files) {
    const csv = readCSV(f);
    if (!csv) continue;
    const brandIdx = csv.header.indexOf('brand');
    const nameIdx = csv.header.indexOf('name');
    if (brandIdx === -1 && nameIdx === -1) continue;
    for (let i = 1; i < csv.lines.length; i++) {
      const parts = parseCSVLine(csv.lines[i]);
      let brand = '';
      if (brandIdx !== -1) brand = (parts[brandIdx] || '').replace(/^"|"$/g, '').trim();
      if (!brand && nameIdx !== -1) brand = (parts[nameIdx] || '').replace(/^"|"$/g, '').trim().split(' ')[0];
      if (brand) brandSet.add(brand.toLowerCase());
    }
  }
  const brands = [...brandSet].sort();
  log(`  Found ${brands.length} unique brands`);

  // Check existing
  const existing = new Set(
    fs.existsSync(LOGO_DIR) ? fs.readdirSync(LOGO_DIR).filter(f => /\.(png|svg)$/i.test(f)).map(f => f.replace(/\.(png|svg)$/i, '')) : []
  );
  const missing = brands.filter(b => !existing.has(b.replace(/[^a-z0-9]/g, '_').toLowerCase()));
  log(`  ${existing.size} cached, ${missing.length} to resolve`);

  if (missing.length === 0) { log('  All logos resolved'); return; }

  function getDomain(brand) {
    const key = Object.keys(BRAND_DOMAINS).find(k => brand.includes(k));
    if (key) return BRAND_DOMAINS[key];
    const clean = brand.replace(/[^a-z0-9]/g, '');
    return clean ? clean + '.com' : null;
  }

  let resolved = 0;

  // Phase 5a: Clearbit
  const cbQueue = [...missing];
  async function cbWorker() {
    while (cbQueue.length > 0) {
      const brand = cbQueue.shift().toLowerCase();
      const safeName = brand.replace(/[^a-z0-9]/g, '_');
      const dest = path.join(LOGO_DIR, safeName + '.png');
      if (fs.existsSync(dest)) { return; }
      const domain = getDomain(brand);
      if (!domain) return;
      if (await downloadFile(`https://logo.clearbit.com/${domain}`, dest, 256)) { resolved++; }
    }
  }
  await Promise.all(Array.from({ length: 10 }, () => cbWorker()));
  log(`  Clearbit: resolved ${resolved}`);

  // Phase 5b: Google Favicons for the rest
  const stillMissing = missing.filter(b => {
    const safeName = b.replace(/[^a-z0-9]/g, '_').toLowerCase();
    return !fs.existsSync(path.join(LOGO_DIR, safeName + '.png'));
  });

  let fvResolved = 0;
  const fvQueue = [...stillMissing];
  async function fvWorker() {
    while (fvQueue.length > 0) {
      const brand = fvQueue.shift().toLowerCase();
      const safeName = brand.replace(/[^a-z0-9]/g, '_');
      const dest = path.join(LOGO_DIR, safeName + '.png');
      if (fs.existsSync(dest)) return;
      const domain = getDomain(brand);
      if (!domain) return;
      if (await downloadFile(`https://www.google.com/s2/favicons?domain=${domain}&sz=128`, dest, 256)) { fvResolved++; }
    }
  }
  await Promise.all(Array.from({ length: 10 }, () => fvWorker()));
  log(`  Favicons: resolved ${fvResolved}`);

  const totalLogos = fs.readdirSync(LOGO_DIR).filter(f => /\.(png|svg)$/i.test(f)).length;
  log(`  Total logos: ${totalLogos} / ${brands.length} (${((totalLogos / brands.length) * 100).toFixed(1)}%)`);
}

/* ── Phase 6: AI Data Status ── */

function generateAIStatus(audit) {
  log('\n═══ Phase 6: AI Data Status ═══');

  const csvFiles = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  const categories = [];

  for (const f of csvFiles) {
    const csv = readCSV(f);
    if (!csv) { categories.push({ file: f, items: 0, hasImages: false, imagesCached: 0 }); continue; }
    const imgIdx = csv.header.indexOf('image');
    let items = 0, withImg = 0, cached = 0;
    for (let i = 1; i < csv.lines.length; i++) {
      const parts = parseCSVLine(csv.lines[i]);
      const name = (parts[0] || '').replace(/^"|"$/g, '').trim();
      if (!name) continue;
      items++;
      if (imgIdx !== -1) {
        const img = (parts[imgIdx] || '').trim();
        if (img) {
          withImg++;
          if (img.startsWith('thumbnails/')) {
            const p = path.join(THUMB_DIR, img.replace('thumbnails/', ''));
            if (fs.existsSync(p)) cached++;
          }
        }
      }
    }
    categories.push({ file: f, category: f.replace('.csv', ''), items, hasImages: imgIdx !== -1, withImg, imagesCached: cached });
  }

  const logoFiles = fs.existsSync(LOGO_DIR) ? fs.readdirSync(LOGO_DIR).filter(f => /\.(png|svg)$/i.test(f)) : [];

  const status = {
    lastUpdate: new Date().toISOString(),
    version: '1.0.0',
    summary: {
      totalItems: categories.reduce((s, c) => s + c.items, 0),
      totalCategories: categories.length,
      categoriesWithImages: categories.filter(c => c.hasImages).length,
      totalImagesCached: categories.reduce((s, c) => s + c.imagesCached, 0),
      brandLogosCached: logoFiles.length,
    },
    dataFreshness: 'Updated by update-all.mjs on ' + new Date().toLocaleDateString('en-GB'),
    categories: categories.filter(c => c.items > 0),
    suitableForBuild: categories.filter(c => c.items > 5).map(c => c.category),
  };

  fs.writeFileSync(AI_STATUS_FILE, JSON.stringify(status, null, 2));
  log(`  Written to ${AI_STATUS_FILE}`);
  log(`  ${status.summary.totalItems} items across ${status.summary.totalCategories} categories`);
  log(`  ${status.summary.totalImagesCached} images cached, ${status.summary.brandLogosCached} brand logos`);
}

/* ── Link Thumbnails to CSV ── */

function linkThumbnails() {
  log('\n═══ Phase: Link Thumbnails ═══');
  let linked = 0;

  const csvFiles = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  for (const file of csvFiles) {
    const csv = readCSV(file);
    if (!csv) continue;
    const category = file.replace('.csv', '');
    const imgIdx = csv.header.indexOf('image');
    if (imgIdx === -1) continue;
    const skipIdx = csv.header.indexOf('skip');
    let changed = false;

    for (let i = 1; i < csv.lines.length; i++) {
      const parts = parseCSVLine(csv.lines[i]);
      if (skipIdx !== -1 && (parts[skipIdx] || '').trim() === '1') continue;
      const name = (parts[0] || '').replace(/^"|"$/g, '').trim();
      if (!name) continue;
      const currentImage = (parts[imgIdx] || '').trim();

      if (currentImage && currentImage.startsWith('thumbnails/')) {
        const thumbPath = path.join(THUMB_DIR, currentImage.replace('thumbnails/', ''));
        if (fs.existsSync(thumbPath)) continue;
      }

      const sanitized = sanitize(name);
      const exts = ['.jpg', '.png', '.webp'];
      const found = exts.find(e => fs.existsSync(path.join(THUMB_DIR, `${category}_${sanitized}${e}`)));
      if (found) {
        parts[imgIdx] = `thumbnails/${category}_${sanitized}${found}`;
        csv.lines[i] = parts.join(',');
        changed = true;
        linked++;
      }
    }

    if (changed) {
      writeCSV(csv.filePath, csv.lines);
      log(`  ${file}: linked ${linked} thumbnails`);
    }
  }
  log(`  Total thumbnails linked: ${linked}`);
}

/* ── Main ── */

async function main() {
  const args = process.argv.slice(2);
  const isQuick = args.includes('--quick');
  const onlyBrandLogos = args.includes('--brand-logos');
  const onlyImages = args.includes('--images');
  const onlyDocyx = args.includes('--docyx');
  const onlyPCPP = args.includes('--pcpp');
  const onlyFixPaths = args.includes('--fix-paths');
  const showDashboard = args.includes('--dashboard');
  const noScrape = args.includes('--no-scrape');
  const categoryArg = args.find(a => a.startsWith('--category='));
  const targetCategory = categoryArg ? categoryArg.split('=')[1] : null;

  log('='.repeat(60));
  log('UPDATE ALL — PC Builder Data Orchestrator');
  log('='.repeat(60));
  log(`Args: ${args.join(' ') || '(default: full update)'}`);

  if (targetCategory) log(`Targeting category: ${targetCategory}`);

  fs.mkdirSync(THUMB_DIR, { recursive: true });

  if (showDashboard) startDashboard();
  writeProgress();

  const csvFiles = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv')).sort((a, b) => {
    const priority = name => name.startsWith('case.') || name.startsWith('gpu.') ? 0 : 1;
    const pa = priority(a), pb = priority(b);
    if (pa !== pb) return pa - pb;
    return a.localeCompare(b);
  });
  const targetFiles = targetCategory
    ? csvFiles.filter(f => f === `${targetCategory}.csv`)
    : csvFiles;

  try {
    // Phase 0: Audit (always)
    markPhase('Audit');
    const audit = auditCSVs();

    if (onlyBrandLogos) {
      markPhase('Brand Logos', false);
      await resolveBrandLogos();
      markPhase('Brand Logos');
      markPhase('AI Status', false);
      generateAIStatus(audit);
      markPhase('AI Status');
      progress.status = 'complete';
      writeProgress();
      log('\n=== UPDATE ALL COMPLETE ===');
      if (showDashboard) { await new Promise(r => setTimeout(r, 5000)); stopDashboard(); }
      return;
    }

    if (onlyImages) {
      markPhase('Product Images', false);
      await downloadProductImages(targetFiles, noScrape);
      markPhase('Product Images');
      markPhase('AI Status', false);
      generateAIStatus(audit);
      markPhase('AI Status');
      progress.status = 'complete';
      writeProgress();
      log('\n=== UPDATE ALL COMPLETE ===');
      if (showDashboard) { await new Promise(r => setTimeout(r, 5000)); stopDashboard(); }
      return;
    }

    if (onlyDocyx) {
      markPhase('docyx Dataset', false);
      await downloadDocyxDataset();
      markPhase('docyx Dataset');
      markPhase('AI Status', false);
      generateAIStatus(audit);
      markPhase('AI Status');
      progress.status = 'complete';
      writeProgress();
      log('\n=== UPDATE ALL COMPLETE ===');
      if (showDashboard) { await new Promise(r => setTimeout(r, 5000)); stopDashboard(); }
      return;
    }

    if (onlyPCPP) {
      log('PCPartPicker import requires scraped_data/ directory with CSVs');
      progress.status = 'complete';
      writeProgress();
      return;
    }

    if (onlyFixPaths) {
      markPhase('Fix Paths');
      fixImagePaths();
      markPhase('Fix Paths', true);
      markPhase('AI Status', false);
      generateAIStatus(audit);
      markPhase('AI Status');
      progress.status = 'complete';
      writeProgress();
      log('\n=== UPDATE ALL COMPLETE ===');
      if (showDashboard) { await new Promise(r => setTimeout(r, 5000)); stopDashboard(); }
      return;
    }

    if (isQuick) {
      // Quick mode: audit + fix paths + brand logos
      markPhase('Fix Paths');
      fixImagePaths();
      markPhase('Fix Paths');

      markPhase('Brand Logos', false);
      await resolveBrandLogos();
      markPhase('Brand Logos');

      markPhase('AI Status', false);
      generateAIStatus(audit);
      markPhase('AI Status');

      progress.status = 'complete';
      writeProgress();
      log('\n=== UPDATE ALL COMPLETE (quick mode) ===');
      if (showDashboard) { await new Promise(r => setTimeout(r, 5000)); stopDashboard(); }
      return;
    }

    // Full update
    markPhase('Fix Paths');
    fixImagePaths();
    markPhase('Fix Paths');

    markPhase('Filter Discontinued');
    filterDiscontinued();
    markPhase('Filter Discontinued');

    markPhase('PCPP + docyx CDN', false);
    await downloadDocyxDataset();
    markPhase('PCPP + docyx CDN');

    markPhase('Product Images', false);
    await downloadProductImages(targetFiles, noScrape);
    markPhase('Product Images');

    markPhase('Link Thumbnails', false);
    linkThumbnails();
    markPhase('Link Thumbnails');

    markPhase('Brand Logos', false);
    await resolveBrandLogos();
    markPhase('Brand Logos');

    markPhase('AI Status', false);
    generateAIStatus(audit);
    markPhase('AI Status');

    progress.status = 'complete';
    writeProgress();

    const elapsed = ((Date.now() - startTime) / 1000 / 60).toFixed(1);
    log(`\n${'='.repeat(60)}`);
    log(`UPDATE ALL COMPLETE — ${elapsed} minutes`);
    log(`  Images found: ${progress.imagesFound}`);
    log(`  Discontinued removed: ${progress.filteredRemoved}`);
    log(`  AI status written to: ${AI_STATUS_FILE}`);
    log('='.repeat(60));
  } catch (err) {
    progress.status = 'error';
    progress.error = err.message;
    writeProgress();
    log(`FATAL: ${err.message}`);
    log(err.stack);
  }

  if (showDashboard) { await new Promise(r => setTimeout(r, 5000)); stopDashboard(); }
}

if (import.meta.url === `file:///${process.argv[1].replace(/\\/g, '/')}`) { main(); }
export { main, auditCSVs, fixImagePaths, filterDiscontinued, downloadProductImages, downloadDocyxDataset, resolveBrandLogos, generateAIStatus, linkThumbnails };
