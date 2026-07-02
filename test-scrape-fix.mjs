import fs from 'fs';
import path from 'path';

async function testBing() {
  const url = 'https://r.jina.ai/http://www.bing.com/images/search?q=' + encodeURIComponent('Noctua NF-A12x25 PWM case fan pc component');
  try {
    const resp = await fetch(url, {
      signal: AbortSignal.timeout(15000),
      headers: { 
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
      }
    });
    console.log('Status:', resp.status);
    const text = await resp.text();
    console.log('Length:', text.length);
    console.log('First 500 chars:', text.slice(0, 500));
    const matches = text.match(/https?:\/\/[^\s"\'<>()]+?\.(?:jpg|jpeg|png|webp)/gi) || [];
    console.log('Image URL candidates:', matches.length);
    matches.slice(0, 5).forEach(u => console.log('  ', u.slice(0, 150)));
  } catch(e) { 
    console.error('Error:', e.message);
  }
}

async function testRetailer() {
  const name = 'Noctua NF-A12x25 PWM';
  const retailers = [
    'https://www.scan.co.uk/search?q=' + encodeURIComponent(name),
    'https://www.overclockers.co.uk/search?search=' + encodeURIComponent(name),
    'https://www.ebuyer.com/search?search=' + encodeURIComponent(name),
  ];
  for (const r of retailers) {
    try {
      const resp = await fetch(r, {
        signal: AbortSignal.timeout(10000),
        headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' }
      });
      console.log(r.match(/https?:\/\/([^/]+)/)[1], '->', resp.status);
    } catch(e) {
      console.log(r.match(/https?:\/\/([^/]+)/)[1], '-> ERROR:', e.message);
    }
  }
}

console.log('=== Testing Bing via jina.ai ===');
await testBing();
console.log('\n=== Testing Retailer Sites ===');
await testRetailer();
