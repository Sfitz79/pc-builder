const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

async function test() {
  try {
    const resp = await fetch('https://uk.pcpartpicker.com/products/case-fan/', {
      signal: AbortSignal.timeout(15000),
      headers: { 'User-Agent': UA, 'Accept': 'text/html,*/*', 'Cookie': 'region=uk; currency=gbp' },
    });
    console.log('Status:', resp.status);
    const html = await resp.text();
    console.log('Length:', html.length);
    console.log('First 500 chars:', html.slice(0, 500));

    const imgRe = /<img[^>]+src="(https?:\/\/cdna\.pcpartpicker\.com\/[^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"/gi;
    const imgMatches = [...html.matchAll(imgRe)];
    console.log('\nDirect product images found:', imgMatches.length);
    imgMatches.slice(0, 6).forEach(m => console.log('  ', m[1]));

    // Try a search
    console.log('\n--- Searching ---');
    const searchResp = await fetch('https://uk.pcpartpicker.com/products/case-fan/?search=Noctua+NF-A12x25', {
      signal: AbortSignal.timeout(15000),
      headers: { 'User-Agent': UA, 'Accept': 'text/html,*/*', 'Cookie': 'region=uk; currency=gbp' },
    });
    if (searchResp.ok) {
      const sHtml = await searchResp.text();
      console.log('Search length:', sHtml.length);
      const sImgMatches = [...sHtml.matchAll(imgRe)];
      console.log('Search images found:', sImgMatches.length);
      sImgMatches.slice(0, 4).forEach(m => console.log('  ', m[1]));
    } else {
      console.log('Search status:', searchResp.status);
    }
  } catch(e) { console.error('Error:', e.message); }
}

test();
