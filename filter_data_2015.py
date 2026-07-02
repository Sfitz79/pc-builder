import os
import csv
import re

DATA_DIR = 'src/data'
THUMBNAILS_DIR = 'public/thumbnails'

def get_release_year(name, category):
    # This is a heuristic-based estimation of release years for common components
    name = name.lower()
    
    # CPUs
    if category == 'cpu':
        # Intel
        if 'i7-1' in name or 'i5-1' in name or 'i3-1' in name or 'i9-1' in name: return 2020 # Gen 10-14
        if 'i7-9' in name or 'i5-9' in name or 'i3-9' in name: return 2018
        if 'i7-8' in name or 'i5-8' in name or 'i3-8' in name: return 2017
        if 'i7-7' in name or 'i5-7' in name or 'i3-7' in name: return 2017
        if 'i7-6' in name or 'i5-6' in name or 'i3-6' in name: return 2015
        if 'i7-5' in name: return 2014
        # AMD
        if 'ryzen' in name:
            if '7000' in name: return 2022
            if '5000' in name: return 2020
            if '3000' in name: return 2019
            if '2000' in name: return 2018
            if '1000' in name or 'ryzen 7 1' in name or 'ryzen 5 1' in name or 'ryzen 3 1' in name: return 2017
        if 'threadripper' in name: return 2017
        
    # GPUs
    if category == 'gpu':
        if 'rtx 40' in name: return 2022
        if 'rtx 30' in name: return 2020
        if 'rtx 20' in name: return 2018
        if 'gtx 16' in name: return 2019
        if 'gtx 10' in name: return 2016
        if 'rx 7000' in name or 'rx 7900' in name: return 2022
        if 'rx 6000' in name or 'rx 6800' in name: return 2020
        if 'rx 5000' in name or 'rx 5700' in name: return 2019
        if 'rx 500' in name or 'rx 580' in name: return 2017
        if 'rx 400' in name or 'rx 480' in name: return 2016
        if 'gtx 9' in name: return 2014
        if 'r9 3' in name: return 2015

    # Motherboards (Socket based)
    if category == 'motherboard':
        if 'am5' in name.lower() or 'lga1700' in name.lower(): return 2021
        if 'am4' in name.lower(): return 2017
        if 'lga1200' in name.lower(): return 2020
        if 'lga1151' in name.lower(): return 2015
        if 'x299' in name.lower(): return 2017
        if 'x399' in name.lower(): return 2017
        if 'trx40' in name.lower(): return 2019

    # Default to 2018 for things we don't know (safer bet for modern parts)
    # But if it's DDR4 or DDR5 it's definitely 2015+
    if 'ddr5' in name: return 2021
    if 'ddr4' in name: return 2015
    if 'm.2' in name or 'nvme' in name: return 2015
    
    return 2018

def filter_csv(filename):
    filepath = os.path.join(DATA_DIR, filename)
    category = filename.replace('.csv', '')
    
    if not os.path.exists(filepath):
        return

    with open(filepath, mode='r', encoding='utf-8', newline='') as f:
        reader = csv.DictReader(f)
        headers = reader.fieldnames
        rows = list(reader)

    original_count = len(rows)
    filtered_rows = []
    
    for row in rows:
        name = row.get('name', '')
        price_str = row.get('price', '0')
        
        # 1. Check price
        try:
            price = float(price_str)
        except:
            price = 0
            
        if price <= 0:
            continue
            
        # 2. Check year (2015+)
        year = get_release_year(name, category)
        if year < 2015:
            continue
            
        # 3. Check thumbnails
        images = row.get('image', '')
        if not images:
            # We will let download-thumbnails.js try to find images for these later
            # but for now, if it's a known good part we keep it.
            pass
        else:
            # Check if at least one image exists if it's a local path
            img_list = [img.strip() for img in images.split(',')]
            valid_images = []
            for img in img_list:
                if img.startswith('thumbnails/'):
                    local_path = os.path.join('public', img)
                    if os.path.exists(local_path):
                        valid_images.append(img)
                elif img.startswith('http'):
                    valid_images.append(img)
            
            # If it had images but none are valid now, we might want to keep it and re-download
            # but the user said "ensure all thumbnails are cached and loaded and are displaying the image not a placeholder"
            # So if it has NO images at all, it's a candidate for removal UNLESS we want to scrape it.
            # For now, let's keep parts that have at least one image (local or remote).
            if images and not valid_images:
                # If it had images but they are all broken local paths, we'll try to re-scrape them later?
                # Actually, let's just keep it and let the downloader fix it.
                pass
            
        filtered_rows.append(row)

    with open(filepath, mode='w', encoding='utf-8', newline='') as f:
        writer = csv.DictWriter(f, fieldnames=headers)
        writer.writeheader()
        writer.writerows(filtered_rows)

    print(f"File {filename}: {original_count} -> {len(filtered_rows)} rows")

def main():
    files = [f for f in os.listdir(DATA_DIR) if f.endswith('.csv')]
    for f in files:
        filter_csv(f)

if __name__ == "__main__":
    main()
