import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.join(__dirname, 'src', 'data');
const OUTPUT_DIR = path.join(__dirname, 'dist');

function parseCSVLine(line) {
  const values = [];
  let current = '';
  let inQuotes = false;
  for (let i = 0; i < line.length; i++) {
    const char = line[i];
    if (char === '"') {
      if (inQuotes && line[i + 1] === '"') { current += '"'; i++; }
      else inQuotes = !inQuotes;
    } else if (char === ',' && !inQuotes) { values.push(current); current = ''; }
    else current += char;
  }
  values.push(current);
  return values;
}

function extractAllData() {
  const files = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  const allProducts = {};

  for (const file of files) {
    const category = file.replace('.csv', '');
    const content = fs.readFileSync(path.join(DATA_DIR, file), 'utf-8');
    const lines = content.split(/\r?\n/).filter(l => l.trim());
    if (lines.length < 2) continue;

    const headers = parseCSVLine(lines[0]);
    const items = [];

    for (let i = 1; i < lines.length; i++) {
      const values = parseCSVLine(lines[i]);
      const item = {};
      headers.forEach((h, idx) => {
        let val = (values[idx] || '').trim();
        const num = Number(val);
        item[h] = (val !== '' && !isNaN(num)) ? num : val;
      });
      if (item.name) items.push(item);
    }

    allProducts[category] = items;
    console.log(`${file}: ${items.length} products`);
  }

  const outputPath = path.join(OUTPUT_DIR, 'product-feed.json');
  fs.writeFileSync(outputPath, JSON.stringify(allProducts, null, 2), 'utf-8');
  console.log(`\nProduct feed saved to ${outputPath}`);

  const totalProducts = Object.values(allProducts).reduce((sum, arr) => sum + arr.length, 0);
  console.log(`Total products: ${totalProducts} across ${Object.keys(allProducts).length} categories`);
}

extractAllData();
