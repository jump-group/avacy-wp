import { test, expect } from '@playwright/test';

/**
 * With the `with-html-forms` blueprint the plugin is configured with the shared
 * Avacy test webspace (`test-production|52fd6a7b-...`), so `EnqueueBanner` should
 * inject the CDN scripts on every front-end page.
 */
test.describe('Avacy banner visibility — with-html-forms blueprint', () => {
  test('front-end home enqueues both oilstub and oil scripts', async ({ page }) => {
    await page.goto('/');

    const oilstub = page.locator('script[src*="test-production.avacy-cdn.com"][src*="oilstub.min.js"]');
    const oil = page.locator('script[src*="test-production.avacy-cdn.com"][src*="oil.min.js"]');

    await expect(oilstub).toHaveCount(1);
    await expect(oil).toHaveCount(1);
  });

  test('oil script is called with the correct team + uuid from webspace key', async ({ page }) => {
    await page.goto('/');

    const oilSrc = await page
      .locator('script[src*="oil.min.js"]')
      .first()
      .getAttribute('src');

    expect(oilSrc).toContain('team=test-production');
    expect(oilSrc).toContain('uuid=52fd6a7b-32b3-49d7-9092-e8afedb6313f');
  });
});

/**
 * Non-regression: the WP Consent API integration must be dormant when the
 * wp-consent-api plugin is NOT installed. The `with-html-forms` blueprint
 * does not install it, so this acts as the control scenario.
 */
test.describe('Non-regression — WP Consent API absent', () => {
  test('adapter JS is not enqueued', async ({ page }) => {
    await page.goto('/');
    const adapter = page.locator('script[src*="wp-consent-api-adapter.js"]');
    await expect(adapter).toHaveCount(0);
  });

  test('front-end loads without PHP errors', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.status()).toBe(200);
    const html = await page.content();
    expect(html).not.toMatch(/Fatal error/i);
    expect(html).not.toMatch(/Parse error/i);
    expect(html).not.toMatch(/Uncaught\s+Error/i);
  });

  test('wp_has_consent and wp_set_consent are not defined', async ({ page }) => {
    await page.goto('/');
    const flags = await page.evaluate(() => ({
      hasConsent: typeof (window as any).wp_has_consent,
      setConsent: typeof (window as any).wp_set_consent,
    }));
    expect(flags.hasConsent).toBe('undefined');
    expect(flags.setConsent).toBe('undefined');
  });
});
