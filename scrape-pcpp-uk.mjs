/**
 * PCPartPicker UK Scraper (Byparr engine)
 *
 * Replaces scripts/scraper_pcpp.py: scrapes ALL product data from
 * uk.pcpartpicker.com using the self-hosted Byparr Cloudflare-bypass API
 * (http://localhost:8191/v1) instead of pyppeteer, which gets blocked.
 *
 * Usage:
 *   node scrape-pcpp-uk.mjs                       # scrape all categories
 *   node scrape-pcpp-uk.mjs cpu,gpu               # specific categories
 *   node scrape-pcpp-uk.mjs cpu --pages=5         # cap pages per category
 *   node scrape-pcpp-uk.mjs --byparr=http://host:8191
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const OUTPUT_DIR = path.join(__dirname, 'scraped_data');

const CATEGORIES = {
  'cpu': 'https://uk.pcpartpicker.com/products/cpu/',
  'cooler': 'https://uk.pcpartpicker.com/products/cpu-cooler/',
  'ram': 'https://uk.pcpartpicker.com/products/memory/',
  'storage': 'https://uk.pcpartpicker.com/products/internal-hard-drive/',
  'motherboard': 'https://uk.pcpartpicker.com/products/motherboard/',
  'gpu': 'https://uk.pcpartpicker.com/products/video-card/',
  'power-supply': 'https://uk.pcpartpicker.com/products/power-supply/',
  'case': 'https://uk.pcpartpicker.com/products/case/',
  'case-fan': 'https://uk.pcpartpicker.com/products/case-fan/',
  'headphones': 'https://uk.pcpartpicker.com/products/headphones/',
  'keyboard': 'https://uk.pcpartpicker.com/products/keyboard/',
  'wireless-network-card': 'https://uk.pcpartpicker.com/products/wireless-network-card/',
  'monitor': 'https://uk.pcpartpicker.com/products/monitor/',
  'mouse': 'https://uk.pcpartpicker.com/products/mouse/',
  'speakers': 'https://uk.pcpartpicker.com/products/speakers/',
  'webcam': 'https://uk.pcpartpicker.com/products/webcam/',
  'external-hard-drive': 'https://uk.pcpartpicker.com/products/external-hard-drive/',
  'optical-drive': 'https://uk.pcpartpicker.com/products/optical-drive/',
  'ups': 'https://uk.pcpartpicker.com/products/ups/',
  'fan-controller': 'https://uk.pcpartpicker.com/products/fan-controller/',
  'thermal-paste': 'https://uk.pcpartpicker.com/products/thermal-paste/',
  'wired-network-card': 'https://uk.pcpartpicker.com/products/wired-network-card/',
  'sound-card': 'https://uk.pcpartpicker.com/products/sound-card/',
  'case-accessory': 'https://uk.pcpartpicker.com/products/case-accessory/',
  'os': 'https://uk.pcpartpicker.com/products/os/',
};

// Files in scraped_data/ that are NOT PCPP product categories
const NOT_CATEGORY_FILES = new Set(['case-with-images.json', 'scan_prices.json']);

function categoriesFromScrapedData() {
  if (!fs.existsSync(OUTPUT_DIR)) return {};
  const cats = {};
  for (const f of fs.readdirSync(OUTPUT_DIR)) {
    if (!f.endsWith('.json') || NOT_CATEGORY_FILES.has(f)) continue;
    const name = f.replace('.json', '');
    if (CATEGORIES[name]) cats[name] = CATEGORIES[name];
    else console.log(`  (skipping ${name}: no PCPP URL mapping)`);
  }
  return cats;
}

function camelCase(s) {
  const t = s.trim();
  if (t.toUpperCase() === t) return t.toLowerCase();
  return t.replace(/[^a-zA-Z0-9]+(.)/g, (_, c) => c.toUpperCase()).replace(/^[A-Z]/, c => c.toLowerCase());
}

async function fetchByparr(url, byparrUrl) {
  const ctrl = new AbortController();
  const id = setTimeout(() => ctrl.abort(), 120000);
  try {
    const resp = await fetch(byparrUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ url }),
      signal: ctrl.signal,
    });
    if (!resp.ok) throw new Error(`Byparr HTTP ${resp.status}`);
    const data = await resp.json();
    if (data.solution && data.solution.status === 200 && data.solution.response) {
      return data.solution.response;
    }
    throw new Error(`Byparr solution status=${data.solution && data.solution.status}`);
  } finally {
    clearTimeout(id);
  }
}

function parseProducts(html) {
  const rows = [...html.matchAll(/<tr class="tr__product[\s\S]*?<\/tr>/g)];
  const products = [];
  for (const m of rows) {
    const row = m[0];
    const nameWrapper = row.match(/<div class="td__nameWrapper">\s*<p>(.*?)<\/p>/);
    if (!nameWrapper) continue;
    const name = nameWrapper[1].replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
    if (!name) continue;

    const link = row.match(/<a href="(\/product\/[^"]+)"/);
    const url = link ? 'https://uk.pcpartpicker.com' + link[1] : null;

    const img = row.match(/<img[^>]+src="(\/\/cdna\.pcpartpicker\.com\/[^"]+)"/);
    const imageUrl = img ? 'https:' + img[1] : null;

    const priceMatch = row.match(/<td class="td__price">\s*([£€$])?([\d,]+(?:\.\d{1,2})?)/);
    let price = null, priceCurrency = null;
    if (priceMatch) {
      price = parseFloat(priceMatch[2].replace(/,/g, ''));
      priceCurrency = priceMatch[1] || null;
    }

    const ratingEl = row.match(/<td class="td__rating[^"]*"[^>]*data-ci="(\d+)"[\s\S]*?<\/td>/);
    let rating = null, ratingCount = null;
    if (ratingEl) {
      const cell = ratingEl[0];
      const full = (cell.match(/class="icon shape-star-full"/g) || []).length;
      const half = (cell.match(/class="icon shape-star-half"/g) || []).length;
      rating = full + half * 0.5;
      ratingCount = ratingEl[1] ? parseInt(ratingEl[1]) : null;
    }

    const specs = {};
    const specCells = [...row.matchAll(/<td class="td__spec[^"]*"><h6 class="specLabel">(.*?)<\/h6>(.*?)<\/td>/g)];
    for (const s of specCells) {
      const label = camelCase(s[1]);
      const val = s[2].replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
      if (label && val) specs[label] = val;
    }

    products.push({ productName: name, url, imageUrl, price, priceCurrency, rating, ratingCount, specs });
  }
  return products;
}

async function scrapeCategory(catName, catUrl, byparrUrl, maxPages, delayMs) {
  console.log(`\n=== ${catName}: ${catUrl} ===`);
  const all = [];
  const seen = new Set();
  let pages = 0;

  for (let pg = 1; maxPages === null || pg <= maxPages; pg++) {
    const url = pg === 1 ? catUrl : catUrl.replace(/\/$/, '') + `/#page=${pg}`;
    try {
      const html = await fetchByparr(url, byparrUrl);
      const products = parseProducts(html);
      const fresh = products.filter(p => {
        const k = p.productName + '|' + p.url;
        if (seen.has(k)) return false;
        seen.add(k);
        return true;
      });
      if (products.length === 0 || fresh.length === 0) {
        console.log(`  Page ${pg}... (end, ${all.length} total)`);
        break;
      }
      all.push(...fresh);
      console.log(`  Page ${pg}: ${fresh.length} products (${all.length} total)`);
    } catch (e) {
      console.log(`  Page ${pg}... Error: ${e.message} (stopping)`);
      break;
    }
    await new Promise(r => setTimeout(r, delayMs));
  }

  for (const p of all) {
    p.category = catName;
    p.country = 'gb';
    p.scrapedAt = new Date().toISOString();
  }
  return all;
}

async function main() {
  const args = process.argv.slice(2);
  const catsArg = args.find(a => !a.startsWith('--'));
  const maxPagesArg = args.find(a => a.startsWith('--pages='));
  const byparrArg = args.find(a => a.startsWith('--byparr='));
  const delayArg = args.find(a => a.startsWith('--delay='));

  const byparrUrl = byparrArg ? byparrArg.split('=')[1] : 'http://localhost:8191/v1';
  const maxPages = maxPagesArg ? parseInt(maxPagesArg.split('=')[1]) || null : null;
  const delayMs = delayArg ? parseInt(delayArg.split('=')[1]) || 0 : 1000;

  let cats;
  if (args.includes('--scraped')) {
    cats = categoriesFromScrapedData();
  } else if (catsArg) {
    cats = {};
    for (const c of catsArg.split(',')) {
      if (CATEGORIES[c]) cats[c] = CATEGORIES[c];
      else console.log(`  Unknown category: ${c}`);
    }
  } else {
    cats = CATEGORIES;
  }
  if (Object.keys(cats).length === 0) {
    console.log('Usage: node scrape-pcpp-uk.mjs [cpu,gpu,...] [--pages=N] [--byparr=URL] [--delay=MS]');
    return;
  }

  console.log('=== PCPartPicker UK Scraper (Byparr engine) ===');
  console.log(`Byparr: ${byparrUrl}`);
  console.log(`Max pages: ${maxPages === null ? 'unlimited' : maxPages}`);
  console.log(`Categories: ${Object.keys(cats).join(', ')}`);

  if (!fs.existsSync(OUTPUT_DIR)) fs.mkdirSync(OUTPUT_DIR, { recursive: true });

  const start = Date.now();
  for (const [catName, catUrl] of Object.entries(cats)) {
    const products = await scrapeCategory(catName, catUrl, byparrUrl, maxPages, delayMs);
    if (products.length > 0) {
      const fp = path.join(OUTPUT_DIR, `${catName}.json`);
      fs.writeFileSync(fp, JSON.stringify(products, null, 2), 'utf-8');
      console.log(`  Saved ${products.length} products to ${catName}.json`);
    }
  }
  const elapsed = ((Date.now() - start) / 1000 / 60).toFixed(1);
  console.log(`\n=== DONE in ${elapsed} min ===`);
}

main().catch(e => { console.error(e); process.exit(1); });
