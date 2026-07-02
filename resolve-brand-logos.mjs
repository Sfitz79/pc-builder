import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

/**
 * Brand Logo Resolver Service
 * Uses public open-source data sources per the specification:
 * 1. Wikimedia Commons (free, no API key)
 * 2. Pixabay API (free tier, needs key)
 * 3. VectorLogoZone (open source SVG logos)
 */

const ROOT = path.resolve(import.meta.dirname, '.');
const LOGO_DIR = path.join(ROOT, 'public', 'brand-logos');
const CONCURRENCY = 4; // Be nice to Wikimedia
const TIMEOUT = 15000;

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

function extractUniqueBrands() {
  const DATA_DIR = path.join(ROOT, 'src', 'data');
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
  return [...brandSet].sort();
}

async function fetchWithRetry(url, retries = 2) {
  for (let attempt = 0; attempt <= retries; attempt++) {
    try {
      const resp = await fetch(url, {
        signal: AbortSignal.timeout(TIMEOUT),
        headers: { 'User-Agent': 'PCTG-PC-Builder/1.0 (datapipeline) Wikimedia-Logo-Resolver' },
      });
      if (resp.ok) return resp;
    } catch {
      if (attempt === retries) return null;
      await new Promise(r => setTimeout(r, 1000 * (attempt + 1)));
    }
  }
  return null;
}

async function downloadFile(url, dest) {
  try {
    const resp = await fetch(url, {
      signal: AbortSignal.timeout(TIMEOUT),
      headers: { 'User-Agent': 'Mozilla/5.0 PCTG-PC-Builder/1.0' },
      redirect: 'follow',
    });
    if (!resp.ok) return false;
    const buf = Buffer.from(await resp.arrayBuffer());
    if (buf.length < 256) return false;
    fs.writeFileSync(dest, buf);
    return true;
  } catch {
    return false;
  }
}

/* ── Source 1: VectorLogoZone (open source SVG logos) ── */
async function tryVectorLogoZone(brand) {
  // VectorLogoZone hosts SVG logos at predictable URLs
  const slug = brand.replace(/[^a-z0-9]/g, '').toLowerCase();
  const urls = [
    `https://cdn.jsdelivr.net/npm/@vectorlogo/zone@latest/logos/${slug}/${slug}.svg`,
    `https://cdn.jsdelivr.net/npm/@vectorlogo/zone@latest/logos/${slug}/${slug}-icon.svg`,
  ];
  for (const url of urls) {
    if (await downloadFile(url, path.join(LOGO_DIR, `${slug}.svg`))) return 'vectorlogozone';
  }
  return null;
}

/* ── Source 2: Wikimedia Commons ── */
async function searchWikimediaLogo(brand) {
  const queries = [
    `${brand} logo vector`,
    `${brand} logo`,
    `${brand} brand`,
  ];

  for (const query of queries) {
    const url = `https://commons.wikimedia.org/w/api.php?action=query&list=search&srsearch=${encodeURIComponent(query)}&srlimit=5&srnamespace=6&format=json&origin=*`;
    const resp = await fetchWithRetry(url);
    if (!resp) continue;

    const data = await resp.json();
    const results = data?.query?.search || [];
    if (results.length === 0) continue;

    for (const result of results) {
      const title = result.title;
      // Filter for logo-type images only
      if (!/logo|brand/i.test(title)) continue;
      // Filter for open licenses
      if (/fair|nonfree|non-free|commercial/i.test(result.snippet)) continue;

      const cleanTitle = title.replace(/^File:/, '');
      const imgUrl = `https://commons.wikimedia.org/wiki/Special:FilePath/${encodeURIComponent(cleanTitle)}?width=512`;

      const safeName = brand.replace(/[^a-z0-9]/g, '_').toLowerCase();
      const ext = (cleanTitle.match(/\.(png|svg|jpg|jpeg|webp)$/i) || [])[0]?.toLowerCase() || '.png';
      const dest = path.join(LOGO_DIR, `${safeName}${ext}`);

      if (await downloadFile(imgUrl, dest)) return 'wikimedia';
    }
  }
  return null;
}

/* ── Source 3: Clearbit (existing, with retry) ── */
async function tryClearbit(brand) {
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

  const key = Object.keys(BRAND_DOMAINS).find(k => brand.includes(k));
  const domain = key ? BRAND_DOMAINS[key] : (brand.replace(/[^a-z0-9]/g, '') + '.com');
  if (!domain) return null;

  const dest = path.join(LOGO_DIR, brand.replace(/[^a-z0-9]/g, '_').toLowerCase() + '.png');
  const url = `https://logo.clearbit.com/${domain}`;
  if (await downloadFile(url, dest)) return 'clearbit';
  return null;
}

/* ── Source 4: Google Favicons (existing) ── */
async function tryFavicon(brand) {
  const domain = brand.replace(/[^a-z0-9]/g, '') + '.com';
  const dest = path.join(LOGO_DIR, brand.replace(/[^a-z0-9]/g, '_').toLowerCase() + '.png');
  const url = `https://www.google.com/s2/favicons?domain=${domain}&sz=128`;
  if (await downloadFile(url, dest)) return 'favicon';
  return null;
}

/* ── Main ── */
async function main() {
  console.log('=== Brand Logo Resolver Service ===\n');
  console.log('Sources: VectorLogoZone, Wikimedia Commons, Clearbit, Google Favicons\n');

  const brands = extractUniqueBrands();
  console.log(`Found ${brands.length} unique brands\n`);

  // Check which logos already exist
  const existing = new Set(
    fs.readdirSync(LOGO_DIR)
      .filter(f => /\.(png|svg|jpg|webp)$/i.test(f))
      .map(f => f.replace(/\.(png|svg|jpg|webp)$/i, ''))
  );

  const needed = brands.filter(b => !existing.has(b.replace(/[^a-z0-9]/g, '_').toLowerCase()));
  console.log(`${existing.size} logos cached, ${needed.length} brands need resolution\n`);

  if (needed.length === 0) {
    console.log('All brand logos already resolved.');
    return;
  }

  let resolved = 0, failed = 0;

  // Phase 1: VectorLogoZone (open source, best quality)
  console.log('Phase 1: VectorLogoZone...');
  const vlzQueue = [...needed];
  async function vlzWorker() {
    while (vlzQueue.length > 0) {
      const brand = vlzQueue.shift().toLowerCase();
      const result = await tryVectorLogoZone(brand);
      if (result) { resolved++; }
    }
  }
  const vlzWorkers = Array.from({ length: CONCURRENCY }, () => vlzWorker());
  await Promise.all(vlzWorkers);
  console.log(`  VectorLogoZone: resolved ${resolved}`);

  // Phase 2: Wikimedia Commons (open data, good quality)
  const remaining = brands.filter(b => {
    const safeName = b.toLowerCase().replace(/[^a-z0-9]/g, '_');
    return !fs.existsSync(path.join(LOGO_DIR, `${safeName}.png`)) &&
           !fs.existsSync(path.join(LOGO_DIR, `${safeName}.svg`));
  });

  console.log(`\nPhase 2: Wikimedia Commons (${remaining.length} remaining)...`);
  let wikiResolved = 0;
  const wikiQueue = [...remaining];
  async function wikiWorker() {
    while (wikiQueue.length > 0) {
      const brand = wikiQueue.shift().toLowerCase();
      const result = await searchWikimediaLogo(brand);
      if (result) {
        wikiResolved++;
        process.stdout.write(`+`);
      } else {
        process.stdout.write(`.`);
      }
    }
  }
  const wikiWorkers = Array.from({ length: CONCURRENCY }, () => wikiWorker());
  await Promise.all(wikiWorkers);
  console.log(`\n  Wikimedia: resolved ${wikiResolved}`);
  resolved += wikiResolved;

  // Phase 3: Clearbit (for any still missing)
  const stillMissing = brands.filter(b => {
    const safeName = b.toLowerCase().replace(/[^a-z0-9]/g, '_');
    return !fs.existsSync(path.join(LOGO_DIR, `${safeName}.png`)) &&
           !fs.existsSync(path.join(LOGO_DIR, `${safeName}.svg`));
  });

  console.log(`\nPhase 3: Clearbit (${stillMissing.length} remaining)...`);
  let clearbitResolved = 0;
  const cbQueue = [...stillMissing];
  async function cbWorker() {
    while (cbQueue.length > 0) {
      const brand = cbQueue.shift().toLowerCase();
      const result = await tryClearbit(brand);
      if (result) { clearbitResolved++; process.stdout.write(`+`); }
      else { process.stdout.write(`.`); }
    }
  }
  const cbWorkers = Array.from({ length: 10 }, () => cbWorker());
  await Promise.all(cbWorkers);
  console.log(`\n  Clearbit: resolved ${clearbitResolved}`);
  resolved += clearbitResolved;

  // Phase 4: Favicons (last resort)
  const finalMissing = brands.filter(b => {
    const safeName = b.toLowerCase().replace(/[^a-z0-9]/g, '_');
    return !fs.existsSync(path.join(LOGO_DIR, `${safeName}.png`)) &&
           !fs.existsSync(path.join(LOGO_DIR, `${safeName}.svg`));
  });

  console.log(`\nPhase 4: Google Favicons (${finalMissing.length} remaining)...`);
  let faviconResolved = 0;
  const fvQueue = [...finalMissing];
  async function fvWorker() {
    while (fvQueue.length > 0) {
      const brand = fvQueue.shift().toLowerCase();
      const result = await tryFavicon(brand);
      if (result) { faviconResolved++; process.stdout.write(`+`); }
      else { process.stdout.write(`.`); }
    }
  }
  const fvWorkers = Array.from({ length: 10 }, () => fvWorker());
  await Promise.all(fvWorkers);
  console.log(`\n  Favicons: resolved ${faviconResolved}`);
  resolved += faviconResolved;

  failed = needed.length - resolved;

  const total = fs.readdirSync(LOGO_DIR).filter(f => /\.(png|svg|jpg|webp)$/i.test(f)).length;
  console.log(`\n=== Summary ===`);
  console.log(`  Total logos on disk: ${total}`);
  console.log(`  Resolved this run: ${resolved}`);
  console.log(`  Failed to resolve: ${failed}`);
  console.log(`  Coverage: ${((total / brands.length) * 100).toFixed(1)}%`);
}

main().catch(e => { console.error(e); process.exit(1); });
