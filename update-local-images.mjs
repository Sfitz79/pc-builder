import fs from 'fs';
import path from 'path';
import sharp from 'sharp';

const DATA_DIR = 'src/data';
const THUMB_DIR = 'public/thumbnails';

if (!fs.existsSync(THUMB_DIR)) fs.mkdirSync(THUMB_DIR, { recursive: true });

async function downloadImage(url, targetPath) {
  try {
    const res = await fetch(url, { signal: AbortSignal.timeout(10000) });
    if (!res.ok) return false;
    const buffer = Buffer.from(await res.arrayBuffer());
    if (buffer.length < 1000) return false;
    
    await sharp(buffer)
      .resize(400, 400, { fit: 'inside', withoutEnlargement: true })
      .toFormat('jpeg', { quality: 85 })
      .toFile(targetPath);
    return true;
  } catch (err) {
    return false;
  }
}

function sanitizeName(name) {
  return name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
}

async function processCSV(filename) {
  const filePath = path.join(DATA_DIR, filename);
  const category = filename.replace('.csv', '');
  const content = fs.readFileSync(filePath, 'utf-8');
  const lines = content.split('\n').filter(l => l.trim());
  if (lines.length <= 1) return;

  const header = lines[0].split(',').map(h => h.trim());
  let imageIdx = header.indexOf('image');
  if (imageIdx === -1) {
    header.push('image');
    imageIdx = header.length - 1;
    lines[0] = header.join(',');
    for (let i = 1; i < lines.length; i++) lines[i] += ',';
  }

  const nameIdx = header.indexOf('name');
  if (nameIdx === -1) return;

  console.log(`Processing ${filename}...`);
  let updatedCount = 0;

  for (let i = 1; i < lines.length; i++) {
    const parts = lines[i].split(',');
    if (parts.length <= nameIdx) continue;
    
    let imageUrl = parts[imageIdx];
    const name = parts[nameIdx];

    if (imageUrl && imageUrl.startsWith('http')) {
      const localName = `${category}_${sanitizeName(name)}.jpg`;
      const localPath = path.join(THUMB_DIR, localName);
      const localUrl = `thumbnails/${localName}`;

      process.stdout.write(`  [${i}/${lines.length-1}] Downloading image for ${name}... `);
      const success = await downloadImage(imageUrl, localPath);
      if (success) {
        parts[imageIdx] = localUrl;
        lines[i] = parts.join(',');
        console.log('Done.');
        updatedCount++;
      } else {
        console.log('Failed.');
      }
    }
  }

  if (updatedCount > 0) {
    fs.writeFileSync(filePath, lines.join('\n'), 'utf-8');
    console.log(`Updated ${updatedCount} images in ${filename}`);
  }
}

async function main() {
  const files = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  for (const file of files) {
    await processCSV(file);
  }
}

main();
