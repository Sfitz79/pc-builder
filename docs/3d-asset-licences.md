# 3D asset licences — what may be used and why

## The rule that matters

**Meshes stay in-house. Customers never receive a source file.**

Third-party 3D models are used as **internal reference material**. The only thing a
customer receives is a **rendered image of their own configured build** — never a
mesh, never a `.glb`, nothing they could lift out of the page and reuse.

That distinction is the whole reason this is workable. Sketchfab's standard licence
clause 2.2(h) forbids making an asset available "in a manner intended to allow a
third party to download, extract or access the Licensed Material as a stand-alone
file". Serving the mesh in a browser configurator does precisely that. Rendering it
into a flat image of the customer's own PC does not.

So: **sampling, measuring, studying and rendering internally is fine. Shipping the
asset is not.**

## "Free to download" is not a licence

These platforms mix licence categories on the same search page. A model sitting
next to a CC0 upload may be:

| Licence | Verdict | Why |
|---|---|---|
| **CC0 / Public Domain** | ✅ allowed | nothing owed |
| **CC-BY 4.0 / 3.0** | ⚠️ attribution required | commercial use fine; **credit the creator** |
| CC-BY-SA | ❌ rejected | share-alike would extend over our rendered output |
| **CC-BY-NC** | ❌ rejected | non-commercial. **We sell the build.** The render is commercial use however it is described internally |
| **CC-BY-ND** | ❌ rejected | no derivatives. Repositioning or re-proportioning *is* a derivative, and merging into a composite does not escape it |
| Editorial | ❌ rejected | explicitly forbids commercial or promotional use |
| Personal Use | ❌ rejected | forbids commercial use |
| Marketplace "Royalty Free" / "Standard Licence" | ⚠️ review | permits renders, restricts source redistribution. Terms differ per platform and change — each needs a human read |
| *(nothing stated)* | ❌ rejected | an unlicensed upload is not public domain |

### Three corrections to the assumption

1. **Combining assets does not clear attribution.** A CC-BY asset still owes credit
   even inside a composite, and mixing it with your own material does not dissolve
   that duty.
2. **Merging does not escape ND.** A no-derivatives licence binds precisely because
   you changed something; recombining is a derivative, so it is banned either way.
3. **"Representative image for visual representation" is still commercial use.**
   We are selling the build it depicts. The internal description of the render does
   not change what the render is for.

### The one genuinely favourable point

Because meshes never ship, and because our output is a render rather than a
redistribution, a credited CC-BY asset is workable. That is the reason this plan is
viable at all.

## Our own geometry comes first

`app/Services/ThreeD/` is **13 files of our own procedural geometry** — 34 checks
passing, 0.47 ms per build, 34 KB payload, deterministic, dimensionally verified
against real part dimensions, and carrying **zero licence risk and zero
attribution obligation**.

It already covers every internal component: case, case fans, cooler, CPU, GPU,
motherboard, PSU, RAM, storage.

Third-party meshes earn their place as **reference** — for real-world proportions,
finish, colour and material behaviour, and for judging what higher-fidelity
generators need to reproduce. Use them to learn, then build our own. That is both
legally clean and better for the brand, because the geometry stays accurate to the
parts we actually sell.

## How this is enforced

- `app/Services/ThreeD/AssetLicenceGate.php` — decides one licence string, and a whole manifest. **Fails closed**: absent, unknown, malformed or unrecognised licences are rejected, never assumed permitted. A CC-BY asset with no author recorded is rejected, because the credit cannot be given.
- `resources/3d/asset-licences.json` — the manifest. Every sampled asset is recorded here with `name`, `source`, `licence`, `attribution`, `url`, `retrieved`.
- `scripts/verify-3d-licence-gate.php` — 26 checks, including the combinations that are easy to get wrong (`CC-BY-NC-ND-4.0` must not pass as `CC-BY`).
- `scripts/verify-3d-geometry.php` — 34 checks on our own geometry.

Run both before any asset is used or shipped.
