import fs from 'fs';

function parseLine(l) {
  const r = [];
  let c = '', q = false;
  for (const ch of l) {
    if (ch === '"') q = !q;
    else if (ch === ',' && !q) { r.push(c); c = ''; }
    else c += ch;
  }
  r.push(c);
  return r;
}

const cats = ['case-fan', 'case', 'power-supply', 'cpu', 'gpu', 'ram', 'storage', 'motherboard'];
for (const f of cats) {
  const p = 'src/data/' + f + '.csv';
  if (!fs.existsSync(p)) { console.log(f + ': file not found'); continue; }
  const lines = fs.readFileSync(p, 'utf-8').split('\n').map(l => l.trim()).filter(Boolean);
  const h = parseLine(lines[0]);
  const imgIdx = h.indexOf('image');
  let thumbs = 0, urls = 0, empty = 0, total = 0;
  for (let i = 1; i < lines.length; i++) {
    total++;
    const v = (parseLine(lines[i])[imgIdx] || '').trim();
    if (v.startsWith('thumbnails/')) thumbs++;
    else if (v.startsWith('http')) urls++;
    else empty++;
  }
  console.log(f + ': ' + total + ' total, ' + thumbs + ' thumbs, ' + urls + ' urls, ' + empty + ' empty');
}
