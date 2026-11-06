import { test, expect, APIRequestContext, Page } from '@playwright/test';

/**
 * Cosa succede alle credenziali salvate quando il SaaS risponde male, o non
 * risponde affatto. Stesso blueprint e stessa mu-plugin di
 * `preemptive-block.spec.ts`: le chiamate a /wp/validate/ sono intercettate e
 * lo scenario le pilota.
 *
 * La regola sotto esame: si cancella solo su un verdetto esplicito — un 404
 * che porta un codice d'errore conosciuto. Tutto il resto lascia tutto dov'è.
 */

const TENANT = 'test-harness';
const WEBSPACE_KEY = 'test-harness|11111111-1111-1111-1111-111111111111';
const API_TOKEN = '7|abcdefghijklmnopqrstuvwxyz';

async function resetHarness(request: APIRequestContext) {
  const res = await request.post('/avacy-test-api/reset');
  if (!res.ok()) throw new Error(`reset failed: ${res.status()} ${await res.text()}`);
}

async function setState(request: APIRequestContext, data: Record<string, unknown>) {
  const res = await request.post('/avacy-test-api/set', { data });
  if (!res.ok()) throw new Error(`set failed: ${res.status()} ${await res.text()}`);
}

async function getState(request: APIRequestContext) {
  const res = await request.get('/avacy-test-api/state');
  if (!res.ok()) throw new Error(`state failed: ${res.status()} ${await res.text()}`);
  return res.json();
}

/** Un sito già collegato: è lo stato in cui il ramo che cancella è raggiungibile. */
async function connect(request: APIRequestContext, extra: Record<string, unknown> = {}) {
  await setState(request, {
    avacy_tenant: TENANT,
    avacy_webspace_key: WEBSPACE_KEY,
    avacy_webspace_id: '42',
    ...extra,
  });
}

async function openAvacyPage(page: Page) {
  await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
  await page.waitForSelector('.wrap:not(.hide)');
}

test.describe('Credenziali — quando il SaaS non dà un verdetto (PBI 32)', () => {
  test.beforeEach(async ({ request }) => {
    await resetHarness(request);
  });

  for (const [scenario, perche] of [
    ['validate_fail', 'la chiamata non parte: rete giù, DNS, timeout'],
    ['validate_unavailable', 'il SaaS risponde 503: è un guasto suo, non un verdetto'],
    ['validate_not_found', 'un 404 che non porta un codice: una rotta sbagliata'],
    ['validate_denied_legacy', 'un 404 nel formato vecchio, senza codice'],
    ['validate_bad_gateway', 'un 502 di un proxy, senza nemmeno il JSON'],
  ] as const) {
    test(`${scenario} — le credenziali restano (${perche})`, async ({ page, request }) => {
      await connect(request, { avacy_test_scenario: scenario });

      await openAvacyPage(page);

      const state = await getState(request);
      expect(state.tenant).toBe(TENANT);
      expect(state.webspaceKey).toBe(WEBSPACE_KEY);
      expect(state.webspaceId).toBe('42');
    });
  }

  for (const scenario of ['validate_fail', 'validate_bad_gateway'] as const) {
    /**
     * `danger` manderebbe l'utente a ricontrollare dei dati che sono giusti:
     * di sbagliato non c'e` niente da questa parte.
     */
    test(`${scenario} — si dice con un avviso, non con un errore sulle credenziali`, async ({ page, request }) => {
      await connect(request, { avacy_test_scenario: scenario });

      await openAvacyPage(page);

      await expect(page.locator('sl-alert[variant="warning"]')).toContainText('Avacy could not be reached');
      await expect(page.locator('sl-alert[variant="danger"]')).toHaveCount(0);
    });
  }

  /**
   * La rottura che il ramo nuovo introdurrebbe se nessuno la guardasse: senza
   * cancellare niente, il redirect in coda ricaricherebbe la stessa pagina e
   * l'hook ripartirebbe da capo, all'infinito.
   */
  test('senza niente da cancellare la pagina non gira su se stessa', async ({ page, request }) => {
    // Giro delle regole gia` fatto adesso: il ripiego su shutdown chiama
    // /wp/validate/ anche lui, e qui si conta solo la chiamata della pagina.
    await connect(request, {
      avacy_test_scenario: 'validate_unavailable',
      avacy_preemptive_refresh_last: Math.floor(Date.now() / 1000),
    });

    await openAvacyPage(page);

    const calls: string[] = (await getState(request)).requestLog ?? [];
    expect(calls.filter((url) => url.includes('/wp/validate/'))).toHaveLength(1);
  });
});

// #586: il team c'e' ma il suo database no. Non e' un verdetto: si tiene tutto e si manda al supporto
test.describe('Credenziali — team senza database (#586)', () => {
  test.beforeEach(async ({ request }) => {
    await resetHarness(request);
  });

  test('aprendo la pagina: credenziali e token restano, l`avviso manda al supporto', async ({ page, request }) => {
    await connect(request, { avacy_api_token: API_TOKEN, avacy_test_scenario: 'tenant_database_missing' });

    await openAvacyPage(page);

    const state = await getState(request);
    expect(state.tenant).toBe(TENANT);
    expect(state.webspaceKey).toBe(WEBSPACE_KEY);
    expect(state.webspaceId).toBe('42');
    expect(state.apiToken).toBe(API_TOKEN);
    await expect(page.locator('sl-alert[variant="warning"]')).toContainText("Avacy can't load the data of this account. Please contact Avacy support.");
    await expect(page.locator('sl-alert[variant="warning"]')).not.toContainText('could not be reached');
    await expect(page.locator('sl-alert[variant="danger"]')).toHaveCount(0);
  });
});

// #586: la globale `$api_base_url` non c'e` piu`: un altro plugin che la usa non sposta le chiamate
test('un altro plugin con una globale `$api_base_url`: le chiamate restano su api.avacy.eu (#586)', async ({ page, request }) => {
  await resetHarness(request);
  await connect(request, { avacy_test_global_clash: 'on' });
  try {
    await openAvacyPage(page);
    const calls: string[] = ((await getState(request)).requestLog ?? []).filter((url: string) => url.includes('/wp/validate/'));
    expect(calls.length).toBeGreaterThan(0);
    for (const url of calls) expect(url).toMatch(/^https:\/\/api\.avacy\.eu\/wp\/validate\//);
  } finally {
    await setState(request, { avacy_test_global_clash: '' });
  }
});

test.describe('Credenziali — quando il SaaS dà un verdetto (PBI 32)', () => {
  test.beforeEach(async ({ request }) => {
    await resetHarness(request);
  });

  for (const scenario of ['validate_denied', 'validate_denied_webspace'] as const) {
    test(`${scenario} — cancella: è il caso per cui quel ramo esiste`, async ({ page, request }) => {
      await connect(request, { avacy_test_scenario: scenario });

      await openAvacyPage(page);

      const state = await getState(request);
      expect(state.tenant).toBe('');
      expect(state.webspaceKey).toBe('');
      expect(state.webspaceId).toBe('');
    });
  }
});

test.describe('Token dell`archivio consensi (PBI 32)', () => {
  test.beforeEach(async ({ request }) => {
    await resetHarness(request);
  });

  for (const scenario of ['token_unreachable', 'token_unavailable', 'token_denied_legacy'] as const) {
    test(`${scenario} — il token resta salvato`, async ({ page, request }) => {
      await connect(request, { avacy_api_token: API_TOKEN, avacy_test_scenario: scenario });

      await openAvacyPage(page);

      expect((await getState(request)).apiToken).toBe(API_TOKEN);
    });
  }

  for (const scenario of ['token_denied', 'token_invalid'] as const) {
    test(`${scenario} — cancella il token`, async ({ page, request }) => {
      await connect(request, { avacy_api_token: API_TOKEN, avacy_test_scenario: scenario });

      await openAvacyPage(page);

      const state = await getState(request);
      expect(state.apiToken).toBe('');
      // il webspace non c'entra: a essere rifiutato e` il token
      expect(state.webspaceKey).toBe(WEBSPACE_KEY);
    });
  }
});
