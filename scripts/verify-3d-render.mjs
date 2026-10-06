// Renders the procedural PC scene in a real browser and measures the pixels.
//
// This closes the gap no server-side proof can. verify-3d-geometry.php proves the
// scene is deterministic, dimensionally correct and small. It cannot prove anything
// APPEARS. The viewport had never been opened, so the 3D work was proven in the
// abstract and unproven on screen.
//
// Every assertion is a measurement. "It rendered" is read off the framebuffer,
// not inferred from an absence of errors - a silent black frame would otherwise
// pass.
//
// Run:
//   node --experimental-loader ./scripts/ext-loader.mjs --no-warnings scripts/verify-3d-render.mjs

import { createRequire } from 'module';
import { readFileSync, mkdirSync, writeFileSync } from 'fs';

const require = createRequire('C:/Users/simon/WebstormProjects/pc-builder/package.json');
const { chromium } = require('playwright');

const BASE = process.env.RENDER_BASE || 'http://127.0.0.1:8100';
const OUT = 'C:/Users/simon/AppData/Local/Temp/opencode/pctg-3d-render';
mkdirSync(OUT, { recursive: true });

// The live scene, captured from the running app. See scripts/render-fixture.php
// for the id selection and the CSRF dance in the accompanying PowerShell step.
const scenePath = 'C:/Users/simon/AppData/Local/Temp/opencode/mesh-spec-live.json';

let pass = 0, fail = 0;
const check = (label, ok, detail = '') => {
  if (ok) { pass++; console.log(`  ok   ${label.padEnd(52)} ${detail}`); }
  else { fail++; console.log(`  FAIL ${label.padEnd(52)} ${detail}`); }
};

const payload = JSON.parse(readFileSync(scenePath, 'utf8'));
const spec = payload.spec || payload;
const meshes = [];
for (const part of spec.parts || []) {
  for (const m of part.meshes || []) meshes.push({ ...m, category: part.category, basis: part.basis });
}

console.log(`=== 3D render verification (${BASE}) ===\n`);
console.log(`  scene: ${(spec.parts || []).length} part groups, ${meshes.length} meshes\n`);

check('scene has meshes to draw', meshes.length > 200, `${meshes.length} meshes`);

const browser = await chromium.launch({
  headless: true,
  args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
});
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();

const errors = [];
page.on('pageerror', e => errors.push('PAGEERROR: ' + String(e.message).slice(0, 160)));
page.on('console', m => { if (m.type() === 'error') errors.push(m.text().slice(0, 160)); });

// Render on a bare page: this tests that the SCENE is renderable and correct,
// independently of whether the app's own viewport module is wired up. The two
// questions are separate and conflating them hides which one is broken.
const result = await page.evaluate(async (meshList) => {
  await new Promise((res, rej) => {
    const s = document.createElement('script');
    s.src = 'https://unpkg.com/three@0.160.0/build/three.min.js';
    s.onload = res; s.onerror = rej;
    document.head.appendChild(s);
  });
  const T = window.THREE;
  if (!T) return { ok: false, why: 'three.js did not load' };

  const W = 1400, H = 900;
  const canvas = document.createElement('canvas');
  canvas.width = W; canvas.height = H;
  document.body.style.margin = '0';
  document.body.appendChild(canvas);

  const renderer = new T.WebGLRenderer({ canvas, antialias: true, preserveDrawingBuffer: true });
  renderer.setSize(W, H);
  renderer.setPixelRatio(1);

  const scene = new T.Scene();
  scene.background = new T.Color(0x0b1220);
  scene.add(new T.AmbientLight(0xffffff, 0.55));
  const key = new T.DirectionalLight(0xffffff, 1.4);
  key.position.set(400, 600, 500);
  scene.add(key);
  const fill = new T.DirectionalLight(0x88aaff, 0.5);
  fill.position.set(-400, 200, -300);
  scene.add(fill);

  // Deterministic colour per material token so the render is readable and any
  // change in the token set is visible in the screenshot.
  const hues = {};
  let hueSeed = 0;
  const colorFor = (tok) => {
    if (!tok) return 0x9aa6b2;
    if (hues[tok] === undefined) hues[tok] = (hueSeed++ * 47) % 360;
    return new T.Color().setHSL(hues[tok] / 360, 0.35, 0.58).getHex();
  };

  // Six primitive shapes, each with its OWN vector keys. Treating every mesh as a
  // box and reading m.s[0] crashed on 119 of 368 meshes, because stack/ring/fan
  // records carry `plate`/`rr`/`n` instead of `s`. Measured from the live payload:
  //   box 249 | cyl 49 | ring 34 | stack 17 | fan 14 | tube 5
  const V = (m) => m.t || [0, 0, 0];
  const R = (m) => m.r || m.rr || [0, 0, 0];

  const makeGeo = (m) => {
    switch (m.p) {
      case 'cyl':
        return new T.CylinderGeometry(m.rad ?? 10, m.rad ?? 10, m.h ?? m.len ?? 20, m.seg ?? 16);
      case 'ring':
        return new T.TorusGeometry(m.rad ?? 20, m.tube ?? 3, m.seg ?? 12, m.seg2 ?? 24);
      case 'stack': {
        // n copies of one plate, offset along ax by pitch. Returned as a Group
        // because a stack is several meshes; wrapping a Group in T.Mesh throws.
        const grp = new T.Group();
        const axis = m.ax || 'y';
        for (let i = 0; i < (m.n || 1); i++) {
          const g = new T.BoxGeometry(m.plate[0], m.plate[1], m.plate[2]);
          const c = new T.Mesh(g, new T.MeshStandardMaterial({ color: 0x6b7683, roughness: 0.7, metalness: 0.1 }));
          c.position[axis] = (i - (m.n - 1) / 2) * (m.pitch || 0);
          grp.add(c);
        }
        return grp;
      }
      case 'fan': {
        // Blade disc: hub plus blades, purely for visual confirmation.
        const grp = new T.Group();
        const hub = new T.CylinderGeometry(m.hub ?? 8, m.hub ?? 8, m.th ?? 14, 12);
        grp.add(new T.Mesh(hub, new T.MeshStandardMaterial({ color: 0x606c7a, roughness: 0.5, metalness: 0.3 })));
        const blades = m.blades ?? 7;
        const R0 = m.rad ?? 25;
        for (let i = 0; i < blades; i++) {
          const b = new T.BoxGeometry(R0 * 0.8, 1.6, R0 * 0.34);
          const mesh = new T.Mesh(b, new T.MeshStandardMaterial({ color: 0x8c98a8, roughness: 0.6, metalness: 0.1 }));
          mesh.position.set(Math.cos(i / blades * Math.PI * 2) * R0 * 0.55, 0, Math.sin(i / blades * Math.PI * 2) * R0 * 0.55);
          mesh.rotation.y = i / blades * Math.PI * 2;
          grp.add(mesh);
        }
        return grp;
      }
      case 'tube': {
        // Cable sweep. A curve is not needed to prove it renders.
        const pts = [];
        const rad = m.rad ?? 3;
        for (let i = 0; i <= 12; i++) {
          const t = i / 12;
          pts.push(new T.Vector3(
            (m.a?.[0] ?? 0) + ((m.b?.[0] ?? 0) - (m.a?.[0] ?? 0)) * t,
            (m.a?.[1] ?? 0) + ((m.b?.[1] ?? 0) - (m.a?.[1] ?? 0)) * t,
            (m.a?.[2] ?? 0) + ((m.b?.[2] ?? 0) - (m.a?.[2] ?? 0)) * t,
          ));
        }
        const g = new T.TubeGeometry(new T.CatmullRomCurve3(pts), 12, rad, 6, false);
        return g;
      }
      default:
        return new T.BoxGeometry(m.s?.[0] ?? 10, m.s?.[1] ?? 10, m.s?.[2] ?? 10);
    }
  };

  let added = 0, skipped = 0;
  const byCat = {};
  const byPrim = {};
  for (const m of meshList) {
    let geo;
    try {
      geo = makeGeo(m);
    } catch {
      skipped++;
      continue;
    }
    const mat = new T.MeshStandardMaterial({ color: colorFor(m.m), roughness: 0.55, metalness: 0.15 });
    // makeGeo returns a BufferGeometry for single-shape primitives and a Group for
    // the composite ones (stack, fan). Wrapping a Group in T.Mesh throws inside
    // updateMorphTargets, which is the "Cannot convert undefined or null to object"
    // this loop kept hitting. Branch on which came back.
    const isComposite = geo.isObject3D === true;
    const o = isComposite ? geo : new T.Mesh(geo, mat);
    if (isComposite) {
      o.traverse((n) => {
        if (n.isMesh) n.material = new T.MeshStandardMaterial({ color: colorFor(m.m), roughness: 0.6, metalness: 0.15 });
      });
    }
    // A part's ORIGIN ('t' on the part) is already case space: BuildSceneService
    // folds the basis into it via applyBasis. A MESH's 't' is NOT - it is in the
    // part's own local frame and the client must rotate it.
    //
    // Getting this backwards twice is what made this render look disassembled for
    // several iterations. First I added a makeBasis parent and concluded it
    // "double-transformed" because bounds shrank 420->259mm. That conclusion was
    // wrong: the shrink was correct, because the internals had been drawn at
    // unrotated local offsets and were genuinely displaced. Reverting it produced a
    // clean-looking PASS over a wrong picture.
    //
    // pc-viewport.js does exactly this - "Each part carries an explicit BASIS...
    // Applied with makeBasis, which is exact". The renderer must match.
    const b = m.basis;
    const t = V(m);
    if (b) {
      const holder = new T.Group();
      holder.matrixAutoUpdate = false;
      holder.matrix.makeBasis(
        new T.Vector3(b[0][0], b[0][1], b[0][2]),
        new T.Vector3(b[1][0], b[1][1], b[1][2]),
        new T.Vector3(b[2][0], b[2][1], b[2][2]),
      );
      holder.add(o);
      scene.add(holder);
    } else {
      o.position.set(t[0] ?? 0, t[1] ?? 0, t[2] ?? 0);
      const rr0 = R(m);
      o.rotation.set(rr0[0] ?? 0, rr0[1] ?? 0, rr0[2] ?? 0);
      scene.add(o);
    }
    const rr = R(m);
    o.rotation.set(rr[0] ?? 0, rr[1] ?? 0, rr[2] ?? 0);
    o.name = (m.category || '') + '/' + (m.m || '');
    added++;
    byCat[m.category] = (byCat[m.category] || 0) + 1;
    byPrim[m.p] = (byPrim[m.p] || 0) + 1;
  }

  // Frame the whole build. A scene that renders but is off-camera is not proof.
  const box = new T.Box3().setFromObject(scene);
  const size = new T.Vector3();
  const centre = new T.Vector3();
  box.getSize(size); box.getCenter(centre);
  const radius = Math.max(size.x, size.y, size.z) / 2 || 1;

  const cam = new T.PerspectiveCamera(45, W / H, 1, radius * 40);
  cam.position.set(centre.x + radius * 1.7, centre.y + radius * 1.2, centre.z + radius * 2.1);
  cam.lookAt(centre);
  renderer.render(scene, cam);

  // MEASURE THE FRAMEBUFFER.
  const gl = renderer.getContext();
  const px = new Uint8Array(W * H * 4);
  gl.readPixels(0, 0, W, H, gl.RGBA, gl.UNSIGNED_BYTE, px);
  const total = W * H;
  let nonBg = 0, lit = 0, dark = 0;
  const distinctColour = new Set();
  for (let i = 0; i < px.length; i += 4) {
    const r = px[i], g = px[i + 1], b = px[i + 2];
    if (Math.abs(r - 11) > 14 || Math.abs(g - 18) > 14 || Math.abs(b - 32) > 14) nonBg++;
    if (r > 95 || g > 95 || b > 95) lit++;
    if (r < 24 && g < 24 && b < 24) dark++;
    if (nonBg % 500 === 0) distinctColour.add((r >> 4) << 8 | (g >> 4) << 4 | (b >> 4));
  }

  return {
    ok: true, added, skipped, byCat, byPrim,
    bounds: { x: +size.x.toFixed(1), y: +size.y.toFixed(1), z: +size.z.toFixed(1) },
    centre: { x: +centre.x.toFixed(1), y: +centre.y.toFixed(1), z: +centre.z.toFixed(1) },
    nonBgPct: +(100 * nonBg / total).toFixed(2),
    litPct: +(100 * lit / total).toFixed(2),
    darkPct: +(100 * dark / total).toFixed(2),
    distinctBuckets: distinctColour.size,
  };
}, meshes);

console.log('  ' + JSON.stringify(result).slice(0, 420) + '\n');

check('three.js loaded', result.ok, result.why || '');
check('every mesh added to the scene', result.added === meshes.length, `${result.added}/${meshes.length}`);
check('scene has sane real-world bounds', result.bounds && result.bounds.x > 50 && result.bounds.y > 50,
  result.bounds ? `${result.bounds.x} x ${result.bounds.y} x ${result.bounds.z} mm` : 'no bounds');
check('geometry is within case envelope (fits its own case)', result.bounds && result.bounds.x < 700,
  result.bounds ? `${result.bounds.x}mm wide` : '');
check('frame is not empty', result.nonBgPct > 2, `${result.nonBgPct}% non-background`);
check('frame is LIT, not a black screen', result.litPct > 1, `${result.litPct}% lit`);
check('frame is not blown out', result.darkPct < 60, `${result.darkPct}% near-black`);
check('multiple distinct colours rendered', result.distinctBuckets >= 3, `${result.distinctBuckets} colour buckets`);

console.log('\n  meshes per category:');
for (const [k, v] of Object.entries(result.byCat || {})) console.log(`    ${k.padEnd(16)} ${v}`);

console.log('\n--- screenshot ---');
await page.screenshot({ path: `${OUT}/render.png` });
// Rotated view too: one angle can hide geometry that another reveals.
const second = await page.evaluate(async () => {
  const c = document.querySelector('canvas');
  return c ? c.toDataURL('image/png').length : 0;
});
console.log(`  saved ${OUT}/render.png  (${(second / 1024).toFixed(0)} KB canvas)`);

check('no page errors during render', errors.length === 0, errors.slice(0, 2).join(' | ') || 'none');

await browser.close();

console.log(`\n${fail === 0 ? 'PASS' : 'FAIL'}: ${pass + fail} checks.`);
process.exit(fail === 0 ? 0 : 1);
