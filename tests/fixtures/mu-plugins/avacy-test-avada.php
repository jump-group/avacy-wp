<?php
/**
 * Solo per i test Playwright: simula Avada Forms senza il suo codice.
 * Registra il post type `fusion_form` e fa scattare l'hook che Avada lancia
 * a ogni invio AJAX, con i dati nella forma vera. Inerte se avacy_test_avada_enabled non e' 'on'.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( get_option( 'avacy_test_avada_enabled' ) !== 'on' ) {
	return;
}

add_action( 'init', function () {
	// Avada spento: il tipo non c'e', come quando il plugin e' disattivato (#747)
	if ( get_option( 'avacy_test_avada_off' ) === 'on' ) {
		return;
	}
	register_post_type( 'fusion_form', [ 'public' => false, 'label' => 'Avada Forms' ] );
} );

// POST /avacy-test-avada/submit {"data": {...}, "hidden"?: [...], "form_id"?: n, "status"?: "success"|"error", "version"?: "3.16.4",
//   "downgrade"?: true (un altro plugin trasforma il successo in errore), "saas_fails"?: true (l'invio al SaaS stampa e lancia un'eccezione)}
// come ajax_form_submit() di Avada: l'hook coi dati, poi le azioni, poi la risposta col loro esito (da Builder 3.11.6)
add_action( 'init', function () {
	if ( strpos( $_SERVER['REQUEST_URI'] ?? '', '/avacy-test-avada/' ) === false ) {
		return;
	}

	header( 'Content-Type: application/json' );
	$form = get_page_by_path( 'avacy-avada-test', OBJECT, 'fusion_form' );

	if ( strpos( $_SERVER['REQUEST_URI'], '/avacy-test-avada/form' ) !== false ) {
		echo wp_json_encode( [ 'id' => $form->ID ] );
		exit;
	}

	// POST /avacy-test-avada/plugin {"off": true|false}: spegne o riaccende Avada dalla richiesta dopo
	if ( strpos( $_SERVER['REQUEST_URI'], '/avacy-test-avada/plugin' ) !== false ) {
		$input = json_decode( (string) file_get_contents( 'php://input' ), true ) ?: [];
		update_option( 'avacy_test_avada_off', empty( $input['off'] ) ? '' : 'on' );
		echo wp_json_encode( [ 'ok' => true ] );
		exit;
	}

	// POST /avacy-test-avada/save-dashboard : il salvataggio della dashboard con in piu' un form Avada vuoto, e i suoi avvisi PHP
	if ( strpos( $_SERVER['REQUEST_URI'], '/avacy-test-avada/save-dashboard' ) !== false ) {
		wp_insert_post( [ 'post_type' => 'fusion_form', 'post_status' => 'publish', 'post_title' => 'Vuoto', 'post_content' => '[fusion_form_submit /]' ] );
		wp_set_current_user( 1 );
		$_REQUEST['option_page'] = 'avacy-plugin-settings-group';
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'avacy-plugin-settings-group-options' );
		$errors = [];
		set_error_handler( function ( $no, $message ) use ( &$errors ) {
			$errors[] = $message;
			return true;
		} );
		\Jumpgroup\Avacy\AddAdminInterface::saveFields();
		restore_error_handler();
		echo wp_json_encode( [ 'errors' => $errors ] );
		exit;
	}

	$input = json_decode( (string) file_get_contents( 'php://input' ), true ) ?: [];
	if ( ! empty( $input['downgrade'] ) ) {
		add_filter( 'awb_form_return_message', function ( $message ) {
			return [ 'status' => 'error', 'info' => 'form_failed' ];
		}, 20 );
	}
	if ( ! empty( $input['saas_fails'] ) ) {
		add_filter( 'pre_http_request', function () {
			echo 'output del SaaS';
			throw new Exception( 'SaaS non raggiungibile' );
		}, 0 );
	}
	$version = $input['version'] ?? '3.16.4';
	$form_id = $input['form_id'] ?? $form->ID;
	define( 'FUSION_BUILDER_VERSION', $version );
	// gli avvisi PHP dell'invio, che su un sito con WP_DEBUG finirebbero nella risposta di Avada
	$errors = [];
	set_error_handler( function ( $no, $message ) use ( &$errors ) {
		$errors[] = $message;
		return true;
	} );
	do_action( 'fusion_form_submission_data', [
		'submission' => [
			'form_id' => 1,
			'time' => gmdate( 'Y-m-d H:i:s' ),
			'source_url' => home_url( '/' ),
			'post_id' => 0,
			'user_id' => 0,
			'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
		],
		'data' => $input['data'],
		'field_labels' => [],
		'field_types' => [],
		'hidden_field_names' => $input['hidden'] ?? [],
		'fields_holding_privacy_data' => [],
	], $form_id );
	if ( version_compare( $version, '3.11.6', '>=' ) ) {
		apply_filters( 'awb_form_return_message', [ 'status' => $input['status'] ?? 'success', 'info' => 'form_submitted' ], $form_id, [] );
	}
	restore_error_handler();
	echo wp_json_encode( [ 'ok' => true, 'errors' => $errors ] );
	exit;
}, 1 );
