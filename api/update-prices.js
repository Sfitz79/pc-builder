import fs from 'fs';
import path from 'path';

const VANTA_KEY = process.env.VANTA_KEY;
const APIFY_KEY = process.env.APIFY_KEY;

async function fetchVantaSpec() {
  const url = `https://api.vantaspec.com/api/products/search?limit=250&region=uk`;
  const res = await fetch(url, {
    headers: { Authorization: `Bearer ${VANTA_KEY}`, Accept: 'application/json' },
  });
  const data = await res.json();
  return data.products || [];
}

async function fetchPCPartPicker() {
  const url = `https://api.apify.com/v2/acts/lulzasaur~pcpartpicker-scraper/run-sync-get-dataset-items?token=${APIFY_KEY}`;
  const res = await fetch(url, {
    method: 'POST',
    body: JSON.stringify({ country: 'uk', maxResults: 200 }),
  });
  return await res.json();
}

async function fetchFeed(url) {
  const res = await fetch(url);
  return await res.json();
}

function normaliseVanta(p) {
  return {
    id: p.id,
    brand: p.brand,
    model: p.model,
    category: p.category,
    name: p.name,
    image: p.image,
    mpn: p.specs?.mpn || null,
    sku: p.sku || null,
    retailers: (p.retailers || []).map(r => ({
      name: r.name,
      price: r.price?.amount || null,
      currency: r.price?.currency || 'GBP',
      stock: r.stock || 'unknown',
      url: r.url,
    })),
  };
}

function normalisePCPP(p) {
  return {
    id: p.id,
    brand: p.brand,
    model: p.model,
    category: p.category,
    name: p.name,
    image: p.image,
    mpn: p.mpn || null,
    sku: p.sku || null,
    retailers: p.retailers || [],
  };
}

function normaliseScan(p) {
  return {
    id: p.sku,
    brand: p.brand,
    model: p.model,
    category: p.category,
    name: p.title,
    image: p.image,
    mpn: p.mpn || null,
    sku: p.sku,
    retailers: [{ name: 'Scan', price: p.price_inc_vat, currency: 'GBP', stock: p.stock_status || 'unknown', url: p.product_url }],
  };
}

function normaliseOCUK(p) {
  return {
    id: p.sku,
    brand: p.brand,
    model: p.model,
    category: p.category,
    name: p.name,
    image: p.image,
    mpn: p.mpn || null,
    sku: p.sku,
    retailers: [{ name: 'Overclockers UK', price: p.price, currency: 'GBP', stock: p.stock || 'unknown', url: p.url }],
  };
}

function normaliseBox(p) {
  return {
    id: p.sku,
    brand: p.brand,
    model: p.model,
    category: p.category,
    name: p.title,
    image: p.image,
    mpn: p.mpn || null,
    sku: p.sku,
    retailers: [{ name: 'Box', price: p.price, currency: 'GBP', stock: p.stock || 'unknown', url: p.product_url }],
  };
}

function mergeProducts(allProducts) {
  const map = new Map();
  const makeKey = p => `${p.sku || ''}|${p.mpn || ''}|${p.brand || ''}|${p.model || ''}`;

  for (const p of allProducts) {
    const key = makeKey(p);
    if (!map.has(key)) {
      map.set(key, { ...p, retailers: [...p.retailers] });
    } else {
      map.get(key).retailers.push(...p.retailers);
    }
  }

  const final = [];
  for (const [, p] of map) {
    const validPrices = p.retailers.filter(r => r.price !== null);
    const lowest = validPrices.length ? validPrices.reduce((a, b) => (a.price < b.price ? a : b)) : null;
    final.push({
      id: p.id,
      brand: p.brand,
      model: p.model,
      category: p.category,
      name: p.name,
      image: p.image,
      mpn: p.mpn,
      sku: p.sku,
      lowest_price: lowest ? lowest.price : null,
      lowest_price_currency: lowest ? lowest.currency : 'GBP',
      lowest_price_retailer: lowest ? lowest.name : null,
      lowest_price_url: lowest ? lowest.url : null,
      stock_status: lowest ? lowest.stock : 'unknown',
      retailers: p.retailers,
    });
  }

  return final;
}

export default async function handler(req, res) {
  try {
    log('update-prices: starting fetch cycle');

    const [vanta, pcpp] = await Promise.all([
      fetchVantaSpec().catch(e => { log(`VantaSpec failed: ${e.message}`); return []; }),
      fetchPCPartPicker().catch(e => { log(`PCPartPicker failed: ${e.message}`); return []; }),
    ]);

    const feeds = await Promise.all([
      fetchFeed('https://affiliate.scan.co.uk/feed/products.json').catch(e => { log(`Scan feed failed: ${e.message}`); return []; }),
      fetchFeed('https://www.overclockers.co.uk/feeds/products.json').catch(e => { log(`OCUK feed failed: ${e.message}`); return []; }),
      fetchFeed('https://www.box.co.uk/affiliate/products.json').catch(e => { log(`Box feed failed: ${e.message}`); return []; }),
    ]);

    const vantaNorm = vanta.map(normaliseVanta);
    const pcppNorm = pcpp.map(normalisePCPP);
    const scanNorm = (feeds[0] || []).map(normaliseScan);
    const ocukNorm = (feeds[1] || []).map(normaliseOCUK);
    const boxNorm = (feeds[2] || []).map(normaliseBox);

    log(`VantaSpec: ${vantaNorm.length}, PCPP: ${pcppNorm.length}, Scan: ${scanNorm.length}, OCUK: ${ocukNorm.length}, Box: ${boxNorm.length}`);

    const merged = mergeProducts([...vantaNorm, ...pcppNorm, ...scanNorm, ...ocukNorm, ...boxNorm]);
    log(`Merged: ${merged.length} unique products`);

    const filePath = path.join(process.cwd(), 'public', 'prices.json');
    fs.writeFileSync(filePath, JSON.stringify(merged, null, 2));

    res.status(200).json({ count: merged.length, updated_at: new Date().toISOString() });
  } catch (e) {
    console.error(e);
    res.status(500).json({ error: 'Failed to update prices' });
  }
}

function log(msg) {
  console.log(`[${new Date().toISOString()}] ${msg}`);
}
