"""
PCPartPicker UK Scraper - scrapes ALL product data from uk.pcpartpicker.com
Uses pyppeteer (headed Chrome) to bypass Cloudflare.
Usage: python scraper_pcpp.py <category|all|list>
"""

import asyncio, json, os, sys, time
from datetime import datetime, timezone
from pyppeteer import launch

CATEGORIES = {
    'cpu': 'https://uk.pcpartpicker.com/products/cpu/',
    'cooler': 'https://uk.pcpartpicker.com/products/cpu-cooler/',
    'ram': 'https://uk.pcpartpicker.com/products/memory/',
    'storage': 'https://uk.pcpartpicker.com/products/internal-hard-drive/',
    'motherboard': 'https://uk.pcpartpicker.com/products/motherboard/',
    'gpu': 'https://uk.pcpartpicker.com/products/video-card/',
    'power-supply': 'https://uk.pcpartpicker.com/products/power-supply/',
    'case': 'https://uk.pcpartpicker.com/products/case/',
    'case-fan': 'https://uk.pcpartpicker.com/products/case-fan/',
    'headphones': 'https://uk.pcpartpicker.com/products/headphones/',
    'keyboard': 'https://uk.pcpartpicker.com/products/keyboard/',
    'wireless-network-card': 'https://uk.pcpartpicker.com/products/wireless-network-card/',
    'monitor': 'https://uk.pcpartpicker.com/products/monitor/',
    'mouse': 'https://uk.pcpartpicker.com/products/mouse/',
    'speakers': 'https://uk.pcpartpicker.com/products/speakers/',
    'webcam': 'https://uk.pcpartpicker.com/products/webcam/',
    'external-hard-drive': 'https://uk.pcpartpicker.com/products/external-hard-drive/',
    'optical-drive': 'https://uk.pcpartpicker.com/products/optical-drive/',
    'ups': 'https://uk.pcpartpicker.com/products/ups/',
    'fan-controller': 'https://uk.pcpartpicker.com/products/fan-controller/',
    'thermal-paste': 'https://uk.pcpartpicker.com/products/thermal-paste/',
    'wired-network-card': 'https://uk.pcpartpicker.com/products/wired-network-card/',
    'sound-card': 'https://uk.pcpartpicker.com/products/sound-card/',
    'case-accessory': 'https://uk.pcpartpicker.com/products/case-accessory/',
}

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUTPUT_DIR = os.path.join(BASE_DIR, 'scraped_data')


async def scrape_category(browser, cat_name, cat_url, max_pages=25):
    print(f'\n=== {cat_name}: {cat_url} ===')
    page = await browser.newPage()
    await page.setUserAgent(
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        '(KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36'
    )
    await page.setViewport({'width': 1920, 'height': 1080})
    await page.evaluateOnNewDocument("""() => {
        Object.defineProperty(navigator, 'webdriver', { get: () => false });
        Object.defineProperty(navigator, 'plugins', { get: () => [1,2,3,4,5] });
        Object.defineProperty(navigator, 'languages', { get: () => ['en-GB','en'] });
    }""")

    all_products = []
    for pg in range(1, max_pages + 1):
        url = f'{cat_url}?page={pg}' if pg > 1 else cat_url
        print(f'  Page {pg}...', end=' ', flush=True)
        try:
            await page.goto(url, waitUntil='networkidle0', timeout=60000)
            await asyncio.sleep(5)

            body = await page.evaluate("() => document.body.innerText.toLowerCase()")
            if 'unavailable' in body:
                print('BLOCKED (Cloudflare)')
                return all_products

            products = await page.evaluate("""() => {
                const results = [];
                const rows = document.querySelectorAll('#category_content tr');
                const headers = [];
                document.querySelectorAll('#paginated_table thead th').forEach((th,i) => {
                    const p = th.querySelector('p');
                    headers[i] = p ? p.textContent.trim() : '';
                });
                rows.forEach(row => {
                    const nameEl = row.querySelector('.td__name');
                    const name = nameEl ? nameEl.textContent.trim() : '';
                    if (!name) return;
                    const linkEl = row.querySelector('.td__name a');
                    const productUrl = linkEl ? linkEl.getAttribute('href') : '';
                    const imgEl = row.querySelector('.td__imageWrapper img');
                    let imgSrc = imgEl ? imgEl.getAttribute('src') : '';
                    if (imgSrc && !imgSrc.startsWith('http')) imgSrc = 'https:' + imgSrc;
                    const priceEl = row.querySelector('.td__price');
                    let price = null, currency = null;
                    if (priceEl) {
                        const txt = priceEl.textContent.trim();
                        const m = txt.match(/[\\u00A3\\u0024\\u20AC]([\\d,.]+)/);
                        if (m) { currency = txt[0]; price = parseFloat(m[1].replace(/,/g,'')); }
                    }
                    const ratingEl = row.querySelector('.td__rating');
                    let rating = null, ratingCount = null;
                    if (ratingEl) {
                        rating = ratingEl.querySelectorAll('.shape-star-full, .shape-star-half').length;
                        const cm = ratingEl.textContent.match(/\\\\\((\\\\d+)\\\\\)/);
                        if (cm) ratingCount = parseInt(cm[1]);
                    }
                    const specs = {};
                    row.querySelectorAll('td.td__spec').forEach((cell, idx) => {
                        const hdr = headers[cell.cellIndex] || '';
                        const val = cell.textContent.trim();
                        if (hdr && val) specs[hdr] = val;
                    });
                    results.push({
                        productName: name,
                        url: productUrl ? 'https://uk.pcpartpicker.com' + productUrl : null,
                        imageUrl: imgSrc || null,
                        price: price, priceCurrency: currency,
                        rating: rating, ratingCount: ratingCount,
                        specs: Object.keys(specs).length ? specs : null,
                    });
                });
                return results;
            }""")

            print(f'{len(products)} products')
            all_products.extend(products)

            has_next = await page.evaluate("""() => {
                const btns = document.querySelectorAll('.pagination__next:not(.disabled)');
                for (const b of btns) { if (!b.classList.contains('disabled')) return true; }
                return false;
            }""")
            if not has_next:
                print('  (last page)')
                break
        except Exception as e:
            print(f'Error: {e}')
            break

    await page.close()
    for p in all_products:
        p['category'] = cat_name
        p['country'] = 'gb'
        p['scrapedAt'] = datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.000Z')
    return all_products


async def main():
    if not os.path.exists(OUTPUT_DIR):
        os.makedirs(OUTPUT_DIR)

    args = sys.argv[1:]
    if not args or args[0] in ('--help', '-h'):
        print('Usage: python scraper_pcpp.py <category|all|list>')
        return
    if args[0] == 'list':
        for k, v in CATEGORIES.items(): print(f'  {k}: {v}')
        return

    cats = CATEGORIES if args[0] == 'all' else {k: CATEGORIES[k] for k in args[0].split(',') if k in CATEGORIES}
    if not cats: print('No valid categories'); return

    browser = await launch(
        headless=False,
        executablePath='C:/Program Files/Google/Chrome/Application/chrome.exe',
        args=[
            '--no-sandbox', '--disable-setuid-sandbox',
            '--disable-blink-features=AutomationControlled',
            '--disable-web-security',
            '--window-size=1920,1080',
        ],
        ignoreHTTPSErrors=True,
    )

    for cat_name, cat_url in cats.items():
        products = await scrape_category(browser, cat_name, cat_url)
        if products:
            fp = os.path.join(OUTPUT_DIR, f'{cat_name}.json')
            with open(fp, 'w', encoding='utf-8') as f:
                json.dump(products, f, indent=2, ensure_ascii=False)
            print(f'  Saved {len(products)} to {cat_name}.json')

    await browser.close()
    print('\nComplete!')


if __name__ == '__main__':
    asyncio.run(main())
