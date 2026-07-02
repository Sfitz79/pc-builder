import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.join(__dirname, 'src', 'data');
const THUMB_DIR = path.join(__dirname, 'public', 'thumbnails');

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

const files = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
let grandTotal = 0, grandWithImg = 0, grandCached = 0;

for (const f of files) {
  const content = fs.readFileSync(path.join(DATA_DIR, f), 'utf-8');
  const lines = content.split('\n').filter(l => l.trim());
  if (lines.length < 2) { console.log(f + ': empty'); continue; }

  const header = parseCSVLine(lines[0]);
  const imgIdx = header.indexOf('image');
  const nameIdx = header.indexOf('name');
  if (imgIdx === -1 || nameIdx === -1) {
    console.log(f + ': no image/name column');
    continue;
  }

  let total = 0, withImg = 0, cached = 0;
  for (let i = 1; i < lines.length; i++) {
    const parts = parseCSVLine(lines[i]);
    const name = (parts[nameIdx] || '').replace(/^"|"$/g, '').trim();
    if (!name) continue;
    total++;
    const img = (parts[imgIdx] || '').trim();
    if (img.startsWith('thumbnails/')) {
      withImg++;
      const thumbFile = path.join(THUMB_DIR, img.replace('thumbnails/', ''));
      if (fs.existsSync(thumbFile)) cached++;
    } else if (img.startsWith('http')) {
      withImg++;
    }
  }

  grandTotal += total;
  grandWithImg += withImg;
  grandCached += cached;
  const pct = ((withImg / total) * 100).toFixed(1);
  console.log(`${f}: ${total} items, ${withImg} with img (${pct}%), ${cached} cached`);
}

console.log(`\nTOTAL: ${grandTotal} items, ${grandWithImg} with img refs (${((grandWithImg/grandTotal)*100).toFixed(1)}%), ${grandCached} cached on disk`);
