/**
 * MERGE SCRAPED CSVs — Merges newly scraped WebScraper CSVs (with name4 image URLs)
 * into existing src/data/*.csv files, updating only name/price/image columns
 * while preserving all existing spec columns.
 *
 * Usage: node merge-scraped-csvs.mjs
 *
 * Looks for files matching uk-pcpartpicker-com-*.csv in Downloads,
 * auto-detects which category they belong to based on spec columns,
 * and merges them into the corresponding src/data/*.csv.
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.join(__dirname, 'src', 'data');
const DOWNLOAD_DIR = 'C:\\Users\\simon\\Downloads';

function parseCSVLine(line) {
  const values = [];
  let current = '', inQuotes = false;
  for (const char of line) {
    if (char === '"') inQuotes = !inQuotes;
    else if (char === ',' && !inQuotes) { values.push(current); current = ''; }
    else { current += char; }
  }
  values.push(current);
  return values;
}

function extractPrice(raw) {
  if (!raw) return null;
  const m = raw.match(/[£€$]\s*(\d+(?:,\d{3})*(?:\.\d{1,2})?)/);
  if (m) return parseFloat(m[1].replace(/,/g, ''));
  const n = raw.match(/(\d+(?:\.\d{1,2})?)/);
  if (n) { const p = parseFloat(n[1]); if (p > 1 && p < 20000) return p; }
  return null;
}

function readCSV(filePath) {
  if (!fs.existsSync(filePath)) return null;
  const content = fs.readFileSync(filePath, 'utf-8');
  const rawLines = content.split(/\r?\n/).filter(l => l.trim());
  if (rawLines.length < 2) return null;
  let hi = 0;
  if (rawLines.length >= 2 && rawLines[0] === rawLines[1]) hi = 1;
  const header = parseCSVLine(rawLines[hi]);
  const lines = rawLines.slice(hi);
  return { header, lines };
}

// Detect which category a scraped CSV belongs to by checking which
// existing CSV's name column best matches the scraped names
function detectCategory(scrapedNames) {
  const csvFiles = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  let bestCat = null, bestScore = 0;
  for (const f of csvFiles) {
    const cat = f.replace('.csv', '');
    const existing = readCSV(path.join(DATA_DIR, f));
    if (!existing) continue;
    const nameIdx = existing.header.indexOf('name');
    if (nameIdx === -1) continue;
    let matches = 0;
    for (const sn of scrapedNames) {
      for (let i = 1; i < existing.lines.length; i++) {
        const parts = parseCSVLine(existing.lines[i]);
        const en = (parts[nameIdx] || '').replace(/^"|"$/g, '').trim();
        if (en && (en === sn || en.includes(sn) || sn.includes(en))) {
          matches++;
          break;
        }
      }
    }
    if (matches > bestScore) { bestScore = matches; bestCat = cat; }
  }
  return bestCat;
}

function mergeCSV(scrapedFilePath, category) {
  const csvFile = path.join(DATA_DIR, `${category}.csv`);
  const existing = readCSV(csvFile);
  if (!existing) { console.log(`  ${category}: existing CSV not found, skipping`); return; }

  const scraped = readCSV(scrapedFilePath);
  if (!scraped) { console.log(`  ${category}: scraped CSV empty, skipping`); return; }

  const sNameIdx = scraped.header.indexOf('name');
  const sName4Idx = scraped.header.indexOf('name4');
  const sPriceIdx = scraped.header.indexOf('price');

  const eNameIdx = existing.header.indexOf('name');
  const ePriceIdx = existing.header.indexOf('price');
  let eImageIdx = existing.header.indexOf('image');
  if (eImageIdx === -1) {
    eImageIdx = existing.header.length;
    existing.header.push('image');
    existing.lines[0] = existing.header.join(',');
    for (let i = 1; i < existing.lines.length; i++) {
      if (existing.lines[i].trim()) existing.lines[i] += ',';
    }
  }

  // Build name→row index map from existing CSV
  const nameMap = new Map();
  for (let i = 1; i < existing.lines.length; i++) {
    const parts = parseCSVLine(existing.lines[i]);
    const name = (parts[eNameIdx] || '').replace(/^"|"$/g, '').trim();
    if (name) nameMap.set(name.toLowerCase(), i);
  }

  let updated = 0, added = 0, skipped = 0;

  for (let i = 1; i < scraped.lines.length; i++) {
    const parts = parseCSVLine(scraped.lines[i]);
    const name = (parts[sNameIdx] || '').replace(/^"|"$/g, '').trim();
    if (!name) continue;

    const imageUrl = (sName4Idx >= 0 ? (parts[sName4Idx] || '') : '').trim();
    const price = sPriceIdx >= 0 ? extractPrice(parts[sPriceIdx]) : null;

    const key = name.toLowerCase();
    if (nameMap.has(key)) {
      // Update existing row
      const rowIdx = nameMap.get(key);
      const rowParts = parseCSVLine(existing.lines[rowIdx]);

      // Update image
      if (imageUrl && imageUrl.startsWith('http')) {
        rowParts[eImageIdx] = imageUrl;
      }
      // Update price (if new price is valid and old one is empty/0)
      if (price !== null) {
        const oldPrice = ePriceIdx >= 0 ? parseFloat(rowParts[ePriceIdx]) : null;
        if (oldPrice === null || isNaN(oldPrice) || oldPrice === 0) {
          rowParts[ePriceIdx] = price.toString();
        }
      }
      existing.lines[rowIdx] = rowParts.join(',');
      updated++;
    } else {
      // Add new row
      const newRow = [];
      for (let ci = 0; ci < existing.header.length; ci++) {
        const h = existing.header[ci];
        if (h === 'name') newRow.push(name);
        else if (h === 'price') newRow.push(price !== null ? price.toString() : '');
        else if (h === 'image') newRow.push(imageUrl || '');
        else newRow.push('');
      }
      const quoted = newRow.map(v => v.includes(',') || v.includes('"') ? `"${v.replace(/"/g, '""')}"` : v);
      existing.lines.push(quoted.join(','));
      nameMap.set(key, existing.lines.length - 1);
      added++;
    }
  }

  // Write back
  const out = existing.lines.map((l, idx) => {
    if (idx === 0) return existing.header.join(',');
    return l;
  });
  fs.writeFileSync(csvFile, out.join('\n'), 'utf-8');
  console.log(`  ${category}: ${updated} updated, ${added} added, ${skipped} skipped`);
}

// Main
console.log('\n=== Merge Scraped CSVs ===\n');
const downloadedFiles = fs.readdirSync(DOWNLOAD_DIR)
  .filter(f => f.startsWith('uk-pcpartpicker-com-') && f.endsWith('.csv'))
  .sort();

if (downloadedFiles.length === 0) {
  console.log('No downloaded CSVs found in Downloads folder.');
  console.log('Run WebScraper export first, save files to Downloads, then run this script.');
  process.exit(0);
}

console.log(`Found ${downloadedFiles.length} scraped CSV(s) in Downloads:\n`);

for (const f of downloadedFiles) {
  const fp = path.join(DOWNLOAD_DIR, f);
  const scraped = readCSV(fp);
  if (!scraped) { console.log(`  ${f}: empty, skipping`); continue; }

  // Collect scraped names for category detection
  const nameIdx = scraped.header.indexOf('name');
  if (nameIdx === -1) { console.log(`  ${f}: no name column, skipping`); continue; }
  const names = [];
  for (let i = 1; i < scraped.lines.length; i++) {
    const parts = parseCSVLine(scraped.lines[i]);
    const n = (parts[nameIdx] || '').replace(/^"|"$/g, '').trim();
    if (n) names.push(n);
  }

  const category = detectCategory(names);
  if (!category) {
    console.log(`  ${f}: could not detect category (${names.length} items)`);
    continue;
  }

  console.log(`[${f}] → ${category}`);
  mergeCSV(fp, category);
}

console.log('\nDone. Now run: node download-thumbnails.mjs');
