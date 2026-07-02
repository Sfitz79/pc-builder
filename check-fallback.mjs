import fs from 'fs';
import path from 'path';

const dir = 'scraped_data/fallback';
if (!fs.existsSync(dir)) {
  console.log('No fallback directory');
  process.exit(0);
}
const files = fs.readdirSync(dir).filter(f => f.endsWith('.csv'));
console.log('Fallback CSVs:', files.length);
for (const f of files) {
  const lines = fs.readFileSync(path.join(dir, f), 'utf-8').split('\n').filter(l => l.trim());
  const header = lines[0].split(',');
  const imgIdx = header.indexOf('image');
  let withUrls = 0;
  if (imgIdx >= 0) {
    for (let i = 1; i < Math.min(lines.length, 10); i++) {
      const parts = lines[i].split(',');
      if (parts[imgIdx] && parts[imgIdx].startsWith('http')) withUrls++;
    }
  }
  // Count all
  let totalWithUrl = 0;
  if (imgIdx >= 0) {
    for (let i = 1; i < lines.length; i++) {
      const parts = lines[i].split(',');
      if (parts[imgIdx] && parts[imgIdx].startsWith('http')) totalWithUrl++;
    }
  }
  console.log(`  ${f}: ${lines.length-1} rows, image col idx=${imgIdx}, first 10 with URLs: ${withUrls}, total with URLs: ${totalWithUrl}`);
}
