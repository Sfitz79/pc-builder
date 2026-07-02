import sys
import json
import os
import csv
import time

# Add pypartpicker to path
sys.path.append(r"C:\pypartpicker-2.0.5")

try:
    from pypartpicker.client import Client
except ImportError:
    print("Error: pypartpicker library not found at C:\\pypartpicker-2.0.5")
    sys.exit(1)

DATA_DIR = 'src/data'
CATEGORIES = {
    'cpu': ['Intel Core i', 'AMD Ryzen', 'Intel Core Ultra'],
    'gpu': ['NVIDIA GeForce RTX', 'AMD Radeon RX', 'NVIDIA GeForce GTX 16'],
    'motherboard': ['B650', 'B760', 'X670', 'Z790', 'B550', 'X570', 'Z690'],
    'ram': ['DDR4', 'DDR5'],
    'storage': ['NVMe', 'SSD', 'SATA'],
    'power-supply': ['80+ Gold', 'Modular'],
    'case': ['ATX Mid Tower', 'MicroATX'],
    'cooler': ['AIO', 'Air Cooler']
}

def get_release_year(name, category):
    name = name.lower()
    if '2024' in name: return 2024
    if '2023' in name: return 2023
    if '2022' in name: return 2022
    if '2021' in name: return 2021
    if '2020' in name: return 2020
    if '2019' in name: return 2019
    if '2018' in name: return 2018
    if '2017' in name: return 2017
    if '2016' in name: return 2016
    if '2015' in name: return 2015
    
    if category == 'cpu':
        if any(x in name for x in ['i9-', 'i7-1', 'i5-1', '7000', '5000']): return 2021
    if category == 'gpu':
        if any(x in name for x in ['rtx 40', 'rtx 30', 'rx 7000', 'rx 6000']): return 2021
    
    return 2018

def populate_data():
    client = Client()
    
    for filename, queries in CATEGORIES.items():
        csv_path = os.path.join(DATA_DIR, f"{filename}.csv")
        print(f"Fetching {filename}...")
        
        all_rows = []
        fieldnames = set(['name', 'price', 'image'])
        
        for query in queries:
            print(f"  Searching '{query}'...")
            try:
                results = client.get_part_search(query)
                parts = []
                if hasattr(results, 'parts'):
                    parts = results.parts
                elif isinstance(results, list):
                    parts = results
                    
                for part in parts:
                    price = 0
                    if hasattr(part, 'cheapest_price') and part.cheapest_price:
                        price = part.cheapest_price.total
                    
                    if price <= 0: continue
                    
                    year = get_release_year(part.name, filename)
                    if year < 2015: continue
                    
                    row = {
                        'name': part.name,
                        'price': price,
                        'image': ",".join(part.image_urls) if hasattr(part, 'image_urls') and part.image_urls else ""
                    }
                    
                    if hasattr(part, 'specs') and part.specs:
                        for k, v in part.specs.items():
                            clean_k = k.lower().replace(' ', '_').replace('/', '_').replace('-', '_').replace('(', '').replace(')', '')
                            row[clean_k] = v
                            fieldnames.add(clean_k)
                    
                    all_rows.append(row)
                
                time.sleep(1)
            except Exception as e:
                print(f"    Error searching '{query}': {e}")

        if all_rows:
            # Ensure all rows have all keys
            for row in all_rows:
                for fn in fieldnames:
                    if fn not in row: row[fn] = ""
            
            # Sort fieldnames to have name, price, image first for readability
            sorted_fieldnames = ['name', 'price', 'image'] + sorted(list(fieldnames - {'name', 'price', 'image'}))
            
            with open(csv_path, 'w', encoding='utf-8', newline='') as f:
                writer = csv.DictWriter(f, fieldnames=sorted_fieldnames)
                writer.writeheader()
                writer.writerows(all_rows)
            print(f"Saved {len(all_rows)} parts to {csv_path}")

if __name__ == "__main__":
    if not os.path.exists(DATA_DIR):
        os.makedirs(DATA_DIR)
    populate_data()
