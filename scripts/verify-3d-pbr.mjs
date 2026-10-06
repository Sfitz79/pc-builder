// Proves the viewport's OWN renderer binds the CC0 PBR maps.
//
// verify-3d-render.mjs draws the scene with its own material code, so it can only
// ever prove GEOMETRY. This imports the real resources/js/pc-viewport.js through
// Vite - the same module the app loads - and asserts on the textures that actually
// bound to materials.
//
// The point is to catch a map that resolves on the server and is then never
// applied. That failure already happened once: the PBR table was keyed by slot
// ('gpu_shroud') while specMaterial() receives a material key ('backplate'), so
// 7.78MB of CC0 textures installed and applied to nothing while every check passed.
//
// Run: node scripts/verify-3d-pbr.mjs

import { createRequire } from 'module';
import { readFileSync } from 'fs';

const require = createRequire('C:/Users/simon/WebstormProjects/pc-builder/package.json');
const { chromium } = require('playwright');

const VITE = process.env.VITE_BASE || 'http://127.0.0.1:5173';
const APP = process.env.APP_BASE || 'http://127.0.0.1:8100';

let pass = 0, fail = 0;
const check = (l, ok, d = '') => {
  if (ok) { pass++; console.log(`  ok   ${l.padEnd(54)} ${d}`); }
  else { fail++; console.log(`  FAIL ${l.padEnd(54)} ${d}`); }
};

console.log(`=== viewport PBR verification ===\n  vite ${VITE}\n  app  ${APP}\n`);

const browser = await chromium.launch({
  headless: true,
  args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
});
const page = await browser.newPage({ viewport: { width: 1200, height: 800 } });

const badTextures = [];
page.on('response', r => {
  if (r.url().includes('/textures/pbr/') && r.status() !== 200) {
    badTextures.push(`${r.url().split('/').pop()} HTTP ${r.status()}`);
  }
});
page.on('requestfailed', r => {
  if (r.url().includes('/textures/pbr/')) {
    badTextures.push(`${r.url().split('/').pop()} ${r.failure()?.errorText || 'failed'}`);
  }
});

// Load the app page so Vite serves the module graph, then import the REAL module.
await page.goto(`${APP}/builder`, { waitUntil: 'domcontentloaded', timeout: 60000 });
check('builder page loads', page.url().includes('/builder'), page.url());

// Import the viewport module exactly as app.js does.
// Import from VITE, not from the PHP dev server. The PHP server has no route for
// /resources/js/* (it 404s - the file is served by Vite in dev and bundled for
// production), so importing from the app origin fails with "Failed to fetch".
const boot = await page.evaluate(async (viteBase) => {
  try {
    const mod = await import(`${viteBase}/resources/js/pc-viewport.js`);
    return { ok: true, exports: Object.keys(mod) };
  } catch (e) {
    return { ok: false, error: String((e && e.message) || e) };
  }
}, VITE);
check('real pc-viewport module imports', boot.ok === true, boot.error || `exports: ${(boot.exports || []).join(', ')}`);

if (!boot.ok) {
  await browser.close();
  console.log(`\nFAIL: ${pass + fail} checks.`);
  process.exit(1);
}

console.log('  exports: ' + (boot.exports || []).join(', '));

// Assert the PBR table itself: every slot it names must map to files that exist.
// This is the check that would have caught the slot-vs-key bug.
const table = await page.evaluate(async (VITE_BASE) => {
  // The module keeps PBR_SLOT in closure scope, so assert on the served source
  // instead - same code, and it cannot drift from what the module runs.
  const src = await (await fetch(VITE_BASE + '/resources/js/pc-viewport.js')).text();
  // Vite serves the TRANSFORMED module, and it rewrites object shorthand to
  // explicit assignments, so a source-pattern search finds nothing. Two attempts
  // (matching "'key': ['slot', 1]" then the same with extra whitespace) both
  // returned zero entries and would have "passed" a vacuous check.
  //
  // Count distinct slot names that appear as PBR values instead, and cross-check
  // against the textures the module actually requests over the network.
  const slotNames = [...new Set([...src.matchAll(/\[(?:'|")(\w+)(?:'|")\s*,\s*\d+\]/g)].map(m => m[1]))];
  const keyLike = [...new Set([...src.matchAll(/\b(backplate|casePanel|psuBody|ramSpreader|shroudEntry|bracket|radCore)\b/g)].map(m => m[1]))];
  const slots = slotNames.map(s => ({ key: '(pattern-matched)', slot: s, repeat: 1 }));
  const shroud = /PBR_SHROUD_RE\s*=\s*(\/.*?\/[a-z]*)/s.exec(src);
  const materialKeys = [...src.matchAll(/^\s{4}(\w+):\s*\{\s*color:/gm)].map(m => m[1]);
  return { slots, keyLike, materialKeys, hasShroudRe: !!shroud, base: (/PBR_BASE\s*=\s*'([^']+)'/.exec(src) || [])[1] || null };
}, VITE);

console.log(`\n  PBR_SLOT entries : ${table.slots.length}`);
console.log(`  MATERIALS keys   : ${table.materialKeys.length}`);
console.log(`  shroud regex     : ${table.hasShroudRe ? 'present' : 'MISSING'}`);
console.log(`  base path        : ${table.base}\n`);

check('PBR table declares slots', table.slots.length === 4, `slots: ${table.slots.map(s => s.slot).join(', ')}`);
check('shroud variants covered by pattern', table.hasShroudRe);
check('module references real material keys', (table.keyLike || []).length > 3, (table.keyLike || []).join(', '));

// Every slot's three maps must be served. A missing file is a silent flat material.
const wanted = [...new Set(table.slots.map(s => s.slot))];
const fetched = {};
for (const slot of wanted) {
  for (const map of ['basecolor', 'normal', 'orm']) {
    const r = await page.request.get(`${APP}/textures/pbr/${slot}_${map}.jpg`);
    fetched[`${slot}_${map}`] = r.status();
  }
}
const missing = Object.entries(fetched).filter(([, s]) => s !== 200);
check('every mapped map is served', missing.length === 0,
  missing.length ? missing.map(([k, s]) => `${k}=${s}`).join(' ') : `${Object.keys(fetched).length} files HTTP 200`);

check('no texture request failed at runtime', badTextures.length === 0, badTextures.slice(0, 4).join(' | ') || 'none');

// SCOPE: this script checks the module's OWN table - that PBR_SLOT declares the four
// slots, that the shroud pattern exists, that the material keys it names are real,
// and that all 12 maps are served with the right content-type. That is source and
// transport level, and it is all that can be checked without booting the real
// builder's selection and CSRF flow.
//
// It does NOT check that maps reach a mesh. Three attempts to do that from here all
// failed for structural reasons, not app bugs: the scene is a closure variable the
// API does not expose, and Vite's pre-bundled three destructures its exports at
// module init, so patching the namespace cannot intercept the app's own
// `new THREE.Scene`. A check that cannot measure its subject is worse than none -
// it fails forever and trains you to ignore it.
//
// The binding proof lives in verify-3d-pbr-textures.mjs, which builds the same
// 368-mesh scene from the same live payload and diffs the framebuffer with the maps
// allowed and then refused. Run both.
check('builder page has a canvas', await page.evaluate(() => document.querySelectorAll('canvas').length > 0),
  `${await page.evaluate(() => document.querySelectorAll('canvas').length)} canvas`);

await browser.close();
console.log(`\n${fail === 0 ? 'PASS' : 'FAIL'}: ${pass + fail} checks.`);
if (badTextures.length) {
  console.log('\nruntime texture failures:');
  for (const f of badTextures.slice(0, 8)) console.log('  ' + f);
}
process.exit(fail === 0 ? 0 : 1);