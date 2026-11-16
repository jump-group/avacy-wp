import { expect, APIRequestContext, BrowserContext, Page, request as pwRequest } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { appendFileSync, existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { LOG_DIR, assertLocalUrl, isLocalHost } from './local-only';

export const env = (name: string) => {
  const v = process.env[name];
  if (!v) throw new Error(`manca ${name}: lancia il test con yarn test:plugin-saas`);
  return v;
};

export const LOCAL_API = assertLocalUrl(process.env.SAAS_LOCAL_API ?? 'http://apiavacy.test:3000', 'SAAS_LOCAL_API');
// senza la riga in /etc/hosts: indirizzo numerico e Host scritto a mano (vedi run.sh)
const HOST_HEADER: Record<string, string> = process.env.SAAS_HOST_HEADER ? { Host: process.env.SAAS_HOST_HEADER } : {};
const ROOT = resolve(__dirname, '../../../../..');

export const browserBlocked: string[] = [];

// il browser della prova non esce dallo stack locale: tutto il resto si interrompe
export async function guardBrowser(context: BrowserContext) {
  await context.route('**/*', (route) => {
    const host = new URL(route.request().url()).hostname;
    if (isLocalHost(host)) return route.fallback();
    browserBlocked.push(host);
    appendFileSync(resolve(LOG_DIR, 'browser-hosts.log'), `${new Date().toISOString()}\tBLOCK\t${host}\n`);
    return route.abort('blockedbyclient');
  });
}

// niente auto-login: si entra dal modulo con la password casuale del giro (run.sh)
export async function adminPage(page: Page) {
  await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
  if (page.url().includes('wp-login.php')) {
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', env('SAAS_WP_ADMIN_PASSWORD'));
    await page.click('#wp-submit');
    await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
  }
  await page.waitForSelector('.wrap:not(.hide)');
}

// il salvataggio posta su admin-post.php e torna alla dashboard: si aspetta la risposta, non solo 'load'
export async function saveDashboard(page: Page) {
  const posted = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/wp-admin/'), { timeout: 30_000 });
  const loaded = page.waitForEvent('load', { timeout: 30_000 });
  await page.locator('sl-button.Submit--Global').click();
  await posted;
  await loaded;
  await page.waitForSelector('.wrap:not(.hide)');
}

// canale di controllo della mu-plugin: risponde solo con il segreto del giro (run.sh)
export const control = (request: APIRequestContext, path: string) =>
  request.get(`/avacy-saas-local/${path}`, { headers: { 'X-Avacy-Saas-Local': env('SAAS_CONTROL_SECRET') } });

export async function wpState(request: APIRequestContext) {
  const res = await control(request, 'state');
  expect(res.ok()).toBeTruthy();
  return res.json();
}

// la dashboard vera, scheda Consent Archive: switch Save, identificativo, campi dati, caratteristiche, poi Salva
export async function configureForm(page: Page, id: number, cfg: { identifier: string; fields: Record<string, boolean>; features?: Record<string, string> }) {
  await adminPage(page);
  await page.locator('sl-tab:has-text("Consent Archive")').click();
  const group = page.locator(`#avacy-form-contact_form_7_${id}`);
  await expect(group).toBeVisible();

  const sw = group.locator(`sl-switch[name=avacy_contact_form_7_${id}_radio_enabled]`);
  if (!(await sw.evaluate((el: any) => el.checked))) await sw.click();

  const ident = group.locator(`sl-select[name=avacy_contact_form_7_${id}_form_user_identifier]`);
  await ident.click();
  await ident.locator(`sl-option[value="${cfg.identifier}"]`).click();
  await expect.poll(() => ident.evaluate((el: any) => el.value)).toBe(cfg.identifier);

  for (const [field, want] of Object.entries(cfg.fields)) {
    const box = group.locator(`sl-checkbox[name=avacy_form_field_wpcf7_${id}_${field}]`);
    if ((await box.evaluate((el: any) => el.checked)) !== want) await box.click();
  }
  for (const [feature, choice] of Object.entries(cfg.features ?? {})) {
    const sel = group.locator(`sl-select[name=avacy_contact_form_7_${id}_consent_feature_${feature}]`);
    await sel.click();
    await sel.locator(`sl-option[value="${choice}"]`).click();
    await expect.poll(() => sel.evaluate((el: any) => el.value)).toBe(choice);
  }
  // la dashboard 1.5.x disabilita Salva per un form incompleto: qui la configurazione deve essere valida
  const button = page.locator('sl-button.Submit--Global');
  await expect.poll(() => button.evaluate((el) => el.hasAttribute('disabled'))).toBe(false);
  await saveDashboard(page);
}

export const lines = (file: string) => {
  const p = resolve(LOG_DIR, file);
  return existsSync(p) ? readFileSync(p, 'utf8').split('\n').filter(Boolean) : [];
};

// invio di un form CF7 dal browser; ritorna stato HTTP dei POST ai consensi e corpi inviati dal plugin
export async function submitCf7(page: Page, slug: string, values: Record<string, string>, ticks: string[]) {
  const before = { responses: lines('responses.log').length, bodies: lines('bodies.log').length };
  await page.goto(`/${slug}/`);
  for (const [name, value] of Object.entries(values)) await page.fill(`form.wpcf7-form input[name=${name}]`, value);
  for (const name of ticks) await page.check(`form.wpcf7-form input[name=${name}]`);
  await page.click('form.wpcf7-form input[type=submit]');
  // CF7 risponde quando il plugin ha gia' scritto (wpcf7_before_send_mail); la mail poi fallisce, non conta
  await expect(page.locator('form.wpcf7-form')).not.toHaveClass(/submitting/, { timeout: 30_000 });
  const statuses = lines('responses.log').slice(before.responses)
    .map((l) => l.split('\t'))
    .filter((c) => c[1] === 'POST' && c[2].includes('/consents'))
    .map((c) => c[3]);
  const bodies = lines('bodies.log').slice(before.bodies).map((l) => JSON.parse(l.split('\t')[1]) as { keys: string[]; encoded: string });
  return { statuses, bodies };
}

type Consent = { identifier?: string; consent_type?: string; consent_data?: { label: string; value: string }[]; consent_features?: string[] };

// l'archivio letto con l'API di lettura del SaaS locale (token dell'utente creato da provision.sh)
export async function findConsent(identifier: string): Promise<Consent | null> {
  const api = await pwRequest.newContext({ extraHTTPHeaders: { ...HOST_HEADER, Accept: 'application/json', Authorization: `Bearer ${env('SAAS_USER_TOKEN')}` } });
  const url = assertLocalUrl(`${LOCAL_API}/${env('SAAS_TENANT')}/v4/webspaces/${env('SAAS_WEBSPACE_ID')}/consents/?type=form&limit=100&page=1`, 'archivio');
  try {
    const res = await api.get(url);
    expect(res.ok(), `lettura archivio HTTP ${res.status()}`).toBeTruthy();
    const body = await res.json();
    return (body.data ?? []).find((c: Consent) => c.identifier === identifier) ?? null;
  } finally {
    await api.dispose();
  }
}

export async function archived(identifier: string): Promise<Consent> {
  let found: Consent | null = null;
  await expect.poll(async () => (found = await findConsent(identifier)) !== null, { timeout: 15_000 }).toBe(true);
  return found as unknown as Consent;
}

// docker compose exec sullo stack e2e locale del test: mai ECS, mai staging o produzione
export function tinker(code: string): string {
  const out = execFileSync('docker', ['compose', '-p', env('COMPOSE_PROJECT'), '-f', `${ROOT}/docker-compose.e2e.yml`, 'exec', '-T', 'api-e2e',
    'php', '-d', 'display_errors=Off', '-d', 'log_errors=Off', 'artisan', 'tinker', `--execute=${code}`], { encoding: 'utf8' });
  return out.trim().split('\n').pop() ?? '';
}
