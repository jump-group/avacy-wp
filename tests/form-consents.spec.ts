import { test, expect, APIRequestContext, Page } from '@playwright/test';

/**
 * Usa il blueprint `with-form-consents`: HTML Forms, Contact Form 7 e WPForms Lite con form veri
 * (la pagina /avacy-form/ e quelle del seed `seed-form-consents.php`). La mu-plugin `avacy-test-harness.php` intercetta la
 * POST dei consensi verso il SaaS e la registra in `consentLog`.
 */

const UUID = '11111111-1111-1111-1111-111111111111';

async function call(request: APIRequestContext, path: string, data?: Record<string, unknown>) {
  const res = data ? await request.post(path, { data }) : await request.post(path);
  if (!res.ok()) throw new Error(`${path} failed: ${res.status()} ${await res.text()}`);
}

async function state(request: APIRequestContext) {
  return (await request.get('/avacy-test-api/state')).json();
}

async function consentLog(request: APIRequestContext): Promise<Array<{ url: string }>> {
  return (await state(request)).consentLog;
}

async function openForm(page: Page) {
  await page.goto('/avacy-form/');
  return page.locator('form.hf-form').getAttribute('data-id');
}

// Il nome dello switch «Save» del form, letto dalla dashboard vera: è quello che salva la dashboard.
async function dashboardSwitchName(page: Page, formId: string | null) {
  await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
  await page.waitForSelector('.wrap:not(.hide)');
  await page.locator('sl-tab:has-text("Consent Archive")').click();
  const group = page.locator(`tbody[id="avacy-form-html_forms_${formId}"]`);
  return group.locator('sl-switch[name$="_radio_enabled"]').getAttribute('name');
}

// L'id del form dall'id del suo blocco nella tabella (avacy-form-{tipo}_{id}).
async function formIdOf(group: ReturnType<Page['locator']>) {
  return ((await group.first().getAttribute('id')) as string).replace(/^avacy-form-[a-z0-9_]+?_(\d+)$/, '$1');
}

async function submitForm(page: Page) {
  await page.fill('form.hf-form input[name=EMAIL]', 'mario@example.com');
  await page.click('form.hf-form input[type=submit]');
  // il plugin invia il consenso dentro la stessa richiesta del form
  await expect(page.locator('form.hf-form .hf-message-success')).toBeVisible();
}

// senza credenziali send() esce prima di inviare: il test passerebbe per il motivo sbagliato
async function setCredentials(request: APIRequestContext) {
  await call(request, '/avacy-test-api/set', {
    avacy_tenant: 'test-harness',
    avacy_webspace_id: UUID,
    avacy_api_token: 'test-token',
    avacy_enable_preemptive_block: 'off',
  });
}

test.describe('Consensi dei form — switch «Save» della dashboard', () => {
  test.beforeEach(async ({ request }) => {
    await call(request, '/avacy-test-api/reset');
    await setCredentials(request);
  });

  test('switch acceso: il consenso arriva al SaaS', async ({ page, request }) => {
    const name = await dashboardSwitchName(page, await openForm(page));
    await call(request, '/avacy-test-api/set', { [name!]: 'on' });
    await openForm(page);
    await submitForm(page);

    const log = await consentLog(request);
    expect(log).toHaveLength(1);
    // la dashboard riscrive l'id del webspace con quello del mock: contano tenant e rotta
    expect(log[0].url).toMatch(/\/test-harness\/v4\/webspaces\/[^/]+\/consents$/);
  });

  test('switch acceso dalla dashboard: il consenso arriva al SaaS', async ({ page, request }) => {
    const id = await openForm(page);
    const name = await dashboardSwitchName(page, id);
    // il JS della dashboard parte dopo 1,5 s: prima i clic non validano le righe
    await page.waitForFunction(() => document.querySelector('.AvacyLoader')?.classList.contains('hidden'));
    const row = page.locator('tbody.AvacyForms__Group', { has: page.locator(`sl-switch[name="${name}"]`) });
    await row.locator('sl-switch').click();
    // la dashboard chiede almeno un campo e il campo che identifica l'utente
    await row.locator('.AvacyData__Checkboxes sl-checkbox').first().click();
    const identifier = row.locator('sl-select[name$="_form_user_identifier"]');
    await identifier.click();
    await identifier.locator('sl-option').first().click();
    await page.locator('sl-button.Submit--Global').click();
    await page.waitForSelector('.wrap:not(.hide)');
    expect((await state(request)).formSwitches[name!]).toBe('on');

    await openForm(page);
    await submitForm(page);
    expect(await consentLog(request)).toHaveLength(1);
  });

  // admin_init gira anche su admin-ajax.php, anche per chi non ha fatto il login:
  // una POST con option_page ma senza permessi e nonce non deve toccare gli switch.
  test('POST anonima con option_page: lo switch «Save» non cambia', async ({ page, request }) => {
    const name = await dashboardSwitchName(page, await openForm(page));
    await call(request, '/avacy-test-api/set', { [name!]: 'on' });

    // un salvataggio vero, privo del suo switch, lo spegnerebbe
    await request.post('/wp-admin/admin-ajax.php', {
      // col campo della tabella dei form: a fermarla devono essere permessi e nonce
      form: { action: 'x', option_page: 'avacy-plugin-settings-group', avacy_forms_table_rendered: '1' },
    });

    expect((await state(request)).formSwitches[name!]).toBe('on');
  });

  test('switch spento: nessun consenso', async ({ page, request }) => {
    const name = await dashboardSwitchName(page, await openForm(page));
    await call(request, '/avacy-test-api/set', { [name!]: 'off' });
    await openForm(page);
    await submitForm(page);

    expect(await consentLog(request)).toHaveLength(0);
  });

  test('switch mai salvato: nessun consenso', async ({ page, request }) => {
    const name = await dashboardSwitchName(page, await openForm(page));
    // il reset ha tolto lo switch del test precedente: qui l'opzione non esiste davvero
    expect((await state(request)).formSwitches[name!]).toBeUndefined();
    await openForm(page);
    await submitForm(page);

    expect(await consentLog(request)).toHaveLength(0);
  });

  // Dopo l'aggiornamento niente migrazione: prima lo switch era ignorato, ora conta solo lo switch.
  test.describe('dopo l\'aggiornamento: conta solo lo switch', () => {
    // arma lo stato «appena aggiornato»: le opzioni vanno scritte PRIMA della prima richiesta dopo il reset
    // (un'eventuale migrazione girerebbe lì). Il nome dello switch si legge prima dalla dashboard vera.
    async function arm(page: Page, request: APIRequestContext, opts: { fieldOn?: boolean; switchValue?: string }) {
      const id = await openForm(page);
      const name = (await dashboardSwitchName(page, id))!;
      await call(request, '/avacy-test-api/reset');
      await setCredentials(request);
      await call(request, '/avacy-test-api/set', {
        ...(opts.switchValue !== undefined ? { [name]: opts.switchValue } : {}),
        ...(opts.fieldOn ? { [`avacy_form_field_htmlforms_${id}_EMAIL`]: 'on' } : {}),
      });
      return name;
    }

    test('form con un campo scelto e switch spento: nessun consenso e lo switch resta spento', async ({ page, request }) => {
      const name = await arm(page, request, { fieldOn: true, switchValue: 'off' });
      await openForm(page);
      expect((await state(request)).formSwitches[name]).toBe('off');

      await submitForm(page);
      expect(await consentLog(request)).toHaveLength(0);
    });

    test('form con un campo scelto e switch mai toccato: nessun consenso e nessuno switch creato', async ({ page, request }) => {
      const name = await arm(page, request, { fieldOn: true });
      await openForm(page);
      expect((await state(request)).formSwitches[name]).toBeUndefined();

      await submitForm(page);
      expect(await consentLog(request)).toHaveLength(0);
    });

    test('form con un campo scelto e switch acceso a mano: il consenso arriva', async ({ page, request }) => {
      await arm(page, request, { fieldOn: true, switchValue: 'on' });
      await openForm(page);
      await submitForm(page);
      expect(await consentLog(request)).toHaveLength(1);
    });
  });
});

// ---------------------------------------------------------------------------------------------
// Caratteristiche di consenso (consent_features) per casella del form — issue #910
// ---------------------------------------------------------------------------------------------

async function openArchive(page: Page) {
  await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
  await page.waitForSelector('.wrap:not(.hide)');
  await page.locator('sl-tab:has-text("Consent Archive")').click();
  // il JS della dashboard parte dopo 1,5 s
  await page.waitForFunction(() => document.querySelector('.AvacyLoader')?.classList.contains('hidden'));
}

type Choice = 'none' | 'ack' | 'box';

// Un form per integrazione, con una casella di consenso facoltativa chiamata `box`.
type Builder = {
  name: string;
  typeSlug: string; // come entra nel nome delle opzioni
  type: string; // come la mostra la dashboard
  path: string;
  box: string;
  boxKey: string; // la chiave stabile della casella nel menu delle caratteristiche (WPForms: l'id del campo)
  formId: (page: Page) => Promise<string | null>;
  fieldOpts: (id: string) => Record<string, string>;
  submit: (page: Page, ticked: boolean) => Promise<void>;
};

const builders: Builder[] = [
  {
    name: 'Contact Form 7',
    typeSlug: 'contact_form_7',
    type: 'Contact Form 7',
    path: '/cf7-form/',
    box: 'privacy',
    boxKey: 'privacy',
    formId: (page) => page.locator('input[name=_wpcf7]').inputValue(),
    fieldOpts: (id) => ({
      [`avacy_form_field_wpcf7_${id}_your_email`]: 'on',
      [`avacy_form_field_wpcf7_${id}_privacy`]: 'on',
    }),
    async submit(page, ticked) {
      await page.fill('input[name=your_email]', 'mario@example.com');
      if (ticked) await page.check('input[name=privacy]');
      await page.click('.wpcf7-submit');
      await expect(page.locator('.wpcf7-response-output')).toContainText(/.+/);
      await page.waitForTimeout(500);
    },
  },
  {
    name: 'WPForms Lite',
    typeSlug: 'wp_forms',
    type: 'WP Forms',
    path: '/wpf-form/',
    box: 'privacy_checkbox', // il nome nei dati: l'etichetta con gli spazi diventati underscore
    boxKey: 'id:3',
    formId: (page) => page.locator('form.wpforms-form').getAttribute('data-formid'),
    fieldOpts: (id) => ({
      [`avacy_form_field_wpforms_${id}_email`]: 'on',
      [`avacy_form_field_wpforms_${id}_privacy_checkbox`]: 'on',
    }),
    async submit(page, ticked) {
      await page.locator('form.wpforms-form').evaluate((f: HTMLFormElement) => (f.noValidate = true));
      await page.fill('input[type=email]', 'mario@example.com');
      if (ticked) await page.locator('form.wpforms-form input[type=checkbox]').first().check();
      await Promise.all([page.waitForLoadState('load'), page.click('form.wpforms-form button[type=submit]')]);
      await page.waitForTimeout(800);
    },
  },
  {
    name: 'HTML Forms',
    typeSlug: 'html_forms',
    type: 'HTML Forms',
    path: '/hf-form/',
    box: 'privacy',
    boxKey: 'privacy',
    formId: (page) => page.locator('form.hf-form').getAttribute('data-id'),
    fieldOpts: (id) => ({
      [`avacy_form_field_htmlforms_${id}_email`]: 'on',
      [`avacy_form_field_htmlforms_${id}_privacy`]: 'on',
    }),
    async submit(page, ticked) {
      await page.fill('form.hf-form input[name=EMAIL]', 'mario@example.com');
      if (ticked) await page.check('form.hf-form input[name=PRIVACY]');
      await page.click('form.hf-form input[type=submit]');
      await expect(page.locator('form.hf-form .hf-message-success')).toBeVisible();
    },
  },
];

const featureOpt = (b: Builder, id: string, key: string) => `avacy_${b.typeSlug}_${id}_consent_feature_${key}`;

// Prepara il form come dopo un salvataggio della dashboard e lo invia. Chi non è in `choices` resta com'è (mai salvato).
async function submitWith(page: Page, request: APIRequestContext, b: Builder, ticked: boolean, choices: Record<string, Choice> = {}) {
  await call(request, '/avacy-test-api/reset');
  await page.goto(b.path);
  const id = (await b.formId(page))!;
  const features: Record<string, string> = {};
  for (const [key, choice] of Object.entries(choices)) {
    features[featureOpt(b, id, key)] = choice === 'box' ? `field:${b.boxKey}` : choice === 'ack' ? 'acknowledged' : 'none';
  }
  await setCredentials(request);
  await call(request, '/avacy-test-api/set', {
    [`avacy_${b.typeSlug}_${id}_radio_enabled`]: 'on',
    ...b.fieldOpts(id),
    ...features,
  });
  await page.goto(b.path);
  await b.submit(page, ticked);
  return consentLog(request) as Promise<Array<{ body: any; headers: any }>>;
}

// Il payload com'e' oggi nel plugin 1.5.0 (prima della #910), campo per campo.
const LEGACY_FEATURES = ['privacy_policy', 'cookie_policy'];
const PAYLOAD_KEYS = ['ip_address', 'consent_type', 'optin', 'consent_data', 'identifier', 'source', 'consent_features', 'html_form'];

for (const b of builders) {
  test.describe(`Caratteristiche di consenso — ${b.name}`, () => {
    // Retrocompatibilità: nessuna configurazione nuova, il payload è quello di sempre.
    for (const ticked of [false, true]) {
      test(`senza scelte salvate, casella ${ticked ? 'spuntata' : 'non spuntata'}: payload di sempre`, async ({ page, request }) => {
        const log = await submitWith(page, request, b, ticked);
        expect(log).toHaveLength(1);
        const body = log[0].body;
        expect(Object.keys(body)).toEqual(PAYLOAD_KEYS);
        expect(body.consent_type).toBe('form');
        expect(body.optin).toBe('accepted');
        expect(body.source).toBe('plugin');
        expect(body.consent_features).toEqual(LEGACY_FEATURES);
        expect(body.ip_address).toBeTruthy();
        // senza campo identificativo configurato: null (WPForms inviava una stringa vuota), e nessun avviso PHP
        expect(body.identifier).toBeNull();
        const st = await state(request);
        expect(st.phpErrors).toEqual([]);
        // #602: il SaaS ricava il dominio del consenso dal Referer
        expect(log[0].headers.Referer).toBe(st.homeUrl);
        expect(typeof body.html_form).toBe('string');
        // i dati scelti: l'email e la casella (il suo valore è vuoto solo se non spuntata)
        const data = body.consent_data;
        expect(data).toHaveLength(2);
        expect(data[0].value).toBe('mario@example.com');
        expect(data[1].label).toBe(b.box);
        expect(data[1].value === '').toBe(!ticked);
      });
    }

    test('newsletter sulla casella: parte solo se spuntata, dopo le altre nell\'ordine della mappa', async ({ page, request }) => {
      const on = await submitWith(page, request, b, true, { newsletter: 'box' });
      expect(on).toHaveLength(1);
      expect(on[0].body.optin).toBe('accepted');
      expect(on[0].body.consent_features).toEqual(['privacy_policy', 'cookie_policy', 'newsletter']);

      const off = await submitWith(page, request, b, false, { newsletter: 'box' });
      expect(off).toHaveLength(1);
      expect(off[0].body.optin).toBe('accepted');
      expect(off[0].body.consent_features).toEqual(LEGACY_FEATURES);
    });

    test('privacy sulla casella, cookie in presa visione: spuntata partono tutte e due, non spuntata solo cookie_policy', async ({ page, request }) => {
      const on = await submitWith(page, request, b, true, { privacy_policy: 'box' });
      expect(on[0].body.consent_features).toEqual(LEGACY_FEATURES);

      const off = await submitWith(page, request, b, false, { privacy_policy: 'box' });
      expect(off).toHaveLength(1);
      expect(off[0].body.consent_features).toEqual(['cookie_policy']);
    });

    test('una casella per privacy e cookie: spuntata partono tutte e due', async ({ page, request }) => {
      const log = await submitWith(page, request, b, true, { privacy_policy: 'box', cookie_policy: 'box' });
      expect(log).toHaveLength(1);
      expect(log[0].body.consent_features).toEqual(LEGACY_FEATURES);
    });

    test('più caratteristiche sulla stessa casella: tutte o nessuna, senza doppioni, nell\'ordine della mappa', async ({ page, request }) => {
      const on = await submitWith(page, request, b, true, { privacy_policy: 'box', profiling: 'box', newsletter: 'box', marketing: 'box', tracking_pixel: 'box', terms_and_conditions: 'box' });
      expect(on[0].body.consent_features).toEqual(['privacy_policy', 'cookie_policy', 'newsletter', 'marketing', 'profiling', 'tracking_pixel', 'terms_and_conditions']);
      const off = await submitWith(page, request, b, false, { privacy_policy: 'box', profiling: 'box', newsletter: 'box', marketing: 'box', tracking_pixel: 'box', terms_and_conditions: 'box' });
      expect(off[0].body.consent_features).toEqual(['cookie_policy']);
    });

    // Nessuna caratteristica accettata: il consenso parte respinto, con la lista vuota ('[]': il corpo form-encoded non porta un array vuoto).
    test('lista vuota (privacy e cookie sulla casella, non spuntata): consenso respinto con lista vuota', async ({ page, request }) => {
      const log = await submitWith(page, request, b, false, { privacy_policy: 'box', cookie_policy: 'box' });
      expect(log).toHaveLength(1);
      expect(log[0].body.optin).toBe('rejected');
      expect(log[0].body.consent_features).toBe('[]');
      expect(Object.keys(log[0].body)).toEqual(PAYLOAD_KEYS);
    });
  });
}

// Il valore della casella spuntata di Contact Form 7 arrivava vuoto: sanitize_text_field riduceva l'array.
test('Contact Form 7: la casella spuntata arriva con il suo valore, non vuota', async ({ page, request }) => {
  const log = await submitWith(page, request, builders[0], true);
  expect(log[0].body.consent_data[1].value).not.toBe('');
});

test.describe('Caratteristiche di consenso — dashboard', () => {
  test.beforeEach(async ({ request }) => {
    await call(request, '/avacy-test-api/reset');
    await setCredentials(request);
  });

  // La riga del form nella tabella, per tipo e nome del form (l'id si legge dalla pagina del form).
  async function formRow(page: Page, b: Builder, path = b.path) {
    await page.goto(path);
    const id = (await b.formId(page))!;
    await openArchive(page);
    return { id, row: page.locator(`tbody[id="avacy-form-${b.typeSlug}_${id}"]`) };
  }

  const featureSelect = (row: ReturnType<Page['locator']>, id: string, b: Builder, key: string) =>
    row.locator(`sl-select[name="${featureOpt(b, id, key)}"]`);

  test('ogni form ha un menu per caratteristica attiva, con i valori predefiniti', async ({ page }) => {
    const b = builders[2];
    const { id, row } = await formRow(page, b);
    // niente intestazioni di colonna: ogni menu porta la sua etichetta
    await expect(page.locator('table.AvacyForms thead')).toHaveCount(0);
    const labels = await row.locator('.AvacyFeatures sl-select').evaluateAll((els) => els.map((e) => e.getAttribute('label')));
    expect(labels).toEqual(['Privacy policy', 'Cookie policy', 'Newsletter', 'Marketing', 'Third-party marketing', 'Profiling', 'Tracking pixel', 'Terms and conditions']);
    expect(labels.join()).not.toMatch(/_/); // niente chiavi con underscore
    await expect(row.locator('.AvacyForms__Label', { hasText: 'Consent features' })).toBeVisible();

    await expect(featureSelect(row, id, b, 'privacy_policy')).toHaveAttribute('value', 'acknowledged');
    await expect(featureSelect(row, id, b, 'cookie_policy')).toHaveAttribute('value', 'acknowledged');
    for (const key of ['newsletter', 'marketing', 'marketing_third_party', 'profiling', 'tracking_pixel', 'terms_and_conditions']) {
      await expect(featureSelect(row, id, b, key)).toHaveAttribute('value', 'none');
    }
    // il menu: privacy e cookie offrono «presa visione» e la casella, le altre «nessun campo» e la casella
    const values = (key: string) => featureSelect(row, id, b, key).locator('sl-option').evaluateAll((os) => os.map((o) => (o as HTMLInputElement).value));
    expect(await values('privacy_policy')).toEqual(['acknowledged', `field:${b.boxKey}`]);
    expect(await values('cookie_policy')).toEqual(['acknowledged', `field:${b.boxKey}`]);
    expect(await values('newsletter')).toEqual(['none', `field:${b.boxKey}`]);
    expect(await values('profiling')).toEqual(['none', `field:${b.boxKey}`]);
    expect(await values('tracking_pixel')).toEqual(['none', `field:${b.boxKey}`]);
    expect(await values('terms_and_conditions')).toEqual(['none', `field:${b.boxKey}`]);
    await page.screenshot({ path: process.env.SHOTS ? `${process.env.SHOTS}/tabella-form.png` : undefined, fullPage: true });
  });

  test('scelta salvata dalla dashboard: resta salvata e non tocca le altre impostazioni del form', async ({ page, request }) => {
    const b = builders[2];
    const { id, row } = await formRow(page, b);
    // come lasciate dalla versione precedente: switch acceso, un campo, il campo identificativo
    const switchName = `avacy_html_forms_${id}_radio_enabled`;
    const fieldName = `avacy_form_field_htmlforms_${id}_email`;
    const identifierName = `avacy_html_forms_${id}_form_user_identifier`;
    await call(request, '/avacy-test-api/set', { [switchName]: 'on', [fieldName]: 'on', [identifierName]: 'email' });
    await page.reload();
    await page.waitForSelector('.wrap:not(.hide)');
    await page.locator('sl-tab:has-text("Consent Archive")').click();
    await page.waitForFunction(() => document.querySelector('.AvacyLoader')?.classList.contains('hidden'));

    const select = featureSelect(row, id, b, 'newsletter');
    await select.click();
    await select.locator(`sl-option[value="field:${b.boxKey}"]`).click();
    await page.locator('sl-button.Submit--Global').click();
    await page.waitForSelector('.wrap:not(.hide)');

    const state = await (await request.get('/avacy-test-api/state')).json();
    expect(state.consentFeatures[featureOpt(b, id, 'newsletter')]).toBe(`field:${b.boxKey}`);
    // le altre caratteristiche non sono state scritte: restano al predefinito
    expect(Object.keys(state.consentFeatures)).toEqual([featureOpt(b, id, 'newsletter')]);
    expect(state.formSwitches[switchName]).toBe('on');
    await page.locator('sl-tab:has-text("Consent Archive")').click();
    await expect(featureSelect(row, id, b, 'newsletter')).toHaveAttribute('value', `field:${b.boxKey}`);
  });

  test('un valore non valido nel salvataggio è ignorato', async ({ page, request }) => {
    const b = builders[2];
    const { id } = await formRow(page, b);
    await page.evaluate(async ({ name }) => {
      const form = document.querySelector('form#avacy-form') as HTMLFormElement;
      const data = new FormData(form);
      data.set(name, 'field:non_esiste');
      await fetch(form.action, { method: 'POST', body: data });
    }, { name: featureOpt(b, id, 'newsletter') });
    const state = await (await request.get('/avacy-test-api/state')).json();
    expect(state.consentFeatures[featureOpt(b, id, 'newsletter')]).toBeUndefined();
  });

  test.describe('avvisi', () => {
    const missingText = 'The linked field is no longer in the form. Choose another one.';
    const warnText = {
      optionalBox: 'This checkbox is not set as required in the form. Make it required in the form builder.',
      requiredBox: 'Required checkbox: this consent must be optional. Make it optional in the form builder.',
    };

    test('predefiniti: nessun avviso e nessuna notice', async ({ page }) => {
      await openArchive(page);
      await expect(page.locator('.AvacyFeatureWarning:not([hidden])')).toHaveCount(0);
      await expect(page.locator('.notice-warning')).toHaveCount(0);
    });

    test('privacy su una casella facoltativa: avviso sotto il menu e notice con il link alla riga', async ({ page, request }) => {
      const b = builders[2];
      const { id } = await formRow(page, b);
      await call(request, '/avacy-test-api/set', { [featureOpt(b, id, 'privacy_policy')]: `field:${b.boxKey}` });
      await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
      await page.waitForSelector('.wrap:not(.hide)');

      const notice = page.locator('.notice-warning');
      await expect(notice).toHaveCount(1);
      await expect(notice.locator('a.AvacyNoticeLink')).toHaveText('HF consensi'); // il titolo del form nel suo builder
      await expect(notice.locator('li')).toHaveText('HF consensi (Privacy policy)');
      await notice.locator('a.AvacyNoticeLink').click();
      const row = page.locator(`tbody[id="avacy-form-html_forms_${id}"]`);
      await expect(row).toBeInViewport();
      await expect(featureSelect(row, id, b, 'privacy_policy').locator('..').locator('.AvacyFeatureWarning')).toHaveText(warnText.optionalBox);
      await page.screenshot({ path: process.env.SHOTS ? `${process.env.SHOTS}/notice-avviso.png` : undefined });
    });

    test('la notice sta solo nella pagina Avacy', async ({ page, request }) => {
      const b = builders[2];
      const { id } = await formRow(page, b);
      await call(request, '/avacy-test-api/set', { [featureOpt(b, id, 'privacy_policy')]: `field:${b.boxKey}` });
      await page.goto('/wp-admin/index.php');
      await expect(page.locator('.notice-warning')).toHaveCount(0);
    });

    test('l\'avviso si aggiorna al cambio del menu, anche prima di salvare', async ({ page }) => {
      const b = builders[2];
      const { id, row } = await formRow(page, b);
      const warning = (key: string) => featureSelect(row, id, b, key).locator('..').locator('.AvacyFeatureWarning');
      // il cambio del menu come lo emette Shoelace: il clic sulla lista è già provato nel test del salvataggio
      const choose = (key: string, value: string) =>
        featureSelect(row, id, b, key).evaluate((el: any, v: string) => {
          el.value = v;
          el.dispatchEvent(new CustomEvent('sl-change', { bubbles: true }));
        }, value);

      await expect(warning('privacy_policy')).toBeHidden();
      await choose('privacy_policy', `field:${b.boxKey}`); // casella facoltativa su una caratteristica obbligatoria
      await expect(warning('privacy_policy')).toHaveText(warnText.optionalBox);
      await choose('privacy_policy', 'acknowledged'); // presa visione: nessun avviso
      await expect(warning('privacy_policy')).toBeHidden();
      await choose('newsletter', `field:${b.boxKey}`); // facoltativa su facoltativa: coerente
      await expect(warning('newsletter')).toBeHidden();
    });

    test('pixel sulla casella di un\'altra caratteristica: riquadro dedicato e notice, aggiornati al cambio del menu', async ({ page, request }) => {
      const b = builders[2];
      const { id } = await formRow(page, b);
      await call(request, '/avacy-test-api/set', {
        [featureOpt(b, id, 'tracking_pixel')]: `field:${b.boxKey}`,
        [featureOpt(b, id, 'newsletter')]: `field:${b.boxKey}`,
      });
      await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
      await page.waitForSelector('.wrap:not(.hide)');
      await expect(page.locator('.notice-warning li')).toHaveText('HF consensi (Tracking pixel)');

      await page.locator('sl-tab:has-text("Consent Archive")').click();
      await page.waitForFunction(() => document.querySelector('.AvacyLoader')?.classList.contains('hidden'));
      const row = page.locator(`tbody[id="avacy-form-html_forms_${id}"]`);
      const pixel = row.locator('.AvacyFeatureWarning--Pixel');
      const choose = (key: string, value: string) =>
        featureSelect(row, id, b, key).evaluate((el: any, v: string) => {
          el.value = v;
          el.dispatchEvent(new CustomEvent('sl-change', { bubbles: true }));
        }, value);
      await expect(pixel).toHaveText('The tracking pixel needs its own checkbox.');
      await expect(pixel).toBeVisible();
      await choose('newsletter', 'none'); // casella solo del pixel
      await expect(pixel).toBeHidden();
      await choose('marketing', `field:${b.boxKey}`);
      await expect(pixel).toBeVisible();
      await choose('tracking_pixel', 'none');
      await expect(pixel).toBeHidden();
    });

    test('casella obbligatoria su una caratteristica facoltativa: avviso sul consenso non libero (HTML Forms)', async ({ page, request }) => {
      const b = builders[2];
      const { id } = await formRow(page, b);
      // il form «obbligatorio» non ha una pagina: se ne legge l'id dalla tabella
      const row = page.locator('tbody.AvacyForms__Group', { hasText: 'HTML Forms' }).filter({ has: page.locator('sl-option[value="field:consenso"]') }).first();
      const reqId = await formIdOf(row);
      await call(request, '/avacy-test-api/set', { [featureOpt(b, reqId, 'marketing')]: 'field:consenso' });
      await page.reload();
      await page.waitForSelector('.wrap:not(.hide)');
      await page.locator('sl-tab:has-text("Consent Archive")').click();
      await expect(page.locator(`tbody[id="avacy-form-html_forms_${reqId}"] .AvacyFeatureWarning:not([hidden])`)).toHaveText(warnText.requiredBox);
      expect(id).not.toBe(reqId);
    });

    test('Contact Form 7: acceptance facoltativo e obbligatorio, secondo l\'opzione optional', async ({ page, request }) => {
      const b = builders[0];
      const { id } = await formRow(page, b);
      // `privacy` ha l'opzione optional (facoltativo); `consenso` no (obbligatorio)
      const optional = page.locator('tbody.AvacyForms__Group', { hasText: 'Contact Form 7' }).filter({ has: page.locator('sl-option[value="field:privacy"]') });
      const required = page.locator('tbody.AvacyForms__Group', { hasText: 'Contact Form 7' }).filter({ has: page.locator('sl-option[value="field:consenso"]') });
      await expect(optional.locator('sl-option[value="field:privacy"]').first()).toHaveAttribute('data-required', '0');
      await expect(required.locator('sl-option[value="field:consenso"]').first()).toHaveAttribute('data-required', '1');
      expect(id).toBeTruthy();
    });

    test('casella collegata che non esiste più: avviso giallo sotto il menu e nella notice, che sparisce al cambio di scelta', async ({ page, request }) => {
      const b = builders[2];
      const { id } = await formRow(page, b);
      await call(request, '/avacy-test-api/set', { [featureOpt(b, id, 'newsletter')]: 'field:casella_sparita' });
      await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
      await page.waitForSelector('.wrap:not(.hide)');
      await expect(page.locator('.notice-warning li')).toHaveText('HF consensi (Newsletter)');

      await page.locator('sl-tab:has-text("Consent Archive")').click();
      await page.waitForFunction(() => document.querySelector('.AvacyLoader')?.classList.contains('hidden'));
      const row = page.locator(`tbody[id="avacy-form-html_forms_${id}"]`);
      const warning = featureSelect(row, id, b, 'newsletter').locator('..').locator('.AvacyFeatureWarning');
      await expect(warning).toHaveText(missingText);
      await expect(warning).toBeVisible();
      // gli altri menu non hanno avvisi
      await expect(row.locator('.AvacyFeatureWarning:not([hidden])')).toHaveCount(1);

      // la scelta salvata non e' toccata dal solo caricamento; scegliendone un'altra l'avviso sparisce
      expect((await state(request)).consentFeatures[featureOpt(b, id, 'newsletter')]).toBe('field:casella_sparita');
      await featureSelect(row, id, b, 'newsletter').evaluate((el: any) => {
        el.value = 'none';
        el.dispatchEvent(new CustomEvent('sl-change', { bubbles: true }));
      });
      await expect(warning).toBeHidden();
    });

    test('casella collegata che non esiste più: il salvataggio resta libero', async ({ page, request }) => {
      const b = builders[2];
      const { id } = await formRow(page, b);
      await call(request, '/avacy-test-api/set', { [featureOpt(b, id, 'newsletter')]: 'field:casella_sparita' });
      await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
      await page.waitForSelector('.wrap:not(.hide)');
      await page.locator('sl-tab:has-text("Consent Archive")').click();
      await page.waitForFunction(() => document.querySelector('.AvacyLoader')?.classList.contains('hidden'));
      const submit = page.locator('sl-button.Submit--Global');
      // una modifica qualsiasi (qui un altro menu, invariato) accende il pulsante: l'avviso non lo blocca
      await featureSelect(page.locator(`tbody[id="avacy-form-html_forms_${id}"]`), id, b, 'marketing').evaluate((el: any) => el.dispatchEvent(new CustomEvent('sl-change', { bubbles: true })));
      await expect(submit).not.toHaveAttribute('disabled', /.*/);
      await submit.click();
      await page.waitForSelector('.wrap:not(.hide)');
      await expect(page.locator('sl-alert[variant=danger]')).toHaveCount(0);
    });

    test('WPForms: la caratteristica resta agganciata alla casella dopo il cambio di label', async ({ page, request }) => {
      const b = builders[1];
      const { id } = await formRow(page, b);
      await call(request, '/avacy-test-api/set', { [featureOpt(b, id, 'newsletter')]: 'field:id:3' });
      await call(request, '/avacy-test-api/wpf-relabel', { form_id: Number(id), field_id: '3', label: 'Altro nome della casella' });
      await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
      await page.waitForSelector('.wrap:not(.hide)');
      await page.locator('sl-tab:has-text("Consent Archive")').click();
      await page.waitForFunction(() => document.querySelector('.AvacyLoader')?.classList.contains('hidden'));
      const row = page.locator(`tbody[id="avacy-form-wp_forms_${id}"]`);
      // il menu mostra la nuova label ma conserva la scelta: nessun avviso, nessuna notice
      const option = row.locator('sl-select[name$="_consent_feature_newsletter"] sl-option[value="field:id:3"]');
      await expect(option).toHaveText('altro nome della casella');
      await expect(featureSelect(row, id, b, 'newsletter')).toHaveAttribute('value', 'field:id:3');
      await expect(page.locator('.AvacyFeatureWarning:not([hidden])')).toHaveCount(0);
      await expect(page.locator('.notice-warning')).toHaveCount(0);

      // e l'invio la riconosce: la casella spuntata accende la caratteristica anche con la label cambiata
      await call(request, '/avacy-test-api/set', {
        [`avacy_wp_forms_${id}_radio_enabled`]: 'on',
        [`avacy_form_field_wpforms_${id}_email`]: 'on',
      });
      await page.goto(b.path);
      await b.submit(page, true);
      const log = await consentLog(request);
      expect(log).toHaveLength(1);
      expect((log[0] as any).body.consent_features).toEqual(['privacy_policy', 'cookie_policy', 'newsletter']);
    });

    test('WPForms: due caselle con la stessa label restano distinte', async ({ page, request }) => {
      await page.goto('/wpf-doppia-form/');
      const id = (await page.locator('form.wpforms-form').getAttribute('data-formid'))!;
      await openArchive(page);
      const row = page.locator(`tbody[id="avacy-form-wp_forms_${id}"]`);
      const options = row.locator('sl-select[name$="_consent_feature_newsletter"] sl-option');
      expect(await options.evaluateAll((os) => os.map((o) => `${(o as HTMLInputElement).value}=${o.textContent}`)))
        .toEqual(['none=No field', 'field:id:2=consenso', 'field:id:3=consenso']);

      // newsletter sulla seconda, marketing sulla prima: spuntando solo la seconda parte solo newsletter
      const b = builders[1];
      await call(request, '/avacy-test-api/set', {
        avacy_tenant: 'test-harness', avacy_webspace_id: UUID, avacy_api_token: 'test-token',
        [`avacy_wp_forms_${id}_radio_enabled`]: 'on',
        [`avacy_form_field_wpforms_${id}_indirizzo_email`]: 'on',
        [featureOpt(b, id, 'newsletter')]: 'field:id:3',
        [featureOpt(b, id, 'marketing')]: 'field:id:2',
      });
      await page.goto('/wpf-doppia-form/');
      await page.locator('form.wpforms-form').evaluate((f: HTMLFormElement) => (f.noValidate = true));
      await page.fill('input[type=email]', 'mario@example.com');
      await page.check('input[name="wpforms[fields][3][]"]');
      await Promise.all([page.waitForLoadState('load'), page.click('form.wpforms-form button[type=submit]')]);
      await page.waitForTimeout(800);
      const log = await consentLog(request);
      expect(log).toHaveLength(1);
      expect((log[0] as any).body.consent_features).toEqual(['privacy_policy', 'cookie_policy', 'newsletter']);
    });

    test('WPForms: identificativo con uno spazio nel nome, nessun falso avviso e identificativo inviato', async ({ page, request }) => {
      await page.goto('/wpf-doppia-form/');
      const id = (await page.locator('form.wpforms-form').getAttribute('data-formid'))!;
      await call(request, '/avacy-test-api/set', {
        avacy_tenant: 'test-harness', avacy_webspace_id: UUID, avacy_api_token: 'test-token',
        [`avacy_wp_forms_${id}_radio_enabled`]: 'on',
        [`avacy_form_field_wpforms_${id}_indirizzo_email`]: 'on',
        [`avacy_wp_forms_${id}_form_user_identifier`]: 'indirizzo_email',
      });
      await openArchive(page);
      await expect(page.locator('.notice-warning')).toHaveCount(0);
      await expect(page.locator(`tbody[id="avacy-form-wp_forms_${id}"] .AvacyFeatureWarning--Identifier`)).toHaveCount(0);

      await page.goto('/wpf-doppia-form/');
      await page.locator('form.wpforms-form').evaluate((f: HTMLFormElement) => (f.noValidate = true));
      await page.fill('input[type=email]', 'mario@example.com');
      await Promise.all([page.waitForLoadState('load'), page.click('form.wpforms-form button[type=submit]')]);
      await page.waitForTimeout(800);
      const log = await consentLog(request);
      expect(log).toHaveLength(1);
      expect((log[0] as any).body.identifier).toBe('mario@example.com');
    });

    test('WPForms: il gdpr-checkbox è sempre obbligatorio, la checkbox facoltativa no', async ({ page }) => {
      await page.goto('/wpf-gdpr-form/');
      const gdprId = (await page.locator('form.wpforms-form').getAttribute('data-formid'))!;
      await page.goto('/wpf-form/');
      const boxId = (await page.locator('form.wpforms-form').getAttribute('data-formid'))!;
      await openArchive(page);
      // il gdpr-checkbox ha id 2 nel suo form, la checkbox facoltativa id 3 nel suo
      await expect(page.locator(`tbody[id="avacy-form-wp_forms_${gdprId}"] sl-option[value="field:id:2"]`).first()).toHaveAttribute('data-required', '1');
      await expect(page.locator(`tbody[id="avacy-form-wp_forms_${boxId}"] sl-option[value="field:id:3"]`).first()).toHaveAttribute('data-required', '0');
    });
  });
});

// ---------------------------------------------------------------------------------------------
// Campo identificativo del form — issue #905
// ---------------------------------------------------------------------------------------------

test.describe('Campo identificativo', () => {
  // Consenso inviato con un identificativo configurato che nel submit non c'è: parte con identifier null, senza avvisi PHP.
  for (const b of builders) {
    test(`${b.name}: identificativo configurato ma assente nel form, il consenso parte con identifier null`, async ({ page, request }) => {
      await call(request, '/avacy-test-api/reset');
      await page.goto(b.path);
      const id = (await b.formId(page))!;
      await setCredentials(request);
      await call(request, '/avacy-test-api/set', {
        [`avacy_${b.typeSlug}_${id}_radio_enabled`]: 'on',
        ...b.fieldOpts(id),
        [`avacy_${b.typeSlug}_${id}_form_user_identifier`]: 'campo_cancellato',
      });
      await page.goto(b.path);
      await b.submit(page, true);
      const log = await consentLog(request);
      expect(log).toHaveLength(1);
      expect(log[0].body.identifier).toBeNull();
      expect((await state(request)).phpErrors).toEqual([]);
    });

    // stato legacy: Save acceso e nessun identificativo salvato (già così prima della #905)
    test(`${b.name}: Save acceso senza identificativo salvato, l'invio continua a funzionare`, async ({ page, request }) => {
      const log = await submitWith(page, request, b, true);
      expect(log).toHaveLength(1);
      expect(log[0].body.identifier).toBeNull();
    });
  }

  // Le due righe HTML Forms del seed: quella con la pagina (casella `privacy`) e quella con la casella obbligatoria.
  async function twoForms(page: Page) {
    await page.goto('/hf-form/');
    const a = (await page.locator('form.hf-form').getAttribute('data-id'))!;
    await openArchive(page);
    const rowB = page.locator('tbody.AvacyForms__Group', { hasText: 'HTML Forms' }).filter({ has: page.locator('sl-option[value="field:consenso"]') }).first();
    const b = await formIdOf(rowB);
    return { a, b };
  }

  const switchOf = (id: string) => `avacy_html_forms_${id}_radio_enabled`;

  test('salvataggio con Save acceso e identificativo vuoto: quel form non cambia, gli altri e il resto della pagina si salvano', async ({ page, request }) => {
    await call(request, '/avacy-test-api/reset');
    await setCredentials(request);
    await call(request, '/avacy-test-api/set', { avacy_show_banner: 'on' });
    const { a, b } = await twoForms(page);
    await page.waitForFunction(() => document.querySelector('.AvacyLoader')?.classList.contains('hidden'));

    // si invia il form della pagina com'è, saltando il controllo del browser (che lo bloccherebbe)
    await page.evaluate(({ a, b }) => {
      const q = (sel: string) => document.querySelector(sel) as any;
      const rowA = q(`tbody[id="avacy-form-html_forms_${a}"]`);
      const rowB = q(`tbody[id="avacy-form-html_forms_${b}"]`);
      rowA.querySelector('sl-switch').checked = true; // Save acceso...
      rowA.querySelector('sl-select[name$="_form_user_identifier"]').value = ''; // ...senza identificativo
      rowA.querySelector('sl-select[name$="_consent_feature_newsletter"]').value = 'field:privacy';
      rowB.querySelector('sl-switch').checked = true;
      rowB.querySelector('sl-select[name$="_form_user_identifier"]').value = 'email';
      rowB.querySelector('.AvacyData__Checkboxes sl-checkbox').checked = true;
      q('sl-checkbox[name=avacy_show_banner]').checked = false; // un'altra impostazione della pagina
      (document.querySelector('form#avacy-form') as HTMLFormElement).submit();
    }, { a, b });
    await page.waitForSelector('.wrap:not(.hide)');

    const alert = page.locator('sl-alert[variant=danger]');
    await expect(alert).toContainText('Form "HF consensi" was not saved: choose the user identifier field.');
    await expect(page.locator('sl-alert[variant=danger]')).toHaveCount(1); // l'altro form non dà errore
    const st = await state(request);
    expect(st.formSwitches[switchOf(a)]).toBeUndefined(); // com'era
    expect(st.consentFeatures[featureOpt(builders[2], a, 'newsletter')]).toBeUndefined();
    expect(st.formSwitches[switchOf(b)]).toBe('on'); // l'altro form è salvato
    expect(st.showBanner).toBe(''); // e il resto della pagina
    await page.screenshot({ path: process.env.SHOTS ? `${process.env.SHOTS}/5-errore-salvataggio-senza-identificativo.png` : undefined });
    await call(request, '/avacy-test-api/set', { avacy_show_banner: 'on' });
  });

  test('salvataggio con Save spento e identificativo vuoto: si salva normalmente', async ({ page, request }) => {
    await call(request, '/avacy-test-api/reset');
    await setCredentials(request);
    const { a } = await twoForms(page);
    await page.waitForFunction(() => document.querySelector('.AvacyLoader')?.classList.contains('hidden'));
    await page.evaluate(({ a }) => {
      const rowA = document.querySelector(`tbody[id="avacy-form-html_forms_${a}"]`) as any;
      rowA.querySelector('sl-select[name$="_consent_feature_newsletter"]').value = 'field:privacy';
      (document.querySelector('form#avacy-form') as HTMLFormElement).submit();
    }, { a });
    await page.waitForSelector('.wrap:not(.hide)');
    await expect(page.locator('sl-alert[variant=danger]')).toHaveCount(0);
    expect((await state(request)).consentFeatures[featureOpt(builders[2], a, 'newsletter')]).toBe('field:privacy');
  });

  test('identificativo salvato che non esiste più: avviso sotto il menu e notice; un identificativo valido non ne ha', async ({ page, request }) => {
    await call(request, '/avacy-test-api/reset');
    await setCredentials(request);
    const { a, b } = await twoForms(page);
    await call(request, '/avacy-test-api/set', {
      [`avacy_html_forms_${a}_form_user_identifier`]: 'email',
      [`avacy_html_forms_${b}_form_user_identifier`]: 'campo_rinominato',
    });
    await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
    await page.waitForSelector('.wrap:not(.hide)');

    const notice = page.locator('.notice-warning li');
    await expect(notice).toHaveCount(1);
    await expect(notice).toHaveText('HF obbligatorio (User identification field)');
    await page.locator('sl-tab:has-text("Consent Archive")').click();
    await expect(page.locator(`tbody[id="avacy-form-html_forms_${b}"] .AvacyFeatureWarning--Identifier`))
      .toHaveText('The saved identifier field is no longer in the form. Choose another one.');
    await expect(page.locator(`tbody[id="avacy-form-html_forms_${a}"] .AvacyFeatureWarning--Identifier`)).toHaveCount(0);
    await page.locator(`tbody[id="avacy-form-html_forms_${b}"]`).scrollIntoViewIfNeeded();
    await page.screenshot({ path: process.env.SHOTS ? `${process.env.SHOTS}/6-avviso-identificativo-mancante.png` : undefined, fullPage: true });
  });
});

// ---------------------------------------------------------------------------------------------
// Interruttore e pulsante «Save changes» della dashboard
// ---------------------------------------------------------------------------------------------

test.describe('Dashboard — switch e «Save changes»', () => {
  const HINT = 'To save, complete the highlighted forms in the Consent Archive tab: choose the user identification field and at least one data field.';

  test.beforeEach(async ({ request }) => {
    await call(request, '/avacy-test-api/reset');
    await setCredentials(request);
  });

  async function hfGroup(page: Page) {
    const id = (await openForm(page))!;
    await openArchive(page);
    return page.locator(`tbody[id="avacy-form-html_forms_${id}"]`);
  }

  // \b non basta: il trattino separa le parole e «warning» troverebbe anche «warning-identifier»
  const WARN_DATA = /(^|\s)warning(\s|$)/;
  const WARN_ID = /(^|\s)warning-identifier(\s|$)/;
  const submit = (page: Page) => page.locator('sl-button.Submit--Global');

  test('il clic sul testo dello switch lo accende', async ({ page }) => {
    const group = await hfGroup(page);
    const sw = group.locator('sl-switch');
    await expect(sw).not.toHaveJSProperty('checked', true);
    await group.getByText('Send to Consent Archive').click();
    await expect(sw).toHaveJSProperty('checked', true);
    await group.getByText('Send to Consent Archive').click();
    await expect(sw).toHaveJSProperty('checked', false);
  });

  test('switch acceso senza identificativo né dati: «Save changes» spento col messaggio; si riattiva completando il form', async ({ page }) => {
    const group = await hfGroup(page);
    const hint = page.locator('.AvacySubmitHint');
    // all'apertura il pulsante è spento finché non cambia qualcosa (com'è sempre stato): qui conta il messaggio
    await expect(hint).toBeHidden();

    await group.locator('sl-switch').click();
    await expect(submit(page)).toHaveAttribute('disabled', '');
    await expect(hint).toBeVisible();
    await expect(hint).toHaveText(HINT);
    await expect(group).toHaveClass(WARN_DATA);
    await expect(group).toHaveClass(WARN_ID);

    // il solo identificativo non basta: manca un dato
    const identifier = group.locator('sl-select[name$="_form_user_identifier"]');
    await identifier.click();
    await identifier.locator('sl-option').first().click();
    await expect(group).not.toHaveClass(WARN_ID);
    await expect(group).toHaveClass(WARN_DATA);
    await expect(submit(page)).toHaveAttribute('disabled', '');

    // con identificativo e dato il form è completo
    await group.locator('.AvacyData__Checkboxes sl-checkbox').first().click();
    await expect(group).not.toHaveClass(WARN_DATA);
    await expect(submit(page)).not.toHaveAttribute('disabled', /.*/);
    await expect(hint).toBeHidden();
  });

  test('switch acceso con un dato ma senza identificativo: «Save changes» spento', async ({ page }) => {
    const group = await hfGroup(page);
    await group.locator('.AvacyData__Checkboxes sl-checkbox').first().click();
    await group.locator('sl-switch').click();
    await expect(group).toHaveClass(WARN_ID);
    await expect(group).not.toHaveClass(WARN_DATA);
    await expect(submit(page)).toHaveAttribute('disabled', '');
    await expect(page.locator('.AvacySubmitHint')).toBeVisible();
    // spento lo switch, il form non conta piu'
    await group.locator('sl-switch').click();
    await expect(submit(page)).not.toHaveAttribute('disabled', /.*/);
    await expect(page.locator('.AvacySubmitHint')).toBeHidden();
  });

  test('al caricamento: switch salvato acceso con un identificativo che non esiste più, «Save changes» spento', async ({ page, request }) => {
    const id = (await openForm(page))!;
    await call(request, '/avacy-test-api/set', {
      [`avacy_html_forms_${id}_radio_enabled`]: 'on',
      [`avacy_form_field_htmlforms_${id}_email`]: 'on',
      [`avacy_html_forms_${id}_form_user_identifier`]: 'campo_rinominato',
    });
    await openArchive(page);
    const group = page.locator(`tbody[id="avacy-form-html_forms_${id}"]`);
    await expect(group).toHaveClass(WARN_ID);
    await expect(submit(page)).toHaveAttribute('disabled', '');
    await expect(page.locator('.AvacySubmitHint')).toBeVisible();

    // il link del messaggio porta alla scheda Consent Archive
    await page.locator('sl-tab:has-text("Cookie banner")').click();
    await page.locator('.AvacySubmitHint a').click();
    await expect(page.locator('sl-tab[panel="consent-archive"]')).toHaveAttribute('active', '');
  });
});

// ---------------------------------------------------------------------------------------------
// HTML Forms: gruppo di caselle `interessi[]` — un campo solo, valore come testo
// ---------------------------------------------------------------------------------------------

test.describe('HTML Forms — gruppo di caselle interessi[]', () => {
  test.beforeEach(async ({ request }) => {
    await call(request, '/avacy-test-api/reset');
    await setCredentials(request);
  });

  async function interessiId(page: Page) {
    await page.goto('/hf-interessi-form/');
    return (await page.locator('form.hf-form').getAttribute('data-id'))!;
  }

  // Il form come dopo un salvataggio: switch acceso, email e interessi tra i dati, email come identificativo.
  async function arm(page: Page, request: APIRequestContext, features: Record<string, string> = {}) {
    const id = await interessiId(page);
    await call(request, '/avacy-test-api/set', {
      [`avacy_html_forms_${id}_radio_enabled`]: 'on',
      [`avacy_form_field_htmlforms_${id}_email`]: 'on',
      [`avacy_form_field_htmlforms_${id}_interessi`]: 'on',
      [`avacy_html_forms_${id}_form_user_identifier`]: 'email',
      ...features,
    });
    return id;
  }

  async function sendInteressi(page: Page, request: APIRequestContext, ticked: string[]) {
    await page.goto('/hf-interessi-form/');
    await page.fill('form.hf-form input[name=EMAIL]', 'mario@example.com');
    for (const v of ticked) await page.check(`form.hf-form input[name="interessi[]"][value=${v}]`);
    await page.click('form.hf-form input[type=submit]');
    await expect(page.locator('form.hf-form .hf-message-success')).toBeVisible();
    return (await consentLog(request)) as Array<{ body: any }>;
  }

  test('la dashboard mostra interessi una volta sola: dati, identificativo e caratteristiche', async ({ page }) => {
    const id = await interessiId(page);
    await openArchive(page);
    const group = page.locator(`tbody[id="avacy-form-html_forms_${id}"]`);
    // dati: email, interessi, privacy (e non tre volte interessi)
    const dataNames = await group.locator('.AvacyData__Checkboxes sl-checkbox').evaluateAll((els) => els.map((e) => e.getAttribute('name')));
    expect(dataNames).toEqual([`avacy_form_field_htmlforms_${id}_email`, `avacy_form_field_htmlforms_${id}_interessi`, `avacy_form_field_htmlforms_${id}_privacy`]);
    // identificativo e caratteristiche: le opzioni arrivano dallo stesso elenco
    const idValues = await group.locator('sl-select[name$="_form_user_identifier"] sl-option').evaluateAll((os) => os.map((o) => (o as HTMLInputElement).value));
    expect(idValues.filter((v) => v === 'interessi')).toHaveLength(1);
    expect(idValues.some((v) => v.includes('[]'))).toBe(false);
    const newsletter = group.locator(`sl-select[name="avacy_html_forms_${id}_consent_feature_newsletter"] sl-option`);
    const values = await newsletter.evaluateAll((os) => os.map((o) => (o as HTMLInputElement).value));
    expect(values.filter((v) => v === 'field:interessi')).toHaveLength(1);
    expect(values.some((v) => v.includes('[]'))).toBe(false);
  });

  test('con interessi tra i dati, l\'invio porta il valore come testo', async ({ page, request }) => {
    await arm(page, request);
    const log = await sendInteressi(page, request, ['newsletter', 'eventi']);
    expect(log).toHaveLength(1);
    const data = log[0].body.consent_data;
    expect(data.find((d: any) => d.label === 'interessi').value).toBe('newsletter | eventi');
    expect(log[0].body.identifier).toBe('mario@example.com');
    expect((await state(request)).phpErrors).toEqual([]);
  });

  test('interessi come identificativo: il valore è il testo delle scelte', async ({ page, request }) => {
    const id = await arm(page, request, {});
    await call(request, '/avacy-test-api/set', { [`avacy_html_forms_${id}_form_user_identifier`]: 'interessi' });
    const log = await sendInteressi(page, request, ['eventi', 'offerte']);
    expect(log[0].body.identifier).toBe('eventi | offerte');
  });

  test('newsletter collegata a interessi: parte se almeno un\'opzione è spuntata, non parte se nessuna', async ({ page, request }) => {
    const id = await interessiId(page);
    const feat = { [`avacy_html_forms_${id}_consent_feature_newsletter`]: 'field:interessi' };
    await arm(page, request, feat);
    const one = await sendInteressi(page, request, ['offerte']);
    expect(one).toHaveLength(1);
    expect(one[0].body.consent_features).toEqual(['privacy_policy', 'cookie_policy', 'newsletter']);

    await call(request, '/avacy-test-api/reset');
    await setCredentials(request);
    await arm(page, request, feat);
    const none = await sendInteressi(page, request, []);
    expect(none).toHaveLength(1);
    expect(none[0].body.consent_features).toEqual(['privacy_policy', 'cookie_policy']);
  });
});

// ---------------------------------------------------------------------------------------------
// HTML Forms: campo nascosto e casella con lo stesso nome
// ---------------------------------------------------------------------------------------------

test.describe('HTML Forms — campo nascosto prima della casella con lo stesso nome', () => {
  test.beforeEach(async ({ request }) => {
    await call(request, '/avacy-test-api/reset');
    await setCredentials(request);
  });

  async function nascostoId(page: Page) {
    await page.goto('/hf-nascosto-form/');
    return (await page.locator('form.hf-form').getAttribute('data-id'))!;
  }

  async function send(page: Page, request: APIRequestContext, ticked: boolean) {
    await page.goto('/hf-nascosto-form/');
    await page.fill('form.hf-form input[name=EMAIL]', 'mario@example.com');
    if (ticked) await page.check('form.hf-form input[type=checkbox][name=OPTIN]');
    await page.click('form.hf-form input[type=submit]');
    await expect(page.locator('form.hf-form .hf-message-success')).toBeVisible();
    return (await consentLog(request)) as Array<{ body: any }>;
  }

  test('la casella resta collegabile a un consenso', async ({ page }) => {
    const id = await nascostoId(page);
    await openArchive(page);
    const newsletter = page.locator(`sl-select[name="avacy_html_forms_${id}_consent_feature_newsletter"] sl-option`);
    expect(await newsletter.evaluateAll((os) => os.map((o) => o.getAttribute('value')))).toEqual(['none', 'field:optin']);
  });

  test('newsletter collegata: parte con la casella spuntata, non parte col solo campo nascosto', async ({ page, request }) => {
    const id = await nascostoId(page);
    const arm = () => call(request, '/avacy-test-api/set', {
      [`avacy_html_forms_${id}_radio_enabled`]: 'on',
      [`avacy_form_field_htmlforms_${id}_email`]: 'on',
      [`avacy_html_forms_${id}_form_user_identifier`]: 'email',
      [`avacy_html_forms_${id}_consent_feature_newsletter`]: 'field:optin',
    });
    await arm();
    const ticked = await send(page, request, true);
    expect(ticked[0].body.consent_features).toEqual(['privacy_policy', 'cookie_policy', 'newsletter']);

    await call(request, '/avacy-test-api/reset');
    await setCredentials(request);
    await arm();
    const unticked = await send(page, request, false);
    expect(unticked[0].body.consent_features).toEqual(['privacy_policy', 'cookie_policy']);
  });
});

// ---------------------------------------------------------------------------------------------
// WPForms: identificativo con l'apostrofo nella label, salvato dalla dashboard
// ---------------------------------------------------------------------------------------------

test.describe('WPForms — identificativo con apostrofo nella label', () => {
  test.beforeEach(async ({ request }) => {
    await call(request, '/avacy-test-api/reset');
    await setCredentials(request);
  });

  test('salvato dalla dashboard: dopo il ricaricamento il form non è rosso e l\'invio porta l\'email', async ({ page, request }) => {
    await page.goto('/wpf-apostrofo-form/');
    const id = (await page.locator('form.wpforms-form').getAttribute('data-formid'))!;
    await openArchive(page);
    const group = page.locator(`tbody[id="avacy-form-wp_forms_${id}"]`);
    await group.locator('sl-switch').click();
    // due dati: una label con spazi e una con apostrofo
    await group.locator('.AvacyData__Checkboxes sl-checkbox', { hasText: 'numero di telefono' }).click();
    await group.locator('.AvacyData__Checkboxes sl-checkbox', { hasText: "nome dell'utente" }).click();
    const identifier = group.locator('sl-select[name$="_form_user_identifier"]');
    await identifier.click();
    await identifier.locator('sl-option', { hasText: "E-mail dell'utente" }).first().click();
    await page.locator('sl-button.Submit--Global').click();
    await page.waitForSelector('.wrap:not(.hide)');

    const saved = (await (await request.get('/avacy-test-api/state')).json()).formSwitches;
    expect(saved[`avacy_wp_forms_${id}_radio_enabled`]).toBe('on');

    // dopo il ricaricamento
    await openArchive(page);
    const reloaded = page.locator(`tbody[id="avacy-form-wp_forms_${id}"]`);
    await expect(reloaded.getAttribute('class')).resolves.not.toMatch(/(^|\s)warning(-identifier)?(\s|$)/);
    await expect(page.locator('.AvacySubmitHint')).toBeHidden();
    await expect(page.locator('.notice-warning')).toHaveCount(0);
    // le due caselle dei dati tornano spuntate
    for (const t of ['numero di telefono', "nome dell'utente"]) {
      await expect(reloaded.locator('.AvacyData__Checkboxes sl-checkbox', { hasText: t })).toHaveAttribute('checked', '');
    }
    // l'identificativo salvato non ha barre davanti all'apostrofo
    const savedValue = (await reloaded.locator('sl-select[name$="_form_user_identifier"]').getAttribute('value'))!;
    expect(savedValue).toContain("'");
    expect(savedValue).not.toContain('\\');
    // «Save changes» non è bloccato dal messaggio: toccato un campo, si accende
    await reloaded.locator('sl-switch').click();
    await reloaded.locator('sl-switch').click();
    await expect(page.locator('sl-button.Submit--Global')).not.toHaveAttribute('disabled', /.*/);

    // l'invio del form porta l'email come identificativo
    await call(request, '/avacy-test-api/set', { avacy_tenant: 'test-harness', avacy_webspace_id: UUID, avacy_api_token: 'test-token' });
    await page.goto('/wpf-apostrofo-form/');
    await page.locator('form.wpforms-form').evaluate((f: HTMLFormElement) => (f.noValidate = true));
    await page.fill('input[type=email]', 'mario@example.com');
    await page.locator('form.wpforms-form input[type=text]').nth(0).fill('0612345678');
    await page.locator('form.wpforms-form input[type=text]').nth(1).fill('Mario');
    await Promise.all([page.waitForLoadState('load'), page.click('form.wpforms-form button[type=submit]')]);
    await page.waitForTimeout(800);
    const log = await consentLog(request);
    expect(log).toHaveLength(1);
    expect((log[0] as any).body.identifier).toBe('mario@example.com');
    expect((log[0] as any).body.consent_data).toEqual([
      { label: 'numero_di_telefono', value: '0612345678' },
      { label: "nome_dell'utente", value: 'Mario' },
    ]);
  });
});

// Fino alla 1.4.x il menu salvava la label con gli spazi: dopo l'aggiornamento il campo resta scelto e l'invio porta l'email
test('WPForms — identificativo salvato con gli spazi dalla 1.4.x: resta scelto e l\'invio porta l\'email', async ({ page, request }) => {
  await call(request, '/avacy-test-api/reset');
  await setCredentials(request);
  await page.goto('/wpf-apostrofo-form/');
  const id = (await page.locator('form.wpforms-form').getAttribute('data-formid'))!;
  await call(request, '/avacy-test-api/set', {
    [`avacy_wp_forms_${id}_radio_enabled`]: 'on',
    [`avacy_form_field_wpforms_${id}_numero_di_telefono`]: 'on',
    [`avacy_wp_forms_${id}_form_user_identifier`]: "e-mail dell'utente",
  });

  await openArchive(page);
  const group = page.locator(`tbody[id="avacy-form-wp_forms_${id}"]`);
  const identifier = group.locator('sl-select[name$="_form_user_identifier"]');
  await expect(identifier).toHaveAttribute('value', "e-mail_dell'utente");
  expect(await identifier.locator('sl-option').evaluateAll((os) => os.map((o) => o.getAttribute('value')))).toContain("e-mail_dell'utente");
  // il campo risulta scelto: «Save changes» si accende appena si tocca qualcosa
  await group.locator('sl-switch').click();
  await group.locator('sl-switch').click();
  await expect(page.locator('sl-button.Submit--Global')).not.toHaveAttribute('disabled', /.*/);

  await page.goto('/wpf-apostrofo-form/');
  await page.locator('form.wpforms-form').evaluate((f: HTMLFormElement) => (f.noValidate = true));
  await page.fill('input[type=email]', 'mario@example.com');
  await page.locator('form.wpforms-form input[type=text]').nth(0).fill('0612345678');
  await Promise.all([page.waitForLoadState('load'), page.click('form.wpforms-form button[type=submit]')]);
  await page.waitForTimeout(800);
  const log = await consentLog(request);
  expect(log).toHaveLength(1);
  expect((log[0] as any).body.identifier).toBe('mario@example.com');
});

// #743: un form con una data vecchia non deve decidere la cartella di un upload da Media
test('upload da Media: il file va nella cartella del mese corrente, non in quella di un form (#747)', async ({ page }) => {
  // un PNG da 1x1 pixel
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
  await page.goto('/wp-admin/media-new.php?browser-uploader');
  await page.setInputFiles('input#async-upload', { name: 'avacy-upload-747.png', mimeType: 'image/png', buffer: png });
  await Promise.all([page.waitForURL(/upload\.php/), page.click('#html-upload')]);

  const [media] = await (await page.request.get('/wp-json/wp/v2/media?search=avacy-upload-747&orderby=id&order=desc&per_page=1')).json();
  const month = new Date().toISOString().slice(0, 7).replace('-', '/');
  expect(media.source_url).toContain(`/uploads/${month}/`);
});

// Il SaaS non riconosce più l'Access Key: la pagina la cancella e la tabella dei form sparisce.
// Il salvataggio con la chiave reinserita non deve spegnere i form che nel POST non c'erano.
test('Access Key cancellata e reinserita: la configurazione dei form resta quella di prima', async ({ page, request }) => {
  await call(request, '/avacy-test-api/reset');
  await setCredentials(request);
  const id = (await openForm(page))!;
  const switchOpt = `avacy_html_forms_${id}_radio_enabled`;
  const fieldOpt = `avacy_form_field_htmlforms_${id}_email`;
  const identifierOpt = `avacy_html_forms_${id}_form_user_identifier`;
  await call(request, '/avacy-test-api/set', { [switchOpt]: 'on', [fieldOpt]: 'on', [identifierOpt]: 'email' });

  await call(request, '/avacy-test-api/set', { avacy_test_scenario: 'token_denied' });
  await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
  await page.waitForSelector('.wrap:not(.hide)');
  expect((await state(request)).apiToken).toBe('');
  await expect(page.locator('table.AvacyForms')).toHaveCount(0);

  // il titolare incolla la chiave nuova e salva dalla pagina vera
  await call(request, '/avacy-test-api/set', { avacy_test_scenario: 'vendor_with_rule' });
  await page.waitForFunction(() => document.querySelector('.AvacyLoader')?.classList.contains('hidden'));
  await page.locator('sl-input[name=avacy_api_token] input').fill('new-token');
  await page.locator('sl-button.Submit--Global').click();
  await page.waitForSelector('.wrap:not(.hide)');

  const st = await state(request);
  expect(st.apiToken).toBe('new-token');
  expect.soft(st.formSwitches[switchOpt]).toBe('on');
  expect.soft(st.formDataOptions[fieldOpt]).toBe('on');
  expect.soft(st.formDataOptions[identifierOpt]).toBe('email');

  await openForm(page);
  await submitForm(page);
  expect(await consentLog(request)).toHaveLength(1);
});
