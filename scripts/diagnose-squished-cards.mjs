/**
 * FINDS WHY THE BUILDER'S BOTTOM CARDS RENDER ~57px WIDE.
 *
 * MEASURED, not guessed. scripts/measure-builder-cards.mjs reported, at 1280px and
 * above, five cards at 57px wide and ~1471px tall, stepping sideways by ~81px each,
 * while the cards in the same column measured 512-1048px:
 *
 *     3D Build View        299w  x= 957
 *     Build Summary         57w  x= 312   OVERFLOWS (scrollW 184)
 *     Compatibility         57w  x= 393   OVERFLOWS (scrollW 176)
 *     Upgrade Path to Ideal 57w  x= 473   OVERFLOWS (scrollW 119)
 *     Build Health          57w  x= 554   OVERFLOWS (scrollW  96)
 *     Expected FPS          57w  x= 635   OVERFLOWS (scrollW 130)
 *
 * x=312 is 288 (the fixed w-72 sidebar) plus its p-5, so these are inside the SIDEBAR -
 * not the results column the Blade partials live in. Something is laying five cards out
 * side by side in a ~57px track inside a 288px fixed element.
 *
 * This walks each squished card's ancestor chain and prints the computed styles that
 * decide its width, so the culprit is named instead of inferred.
 *
 * Usage: node scripts/diagnose-squished-cards.mjs [url] [width]
 */
import { chromium } from 'playwright';

const URL = process.argv[2] || 'https://pctechguy.app/builder';
const WIDTH = Number(process.argv[3] || 1280);

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: WIDTH, height: 900 } });
const page = await ctx.newPage();
await page.goto(URL, { waitUntil: 'domcontentloaded', timeout: 90000 });
await page.waitForTimeout(6000);

const report = await page.evaluate(() => {
  const props = ['display', 'gridTemplateColumns', 'flexDirection', 'flexWrap', 'width', 'maxWidth', 'minWidth', 'position', 'overflowX', 'gap'];
  const chain = (el) => {
    const out = [];
    let n = el;
    for (let i = 0; n && i < 7; i += 1) {
      const cs = getComputedStyle(n);
      const r = n.getBoundingClientRect();
      const styles = {};
      for (const p of props) {
        const v = cs[p];
        if (v && v !== 'none' && v !== 'normal' && v !== 'auto' && v !== '0px') styles[p] = v;
      }
      out.push({
        tag: n.tagName.toLowerCase(),
        cls: (n.className || '').toString().slice(0, 90),
        width: Math.round(r.width),
        left: Math.round(r.left),
        styles,
      });
      n = n.parentElement;
    }
    return out;
  };

  const squished = [...document.querySelectorAll('.pctg-card')]
    .map((c) => ({ c, w: c.getBoundingClientRect().width }))
    .filter(({ w }) => w > 0 && w < 200);

  return squished.map(({ c, w }) => {
    const heading = c.querySelector('h2, h3');
    return {
      title: (heading?.textContent || '(no heading)').trim().slice(0, 30),
      width: Math.round(w),
      scrollW: c.scrollWidth,
      clientW: c.clientWidth,
      // Which of the card's own children are refusing to shrink?
      widestChild: [...c.children]
        .map((k) => ({ tag: k.tagName.toLowerCase(), cls: (k.className || '').toString().slice(0, 60), w: Math.round(k.getBoundingClientRect().width) }))
        .sort((a, b) => b.w - a.w)[0] || null,
      ancestors: chain(c),
    };
  });
});

console.log(`\nSQUISHED CARDS AT ${WIDTH}px  (${report.length} found)\n`);
for (const r of report) {
  console.log(`--- ${r.title}  ${r.width}px wide, client ${r.clientW}, scroll ${r.scrollW} ---`);
  if (r.widestChild) console.log(`    widest child: <${r.widestChild.tag}> ${r.widestChild.w}px  ${r.widestChild.cls}`);
  r.ancestors.forEach((a, i) => {
    const pad = '    '.repeat(i + 2);
    const s = Object.entries(a.styles).map(([k, v]) => `${k}=${v}`).join('  ');
    console.log(`${pad}<${a.tag}> w=${a.width} x=${a.left}  ${a.cls}`);
    console.log(`${pad}    ${s}`);
  });
  console.log('');
}

await browser.close();
