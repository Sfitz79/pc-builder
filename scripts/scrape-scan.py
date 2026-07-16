from scrapingbee import ScrapingBeeClient
import re, json

API_KEY = 'D67BFUKAFFO279B6S5KSUUFVD8HWUR3831QZI0DNJ5WO2EZT325EE8ZYIU9SJENHH5NIIMYOA8F0JVVY'
client = ScrapingBeeClient(api_key=API_KEY)

# Products to search for on Scan UK
searches = [
    # GPUs
    "rtx+5090", "rtx+5080", "rtx+5070+ti", "rtx+5070", "rtx+5060+ti",
    "rx+9070+xt", "rx+9070", "rx+9060+xt", "rx+7600+xt",
    "rtx+4060+ti", "rtx+4060", "arc+b580",
    # CPUs
    "ryzen+7+9800x3d", "ryzen+9+9950x3d", "ryzen+9+9900x3d",
    "ryzen+7+7800x3d", "ryzen+7+9700x", "ryzen+5+9600x", "ryzen+5+7600",
    "ryzen+5+5600", "ryzen+5+5500",
    "core+ultra+7+265k", "core+ultra+5+245k",
    # RAM
    "corsair+vengeance+32gb+ddr5", "kingston+fury+32gb+ddr5",
    "gskill+flare+x5+32gb", "crucial+32gb+ddr5",
]

extract_rules = {
    "products": {
        "selector": "li.product, .productItem, [data-product]",
        "type": "list",
        "output": {
            "html": "html"
        }
    }
}

def extract_products(html):
    items = []
    # Split by product items
    chunks = re.split(r'<li[^>]*class="product"[^>]*>', html)
    if len(chunks) < 2:
        chunks = re.split(r'class="productItem"', html)
    for chunk in chunks[1:]:
        # Find price - look for £ sign followed by number
        price_m = re.search(r'\xa3\s*(\d[\d,.]*)', chunk)
        if not price_m:
            continue
        price = float(price_m.group(1).replace(',', ''))
        # Extract name - first significant text
        name_m = re.search(r'<a[^>]*>([^<]+)</a>', chunk)
        if name_m:
            name = name_m.group(1).strip()
        else:
            name = chunk.strip()[:100]
        items.append({"name": name, "price": price})
    return items

all_products = []
for term in searches:
    url = f"https://www.scan.co.uk/search?q={term}"
    print(f"Search: {term}", end="", flush=True)
    try:
        resp = client.get(url, params={
            "render_js": "false", "country_code": "gb",
            "stealth_proxy": "true", "premium_proxy": "true",
            "extract_rules": extract_rules
        })
        if resp.status_code != 200:
            print(f" HTTP {resp.status_code}")
            continue
        data = resp.json()
        items = data.get("products", [])
        for item in items:
            html = item.get("html", "")
            price_m = re.search(r'\xa3\s*(\d[\d,.]*)', html)
            name_m = re.search(r'<a[^>]*class="[^"]*description[^"]*"[^>]*>([^<]+)</a>', html)
            if not name_m:
                name_m = re.search(r'<a[^>]*>([^<]{10,})</a>', html)
            if price_m:
                price = float(price_m.group(1).replace(',', ''))
                name = name_m.group(1).strip() if name_m else ""
                # Skip bundles and accessories
                if name and "BUNDLE" not in name.upper() and "bundle" not in name.lower() and price > 10:
                    all_products.append({"search": term, "name": name, "price": price})
        print(f" {len(items)} items, {len([x for x in all_products if x['search']==term])} saved")
    except Exception as e:
        print(f" Error: {e}")

with open("scraped_data/scan_prices.json", "w") as f:
    json.dump(all_products, f, indent=2)
print(f"\nTotal: {len(all_products)} products")
# Show summary by category
by_search = {}
for p in all_products:
    by_search.setdefault(p['search'], []).append(p)
for s, prods in sorted(by_search.items()):
    prices = sorted(set(p['price'] for p in prods))
    print(f"  {s}: {len(prods)} items, prices £{min(prices):.2f} - £{max(prices):.2f}")
