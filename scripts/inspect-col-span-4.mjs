import { chromium } from 'playwright';

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
await page.goto('https://pctechguy.app/builder', { waitUntil: 'domcontentloaded', timeout: 90000 });
await page.waitForTimeout(6000);

const out = await page.evaluate(() => {
  const grid = document.querySelector('#build-results');
  // classList avoids selector-escaping problems with `lg:col-span-4`.
  const col = [...document.querySelectorAll('div')].find((d) => d.classList.contains('lg:col-span-4'));
  const label = (el) => `${el.tagName}.${(el.className || '').toString().trim().replace(/\s+/g, ' ').slice(0, 40)}  ::  ${(el.querySelector('h2, h3')?.textContent || '').trim().slice(0, 22)}`;
  return {
    colChildren: col ? [...col.children].map(label) : ['(none)'],
    gridChildren: grid ? [...grid.children].map(label) : ['(none)'],
    tail: col ? col.outerHTML.slice(-700) : '(none)',
  };
});

console.log('\ncol-span-4 children:');
out.colChildren.forEach((c) => console.log('   ', c));
console.log('\n#build-results direct children:');
out.gridChildren.forEach((c) => console.log('   ', c));
console.log('\n--- last 700 chars of the col-span-4 div AS PARSED BY THE BROWSER ---\n');
console.log(out.tail);

await browser.close();
