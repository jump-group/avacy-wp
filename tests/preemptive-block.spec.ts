import { test, expect, APIRequestContext, Page } from '@playwright/test';

/**
 * Usa il blueprint `with-preemptive-block`: il webspace è finto, le chiamate
 * verso la CDN e verso /wp/validate/ sono intercettate dalla mu-plugin
 * `tests/fixtures/mu-plugins/avacy-test-harness.php`, che le rende
 * controllabili e ispezionabili via REST (vedi quel file per i dettagli).
 */

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

async function triggerRefresh(request: APIRequestContext) {
  const res = await request.post('/avacy-test-api/refresh');
  if (!res.ok()) throw new Error(`refresh failed: ${res.status()} ${await res.text()}`);
  return res.json();
}

/**
 * La versione del banner la scrive `AddAdminInterface::checkSaasAccount()`,
 * dalla risposta di /wp/validate/ che la pagina admin riceve comunque. Non
 * esiste un altro modo di portarsela a casa: il refresh non la chiede.
 */
async function loadBannerVersion(page: Page, request: APIRequestContext, version: string) {
  await setState(request, { avacy_test_banner_version: version });
  await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
  await page.waitForSelector('.wrap:not(.hide)');
}

async function waitUntil(fn: () => Promise<boolean>, timeoutMs = 8000, intervalMs = 200) {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    if (await fn()) return true;
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  return false;
}

test.describe('PreemptiveBlock — refresh (B5 / 10b)', () => {
  test.beforeEach(async ({ request }) => {
    await resetHarness(request);
  });

  test('senza vendor da bloccare non si registra nessuna intercettazione (problema 1)', async ({ page, request }) => {
    await setState(request, { avacy_test_scenario: 'vendor_empty' });
    const result = await triggerRefresh(request);
    expect(result.refreshed).toBe(true);

    const state = await getState(request);
    expect(state.blackListCount).toBe(0);

    await page.goto('/avacy-test-page?variant=tracked');
    const html = await page.content();
    expect(html).toContain('<script src="https://tracker.example.com/t.js">');
    expect(html).not.toContain('as-oil');
    expect(html).not.toContain('text/avacy-blocked');
  });

  test('se la chiamata di rete fallisce, la copia locale non si tocca (rottura 1)', async ({ page, request }) => {
    await setState(request, { avacy_test_scenario: 'vendor_with_rule' });
    await triggerRefresh(request);
    await loadBannerVersion(page, request, 'v3');

    let state = await getState(request);
    expect(state.blackListCount).toBe(1);
    expect(state.bannerVersion).toBe('v3');

    await setState(request, { avacy_test_scenario: 'all_fail' });
    const result = await triggerRefresh(request);
    expect(result.refreshed).toBe(true); // il tentativo c'è stato, solo che è fallito

    state = await getState(request);
    expect(state.blackListCount).toBe(1); // invariato
    // e la versione nemmeno la sfiora: ormai sono due strade separate
    expect(state.bannerVersion).toBe('v3');
  });

  test('il lucchetto impedisce un secondo refresh mentre uno è già in corso (rottura 2)', async ({ request }) => {
    await setState(request, { avacy_test_scenario: 'vendor_with_rule' });

    // pianta il lucchetto a mano: equivale a "un refresh è già in corso",
    // senza dover vincere una vera gara di concorrenza contro il backend di test
    await request.post('/avacy-test-api/lock');
    const result = await triggerRefresh(request);
    expect(result.refreshed).toBe(false);

    const state = await getState(request);
    expect(state.requestLog.length).toBe(0); // nessuna chiamata: si è fermato al lucchetto
  });

  test('a ogni caricamento si controlla la data, non si chiama la rete prima di 2 ore', async ({ page, request }) => {
    await setState(request, {
      avacy_test_scenario: 'vendor_with_rule',
      avacy_preemptive_refresh_last: Math.floor(Date.now() / 1000),
    });

    await page.goto('/');
    const state = await getState(request);
    expect(state.requestLog.length).toBe(0);
  });

  test('oltre 2 ore, il ripiego su shutdown scarica da solo', async ({ page, request }) => {
    const threeHoursAgo = Math.floor(Date.now() / 1000) - 3 * 60 * 60;
    await setState(request, {
      avacy_test_scenario: 'vendor_with_rule',
      avacy_preemptive_refresh_last: threeHoursAgo,
    });

    await page.goto('/');

    const ok = await waitUntil(async () => (await getState(request)).requestLog.length > 0);
    expect(ok).toBe(true);
  });

  test('un tentativo fallito frena comunque: non si richiama la rete a ogni visita', async ({ page, request }) => {
    // Mai riuscita: nessuna delle due date esiste dopo il reset.
    await setState(request, { avacy_test_scenario: 'network_fail' });

    // Le chiamate di un giro sono due (versione e regole): si contano i giri.
    const rounds = async () =>
      (await getState(request)).requestLog.filter((url: string) => url.includes('custom-vendor-list.json')).length;

    await page.goto('/');
    expect(await waitUntil(async () => (await rounds()) === 1)).toBe(true);

    // La seconda visita arriva subito dopo: il freno del primo avvio e` di
    // cinque minuti, quindi niente seconda chiamata.
    await page.goto('/');
    await page.goto('/');
    expect(await rounds()).toBe(1);
  });

  test('mai riuscita, il freno dura cinque minuti e non due ore', async ({ page, request }) => {
    const seiMinutiFa = Math.floor(Date.now() / 1000) - 6 * 60;
    await setState(request, {
      avacy_test_scenario: 'network_fail',
      avacy_preemptive_refresh_last: seiMinutiFa,
    });

    // Sei minuti sono lontani dalle due ore, ma oltre i cinque del primo avvio.
    await page.goto('/');
    expect(await waitUntil(async () => (await getState(request)).requestLog.length > 0)).toBe(true);
  });

  test('il pulsante manuale forza il refresh anche se l\'intervallo non è scaduto', async ({ request }) => {
    await setState(request, { avacy_test_scenario: 'vendor_empty' });
    await triggerRefresh(request); // popola il timestamp "adesso"
    expect((await getState(request)).blackListCount).toBe(0);

    await setState(request, { avacy_test_scenario: 'vendor_with_rule' });
    const result = await triggerRefresh(request); // equivalente al click sul pulsante manuale
    expect(result.refreshed).toBe(true);

    // il secondo giro e` avvenuto davvero, pur con l'intervallo non scaduto
    expect((await getState(request)).blackListCount).toBe(1);
  });

  test('il pulsante nella scheda Preemptive Block funziona da UI autenticata', async ({ page, request }) => {
    await setState(request, { avacy_test_scenario: 'vendor_with_rule' });

    await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
    await page.waitForSelector('.wrap:not(.hide)');
    await page.locator('sl-tab:has-text("Preemptive Block")').click();

    const refreshButton = page.locator('sl-button:has-text("Refresh the vendor list")');
    await expect(refreshButton).toHaveCount(1);
    // sl-button renderizza il vero <a> nello shadow DOM; Playwright lo raggiunge comunque
    await refreshButton.locator('a').click();

    // il click porta a admin-post.php che fa da subito refresh() e poi redirige:
    // verifichiamo l'effetto (stato aggiornato), non l'URL esatto del redirect
    const ok = await waitUntil(async () => (await getState(request)).blackListCount === 1, 15000);
    expect(ok).toBe(true);
  });

  // #586: senza credenziali il refresh esce prima del lock, e non scrive niente a ogni visita
  test('credenziali vuote: le visite non prendono il lock del refresh (#586)', async ({ page, request }) => {
    await setState(request, { avacy_webspace_key: '', avacy_tenant: '' });
    await page.goto('/');
    await page.goto('/');
    expect((await getState(request)).lockWrites).toBe(0);

    // con le credenziali il lock si prende: il contatore funziona
    await resetHarness(request);
    await page.goto('/');
    expect(await waitUntil(async () => (await getState(request)).lockWrites > 0)).toBe(true);
  });

  // #586: con WP-CLI il ripiego su shutdown non parte, una visita normale sì
  test('WP-CLI: niente refresh di ripiego, nessuna chiamata in rete (#586)', async ({ page, request }) => {
    await page.goto('/?avacy-test-cli=1');
    const state = await getState(request);
    expect(state.lockWrites).toBe(0);
    expect(state.requestLog ?? []).toEqual([]);

    await page.goto('/');
    expect(await waitUntil(async () => ((await getState(request)).requestLog ?? []).length > 0)).toBe(true);
  });

  // #719: l'esito si legge dalle due date, sotto il pulsante
  async function refreshBox(page: Page) {
    await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
    await page.waitForSelector('.wrap:not(.hide)');
    await page.locator('sl-tab:has-text("Preemptive Block")').click();
    return page.locator('.AvacyDescription', { has: page.locator('sl-button:has-text("Refresh the vendor list")') });
  }

  test('aggiornamento fallito: avviso sotto il pulsante, regole di prima intatte (#719)', async ({ page, request }) => {
    await setState(request, { avacy_test_scenario: 'vendor_with_rule' });
    await triggerRefresh(request);
    await expect((await refreshBox(page)).locator('.AvacyRefreshFailed')).toHaveCount(0);

    await setState(request, { avacy_test_scenario: 'network_fail' });
    await triggerRefresh(request);
    const box = await refreshBox(page);
    await expect(box.locator('.AvacyRefreshFailed')).toContainText('The last update failed');
    await expect(box).toContainText('Updated');
    expect((await getState(request)).blackListCount).toBe(1);

    // il giro dopo riesce: l'avviso sparisce
    await setState(request, { avacy_test_scenario: 'vendor_with_rule' });
    await triggerRefresh(request);
    await expect((await refreshBox(page)).locator('.AvacyRefreshFailed')).toHaveCount(0);
  });

  test('mai riuscito: «Not updated yet» con l`avviso (#719)', async ({ page, request }) => {
    await setState(request, { avacy_test_scenario: 'network_fail' });
    await triggerRefresh(request);
    const box = await refreshBox(page);
    await expect(box).toContainText('Not updated yet');
    await expect(box.locator('.AvacyRefreshFailed')).toContainText('The last update failed');
  });
});

// #667: banner spento e blocco acceso, nessuno sblocca gli script
test.describe('PreemptiveBlock — avviso col banner spento (#667)', () => {
  // il reset dell'harness non rimette il banner: lo fanno i test, prima e dopo
  test.beforeEach(async ({ request }) => {
    await resetHarness(request);
    await setState(request, { avacy_show_banner: 'on', avacy_enable_preemptive_block: 'on' });
  });
  test.afterEach(async ({ request }) => {
    await setState(request, { avacy_show_banner: 'on', avacy_enable_preemptive_block: 'on' });
  });

  async function warning(page: Page) {
    await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
    await page.waitForSelector('.wrap:not(.hide)');
    await page.locator('sl-tab:has-text("Preemptive Block")').click();
    return page.locator('sl-tab-panel[name="preemptive-block"] .AvacyBannerOff');
  }

  test('banner spento e blocco acceso: avviso sotto la spunta', async ({ page, request }) => {
    await setState(request, { avacy_show_banner: '' });
    await expect(await warning(page)).toContainText('The cookie banner is turned off');
  });

  test('banner acceso, oppure blocco spento: nessun avviso', async ({ page, request }) => {
    await expect(await warning(page)).toHaveCount(0);
    await setState(request, { avacy_show_banner: '', avacy_enable_preemptive_block: '' });
    await expect(await warning(page)).toHaveCount(0);
  });
});

// #586: «Elimina» nella pagina Plugin toglie dati tecnici e credenziali, tiene le impostazioni
test.describe('Disinstallazione (#586)', () => {
  test.afterEach(async ({ request }) => {
    await resetHarness(request);
    await setState(request, { avacy_show_banner: 'on' });
  });

  test('via dati tecnici e credenziali; banner, blocco e form restano', async ({ page, request }) => {
    // la registrazione la rifà il plugin a ogni caricamento: anche su un sito aggiornato, che non riattiva
    await request.post('/avacy-test-api/uninstall', { data: { forget: true } });
    await resetHarness(request);
    await setState(request, { avacy_test_scenario: 'vendor_with_rule' });
    await triggerRefresh(request);
    await loadBannerVersion(page, request, 'v3');
    await setState(request, {
      avacy_tenant: 'test-harness',
      avacy_webspace_id: '42',
      avacy_api_token: '7|abcdefghijklmnopqrstuvwxyz',
      avacy_show_banner: 'on',
      avacy_html_forms_7_radio_enabled: 'on',
    });

    const removed = [
      'avacy_preemptive_blacklist', 'avacy_preemptive_refresh_last', 'avacy_preemptive_refresh_ok',
      'avacy_banner_config_version', 'avacy_tenant', 'avacy_webspace_key', 'avacy_webspace_id', 'avacy_api_token',
    ];
    const kept = ['avacy_show_banner', 'avacy_enable_preemptive_block', 'avacy_html_forms_7_radio_enabled'];
    const res = await request.post('/avacy-test-api/uninstall', { data: { options: [...removed, 'avacy_cdn_url', ...kept] } });
    const out = await res.json();

    expect(out.registered).toBe(true);
    for (const name of [...removed, ...kept]) expect(out.before[name], `${name} prima`).not.toBeNull();
    for (const name of [...removed, 'avacy_cdn_url']) expect(out.after[name], `${name} dopo`).toBeNull();
    for (const name of kept) expect(out.after[name], `${name} dopo`).toBe(out.before[name]);
    expect(out.cronBefore).toBeTruthy();
    expect(out.cron).toBe(false);
    expect(out.lock).toBe(false);
  });
});

test.describe('PreemptiveBlock — gate di versione e marcatura (B2 / B3)', () => {
  test.beforeEach(async ({ request }) => {
    await resetHarness(request);
  });

  test('webspace v2 -> marcatura legacy (as-oil)', async ({ page, request }) => {
    await setState(request, { avacy_test_scenario: 'vendor_with_rule' });
    await triggerRefresh(request);

    await page.goto('/avacy-test-page?variant=tracked');
    const script = page.locator('script[data-managed="as-oil"]');
    await expect(script).toHaveCount(1);
    await expect(script).toHaveAttribute('type', 'as-oil');
    await expect(script).toHaveAttribute('data-src', 'https://tracker.example.com/t.js');
    await expect(script).toHaveAttribute('data-type', 'text/javascript');
    await expect(script).toHaveAttribute('data-custom-vendor', 'd999');
    await expect(script).toHaveAttribute('data-purposes', '1,8,9');
  });

  test('webspace v3 -> vocabolario nuovo (text/avacy-blocked)', async ({ page, request }) => {
    await setState(request, { avacy_test_scenario: 'vendor_with_rule' });
    await triggerRefresh(request);
    await loadBannerVersion(page, request, 'v3');

    await page.goto('/avacy-test-page?variant=tracked');
    const script = page.locator('script[type="text/avacy-blocked"]');
    await expect(script).toHaveCount(1);
    await expect(script).toHaveAttribute('data-avacy-src', 'https://tracker.example.com/t.js');
    await expect(script).toHaveAttribute('data-avacy-type', 'text/javascript');
    await expect(script).toHaveAttribute('data-avacy-requires', 'custom:vendor:d999;tcf:purpose:1,8,9');
    // L'asimmetria di 14f: un banner v2 cerca `as-oil` e non la troverebbe mai.
    // Sbagliare verso v3 lascia lo script bloccato per sempre, sbagliare verso
    // v2 no — ed e' il motivo per cui il default e' v2.
    expect(await page.content()).not.toContain('as-oil');
  });

  test('il file v3 porta le finalità di piu` framework, raggruppate per quello che il file dichiara', async ({ page, request }) => {
    await setState(request, { avacy_test_scenario: 'vendor_v3_shape' });
    await triggerRefresh(request);
    await loadBannerVersion(page, request, 'v3');

    await page.goto('/avacy-test-page?variant=tracked');
    const script = page.locator('script[type="text/avacy-blocked"]');
    // Un gruppo per framework, nell'ordine in cui il file li presenta: il
    // plugin non ha un elenco dei framework, raggruppa per quello che legge.
    await expect(script).toHaveAttribute(
      'data-avacy-requires',
      'custom:vendor:d999;tcf:purpose:1,8;gcm:purpose:3',
    );
  });

  test('un nome di framework che proverebbe a iniettare separatori viene scartato', async ({ page, request }) => {
    await setState(request, { avacy_test_scenario: 'vendor_v3_shape' });
    await triggerRefresh(request);
    await loadBannerVersion(page, request, 'v3');

    await page.goto('/avacy-test-page?variant=tracked');
    const requires = await page.locator('script[type="text/avacy-blocked"]').getAttribute('data-avacy-requires');
    expect(requires).not.toContain('bad');
    expect(requires).not.toContain('inject');
  });

  test('url non in blacklist -> script intonso (nessun vendor combacia)', async ({ page, request }) => {
    await setState(request, { avacy_test_scenario: 'vendor_with_rule' });
    await triggerRefresh(request);
    await loadBannerVersion(page, request, 'v3');

    await page.goto('/avacy-test-page?variant=untracked');
    const html = await page.content();
    expect(html).toContain('<script src="https://not-tracked.example.com/a.js">');
    expect(html).not.toContain('text/avacy-blocked');
  });
});

test.describe('PreemptiveBlock — DOMDocument, problemi 2 e 3 (10e)', () => {
  test.beforeEach(async ({ request }) => {
    await resetHarness(request);
    await setState(request, { avacy_test_scenario: 'vendor_with_rule' });
    await triggerRefresh(request);
  });

  test('gli accenti sopravvivono senza <meta charset> in testa al documento (problema 2)', async ({ page }) => {
    await page.goto('/avacy-test-page?variant=tracked');
    const text = await page.locator('p').first().textContent();
    expect(text).toBe('perché città');
  });

  test('una pagina senza script da marcare non genera errori PHP (rottura 4 — non-regressione)', async ({ page }) => {
    const response = await page.goto('/avacy-test-page?variant=untracked');
    expect(response?.status()).toBe(200);
    const html = await page.content();
    expect(html).not.toMatch(/Fatal error/i);
    expect(html).not.toMatch(/Parse error/i);
  });

  test('il contenuto degli script esce identico, anche con HTML e accenti dentro (B1)', async ({ request }) => {
    const html = await (await request.get('/avacy-test-page?variant=inline-html')).text();
    expect(html).toContain('<script>var s = "<div>a</div>"; var t = "città";</script>');
  });

  test('uno script inline da bloccare viene marcato e resta intero (B1)', async ({ page, request }) => {
    const html = await (await request.get('/avacy-test-page?variant=inline-html')).text();
    expect(html).toContain('var x = "tracker.example.com/t.js"; var h = "<p>x</p>";</script>');
    await page.goto('/avacy-test-page?variant=inline-html');
    await expect(page.locator('script[data-managed="as-oil"]')).toHaveCount(1);
  });
});

/**
 * Quale banner finisce in pagina. Separato dalla marcatura degli script: il
 * gate e' lo stesso, ma qui decide chi viene caricato, non come viene marcato
 * (il caso 02 del catalogo «banner morto»). La home del sito, non
 * `/avacy-test-page`: quella scrive il body a mano e non passa da `wp_head()`,
 * dove gli script in coda vengono stampati.
 */
test.describe('EnqueueBanner — link unico (#723)', () => {
  const V3_TAG = 'avacy-cdn.com/v3/avacy-cmp-web.js?webspace=11111111-1111-1111-1111-111111111111';

  test.beforeEach(async ({ request }) => {
    await resetHarness(request);
  });

  // Chi decide v2 o v3 e` la CDN: il tag e` lo stesso comunque, anche per
  // un sito che il SaaS non ha ancora interpellato.
  for (const version of ['', 'v2', 'v3']) {
    test(`versione salvata «${version || 'assente'}» -> sempre il tag v3, e nessuna traccia di OIL`, async ({ page, request }) => {
      if (version) await loadBannerVersion(page, request, version);

      await page.goto('/');
      const html = await page.content();

      expect(html).toContain(V3_TAG);
      // La prova al contrario: col vecchio if, v2 e assente caricavano OIL.
      expect(html).not.toContain('oilstub.min.js');
      expect(html).not.toContain('oil.min.js');
    });
  }

  test('il tag e` un <script src> classico, che il loader sa riconoscere', async ({ page }) => {
    await page.goto('/');
    const tag = page.locator('script[src*="/v3/avacy-cmp-web.js"]');

    await expect(tag).toHaveCount(1);
    // `document.currentScript` vale null su un modulo: il loader cadrebbe sul
    // suo ripiego, che cerca proprio `/v3/` + `webspace=`. Meglio non servircene.
    await expect(tag).not.toHaveAttribute('type', 'module');
    // Nessun `&ver=` appeso da WordPress: il tag deve restare identico a quello
    // che il pannello mostra a chi installa a mano.
    expect(await tag.getAttribute('src')).toBe('https://test-harness.' + V3_TAG);
  });
});

/**
 * Due strade separate: le regole di blocco le porta il periodico (solo dove il
 * blocco e' acceso) o il pulsante; la versione del banner la porta la pagina
 * admin, da una risposta che riceve comunque.
 */
test.describe('chi porta cosa, e dove (10b / 14)', () => {
  test.beforeEach(async ({ request }) => {
    await resetHarness(request);
  });

  test('col blocco acceso il cron e` pianificato', async ({ page, request }) => {
    // una richiesta qualsiasi: gli hook si registrano a plugins_loaded
    await page.goto('/');

    const state = await getState(request);
    expect(state.preemptiveBlock).toBe('on');
    expect(state.cronScheduled).toBe(true);
  });

  test('spegnendo il blocco il cron viene tolto, non lasciato orfano', async ({ page, request }) => {
    await page.goto('/');
    expect((await getState(request)).cronScheduled).toBe(true);

    await setState(request, { avacy_enable_preemptive_block: '' });
    // la casella si legge a plugins_loaded: serve una richiesta dopo il cambio
    await page.goto('/');

    expect((await getState(request)).cronScheduled).toBe(false);
  });

  test('il cron e` a 2 ore, nello schedule e nell`evento pianificato', async ({ page, request }) => {
    await page.goto('/');

    const state = await getState(request);
    expect(state.scheduleInterval).toBe(2 * 60 * 60);
    expect(state.cronInterval).toBe(2 * 60 * 60);
  });

  test('un sito aggiornato con l`evento a 6 ore passa a 2 ore senza riattivare il plugin', async ({ page, request }) => {
    const legacy = await (await request.post('/avacy-test-api/legacy-cron')).json();
    expect(legacy.interval).toBe(6 * 60 * 60); // partenza: l'evento della 1.4.x

    // una richiesta qualsiasi: gli hook si registrano a plugins_loaded
    await page.goto('/');

    const state = await getState(request);
    expect(state.cronScheduled).toBe(true);
    expect(state.cronInterval).toBe(2 * 60 * 60);
    // la prossima esecuzione non sta oltre un intervallo intero da adesso
    expect(state.cronNext).toBeLessThanOrEqual(Math.floor(Date.now() / 1000) + 2 * 60 * 60 + 5);
  });

  test('il giro delle regole aggiorna la versione dal SaaS e riscarica le regole (#714)', async ({ page, request }) => {
    await setState(request, { avacy_test_scenario: 'vendor_with_rule' });
    await loadBannerVersion(page, request, 'v2');
    expect((await getState(request)).bannerVersion).toBe('v2');

    // il SaaS ora dice v3, ma nessuno apre la pagina admin
    await setState(request, { avacy_test_banner_version: 'v3' });
    await triggerRefresh(request);

    const state = await getState(request);
    expect(state.bannerVersion).toBe('v3');
    expect(state.blackListCount).toBe(1);

    // la versione si chiede prima delle regole, e con la chiamata normale
    // (team/uuid), non con quella del token
    const validate = state.requestLog.findIndex((url: string) => /\/wp\/validate\/test-harness\/11111111-1111-1111-1111-111111111111$/.test(url));
    const rules = state.requestLog.findIndex((url: string) => url.includes('custom-vendor-list.json'));
    expect(validate).toBeGreaterThanOrEqual(0);
    expect(rules).toBeGreaterThan(validate);

    // e il blocco usa subito il vocabolario della versione nuova
    await page.goto('/avacy-test-page?variant=tracked');
    await expect(page.locator('script[type="text/avacy-blocked"]')).toHaveCount(1);
  });

  test('anche da v3 a v2 il giro riallinea, senza azioni dell`admin', async ({ page, request }) => {
    await setState(request, { avacy_test_scenario: 'vendor_with_rule' });
    await loadBannerVersion(page, request, 'v3');
    await setState(request, { avacy_test_banner_version: 'v2' });

    await triggerRefresh(request);

    expect((await getState(request)).bannerVersion).toBe('v2');
    await page.goto('/avacy-test-page?variant=tracked');
    await expect(page.locator('script[data-managed="as-oil"]')).toHaveCount(1);
  });

  // Scenari del SaaS che non danno una versione valida: nessuno tocca la
  // versione salvata, e il giro prosegue con le regole.
  const noVersion: [string, Record<string, unknown>][] = [
    ['rete giu`', { avacy_test_scenario: 'validate_fail' }],
    ['503', { avacy_test_scenario: 'validate_unavailable' }],
    ['502 senza JSON', { avacy_test_scenario: 'validate_bad_gateway' }],
    ['404 team non trovato', { avacy_test_scenario: 'validate_denied' }],
    ['404 webspace non trovato', { avacy_test_scenario: 'validate_denied_webspace' }],
    ['404 senza codice', { avacy_test_scenario: 'validate_denied_legacy' }],
    ['200 senza il campo', { avacy_test_banner_version: 'missing' }],
    ['200 con un valore inatteso', { avacy_test_banner_version: 'v4' }],
    ['200 con un valore vuoto', { avacy_test_banner_version: '' }],
  ];

  for (const [label, scenario] of noVersion) {
    test(`${label}: la versione e le credenziali restano intatte`, async ({ page, request }) => {
      await loadBannerVersion(page, request, 'v3');
      await setState(request, {
        avacy_tenant: 'test-harness',
        avacy_webspace_id: '4242',
        avacy_api_token: 'segreto',
        ...scenario,
      });
      const before = await getState(request);

      const result = await triggerRefresh(request);
      expect(result.refreshed).toBe(true);

      const state = await getState(request);
      expect(state.bannerVersion).toBe('v3');
      expect(state.tenant).toBe(before.tenant);
      expect(state.webspaceKey).toBe(before.webspaceKey);
      expect(state.webspaceId).toBe('4242');
      expect(state.apiToken).toBe('segreto');
      // il giro non si e` fermato: le regole sono arrivate lo stesso
      expect(state.blackListCount).toBe(1);
    });
  }

  test('la scheda Cookie Banner mostra la versione in uso', async ({ page, request }) => {
    await loadBannerVersion(page, request, 'v3');

    const panel = page.locator('sl-tab-panel[name="cookie-banner"]');
    await expect(panel).toContainText('Current banner version:');
    await expect(panel.locator('strong')).toHaveText('v3');

    // e segue il valore, non e` scritta a mano
    await loadBannerVersion(page, request, 'v2');
    await expect(page.locator('sl-tab-panel[name="cookie-banner"] strong')).toHaveText('v2');
  });

  /**
   * Trovato in revisione il 05/10. Un giro a vuoto timbrava «aggiornato»:
   * all'accensione del blocco il bootstrap non partiva piu` e la lista restava
   * vuota fino al cron dopo, mentre il pannello diceva «Updated just now».
   */
  test('a blocco spento il giro non parte, e non timbra «aggiornato»', async ({ request }) => {
    await setState(request, { avacy_test_scenario: 'vendor_with_rule', avacy_enable_preemptive_block: '' });

    const result = await triggerRefresh(request);
    expect(result.refreshed).toBe(false);

    const state = await getState(request);
    expect(state.blackListCount).toBe(0);
    expect(state.lastRefresh).toBe(0);
  });

  /**
   * Trovato in revisione il 05/10. Il default verso v2 copre «non so niente»,
   * non «cancello quello che sapevo»: una 200 degradata che non porta il campo
   * manderebbe su OIL un sito che e' davvero v3.
   */
  test('una risposta senza il campo non cancella la versione gia` nota', async ({ page, request }) => {
    await loadBannerVersion(page, request, 'v3');
    expect((await getState(request)).bannerVersion).toBe('v3');

    await loadBannerVersion(page, request, 'missing');

    expect((await getState(request)).bannerVersion).toBe('v3');
  });

  test('aprire la pagina admin porta a casa la versione del banner', async ({ page, request }) => {
    await setState(request, { avacy_test_banner_version: 'v3' });
    expect((await getState(request)).bannerVersion).toBe('');

    await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
    await page.waitForSelector('.wrap:not(.hide)');

    // nessuna chiamata dedicata: la versione viene dalla stessa risposta che
    // la pagina usa gia` per validare le credenziali.
    expect((await getState(request)).bannerVersion).toBe('v3');
  });

  /**
   * La rottura vista a mano il 05/10: l'aggiornamento andava a buon fine e
   * l'utente restava su una pagina bianca, perche' il ritorno si affidava al
   * solo referer. Qui si va sull'URL direttamente, che e' il modo piu' semplice
   * di non averne uno.
   */
  test('senza referer si torna comunque alla pagina Avacy, non su una bianca', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
    await page.waitForSelector('.wrap:not(.hide)');
    await page.locator('sl-tab:has-text("Preemptive Block")').click();
    const href = await page.locator('sl-button:has-text("Refresh the vendor list")').getAttribute('href');

    await page.goto(href!);

    expect(page.url()).toContain('page=avacy-plugin-settings');
    await expect(page.locator('sl-tab:has-text("Preemptive Block")')).toHaveCount(1);
  });

  test('con zero regole si dice dove andare a guardare, non quante sono', async ({ page, request }) => {
    await setState(request, { avacy_test_scenario: 'vendor_empty' });
    await triggerRefresh(request);

    await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
    await page.waitForSelector('.wrap:not(.hide)');
    await page.locator('sl-tab:has-text("Preemptive Block")').click();

    const panel = page.locator('sl-tab-panel[name="preemptive-block"]');
    await expect(panel).toContainText('No vendors to block');
    await expect(panel).toContainText('Updated');
    // il link porta al webspace giusto: `tenant|uuid` va diviso, o si va altrove
    await expect(panel.locator('a[href*="/redirect/vendors/test-harness/11111111-1111-1111-1111-111111111111"]')).toHaveCount(1);
  });

  test('con delle regole si dice solo quando, non quante', async ({ page, request }) => {
    await setState(request, { avacy_test_scenario: 'vendor_with_rule' });
    await triggerRefresh(request);

    await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
    await page.waitForSelector('.wrap:not(.hide)');
    await page.locator('sl-tab:has-text("Preemptive Block")').click();

    const panel = page.locator('sl-tab-panel[name="preemptive-block"]');
    await expect(panel).toContainText('Updated');
    await expect(panel).not.toContainText('No vendors to block');
    // il conteggio se n'e` andato: diceva quante, mai quali
    await expect(panel).not.toContainText('rule');
  });

  test('col blocco spento il pulsante non c`e`: non ci sono regole da aggiornare', async ({ page, request }) => {
    await setState(request, { avacy_enable_preemptive_block: '' });

    await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
    await page.waitForSelector('.wrap:not(.hide)');
    await page.locator('sl-tab:has-text("Preemptive Block")').click();

    // la casella c'e` sempre, e` il riquadro delle regole che sparisce
    await expect(page.locator('sl-checkbox[name="avacy_enable_preemptive_block"]')).toHaveCount(1);
    await expect(page.locator('sl-button:has-text("Refresh the vendor list")')).toHaveCount(0);
  });
});

test.describe('PreemptiveBlock — cambio di chiave al Salva (issue 612)', () => {
  const OTHER_KEY = 'test-harness|22222222-2222-2222-2222-222222222222';

  test.beforeEach(async ({ request }) => {
    await resetHarness(request);
  });

  /** Il Salva della pagina Avacy, con la chiave data: stesso POST che manda il form. */
  async function saveKey(page: Page, key: string, preemptiveBlock = 'on') {
    await page.goto('/wp-admin/admin.php?page=avacy-plugin-settings');
    await page.waitForSelector('.wrap:not(.hide)');
    const nonce = await page.locator('input[name="_wpnonce"]').first().inputValue();
    const res = await page.request.post('/wp-admin/admin-post.php', {
      form: {
        action: 'avacy_admin_save',
        _wpnonce: nonce,
        avacy_webspace_key: key,
        avacy_tenant: 'test-harness',
        avacy_enable_preemptive_block: preemptiveBlock,
        redirectToUrl: '/wp-admin/admin.php?page=avacy-plugin-settings',
      },
    });
    expect(res.ok()).toBe(true);
  }

  const vendorCalls = async (request: APIRequestContext) =>
    ((await getState(request)).requestLog as string[]).filter((u) => u.includes('custom-vendor-list.json'));

  test('con una chiave diversa le regole sono subito quelle del nuovo webspace', async ({ page, request }) => {
    await triggerRefresh(request);
    expect((await getState(request)).blackListIds).toEqual(['d999']);

    await saveKey(page, OTHER_KEY);

    const state = await getState(request);
    expect(state.webspaceKey).toBe(OTHER_KEY);
    expect(state.blackListIds).toEqual(['d222']);
  });

  test('con una chiave diversa e il download in errore le regole vecchie si svuotano', async ({ page, request }) => {
    await triggerRefresh(request);
    expect((await getState(request)).blackListCount).toBe(1);

    await setState(request, { avacy_test_scenario: 'network_fail' });
    await saveKey(page, OTHER_KEY);

    const state = await getState(request);
    expect(state.webspaceKey).toBe(OTHER_KEY);
    expect(state.blackListCount).toBe(0);
    // niente data di riuscita: il bootstrap riprova
    expect(state.lastRefresh).toBe(0);
  });

  test('salvando la stessa chiave non si riscarica niente', async ({ page, request }) => {
    await triggerRefresh(request);
    const before = (await vendorCalls(request)).length;

    await saveKey(page, 'test-harness|11111111-1111-1111-1111-111111111111');

    expect(await vendorCalls(request)).toHaveLength(before);
    expect((await getState(request)).blackListIds).toEqual(['d999']);
  });

  test('a blocco spento il cambio di chiave svuota le regole e non chiama la CDN', async ({ page, request }) => {
    await triggerRefresh(request);
    expect((await getState(request)).blackListCount).toBe(1);
    await setState(request, { avacy_enable_preemptive_block: '' });
    const before = (await vendorCalls(request)).length;

    await saveKey(page, OTHER_KEY, '');

    const state = await getState(request);
    expect(state.webspaceKey).toBe(OTHER_KEY);
    expect(state.blackListCount).toBe(0);
    expect(await vendorCalls(request)).toHaveLength(before);
  });
});
