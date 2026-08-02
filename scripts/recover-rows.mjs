import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.resolve(__dirname, '..', 'src', 'data');

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

const KNOWN_SCHEMAS = {
  // ram.csv: name,price,speed,modules,price_per_gb,color,first_word_latency,cas_latency,image,skip
  // Some old rows have: name,price,type,speed,modules,size,rating,color,timing,skip,?,?
  'ram.csv': (parts, numCols) => {
    if (parts.length === 12) {
      // DDR4-style: name,price,type,speed,modules,size_per_module,?,color,timing,skip,,
      const type = parts[2]; // "4" or "5" for DDR4/DDR5
      const speed = parts[3];
      const moduleCount = parseInt(parts[4]) || 1;
      const sizePerModule = parseInt(parts[5]) || 0;
      const totalGB = moduleCount * sizePerModule;
      const price = parseFloat(parts[1]);
      const pricePerGB = price > 0 && totalGB > 0 ? (price / totalGB).toFixed(3) : '';
      return [
        parts[0],                   // name
        parts[1],                   // price
        `DDR${type}-${speed}`,      // speed
        `${moduleCount} x ${sizePerModule}GB`, // modules
        pricePerGB,                 // price_per_gb
        parts[7],                   // color
        '',                         // first_word_latency
        parts[8],                   // cas_latency (timing)
        '',                         // image
        '0',                        // skip
      ];
    }
    return null;
  },

  // cooler.csv: name,price,rpm,noise_level,color,size,image,skip
  // Some rows have extra columns: name,price,rpm_low,rpm_high,noise_low,noise_high,color,size,skip,?
  'cooler.csv': (parts, numCols) => {
    if (parts.length === 9) {
      // Has 9 cols: name,price,rpm,noise_level,extra?,color,size,skip,?
      // Cols 0-1 match header. Col 2 might be rpm_low, col 3 rpm_high, etc.
      const price = parseFloat(parts[1]);
      if (isNaN(price) || price <= 0) return null;
      return [
        parts[0],  // name
        parts[1],  // price
        parts[2] && parts[3] ? `${parts[2]} - ${parts[3]} RPM` : (parts[2] || ''), // rpm
        parts[4] || '',  // noise_level (may be in col 4)
        parts[5] || '',  // color (may be in col 5)
        parts[6] || '',  // size (may be in col 6)
        '', '',          // image, skip
      ];
    }
    if (parts.length === 10) {
      return [
        parts[0], parts[1],
        parts[2] && parts[3] ? `${parts[2]} - ${parts[3]} RPM` : '',
        parts[4] || '',
        parts[5] || '',
        parts[6] || '',
        '', '',
      ];
    }
    return null;
  },

  // For CSVs where we just extract name + price and ignore other columns
  'default': (parts, numCols) => {
    const price = parseFloat(parts[1]);
    if (isNaN(price) || price <= 0) return null;
    const row = [parts[0], parts[1]];
    // Fill remaining columns as empty
    for (let i = 2; i < numCols; i++) row.push('');
    return row;
  }
};

function processFile(filename) {
  const filePath = path.join(DATA_DIR, filename);
  if (!fs.existsSync(filePath)) {
    console.log(`  SKIP: ${filename} not found`);
    return;
  }

  const text = fs.readFileSync(filePath, 'utf-8');
  const lines = text.split(/\r?\n/);
  while (lines.length > 0 && lines[lines.length - 1].trim() === '') lines.pop();

  if (lines.length < 2) { console.log(`  SKIP: ${filename} too short`); return; }

  const header = parseCSVLine(lines[0]);
  const numCols = header.length;
  const skipIdx = header.indexOf('skip');
  const priceIdx = header.indexOf('price');

  const schema = KNOWN_SCHEMAS[filename] || KNOWN_SCHEMAS['default'];

  let recovered = 0;
  const result = [lines[0]];

  for (let i = 1; i < lines.length; i++) {
    const line = lines[i];
    const parts = parseCSVLine(line);

    if (parts.length === numCols) {
      // Already correct column count — keep as-is
      // But ensure skip field is set properly
      if (skipIdx >= 0 && parts.length > skipIdx) {
        const price = parseFloat(parts[priceIdx]);
        if (parts[skipIdx] === '1' && !isNaN(price) && price > 0) {
          // Row has valid price but is skipped — recover it
          parts[skipIdx] = '0';
          result.push(parts.map(v => escapeCSV(v)).join(','));
          recovered++;
        } else {
          result.push(line);
        }
      } else {
        result.push(line);
      }
    } else if (parts.length > 1) {
      // Wrong column count — try to recover
      const recoveredRow = schema(parts, numCols);
      if (recoveredRow && recoveredRow.length === numCols) {
        result.push(recoveredRow.map(v => escapeCSV(v)).join(','));
        recovered++;
      } else {
        // Keep line as-is (already has skip=1 or will be caught by cleanup)
        result.push(line);
      }
    } else {
      result.push(line);
    }
  }

  if (recovered > 0) {
    fs.writeFileSync(filePath, result.join('\n'), 'utf-8');
    console.log(`  ${filename}: ${recovered} rows recovered`);
  } else {
    console.log(`  ${filename}: no recoverable rows`);
  }
}

console.log('=== Row Recovery Script ===\n');

const csvFiles = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv') && f !== 'master-prices.csv');
for (const f of csvFiles) {
  processFile(f);
}

console.log('\nDone. Run fix-prices.mjs to re-clean any still-invalid rows.');
