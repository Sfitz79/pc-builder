const https = require('https');
const http = require('http');

function fetch(url) {
  return new Promise((resolve, reject) => {
    const client = url.startsWith('https') ? https : http;
    const req = client.get(url, { headers: { 'User-Agent': 'Mozilla/5.0' } }, (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => resolve({ ok: res.statusCode === 200, text: () => data }));
    });
    req.on('error', reject);
    setTimeout(() => { req.destroy(); reject(new Error('timeout')); }, 10000);
  });
}

async function test() {
  const query = 'rtx 4090 graphics card';
  const url = `https://r.jina.ai/http://www.bing.com/images/search?q=${encodeURIComponent(query)}`;
  
  console.log('Testing jina.ai proxy...');
  try {
    const response = await fetch(url);
    if (!response.ok) { console.log('Failed:', response.status); return; }
    
    const text = await response.text();
    const matches = text.match(/https?:\/\/[^\s"'<>]+\.(?:jpg|jpeg|png|webp)/gi) || [];
    const filtered = matches.filter(u => !/bing|jina|pixel|logo/i.test(u)).slice(0, 5);
    
    console.log('Found images:', filtered.length);
    filtered.forEach(u => console.log(' -', u.substring(0, 100)));
  } catch (e) {
    console.log('Error:', e.message);
  }
}

test();
