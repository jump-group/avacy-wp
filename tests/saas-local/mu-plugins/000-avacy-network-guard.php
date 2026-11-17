<?php
/**
 * Prova plugin → SaaS locale: WordPress parla solo con host locali.
 *
 * Carica per prima (nome 000-, le mu-plugin vanno in ordine alfabetico).
 * Due punti di controllo, perche' uno solo non basta:
 * - pre_http_request all'ultima priorita': nessun altro filtro puo' riaprire;
 * - requests.before_request: scatta anche su ogni redirect seguito da Requests,
 *   che pre_http_request non vede.
 * Ogni host tentato finisce nel log, con l'esito. Le righe della prova del blocco
 * sono marcate «probe»: il controllo finale di run.sh le distingue dai tentativi veri.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const AVACY_GUARD_EXACT = [ 'localhost', '127.0.0.1', '::1', '[::1]', 'host.docker.internal', 'apiavacy.test', 'avacy.test', 'mail.avacy.test' ];

function avacy_guard_log_file() {
	return WP_CONTENT_DIR . '/avacy-guard-log/hosts.log';
}

function avacy_guard_is_local( $url ) {
	$host = strtolower( rtrim( (string) parse_url( $url, PHP_URL_HOST ), '.' ) );
	if ( $host === '' ) {
		return false;
	}
	// i tenant del CDN locale (AVACY_CDN_URL = avacy.test) sono sottodomini dello stack
	return in_array( $host, AVACY_GUARD_EXACT, true ) || substr( $host, -strlen( '.avacy.test' ) ) === '.avacy.test';
}

function avacy_guard_log( $verdict, $url, $via ) {
	$host = (string) parse_url( $url, PHP_URL_HOST );
	$tag = empty( $GLOBALS['avacy_guard_probe'] ) ? 'test' : 'probe';
	$line = gmdate( 'c' ) . "\t" . $verdict . "\t" . $host . "\t" . $via . "\t" . $tag . "\t" . $url . "\n";
	@file_put_contents( avacy_guard_log_file(), $line, FILE_APPEND );
}

add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
	if ( avacy_guard_is_local( $url ) ) {
		avacy_guard_log( 'ALLOW', $url, 'pre_http_request' );
		return $preempt;
	}
	avacy_guard_log( 'BLOCK', $url, 'pre_http_request' );
	return new WP_Error( 'avacy_guard_blocked', 'Host non locale bloccato: ' . parse_url( $url, PHP_URL_HOST ) );
}, PHP_INT_MAX, 3 );

add_action( 'requests-requests.before_request', function ( &$url ) {
	if ( avacy_guard_is_local( $url ) ) {
		return;
	}
	avacy_guard_log( 'BLOCK', $url, 'requests.before_request' );
	throw new \WpOrg\Requests\Exception( 'Host non locale bloccato', 'avacy_guard_blocked' );
}, PHP_INT_MAX );
