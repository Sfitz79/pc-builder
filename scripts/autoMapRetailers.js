const RETAILER_SOURCES = [
  { name: 'VantaSpec', url: 'https://api.vantaspec.com/api/products/search?limit=50&region=uk', type: 'api' },
  { name: 'PCPartPicker_UK', url: 'https://api.apify.com/v2/acts/lulzasaur~pcpartpicker-scraper/run-sync-get-dataset-items', type: 'apify', body: { country: 'uk', maxResults: 50 } },
  { name: 'Scan', url: 'https://affiliate.scan.co.uk/feed/products.json', type: 'feed', retailerName: 'Scan' },
  { name: 'Overclockers UK', url: 'https://www.overclockers.co.uk/feeds/products.json', type: 'feed', retailerName: 'Overclockers UK' },
  { name: 'Box', url: 'https://www.box.co.uk/affiliate/products.json', type: 'feed', retailerName: 'Box' },
  { name: 'Novatech', url: 'https://www.novatech.co.uk/feeds/products.json', type: 'feed', retailerName: 'Novatech' },
];

async function fetchSource(source) {
  if (source.type === 'apify') {
    const token = process.env.APIFY_KEY;
    if (!token) return [];
    const res = await fetch(`${source.url}?token=${encodeURIComponent(token)}`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(source.body),
    });
    return await res.json();
  }
  const res = await fetch(source.url, {
    headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' },
  });
  const text = await res.text();
  try { return JSON.parse(text); } catch { return []; }
}

async function run() {
  console.log('=== Retailer Auto-Mapper ===\n');
  const retailerSet = new Set();
  let totalProducts = 0;

  for (const s of RETAILER_SOURCES) {
    try {
      const data = await fetchSource(s);
      const products = Array.isArray(data) ? data : (data.products || data.results || data.items || []);

      if (!Array.isArray(products) || products.length === 0) {
        console.log(`[${s.name}] No data`);
        continue;
      }

      totalProducts += products.length;
      console.log(`[${s.name}] ${products.length} products`);

      if (s.retailerName) {
        retailerSet.add(s.retailerName);
      } else if (s.type === 'api') {
        for (const p of products) {
          if (p.retailers && Array.isArray(p.retailers)) {
            p.retailers.forEach(r => r.name && retailerSet.add(r.name));
          }
        }
      } else if (s.type === 'apify') {
        for (const p of products) {
          if (p.retailers && Array.isArray(p.retailers)) {
            p.retailers.forEach(r => r.name && retailerSet.add(r.name));
          }
        }
      }
    } catch (e) {
      console.log(`[${s.name}] ERROR: ${e.message}`);
    }
  }

  const retailers = Array.from(retailerSet).sort();
  console.log(`\n=== ${retailers.length} Unique Retailers Detected ===`);
  console.log(retailers.map((r, i) => `${i + 1}. ${r}`).join('\n'));

  const mappingStubs = retailers.map(name => ({
    name,
    mapping: {
      priceField: 'price',
      currencyField: "currency || 'GBP'",
      stockField: "stock || 'unknown'",
      urlField: 'url',
    },
  }));

  console.log(`\n=== Mapping Stubs (${retailers.length}) ===`);
  console.log(JSON.stringify(mappingStubs, null, 2));
}

run();
