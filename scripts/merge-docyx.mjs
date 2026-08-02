import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');
const DATA_DIR = path.join(ROOT, 'src', 'data');
const DOCYX_DIR = path.join(ROOT, 'scraped_data', 'docyx');

const FILE_MAP = {
  'memory.csv': 'ram.csv',
  'video-card.csv': 'gpu.csv',
  'cpu-cooler.csv': 'cooler.csv',
  'internal-hard-drive.csv': 'storage.csv',
};

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

const docyxFiles = fs.readdirSync(DOCYX_DIR).filter(f => f.endsWith('.csv'));

let totalUpdated = 0, totalAdded = 0;

for (const df of docyxFiles) {
  const targetFile = FILE_MAP[df] || df;
  const docyxPath = path.join(DOCYX_DIR, df);
  const targetPath = path.join(DATA_DIR, targetFile);

  if (!fs.existsSync(targetPath)) {
    console.log(`SKIP: ${targetFile} does not exist`);
    continue;
  }

  const docyxLines = fs.readFileSync(docyxPath, 'utf-8').split(/\r?\n/).filter(l => l.trim());
  if (docyxLines.length < 2) {
    console.log(`SKIP: ${df} has no data`);
    continue;
  }

  const ourLines = fs.readFileSync(targetPath, 'utf-8').split(/\r?\n/).filter(l => l.trim());
  const ourHeader = parseCSVLine(ourLines[0]);
  const priceIdx = ourHeader.indexOf('price');
  const skipIdx = ourHeader.indexOf('skip');
  const imageIdx = ourHeader.indexOf('image');

  // Build name index from our CSV (only active items)
  const nameToLineIdx = new Map();
  for (let i = 1; i < ourLines.length; i++) {
    const parts = parseCSVLine(ourLines[i]);
    const name = (parts[0] || '').trim().toLowerCase();
    const skip = String(parts[skipIdx] ?? '').trim();
    if (name && skip !== '1') {
      // Only store first occurrence (skip duplicates)
      if (!nameToLineIdx.has(name)) {
        nameToLineIdx.set(name, i);
      }
    }
  }

  let updated = 0, added = 0;

  for (let i = 1; i < docyxLines.length; i++) {
    const dParts = parseCSVLine(docyxLines[i]);
    const dName = (dParts[0] || '').trim();
    if (!dName) continue;
    const dPrice = dParts[1] ? dParts[1].trim() : '';
    if (!dPrice || isNaN(parseFloat(dPrice)) || parseFloat(dPrice) <= 0) continue;

    const key = dName.toLowerCase();

    if (nameToLineIdx.has(key)) {
      // Update existing item's price
      const ourIdx = nameToLineIdx.get(key);
      const ourParts = parseCSVLine(ourLines[ourIdx]);
      const ourPrice = ourParts[priceIdx] ? ourParts[priceIdx].trim() : '';
      if (ourPrice !== dPrice) {
        ourParts[priceIdx] = dPrice;
        ourLines[ourIdx] = ourParts.map(v => escapeCSV(v)).join(',');
        updated++;
      }
    } else {
      // Add as new row
      const newRow = [];
      for (let h = 0; h < ourHeader.length; h++) {
        if (h < dParts.length) {
          newRow.push(dParts[h]);
        } else if (h === imageIdx) {
          newRow.push('');
        } else if (h === skipIdx) {
          newRow.push('0');
        } else {
          newRow.push('');
        }
      }
      // If docyx has more columns than our header, ignore extras
      ourLines.push(newRow.map(v => escapeCSV(v)).join(','));
      nameToLineIdx.set(key, ourLines.length - 1);
      added++;
    }
  }

  fs.writeFileSync(targetPath, ourLines.join('\n'), 'utf-8');
  totalUpdated += updated;
  totalAdded += added;

  const fromFile = targetFile === df ? df : `${df} → ${targetFile}`;
  console.log(`${fromFile}: ${updated} price updates, ${added} new items added`);
}

console.log(`\nTotal: ${totalUpdated} prices updated, ${totalAdded} new items added`);
