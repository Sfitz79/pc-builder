import json, os, csv, re
from datetime import datetime, timezone

DATA_DIR = r'C:\Users\simon\WebstormProjects\pc-builder\src\data'
OUTPUT_DIR = r'C:\Users\simon\WebstormProjects\pc-builder\scraped_data'

CATEGORY_MAP = {
    'cpu': 'cpu',
    'cooler': 'cpu-cooler', 
    'motherboard': 'motherboard',
    'ram': 'memory',
    'gpu': 'video-card',
    'storage': 'internal-hard-drive',
    'power-supply': 'power-supply',
    'case': 'case',
    'case-fan': 'case-fan',
    'monitor': 'monitor',
    'keyboard': 'keyboard',
    'mouse': 'mouse',
    'headphones': 'headphones',
    'speakers': 'speakers',
    'webcam': 'webcam',
    'wireless-network-card': 'wireless-network-card',
    'wired-network-card': 'wired-network-card',
    'sound-card': 'sound-card',
    'external-hard-drive': 'external-hard-drive',
    'optical-drive': 'optical-drive',
    'fan-controller': 'fan-controller',
    'ups': 'ups',
    'thermal-paste': 'thermal-paste',
    'os': 'os',
    'case-accessory': 'case-accessory',
}

SPEC_MAP = {
    'cpu': {
        'core_count': 'coreCount',
        'core_clock': 'performanceCoreClock',
        'boost_clock': 'performanceCoreBoostClock',
        'microarchitecture': 'microarchitecture',
        'tdp': 'tdp',
        'graphics': 'integratedGraphics',
    },
    'cooler': {
        'rpm': 'fanRpm',
        'noise_level': 'noiseLevel',
        'color': 'color',
        'size': 'size',
    },
    'motherboard': {
        'socket': 'socket/Cpu',
        'form_factor': 'formFactor',
        'max_memory': 'memoryMax',
        'memory_slots': 'memorySlots',
        'color': 'color',
        'wifi': 'wifi',
        'usb_c': 'usbC',
    },
    'ram': {
        'speed': 'speed',
        'modules': 'modules',
        'price_per_gb': 'price/Gb',
        'color': 'color',
        'first_word_latency': 'firstWordLatency',
        'cas_latency': 'casLatency',
    },
    'gpu': {
        'chipset': 'chipset',
        'memory': 'memory',
        'core_clock': 'coreClock',
        'boost_clock': 'boostClock',
        'color': 'color',
        'length': 'length',
    },
    'storage': {
        'capacity': 'capacity',
        'price_per_gb': 'price/Gb',
        'type': 'type',
        'cache': 'cache',
        'form_factor': 'formFactor',
        'interface': 'interface',
    },
    'power-supply': {
        'type': 'type',
        'efficiency': 'efficiency',
        'wattage': 'wattage',
        'modular': 'modular',
        'color': 'color',
    },
    'case': {
        'type': 'type',
        'color': 'color',
        'psu': 'psu',
        'side_panel': 'sidePanel',
        'external_volume': 'externalVolume',
        'internal_35_bays': 'internal35Bays',
    },
    'case-fan': {
        'size': 'size',
        'color': 'color',
        'rpm': 'rpm',
        'airflow': 'airflow',
        'noise_level': 'noiseLevel',
        'pwm': 'pwm',
    },
    'monitor': {
        'screen_size': 'screenSize',
        'resolution': 'resolution',
        'refresh_rate': 'refreshRate',
        'response_time': 'responseTime',
        'panel_type': 'panelType',
        'aspect_ratio': 'aspectRatio',
    },
    'keyboard': {
        'style': 'style',
        'switches': 'switches',
        'backlit': 'backlit',
        'tenkeyless': 'tenkeyless',
        'connection_type': 'connectionType',
        'color': 'color',
    },
    'mouse': {
        'tracking_method': 'trackingMethod',
        'connection_type': 'connectionType',
        'max_dpi': 'maxDpi',
        'hand_orientation': 'handOrientation',
        'color': 'color',
    },
    'headphones': {
        'type': 'type',
        'frequency_response': 'frequencyResponse',
        'microphone': 'microphone',
        'wireless': 'wireless',
        'enclosure_type': 'enclosureType',
        'color': 'color',
    },
    'speakers': {
        'configuration': 'configuration',
        'wattage': 'wattage',
        'frequency_response': 'frequencyResponse',
        'color': 'color',
    },
    'webcam': {
        'resolutions': 'resolutions',
        'connection': 'connection',
        'focus_type': 'focusType',
        'os': 'os',
        'fov': 'fov',
    },
    'wireless-network-card': {
        'protocol': 'protocol',
        'interface': 'interface',
        'color': 'color',
    },
    'wired-network-card': {
        'interface': 'interface',
        'color': 'color',
    },
    'sound-card': {
        'channels': 'channels',
        'digital_audio': 'digitalAudio',
        'snr': 'snr',
        'sample_rate': 'sampleRate',
        'chipset': 'chipset',
        'interface': 'interface',
    },
    'external-hard-drive': {
        'type': 'type',
        'interface': 'interface',
        'capacity': 'capacity',
        'price_per_gb': 'price/Gb',
        'color': 'color',
    },
    'optical-drive': {
        'bd': 'bd',
        'dvd': 'dvd',
        'cd': 'cd',
        'bd_write': 'bdWrite',
        'dvd_write': 'dvdWrite',
        'cd_write': 'cdWrite',
    },
    'fan-controller': {
        'channels': 'channels',
        'channel_wattage': 'channelWattage',
        'pwm': 'pwm',
        'form_factor': 'formFactor',
        'color': 'color',
    },
    'ups': {
        'capacity_w': 'capacityW',
        'capacity_va': 'capacityVa',
    },
    'thermal-paste': {
        'amount': 'amount',
    },
    'os': {
        'mode': 'mode',
        'max_memory': 'maxMemory',
    },
    'case-accessory': {
        'type': 'type',
        'form_factor': 'formFactor',
    },
}

def parse_price(val):
    if not val or val == '0':
        return None
    try:
        return float(val)
    except (ValueError, TypeError):
        m = re.search(r'[\d.]+', str(val))
        return float(m.group()) if m else None

def csv_to_json(csv_name):
    csv_path = os.path.join(DATA_DIR, csv_name)
    category = csv_name.replace('.csv', '')
    json_category = CATEGORY_MAP.get(category, category)
    
    if not os.path.exists(csv_path):
        print(f'  SKIP: {csv_name} not found')
        return
    
    with open(csv_path, 'r', encoding='utf-8') as f:
        lines = f.readlines()
    
    if len(lines) < 2:
        print(f'  SKIP: {csv_name} empty')
        return
    
    headers = [h.strip() for h in lines[0].strip().split(',')]
    spec_keys = SPEC_MAP.get(category, {})
    
    items = []
    for line in lines[1:]:
        line = line.strip()
        if not line:
            continue
        
        vals = []
        current = ''
        in_quotes = False
        for char in line:
            if char == '"':
                in_quotes = not in_quotes
            elif char == ',' and not in_quotes:
                vals.append(current)
                current = ''
            else:
                current += char
        vals.append(current)
        
        row = {}
        for i, h in enumerate(headers):
            if i < len(vals):
                row[h] = vals[i].strip().strip('"')
            else:
                row[h] = ''
        
        name = row.get('name', '')
        if not name:
            continue
        
        price = parse_price(row.get('price'))
        
        specs = {}
        for csv_field, json_field in spec_keys.items():
            val = row.get(csv_field, '')
            if val:
                specs[json_field] = val
        
        image = row.get('image', '')
        if image and not image.startswith('http') and not image.startswith('thumbnails'):
            image = ''
        
        item = {
            'productName': name,
            'category': json_category,
            'country': 'gb',
            'price': price,
            'priceCurrency': '\u00a3' if price is not None else None,
            'rating': None,
            'ratingCount': None,
            'specs': specs if specs else None,
            'imageUrl': image if image else None,
            'url': None,
            'scrapedAt': datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.000Z'),
        }
        items.append(item)
    
    if items:
        output_path = os.path.join(OUTPUT_DIR, f'{category}.json')
        with open(output_path, 'w', encoding='utf-8') as f:
            json.dump(items, f, indent=2, ensure_ascii=False)
        print(f'  OK: {category}.json ({len(items)} items)')
    else:
        print(f'  EMPTY: {csv_name}')

def main():
    if not os.path.exists(OUTPUT_DIR):
        os.makedirs(OUTPUT_DIR)
    
    # Process all CSVs that exist
    csv_files = [f for f in os.listdir(DATA_DIR) if f.endswith('.csv')]
    already_done = {'cooler.json', 'cpu.json', 'motherboard.json', 'ram.json'}
    
    for csv_file in sorted(csv_files):
        json_name = csv_file.replace('.csv', '.json')
        if json_name in already_done:
            print(f'  SKIP: {json_name} already exists in scraped_data/')
            continue
        csv_to_json(csv_file)
    
    print('\nDone!')

if __name__ == '__main__':
    main()
