import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.join(__dirname, 'src', 'data');
const THUMBNAILS_DIR = path.join(__dirname, 'public', 'thumbnails');

let missing = 0;
const missingFiles = [];

const files = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));

files.forEach(file => {
  const content = fs.readFileSync(path.join(DATA_DIR, file), 'utf-8');
  const lines = content.split('\n').filter(l => l.trim());
  
  lines.slice(1).forEach((line, idx) => {
    const parts = line.split(',');
    const imgField = parts[parts.length - 1];
    
    if (imgField && imgField.includes('thumbnails/')) {
      const imgs = imgField.replace(/"/g, '').split(',');
      imgs.forEach(img => {
        if (img && !img.startsWith('http')) {
          const imgPath = path.join(THUMBNAILS_DIR, path.basename(img));
          if (!fs.existsSync(imgPath)) {
            missing++;
            if (missing <= 20) {
              missingFiles.push(img);
            }
          }
        }
      });
    }
  });
});

console.log('Total missing images:', missing);
if (missingFiles.length > 0) {
  console.log('\nFirst 20 missing images:');
  missingFiles.forEach(f => console.log(' -', f));
}
