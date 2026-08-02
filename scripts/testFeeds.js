const FEEDS = [
  { name: 'Novatech Feed', url: 'https://www.novatech.co.uk/feeds/products.json', requiredFields: ['name', 'price', 'image'] },
  { name: 'CCL Computers', url: 'https://www.cclonline.com/product-feed/', requiredFields: ['name', 'price'] },
  { name: 'Ebuyer', url: 'https://www.ebuyer.com/product-feed', requiredFields: ['name', 'price'] },
  { name: 'Scan Affiliate', url: 'https://affiliate.scan.co.uk/feed/products.json', requiredFields: ['sku', 'price_inc_vat', 'title'] },
  { name: 'Overclockers UK', url: 'https://www.overclockers.co.uk/feeds/products.json', requiredFields: ['sku', 'price', 'name'] },
  { name: 'Box.co.uk', url: 'https://www.box.co.uk/affiliate/products.json', requiredFields: ['sku', 'price', 'title'] },
];

async function testFeed(feed) {
  try {
    const res = await fetch(feed.url);
    const contentType = res.headers.get('content-type') || '';
    const text = await res.text();
    let data;

    if (contentType.includes('xml') || text.trim().startsWith('<')) {
      const names = [...text.matchAll(/<name[^>]*>([^<]+)<\/name>/gi)].map(m => m[1]);
      const prices = [...text.matchAll(/<price[^>]*>([^<]+)<\/price>/gi)].map(m => parseFloat(m[1]));
      console.log(`[${feed.name}] XML feed, ${names.length} names, ${prices.length} prices`);
      if (names.length > 0) console.log(`  sample: ${names[0]} @ £${prices[0] || '?'}`);
      return;
    }

    try { data = JSON.parse(text); } catch {
      console.log(`[${feed.name}] NOT JSON (${text.slice(0, 100)})`);
      return;
    }

    const items = Array.isArray(data) ? data : (data.products || data.results || data.items || data.data || []);
    if (!Array.isArray(items) || items.length === 0) {
      console.log(`[${feed.name}] INVALID: no product array`);
      return;
    }

    const sample = items[0];
    const missing = feed.requiredFields.filter(f => !(f in sample));
    if (missing.length) {
      console.log(`[${feed.name}] INVALID: missing fields ${missing.join(', ')}`);
      console.log(`  sample keys: ${Object.keys(sample).join(', ')}`);
    } else {
      console.log(`[${feed.name}] OK: ${items.length} products, all fields present`);
      console.log(`  sample: ${sample.name || sample.title} @ £${sample.price || sample.price_inc_vat}`);
    }
  } catch (e) {
    console.log(`[${feed.name}] ERROR: ${e.message}`);
  }
}

async function run() {
  console.log('=== Feed Validity Tests ===\n');
  for (const f of FEEDS) {
    await testFeed(f);
  }
}

run();
