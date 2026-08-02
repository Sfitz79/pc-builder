import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, '..');

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

// Build docyx name-to-chipset map
const docyxLines = fs.readFileSync(path.join(ROOT, 'scraped_data', 'docyx', 'video-card.csv'), 'utf-8').split(/\r?\n/).filter(l => l.trim());
const dH = parseCSVLine(docyxLines[0]);
const dNameIdx = dH.indexOf('name');
const dChipsetIdx = dH.indexOf('chipset');

const docyxMap = new Map();
for (let i = 1; i < docyxLines.length; i++) {
  const parts = parseCSVLine(docyxLines[i]);
  const name = (parts[dNameIdx] || '').trim().toLowerCase();
  const chipset = (parts[dChipsetIdx] || '').trim();
  if (name && chipset && !docyxMap.has(name)) {
    docyxMap.set(name, chipset);
  }
}

// Read our GPU CSV
const gpuPath = path.join(ROOT, 'src', 'data', 'gpu.csv');
const ourLines = fs.readFileSync(gpuPath, 'utf-8').split(/\r?\n/).filter(l => l.trim());
const h = parseCSVLine(ourLines[0]);
const nameIdx = h.indexOf('name');
const chipsetIdx = h.indexOf('chipset');
const skipIdx = h.indexOf('skip');

let fixed = 0, alreadyProper = 0, notFound = 0;

for (let i = 1; i < ourLines.length; i++) {
  const parts = parseCSVLine(ourLines[i]);
  const name = (parts[nameIdx] || '').trim().toLowerCase();
  const chipset = (parts[chipsetIdx] || '').trim();
  const skip = (parts[skipIdx] || '').trim();

  if (!name) continue;
  if (!docyxMap.has(name)) { notFound++; continue; }

  const properChipset = docyxMap.get(name);
  const isNumeric = /^\d+(\.\d+)?$/.test(chipset);
  const isCorrupted = isNumeric || !chipset || chipset === '';

  if (isCorrupted) {
    parts[chipsetIdx] = properChipset;
    ourLines[i] = parts.map(v => escapeCSV(v)).join(',');
    fixed++;
  } else {
    alreadyProper++;
  }
}

fs.writeFileSync(gpuPath, ourLines.join('\n'), 'utf-8');
console.log(`GPU chipset fix complete:`);
console.log(`  Fixed (numeric/empty → proper): ${fixed}`);
console.log(`  Already proper:                  ${alreadyProper}`);
console.log(`  Not found in docyx:              ${notFound}`);
