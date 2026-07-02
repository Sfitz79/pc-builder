import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const THUMBNAILS_DIR = path.join(__dirname, 'public', 'thumbnails');

let missing = 0;

// Check case.csv for missing images
const content = fs.readFileSync(path.join(__dirname, 'src', 'data', 'case.csv'), 'utf-8');
const lines = content.split('\n').filter(l => l.trim());

lines.slice(1, 10).forEach(line => {
  const parts = line.split(',');
  const imgField = parts[parts.length - 1];
  
  if (imgField && imgField.includes('thumbnails/')) {
    const imgs = imgField.replace(/"/g, '').split(',');
    imgs.forEach(img => {
      if (img && !img.startsWith('http')) {
        const imgPath = path.join(THUMBNAILS_DIR, path.basename(img));
        if (!fs.existsSync(imgPath)) {
          missing++;
          console.log('Missing:', img);
        }
      }
    });
  }
});

console.log('\nMissing images in first 10 lines:', missing);
