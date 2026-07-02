import fs from 'fs';
const f = 'scraped_data/fallback/gpu.csv';
const h = fs.readFileSync(f, 'utf-8').split('\n')[0];
console.log('gpu.csv fallback header:', h);
const lines = fs.readFileSync(f, 'utf-8').split('\n').filter(l => l.trim());
console.log('Total rows:', lines.length);

// Find image column
const header = h.split(',');
console.log('Columns:', header.map((c,i) => i+':'+c).join(', '));
const imgIdx = header.indexOf('image');
console.log('Image col index:', imgIdx);

if (imgIdx >= 0) {
  let withUrl = 0;
  for (let i = 1; i < lines.length; i++) {
    const parts = lines[i].split(',');
    if (parts[imgIdx] && parts[imgIdx].startsWith('http')) withUrl++;
  }
  console.log('Rows with image URLs:', withUrl);
}
