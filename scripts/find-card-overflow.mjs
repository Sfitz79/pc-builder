import { chromium } from 'playwright';
const b = await chromium.launch();
const p = await (await b.newContext({ viewport: { width: 360, height: 800 } })).newPage();
await p.goto('https://pctechguy.app/builder', { waitUntil: 'domcontentloaded', timeout: 90000 });
await p.waitForTimeout(6000);
const out = await p.evaluate(() => {
  const card = [...document.querySelectorAll('.pctg-card')].find(c => (c.querySelector('h2,h3')?.textContent||'').includes('Selected Components'));
  if (!card) return ['not found'];
  const res = [];
  const walk = (el, d) => {
    if (d > 10) return;
    for (const k of el.children) {
      if (k.scrollWidth > k.clientWidth + 1) {
        res.push({ d, tag: k.tagName.toLowerCase(), cls: (k.className||'').toString().trim().replace(/\s+/g,' ').slice(0,90),
          cw: k.clientWidth, sw: k.scrollWidth, ws: getComputedStyle(k).whiteSpace, minW: getComputedStyle(k).minWidth,
          txt: (k.textContent||'').trim().replace(/\s+/g,' ').slice(0,44) });
      }
      walk(k, d+1);
    }
  };
  walk(card, 0);
  return res.slice(0, 10);
});
out.forEach(x => console.log(`d${x.d} <${x.tag}> client=${x.cw} scroll=${x.sw} ws=${x.ws} minW=${x.minW}\n     ${x.cls}\n     "${x.txt}"`));
await b.close();
