const fetchWithTimeout = async (url, timeout = 10000) => {
  const controller = new AbortController();
  const id = setTimeout(() => controller.abort(), timeout);
  try {
    const res = await fetch(url, { signal: controller.signal, headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', 'Accept-Language': 'en-GB,en;q=0.9' } });
    clearTimeout(id); return res;
  } catch (e) { clearTimeout(id); throw e; }
};

(async () => {
  const searchUrl = 'https://www.amazon.co.uk/s?k=AMD+Ryzen+7+9800X3D&i=computers';
  console.log('Searching:', searchUrl);
  const resp = await fetchWithTimeout(searchUrl);
  console.log('Status:', resp.status);
  const html = await resp.text();
  console.log('HTML length:', html.length);
  
  if (html.includes('captcha') || html.includes('robot') || html.includes('Type the characters')) {
    console.log('CAPTCHA detected!');
    console.log('Snippet:', html.substring(0, 500));
    return;
  }

  const seen = new Set();
  const linkPattern = /href="(\/[^"]*\/dp\/([A-Z0-9]{10})[^"]*)"/gi;
  let m;
  while ((m = linkPattern.exec(html)) !== null) {
    if (!seen.has(m[2])) {
      seen.add(m[2]);
      console.log('Found ASIN:', m[2], 'URL:', m[1].substring(0, 100));
      if (seen.size >= 3) break;
    }
  }
  console.log('Total unique ASINs found:', seen.size);

  if (seen.size === 0) {
    console.log('No product links found. Checking page content...');
    const bodyStart = html.indexOf('<body');
    if (bodyStart > 0) console.log('Body snippet:', html.substring(bodyStart, bodyStart + 500));
    return;
  }

  const firstAsin = [...seen][0];
  const firstLinkMatch = html.match(new RegExp('href="(/[^"]*\\/dp\\/' + firstAsin + '[^"]*)"'));
  if (firstLinkMatch) {
    const prodUrl = 'https://www.amazon.co.uk' + firstLinkMatch[1];
    console.log('\nFetching product page:', prodUrl.substring(0, 120));
    const prodResp = await fetchWithTimeout(prodUrl);
    console.log('Product status:', prodResp.status);
    const prodHtml = await prodResp.text();
    console.log('Product HTML length:', prodHtml.length);
    
    if (prodHtml.includes('captcha') || prodHtml.includes('robot') || prodHtml.includes('Type the characters')) {
      console.log('CAPTCHA on product page!');
      console.log('Snippet:', prodHtml.substring(0, 500));
      return;
    }

    const titleMatch = prodHtml.match(/<span[^>]*id="productTitle"[^>]*>([\s\S]*?)<\/span>/i);
    if (titleMatch) {
      console.log('Product title:', titleMatch[1].replace(/<[^>]+>/g, '').trim().substring(0, 100));
    } else {
      console.log('No productTitle found');
      const bodyStart = prodHtml.indexOf('<body');
      if (bodyStart > 0) console.log('Body snippet:', prodHtml.substring(bodyStart, bodyStart + 300));
    }

    const pricePatterns = [
      ['a-price-whole/fraction', /class="a-price-whole">([\d,]+)<.*?class="a-price-fraction">(\d+)/s],
      ['a-offscreen', /<span[^>]*class="a-offscreen"[^>]*>£([\d,]+\.?\d*)/],
      ['priceAmount', /"priceAmount":([\d.]+)/],
      ['priceblock', /class="a-price"[^>]*>[\s\S]*?£([\d,]+\.\d{2})/],
      ['priceSymbol', /£([\d,]+\.\d{2})/],
    ];
    for (const [label, p] of pricePatterns) {
      const m = prodHtml.match(p);
      if (m) {
        console.log(`Price match (${label}):`, m[0].substring(0, 80));
        break;
      }
    }
  }
})();
