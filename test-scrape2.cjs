const https = require('https');
const http = require('http');

function fetch(url) {
  return new Promise((resolve, reject) => {
    const client = url.startsWith('https') ? https : http;
    const req = client.get(url, { 
      headers: { 
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept': 'text/html,application/xhtml+xml'
      } 
    }, (res) => {
      if (res.statusCode === 301 || res.statusCode === 302) {
        return fetch(res.headers.location).then(resolve).catch(reject);
      }
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => resolve({ ok: res.statusCode === 200, text: () => data, status: res.statusCode }));
    });
    req.on('error', reject);
    setTimeout(() => { req.destroy(); reject(new Error('timeout')); }, 10000);
  });
}

async function test() {
  const query = 'rtx 4090 graphics card';
  
  // Try Bing Images directly
  console.log('Testing Bing Images search...');
  try {
    const url = `https://www.bing.com/images/search?q=${encodeURIComponent(query)}`;
    const response = await fetch(url);
    if (!response.ok) { console.log('Failed:', response.status); return; }
    
    const text = await response.text();
    const matches = text.match(/murl&quot;:&quot;(https?:\/\/[^"&]+)&quot;/gi) || [];
    console.log('Found murl matches:', matches.length);
    matches.slice(0, 3).forEach(m => {
      const urlMatch = m.match(/murl&quot;:&quot;(https?:\/\/[^"&]+)/);
      if (urlMatch) console.log(' -', urlMatch[1].substring(0, 100));
    });
    
    // Also try direct img src
    const imgMatches = text.match(/<img[^>]+src="(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp))"/gi) || [];
    console.log('Found img src matches:', imgMatches.length);
    imgMatches.slice(0, 3).forEach(m => {
      const match = m.match(/src="([^"]+)"/);
      if (match) console.log(' -', match[1].substring(0, 100));
    });
  } catch (e) {
    console.log('Error:', e.message);
  }
}

test();
