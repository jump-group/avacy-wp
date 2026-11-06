<?php
/**
 * Solo per i test Playwright: una casella di consenso facoltativa nel checkout, come la aggiunge un tema o un
 * plugin, e l'id del prodotto di prova. Inerte su ogni altro blueprint: parte solo se avacy_test_woo_enabled e' 'on'.
 */
if ( get_option( 'avacy_test_woo_enabled' ) !== 'on' ) {
	return;
}
add_filter( 'woocommerce_checkout_fields', function ( $fields ) {
	$fields['billing']['billing_privacy'] = [ 'type' => 'checkbox', 'label' => 'Accetto la privacy (facoltativo)', 'required' => false, 'class' => [ 'form-row-wide' ], 'priority' => 999 ];
	return $fields;
} );
add_action( 'init', function () {
	if ( strpos( $_SERVER['REQUEST_URI'] ?? '', '/avacy-test-woo-info/' ) === false ) {
		return;
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( [ 'product' => (int) get_option( 'avacy_test_woo_product' ) ] );
	exit;
}, 0 );
