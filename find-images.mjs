import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import sharp from 'sharp';

const THUMB_DIR = 'public/thumbnails';
fs.mkdirSync(THUMB_DIR, { recursive: true });

function sanitizeFilename(name) {
  return name.replace(/[^a-z0-9]/gi, '_').toLowerCase().substring(0, 55);
}

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

// Source 1: Wikipedia API
async function searchWikipedia(name) {
  try {
    const resp = await fetch(
      `https://en.wikipedia.org/w/api.php?action=query&list=search&srsearch=${encodeURIComponent(name + ' computer hardware')}&format=json&srlimit=3`,
      { signal: AbortSignal.timeout(8000), headers: { 'User-Agent': UA } }
    );
    if (!resp.ok) return null;
    const data = await resp.json();
    const pages = data?.query?.search || [];
    for (const page of pages.slice(0, 3)) {
      const imgResp = await fetch(
        `https://en.wikipedia.org/w/api.php?action=query&titles=${encodeURIComponent(page.title)}&prop=pageimages&format=json&pithumbsize=400`,
        { signal: AbortSignal.timeout(8000), headers: { 'User-Agent': UA } }
      );
      if (!imgResp.ok) continue;
      const imgData = await imgResp.json();
      const pages2 = imgData?.query?.pages || {};
      for (const id of Object.keys(pages2)) {
        const thumbnail = pages2[id]?.thumbnail?.source;
        if (thumbnail) return 'https:' + thumbnail;
      }
    }
  } catch {}
  return null;
}

// Source 2: Google Images via serpapi-free method (direct page fetch)
async function searchGoogleImages(name, category) {
  const queries = [
    `${name} ${category} product`,
    `${name} pc component`,
    `"${name}" computer`,
  ];
  for (const q of queries.slice(0, 2)) {
    try {
      const resp = await fetch(
        `https://www.google.com/search?tbm=isch&q=${encodeURIComponent(q)}`,
        {
          signal: AbortSignal.timeout(10000),
          headers: {
            'User-Agent': UA,
            'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language': 'en-GB,en;q=0.9',
          }
        }
      );
      if (!resp.ok) continue;
      const html = await resp.text();

      // Google image results use various formats
      const patterns = [
        /"(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp)(?:\?[^"]*)?)"/gi,
        /\["(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/gi,
        /"ou":"([^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/gi,
      ];

      for (const pat of patterns) {
        const matches = [...html.matchAll(pat)];
        for (const m of matches) {
          let u = m[1].replace(/\\u003d/g, '=').replace(/\\u0026/g, '&').replace(/\\x3d/g, '=').replace(/\\x26/g, '&');
          if (u.length > 50 && u.length < 500 &&
              !/google|gstatic|pixel|tracking|logo|icon|favicon/i.test(u) &&
              !u.includes('encrypted-tbn') && !u.startsWith('data:')) {
            return u;
          }
        }
      }
    } catch {}
  }
  return null;
}

// Source 3: Amazon product search
async function searchAmazon(name) {
  try {
    const resp = await fetch(
      `https://www.amazon.co.uk/s?k=${encodeURIComponent(name.replace(/[^a-z0-9 ]/gi, ' '))}&rh=n%3A430329031&s=relevancerank`,
      {
        signal: AbortSignal.timeout(8000),
        headers: {
          'User-Agent': UA,
          'Accept': 'text/html,*/*',
          'Accept-Language': 'en-GB,en;q=0.9',
        }
      }
    );
    if (!resp.ok) return null;
    const html = await resp.text();

    // Amazon product images
    const patterns = [
        /<img[^>]+src="(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*(?:AC_SL|AC_US|SX[0-9]+|SY[0-9]+)[^"]*)"/gi,
        /data-src="(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*(?:images-I|images-M)[^"]*)"/gi,
        /"image":"(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/gi,
    ];
    for (const pat of patterns) {
      const matches = [...html.matchAll(pat)];
      for (const m of matches) {
        let u = m[1].replace(/\\u002d/g, '-').replace(/\\u002f/g, '/');
        if (u.length > 50 && u.length < 500 &&
            u.includes('images-amazon.com') && !/pixel|tracking|icon/i.test(u)) {
          return u;
        }
      }
    }
  } catch {}
  return null;
}

// Source 4: Direct product database sites (TechPowerUp)
async function searchTechPowerUp(name) {
  const dbCategories = ['gpu', 'cpu', 'psu', 'case', 'cooler', 'ram'];
  const categoryMap = {
    'gpu': 'gpu', 'cpu': 'cpu', 'power-supply': 'psu', 'case': 'case',
    'cooler': 'cooler', 'ram': 'memory',
  };
  // Skip for now - TechPowerUp blocks automated requests
  return null;
}

// Source 5: Jina.ai + DuckDuckGo images (trying multiple formats)
async function searchViaJina(name, category) {
  const urls = [
    `https://r.jina.ai/http://www.bing.com/images/search?q=${encodeURIComponent(name + ' ' + category + ' product photo')}`,
    `https://r.jina.ai/http://www.google.com/search?tbm=isch&q=${encodeURIComponent(name + ' ' + category)}`,
  ];
  for (const url of urls) {
    try {
      const resp = await fetch(url, {
        signal: AbortSignal.timeout(15000),
        headers: {
          'User-Agent': UA,
          'Accept': 'text/plain,text/html,*/*',
          'X-Return-Format': 'markdown',
        }
      });
      if (!resp.ok) continue;
      const text = await resp.text();

      // Jina returns markdown with image links like ![alt](url)
      const mdMatches = text.match(/!\[.*?\]\((https?:\/\/[^\s)]+\.(?:jpg|jpeg|png|webp)[^\s)]*)\)/gi);
      if (mdMatches) {
        for (const m of mdMatches) {
          const u = m.replace(/!\[.*?\]\(/, '').replace(/\)$/, '');
          if (u.length > 40 && u.length < 500 &&
              !/r\.bing|jina\.ai|pixel|tracking|data:/i.test(u) &&
              (u.includes('.jpg') || u.includes('.png'))) {
            return u;
          }
        }
      }
    } catch {}
  }
  return null;
}

export async function findImage(productName, category) {
  // Try all sources in order
  console.error(`  Searching for: ${productName}`);
  
  const sources = [
    { name: 'Google Images', fn: () => searchGoogleImages(productName, category) },
    { name: 'Wikipedia', fn: () => searchWikipedia(productName) },
    { name: 'Jina/Bing', fn: () => searchViaJina(productName, category) },
    { name: 'Amazon', fn: () => searchAmazon(productName) },
  ];

  for (const src of sources) {
    try {
      const url = await src.fn();
      if (url) {
        console.error(`    Found via ${src.name}: ${url.slice(0, 120)}`);
        return url;
      }
    } catch {}
  }
  return null;
}

export async function downloadImage(url, dest) {
  try {
    const resp = await fetch(url, {
      signal: AbortSignal.timeout(15000),
      headers: { 'User-Agent': UA, 'Accept': 'image/webp,image/apng,image/*,*/*' },
      redirect: 'follow',
    });
    if (!resp.ok) return false;
    const buf = Buffer.from(await resp.arrayBuffer());
    if (buf.length < 4096) return false;
    const meta = await sharp(buf).metadata();
    if (meta.width < 100 || meta.height < 100) return false;
    await sharp(buf).jpeg({ quality: 85 }).toFile(dest);
    return true;
  } catch { return false; }
}

async function main() {
  console.log('Testing image search sources...\n');

  const tests = [
    { name: 'Corsair 4000D Airflow', category: 'case' },
    { name: 'Noctua NF-A12x25 PWM', category: 'case-fan' },
    { name: 'Lian Li UNI FAN SL Infinity 3 Pack', category: 'case-fan' },
    { name: 'Samsung 990 Pro 2TB', category: 'storage' },
    { name: 'NVIDIA GeForce RTX 4070', category: 'gpu' },
  ];

  for (const t of tests) {
    console.log(`\n=== ${t.name} (${t.category}) ===`);
    const url = await findImage(t.name, t.category);
    if (url) {
      const sanitized = sanitizeFilename(t.name);
      const dest = path.join(THUMB_DIR, `${t.category}_${sanitized}_test.jpg`);
      const ok = await downloadImage(url, dest);
      console.log(`  Downloaded: ${ok ? 'YES' : 'NO'} -> ${dest}\n`);
    } else {
      console.log('  NO IMAGE FOUND\n');
    }
  }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  main();
}
