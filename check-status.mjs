import fs from 'fs';

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

const content = fs.readFileSync('src/data/case-fan.csv', 'utf-8');
const lines = content.split('\n').map(l => l.trim()).filter(Boolean);
const header = parseCSVLine(lines[0]);
const imgIdx = header.indexOf('image');

let thumbs = 0, urls = 0, empty = 0, total = 0;
for (let i = 1; i < lines.length; i++) {
  total++;
  const parts = parseCSVLine(lines[i]);
  const v = (parts[imgIdx] || '').trim();
  if (v.startsWith('thumbnails/')) thumbs++;
  else if (v.startsWith('http')) urls++;
  else empty++;
}

const diskFiles = fs.readdirSync('public/thumbnails').filter(f => /\.(jpg|jpeg|png|webp)$/i.test(f)).length;

console.log('=== case-fan.csv status ===');
console.log('Total products:', total);
console.log('Has thumbnail ref:', thumbs);
console.log('Has URL ref:', urls);
console.log('Empty:', empty);
console.log('Files on disk:', diskFiles);

// Also check for broken refs
let broken = 0;
for (let i = 1; i < lines.length; i++) {
  const parts = parseCSVLine(lines[i]);
  const v = (parts[imgIdx] || '').trim();
  if (v.startsWith('thumbnails/')) {
    const thumbFile = v.replace('thumbnails/', '');
    if (!fs.existsSync('public/thumbnails/' + thumbFile)) {
      broken++;
      if (broken <= 5) console.log('  MISSING:', thumbFile);
    }
  }
}
console.log('Broken refs:', broken);
