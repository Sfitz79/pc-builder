/**
 * Live dashboard for scrape-pcpp-uk.mjs
 * Tails the scraper log + scraped_data JSONs and serves a live-updating HTML page.
 *
 * Usage: node scrape-pcpp-uk-dashboard.mjs [--port=3335] [--log=path]
 */

import fs from 'fs';
import http from 'http';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const PORT = parseInt((args.find(a => a.startsWith('--port=')) || '').split('=')[1] || '3335');
const LOG_FILE = args.find(a => a.startsWith('--log=')) ? args.find(a => a.startsWith('--log=')).split('=')[1] : path.join(__dirname, 'scrape-pcpp-uk.log');
const DATA_DIR = path.join(__dirname, 'scraped_data');

const CATEGORY_ORDER = ['cpu', 'gpu', 'motherboard', 'ram', 'storage', 'case', 'cooler', 'power-supply', 'case-fan'];

function readLog() {
  try {
    return fs.readFileSync(LOG_FILE, 'utf-8');
  } catch {
    return '';
  }
}

function parseState() {
  const log = readLog();
  const lines = log.split(/\r?\n/).filter(Boolean);
  const cats = [];
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const m = line.match(/^=== ([a-z0-9-]+):/);
    if (m) {
      cats.push({ name: m[1], pages: 0, products: 0, done: false });
    } else if (cats.length) {
      const cat = cats[cats.length - 1];
      const pm = line.match(/^\s*Page (\d+): (\d+) products \((\d+) total\)/);
      if (pm) {
        cat.pages = parseInt(pm[1]);
        cat.products = parseInt(pm[3]);
      } else if (/^\s*Saved \d+ products to .*\.json/.test(line)) {
        cat.done = true;
      }
    }
  }
  const lastCat = cats[cats.length - 1];
  const state = {
    running: !lastCat || !lastCat.done,
    lastLine: lines[lines.length - 1] || '',
    category: lastCat && !lastCat.done ? lastCat.name : '',
    categories: cats,
    files: CATEGORY_ORDER.filter(c => fs.existsSync(path.join(DATA_DIR, `${c}.json`))).map(c => {
      const st = fs.statSync(path.join(DATA_DIR, `${c}.json`));
      return { name: c, size: st.size, mtime: st.mtime };
    }),
  };
  return state;
}

const HTML = `<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>PCPP UK Scraper — Live</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;background:#0d1117;color:#c9d1d9;padding:24px}
h1{font-size:22px;color:#58a6ff}.subtitle{color:#8b949e;font-size:13px;margin:6px 0 20px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;margin-bottom:22px}
.card{background:#161b22;border:1px solid #30363d;border-radius:10px;padding:16px}
.card h3{font-size:12px;text-transform:uppercase;color:#8b949e;margin-bottom:8px}
.card .name{font-size:15px;font-weight:700;color:#fff}
.card .num{font-size:26px;font-weight:800;color:#3fb950}
.card .sub{font-size:11px;color:#8b949e;margin-top:4px}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700}
.badge.run{background:#1f6feb33;color:#58a6ff}.badge.done{background:#23863633;color:#3fb950}
.pre{font-family:Consolas,monospace;font-size:12px;white-space:pre-wrap;background:#161b22;border:1px solid #30363d;border-radius:8px;padding:14px;max-height:50vh;overflow-y:auto;color:#8b949e}
</style></head><body>
<h1>PCPartPicker UK Scraper</h1>
<div class="subtitle" id="status">Loading...</div>
<div class="grid" id="grid"></div>
<pre class="pre" id="tail"></pre>
<script>
async function poll(){
  const r=await fetch('/state'),d=await r.json();
  const last=d.categories[d.categories.length-1];
  document.getElementById('status').textContent=(d.running?'RUNNING — ':'DONE — ')+(d.category?('category '+d.category+' | '):'')+d.lastLine;
  let html='';
  for(const c of d.categories){
    html+='<div class="card"><div class="name">'+c.name+' <span class="badge '+(c.done?'done':'run')+'">'+(c.done?'DONE':'...')+'</span></div><div class="num">'+c.products+'</div><div class="sub">page '+c.pages+'</div></div>';
  }
  for(const f of d.files){
    if(d.categories.find(c=>c.name===f.name)) continue;
    html+='<div class="card"><div class="name">'+f.name+'</div><div class="num">'+(f.size/1024).toFixed(0)+'KB</div><div class="sub">'+new Date(f.mtime).toLocaleTimeString()+'</div></div>';
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
    s.tail = readLog().split(/\r?\n/).filter(Boolean).slice(-40).join('\n');
    res.writeHead(200, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' });
    res.end(JSON.stringify(s));
    return;
  }
  res.writeHead(404);
  res.end();
}).listen(PORT, () => console.log(`Dashboard: http://localhost:${PORT}`));
