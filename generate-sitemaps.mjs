import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const OUT_DIR = path.join(__dirname, 'webscraper-sitemaps');
fs.mkdirSync(OUT_DIR, { recursive: true });

const CATEGORIES = [
  { id: 'case', url: 'https://uk.pcpartpicker.com/products/case/' },
  { id: 'motherboard', url: 'https://uk.pcpartpicker.com/products/motherboard/' },
  { id: 'case-fan', url: 'https://uk.pcpartpicker.com/products/case-fan/' },
  { id: 'monitor', url: 'https://uk.pcpartpicker.com/products/monitor/' },
  { id: 'keyboard', url: 'https://uk.pcpartpicker.com/products/keyboard/' },
  { id: 'mouse', url: 'https://uk.pcpartpicker.com/products/mouse/' },
  { id: 'headphones', url: 'https://uk.pcpartpicker.com/products/headphones/' },
  { id: 'external-hard-drive', url: 'https://uk.pcpartpicker.com/products/internal-hard-drive/' },
  { id: 'speakers', url: 'https://uk.pcpartpicker.com/products/speakers/' },
  { id: 'ups', url: 'https://uk.pcpartpicker.com/products/ups/' },
  { id: 'webcam', url: 'https://uk.pcpartpicker.com/products/webcam/' },
  { id: 'wired-network-card', url: 'https://uk.pcpartpicker.com/products/wired-network-card/' },
  { id: 'wireless-network-card', url: 'https://uk.pcpartpicker.com/products/wireless-network-card/' },
  { id: 'sound-card', url: 'https://uk.pcpartpicker.com/products/sound-card/' },
  { id: 'optical-drive', url: 'https://uk.pcpartpicker.com/products/optical-drive/' },
  { id: 'fan-controller', url: 'https://uk.pcpartpicker.com/products/fan-controller/' },
  { id: 'thermal-paste', url: 'https://uk.pcpartpicker.com/products/thermal-paste/' },
  { id: 'os', url: 'https://uk.pcpartpicker.com/products/os/' },
];

function buildSitemap(cat) {
  const id = 'pcpp-' + cat.id;
  return {
    _id: id,
    startUrl: cat.url,
    selectors: [
      {
        parentSelectors: ['_root'],
        type: 'SelectorLink',
        multiple: true,
        id: 'product_link',
        selector: 'a[href*="/product/"]',
        delay: '',
      },
      {
        parentSelectors: ['product_link'],
        type: 'SelectorText',
        multiple: false,
        id: 'name',
        selector: 'h1.pageTitle',
        regex: '',
        delay: '',
      },
      {
        parentSelectors: ['product_link'],
        type: 'SelectorImage',
        multiple: false,
        id: 'name4',
        selector: '#pp_main_product_image',
        delay: '',
      },
      {
        parentSelectors: ['product_link'],
        type: 'SelectorText',
        multiple: false,
        id: 'price',
        selector: 'td.td__finalPrice a.pp_async_mr',
        regex: '',
        delay: '',
      },
    ],
  };
}

for (const cat of CATEGORIES) {
  const sitemap = buildSitemap(cat);
  const fp = path.join(OUT_DIR, 'pcpp-' + cat.id + '.json');
  fs.writeFileSync(fp, JSON.stringify(sitemap, null, 2));
  console.log('  ' + fp);
}

console.log('\nDone - ' + CATEGORIES.length + ' sitemaps');
