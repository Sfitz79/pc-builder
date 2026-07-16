import fs from 'fs';

function parseCSVLine(line) {
  const values = [];
  let current = '';
  let inQuotes = false;
  for (const char of line) {
    if (char === '"') { inQuotes = !inQuotes; }
    else if (char === ',' && !inQuotes) { values.push(current.trim()); current = ''; }
    else { current += char; }
  }
  values.push(current.trim());
  return values;
}

function analyze(filename) {
  const text = fs.readFileSync(filename, 'utf-8');
  const lines = text.split(/\r?\n/).filter(l => l.trim());
  if (lines.length < 2) { console.log(`${filename}: too short`); return; }

  const header = parseCSVLine(lines[0]);
  const expected = header.length;
  const priceIdx = header.indexOf('price');

  let good = 0, bad = 0, emptyPrice = 0, zeroPrice = 0;

  for (let i = 1; i < lines.length; i++) {
    const vals = parseCSVLine(lines[i]);
    if (vals.length !== expected) {
      bad++;
    } else {
      good++;
      const price = vals[priceIdx] || '';
      if (price === '' || price === undefined) emptyPrice++;
      else if (parseFloat(price) <= 0) zeroPrice++;
    }
  }

  console.log(`\n${filename}:`);
  console.log(`  Header: ${expected} columns`);
  console.log(`  Total rows: ${lines.length - 1}`);
  console.log(`  Good (correct columns): ${good}`);
  console.log(`  Bad (wrong columns): ${bad}`);
  console.log(`  Empty price: ${emptyPrice}`);
  console.log(`  Zero/negative price: ${zeroPrice}`);
  console.log(`  Valid with price: ${good - emptyPrice - zeroPrice}`);
}

analyze('src/data/ram.csv');
analyze('src/data/cpu.csv');
analyze('src/data/gpu.csv');
analyze('src/data/case.csv');
analyze('src/data/mouse.csv');
analyze('src/data/keyboard.csv');
analyze('src/data/motherboard.csv');
analyze('src/data/cooler.csv');
