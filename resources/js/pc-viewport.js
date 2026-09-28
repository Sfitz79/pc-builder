import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';

/**
 * PCTG 3D Build Viewport
 *
 * Assembles a true-to-scale visual reference of the configured build from the
 * catalogue's real spec dimensions (mm). Internal reference tool — not the
 * BuildCores 3D-product-mesh approach; every part is a procedural mesh sized
 * to its physical dims and placed into the case by slot. Real product photos
 * (GPU shroud, motherboard, PSU face) are applied as textures through the
 * same-origin /builder/part-image proxy so WebGL never taints on CORS.
 *
 * Richer procedural detail (all original, mm-scaled):
 *  - RGB: animated cycling LED rings on fans + perimeter glow strips in the
 *    case + GPU accent strip (pure emissive, no textures).
 *  - Fans: case intake (front), exhaust (rear), top/aio fans + GPU shroud
 *    fans, all with spinning blade assemblies and LED ring.
 *  - Cables: sleeved CatmullRom tubes from PSU up to the ATX 24-pin, GPU PCIe
 *    power, and a SATA run to storage.
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

export function mountPcViewport(container, getSelection, callbacks = {}) {
    if (!container) return null;

    const { onRender, onDimsChange } = callbacks;

    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, preserveDrawingBuffer: true });
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

    // --- Animated state (reset on every assemble) --------------------------
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

    function assemble() {
        // dispose old scene children (build group only — keep light + floor)
        [...scene.children].forEach((c) => {
            if (c.type === 'Group' || (c.isMesh && c !== floor)) {
                scene.remove(c);
                c.traverse?.((n) => {
                    n.geometry?.dispose?.();
                    n.material && !(n.material instanceof Array) && n.material.dispose?.();
                });
            }
        });
        rgbMats.length = 0;
        spinBlades.length = 0;

        const parts = buildFromSelection();
        const caseP = parts.case;
        const caseDims = caseP?.dims || { x: 460, y: 471, z: 426, glass: 'side', max_gpu: 420, max_cooler: 167, rad_top: 360 };

        const build = new THREE.Group();

        // --- Case frame -----------------------------------------------------
        const cw = caseDims.x, ch = caseDims.y, cd = caseDims.z;
        const casing = box(cw, ch, cd, COLORS.case, { transparent: true, opacity: 0.35, roughness: 0.35, metalness: 0.5 });

        // Tempered glass side panel (the showcase panel)
        const glassSide = box(cd, ch - 60, 6, COLORS.glass, {
            transparent: true, opacity: 0.22, roughness: 0.08, metalness: 0.6, side: THREE.DoubleSide,
        });
        glassSide.position.set(0, 0, cw / 2 - 8);
        casing.add(glassSide);
        build.add(casing);

        // Front glass for front-glass cases (O11 Vision, Y70)
        if ((caseDims.glass || '').includes('front')) {
            const glassFront = box(cw - 40, ch - 40, 6, COLORS.glass, {
                transparent: true, opacity: 0.16, roughness: 0.08, metalness: 0.6, side: THREE.DoubleSide,
            });
            glassFront.position.set(0, 0, -cd / 2 + 10);
            casing.add(glassFront);
        }

        // --- Case RGB perimeter (subtle, along the glass edge + top lip) -----
        if (cw > 0 && ch > 0) {
            // vertical strip beside the glass panel, facing the viewer
            const sideStrip = rgbStrip(4, ch * 0.55, 4, 0x0a0a0f, 0.15);
            sideStrip.position.set(-cw / 2 * 0.25, 0, cw / 2 - 26);
            build.add(sideStrip);
            // top lip strip
            const topStrip = rgbStrip(cd * 0.5, 4, 4, 0x0a0a0f, 0.45);
            topStrip.position.set(0, ch / 2 - 14, cw / 2 - 60);
            build.add(topStrip);
            // basement glow strip
            const baseStrip = rgbStrip(cd * 0.42, 4, 4, 0x0a0a0f, 0.7);
            baseStrip.position.set(0, -ch / 2 + 26, cw / 2 - 60);
            build.add(baseStrip);
        }

        // --- Motherboard ------------------------------------------------------
        const mbDims = parts.motherboard?.dims || { x: 305, y: 25, z: 244 };
        const mb = box(mbDims.x, mbDims.y, mbDims.z, COLORS.mb);
        // Mounted on the right wall (when viewed from glass side): x = depth axis of case, z = width axis
        mb.rotation.y = Math.PI / 2;
        mb.position.set(-cd / 2 + 12, ch / 2 - 70, -cw / 6);
        build.add(mb);

        // --- CPU ---------------------------------------------------------------
        let cpuCenter = null;
        if (parts.cpu) {
            const cpuDims = parts.cpu.dims || { x: 40, y: 9, z: 90 };
            const cpu = box(cpuDims.x, cpuDims.y, cpuDims.z, COLORS.cpu);
            cpu.position.set(
                mb.position.x - 12,
                mb.position.y + mbDims.y / 2 + cpuDims.y / 2 + 2,
                mb.position.z + 32
            );
            cpuCenter = cpu.position.clone();
            build.add(cpu);
        }

        // --- RAM sticks ----------------------------------------------------------
        if (parts.ram) {
            const ramDims = parts.ram.dims || { x: 133, y: 32, z: 7 };
            for (let i = 0; i < 2; i++) {
                const stick = box(ramDims.x, ramDims.y, ramDims.z, COLORS.ram);
                stick.position.set(
                    mb.position.x,
                    mb.position.y + mbDims.y / 2 + ramDims.y / 2,
                    mb.position.z - 60 + i * 20
                );
                build.add(stick);
            }
        }

        // --- GPU in the primary PCIe slot -----------------------------------------
        let gpu = null, gpuDims = { x: 300, y: 120, z: 55 }, gpuPos = { x: 0, y: 0, z: 0 };
        if (parts.gpu) {
            gpuDims = parts.gpu.dims || { x: 300, y: 120, z: 55 };
            gpu = box(gpuDims.x, gpuDims.y, gpuDims.z, COLORS.gpu);
            // PCIe slot on the motherboard, horizontal across the depth axis
            gpu.rotation.y = Math.PI / 2;
            gpu.position.set(
                mb.position.x + mbDims.x / 2 - gpuDims.x + 30,
                mb.position.y - mbDims.y / 2 - gpuDims.z / 2,
                mb.position.z + 12
            );
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
            const gpuRgb = rgbStrip(gpuDims.x * 0.72, 3, 3, 0x0a0a0f, 0.9);
            gpuRgb.position.set(gpu.position.x, gpu.position.y - gpuDims.y / 2 + 4, gpu.position.z + gpuDims.x * 0.1);
            build.add(gpuRgb);

            // Fan accents (spinning GPU shroud fans)
            for (let i = 0; i < 3; i++) {
                const fanAccent = fan(42, COLORS.fan, { spin: true, rgb: true, blades: 7, offset: 0.3 + i * 0.15, speed: Math.PI * 1.6 });
                fanAccent.position.set(
                    gpu.position.x + (i - 1) * 72,
                    gpu.position.y + gpuDims.y / 4,
                    gpu.position.z + gpuDims.z / 2 + 14
                );
                fanAccent.rotation.x = 0;
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
            // Top-mounted radiator
            const rad = box(radLen, 30, 54, COLORS.rad);
            rad.position.set(0, ch / 2 - 18, -cw / 6);
            build.add(rad);
            const gap = radLen / (fans + 1);
            for (let i = 1; i <= fans; i++) {
                const f = fan(60, COLORS.fan, { spin: true, rgb: true, blades: 9, offset: 0.05 * i, speed: Math.PI * 1.1 });
                f.position.set(rad.position.x + (i - (fans + 1) / 2) * gap, ch / 2 - 42, -cw / 6);
                f.rotation.x = Math.PI; // blow down into case
                build.add(f);
            }
            // Tube runs
            const tube = box(8, 140, 8, COLORS.aioTube);
            tube.position.set(rad.position.x + radLen / 2 - 40, ch / 2 - 110, -cw / 6 + 30);
            build.add(tube);
        } else if (cooler?.type === 'air') {
            const air = box(cooler.x || 125, cooler.y || 160, cooler.z || 135, COLORS.rad);
            air.position.set(mb.position.x - 20, mb.position.y + 100, mb.position.z + 32);
            build.add(air);
        } else {
            // No cooler → a couple of top exhaust fans make the case feel alive
            const gap = cd / (topFanCount + 1);
            for (let i = 1; i <= topFanCount; i++) {
                const f = fan(60, COLORS.fan, { spin: true, rgb: true, blades: 9, offset: 0.2 * i, speed: Math.PI * 1.0 });
                f.position.set(0, ch / 2 - 42, -cw / 6 + (i - (topFanCount + 1) / 2) * gap * 0.4);
                f.rotation.x = Math.PI; // exhaust up
                build.add(f);
            }
        }

        // --- Case airflow fans ----------------------------------------------------
        // Rear exhaust near the motherboard tray (visible from the back)
        const rear = fan(60, COLORS.fan, { spin: true, rgb: true, blades: 9, offset: 0.55, speed: Math.PI * 1.05 });
        rear.position.set(-cd / 2 + 8, ch / 2 - 160, -cw / 6);
        build.add(rear);

        // Front intake (hidden against the front panel but present at scale)
        const intakeY = ch * 0.28, intakeGap = cd / 6;
        for (let i = 0; i < 3; i++) {
            const front = fan(52, COLORS.fan, { spin: true, rgb: false, blades: 7, speed: Math.PI * 0.9 });
            front.position.set(0, intakeY, -cd / 2 + 12 + i * intakeGap);
            front.rotation.x = 0; // blow inward
            build.add(front);
        }

        // --- PSU in the basement ----------------------------------------------------
        let psu = null, psuDims = { x: 140, y: 86, z: 150 }, psuPos = { x: 0, y: 0, z: 0 };
        if (parts.psu) {
            psuDims = parts.psu.dims || { x: 140, y: 86, z: 150 };
            psu = box(psuDims.x, psuDims.y, psuDims.z, COLORS.psu);
            psu.position.set(0, -ch / 2 + psuDims.y / 2 + 20, cw / 6);
            psuPos = psu.position;
            build.add(psu);
        }

        // --- Storage ----------------------------------------------------------------
        let storageP = null;
        if (parts.storage) {
            const stDims = parts.storage.dims || { x: 80, y: 22, z: 3 };
            const st = box(stDims.x, stDims.y, stDims.z, COLORS.storage);
            st.position.set(mb.position.x - 10, mb.position.y + 10, mb.position.z - 80);
            storageP = st.position;
            build.add(st);
        }

        // --- Cables (sleeved CatmullRom runs, true thickness-scale) ---------------
        const psuTop = psu ? psuPos.y + psuDims.y / 2 : -60;
        // 24-pin ATX: motherboard right edge -> down to the PSU front
        if (psu && mb) {
            const src = [mb.position.x + mbDims.x / 2 - 6, mb.position.y + 24, mb.position.z + 40];
            const dst = [psuPos.x + 4, psuTop - 4, psuPos.z - psuDims.z / 2 + 8];
            const atx = cable([
                src,
                [src[0] - 8, src[1] - 60, src[2] + 18],
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
                const src = [gpu.position.x - gpuDims.x / 2 + 18, gpu.position.y - gpuDims.y / 2 - 4, gpu.position.z - 10 + k * 8];
                const dst = [psuPos.x - 6 + k * 20, psuTop - 2, psuPos.z - 14];
                const pcie = cable([
                    src,
                    [src[0] + 6, src[1] - 30, src[2] + 10 + k * 20],
                    [dst[0], dst[1] - 8, dst[2] + 18],
                    dst,
                ], 3.0, 0x151a22, 0.78);
                build.add(pcie);
            }
            const gpuConn = box(30, 12, 14, COLORS.conRad);
            gpuConn.position.set(gpu.position.x - gpuDims.x / 2 + 6, gpu.position.y - gpuDims.y / 2 - 6, gpu.position.z - 4);
            build.add(gpuConn);
        }
        // SATA data + power run to storage
        if (psu && storageP && parts.storage) {
            const src = [storageP.x + 20, storageP.y + 6, storageP.z + 12];
            const dst = [mb.position.x - 10, mb.position.y + 14, mb.position.z - 70];
            const sata = cable([
                src,
                [src[0] + 4, src[1] - 20, src[2] + 24],
                [dst[0] + 8, dst[1] - 10, dst[2] + 20],
                dst,
            ], 2.2, 0x1a2029, 0.8);
            build.add(sata);
        }

        scene.add(build);
        onDimsChange?.(parts);

        // Frame the build in mm space: place camera so the whole case is visible.
        const diag = Math.max(cw, ch, cd) * 2.6;
        camera.position.set(-diag * 0.55, diag * 0.55, diag * 0.85);
        controls.target.set(0, 0, 0);
        controls.update();

        return build;
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

    return { assemble, snapshot, renderStorefront, renderer, destroy: () => { cancelAnimationFrame(raf); observer.disconnect(); renderer.dispose(); container.innerHTML = ''; } };
}