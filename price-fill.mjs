/**
 * PRICE FILL — PCPartPicker UK product-page pass (Byparr engine)
 *
 * For every deduped product in scraped_data/*.json that has a scraped URL
 * but no price, visit its uk.pcpartpicker.com/product/<id> page via Byparr,
 * extract the lowest retailer price, and write it back into the JSON. After
 * each category finishes, src/data CSVs are regenerated via mergeCategory.
 *
 * The pass is resumable: progress is stored in price-fill-state.json and
 * every item is logged to price-fill.log (tail-able by the dashboard).
 *
 * Usage:
 *   node price-fill.mjs                       # all categories, 1 worker
 *   node price-fill.mjs cpu.json,gpu.json     # specific categories
 *   node price-fill.mjs --max=50              # cap items this run (test)
 *   node price-fill.mjs --dry-run             # count tasks, no fetching
 *   node price-fill.mjs --ports=8191,8192,8193,8194   # shard across Byparr instances
 *   node price-fill.mjs --byparr=http://host:8191/v1 --delay=0
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { CATEGORY_DEFS, buildKeptMap, mergeCategory } from './merge-scraped-json-to-csv.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SCRAPED_DIR = path.join(__dirname, 'scraped_data');
const DATA_DIR = path.join(__dirname, 'src', 'data');
const STATE_FILE = path.join(__dirname, 'price-fill-state.json');
const LOG_FILE = path.join(__dirname, 'price-fill.log');

const args = process.argv.slice(2);
const catArg = args.find(a => a && !a.startsWith('--'));
const maxArg = args.find(a => a.startsWith('--max='));
const delayArg = args.find(a => a.startsWith('--delay='));
const byparrArg = args.find(a => a.startsWith('--byparr='));
const portsArg = args.find(a => a.startsWith('--ports='));
const dryRun = args.includes('--dry-run');

const BYPARR = byparrArg ? byparrArg.split('=')[1] : 'http://localhost:8191/v1';
const MAX_ITEMS = maxArg ? parseInt(maxArg.split('=')[1], 10) || 0 : 0;
const DELAY_MS = delayArg ? parseInt(delayArg.split('=')[1], 10) || 0 : 0;
const PORTS = portsArg ? portsArg.split('=')[1].split(',').map(s => s.trim()).filter(Boolean)
  : [BYPARR.replace(/^http:\/\/localhost:/, '').replace(/\/v1$/, '') || '8191'];

// Core build categories first for fastest app value
const PRIORITY_ORDER = [
  'cpu.json', 'motherboard.json', 'ram.json', 'gpu.json', 'storage.json',
  'power-supply.json', 'case.json', 'cooler.json', 'case-fan.json',
  'monitor.json', 'keyboard.json', 'mouse.json', 'headphones.json',
  'speakers.json', 'webcam.json', 'external-hard-drive.json', 'os.json',
  'optical-drive.json', 'ups.json', 'fan-controller.json', 'thermal-paste.json',
  'wired-network-card.json', 'wireless-network-card.json', 'sound-card.json',
  'case-accessory.json',
];

function sleep(ms) {
  return new Promise(r => setTimeout(r, ms));
}

function log(msg) {
  const line = msg;
  try {
    fs.appendFileSync(LOG_FILE, line + '\n', 'utf-8');
  } catch {
    /* ignore */
  }
  console.log(line);
}

function loadState() {
  const empty = { version: 1, startedAt: null, updatedAt: null, categories: {} };
  try {
    return { ...empty, ...JSON.parse(fs.readFileSync(STATE_FILE, 'utf-8')) };
  } catch {
    return empty;
  }
}

function saveState() {
  state.updatedAt = new Date().toISOString();
  fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2), 'utf-8');
}

async function fetchPage(url, byparrUrl) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 130000);
  try {
    const resp = await fetch(byparrUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ cmd: 'request.get', url, max_timeout: 60 }),
      signal: ctrl.signal,
    });
    if (!resp.ok) {
      const body = await resp.text().catch(() => '');
      throw new Error(`byparr HTTP ${resp.status} ${body.slice(0, 100)}`);
    }
    const data = await resp.json();
    const sol = data.solution;
    if (sol && sol.status === 200 && sol.response) return sol.response;
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

async function fillOne(task, jsonFile, byparrUrl) {
  const t0 = Date.now();
  const backoffs = [0, 3000, 8000, 20000];
  let lastErr = '';
  for (let i = 0; i < backoffs.length; i++) {
    if (i > 0) await sleep(backoffs[i]);
    try {
      const html = await fetchPage(task.url, byparrUrl);
      const price = extractPrice(html);
      const took = (Date.now() - t0) / 1000;
      if (price !== null) {
        log(`ITEM OK T=${Math.floor(t0 / 1000)} cat=${jsonFile} price=${price} took=${took.toFixed(1)} name=${task.name}`);
        return { status: 'found', price, took };
      }
      log(`ITEM NO T=${Math.floor(t0 / 1000)} cat=${jsonFile} took=${took.toFixed(1)} name=${task.name}`);
      return { status: 'notfound', took };
    } catch (e) {
      lastErr = e.message;
    }
  }
  const took = (Date.now() - t0) / 1000;
  log(`ITEM FAIL T=${Math.floor(t0 / 1000)} cat=${jsonFile} took=${took.toFixed(1)} err=${lastErr.slice(0, 120)} name=${task.name}`);
  return { status: 'failed', took };
}

async function processCategory(jsonFile, def, byparrUrl) {
  const srcPath = path.join(SCRAPED_DIR, jsonFile);
  if (!fs.existsSync(srcPath)) {
    log(`CAT_SKIP ${jsonFile} not-found`);
    return;
  }
  const items = JSON.parse(fs.readFileSync(srcPath, 'utf-8'));

  const clones = items.map(i => ({ ...i, specs: { ...(i.specs || {}) } }));
  const keptMap = buildKeptMap(def, clones);
  const byName = new Map(items.map(i => [String(i.productName || '').trim().toLowerCase(), i]));

  const catState = state.categories[jsonFile] ||= {
    total: 0, need: 0, urlmiss: 0, done: 0, found: 0, notfound: 0, failed: 0, doneNames: [], status: 'pending',
  };
  catState.status = 'running';
  catState.total = keptMap.size;

  const tasks = [];
  let urlmiss = 0;
  for (const [mapKey, clone] of keptMap) {
    const lower = String(clone.productName || '').trim().toLowerCase();
    const orig = byName.get(lower);
    if (!orig) continue;
    const hasPrice = typeof orig.price === 'number' && orig.price > 0;
    if (hasPrice) continue;
    if (!orig.url) { urlmiss++; continue; }
    if (catState.doneNames.includes(mapKey)) continue;
    tasks.push({ mapKey, name: clone.productName, url: orig.url });
  }
  catState.urlmiss = urlmiss;
  catState.need = catState.done + tasks.length + urlmiss;

  log(`CAT_START ${jsonFile} total=${catState.total} need=${catState.need} remaining=${tasks.length} urlmiss=${urlmiss}`);
  if (dryRun) return;

  for (const task of tasks) {
    const res = await fillOne(task, jsonFile, byparrUrl);
    if (res.status === 'found') {
      const orig = byName.get(String(task.name).trim().toLowerCase());
      if (orig) {
        orig.price = res.price;
        orig.priceCurrency = orig.priceCurrency || 'gbp';
        orig.priceUpdatedAt = new Date().toISOString();
      }
      catState.found++;
    } else if (res.status === 'notfound') {
      catState.notfound++;
    } else {
      catState.failed++;
    }
    catState.done++;
    catState.doneNames.push(task.mapKey);
    itemsProcessed++;

    if (catState.done % 5 === 0 || catState.done === catState.need) {
      fs.writeFileSync(srcPath, JSON.stringify(items, null, 2), 'utf-8');
    }
    if (DELAY_MS > 0) await sleep(DELAY_MS);
    if (MAX_ITEMS > 0 && itemsProcessed >= MAX_ITEMS) {
      fs.writeFileSync(srcPath, JSON.stringify(items, null, 2), 'utf-8');
      saveState();
      log(`CAT_PAUSE ${jsonFile} hit-max=${MAX_ITEMS}`);
      catState.status = 'paused';
      pauseRequested = true;
      return;
    }
  }

  fs.writeFileSync(srcPath, JSON.stringify(items, null, 2), 'utf-8');
  catState.status = 'done';
  saveState();
  log(`CAT_DONE ${jsonFile} found=${catState.found} notfound=${catState.notfound} failed=${catState.failed} urlmiss=${urlmiss}`);
  console.log(`[${jsonFile}] regenerating CSVs...`);
  try {
    mergeCategory(def, items);
  } catch (e) {
    log(`CAT_MERGE_ERR ${jsonFile} ${e.message}`);
  }
}

const state = loadState();
if (!state.startedAt) state.startedAt = new Date().toISOString();

let itemsProcessed = 0;
let pauseRequested = false;

async function main() {
  console.log('\n=== PCPP UK Price Fill (Byparr) ===');
  console.log(`Byparr ports: ${PORTS.join(', ')}`);
  console.log(`Max items: ${MAX_ITEMS || 'unlimited'} | Dry run: ${dryRun}`);

  let order = PRIORITY_ORDER.filter(jf => CATEGORY_DEFS[jf]);
  if (catArg) {
    const wanted = catArg.split(',');
    order = wanted.filter(w => CATEGORY_DEFS[w]);
    const unknown = wanted.filter(w => !CATEGORY_DEFS[w]);
    if (unknown.length) console.log(`  Unknown categories: ${unknown.join(', ')}`);
  }
  if (order.length === 0) {
    console.log('Usage: node price-fill.mjs [cpu.json,gpu.json,...] [--max=N] [--dry-run]');
    return;
  }
  console.log(`Categories: ${order.join(', ')}`);

  const flush = setInterval(() => saveState(), 2000);

  let nextIdx = 0;
  async function workerLoop(byparrUrl) {
    while (!pauseRequested) {
      const idx = nextIdx++;
      if (idx >= order.length) break;
      const jsonFile = order[idx];
      await processCategory(jsonFile, CATEGORY_DEFS[jsonFile], byparrUrl);
    }
  }

  await Promise.all(PORTS.map((port, i) => workerLoop(`http://localhost:${port}/v1`)));

  clearInterval(flush);
  saveState();
  console.log('\n=== PRICE FILL DONE ===');
}

main().catch(e => {
  log(`FATAL ${e.stack || e.message}`);
  saveState();
  process.exit(1);
});
