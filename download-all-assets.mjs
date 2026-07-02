import fs from 'fs';
import path from 'path';

const ROOT = path.resolve(import.meta.dirname, '.');
const DATA_DIR = path.join(ROOT, 'src', 'data');
const THUMB_DIR = path.join(ROOT, 'public', 'thumbnails');
const LOGO_DIR = path.join(ROOT, 'public', 'brand-logos');
const CONCURRENCY = 10;
const TIMEOUT = 10000;

fs.mkdirSync(LOGO_DIR, { recursive: true });

function parseCSVLine(line) {
  const values = [];
  let current = '', inQuotes = false;
  for (const char of line) {
    if (char === '"') inQuotes = !inQuotes;
    else if (char === ',' && !inQuotes) { values.push(current); current = ''; }
    else current += char;
  }
  values.push(current);
  return values;
}

async function downloadFile(url, dest) {
  try {
    const resp = await fetch(url, {
      signal: AbortSignal.timeout(TIMEOUT),
      headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) PC-Builder/1.0', 'Accept': 'image/webp,image/apng,image/svg+xml,image/*,*/*' },
      redirect: 'follow',
    });
    if (!resp.ok) return false;
    const ct = resp.headers.get('content-type') || '';
    if (!ct.startsWith('image/')) return false;
    const buf = Buffer.from(await resp.arrayBuffer());
    if (buf.length < 512) return false;
    fs.writeFileSync(dest, buf);
    return true;
  } catch {
    return false;
  }
}

/* ── Brand Logo Downloader ── */
async function downloadBrandLogos() {
  // Extract unique brands from all CSVs
  const brandSet = new Set();
  const files = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));

  for (const f of files) {
    const content = fs.readFileSync(path.join(DATA_DIR, f), 'utf-8');
    const lines = content.split('\n').filter(l => l.trim());
    if (lines.length < 2) continue;
    const header = parseCSVLine(lines[0]);
    const brandIdx = header.indexOf('brand');
    const nameIdx = header.indexOf('name');
    if (brandIdx === -1 && nameIdx === -1) continue;
    for (let i = 1; i < lines.length; i++) {
      const parts = parseCSVLine(lines[i]);
      let brand = '';
      if (brandIdx !== -1) {
        brand = (parts[brandIdx] || '').replace(/^"|"$/g, '').trim();
      }
      if (!brand && nameIdx !== -1) {
        brand = (parts[nameIdx] || '').replace(/^"|"$/g, '').trim().split(' ')[0];
      }
      if (brand) brandSet.add(brand.toLowerCase());
    }
  }

  const brands = [...brandSet].sort();
  console.log(`Found ${brands.length} unique brands across all CSVs\n`);

  // Brand domain map (same as in common.js) + domain derivation
  const BRAND_DOMAINS = {
    intel: 'intel.com', amd: 'amd.com', nvidia: 'nvidia.com', asus: 'asus.com',
    msi: 'msi.com', gigabyte: 'gigabyte.com', corsair: 'corsair.com',
    evga: 'evga.com', samsung: 'samsung.com', kingston: 'kingston.com',
    wd: 'wd.com', seagate: 'seagate.com', crucial: 'crucial.com',
    noctua: 'noctua.at', lian: 'lian-li.com', nzxt: 'nzxt.com',
    phanteks: 'phanteks.com', fractal: 'fractal-design.com',
    cooler: 'coolermaster.com', thermaltake: 'thermaltake.com',
    bequiet: 'bequiet.com', asrock: 'asrock.com', pny: 'pny.com',
    zotac: 'zotac.com', sapphire: 'sapphiretech.com',
    powercolor: 'powercolor.com', xfx: 'xfxforce.com',
    gskill: 'gskill.com', team: 'teamgroupinc.com', adata: 'adata.com',
    lexar: 'lexar.com', sabrent: 'sabrent.com', deepcool: 'deepcool.com',
    arctic: 'arctic.de', scythe: 'scythe-eu.com',
    ekwb: 'ekwb.com', silverstone: 'silverstonetek.com',
    antec: 'antec.com', inwin: 'in-win.com', razer: 'razer.com',
    logitech: 'logitech.com', steelseries: 'steelseries.com',
    hyperx: 'hyperxgaming.com', li: 'lian-li.com', lianli: 'lian-li.com',
    western: 'wd.com', thermalright: 'thermalright.com',
    seasonic: 'seasonic.com', superflower: 'super-flower.com',
    fsp: 'fsp-group.com', enermax: 'enermax.com',
    bitfenix: 'bitfenix.com', idcooling: 'idcooling.com',
    viewsonic: 'viewsonic.com', lg: 'lg.com', dell: 'dell.com',
    hp: 'hp.com', acer: 'acer.com', lenovo: 'lenovo.com',
    benq: 'benq.com', aoc: 'aoc.com', palit: 'palit.com',
    gainward: 'gainward.com', inno3d: 'inno3d.com',
    kfa: 'kfa2.com', galax: 'galax.com', colorful: 'colorful.com',
    maxsun: 'maxsun.com', yeston: 'yeston.com', oloy: 'oloy.com',
    newegg: 'newegg.com', patriot: 'patriotmemory.com',
    siliconpower: 'silicon-power.com', timetec: 'timetec.com',
    klevv: 'klevv.com', pccooler: 'pccooler.com',
    jonsbo: 'jonsbo.com', metallic: 'metallicgear.com',
    coolman: 'coolman.com', ssupd: 'ssupd.com', hyte: 'hyte.com',
    matorx: 'matorx.com', streacom: 'streacom.com',
    akasa: 'akasa.com', alphel: 'alphel.com',
    alphacool: 'alphacool.com', watercool: 'watercool.de',
    blacknoise: 'blacknoise.de', noiseblocker: 'noiseblocker.de',
    prolimatech: 'prolimatech.com', raijintek: 'raijintek.com',
    reeven: 'reeven.com', swiftech: 'swiftech.com',
    thermal: 'thermalright.com'
  };

  function getDomain(brand) {
    const key = Object.keys(BRAND_DOMAINS).find(k => brand.includes(k));
    if (key) return BRAND_DOMAINS[key];
    const clean = brand.replace(/[^a-z0-9]/g, '');
    return clean ? clean + '.com' : null;
  }

  async function downloadLogo(brand) {
    const safeName = brand.replace(/[^a-z0-9]/g, '_').toLowerCase();
    const dest = path.join(LOGO_DIR, safeName + '.png');
    if (fs.existsSync(dest)) return 'cached';

    const domain = getDomain(brand);
    if (!domain) return 'no-domain';

    // Try Clearbit first
    const clearbitUrl = `https://logo.clearbit.com/${domain}`;
    if (await downloadFile(clearbitUrl, dest)) return 'clearbit';

    // Try Favicons as fallback
    const faviconUrl = `https://www.google.com/s2/favicons?domain=${domain}&sz=128`;
    if (await downloadFile(faviconUrl, dest)) return 'favicon';

    return 'failed';
  }

  let clearbit = 0, favicon = 0, cached = 0, failed = 0, noDomain = 0;
  const queue = [...brands];

  async function worker() {
    while (queue.length > 0) {
      const brand = queue.shift();
      const result = await downloadLogo(brand);
      if (result === 'clearbit') clearbit++;
      else if (result === 'favicon') favicon++;
      else if (result === 'cached') cached++;
      else if (result === 'no-domain') noDomain++;
      else failed++;
      process.stdout.write(`\r  Logos: ${cached} cached, ${clearbit} Clearbit, ${favicon} Favicon, ${failed} failed, ${noDomain} no-domain / ${brands.length}`);
    }
  }

  const workers = Array.from({ length: CONCURRENCY }, () => worker());
  await Promise.all(workers);
  console.log(`\n  Done. ${cached + clearbit + favicon}/${brands.length} logos downloaded.`);
}

/* ── Commerce Image API Search (unsplash / Wikimedia) ── */
async function searchWikimediaProduct(query) {
  try {
    const url = `https://commons.wikimedia.org/w/api.php?action=query&list=search&srsearch=${encodeURIComponent(query + ' product')}&srlimit=3&format=json&origin=*`;
    const resp = await fetch(url, { signal: AbortSignal.timeout(8000) });
    if (!resp.ok) return [];
    const data = await resp.json();
    if (!data?.query?.search) return [];
    return data.query.search
      .filter(r => r.title.match(/\.(jpg|jpeg|png|webp|svg)$/i))
      .map(r => {
        const cleanTitle = r.title.replace(/^File:/, '');
        return `https://commons.wikimedia.org/wiki/Special:FilePath/${encodeURIComponent(cleanTitle)}?width=400`;
      });
  } catch {
    return [];
  }
}

async function downloadMissingProductImages() {
  const files = fs.readdirSync(DATA_DIR).filter(f => f.endsWith('.csv'));
  let totalDownloaded = 0, totalSkipped = 0, totalFailed = 0;

  for (const f of files) {
    const content = fs.readFileSync(path.join(DATA_DIR, f), 'utf-8');
    const lines = content.split('\n').filter(l => l.trim());
    if (lines.length < 2) continue;
    const header = parseCSVLine(lines[0]);
    const imgIdx = header.indexOf('image');
    const nameIdx = header.indexOf('name');
    if (nameIdx === -1) continue;

    const category = f.replace('.csv', '');
    const hasImageColumn = imgIdx !== -1;

    let missing = 0, downloaded = 0, failed = 0, skipped = 0;

    for (let i = 1; i < Math.min(lines.length, 1000); i++) {
      const parts = parseCSVLine(lines[i]);
      const name = (parts[nameIdx] || '').replace(/^"|"$/g, '').trim();
      if (!name) continue;

      // Determine expected thumbnail filename
      const sanitized = name.replace(/[^a-z0-9]/gi, '_').toLowerCase().substring(0, 50);
      const expectedFiles = ['.jpg', '.png', '.webp'].map(ext => `${category}_${sanitized}${ext}`);
      const existing = expectedFiles.find(ef => fs.existsSync(path.join(THUMB_DIR, ef)));

      if (existing) {
        skipped++;
        continue;
      }

      // No local image found - search for one
      const images = await searchWikimediaProduct(name + ' ' + category.replace('-', ' '));
      let saved = false;

      for (const imgUrl of images) {
        const ext = path.extname(new URL(imgUrl).pathname).split('?')[0] || '.jpg';
        const dest = path.join(THUMB_DIR, `${category}_${sanitized}${ext}`);
        if (await downloadFile(imgUrl, dest)) {
          saved = true;
          downloaded++;
          break;
        }
      }

      if (!saved) {
        // If CSV has image column but file is missing, try PCPP or other source
        if (hasImageColumn) {
          const imgRef = (parts[imgIdx] || '').replace(/^"|"$/g, '').trim();
          if (imgRef && imgRef.startsWith('http')) {
            const ext = path.extname(imgRef.split('?')[0]) || '.jpg';
            const dest = path.join(THUMB_DIR, `${category}_${sanitized}${ext}`);
            if (await downloadFile(imgRef, dest)) {
              downloaded++;
            } else {
              failed++;
            }
          } else {
            failed++;
          }
        } else {
          failed++;
        }
      }

      missing++;
      process.stdout.write(`\r  ${f}: ${downloaded} downloaded, ${failed} failed, ${skipped} cached / ${missing} processed`);
    }

    if (missing > 0) {
      console.log(`\n  ${f}: ${downloaded} OK, ${failed} FAIL, ${skipped} cached`);
    }
    totalDownloaded += downloaded;
    totalFailed += failed;
    totalSkipped += skipped;
  }

  console.log(`\nProduct images: ${totalDownloaded} downloaded, ${totalFailed} failed, ${totalSkipped} cached`);
}

/* ── Main ── */
async function main() {
  console.log('=== PC Builder Asset Downloader ===\n');

  // Phase 1: Brand logos
  console.log('Phase 1/2: Downloading brand logos...');
  const logoStart = Date.now();
  await downloadBrandLogos();
  const logoTime = ((Date.now() - logoStart) / 1000).toFixed(1);
  const logoCount = fs.readdirSync(LOGO_DIR).filter(f => f.endsWith('.png')).length;
  console.log(`  Brand logos done in ${logoTime}s. Total: ${logoCount}\n`);

  // Phase 2: Missing product images
  console.log('Phase 2/2: Finding missing product images...');
  const imgStart = Date.now();
  await downloadMissingProductImages();
  const imgTime = ((Date.now() - imgStart) / 1000).toFixed(1);
  const thumbCount = fs.readdirSync(THUMB_DIR).filter(f => /\.(jpg|png|webp)$/i.test(f)).length;
  console.log(`  Product images done in ${imgTime}s. Total thumbnails: ${thumbCount}\n`);

  console.log('=== All done ===');
}

main().catch(e => { console.error(e); process.exit(1); });
