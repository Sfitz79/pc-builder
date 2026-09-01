import { chromium } from '@playwright/test';

const browser = await chromium.launch();
const page = await browser.newPage();

page.on('response', async (res) => {
  if (res.url().includes('/builder/')) {
    let body = '';
    try { body = (await res.text()).slice(0, 260); } catch {}
    console.log('NET', res.status(), res.request().method(), res.url().split('?')[0].replace('http://127.0.0.1:8000', ''), '->', body.replace(/\n/g, ' ').slice(0, 240));
  }
});
page.on('console', m => { if (m.type() === 'error') console.log('CONSOLE ERROR:', m.text()); });
page.on('pageerror', e => console.log('PAGE ERROR:', e.message));

await page.goto('http://127.0.0.1:8000/login');
await page.fill('#email', 'demo@pc-tg.co.uk');
await page.fill('#password', 'password');
await page.click('button[type="submit"]');
await page.waitForURL('**/builder');
await page.waitForSelector('[x-data="builderState()"]');
await page.getByRole('button', { name: 'Gaming' }).click();
await page.getByRole('button', { name: 'Generate AI Build' }).click();
await page.waitForTimeout(6000);

const state = await page.evaluate(() => {
  const el = document.querySelector('[x-data="builderState()"]');
  const d = window.Alpine.$data(el);
  return {
    aiCategories: Object.keys(d.aiRecommendation?.components || {}),
    aiCpu: d.aiRecommendation?.components?.cpu,
    aiBoard: d.aiRecommendation?.components?.motherboard,
    selectedCpu: d.selected.cpu,
    selectedGpu: d.selected.gpu,
    fps: d.fpsResults,
    compat: d.compatibility,
  };
});
console.log('STATE', JSON.stringify(state, null, 2));
await browser.close();
