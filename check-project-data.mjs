import fs from 'fs';
import path from 'path';

const dir = 'project/public/data';
if (!fs.existsSync(dir)) {
  console.log('No project/public/data directory');
  process.exit(0);
}
const files = fs.readdirSync(dir).filter(f => f.endsWith('.csv'));
console.log('Project data CSVs:', files.length);
for (const f of files) {
  const lines = fs.readFileSync(path.join(dir, f), 'utf-8').split('\n').filter(l => l.trim());
  const header = lines[0].split(',');
  const imgIdx = header.indexOf('image');
  let withUrl = 0, total = lines.length - 1;
  if (imgIdx >= 0) {
    for (let i = 1; i < lines.length; i++) {
      const parts = lines[i].split(',');
      if (parts[imgIdx] && (parts[imgIdx].startsWith('http') || parts[imgIdx].startsWith('thumbnails'))) withUrl++;
    }
  }
  console.log(`  ${f}: ${total} rows, img col idx=${imgIdx}, with images: ${withUrl}`);
}
