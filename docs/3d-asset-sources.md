# 3D / PBR asset sources — verified, not assumed

**Measured 2026-10-05** by calling each API and reading the live payload shape.
Nothing below is from memory, and several items contradict what the research notes
we started from claimed.

## The short version

**Poly Haven is the source to use.** CC0, and it states commercial use and
redistribution in writing. It serves a documented JSON API, needs no account, and
was reachable from here with no challenge of any kind.

**ambientCG is CC0 too but was not usable here** — see the honest note below.

**The free-model marketplaces are not a viable source for shipping**, and the
reason is not licence law. It's that we would be scraping them at all.

---

## Poly Haven — use this

- **Licence:** CC0 1.0. Public domain dedication. No attribution required, though
  crediting is free. Commercial use and redistribution both explicitly permitted —
  which is *stronger* than CC-BY, because there is no attribution trap to fall into.
- **API verified live:**
  - `https://api.polyhaven.com/types` → `hdris, textures, models`
  - `https://api.polyhaven.com/assets?t=textures` → full texture catalogue
  - `https://api.polyhaven.com/files/<name>` → per-map file listing
- **Map channels confirmed present:** `Diffuse`, `Rough`, `Metal`, `AO`, `Bump`,
  `nor_dx`, `nor_gl`, `arm`, `Displacement`, plus `blend`, `gltf`, `mtlx`.
  That is a complete PBR set including ORM packing and both normal-map conventions.
- **Resolutions:** 1k / 2k / 4k / 8k. Use **1k or 2k only** for the configurator.
- **No challenge, no sign-up, no rate-limit problem observed.**

### Candidates for our parts

| Texture | Apply to |
|---|---|
| `metal_plate` | Brushed steel — GPU shrouds, PSU casings, case panels |
| `metal_plate_02` | Variant for variety across chassis |
| `blue_metal_plate` | Accent trim, RGB-adjacent panels |
| `painted_metal_shutter` | Powder-coated / matte finishes |
| `black_painted_planks` | Matte black plastics (cooler fans, RAM heatspreaders) |
| `metal_grate_rusty`, `green_metal_rust` | Wear detail — **reference only**, too weathered to sell |

**Note:** there is no `plastic`, `circuit_board` or `pcb` asset on Poly Haven. My
first guess list invented those names. PCB green and moulded plastics will need
either a different CC0 source or simple procedurally-generated materials — and for a
PC configurator, plain flat colours with correct roughness are honestly better than a
generic grunge map anyway.

### Suggested first palette

```
gpu shroud / bracket   metal_plate        (1k Diffuse + Rough + Metal)
psu casing             metal_plate_02     (1k)
case panels            painted_metal_shutter (2k, tileable)
ram heatspreader       black_painted_planks (1k)
cooler fan hub         black_painted_planks (1k)
```

Start at **1k JPG** for everything except the case panels at 2k. The viewport budget
matters: geometry is currently 34 KB for the whole scene, and shipping four 4k PBR
sets would dwarf it.

---

## ambientCG — CC0, but not usable here

Also CC0 with written commercial permission, and the API responded HTTP 200. But
three attempts all failed to get usable data:

1. `displayCategory=Metal|Plastic|PaintedMetal` returned **the same 2013 results
   every time** — the filter was ignored.
2. `id=Metal049` returned `numberOfResults: 0`.
3. The `Wood096` record that did come back had an **empty `downloadFolders`** and a
   `maps` property that serialised as a .NET array header, not channels.

So the endpoint answers but does not reliably return downloadable map URLs. That's
recorded as measured. Poly Haven is strictly better for our purpose anyway: cleaner
API, ORM and dual-normal support, no reliance on guessing an undocumented parameter.

---

## Sketchfab / CGTrader / Free3D — do not build a pipeline on these

Not primarily for licence reasons, though those are real:

- **Sketchfab's paid standard licence, clause 2.2(h):** you may not make the asset
  available "in a manner intended to allow a third party to download, extract or
  access the Licensed Material as a stand-alone file." A browser 3D configurator
  does exactly that.
- **Per-model CC licences are mixed and often wrong for us.** `CC-BY-NC` is fatal
  (we sell the build). `CC-BY-ND` is fatal (repositioning or recomposing a mesh is
  a derivative, and merging into a composite does not escape it). `CC-BY-SA` would
  extend over our rendered output. "Editorial" forbids commercial use outright.
  **Free to download is not a licence category.**

The decisive problem is different and practical: **there is no bulk API and no
reliable way to source 13 component families this way.** Hunting individual
community uploads for a motherboard and matching its scale to our geometry is a
manual, unverifiable process. The licence gate is already built to police this
(`app/Services/ThreeD/AssetLicenceGate.php`, 26 checks passing) and it correctly
allows **CC0 and credited CC-BY only** — but that gate exists to police a handful of
carefully-vetted assets, not a scraping pipeline.

**If we do want a specific third-party mesh**, the honest sequence is: find it, read
its individual licence, record it in `resources/3d/asset-licences.json`, and run
`scripts/verify-3d-licences.php` before using it. That works fine for one asset. It
does not scale to a catalogue.

---

## What this changes about the plan

**Textures: solved, and cheaply.** Poly Haven, CC0, documented API, no challenge.
This is the low-risk high-value win and it was worth doing before touching meshes.

**Models: our own procedural geometry stays primary.** We already have 13 geometry
generators passing 34 checks — deterministic, dimensionally verified against real
part dimensions, 0.47 ms per build, 34 KB payload, and carrying **zero licence
obligation and zero attribution debt**. Third-party meshes add a licence tail to
something we already own outright. They are worth having only as *reference* for
proportions and materials, so we can write better generators.

**Two honest gaps remain**, and neither is a sourcing problem:
1. Our current output is **362 untextured primitives** — the PBR work is a real step
   up from here, not a tweak.
2. Physical dimensions are **100% missing on production**, and every vendor that
   publishes them either blocks headless browsers or renders specs in JavaScript.
   That is a procurement problem (a spec feed, or commissioned figures), not a
   scraping problem.

See `docs/3d-asset-licences.md` for the licence policy and
`scripts/repair-mojibake.mjs` for the encoding fix that made the Social panel's copy
readable on the page the TikTok audit was recorded against.