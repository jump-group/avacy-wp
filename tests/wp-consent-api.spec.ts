import { test, expect } from '@playwright/test';

/**
 * Verifies the adapter that bridges Avacy CMP (`avacy_consent` event)
 * to WP Consent API (`wp_set_consent`). Uses the `with-wp-consent-api`
 * blueprint (Avacy + WP Consent API + `googlecmp` webspace).
 */

async function spyOnSetConsent(page) {
  await page.evaluate(() => {
    (window as any).__wpCalls = [];
    const orig = (window as any).wp_set_consent;
    (window as any).wp_set_consent = function (category: string, value: string) {
      (window as any).__wpCalls.push({ category, value });
      if (typeof orig === 'function') {
        return orig.apply(this, arguments);
      }
    };
  });
}

async function dispatchAvacyConsent(page, gcm: string[]) {
  await page.evaluate((grants) => {
    const event = new CustomEvent('avacy_consent', { detail: { gcm: grants } });
    window.dispatchEvent(event);
  }, gcm);
}

async function getCalls(page) {
  return page.evaluate(() => (window as any).__wpCalls as Array<{ category: string; value: string }>);
}

test.describe('WP Consent API integration', () => {
  test('adapter JS is enqueued when WP Consent API is active', async ({ page }) => {
    await page.goto('/');
    const script = page.locator('script[src*="wp-consent-api-adapter.js"]');
    await expect(script).toHaveCount(1);
  });

  test('wp_set_consent is available (WP Consent API loaded)', async ({ page }) => {
    await page.goto('/');
    await page.waitForFunction(() => typeof (window as any).wp_set_consent === 'function');
  });

  test('accept all → all categories granted', async ({ page }) => {
    await page.goto('/');
    await page.waitForFunction(() => typeof (window as any).wp_set_consent === 'function');
    await spyOnSetConsent(page);

    await dispatchAvacyConsent(page, [
      'ad_storage',
      'ad_user_data',
      'ad_personalization',
      'analytics_storage',
      'functionality_storage',
      'personalization_storage',
      'security_storage',
    ]);

    const calls = await getCalls(page);
    expect(calls).toEqual(
      expect.arrayContaining([
        { category: 'functional', value: 'allow' },
        { category: 'preferences', value: 'allow' },
        { category: 'statistics', value: 'allow' },
        { category: 'statistics-anonymous', value: 'allow' },
        { category: 'marketing', value: 'allow' },
      ]),
    );
  });

  test('reject all → only functional granted', async ({ page }) => {
    await page.goto('/');
    await page.waitForFunction(() => typeof (window as any).wp_set_consent === 'function');
    await spyOnSetConsent(page);

    await dispatchAvacyConsent(page, []);

    const calls = await getCalls(page);
    expect(calls).toEqual(
      expect.arrayContaining([
        { category: 'functional', value: 'allow' },
        { category: 'preferences', value: 'deny' },
        { category: 'statistics', value: 'deny' },
        { category: 'statistics-anonymous', value: 'deny' },
        { category: 'marketing', value: 'deny' },
      ]),
    );
  });

  test('consent type is forced to opt-in', async ({ page }) => {
    await page.goto('/');
    await page.waitForFunction(() => typeof (window as any).wp_has_consent === 'function');
    const consentType = await page.evaluate(
      () => (window as any).wp_consent_type || (window as any).wp_fallback_consent_type,
    );
    expect(consentType).toBe('optin');
  });

  test('wp_has_consent returns false before any action (opt-in default)', async ({ page }) => {
    await page.goto('/');
    await page.waitForFunction(() => typeof (window as any).wp_has_consent === 'function');
    const marketing = await page.evaluate(() => (window as any).wp_has_consent('marketing'));
    expect(marketing).toBe(false);
  });

  test('after reject-all, wp_has_consent is false except functional', async ({ page }) => {
    await page.goto('/');
    await page.waitForFunction(() => typeof (window as any).wp_has_consent === 'function');
    await dispatchAvacyConsent(page, []);

    const results = await page.evaluate(() => ({
      functional: (window as any).wp_has_consent('functional'),
      preferences: (window as any).wp_has_consent('preferences'),
      statistics: (window as any).wp_has_consent('statistics'),
      marketing: (window as any).wp_has_consent('marketing'),
    }));
    expect(results.functional).toBe(true);
    expect(results.preferences).toBe(false);
    expect(results.statistics).toBe(false);
    expect(results.marketing).toBe(false);
  });

  test('analytics-only consent → statistics allow, marketing deny', async ({ page }) => {
    await page.goto('/');
    await page.waitForFunction(() => typeof (window as any).wp_set_consent === 'function');
    await spyOnSetConsent(page);

    await dispatchAvacyConsent(page, ['analytics_storage']);

    const calls = await getCalls(page);
    expect(calls).toEqual(
      expect.arrayContaining([
        { category: 'functional', value: 'allow' },
        { category: 'statistics', value: 'allow' },
        { category: 'statistics-anonymous', value: 'allow' },
        { category: 'marketing', value: 'deny' },
        { category: 'preferences', value: 'deny' },
      ]),
    );
  });
});
