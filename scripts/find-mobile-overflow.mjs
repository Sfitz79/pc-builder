import { chromium } from 'playwright';
const b = await chromium.launch();
const p = await (await b.newContext({ viewport: { width: 360, height: 800 } })).newPage();
await p.goto('https://pctechguy.app/builder', { waitUntil: 'domcontentloaded', timeout: 90000 });
await p.waitForTimeout(6000);
const out = await p.evaluate(() => {
  const vw = document.documentElement.clientWidth;
  const bad = [];
  for (const el of document.querySelectorAll('body *')) {
    const r = el.getBoundingClientRect();
    if (r.right > vw + 1 || r.width > vw + 1) {
      const cs = getComputedStyle(el);
      if (cs.position === 'fixed' && r.width <= vw + 1) continue;
      bad.push({
        tag: el.tagName.toLowerCase(),
        cls: (el.className || '').toString().trim().replace(/\s+/g,' ').slice(0,70),
        w: Math.round(r.width), right: Math.round(r.right), left: Math.round(r.left),
        text: (el.textContent||'').trim().replace(/\s+/g,' ').slice(0,30),
      });
    }
  }
  return { vw, scrollW: document.documentElement.scrollWidth, bad: bad.slice(0, 12) };
});
console.log('\nviewport', out.vw, ' scrollWidth', out.scrollW);
out.bad.forEach(x => console.log(`  <${x.tag}> w=${x.w} right=${x.right} left=${x.left}  ${x.cls}  | "${x.text}"`));
await b.close();
