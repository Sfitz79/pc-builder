import { ApifyClient } from 'apify-client';

const client = new ApifyClient({
    token: process.env.APIFY_API_TOKEN,
});

// Amazon Product Scraper for PC Components
export async function scrapeAmazonProducts(searchQuery, options = {}) {
    const {
        maxItems = 20,
        category = 'electronics',
        minPrice = 0,
        maxPrice = 10000,
    } = options;

    const run = await client.actor('junglee/amazon-crawler').call({
        searchTerms: [searchQuery],
        category: category,
        maxItems: maxItems,
        minPrice: minPrice,
        maxPrice: maxPrice,
        country: 'US',
    });

    const { items } = await client.dataset(run.defaultDatasetId).listItems();
    return items;
}

// Newegg Scraper for PC Components
export async function scrapeNeweggProducts(searchQuery, options = {}) {
    const {
        maxItems = 20,
        sort = 'price',
    } = options;

    const run = await client.actor('epctex/newegg-scraper').call({
        searchUrl: `https://www.newegg.com/p/pl?d=${encodeURIComponent(searchQuery)}`,
        maxItems: maxItems,
        sort: sort,
    });

    const { items } = await client.dataset(run.defaultDatasetId).listItems();
    return items;
}

// eBay Scraper for PC Components
export async function scrapeEbayProducts(searchQuery, options = {}) {
    const {
        maxItems = 20,
        minPrice = 0,
        maxPrice = 10000,
        condition = 'new',
    } = options;

    const run = await client.actor('epctex/ebay-scraper').call({
        searchTerms: [searchQuery],
        maxItems: maxItems,
        minPrice: minPrice,
        maxPrice: maxPrice,
        condition: condition,
        country: 'US',
    });

    const { items } = await client.dataset(run.defaultDatasetId).listItems();
    return items;
}

// Best Buy Scraper for PC Components
export async function scrapeBestBuyProducts(searchQuery, options = {}) {
    const {
        maxItems = 20,
    } = options;

    const run = await client.actor('curious_coder/best-buy-scraper').call({
        searchUrl: `https://www.bestbuy.com/site/searchpage.jsp?st=${encodeURIComponent(searchQuery)}`,
        maxItems: maxItems,
    });

    const { items } = await client.dataset(run.defaultDatasetId).listItems();
    return items;
}

// Storage Drive Pricing from PricePerGig.com (Free API)
export async function getStoragePricing(options = {}) {
    const {
        technology = 'SSD',
        condition = 'New',
        marketplace = 'amazon.com',
        capacityGb = null,
        limit = 20,
    } = options;

    let url = `https://api.pricepergig.com/drives?technology=eq.${technology}&condition=eq.${condition}&marketplace=eq.${marketplace}&order=price_per_tb.asc&limit=${limit}`;

    if (capacityGb) {
        url += `&capacity_gb=gte.${capacityGb}`;
    }

    const response = await fetch(url);
    if (!response.ok) {
        throw new Error(`PricePerGig API error: ${response.status}`);
    }

    return await response.json();
}

// Get cheapest SSDs
export async function getCheapestSSDs(limit = 10) {
    return getStoragePricing({
        technology: 'SSD',
        condition: 'New',
        marketplace: 'amazon.com',
        limit,
    });
}

// Get cheapest HDDs
export async function getCheapestHDDs(limit = 10) {
    return getStoragePricing({
        technology: 'HDD',
        condition: 'New',
        marketplace: 'amazon.com',
        limit,
    });
}

// Search storage by capacity
export async function searchStorageByCapacity(capacityGb, technology = 'SSD') {
    return getStoragePricing({
        technology,
        condition: 'New',
        marketplace: 'amazon.com',
        capacityGb,
        limit: 20,
    });
}
