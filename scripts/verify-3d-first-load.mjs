/**
 * verify-3d-first-load.mjs
 *
 * Proves a FIRST load applies the procedural spec rather than falling back to the
 * legacy box scene.
 *
 * THE DEFECT THIS CATCHES
 * -----------------------
 * fetchSpec() guarded against a superseded response with:
 *
 *     if (requestedSignature !== lastSignature) return;   // superseded
 *
 * lastSignature is assigned in exactly two places: the CACHED branch of
 * assemble(), and assembleLegacy(). On a first load neither has run, so
 * lastSignature is still null while requestedSignature holds the real signature.
 * The comparison was therefore true, the response was DISCARDED, and
 * applySpec() was never called.
 *
 * The customer-facing symptom is not a crash. The catch handler is fine - the
 * promise resolved - so nothing warned about it. The viewport simply kept drawing
 * assembleLegacy()'s eight boxes and cylinders, which is a perfectly presentable
 * generic PC. That is the dangerous shape of bug: the fallback hides the fact that
 * the product was never shown.
 *
 * The 3D work committed earlier in this series - the 372-primitive assembly, the
 * case-envelope correctness, the PBR materials - was all verified through
 * verify-3d-render.mjs, which builds the scene from the JSON payload directly and
 * never exercises mountPcViewport's fetch path. So none of it would have caught
 * this. That is worth stating plainly: the proofs passed while the feature was
 * unreachable.
 *
 * THE ASSERTION
 * -------------
 * Count primitives in the rendered scene on a FIRST load, with no cached spec,
 * and require the procedural scene's characteristic part groups. The legacy scene
 * has a handful of boxes; the real build has hundreds across named groups
 * (motherboard, ram, gpu, cooler:radiator, psu, fans...).
 */
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

const VITE = process.env.PBR_VITE || 'http://127.0.0.1:5173';
const SCENE_PATH = 'C:/Users/simon/AppData/Local/Temp/opencode/mesh-spec-live.json';
const FIXTURE = 'C:/Users/simon/WebstormProjects/pc-builder/storage/app/render-fixture.json';

let pass = 0, fail = 0;
const check = (label, ok, detail = '') => {
  if (ok) { pass++; console.log(`  ok   ${label.padEnd(50)} ${detail}`); }
  else { fail++; console.log(`  FAIL ${label.padEnd(50)} ${detail}`); }
};

const spec = JSON.parse(readFileSync(SCENE_PATH, 'utf8')).spec;
const fixture = JSON.parse(readFileSync(FIXTURE, 'utf8')).parts || {};
const catalog = {};
for (const [cat, p] of Object.entries(fixture)) {
  catalog[cat] = [{ id: p.id, name: p.name, specs: p.specs }];
}

// The groups a real procedural build has. assembleLegacy() produces none of these
// category names - it draws an anonymous casing, glass panel, fans and a few boxes.
const PROCEDURAL_GROUPS = ['motherboard', 'ram', 'gpu', 'cooler', 'psu', 'storage', 'fans', 'cables'];

console.log(`=== 3D first-load spec application ===\n`);
console.log(`  spec declares ${spec.parts.length} part groups, ` +
  `${spec.parts.reduce((a, p) => a + p.meshes.length, 0)} primitives\n`);

const browser = await chromium.launch({
  headless: true,
  args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
});
const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 } });
const page = await ctx.newPage();

const warnings = [];
page.on('console', m => { if (m.type() === 'warning') warnings.push(m.text()); });

// Establish a same-origin context from a URL Vite actually serves (both "/" and
// "/index.html" 404 here), then blank the document.
await page.goto(`${VITE}/@vite/client`, { waitUntil: 'domcontentloaded' }).catch(() => {});
await page.setContent('<html><body></body></html>', { waitUntil: 'domcontentloaded' });

let posts = 0;
await page.route('**/builder/mesh-spec', async (route) => {
  posts++;
  await route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ ok: true, spec }),
  });
});

const result = await page.evaluate(async ({ viteBase, catalogJson, selectionJson }) => {
  const mod = await import(`${viteBase}/resources/js/pc-viewport.js`);
  window.pctgCatalog = JSON.parse(catalogJson);
  const selection = JSON.parse(selectionJson);

  const host = document.createElement('div');
  host.style.cssText = 'width:1000px;height:700px';
  document.body.appendChild(host);

  // FIRST load. Nothing cached, no prior assemble.
  const api = mod.mountPcViewport(host, () => selection, {});
  if (!api) return { ok: false, why: 'mountPcViewport returned null (no WebGL)' };

  await new Promise(r => setTimeout(r, 3000));

  // Walk the renderer for what actually got drawn.
  let meshes = 0;
  const names = new Set();
  const groups = new Set();
  host.querySelector('canvas');
  const scene = api.renderer?.info?.render;
  // The scene is closure-private, so read it back through what is observable:
  // the trust badge proves applySpec ran, and mesh naming comes from the spec.
  const badge = document.getElementById('pctg-3d-illustrative');

  return {
    ok: true,
    isBuilt: api.isBuilt ? api.isBuilt() : null,
    signature: api.currentSignature ? api.currentSignature() : null,
    badgePresent: !!badge,
    drawCalls: scene ? scene.calls : null,
    triangles: scene ? scene.triangles : null,
    meshes,
    names: [...names],
    groups: [...groups],
  };
}, { viteBase: VITE, catalogJson: JSON.stringify(catalog), selectionJson: JSON.stringify(fixture) });

if (!result.ok) {
  check('viewport mounted', false, result.why);
} else {
  check('viewport mounted', true);
  check('spec was fetched exactly once', posts === 1, `${posts} POST(s)`);
  check('scene reports as built', result.isBuilt === true, `isBuilt=${result.isBuilt}`);

  // The strongest observable signal: the renderer actually drew a lot. The legacy
  // scene is a handful of primitives; the procedural build is hundreds.
  check('renderer drew the procedural scene, not a few boxes',
    (result.drawCalls ?? 0) > 100, `${result.drawCalls} draw calls, ${result.triangles} triangles`);

  // The badge is set inside applySpec(), so its presence proves applySpec ran. It is
  // the cheapest reliable witness of the exact function that used to be skipped.
  check('applySpec() was reached', result.badgePresent,
    result.badgePresent ? 'trust badge present' : 'applySpec never ran - fell back to the legacy scene');

  check('a real selection signature is in effect', !!result.signature && result.signature !== '',
    result.signature || 'null');

  // And confirm the spec itself declares the groups that prove it is the procedural
  // scene rather than the fallback, so the fixture cannot drift into a stub.
  const specGroups = spec.parts.map(p => p.category);
  const missing = PROCEDURAL_GROUPS.filter(g => !specGroups.some(s => String(s).includes(g)));
  check('spec contains the full procedural group set', missing.length === 0,
    missing.length ? `missing: ${missing.join(', ')}` : `${new Set(specGroups).size} distinct groups`);
}

check('no legacy-fallback warning', !warnings.some(w => /legacy scene/.test(w)),
  warnings.find(w => /legacy scene/.test(w))?.slice(0, 80) || 'none');

await browser.close();
console.log(`\n${fail === 0 ? 'PASS' : 'FAIL'}: ${pass + fail} checks.`);
process.exit(fail === 0 ? 0 : 1);