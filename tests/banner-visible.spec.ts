import { test, expect } from '@playwright/test';

/**
 * With the `with-html-forms` blueprint the plugin is configured with the shared
 * Avacy test webspace (`test-production|52fd6a7b-...`), so `EnqueueBanner` should
 * inject the CDN scripts on every front-end page.
 */
test.describe('Avacy banner visibility — with-html-forms blueprint', () => {
  // Link unico: il plugin non sceglie piu' tra OIL e v3, lo fa la CDN.
  test('front-end home enqueues the single v3 loader tag, not OIL', async ({ request }) => {
    // L'HTML che il server stampa, non il DOM dopo l'esecuzione: il loader
    // vero, su un webspace v2, inietta OIL da solo, ed e` giusto cosi`.
    const html = await (await request.get('/')).text();

    expect(html).toContain('test-production.avacy-cdn.com/v3/avacy-cmp-web.js');
    expect(html).not.toContain('oilstub.min.js');
    expect(html).not.toContain('oil.min.js');
  });

  test('loader is called with the webspace uuid from the webspace key', async ({ page }) => {
    await page.goto('/');

    const src = await page.locator('script[src*="/v3/avacy-cmp-web.js"]').first().getAttribute('src');

    expect(src).toBe('https://test-production.avacy-cdn.com/v3/avacy-cmp-web.js?webspace=52fd6a7b-32b3-49d7-9092-e8afedb6313f');
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
