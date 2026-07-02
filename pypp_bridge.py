import sys
import json
import os

# Add pypartpicker to path
sys.path.append(r"C:\pypartpicker-2.0.5")

try:
    from pypartpicker.client import Client
except ImportError:
    print(json.dumps({"error": "pypartpicker library not found at C:\\pypartpicker-2.0.5"}))
    sys.exit(1)

def main():
    if len(sys.argv) < 2:
        print(json.dumps({"error": "No search query provided"}))
        return

    query = sys.argv[1]
    client = Client()

    try:
        # Instead of generic search, let's try to search specifically in a category if possible
        # Or just use get_part_search and handle results.
        # Note: if it returns exactly one part, it might return a Part object instead of PartSearchResult
        
        results = client.get_part_search(query)
        parts_list = []
        
        # If it's a list (PartSearchResult)
        if hasattr(results, 'parts'):
            for part in results.parts:
                parts_list.append(serialize_part(part))
        elif hasattr(results, 'name'): # It's a single Part object
            parts_list.append(serialize_part(results))
        elif isinstance(results, list):
            for part in results:
                parts_list.append(serialize_part(part))
                
        print(json.dumps({"parts": parts_list}))
        
    except Exception as e:
        print(json.dumps({"error": str(e)}))

def serialize_part(part):
    price = 0
    if hasattr(part, 'cheapest_price') and part.cheapest_price:
        price = part.cheapest_price.total
    
    part_data = {
        "name": part.name,
        "price": price,
        "image": part.image_urls[0] if hasattr(part, 'image_urls') and part.image_urls else "",
        "url": part.url,
        "type": part.type,
        "in_stock": getattr(part, 'in_stock', False)
    }
    
    # Add specs if available
    if hasattr(part, 'specs') and part.specs:
        part_data.update(part.specs)
    
    return part_data

if __name__ == "__main__":
    main()
