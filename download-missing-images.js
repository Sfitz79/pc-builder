import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const THUMBNAILS_DIR = path.join(__dirname, 'public', 'thumbnails');

async function downloadImage(url, dest) {
  try {
    const response = await fetch(url, {
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
      }
    });
    if (!response.ok) return false;
    const arrayBuffer = await response.arrayBuffer();
    const buffer = Buffer.from(arrayBuffer);
    fs.writeFileSync(dest, buffer);
    return true;
  } catch (err) {
    return false;
  }
}

async function main() {
  let downloaded = 0;
  let failed = 0;
  
  // List of images to download (from PCPartPicker)
  const imagesToDownload = [
    'case_montech_xr_0.jpg',
    'case_montech_xr_1.jpg', 
    'case_montech_xr_2.jpg'
  ];
  
  for (const img of imagesToDownload) {
    const destPath = path.join(THUMBNAILS_DIR, img);
    if (fs.existsSync(destPath)) {
      console.log(`Skip (exists): ${img}`);
      continue;
    }
    
    // Try to find on PCPartPicker
    const searchUrl = `https://uk.pcpartpicker.com/search?q=${path.basename(img, path.extname(img)).replace(/_/g, ' ')}`;
    console.log(`Trying: ${img}...`);
    
    // For now, just log
    failed++;
  }
  
  console.log(`\nDownloaded: ${downloaded}, Failed: ${failed}`);
}

main().catch(console.error);
