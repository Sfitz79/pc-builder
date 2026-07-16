const FIRECRAWL_API = 'https://api.firecrawl.dev/v2';
const FIRECRAWL_TOKEN = 'fc-5f8f7334d7ee4be3a0afe9869f9ed69d';

async function testFirecrawl() {
  // Test 1: Amazon UK search page
  console.log('=== Test 1: Amazon UK search page ===');
  try {
    const searchUrl = 'https://www.amazon.co.uk/s?k=RTX+4070+Super&i=computers&rh=p_6%3AA3P5ROKF5B19Y3';
    console.log(`Scraping: ${searchUrl}`);
    const resp = await fetch(`${FIRECRAWL_API}/scrape`, {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${FIRECRAWL_TOKEN}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ url: searchUrl, formats: ['markdown'], location: { country: 'GB', languages: ['en-GB'] } }),
    });
    const json = await resp.json();
    console.log(`Status: ${resp.status}`);
    if (json.data) {
      const md = json.data.markdown || '';
      console.log(`Markdown length: ${md.length} chars`);
      // Extract ASINs
      const asins = [...md.matchAll(/\/dp\/([A-Z0-9]{10})/gi)].map(m => m[1]);
      console.log(`ASINs found: ${asins.slice(0, 5).join(', ')}`);
      // Extract prices
      const prices = [...md.matchAll(/£([\d,]+\.\d{2})/g)].map(m => m[1]);
      console.log(`Prices found: ${prices.slice(0, 5).join(', ')}`);
      // Print first 500 chars of markdown
      console.log('\nFirst 500 chars of markdown:');
      console.log(md.substring(0, 500));
    } else {
      console.log('No data returned');
      console.log(JSON.stringify(json, null, 2));
    }
  } catch (e) {
    console.log(`Error: ${e.message}`);
  }

  // Test 2: Amazon UK product page
  console.log('\n=== Test 2: Amazon UK product page (RTX 4070 Super) ===');
  try {
    const productUrl = 'https://www.amazon.co.uk/dp/B0D5MQ8BXS';
    console.log(`Scraping: ${productUrl}`);
    const resp = await fetch(`${FIRECRAWL_API}/scrape`, {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${FIRECRAWL_TOKEN}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ url: productUrl, formats: ['markdown'], location: { country: 'GB', languages: ['en-GB'] } }),
    });
    const json = await resp.json();
    console.log(`Status: ${resp.status}`);
    if (json.data) {
      const md = json.data.markdown || '';
      console.log(`Markdown length: ${md.length} chars`);
      // Extract price
      const priceMatch = md.match(/£([\d,]+\.\d{2})/);
      console.log(`Price: ${priceMatch ? priceMatch[1] : 'not found'}`);
      // Check availability
      const unavail = /currently\s+unavailable|out\s+of\s+stock/i.test(md);
      console.log(`Available: ${!unavail}`);
      // Extract image
      const imgMatch = md.match(/!\[.*?\]\((https?:\/\/[^)]+m\.media-amazon\.com[^)]+\.(?:jpg|png|webp))/i);
      console.log(`Image: ${imgMatch ? imgMatch[1] : 'not found'}`);
      console.log('\nFirst 800 chars of markdown:');
      console.log(md.substring(0, 800));
    } else {
      console.log('No data returned');
      console.log(JSON.stringify(json, null, 2));
    }
  } catch (e) {
    console.log(`Error: ${e.message}`);
  }
}

testFirecrawl();
