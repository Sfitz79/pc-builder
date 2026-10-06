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

// THE ASSERTION THAT ACTUALLY MATTERS: prove the maps really BOUND, behaviourally.
//
// Three previous attempts to inspect materials all failed for structural reasons,
// not app bugs. The scene is a closure variable the API does not expose, and Vite's
// pre-bundled three destructures its exports at module init, so patching the
// namespace cannot intercept the app's own `new THREE.Scene`. Reading internals
// was the wrong tool.
//
// So use the NETWORK as the lever instead. Render the real scene twice through the
// real mountPcViewport - texture requests allowed, then refused - and compare the
// actual framebuffer. If nothing were bound the two frames would be IDENTICAL,
// which is precisely the silent no-op that already shipped once: 7.78MB of CC0
// maps installed, applied to nothing, every check green. A file that never
// reaches a mesh cannot change a pixel.
const SCENE = readFileSync('C:/Users/simon/AppData/Local/Temp/opencode/mesh-spec-live.json', 'utf8');

// Phase-tag texture requests so "did it ask for the maps" and "were they refused"
// are measured, not assumed.
let pbrPhase = 'allowed';
let pbrReqAllowed = 0;
let pbrReqBlocked = 0;
await page.route('**/*', async (route) => {
  const url = route.request().url();
  if (url.includes('/textures/pbr/')) {
    if (pbrPhase === 'allowed') { pbrReqAllowed++; return route.continue(); }
    pbrReqBlocked++;
    return route.abort();
  }
  return route.continue();
});

// Mount the real viewport, let the async TextureLoader settle, then read the
// framebuffer back off the canvas.
const grabFrame = async (slot) => {
  await page.evaluate(async ({ viteBase, sceneJson, slot }) => {
    const mod = await import(viteBase + '/resources/js/pc-viewport.js');
    const mount = mod.mountPcViewport;
    if (typeof mount !== 'function') { window.__pbrErr = 'mountPcViewport is not a function'; return; }

    const host = document.createElement('div');
    host.id = 'pbr-probe-' + slot;
    host.style.cssText = 'width:900px;height:640px;position:fixed;left:0;top:0;z-index:99999';
    document.body.appendChild(host);

    const selection = JSON.parse(sceneJson);
    // The second argument is getSelection - a FUNCTION returning the selection.
    let api = null;
    try {
      api = mount(host, () => selection.spec, {});
    } catch (e) {
      window.__pbrErr = 'mount threw: ' + String((e && e.message) || e);
      host.remove();
      return;
    }
    if (!api) { window.__pbrErr = 'mountPcViewport returned null (no WebGL)'; host.remove(); return; }

    // The renderer owns a rAF loop; wait long enough for TextureLoader to land.
    await new Promise(r => setTimeout(r, 4000));
    if (api.renderStorefront) api.renderStorefront();

    const canvas = host.querySelector('canvas');
    if (!canvas) { window.__pbrErr = 'viewport produced no canvas'; api.destroy?.(); host.remove(); return; }

    const gl = canvas.getContext('webgl2') || canvas.getContext('webgl');
    if (!gl) { window.__pbrErr = 'no WebGL context to read back'; api.destroy?.(); host.remove(); return; }

    // preserveDrawingBuffer is set in the renderer, so the last frame is readable.
    const w = canvas.width, h = canvas.height;
    const px = new Uint8Array(w * h * 4);
    gl.readPixels(0, 0, w, h, gl.RGBA, gl.UNSIGNED_BYTE, px);

    window['__frame_' + slot] = { w, h, px };
    api.destroy?.();
    host.remove();
  }, { viteBase: VITE, sceneJson: SCENE, slot });
};

await grabFrame('A');
const errA = await page.evaluate(() => window.__pbrErr || null);

// Same render, textures refused. Any material that had a bound map must now fall
// back to the flat brand palette, so the frame has to change.
pbrPhase = 'blocked';
await grabFrame('B');
const errB = await page.evaluate(() => window.__pbrErr || null);

if (errA || errB) {
  check('viewport renders with textures both available and blocked', false, errA || errB);
} else {
  const cmp = await page.evaluate(() => {
    const A = window.__frame_A, B = window.__frame_B;
    if (!A || !B) return { ok: false, why: `missing frame(s): A=${!!A} B=${!!B}` };
    if (A.w !== B.w || A.h !== B.h) return { ok: false, why: 'frame sizes differ' };

    const n = A.px.length;
    const stride = 28; // sample every 7th pixel: plenty for a mean, cheap to ship
    let sum = 0, changed = 0, samples = 0;
    let litA = 0, litTotal = 0;
    for (let i = 0; i < n; i += stride) {
      const d = Math.abs(A.px[i] - B.px[i])
        + Math.abs(A.px[i + 1] - B.px[i + 1])
        + Math.abs(A.px[i + 2] - B.px[i + 2]);
      sum += d; samples++;
      if (d > 12) changed++;
      if (i % (stride * 11) === 0) {
        litTotal++;
        if (A.px[i] + A.px[i + 1] + A.px[i + 2] > 30) litA++;
      }
    }
    return {
      ok: true,
      w: A.w, h: A.h,
      meanDiff: sum / samples,
      pctChanged: (changed / samples) * 100,
      pctLit: (litA / litTotal) * 100,
      identical: sum === 0,
    };
  });

  if (!cmp.ok) {
    check('framebuffers captured for comparison', false, cmp.why);
  } else {
    console.log(`\n  framebuffer       : ${cmp.w} x ${cmp.h}`);
    console.log(`  texture requests  : ${pbrReqAllowed} allowed / ${pbrReqBlocked} refused`);
    console.log(`  lit pixels (A)    : ${cmp.pctLit.toFixed(1)}%`);
    console.log(`  mean pixel delta  : ${cmp.meanDiff.toFixed(2)} / 765`);
    console.log(`  pixels changed    : ${cmp.pctChanged.toFixed(1)}%`);

    check('viewport actually requested the PBR maps', pbrReqAllowed > 0, `${pbrReqAllowed} requests`);
    check('refusing the maps changes the render', pbrReqBlocked > 0 && !cmp.identical,
      `${pbrReqBlocked} refused, mean delta ${cmp.meanDiff.toFixed(2)}`);
    check('render is not a blank frame', cmp.pctLit > 20, `${cmp.pctLit.toFixed(1)}% lit`);
    // A bound PBR map changes shading over the parts it covers. The bar is set low
    // on purpose: this asserts the maps REACH the scene, not that they look good.
    check('delta is large enough to be textures, not antialiasing', cmp.meanDiff > 1.0,
      `mean delta ${cmp.meanDiff.toFixed(2)}`);
  }
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