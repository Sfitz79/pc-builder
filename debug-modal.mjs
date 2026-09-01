import { chromium } from '@playwright/test';

const browser = await chromium.launch();
const page = await browser.newPage();

page.on('console', m => { if (m.type() === 'error') console.log('CONSOLE ERROR:', m.text()); });
page.on('pageerror', e => console.log('PAGE ERROR:', e.message));

await page.goto('http://127.0.0.1:8000/login');
await page.fill('#email', 'demo@pc-tg.co.uk');
await page.fill('#password', 'password');
await page.click('button[type="submit"]');
await page.waitForURL('**/builder');
await page.waitForSelector('[x-data="builderState()"]');
await page.getByRole('button', { name: 'Generate AI Build' }).click();
await page.getByText('CS2', { exact: true }).waitFor({ timeout: 20000 });

const btn = page.getByRole('button', { name: 'Change Motherboard' });
console.log('btn count:', await btn.count());
await btn.click();
await page.waitForTimeout(800);

const state = await page.evaluate(() => {
  const el = document.querySelector('[x-data="builderState()"]');
  const d = window.Alpine.$data(el);
  return {
    componentModal: d.componentModal,
    currentCategory: d.currentCategory,
    board: d.selected.motherboard ? d.selected.motherboard.name : null,
    modalDisplay: getComputedStyle(document.querySelector('[x-show="componentModal"]') || el).display,
  };
});
console.log(JSON.stringify(state, null, 2));
await browser.close();
