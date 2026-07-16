import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { execSync } from 'child_process';
import http from 'http';
import { main as runUpdateAll } from '../update-all.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const DATA_DIR = path.join(ROOT, 'src', 'data');
const THUMB_DIR = path.join(ROOT, 'public', 'thumbnails');
const LOG_FILE = path.join(ROOT, 'imgup.log');
const UPDATE_ALL_LOG = path.join(ROOT, 'update-all.log');
const STATE_FILE = path.join(ROOT, 'imgup-state.json');
const UPDATE_ALL_PROGRESS = path.join(ROOT, 'update-all-progress.json');
const MASTER_PROGRESS = path.join(ROOT, 'master-update-progress.json');
const PROGRESS_JSON = MASTER_PROGRESS;
const DASHBOARD_HTML = path.join(ROOT, 'imgup-dashboard.html');

function readProgress() {
  for (const fp of [UPDATE_ALL_PROGRESS, MASTER_PROGRESS]) {
    try {
      if (fs.existsSync(fp)) return JSON.parse(fs.readFileSync(fp, 'utf-8'));
    } catch {}
  }
  return null;
}

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
<title>PC Builder - Img Update</title>
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





async function runUpdateDirectly() {
  state.status = 'running';
  state.restartCount = (state.restartCount || 0) + 1;
  state.lastRun = Date.now();
  state.progressSnapshot = null;
  state.lastProgressTime = Date.now();
  state.pid = process.pid;
  saveState();
  writeProgress();

  log(`Running thumbnail update (attempt ${state.restartCount})...`);

  try {
    state.phase = 'Version Bump';
    state.phaseNumber = 0;
    writeProgress();
    bumpVersion();

    state.phase = 'Download Missing Thumbnails';
    state.phaseNumber = 0;
    writeProgress();
    await runUpdateAll();

    deployToVercel();
    state.status = 'complete';
    state.lastSuccess = Date.now();
    state.restartCount = 0;
    state.phase = 'All thumbnails resolved';
  } catch (err) {
    log(`Update failed: ${err.message}`);
    state.status = 'error';
    state.phase = `Error: ${err.message}`;
    if (state.restartCount < 10) {
      setTimeout(() => runUpdateDirectly(), 5000);
    }
  }
  saveState();
  writeProgress();
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

function getNextFriday2300() {
  const now = new Date();
  const friday = new Date(now);
  friday.setDate(now.getDate() + ((5 + 7 - now.getDay()) % 7));
  friday.setHours(23, 0, 0, 0);
  if (friday <= now) friday.setDate(friday.getDate() + 7);
  return friday;
}

function getSaturday2000() {
  const now = new Date();
  const saturday = new Date(now);
  saturday.setDate(now.getDate() + ((6 + 7 - now.getDay()) % 7));
  saturday.setHours(20, 0, 0, 0);
  return saturday;
}

function isInTimeWindow() {
  const now = new Date();
  const day = now.getDay();
  const hour = now.getHours();
  if (day === 5 && hour >= 23) return true;
  if (day === 6 && hour < 20) return true;
  return false;
}

function msUntilWindowEnd() {
  const now = new Date();
  const end = getSaturday2000();
  if (now.getDay() === 5 && now.getHours() >= 23) {
    end.setDate(end.getDate());
  }
  return Math.max(0, end - now);
}

function msUntilWindowStart() {
  const now = new Date();
  if (isInTimeWindow()) return 0;
  const next = getNextFriday2300();
  return Math.max(0, next - now);
}

async function scheduledLoop() {
  log('Scheduled mode: will run weekly Fri 23:00 → Sat 20:00');

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
      process.exit(0);
    }

    log('Starting update within scheduled window...');
    runUpdateDirectly();
    await waitForCompletion();

    const windowMsLeft = msUntilWindowEnd();
    if (windowMsLeft <= 0) {
      log('Scheduled window ended. Exiting.');
      stopDashboard();
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
Usage: node scripts/imgup.js [options]

Options:
  --no-dashboard    Do not start the dashboard server
  --once            Run one cycle then exit
  --force           Force run even if not due
  --scheduled       Run in scheduled window (Fri 23:00 → Sat 20:00 weekly, auto-exit)
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
    process.exit(0);
  }

  await runLoop();
}

main().catch(err => {
  log(`FATAL: ${err.message}`);
  log(err.stack);
  process.exit(1);
});
