import { test, expect, request as pwRequest } from '@playwright/test';
import { assertLocalUrl, asProbe } from './support/local-only';
import {
  LOCAL_API, env, browserBlocked, guardBrowser, adminPage, saveDashboard, control, wpState,
  configureForm, submitCf7, findConsent, archived, tinker,
} from './support/helpers';

/**
 * Viaggio del consenso: form CF7 → plugin WP → SaaS locale (stack e2e) → archivio.
 * Niente finto SaaS. Si lancia con `yarn test:plugin-saas` (run.sh): stack, dati, Playground, pulizia.
 * I test sono in serie e condividono lo stesso sito: l'ordine conta.
 */

let forms: Record<string, number> = {};
const FORM = 'cf7-saas-local'; // nome, email, privacy, newsletter
const SOLO_EMAIL = 'cf7-solo-email'; // email, privacy

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ request }) => {
  forms = (await wpState(request)).forms;
  expect(forms[FORM]).toBeGreaterThan(0);
  expect(forms[SOLO_EMAIL]).toBeGreaterThan(0);
});

test.beforeEach(async ({ context }) => {
  await guardBrowser(context);
});

test('1. sicurezza: host locali, produzione bloccata da WordPress e dal runner', async ({ request }) => {
  const s = await wpState(request);
  expect(s.api).toBe(LOCAL_API);
  expect(s.cdn).toBe('avacy.test');
  expect(s.blockExternal).toBe(true);

  // WordPress: le chiamate verso la produzione tornano tutte errore del blocco
  const probe = await (await control(request, 'probe')).json();
  expect(probe).toHaveLength(4);
  for (const p of probe) {
    expect(p.status, p.url).toBeNull();
    expect(p.error, p.url).toMatch(/avacy_guard_blocked|bloccato/);
  }

  // runner Node: URL non locali rifiutati, e socket verso IP non locali chiusi prima di partire
  // (192.0.2.1 e' un indirizzo di documentazione, RFC 5737: non e' instradato, nessun rischio)
  expect(() => assertLocalUrl('https://api.avacy.eu/', 'prova')).toThrow(/non è locale/);
  await asProbe(async () => {
    const api = await pwRequest.newContext();
    await expect(api.get('http://192.0.2.1/', { timeout: 5_000 })).rejects.toThrow(/avacy_node_guard/);
    await api.dispose();
  });
});

test('2. collegamento: Webspace Key e Access Key validate dal SaaS locale', async ({ page, request }) => {
  await adminPage(page);
  await page.locator('sl-input[name=avacy_webspace_key] input').fill(`${env('SAAS_TENANT')}|${env('SAAS_WEBSPACE_UUID')}`);
  await saveDashboard(page);
  await expect.poll(async () => (await wpState(request)).webspaceId).toBe(env('SAAS_WEBSPACE_ID'));

  // l'Access Key sta nella scheda Consent Archive, che compare a sito collegato
  await adminPage(page);
  await page.locator('sl-tab:has-text("Consent Archive")').click();
  await page.locator('sl-input[name=avacy_api_token] input').fill(env('SAAS_ACCESS_KEY'));
  await saveDashboard(page);
  await expect.poll(async () => (await wpState(request)).apiToken).toBe(env('SAAS_ACCESS_KEY'));
});

test('3. form con email identificativo e un dato: consenso in archivio con l\'email giusta', async ({ page }) => {
  await configureForm(page, forms[FORM], { identifier: 'your_email', fields: { your_name: true, your_email: false, privacy: false, newsletter: false } });
  const email = `c3-${Date.now()}@example.com`;
  const sent = await submitCf7(page, FORM, { your_name: 'Caso Tre', your_email: email }, ['privacy']);
  expect(sent.statuses).toEqual(['201']);

  const c = await archived(email);
  expect(c.consent_type).toBe('form');
  expect(c.consent_data).toEqual([{ label: 'your_name', value: 'Caso Tre' }]);
  // privacy e cookie partono sempre («presa visione»); la newsletter no, non e' collegata
  expect(c.consent_features).toEqual(expect.arrayContaining(['privacy_policy', 'cookie_policy']));
  expect(c.consent_features).not.toContain('newsletter');
});

test('4. casella collegata alla newsletter: la caratteristica c\'è solo se spuntata', async ({ page }) => {
  await configureForm(page, forms[FORM], {
    identifier: 'your_email',
    fields: { your_name: true },
    features: { newsletter: 'field:newsletter' },
  });

  const ticked = `c4-si-${Date.now()}@example.com`;
  expect((await submitCf7(page, FORM, { your_name: 'Caso Quattro', your_email: ticked }, ['privacy', 'newsletter'])).statuses).toEqual(['201']);
  expect((await archived(ticked)).consent_features).toContain('newsletter');

  const unticked = `c4-no-${Date.now()}@example.com`;
  expect((await submitCf7(page, FORM, { your_name: 'Caso Quattro', your_email: unticked }, ['privacy'])).statuses).toEqual(['201']);
  expect((await archived(unticked)).consent_features).not.toContain('newsletter');
});

// caso 5: identificativo senza campi dati. La dashboard 1.5.x non lo salva col pulsante,
// ma lo stato esiste (Invio nel form, o salvato prima della 1.5.0): si scrive a mano.
let noDataEmail = '';
let noDataStatuses: string[] = [];

test('5a. identificativo senza dati: il plugin invia l\'identificativo e nessun consent_data', async ({ page, request }) => {
  const set = await control(request, `set-form?form=${SOLO_EMAIL}&ident=your_email&fields=`);
  expect(set.ok(), `set-form HTTP ${set.status()}`).toBeTruthy();
  expect((await set.json()).formId, `set-form: form ${SOLO_EMAIL} non trovato`).toBeGreaterThan(0);
  noDataEmail = `c5-${Date.now()}@example.com`;
  const sent = await submitCf7(page, SOLO_EMAIL, { your_email: noDataEmail }, ['privacy']);
  expect(sent.bodies).toHaveLength(1);
  expect(sent.bodies[0].keys).toContain('identifier');
  expect(sent.bodies[0].encoded).toContain(`identifier=${encodeURIComponent(noDataEmail)}`);
  // un array vuoto sparisce dal corpo form-encoded: la chiave non arriva al SaaS
  expect(sent.bodies[0].encoded).not.toContain('consent_data');
  noDataStatuses = sent.statuses;
});

// Con identifier il SaaS accetta il form anche senza consent_data (PR #1098).
test('5b. identificativo senza dati: il consenso arriva in archivio', async () => {
  expect(noDataStatuses).toEqual(['201']);
  expect(await findConsent(noDataEmail)).not.toBeNull();
});

test('6. Access Key cambiata dopo la configurazione: il consenso non arriva', async ({ page }) => {
  // nuova chiave creata sul SaaS: vale solo l'ultima, il plugin tiene la vecchia
  const ws = env('SAAS_WEBSPACE_ID');
  const fresh = tinker(`tenancy()->initialize('${env('SAAS_TENANT')}'); $w=App\\WebSpace::find(${ws}); echo $w->createToken('rotated', ['domains_groups:${ws}'])->plainTextToken;`);
  expect(fresh).toMatch(/^\d+\|/);

  const email = `c6-${Date.now()}@example.com`;
  const sent = await submitCf7(page, FORM, { your_name: 'Caso Sei', your_email: email }, ['privacy']);
  // comportamento di oggi: 403 e nessun segnale all'admin o al visitatore. Con P.8 diventera' un avviso.
  expect(sent.statuses).toEqual(['403']);
  expect(await findConsent(email)).toBeNull();
});

test.afterAll(async () => {
  // il browser non deve aver nemmeno tentato host di produzione di Avacy
  console.log('host bloccati dal browser:', [...new Set(browserBlocked)].join(', ') || 'nessuno');
  expect(browserBlocked.filter((h) => /avacy\.(eu|com)$|avacy-cdn\.com$|avacysolution\.com$/.test(h))).toEqual([]);
});
