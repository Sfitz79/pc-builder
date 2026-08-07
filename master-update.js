/**
 * MASTER UPDATE — Unified PC Builder Data Updater
 *
 * Single entry point that consolidates all update scripts:
 *   - Prices  : PCPP UK product-page pass via Byparr (resumable, tracks
 *               availability per item; skips dead listings that have neither
 *               price nor availability, but keeps re-scraping items that have
 *               a known price even when currently unavailable)
 *   - Images  : update-all pipeline (audit → fix paths → filter discontinued → docyx/PCPP CDN
 *               → product images → link thumbnails → brand logos → AI status)
 *   - Filter  : removes discontinued / non-modern items only (NEVER by price or availability)
 *   - Deploy  : optional Vercel deploy
 *   - Dashboard : live progress on http://localhost:3333
 *
 * Usage:
 *   node master-update.js                        # full run (prices + images + filter)
 *   node master-update.js --category=cpu         # one category
 *   node master-update.js --prices-only          # skip image pipeline
 *   node master-update.js --images-only          # skip price pass
 *   node master-update.js --no-dashboard         # headless (scheduled tasks)
 *   node master-update.js --no-deploy            # skip Vercel deploy
 *   node master-update.js --dry-run              # price pass: count tasks, no fetching
 *   node master-update.js --max=50               # price pass: cap items this run
 *   node master-update.js --byparr=http://host:8191/v1
 */

import fs from 'fs';
import path from 'path';
import http from 'http';
import { execSync } from 'child_process';
import { fileURLToPath } from 'url';

import {
  auditCSVs,
  fixImagePaths,
  filterDiscontinued,
  downloadDocyxDataset,
  downloadProductImages,
  resolveBrandLogos,
  generateAIStatus,
  linkThumbnails,
  isModern,
} from './update-all.mjs';
import { CATEGORY_DEFS, buildKeptMap, mergeCategory } from './merge-scraped-json-to-csv.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = __dirname;
const DATA_DIR = path.join(ROOT, 'src', 'data');
const SCRAPED_DIR = path.join(ROOT, 'scraped_data');
const LOG_FILE = path.join(ROOT, 'master-update.log');
const PROGRESS_JSON = path.join(ROOT, 'master-update-progress.json');
const DASHBOARD_HTML = path.join(ROOT, 'master-update-dashboard.html');
const PRICE_STATE_FILE = path.join(ROOT, 'price-fill-state.json');
const PRICE_LOG_FILE = path.join(ROOT, 'price-fill.log');
const DASHBOARD_PORT = 3333;

const args = process.argv.slice(2);
const catFlag = args.find(a => a.startsWith('--category='));
const catArg = catFlag ? catFlag.split('=')[1] : (args.find(a => a && !a.startsWith('--')) || '');
const maxArg = args.find(a => a.startsWith('--max='));
const byparrArg = args.find(a => a.startsWith('--byparr='));
const concurrencyArg = args.find(a => a.startsWith('--concurrency='));
const freshDaysArg = args.find(a => a.startsWith('--fresh-days='));
const proxyFileArg = args.find(a => a.startsWith('--proxy-file='));
const onlyPrices = args.includes('--prices-only');
const onlyImages = args.includes('--images-only');
const noDashboard = args.includes('--no-dashboard');
const noDeploy = args.includes('--no-deploy');
const dryRun = args.includes('--dry-run');
const newProducts = args.includes('--new-products');
const dashboardOnly = args.includes('--dashboard-only');
const skipVersionBump = args.includes('--no-version-bump');

if (args.includes('--help')) {
  console.log(`
MASTER UPDATE — unified PC Builder data updater

Usage:
  node master-update.js [options]

Options:
  --category=cpu       limit to one category (e.g. cpu, gpu, case-accessory)
  --prices-only        run the PCPP UK price pass only
  --images-only        run the update-all image pipeline only
  --new-products       merge scraped_data/*.json into src/data CSVs (adds new
                       products to the catalog) then run the image pipeline
  --dashboard-only     serve the live dashboard without running an update
                       (point a browser at http://localhost:3333)
  --no-dashboard       headless (no live dashboard, for scheduled tasks)
  --no-deploy          skip Vercel deploy at the end
  --no-version-bump    skip the package.json version bump
  --dry-run            price pass: count tasks, no fetching (no changes)
  --max=N              price pass: cap number of items this run
  --concurrency=N      price pass: parallel Byparr requests per category (default 3)
  --fresh-days=N       price pass: skip items whose price was fetched within N days
                       (default 7). Use 0 to always re-scrape.
   --byparr=http://...  Byparr URL (default http://localhost:8191/v1)
   --proxy-file=file    proxy pool (one host:port per line) rotated per attempt
                        in the price pass, sent via the X-Proxy-Server header

Pipeline:
  1. Version bump            5. Filter discontinued / non-modern only
  2. PCPP UK price pass      6. Product images (multi-pass, batch 500)
  3. Audit CSVs              7. Link thumbnails
  4. Fix image paths         8. Brand logos → AI status → optional deploy

Note: filters ONLY remove discontinued/non-modern items — zero-availability
      items are always kept. The price pass additionally skips scraping items
      that have neither a stored price nor confirmed availability (dead
      listings); items with a known price are still scraped even when
      unavailable.
`);
  process.exit(0);
}

const BYPARR = byparrArg ? byparrArg.split('=')[1] : 'http://localhost:8191/v1';
const MAX_ITEMS = maxArg ? parseInt(maxArg.split('=')[1], 10) || 0 : 0;
const PRICE_CONCURRENCY = concurrencyArg ? Math.max(1, parseInt(concurrencyArg.split('=')[1], 10) || 3) : 3;
const FRESH_DAYS = freshDaysArg ? Math.max(0, parseInt((freshDaysArg.split('=')[1] ?? ''), 10) || 0) : 7;
const IMAGE_MAX_PASSES = 20;

// Optional free-proxy pool (one proxy per line) for the PCPP price pass.
// Rotated per attempt so a dead/blocked proxy doesn't stall the run.
const PROXY_FILE = proxyFileArg ? proxyFileArg.split('=')[1] : '';
const proxies = (() => {
  try {
    if (!PROXY_FILE) return [];
    const txt = fs.readFileSync(path.join(ROOT, PROXY_FILE), 'utf-8');
    return txt.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
  } catch {
    return [];
  }
})();
let proxyIdx = 0;
function nextProxy() {
  if (!proxies.length) return null;
  const p = proxies[proxyIdx % proxies.length];
  proxyIdx++;
  return p;
}

// Modern part list: categories covered by modern_pc_parts.json get a precise
// list match ON TOP of the isModern heuristic; other categories rely on isModern.
const MODERN_PARTS = (() => {
  try {
    return JSON.parse(fs.readFileSync(path.join(ROOT, 'modern_pc_parts.json'), 'utf-8')).modern_relevant_pc_tech || {};
  } catch {
    return {};
  }
})();
const LIST_CATEGORIES = new Set(['cpu', 'motherboard', 'ram', 'gpu', 'case']);

const MBOARD_TOKEN_RE = (() => {
  const tokens = new Set();
  for (const group of Object.values(MODERN_PARTS.motherboard_chipsets || {})) {
    for (const entry of group) {
      for (const token of String(entry).toUpperCase().replace(/[^A-Z0-9]+/g, ' ').split(' ')) {
        if (token && /\d/.test(token)) tokens.add(token);
      }
    }
  }
  const list = [...tokens];
  return list.length ? new RegExp(`\\b(?:${list.join('|')})\\b`) : null;
})();

const startTime = Date.now();

// ─── Prices: PCPP priority order ────────────────────────────────────────────
const PRIORITY_ORDER = [
  'cpu.json', 'motherboard.json', 'ram.json', 'gpu.json', 'storage.json',
  'power-supply.json', 'case.json', 'cooler.json', 'case-fan.json',
  'monitor.json', 'keyboard.json', 'mouse.json', 'headphones.json',
  'speakers.json', 'webcam.json', 'external-hard-drive.json', 'os.json',
  'optical-drive.json', 'ups.json', 'fan-controller.json', 'thermal-paste.json',
  'wired-network-card.json', 'wireless-network-card.json', 'sound-card.json',
  'case-accessory.json',
];

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
  pricesFound: 0,
  pricesFailed: 0,
  startTime,
  currentPass: 1,
  totalRemaining: 0,
  status: 'initialising',
  phase: '',
};

// Seed every catalog category up front so the dashboard lists them all from
// the start (they fill in as each category is processed).
for (const jsonFile of PRIORITY_ORDER.filter(jf => CATEGORY_DEFS[jf])) {
  progressState.categories[jsonFile] = {
    processed: 0, total: 0, images: 0, prices: 0, active: false, done: false, remaining: 0,
  };
}

function writeProgress() {
  try {
    progressState.elapsed = (Date.now() - startTime) / 1000;
    progressState.elapsedFormatted = `${Math.floor(progressState.elapsed / 60)}m ${Math.floor(progressState.elapsed % 60)}s`;
    fs.writeFileSync(PROGRESS_JSON, JSON.stringify(progressState, null, 2));
    writeStatusFile();
  } catch {}
}

function writeStatusFile() {
  try {
    const lines = [
      `Status: ${progressState.status}`,
      `Phase: ${progressState.phase || '-'}`,
      `Progress: ${progressState.overallProcessed} / ${progressState.overallTotal || '?'} items`,
      `Prices: ${progressState.pricesFound} found / ${progressState.pricesFailed} failed`,
      `Images: ${progressState.imagesFound} found / ${progressState.imagesFailed} failed`,
      `Elapsed: ${progressState.elapsedFormatted || '0m 0s'}`,
      `Updated: ${new Date().toISOString()}`,
    ];
    fs.writeFileSync(path.join(ROOT, 'master-update-status.txt'), lines.join('\n'), 'utf-8');
  } catch {}
}

function log(msg) {
  const ts = new Date().toISOString().replace('T', ' ').substring(0, 19);
  const line = `[${ts}] ${msg}`;
  console.log(line);
  try { fs.appendFileSync(LOG_FILE, line + '\n'); } catch {}
}

function priceLog(msg) {
  try { fs.appendFileSync(PRICE_LOG_FILE, msg + '\n', 'utf-8'); } catch {}
  console.log(msg);
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

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
    fs.writeFileSync(path.join(ROOT, 'src', 'version.json'), JSON.stringify(versionInfo, null, 2) + '\n');
    log(`  Version bumped to ${pkg.version}`);
  } catch (e) { log(`  Version bump failed: ${e.message}`); }
}// ─── Dashboard (port 3333, schema compatible with master-update-progress.json) ──

let dashboardServer = null;

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
  <h1>&#9881; Master Update</h1>
  <div class="subtitle" id="status">Connecting...</div>
  <div class="grid">
    <div class="card">
      <h3>Overall Progress</h3>
      <div class="stat-row"><span class="stat-label">Status</span><span class="stat-value blue" id="overallStatus">-</span></div>
      <div class="stat-row"><span class="stat-label">Phase</span><span class="stat-value yellow" id="phase">-</span></div>
      <div class="stat-row"><span class="stat-label">Elapsed</span><span class="stat-value" id="elapsed">-</span></div>
      <div class="stat-row"><span class="stat-label">ETA</span><span class="stat-value green" id="eta">-</span></div>
      <div class="stat-row"><span class="stat-label">Rate</span><span class="stat-value blue" id="rate">-</span></div>
      <div class="bar-container"><div class="bar" id="overallBar" style="width:0%">0%</div></div>
      <div class="stat-row"><span class="stat-label">Items</span><span class="stat-value" id="itemsProgress">-</span></div>
    </div>
    <div class="card">
      <h3>Image Scrape</h3>
      <div class="stat-row"><span class="stat-label">Pass</span><span class="stat-value blue" id="currentPass">1</span></div>
      <div class="stat-row"><span class="stat-label">Remaining</span><span class="stat-value yellow" id="totalRemaining">0</span></div>
      <div class="stat-row"><span class="stat-label">Downloaded</span><span class="stat-value green" id="imagesFound">0</span></div>
      <div class="stat-row"><span class="stat-label">Failed</span><span class="stat-value red" id="imagesFailed">0</span></div>
      <div class="stat-row"><span class="stat-label">Discontinued Removed</span><span class="stat-value yellow" id="filteredRemoved">0</span></div>
      <div class="stat-row"><span class="stat-label">Prices Found</span><span class="stat-value green" id="pricesFound">0</span></div>
      <div class="stat-row"><span class="stat-label">Prices Failed</span><span class="stat-value red" id="pricesFailed">0</span></div>
    </div>
  </div>
  <div class="card">
    <h3>Categories</h3>
    <table>
      <thead><tr><th>Category</th><th>Status</th><th>Progress</th><th class="text-right">Images</th><th class="text-right">Prices</th><th class="text-right">Items</th></tr></thead>
      <tbody id="catTable"></tbody>
    </table>
  </div>
  <div class="subtitle mt-24" id="currentAction">-</div>
  <script>
    async function poll() {
      try {
        const r = await fetch('/progress'); const d = await r.json();
        const total = d.overallTotal || 1; const pct = d.overallPercent != null ? d.overallPercent : Math.round((d.overallProcessed / total) * 100);
        document.getElementById('overallStatus').textContent = d.status || '-';
        document.getElementById('phase').textContent = d.phase || '-';
        document.getElementById('elapsed').textContent = d.elapsedFormatted || '-';
        document.getElementById('eta').textContent = d.etaFormatted || '-';
        document.getElementById('rate').textContent = d.ratePerMin ? d.ratePerMin + '/min' : '-';
        document.getElementById('itemsProgress').textContent = d.overallProcessed + ' / ' + total + ' (' + pct + '%)';
        document.getElementById('overallBar').style.width = pct + '%';
        document.getElementById('overallBar').textContent = pct + '%';
        document.getElementById('currentPass').textContent = 'Pass ' + (d.currentPass || 1);
        document.getElementById('totalRemaining').textContent = (d.totalRemaining || 0) + ' items';
        document.getElementById('imagesFound').textContent = d.imagesFound || 0;
        document.getElementById('imagesFailed').textContent = d.imagesFailed || 0;
        document.getElementById('filteredRemoved').textContent = d.filteredRemoved || 0;
        document.getElementById('pricesFound').textContent = d.pricesFound || 0;
        document.getElementById('pricesFailed').textContent = d.pricesFailed || 0;
        document.getElementById('currentAction').textContent = d.currentCategory ? '> ' + d.currentCategory + ': ' + (d.currentItem || 0) + '/' + (d.totalItems || 0) : '-';
        const cats = d.categories || {}; const keys = Object.keys(cats);
        let html = '';
        for (const key of keys) {
          const c = cats[key]; const cpct = c.total > 0 ? Math.round((c.processed / c.total) * 100) : 0;
          const badge = c.done ? '<span class="badge done">DONE</span>' : (c.active ? '<span class="badge active">ACTIVE</span>' : '<span class="badge pending">PENDING</span>');
          html += '<tr><td class="cat-name">' + key + '</td><td>' + badge + '</td><td><div class="mini-bar"><div class="mini-fill" style="width:' + cpct + '%">&nbsp;</div></div></td><td class="text-right">' + (c.images || 0) + (c.remaining ? ' <span style="color:#d29922;font-size:11px">(' + c.remaining + ' left)</span>' : '') + '</td><td class="text-right">' + (c.prices || 0) + '</td><td class="text-right">' + (c.processed || 0) + '/' + (c.total || 0) + '</td></tr>';
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

function startDashboard() {
  generateDashboardHTML();
  dashboardServer = http.createServer((req, res) => {
    if (req.url === '/progress') {
      try {
        let data;
        try { data = JSON.parse(fs.readFileSync(PROGRESS_JSON, 'utf-8')); }
        catch { data = progressState; }
        const elapsed = (Date.now() - (data.startTime || startTime)) / 1000;
        const done = data.overallProcessed || 0;
        const remaining = data.totalRemaining != null && data.totalRemaining > 0
          ? data.totalRemaining
          : Math.max(0, (data.overallTotal || 0) - done);
        const total = (data.overallTotal || 0) + done;
        const rate = elapsed > 30 ? done / elapsed : null;
        data.elapsedSeconds = elapsed;
        data.etaSeconds = (rate && remaining > 0) ? Math.round(remaining / rate) : null;
        data.ratePerMin = rate ? Math.round(rate * 60) : null;
        data.etaFormatted = data.etaSeconds
          ? `${Math.floor(data.etaSeconds / 3600)}h ${Math.floor((data.etaSeconds % 3600) / 60)}m ${Math.round(data.etaSeconds % 60)}s`
          : (rate ? 'calculating…' : '—');
        data.overallPercent = total > 0 ? Math.round((done / total) * 100) : 0;
        res.writeHead(200, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' });
        res.end(JSON.stringify(data));
      } catch { res.end(JSON.stringify(progressState)); }
    } else {
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end(fs.readFileSync(DASHBOARD_HTML, 'utf-8'));
    }
  });
  dashboardServer.listen(DASHBOARD_PORT, () => {
    const url = `http://localhost:${DASHBOARD_PORT}`;
    log(`Dashboard: ${url}`);
    if (process.env.ELECTRON_RUN !== '1') {
      try { execSync(`start ${url}`, { shell: 'cmd.exe', timeout: 3000 }); } catch {}
    } else {
      log('Electron window will load dashboard instead of opening browser');
    }
  });
}

function stopDashboard() { if (dashboardServer) { dashboardServer.close(); dashboardServer = null; } }

// ─── Prices: Byparr PCPP UK pass (port of confirmed-working price-fill.mjs) ──

function loadPriceState() {
  const empty = { version: 1, startedAt: null, updatedAt: null, categories: {} };
  try {
    return { ...empty, ...JSON.parse(fs.readFileSync(PRICE_STATE_FILE, 'utf-8')) };
  } catch {
    return empty;
  }
}

const priceState = loadPriceState();
if (!priceState.startedAt) priceState.startedAt = new Date().toISOString();

function savePriceState() {
  priceState.updatedAt = new Date().toISOString();
  fs.writeFileSync(PRICE_STATE_FILE, JSON.stringify(priceState, null, 2), 'utf-8');
}

// True when a page is a block/challenge page rather than real product content.
function isBlockedPage(html) {
  const title = (/<title>([^<]*)<\/title>/i.exec(html) || [])[1] || '';
  if (/Unavailable/i.test(title) && /Refcode|unavailable/i.test(html)) return true;
  if (/Just a moment/i.test(title)) return true;
  if (/PCPartPicker is unavailable/i.test(html)) return true;
  return false;
}

async function fetchPage(url, byparrUrl, proxy) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 130000);
  try {
    const headers = { 'Content-Type': 'application/json' };
    if (proxy) headers['X-Proxy-Server'] = `http://${proxy}`;
    const resp = await fetch(byparrUrl, {
      method: 'POST',
      headers,
      body: JSON.stringify({ cmd: 'request.get', url, max_timeout: 75 }),
      signal: ctrl.signal,
    });
    if (!resp.ok) {
      const body = await resp.text().catch(() => '');
      throw new Error(`byparr HTTP ${resp.status} ${body.slice(0, 100)}`);
    }
    const data = await resp.json();
    const sol = data.solution;
    if (sol && sol.status === 200 && sol.response) {
      if (isBlockedPage(sol.response)) throw new Error('blocked-page');
      return sol.response;
    }
    throw new Error(`byparr status=${sol && sol.status} msg=${data.message || ''}`);
  } finally {
    clearTimeout(timer);
  }
}

function extractPrice(html) {
  const base = /class="td__base[^"]*">(?:[^0-9]*?)([\d,]+(?:\.\d{1,2})?)\s*<\/td>/g;
  const m = base.exec(html);
  if (m) return parseFloat(m[1].replace(/,/g, ''));
  const nf = /New from\s*[^0-9]*?([\d,]+(?:\.\d{1,2})?)/i.exec(html);
  if (nf) return parseFloat(nf[1].replace(/,/g, ''));
  return null;
}

// Grab the product image from the same page we already fetch for the price.
// Prefer the full-res (1600px) variant of the og:image hash; fall back to the
// og:image itself. Returns '' when the page has none.
function extractImage(html) {
  const og = /<meta[^>]+property=["']og:image["'][^>]*content=["']([^"']+)["']/i.exec(html);
  if (!og || !og[1]) return '';
  const ogUrl = og[1].trim();
  const hash = ogUrl.match(/images\/product\/([a-f0-9]+)\.256p\.jpg/);
  if (hash && new RegExp(`images\\/product\\/${hash[1]}\\.1600\\.jpg`).test(html)) {
    return `https://cdna.pcpartpicker.com/static/forever/images/product/${hash[1]}.1600.jpg`;
  }
  return ogUrl.replace(/^http:/, 'https:');
}

async function fillOne(task, jsonFile, byparrUrl) {
  const t0 = Date.now();
  const backoffs = [0, 3000, 8000, 20000];
  let lastErr = '';
  for (let i = 0; i < backoffs.length; i++) {
    if (i > 0) await sleep(backoffs[i]);
    const proxy = nextProxy();
    try {
      const html = await fetchPage(task.url, byparrUrl, proxy);
      const price = extractPrice(html);
      const imageUrl = extractImage(html);
      const took = (Date.now() - t0) / 1000;
      if (price !== null) {
        priceLog(`ITEM OK T=${Math.floor(t0 / 1000)} cat=${jsonFile} price=${price} img=${imageUrl ? 'Y' : 'N'} took=${took.toFixed(1)} name=${task.name}`);
        return { status: 'found', price, imageUrl, took };
      }
      priceLog(`ITEM NO T=${Math.floor(t0 / 1000)} cat=${jsonFile} img=${imageUrl ? 'Y' : 'N'} took=${took.toFixed(1)} name=${task.name}`);
      return { status: 'notfound', imageUrl, took };
    } catch (e) {
      lastErr = e.message;
    }
  }
  const took = (Date.now() - t0) / 1000;
  priceLog(`ITEM FAIL T=${Math.floor(t0 / 1000)} cat=${jsonFile} took=${took.toFixed(1)} err=${lastErr.slice(0, 120)} name=${task.name}`);
  return { status: 'failed', took };
}

function toModernFlat(item) {
  const specs = item.specs || {};
  const flat = { name: item.productName || '', ...specs, socket: specs.socketCPU };
  if (!flat.ram_type && specs.speed) {
    const m = String(specs.speed).match(/^(DDR\d*|LPDDR\d*X?)/i);
    if (m) flat.ram_type = m[1].toUpperCase();
  }
  return flat;
}

function listMatch(categoryId, item) {
  const specs = item.specs || {};
  const name = String(item.productName || '').toUpperCase();
  switch (categoryId) {
    case 'cpu': {
      const micro = String(specs.microarchitecture || '').toUpperCase();
      if (name.includes('EPYC')) return micro.includes('ZEN 5');
      if (name.includes('RYZEN') || name.includes('THREADRIPPER')) return ['ZEN 3', 'ZEN 4', 'ZEN 5'].includes(micro);
      if (name.includes('XEON')) return micro.includes('SIERRA FOREST') || micro.includes('GRANITE RAPIDS');
      return ['ARROW LAKE', 'LUNAR LAKE', 'RAPTOR LAKE', 'RAPTOR LAKE REFRESH'].includes(micro);
    }
    case 'motherboard':
      return !!MBOARD_TOKEN_RE && MBOARD_TOKEN_RE.test((name + ' ' + String(specs.socketCPU || '')).toUpperCase());
    case 'ram': {
      const speed = String(specs.speed || '');
      return /^(DDR4|DDR5|LPDDR5X|LPDDR5)/i.test(speed);
    }
    case 'gpu': {
      const chipset = String(specs.chipset || '').toUpperCase();
      if (/RTX\s*[45]\d{3}\b/.test(chipset)) return true;
      if (/RX\s*[97]\d{3}\b/.test(chipset)) return true;
      if (/\bARC\s*B\b/.test(chipset)) return true;
      if (/RTX PRO/.test(chipset)) return true;
      if (/RTX\s*\d{4}\s*ADA\b/.test(chipset)) return true;
      if (/RADEON.*AI PRO/.test(chipset)) return true;
      if (/RADEON.*W7\d{3}\b/.test(chipset)) return true;
      if (/ARC PRO B/.test(chipset)) return true;
      return false;
    }
    case 'case': {
      const n = (name + ' ' + String(specs.type || '')).toUpperCase();
      return (MODERN_PARTS.cases || []).some(t => n.includes(String(t).toUpperCase()));
    }
    default:
      return true;
  }
}

// An item is scrape-worthy if it passes the isModern heuristic AND (for the
// categories listed in modern_pc_parts.json) matches the modern part list.
function isModernScraped(categoryId, item) {
  if (!isModern(categoryId, toModernFlat(item))) return false;
  if (LIST_CATEGORIES.has(categoryId)) return listMatch(categoryId, item);
  return true;
}

async function processPriceCategory(jsonFile, def, byparrUrl) {
  const srcPath = path.join(SCRAPED_DIR, jsonFile);
  if (!fs.existsSync(srcPath)) {
    priceLog(`CAT_SKIP ${jsonFile} not-found`);
    return;
  }
  const items = JSON.parse(fs.readFileSync(srcPath, 'utf-8'));

  const clones = items.map(i => ({ ...i, specs: { ...(i.specs || {}) } }));
  const keptMap = buildKeptMap(def, clones);
  const byName = new Map(items.map(i => [String(i.productName || '').trim().toLowerCase(), i]));

  const catState = priceState.categories[jsonFile] ||= {
    total: 0, need: 0, urlmiss: 0, nonmodern: 0, deadSkip: 0, done: 0, found: 0, notfound: 0, failed: 0, doneNames: [], status: 'pending',
  };
  if (catState.status !== 'paused') {
    catState.done = 0;
    catState.found = 0;
    catState.notfound = 0;
    catState.failed = 0;
    catState.nonmodern = 0;
    catState.deadSkip = 0;
    catState.doneNames = [];
  }
  catState.status = 'running';
  catState.total = keptMap.size;

  const now = Date.now();
  const freshMs = FRESH_DAYS * 24 * 60 * 60 * 1000;
  const isFresh = (orig) => {
    const t = Date.parse(orig.priceCheckedAt || orig.priceUpdatedAt || '');
    return t > 0 && (now - t) < freshMs;
  };

  const tasks = [];
  let urlmiss = 0;
  let nonmodern = 0;
  let deadSkip = 0;
  const categoryId = jsonFile.replace(/\.json$/, '');
  for (const [mapKey, clone] of keptMap) {
    const lower = String(clone.productName || '').trim().toLowerCase();
    const orig = byName.get(lower);
    if (!orig) continue;
    if (isFresh(orig)) continue;
    // Skip items with no stored price AND confirmed no availability (dead
    // listings). Items that still have a price but no availability are kept
    // so we keep trying to refresh their price.
    if (orig.availability === false && (orig.price == null || orig.price === '')) { deadSkip++; continue; }
    if (!isModernScraped(categoryId, orig)) { nonmodern++; continue; }
    if (!orig.url) { urlmiss++; continue; }
    tasks.push({ mapKey, name: clone.productName, url: orig.url });
  }
  catState.urlmiss = urlmiss;
  catState.nonmodern = nonmodern;
  catState.deadSkip = deadSkip;
  catState.need = tasks.length + urlmiss;

  progressState.categories[jsonFile] = {
    processed: catState.done,
    total: catState.need,
    images: 0,
    prices: catState.found,
    active: true,
    done: catState.status === 'done',
    remaining: tasks.length,
  };
  progressState.currentCategory = jsonFile;
  progressState.totalItems = tasks.length;
  progressState.currentItem = 0;
  writeProgress();

  priceLog(`CAT_START ${jsonFile} total=${catState.total} need=${catState.need} remaining=${tasks.length} urlmiss=${urlmiss} nonmodern=${nonmodern} deadSkip=${deadSkip} freshDays=${FRESH_DAYS} concurrency=${PRICE_CONCURRENCY}`);
  if (dryRun) return;

  let itemsProcessed = 0;
  let changedAny = false;
  let savedAt = 0;

  for (let start = 0; start < tasks.length; start += PRICE_CONCURRENCY) {
    const chunk = tasks.slice(start, start + PRICE_CONCURRENCY);
    const chunkResults = await Promise.all(chunk.map(task => fillOne(task, jsonFile, byparrUrl)));

    for (let k = 0; k < chunk.length; k++) {
      const task = chunk[k];
      const res = chunkResults[k];
      const orig = byName.get(String(task.name).trim().toLowerCase());
      const prevPrice = orig ? orig.price : null;
      if (orig && res.imageUrl && !orig.imageUrl) {
        orig.imageUrl = res.imageUrl;
        changedAny = true;
      }
      if (res.status === 'found') {
        if (orig) {
          orig.price = res.price;
          orig.priceCurrency = orig.priceCurrency || 'gbp';
          orig.priceUpdatedAt = new Date().toISOString();
          orig.priceCheckedAt = new Date().toISOString();
          orig.availability = true;
        }
        catState.found++;
        progressState.pricesFound++;
        if (prevPrice !== res.price) changedAny = true;
      } else if (res.status === 'notfound') {
        catState.notfound++;
        if (orig) {
          orig.priceCheckedAt = new Date().toISOString();
          orig.availability = false;
        }
      } else {
        catState.failed++;
        progressState.pricesFailed++;
      }
      catState.done++;
      if (!catState.doneNames.includes(task.mapKey)) catState.doneNames.push(task.mapKey);
      itemsProcessed++;
      progressState.currentItem = itemsProcessed;
      progressState.overallProcessed++;
      const catDash = progressState.categories[jsonFile];
      if (catDash) {
        catDash.processed = catState.done;
        catDash.remaining = tasks.length - itemsProcessed;
        catDash.prices = catState.found;
      }
      writeProgress();

      if (catState.done - savedAt >= 10) {
        fs.writeFileSync(srcPath, JSON.stringify(items, null, 2), 'utf-8');
        savePriceState();
        savedAt = catState.done;
      }
      if (MAX_ITEMS > 0 && progressState.overallProcessed >= MAX_ITEMS) {
        fs.writeFileSync(srcPath, JSON.stringify(items, null, 2), 'utf-8');
        savePriceState();
        priceLog(`CAT_PAUSE ${jsonFile} hit-max=${MAX_ITEMS} actual=${catState.done}`);
        catState.status = 'paused';
        return;
      }
    }
  }

  fs.writeFileSync(srcPath, JSON.stringify(items, null, 2), 'utf-8');
  catState.status = 'done';
  savePriceState();
  priceLog(`CAT_DONE ${jsonFile} found=${catState.found} notfound=${catState.notfound} failed=${catState.failed} urlmiss=${urlmiss} nonmodern=${nonmodern} deadSkip=${deadSkip} changed=${changedAny}`);
  if (progressState.categories[jsonFile]) {
    progressState.categories[jsonFile].done = true;
    progressState.categories[jsonFile].active = false;
    progressState.categories[jsonFile].processed = catState.done;
    progressState.categories[jsonFile].remaining = 0;
    writeProgress();
  }
  if (changedAny) {
    console.log(`[${jsonFile}] regenerating CSVs (prices changed)...`);
    try {
      mergeCategory(def, items);
    } catch (e) {
      priceLog(`CAT_MERGE_ERR ${jsonFile} ${e.message}`);
    }
  } else {
    priceLog(`CAT_NOMERGE ${jsonFile} no price changes — CSVs untouched`);
  }
}

async function runPricePass() {
  progressState.phase = 'Prices';
  writeProgress();
  console.log('\n=== PCPP UK Price Fill (Byparr) ===');
  console.log(`Byparr: ${BYPARR} | Max items: ${MAX_ITEMS || 'unlimited'} | Dry run: ${dryRun}`);

  let order = PRIORITY_ORDER.filter(jf => CATEGORY_DEFS[jf]);
  if (catArg) {
    const wanted = catArg.split(',').map(w => (w.endsWith('.json') ? w : `${w}.json`));
    order = wanted.filter(w => CATEGORY_DEFS[w]);
    const unknown = wanted.filter(w => !CATEGORY_DEFS[w]);
    if (unknown.length) console.log(`  Unknown categories: ${unknown.join(', ')}`);
  }
  if (order.length === 0) {
    console.log('No known categories to process');
    return;
  }
  console.log(`Categories: ${order.join(', ')}`);

  let overallNeeded = 0;
  for (const jf of order) {
    const st = priceState.categories[jf];
    if (st) overallNeeded += st.need || 0;
  }
  progressState.overallTotal = overallNeeded || progressState.overallProcessed;
  progressState.totalRemaining = Math.max(0, (progressState.overallTotal || 0) - progressState.overallProcessed);
  writeProgress();

  const flush = dryRun ? null : setInterval(() => savePriceState(), 2000);
  for (const jsonFile of order) {
    await processPriceCategory(jsonFile, CATEGORY_DEFS[jsonFile], BYPARR);
    if (MAX_ITEMS > 0 && progressState.overallProcessed >= MAX_ITEMS) break;
  }
  if (flush) clearInterval(flush);
  if (!dryRun) savePriceState();
  progressState.currentCategory = '';
  writeProgress();
  console.log('\n=== PRICE PASS DONE ===');
}

// ─── Images: update-all pipeline phases ─────────────────────────────────────

const DUMMY_IMAGE_MAX_BYTES = 8192;

function isDummyImage(filePath) {
  try {
    const st = fs.statSync(filePath);
    if (!st.isFile()) return true;
    if (st.size < DUMMY_IMAGE_MAX_BYTES) return true;
    if (/\.svg$/i.test(filePath)) return true;
    return false;
  } catch {
    return true;
  }
}

function hasUsableImage(thumbPath) {
  return fs.existsSync(thumbPath) && !isDummyImage(thumbPath);
}

function countNeedingImages() {
  const csvFiles = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  let total = 0;
  for (const file of csvFiles) {
    const fp = path.join(DATA_DIR, file);
    if (!fs.existsSync(fp)) continue;
    const content = fs.readFileSync(fp, 'utf-8');
    const lines = content.split(/\r?\n/).filter(l => l.trim());
    if (lines.length < 2) continue;
    const header = lines[0].split(',');
    const imgIdx = header.indexOf('image');
    if (imgIdx === -1) continue;
    for (let i = 1; i < lines.length; i++) {
      const parts = lines[i].split(',');
      const name = (parts[0] || '').replace(/^"|"$/g, '').trim();
      if (!name) continue;
      const currentImage = (parts[imgIdx] || '').trim();
      if (currentImage) {
        if (currentImage.startsWith('thumbnails/')) {
          const thumb = path.join(ROOT, 'public', 'thumbnails', currentImage.replace('thumbnails/', ''));
          if (hasUsableImage(thumb)) continue;
        }
      }
      total++;
    }
  }
  return total;
}

function scanImageStatus() {
  const csvFiles = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  const rows = {};
  for (const file of csvFiles) {
    const fp = path.join(DATA_DIR, file);
    if (!fs.existsSync(fp)) continue;
    const content = fs.readFileSync(fp, 'utf-8');
    const lines = content.split(/\r?\n/).filter(l => l.trim());
    if (lines.length < 2) continue;
    const header = lines[0].split(',');
    const imgIdx = header.indexOf('image');
    if (imgIdx === -1) continue;
    let total = 0, have = 0;
    for (let i = 1; i < lines.length; i++) {
      const parts = lines[i].split(',');
      const name = (parts[0] || '').replace(/^"|"$/g, '').trim();
      if (!name) continue;
      total++;
      const currentImage = (parts[imgIdx] || '').trim();
      if (currentImage) {
        if (currentImage.startsWith('thumbnails/')) {
          const thumb = path.join(ROOT, 'public', 'thumbnails', currentImage.replace('thumbnails/', ''));
          if (hasUsableImage(thumb)) { have++; continue; }
        } else if (currentImage.startsWith('http')) { have++; continue; }
      }
    }
    rows[file] = { total, have, need: total - have };
  }
  return rows;
}

function updateImageDashboard() {
  const rows = scanImageStatus();
  for (const [file, r] of Object.entries(rows)) {
    const catKey = file.replace(/\.csv$/, '') + '.json';
    const existing = progressState.categories[catKey] || {
      processed: 0, total: 0, images: 0, active: false, done: false, remaining: 0, prices: 0,
    };
    progressState.categories[catKey] = {
      ...existing,
      total: Math.max(existing.total, r.total),
      images: r.have,
      remaining: r.need,
      done: existing.done || r.need === 0,
    };
  }
  progressState.overallTotal = Object.values(rows).reduce((s, r) => s + r.total, 0);
  progressState.totalRemaining = Object.values(rows).reduce((s, r) => s + r.need, 0);
  writeProgress();
}

async function runImagePass() {
  progressState.phase = 'Images';
  writeProgress();

  const csvFiles = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv')).sort((a, b) => {
    const pa = a.startsWith('case.') || a.startsWith('gpu.') ? 0 : 1;
    const pb = b.startsWith('case.') || b.startsWith('gpu.') ? 0 : 1;
    if (pa !== pb) return pa - pb;
    return a.localeCompare(b);
  });
  const targetFiles = catArg
    ? csvFiles.filter(f => f === `${catArg}.csv`)
    : csvFiles;

  let pass = 1;
  let remaining = countNeedingImages();
  updateImageDashboard();
  while (remaining > 0 && pass <= IMAGE_MAX_PASSES) {
    progressState.currentPass = pass;
    progressState.overallTotal = remaining;
    progressState.totalRemaining = remaining;
    log(`\n${'='.repeat(60)}\n=== IMAGE PASS ${pass} — ${remaining} items remaining ===\n${'='.repeat(60)}`);

    await downloadProductImages(targetFiles, args.includes('--no-scrape'));

    remaining = countNeedingImages();
    progressState.totalRemaining = remaining;
    updateImageDashboard();
    pass++;
    writeProgress();
    if (remaining > 0 && pass <= IMAGE_MAX_PASSES) {
      await sleep(3000);
    }
  }
  progressState.currentPass = pass - 1;
  updateImageDashboard();
  writeProgress();
}

// ─── New products: merge scraped_data/*.json into src/data CSVs ─────────────

async function runNewProductsMerge() {
  progressState.phase = 'Merge new products';
  writeProgress();
  console.log('\n=== Merge Scraped JSON → src/data CSVs (new products) ===');
  let merged = 0;
  for (const [jsonFile, def] of Object.entries(CATEGORY_DEFS)) {
    const srcPath = path.join(SCRAPED_DIR, jsonFile);
    if (!fs.existsSync(srcPath)) continue;
    const items = JSON.parse(fs.readFileSync(srcPath, 'utf-8'));
    console.log(`[${jsonFile}] ${items.length} items`);
    try {
      mergeCategory(def, items);
      merged++;
    } catch (e) {
      log(`  Merge failed for ${jsonFile}: ${e.message}`);
    }
  }
  progressState.imagesFound = merged;
  writeProgress();
  console.log(`Merged ${merged} categories`);
}

// ─── Deploy ────────────────────────────────────────────────────────────────

function deployToVercel() {
  try {
    log('Deploying to Vercel...');
    const result = execSync('npx vercel --prod --yes 2>&1', { timeout: 120000, cwd: ROOT, shell: 'cmd.exe' });
    const out = result.toString().trim();
    const lines = out.split(/\r?\n/).filter(l => l.trim());
    const deployUrl = lines.find(l => l.includes('https://')) || lines[lines.length - 1] || 'done';
    log(`  Vercel deploy: ${deployUrl}`);
  } catch (err) {
    log(`  Vercel deploy failed: ${err.message}`);
  }
}

// ─── Main ──────────────────────────────────────────────────────────────────

async function main() {
  log('\n' + '='.repeat(60));
  log('MASTER UPDATE (unified) - Starting');
  log(`Args: ${args.join(' ') || '(default: full run)'}`);
  log('='.repeat(60));

  if (!noDashboard || dashboardOnly) startDashboard();
  writeProgress();

  // Dashboard-only: serve progress from a background/scheduled run.
  if (dashboardOnly) {
    progressState.status = 'dashboard-only';
    progressState.phase = 'Monitoring background update';
    log('Dashboard-only mode. Serving progress while a scheduled/background run updates master-update-progress.json.');
    try {
      const existing = JSON.parse(fs.readFileSync(PROGRESS_JSON, 'utf-8'));
      progressState.phase = existing.phase || progressState.phase;
      progressState.status = existing.status || progressState.status;
      if (existing.overallProcessed) progressState.overallProcessed = existing.overallProcessed;
      if (existing.overallTotal) progressState.overallTotal = existing.overallTotal;
    } catch {}
    writeProgress();
    log('Ctrl+C to stop. Dashboard at http://localhost:3333');
    await new Promise(() => {});
    return;
  }

  try {
    progressState.status = 'running';

    if (newProducts) {
      // New products check: merge scraped data into CSVs, then images.
      await runNewProductsMerge();
      progressState.status = 'complete';
      progressState.phase = 'Complete';
      writeProgress();
      log('\n' + '='.repeat(60));
      log('NEW PRODUCTS MERGE COMPLETE');
      log('='.repeat(60));
      if (!noDashboard) {
        await new Promise(r => setTimeout(r, 30000));
        stopDashboard();
      }
      return;
    }

    // Phase 0: Version bump
    progressState.phase = 'Version Bump';
    writeProgress();
    if (!dryRun && !skipVersionBump) bumpVersion();

    // Phase 1: Prices (Byparr PCPP UK)
    if (!onlyImages) {
      await runPricePass();
    }

    if (dryRun) {
      log('Dry run complete — no changes applied.');
      progressState.status = 'complete';
      writeProgress();
      return;
    }

    // Phase 2: update-all pipeline (audit → filter → images → logos → AI status)
    if (!onlyPrices) {
      progressState.phase = 'Audit';
      writeProgress();
      auditCSVs();

      if (!onlyPrices) {
        progressState.phase = 'Fix Paths';
        writeProgress();
        fixImagePaths();

        progressState.phase = 'Filter Discontinued';
        writeProgress();
        filterDiscontinued();
      }

      progressState.phase = 'docyx + PCPP CDN';
      writeProgress();
      await downloadDocyxDataset();

      await runImagePass();

      progressState.phase = 'Link Thumbnails';
      writeProgress();
      linkThumbnails();

      progressState.phase = 'Brand Logos';
      writeProgress();
      await resolveBrandLogos();

      progressState.phase = 'AI Status';
      writeProgress();
      const audit = auditCSVs();
      generateAIStatus(audit);
    }

    if (!noDeploy) {
      progressState.phase = 'Deploy';
      writeProgress();
      deployToVercel();
    }

    progressState.status = 'complete';
    progressState.phase = 'Complete';
    writeProgress();

    const elapsed = Math.round((Date.now() - startTime) / 60000);
    log('\n' + '='.repeat(60));
    log(`MASTER UPDATE COMPLETE — ${elapsed} minutes`);
    log(`  Prices found: ${progressState.pricesFound} | Failed: ${progressState.pricesFailed}`);
    log(`  Images found: ${progressState.imagesFound} | Discontinued removed: ${progressState.filteredRemoved}`);
    log('='.repeat(60));
  } catch (err) {
    progressState.status = 'error';
    progressState.error = err.message;
    writeProgress();
    log(`Fatal: ${err.message}\n${err.stack}`);
  }

  if (!noDashboard) {
    await new Promise(r => setTimeout(r, 30000));
    stopDashboard();
  }
}

main().catch(err => {
  progressState.status = 'error';
  progressState.error = err.message;
  writeProgress();
  log(`Fatal: ${err.message}\n${err.stack}`);
  if (dashboardServer) stopDashboard();
  process.exit(1);
});
