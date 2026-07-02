/**
 * MASTER UPDATE SCRIPT - Unified Product Data Scraper
 * Runs until all categories are processed. Validates real product images only.
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.join(__dirname, 'src', 'data');
const THUMBNAILS_DIR = path.join(__dirname, 'public', 'thumbnails');
const LOG_FILE = path.join(__dirname, 'master-update.log');
const PROGRESS_FILE = path.join(__dirname, 'master-update-progress.json');
const STATUS_FILE = path.join(__dirname, 'master-update-status.txt');

const CONFIG = {
  BATCH_SIZE: 1000,
  DELAY_BETWEEN_ITEMS: 0,
  DELAY_BETWEEN_BATCHES: 0,
  DELAY_BETWEEN_CATEGORIES: 2000,
  TIMEOUT: 15000,
  IMAGE_TIMEOUT: 30000,
  PRICE_TIMEOUT: 10000,
};

if (!fs.existsSync(THUMBNAILS_DIR)) fs.mkdirSync(THUMBNAILS_DIR, { recursive: true });
if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });

const RETAILERS = {
  'scan.co.uk': (q) => `https://www.scan.co.uk/search#q=${encodeURIComponent(q)}`,
  'overclockers.co.uk': (q) => `https://www.overclockers.co.uk/search?search=${encodeURIComponent(q)}`,
  'box.co.uk': (q) => `https://www.box.co.uk/search?q=${encodeURIComponent(q)}`,
  'ebuyer.com': (q) => `https://www.ebuyer.com/search?q=${encodeURIComponent(q)}`,
  'cclonline.com': (q) => `https://www.cclonline.com/search?q=${encodeURIComponent(q)}`,
  'awd-it.co.uk': (q) => `https://www.awd-it.co.uk/search?q=${encodeURIComponent(q)}`,
};

const CATEGORIES = [
  'case.csv', 'cpu.csv', 'gpu.csv', 'motherboard.csv', 'ram.csv',
  'storage.csv', 'power-supply.csv', 'cooler.csv', 'case-fan.csv',
  'monitor.csv', 'keyboard.csv', 'mouse.csv', 'headphones.csv',
  'speakers.csv', 'webcam.csv', 'wireless-network-card.csv',
  'case-with-images.csv', 'case-accessory.csv', 'external-hard-drive.csv',
  'fan-controller.csv', 'optical-drive.csv', 'os.csv', 'sound-card.csv',
  'thermal-paste.csv', 'ups.csv', 'wired-network-card.csv',
];

// Exclude patterns for non-product images
const EXCLUDE_IMAGE_PATTERNS = [
  /pixel|tracking|analytics|logo|icon|placeholder|banner|advertisement|sponsor/i,
  /badge|star|rating|review|avatar|profile|thumbnail_gallery|colour_swatch/i,
  /checkout|captcha|transparent|empty|no_image|not_found|coming_soon/i,
  /data:image|svg\+xml|\.svg/i,
  /wishlist|compare|cart_badge|newsletter/i,
  /width=\d{1,2}|height=\d{1,2}/i,
  /social|facebook|twitter|instagram|linkedin|youtube|pinterest/i,
  /favicon|apple-touch|manifest|mask-icon/i,
  /loader|spinner|loading|ajax/i,
];

const CATEGORY_IMAGE_KEYWORDS = {
  'case': ['case', 'chassis', 'tower', 'pc case'],
  'cpu': ['cpu', 'processor', 'ryzen', 'core i', 'socket'],
  'gpu': ['gpu', 'graphics', 'geforce', 'radeon', 'video card'],
  'motherboard': ['motherboard', 'mainboard', 'socket'],
  'ram': ['ram', 'memory', 'ddr', 'vengeance', 'trident'],
  'storage': ['ssd', 'hard drive', 'nvme', 'storage'],
  'power-supply': ['psu', 'power supply', 'powe supply'],
  'cooler': ['cooler', 'fan', 'liquid', 'aio', 'heatsink'],
  'case-fan': ['case fan', 'fan'],
  'monitor': ['monitor', 'display', 'gaming monitor'],
  'keyboard': ['keyboard', 'mechanical keyboard'],
  'mouse': ['mouse', 'gaming mouse'],
  'headphones': ['headphone', 'headset', 'gaming headset'],
  'speakers': ['speaker', 'soundbar'],
  'webcam': ['webcam', 'camera'],
  'wireless-network-card': ['wireless', 'wifi', 'network'],
  'wired-network-card': ['network', 'ethernet', 'lan'],
  'sound-card': ['sound card', 'audio'],
  'external-hard-drive': ['external hard', 'portable ssd', 'usb drive'],
  'optical-drive': ['optical', 'blu-ray', 'dvd', 'cd'],
  'ups': ['ups', 'battery backup', 'power backup'],
  'case-accessory': ['case accessory'],
  'fan-controller': ['fan controller'],
  'thermal-paste': ['thermal paste', 'thermal compound'],
  'os': ['windows', 'operating system'],
};

const startTime = Date.now();
let totalItemsAcrossAllCategories = 0;
let processedItemsAcrossAllCategories = 0;

function updateProgress(current, total, category) {
  if (total === 0) return;
  const pct = Math.round((current / total) * 100);
  const barWidth = 25;
  const filled = Math.round((current / total) * barWidth);
  const bar = '\u2588'.repeat(filled) + '\u2591'.repeat(barWidth - filled);

  const elapsed = (Date.now() - startTime) / 1000;
  const rate = current > 0 ? current / elapsed : 0;
  const eta = rate > 0 ? (total - current) / rate : 0;
  const etaMins = Math.floor(eta / 60);
  const etaSecs = Math.floor(eta % 60);

  const totalPct = totalItemsAcrossAllCategories > 0
    ? Math.round(((processedItemsAcrossAllCategories + current) / totalItemsAcrossAllCategories) * 100)
    : 0;

  process.stdout.write(`\r${' '.repeat(120)}\r`);
  process.stdout.write(
    `\x1b[36m${category}\x1b[0m [${bar}] \x1b[33m${pct}%\x1b[0m ` +
    `(\x1b[32m${current}/${total}\x1b[0m) ` +
    `\x1b[90mETA: ${etaMins}m ${etaSecs}s | Overall: ${totalPct}%\x1b[0m`
  );

  if (current === total) process.stdout.write('\n');

  try {
    const status = [
      `Last Updated: ${new Date().toISOString()}`,
      `Category: ${category} | ${current}/${total} (${pct}%)`,
      `Overall: ${totalPct}% (${processedItemsAcrossAllCategories + current}/${totalItemsAcrossAllCategories})`,
      `Elapsed: ${Math.floor(elapsed / 60)}m ${Math.floor(elapsed % 60)}s`,
      eta > 0 ? `ETA: ${etaMins}m ${etaSecs}s` : 'ETA: calculating...',
      `Rate: ${rate.toFixed(1)} items/s`,
    ].join('\n');
    fs.writeFileSync(STATUS_FILE, status);
  } catch (e) {}
}

function log(msg) {
  const timestamp = new Date().toISOString();
  console.log(`\n[${timestamp}] ${msg}`);
  try { fs.appendFileSync(LOG_FILE, `[${timestamp}] ${msg}\n`); } catch (e) {}
}

function loadProgress() {
  try {
    if (fs.existsSync(PROGRESS_FILE)) {
      return JSON.parse(fs.readFileSync(PROGRESS_FILE, 'utf-8'));
    }
  } catch (e) {}
  return { categories: {}, completedCategories: [], lastRun: null };
}

function saveProgress(progress) {
  progress.lastRun = new Date().toISOString();
  fs.writeFileSync(PROGRESS_FILE, JSON.stringify(progress, null, 2));
}

async function fetchWithTimeout(url, timeout = CONFIG.TIMEOUT) {
  const controller = new AbortController();
  const id = setTimeout(() => controller.abort(), timeout);
  try {
    const res = await fetch(url, {
      signal: controller.signal,
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language': 'en-GB,en;q=0.9',
      }
    });
    clearTimeout(id);
    return res;
  } catch (e) {
    clearTimeout(id);
    throw e;
  }
}

function isRealProductImage(url, category) {
  if (EXCLUDE_IMAGE_PATTERNS.some(p => p.test(url))) return false;
  if (url.length < 50) return false;
  const validExt = /\.(jpg|jpeg|png|webp|avif|jfif)(\?.*)?$/i.test(url);
  if (!validExt) return false;

  const keywords = CATEGORY_IMAGE_KEYWORDS[category] || [];
  if (keywords.length > 0) {
    const urlLower = url.toLowerCase();
    if (!keywords.some(k => urlLower.includes(k.replace(/\s+/g, '')) ||
        urlLower.includes(k.replace(/\s+/g, '_')) ||
        urlLower.includes(k.replace(/\s+/g, '-')))) {
      return keywords.length <= 2;
    }
  }
  return true;
}

async function verifyImageContentType(url) {
  try {
    const controller = new AbortController();
    const id = setTimeout(() => controller.abort(), 8000);
    const resp = await fetch(url, {
      method: 'HEAD',
      signal: controller.signal,
      headers: { 'User-Agent': 'Mozilla/5.0' },
      redirect: 'follow',
    });
    clearTimeout(id);
    if (!resp.ok) return false;
    const ct = resp.headers.get('content-type') || '';
    const cl = parseInt(resp.headers.get('content-length') || '0');
    if (!ct.startsWith('image/')) return false;
    if (cl > 0 && cl < 1024) return false;
    return true;
  } catch (e) {
    return false;
  }
}

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
        /srcset="([^"]+)"/gi,
      ];

      for (const pattern of patterns) {
        const matches = html.matchAll(pattern);
        for (const match of matches) {
          if (allUrls.size >= 10) break;
          const u = match[1].split(',')[0].trim().split(' ')[0];
          if (u && u.length > 50 && isRealProductImage(u, category)) {
            allUrls.set(u, (allUrls.get(u) || 0) + 1);
          }
        }
      }

      const linkMatches = [...html.matchAll(/href="(https?:\/\/[^"']+\/(?:[^"']*?(?:product|item|p|dp)[^"']*))"/gi)];
      const productLinks = new Set();
      for (const m of linkMatches) {
        if (productLinks.size >= 3) break;
        const link = m[1];
        if (link && !/\/cart|\/login|\/wishlist|\/basket/i.test(link)) productLinks.add(link);
      }

      for (const pl of productLinks) {
        if (allUrls.size >= 10) break;
        try {
          const resp = await fetchWithTimeout(pl);
          if (!resp.ok) continue;
          const pageHtml = await resp.text();

          const ogMatch = pageHtml.match(/<meta[^>]*property=["']og:image["'][^>]*content=["']([^"']+)["'][^>]*>/i);
          if (ogMatch && ogMatch[1] && ogMatch[1].length > 80 && isRealProductImage(ogMatch[1], category)) {
            allUrls.set(ogMatch[1], (allUrls.get(ogMatch[1]) || 0) + 2);
          }

          const galleryMatches = pageHtml.matchAll(
            /<img[^>]+(?:data-zoom-image|data-large-image|data-src|src)=["'](https?:\/\/[^"']+\.(?:jpg|jpeg|png|webp|avif)[^"']*)["'][^>]*>/gi
          );
          for (const gm of galleryMatches) {
            if (allUrls.size >= 10) break;
            const u = gm[1];
            if (u && u.length > 50 && isRealProductImage(u, category)) {
              allUrls.set(u, (allUrls.get(u) || 0) + 1);
            }
          }
        } catch (e) {}
      }
    } catch (e) {}
  }

  const sorted = [...allUrls.entries()].sort((a, b) => b[1] - a[1]);
  return sorted.map(([url]) => url);
}

async function verifyAndDownload(url, dest) {
  const valid = await verifyImageContentType(url);
  if (!valid) return false;
  try {
    const controller = new AbortController();
    const id = setTimeout(() => controller.abort(), CONFIG.IMAGE_TIMEOUT);
    const resp = await fetch(url, {
      signal: controller.signal,
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept': 'image/webp,image/apng,image/*,*/*',
      },
      redirect: 'follow',
    });
    clearTimeout(id);
    if (!resp.ok) return false;
    const buffer = Buffer.from(await resp.arrayBuffer());
    if (buffer.length < 2048) return false;
    fs.writeFileSync(dest, buffer);
    return true;
  } catch (e) {
    return false;
  }
}

async function scrapePrices(name) {
  const searchName = name.replace(/[^\w\s]/g, ' ').trim().substring(0, 100);
  const allPrices = [];

  for (const [retailer, urlFunc] of Object.entries(RETAILERS)) {
    try {
      const url = urlFunc(searchName);
      const response = await fetchWithTimeout(url, CONFIG.PRICE_TIMEOUT);
      if (!response.ok) continue;
      const html = await response.text();

      const priceMatches = html.match(/\xA3\s*(\d+(?:,\d{3})*(?:\.\d{2})?)/g);
      if (priceMatches) {
        for (const pm of priceMatches) {
          const price = parseFloat(pm.replace(/\xA3|,/g, ''));
          if (price > 1 && price < 20000) {
            allPrices.push(price);
          }
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
        } catch (e) {}
      }
    } catch (e) {}
  }

  return allPrices;
}

function sanitizeFilename(name) {
  return name.replace(/[^a-z0-9]/gi, '_').toLowerCase().substring(0, 50);
}

function parseCSVLine(line) {
  const values = [];
  let current = '';
  let inQuotes = false;
  for (const char of line) {
    if (char === '"') inQuotes = !inQuotes;
    else if (char === ',' && !inQuotes) { values.push(current); current = ''; }
    else { current += char; }
  }
  values.push(current);
  return values;
}

async function processCategory(filename, progress) {
  const filePath = path.join(DATA_DIR, filename);
  const category = filename.replace('.csv', '');
  if (!fs.existsSync(filePath)) { log(`Skipping ${filename} (not found)`); return { processed: 0, prices: 0, images: 0 }; }

  log(`\n${'='.repeat(60)}\nProcessing ${filename}\n${'='.repeat(60)}`);

  const content = fs.readFileSync(filePath, 'utf-8');
  const lines = content.split('\n');
  if (lines.length <= 1) return { processed: 0, prices: 0, images: 0 };

  const header = lines[0].split(',');
  let imageIdx = header.indexOf('image');
  const priceIdx = header.indexOf('price');
  const nameIdx = header.indexOf('name');

  if (imageIdx === -1) {
    header.push('image');
    imageIdx = header.length - 1;
    lines[0] = header.join(',');
  }

  const itemsToProcess = [];
  for (let i = 1; i < lines.length; i++) {
    const line = lines[i].trim();
    if (!line) continue;
    const parts = parseCSVLine(line);
    const name = parts[nameIdx]?.replace(/^"|"$/g, '') || '';
    if (!name) continue;

    const price = parseFloat(parts[priceIdx]?.replace(/^"|"$/g, '') || '0');
    const image = (parts[imageIdx]?.replace(/^"|"$/g, '') || '').trim();
    const needsPrice = !price || price <= 0;
    const needsImage = !image || (!image.startsWith('thumbnails/') && !image.startsWith('http'));

    if (needsPrice || needsImage) {
      itemsToProcess.push({ i, name, parts, needsPrice, needsImage });
    }
  }

  log(`${itemsToProcess.length} items need updates (${itemsToProcess.filter(x => x.needsPrice).length} need price, ${itemsToProcess.filter(x => x.needsImage).length} need images)`);
  if (itemsToProcess.length === 0) return { processed: 0, prices: 0, images: 0 };

  let pricesFound = 0;
  let imagesFound = 0;
  let processedOverall = 0;
  const startCategory = Date.now();

  for (let batchStart = 0; batchStart < itemsToProcess.length; batchStart += CONFIG.BATCH_SIZE) {
    const batch = itemsToProcess.slice(batchStart, batchStart + CONFIG.BATCH_SIZE);
    log(`Batch ${Math.floor(batchStart / CONFIG.BATCH_SIZE) + 1}/${Math.ceil(itemsToProcess.length / CONFIG.BATCH_SIZE)}`);

    for (let j = 0; j < batch.length; j++) {
      const item = batch[j];
      const { i, name, parts, needsPrice, needsImage } = item;
      let itemUpdated = false;

      if (needsPrice) {
        const prices = await scrapePrices(name);
        if (prices.length > 0) {
          const avg = prices.reduce((a, b) => a + b, 0) / prices.length;
          parts[priceIdx] = avg.toFixed(2);
          itemUpdated = true;
          pricesFound++;
        }
      }

      if (needsImage) {
        const urls = await scrapeRetailerImages(name, category);
        let downloaded = false;
        for (const url of urls) {
          const extMatch = url.match(/\.(jpg|jpeg|png|webp|avif)/i);
          const ext = extMatch ? extMatch[0] : '.jpg';
          const local = `${category}_${sanitizeFilename(name)}_0${ext}`;
          const dest = path.join(THUMBNAILS_DIR, local);
          if (await verifyAndDownload(url, dest)) {
            parts[imageIdx] = `thumbnails/${local}`;
            downloaded = true;
            itemUpdated = true;
            imagesFound++;
            break;
          }
        }
      }

      if (itemUpdated) lines[i] = parts.join(',');
      processedOverall++;
      processedItemsAcrossAllCategories++;
      updateProgress(batchStart + j + 1, itemsToProcess.length, category);
    }

    fs.writeFileSync(filePath, lines.join('\n'), 'utf-8');
    log(`Batch done. Category total: ${pricesFound} prices, ${imagesFound} images`);

    progress.categories[filename] = {
      processed: batchStart + batch.length,
      total: itemsToProcess.length,
      pricesFound,
      imagesFound,
      elapsed: Math.round((Date.now() - startCategory) / 1000),
    };
    saveProgress(progress);
  }

  const elapsed = Math.round((Date.now() - startCategory) / 1000);
  log(`${filename} done: ${processedOverall} items, ${pricesFound} prices, ${imagesFound} images in ${elapsed}s`);
  return { processed: processedOverall, prices: pricesFound, images: imagesFound };
}

async function main() {
  log('\n' + '='.repeat(60));
  log('MASTER UPDATE - Starting full run');
  log('='.repeat(60));

  const progress = loadProgress();
  const uniqueCats = [...new Set(CATEGORIES)];
  let totalPrices = 0;
  let totalImages = 0;

  totalItemsAcrossAllCategories = 0;
  for (const cat of uniqueCats) {
    const fp = path.join(DATA_DIR, cat);
    if (fs.existsSync(fp)) {
      const lines = fs.readFileSync(fp, 'utf-8').split('\n').filter(l => l.trim()).length - 1;
      totalItemsAcrossAllCategories += lines;
    }
  }

  for (const cat of uniqueCats) {
    if (progress.completedCategories?.includes(cat)) {
      log(`Skipping ${cat} (already completed)`);
      continue;
    }

    const filePath = path.join(DATA_DIR, cat);
    if (!fs.existsSync(filePath)) {
      log(`Skipping ${cat} (not found)`);
      continue;
    }

    const result = await processCategory(cat, progress);
    totalPrices += result.prices;
    totalImages += result.images;

    if (!progress.completedCategories) progress.completedCategories = [];
    progress.completedCategories.push(cat);
    saveProgress(progress);

    if (cat !== uniqueCats[uniqueCats.length - 1]) {
      log(`Cooling down...`);
      await new Promise(r => setTimeout(r, CONFIG.DELAY_BETWEEN_CATEGORIES));
    }
  }

  const totalElapsed = Math.round((Date.now() - startTime) / 60);
  log('\n' + '='.repeat(60));
  log(`MASTER UPDATE COMPLETE - ${totalElapsed} minutes`);
  log(`TOTAL: ${totalPrices} prices found, ${totalImages} images downloaded`);
  log('='.repeat(60));

  try {
    fs.writeFileSync(STATUS_FILE, `COMPLETED: ${new Date().toISOString()}\nTotal prices: ${totalPrices}\nTotal images: ${totalImages}\nElapsed: ${totalElapsed}m`);
  } catch (e) {}
}

main().catch(err => {
  log(`Fatal: ${err.message}\n${err.stack}`);
  process.exit(1);
});
