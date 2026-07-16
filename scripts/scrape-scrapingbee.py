from scrapingbee import ScrapingBeeClient
import pandas as pd
import json

API_KEY = 'D67BFUKAFFO279B6S5KSUUFVD8HWUR3831QZI0DNJ5WO2EZT325EE8ZYIU9SJENHH5NIIMYOA8F0JVVY'
client = ScrapingBeeClient(api_key=API_KEY)

# Search queries for popular components
searches = [
    # GPUs
    ('scan-gpu', 'https://www.scan.co.uk/search?q=rtx+5070+ti'),
    ('scan-gpu', 'https://www.scan.co.uk/search?q=rtx+5080'),
    ('scan-gpu', 'https://www.scan.co.uk/search?q=rtx+5090'),
    ('scan-gpu', 'https://www.scan.co.uk/search?q=rx+9070+xt'),
    # CPUs
    ('scan-cpu', 'https://www.scan.co.uk/search?q=ryzen+7+7800x3d'),
    ('scan-cpu', 'https://www.scan.co.uk/search?q=ryzen+7+9800x3d'),
    ('scan-cpu', 'https://www.scan.co.uk/search?q=ryzen+5+7600'),
    # RAM
    ('scan-ram', 'https://www.scan.co.uk/search?q=corsair+vengeance+32gb+ddr5'),
]

# Better selectors for Scan UK based on common patterns
extract_rules = {
    "products": {
        "selector": "li.product, .productItem, [data-product], article.product, .search__result",
        "type": "list",
        "output": {
            "name": "a, h2, h3, .productName, .description",
            "price": ".price, .productPrice, [data-price], .now, .price-val"
        }
    }
}

all_data = []
for label, url in searches:
    print(f"Scraping {url}")
    try:
        response = client.get(
            url,
            params={
                "render_js": "false",
                "extract_rules": extract_rules,
                "country_code": "gb",
                "stealth_proxy": "true",
                "premium_proxy": "true"
            }
        )
        if response.status_code == 200:
            data = response.json()
            products = data.get("products", [])
            print(f"  Products: {len(products)}")
            for item in products[:10]:
                all_data.append({
                    "Source": label,
                    "URL": url,
                    "Name": str(item.get("name", "")).strip()[:120],
                    "Price": str(item.get("price", "")).strip()[:50]
                })
            # Show first 3
            for item in products[:3]:
                print(f"    - {str(item.get('name',''))[:80]}: {item.get('price')}")
        else:
            print(f"  Status: {response.status_code}")
            print(f"  Response: {response.text[:200]}")
    except Exception as e:
        print(f"  Error: {e}")

df = pd.DataFrame(all_data)
df.to_csv("scraped_data/scrapingbee_results.csv", index=False)
print(f"\nSaved {len(df)} results")
