const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

const tests = [
  { name: 'Scan UK', url: 'https://www.scan.co.uk/shop/computer-hardware/all/fans-cooling/case-fans?q=Noctua+NF-A12x25' },
  { name: 'eBay UK', url: 'https://www.ebay.co.uk/sch/i.html?_nkw=Noctua+NF-A12x25+case+fan' },
  { name: 'Amazon UK', url: 'https://www.amazon.co.uk/s?k=Noctua+NF-A12x25&rh=n%3A430329031' },
  { name: 'Newegg UK', url: 'https://www.newegg.com/p/pl?d=Noctua+NF-A12x25' },
];

async function testImages(name, url) {
  try {
    const resp = await fetch(url, {
      signal: AbortSignal.timeout(15000),
      headers: { 'User-Agent': UA, 'Accept': 'text/html,*/*', 'Accept-Language': 'en-GB,en;q=0.9' },
      redirect: 'follow',
    });
    if (!resp.ok) { console.log(`${name}: Status ${resp.status}`); return; }
    const html = await resp.text();
    console.log(`${name}: ${html.length} bytes`);
    
    const imgRe = /<img[^>]+src="(https?:\/\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/gi;
    const matches = [...html.matchAll(imgRe)];
    console.log(`  Images: ${matches.length}`);
    const valid = matches.filter(m => {
      const u = m[1];
      return u.length > 30 && u.length < 500 && !/pixel|icon|logo|spacer|tracking|svg/i.test(u);
    });
    console.log(`  Valid: ${valid.length}`);
    if (valid.length > 0) {
      valid.slice(0, 3).forEach(m => console.log(`    ${m[1].slice(0, 150)}`));
    }
  } catch(e) {
    console.log(`${name}: Error - ${e.message}`);
  }
}

(async () => {
  for (const t of tests) {
    await testImages(t.name, t.url);
    await new Promise(r => setTimeout(r, 3000));
  }
})();
