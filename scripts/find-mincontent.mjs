import { chromium } from 'playwright';
const b = await chromium.launch();
const p = await (await b.newContext({ viewport: { width: 360, height: 800 } })).newPage();
await p.goto('https://pctechguy.app/builder', { waitUntil: 'domcontentloaded', timeout: 90000 });
await p.waitForTimeout(6000);
const out = await p.evaluate(() => {
  const main = document.querySelector('main');
  const res = [];
  const walk = (el, depth) => {
    if (depth > 14) return;
    for (const k of el.children) {
      const r = k.getBoundingClientRect();
      const cs = getComputedStyle(k);
      // Elements that are wider than main's content box AND whose children are not
      // wider still are the ones actually forcing the min-content floor.
      let maxChild = 0;
      for (const c of k.children) maxChild = Math.max(maxChild, c.getBoundingClientRect().width);
      if (r.width > 330 && maxChild <= r.width + 1) {
        res.push({ d: depth, tag: k.tagName.toLowerCase(), cls: (k.className||'').toString().trim().replace(/\s+/g,' ').slice(0,80),
          w: Math.round(r.width), maxChild: Math.round(maxChild), ws: cs.whiteSpace, txt: (k.textContent||'').trim().replace(/\s+/g,' ').slice(0,40) });
      }
      walk(k, depth + 1);
    }
  };
  walk(main, 0);
  return res.slice(0, 14);
});
out.forEach(x => console.log(`d${x.d} <${x.tag}> w=${x.w} maxChild=${x.maxChild} ws=${x.ws}  ${x.cls}  | "${x.txt}"`));
await b.close();
