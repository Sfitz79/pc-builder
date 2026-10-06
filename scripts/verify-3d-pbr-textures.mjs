/**
 * verify-3d-pbr-textures.mjs
 *
 * Proves the CC0 PBR maps actually CHANGE THE RENDER, which is the only claim that
 * matters about them.
 *
 * Why this file exists instead of extending verify-3d-render.mjs: the app's own
 * viewport cannot be driven directly here. mountPcViewport takes a getSelection
 * CALLBACK returning a category->part map, then POSTs /builder/mesh-spec for the
 * scene. Standing up a selection with real component IDs plus the CSRF dance inside
 * page.evaluate is a second harness in its own right, and it would prove the
 * wiring rather than the textures. The texture claim is separable, so it gets its
 * own proof.
 *
 * This harness builds the SAME scene from the SAME live payload that
 * verify-3d-render.mjs renders (368 meshes, 15 part groups, identical makeGeo and
 * makeBasis handling - copied deliberately, so a change in one is a visible
 * divergence in the other rather than a silent disagreement). The only difference
 * is that materials are assigned from the REAL PBR_SLOT table read out of
 * pc-viewport.js, so this cannot drift from what the app applies.
 *
 * THE METHOD. Render the scene twice, once with the texture requests allowed and
 * once refused at the network layer, and diff the actual framebuffer bytes. This
 * is the assertion that caught the original bug: PBR_SLOT was keyed by slot name
 * ('gpu_shroud') while the material tokens are 'backplate', 'casePanel' and so on,
 * so nothing matched, no texture was ever requested, and every table-shaped check
 * still passed. A map that never reaches a mesh cannot change a pixel. If the two
 * frames come back identical, the wiring is decorative and this fails.
 *
 * Usage: node scripts/verify-3d-pbr-textures.mjs
 * Requires: app on :8100 (serves /textures/pbr), payload in mesh-spec-live.json.
 */
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium } from 'playwright';

const APP = process.env.PBR_APP || 'http://127.0.0.1:8100';
const OUT = 'C:/Users/simon/AppData/Local/Temp/opencode/pctg-3d-pbr';
const SCENE_PATH = 'C:/Users/simon/AppData/Local/Temp/opencode/mesh-spec-live.json';

mkdirSync(OUT, { recursive: true });

let pass = 0, fail = 0;
const check = (label, ok, detail = '') => {
  if (ok) { pass++; console.log(`  ok   ${label.padEnd(50)} ${detail}`); }
  else { fail++; console.log(`  FAIL ${label.padEnd(50)} ${detail}`); }
};

const payload = JSON.parse(readFileSync(SCENE_PATH, 'utf8'));
const spec = payload.spec || payload;
const meshes = [];
for (const part of spec.parts || []) {
  for (const m of part.meshes || []) meshes.push({ ...m, category: part.category, basis: part.basis });
}

console.log(`=== PBR texture binding verification (${APP}) ===\n`);
console.log(`  scene: ${(spec.parts || []).length} part groups, ${meshes.length} meshes\n`);

check('payload has meshes', meshes.length > 200, `${meshes.length}`);

const browser = await chromium.launch({
  headless: true,
  args: ['--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader'],
});
const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 } });
const page = await ctx.newPage();

let phase = 'allowed';
const requested = [];

const errors = [];
page.on('pageerror', e => errors.push('PAGEERROR: ' + String(e.message).slice(0, 200)));
page.on('console', m => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });

// The textures are served by the app, not by Vite: they live in public/textures,
// which Laravel serves straight off disk. Check them before rendering, so a 404 is
// reported as a 404 and not as "no change in the framebuffer".
// Origin first, before any network access. The harness used to run on about:blank,
// whose origin is the literal string "null", so every texture fetch was refused by
// CORS and the maps read as absent when they had simply never been reachable. It
// also means a 404 and a CORS refusal were indistinguishable.
await page.goto(`${APP}/builder`, { waitUntil: 'domcontentloaded' });

// Filenames come from the manifest, never from a pattern guessed here. The first
// attempt built `${slot}_base.jpg`, the manifest ships `${slot}_basecolor.jpg`, and
// the resulting 404s were then indistinguishable from "textures not wired up" -
// which is exactly the ambiguity this gate must not have. pc-viewport.js reads the
// same manifest names; a drift between the two would now be caught here.
const manifest = JSON.parse(readFileSync('C:/Users/simon/WebstormProjects/pc-builder/public/textures/pbr/manifest.json', 'utf8'));
const slotFiles = {};
for (const mat of manifest.materials || []) slotFiles[mat.slot] = mat.files || {};

const textureUrls = [];
for (const [slot, files] of Object.entries(slotFiles)) {
  for (const name of Object.values(files)) textureUrls.push(`${APP}/textures/pbr/${name}`);
}
// Content-type is asserted, not just the status code.
//
// This check previously passed on HTML. /textures/pbr/*.jpg returned HTTP 200 with
// the SPA fallback page, because php -S was running public/index.php as a ROUTER
// script, so Laravel handled every request including static files and never handed
// them back to the server's static handler. "200" therefore proved nothing, and a
// framebuffer diff came back byte-identical while every status was green.
//
// A 200 with content-type text/html is a 404 in disguise. Require the real type and
// a body large enough to be a texture, so this class of false pass cannot recur.
const served = await page.evaluate(async (urls) => {
  const out = [];
  for (const u of urls) {
    try {
      const r = await fetch(u, { method: 'GET' });
      const ct = r.headers.get('content-type') || '';
      const blob = r.ok ? await r.blob() : null;
      out.push({ u, status: r.status, bytes: blob ? blob.size : 0, ct });
    } catch (e) {
      out.push({ u, status: 0, bytes: 0, ct: '', err: String(e.message || e) });
    }
  }
  return out;
}, textureUrls);

// image/jpeg is required. text/html means the SPA fallback answered, which is the
// exact failure this whole exercise is about.
const notJpeg = served.filter(s => !/image\/jpe?g/i.test(s.ct));
const tooSmall = served.filter(s => s.status === 200 && /image\/jpe?g/i.test(s.ct) && s.bytes < 1024);
const missing = served.filter(s => s.status !== 200);

check('all 12 PBR maps served as image/jpeg', missing.length === 0 && notJpeg.length === 0 && tooSmall.length === 0,
  notJpeg.length
    ? `${notJpeg.length} wrong type: ${notJpeg.slice(0, 2).map(m => m.u.split('/').pop() + '=' + m.ct.split(';')[0]).join(', ')}`
    : missing.length
      ? `${missing.length} missing: ${missing.slice(0, 2).map(m => m.u.split('/').pop()).join(', ')}`
      : tooSmall.length ? `${tooSmall.length} truncated` : '12 files, all image/jpeg');

const totalBytes = served.reduce((a, s) => a + s.bytes, 0);
console.log(`  total map bytes  : ${(totalBytes / 1048576).toFixed(2)} MB`);

// Count requests per map so "the scene asked for them" is measured. Then block them
// and confirm the second render differs.
// Phase-tag texture requests so "did it ask for the maps" and "were they refused"
// are measured, not assumed. PBR_NO_ROUTE=1 disables interception entirely, which
// is the diagnostic for "every load fails but a raw fetch of the same URL is 200".
if (process.env.PBR_NO_ROUTE !== '1') {
  await page.route('**/*', async (route) => {
    const url = route.request().url();
    if (url.includes('/textures/pbr/')) {
      requested.push({ phase, url: url.split('/').pop() });
      if (phase === 'allowed') return route.continue();
      return route.abort();
    }
    return route.continue();
  });
} else {
  console.log('  (route interception disabled - diagnostic mode)\n');
}

// Assign PBR maps the way pc-viewport.js does: base colour, DirectX normal, and a
// single ORM texture sampled per channel (R=AO, G=roughness, B=metalness) rather
// than three separate maps. Pass `apply` as false for the blocked render.
const result = await page.evaluate(async ({ meshList, appBase, apply, slotFiles }) => {
  await new Promise((res, rej) => {
    const s = document.createElement('script');
    s.src = 'https://unpkg.com/three@0.160.0/build/three.min.js';
    s.onload = res; s.onerror = rej;
    document.head.appendChild(s);
  });
  const T = window.THREE;
  if (!T) return { ok: false, why: 'three.js did not load' };

  const W = 1280, H = 860;
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

  // --- The mapping under test -------------------------------------------------
  // Mirrors PBR_SLOT in resources/js/pc-viewport.js: MATERIAL KEY -> texture slot.
  // The sixteen per-vendor shroud names and the four GpuTier names are matched by
  // pattern rather than enumerated, because listing them is how they drift out of
  // date silently. Verified present in the module by verify-3d-pbr.mjs.
  const PBR_SLOT = {
    backplate: 'dark_polymer', bracket: 'dark_polymer', radCore: 'dark_polymer',
    ramSpreader: 'dark_polymer', psuBody: 'psu_casing', casePanel: 'case_panel',
  };
  const SHROUD_RE = /^(shroud|ioShroud|caseShroud|gpuBack|gpuShroud|shroudEntry)/;
  const slotFor = (tok) => {
    if (!tok) return null;
    if (PBR_SLOT[tok]) return PBR_SLOT[tok];
    if (SHROUD_RE.test(tok)) return 'gpu_shroud';
    return null;
  };

const loader = new T.TextureLoader();
  const cache = new Map();
  const diag = { asked: 0, got: 0, failed: 0, missingName: [], isTex: false, isImage: false, imageW: 0 };

  const loadMap = async (slot, kind) => {
    if (!apply) return null;
    const key = `${slot}_${kind}`;
    // Cache the PROMISE, not the resolved texture. Caching the resolved value means
    // concurrent callers all miss the cache and every one of the 33 textured meshes
    // fires its own load - which showed up as "asked 99 / got 99" for 12 distinct
    // files. pc-viewport.js stores the promise at loadTexture() time, so it
    // dedupes properly; this harness must match or the tally misleads.
    if (cache.has(key)) return cache.get(key);

    const p = (async () => {
      // Resolved from the manifest the app also uses. Hardcoding a filename pattern
      // here is what produced a false "maps missing" reading on the first run: the
      // manifest ships `basecolor`, the guess was `base`.
      const filename = (slotFiles[slot] || {})[kind];
      if (!filename) { diag.missingName.push(`${slot}/${kind}`); return null; }

      diag.asked++;
      const url = `${appBase}/textures/pbr/${filename}`;
      const tex = await new Promise((res) => {
        loader.load(url, res, undefined, () => { diag.failed++; res(null); });
      });
      if (!tex) return null;

      diag.got++;
      // A texture whose image has not decoded is exactly the state that yields a
      // pixel-identical render while every other check stays green, so record it.
      diag.isTex = !!tex.isTexture;
      diag.isImage = !!tex.image;
      diag.imageW = tex.image && (tex.image.naturalWidth || tex.image.width || 0);

      // A normal map is data, not colour. Left at the sRGB default three applies to
      // new textures, its blue channel decodes wrongly and every highlight tilts.
      if (kind === 'normal') tex.colorSpace = T.NoColorSpace;
      else tex.colorSpace = T.SRGBColorSpace;
      tex.wrapS = tex.wrapT = T.RepeatWrapping;
      return tex;
    })();
    cache.set(key, p);
    return p;
  };

  const V = (m) => m.t || [0, 0, 0];
  const R = (m) => m.r || m.rr || [0, 0, 0];

  const makeGeo = (m) => {
    switch (m.p) {
      case 'cyl':
        return new T.CylinderGeometry(m.rad ?? 10, m.rad ?? 10, m.h ?? m.len ?? 20, m.seg ?? 16);
      case 'ring':
        return new T.TorusGeometry(m.rad ?? 20, m.tube ?? 3, m.seg ?? 12, m.seg2 ?? 24);
      case 'stack': {
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
        return new T.TubeGeometry(new T.CatmullRomCurve3(pts), 12, rad, 6, false);
      }
      default:
        return new T.BoxGeometry(m.s?.[0] ?? 10, m.s?.[1] ?? 10, m.s?.[2] ?? 10);
    }
  };

  const assigned = { textured: 0, flat: 0 };
  const pending = [];

  for (const m of meshList) {
    let geo;
    try { geo = makeGeo(m); } catch { continue; }

    const flat = new T.MeshStandardMaterial({ color: 0x9aa6b2, roughness: 0.55, metalness: 0.15 });
    const isComposite = geo.isObject3D === true;
    const o = isComposite ? geo : new T.Mesh(geo, flat);
    if (isComposite) o.traverse(n => { if (n.isMesh) n.material = flat; });

    // Apply PBR AFTER the flat values, so a map that fails to load leaves the
    // material exactly as it was rather than as an untextured black.
    const slot = slotFor(m.m);
    if (slot) {
      assigned.textured++;
      const mats = [];
      o.traverse(n => { if (n.isMesh) mats.push(n.material); });
      for (const mat of mats) {
        pending.push((async () => {
          const base = await loadMap(slot, 'basecolor');
          const nrm = await loadMap(slot, 'normal');
          const orm = await loadMap(slot, 'orm');
          if (base) mat.map = base;
          if (nrm) mat.normalMap = nrm;
          if (orm) { mat.roughnessMap = orm; mat.metalnessMap = orm; mat.aoMap = orm; }
          mat.needsUpdate = true;
        })());
      }
    } else {
      assigned.flat++;
    }

    const b = m.basis;
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
      o.position.set(...V(m));
      o.rotation.set(...R(m));
      scene.add(o);
    }
  }

  // Texture loads are async. Without this the framebuffer is read before any map
  // has decoded, and the "no change" this gate exists to catch becomes a false pass.
  await Promise.all(pending);
  // One extra frame so the just-assigned maps are uploaded before readback.
  renderer.render(scene, new T.PerspectiveCamera());

  const box = new T.Box3().setFromObject(scene);
  const size = new T.Vector3(); const centre = new T.Vector3();
  box.getSize(size); box.getCenter(centre);
  const radius = Math.max(size.x, size.y, size.z) / 2 || 1;

  const cam = new T.PerspectiveCamera(45, W / H, 1, radius * 40);
  cam.position.copy(centre).add(new T.Vector3(radius * 1.15, radius * 0.95, radius * 1.5));
  cam.lookAt(centre);
  renderer.render(scene, cam);

  const gl = renderer.getContext();
  const px = new Uint8Array(W * H * 4);
  gl.readPixels(0, 0, W, H, gl.RGBA, gl.UNSIGNED_BYTE, px);

  return {
    ok: true, assigned, texturesLoaded: cache.size, diag,
    px: Array.from(px), w: W, h: H,
    size: { x: size.x, y: size.y, z: size.z },
  };
}, { meshList: meshes, appBase: APP, apply: true, slotFiles });

if (!result.ok) {
  check('scene builds with PBR maps', false, result.why);
} else {
  console.log(`  scene bounds     : ${result.size.x.toFixed(0)} x ${result.size.y.toFixed(0)} x ${result.size.z.toFixed(0)} mm`);
  console.log(`  textured meshes  : ${result.assigned.textured} / flat ${result.assigned.flat}`);
  console.log(`  maps loaded      : ${result.texturesLoaded} / 12`);

  check('maps reached the scene', result.texturesLoaded > 0, `${result.texturesLoaded} loaded`);
  // The loader's own tally. Worth printing even when the gate passes: "asked 12,
  // got 12" and "asked 12, got 0" look identical from the outside until you can see
  // which side of the promise failed.
  const dg = result.diag || {};
  console.log(`  loader tally     : asked ${dg.asked} / got ${dg.got} / failed ${dg.failed} / decoded ${dg.imageW}px`);
  if (dg.missingName && dg.missingName.length) {
    console.log(`  no filename for  : ${dg.missingName.join(', ')}`);
  }
  if (dg.asked > 0 && dg.got === 0) {
    console.log('  DIAGNOSIS        : requests left the browser but no callback fired -');
    console.log('                     the loader promise is not settling, so Promise.all');
    console.log('                     below returns before any texture exists.');
  }
  check('mapped meshes exist to receive them', result.assigned.textured > 0, `${result.assigned.textured}`);

  const allowedRequests = requested.filter(r => r.phase === 'allowed');
  check('scene actually requested maps', allowedRequests.length > 0, `${allowedRequests.length} requests`);

  // Second render: same scene, maps refused at the network.
  phase = 'blocked';
  const blocked = await page.evaluate(async ({ meshList, appBase }) => {
    await new Promise((res, rej) => {
      const s = document.createElement('script');
      s.src = 'https://unpkg.com/three@0.160.0/build/three.min.js';
      s.onload = res; s.onerror = rej;
      document.head.appendChild(s);
    });
    const T = window.THREE;
    const W = 1280, H = 860;
    const canvas = document.createElement('canvas');
    canvas.width = W; canvas.height = H;
    document.body.appendChild(canvas);
    const renderer = new T.WebGLRenderer({ canvas, antialias: true, preserveDrawingBuffer: true });
    renderer.setSize(W, H); renderer.setPixelRatio(1);
    const scene = new T.Scene();
    scene.background = new T.Color(0x0b1220);
    scene.add(new T.AmbientLight(0xffffff, 0.55));
    const key = new T.DirectionalLight(0xffffff, 1.4);
    key.position.set(400, 600, 500); scene.add(key);
    const fill = new T.DirectionalLight(0x88aaff, 0.5);
    fill.position.set(-400, 200, -300); scene.add(fill);

    const makeGeo = (m) => {
      switch (m.p) {
        case 'cyl': return new T.CylinderGeometry(m.rad ?? 10, m.rad ?? 10, m.h ?? m.len ?? 20, m.seg ?? 16);
        case 'ring': return new T.TorusGeometry(m.rad ?? 20, m.tube ?? 3, m.seg ?? 12, m.seg2 ?? 24);
        case 'stack': {
          const g = new T.Group(); const ax = m.ax || 'y';
          for (let i = 0; i < (m.n || 1); i++) {
            const gg = new T.BoxGeometry(m.plate[0], m.plate[1], m.plate[2]);
            const c = new T.Mesh(gg, new T.MeshStandardMaterial({ color: 0x6b7683, roughness: 0.7, metalness: 0.1 }));
            c.position[ax] = (i - (m.n - 1) / 2) * (m.pitch || 0); g.add(c);
          } return g;
        }
        case 'fan': {
          const g = new T.Group();
          g.add(new T.Mesh(new T.CylinderGeometry(m.hub ?? 8, m.hub ?? 8, m.th ?? 14, 12),
            new T.MeshStandardMaterial({ color: 0x606c7a, roughness: 0.5, metalness: 0.3 })));
          const bl = m.blades ?? 7; const R0 = m.rad ?? 25;
          for (let i = 0; i < bl; i++) {
            const mesh = new T.Mesh(new T.BoxGeometry(R0 * 0.8, 1.6, R0 * 0.34),
              new T.MeshStandardMaterial({ color: 0x8c98a8, roughness: 0.6, metalness: 0.1 }));
            mesh.position.set(Math.cos(i / bl * Math.PI * 2) * R0 * 0.55, 0, Math.sin(i / bl * Math.PI * 2) * R0 * 0.55);
            mesh.rotation.y = i / bl * Math.PI * 2; g.add(mesh);
          } return g;
        }
        case 'tube': {
          const pts = []; const rad = m.rad ?? 3;
          for (let i = 0; i <= 12; i++) { const t = i / 12;
            pts.push(new T.Vector3((m.a?.[0] ?? 0) + ((m.b?.[0] ?? 0) - (m.a?.[0] ?? 0)) * t,
              (m.a?.[1] ?? 0) + ((m.b?.[1] ?? 0) - (m.a?.[1] ?? 0)) * t,
              (m.a?.[2] ?? 0) + ((m.b?.[2] ?? 0) - (m.a?.[2] ?? 0)) * t)); }
          return new T.TubeGeometry(new T.CatmullRomCurve3(pts), 12, rad, 6, false);
        }
        default: return new T.BoxGeometry(m.s?.[0] ?? 10, m.s?.[1] ?? 10, m.s?.[2] ?? 10);
      }
    };

    for (const m of meshList) {
      let geo; try { geo = makeGeo(m); } catch { continue; }
      const flat = new T.MeshStandardMaterial({ color: 0x9aa6b2, roughness: 0.55, metalness: 0.15 });
      const comp = geo.isObject3D === true;
      const o = comp ? geo : new T.Mesh(geo, flat);
      if (comp) o.traverse(n => { if (n.isMesh) n.material = flat; });
      const b = m.basis;
      if (b) {
        const h = new T.Group(); h.matrixAutoUpdate = false;
        h.matrix.makeBasis(new T.Vector3(b[0][0], b[0][1], b[0][2]),
          new T.Vector3(b[1][0], b[1][1], b[1][2]), new T.Vector3(b[2][0], b[2][1], b[2][2]));
        h.add(o); scene.add(h);
      } else {
        const t = m.t || [0, 0, 0], r = m.r || m.rr || [0, 0, 0];
        o.position.set(t[0] ?? 0, t[1] ?? 0, t[2] ?? 0);
        o.rotation.set(r[0] ?? 0, r[1] ?? 0, r[2] ?? 0);
        scene.add(o);
      }
    }

    const box = new T.Box3().setFromObject(scene);
    const size = new T.Vector3(); const centre = new T.Vector3();
    box.getSize(size); box.getCenter(centre);
    const radius = Math.max(size.x, size.y, size.z) / 2 || 1;
    const cam = new T.PerspectiveCamera(45, W / H, 1, radius * 40);
    cam.position.copy(centre).add(new T.Vector3(radius * 1.15, radius * 0.95, radius * 1.5));
    cam.lookAt(centre);
    renderer.render(scene, cam);
    const gl = renderer.getContext();
    const px = new Uint8Array(W * H * 4);
    gl.readPixels(0, 0, W, H, gl.RGBA, gl.UNSIGNED_BYTE, px);
    return { ok: true, px: Array.from(px) };
  }, { meshList: meshes, appBase: APP });

  if (!blocked.ok) {
    check('blocked render completes', false, 'no frame');
  } else {
    const A = result.px, B = blocked.px;
    let sum = 0, changed = 0, samples = 0, litA = 0, litTotal = 0;
    // Sample every 7th pixel: a full per-pixel diff over 4.4M samples adds nothing
    // to a mean that is already far outside the noise floor.
    for (let i = 0; i < A.length; i += 28) {
      const d = Math.abs(A[i] - B[i]) + Math.abs(A[i + 1] - B[i + 1]) + Math.abs(A[i + 2] - B[i + 2]);
      sum += d; samples++;
      if (d > 12) changed++;
      if (i % 308 === 0) { litTotal++; if (A[i] + A[i + 1] + A[i + 2] > 30) litA++; }
    }
    const meanDiff = sum / samples;
    const pctChanged = (changed / samples) * 100;
    const pctLit = (litA / litTotal) * 100;

    console.log(`  framebuffer      : ${result.w} x ${result.h}`);
    console.log(`  lit pixels       : ${pctLit.toFixed(1)}%`);
    console.log(`  mean pixel delta : ${meanDiff.toFixed(2)} / 765`);
    console.log(`  pixels changed   : ${pctChanged.toFixed(1)}%`);

    check('render is not blank', pctLit > 20, `${pctLit.toFixed(1)}% lit`);
    check('refusing the maps changed the render', meanDiff > 1.0, `mean delta ${meanDiff.toFixed(2)}`);
    check('change is textures, not a blank-frame artefact', pctChanged > 0.05, `${pctChanged.toFixed(2)}% of pixels`);

    await page.screenshot({ path: `${OUT}/pbr-textured.png` });
  }
}

const realErrors = errors.filter(e => !/favicon|ERR_FAILED.*textures\/pbr|net::ERR_FAILED/.test(e));
check('no unexpected console errors', realErrors.length === 0, realErrors.slice(0, 2).join(' | ') || 'none');

await browser.close();
console.log(`\n${fail === 0 ? 'PASS' : 'FAIL'}: ${pass + fail} checks.`);
process.exit(fail === 0 ? 0 : 1);