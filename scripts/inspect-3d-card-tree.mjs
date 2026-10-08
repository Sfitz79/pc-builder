import { chromium } from 'playwright';

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
await page.goto('https://pctechguy.app/builder', { waitUntil: 'domcontentloaded', timeout: 90000 });
await page.waitForTimeout(6000);

const out = await page.evaluate(() => {
  const cards = [...document.querySelectorAll('.pctg-card')];
  const threeD = cards.find((c) => (c.querySelector('h2, h3')?.textContent || '').includes('3D Build View'));
  if (!threeD) return { err: '3D card not found' };

  // Walk the parsed subtree and report depth, so a spill (an element that should be
  // a sibling but became a child, or vice versa) is visible rather than inferred.
  const walk = (el, depth = 0, out = []) => {
    if (depth > 3) return out;
    for (const child of el.children) {
      const cls = (child.className || '').toString().trim().replace(/\s+/g, ' ').slice(0, 58);
      const txt = (child.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 26);
      out.push(`${'  '.repeat(depth)}<${child.tagName.toLowerCase()}> ${cls}  | "${txt}"`);
      walk(child, depth + 1, out);
    }
    return out;
  };

  // Count element children of the 3D card's own ancestors, to see what it swallowed.
  return {
    depth: threeD.getBoundingClientRect().width,
    tree: walk(threeD),
    ancestorChain: (() => {
      const chain = [];
      let n = threeD.parentElement;
      for (let i = 0; n && i < 4; i += 1) {
        chain.push(`${'  '.repeat(i)}<${n.tagName.toLowerCase()} id="${n.id}" class="${(n.className || '').toString().trim().slice(0, 46)}"> children=${n.children.length}`);
        n = n.parentElement;
      }
      return chain;
    })(),
  };
});

console.log('\n3D card width:', out.err || out.depth);
console.log('\nancestor chain:');
(out.ancestorChain || []).forEach((l) => console.log(l));
console.log('\nparsed subtree of the 3D card (depth 0-3):');
(out.tree || []).forEach((l) => console.log(l));

await browser.close();
