import { test, expect } from '@playwright/test';

test.describe('Public site pages', () => {

  test('homepage loads with hero content and CTAs', async ({ page }) => {
    const resp = await page.goto('/');
    expect(resp?.status()).toBe(200);

    await expect(page).toHaveTitle(/Welcome/);
    await expect(page.getByRole('heading', { name: /Dream Gaming PC/i })).toBeVisible();
    await expect(page.getByText(/AI Powered PC Builder/i)).toBeVisible();

    const startBuilding = page.getByRole('link', { name: /Start Building/i }).first();
    await expect(startBuilding).toBeVisible();
    await expect(startBuilding).toHaveAttribute('href', '/builder');
  });

  test('builder page loads the AI wizard', async ({ page }) => {
    const resp = await page.goto('/builder');
    expect(resp?.status()).toBe(200);

    await expect(page.getByRole('heading', { name: /Build Your Next PC/i })).toBeVisible();
    await expect(page.getByText(/AI Powered Builder/i)).toBeVisible();

    // Purpose selectors
    await expect(page.getByText(/Gaming/i).first()).toBeVisible();
    await expect(page.getByText(/Streaming/i).first()).toBeVisible();

    // Budget + resolution inputs and generate button
    await expect(page.getByPlaceholder(/Budget/i).first()).toBeVisible();
    await expect(page.getByText(/Generate AI Build/i).first()).toBeVisible();
  });

  const staticPages = [
    ['/components', /Components/i],
    ['/prebuilts', /Pre-Builts/i],
    ['/support', /Support/i],
    ['/privacy', /Privacy Policy/i],
    ['/terms', /Terms of Service/i],
    ['/software', /Software/i],
  ];

  for (const [path, titleRe] of staticPages) {
    test(`static page ${path} returns 200 and renders title`, async ({ page }) => {
      const resp = await page.goto(path);
      expect(resp?.status()).toBe(200);
      await expect(page.getByRole('heading', { name: titleRe }).first()).toBeVisible();
    });
  }

  const seoPages = [
    '/best-gaming-pc-under-1000',
    '/best-gaming-pc-under-1500',
    '/best-gaming-pc-under-2000',
    '/best-gaming-pc-under-2500',
    '/best-gaming-pc-under-3000',
    '/best-pc-for-fortnite',
    '/best-pc-for-warzone',
    '/best-pc-for-streaming',
  ];

  for (const path of seoPages) {
    test(`SEO page ${path} returns 200`, async ({ page }) => {
      const resp = await page.goto(path);
      expect(resp?.status()).toBe(200);
    });
  }

  test('sitemap.xml is served', async ({ request }) => {
    const resp = await request.get('/sitemap.xml');
    expect(resp.status()).toBe(200);
    const body = await resp.text();
    expect(body).toContain('<urlset');
    expect(body).toContain('/builder');
  });

  test('robots.txt is served', async ({ request }) => {
    const resp = await request.get('/robots.txt');
    expect(resp.status()).toBe(200);
    const body = await resp.text();
    expect(body).toContain('User-agent:');
  });
});
