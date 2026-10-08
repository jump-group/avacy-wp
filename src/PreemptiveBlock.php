<?php
namespace Jumpgroup\Avacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use DOMDocument;

class PreemptiveBlock {

    private static $blackList;

    const OPTION_BLACKLIST = 'avacy_preemptive_blacklist';
    const OPTION_LAST_REFRESH = 'avacy_preemptive_refresh_last';
    // Separato dal precedente: quello dice quando si e' tentato, questo quando
    // e' andata bene. Il pannello mostra il secondo, o direbbe «aggiornato»
    // anche dopo giorni di tentativi falliti.
    const OPTION_LAST_SUCCESS = 'avacy_preemptive_refresh_ok';
    const LOCK_KEY = 'avacy_preemptive_refresh_lock';
    const LOCK_TTL = 60; // secondi, protegge solo dalle chiamate in contemporanea
    const REFRESH_INTERVAL = 6 * HOUR_IN_SECONDS;
    // Finche' non e' mai riuscita non c'e' una copia da tenere buona: si
    // riprova spesso, ma si riprova, non a ogni visita.
    const BOOTSTRAP_RETRY_INTERVAL = 5 * MINUTE_IN_SECONDS;
    // Dichiarati, non lasciati al default di WordPress: un hosting che alza
    // `http_request_timeout` alzerebbe anche questa.
    const HTTP_TIMEOUT = 5;
    // Sullo shutdown a pagare l'attesa e' il lavoratore PHP del server, non
    // l'amministratore che guarda: si aspetta meno.
    const SHUTDOWN_HTTP_TIMEOUT = 3;
    const CRON_HOOK = 'avacy_preemptive_block_refresh';
    const CRON_SCHEDULE = 'avacy_six_hours';
    // Oltre la priorita' di output_end (100), e pubblica perche' chi vuole
    // staccare l'hook deve passare la stessa a remove_action().
    const SHUTDOWN_PRIORITY = 999;
    // Il file v2 porta le finalita' come numeri nudi, che nel vocabolario
    // legacy erano sempre e solo IAB. Il v3 dice di chi sono, una per una.
    const LEGACY_FRAMEWORK = 'tcf';

    /**
     * Chiamato sempre, ma a blocco spento non registra niente: serve solo a
     * staccare il cron rimasto da quando era acceso.
     */
    public static function registerRefreshHooks() {
        // Tutto quello che c'e' qui dentro riguarda le regole di blocco, e le
        // regole servono solo a chi il blocco lo usa: senza la casella, niente
        // periodico e niente pulsante. La versione del banner non passa piu' da
        // qui (PO, 05/10/2026).
        if (empty(get_option('avacy_enable_preemptive_block'))) {
            // Spento dopo essere stato acceso: il cron di prima resterebbe
            // pianificato, e continuerebbe a chiamare.
            if (wp_next_scheduled(self::CRON_HOOK)) {
                self::clearScheduledRefresh();
            }

            return;
        }

        // pulsante manuale nella scheda Preemptive Block (10d)
        add_action('admin_post_avacy_refresh_vendor_list', [static::class, 'handleRefresh']);

        add_filter('cron_schedules', [static::class, 'addCronSchedule']);

        // la prima volta è già coperta dal bootstrap sincrono al Salva e dal
        // ripiego su shutdown: il cron parte solo dopo il primo intervallo pieno
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + self::REFRESH_INTERVAL, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
        add_action(self::CRON_HOOK, [static::class, 'refreshRules']);

        // Ripiego per i siti col cron rotto: il controllo della data costa zero,
        // la chiamata no. Priorita' oltre quella di output_end (100), altrimenti
        // il visitatore aspetta la rete con la pagina gia' pronta nel buffer.
        add_action('shutdown', [static::class, 'maybeRefreshOnShutdown'], self::SHUTDOWN_PRIORITY);
    }

    public static function clearScheduledRefresh() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    // per la scheda Preemptive Block (10d): quante regole, aggiornate quando
    public static function getBlackListCount() {
        return count(get_option(self::OPTION_BLACKLIST, []));
    }

    public static function getLastRefreshTimestamp() {
        return (int) get_option(self::OPTION_LAST_SUCCESS, 0);
    }

    /**
     * `display` non tradotto di proposito: il filtro scatta a `plugins_loaded`, e
     * da WP 6.7 una `__()` prima di `init` stampa una notice che rompe i
     * redirect. L'etichetta la leggono solo strumenti tipo WP Crontrol.
     */
    public static function addCronSchedule($schedules) {
        $schedules[self::CRON_SCHEDULE] = [
            'interval' => self::REFRESH_INTERVAL,
            'display' => 'Every 6 hours (Avacy)',
        ];
        return $schedules;
    }

    /**
     * Legge la copia locale: nessuna chiamata di rete da qui, mai.
     * Si registra l'intercettazione solo se c'è davvero qualcosa da bloccare.
     */
    public static function init() {
        self::$blackList = get_option(self::OPTION_BLACKLIST, []);

        if (empty(self::$blackList)) {
            return;
        }

        add_action( 'template_redirect', [static::class, 'output_start'], 0 );
        add_action( 'shutdown', [static::class, 'output_end'], 100 );
    }

    /**
     * Il ripiego per i siti col cron rotto: stesso giro, stesse regole. Il terzo
     * innesco e' il pulsante, che chiama refreshRules() senza passare da qui.
     * Se la chiamata fallisce la copia locale resta quella di prima.
     */
    public static function maybeRefreshOnShutdown() {
        if (wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        $interval = empty(get_option(self::OPTION_LAST_SUCCESS))
            ? self::BOOTSTRAP_RETRY_INTERVAL
            : self::REFRESH_INTERVAL;

        $lastRefresh = get_option(self::OPTION_LAST_REFRESH);
        if ($lastRefresh && (time() - (int) $lastRefresh) < $interval) {
            return;
        }

        self::refreshRules(self::SHUTDOWN_HTTP_TIMEOUT);
    }

    /**
     * Le regole di blocco, e nient'altro: la versione del banner la scrive
     * `AddAdminInterface::checkSaasAccount()`, da una risposta che la pagina
     * admin riceve comunque. Chiamato solo dove il blocco e' acceso.
     */
    public static function refreshRules($timeout = self::HTTP_TIMEOUT) {
        // A blocco spento non c'e' niente da scaricare, e un giro a vuoto
        // timbrerebbe «aggiornato»: all'accensione successiva il bootstrap non
        // partirebbe, e la lista resterebbe vuota fino al cron dopo.
        if (empty(get_option('avacy_enable_preemptive_block'))) {
            return false;
        }

        if (!self::acquireLock()) {
            return false;
        }

        [$tenant, $webSpaceKey] = self::resolveCredentials();

        if (empty($tenant) || empty($webSpaceKey)) {
            self::releaseLock();
            return false;
        }

        $blackListOk = self::refreshBlackList($tenant, $webSpaceKey, $timeout);

        // Il tentativo si segna sempre, riuscito o no: e' l'unica cosa che
        // trattiene `maybeRefreshOnShutdown` dal chiamare la rete a ogni visita.
        // Quanto aspettare prima del prossimo lo decide quello, sulla data di
        // riuscita qui sotto.
        update_option(self::OPTION_LAST_REFRESH, time());
        if ($blackListOk) {
            update_option(self::OPTION_LAST_SUCCESS, time());
        }
        self::releaseLock();

        return true;
    }

    public static function handleRefresh() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'avacy'));
        }
        check_admin_referer('avacy_refresh_vendor_list');

        self::refreshRules();

        set_transient('avacy_active_tab', 'preemptive-block', 30);
        // Il referer non e' garantito: manca su una richiesta diretta, e puo'
        // mancare anche dietro un proxy o una Referrer-Policy stretta. Senza
        // ripiego, wp_safe_redirect(false) non manda nessun header e l'exit qui
        // sotto lascia l'utente su una pagina bianca.
        $back = wp_get_referer();
        wp_safe_redirect($back ?: admin_url('admin.php?page=avacy-plugin-settings'));
        exit;
    }

    private static function resolveCredentials() {
        $webSpaceKey = get_option('avacy_webspace_key');
        $tenant = '';

        if (empty($webSpaceKey)) {
            return ['', ''];
        }

        if (strpos($webSpaceKey, '|') === false) {
            $tenant = get_option('avacy_tenant');
        } else {
            [$tenant, $webSpaceKey] = explode('|', $webSpaceKey);
        }

        return [$tenant, $webSpaceKey];
    }

    private static function refreshBlackList($tenant, $webSpaceKey, $timeout = self::HTTP_TIMEOUT) {
        // Stesso indirizzo che il SaaS scrive nella configurazione del banner:
        // `https://{tenant}.avacy-cdn.com/config/{tenant}/{uuid}/...` (VendorService).
        // Il terzo livello era fisso su `assets`, e reggeva solo perche' la CDN
        // risponde su qualunque sottodominio.
        $url = Hosts::tenantCdn($tenant) . '/config/' . $tenant . '/' . $webSpaceKey . '/custom-vendor-list.json';
        $response = wp_remote_get($url, ['timeout' => $timeout]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false; // CDN giù: la copia locale non si tocca
        }

        $payload = json_decode(wp_remote_retrieve_body($response), true);

        // Una 200 non basta: la CDN risponde su qualunque sottodominio, e una
        // sua pagina d'errore svuoterebbe le regole. Il SaaS scrive sempre la
        // chiave `vendors`, anche vuota (VendorService): se manca, non e' la lista.
        if (!is_array($payload) || !isset($payload['vendors']) || !is_array($payload['vendors'])) {
            return false;
        }

        $blackList = [];

        foreach ($payload['vendors'] as $vendor) {
            // Stesso filtro di framework e finalita': l'id finisce nello stesso
            // attributo e con gli stessi separatori.
            if (!self::isSafeToken($vendor['id'] ?? null, '/^[A-Za-z0-9_.-]{1,64}$/')) {
                continue;
            }

            // Senza URL non c'e' regola: src_contains e inner_html_contains
            // iterano `sources`, e con `sources` vuoto non trovano mai nulla.
            $sources = self::safeSources($vendor['blockUrls'] ?? []);

            if (empty($sources)) {
                continue;
            }

            // Nessun ripiego sulle finalita': una lista vuota nel file vuol dire
            // che non ne serve nessuna, e inventarsene una fa chiedere al
            // visitatore un permesso che il SaaS non ha mai richiesto.
            // Indicizzate per id, non per nome: l'id e' unico per costruzione ed e'
            // gia' validato, mentre due fornitori omonimi si sovrascriverebbero -
            // le regole del primo sparirebbero senza un errore, e il conteggio
            // mostrato al cliente sembrerebbe giusto.
            $blackList[$vendor['id']] = [
                'attribute' => 'data-custom-vendor',
                'purposes' => self::groupPurposesByFramework($vendor['purposes'] ?? []),
                // Il file le manda senza framework, e sono solo IAB: prendono quello legacy.
                'specialFeatures' => self::safeIds($vendor['specialFeatures'] ?? []),
                'id' => $vendor['id'],
                'sources' => $sources,
            ];
        }

        // autoload off: può pesare decine di KB, non deve appesantire ogni richiesta
        update_option(self::OPTION_BLACKLIST, $blackList, false);

        return true;
    }

    private static function acquireLock() {
        if (get_transient(self::LOCK_KEY)) {
            return false;
        }

        set_transient(self::LOCK_KEY, 1, self::LOCK_TTL);
        return true;
    }

    private static function releaseLock() {
        delete_transient(self::LOCK_KEY);
    }

    public static function output_start() {
        if ( !is_admin() && !wp_doing_ajax() && !defined('REST_REQUEST') ){ // portare le stesse condizioni anche nell'output_end
            if(!empty(get_option('avacy_enable_preemptive_block'))) {
                ob_start([static::class, 'output_callback']);
            }
        }
    }

    public static function output_callback( $buffer ) {
        if (empty($buffer)) {
            return $buffer;
        }

        $dom = new DOMDocument();
        $previousErrorSetting = libxml_use_internal_errors(true);

        // il PI iniettato forza l'UTF-8: senza, libxml ripiega su ISO-8859-1
        // e rompe gli accenti su tutta la pagina, non solo sugli script (problema 2)
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $buffer,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorSetting);

        if (!$loaded) {
            return $buffer; // smontaggio fallito: meglio l'originale di una pagina monca (problema 3)
        }

        foreach ($dom->childNodes as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $dom->removeChild($node);
                break;
            }
        }

        $isV3 = BannerVersionGate::isV3();
        $scripts = $dom->getElementsByTagName('script');

        foreach($scripts as $script) {
            // Solo quello che il browser eseguirebbe: un `application/ld+json`
            // non e' codice, e marcarlo lo toglie dalla pagina (i motori di
            // ricerca non lo leggono piu') per poi farlo eseguire come JS.
            if (!self::isExecutableScript($script)) {
                continue;
            }

            $src = $script->getAttribute('src');

            if (($src !== '' && ($emt = self::src_contains($src, self::$blackList))) ||
                ($emt = self::inner_html_contains($script, self::$blackList))) {

                if ($isV3) {
                    self::markAsV3($script, $src, $emt);
                } else {
                    self::markAsV2($script, $src, $emt);
                }
            }
        }

        return $dom->saveHTML();
    }

    // webspace v2: marcatura legacy, letta anche dal modulo v3 (default sicuro, 14f)
    private static function markAsV2($script, $src, $emt) {
        // Letto prima di sovrascriverlo: e' il tipo a cui lo script va riportato.
        $type = self::originalType($script);

        $script->setAttribute('type', 'as-oil');
        $script->setAttribute('data-src', $src);
        $script->setAttribute('data-managed', 'as-oil');
        $script->setAttribute('data-type', $type);
        $script->setAttribute($emt['attribute'], $emt['id']);
        // Solo le IAB: il banner legacy non sa leggere le altre, e su un
        // webspace v2 il file non ne porta di altre.
        self::setIdsAttribute($script, 'data-purposes', $emt['purposes'][self::LEGACY_FRAMEWORK] ?? []);
        self::setIdsAttribute($script, 'data-special-features', $emt['specialFeatures'] ?? []);
    }

    /**
     * Per il banner legacy un attributo presente ma vuoto vuol dire «servono
     * TUTTE»: se non c'e' niente da chiedere l'attributo non si scrive, o il
     * significato si ribalta. Vale per data-purposes, data-legints e
     * data-special-features allo stesso modo.
     */
    private static function setIdsAttribute($script, $name, $ids) {
        if (!empty($ids)) {
            $script->setAttribute($name, implode(',', $ids));
        }
    }

    /**
     * Le finalita' del file raggruppate per framework. Qui l'elenco dei
     * framework non si conosce e non si vuole conoscerlo: si raggruppa per
     * quello che il file dichiara, cosi' un framework nuovo arriva in pagina
     * senza che questo codice venga toccato.
     */
    private static function groupPurposesByFramework($purposes) {
        $grouped = [];

        foreach (is_array($purposes) ? $purposes : [] as $purpose) {
            if (is_array($purpose)) {
                $framework = isset($purpose['framework']) ? (string) $purpose['framework'] : '';
                $id = $purpose['id'] ?? null;
            } else {
                $framework = self::LEGACY_FRAMEWORK;
                $id = $purpose;
            }

            // Il nome finisce dentro un attributo con `;` e `:` come separatori:
            // quello che non e' un nome di framework non entra in pagina.
            if (!self::isSafeToken($framework, '/^[A-Za-z0-9_-]{1,32}$/')) {
                continue;
            }
            if (!self::isSafeToken($id, '/^[A-Za-z0-9_.-]{1,64}$/')) {
                continue;
            }

            $grouped[$framework][] = (string) $id;
        }

        return $grouped;
    }

    // webspace v3: vocabolario nuovo, porta anche il "di chi" della finalità
    private static function markAsV3($script, $src, $emt) {
        $type = self::originalType($script);

        $script->setAttribute('type', 'text/avacy-blocked');
        $script->setAttribute('data-avacy-src', $src);
        $script->setAttribute('data-avacy-type', $type);
        $script->setAttribute('data-avacy-requires', self::requiresOf($emt));
    }

    /**
     * `;` e `:` separano i pezzi di data-avacy-requires: quello che non e' un
     * nome o un id non entra in pagina. Oggi il file lo scriviamo noi e questi
     * valori sono sempre a posto - e' una difesa, non una toppa.
     */
    private static function isSafeToken($value, $pattern) {
        return is_scalar($value) && preg_match($pattern, (string) $value);
    }

    /**
     * `module` cambia come il browser esegue il file: perderlo rompe ogni
     * script che usa `import`. Senza tipo il default e' `text/javascript`, ed
     * e' quello a cui si riporta.
     */
    private static function originalType($script) {
        $type = trim($script->getAttribute('type'));

        return $type !== '' ? $type : 'text/javascript';
    }

    /**
     * Un <script> che il browser esegue davvero: tipo assente, `module`, o un
     * MIME JavaScript, senza distinguere maiuscole (la lista dello standard
     * HTML, compresi i vecchi `javascript1.x`, `jscript` e `livescript`).
     * Tutto il resto - dati strutturati, template - e' un blocco di dati,
     * non codice, e non va toccato.
     */
    private static function isExecutableScript($script) {
        $type = trim($script->getAttribute('type'));

        return $type === ''
            || strcasecmp($type, 'module') === 0
            || preg_match('#^(?:(?:text|application)/(?:x-)?(?:java|ecma)script|text/javascript1\.[0-5]|text/(?:jscript|livescript))$#i', $type) === 1;
    }

    /**
     * Le sorgenti decidono cosa viene bloccato, e sono l'unico campo del file
     * che finisce dentro `str_contains`: una stringa vuota li' e' contenuta in
     * qualunque indirizzo, quindi bloccherebbe ogni script della pagina.
     */
    private static function safeSources($sources) {
        $safe = [];

        foreach (is_array($sources) ? $sources : [] as $source) {
            if (is_string($source) && trim($source) !== '') {
                $safe[] = trim($source);
            }
        }

        return $safe;
    }

    /** Gli id che possono entrare in un attributo: quello che non lo e' non ci arriva. */
    private static function safeIds($ids) {
        $safe = [];

        foreach (is_array($ids) ? $ids : [] as $id) {
            if (self::isSafeToken($id, '/^[A-Za-z0-9_.-]{1,64}$/')) {
                $safe[] = (string) $id;
            }
        }

        return $safe;
    }

    /**
     * `custom:vendor:d116;tcf:purpose:1,8;gcm:purpose:3` - un gruppo per
     * framework, nell'ordine in cui il file li presenta.
     */
    private static function requiresOf($emt) {
        $tokens = ['custom:vendor:' . $emt['id']];

        foreach ($emt['purposes'] as $framework => $ids) {
            $tokens[] = $framework . ':purpose:' . implode(',', $ids);
        }

        if (!empty($emt['specialFeatures'])) {
            $tokens[] = self::LEGACY_FRAMEWORK . ':specialFeature:' . implode(',', $emt['specialFeatures']);
        }

        return implode(';', $tokens);
    }

    public static function output_end() {
        if ( ! is_admin() && !wp_doing_ajax() && !defined('REST_REQUEST') && ob_get_level() )
            ob_end_flush();
    }

    private static function src_contains($src, $blackList) {
        foreach($blackList as $item) {
            foreach($item['sources'] as $source) {
                if (str_contains($src, $source) !== false) {
                    return $item;
                }
            }
        }

        return false;
    }

    private static function inner_html_contains($node, $blackList) {
        $innerHTML = $node->textContent;

        foreach($blackList as $item) {
            foreach($item['sources'] as $source) {
                if (str_contains($innerHTML, $source) !== false) {
                    return $item;
                }
            }
        }

        return null;
    }
}
