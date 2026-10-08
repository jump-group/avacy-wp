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

add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
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
			'blackListCount' => \Jumpgroup\Avacy\PreemptiveBlock::getBlackListCount(),
			'lastRefresh' => \Jumpgroup\Avacy\PreemptiveBlock::getLastRefreshTimestamp(),
			'bannerVersion' => get_option( \Jumpgroup\Avacy\BannerVersionGate::OPTION_VERSION, '' ),
			// il periodico esiste solo col blocco acceso: qui si vede se e' pianificato
			'cronScheduled' => (bool) wp_next_scheduled( \Jumpgroup\Avacy\PreemptiveBlock::CRON_HOOK ),
			'preemptiveBlock' => get_option( 'avacy_enable_preemptive_block', '' ),
			// cio' che il plugin cancella quando il SaaS dice che non esiste
			'tenant' => get_option( 'avacy_tenant', '' ),
			'webspaceKey' => get_option( 'avacy_webspace_key', '' ),
			'webspaceId' => get_option( 'avacy_webspace_id', '' ),
			'apiToken' => get_option( 'avacy_api_token', '' ),
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
			'avacy_preemptive_refresh_last',
			'avacy_enable_preemptive_block',
			// le credenziali: il ramo che le cancella si raggiunge solo con un
			// webspace id salvato, che il blueprint non mette.
			'avacy_tenant',
			'avacy_webspace_key',
			'avacy_webspace_id',
			'avacy_api_token',
		];

		foreach ( $input as $key => $value ) {
			if ( in_array( $key, $allowed, true ) ) {
				update_option( $key, $value );
			}
		}
		// uno scenario nuovo non deve trovare il lucchetto del precedente
		delete_transient( 'avacy_preemptive_refresh_lock' );
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

	// invoca refreshRules() direttamente: e' quello che fanno il cron, il
	// ripiego su shutdown e il pulsante, senza passare dalla UI autenticata.
	if ( strpos( $uri, '/avacy-test-api/refresh' ) !== false ) {
		echo wp_json_encode( [ 'refreshed' => \Jumpgroup\Avacy\PreemptiveBlock::refreshRules() ] );
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
	];
	$body = $bodies[ $variant ] ?? $bodies['tracked'];

	// WP ha già deciso 404 per una URL senza rewrite rule: la sovrascriviamo
	status_header( 200 );
	echo '<!DOCTYPE html><html><head><title>avacy-test-page</title></head><body>' . $body . '</body></html>';
	exit;
}, 5 );
