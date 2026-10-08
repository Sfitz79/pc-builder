/**
 * NAMES THE CONTAINER EACH BUILDER CARD ACTUALLY SITS IN.
 *
 * MEASURED PROBLEM. At 1280px and above, scripts/measure-builder-cards.mjs found five
 * cards at 57px wide stepping sideways by ~81px, while the card above them measured
 * 299px. 57px x 12 + 11 gaps x 24 = 944px, which is exactly the width of
 * `#build-results`, and the step of ~81px is exactly one 56.67px grid track plus one
 * 24px gap. So those five cards are each occupying ONE TRACK of the 12-column grid,
 * which only happens if they are direct children of it rather than members of the
 * `lg:col-span-4` column.
 *
 * Counting <div> tags in the served HTML gave a delta of -2 around that region, but
 * the source partials are all balanced (build-3d.blade.php is 13/13 and no partial is
 * unbalanced), so the string count was misleading. This asks the RENDERED DOM instead,
 * which cannot be miscounted: for every card it reports its real parent's classes and
 * how many cards share each parent.
 *
 * Usage: node scripts/diagnose-card-containers.mjs [url] [width]
 */
import { chromium } from 'playwright';

const URL = process.argv[2] || 'https://pctechguy.app/builder';
const WIDTH = Number(process.argv[3] || 1280);

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: WIDTH, height: 900 } });
const page = await ctx.newPage();
await page.goto(URL, { waitUntil: 'domcontentloaded', timeout: 90000 });
await page.waitForTimeout(6000);

const out = await page.evaluate(() => {
  const cards = [...document.querySelectorAll('.pctg-card')];
  const describe = (el) => {
    if (!el) return '(none)';
    const cls = (el.className || '').toString().trim().replace(/\s+/g, ' ');
    return `<${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${cls ? ' class="' + cls.slice(0, 80) + '"' : ''}>`;
  };
  return {
    gridCols: (() => {
      const g = document.querySelector('#build-results');
      return g ? getComputedStyle(g).gridTemplateColumns : '(no #build-results)';
    })(),
    cards: cards.map((c) => {
      const r = c.getBoundingClientRect();
      const parent = c.parentElement;
      return {
        title: (c.querySelector('h2, h3')?.textContent || '(no heading)').trim().slice(0, 26),
        width: Math.round(r.width),
        left: Math.round(r.left),
        parent: describe(parent),
        parentWidth: parent ? Math.round(parent.getBoundingClientRect().width) : 0,
        siblingsInParent: parent ? [...parent.children].filter((k) => k.classList?.contains('pctg-card')).length : 0,
        // Is this card a DIRECT child of the 12-column grid? That is the whole question.
        directGridChild: !!parent && parent.id === 'build-results',
      };
    }),
  };
});

console.log(`\n#build-results gridTemplateColumns:\n  ${out.gridCols}\n`);
const parents = new Map();
for (const c of out.cards) {
  parents.set(c.parent, (parents.get(c.parent) || 0) + 1);
}
console.log('CARD CONTAINERS');
for (const [p, n] of parents) console.log(`  ${String(n).padStart(2)} card(s) inside ${p}`);
console.log('\nPER CARD');
for (const c of out.cards) {
  console.log(
    `  ${String(c.width).padStart(5)}w x=${String(c.left).padStart(5)}  ${c.title.padEnd(28)} parent=${c.parent.slice(0, 52).padEnd(52)} ${c.directGridChild ? 'DIRECT GRID CHILD' : ''}`,
  );
}

const strays = out.cards.filter((c) => c.directGridChild);
console.log(`\n${strays.length === 0 ? 'Every card is inside a column wrapper.' : `${strays.length} card(s) are direct children of the 12-column grid, each confined to a single ~57px track: ${strays.map((s) => s.title).join(', ')}`}\n`);

await browser.close();
