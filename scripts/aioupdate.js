import fs from 'fs';
import path from 'path';
import os from 'os';
import { fileURLToPath } from 'url';
import { execSync } from 'child_process';
import http from 'http';
import dotenv from 'dotenv';
dotenv.config();
import puppeteer from 'puppeteer-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';
puppeteer.use(StealthPlugin());
import puppeteerCore from 'puppeteer-core';
import { chromium as playwrightChromium } from 'playwright';
import { main as runUpdateAll, auditCSVs, fixImagePaths, filterDiscontinued, downloadProductImages, downloadDocyxDataset, resolveBrandLogos, generateAIStatus } from '../update-all.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const DATA_DIR = path.join(ROOT, 'src', 'data');
const THUMB_DIR = path.join(ROOT, 'public', 'thumbnails');
const scrapedDir = path.join(ROOT, 'scraped_data');
const LOG_FILE = path.join(ROOT, 'aioupdate.log');
const UPDATE_ALL_LOG = path.join(ROOT, 'update-all.log');
const STATE_FILE = path.join(ROOT, 'aioupdate-state.json');
const UPDATE_ALL_PROGRESS = path.join(ROOT, 'update-all-progress.json');
const MASTER_PROGRESS = path.join(ROOT, 'master-update-progress.json');
const PROGRESS_JSON = MASTER_PROGRESS;
const DASHBOARD_HTML = path.join(ROOT, 'aioupdate-dashboard.html');

export { APP_CONFIG, DB_TABLES, DATA_SOURCES };

const APP_CONFIG = {
  package: 'com.indraanisa.pcbuilder',
  version: '2.6.1',
  author: 'http://bit.ly/2BF4Qi9',
  firebase: {
    apiKey: 'AIzaSyB1-Nfh7NUh-C1NJCX_UTWpmDL4W8TZugU',
    projectId: 'pc-builder-eebc7',
    storageBucket: 'pc-builder-eebc7.appspot.com',
    appId: '1:608081718634:android:f6681d263159365d37c913',
    oauthClientId: '608081718634-nlaq402cea0npisc45d0egg5m82hg1g5.apps.googleusercontent.com',
    analyticsUrl: 'https://app-measurement.com/a',
    analyticsSgtmUrl: 'https://app-measurement.com/s/d',
  },
  admob: {
    appId: 'ca-app-pub-1342416559539205~72650633488',
  },
  s3: {
    assetBucket: 'https://pcbuilderapp.s3.us-east-2.amazonaws.com/',
    updateJson: 'update.json',
  },
  google: {
    conversionUrl: 'https://www.googleadservices.com/pagead/conversion/app/deeplink?id_type=adid&sdk_version=%s&rdid=%s&bundleid=%s&retry=%s',
    pageadUrl: 'https://pagead2.googlesyndication.com/pagead/gen_204?id=gmob-apps',
  },
  amazon: {
    us: 'https://www.amazon.com/dp/',
    uk: 'https://www.amazon.co.uk/dp/',
    de: 'https://www.amazon.de/dp/',
    fr: 'https://www.amazon.fr/dp/',
    es: 'https://www.amazon.es/dp/',
    it: 'https://www.amazon.it/dp/',
    ca: 'https://www.amazon.ca/dp/',
    in: 'https://www.amazon.in/dp/',
    au: 'https://www.amazon.com.au/dp/',
  },
};

const DB_TABLES = {
  pc_build: 'PC build configurations',
  pc_build_dtl: 'PC build detail/line items',
  pc_parts: 'Parts catalog (bundled locally)',
  fav_parts: "User's favorite parts",
  socket_proc: 'CPU socket/Motherboard compatibility',
};

const DATA_SOURCES = {
  s3_assets: {
    base_url: APP_CONFIG.s3.assetBucket,
    update_check: `${APP_CONFIG.s3.assetBucket}${APP_CONFIG.s3.updateJson}`,
    prices: `${APP_CONFIG.s3.assetBucket}prices/`,
    images: `${APP_CONFIG.s3.assetBucket}images/`,
    csvs: `${APP_CONFIG.s3.assetBucket}csvs/`,
  },
  affiliate_amazon: {
    _priority: 'primary',
    ...Object.fromEntries(
      Object.entries(APP_CONFIG.amazon).map(([region, url]) => [`amazon_${region}`, url])
    ),
  },
  uk_retailers: {
    overclockers_uk: 'https://www.overclockers.co.uk/',
    scan_uk: 'https://www.scan.co.uk/',
    box_uk: 'https://www.box.co.uk/',
    ccl_uk: 'https://www.cclonline.com/',
    novatech_uk: 'https://www.novatech.co.uk/',
    argos_uk: 'https://www.argos.co.uk/browse/technology/computing/pc-components/c:30049/',
    currys_uk: 'https://www.currys.co.uk/pc-components/',
    awdit_uk: 'https://www.awd-it.co.uk/',
    laptops_direct_uk: 'https://www.laptopsdirect.co.uk/pc-components',
  },
  uk_price_comparison: {
    pcpartpicker_uk: 'https://uk.pcpartpicker.com/',
    marginseye: 'https://www.marginseye.com/',
    cex_used_parts: 'https://uk.webuy.com/',
  },
  open_source_datasets: {
    hardwaredealsco_gpu: 'https://hardwaredeals.co/datasets/gpu.json',
    hardwaredealsco_ram: 'https://hardwaredeals.co/datasets/ram.json',
    hardwaredealsco_ssd: 'https://hardwaredeals.co/datasets/drives.json',
    hardwaredealsco_monitors: 'https://hardwaredeals.co/datasets/monitors.json',
    pc_retailer_list: 'https://github.com/ElBozoII/PC-Retailer-List',
  },
  firebase: {
    analytics: APP_CONFIG.firebase.analyticsUrl,
    analytics_sgtm: APP_CONFIG.firebase.analyticsSgtmUrl,
  },
  google_tracking: {
    conversion: APP_CONFIG.google.conversionUrl,
    pagead: APP_CONFIG.google.pageadUrl,
  },
};

function readProgress() {
  for (const fp of [UPDATE_ALL_PROGRESS, MASTER_PROGRESS]) {
    try {
      if (fs.existsSync(fp)) return JSON.parse(fs.readFileSync(fp, 'utf-8'));
    } catch {}
  }
  return null;
}

const PUPPETEER_DELAY_MS = 1500;
let lastPuppeteerTime = 0;
let browserInstance = null;

const OBSCURA_PORT = 9222;
const OBSCURA_PATH = path.join(ROOT, 'obscura', 'obscura.exe');
let obscuraProcess = null;
let obscuraBrowser = null;

const HEALTH_CHECK_INTERVAL = 5 * 60 * 1000;
const DASHBOARD_POLL_INTERVAL = 1000;
const DASHBOARD_PORT = 3335;
const BATCH_SIZE = 100;
const RUN_INTERVAL_DAYS = 5;
const STALL_TIMEOUT = 5 * 60 * 1000;
const DEPLOY_INTERVAL = 30 * 60 * 1000;

const startTime = Date.now();

const state = {
  lastRun: null,
  lastSuccess: null,
  pid: null,
  status: 'idle',
  category: null,
  categoryIndex: 0,
  totalCategories: 0,
  batchIndex: 0,
  totalBatches: 0,
  imagesNeeded: 0,
  imagesFound: 0,
  imagesFailed: 0,
  imagesSkipped: 0,
  itemsProcessed: 0,
  itemsTotal: 0,
  progressPct: 0,
  lastProgressTime: Date.now(),
  progressSnapshot: null,
  restartCount: 0,
  phase: '',
  phaseNumber: 0,
  latestThumbnail: '',
  lastDeployTime: 0,
};

let healthTimer = null;
let dashboardServer = null;

function log(msg) {
  const ts = new Date().toISOString().replace('T', ' ').substring(0, 19);
  const line = `[${ts}] ${msg}`;
  console.log(line);
  try { fs.appendFileSync(LOG_FILE, line + '\n'); } catch {}
}

function loadState() {
  try {
    if (fs.existsSync(STATE_FILE)) {
      const saved = JSON.parse(fs.readFileSync(STATE_FILE, 'utf-8'));
      Object.assign(state, saved);
    }
  } catch {}
}

function saveState() {
  try {
    fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2));
  } catch {}
}

function shouldRun() {
  const now = Date.now();
  if (!state.lastSuccess) return true;
  const diffDays = (now - state.lastSuccess) / (1000 * 60 * 60 * 24);
  return diffDays >= RUN_INTERVAL_DAYS;
}

function writeProgress() {
  const elapsed = (Date.now() - startTime) / 1000;
  const progress = {
    status: state.status,
    phase: state.phase,
    pid: state.pid,
    category: state.category,
    categoryIndex: state.categoryIndex,
    totalCategories: state.totalCategories,
    batchIndex: state.batchIndex,
    totalBatches: state.totalBatches,
    imagesNeeded: state.imagesNeeded,
    imagesFound: state.imagesFound,
    imagesFailed: state.imagesFailed,
    imagesSkipped: state.imagesSkipped,
    imagesTotal: state.imagesNeeded + state.imagesFound + state.imagesFailed,
    itemsProcessed: state.itemsProcessed,
    itemsTotal: state.itemsTotal,
    progressPct: state.progressPct,
    restartCount: state.restartCount,
    elapsedFormatted: `${Math.floor(elapsed / 60)}m ${Math.floor(elapsed % 60)}s`,
    lastProgressTime: state.lastProgressTime,
    lastRun: state.lastRun,
    lastSuccess: state.lastSuccess,
    nextRunDue: state.lastSuccess
      ? new Date(state.lastSuccess + RUN_INTERVAL_DAYS * 24 * 60 * 60 * 1000).toISOString()
      : null,
  };
  try {
    fs.writeFileSync(PROGRESS_JSON, JSON.stringify(progress, null, 2));
  } catch {}
}

function getCSVItemCounts() {
  try {
    const files = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
    let total = 0;
    for (const f of files) {
      const content = fs.readFileSync(path.join(DATA_DIR, f), 'utf-8');
      const lines = content.split(/\r?\n/).filter(l => l.trim());
      const items = Math.max(0, lines.length - 1);
      total += items;
    }
    return total;
  } catch {
    return 0;
  }
}

function countImagesNeeded() {
  try {
    let needed = 0;
    const files = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
    for (const f of files) {
      const content = fs.readFileSync(path.join(DATA_DIR, f), 'utf-8');
      const lines = content.split(/\r?\n/).filter(l => l.trim());
      if (lines.length < 2) continue;
      const header = lines[0].split(',').map(h => h.trim());
      const imgIdx = header.indexOf('image');
      if (imgIdx === -1) {
        needed += Math.max(0, lines.length - 1);
        continue;
      }
      for (let i = 1; i < lines.length; i++) {
        const parts = lines[i].split(',');
        const img = (parts[imgIdx] || '').trim();
        if (!img) { needed++; continue; }
        if (img.startsWith('thumbnails/')) {
          const thumbPath = path.join(THUMB_DIR, img.replace('thumbnails/', ''));
          if (!fs.existsSync(thumbPath)) needed++;
        }
      }
    }
    return needed;
  } catch {
    return 0;
  }
}

function countThumbnails() {
  try {
    return fs.readdirSync(THUMB_DIR).filter(f => /\.(jpg|jpeg|png|webp)$/i.test(f)).length;
  } catch {
    return 0;
  }
}

function getCategories() {
  try {
    return fs.readdirSync(DATA_DIR)
      .filter(f => f.endsWith('.csv'))
      .sort((a, b) => {
        const priority = name => name.startsWith('case.') || name.startsWith('gpu.') ? 0 : 1;
        const pa = priority(a), pb = priority(b);
        if (pa !== pb) return pa - pb;
        return a.localeCompare(b);
      })
      .map(f => ({
        file: f,
        name: f.replace('.csv', ''),
        items: 0,
        withImages: 0,
        needsImages: 0,
        localCached: 0,
      }));
  } catch {
    return [];
  }
}

function enrichCategoryStats(categories) {
  const thumbFiles = new Set();
  try {
    for (const f of fs.readdirSync(THUMB_DIR)) {
      if (/\.(jpg|jpeg|png|webp)$/i.test(f)) thumbFiles.add(f);
    }
  } catch {}

  for (const cat of categories) {
    try {
      const content = fs.readFileSync(path.join(DATA_DIR, cat.file), 'utf-8');
      const lines = content.split(/\r?\n/).filter(l => l.trim());
      if (lines.length < 2) continue;
      const header = lines[0].split(',').map(h => h.trim());
      const imgIdx = header.indexOf('image');
      cat.items = lines.length - 1;

      for (let i = 1; i < lines.length; i++) {
        const parts = lines[i].split(',');
        if (imgIdx !== -1) {
          const img = (parts[imgIdx] || '').trim();
          if (img) {
            cat.withImages++;
            if (img.startsWith('thumbnails/')) {
              const basename = img.replace('thumbnails/', '');
              if (thumbFiles.has(basename)) cat.localCached++;
            }
          } else {
            cat.needsImages++;
          }
        } else {
          cat.needsImages++;
        }
      }
    } catch {}
  }
  return categories;
}

function generateDashboardHTML() {
  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>PC Builder - AIO Update</title>
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
.restart-btn{padding:6px 18px;border-radius:20px;border:1px solid #30363d;background:#21262d;color:#c9d1d9;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s ease}
.restart-btn:hover{background:#30363d;border-color:#58a6ff;color:#58a6ff}
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
<h1>&#9881; AIO Update <small id="schedInfo"></small></h1>
<div class="top-bar">
  <div class="live-indicator">
    <span class="live-dot red" id="liveDot"></span>
    <span id="liveLabel">STOPPED</span>
    <span style="color:#8b949e;font-weight:400;font-size:12px" id="pidLabel"></span>
  </div>
  <button class="restart-btn" id="restartBtn" onclick="restartUpdate()">&#8635; Restart</button>
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
    <div class="bar-container"><div class="bar" id="progressBar" style="width:0%">0%</div></div>
    <div class="stat-row"><span class="stat-label">Items</span><span class="stat-value" id="itemsInfo">-</span></div>
  </div>
  <div class="card">
    <h3>&#128247; Images</h3>
    <div class="stat-row"><span class="stat-label">Needed</span><span class="stat-value yellow" id="imagesNeeded">0</span></div>
    <div class="stat-row"><span class="stat-label">Found</span><span class="stat-value green" id="imagesFound">0</span></div>
    <div class="stat-row"><span class="stat-label">Failed</span><span class="stat-value red" id="imagesFailed">0</span></div>
    <div class="stat-row"><span class="stat-label">Skipped (had)</span><span class="stat-value blue" id="imagesSkipped">0</span></div>
    <div class="stat-row"><span class="stat-label">Total on Disk</span><span class="stat-value magenta" id="thumbnailsOnDisk">0</span></div>
    <div class="stat-row" style="border-bottom:none">
      <span class="stat-label">Last Check</span>
      <span class="stat-value" id="lastProgressTime" style="font-size:11px;color:#8b949e">-</span>
    </div>
    <div class="stat-row" style="border-bottom:none">
      <span class="stat-label">Vercel Deploy</span>
      <span class="stat-value" id="deployTime" style="font-size:11px;color:#58a6ff">-</span>
    </div>

  </div>
</div>
<div class="card">
  <h3>&#128202; Categories</h3>
  <table>
    <thead><tr>
      <th>Category</th>
      <th>Items</th>
      <th>With Img</th>
      <th>Needs Img</th>
      <th>Cached</th>
      <th>Progress</th>
    </tr></thead>
    <tbody id="catTable"></tbody>
  </table>
</div>
<div class="card mt-16" style="border-color:#30363d">
  <h3>&#9000; Live Console <span style="font-weight:400;text-transform:none;color:#8b949e;font-size:11px" id="logCount">(0 lines)</span></h3>
  <div style="display:flex;justify-content:space-between;margin-bottom:6px">
    <span style="color:#8b949e;font-size:11px">&#9654; Streaming live actions</span>
    <span style="color:#8b949e;font-size:11px">PID: <span id="pidDisplay" style="color:#58a6ff">-</span></span>
  </div>
  <div class="log-box" id="logBox"></div>
</div>
<script>
const POLL = 2000;
let lastLines = 0;
async function restartUpdate() {
  const btn = document.getElementById('restartBtn');
  btn.disabled = true; btn.textContent = '...';
  try { const r = await fetch('/restart',{method:'POST'}); const d = await r.json(); btn.textContent = d.ok ? '&#8635; Restarting...' : '&#8635; Failed'; setTimeout(()=>{btn.disabled=false;btn.textContent='&#8635; Restart'},2000); } catch { btn.textContent = '&#8635; Failed'; setTimeout(()=>{btn.disabled=false;btn.textContent='&#8635; Restart'},2000); }
}
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
    document.getElementById('schedText').textContent = d.status==='running'?'Processing...':d.status==='complete'?'All done':d.status==='error'?(d.phase||'Error'):d.status==='frozen'?'Frozen':'Idle';
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
    document.getElementById('imagesNeeded').textContent = d.imagesNeeded||0;
    document.getElementById('imagesFound').textContent = d.imagesFound||0;
    document.getElementById('imagesFailed').textContent = d.imagesFailed||0;
    document.getElementById('imagesSkipped').textContent = d.imagesSkipped||0;
    document.getElementById('thumbnailsOnDisk').textContent = d.thumbnailsOnDisk||0;
    document.getElementById('lastProgressTime').textContent = d.lastProgressTime?new Date(d.lastProgressTime).toLocaleString():'-';
    document.getElementById('deployTime').textContent = d.lastDeployTime?new Date(d.lastDeployTime).toLocaleString():'-';
    document.getElementById('schedInfo').textContent = d.lastSuccess ? 'Last: '+new Date(d.lastSuccess).toLocaleDateString() : '';
    if (d.categories && d.categories.length) {
      let html = '';
      for (const c of d.categories) {
        const pct2 = c.items > 0 ? Math.round(((c.localCached||0)/c.items)*100) : 0;
        html += '<tr><td class="cat-name">'+c.name+'</td><td class="text-right">'+(c.items||0)+'</td><td class="text-right">'+(c.withImages||0)+'</td><td class="text-right'+(c.needsImages>0?' yellow':'')+'">'+(c.needsImages||0)+'</td><td class="text-right'+(c.localCached>0?' green':'')+'">'+(c.localCached||0)+'</td><td style="min-width:100px"><div class="mini-bar"><div class="mini-fill '+(pct2===100?'green':'')+'" style="width:'+pct2+'%"></div></div></td></tr>';
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

async function startDashboard() {
  dashboardServer = http.createServer((req, res) => {
    if (req.url === '/restart' && req.method === 'POST') {
      handleManualRestart();
      res.writeHead(200, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' });
      res.end(JSON.stringify({ ok: true, status: state.status }));
      return;
    }

    if (req.url === '/status') {
      try {
        const logLines = [];
        try {
          const lf = fs.readFileSync(LOG_FILE, 'utf-8');
          logLines.push(...lf.split(/\r?\n/).filter(Boolean));
        } catch {}
        try {
          const uf = fs.readFileSync(UPDATE_ALL_LOG, 'utf-8');
          const ulines = uf.split(/\r?\n/).filter(Boolean);
          const existing = new Set(logLines.map(l => l.replace(/^\[UPDATE\] /, '').replace(/^\[UPDATE-ALL\] /, '').substring(0, 60)));
          for (const l of ulines) {
            const key = l.replace(/^\[UPDATE\] /, '').replace(/^\[UPDATE-ALL\] /, '').substring(0, 60);
            if (!existing.has(key)) logLines.push('[UPDATE-ALL] ' + l);
          }
        } catch {}

        const categories = enrichCategoryStats(getCategories());
        const thumbnailsOnDisk = countThumbnails();

        let progressFile = {};
        for (const fp of [UPDATE_ALL_PROGRESS, MASTER_PROGRESS]) {
          try { if (fs.existsSync(fp)) { progressFile = JSON.parse(fs.readFileSync(fp, 'utf-8')); break; } } catch {}
        }

        const phase = progressFile.currentPhase || progressFile.currentCategory || state.phase || '';
        const imagesFound = progressFile.imagesFound || state.imagesFound || 0;
        const imagesFailed = progressFile.imagesFailed || state.imagesFailed || 0;
        const imagesSkipped = progressFile.imagesSkipped || state.imagesSkipped || 0;
        const imagesDone = categories.reduce((s, c) => s + (c.localCached || 0), 0);
        const imagesNeeded = categories.reduce((s, c) => s + (c.needsImages || 0), 0);
        const progressDenom = imagesDone + imagesNeeded;
        const pct = progressDenom > 0 ? (imagesDone / progressDenom) * 100 : 100;

        let etaFormatted = '-';
        if (progressFile.latestThumbnail) state.latestThumbnail = progressFile.latestThumbnail;
        if (!state.latestThumbnail) {
          try {
            const files = fs.readdirSync(THUMB_DIR).filter(f => /\.(jpg|jpeg|png|webp)$/i.test(f)).sort((a, b) => fs.statSync(path.join(THUMB_DIR, b)).mtimeMs - fs.statSync(path.join(THUMB_DIR, a)).mtimeMs);
            if (files.length > 0) state.latestThumbnail = `thumbnails/${files[0]}`;
          } catch {}
        }
        const elapsedSec = progressFile.elapsed || (progressFile.startTime ? (Date.now() - progressFile.startTime) / 1000 : 0);
        const elapsedMin = elapsedSec / 60;
        if (progressFile.imagesFound > 0 && imagesNeeded > 0 && elapsedMin > 0) {
          const rate = progressFile.imagesFound / elapsedMin;
          const etaMin = imagesNeeded / rate;
          if (etaMin >= 60) {
            etaFormatted = `${Math.floor(etaMin / 60)}h ${Math.floor(etaMin % 60)}m`;
          } else {
            etaFormatted = `${Math.floor(etaMin)}m ${Math.floor((etaMin % 1) * 60)}s`;
          }
        }

        const data = {
          status: state.status || progressFile.status || 'idle',
          phase,
          phaseNumber: progressFile.currentPhaseNumber ?? state.phaseNumber ?? 0,
          category: progressFile.currentCategory || state.category || null,
          pid: state.pid,
          imagesFound,
          imagesFailed,
          imagesSkipped,
          imagesNeeded,
          itemsProcessed: imagesDone,
          itemsTotal: progressDenom,
          progressPct: pct,
          etaFormatted,
          restartCount: state.restartCount || 0,
          currentItem: progressFile.currentItem || '',
          latestThumbnail: (progressFile.latestThumbnail || state.latestThumbnail || ''),
          batchDone: progressFile.batchDone || 0,
          batchTotal: progressFile.batchTotal || 0,
          elapsedFormatted: progressFile.elapsedFormatted || `${Math.floor(((Date.now() - (progressFile.startTime || Date.now())) / 1000) / 60)}m ${Math.floor(((Date.now() - (progressFile.startTime || Date.now())) / 1000) % 60)}s`,
          lastProgressTime: state.lastProgressTime,
          lastDeployTime: state.lastDeployTime,
          lastSuccess: state.lastSuccess,
          categories,
          thumbnailsOnDisk,
          logLines,
          canRestart: true,
          runIntervalDays: RUN_INTERVAL_DAYS,
        };
        res.writeHead(200, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' });
        res.end(JSON.stringify(data));
      } catch {
        const categories = enrichCategoryStats(getCategories());
        const ciDone = categories.reduce((s, c) => s + (c.localCached || 0), 0);
        const ciNeeded = categories.reduce((s, c) => s + (c.needsImages || 0), 0);
        const ciDenom = ciDone + ciNeeded;
        let catchEta = '-';
        const data = {
          status: state.status || 'idle',
          phase: state.phase,
          category: state.category || null,
          pid: state.pid,
          etaFormatted: catchEta,
          imagesFound: state.imagesFound || 0,
          imagesFailed: state.imagesFailed || 0,
          imagesSkipped: state.imagesSkipped || 0,
          imagesNeeded: ciNeeded,
          itemsProcessed: ciDone,
          itemsTotal: ciDenom,
          progressPct: ciDenom > 0 ? (ciDone / ciDenom) * 100 : 100,
          restartCount: state.restartCount || 0,
          currentItem: '',
          batchDone: 0,
          batchTotal: 0,
          elapsedFormatted: '0m 0s',
          lastProgressTime: state.lastProgressTime,
          lastSuccess: state.lastSuccess,
          categories,
          thumbnailsOnDisk: countThumbnails(),
          logLines: [],
          canRestart: true,
          runIntervalDays: RUN_INTERVAL_DAYS,
        };
        res.writeHead(200, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' });
        res.end(JSON.stringify(data));
      }
    } else if (req.url === '/dashboard') {
      res.writeHead(200, { 'Content-Type': 'text/html' });
      try { res.end(generateDashboardHTML()); } catch { res.end('<h1>Dashboard loading...</h1>'); }
    } else if (req.url.startsWith('/thumbnails/')) {
      const thumbPath = path.join(ROOT, 'public', decodeURIComponent(req.url));
      try {
        if (fs.existsSync(thumbPath)) {
          const ext = path.extname(thumbPath).toLowerCase();
          const mime = { '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.png': 'image/png', '.webp': 'image/webp' };
          res.writeHead(200, { 'Content-Type': mime[ext] || 'image/jpeg', 'Cache-Control': 'max-age=5' });
          res.end(fs.readFileSync(thumbPath));
        } else {
          res.writeHead(404); res.end();
        }
      } catch { res.writeHead(500); res.end(); }
    } else {
      res.writeHead(302, { Location: '/dashboard' });
      res.end();
    }
  });
  dashboardServer.listen(DASHBOARD_PORT, () => {
    log(`Dashboard: http://localhost:${DASHBOARD_PORT}`);
    try {
      execSync(`start /max http://localhost:${DASHBOARD_PORT}`, { shell: 'cmd.exe', timeout: 3000 });
    } catch {}
  });
}

function stopDashboard() {
  if (dashboardServer) {
    dashboardServer.close();
    dashboardServer = null;
  }
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

  // Detect category by column signature (PCPP exports have date-based filenames)
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

const PRICE_RETAILERS = {
  'amazon.co.uk': (q) => `https://www.amazon.co.uk/s?k=${encodeURIComponent(q)}&i=computers&rh=p_6%3AA3P5ROKF5B19Y3`,
  'scan.co.uk': (q) => `https://www.scan.co.uk/search#q=${encodeURIComponent(q)}`,
  'overclockers.co.uk': (q) => `https://www.overclockers.co.uk/search?search=${encodeURIComponent(q)}`,
  'box.co.uk': (q) => `https://www.box.co.uk/search?q=${encodeURIComponent(q)}`,
  'cclonline.com': (q) => `https://www.cclonline.com/catalogsearch/result/?q=${encodeURIComponent(q)}`,
  'novatech.co.uk': (q) => `https://www.novatech.co.uk/search/?q=${encodeURIComponent(q)}`,
  'awd-it.co.uk': (q) => `https://www.awd-it.co.uk/catalogsearch/result/?q=${encodeURIComponent(q)}`,
};

const CATEGORY_MIN_PRICE = {
  cpu: 30, gpu: 50, motherboard: 40, ram: 10, cooler: 10,
  storage: 15, 'power-supply': 20, case: 20, 'case-fan': 3,
  monitor: 80, keyboard: 10, mouse: 5, webcam: 15, speakers: 10,
  headphones: 10, 'sound-card': 20, 'optical-drive': 10,
  'thermal-paste': 2, ups: 30, os: 20,
  'external-hard-drive': 15, 'fan-controller': 10,
  'wired-network-card': 10, 'wireless-network-card': 10,
  'case-accessory': 5,
};

const CATEGORY_MAX_PRICE = {
  cpu: 6000, gpu: 3000, motherboard: 1500, ram: 800, cooler: 500,
  storage: 800, 'power-supply': 600, case: 800, 'case-fan': 80,
  monitor: 5000, keyboard: 500, mouse: 300, webcam: 300, speakers: 2000,
  headphones: 2000, 'sound-card': 800, 'optical-drive': 200,
  'thermal-paste': 30, ups: 2000, os: 400,
  'external-hard-drive': 600, 'fan-controller': 100,
  'wired-network-card': 200, 'wireless-network-card': 200,
  'case-accessory': 100,
};

function isValidPrice(price, csvFile) {
  if (!price || isNaN(price) || price <= 0) return false;
  const cat = csvFile ? csvFile.replace('.csv', '') : '';
  const min = CATEGORY_MIN_PRICE[cat] || 1;
  const max = CATEGORY_MAX_PRICE[cat] || 10000;
  return price >= min && price <= max;
}

function isUnavailable(html) {
  const unavailPatterns = [
    /currently\s+unavailable/i,
    /out\s+of\s+stock/i,
    /not\s+currently\s+available/i,
    /we\s+don't\s+know\s+when/i,
    /this\s+product\s+is\s+no\s+longer/i,
    /discontinued/i,
    /price\s+not\s+available/i,
  ];
  return unavailPatterns.some(p => p.test(html));
}

async function fetchWithTimeout(url, timeout = 8000) {
  const controller = new AbortController();
  const id = setTimeout(() => controller.abort(), timeout);
  try {
    const res = await fetch(url, { signal: controller.signal, headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', 'Accept-Language': 'en-GB,en;q=0.9' } });
    clearTimeout(id); return res;
  } catch (e) { clearTimeout(id); throw e; }
}

async function getBrowser() {
  if (browserInstance && browserInstance.connected) return browserInstance;
  log('  [PUPPETEER] Launching browser...');
  browserInstance = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--disable-web-security'],
    defaultViewport: { width: 1366, height: 768 },
  });
  log('  [PUPPETEER] Browser launched');
  return browserInstance;
}

async function puppeteerScrape(url, opts = {}) {
  const now = Date.now();
  const wait = PUPPETEER_DELAY_MS - (now - lastPuppeteerTime);
  if (wait > 0) await new Promise(r => setTimeout(r, wait));

  let browser;
  let page;
  try {
    browser = await getBrowser();
    page = await browser.newPage();
    lastPuppeteerTime = Date.now();

    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-GB,en;q=0.9' });

    const waitUntil = opts.waitUntil || 'networkidle2';
    await page.goto(url, { waitUntil, timeout: 30000 });

    if (opts.waitForSelector) {
      try {
        await page.waitForSelector(opts.waitForSelector, { timeout: 10000 });
      } catch {
        log(`  [PUPPETEER] Selector "${opts.waitForSelector}" not found, continuing with current content`);
      }
    }

    await new Promise(r => setTimeout(r, opts.delay || 1500));

    const html = await page.content();
    return { html };
  } catch (e) {
    log(`  [PUPPETEER] Error scraping ${url}: ${e.message}`);
    throw e;
  } finally {
    if (page) {
      try { await page.close(); } catch {}
    }
  }
}

async function closeBrowser() {
  if (browserInstance) {
    try { await browserInstance.close(); } catch {}
    browserInstance = null;
  }
}

async function startObscura() {
  if (!fs.existsSync(OBSCURA_PATH)) {
    log(`  [OBSCURA] Binary not found at ${OBSCURA_PATH}`);
    return false;
  }
  return true;
}

async function getObscuraBrowser() {
  throw new Error('Use obscuraScrape() with CLI fetch instead of CDP');
}

async function obscuraScrape(url, opts = {}) {
  if (!fs.existsSync(OBSCURA_PATH)) throw new Error('Obscura binary not found');

  const now = Date.now();
  const wait = PUPPETEER_DELAY_MS - (now - lastPuppeteerTime);
  if (wait > 0) await new Promise(r => setTimeout(r, wait));
  lastPuppeteerTime = Date.now();

  const timeoutSec = Math.min(Math.max(opts.timeout || 60, 30), 120);
  const waitSec = Math.min(Math.max(opts.delay || 15, 5), 60);

  const args = ['fetch', '--stealth', '--quiet', '--wait', String(waitSec), '--timeout', String(timeoutSec), '--dump', 'html', url];

  log(`  [OBSCURA] CLI fetch: ${url} (wait=${waitSec}s, timeout=${timeoutSec}s)`);
  try {
    const { execFileSync } = await import('child_process');
    const html = execFileSync(OBSCURA_PATH, args, {
      timeout: (timeoutSec + waitSec + 30) * 1000,
      maxBuffer: 50 * 1024 * 1024,
      encoding: 'utf-8',
      windowsHide: true,
    });
    log(`  [OBSCURA] Got ${html.length} chars`);
    return { html };
  } catch (e) {
    const stderr = e.stderr || '';
    const stdout = e.stdout || '';
    const output = stdout + stderr;
    if (output.includes('Just a moment') || output.includes('page loaded')) {
      log(`  [OBSCURA] Page loaded (may have Cloudflare challenge)`);
      return { html: output };
    }
    log(`  [OBSCURA] Error: ${e.message}`);
    throw e;
  }
}

async function closeObscura() {
  if (obscuraBrowser) {
    try { await obscuraBrowser.disconnect(); } catch {}
    obscuraBrowser = null;
  }
}

let playwrightBrowser = null;

async function getPlaywrightBrowser() {
  if (playwrightBrowser && playwrightBrowser.isConnected()) return playwrightBrowser;
  log('  [PLAYWRIGHT] Launching browser...');
  playwrightBrowser = await playwrightChromium.launch({
    headless: true,
    args: ['--disable-blink-features=AutomationControlled'],
  });
  log('  [PLAYWRIGHT] Browser launched');
  return playwrightBrowser;
}

async function closePlaywrightBrowser() {
  if (playwrightBrowser) {
    try { await playwrightBrowser.close(); } catch {}
    playwrightBrowser = null;
  }
}

async function playwrightScrape(url, opts = {}) {
  const now = Date.now();
  const wait = PUPPETEER_DELAY_MS - (now - lastPuppeteerTime);
  if (wait > 0) await new Promise(r => setTimeout(r, wait));

  let browser;
  let context;
  let page;
  try {
    browser = await getPlaywrightBrowser();
    context = await browser.newContext({
      userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
      locale: 'en-GB',
      viewport: { width: 1366, height: 768 },
    });
    page = await context.newPage();
    lastPuppeteerTime = Date.now();

    await page.goto(url, { waitUntil: opts.waitUntil || 'domcontentloaded', timeout: opts.timeout || 30000 });

    if (opts.waitForCloudflare) {
      for (let i = 0; i < 15; i++) {
        const title = await page.title();
        if (!title.includes('moment') && !title.includes('challenge')) break;
        log(`  [PLAYWRIGHT] Cloudflare challenge, waiting... (${i + 1}/15)`);
        await page.waitForTimeout(3000);
      }
    }

    if (opts.waitForSelector) {
      try {
        await page.waitForSelector(opts.waitForSelector, { timeout: opts.selectorTimeout || 10000 });
      } catch {
        log(`  [PLAYWRIGHT] Selector "${opts.waitForSelector}" not found, continuing`);
      }
    }

    await page.waitForTimeout(opts.delay || 1500);

    const html = await page.content();
    return { html };
  } catch (e) {
    log(`  [PLAYWRIGHT] Error scraping ${url}: ${e.message}`);
    throw e;
  } finally {
    if (page) { try { await page.close(); } catch {} }
    if (context) { try { await context.close(); } catch {} }
  }
}

const BROWSERLESS_TOKEN = process.env.BROWSERLESS_TOKEN || '';
const BROWSERLESS_FLEET = 'production-lon';

async function browserlessScrape(url, opts = {}) {
  if (!BROWSERLESS_TOKEN) throw new Error('BROWSERLESS_TOKEN not set');

  const timeout = opts.timeout || 120000;
  const proxy = opts.proxy || 'residential';
  const proxyCountry = opts.proxyCountry || 'gb';

  const queryParams = new URLSearchParams({
    timeout,
    proxy,
    proxyCountry,
    token: BROWSERLESS_TOKEN,
  }).toString();

  const unblockURL = `https://${BROWSERLESS_FLEET}.browserless.io/chromium/unblock?${queryParams}`;

  log(`  [BROWSERLESS] Unblocking ${url}`);
  const resp = await fetch(unblockURL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      url,
      content: true,
      cookies: false,
      screenshot: false,
    }),
  });

  if (!resp.ok) {
    const errText = await resp.text();
    throw new Error(`Browserless unblock ${resp.status}: ${errText}`);
  }

  const data = await resp.json();
  const html = data.content || '';
  if (!html) throw new Error('Browserless returned empty content');
  log(`  [BROWSERLESS] Got ${html.length} chars`);
  return { html };
}

function parseAmazonSearchHtml(html, searchName) {
  if (!html) return [];
  const results = [];
  const seen = new Set();

  const linkPattern = /href="(\/[^"]*\/dp\/([A-Z0-9]{10})[^"]*)"/gi;
  let m;
  while ((m = linkPattern.exec(html)) !== null) {
    if (seen.has(m[2])) continue;
    seen.add(m[2]);
    const asin = m[2];

    const startIdx = Math.max(0, m.index - 2000);
    const endIdx = Math.min(html.length, m.index + 3000);
    const context = html.substring(startIdx, endIdx);

    const priceMatch = context.match(/£([\d,]+\.?\d{2})/);
    const price = priceMatch ? parseFloat(priceMatch[1].replace(/,/g, '')) : null;

    const titleMatch = context.match(/class="a-size-?medium[^"]*"[^>]*>\s*<span[^>]*>([^<]+)/i) ||
                       context.match(/class="a-size-?base[^"]*"[^>]*>\s*<span[^>]*>([^<]+)/i) ||
                       context.match(/alt="([^"]{10,})"/i);
    const title = titleMatch ? titleMatch[1].trim().replace(/&amp;/g, '&') : null;

    const imgMatch = context.match(/src="(https:\/\/m\.media-amazon\.com\/images\/I\/[^"]+)"/i);
    const image = imgMatch ? imgMatch[1] : null;

    results.push({ price, asin, image, title });
  }

  if (results.length === 0) {
    const allAsins = [...html.matchAll(/\/dp\/([A-Z0-9]{10})/gi)].map(m => m[1]);
    const allPrices = [...html.matchAll(/£([\d,]+\.\d{2})/g)].map(m => parseFloat(m[1].replace(/,/g, '')));
    const uniqueAsins = [...new Set(allAsins)];
    for (let i = 0; i < Math.min(uniqueAsins.length, 5); i++) {
      const price = allPrices[i] || null;
      if (price && !seen.has(uniqueAsins[i])) {
        seen.add(uniqueAsins[i]);
        results.push({ price, asin: uniqueAsins[i], image: null, title: null });
      }
    }
  }
  return results;
}

function parseAmazonProductHtml(html) {
  if (!html) return { price: null, available: true, image: null, title: null };

  let price = null;
  const pricePatterns = [
    /class="a-price-whole">([\d,]+)<.*?class="a-price-fraction">(\d+)/s,
    /<span[^>]*class="a-offscreen"[^>]*>£([\d,]+\.?\d*)/,
    /"priceAmount":([\d.]+)/,
    /id="priceblock_ourprice"[^>]*>£([\d,]+\.?\d{2})/,
    /id="priceblock_dealprice"[^>]*>£([\d,]+\.?\d{2})/,
  ];
  for (const p of pricePatterns) {
    const m = html.match(p);
    if (m) {
      const priceStr = m[2] ? `${m[1]}.${m[2]}` : m[1];
      price = parseFloat(priceStr.replace(/,/g, ''));
      if (price > 0) break;
    }
  }

  let available = true;
  if (/currently\s+unavailable/i.test(html)) available = false;
  if (/out\s+of\s+stock/i.test(html)) available = false;
  if (/we\s+don.t\s+know\s+when/i.test(html)) available = false;
  if (/this\s+product\s+is\s+no\s+longer/i.test(html)) available = false;

  let image = null;
  const imgPatterns = [
    /"hiRes":"(https?:\/\/[^"]+)"/i,
    /"large":"(https?:\/\/[^"]+)"/i,
    /id="landingImage"[^>]*src="(https?:\/\/[^"]+)"/i,
    /data-old-hires="(https?:\/\/[^"]+\.jpg)"/i,
    /https?:\/\/m\.media-amazon\.com\/images\/I\/[A-Za-z0-9]+(?:\._[A-Z0-9_]+)?\.(?:jpg|png|webp)/i,
  ];
  for (const p of imgPatterns) {
    const m = html.match(p);
    if (m) { image = m[1] || m[0]; break; }
  }

  return { price, available, image, title: null };
}

const PCPP_CATEGORY_MAP = {
  cpu: 'https://uk.pcpartpicker.com/products/cpu/',
  cooler: 'https://uk.pcpartpicker.com/products/cpu-cooler/',
  motherboard: 'https://uk.pcpartpicker.com/products/motherboard/',
  ram: 'https://uk.pcpartpicker.com/products/memory/',
  gpu: 'https://uk.pcpartpicker.com/products/video-card/',
  storage: 'https://uk.pcpartpicker.com/products/internal-hard-drive/',
  'power-supply': 'https://uk.pcpartpicker.com/products/power-supply/',
  case: 'https://uk.pcpartpicker.com/products/case/',
  'case-fan': 'https://uk.pcpartpicker.com/products/case-fan/',
  monitor: 'https://uk.pcpartpicker.com/products/monitor/',
  keyboard: 'https://uk.pcpartpicker.com/products/keyboard/',
  mouse: 'https://uk.pcpartpicker.com/products/mouse/',
  webcam: 'https://uk.pcpartpicker.com/products/webcam/',
  speakers: 'https://uk.pcpartpicker.com/products/speakers/',
  headphones: 'https://uk.pcpartpicker.com/products/headphones/',
  'external-hard-drive': 'https://uk.pcpartpicker.com/products/external-hard-drive/',
  'sound-card': 'https://uk.pcpartpicker.com/products/sound-card/',
};

const pcppIndexCache = {};

function parsePCPPHtml(html) {
  const products = [];
  const rows = html.match(/<tr[^>]*class="[^"]*tr__product[^"]*"[^>]*>[\s\S]*?<\/tr>/gi) || [];
  for (const row of rows) {
    const nameMatch = row.match(/td__nameWrapper[^>]*>\s*<p>([^<]+)/i);
    const altMatch = row.match(/alt="([^"]{5,})"/i);
    const priceMatch = row.match(/£([\d,]+\.?\d{2})/);
    const imgMatch = row.match(/src="(https:\/\/cdna\.pcpartpicker\.com\/[^"]+)"/i);
    const linkMatch = row.match(/href="(https:\/\/uk\.pcpartpicker\.com\/product\/[^"]+)"/i);
    const name = nameMatch ? nameMatch[1].trim() : (altMatch ? altMatch[1].trim() : null);
    const price = priceMatch ? parseFloat(priceMatch[1].replace(/,/g, '')) : null;
    if (name && price && price > 0) {
      products.push({ name, price, image: imgMatch ? imgMatch[1] : null, link: linkMatch ? linkMatch[1] : null });
    }
  }
  if (products.length > 0) return products;

  const productLinks = [...html.matchAll(/href="(https:\/\/uk\.pcpartpicker\.com\/product\/[^"]+)"/gi)];
  const seen = new Set();
  for (const linkMatch of productLinks) {
    const link = linkMatch[1];
    if (seen.has(link)) continue;
    seen.add(link);
    const startIdx = Math.max(0, linkMatch.index - 2000);
    const endIdx = Math.min(html.length, linkMatch.index + 2000);
    const context = html.substring(startIdx, endIdx);
    const nameMatch = context.match(/alt="([^"]{10,})"/i) || context.match(/<p[^>]*>([^<]{10,})<\/p>/i);
    const priceMatch = context.match(/£([\d,]+\.?\d{2})/);
    const imgMatch = context.match(/src="(https:\/\/cdna\.pcpartpicker\.com\/[^"]+)"/i);
    const name = nameMatch ? nameMatch[1].trim() : null;
    const price = priceMatch ? parseFloat(priceMatch[1].replace(/,/g, '')) : null;
    if (name && price && price > 0) {
      products.push({ name, price, image: imgMatch ? imgMatch[1] : null, link });
    }
  }
  return products;
}

function parsePCPPProductPage(html) {
  const result = { name: null, price: null, image: null, available: false, retailers: [] };
  if (!html || html.length < 1000) return result;

  const titleMatch = html.match(/<title>([^<]+)<\/title>/i);
  if (titleMatch) {
    const rawTitle = titleMatch[1].replace(/\s*-\s*PCPartPicker\s*$/i, '').trim();
    result.name = rawTitle;
  }

  const priceMatches = [...html.matchAll(/£([\d,]+\.?\d{2})/g)];
  if (priceMatches.length > 0) {
    const prices = priceMatches.map(m => parseFloat(m[1].replace(/,/g, ''))).filter(p => p > 0);
    if (prices.length > 0) result.price = Math.min(...prices);
  }

  const imgPatterns = [
    /src="(https:\/\/cdna\.pcpartpicker\.com\/static\/img\/[^"]+)"/i,
    /src="(https:\/\/[^"]*pcpartpicker[^"]*\.(?:jpg|png|webp))/i,
  ];
  for (const p of imgPatterns) {
    const m = html.match(p);
    if (m && !m[1].includes('vendor-logos') && !m[1].includes('icon')) {
      result.image = m[1]; break;
    }
  }

  const availMatches = [...html.matchAll(/class="td__availability[^"]*"[^>]*>([^<]+)</gi)];
  result.available = availMatches.some(m => /in\s*stock/i.test(m[1]));

  const rowPattern = /<tr>\s*<td class="td__logo">([\s\S]*?)<\/tr>/gi;
  let rowMatch;
  while ((rowMatch = rowPattern.exec(html)) !== null) {
    const rowHtml = rowMatch[1] + (html.substring(rowMatch.index, rowMatch.index + rowMatch[0].length));
    const retailerMatch = rowHtml.match(/alt="([^"]+)"/);
    const priceMatch = rowHtml.match(/£([\d,]+\.?\d{2})/);
    const availMatch = rowHtml.match(/class="td__availability[^"]*"[^>]*>([^<]+)</);
    const urlMatch = rowHtml.match(/href="(https?:\/\/[^"]+)"/);
    if (retailerMatch && priceMatch) {
      result.retailers.push({
        retailer: retailerMatch[1].trim(),
        price: parseFloat(priceMatch[1].replace(/,/g, '')),
        available: availMatch ? /in\s*stock/i.test(availMatch[1]) : false,
        url: urlMatch ? urlMatch[1] : null,
      });
    }
  }

  return result;
}

async function buildPCPPIndex(csvFile) {
  const cat = csvFile.replace('.csv', '');
  if (pcppIndexCache[cat]) return pcppIndexCache[cat];

  const url = PCPP_CATEGORY_MAP[cat];
  if (!url) { pcppIndexCache[cat] = []; return []; }

  log(`  [PCPP] Building index for ${cat} from ${url}`);
  try {
    let products = [];

    if (fs.existsSync(OBSCURA_PATH)) {
      log(`  [PCPP] Using Obscura for ${cat}...`);
      try {
        const data = await obscuraScrape(url, { delay: 20000 });
        products = parsePCPPHtml(data.html || '');
        if (products.length === 0 && !data.html.includes('Just a moment')) {
          log(`  [PCPP] ${cat}: Obscura loaded page but API blocked (403), category listing unavailable`);
        } else if (products.length === 0) {
          log(`  [PCPP] ${cat}: Cloudflare still blocking via Obscura`);
        }
      } catch (e) {
        log(`  [PCPP] ${cat}: Obscura error: ${e.message}, falling back...`);
      }
    }

    if (products.length === 0 && BROWSERLESS_TOKEN) {
      log(`  [PCPP] Trying Browserless for ${cat}...`);
      try {
        const data = await browserlessScrape(url);
        products = parsePCPPHtml(data.html || '');
      } catch (e) {
        log(`  [PCPP] ${cat}: Browserless error: ${e.message}`);
      }
    }

    if (products.length === 0) {
      log(`  [PCPP] ${cat}: No index available (Obscura/Browserless both failed or API blocked)`);
    }

    pcppIndexCache[cat] = products;
    log(`  [PCPP] ${cat}: ${products.length} products indexed`);
    return products;
  } catch (e) {
    log(`  [PCPP] ${cat} error: ${e.message}`);
    pcppIndexCache[cat] = [];
    return [];
  }
}

function lookupPCPPIndex(name, csvFile) {
  const cat = csvFile.replace('.csv', '');
  const index = pcppIndexCache[cat] || [];
  if (index.length === 0) return null;

  let best = null;
  let bestScore = 0;
  for (const item of index) {
    const score = nameSimilarity(name, item.name);
    if (score > bestScore && score >= 0.5) {
      bestScore = score;
      best = item;
    }
  }
  return best;
}

const MODERN_PATTERNS = {
  cpu: /(?:ryzen\s*[3579]\s*[456789]\d{3}|ryzen\s*[3579]\s*[456789]00x|core\s*i[3579]\s*1[2-5]\d{3}|core\s*i[3579]\s*1[2-5]00[fk]?|athlon\s*(?:3|4)\d{3}|epyc\s*9\d{3}|threadripper\s*7\d{3})/i,
  gpu: /(?:rtx\s*[345]\d{2}|rx\s*[67]\d{0,2}\d{2}\d?|arc\s*a[0-9]{3}|geforce\s*(?:rtx|gtx)\s*[345]\d{2}|radeon\s*rx\s*[67]\d)/i,
  motherboard: /(?:b[56]50|x[56]70|z[67]90|b[67]0|h[67]0|x870|z890|b850|a620|trx40|sWRX8)/i,
  ram: /(?:ddr[45]\s*\d{4,}|ddr[45]$)/i,
  cooler: /(?:240|280|360|420)\s*(?:mm|rad)/i,
  storage: /(?:nvme|ssd|m\.2|pcie\s*gen\s*[45]|gen\s*4|gen\s*5)/i,
};

const DISCONTINUED_PATTERNS = [
  /\b(?:fx|phenom|athlon\s*ii|sempron|opteron|turion)\b/i,
  /\b(?:core\s*i[3579]\s*[1-4]\d{3}|pentium\s*(?:g|j)\d{3}|celeron\s*g\d{3})\b/i,
  /\b(?:gtx\s*[12]\d{2}|gt\s*[12]\d{2}|gts\s*450|gtx\s*6\d{2}|gtx\s*7\d{2}|gtx\s*9\d{2}|gtx\s*10[01]\d)\b/i,
  /\b(?:hd\s*[5678]\d{3|r[79]\s*[23]\d{2})\b/i,
  /\b(?:ddr[23]|sdr|rdram)\b/i,
  /\b(?:lga\s*(?:115[056]|2011|1366|775|478))\b/i,
  /\b(?:socket\s*(?:AM[234]|FM[12]|TR4|sTRX4|sWRX8))\b/i,
  /\b(?:z\d{3}|h\d{3}[0-9]?|b\d{3}[0-9]?)\s*(?:201[0-9]|2020)\b/i,
  /\b(?:radeon\s*rx\s*5[0-9]{2}|radeon\s*rx\s*v\d{2})\b/i,
  /\b(?:geforce\s*gtx\s*16[0-5]\d)\b/i,
  /(?:eol|end.of.life|discontinued|legacy|obsolete)/i,
];

function isDiscontinued(name) {
  if (!name) return true;
  return DISCONTINUED_PATTERNS.some(p => p.test(name));
}

function isModernPart(name) {
  if (!name || isDiscontinued(name)) return false;
  const lower = name.toLowerCase();
  for (const [cat, pattern] of Object.entries(MODERN_PATTERNS)) {
    if (pattern.test(lower)) return true;
  }
  if (/\b(20[2-3]\d)\b/.test(name)) return true;
  if (/\b(ryzen|core\s*i[3579]|rtx|rx\s*[67]|ddr[45]|nvme|pcie)\b/i.test(name)) return true;
  return false;
}

function extractAmazonImage(html) {
  const patterns = [
    /"hiRes":"(https?:\/\/[^"]+)"/i,
    /"large":"(https?:\/\/[^"]+)"/i,
    /id="landingImage"[^>]*src="(https?:\/\/[^"]+)"/i,
    /data-old-hires="(https?:\/\/[^"]+\.jpg)"/i,
    /https?:\/\/m\.media-amazon\.com\/images\/I\/[A-Za-z0-9]+(?:\._[A-Z0-9_]+)?\.(?:jpg|png|webp)/i,
  ];
  for (const p of patterns) {
    const m = html.match(p);
    if (m) return m[1] || m[0];
  }
  return null;
}

function nameSimilarity(a, b) {
  const wordsA = new Set(a.toLowerCase().replace(/[^\w\s]/g, '').split(/\s+/).filter(w => w.length > 1));
  const wordsB = new Set(b.toLowerCase().replace(/[^\w\s]/g, '').split(/\s+/).filter(w => w.length > 1));
  let matches = 0;
  for (const w of wordsA) { if (wordsB.has(w)) matches++; }
  const unionSize = new Set([...wordsA, ...wordsB]).size;
  const jaccard = matches / Math.max(unionSize, 1);
  const digitsA = [...wordsA].filter(w => /\d{3,}/.test(w));
  const digitsB = [...wordsB].filter(w => /\d{3,}/.test(w));
  if (digitsA.length > 0 && digitsB.length > 0) {
    const numMatch = digitsA.some(d => digitsB.some(d2 => d2.startsWith(d) || d.startsWith(d2)));
    if (!numMatch && jaccard < 0.8) return 0;
  }
  return jaccard;
}

function findAmazonProductUrl(html) {
  const seen = new Set();
  const linkPattern = /href="(\/[^"]*\/dp\/([A-Z0-9]{10})[^"]*)"/gi;
  let m;
  while ((m = linkPattern.exec(html)) !== null) {
    if (!seen.has(m[2])) {
      seen.add(m[2]);
      return `https://www.amazon.co.uk${m[1]}`;
    }
  }
  return null;
}

function extractProductPagePrice(html) {
  const patterns = [
    /class="a-price-whole">([\d,]+)<.*?class="a-price-fraction">(\d+)/s,
    /<span[^>]*class="a-offscreen"[^>]*>£([\d,]+\.?\d*)/,
    /"priceAmount":([\d.]+)/,
  ];
  for (const p of patterns) {
    const m = html.match(p);
    if (m) {
      const priceStr = m[2] ? `${m[1]}.${m[2]}` : m[1];
      const price = parseFloat(priceStr.replace(/,/g, ''));
      if (price > 0) return price;
    }
  }
  return null;
}

function isProductAvailable(html) {
  if (/currently\s+unavailable/i.test(html)) return false;
  if (/out\s+of\s+stock/i.test(html)) return false;
  if (/we\s+don.t\s+know\s+when/i.test(html)) return false;
  if (/this\s+product\s+is\s+no\s+longer/i.test(html)) return false;
  return true;
}

async function scrapeRetailerPrices(name, csvFile) {
  const searchName = name.replace(/[^\w\s]/g, ' ').trim().substring(0, 100);
  const results = { prices: [], image: null, available: true };

  const pcppMatch = lookupPCPPIndex(name, csvFile);
  if (pcppMatch) {
    log(`  [PCPP] Match: ${pcppMatch.name} => £${pcppMatch.price}`);
    if (isValidPrice(pcppMatch.price, csvFile)) results.prices.push(pcppMatch.price);
    if (pcppMatch.image) results.image = pcppMatch.image;
    if (pcppMatch.link && fs.existsSync(OBSCURA_PATH)) {
      try {
        log(`  [OBSCURA] Scraping PCPP product page: ${pcppMatch.link}`);
        const pData = await obscuraScrape(pcppMatch.link, { delay: 25000 });
        const pResult = parsePCPPProductPage(pData.html);
        if (pResult.price && isValidPrice(pResult.price, csvFile)) {
          results.prices = [pResult.price];
        }
        if (pResult.image && !results.image) results.image = pResult.image;
        if (!pResult.available) results.available = false;
        if (pResult.retailers.length > 0) {
          log(`  [OBSCURA] PCPP retailers: ${pResult.retailers.map(r => `${r.retailer} £${r.price}`).join(', ')}`);
        }
      } catch (e) {
        log(`  [OBSCURA] PCPP product page error: ${e.message}`);
      }
    }
    return results;
  }

  const scraper = fs.existsSync(OBSCURA_PATH) ? obscuraScrape : puppeteerScrape;
  const scraperTag = fs.existsSync(OBSCURA_PATH) ? 'OBSCURA' : 'PUPPETEER';

  try {
    const searchUrl = PRICE_RETAILERS['amazon.co.uk'](searchName);
    log(`  [${scraperTag}] Scraping amazon.co.uk for "${searchName}"`);
    const data = await scraper(searchUrl, { delay: 3000 });
    const items = parseAmazonSearchHtml(data.html, searchName);
    if (items.length > 0) {
      let bestMatch = null;
      let bestScore = 0;
      for (const item of items) {
        const title = item.title || '';
        const score = nameSimilarity(searchName, title);
        if (score > bestScore && score >= 0.25) { bestScore = score; bestMatch = item; }
      }
      if (!bestMatch && items.length > 0) bestMatch = items[0];

      if (bestMatch) {
        if (bestMatch.price && isValidPrice(bestMatch.price, csvFile)) results.prices.push(bestMatch.price);
        if (bestMatch.image && !results.image) results.image = bestMatch.image;

        if (bestMatch.asin && bestScore < 0.5) {
          const productUrl = `https://www.amazon.co.uk/dp/${bestMatch.asin}`;
          try {
            const pData = await scraper(productUrl, { delay: 3000 });
            const pResult = parseAmazonProductHtml(pData.html);
            if (pResult.price && isValidPrice(pResult.price, csvFile)) {
              results.prices = [pResult.price];
            }
            if (pResult.image && !results.image) results.image = pResult.image;
            if (!pResult.available) results.available = false;
          } catch (e) {
            log(`    [${scraperTag}] Product page error: ${e.message}`);
          }
        }
      }
    }
  } catch (e) {
    log(`  [${scraperTag}] amazon.co.uk error: ${e.message}`);
  }

  return results;
}

async function scrapeMissingPrices() {
  log('\n=== Phase: Scrape Modern Parts (UK/GBP + Availability) ===');
  const csvFiles = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  const eligibleFiles = [];
  for (const file of csvFiles) {
    const csvPath = path.join(DATA_DIR, file);
    const csv = readCSV(csvPath);
    if (!csv) continue;
    const nameIdx = csv.header.indexOf('name');
    const priceIdx = csv.header.indexOf('price');
    if (nameIdx < 0 || priceIdx < 0) continue;
    eligibleFiles.push(file);
  }
  state.totalCategories = eligibleFiles.length;
  state.categoryIndex = 0;
  state.itemsProcessed = 0;
  state.itemsTotal = 0;
  writeProgress();

  let totalChecked = 0, totalUpdated = 0, totalModern = 0, totalOld = 0, totalDiscontinued = 0, totalUnavailable = 0;

  for (let fi = 0; fi < eligibleFiles.length; fi++) {
    const file = eligibleFiles[fi];
    state.categoryIndex = fi + 1;
    state.category = file.replace('.csv', '');
    writeProgress();

    const csvPath = path.join(DATA_DIR, file);
    const csv = readCSV(csvPath);
    if (!csv) continue;
    const nameIdx = csv.header.indexOf('name');
    const priceIdx = csv.header.indexOf('price');
    const imgIdx = csv.header.indexOf('image');

    await buildPCPPIndex(file);

    let updated = 0, modernCount = 0, oldCount = 0, discCount = 0, unavailCount = 0;
    const keptLines = [csv.lines[0]];
    for (let i = 1; i < csv.lines.length; i++) {
      const parts = parseCSVLine(csv.lines[i]);
      const name = (parts[nameIdx] || '').trim();
      if (!name) continue;

      if (isDiscontinued(name)) { discCount++; totalDiscontinued++; continue; }
      keptLines.push(csv.lines[i]);
    }

    let categoryItemsProcessed = 0;
    const categoryModernCount = keptLines.length - 1;

    for (let i = 1; i < keptLines.length; i++) {
      const parts = parseCSVLine(keptLines[i]);
      const name = (parts[nameIdx] || '').trim();
      if (!name) continue;

      const modern = isModernPart(name);
      if (!modern) { oldCount++; categoryItemsProcessed++; continue; }
      modernCount++;
      totalChecked++;
      categoryItemsProcessed++;

      state.itemsProcessed = totalChecked;
      state.progressPct = Math.min(100, (totalChecked / Math.max(categoryModernCount, 1)) * ((fi + 1) / eligibleFiles.length) * 100);
      if (categoryItemsProcessed % 5 === 0) writeProgress();

      const result = await scrapeRetailerPrices(name, file);
      let modified = false;

      if (!result.available) {
        log(`  [UNAVAIL] ${file.replace('.csv','')}: ${name}`);
        keptLines.splice(i, 1);
        i--;
        unavailCount++;
        totalUnavailable++;
        continue;
      }

      if (result.prices.length > 0) {
        const avgPrice = result.prices.reduce((a, b) => a + b, 0) / result.prices.length;
        if (isValidPrice(avgPrice, file)) {
          const oldPrice = parts[priceIdx] ? parseFloat(parts[priceIdx]) : 0;
          if (Math.abs(avgPrice - oldPrice) > 0.50) {
            parts[priceIdx] = avgPrice.toFixed(2);
            log(`  [PRICE] ${file.replace('.csv','')}: ${name} => £${avgPrice.toFixed(2)} (was £${oldPrice || '0'})`);
            modified = true;
          }
        } else {
          log(`  [SKIP] ${file.replace('.csv','')}: ${name} => £${avgPrice.toFixed(2)} out of range`);
        }
      }

      if (result.image && imgIdx >= 0) {
        const oldImg = (parts[imgIdx] || '').trim();
        if (!oldImg || oldImg === '""' || oldImg === '') {
          parts[imgIdx] = `"${result.image}"`;
          log(`  [IMAGE] ${file.replace('.csv','')}: ${name} => image added`);
          modified = true;
        }
      }

      if (modified) {
        keptLines[i] = parts.join(',');
        updated++;
        totalUpdated++;
      }
    }

    totalModern += modernCount;
    totalOld += oldCount;
    state.itemsTotal = totalModern + totalOld;
    writeProgress();

    if (discCount > 0 || updated > 0 || unavailCount > 0) {
      fs.writeFileSync(csvPath, keptLines.join('\n'), 'utf-8');
      log(`  ${file}: ${updated} refreshed, ${modernCount} modern, ${oldCount} old, ${discCount} disc, ${unavailCount} unavail`);
    } else {
      log(`  ${file}: 0 changed, ${modernCount} modern, ${oldCount} old`);
    }
  }
  log(`  Total: ${totalUpdated} refreshed, ${totalModern} modern, ${totalOld} old, ${totalDiscontinued} disc, ${totalUnavailable} unavail`);
}

async function runUpdateDirectly() {
  state.status = 'running';
  state.restartCount = (state.restartCount || 0) + 1;
  state.lastRun = Date.now();
  state.progressSnapshot = null;
  state.lastProgressTime = Date.now();
  state.pid = process.pid;
  saveState();
  writeProgress();

  log(`Running full update (attempt ${state.restartCount})...`);

  try {
    // Pre-phases: import data
    state.phase = 'Version Bump';
    state.phaseNumber = 0;
    writeProgress();
    bumpVersion();

    state.phase = 'Import Apify Data';
    state.phaseNumber = 0;
    writeProgress();
    await importApifyData();

    state.phase = 'Import PCPP CSVs';
    state.phaseNumber = 0;
    writeProgress();
    await importPCPPData();

    state.phase = 'Import Docyx Data';
    state.phaseNumber = 0;
    writeProgress();
    await importDocyxData();

    state.phase = 'Import Open Source Datasets';
    state.phaseNumber = 0;
    writeProgress();
    await importOpenSourceDatasets();

    state.phase = 'Scrape Missing Prices';
    state.phaseNumber = 0;
    writeProgress();
    await scrapeMissingPrices();

    // Run the main update-all pipeline
    await runUpdateAll();
    deployToVercel();
    state.status = 'complete';
    state.lastSuccess = Date.now();
    state.restartCount = 0;
    state.phase = 'All phases complete';
  } catch (err) {
    log(`Update failed: ${err.message}`);
    state.status = 'error';
    state.phase = `Error: ${err.message}`;
    await cleanupAll();
    if (state.restartCount < 10) {
      setTimeout(() => runUpdateDirectly(), 5000);
    }
  }
  saveState();
  writeProgress();
}

async function cleanupAll() {
  await closeBrowser().catch(() => {});
  await closePlaywrightBrowser().catch(() => {});
  await closeObscura().catch(() => {});
}

function deployToVercel() {
  try {
    log('Deploying to Vercel...');
    const result = execSync('vercel --prod --yes 2>&1', { timeout: 120000, cwd: ROOT, shell: 'cmd.exe' });
    const out = result.toString().trim();
    const lines = out.split(/\r?\n/).filter(l => l.trim());
    const deployUrl = lines.find(l => l.includes('https://')) || lines[lines.length - 1] || 'done';
    log(`  Vercel deploy: ${deployUrl}`);
    state.lastDeployTime = Date.now();
    saveState();
  } catch (err) {
    log(`  Vercel deploy failed: ${err.message}`);
  }
}

function startDeployTimer() {
  setTimeout(() => {
    deployToVercel();
    startDeployTimer();
  }, DEPLOY_INTERVAL);
}

function handleManualRestart() {
  log('Manual restart requested from dashboard');
  if (state.status === 'running') {
    log('Already running, ignoring restart request');
    return;
  }
  setTimeout(() => runUpdateDirectly(), 1000);
}

function checkNetwork() {
  try {
    execSync('ping -n 1 8.8.8.8', { timeout: 5000, stdio: 'pipe' });
    log('Network check: OK');
    return true;
  } catch {
    log('WARNING: Network unreachable (VPN may not be connected). Will retry...');
    return false;
  }
}

function healthCheck() {
  if (['completed', 'stopped', 'error', 'idle'].includes(state.status)) return;

  const now = Date.now();
  const progress = readProgress();
  if (!progress) {
    const stalledFor = now - state.lastProgressTime;
    if (stalledFor >= STALL_TIMEOUT && state.lastProgressTime > 0) {
      state.status = 'frozen';
      writeProgress();
      log(`WARNING: No progress file for ${Math.round(stalledFor / 1000 / 60)}m. Pipeline still running...`);
      state.status = 'running';
    }
    return;
  }

  const snapshot = JSON.stringify({
    imagesFound: progress.imagesFound || 0,
    imagesFailed: progress.imagesFailed || 0,
    overallProcessed: progress.overallProcessed || 0,
    currentPhase: progress.currentPhase || progress.currentCategory || '',
    status: progress.status || '',
  });

  state.phase = progress.currentPhase || progress.currentCategory || state.phase;
  state.phaseNumber = progress.currentPhaseNumber ?? state.phaseNumber ?? 0;
  state.category = progress.currentCategory || null;
  state.imagesFound = progress.imagesFound || 0;
  state.imagesFailed = progress.imagesFailed || 0;
  state.imagesSkipped = progress.imagesSkipped || 0;

  const categories = enrichCategoryStats(getCategories());
  const imagesDone = categories.reduce((s, c) => s + (c.localCached || 0), 0);
  const imagesNeeded = categories.reduce((s, c) => s + (c.needsImages || 0), 0);
  const overallDenom = imagesDone + imagesNeeded;
  state.itemsProcessed = imagesDone;
  state.itemsTotal = overallDenom;
  state.imagesNeeded = imagesNeeded;
  state.progressPct = overallDenom > 0 ? (imagesDone / overallDenom) * 100 : 100;

  if (state.progressSnapshot === null) {
    state.progressSnapshot = snapshot;
    state.lastProgressTime = now;
    state.status = 'running';
    writeProgress();
    log(`Health check: progress initialized (${state.itemsProcessed}/${state.itemsTotal} items, ${state.imagesFound} images)`);
  } else if (state.progressSnapshot !== snapshot) {
    state.progressSnapshot = snapshot;
    state.lastProgressTime = now;
    writeProgress();
    log(`Health check: advancing (${state.itemsProcessed}/${state.itemsTotal} items, ${state.imagesFound} images)`);
  } else {
    const stalledFor = now - state.lastProgressTime;
    log(`Health check: stalled ${Math.round(stalledFor / 1000 / 60)}m (threshold ${STALL_TIMEOUT / 1000 / 60}m)`);

    if (stalledFor >= STALL_TIMEOUT) {
      log(`WARNING: Frozen ${Math.round(stalledFor / 1000 / 60)}m — update still in progress...`);
      state.lastProgressTime = now;
      state.status = 'running';
      writeProgress();
    } else {
      state.status = 'running';
      writeProgress();
    }
  }

  saveState();

  if (['running', 'initialising', 'frozen'].includes(state.status)) {
    healthTimer = setTimeout(healthCheck, HEALTH_CHECK_INTERVAL);
  }
}

function checkScheduleAndRun() {
  if (state.status === 'running' || state.status === 'frozen') {
    log('Schedule check: update already running');
    return;
  }

  loadState();
  if (!shouldRun()) {
    const last = state.lastSuccess ? new Date(state.lastSuccess).toISOString() : 'never';
    log(`Schedule check: not due yet. Last: ${last}`);
    return;
  }

  log('Schedule check: update is due. Starting...');
  runUpdateDirectly();
}

async function waitForCompletion() {
  return new Promise((resolve) => {
    const check = () => {
      if (state.status === 'idle' || state.status === 'complete' || state.status === 'error') {
        resolve();
      } else {
        setTimeout(check, 5000);
      }
    };
    check();
  });
}

async function runLoop() {
  let startedOnce = false;

  while (true) {
    if (!startedOnce) {
      log('Starting update immediately...');
      runUpdateDirectly();
      startedOnce = true;
    } else if (state.status !== 'running' && state.status !== 'frozen') {
      const imagesNeeded = countImagesNeeded();
      state.imagesNeeded = imagesNeeded;

      if (imagesNeeded === 0) {
        state.status = 'complete';
        state.lastSuccess = Date.now();
        state.restartCount = 0;
        state.phase = 'All categories complete';
        log('All categories fully processed. All images resolved.');
        saveState();
        writeProgress();
      } else {
        log(`${imagesNeeded} images still needed. Starting next pass...`);
        runUpdateDirectly();
      }
    }

    if (state.status === 'running') {
      await waitForCompletion();
    }

    if (state.status === 'idle' || state.status === 'complete') {
      const daysSinceLastSuccess = state.lastSuccess
        ? (Date.now() - state.lastSuccess) / (1000 * 60 * 60 * 24)
        : Infinity;

      if (daysSinceLastSuccess >= RUN_INTERVAL_DAYS && countImagesNeeded() > 0) {
        log(`${RUN_INTERVAL_DAYS}d elapsed since last success, restarting...`);
        runUpdateDirectly();
      }
    }

    await new Promise(r => setTimeout(r, 15000));
  }
}

function isInTimeWindow() {
  const hour = new Date().getHours();
  return hour >= 23 || hour < 8;
}

function msUntilWindowEnd() {
  const now = new Date();
  const end = new Date(now);
  if (now.getHours() >= 23) {
    end.setDate(end.getDate() + 1);
  }
  end.setHours(8, 0, 0, 0);
  return Math.max(0, end - now);
}

function msUntilWindowStart() {
  const now = new Date();
  const hour = now.getHours();
  if (hour >= 8 && hour < 23) {
    const start = new Date(now);
    start.setHours(23, 0, 0, 0);
    return Math.max(0, start - now);
  }
  return 0;
}

async function scheduledLoop() {
  log('Scheduled mode: will run daily 23:00-08:00');

  while (true) {
    const waitStart = msUntilWindowStart();
    if (waitStart > 0) {
      const waitMin = Math.round(waitStart / 60000);
      log(`Waiting ${waitMin}min until 23:00 start...`);
      await new Promise(r => setTimeout(r, Math.min(waitStart, 60000)));
      continue;
    }

    if (!isInTimeWindow()) {
      log('Outside scheduled window (08:00-23:00). Exiting.');
      stopDashboard();
      await cleanupAll();
      process.exit(0);
    }

    log('Starting update within scheduled window...');
    runUpdateDirectly();
    await waitForCompletion();

    const windowMsLeft = msUntilWindowEnd();
    if (windowMsLeft <= 0) {
      log('Scheduled window ended. Exiting.');
      stopDashboard();
      await cleanupAll();
      process.exit(0);
    }

    if (state.status === 'idle' || state.status === 'complete') {
      const imagesNeeded = countImagesNeeded();
      if (imagesNeeded === 0) {
        log('All images resolved. Waiting in window in case of new data...');
        await new Promise(r => setTimeout(r, Math.min(windowMsLeft, 300000)));
      } else {
        log(`${imagesNeeded} images still needed, continuing...`);
        await new Promise(r => setTimeout(r, 5000));
      }
    } else {
      await new Promise(r => setTimeout(r, 15000));
    }
  }
}

async function main() {
  const args = process.argv.slice(2);
  const showHelp = args.includes('--help') || args.includes('-h');

  if (showHelp) {
    console.log(`
Usage: node scripts/aioupdate.js [options]

Options:
  --no-dashboard    Do not start the dashboard server
  --once            Run one cycle then exit
  --force           Force run even if not due
  --scheduled       Run in scheduled window (23:00-08:00 daily, auto-exit)
  --dashboard-only  Just start the dashboard (for monitoring existing process)
  --help            Show this help
    `);
    process.exit(0);
  }

  const noDashboard = args.includes('--no-dashboard');
  const runOnce = args.includes('--once');
  const forceRun = args.includes('--force');
  const dashboardOnly = args.includes('--dashboard-only');
  const scheduled = args.includes('--scheduled');

  fs.mkdirSync(THUMB_DIR, { recursive: true });
  loadState();

  process.on('SIGINT', async () => { log('SIGINT received'); await cleanupAll(); process.exit(0); });
  process.on('SIGTERM', async () => { log('SIGTERM received'); await cleanupAll(); process.exit(0); });

  log('Checking network connectivity (VPN check)...');
  for (let i = 0; i < 12; i++) {
    if (checkNetwork()) break;
    log(`Retrying network check in 10s (${i + 1}/12)...`);
    await new Promise(r => setTimeout(r, 10000));
  }

  if (!noDashboard && !scheduled) {
    await startDashboard();
  }

  if (dashboardOnly) {
    log('Dashboard-only mode. Monitoring existing process...');
    state.status = 'monitoring';
    writeProgress();
    await new Promise(() => {});
    return;
  }

  if (scheduled) {
    await scheduledLoop();
    return;
  }

  if (runOnce) {
    log('Single-run mode');
    if (forceRun || shouldRun()) {
      runUpdateDirectly();
      await waitForCompletion();
    } else {
      log('Not due yet. Use --force to override.');
    }
    stopDashboard();
    await cleanupAll();
    process.exit(0);
  }

  await runLoop();
}

main().catch(async err => {
  log(`FATAL: ${err.message}`);
  log(err.stack);
  await cleanupAll();
  process.exit(1);
});
