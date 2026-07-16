from scrapingbee import ScrapingBeeClient
import re, json

API_KEY = 'D67BFUKAFFO279B6S5KSUUFVD8HWUR3831QZI0DNJ5WO2EZT325EE8ZYIU9SJENHH5NIIMYOA8F0JVVY'
client = ScrapingBeeClient(api_key=API_KEY)

categories = [
    ('gpu', 'https://www.scan.co.uk/shop/computer-hardware/gpu-nvidia-nvidia'),
    ('gpu', 'https://www.scan.co.uk/shop/computer-hardware/gpu-amd-amd'),
    ('cpu', 'https://www.scan.co.uk/shop/computer-hardware/cpu-amd/amd-ryzen-am5'),
    ('cpu', 'https://www.scan.co.uk/shop/computer-hardware/cpu-intel/intel-core-ultra'),
    ('ram', 'https://www.scan.co.uk/shop/computer-hardware/memory-ddr5/ddr5-memory'),
    ('motherboard', 'https://www.scan.co.uk/shop/computer-hardware/motherboards/am5-motherboards'),
    ('psu', 'https://www.scan.co.uk/shop/computer-hardware/power-supply-units/psu-atx'),
    ('storage', 'https://www.scan.co.uk/shop/computer-hardware/hard-drives/ssd-m2-nvme'),
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

def parse_price(text):
    m = re.search(r'\xa3\s*(\d[\d,.]*)', text)
    if m:
        return float(m[1].replace(',', ''))
    return None

def parse_name(text):
    # Extract product name - usually after "Quick view" or at start
    m = re.search(r'(?:Quick view|Hot Seller|Sale|Offer)?\s*(.*?)(?:\s*\d+GB\s+GDDR|\s*AM\d|\s*Gen\d)', text)
    if m:
        return m.group(1).strip()[:120]
    # Fallback: get first 100 chars
    return text.strip()[:120]

all_products = []
for cat, url in categories:
    print(f"\nScraping {cat}: {url[:80]}")
    try:
        resp = client.get(url, params={
            "render_js": "false", "country_code": "gb",
            "stealth_proxy": "true", "premium_proxy": "true",
            "extract_rules": extract_rules
        })
        if resp.status_code != 200:
            print(f"  HTTP {resp.status_code}")
            continue
        data = resp.json()
        items = data.get("products", [])
        print(f"  Got {len(items)} items")
        for item in items:
            html = item.get("html", "")
            price = parse_price(html)
            name = parse_name(html)
            if name and price:
                all_products.append({"category": cat, "name": name, "price": price, "source": url})
        # Show first 3
        for p in all_products[-3:]:
            print(f"    £{p['price']:>8.2f} {p['name'][:70]}")
    except Exception as e:
        print(f"  Error: {e}")

with open("scraped_data/scan_prices.json", "w") as f:
    json.dump(all_products, f, indent=2)
print(f"\nTotal: {len(all_products)} products saved to scraped_data/scan_prices.json")
