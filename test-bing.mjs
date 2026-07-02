import fs from 'fs';

async function testBing(name) {
  const url = 'https://r.jina.ai/http://www.bing.com/images/search?q=' + encodeURIComponent(name + ' product');
  try {
    const resp = await fetch(url, {
      signal: AbortSignal.timeout(30000),
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'X-Return-Format': 'markdown',
      }
    });
    console.log('Status:', resp.status, 'Content-Type:', resp.headers.get('content-type'));
    const text = await resp.text();
    console.log('Length:', text.length);
    console.log('First 300:', text.slice(0, 300));

    // Find image URLs
    const urls = text.match(/https?:\/\/[^\s"'<>()]+?\.(?:jpg|jpeg|png|webp)(?:\?[^\s"'<>]*)?/gi) || [];
    const filtered = urls.filter(u => u.length > 40 && u.length < 500 && !u.includes('bing.com') && !u.includes('jina.ai'));
    console.log('Filtered image URLs:', filtered.length);
    filtered.slice(0, 3).forEach(u => console.log('  ', u));

    // Also try direct HTML
    const resp2 = await fetch(url.replace('r.jina.ai/http://', 'r.jina.ai/http://'), {
      signal: AbortSignal.timeout(30000),
      headers: { 'User-Agent': 'Mozilla/5.0', 'Accept': 'text/html,*/*' },
    });
    const text2 = await resp2.text();
    const urls2 = text2.match(/https?:\/\/[^\s"'<>()]+?\.(?:jpg|jpeg|png|webp)/gi) || [];
    const filtered2 = urls2.filter(u => u.length > 40 && u.length < 500 && !u.includes('bing.com') && !u.includes('jina.ai'));
    console.log('\nHTML mode filtered URLs:', filtered2.length);
    filtered2.slice(0, 3).forEach(u => console.log('  ', u));

  } catch(e) {
    console.error('Error:', e.message);
  }
}

await testBing('Noctua NF-A12x25 PWM case fan');
