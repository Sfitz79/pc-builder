import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.join(__dirname, 'src', 'data');
const THUMBNAILS_DIR = path.join(__dirname, 'public', 'thumbnails');

// Get all actual thumbnail files
const thumbnailFiles = fs.readdirSync(THUMBNAILS_DIR);

function findMatchingImage(itemName, imgBasename) {
  const base = imgBasename.toLowerCase();
  // Try exact match first
  let found = thumbnailFiles.find(f => f.toLowerCase() === base);
  if (found) return found;
  
  // Try partial match (remove numbers/underscores)
  const parts = base.split('_');
  found = thumbnailFiles.find(f => {
    const fLower = f.toLowerCase();
    return parts.every(p => fLower.includes(p));
  });
  if (found) return found;
  
  return null;
}

async function main() {
  const files = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  let totalFixed = 0;
  
  for (const file of files) {
    const filePath = path.join(DATA_DIR, file);
    let content = fs.readFileSync(filePath, 'utf-8');
    const lines = content.split('\n');
    if (lines.length <= 1) continue;
      
    let modified = false;
      
    for (let i = 1; i < lines.length; i++) {
      if (!lines[i].trim()) continue;
        
      const parts = [];
      let current = '';
      let inQuotes = false;
      for (const char of lines[i]) {
        if (char === '"') {
          inQuotes = !inQuotes;
        } else if (char === ',' && !inQuotes) {
          parts.push(current);
          current = '';
        } else {
          current += char;
        }
      }
      parts.push(current);
        
      const imgField = parts[parts.length - 1];
      if (!imgField || !imgField.includes('thumbnails/')) continue;
        
      const imgs = imgField.replace(/"/g, '').split(',').map(s => s.trim()).filter(Boolean);
      let needsFix = false;
        
      const newImgs = imgs.map(img => {
        if (!img || img.startsWith('http')) return img;
          
        const imgPath = path.join(THUMBNAILS_DIR, path.basename(img));
        if (fs.existsSync(imgPath)) return img;
          
        // Try to find matching image
        const found = findMatchingImage(parts[0], path.basename(img));
        if (found) {
          needsFix = true;
          return `thumbnails/${found}`;
        }
          
        return img;
      });
        
      if (needsFix) {
        parts[parts.length - 1] = `"${newImgs.join(',')}"`;
        lines[i] = parts.join(',');
        modified = true;
        totalFixed++;
      }
    }
      
    if (modified) {
      fs.writeFileSync(filePath, lines.join('\n'));
      console.log(`Fixed: ${file}`);
    }
  }
    
  console.log(`\nTotal image paths fixed: ${totalFixed}`);
}

main().catch(console.error);
