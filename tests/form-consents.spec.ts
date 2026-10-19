import { test, expect, APIRequestContext, Page } from '@playwright/test';

/**
 * Usa il blueprint `with-form-consents`: HTML Forms con un form vero nella
 * pagina /avacy-form/. La mu-plugin `avacy-test-harness.php` intercetta la
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
  await page.locator('sl-tab:has-text("Consent Solution")').click();
  const row = page.locator('table.AvacyForms tr', { hasText: 'HTML Forms' }).filter({ has: page.locator(`td:text-is("${formId}")`) });
  return row.locator('sl-switch[name$="_radio_enabled"]').getAttribute('name');
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
    const row = page.locator('table.AvacyForms tr', { has: page.locator(`sl-switch[name="${name}"]`) });
    await row.locator('sl-switch').click();
    // la dashboard chiede almeno un campo e il campo che identifica l'utente
    await row.locator('sl-details').click();
    await row.locator('sl-checkbox').first().click();
    await row.locator('sl-select').click();
    await row.locator('sl-option').first().click();
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
      form: { action: 'x', option_page: 'avacy-plugin-settings-group' },
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
