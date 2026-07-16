from scrapingbee import ScrapingBeeClient
import json, re, time

API_KEY = 'D67BFUKAFFO279B6S5KSUUFVD8HWUR3831QZI0DNJ5WO2EZT325EE8ZYIU9SJENHH5NIIMYOA8F0JVVY'
client = ScrapingBeeClient(api_key=API_KEY)

# Search terms organized by category
searches = {
    "gpu": [
        "rtx+5090", "rtx+5080", "rtx+5070+ti", "rtx+5070", "rtx+5060+ti",
        "rx+9070+xt", "rx+9070", "rx+9060+xt", "rx+7600+xt",
        "rtx+4090", "rtx+4080+super", "rtx+4070+ti+super", "rtx+4070+super",
        "rtx+4060+ti", "rtx+4060", "arc+b580",
    ],
    "cpu": [
        "ryzen+7+9800x3d", "ryzen+9+9950x3d", "ryzen+9+9900x3d",
        "ryzen+7+7800x3d", "ryzen+7+9700x", "ryzen+5+9600x", "ryzen+5+7600",
        "ryzen+5+5600", "ryzen+5+5500",
        "core+ultra+7+265k", "core+ultra+5+245k", "core+ultra+9+285k",
        "core+i7+14700k", "core+i5+14600k", "core+i9+14900k",
    ],
    "ram": [
        "corsair+vengeance+32gb+ddr5", "kingston+fury+32gb+ddr5",
        "gskill+flare+x5+32gb", "crucial+pro+32gb+ddr5",
        "corsair+vengeance+16gb+ddr5", "gskill+trident+z5+32gb",
        "corsair+vengeance+64gb+ddr5", "kingston+fury+64gb+ddr5",
        "teamgroup+tcreate+32gb", "patriot+viper+venom+32gb",
    ],
    "motherboard": [
        "b650+wifi+motherboard", "b650e+motherboard", "x870+motherboard",
        "x670e+motherboard", "b760+motherboard", "z790+motherboard",
        "z890+motherboard", "b850+motherboard",
    ],
    "storage": [
        "samsung+990+pro+2tb", "wd+black+sn850x+2tb", "crucial+t500+2tb",
        "samsung+870+evo+1tb", "seagate+barracuda+2tb",
        "wd+black+sn770+1tb", "kingston+kc3000+2tb", "crucial+mx500+1tb",
    ],
    "psu": [
        "corsair+rm850x", "corsair+rm1000x", "evga+supernova+850",
        "seasonic+focus+gx+850", "bequiet+dark+power+13+1000",
        "corsair+sf750", "corsair+rm750x", "asus+rog+thor+1000",
    ],
    "cooler": [
        "noctua+nhd15", "bequiet+dark+rock+pro+5", "corsair+h150i+elite",
        "arctic+freezer+34+esports", "deepcool+ak620",
        "nzxt+kraken+x73", "corsair+h100i+elite", "arctic+freezer+iii+360",
    ],
    "case": [
        "corsair+4000d", "fractal+design+meshify+2", "nzxt+h7+flow",
        "lian+li+o11+dynamic", "corsair+5000d", "fractal+design+torrent",
        "bequiet+silent+base+802", "nzxt+h5+flow",
    ],
    "monitor": [
        "27+inch+1440p+monitor", "32+inch+4k+monitor",
        "lg+27gp850", "samsung+odyssey+g7", "dell+s2722qc",
        "asus+rog+swift+pg27aqdm", "gigabyte+m27q",
    ],
}

extract_rules = {
    "products": {
        "selector": "li.product, .productItem, [data-product]",
        "type": "list",
        "output": {"html": "html"}
    }
}

all_products = []
total_cost = 0
for cat, terms in searches.items():
    for term in terms:
        url = f"https://www.scan.co.uk/search?q={term}"
        print(f"[{cat}] {term}...", end="", flush=True)
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
            count = 0
            for item in items:
                html = item.get("html", "")
                price_m = re.search(r'\xa3\s*(\d[\d,.]*)', html)
                name_m = re.search(r'<a[^>]*class="[^"]*description[^"]*"[^>]*>([^<]+)</a>', html)
                if not name_m:
                    name_m = re.search(r'<a[^>]*>([^<]{10,})</a>', html)
                if price_m:
                    price = float(price_m.group(1).replace(',', ''))
                    name = name_m.group(1).strip() if name_m else ""
                    if name and "BUNDLE" not in name.upper() and price > 10:
                        all_products.append({"category": cat, "search": term, "name": name, "price": price})
                        count += 1
            print(f" {count} saved")
            total_cost += 1
        except Exception as e:
            print(f" Error: {e}")
        time.sleep(0.3)

# Save
with open("scraped_data/scan_prices.json", "w") as f:
    json.dump(all_products, f, indent=2)

print(f"\nTotal: {len(all_products)} products across {total_cost} API calls")
for cat in searches:
    items = [p for p in all_products if p["category"] == cat]
    prices = sorted(set(p["price"] for p in items))
    print(f"  {cat}: {len(items)} items" + (f", £{min(prices):.2f} - £{max(prices):.2f}" if prices else ""))
