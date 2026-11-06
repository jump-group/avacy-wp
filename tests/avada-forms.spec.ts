import { test, expect, APIRequestContext, Page } from '@playwright/test';

/**
 * Usa il blueprint `with-avada-forms`: un form Avada simulato (post type
 * `fusion_form` + shortcode dei campi) e l'hook `fusion_form_submission_data`
 * lanciato dalla mu-plugin `avacy-test-avada.php`, con i dati nella forma di Avada.
 */

const UUID = '11111111-1111-1111-1111-111111111111';

async function call(request: APIRequestContext, path: string, data?: Record<string, unknown>) {
  const res = data ? await request.post(path, { data }) : await request.post(path);
  if (!res.ok()) throw new Error(`${path} failed: ${res.status()} ${await res.text()}`);
  return res;
}

async function consentLog(request: APIRequestContext): Promise<Array<{ body: any }>> {
  return (await (await request.get('/avacy-test-api/state')).json()).consentLog;
}

async function formId(request: APIRequestContext): Promise<number> {
  return (await (await request.get('/avacy-test-avada/form')).json()).id;
}

// L'invio di un form Avada: i valori per nome campo, le checkbox multiple come array.
// hidden: i campi nascosti dalla logica condizionale, che il browser invia comunque
type Avada = {
  formId?: number;
  status?: 'success' | 'error';
  version?: string;
  hidden?: unknown[];
  downgrade?: boolean;
  saasFails?: boolean;
};

async function submit(request: APIRequestContext, data: Record<string, unknown>, avada: Avada = {}) {
  const res = await call(request, '/avacy-test-avada/submit', {
    data,
    hidden: avada.hidden,
    downgrade: avada.downgrade,
    saas_fails: avada.saasFails,
    form_id: avada.formId,
    status: avada.status,
    version: avada.version,
  });
  // nessun avviso PHP: con WP_DEBUG finirebbe nella risposta di Avada e la romperebbe
  expect(await res.json()).toEqual({ ok: true, errors: [] });
}

// Il nome dello switch «Save» e le checkbox dei campi, letti dalla dashboard vera.
async function dashboardRow(page: Page, id: number) {
  await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
  await page.waitForSelector('.wrap:not(.hide)');
  await page.locator('sl-tab:has-text("Consent Archive")').click();
  return page.locator(`tbody[id="avacy-form-avada_forms_${id}"]`);
}

test.describe('Consensi dei form Avada', () => {
  let id: number;
  let enabled: string;
  let fieldOpt: (name: string) => string;
  let identifier: string;

  test.beforeEach(async ({ request }) => {
    await call(request, '/avacy-test-api/reset');
    await call(request, '/avacy-test-api/set', {
      avacy_tenant: 'test-harness',
      avacy_webspace_id: UUID,
      avacy_api_token: 'test-token',
      avacy_enable_preemptive_block: 'off',
    });
    id = await formId(request);
    enabled = `avacy_avada_forms_${id}_radio_enabled`;
    fieldOpt = (name) => `avacy_form_field_avadaforms_${id}_${name}`;
    identifier = `avacy_avada_forms_${id}_form_user_identifier`;
  });

  test('la dashboard elenca i campi dei dati, mai password, upload, captcha e honeypot', async ({ page }) => {
    const row = await dashboardRow(page, id);
    await expect(row.locator('sl-switch[name$="_radio_enabled"]')).toHaveAttribute('name', enabled);
    const fields = await row.locator('.AvacyData__Checkboxes sl-checkbox').allTextContents();
    expect(fields).toEqual(['your_name', 'your_email', 'interests', 'privacy']);
  });

  test('switch acceso: arrivano campi scelti, identificativo e prova', async ({ request }) => {
    await call(request, '/avacy-test-api/set', {
      [enabled]: 'on',
      [fieldOpt('your_name')]: 'on',
      [fieldOpt('interests')]: 'on',
      [fieldOpt('privacy')]: 'on',
      [identifier]: 'your_email',
    });
    await submit(request, {
      your_name: 'Mario',
      your_email: 'mario@example.com',
      user_pass: 'segretissima',
      cv_file: 'https://example.com/cv.pdf',
      interests: ['sport', 'musica'],
      privacy: '1',
    });

    const log = await consentLog(request);
    expect(log).toHaveLength(1);
    const body = log[0].body;
    // retrocompatibilità: senza scelte salvate il payload è quello di sempre
    expect(Object.keys(body)).toEqual(['ip_address', 'consent_type', 'optin', 'consent_data', 'identifier', 'source', 'consent_features', 'html_form']);
    expect(body).toMatchObject({ consent_type: 'form', optin: 'accepted', source: 'plugin', consent_features: ['privacy_policy', 'cookie_policy'] });
    expect(body.identifier).toBe('mario@example.com');
    expect(body.consent_data).toEqual(
      expect.arrayContaining([
        { label: 'your_name', value: 'Mario' },
        { label: 'interests', value: 'sport | musica' },
        { label: 'privacy', value: '1' },
      ]),
    );
    expect(body.consent_data).toHaveLength(3);

    // la prova sono le etichette del form e il testo del consenso, niente HTML
    const proof = JSON.parse(body.html_form);
    expect(proof.map((f: any) => f.name)).toEqual(['your_name', 'your_email', 'interests', 'privacy']);
    expect(proof[0]).toMatchObject({ label: 'Your name', type: 'text' });
    expect(proof[3]).toMatchObject({ type: 'consent', text: 'Accetto l’informativa sulla <a href="/privacy">privacy</a>' });
    // la prova si legge com'è nell'archivio: niente \u2019 né \/
    expect(body.html_form).toContain('Accetto l’informativa sulla <a href=\\"/privacy\\">privacy</a>');
    expect(body.html_form).not.toContain('segretissima');
    expect(JSON.stringify(body)).not.toContain('segretissima');
  });

  test('Avada spento: i suoi form spariscono dalla dashboard, riacceso tornano (#747)', async ({ page, request }) => {
    await call(request, '/avacy-test-avada/plugin', { off: true });
    try {
      await expect(await dashboardRow(page, id)).toHaveCount(0);
    } finally {
      await call(request, '/avacy-test-avada/plugin', { off: false });
    }
    await expect(await dashboardRow(page, id)).toHaveCount(1);
  });

  test('la dashboard offre le caselle del form con la loro obbligatorietà', async ({ page }) => {
    const row = await dashboardRow(page, id);
    const options = row.locator('sl-select[name$="_consent_feature_newsletter"] sl-option');
    // il consenso e il checkbox del form di prova non hanno required: facoltativi
    expect(await options.evaluateAll((os) => os.map((o) => `${o.getAttribute('value')}=${o.getAttribute('data-required')}`)))
      .toEqual(['none=null', 'field:interests=0', 'field:privacy=0']);
  });

  test.describe('caratteristiche di consenso per casella', () => {
    const choose = (key: string, value: string) => ({ [`avacy_avada_forms_${id}_consent_feature_${key}`]: value });

    test.beforeEach(async ({ request }) => {
      await call(request, '/avacy-test-api/set', { [enabled]: 'on', [fieldOpt('your_name')]: 'on', [identifier]: 'your_email' });
    });

    test('privacy sul consenso, cookie in presa visione: non spuntato resta solo cookie_policy', async ({ request }) => {
      await call(request, '/avacy-test-api/set', choose('privacy_policy', 'field:privacy'));
      await submit(request, { your_name: 'Mario', your_email: 'mario@example.com' });
      expect((await consentLog(request))[0].body.consent_features).toEqual(['cookie_policy']);
    });

    test('newsletter sul consenso: spuntato parte, non spuntato no', async ({ request }) => {
      await call(request, '/avacy-test-api/set', choose('newsletter', 'field:privacy'));
      await submit(request, { your_name: 'Mario', your_email: 'mario@example.com', privacy: '1' });
      await submit(request, { your_name: 'Mario', your_email: 'mario@example.com' });

      const log = await consentLog(request);
      expect(log).toHaveLength(2);
      expect(log[0].body.consent_features).toEqual(['privacy_policy', 'cookie_policy', 'newsletter']);
      expect(log[1].body.consent_features).toEqual(['privacy_policy', 'cookie_policy']);
      expect(log[1].body.optin).toBe('accepted');
    });

    test('consenso nascosto dalla logica condizionale: non conta come spuntato', async ({ request }) => {
      await call(request, '/avacy-test-api/set', choose('marketing', 'field:privacy'));
      await submit(request, { your_name: 'Mario', your_email: 'mario@example.com', privacy: '1' }, { hidden: ['privacy'] });
      expect((await consentLog(request))[0].body.consent_features).toEqual(['privacy_policy', 'cookie_policy']);
    });

    test('checkbox a scelta multipla: basta una scelta spuntata', async ({ request }) => {
      await call(request, '/avacy-test-api/set', choose('profiling', 'field:interests'));
      await submit(request, { your_name: 'Mario', your_email: 'mario@example.com', interests: ['sport'] });
      expect((await consentLog(request))[0].body.consent_features).toEqual(['privacy_policy', 'cookie_policy', 'profiling']);
    });

    test('una casella per privacy e cookie: spuntata partono tutte e due', async ({ request }) => {
      await call(request, '/avacy-test-api/set', { ...choose('privacy_policy', 'field:privacy'), ...choose('cookie_policy', 'field:privacy') });
      await submit(request, { your_name: 'Mario', your_email: 'mario@example.com', privacy: '1' });
      expect((await consentLog(request))[0].body.consent_features).toEqual(['privacy_policy', 'cookie_policy']);
    });

    test('lista vuota (privacy e cookie sulla casella, non spuntata): consenso respinto con lista vuota', async ({ request }) => {
      await call(request, '/avacy-test-api/set', { ...choose('privacy_policy', 'field:privacy'), ...choose('cookie_policy', 'field:privacy') });
      await submit(request, { your_name: 'Mario', your_email: 'mario@example.com' });
      const log = await consentLog(request);
      expect(log).toHaveLength(1);
      expect(log[0].body).toMatchObject({ optin: 'rejected', consent_features: '[]' });
    });
  });

  test('switch spento: nessun consenso', async ({ request }) => {
    await call(request, '/avacy-test-api/set', { [enabled]: 'off', [fieldOpt('your_name')]: 'on' });
    await submit(request, { your_name: 'Mario', your_email: 'mario@example.com' });
    expect(await consentLog(request)).toHaveLength(0);
  });

  test('switch mai salvato: nessun consenso', async ({ request }) => {
    await submit(request, { your_name: 'Mario', your_email: 'mario@example.com' });
    expect(await consentLog(request)).toHaveLength(0);
  });

  test('form cancellato con lo switch ancora acceso: il consenso arriva con la prova vuota', async ({ request }) => {
    const gone = 999999;
    await call(request, '/avacy-test-api/set', {
      [`avacy_avada_forms_${gone}_radio_enabled`]: 'on',
      [`avacy_form_field_avadaforms_${gone}_your_name`]: 'on',
    });
    await submit(request, { your_name: 'Mario' }, { formId: gone });

    const log = await consentLog(request);
    expect(log).toHaveLength(1);
    expect(log[0].body.consent_data).toEqual([{ label: 'your_name', value: 'Mario' }]);
    expect(log[0].body.html_form).toBe('[]');
  });

  // l'esito delle azioni del form: un login sbagliato, una registrazione con email gia' usata, una mail non partita
  test('azione del form fallita: nessun consenso', async ({ request }) => {
    await call(request, '/avacy-test-api/set', { [enabled]: 'on', [fieldOpt('your_name')]: 'on' });
    await submit(request, { your_name: 'Mario' }, { status: 'error' });
    expect(await consentLog(request)).toHaveLength(0);
  });

  test('Avada prima della 3.11.6, senza esito delle azioni: il consenso parte subito', async ({ request }) => {
    await call(request, '/avacy-test-api/set', { [enabled]: 'on', [fieldOpt('your_name')]: 'on' });
    await submit(request, { your_name: 'Mario' }, { version: '3.4.1' });
    expect(await consentLog(request)).toHaveLength(1);
  });

  test('campo nascosto dalla logica condizionale: non arriva all\'archivio', async ({ request }) => {
    await call(request, '/avacy-test-api/set', {
      [enabled]: 'on',
      [fieldOpt('your_name')]: 'on',
      [fieldOpt('privacy')]: 'on',
    });
    await submit(request, { your_name: 'Mario', privacy: '1' }, { hidden: ['privacy'] });

    const log = await consentLog(request);
    expect(log).toHaveLength(1);
    // il campo nascosto non c'e' proprio: non sembra un consenso lasciato in bianco
    expect(log[0].body.consent_data).toEqual([{ label: 'your_name', value: 'Mario' }]);
  });

  // gira dopo «switch acceso», che imposta l'identificativo: il reset non deve lasciarlo in giro
  test('senza campo identificativo: il consenso parte con identificativo vuoto', async ({ request }) => {
    await call(request, '/avacy-test-api/set', { [enabled]: 'on', [fieldOpt('your_name')]: 'on' });
    await submit(request, { your_name: 'Mario', your_email: 'mario@example.com' });

    const log = await consentLog(request);
    expect(log).toHaveLength(1);
    expect(log[0].body.identifier ?? null).toBeNull();
  });

  test('un altro plugin trasforma il successo in errore: nessun consenso', async ({ request }) => {
    await call(request, '/avacy-test-api/set', { [enabled]: 'on', [fieldOpt('your_name')]: 'on' });
    await submit(request, { your_name: 'Mario' }, { downgrade: true });
    expect(await consentLog(request)).toHaveLength(0);
  });

  test('il SaaS fallisce durante l\'invio: la risposta di Avada resta intatta', async ({ request }) => {
    await call(request, '/avacy-test-api/set', { [enabled]: 'on', [fieldOpt('your_name')]: 'on' });
    await submit(request, { your_name: 'Mario' }, { saasFails: true });
    expect(await consentLog(request)).toHaveLength(0);
  });

  test('elenco dei campi nascosti costruito a mano: nessun avviso PHP', async ({ request }) => {
    await call(request, '/avacy-test-api/set', { [enabled]: 'on', [fieldOpt('your_name')]: 'on' });
    await submit(request, { your_name: 'Mario' }, { hidden: [['x'], null, 1.5] });
    expect(await consentLog(request)).toHaveLength(1);
  });

  test('salvataggio della dashboard con un form Avada vuoto: nessun avviso PHP', async ({ request }) => {
    const res = await call(request, '/avacy-test-avada/save-dashboard');
    expect(await res.json()).toEqual({ errors: [] });
  });
});
