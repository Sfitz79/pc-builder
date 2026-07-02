import sys
import os
sys.path.append(r"C:\pypartpicker-2.0.5")

from pypartpicker.client import Client

client = Client()

try:
    # Search for a CPU
    results = client.get_part_search("Core i9-13900K")
    print(f"Found {len(results.parts)} parts:")
    for part in results.parts:
        print(f"Name: {part.name}, Price: {part.price}, URL: {part.url}")
except Exception as e:
    print(f"Error: {e}")
