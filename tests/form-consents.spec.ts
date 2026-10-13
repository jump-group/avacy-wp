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

test.describe('Consensi dei form — switch «Save» della dashboard', () => {
  test.beforeEach(async ({ request }) => {
    await call(request, '/avacy-test-api/reset');
    // senza credenziali send() esce prima di inviare: il test passerebbe per il motivo sbagliato
    await call(request, '/avacy-test-api/set', {
      avacy_tenant: 'test-harness',
      avacy_webspace_id: UUID,
      avacy_api_token: 'test-token',
      avacy_enable_preemptive_block: 'off',
    });
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

  // Migrazione una tantum: prima lo switch era ignorato, quindi ogni form con un campo scelto inviava.
  test.describe('migrazione degli switch dopo l\'aggiornamento', () => {
    // arma lo stato «appena aggiornato»: il nome dello switch si legge dalla dashboard, poi si riscrivono le opzioni
    async function arm(page: Page, request: APIRequestContext, opts: { fieldOn?: boolean; fieldOff?: boolean; switchValue: string; migrated: string }) {
      const id = await openForm(page);
      const name = (await dashboardSwitchName(page, id))!;
      const field = `avacy_form_field_htmlforms_${id}_EMAIL`;
      await call(request, '/avacy-test-api/set', {
        [name]: opts.switchValue,
        ...(opts.fieldOn ? { [field]: 'on' } : {}),
        ...(opts.fieldOff ? { [field]: 'off' } : {}),
        avacy_form_switch_migrated: opts.migrated,
      });
      return name;
    }

    test('form con un campo scelto e switch spento: la migrazione lo accende e il consenso arriva', async ({ page, request }) => {
      const name = await arm(page, request, { fieldOn: true, switchValue: 'off', migrated: '0' });
      // nessuna richiesta è passata dopo il set: la migrazione non è ancora girata
      expect((await state(request)).formSwitches[name]).toBe('off');

      await openForm(page);
      const after = await state(request);
      expect(after.formSwitches[name]).toBe('on');
      expect(after.switchMigrated).toBe('1');

      await submitForm(page);
      expect(await consentLog(request)).toHaveLength(1);
    });

    test('form senza campi scelti: lo switch resta spento e non parte nulla', async ({ page, request }) => {
      // un campo presente ma spento non conta come scelto
      const name = await arm(page, request, { fieldOff: true, switchValue: 'off', migrated: '0' });
      await openForm(page);
      const after = await state(request);
      expect(after.formSwitches[name]).toBe('off');
      expect(after.switchMigrated).toBe('1');

      await submitForm(page);
      expect(await consentLog(request)).toHaveLength(0);
    });

    test('form che i detector non trovano: lo switch si accende dalle opzioni dei campi', async ({ page, request }) => {
      // id inesistenti: nessun form vero li ha, e nomi di campo con underscore
      await call(request, '/avacy-test-api/set', {
        avacy_form_field_wpcf7_9991_your_email: 'on',
        avacy_form_field_elementorforms_ab12cd3_first_name: 'on',
        avacy_form_field_wc__billing_email: 'on',
        avacy_form_field_wpforms_9992_email: 'off',
        avacy_form_switch_migrated: '0',
      });
      await openForm(page);
      const after = (await state(request)).formSwitches;
      expect(after.avacy_contact_form_7_9991_radio_enabled).toBe('on');
      expect(after.avacy_elementor_forms_ab12cd3_radio_enabled).toBe('on');
      expect(after.avacy_woocommerce_checkout_form__radio_enabled).toBe('on');
      expect(after.avacy_wp_forms_9992_radio_enabled).toBeUndefined();
    });

    test('migrazione già fatta: uno switch spento dal cliente resta spento', async ({ page, request }) => {
      const name = await arm(page, request, { fieldOn: true, switchValue: 'off', migrated: '1' });
      await openForm(page);
      expect((await state(request)).formSwitches[name]).toBe('off');

      await submitForm(page);
      expect(await consentLog(request)).toHaveLength(0);
    });
  });
});
