/**
 * Live dashboard for price-fill.mjs
 * Tails price-fill.log + price-fill-state.json and serves a live-updating page.
 *
 * Usage: node price-fill-dashboard.mjs [--port=3337]
 */

import fs from 'fs';
import http from 'http';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const PORT = parseInt((args.find(a => a.startsWith('--port=')) || '').split('=')[1] || '3337');
const LOG_FILE = path.join(__dirname, 'price-fill.log');
const STATE_FILE = path.join(__dirname, 'price-fill-state.json');

function readLog() {
  try {
    return fs.readFileSync(LOG_FILE, 'utf-8');
  } catch {
    return '';
  }
}

function loadState() {
  try {
    return JSON.parse(fs.readFileSync(STATE_FILE, 'utf-8'));
  } catch {
    return { categories: {} };
  }
}

function parseState() {
  const log = readLog();
  const lines = log.split(/\r?\n/).filter(Boolean);
  const state = loadState();
  const cats = state.categories || {};

  const catsOrder = [];
  const catMeta = {};
  for (const line of lines) {
    let m = line.match(/^CAT_START (\S+) total=(\d+) need=(\d+) remaining=(\d+) urlmiss=(\d+)/);
    if (m) {
      if (!catsOrder.includes(m[1])) catsOrder.push(m[1]);
      catMeta[m[1]] = { total: +m[2], need: +m[3], remaining: +m[4], urlmiss: +m[5] };
      continue;
    }
    m = line.match(/^CAT_DONE (\S+) found=(\d+) notfound=(\d+) failed=(\d+) urlmiss=(\d+)/);
    if (m) {
      if (catMeta[m[1]]) catMeta[m[1]].done = true;
      continue;
    }
    m = line.match(/^CAT_SKIP (\S+)/);
    if (m && !catMeta[m[1]]) {
      if (!catsOrder.includes(m[1])) catsOrder.push(m[1]);
      catMeta[m[1]] = { total: 0, need: 0, remaining: 0, urlmiss: 0, done: true, skip: true };
    }
    m = line.match(/^CAT_PAUSE (\S+)/);
    if (m && catMeta[m[1]]) catMeta[m[1]].paused = true;
  }

  const items = lines.filter(l => /^ITEM (OK|NO|FAIL) /.test(l)).map(l => {
    const m = l.match(/^ITEM (\w+) T=(\d+) cat=(\S+) (?:price=([\d.]+) )?took=([\d.]+)(?: err=(\S+))?/);
    return m ? { status: m[1], t: +m[2], cat: m[3], price: m[4] ? +m[4] : null, took: +m[5] } : null;
  }).filter(Boolean);

  const totalItems = items.length;
  const found = items.filter(i => i.status === 'OK').length;
  const notfound = items.filter(i => i.status === 'NO').length;
  const failed = items.filter(i => i.status === 'FAIL').length;

  let rate = 0, avgTook = 0;
  const now = Math.floor(Date.now() / 1000);
  const recent = items.filter(i => now - i.t <= 120);
  if (recent.length >= 2) {
    const span = Math.max(1, recent[recent.length - 1].t - recent[0].t);
    rate = recent.length / span;
  }
  const tooks = items.map(i => i.took).slice(-100);
  if (tooks.length) avgTook = tooks.reduce((a, b) => a + b, 0) / tooks.length;

  const remainingAll = catsOrder.reduce((s, c) => s + Math.max(0, (catMeta[c].need || 0) - (cats[c]?.done || 0)), 0);
  const etaS = rate > 0 ? remainingAll / rate : 0;

  const catCards = catsOrder.map(c => {
    const meta = catMeta[c] || { total: 0, need: 0, remaining: 0, urlmiss: 0 };
    const st = cats[c] || { done: 0, found: 0, notfound: 0, failed: 0, status: 'pending' };
    const total = meta.need || st.need || 0;
    const done = st.done || 0;
    const pct = total > 0 ? Math.round((done / total) * 100) : 0;
    return {
      name: c, total, done, pct,
      found: st.found || 0, notfound: st.notfound || 0, failed: st.failed || 0,
      status: meta.done ? 'DONE' : meta.paused ? 'PAUSED' : meta.skip ? 'SKIP' : (st.status === 'running' || st.status === 'paused' ? (st.status === 'paused' ? 'PAUSED' : 'RUNNING') : 'QUEUED'),
    };
  });

  const running = catCards.some(c => c.status === 'RUNNING');

  return {
    running,
    lastLine: lines[lines.length - 1] || '',
    totals: { totalItems, found, notfound, failed, rate, avgTook, remaining: remainingAll, etaS },
    categories: catCards,
    tail: lines.slice(-60).join('\n'),
  };
}

const HTML = `<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>PCPP UK Price Fill — Live</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;background:#0d1117;color:#c9d1d9;padding:24px}
h1{font-size:22px;color:#58a6ff}.subtitle{color:#8b949e;font-size:13px;margin:6px 0 20px}
.stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;margin-bottom:22px}
.card{background:#161b22;border:1px solid #30363d;border-radius:10px;padding:16px}
.card h3{font-size:12px;text-transform:uppercase;color:#8b949e;margin-bottom:8px}
.card .num{font-size:26px;font-weight:800;color:#3fb950}
.card .sub{font-size:11px;color:#8b949e;margin-top:4px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;margin-bottom:22px}
.bar{height:8px;background:#30363d;border-radius:4px;margin-top:8px;overflow:hidden}
.bar>div{height:100%;background:#3fb950;border-radius:4px}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700}
.badge.run{background:#1f6feb33;color:#58a6ff}.badge.done{background:#23863633;color:#3fb950}
.badge.fail{background:#da363333;color:#f85149}.badge.queued{background:#30363d;color:#8b949e}.badge.skip{background:#6e768133;color:#c9d1d9}
.cname{font-size:13px;font-weight:700;color:#fff;word-break:break-all}
.csub{font-size:11px;color:#8b949e;margin-top:4px}
.pre{font-family:Consolas,monospace;font-size:12px;white-space:pre-wrap;background:#161b22;border:1px solid #30363d;border-radius:8px;padding:14px;max-height:45vh;overflow-y:auto;color:#8b949e}
</style></head><body>
<h1>PCPartPicker UK — Price Fill</h1>
<div class="subtitle" id="status">Loading...</div>
<div class="stats" id="stats"></div>
<div class="grid" id="grid"></div>
<pre class="pre" id="tail"></pre>
<script>
function fmtEta(s){if(!isFinite(s)||s<=0)return'--';const h=Math.floor(s/3600),m=Math.round((s%3600)/60);return h>0?h+'h '+m+'m':m+'m';}
async function poll(){
  const d=await (await fetch('/state')).json();
  document.getElementById('status').textContent=(d.running?'RUNNING — ':'PAUSED/DONE — ')+d.lastLine;
  const t=d.totals;
  document.getElementById('stats').innerHTML=
    '<div class="card"><h3>Fetched</h3><div class="num">'+t.totalItems+'</div><div class="sub">pages visited</div></div>'+
    '<div class="card"><h3>Found price</h3><div class="num" style="color:#3fb950">'+t.found+'</div></div>'+
    '<div class="card"><h3>No price</h3><div class="num" style="color:#d29922">'+t.notfound+'</div></div>'+
    '<div class="card"><h3>Failed</h3><div class="num" style="color:#f85149">'+t.failed+'</div></div>'+
    '<div class="card"><h3>Rate</h3><div class="num" style="color:#58a6ff">'+t.rate.toFixed(2)+'</div><div class="sub">items/sec</div></div>'+
    '<div class="card"><h3>Avg fetch</h3><div class="num" style="color:#58a6ff">'+t.avgTook.toFixed(1)+'</div><div class="sub">seconds</div></div>'+
    '<div class="card"><h3>Remaining</h3><div class="num">'+t.remaining+'</div><div class="sub">ETA '+fmtEta(t.etaS)+'</div></div>';
  let html='';
  for(const c of d.categories){
    const badge='<span class="badge '+(c.status==='DONE'?'done':c.status==='RUNNING'?'run':c.status==='PAUSED'?'fail':c.status==='SKIP'?'skip':'queued')+'">'+c.status+'</span>';
    html+='<div class="card"><div class="cname">'+c.name+' '+badge+'</div><div class="bar"><div style="width:'+c.pct+'%"></div></div>'+
      '<div class="csub">'+c.done+'/'+c.total+' ('+c.pct+'%) &middot; found '+c.found+' &middot; no '+c.notfound+' &middot; fail '+c.failed+'</div></div>';
  }
  document.getElementById('grid').innerHTML=html||'<div class="card">No data yet</div>';
  document.getElementById('tail').textContent=d.tail;
}
setInterval(poll,2000);poll();
</script></body></html>`;

http.createServer((req, res) => {
  if (req.url === '/') {
    res.writeHead(200, { 'Content-Type': 'text/html' });
    res.end(HTML);
    return;
  }
  if (req.url === '/state') {
    const s = parseState();
    res.writeHead(200, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' });
    res.end(JSON.stringify(s));
    return;
  }
  res.writeHead(404);
  res.end();
}).listen(PORT, () => console.log(`Price-fill dashboard: http://localhost:${PORT}`));
