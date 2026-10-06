# Top-150 floor derivation + catalogue findings

Date: 2026-09-28
Author: Genie (session-008k continuation)
Status: **measurement complete, band change NOT applied**

## Why this document exists

The Boss directive: the **minimum** 1080p build must run the latest top 150
games at publisher-recommended hardware. So the 1080p floor stopped being a
hand-picked `gpu_tier` and became something that has to be measured against a
sourced game list, then priced as a real build.

This file records what was measured, what is broken, and what still has to
happen before the band promise can change. Nothing here is a claim about live
behaviour that was not checked.

---

## 1. The top-150 dataset

`database/scraped/steam-top150-requirements.json`, built by
`scripts/fetch-steam-top150.php`.

| Metric | Value |
|---|---|
| Chart source | `ISteamChartsService/GetMostPlayedGames` (first-party) |
| Requirements source | `store.steampowered.com/api/appdetails` (publisher-stated) |
| Rollup date | 2026-09-27 |
| Titles held | 100 |
| With both recommended GPU **and** CPU | 82 |
| With any requirements | 98 |
| With **recommended** specs | 89 |
| Fetch failures | 0 |
| No Steam store page | 1 (rank #16, appid 553850) |
| Genuinely state no requirements | 2 (FiveM, and the #16 app) |

### The chart only ever returns 100 ranks

`GetMostPlayedGames?count=200` returns **100**. Verified by dumping the decoded
`response.ranks` length. The original log line said "taking top 150", which was
a lie by implication - it printed a target the endpoint could never satisfy.
The script now reports the real cap and records `chart_rank_cap: 100` in
`_meta`.

Extending to 150 was probed: Steam's first-party top-sellers search pages
(`/search/?filter=topsellers`) yield 50 ids per page and 149 unique across
`start=0,50,100`. That is a **different metric** (revenue ranking, not
concurrent players). Presenting it as part of the same chart would
misrepresent it. Decision: **not** merged. Ranks 101-150 need either a Boss
decision to accept a second metric with per-game provenance, or a different
sourced list.

### The #16 gap is real and must be disclosed

appid 553850 (rank 16, 67,937 peak players) returns
`{"553850":{"success":false}}` - 28 bytes - and its store page renders
"Site Error". Steam has **no publisher specs** for it. Any claim of top-150
coverage has to name this hole rather than quietly counting 100/100.

---

## 2. The binding constraint (this is the actual answer)

Ranked by a coarse performance order in `scripts/genie-top150-floor.php`:

**GPU ceiling — `RX 6800 XT / RTX 3070` class (score 64), 8 GB VRAM**

| Rank | Title | Recommended GPU |
|---|---|---|
| #85 | Warhammer 40,000: Space Marine 2 | 8 GB VRAM, RX 6800 XT / RTX 3070 |
| #5 | WARDOGS | RTX 3070, RX 6700 XT |
| #36 | Palworld | RTX 3060 Ti / RX 6700 XT |
| #57 | Forza Horizon 6 | RTX 3060 Ti / RX 6700 XT / Arc A580 |
| #72 | CONTROL Resonant | RTX 3060 Ti / RX 6700 XT / Arc B580 |
| #92 | Crimson Desert Enhanced | RX 6700 XT / RTX 2080 |

**Cheapest class that clears it: `RX 6800 XT`** (12 titles sit at 6700 XT-class
or better).

**CPU ceiling — `i7-12700 / Ryzen 7 7800X3D` class**

| Rank | Title | Recommended CPU |
|---|---|---|
| #45 | Cyberpunk 2077 | Core i7-12700 or Ryzen 7 7800X3D |
| #85 | Warhammer 40,000: Space Marine 2 | Ryzen 7 5800X / i7-12700 |
| #5 | WARDOGS | i7-12700K / Ryzen 7 5700X |

So the evidence-derived 1080p floor is roughly:
**RTX 3060 Ti / RX 6700 XT / RX 6800 XT + Ryzen 7 5700X-5800X or i7-12700.**

---

## 3. The current band does not satisfy that promise

`minGpuTierFor('1080p')` is **tier 2**. Tier 2 admits
`RTX 5060, RTX 4060, RTX 3070, RTX 3060, RX 7600, RX 7600 XT, Arc A750,
Arc B580, Arc B570, Arc A580, GTX 1080 Ti`.

**An RTX 3060 passes the 1080p gate today.** WARDOGS, Space Marine 2,
Battlefield 6 and Palworld all demand more than a 3060. So the existing floor
is **under-specified** against the Boss's own requirement - it is not a
close call, it admits cards the data rejects.

### Taxonomy defects found while checking this

1. **`RX 6800 XT` is tier 5.** It sits in the same bucket as RTX 5090, RTX 4090
   and RX 7900 XTX. A 16 GB 3070 Ti-class card is not a 4K flagship. This also
   makes the gate *over*-generous for anything that reads tier upward.
2. **`RTX 4070 Ti SUPER` is tier 3.** Tier 3 matches on `/RTX 4070/` with no
   word boundary, so the Ti/Ti Super variants fall through to a 1440p bucket
   they exceed. The separate score table at `AIRecommendationService` ~line 4157
   rates `RTX 4070 TI SUPER` at 750, so **the two functions disagree**.
3. **`RX 6700 XT` (tier 3) sits above `RTX 3070` (tier 2)** despite being
   near-equivalent.

Measured on the live-shaped rows (`scripts/genie-check-t150-parts.php`):
`RX 6800 XT` -> 5, `RTX 3070 Ti` -> 3, `RX 6700 XT` -> 3.

### Not yet decided

The coherent end state is roughly esports=tier 1, 1080p=tier 3, 1440p=tier 4,
4K=tier 5, **but** that changes what we are allowed to promise at every tier and
moves money. It is a decision, not a patch, and it is **not applied**. Fixing
(1) and (2) are unambiguous bug fixes; re-banding the floor is the business call.

---

## 4. Price sanity - suspicion raised and RETRACTED

The 3070 Ti / 6800 XT / 6700 XT rows carry prices of £640-£899, which looked ~2x
UK street price. **Checked against PCPP directly and the suspicion was wrong:**
PCPP's own page for the Sapphire RX 6700 XT 12 GB Pulse lists **£899.00**, and
our catalogue matches it. No inflation bug. Recorded here because I stated the
concern out loud and it did not survive checking.

Still unproven: whether those UK prices are genuinely what the thin end-of-life
market now charges. PCPP's vendor table markup did not match a simple `<tr>`
regex, so the per-merchant spread was not extracted. Merchant evidence is still
`0 / 2,708`, so nothing here is price-confirmed against a retailer.

---

## 5. CanIRunIt and SystemRequirementsLabs are the same site

The Boss asked to add "canirunit.com / systemrequirementslabs" as sources. They
are **one source**:

- Both resolve into `74.208.236.0/24`.
- Both return **byte-identical 94,564-byte** responses.
- Same title (`Can You RUN It | Can I Run It | Can My PC Run It`).
- Same `Server: Kestrel`.
- The page text names System Requirements Lab as the operator, and the asset
  host is `assets.systemrequirementslabs.net`.

**HTTPS is broken on it.** Both hosts accept TCP:443, then close the transport
mid-handshake (`SslStream` reports "the remote party has closed the transport
stream"). This is not a client TLS-version problem - Byparr's Chromium fails too
with `SSL_ERROR_UNKNOWN` / 502.

**HTTP works.** Over port 80 both serve the site, and the search API responds:

```
http://www.canirunit.com/api/games/search?q=cyberpunk%202077&page=1&itemsPerPage=5
-> {"games":[{"id":13169,"name":"Cyberpunk 2077","mask":"cyberpunk-2077"}, ...]}
```

Per-game detail endpoints were not found: `/api/games/13169`, `/api/game/13169`,
`.../details`, `/api/game/cyberpunk-2077` and `/api/games/mask/...` all fail at
the connection, and the game page itself (`/cyberpunk-2077`) closes the
connection. Only the search endpoint is confirmed working.

**Consequence:** CanIRunIt can currently supply *game name matching* only, not
requirements. It cannot be used as a requirements source until a working
per-game endpoint is identified from the site's own JS. Do not present it as
requirements data.

---

## 6. Bugs found and fixed in `scripts/fetch-steam-top150.php`

Every one of these produced a **silently empty or wrong dataset** rather than an
error:

1. `['recommended']['minimum']` - wrong path. `pc_requirements.recommended` is a
   **plain HTML string**, with no `minimum` sub-key.
2. Then `['recommended']['raw']` - also wrong. There is **no `raw` sub-key**.
   Verified shape: `pc_requirements = ['minimum' => '<html>', 'recommended' => '<html>']`,
   and `recommended` is **absent entirely** for titles that only state minimums.
   Result: still 0/100.
3. `field($blob, 'Video Card')` - Steam's label is **`Graphics:`**. Several
   publishers use `CPU:` not `Processor:`. The extractor now accepts
   `Graphics|Video Card|GPU` and `Processor|CPU`.
4. `clean()` used bare `strip_tags`, welding `<li>` boundaries into
   `...Windows 10Processor:...` and destroying the word boundary every field
   lookup depends on. Tags are now replaced with a space, and `script`/`style`
   bodies are dropped.
5. Skip condition was `isset($out['games'][$appid]['gpu'])` - but a title with no
   requirements legitimately stores `null`, and `isset(null)` is `false`, so such
   titles were refetched forever. Now keyed on an explicit `fetched_at`.
6. A throttled response and a genuine "no requirements" were indistinguishable.
   `get()` now retries 3x with backoff, transport failure is recorded as
   `fetch_failed` (and retried next run), and `success:false` is recorded as
   `no_store_data`.
7. The DONE line conflated the two, and would have reported a broken capture as
   complete. It now reports parsed / with-requirements / fetch-failures /
   no-store-page / genuinely-no-requirements separately.

**Outcome: 0/100 -> 89 titles with recommended specs.**

---

## 7. Image pipeline (resolved)

The `.1600.jpg` candidate that returned 403 was a **missing separator** in the
generated filename. Fixed. New production-ID images measure 600x600 to
1200x1200 - real high-res, not 256px fallbacks.

One genuine low-resolution file, `38943.jpg` (Ryzen 5 7600X3D) at 160x160, was
checked and is **byte-identical (SHA256) to the source `.1600.jpg` URL**, which
PCPP itself serves at 160x160. Upstream asset is small; not a code fallback.

---

## 8. What still has to happen

1. Decide the floor re-banding (esports=t1, 1080p=t3, 1440p=t4, 4K=t5) as a
   business call, then fix `RX 6800 XT` and `RTX 4070 Ti SUPER` as outright
   bugs with tests.
2. Price the 3070/6700XT/6800XT + 5700X/5800X build from the **production**
   catalogue (local sqlite was used for the reading above) to turn the spec
   ceiling into a £ floor.
3. Neon-backed regression for the picker, because SQLite is not production.
4. Decide the ranks 101-150 source, with per-game metric provenance.
5. Find a working CanIRunIt per-game endpoint, or stop citing it.
6. Merchant evidence feed; `0 / 2,708` means no price on the floor is verified.
