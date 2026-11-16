<?php
/**
 * Test plugin → SaaS locale: canale di controllo del test, senza finto SaaS.
 * Inerte senza la costante AVACY_SAAS_LOCAL (la mette il blueprint saas-local).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'AVACY_SAAS_LOCAL' ) ) {
	return;
}

const AVACY_SAAS_LOCAL_FORMS = [ 'cf7-saas-local', 'cf7-solo-email' ];

function avacy_saas_local_log( $file, $line ) {
	@file_put_contents( WP_CONTENT_DIR . '/avacy-guard-log/' . $file, gmdate( 'c' ) . "\t" . $line . "\n", FILE_APPEND );
}

// senza la riga in /etc/hosts: indirizzo numerico e Host scritto a mano, Traefik instrada sull'Host
if ( defined( 'AVACY_SAAS_HOST_HEADER' ) ) {
	add_filter( 'http_request_args', function ( $args, $url ) {
		if ( strpos( $url, \Jumpgroup\Avacy\Hosts::api() . '/' ) === 0 ) {
			$args['headers']['Host'] = AVACY_SAAS_HOST_HEADER;
		}
		return $args;
	}, 10, 2 );
}

// il corpo del POST dei consensi come lo codifica Requests, senza html_form
add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
	if ( strpos( $url, '/consents' ) === false || ( $args['method'] ?? '' ) !== 'POST' ) {
		return $preempt;
	}
	$body = is_array( $args['body'] ) ? $args['body'] : [];
	unset( $body['html_form'] );
	avacy_saas_local_log( 'bodies.log', wp_json_encode( [ 'url' => $url, 'keys' => array_keys( $body ), 'encoded' => http_build_query( $body, '', '&' ) ] ) );
	return $preempt;
}, 10, 3 );

// le risposte del SaaS locale: stato e inizio del corpo
add_action( 'http_api_debug', function ( $response, $context, $class, $args, $url ) {
	$status = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response );
	$body = is_wp_error( $response ) ? '' : substr( wp_remote_retrieve_body( $response ), 0, 300 );
	avacy_saas_local_log( 'responses.log', ( $args['method'] ?? 'GET' ) . "\t" . $url . "\t" . $status . "\t" . $body );
}, 10, 5 );

function avacy_saas_local_form_id( $slug ) {
	$form = get_page_by_path( $slug, OBJECT, 'wpcf7_contact_form' );
	return $form ? $form->ID : 0;
}

add_action( 'init', function () {
	$uri = $_SERVER['REQUEST_URI'] ?? '';
	if ( strpos( $uri, '/avacy-saas-local/' ) === false ) {
		return;
	}
	header( 'Content-Type: application/json' );

	// un redirect verso la produzione: serve a provare che il blocco vede anche i salti
	if ( strpos( $uri, '/avacy-saas-local/redirect' ) !== false ) {
		header( 'Location: ' . \Jumpgroup\Avacy\Hosts::DEFAULT_API . '/' );
		status_header( 302 );
		exit;
	}

	// il resto solo con il segreto del giro (run.sh): REMOTE_ADDR in Playground vale sempre 127.0.0.1
	$secret = defined( 'AVACY_SAAS_LOCAL_SECRET' ) ? (string) AVACY_SAAS_LOCAL_SECRET : '';
	if ( $secret === '' || ! hash_equals( $secret, (string) ( $_SERVER['HTTP_X_AVACY_SAAS_LOCAL'] ?? '' ) ) ) {
		status_header( 403 );
		echo wp_json_encode( [ 'error' => 'forbidden' ] );
		exit;
	}

	// gli indirizzi che il plugin usa davvero, le credenziali salvate e gli id dei form
	if ( strpos( $uri, '/avacy-saas-local/state' ) !== false ) {
		$forms = [];
		foreach ( AVACY_SAAS_LOCAL_FORMS as $slug ) {
			$forms[ $slug ] = avacy_saas_local_form_id( $slug );
		}
		echo wp_json_encode( [
			'api' => \Jumpgroup\Avacy\Hosts::api(),
			'cdn' => \Jumpgroup\Avacy\Hosts::cdn(),
			'blockExternal' => defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL,
			'webspaceId' => get_option( 'avacy_webspace_id', '' ),
			'apiToken' => get_option( 'avacy_api_token', '' ),
			'forms' => $forms,
			// l'ultimo passo del blueprint crea le pagine: finche' mancano, Playground sta ancora preparando il sito
			'ready' => (bool) get_page_by_path( 'cf7-solo-email' ),
		] );
		exit;
	}

	// chiamate verso la produzione: devono tornare tutte errore del blocco
	if ( strpos( $uri, '/avacy-saas-local/probe' ) !== false ) {
		$GLOBALS['avacy_guard_probe'] = true;
		$out = [];
		$urls = [
			\Jumpgroup\Avacy\Hosts::DEFAULT_API . '/wp/validate/wpspike/x',
			'https://' . \Jumpgroup\Avacy\Hosts::DEFAULT_CDN . '/',
			'https://api.avacy.eu/',
			home_url( '/avacy-saas-local/redirect' ),
		];
		foreach ( $urls as $url ) {
			$r = wp_remote_get( $url, [ 'timeout' => 10 ] );
			$out[] = [
				'url' => $url,
				'error' => is_wp_error( $r ) ? $r->get_error_code() . ': ' . $r->get_error_message() : null,
				'status' => is_wp_error( $r ) ? null : wp_remote_retrieve_response_code( $r ),
			];
		}
		$GLOBALS['avacy_guard_probe'] = false;
		echo wp_json_encode( $out );
		exit;
	}

	// stato di un form CF7 scritto a mano (Save, identificativo, campi dati), come un form salvato prima della 1.5.0
	if ( strpos( $uri, '/avacy-saas-local/set-form' ) !== false ) {
		$id = avacy_saas_local_form_id( sanitize_key( $_GET['form'] ?? '' ) );
		foreach ( array_keys( wp_load_alloptions() ) as $k ) {
			if ( strpos( $k, 'avacy_form_field_wpcf7_' . $id . '_' ) === 0 ) {
				update_option( $k, 'off' );
			}
		}
		foreach ( array_filter( explode( ',', (string) ( $_GET['fields'] ?? '' ) ) ) as $f ) {
			update_option( 'avacy_form_field_wpcf7_' . $id . '_' . sanitize_key( $f ), 'on' );
		}
		update_option( \Jumpgroup\Avacy\SendFormsToConsentSolution::enabledOptionName( 'Contact Form 7', $id ), 'on' );
		update_option( \Jumpgroup\Avacy\SendFormsToConsentSolution::identifierOptionName( 'Contact Form 7', $id ), sanitize_key( $_GET['ident'] ?? '' ) );
		echo wp_json_encode( [ 'formId' => $id ] );
		exit;
	}
}, 0 );
