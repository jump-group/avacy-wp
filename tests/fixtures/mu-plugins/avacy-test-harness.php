<?php
/**
 * Solo per i test Playwright: intercetta le chiamate di rete del plugin
 * (CDN vendor list + /wp/validate/) e rende lo stato ispezionabile.
 * Inerte su ogni altro blueprint: parte solo se avacy_test_harness_enabled è 'on'.
 *
 * Il canale di controllo NON usa /wp-json/: il browser arriva già loggato
 * (blueprint con "login": true), e la REST API di WP pretende un nonce per
 * ogni richiesta con cookie di sessione, anche su una rotta con
 * permission_callback '__return_true'. Un front-controller su 'init' con un
 * percorso qualsiasi bypassa quel livello del tutto: è solo infrastruttura
 * di test, non ha bisogno delle garanzie della REST API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( get_option( 'avacy_test_harness_enabled' ) !== 'on' ) {
	return;
}

// #586: un altro plugin che usa una globale `$api_base_url` non deve spostare le chiamate di Avacy
if ( get_option( 'avacy_test_global_clash' ) === 'on' ) {
	add_action( 'plugins_loaded', function () {
		$GLOBALS['api_base_url'] = 'https://wrong.example';
	} );
}

// #586: una richiesta con ?avacy-test-cli si comporta come un comando WP-CLI
if ( isset( $_GET['avacy-test-cli'] ) && ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}

// #586: quante volte il plugin prende il lock del refresh
add_action( 'set_transient_avacy_preemptive_refresh_lock', function () {
	update_option( 'avacy_test_lock_writes', (int) get_option( 'avacy_test_lock_writes', 0 ) + 1, false );
} );

/** Com'e' nel blueprint `with-preemptive-block`: /reset ci riporta le credenziali. */
const AVACY_TEST_WEBSPACE_KEY = 'test-harness|11111111-1111-1111-1111-111111111111';

function avacy_test_json_response( $code, array $body ) {
	return [
		'response' => [ 'code' => $code, 'message' => '' ],
		'body' => wp_json_encode( $body ),
		'headers' => [],
		'cookies' => [],
	];
}

// Avvisi PHP (notice, warning, deprecated) partiti dal codice del plugin: un test li legge in `phpErrors`.
set_error_handler( function ( $no, $msg, $file, $line ) {
	if ( strpos( $file, '/plugins/avacy/' ) !== false && ( error_reporting() & $no ) ) {
		$log   = get_option( 'avacy_test_php_errors', [] );
		$log[] = basename( $file ) . ':' . $line . ' ' . $msg;
		update_option( 'avacy_test_php_errors', $log, false );
	}
	return false; // poi segue il gestore normale
} );

// la casella scelta per ogni caratteristica di consenso dei form, per nome di opzione
function avacy_test_consent_features() {
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'avacy\\_%\\_consent\\_feature\\_%'" );
	return wp_list_pluck( $rows, 'option_value', 'option_name' );
}

// gli switch «Save» dei form salvati finora, per nome di opzione
function avacy_test_form_switches() {
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'avacy\\_%\\_radio\\_enabled'" );
	return wp_list_pluck( $rows, 'option_value', 'option_name' );
}

// i campi dati scelti e il campo identificativo dei form, per nome di opzione
function avacy_test_form_data_options() {
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'avacy\\_form\\_field\\_%' OR option_name LIKE 'avacy\\_%\\_form\\_user\\_identifier'" );
	return wp_list_pluck( $rows, 'option_value', 'option_name' );
}

add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
	// il consenso di un form inviato: si registra e basta, il SaaS non serve
	if ( preg_match( '#/v4/webspaces/[^/]+/consents$#', $url ) ) {
		$log = get_option( 'avacy_test_consent_log', [] );
		$log[] = [ 'url' => $url, 'body' => $args['body'] ?? null, 'headers' => $args['headers'] ?? [] ];
		update_option( 'avacy_test_consent_log', $log, false );
		return avacy_test_json_response( 201, [ 'ok' => true ] );
	}

	$isVendorList = strpos( $url, 'custom-vendor-list.json' ) !== false;
	$isValidate = strpos( $url, '/wp/validate/' ) !== false;

	if ( ! $isVendorList && ! $isValidate ) {
		return $preempt;
	}

	update_option( 'avacy_test_last_request_url', $url, false );
	$log = get_option( 'avacy_test_request_log', [] );
	$log[] = $url;
	update_option( 'avacy_test_request_log', $log, false );

	$scenario = get_option( 'avacy_test_scenario', 'vendor_with_rule' );

	if ( $isVendorList ) {
		if ( $scenario === 'network_fail' || $scenario === 'all_fail' ) {
			return new WP_Error( 'avacy_test_fail', 'simulated vendor-list failure' );
		}

		// il secondo webspace ha un fornitore suo: serve a vedere di quale
		// webspace sono le regole in copia locale
		if ( strpos( $url, '22222222-2222-2222-2222-222222222222' ) !== false ) {
			return [
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'body' => wp_json_encode( [ 'vendors' => [ [
					'name' => 'Other Vendor',
					'id' => 'd222',
					'purposes' => [ 1 ],
					'blockUrls' => [ 'other.example.com/o.js' ],
				] ] ] ),
				'headers' => [],
				'cookies' => [],
			];
		}

		// `vendor_v3_shape`: le finalita' come oggetti {framework, id}, che e'
		// la forma del file su un webspace v3.
		if ( $scenario === 'vendor_v3_shape' ) {
			$vendors = [ [
				'name' => 'Test Vendor',
				'id' => 'd999',
				'purposes' => [
					[ 'framework' => 'tcf', 'id' => 1 ],
					[ 'framework' => 'gcm', 'id' => 3 ],
					[ 'framework' => 'tcf', 'id' => 8 ],
					[ 'framework' => 'bad;inject', 'id' => 4 ],
				],
				'blockUrls' => [ 'tracker.example.com/t.js' ],
			] ];
		} else {
			$vendors = ( $scenario === 'vendor_empty' ) ? [] : [ [
				'name' => 'Test Vendor',
				'id' => 'd999',
				'purposes' => [ 1, 8, 9 ],
				'blockUrls' => [ 'tracker.example.com/t.js' ],
			] ];
		}

		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body' => wp_json_encode( [ 'vendors' => $vendors ] ),
			'headers' => [],
			'cookies' => [],
		];
	}

	// /wp/validate/{team}/{uuid}, e con un segmento in piu' quella del token.
	if ( $scenario === 'all_fail' ) {
		return new WP_Error( 'avacy_test_fail', 'simulated validate failure' );
	}

	// il team c'e' ma il suo database no: il SaaS lo dice uguale su tutte e due le rotte
	if ( $scenario === 'tenant_database_missing' ) {
		return avacy_test_json_response( 503, [ 'message' => 'tenant_database_missing', 'error' => 'tenant_database_missing' ] );
	}

	if ( preg_match( '#/wp/validate/[^/?]+/[^/?]+/[^/?]+#', $url ) ) {
		switch ( $scenario ) {
			case 'token_unreachable':
				return new WP_Error( 'avacy_test_fail', 'simulated token-call failure' );
			case 'token_unavailable':
				return avacy_test_json_response( 503, [ 'message' => 'service_unavailable', 'error' => 'service_unavailable' ] );
			case 'token_denied':
				return avacy_test_json_response( 404, [ 'message' => 'token_not_found_or_expired', 'error' => 'token_not_found_or_expired' ] );
			case 'token_denied_legacy':
				// Un 404 che un codice non lo porta: nel dubbio non si cancella.
				return avacy_test_json_response( 404, [ 'message' => 'token_not_found_or_expired' ] );
			case 'token_invalid':
				return avacy_test_json_response( 404, [ 'message' => 'invalid_token', 'error' => 'invalid_token' ] );
		}

		return avacy_test_json_response( 200, [ 'message' => 'valid_token' ] );
	}

	switch ( $scenario ) {
		case 'validate_fail':
			return new WP_Error( 'avacy_test_fail', 'simulated validate failure' );
		case 'validate_unavailable':
			return avacy_test_json_response( 503, [ 'message' => 'service_unavailable', 'error' => 'service_unavailable' ] );
		case 'validate_denied':
			return avacy_test_json_response( 404, [ 'message' => 'team_not_found test-harness', 'error' => 'team_not_found' ] );
		case 'validate_denied_legacy':
			return avacy_test_json_response( 404, [ 'message' => 'team_not_found test-harness' ] );
		case 'validate_denied_webspace':
			return avacy_test_json_response( 404, [ 'message' => 'webspace_not_found', 'error' => 'webspace_not_found' ] );
		case 'validate_not_found':
			// Una rotta sbagliata, non un verdetto sulle credenziali.
			return avacy_test_json_response( 404, [ 'message' => 'The route could not be found.' ] );
		case 'validate_bad_gateway':
			// Un proxy che taglia: niente JSON, nessun codice da leggere.
			return [
				'response' => [ 'code' => 502, 'message' => 'Bad Gateway' ],
				'body' => '<html><body>502 Bad Gateway</body></html>',
				'headers' => [],
				'cookies' => [],
			];
	}

	$version = get_option( 'avacy_test_banner_version', 'v2' );

	// 200 che non porta la versione: un firewall o un proxy di mezzo, oppure un
	// SaaS che quel campo non lo conosce ancora.
	if ( $version === 'missing' ) {
		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body' => wp_json_encode( [ 'id' => 1 ] ),
			'headers' => [],
			'cookies' => [],
		];
	}

	return [
		'response' => [ 'code' => 200, 'message' => 'OK' ],
		'body' => wp_json_encode( [ 'id' => 1, 'banner_config_version' => $version ] ),
		'headers' => [],
		'cookies' => [],
	];
}, 10, 3 );

add_action( 'init', function () {
	$uri = $_SERVER['REQUEST_URI'] ?? '';

	if ( strpos( $uri, '/avacy-test-api/' ) === false ) {
		return;
	}

	// queste sono chiamate di controllo del test, non visite reali: non devono
	// innescare da sole il ripiego su shutdown (altrimenti /reset si vanifica
	// da solo, perché shutdown scatta comunque anche dopo un exit()).
	// la priorita' deve combaciare con quella di add_action, o non stacca niente
	remove_action(
		'shutdown',
		[ \Jumpgroup\Avacy\PreemptiveBlock::class, 'maybeRefreshOnShutdown' ],
		\Jumpgroup\Avacy\PreemptiveBlock::SHUTDOWN_PRIORITY
	);

	header( 'Content-Type: application/json' );
	$input = json_decode( (string) file_get_contents( 'php://input' ), true ) ?: [];

	if ( strpos( $uri, '/avacy-test-api/state' ) !== false ) {
		echo wp_json_encode( [
			'lastRequestUrl' => get_option( 'avacy_test_last_request_url', '' ),
			'requestLog' => get_option( 'avacy_test_request_log', [] ),
			'lockWrites' => (int) get_option( 'avacy_test_lock_writes', 0 ),
			'blackListCount' => \Jumpgroup\Avacy\PreemptiveBlock::getBlackListCount(),
			'blackListIds' => array_keys( get_option( 'avacy_preemptive_blacklist', [] ) ),
			'lastRefresh' => \Jumpgroup\Avacy\PreemptiveBlock::getLastRefreshTimestamp(),
			'bannerVersion' => get_option( \Jumpgroup\Avacy\BannerVersionGate::OPTION_VERSION, '' ),
			// il periodico esiste solo col blocco acceso: qui si vede se e' pianificato
			'cronScheduled' => (bool) wp_next_scheduled( \Jumpgroup\Avacy\PreemptiveBlock::CRON_HOOK ),
			'preemptiveBlock' => get_option( 'avacy_enable_preemptive_block', '' ),
			// intervallo dell'evento pianificato e dello schedule registrato, e quando scatta
			'cronInterval' => ( $avacyEvent = wp_get_scheduled_event( \Jumpgroup\Avacy\PreemptiveBlock::CRON_HOOK ) ) ? (int) $avacyEvent->interval : 0,
			'cronNext' => $avacyEvent ? (int) $avacyEvent->timestamp : 0,
			'scheduleInterval' => (int) ( wp_get_schedules()[ \Jumpgroup\Avacy\PreemptiveBlock::CRON_SCHEDULE ]['interval'] ?? 0 ),
			// cio' che il plugin cancella quando il SaaS dice che non esiste
			'tenant' => get_option( 'avacy_tenant', '' ),
			'webspaceKey' => get_option( 'avacy_webspace_key', '' ),
			'webspaceId' => get_option( 'avacy_webspace_id', '' ),
			'apiToken' => get_option( 'avacy_api_token', '' ),
			'consentLog' => get_option( 'avacy_test_consent_log', [] ),
			'formSwitches' => (object) avacy_test_form_switches(),
			'formDataOptions' => (object) avacy_test_form_data_options(),
			'consentFeatures' => (object) avacy_test_consent_features(),
			'phpErrors' => get_option( 'avacy_test_php_errors', [] ),
			'homeUrl' => home_url(),
			'showBanner' => get_option( 'avacy_show_banner', '' ),
		] );
		exit;
	}

	if ( strpos( $uri, '/avacy-test-api/set' ) !== false ) {
		// Elenco di cio' che e' permesso, non di cio' che e' vietato: i test usano
		// solo queste tre, e scrivere un'opzione qualsiasi da una richiesta non
		// autenticata non deve essere una cosa che questo file sa fare.
		// `avacy_enable_preemptive_block` e' del plugin, non del test: ci sta
		// perche' da essa dipende se il periodico viene pianificato, e un test
		// deve poterla spegnere senza un secondo blueprint.
		$allowed = [
			'avacy_test_scenario',
			'avacy_test_banner_version',
			'avacy_test_global_clash',
			'avacy_preemptive_refresh_last',
			'avacy_enable_preemptive_block',
			'avacy_show_banner',
			// le credenziali: il ramo che le cancella si raggiunge solo con un
			// webspace id salvato, che il blueprint non mette.
			'avacy_tenant',
			'avacy_webspace_key',
			'avacy_webspace_id',
			'avacy_api_token',
		];

		foreach ( $input as $key => $value ) {
			// lo switch «Save» di un form, i suoi campi scelti, il campo identificativo e le caratteristiche di consenso, con i nomi che gli da' la dashboard
			if ( in_array( $key, $allowed, true ) || preg_match( '/^avacy_(form_field_[A-Za-z0-9_]+|[a-z0-9_]+_radio_enabled|[a-z0-9_]+_form_user_identifier|[a-z0-9_]+_consent_feature_[a-z_]+)$/', $key ) ) {
				update_option( $key, $value );
			}
		}
		// uno scenario nuovo non deve trovare il lucchetto del precedente
		delete_transient( 'avacy_preemptive_refresh_lock' );
		echo wp_json_encode( [ 'ok' => true ] );
		exit;
	}

	// cambia la label di un campo di un form WPForms (come chi lo rinomina nel builder): l'id del campo non cambia
	if ( strpos( $uri, '/avacy-test-api/wpf-relabel' ) !== false ) {
		$post = get_post( (int) ( $input['form_id'] ?? 0 ) );
		$data = $post ? json_decode( $post->post_content, true ) : null;
		if ( ! is_array( $data ) || ! isset( $data['fields'][ $input['field_id'] ?? '' ] ) ) {
			status_header( 400 );
			echo wp_json_encode( [ 'ok' => false ] );
			exit;
		}
		$data['fields'][ $input['field_id'] ]['label'] = (string) $input['label'];
		wp_update_post( [ 'ID' => $post->ID, 'post_content' => wp_slash( wp_json_encode( $data ) ) ] );
		echo wp_json_encode( [ 'ok' => true ] );
		exit;
	}

	if ( strpos( $uri, '/avacy-test-api/reset' ) !== false ) {
		delete_option( 'avacy_preemptive_blacklist' );
		delete_option( 'avacy_preemptive_refresh_last' );
		delete_option( 'avacy_preemptive_refresh_ok' );
		delete_option( 'avacy_banner_config_version' );
		delete_option( 'avacy_cdn_url' );
		delete_option( 'avacy_test_last_request_url' );
		delete_option( 'avacy_test_request_log' );
		delete_option( 'avacy_test_lock_writes' );
		delete_option( 'avacy_test_consent_log' );
		delete_option( 'avacy_test_php_errors' );
		// gli switch «Save» dei form, i campi scelti e l'identificativo: un test non deve ereditare quelli del precedente
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'avacy\\_%\\_radio\\_enabled'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'avacy\\_form\\_field\\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'avacy\\_%\\_form\\_user\\_identifier'" );
		// la casella che accetta ogni caratteristica di consenso
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'avacy\\_%\\_consent\\_feature\\_%'" );
		wp_cache_delete( 'alloptions', 'options' );
		delete_option( 'avacy_test_scenario' );
		delete_option( 'avacy_test_banner_version' );
		delete_option( 'avacy_webspace_id' );
		delete_option( 'avacy_api_token' );
		delete_option( 'avacy_tenant' );
		update_option( 'avacy_webspace_key', AVACY_TEST_WEBSPACE_KEY );
		delete_transient( 'avacy_preemptive_refresh_lock' );
		// com'e' nel blueprint: un test che l'ha spenta non deve lasciarla cosi'
		// per quello dopo. Il cron si ripianifica da solo alla richiesta seguente.
		update_option( 'avacy_enable_preemptive_block', 'on' );
		echo wp_json_encode( [ 'ok' => true ] );
		exit;
	}

	// la disinstallazione vera di WordPress, senza cancellare i file (#586):
	// quali opzioni restano, e se il cron e il lock ci sono ancora
	if ( strpos( $uri, '/avacy-test-api/uninstall' ) !== false ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		// {"forget": true}: toglie la registrazione, che il plugin deve rifare alla richiesta dopo
		if ( ! empty( $input['forget'] ) ) {
			$plugins = (array) get_option( 'uninstall_plugins' );
			unset( $plugins['avacy/avacy.php'] );
			update_option( 'uninstall_plugins', $plugins );
			echo wp_json_encode( [ 'ok' => true ] );
			exit;
		}
		$read = function () use ( $input ) {
			$values = [];
			foreach ( $input['options'] ?? [] as $name ) {
				$values[ $name ] = get_option( $name, null );
			}
			return $values;
		};
		$registered = isset( ( (array) get_option( 'uninstall_plugins' ) )['avacy/avacy.php'] );
		$before = $read();
		$cronBefore = wp_next_scheduled( \Jumpgroup\Avacy\PreemptiveBlock::CRON_HOOK );
		uninstall_plugin( 'avacy/avacy.php' );
		echo wp_json_encode( [
			'registered' => $registered,
			'before' => $before,
			'after' => $read(),
			'cronBefore' => $cronBefore,
			'lock' => get_transient( \Jumpgroup\Avacy\PreemptiveBlock::LOCK_KEY ),
			'cron' => wp_next_scheduled( \Jumpgroup\Avacy\PreemptiveBlock::CRON_HOOK ),
		] );
		exit;
	}

	// invoca refreshRules() direttamente: e' quello che fanno il cron, il
	// ripiego su shutdown e il pulsante, senza passare dalla UI autenticata.
	if ( strpos( $uri, '/avacy-test-api/refresh' ) !== false ) {
		echo wp_json_encode( [ 'refreshed' => \Jumpgroup\Avacy\PreemptiveBlock::refreshRules() ] );
		exit;
	}

	// un sito aggiornato dalla 1.4.x: evento gia' pianificato con l'intervallo
	// di 6 ore e la prossima esecuzione tra 5 ore
	if ( strpos( $uri, '/avacy-test-api/legacy-cron' ) !== false ) {
		$hook = \Jumpgroup\Avacy\PreemptiveBlock::CRON_HOOK;
		wp_clear_scheduled_hook( $hook );
		$cron = _get_cron_array();
		$when = time() + 5 * HOUR_IN_SECONDS;
		$cron[ $when ][ $hook ][ md5( serialize( [] ) ) ] = [
			'schedule' => \Jumpgroup\Avacy\PreemptiveBlock::CRON_SCHEDULE,
			'args' => [],
			'interval' => 6 * HOUR_IN_SECONDS,
		];
		ksort( $cron );
		_set_cron_array( $cron );
		// com'e' ora, prima che una richiesta successiva lo riallinei
		echo wp_json_encode( [ 'interval' => (int) wp_get_scheduled_event( $hook )->interval ] );
		exit;
	}

	// pianta il lucchetto a mano: testa acquireLock() senza dover vincere una
	// vera gara di concorrenza contro il backend di wp-playground.
	if ( strpos( $uri, '/avacy-test-api/lock' ) !== false ) {
		set_transient( 'avacy_preemptive_refresh_lock', 1, 60 );
		echo wp_json_encode( [ 'ok' => true ] );
		exit;
	}
}, 0 );

/**
 * Pagina di test a contenuto fisso, fuori da post/pagine WP (niente KSES di
 * mezzo): passa comunque dai veri hook template_redirect/shutdown di
 * PreemptiveBlock, quindi l'intercettazione la vede come una pagina reale.
 */
add_action( 'template_redirect', function () {
	if ( strpos( $_SERVER['REQUEST_URI'] ?? '', '/avacy-test-page' ) === false ) {
		return;
	}

	$variant = isset( $_GET['variant'] ) ? sanitize_text_field( $_GET['variant'] ) : 'tracked';

	$bodies = [
		// accenti senza meta charset: verifica a mano del problema 2
		'tracked' => '<p>perché città</p><script src="https://tracker.example.com/t.js"></script>',
		'untracked' => '<script src="https://not-tracked.example.com/a.js"></script>',
		'inline' => '<script>var x = "tracker.example.com/t.js";</script>',
		// HTML e accenti dentro gli script: libxml li troncava a `</` (B1)
		'inline-html' => '<script>var s = "<div>a</div>"; var t = "città";</script><script>var x = "tracker.example.com/t.js"; var h = "<p>x</p>";</script>',
	];
	$body = $bodies[ $variant ] ?? $bodies['tracked'];

	// WP ha già deciso 404 per una URL senza rewrite rule: la sovrascriviamo
	status_header( 200 );
	echo '<!DOCTYPE html><html><head><title>avacy-test-page</title></head><body>' . $body . '</body></html>';
	exit;
}, 5 );
