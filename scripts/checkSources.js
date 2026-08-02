const SOURCES = [
  { name: 'VantaSpec', url: 'https://api.vantaspec.com/api/products/search?limit=10&region=uk', type: 'api' },
  { name: 'PCPartPicker_UK', url: 'https://api.apify.com/v2/acts/lulzasaur~pcpartpicker-scraper/run-sync-get-dataset-items', type: 'apify', body: { country: 'uk', maxResults: 10 } },
  { name: 'ScanAffiliateFeed', url: 'https://affiliate.scan.co.uk/feed/products.json', type: 'feed' },
  { name: 'OverclockersUK_Feed', url: 'https://www.overclockers.co.uk/feeds/products.json', type: 'feed' },
  { name: 'BoxUK_Feed', url: 'https://www.box.co.uk/affiliate/products.json', type: 'feed' },
  { name: 'Novatech_Feed', url: 'https://www.novatech.co.uk/feeds/products.json', type: 'feed' },
  { name: 'PriceSpy_UK', url: 'https://pricespy.co.uk/search?q=RTX+4070', type: 'scraper' },
  { name: 'Idealo_UK', url: 'https://www.idealo.co.uk/search?q=RTX+4070', type: 'scraper' },
  { name: 'CCL_Feed', url: 'https://www.cclonline.com/product-feed/', type: 'feed' },
  { name: 'Ebuyer_Feed', url: 'https://www.ebuyer.com/product-feed', type: 'feed' },
  { name: 'AMD_MSRP', url: 'https://www.amd.com/en/products', type: 'scraper' },
  { name: 'Intel_MSRP', url: 'https://www.intel.co.uk/content/www/uk/en/products/details/processors.html', type: 'scraper' },
  { name: 'NVIDIA_MSRP', url: 'https://www.nvidia.com/en-gb/geforce/graphics-cards/', type: 'scraper' },
];

async function checkSource(source) {
  try {
    let res;
    if (source.type === 'apify') {
      const token = process.env.APIFY_KEY;
      if (!token) { console.log(`[${source.name}] SKIPPED: no APIFY_KEY`); return; }
      res = await fetch(`${source.url}?token=${encodeURIComponent(token)}`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(source.body),
      });
    } else {
      res = await fetch(source.url, {
        headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' },
      });
    }

    const status = res.status;
    const text = await res.text();
    const firstChars = text.slice(0, 200).replace(/\n/g, ' ');

    console.log(`[${source.name}] status=${status} size=${text.length}`);
    if (text.trim().startsWith('{') || text.trim().startsWith('[')) {
      try {
        const json = JSON.parse(text);
        const count = Array.isArray(json) ? json.length : json.products?.length || json.results?.length || json.items?.length || json.total || '?';
        console.log(`  products=${count}`);
      } catch { console.log(`  body=${firstChars}...`); }
    } else {
      console.log(`  body=${firstChars}...`);
    }
  } catch (e) {
    console.log(`[${source.name}] ERROR: ${e.message}`);
  }
}

async function run() {
  console.log('=== Source Health Check ===\n');
  for (const s of SOURCES) {
    await checkSource(s);
  }
}

run();
