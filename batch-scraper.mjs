import fs from 'fs';
import path from 'path';
import sharp from 'sharp';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.join(__dirname, 'src', 'data');
const THUMB_DIR = path.join(__dirname, 'public', 'thumbnails');
fs.mkdirSync(THUMB_DIR, { recursive: true });

const RETAILERS = [
  { name: 'scan', url: (q) => `https://www.scan.co.uk/search?q=${encodeURIComponent(q)}` },
  { name: 'ebuyer', url: (q) => `https://www.ebuyer.com/search?search=${encodeURIComponent(q)}` },
  { name: 'overclockers', url: (q) => `https://www.overclockers.co.uk/search?search=${encodeURIComponent(q)}` },
];

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

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

function sanitizeFilename(name) {
  return name.replace(/[^a-z0-9]/gi, '_').toLowerCase().substring(0, 55);
}

async function scrapeOneProduct(name) {
  const searchQ = name.replace(/[^a-z0-9 ]/gi, ' ').replace(/\s+/g, ' ').trim();

  for (const r of RETAILERS) {
    try {
      const resp = await fetch(r.url(searchQ), {
        signal: AbortSignal.timeout(10000),
        headers: { 'User-Agent': UA, 'Accept': 'text/html,*/*' },
        redirect: 'follow',
      });
      if (!resp.ok) continue;
      const html = await resp.text();

      // Try different image patterns
      const patterns = [
        /<img[^>]+src="(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"[^>]*>/gi,
        /data-src="(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/gi,
        /srcset="([^"]+)"/gi,
      ];

      for (const pat of patterns) {
        const matches = [...html.matchAll(pat)];
        for (const m of matches) {
          let u = m[1].split(' ')[0].split(',')[0].trim();
          if (u.length > 50 && u.length < 500 &&
              !/pixel|tracking|logo|icon|avatar|placeholder|spacer|banner|data:|svg/i.test(u) &&
              /product|item|\d{5,}/i.test(u)) {
            return u;
          }
        }
      }
    } catch {}
  }

  // Fallback: try Amazon UK
  try {
    const resp = await fetch(`https://www.amazon.co.uk/s?k=${encodeURIComponent(searchQ)}`, {
      signal: AbortSignal.timeout(8000),
      headers: { 'User-Agent': UA, 'Accept': 'text/html,*/*' },
    });
    if (resp.ok) {
      const html = await resp.text();
      const m = html.match(/<img[^>]+src="(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*(?:AC|SL|product)[^"]*)"/i);
      if (m) return m[1];
    }
  } catch {}

  return null;
}

async function downloadImage(url, dest) {
  try {
    const resp = await fetch(url, {
      signal: AbortSignal.timeout(15000),
      headers: { 'User-Agent': UA, 'Accept': 'image/webp,image/apng,image/*,*/*' },
      redirect: 'follow',
    });
    if (!resp.ok) return false;
    const buf = Buffer.from(await resp.arrayBuffer());
    if (buf.length < 4096) return false;
    const meta = await sharp(buf).metadata();
    if (meta.width < 100 || meta.height < 100) return false;
    // Convert to JPEG for consistency
    await sharp(buf).jpeg({ quality: 85 }).toFile(dest);
    return true;
  } catch {
    return false;
  }
}

async function processBatch(csvFile, startIdx, batchSize) {
  const csvPath = path.join(DATA_DIR, csvFile);
  const content = fs.readFileSync(csvPath, 'utf-8');
  const rawLines = content.split('\n');
  const lines = rawLines.map(l => l.trim()).filter(l => l);
  if (lines.length < 2) return { done: 0, failed: 0, nextIdx: startIdx, totalLeft: 0 };

  const header = parseCSVLine(lines[0]);
  const imgIdx = header.indexOf('image');
  const nameIdx = header.indexOf('name');
  if (imgIdx === -1 || nameIdx === -1) return { done: 0, failed: 0, nextIdx: startIdx, totalLeft: 0 };

  const category = csvFile.replace('.csv', '');

  // Build list of items needing images starting from startIdx
  const items = [];
  for (let i = startIdx; i < lines.length; i++) {
    const parts = parseCSVLine(lines[i]);
    const name = (parts[nameIdx] || '').replace(/^"|"$/g, '').trim();
    if (!name) continue;
    const existing = (parts[imgIdx] || '').trim();
    if (existing.startsWith('thumbnails/')) {
      const tf = path.join(THUMB_DIR, existing.replace('thumbnails/', ''));
      if (fs.existsSync(tf)) continue;
    }
    if (existing.startsWith('http')) continue;
    items.push({ i, name, parts });
    if (items.length >= batchSize) break;
  }

  if (items.length === 0) {
    return { done: 0, failed: 0, nextIdx: lines.length, totalLeft: 0 };
  }

  let done = 0, failed = 0;

  for (const item of items) {
    const sanitized = sanitizeFilename(item.name);
    const dest = path.join(THUMB_DIR, `${category}_${sanitized}.jpg`);

    if (fs.existsSync(dest)) {
      const p = parseCSVLine(lines[item.i]);
      p[imgIdx] = `thumbnails/${category}_${sanitized}.jpg`;
      lines[item.i] = p.join(',');
      done++;
      continue;
    }

    const url = await scrapeOneProduct(item.name);
    const delay = 2000 + Math.random() * 1000; // be polite
    await new Promise(r => setTimeout(r, delay));

    if (url && await downloadImage(url, dest)) {
      const p = parseCSVLine(lines[item.i]);
      p[imgIdx] = `thumbnails/${category}_${sanitized}.jpg`;
      lines[item.i] = p.join(',');
      done++;
      process.stdout.write('.');
    } else {
      failed++;
      process.stdout.write('x');
    }
  }

  // Save progress
  fs.writeFileSync(csvPath, lines.join('\n'), 'utf-8');
  const nextIdx = items[items.length - 1].i + 1;
  const remaining = lines.length - nextIdx;
  return { done, failed, nextIdx, totalLeft: remaining, items };
}

export { processBatch, sanitizeFilename, THUMB_DIR, DATA_DIR };
