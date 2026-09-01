import { test, expect } from '@playwright/test';

test.describe('Builder functionality (live)', () => {

  test('catalog JSON endpoint returns usable component data', async ({ request }) => {
    const resp = await request.get('/builder/catalog');
    expect(resp.status()).toBe(200);

    const catalog = await resp.json();
    expect(catalog).toBeTruthy();

    // Core build categories must be present with non-empty arrays.
    for (const key of ['cpu', 'motherboard', 'gpu', 'ram', 'storage', 'psu', 'case', 'cooler']) {
      expect(Array.isArray(catalog[key]), `catalog.${key} should be an array`).toBe(true);
      expect(catalog[key].length, `catalog.${key} should not be empty`).toBeGreaterThan(0);
    }

    // Representative entry carries the fields the UI renders.
    const cpu = catalog.cpu[0];
    expect(cpu).toMatchObject({ id: expect.any(Number), name: expect.any(String), slug: expect.any(String) });
    expect(typeof cpu.price).toBe('number');
  });

  test('AI build generation returns a recommendation and renders FPS estimates', async ({ page }) => {
    await page.goto('/builder');
    await expect(page.getByRole('heading', { name: /Build Your Next PC/i })).toBeVisible();

    // Choose a purpose.
    await page.getByText(/Gaming/i).first().click();

    // Set a budget.
    await page.getByPlaceholder(/Budget/i).first().fill('1200');

    // Trigger generation.
    await page.getByText(/Generate AI Build/i).first().click();

    // Success text includes "Build generated" with a total figure.
    await expect(page.getByText(/Build generated/i)).toBeVisible({ timeout: 45000 });

    // Selected parts render in the summary.
    await expect(page.getByText(/Expected FPS/i).first()).toBeVisible();
  });
});