import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.resolve(__dirname, '..', 'src', 'data');

const whatIf = process.argv.includes('--what-if');
const verbose = process.argv.includes('--verbose');

let totalSkipped = 0;
let totalUpdated = 0;

function parseCSVLine(line) {
  const values = [];
  let current = '';
  let inQuotes = false;
  for (const char of line) {
    if (char === '"') {
      inQuotes = !inQuotes;
    } else if (char === ',' && !inQuotes) {
      values.push(current.trim());
      current = '';
    } else {
      current += char;
    }
  }
  values.push(current.trim());
  return values;
}

function escapeCSV(value) {
  if (value === null || value === undefined) return '';
  const str = String(value);
  if (str.includes(',') || str.includes('"') || str.includes('\n')) {
    return `"${str.replace(/"/g, '""')}"`;
  }
  return str;
}

function readLines(filePath) {
  const text = fs.readFileSync(filePath, 'utf-8');
  // Split on newlines, keep all lines (including empty ones that might be meaningful)
  const lines = text.split(/\r?\n/);
  // Remove trailing empty lines but keep internal ones
  while (lines.length > 0 && lines[lines.length - 1].trim() === '') {
    lines.pop();
  }
  return lines;
}

function writeLines(filePath, lines) {
  fs.writeFileSync(filePath, lines.join('\n'), 'utf-8');
}

function processFile(filename, fixFn) {
  const filePath = path.join(DATA_DIR, filename);
  if (!fs.existsSync(filePath)) {
    console.log(`  SKIP: ${filename} not found`);
    return;
  }

  const lines = readLines(filePath);
  if (lines.length < 2) {
    console.log(`  SKIP: ${filename} too short`);
    return;
  }

  const headerParts = parseCSVLine(lines[0]);
  const priceIdx = headerParts.indexOf('price');
  const skipIdx = headerParts.indexOf('skip');
  const numCols = headerParts.length;

  console.log(`  Processing ${filename} (${lines.length - 1} data rows)`);

  let skipped = 0;
  let updated = 0;

  // Process header line (index 0) and data lines
  const result = [lines[0]]; // keep header as-is

  for (let i = 1; i < lines.length; i++) {
    const line = lines[i];
    const values = parseCSVLine(line);

    // Check if the line is malformed (wrong number of columns)
    const isMalformed = values.length !== numCols;

    let action = 'keep';
    if (isMalformed) {
      action = 'skip';
    } else {
      const item = {};
      for (let j = 0; j < numCols; j++) {
        item[headerParts[j]] = values[j];
      }
      action = fixFn(item, headerParts) || 'keep';
    }

    if (action === 'skip') {
      if (skipIdx >= 0) {
        const parts = parseCSVLine(line);
        if (parts.length > skipIdx) {
          parts[skipIdx] = '1';
          result.push(parts.map(v => escapeCSV(v)).join(','));
        } else {
          // Add empty fields up to skipIdx
          while (parts.length <= skipIdx) parts.push('');
          parts[skipIdx] = '1';
          result.push(parts.map(v => escapeCSV(v)).join(','));
        }
      } else {
        result.push(line);
      }
      skipped++;
    } else if (typeof action === 'object' && action !== null) {
      // Update specific fields
      const parts = parseCSVLine(line);
      for (const [key, val] of Object.entries(action)) {
        const idx = headerParts.indexOf(key);
        if (idx >= 0) parts[idx] = String(val);
      }
      result.push(parts.map(v => escapeCSV(v)).join(','));
      updated++;
    } else {
      result.push(line);
    }
  }

  totalSkipped += skipped;
  totalUpdated += updated;

  if (skipped > 0 || updated > 0) {
    console.log(`    → ${skipped} skipped, ${updated} updated`);
    if (!whatIf) {
      writeLines(filePath, result);
    }
  } else {
    console.log(`    → No changes needed`);
  }
}

console.log('=== PC Builder Price Fix Script ===\n');

// 1. Case CSV - skip entries at placeholder price £82.83
console.log('[1/6] Case CSV - marking £82.83 placeholder entries as skip=1');
processFile('case.csv', (item) => {
  if (item.price === '82.83') return 'skip';
});

// 2. RAM CSV - skip entries with empty/non-numeric prices
console.log('\n[2/6] RAM CSV - marking empty-price entries');
processFile('ram.csv', (item) => {
  if (item.price === '' || item.price === undefined) return 'skip';
  const p = parseFloat(item.price);
  if (isNaN(p) || p <= 0) return 'skip';
});

// 3. GPU CSV - skip entries with empty price
console.log('\n[3/6] GPU CSV - marking empty-price entries');
processFile('gpu.csv', (item) => {
  if (item.price === '' || item.price === undefined) return 'skip';
  const p = parseFloat(item.price);
  if (isNaN(p) || p <= 0) return 'skip';
});

// 4. CPU CSV - skip entries with empty or unreasonably low prices
console.log('\n[4/6] CPU CSV - fixing anomalous entries');
processFile('cpu.csv', (item) => {
  if (item.price === '' || item.price === undefined) return 'skip';
  const p = parseFloat(item.price);
  if (isNaN(p) || p < 10) return 'skip';
});

// 5. All other CSVs - skip entries with empty/zero/non-numeric price and malformed rows
console.log('\n[5/6] All other CSVs - marking bad entries');
const csvs = [
  'cooler.csv', 'motherboard.csv', 'power-supply.csv', 'storage.csv',
  'case-fan.csv', 'keyboard.csv', 'mouse.csv', 'monitor.csv',
  'headphones.csv', 'speakers.csv', 'webcam.csv', 'wireless-network-card.csv',
  'wired-network-card.csv', 'sound-card.csv', 'optical-drive.csv',
  'ups.csv', 'fan-controller.csv', 'thermal-paste.csv',
  'case-accessory.csv', 'external-hard-drive.csv', 'os.csv'
];
for (const csv of csvs) {
  processFile(csv, (item) => {
    if (item.price === '' || item.price === undefined) return 'skip';
    const p = parseFloat(item.price);
    if (isNaN(p) || p <= 0) return 'skip';
  });
}

// 6. OS CSV - fix Windows 11 Pro Retail price
console.log('\n[6/6] OS CSV - fixing Windows 11 Pro Retail price');
processFile('os.csv', (item) => {
  if (item.name === 'Microsoft Windows 11 Pro Retail - Download 64-bit') {
    return { price: '189.99' };
  }
});

console.log(`\n=== Done: ${totalSkipped} total skipped, ${totalUpdated} total updated ===`);
if (whatIf) {
  console.log('NOTE: Run without --what-if to apply changes');
}
