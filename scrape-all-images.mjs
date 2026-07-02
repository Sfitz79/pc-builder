import fs from 'fs';
import path from 'path';
import sharp from 'sharp';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
export const DATA_DIR = path.join(__dirname, 'src', 'data');
export const THUMB_DIR = path.join(__dirname, 'public', 'thumbnails');
fs.mkdirSync(THUMB_DIR, { recursive: true });

export function parseCSVLine(line) {
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

export function sanitizeFilename(name) {
  return name.replace(/[^a-z0-9]/gi, '_').toLowerCase().substring(0, 55);
}

// Try multiple image search sources
async function searchImage(productName, category) {
  const sources = [
    () => searchDuckDuckGo(productName, category),
    () => searchBingDirect(productName, category),
  ];

  for (const src of sources) {
    const url = await src();
    if (url) return url;
  }
  return null;
}

export async function searchDuckDuckGo(name, category) {
  const query = `${name} ${category}`;
  try {
    const resp = await fetch(
      `https://html.duckduckgo.com/html/?q=${encodeURIComponent(query + ' product image')}`,
      {
        signal: AbortSignal.timeout(10000),
        headers: {
          'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
          'Accept': 'text/html,*/*',
        }
      }
    );
    if (!resp.ok) return null;
    const html = await resp.text();
    
    // Extract image URLs from DuckDuckGo results (they show thumbnails)
    const matches = html.match(/src="(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp)(?:\?[^"]*)?)"/gi);
    if (!matches) return null;

    for (const m of matches) {
      const u = m.replace(/^src="/, '').replace(/"$/, '');
      if (u.length > 40 && u.length < 500 &&
          !/duckduckgo|pixel|tracking|logo|icon|avatar|placeholder/i.test(u) &&
          (u.includes('product') || u.includes('item') || /\d{5,}/.test(u))) {
        return u;
      }
    }
  } catch {}
  return null;
}

export async function searchBingDirect(name, category) {
  const query = `${name} ${category}`;
  try {
    const resp = await fetch(
      `https://www.bing.com/images/search?q=${encodeURIComponent(query + ' pc component')}&form=HDRSC2`,
      {
        signal: AbortSignal.timeout(10000),
        headers: {
          'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
          'Accept': 'text/html,*/*',
          'Accept-Language': 'en-GB,en;q=0.9',
        }
      }
    );
    if (!resp.ok) return null;
    const html = await resp.text();

    // Bing uses murl in JSON-like format
    const murls = html.match(/"murl":"([^"]+)"/g);
    if (murls) {
      for (const m of murls) {
        const u = m.replace(/"murl":"/, '').replace(/"$/, '').replace(/\\u002f/g, '/').replace(/\\/g, '');
        if (u.startsWith('http') && !u.includes('bing.com') && u.length < 500) return u;
      }
    }

    // Also try direct img tags
    const imgMatches = html.match(/src="(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/gi);
    if (imgMatches) {
      for (const m of imgMatches) {
        const u = m.replace(/^src="/, '').replace(/"$/, '').split('?')[0];
        if (u.length > 40 && u.length < 500 && !u.includes('bing.com') && !/pixel|tracking/i.test(u)) {
          return u;
        }
      }
    }
  } catch {}
  return null;
}

export async function downloadImage(url, dest) {
  try {
    const resp = await fetch(url, {
      signal: AbortSignal.timeout(15000),
      headers: { 'User-Agent': 'Mozilla/5.0', 'Accept': 'image/webp,image/apng,image/*,*/*' },
      redirect: 'follow',
    });
    if (!resp.ok) return false;
    const buf = Buffer.from(await resp.arrayBuffer());
    if (buf.length < 4096) return false;
    const meta = await sharp(buf).metadata();
    if (meta.width < 100 || meta.height < 100) return false;
    await sharp(buf).jpeg({ quality: 85 }).toFile(dest);
    return true;
  } catch { return false; }
}

function createPlaceholder(dest, name) {
  return false;
}

function escapeXml(s) {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

async function processCategory(csvFile) {
  const csvPath = path.join(DATA_DIR, csvFile);
  const content = fs.readFileSync(csvPath, 'utf-8');
  const rawLines = content.split('\n');
  const lines = rawLines.map(l => l.trim()).filter(l => l);
  if (lines.length < 2) return { total: 0, done: 0, failed: 0, placeholdered: 0 };

  const header = parseCSVLine(lines[0]);
  const imgIdx = header.indexOf('image');
  const nameIdx = header.indexOf('name');
  if (imgIdx === -1 || nameIdx === -1) return { total: 0, done: 0, failed: 0, placeholdered: 0 };

  const category = csvFile.replace('.csv', '');

  // Collect items needing images
  const items = [];
  for (let i = 1; i < lines.length; i++) {
    const parts = parseCSVLine(lines[i]);
    const name = (parts[nameIdx] || '').replace(/^"|"$/g, '').trim();
    if (!name) continue;
    const existing = (parts[imgIdx] || '').trim();
    const sanitized = sanitizeFilename(name);
    const dest = path.join(THUMB_DIR, `${category}_${sanitized}.jpg`);

    if (existing.startsWith('thumbnails/')) {
      const tf = path.join(THUMB_DIR, existing.replace('thumbnails/', ''));
      if (fs.existsSync(tf)) continue;
    }
    if (existing.startsWith('http')) continue;

    // Check if we already have this file from a previous run
    if (fs.existsSync(dest)) {
      const p = parseCSVLine(lines[i]);
      p[imgIdx] = `thumbnails/${category}_${sanitized}.jpg`;
      lines[i] = p.join(',');
      continue;
    }

    items.push({ i, name, parts, sanitized, dest });
  }

  if (items.length === 0) return { total: 0, done: 0, failed: 0, placeholdered: 0 };

  console.log(`  ${csvFile}: ${items.length} products need images`);

  let done = 0, failed = 0, placeholdered = 0;
  const batchSize = 200;

  for (let start = 0; start < items.length; start += batchSize) {
    const batch = items.slice(start, start + batchSize);
    console.log(`    Batch ${Math.floor(start / batchSize) + 1}/${Math.ceil(items.length / batchSize)} (${batch.length} items)...`);

    for (const item of batch) {
      // Try to find an image
      const url = await searchImage(item.name, category);
      if (url) {
        if (await downloadImage(url, item.dest)) {
          done++;
          process.stdout.write('.');
        } else {
          // Try placeholder
          if (await createPlaceholder(item.dest, item.name)) {
            placeholdered++;
            process.stdout.write('p');
          } else {
            failed++;
            process.stdout.write('x');
          }
        }
      } else {
        // Generate placeholder as last resort
        if (await createPlaceholder(item.dest, item.name)) {
          placeholdered++;
          process.stdout.write('p');
        } else {
          failed++;
          process.stdout.write('x');
        }
      }

      // Update CSV
      const p = parseCSVLine(lines[item.i]);
      p[imgIdx] = `thumbnails/${category}_${item.sanitized}.jpg`;
      lines[item.i] = p.join(',');

      await new Promise(r => setTimeout(r, 1500 + Math.random() * 1000));
    }

    fs.writeFileSync(csvPath, lines.join('\n'), 'utf-8');
    console.log(`\n    Batch done: ${done} OK, ${placeholdered} placeholders, ${failed} FAIL`);
  }

  return { total: items.length, done, failed, placeholdered };
}

async function main() {
  console.log('=== Scraping Product Images ===\n');

  const categories = [
    'case-fan.csv',   // 67 broken + ~2157 missing
    'case.csv',       // 6626 missing
    'power-supply.csv', // 379 missing
    'cpu.csv',        // 14 missing
    'gpu.csv',        // 2 missing
    'ram.csv',        // 11 missing
    'storage.csv',    // 8 missing
    'motherboard.csv', // 5 missing
  ];

  const start = Date.now();
  let grandTotal = 0, grandDone = 0, grandPlaceholder = 0;

  for (const cat of categories) {
    console.log(`\n--- ${cat} ---`);
    const r = await processCategory(cat);
    if (r.total > 0) {
      grandTotal += r.total;
      grandDone += r.done;
      grandPlaceholder += r.placeholdered;
      console.log(`  Result: ${r.done} found, ${r.placeholdered} placeholders, ${r.failed} failed`);
    }
  }

  const elapsed = ((Date.now() - start) / 1000 / 60).toFixed(1);
  console.log(`\n=== DONE in ${elapsed} min ===`);
  console.log(`Total: ${grandTotal} items, ${grandDone} with real images, ${grandPlaceholder} placeholders`);

  const totalFiles = fs.readdirSync(THUMB_DIR).filter(f => /\.(jpg|jpeg|png|webp)$/i.test(f)).length;
  console.log(`Total thumbnails on disk: ${totalFiles}`);
}

main().catch(e => { console.error(e); process.exit(1); });
