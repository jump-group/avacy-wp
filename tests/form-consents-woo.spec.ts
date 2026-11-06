import { test, expect, APIRequestContext, Page } from '@playwright/test';

/**
 * Usa il blueprint `with-woo-forms`: checkout classico di WooCommerce con pagamento alla consegna,
 * la pagina dei termini e una casella di consenso facoltativa (`billing_privacy`) aggiunta da una mu-plugin.
 * Il checkout di WooCommerce è un form solo, con id vuoto.
 */

const UUID = '11111111-1111-1111-1111-111111111111';
const TYPE = 'woocommerce_checkout_form';

async function call(request: APIRequestContext, path: string, data?: Record<string, unknown>) {
  const res = data ? await request.post(path, { data }) : await request.post(path);
  if (!res.ok()) throw new Error(`${path} failed: ${res.status()} ${await res.text()}`);
}

async function consentLog(request: APIRequestContext): Promise<Array<{ body: any }>> {
  return (await (await request.get('/avacy-test-api/state')).json()).consentLog;
}

// Prepara il form come dopo un salvataggio e compila il checkout. Le scelte non indicate restano al predefinito.
async function checkout(page: Page, request: APIRequestContext, opts: { privacy: boolean; terms: boolean; choices?: Record<string, string>; identifier?: string }) {
  await call(request, '/avacy-test-api/reset');
  const features: Record<string, string> = {};
  for (const [key, choice] of Object.entries(opts.choices ?? {})) {
    features[`avacy_${TYPE}__consent_feature_${key}`] = choice;
  }
  await call(request, '/avacy-test-api/set', {
    avacy_tenant: 'test-harness',
    avacy_webspace_id: UUID,
    avacy_api_token: 'test-token',
    avacy_enable_preemptive_block: 'off',
    [`avacy_${TYPE}__radio_enabled`]: 'on',
    avacy_form_field_wc_email: 'on',
    avacy_form_field_wc_first_name: 'on',
    ...features,
    ...(opts.identifier !== undefined ? { [`avacy_${TYPE}__form_user_identifier`]: opts.identifier } : {}),
  });
  const { product } = await (await request.get('/avacy-test-woo-info/')).json();
  await page.goto(`/?add-to-cart=${product}`);
  await page.goto('/checkout/');
  await page.fill('#billing_first_name', 'Mario');
  await page.fill('#billing_last_name', 'Rossi');
  await page.selectOption('#billing_country', 'IT');
  await page.fill('#billing_address_1', 'Via Roma 1');
  await page.fill('#billing_postcode', '00100');
  await page.fill('#billing_city', 'Roma');
  await page.selectOption('#billing_state', 'RM');
  await page.fill('#billing_phone', '3331234567');
  await page.fill('#billing_email', 'mario@example.com');
  // WooCommerce ricorda nella sessione la casella dell'ordine precedente: si imposta sempre lo stato voluto
  await page.setChecked('#billing_privacy', opts.privacy);
  await page.setChecked('#terms', opts.terms);
  await page.waitForTimeout(1500); // il checkout ricarica il riepilogo via ajax
  await page.click('#place_order');
}

async function placed(page: Page, request: APIRequestContext) {
  await page.waitForURL(/order-received/, { timeout: 30_000 });
  await page.waitForTimeout(500);
  return consentLog(request);
}

test.describe('Consensi del checkout di WooCommerce', () => {
  // Retrocompatibilità: senza scelte salvate il payload è quello di sempre.
  test('senza scelte salvate: payload di sempre, con la casella facoltativa non spuntata', async ({ page, request }) => {
    await checkout(page, request, { privacy: false, terms: true });
    const log = await placed(page, request);
    expect(log).toHaveLength(1);
    const body = log[0].body;
    expect(Object.keys(body)).toEqual(['ip_address', 'consent_type', 'optin', 'consent_data', 'identifier', 'source', 'consent_features', 'html_form']);
    expect(body.optin).toBe('accepted');
    expect(body.consent_type).toBe('form');
    expect(body.source).toBe('plugin');
    expect(body.consent_features).toEqual(['privacy_policy', 'cookie_policy']);
    expect(body.identifier).toBe('mario@example.com');
    expect(body.consent_data).toEqual([
      { label: 'email', value: 'mario@example.com' },
      { label: 'first_name', value: 'Mario' },
    ]);
  });

  test('newsletter sulla casella facoltativa: parte solo se spuntata', async ({ page, request }) => {
    const choices = { newsletter: 'field:billing_privacy' };
    await checkout(page, request, { privacy: true, terms: true, choices });
    expect((await placed(page, request))[0].body.consent_features).toEqual(['privacy_policy', 'cookie_policy', 'newsletter']);

    await checkout(page, request, { privacy: false, terms: true, choices });
    const log = await placed(page, request);
    expect(log[0].body.optin).toBe('accepted');
    expect(log[0].body.consent_features).toEqual(['privacy_policy', 'cookie_policy']);
  });

  // I termini non stanno nei campi billing né nell'ordine: il plugin li legge dal checkout
  test('una casella (i termini) per privacy e cookie: spuntati, partono; la casella è obbligatoria, senza l\'ordine non parte', async ({ page, request }) => {
    const choices = { cookie_policy: 'field:terms', privacy_policy: 'field:terms' };
    await checkout(page, request, { privacy: false, terms: true, choices });
    expect((await placed(page, request))[0].body.consent_features).toEqual(['privacy_policy', 'cookie_policy']);

    await checkout(page, request, { privacy: false, terms: false, choices });
    await page.waitForTimeout(4000);
    expect(page.url()).not.toMatch(/order-received/);
    expect(await consentLog(request)).toHaveLength(0);
  });

  test('lista vuota (privacy e cookie sulla casella facoltativa, non spuntata): consenso respinto con lista vuota', async ({ page, request }) => {
    await checkout(page, request, { privacy: false, terms: true, choices: { privacy_policy: 'field:billing_privacy', cookie_policy: 'field:billing_privacy' } });
    const log = await placed(page, request);
    expect(log).toHaveLength(1);
    expect(log[0].body).toMatchObject({ optin: 'rejected', consent_features: '[]' });
  });

  // Il campo scelto nella dashboard si salva sotto avacy_woocommerce_checkout_form__form_user_identifier
  test('identificativo scelto nella dashboard: è quello a partire come identifier', async ({ page, request }) => {
    await checkout(page, request, { privacy: false, terms: true, identifier: 'first_name' });
    const log = await placed(page, request);
    expect(log).toHaveLength(1);
    expect(log[0].body.identifier).toBe('Mario');
  });

  test('identificativo scelto ma vuoto nell\'ordine (azienda): torna l\'email', async ({ page, request }) => {
    await checkout(page, request, { privacy: false, terms: true, identifier: 'company' });
    const log = await placed(page, request);
    expect(log[0].body.identifier).toBe('mario@example.com');
  });

  test('identificativo scelto che non esiste tra i dati di fatturazione: torna l\'email', async ({ page, request }) => {
    await checkout(page, request, { privacy: false, terms: true, identifier: 'campo_inesistente' });
    const log = await placed(page, request);
    expect(log[0].body.identifier).toBe('mario@example.com');
  });

  test('la dashboard offre i termini e la casella aggiunta, con la loro obbligatorietà', async ({ page, request }) => {
    await call(request, '/avacy-test-api/reset');
    await call(request, '/avacy-test-api/set', { avacy_tenant: 'test-harness', avacy_webspace_id: UUID, avacy_api_token: 'test-token', avacy_enable_preemptive_block: 'off' });
    await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
    await page.waitForSelector('.wrap:not(.hide)');
    await page.locator('sl-tab:has-text("Consent Archive")').click();
    const options = page.locator('table.AvacyForms sl-select[name$="_consent_feature_newsletter"] sl-option');
    expect(await options.evaluateAll((os) => os.map((o) => `${o.getAttribute('value')}=${o.getAttribute('data-required')}`)))
      .toEqual(['none=null', 'field:billing_privacy=0', 'field:terms=1']);
  });
});
