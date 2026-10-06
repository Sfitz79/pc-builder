# Physical dimensions — what was tried, and why each was rejected

Measured 2026-10-05 against production Neon and live HTTP. Every entry below was
tested, not assumed. **No source has passed validation, so `curated.json` remains
empty and every such build honestly reads UNVERIFIED.**

This file exists so the work is not repeated. Each rejection below cost real time
to establish.

---

## 1. ASUS/MSI/Gigabyte product pages — rejected, not blocked

**Byparr renders them fine.** An early conclusion that ASUS "challenges headless
browsers" was wrong: at a 120s timeout Byparr returned 620,776 characters of
rendered HTML with no challenge. The 300s attempt timed out, which is a timeout,
not a refusal.

**The real problem is worse than a block: the data isn't published.**

```
Card Size occurrences : 0
exact number + mm/cm  : 80mm only
length                : ">30cm", "25-30cm", "20-25cm", "<20cm"
slot                  : 1-slot ... 3.65-slot  (a 15-value filter vocabulary)
```

ASUS publishes **buckets**, not millimetres, and the spec table itself never loads
— only the filter sidebar does. Even a perfect fetcher finds nothing to read.
`25-30cm` cannot settle whether a 300mm card clears a 300mm case, which is the only
question a fit check asks.ASUS's own `odinapi.asus.com/apiv2/SpecList` returns 404
for every plausible signature tried.

## 2. TechPowerUp — rejected, explicit bot challenge

Serves an active "Automated bot check" / slider challenge. Deliberate refusal, not
circumvented. Not attempted via Byparr either.

## 3. UK retailers — rejected, blocked

| Source | Result |
|---|---|
| Scan | 403 + challenge |
| Pangaea | 403 + challenge |
| Overclockers UK | 403 + challenge |
| ebuyer / Newegg | connection failed / 404 |

We are a UK retailer ourselves. Scraping our competitors' spec sheets to undercut
them was not going to be the right call regardless of technical feasibility.

## 4. technical.city — **rejected on accuracy, the important one**

Reached it, HTTP 200, no challenge, plain fetch, parsed cleanly. It looked like the
answer. Then validated against figures that are independently well documented:

| Card | technical.city | Correct | Verdict |
|---|---|---|---|
| RTX 3080 Ti | 285mm | 285mm | correct |
| RX 7900 XTX | 287mm | 287mm | correct |
| RTX 3060 | 242mm | 242mm (ref) | correct |
| **RTX 4090** | **304mm** | **336mm** | **WRONG by 32mm** |

A 32mm error is larger than the clearance being checked. This source mixes
Founders Edition and partner dimensions without saying which, which is exactly the
failure mode that must never reach a fit decision.

**This is why validation exists.** The source passed every mechanical test —
reachable, no challenge, parseable, plausible-looking — and was still wrong. Had it
been accepted on those grounds, a customer would have been told a 336mm card fits a
308mm case.

## 5. Other aggregators — rejected

`wikichip` connection failed · `gpuzoo` 403 · `videocardz` 404 + challenge ·
`nanoreview` challenge · `gpu-monkey` 404 · `specsheet-io` 404 ·
`tomshardware` 404. Two (`nanoreview`, `overclockers-co`) contained `584mm`/`276 mm`
fragments but no parseable, attributable dimension block.

---

## Where this actually leaves us

Dimensions are 100% absent on production:

```
gpu.length                  306 / 306 missing
case.max_gpu_length         399 / 399 missing
case.max_cpu_cooler_height  399 / 399 missing
cooler.height               372 / 372 missing
```

**This is safe rather than dangerous, because of the fail-closed fit gate.** Every
affected build reads UNVERIFIED, never "compatible". Before that gate existed,
`CompatibilityCheckerService` returned `compatible => true` for all of them,
because `if ($max && $gpu && $gpu > $max)` cannot fire when both are null.

## The routes that would actually work

1. **Buy a spec feed.** Data vendors and several PSU/cooler manufacturers sell
   structured dimension data. Deterministic, licensed for commercial use, and it
   removes scraping from the picture entirely.
2. **Commission the figures** for the SKUs we actually build. We do not need all
   2,708 — the top ~200 by demand covers most revenue.
3. **Measure the parts we stock.** We physically have GPUs and coolers. A
   caliper and an afternoon beats any of the above, and it is ground truth.

All three need an owner decision, which is why the loop stops here rather than me
committing the business to one.

## What NOT to do

Do not relax the fit gate to make the data look better. The gate being strict is
what makes missing data safe. Filling `max_gpu_length` with a plausible guess would
turn an honest UNVERIFIED into a false PASS — the precise failure this work exists
to prevent.
