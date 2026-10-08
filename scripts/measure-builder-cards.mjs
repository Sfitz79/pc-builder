/**
 * MEASURES THE BUILDER'S CARDS AT REAL VIEWPORTS.
 *
 * WHY THIS EXISTS. The Boss reported that the cards at the bottom of the builder's
 * right-hand column "seem squished horizontally" compared with the card above them,
 * and that the page should be mobile-first and scale up to a desktop browser.
 *
 * Reading the Blade did not settle it: every partial is a plain block-level child of
 * `<div class="lg:col-span-4 space-y-6">`, so in principle all six share one grid
 * column width. "In principle" is not a measurement, and guessing at a CSS fix for
 * forty card usages risks making every one of them worse. So this opens the real page
 * in a real browser at several viewports and reports the actual rendered boxes.
 *
 * It reports, per card: width, left offset and height, plus any horizontal overflow
 * the card itself is causing, and the viewport it was measured at.
 *
 * Usage: node scripts/measure-builder-cards.mjs [url]
 */
import { chromium } from 'playwright';

const URL = process.argv[2] || 'https://pctechguy.app/builder';

// Mobile-first is the stated target, so the small viewports come first and are the
// ones that matter most.
const VIEWPORTS = [
  { name: 'mobile-360', width: 360, height: 800 },
  { name: 'mobile-390', width: 390, height: 844 },
  { name: 'tablet-768', width: 768, height: 1024 },
  { name: 'laptop-1280', width: 1280, height: 800 },
  { name: 'desktop-1920', width: 1920, height: 1080 },
  { name: 'wide-2560', width: 2560, height: 1440 },
];

const browser = await chromium.launch();
let problems = 0;

for (const vp of VIEWPORTS) {
  const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
  const page = await ctx.newPage();

  try {
    await page.goto(URL, { waitUntil: 'domcontentloaded', timeout: 90000 });
    // The intro overlay covers the page for its first few seconds; measuring through
    // it would report the overlay, not the cards.
    await page.waitForTimeout(6000);

    const data = await page.evaluate(() => {
      const cards = [...document.querySelectorAll('.pctg-card')];
      return {
        url: location.pathname,
        docScrollW: document.documentElement.scrollWidth,
        clientW: document.documentElement.clientWidth,
        cards: cards.map((c) => {
          const r = c.getBoundingClientRect();
          const heading = c.querySelector('h2, h3');
          return {
            title: (heading?.textContent || '(no heading)').trim().slice(0, 28),
            left: Math.round(r.left),
            width: Math.round(r.width),
            height: Math.round(r.height),
            scrollW: c.scrollWidth,
            // Overflowing content inside a card is the usual reason a card looks
            // "squished": the inner row refuses to shrink and the card clips it.
            overflowing: c.scrollWidth > c.clientWidth + 1,
          };
        }),
      };
    });

    const hOverflow = data.docScrollW > data.clientW + 1;
    console.log(`\n=== ${vp.name} (${vp.width}x${vp.height}) -> ${data.url} ===`);
    if (hOverflow) {
      console.log(`  PAGE SCROLLS HORIZONTALLY: scrollWidth ${data.docScrollW} > clientWidth ${data.clientW}`);
      problems += 1;
    }

    const widths = new Set();
    for (const c of data.cards) {
      widths.add(c.width);
      const flags = [];
      if (c.overflowing) flags.push(`OVERFLOWS (scrollW ${c.scrollW})`);
      console.log(
        `  ${String(c.width).padStart(5)}w  x=${String(c.left).padStart(5)}  h=${String(c.height).padStart(5)}  ${c.title.padEnd(30)} ${flags.join(' ')}`,
      );
    }
    if (widths.size > 1) {
      console.log(`  ${widths.size} DIFFERENT CARD WIDTHS on one screen: ${[...widths].sort((a, b) => a - b).join(', ')}`);
      problems += 1;
    }
    if (data.cards.length === 0) console.log('  no .pctg-card found - page may still be behind the login');
  } catch (err) {
    console.log(`  ERROR ${String(err?.message || err).slice(0, 120)}`);
  }
  await ctx.close();
}

await browser.close();
console.log(`\n${problems === 0 ? 'No layout problems measured.' : `${problems} layout problem(s) measured.`}\n`);
