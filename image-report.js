import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.join(__dirname, 'src', 'data');
const THUMBNAILS_DIR = path.join(__dirname, 'public', 'thumbnails');

const categories = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));

console.log('--- Image Source and Cache Report ---');
console.log(`Checking ${categories.length} categories...\n`);

let totalItems = 0;
let totalWithImages = 0;
let totalCached = 0;

categories.forEach(file => {
  const content = fs.readFileSync(path.join(DATA_DIR, file), 'utf-8');
  const lines = content.split('\n').filter(l => l.trim());
  if (lines.length <= 1) return;

  const header = lines[0].split(',');
  const imageIdx = header.lastIndexOf('image');
  const nameIdx = header.indexOf('name');

  let catItems = 0;
  let catWithImages = 0;
  let catCached = 0;

  for (let i = 1; i < lines.length; i++) {
    const line = lines[i];
    const parts = line.split(/,(?=(?:(?:[^"]*"){2})*[^"]*$)/);
    catItems++;
    
    const imageVal = parts[imageIdx];
    if (imageVal && imageVal.trim() && imageVal !== '""' && imageVal !== 'undefined') {
      catWithImages++;
      
      const images = imageVal.replace(/"/g, '').split(',').filter(Boolean);
      const firstImage = images[0];
      if (firstImage && firstImage.startsWith('thumbnails/')) {
        const localPath = path.join(__dirname, 'public', firstImage);
        if (fs.existsSync(localPath)) {
          catCached++;
        }
      }
    }
  }

  console.log(`${file.padEnd(20)}: ${catWithImages}/${catItems} have images (${catCached} cached)`);
  
  totalItems += catItems;
  totalWithImages += catWithImages;
  totalCached += catCached;
});

console.log('\n--- Summary ---');
console.log(`Total Items: ${totalItems}`);
console.log(`Items with Image Source: ${totalWithImages} (${((totalWithImages/totalItems)*100).toFixed(1)}%)`);
console.log(`Cached Thumbnails: ${totalCached} (${((totalCached/totalWithImages)*100).toFixed(1)}% of sources)`);
