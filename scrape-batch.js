import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const DATA_DIR = path.join(__dirname, 'src', 'data');
const THUMBNAILS_DIR = path.join(__dirname, 'public', 'thumbnails');

if (!fs.existsSync(THUMBNAILS_DIR)) {
  fs.mkdirSync(THUMBNAILS_DIR, { recursive: true });
}

const BATCH_SIZE = 10;
const DELAY_BETWEEN_BATCHES = 45000;
const DELAY_BETWEEN_ITEMS = 3000;

const RETAILERS = [
  'overclockers.co.uk',
  'scan.co.uk', 
  'ebuyer.com',
  'currys.co.uk'
];

async function scrapeRetailerSite(name, retailer) {
  const urls = [];
  const searchName = name.replace(/\s+/g, '+');
  
  const searchUrls = {
    'overclockers.co.uk': `https://www.overclockers.co.uk/search?search=${encodeURIComponent(searchName)}`,
    'scan.co.uk': `https://www.scan.co.uk/search?q=${encodeURIComponent(searchName)}`,
    'ebuyer.com': `https://www.ebuyer.com/search?search=${encodeURIComponent(searchName)}`,
    'currys.co.uk': `https://www.currys.co.uk/search?q=${encodeURIComponent(searchName)}`
  };

  try {
    const url = searchUrls[retailer];
    if (!url) return urls;
    
    const response = await fetch(url, {
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8'
      }
    });

    if (response.status === 429 || response.status === 503) {
      return urls;
    }

    if (!response.ok) return urls;
    const html = await response.text();
    
    const imgMatches = html.matchAll(/<img[^>]+src="(https?:\/\/[^"']+\.(?:jpg|jpeg|png|webp|avif)[^"']*)"/gi);
    for (const match of imgMatches) {
      const u = match[1];
      if (!/pixel|tracking|analytics|logo|icon|placeholder|banner|ad/i.test(u) && u.length > 50) {
        urls.push(u);
      }
    }
    
    const srcsetMatches = html.matchAll(/srcset="(https?:\/\/[^"']+\.(?:jpg|jpeg|png|webp|avif)[^"']+)"/gi);
    for (const match of srcsetMatches) {
      const u = match[1].split(',')[0].trim().split(' ')[0];
      if (!/pixel|tracking|logo|icon|placeholder/i.test(u)) {
        urls.push(u);
      }
    }
    
    const dataSrcMatches = html.matchAll(/data-src="(https?:\/\/[^"']+\.(?:jpg|jpeg|png|webp|avif)[^"']+)"/gi);
    for (const match of dataSrcMatches) {
      urls.push(match[1]);
    }
    
  } catch (err) {}

  return [...new Set(urls)].slice(0, 5);
}

async function scrapeImageUrl(name, category) {
  const allScrapedUrls = new Set();

  for (const retailer of RETAILERS) {
    if (allScrapedUrls.size >= 3) break;
    
    const urls = await scrapeRetailerSite(name, retailer);
    for (const url of urls) {
      allScrapedUrls.add(url);
    }
    
    await new Promise(r => setTimeout(r, 1500));
  }

  return Array.from(allScrapedUrls);
}

async function downloadImage(url, dest) {
  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 30000);
    const response = await fetch(url, { signal: controller.signal });
    clearTimeout(timeoutId);

    if (!response.ok) return false;
    const arrayBuffer = await response.arrayBuffer();
    const buffer = Buffer.from(arrayBuffer);
    fs.writeFileSync(dest, buffer);
    return true;
  } catch (error) {
    return false;
  }
}

function sanitizeFilename(name) {
  return name.replace(/[^a-z0-9]/gi, '_').toLowerCase().substring(0, 50);
}

async function processCategory(filename) {
  const filePath = path.join(DATA_DIR, filename);
  const category = filename.replace('.csv', '');
  const categoryDir = path.join(THUMBNAILS_DIR);

  const content = fs.readFileSync(filePath, 'utf-8');
  const lines = content.split('\n');
  if (lines.length <= 1) return 0;

  const header = lines[0].split(',');
  let imageIdx = header.findIndex(h => h.trim() === 'image');
  let nameIdx = header.indexOf('name');

  if (imageIdx === -1) {
    header.push('image');
    imageIdx = header.length - 1;
    lines[0] = header.join(',');
  }

  const itemsToProcess = [];
  for (let i = 1; i < lines.length; i++) {
    const line = lines[i].trim();
    if (!line) continue;

    const parts = [];
    let current = '';
    let inQuotes = false;
    for (const char of line) {
      if (char === '"') inQuotes = !inQuotes;
      else if (char === ',' && !inQuotes) {
        parts.push(current);
        current = '';
      } else current += char;
    }
    parts.push(current);

    const partName = parts[nameIdx]?.replace(/^"|"$/g, '') || '';
    const currentImages = parts[imageIdx]?.replace(/^"|"$/g, '') || '';

    if (!partName) continue;

    if (!currentImages || (!currentImages.startsWith('http') && !currentImages.startsWith('thumbnails'))) {
      itemsToProcess.push({ i, partName, parts });
    }
  }

  console.log(`\n=== ${filename}: ${itemsToProcess.length} missing ===`);
  if (itemsToProcess.length === 0) return 0;

  let processed = 0;

  for (let i = 0; i < itemsToProcess.length; i += BATCH_SIZE) {
    const batch = itemsToProcess.slice(i, i + BATCH_SIZE);
    console.log(`\nBatch ${Math.floor(i / BATCH_SIZE) + 1}: items ${i + 1}-${i + batch.length}`);

    for (const item of batch) {
      const { partName, parts, i: lineIdx } = item;

      const scrapedUrls = await scrapeImageUrl(partName, category.replace(/-/g, ' '));

      if (scrapedUrls.length > 0) {
        for (let j = 0; j < scrapedUrls.length; j++) {
          const url = scrapedUrls[j];
          const extMatch = url.match(/\.(jpg|jpeg|png|webp|avif|jfif)/i);
          const ext = extMatch ? extMatch[0] : '.jpg';
          const localName = `${category}_${sanitizeFilename(partName)}_${j}${ext}`;
          const dest = path.join(THUMBNAILS_DIR, localName);

          if (await downloadImage(url, dest)) {
            parts[imageIdx] = `thumbnails/${localName}`;
            lines[lineIdx] = parts.join(',');
            processed++;
            process.stdout.write('\x1b[32m.\x1b[0m');
            break;
          }
        }
      } else {
        process.stdout.write('\x1b[90m.\x1b[0m');
      }

      await new Promise(r => setTimeout(r, DELAY_BETWEEN_ITEMS));
    }

    fs.writeFileSync(filePath, lines.join('\n'), 'utf-8');
    console.log(`\nSaved batch ${Math.floor(i / BATCH_SIZE) + 1}. Processed: ${processed}`);

    if (i + BATCH_SIZE < itemsToProcess.length) {
      console.log(`Waiting ${DELAY_BETWEEN_BATCHES / 1000}s before next batch...`);
      await new Promise(r => setTimeout(r, DELAY_BETWEEN_BATCHES));
    }
  }

  return processed;
}

async function run() {
  const categories = [
    'webcam.csv',
    'speakers.csv',
    'wireless-network-card.csv',
    'cpu.csv',
    'case-fan.csv',
    'headphones.csv',
    'gpu.csv',
    'cooler.csv',
    'mouse.csv',
    'keyboard.csv',
    'case.csv',
    'motherboard.csv',
    'power-supply.csv',
    'storage.csv',
    'ram.csv',
    'monitor.csv'
  ];

  let totalProcessed = 0;

  for (const cat of categories) {
    const filePath = path.join(DATA_DIR, cat);
    if (!fs.existsSync(filePath)) {
      console.log(`Skipping ${cat} (not found)`);
      continue;
    }

    const processed = await processCategory(cat);
    totalProcessed += processed;
    console.log(`\n=== ${cat} complete: ${processed} images added ===`);

    console.log('\n--- Cooling down 45s ---');
    await new Promise(r => setTimeout(r, 45000));
  }

  console.log(`\n=== TOTAL: ${totalProcessed} images added ===`);
}

const CATEGORY = process.argv[2] || null;

async function runSingle(cat) {
  const filePath = path.join(DATA_DIR, cat);
  if (!fs.existsSync(filePath)) {
    console.log(`File not found: ${cat}`);
    return;
  }
  await processCategory(cat);
}

if (CATEGORY) {
  runSingle(CATEGORY).then(() => console.log('Done')).catch(console.error);
} else {
  run().catch(console.error);
}