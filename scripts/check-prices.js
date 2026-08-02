const fs = require('fs');
const path = require('path');
const DATA_DIR = path.join(__dirname, '..', 'src', 'data');

function parseCSVLine(line) {
  const result = [];
  let current = '';
  let inQuotes = false;
  for (let i = 0; i < line.length; i++) {
    if (line[i] === '"') { inQuotes = !inQuotes; }
    else if (line[i] === ',' && !inQuotes) { result.push(current); current = ''; }
    else { current += line[i]; }
  }
  result.push(current);
  return result;
}

const files = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
for (const file of files) {
  const lines = fs.readFileSync(path.join(DATA_DIR, file), 'utf-8').split('\n').filter(Boolean);
  if (lines.length < 2) continue;
  const header = parseCSVLine(lines[0]);
  const priceIdx = header.indexOf('price');
  const nameIdx = header.indexOf('name');
  if (priceIdx < 0 || nameIdx < 0) continue;
  let withPrice = 0, total = 0;
  for (let i = 1; i < lines.length; i++) {
    const parts = parseCSVLine(lines[i]);
    total++;
    const p = parseFloat(parts[priceIdx]);
    if (p && p > 0) withPrice++;
  }
  const pct = total > 0 ? ((withPrice / total) * 100).toFixed(1) : '0';
  console.log(`${file}: ${withPrice}/${total} have prices (${pct}%)`);
}
