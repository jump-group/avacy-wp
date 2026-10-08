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

  test('a ogni caricamento si controlla la data, non si chiama la rete prima di 6 ore', async ({ page, request }) => {
    await setState(request, {
      avacy_test_scenario: 'vendor_with_rule',
      avacy_preemptive_refresh_last: Math.floor(Date.now() / 1000),
    });

    await page.goto('/');
    const state = await getState(request);
    expect(state.requestLog.length).toBe(0);
  });

  test('oltre 6 ore, il ripiego su shutdown scarica da solo', async ({ page, request }) => {
    const sevenHoursAgo = Math.floor(Date.now() / 1000) - 7 * 60 * 60;
    await setState(request, {
      avacy_test_scenario: 'vendor_with_rule',
      avacy_preemptive_refresh_last: sevenHoursAgo,
    });

    await page.goto('/');

    const ok = await waitUntil(async () => (await getState(request)).requestLog.length > 0);
    expect(ok).toBe(true);
  });

  test('un tentativo fallito frena comunque: non si richiama la rete a ogni visita', async ({ page, request }) => {
    // Mai riuscita: nessuna delle due date esiste dopo il reset.
    await setState(request, { avacy_test_scenario: 'network_fail' });

    await page.goto('/');
    expect(await waitUntil(async () => (await getState(request)).requestLog.length === 1)).toBe(true);

    // La seconda visita arriva subito dopo: il freno del primo avvio e` di
    // cinque minuti, quindi niente seconda chiamata.
    await page.goto('/');
    await page.goto('/');
    expect((await getState(request)).requestLog.length).toBe(1);
  });

  test('mai riuscita, il freno dura cinque minuti e non sei ore', async ({ page, request }) => {
    const seiMinutiFa = Math.floor(Date.now() / 1000) - 6 * 60;
    await setState(request, {
      avacy_test_scenario: 'network_fail',
      avacy_preemptive_refresh_last: seiMinutiFa,
    });

    // Sei minuti sono lontani dalle sei ore, ma oltre i cinque del primo avvio.
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
});

/**
 * Quale banner finisce in pagina. Separato dalla marcatura degli script: il
 * gate e' lo stesso, ma qui decide chi viene caricato, non come viene marcato
 * (il caso 02 del catalogo «banner morto»). La home del sito, non
 * `/avacy-test-page`: quella scrive il body a mano e non passa da `wp_head()`,
 * dove gli script in coda vengono stampati.
 */
test.describe('EnqueueBanner — quale banner carica (14f)', () => {
  const V3_TAG = 'avacy-cdn.com/v3/avacy-cmp-web.js?webspace=11111111-1111-1111-1111-111111111111';

  test.beforeEach(async ({ request }) => {
    await resetHarness(request);
  });

  test('senza notizie dal SaaS si carica il banner vecchio', async ({ page }) => {
    await page.goto('/');
    const html = await page.content();

    expect(html).toContain('current/dist/oilstub.min.js');
    expect(html).toContain('current/dist/oil.min.js');
    expect(html).not.toContain('/v3/avacy-cmp-web.js');
  });

  test('un v2 scritto vale quanto l`assenza: oilstub e oil, come prima', async ({ page, request }) => {
    await loadBannerVersion(page, request, 'v2');

    await page.goto('/');
    const html = await page.content();

    expect(html).toContain('current/dist/oil.min.js');
    expect(html).not.toContain('/v3/avacy-cmp-web.js');
  });

  test('webspace v3 -> il tag singolo del loader, e nessuna traccia di OIL', async ({ page, request }) => {
    await loadBannerVersion(page, request, 'v3');

    await page.goto('/');
    const html = await page.content();

    expect(html).toContain(V3_TAG);
    // La prova al contrario: senza il ramo nuovo questi due ci sarebbero
    // ancora, ed e' esattamente il banner morto che stiamo chiudendo.
    expect(html).not.toContain('oilstub.min.js');
    expect(html).not.toContain('oil.min.js');
  });

  test('il tag v3 e` un <script src> classico, che il loader sa riconoscere', async ({ page, request }) => {
    await loadBannerVersion(page, request, 'v3');

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

  test('il giro delle regole non interpella il SaaS', async ({ request }) => {
    await setState(request, { avacy_test_scenario: 'vendor_with_rule', avacy_test_banner_version: 'v3' });

    await triggerRefresh(request);

    const state = await getState(request);
    // le regole sono arrivate dalla CDN...
    expect(state.blackListCount).toBe(1);
    // ...e il SaaS non e` stato toccato: la versione la scrive la pagina admin.
    expect(state.bannerVersion).toBe('');
    expect(state.requestLog.some((url: string) => url.includes('/wp/validate/'))).toBe(false);
  });

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
