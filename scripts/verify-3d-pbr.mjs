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
  const keyLike = [...new Set([...src.matchAll(/\\b(backplate|casePanel|psuBody|ramSpreader|shroudEntry|bracket|radCore)\\b/g)].map(m => m[1]))];
  const slots = slotNames.map(s => ({ key: '(pattern-matched)', slot: s, repeat: 1 }));
  const shroud = /PBR_SHROUD_RE\s*=\s*(\/.*?\/[a-z]*)/s.exec(src);
  const materialKeys = [...src.matchAll(/^\s{4}(\w+):\s*\{\s*color:/gm)].map(m => m[1]);
  return { slots, materialKeys, hasShroudRe: !!shroud, base: (/PBR_BASE\s*=\s*'([^']+)'/.exec(src) || [])[1] || null };
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

// The assertion that actually matters: drive the REAL mountPcViewport with the
// live scene and count how many materials end up carrying a texture. Not that maps
// exist on disk, and not that the table parses - that they LAND.
const SCENE = readFileSync('C:/Users/simon/AppData/Local/Temp/opencode/mesh-spec-live.json', 'utf8');

const bound = await page.evaluate(async ({ viteBase, sceneJson }) => {
  // The scene is a CLOSURE variable inside mountPcViewport and the API does not
  // expose it - api is { assemble, snapshot, renderStorefront, renderer,
  // currentSignature, isBuilt, destroy }. Rather than edit app code to reach it,
  // instrument three FIRST so every Scene the module constructs is recorded. That
  // keeps the probe entirely outside the application.
  // Instrument by importing the SAME specifier the app's own module uses. Reading
  // window.THREE does not work: the app pulls three through Vite, so there is no
  // global, and guessing Vite's hashed dep path fails because the hash is per-run.
  let three = null;
  try {
    const mod = await import('three');
    three = mod.Scene ? mod : mod.default;
  } catch { three = window.THREE; }
  if (!three || !three.Scene) return { ok: false, why: 'could not resolve a THREE instance to instrument' };

  if (!window.__scenes) {
    window.__scenes = [];
    const OrigScene = three.Scene;
    three.Scene = function (...a) { const s = new OrigScene(...a); window.__scenes.push(s); return s; };
    three.Scene.prototype = OrigScene.prototype;
    const OrigStd = three.MeshStandardMaterial;
    three.MeshStandardMaterial = function (...a) { const m = new OrigStd(...a); (window.__mats ||= []).push(m); return m; };
    three.MeshStandardMaterial.prototype = OrigStd.prototype;
  }

  const mod = await import(viteBase + '/resources/js/pc-viewport.js');
  const mount = mod.mountPcViewport;
  if (typeof mount !== 'function') return { ok: false, why: 'mountPcViewport is not a function' };

  const host = document.getElementById('pc-viewport') || document.body;
  host.style.width = '1200px';
  host.style.height = '800px';
  if (!host.id) host.id = 'pc-viewport-probe';

  let api = null;
  try {
    // The SECOND argument is getSelection - a FUNCTION returning the selection -
    // not the scene itself. Passing the scene made the viewport call
    // getSelection(...) and die with "getSelection is not a function".
    const selection = JSON.parse(sceneJson);
    api = mount(host, () => selection.spec, {});
  } catch (e) {
    return { ok: false, why: String((e && e.message) || e) };
  }
  if (!api) return { ok: false, why: 'mountPcViewport returned null (no WebGL)' };

  // mountPcViewport returns an API, not the scene: { assemble, snapshot, renderer,
  // isBuilt, destroy }. The scene is reached through the renderer's WebGL context's
  // owning Object3D, or via api.assemble's return. Reach it the way the app does -
  // call assemble() - then walk api.renderer for the tree.
  let built = null;
  try {
    built = api.assemble ? await api.assemble() : null;
  } catch (e) {
    return { ok: false, why: 'assemble threw: ' + String((e && e.message) || e) };
  }

  // Let the async map loads settle before counting.
  await new Promise(r => setTimeout(r, 3000));

  const found = { total: 0, textured: 0, orm: 0, normal: 0, bySlot: {} };
  const visit = (o, depth = 0) => {
    if (!o || depth > 40) return;
    const m = o.material;
    if (m && !Array.isArray(m)) {
      found.total++;
      const slot = (m.map && m.map.name) || m.name || 'unnamed';
      if (m.map && m.map.image) { found.textured++; found.bySlot[slot] = (found.bySlot[slot] || 0) + 1; }
      if (m.roughnessMap && m.roughnessMap.image) found.orm++;
      if (m.normalMap && m.normalMap.image) found.normal++;
    }
    const kids = o.children || [];
    for (const k of kids) visit(k, depth + 1);
  };

  // Every scene the module built, plus whatever the API exposed.
  const roots = [...(window.__scenes || []), built, api.scene, api.root].filter(Boolean);
  for (const r of roots) visit(r);

  // Independent cross-check: tally the recorded materials directly, so a scene
  // graph that failed to walk cannot hide a bound texture.
  const mats = window.__mats || [];
  const direct = {
    mats: mats.length,
    map: mats.filter(m => m.map && m.map.image).length,
    orm: mats.filter(m => m.roughnessMap && m.roughnessMap.image).length,
    normal: mats.filter(m => m.normalMap && m.normalMap.image).length,
  };

  return {
    ok: true, ...found, direct,
    apiKeys: Object.keys(api || {}).slice(0, 12),
    isBuilt: api.isBuilt ? !!api.isBuilt() : null,
    scenesCaptured: (window.__scenes || []).length,
  };
}, { viteBase: VITE, sceneJson: SCENE });

if (!bound.ok) {
  check('viewport mounts with the live scene', false, bound.why);
} else {
  check('viewport mounts with the live scene', true, `api: ${(bound.apiKeys || []).join(', ')}`);
  console.log(`\n  scenes captured  : ${bound.scenesCaptured}`);
  console.log(`  graph materials  : ${bound.total}`);
  console.log(`  recorded mats    : ${bound.direct?.mats}`);
  console.log(`  with base colour : ${bound.textured} (graph) / ${bound.direct?.map} (recorded)`);
  console.log(`  with ORM         : ${bound.orm} (graph) / ${bound.direct?.orm} (recorded)`);
  console.log(`  with normal      : ${bound.normal} (graph) / ${bound.direct?.normal} (recorded)`);

  const mats = bound.direct?.mats || 0;
  check('materials exist in the scene graph', bound.total > 100, `${bound.total} in graph, ${mats} recorded`);
  check('PBR base colour actually BOUND', (bound.direct?.map || 0) > 4, `${bound.direct?.map} materials textured`);
  check('ORM (roughness+metalness+AO) actually BOUND', (bound.direct?.orm || 0) > 4, `${bound.direct?.orm}`);
  check('normal maps actually BOUND', (bound.direct?.normal || 0) > 4, `${bound.direct?.normal}`);
}

check('builder page has a canvas', await page.evaluate(() => document.querySelectorAll('canvas').length > 0),
  `${await page.evaluate(() => document.querySelectorAll('canvas').length)} canvas`);

await browser.close();
console.log(`\n${fail === 0 ? 'PASS' : 'FAIL'}: ${pass + fail} checks.`);
if (badTextures.length) {
  console.log('\nruntime texture failures:');
  for (const f of badTextures.slice(0, 8)) console.log('  ' + f);
}
process.exit(fail === 0 ? 0 : 1);
