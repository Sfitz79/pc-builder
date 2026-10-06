import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';

/**
 * PCTG 3D Build Viewport
 *
 * Geometry is GENERATED SERVER-SIDE by app/Services/ThreeD/ and arrives as a
 * declarative mesh spec in real millimetres (POST /builder/mesh-spec). The
 * browser only instantiates Three.js objects from it. That split is deliberate:
 * the generators are real PHP, driven by the real catalogue dimensions in
 * App\Services\PartDimensions, so they can be tested and hashed by
 * scripts/verify-3d-geometry.php. Generating the same shapes in both languages
 * would mean two implementations quietly drifting apart.
 *
 * SPEC PRIMITIVES (all millimetres):
 *   box    size [w,h,d]      position   rotation
 *   cyl    radius, height    axis x|y|z
 *   ring   outer, inner      axis       (fan apertures, guards, shrouds)
 *   stack  count, pitch, plate, axis  (fin stacks, grilles, DIMM ridges)
 *   fan    radius, depth, blades     (registers a rotor for animation)
 *   tube   radius, points            (sleeved cable runs)
 *
 * A `stack` with `c:true` is centred on its position; with `c:false` the
 * position is the first plate and the run marches in +axis.
 *
 * Each part carries an explicit BASIS: three column vectors giving the
 * case-space direction of its local X, Y and Z. Applied with makeBasis, which
 * is exact, rather than a chain of Euler rotations.
 *
 * FAIL SAFE, and the fallback announces itself. If the spec request fails or
 * returns nothing usable, the legacy primitive scene is drawn and the reason is
 * logged, both here and to the caller. The 3D panel degrading to a simpler
 * model is acceptable; the builder going dark is not.
 *
 * NO BRANDING: the generators emit only material keys and geometry. No logos,
 * no wordmarks, no watermarks are ever burned into rendered content - the
 * platform's content-sharing guidelines treat that as a violation that gets
 * content deleted and accounts disabled.
 */

const MM = 1; // 1 unit = 1 mm (camera positioned in mm space)

function fmt(n) {
    return Math.round(n * 10) / 10;
}

function merge(base, part, key) {
    const v = part?.dims?.[key] ?? part?.[key];
    return v === undefined || v === null ? base : v;
}

const textureCache = new Map();

function loadTexture(url) {
    if (!url) return Promise.resolve(null);
    if (!/^https?:|^\//.test(url)) return Promise.resolve(null);
    // Skip placeholder SVGs — they're category graphics, not real product shots.
    if (/placeholders?\//.test(url) || /\.svg($|\?)/.test(url)) return Promise.resolve(null);

    const key = url;
    if (textureCache.has(key)) return textureCache.get(key);

    const loader = new THREE.TextureLoader();
    loader.setCrossOrigin('anonymous');
    const promise = new Promise((resolve) => {
        loader.load(
            url,
            (tex) => {
                tex.colorSpace = THREE.SRGBColorSpace;
                tex.anisotropy = 4;
                textureCache.set(key, Promise.resolve(tex));
                resolve(tex);
            },
            undefined,
            () => resolve(null) // CORS/404 → fall back to flat material
        );
    });
    textureCache.set(key, promise);
    return promise;
}

/**
 * The material palette. Kept in the browser rather than in the spec so the
 * payload stays small (a full scene is ~34 KB) and so no colour logic lives in
 * two places. Keys are declared by the generators.
 *
 * Roughness/metalness are chosen per material class, not uniformly: a GPU
 * shroud is semi-gloss plastic, a fin stack is bare aluminium, a gold edge
 * connector is metal. Uniform values are what makes procedural models read as
 * plastic toys.
 */
const MATERIALS = {
    // Structure
    casePanel:    { color: 0x1b1f27, roughness: 0.55, metalness: 0.65 },
    caseFront:    { color: 0x151920, roughness: 0.6,  metalness: 0.5 },
    caseShroud:   { color: 0x171b22, roughness: 0.6,  metalness: 0.5 },
    caseTray:     { color: 0x14181f, roughness: 0.65, metalness: 0.55 },
    caseTrim:     { color: 0x2a2f39, roughness: 0.4,  metalness: 0.8 },
    caseMesh:     { color: 0x0d1015, roughness: 0.75, metalness: 0.4 },
    caseFoot:     { color: 0x0b0d11, roughness: 0.9,  metalness: 0.1 },
    caseIo:       { color: 0x22262e, roughness: 0.5,  metalness: 0.6 },
    casePowerButton: { color: 0x30363f, roughness: 0.45, metalness: 0.5 },
    caseUsb:      { color: 0x0a0c10, roughness: 0.7,  metalness: 0.3 },
    caseJack:     { color: 0x141821, roughness: 0.6,  metalness: 0.4 },

    // Boards and silicon
    pcb:          { color: 0x0d2b1e, roughness: 0.72, metalness: 0.08 },
    pcbMask:      { color: 0x123a28, roughness: 0.55, metalness: 0.1 },
    pcbEdge:      { color: 0x0a1f16, roughness: 0.8,  metalness: 0.05 },
    heatsink:     { color: 0x9aa3ad, roughness: 0.42, metalness: 0.85 },
    fin:          { color: 0x8e97a2, roughness: 0.35, metalness: 0.9 },
    socket:       { color: 0x1c2028, roughness: 0.6,  metalness: 0.2 },
    socketFrame:  { color: 0xb6bec7, roughness: 0.3,  metalness: 0.9 },
    port:         { color: 0x0a0c10, roughness: 0.75, metalness: 0.2 },
    atx24:        { color: 0x14171d, roughness: 0.7,  metalness: 0.2 },
    atx8:         { color: 0x14171d, roughness: 0.7,  metalness: 0.2 },
    pcieSlot:     { color: 0x0e1116, roughness: 0.65, metalness: 0.15 },
    pcieLatch:    { color: 0x23282f, roughness: 0.5,  metalness: 0.3 },
    dimmSlot:     { color: 0x101318, roughness: 0.6,  metalness: 0.2 },
    dimmLatch:    { color: 0x272d36, roughness: 0.45, metalness: 0.35 },
    m2Heatsink:   { color: 0x7f8892, roughness: 0.4,  metalness: 0.85 },
    ioShroud:     { color: 0x1d222b, roughness: 0.5,  metalness: 0.6 },
    antenna:      { color: 0xc8ccd2, roughness: 0.35, metalness: 0.8 },
    header:       { color: 0x2b3138, roughness: 0.6,  metalness: 0.3 },
    gold:         { color: 0xc9a227, roughness: 0.28, metalness: 0.95 },

    // CPU
    cpuSubstrate: { color: 0x123524, roughness: 0.7,  metalness: 0.08 },
    ihs:          { color: 0xb9bfc6, roughness: 0.34, metalness: 0.88 },
    ihsStep:      { color: 0x9aa1a9, roughness: 0.4,  metalness: 0.85 },

    // GPU shrouds — colour identity only, never a logo
    shroudAsus:   { color: 0x2a2d33, roughness: 0.42, metalness: 0.45 },
    shroudGigabyte: { color: 0x1f2937, roughness: 0.44, metalness: 0.42 },
    shroudMsi:    { color: 0x22262e, roughness: 0.4,  metalness: 0.5 },
    shroudEvga:   { color: 0x2c2f36, roughness: 0.42, metalness: 0.45 },
    shroudZotac:  { color: 0x272b32, roughness: 0.45, metalness: 0.4 },
    shroudPny:    { color: 0x33373f, roughness: 0.38, metalness: 0.5 },
    shroudSapphire: { color: 0x1c2a3a, roughness: 0.4, metalness: 0.5 },
    shroudPowercolor: { color: 0x2b1f22, roughness: 0.42, metalness: 0.45 },
    shroudAsrock: { color: 0x242830, roughness: 0.43, metalness: 0.45 },
    shroudCorsair: { color: 0x2d3139, roughness: 0.4, metalness: 0.48 },
    shroudAmd:    { color: 0x2e2e34, roughness: 0.4, metalness: 0.5 },
    shroudArc:    { color: 0x25303a, roughness: 0.4, metalness: 0.5 },
    shroudEntry:  { color: 0x2b2f36, roughness: 0.48, metalness: 0.35 },
    shroudMid:    { color: 0x262a31, roughness: 0.44, metalness: 0.4 },
    shroudUpper:  { color: 0x22262d, roughness: 0.4, metalness: 0.48 },
    shroudFlagship: { color: 0x1e222a, roughness: 0.38, metalness: 0.55 },
    shroudLip:    { color: 0x12151a, roughness: 0.5, metalness: 0.4 },
    backplate:    { color: 0x14171c, roughness: 0.34, metalness: 0.75 },
    backplateGroove: { color: 0x0a0c10, roughness: 0.6, metalness: 0.4 },
    bracket:      { color: 0x9ba3ad, roughness: 0.35, metalness: 0.9 },
    screw:        { color: 0x6f767f, roughness: 0.4, metalness: 0.9 },
    connector:    { color: 0x15181d, roughness: 0.6, metalness: 0.25 },
    connectorLip: { color: 0x0d0f13, roughness: 0.65, metalness: 0.2 },
    connectorPin: { color: 0xb9922a, roughness: 0.3, metalness: 0.9 },
    cavity:       { color: 0x05070a, roughness: 0.95, metalness: 0.0 },

    // Cooling
    coolerBase:   { color: 0x1c2027, roughness: 0.55, metalness: 0.4 },
    coolerTop:    { color: 0x24282f, roughness: 0.45, metalness: 0.5 },
    coolerTrim:   { color: 0x1a1e24, roughness: 0.55, metalness: 0.4 },
    coolerMount:  { color: 0x9aa2ab, roughness: 0.4, metalness: 0.8 },
    nickel:       { color: 0xc4cad1, roughness: 0.22, metalness: 0.95 },
    heatpipe:     { color: 0xd0a34e, roughness: 0.2,  metalness: 0.95 },
    radCore:      { color: 0x0e1116, roughness: 0.6, metalness: 0.35 },
    radTank:      { color: 0x191d24, roughness: 0.5, metalness: 0.5 },
    radFrame:     { color: 0x262b33, roughness: 0.45, metalness: 0.6 },
    pumpBlock:    { color: 0x1a1e25, roughness: 0.4, metalness: 0.7 },
    pumpMotor:    { color: 0x2b3038, roughness: 0.35, metalness: 0.75 },
    pumpCap:      { color: 0x11151b, roughness: 0.12, metalness: 0.1, transparent: true, opacity: 0.7 },

    // Fans
    fanFrame:     { color: 0x14181f, roughness: 0.6, metalness: 0.25 },
    fanBlade:     { color: 0xa9b2bd, roughness: 0.35, metalness: 0.15 },
    fanHub:       { color: 0x0a0d11, roughness: 0.6, metalness: 0.2 },
    fanScrew:     { color: 0x6b727b, roughness: 0.45, metalness: 0.85 },
    fanGuard:     { color: 0x2a2f37, roughness: 0.45, metalness: 0.7 },

    // RAM / storage
    ramPcb:       { color: 0x10261c, roughness: 0.7, metalness: 0.08 },
    ramSpreader:  { color: 0x1f242c, roughness: 0.45, metalness: 0.55 },
    ramChip:      { color: 0x171b21, roughness: 0.6, metalness: 0.2 },
    ssdPcb:       { color: 0x0e2a1e, roughness: 0.7, metalness: 0.08 },
    ssdHeatsink:  { color: 0x8d959e, roughness: 0.4, metalness: 0.85 },
    ssdChip:      { color: 0x1a1e24, roughness: 0.6, metalness: 0.2 },

    // PSU
    psuBody:      { color: 0x0f1216, roughness: 0.55, metalness: 0.55 },
    psuChamfer:   { color: 0x161a20, roughness: 0.5, metalness: 0.6 },
    psuTop:       { color: 0x1b2027, roughness: 0.45, metalness: 0.6 },
    psuGrillFrame: { color: 0x0b0d11, roughness: 0.7, metalness: 0.3 },
    psuGrill:     { color: 0x3a4149, roughness: 0.4, metalness: 0.8 },
    psuIec:       { color: 0x14171c, roughness: 0.6, metalness: 0.3 },
    psuIecCavity: { color: 0x05070a, roughness: 0.9, metalness: 0.05 },
    psuRocker:    { color: 0xc0392b, roughness: 0.5, metalness: 0.2 },
    psuPanel:     { color: 0x14171c, roughness: 0.6, metalness: 0.35 },
    psuPort:      { color: 0x080a0e, roughness: 0.75, metalness: 0.15 },
    psuScrew:     { color: 0x6b727b, roughness: 0.45, metalness: 0.85 },
    psuCableExit: { color: 0x0c0e12, roughness: 0.7, metalness: 0.2 },
    driveBay:     { color: 0x1a1e25, roughness: 0.6, metalness: 0.4 },

    // Cables and light
    cable:        { color: 0x0a0d12, roughness: 0.8, metalness: 0.08 },
    cableSata:    { color: 0x161c26, roughness: 0.8, metalness: 0.08 },
    aioTube:      { color: 0x14181f, roughness: 0.6, metalness: 0.1 },
    glass:        { color: 0x39506e, roughness: 0.06, metalness: 0.4, transparent: true, opacity: 0.16 },
    gpuLed:       { color: 0x0a0a0f, emissive: 0xff2244, emissiveIntensity: 2.4, roughness: 0.3 },
    ramLight:     { color: 0x0a0a0f, emissive: 0x44aaff, emissiveIntensity: 2.0, roughness: 0.3 },
    ramDiffuser:  { color: 0x101018, emissive: 0x88ccff, emissiveIntensity: 1.4, roughness: 0.2, transparent: true, opacity: 0.8 },
    caseLed:      { color: 0x0a0a0f, emissive: 0x5566ff, emissiveIntensity: 1.8, roughness: 0.3 },
    fanLed:       { color: 0x0a0a0f, emissive: 0xff3355, emissiveIntensity: 1.8, roughness: 0.3 },
};

export function mountPcViewport(container, getSelection, callbacks = {}) {
    if (!container) return null;

    const { onRender, onDimsChange, onFailure } = callbacks;

    // WebGL is not universal. A customer on a locked-down machine, a VM, or an
    // old integrated GPU can fail here, and the old code threw straight out of
    // mountPcViewport: the button said "Show", the panel stayed blank, and the
    // only symptom was a black rectangle. Fail to the caller's handler so the
    // panel can explain itself instead of pretending to render.
    let renderer;
    try {
        renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, preserveDrawingBuffer: true });
    } catch (e) {
        onFailure?.('webgl-unavailable', 'This browser could not start WebGL, so the 3D view is unavailable. Every other part of the builder still works normally.');
        return null;
    }

    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.setSize(container.clientWidth, container.clientHeight);
    renderer.shadowMap.enabled = true;
    container.appendChild(renderer.domElement);

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(45, container.clientWidth / container.clientHeight, 1, 5000);
    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.08;
    controls.minDistance = 400;
    controls.maxDistance = 1800;

    scene.add(new THREE.AmbientLight(0xffffff, 0.75));
    const key = new THREE.DirectionalLight(0xffffff, 1.5);
    key.position.set(600, 900, 700);
    scene.add(key);
    const rim = new THREE.DirectionalLight(0x5566ff, 0.4);
    rim.position.set(-700, 200, -500);
    scene.add(rim);

    const floor = new THREE.Mesh(
        new THREE.PlaneGeometry(2500, 2500),
        new THREE.MeshStandardMaterial({ color: 0x0d1117, roughness: 0.9 })
    );
    floor.rotation.x = -Math.PI / 2;
    floor.position.y = -2;
    floor.receiveShadow = true;
    scene.add(floor);

    const COLORS = {
        case: 0x161a22, glass: 0x39506e, gpu: 0x1b1f2a, gpuAccent: 0xff2233,
        psu: 0x101318, mb: 0x142032, cpu: 0x8a9099, ram: 0x2a3f55,
        rad: 0x0c0f14, fan: 0x12161d, storage: 0x232830, aioTube: 0x1e2733,
        cable: 0x0a0d12, conRad: 0x1f2937,
    };

    const material = (color, opts = {}) => new THREE.MeshStandardMaterial({ color, roughness: 0.75, metalness: 0.15, ...opts });

    function box(w, h, d, color, opts) {
        const m = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), material(color, opts));
        m.castShadow = true;
        m.receiveShadow = true;
        return m;
    }

    // --- Spec materials ----------------------------------------------------
    // One shared MeshStandardMaterial per key, so 360 primitives still cost
    // ~40 draw calls' worth of material churn instead of 360.
      const specMaterials = new Map();
      function specMaterial(key) {
          if (specMaterials.has(key)) return specMaterials.get(key);
          const def = MATERIALS[key] || MATERIALS.casePanel;
          const m = new THREE.MeshStandardMaterial({
              color: def.color,
              roughness: def.roughness ?? 0.6,
              metalness: def.metalness ?? 0.2,
          });
          if (def.emissive) {
              m.emissive = new THREE.Color(def.emissive);
              m.emissiveIntensity = def.emissiveIntensity ?? 1.5;
          }
          if (def.transparent) {
              m.transparent = true;
              m.opacity = def.opacity ?? 0.6;
          }
          if (key === 'gpuLed' || key === 'ramLight' || key === 'ramDiffuser' || key === 'caseLed' || key === 'fanLed') {
              m.userData.rgbOffset = RGB_OFFSETS[key] ?? 0;
              rgbMats.push(m);
          }
          // PBR upgrade, applied AFTER the brand palette so the flat values remain
          // the fallback. If a map 404s or is blocked, the material keeps the brand
          // colour and roughness and nothing else changes.
          applyPbr(m, key);
          specMaterials.set(key, m);
          return m;
      }

      // -----------------------------------------------------------------------
      // CC0 PBR MAPS
      // -----------------------------------------------------------------------
      // scripts/fetch-pbr-textures.php installs three maps per material class from
      // Poly Haven under CC0 1.0 - commercial use and redistribution both permitted,
      // with no attribution obligation. 7.78MB across four classes, because only
      // base colour, one normal and ORM are downloaded: nor_dx/nor_gl are one map in
      // two conventions, bump repeats normal, and separate AO/Roughness/Metallic are
      // already packed into ORM.
      //
      // ORM is sampled rather than bound as three textures: R = ambient occlusion,
      // G = roughness, B = metalness.
      //
      // THE KEYS HERE ARE MATERIAL KEYS, NOT SLOT NAMES. The first version of this
      // block was keyed by slot ('gpu_shroud') and therefore matched nothing at all,
      // because specMaterial() is handed 'backplate' or 'casePanel'. It installed
      // 7.78MB of textures and applied none of them - the exact silent-no-op failure
      // this project keeps hitting. The mapping below is the client half of
      // App\Services\ThreeD\MaterialLibrary.php, which is the gate.
      //
      // Shroud names are per-vendor (shroudAsus ... shroudArc), so they are matched
      // by pattern rather than listed 16 times.
      const PBR_SLOT = {
          // GPU: shroud, backplate, bracket and bare-metal heatsinks
          backplate: ['gpu_shroud', 1],
          backplateGroove: ['gpu_shroud', 1],
          bracket: ['gpu_shroud', 1],
          ioShroud: ['gpu_shroud', 1],
          heatsink: ['gpu_shroud', 1],
          fin: ['gpu_shroud', 1],
          coolerBase: ['gpu_shroud', 1],
          coolerTop: ['gpu_shroud', 1],
          coolerTrim: ['gpu_shroud', 1],
          coolerMount: ['gpu_shroud', 1],
          radCore: ['gpu_shroud', 1],
          radFrame: ['gpu_shroud', 1],
          m2Heatsink: ['gpu_shroud', 1],
          // PSU
          psuBody: ['psu_casing', 1],
          psuTop: ['psu_casing', 1],
          psuPanel: ['psu_casing', 1],
          psuGrillFrame: ['psu_casing', 1],
          psuChamfer: ['psu_casing', 1],
          pumpCap: ['psu_casing', 1],
          // Case: the large tiled surfaces
          casePanel: ['case_panel', 2],
          caseFront: ['case_panel', 2],
          caseShroud: ['case_panel', 2],
          caseTray: ['case_panel', 2],
          // Polymer: cables, fan hubs, RAM spreaders, drive bays
          caseTrim: ['dark_polymer', 1],
          caseFoot: ['dark_polymer', 1],
          caseCutout: ['dark_polymer', 1],
          caseMesh: ['dark_polymer', 1],
          driveBay: ['dark_polymer', 1],
          antenna: ['dark_polymer', 1],
          pumpMotor: ['dark_polymer', 1],
          radTank: ['dark_polymer', 1],
          cable: ['dark_polymer', 1],
          cableSata: ['dark_polymer', 1],
      };

      // Every shroud* variant and the tier names share one finish.
      const PBR_SHROUD_RE = /^shroud(Asus|Gigabyte|Msi|Evga|Zotac|Pny|Sapphire|Powercolor|Asrock|Corsair|Amd|Arc|Entry|Mid|Upper|Flagship|Lip)?$/;

      const PBR_BASE = '/textures/pbr';

      function applyPbr(material, key) {
          let entry = PBR_SLOT[key];
          if (!entry && PBR_SHROUD_RE.test(key)) {
              entry = ['gpu_shroud', 1];
          }
          if (!entry) return material;
          const [slot, repeat] = entry;

          // Base colour + normal load asynchronously; roughness/metalness wait for
          // the ORM, so a material is never left with an albedo and a stale
          // roughness. Callers get the material immediately and it upgrades in place.
          loadTexture(`${PBR_BASE}/${slot}_basecolor.jpg`).then((map) => {
              if (!map) return;
              map.wrapS = map.wrapT = THREE.RepeatWrapping;
              map.repeat.set(repeat, repeat);
              map.colorSpace = THREE.SRGBColorSpace;
              material.map = map;
              material.needsUpdate = true;
          });

          // Normals are data, not colour. Three defaults new textures to sRGB, which
          // would decode the normal's blue channel wrongly and tilt every highlight.
          loadTexture(`${PBR_BASE}/${slot}_normal.jpg`).then((map) => {
              if (!map) return;
              map.wrapS = map.wrapT = THREE.RepeatWrapping;
              map.repeat.set(repeat, repeat);
              map.colorSpace = THREE.NoColorSpace;
              material.normalMap = map;
              material.normalScale.set(0.6, 0.6);
              material.needsUpdate = true;
          });

          loadTexture(`${PBR_BASE}/${slot}_orm.jpg`).then((map) => {
              if (!map) return;
              map.wrapS = map.wrapT = THREE.RepeatWrapping;
              map.repeat.set(repeat, repeat);
              map.colorSpace = THREE.NoColorSpace;
              // Pack ORM into the three maps MeshStandardMaterial expects. Red is
              // AO, green is roughness, blue is metalness - no channel conversion.
              material.aoMap = map;
              material.roughnessMap = map;
              material.metalnessMap = map;
              // ORM packs AO in R; the material's own R would multiply it a second
              // time, so aoMapIntensity is set from the same image and the base
              // colour is left unmultiplied.
              material.aoMapIntensity = 0.85;
              material.needsUpdate = true;
          });

          return material;
      }

    const RGB_OFFSETS = {
        gpuLed: 0.85, ramLight: 0.55, ramDiffuser: 0.6, caseLed: 0.2, fanLed: 0.35,
    };

    // --- Spec primitives ----------------------------------------------------
    function applyTransform(obj, t, r) {
        obj.position.set(t?.[0] || 0, t?.[1] || 0, t?.[2] || 0);
        if (r) obj.rotation.set(r[0] || 0, r[1] || 0, r[2] || 0);
    }

    function primitive(p) {
        switch (p.p) {
            case 'box': {
                const g = new THREE.BoxGeometry(Math.max(0.1, p.s[0]), Math.max(0.1, p.s[1]), Math.max(0.1, p.s[2]));
                const m = new THREE.Mesh(g, specMaterial(p.m));
                applyTransform(m, p.t, p.r);
                m.castShadow = true;
                m.receiveShadow = true;
                return m;
            }

            case 'cyl': {
                // Axis-to-geometry: three's cylinder runs along +Y, so x and z
                // are a rotation rather than a rebuild.
                const rot = axisRotation(p.ax, p.rr);
                const g = new THREE.CylinderGeometry(Math.max(0.1, p.r), Math.max(0.1, p.r), Math.max(0.1, p.h), Math.min(32, p.seg || 20));
                const m = new THREE.Mesh(g, specMaterial(p.m));
                applyTransform(m, p.t, rot);
                m.castShadow = true;
                return m;
            }

            case 'ring': {
                // A hollow tube. three has no ring primitive, so it is two
                // concentric open cylinders merged - which is also what makes it
                // double-sided-free from the inside.
                const outer = new THREE.CylinderGeometry(p.o, p.o, Math.max(0.1, p.h), Math.min(32, p.seg || 24), 1, true);
                const inner = new THREE.CylinderGeometry(p.i, p.i, Math.max(0.1, p.h), Math.min(32, p.seg || 24), 1, true);
                const idx = inner.index ? inner.index.array : null;
                if (idx) {
                    for (let k = 0; k < idx.length; k += 3) {
                        const t2 = idx[k + 1];
                        idx[k + 1] = idx[k + 2];
                        idx[k + 2] = t2;
                    }
                    inner.index.needsUpdate = true;
                }
                const merged = mergeGeometries([outer, inner]);
                const m = new THREE.Mesh(merged, specMaterial(p.m));
                applyTransform(m, p.t, axisRotation(p.ax, p.rr));
                m.castShadow = true;
                return m;
            }

            case 'stack': {
                // A repeated plate run. Built here rather than in PHP so a
                // 54-plate fin stack is one spec entry instead of 54.
                const group = new THREE.Group();
                const [sx, sy, sz] = p.plate;
                const geo = new THREE.BoxGeometry(Math.max(0.1, sx), Math.max(0.1, sy), Math.max(0.1, sz));
                const mat = specMaterial(p.m);
                const n = Math.max(0, p.n | 0);
                const centred = p.c !== false;
                const total = (n - 1) * p.pitch;
                for (let i = 0; i < n; i++) {
                    const off = centred ? -total / 2 + i * p.pitch : i * p.pitch;
                    const mesh = new THREE.Mesh(geo, mat);
                    if (p.ax === 'x') mesh.position.x = off;
                    else if (p.ax === 'y') mesh.position.y = off;
                    else if (p.ax === 'z') mesh.position.z = off;
                    mesh.castShadow = true;
                    group.add(mesh);
                }
                applyTransform(group, p.t, p.rr);
                return group;
            }

            case 'fan': {
                // Rotor in the XY plane spinning about local +Z; `rr` orients it.
                const group = new THREE.Group();
                const r = Math.max(2, p.r);
                const depth = Math.max(1, p.d || 6);
                const hubR = r * (p.hub || 0.3);
                const blades = Math.max(3, p.n | 0);
                const bladeMat = specMaterial(p.bm || 'fanBlade');

                const hub = new THREE.Mesh(new THREE.CylinderGeometry(hubR, hubR, depth * 1.3, 16), specMaterial('fanHub'));
                hub.rotation.x = Math.PI / 2;
                group.add(hub);

                // Blades are pitched plates, not flat rectangles: a flat blade
                // is the single clearest tell of a procedural fan.
                const bladeGeo = new THREE.BoxGeometry(r * 0.30, r * 0.86, depth * 0.55);
                for (let i = 0; i < blades; i++) {
                    const blade = new THREE.Mesh(bladeGeo, bladeMat);
                    const a = (i / blades) * Math.PI * 2;
                    blade.position.set(Math.cos(a) * r * 0.55, Math.sin(a) * r * 0.55, 0);
                    blade.rotation.set(0, 0, a);
                    blade.rotateOnAxis(new THREE.Vector3(0, 1, 0), 0.42);
                    blade.castShadow = true;
                    group.add(blade);
                }

                applyTransform(group, p.t, p.rr);
                spinBlades.push({ group, speed: 0 });
                return group;
            }

            case 'tube': {
                const pts = (p.pts || []).map((q) => new THREE.Vector3(q[0], q[1], q[2]));
                if (pts.length < 2) return new THREE.Group();
                const curve = new THREE.CatmullRomCurve3(pts);
                const g = new THREE.TubeGeometry(curve, 40, Math.max(0.5, p.r), 8, false);
                const m = new THREE.Mesh(g, specMaterial(p.m || 'cable'));
                m.castShadow = true;
                return m;
            }

            default:
                return null;
        }
    }

    /** Cylinder/ring axis -> Euler. `extra` is any primitive rotation on top. */
    function axisRotation(axis, extra) {
        const e = extra || [0, 0, 0];
        if (axis === 'x') return [0, 0, Math.PI / 2 + (e[2] || 0)];
        if (axis === 'z') return [Math.PI / 2 + (e[0] || 0), 0, 0];
        return [e[0] || 0, e[1] || 0, e[2] || 0];
    }

    // Local merge, so no extra dependency on BufferGeometryUtils.
    function mergeGeometries(geometries) {
        const out = new THREE.BufferGeometry();
        const attrs = ['position', 'normal', 'uv'];
        const sizes = { position: 3, normal: 3, uv: 2 };
        let vertexCount = 0, indexCount = 0;
        for (const g of geometries) {
            vertexCount += g.attributes.position.count;
            indexCount += g.index ? g.index.count : g.attributes.position.count;
        }
        for (const name of attrs) {
            if (!geometries[0].attributes[name]) continue;
            const arr = new Float32Array(vertexCount * sizes[name]);
            let off = 0;
            for (const g of geometries) {
                const a = g.attributes[name];
                if (!a) { off += g.attributes.position.count * sizes[name]; continue; }
                arr.set(a.array, off);
                off += a.count * sizes[name];
            }
            out.setAttribute(name, new THREE.BufferAttribute(arr, sizes[name]));
        }
        const idxArr = vertexCount > 65535 ? new Uint32Array(indexCount) : new Uint16Array(indexCount);
        let vBase = 0, iOff = 0;
        for (const g of geometries) {
            const count = g.attributes.position.count;
            if (g.index) {
                for (let k = 0; k < g.index.count; k++) idxArr[iOff + k] = g.index.array[k] + vBase;
                iOff += g.index.count;
            } else {
                for (let k = 0; k < count; k++) idxArr[iOff + k] = k + vBase;
                iOff += count;
            }
            vBase += count;
            g.dispose();
        }
        out.setIndex(new THREE.BufferAttribute(idxArr, 1));
        out.computeBoundingSphere();
        return out;
    }

    /** Turn one server part entry (with its basis) into a Three.js group. */
    function partGroup(part) {
        const group = new THREE.Group();
        const b = part.basis || [[1, 0, 0], [0, 1, 0], [0, 0, 1]];
        const m = new THREE.Matrix4().makeBasis(
            new THREE.Vector3(b[0][0], b[0][1], b[0][2]),
            new THREE.Vector3(b[1][0], b[1][1], b[1][2]),
            new THREE.Vector3(b[2][0], b[2][1], b[2][2])
        );
        group.quaternion.setFromRotationMatrix(m);
        group.position.set(part.t?.[0] || 0, part.t?.[1] || 0, part.t?.[2] || 0);
        group.userData.category = part.category;

        for (const p of part.meshes || []) {
            const mesh = primitive(p);
            if (!mesh) continue;
            if (part.category === 'gpu' && p.m === 'backplate') mesh.name = 'gpu-shroud';
            group.add(mesh);
        }
        return group;
    }

    /** The whole scene from a spec. Returns the root group. */
    function buildFromSpec(spec) {
        const root = new THREE.Group();
        for (const part of spec.parts || []) {
            root.add(partGroup(part));
        }
        return root;
    }

    // --- Animated state (reset on every assemble) --------------------------
    let lastSignature = null;
    let lastCaseSignature = null;
    let buildGroup = null;
    let rgbPhase = 0;
    const rgbMats = [];          // emissive materials cycled each frame
    const spinBlades = [];       // { group, speed, wobble } fan blade rotors

    function rgbMaterial(offset = 0) {
        const m = new THREE.MeshStandardMaterial({
            color: 0x0a0a0f,
            emissive: new THREE.Color(0xff0000),
            emissiveIntensity: 2.0,
            roughness: 0.35,
            metalness: 0.2,
            transparent: true,
            opacity: 0.95,
        });
        m.userData.rgbOffset = offset;
        rgbMats.push(m);
        return m;
    }

    // Thin glowing strip (case perimeter / accent lines).
    function rgbStrip(w, h, d, color = 0x0a0a0f, offset = 0) {
        const m = new THREE.Mesh(new THREE.BoxGeometry(w, h, d), rgbMaterial(offset));
        m.castShadow = true;
        return m;
    }

    // Sleeved cable: CatmullRom tube through control points + end connectors.
    function cable(points, radius = 3.4, color = COLORS.cable, roughness = 0.85) {
        const group = new THREE.Group();
        const curve = new THREE.CatmullRomCurve3(points.map((p) => new THREE.Vector3(...p)));
        const tube = new THREE.Mesh(
            new THREE.TubeGeometry(curve, 32, radius, 8, false),
            material(color, { roughness, metalness: 0.08 })
        );
        tube.castShadow = true;
        group.add(tube);

        const startConn = new THREE.Mesh(new THREE.BoxGeometry(radius * 2.6, radius * 2.6, radius * 2.6), material(COLORS.conRad, { roughness: 0.6, metalness: 0.4 }));
        startConn.position.copy(curve.getPoint(0));
        group.add(startConn);
        return group;
    }

    /**
     * Procedural case fan. Axis = local Z (group is rotated into place by the
     * caller). Blades live in a child rotor so they can spin in the render loop
     * without moving the ring/hub. LED ring is an emissive RGB cylinder.
     */
    function fan(r, color, opts = {}) {
        const { spin = true, rgb = true, offset = 0, blades = 7, speed = Math.PI * 0.9 } = opts;

        const group = new THREE.Group();

        // Frame / shroud ring
        const ring = new THREE.Mesh(new THREE.CylinderGeometry(r, r, 25, 28), material(color));
        ring.rotation.x = Math.PI / 2;
        group.add(ring);

        // RGB LED ring (inner lip of the frame)
        if (rgb) {
            const led = new THREE.Mesh(new THREE.CylinderGeometry(r * 0.92, r * 0.92, 3, 28), rgbMaterial(offset));
            led.rotation.x = Math.PI / 2;
            group.add(led);
        }

        // Rotor (blades + hub) — animates rotation.z
        const rotor = new THREE.Group();
        const hub = new THREE.Mesh(new THREE.CylinderGeometry(r * 0.22, r * 0.22, 26, 16), material(0x05070a));
        hub.rotation.x = Math.PI / 2;
        rotor.add(hub);
        for (let i = 0; i < blades; i++) {
            const blade = new THREE.Mesh(new THREE.BoxGeometry(r * 0.085, r * 1.55, 20), material(0x9aa5b6, { roughness: 0.6, metalness: 0.1 }));
            blade.position.y = r * 0.72;
            blade.rotation.z = (i / blades) * Math.PI * 2;
            rotor.add(blade);
        }
        group.add(rotor);

        if (spin) spinBlades.push({ group: rotor, speed });
        return group;
    }

    function buildFromSelection() {
        const sel = getSelection?.() || {};
        // Pull the live catalogue so dims are from the DB, not the Alpine store.
        const catalog = window.pctgCatalog || {};
        const parts = {};
        for (const cat of Object.keys(sel)) {
            const picked = sel[cat];
            if (!picked?.id) continue;
            const items = catalog[cat] || [];
            const full = items.find((c) => c.id === picked.id) || picked;
            parts[cat] = full;
        }
        return parts;
    }

    /**
     * A fingerprint of the CURRENT selection, so assemble() can tell a real
     * part swap from a no-op re-render.
     *
     * This is what makes the viewport a builder rather than a one-shot preview.
     * Without it the only way to see a swap was to Hide then Show again, because
     * nothing in builder.js reacted to the selection changing - there was no
     * watcher anywhere in the Alpine store. Rebuilding 200+ procedural meshes
     * and re-creating the scene on every unrelated state change is wasteful and
     * resets the camera, so the signature is checked first.
     */
    function selectionSignature(parts) {
        return Object.keys(parts)
            .sort()
            .map((cat) => `${cat}:${parts[cat]?.id ?? 'none'}`)
            .join('|');
    }

    // --- Spec fetch, with an honest fallback ---------------------------------
    //
    // The scene is generated server-side (POST /builder/mesh-spec). Until that
    // lands, and forever if it cannot, the legacy primitive scene below is drawn.
    //
    // `specState` tracks which stage we are at so a slow request can never be
    // mistaken for a successful one, and so a part swap cannot apply a spec that
    // has already been superseded:
    //   null      nothing requested yet
    //   'loading' a request is in flight for `requestedSignature`
    //   'ready'   spec is valid for lastSignature
    //   'failed'  the endpoint could not produce a scene; legacy is in use
    let specState = null;
    let specSignature = null;
    const specCache = new Map();
    const SPEC_CACHE_MAX = 24;

    async function fetchSpec(parts, signature) {
        requestedSignature = signature;
        specState = 'loading';
        try {
            const payload = {};
            for (const cat of Object.keys(parts)) {
                if (parts[cat]?.id) payload[cat] = { id: parts[cat].id };
            }
            const res = await fetch('/builder/mesh-spec', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') || {}).content || '',
                },
                body: JSON.stringify({ parts: payload }),
            });
            const body = await res.json();
            if (!body?.ok || !body?.spec?.parts?.length) {
                throw new Error(body?.reason || `mesh-spec ${res.status}`);
            }
            specCache.set(signature, body.spec);
            // Bounded cache: a long browsing session swapping parts should not
            // grow this without limit.
            if (specCache.size > SPEC_CACHE_MAX) {
                specCache.delete(specCache.keys().next().value);
            }
            if (requestedSignature !== lastSignature) return; // superseded
            specState = 'ready';
            specSignature = signature;
            applySpec(body.spec);
        } catch (e) {
            if (requestedSignature !== lastSignature) return;
            // Announce the fallback in words (Rule 5): a silent drop back to
            // boxes would read as "this part just has less detail".
            specState = 'failed';
            console.warn('[3d] procedural geometry unavailable, using the legacy scene:', e.message);
            onFailure?.('geometry-unavailable',
                'The detailed 3D model could not be loaded, so a simplified version is shown instead. Every other part of the builder works normally.');
            // Draw the legacy scene so the panel is never empty. The previous
            // build is left in place if there is one: an empty black rectangle
            // is worse than a stale-but-real machine.
            if (!buildGroup) assembleLegacy();
        }
    }

    let requestedSignature = null;

    /** Swap the live scene for the generated one, keeping lights and floor. */
    function applySpec(spec) {
        disposeBuild();
        const build = buildFromSpec(spec);
        scene.add(build);
        buildGroup = build;
        lastCaseSignature = `${spec.case?.x}x${spec.case?.y}x${spec.case?.z}`;
        frameCamera(spec.case);
        onDimsChange?.(buildFromSelection());
    }

    function disposeBuild() {
        [...scene.children].forEach((c) => {
            if (c.type === 'Group' || (c.isMesh && c !== floor)) {
                scene.remove(c);
                c.traverse?.((n) => {
                    n.geometry?.dispose?.();
                    if (Array.isArray(n.material)) n.material.forEach((m) => m.dispose?.());
                    else n.material?.dispose?.();
                });
            }
        });
        rgbMats.length = 0;
        spinBlades.length = 0;
    }

    function assemble() {
        // Build once, or only when the selection has genuinely changed. The
        // signature is checked BEFORE any scene mutation, so a redundant call
        // is free and cannot leave a half-disposed scene behind.
        const pending = buildFromSelection();
        const signature = selectionSignature(pending);
        if (signature === lastSignature && buildGroup) {
            return buildGroup;
        }

        // A spec we already hold renders immediately and synchronously, so
        // going back to a previously viewed part is instant.
        const cached = specCache.get(signature);
        if (cached) {
            lastSignature = signature;
            applySpec(cached);
            return buildGroup;
        }

        // Otherwise keep what the customer is looking at until the new scene
        // arrives. Tearing the scene down to a blank case and then repopulating
        // it a few hundred milliseconds later is worse than being briefly stale.
        fetchSpec(pending, signature);
        return buildGroup;
    }

    /**
     * The legacy primitive scene: eight boxes and a few cylinders, one per
     * category. This is the FALLBACK, not the product.
     *
     * It is kept because the 3D panel is on a revenue-bearing storefront and a
     * geometry endpoint must never be able to take it down. It is drawn only
     * when /builder/mesh-spec cannot produce a scene, and the customer is told
     * the model has been simplified rather than being left to wonder why their
     * build turned into boxes.
     */
    function assembleLegacy() {
        lastSignature = selectionSignature(buildFromSelection());
        disposeBuild();

        const parts = buildFromSelection();
        const caseP = parts.case;
        const caseDims = caseP?.dims || { x: 210, y: 460, z: 460, glass: 'side', max_gpu: 400, max_cooler: 170, rad_top: 360 };

        const build = new THREE.Group();

        // --- Case frame -----------------------------------------------------
        const cw = caseDims.x, ch = caseDims.y, cd = caseDims.z;
        const casing = box(cw, ch, cd, COLORS.case, { transparent: true, opacity: 0.28, roughness: 0.35, metalness: 0.5 });

        // Tempered glass side panel — the showcase panel on the LEFT (-x wall).
        // The mesh is thickness (x=6) × height (y) × depth (z), flush into the
        // left wall, so it reads as a side panel, not a floating divider.
        const glassSide = box(6, ch - 60, cd - 8, COLORS.glass, {
            transparent: true, opacity: 0.22, roughness: 0.08, metalness: 0.6, side: THREE.DoubleSide,
        });
        glassSide.position.set(-cw / 2 + 4, 0, 0);
        casing.add(glassSide);
        build.add(casing);

        // Front glass for front-glass cases (O11 Vision, Y70)
        if ((caseDims.glass || '').includes('front')) {
            const glassFront = box(cw - 8, ch - 40, 6, COLORS.glass, {
                transparent: true, opacity: 0.16, roughness: 0.08, metalness: 0.6, side: THREE.DoubleSide,
            });
            glassFront.position.set(0, 0, cd / 2 - 4);
            casing.add(glassFront);
        }

        // --- Case RGB perimeter (subtle, along the glass edge + top lip) -----
        if (cw > 0 && ch > 0) {
            // vertical strip beside the glass panel, facing the viewer
            const sideStrip = rgbStrip(4, ch * 0.5, 6, 0x0a0a0f, 0.15);
            sideStrip.position.set(-cw / 2 * 0.45, 0, 0);
            build.add(sideStrip);
            // top lip strip
            const topStrip = rgbStrip(cd * 0.5, 4, 8, 0x0a0a0f, 0.45);
            topStrip.position.set(0, ch / 2 - 14, 0);
            build.add(topStrip);
            // basement glow strip
            const baseStrip = rgbStrip(cd * 0.42, 4, 6, 0x0a0a0f, 0.7);
            baseStrip.position.set(0, -ch / 2 + 26, 0);
            build.add(baseStrip);
        }

        // --- Coordinate basis (one convention for every part) ----------------
        // x = case width (glass at -x, tray wall at +x)
        // y = up
        // z = front-to-back (rear I/O at -z, front intake at +z)
        // The motherboard stands vertically on the +x tray wall, facing the
        // glass; everything else mounts off it exactly like a real build. */
        const mbDims = parts.motherboard?.dims || { x: 305, y: 25, z: 244 };
        const mbThick = 10;                        // board + standoffs, visual only
        const mbH = mbDims.x || 305;               // long axis runs VERTICALLY (y)
        const mbD = mbDims.z || 244;               // short axis runs front-back (z)
        const mbX = cw / 2 - mbThick / 2 - 16;     // tray wall: on the +x side
        const mbY = ch / 2 - mbH / 2 - 70;         // top of the board below any top rad
        const mbZ = -cd / 2 + mbD / 2 + 14;         // I/O edge against the rear panel

        // --- Motherboard ------------------------------------------------------
        const mb = box(mbThick, mbH, mbD, COLORS.mb);
        // No rotation: thickness along x, long axis along y, depth along z —
        // a board standing on the tray wall facing the glass.
        mb.position.set(mbX, mbY, mbZ);
        build.add(mb);

        // --- CPU -------------------------------------------------------------
        let cpuCenter = null;
        if (parts.cpu) {
            const cpuDims = parts.cpu.dims || { x: 40, y: 9, z: 40 };
            // Socket is up the board, toward the I/O edge (rear, -z).
            const cpuX = mbX + mbThick;                 // on the glass-facing face
            const cpuY = mbY + mbH * 0.30;              // upper half of the board
            const cpuZ = mbZ - mbD * 0.18;              // toward the rear I/O
            const cpu = box(cpuDims.x, cpuDims.y, cpuDims.z, COLORS.cpu);
            cpu.position.set(cpuX, cpuY, cpuZ);
            cpuCenter = cpu.position.clone();
            build.add(cpu);

            // Socket base (the raised metal frame around the IHS)
            const socket = box(cpuDims.x + 12, 3, cpuDims.z + 12, COLORS.mb);
            socket.position.set(cpuX, cpuY - cpuDims.y / 2 - 2, cpuZ);
            build.add(socket);
        }

        // --- RAM sticks ---------------------------------------------------------
        if (parts.ram) {
            const ramDims = parts.ram.dims || { x: 133, y: 32, z: 7 };
            // Two sticks stand upright beside the socket, slats facing the glass.
            for (let i = 0; i < 2; i++) {
                const stick = box(ramDims.z, ramDims.y, ramDims.x, COLORS.ram);
                stick.position.set(
                    mbX + mbThick + 12 + i * 15,
                    mbY + mbH * 0.30 + ramDims.y / 2 + 4,
                    mbZ - mbD * 0.12 + (i % 2) * 4
                );
                build.add(stick);
            }
        }

        // --- GPU in the primary PCIe slot --------------------------------
        let gpu = null, gpuDims = { x: 300, y: 120, z: 55 }, gpuPos = { x: 0, y: 0, z: 0 };
        if (parts.gpu) {
            gpuDims = parts.gpu.dims || { x: 300, y: 120, z: 55 };
            // The card sits in a PCIe slot below the CPU, parallel to the board,
            // its long axis (x=300) pointing down into the case depth (-z is
            // toward the rear panel where the bracket mounts).
            gpu = box(gpuDims.x, gpuDims.z, gpuDims.y, COLORS.gpu);
            const gpuY = mbY + mbH * 0.30 - 90;              // below the CPU
            const gpuZ = mbZ - mbD * 0.32;                    // slot position on board
            // Two-slot card: hang from the slot, bracket faces the rear panel.
            gpu.position.set(mbX + mbThick + 18, gpuY, gpuZ);
            gpuPos = gpu.position;

            // Real product shot as the shroud texture when the part has one.
            if (parts.gpu.id && !/svg/i.test(String(parts.gpu.image || ''))) {
                const shroudUrl = `/builder/part-image/${parts.gpu.id}`;
                loadTexture(shroudUrl).then((tex) => {
                    if (!tex || gpu.material instanceof Array) return;
                    const mats = [];
                    for (let i = 0; i < 6; i++) mats.push(material(COLORS.gpu));
                    mats[4] = new THREE.MeshStandardMaterial({ map: tex, roughness: 0.55, metalness: 0.15 });
                    gpu.material = mats;
                });
            }

            // RGB accent strip along the shroud's top edge (visible under glass)
            const gpuRgb = rgbStrip(gpuDims.x * 0.8, 3, 3, 0x0a0a0f, 0.9);
            gpuRgb.position.set(gpuPos.x, gpuPos.y + gpuDims.z / 2 + 4, gpuPos.z);
            build.add(gpuRgb);

            // Spinning GPU shroud fans (underside of the card, visible under glass)
            const fanSpacing = [gpuDims.y / 4, 0, -gpuDims.y / 4];
            for (let i = 0; i < 3; i++) {
                const fanAccent = fan(40, COLORS.fan, { spin: true, rgb: true, blades: 7, offset: 0.3 + i * 0.15, speed: Math.PI * 1.6 });
                fanAccent.position.set(
                    gpuPos.x,
                    gpuPos.y + gpuDims.y * 0.25,
                    gpuPos.z + fanSpacing[i]
                );
                fanAccent.rotation.x = Math.PI / 2;    // blow down toward the floor
                build.add(fanAccent);
            }
            build.add(gpu);
        }

        // --- Cooling --------------------------------------------------------------
        const cooler = parts.cooler?.dims;
        const topFanCount = 2;
        if (cooler?.type === 'aio') {
            const radLen = cooler.radiator_length || 360;
            const fans = cooler.fan_count || 3;
            // Top-mounted radiator, sitting flush against the roof (y = ch/2),
            // length spans the case depth, width spans the case width.
            const rad = box(cw * 0.72, 28, Math.min(radLen, cd - 40), COLORS.rad);
            rad.position.set(0, ch / 2 - 14, 0);
            build.add(rad);
            const gap = (Math.min(radLen, cd - 40)) / (fans + 1);
            for (let i = 1; i <= fans; i++) {
                const f = fan(60, COLORS.fan, { spin: true, rgb: true, blades: 9, offset: 0.05 * i, speed: Math.PI * 1.1 });
                f.position.set(0, ch / 2 - 52, 0 + (i - (fans + 1) / 2) * gap);
                f.rotation.x = Math.PI / 2; // blow DOWN into case (-y)
                build.add(f);
            }
            // Tube runs from the pump block up to the rad edge
            const tube = box(10, 140, 10, COLORS.aioTube);
            tube.position.set(mbX + mbThick + 6, ch / 2 - 110, mbZ + 30);
            build.add(tube);
        } else if (cooler?.type === 'air') {
            // Air tower over the CPU: tall fin stack, sits on the socket.
            const air = box(cooler.z || 130, cooler.y || 160, cooler.x || 120, COLORS.rad);
            air.position.set(mbX + mbThick + 20, mbY + mbH * 0.30 + (cooler.y || 160) / 2 - 8, mbZ - mbD * 0.18 + 30);
            build.add(air);
        } else {
            // No cooler → a couple of top exhaust fans make the case feel alive
            const gap = cd / (topFanCount + 1);
            for (let i = 1; i <= topFanCount; i++) {
                const f = fan(60, COLORS.fan, { spin: true, rgb: true, blades: 9, offset: 0.2 * i, speed: Math.PI * 1.0 });
                f.position.set(0, ch / 2 - 42, 0 + (i - (topFanCount + 1) / 2) * gap);
                f.rotation.x = -Math.PI / 2; // exhaust UP out of the roof (+y)
                build.add(f);
            }
        }

        // --- Case airflow fans ----------------------------------------------------
        // Rear exhaust on the rear wall (z = -cd/2), beside the I/O, blowing
        // OUT toward -z (the back of the case).
        const rear = fan(60, COLORS.fan, { spin: true, rgb: true, blades: 9, offset: 0.55, speed: Math.PI * 1.05 });
        rear.position.set(0, mbY + mbH * 0.30, -cd / 2 + 12);
        rear.rotation.x = Math.PI; // blow OUT the rear panel (-z)
        build.add(rear);

        // Front intake column hidden against the front panel (z = +cd/2),
        // blowing inward across the build toward the rear.
        const intakeY = [mbY + mbH * 0.42, ch * 0.15, ch * 0.42];
        const intakeGap = [cd * 0.18, 0, -cd * 0.18];   // x offsets for depth column
        for (let i = 0; i < 3; i++) {
            const front = fan(52, COLORS.fan, { spin: true, rgb: false, blades: 7, speed: Math.PI * 0.9 });
            front.position.set(intakeGap[i], intakeY[i], cd / 2 - 12);
            front.rotation.x = 0; // face inward: fan axis already +z
            build.add(front);
        }

        // --- PSU in the basement ----------------------------------------------------
        let psu = null, psuDims = { x: 140, y: 86, z: 150 }, psuPos = { x: 0, y: 0, z: 0 };
        if (parts.psu) {
            psuDims = parts.psu.dims || { x: 140, y: 86, z: 150 };
            psu = box(psuDims.x, psuDims.y, psuDims.z, COLORS.psu);
            // Bottom of the case, toward the rear, fan face to the floor
            psu.position.set(0, -ch / 2 + psuDims.y / 2 + 16, mbZ - 30);
            psuPos = psu.position;
            build.add(psu);
        }

        // --- Storage ----------------------------------------------------------------
        let storageP = null;
        if (parts.storage) {
            const stDims = parts.storage.dims || { x: 80, y: 22, z: 3 };
            // M.2 / 2.5” drive behind the board tray (hidden side), facing inward
            const st = box(stDims.y, stDims.x, stDims.z, COLORS.storage);
            st.position.set(cw / 2 - 28, mbY - mbH * 0.12, mbZ + 66);
            storageP = st.position;
            build.add(st);
        }

        // --- Cables (sleeved CatmullRom runs, true thickness-scale) ---------------
        const psuTop = psu ? psuPos.y + psuDims.y / 2 : -60;
        // 24-pin ATX: board I/O edge -> down to the PSU front
        if (psu && mb) {
            const src = [mbX + mbThick + 18, mbY - 10, mbZ + 40];
            const dst = [psuPos.x + 4, psuTop + 6, psuPos.z - psuDims.z / 2 + 8];
            const atx = cable([
                src,
                [src[0] + 10, src[1] - 60, src[2] + 16],
                [dst[0], dst[1] - 6, dst[2] + 14],
                dst,
            ], 3.6, 0x10151c, 0.8);
            build.add(atx);
            // 24-pin connector block on the board
            const conn = box(26, 30, 12, COLORS.conRad);
            conn.position.set(src[0], src[1] - 2, src[2]);
            build.add(conn);
        }
        // Dual PCIe 6+2 power to the GPU
        if (psu && gpu) {
            for (let k = 0; k < 2; k++) {
                const src = [gpuPos.x - gpuDims.x / 2 + 18, gpuPos.y - gpuDims.z / 2 - 4, gpuPos.z - 10 + k * 8];
                const dst = [psuPos.x - 6 + k * 20, psuTop + 6, psuPos.z - 14];
                const pcie = cable([
                    src,
                    [src[0] + 6, src[1] - 40, src[2] + 10 + k * 20],
                    [dst[0], dst[1] - 8, dst[2] + 18],
                    dst,
                ], 3.0, 0x151a22, 0.78);
                build.add(pcie);
            }
            const gpuConn = box(30, 12, 14, COLORS.conRad);
            gpuConn.position.set(gpuPos.x - gpuDims.x / 2 + 6, gpuPos.y - gpuDims.z / 2 - 6, gpuPos.z - 4);
            build.add(gpuConn);
        }
        // SATA data + power run to storage
        if (psu && storageP && parts.storage) {
            const src = [storageP.x + 12, storageP.y + 6, storageP.z + 10];
            const dst = [mbX - 10, mbY + 14, mbZ - 70];
            const sata = cable([
                src,
                [src[0] - 12, src[1] - 24, src[2] + 22],
                [dst[0] + 8, dst[1] - 10, dst[2] + 20],
                dst,
            ], 2.2, 0x1a2029, 0.8);
            build.add(sata);
        }

        scene.add(build);
        buildGroup = build;
        onDimsChange?.(parts);

        frameCamera({ x: cw, y: ch, z: cd });

        return build;
    }

    /**
     * Frame the build in mm space.
     *
     * Only re-framed when the CASE changes size. Re-framing on every swap yanks
     * the camera away from wherever the customer had orbited to, which is the
     * single most irritating way a 3D preview can behave while someone is
     * inspecting their build.
     *
     * The elevation is deliberately modest: a GPU's axial fans face the side
     * glass, so a near-level three-quarter view is what actually shows the
     * shroud, the fan apertures and the fin stack. Looking down from above shows
     * the top of a case and very little of the hardware inside it.
     */
    function frameCamera(caseDims) {
        const cw = caseDims?.x || 230;
        const ch = caseDims?.y || 460;
        const cd = caseDims?.z || 460;
        const caseSignature = `${cw}x${ch}x${cd}`;
        if (caseSignature === lastCaseSignature) return;
        lastCaseSignature = caseSignature;
        const diag = Math.max(cw, ch, cd) * 2.6;
        camera.position.set(-diag * 0.62, diag * 0.30, diag * 0.82);
        controls.target.set(0, 0, 0);
        controls.update();
    }

    function snapshot() {
        const canvas = renderer.domElement;
        const a = document.createElement('a');
        a.download = `pctg-build-3d-${Date.now()}.png`;
        a.href = canvas.toDataURL('image/png');
        a.click();
        return a.href;
    }

    /**
     * Send the current 3D frame to the render bridge: geometry → aigen →
     * photoreal storefront render. Polls ComfyUI history until the render drops.
     * Returns the render's public URL (ComfyUI /view) or throws.
     */
    async function renderStorefront({ prompt, parts, denoise } = {}) {
        // Force a square high-res frame for the storefront render.
        const w = 1024;
        const wasW = container.clientWidth, wasH = container.clientHeight;
        renderer.setSize(w, w);
        camera.aspect = 1;
        camera.updateProjectionMatrix();

        // Compose a clean "showroom" hero frame: soft studio backdrop, no floor
        // grid, and a front-3/4 elevated angle so BOTH glass panels and the
        // internals (GPU, AIO) are clearly in shot. Restore the user's camera +
        // scene state afterwards so the interactive viewport is unchanged.
        const [sx, sy, sz] = [scene.background, floor.visible, renderer.getClearColor().getHex()];
        const camPos = camera.position.clone();
        const camTarget = controls.target.clone();

        scene.background = new THREE.Color(0xe8eaee);
        renderer.setClearColor(0xe8eaee, 1);
        floor.visible = false;

        const boxBounds = new THREE.Box3().setFromObject(build);
        const diag = boxBounds.getSize(new THREE.Vector3()).length();
        const az = -Math.PI * 0.28, el = 0.38;
        camera.position.set(
            Math.sin(az) * Math.cos(el) * diag * 1.15,
            Math.sin(el) * diag * 1.15 + 60,
            Math.cos(az) * Math.cos(el) * diag * 1.15
        );
        controls.target.set(0, 40, 0);
        controls.update();
        renderer.render(scene, camera);
        const dataUrl = renderer.domElement.toDataURL('image/png');

        // restore interactive state
        scene.background = sx;
        floor.visible = sy;
        renderer.setClearColor(sz, 1);
        camera.position.copy(camPos);
        controls.target.copy(camTarget);
        controls.update();
        renderer.setSize(wasW, wasH);
        camera.aspect = wasW / wasH;
        camera.updateProjectionMatrix();

        let res;
        try {
            res = await fetch('/builder/render-3d', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') || {}).content || '' },
                body: JSON.stringify({ dataUrl, prompt: prompt || '', parts: parts || {}, denoise: denoise ?? 0.42 })
            });
        } catch (e) {
            onRender?.(null, 'Could not reach the render bridge: ' + e.message);
            throw e;
        }

        const body = await res.json();
        if (!res.ok) {
            onRender?.(null, body.error || `render-3d ${res.status}`);
            throw new Error(body.error || `render-3d ${res.status}`);
        }

        const promptId = body.prompt_id;

        // Poll ComfyUI history for completion (output filename appears as PNG).
        const comfyUrl = (window.pctgComfyUrl || 'http://127.0.0.1:8188');
        for (let i = 0; i < 60; i++) {
            await new Promise((r) => setTimeout(r, 2500));
            try {
                const hRes = await fetch(`${comfyUrl}/history/${promptId}`);
                if (!hRes.ok) continue;
                const h = await hRes.json();
                const entry = (h && h[promptId]) || {};
                if (entry.status?.status_str === 'success') {
                    const outs = entry.outputs || {};
                    const files = Object.values(outs)
                        .flatMap((node) => (node?.images || []).map((im) => im.filename))
                        .filter(Boolean);
                    if (files.length) {
                        const url = `${comfyUrl}/view?filename=${encodeURIComponent(files[0])}&subfolder=&type=output`;
                        onRender?.(url, null);
                        return url;
                    }
                } else if (entry.status?.status_str === 'error') {
                    onRender?.(null, 'ComfyUI render failed.');
                    throw new Error('ComfyUI render failed');
                }
            } catch (e) {
                // transient poll errors are fine
            }
        }
        onRender?.(null, 'Render timed out after 150s.');
        throw new Error('Render timed out');
    }

    function resize() {
        const w = container.clientWidth || 320;
        const h = container.clientHeight || 320;
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
        renderer.setSize(w, h);
    }

    const clock = new THREE.Clock();
    let raf;
    function loop() {
        const dt = Math.min(clock.getDelta(), 0.05);

        // Animate RGB: cycle hue through every registered emissive material.
        rgbPhase = (rgbPhase + dt * 0.10) % 1;
        for (const m of rgbMats) {
            if (m && m.emissive) {
                m.emissive.setHSL((rgbPhase + (m.userData?.rgbOffset || 0)) % 1, 0.9, 0.55);
            }
        }

        // Spin fan rotors (local Z axis — frame orientation is inherited).
        for (const sb of spinBlades) {
            if (sb.group) sb.group.rotation.z += dt * sb.speed;
        }

        controls.update();
        renderer.render(scene, camera);
        raf = requestAnimationFrame(loop);
    }

    const observer = new ResizeObserver(resize);
    observer.observe(container);

    window.addEventListener('resize', resize);
    resize();
    assemble();
    loop();

    return {
        assemble,
        snapshot,
        renderStorefront,
        renderer,

        /**
         * True when the scene matches the current selection. Used by the caller
         * to decide whether a re-render is needed, and by the test to prove a
         * part swap actually changes the scene.
         */
        currentSignature: () => lastSignature,
        isBuilt: () => Boolean(buildGroup),

        destroy: () => {
            cancelAnimationFrame(raf);
            observer.disconnect();
            window.removeEventListener('resize', resize);
            scene.traverse?.((n) => {
                n.geometry?.dispose?.();
                if (Array.isArray(n.material)) n.material.forEach((m) => m.dispose?.());
                else n.material?.dispose?.();
            });
            renderer.dispose();
            container.innerHTML = '';
        },
    };
}