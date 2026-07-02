import { searchDuckDuckGo, searchBingDirect, downloadImage, sanitizeFilename, THUMB_DIR } from './scrape-all-images.mjs';

// Test on 3 products from case-fan that need images
const tests = [
  { name: 'Lian Li UNI FAN SL Infinity 3 Pack', category: 'case-fan' },
  { name: 'Noctua NF-A12x25 PWM', category: 'case-fan' },
  { name: 'Corsair iCUE LINK QX120 RGB Starter Kit 3 Pack', category: 'case-fan' },
];

for (const t of tests) {
  console.log(`\nSearching: ${t.name} (${t.category})`);
  console.log('DuckDuckGo...');
  const u1 = await searchDuckDuckGo(t.name, t.category);
  console.log('  Result:', u1 ? u1.slice(0, 150) : 'null');
  console.log('Bing...');
  const u2 = await searchBingDirect(t.name, t.category);
  console.log('  Result:', u2 ? u2.slice(0, 150) : 'null');

  const url = u1 || u2;
  if (url) {
    const sanitized = sanitizeFilename(t.name);
    const dest = `public/thumbnails/${t.category}_${sanitized}.jpg`;
    const ok = await downloadImage(url, dest);
    console.log('Download:', ok ? 'OK' : 'FAIL', '->', dest);
  }
}
