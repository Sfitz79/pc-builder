#!/usr/bin/env python3
import csv
import os
import re

rgb_tag = "RGB"
data_dir = "src/data"

csv_files = [
    "cooler.csv",
    "case.csv",
    "gpu.csv",
    "ram.csv",
    "storage.csv"
]

for filename in csv_files:
    filepath = os.path.join(data_dir, filename)
    if not os.path.exists(filepath):
        print(f"Skipping {filename} - not found")
        continue
    
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    modified = 0
    
    lines = content.split('\n')
    new_lines = []
    
    for line in lines:
        if line.startswith('name,') or not line.strip():
            new_lines.append(line)
            continue
        
        parser = csv.reader([line])
        row = next(parser)
        name_idx = 0
        
        name = row[name_idx]
        if 'rgb' in name.lower() or 'argb' in name.lower():
            if not re.search(r'\bRGB\b', name) and not name.endswith(' RGB'):
                row[name_idx] = name + " RGB"
                modified += 1
        new_lines.append(','.join(row))
    
    if modified > 0:
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write('\n'.join(new_lines))
        print(f"{filename}: Added RGB tag to {modified} products")
    else:
        print(f"{filename}: No RGB products found")

print("\nDone!")