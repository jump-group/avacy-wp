<?php
// Negozio WooCommerce minimo con checkout classico e pagamento alla consegna.
kses_remove_filters();
$product = new WC_Product_Simple();
$product->set_name( 'Prodotto di prova' );
$product->set_regular_price( '10' );
$product->set_status( 'publish' );
$pid = $product->save();
update_option( 'avacy_test_woo_product', $pid );

update_option( 'woocommerce_cod_settings', [ 'enabled' => 'yes', 'title' => 'COD' ] );
update_option( 'woocommerce_default_country', 'IT' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );

$checkout = wc_get_page_id( 'checkout' );
if ( $checkout > 0 ) {
	wp_update_post( [ 'ID' => $checkout, 'post_content' => '[woocommerce_checkout]' ] );
}
$cart = wc_get_page_id( 'cart' );
if ( $cart > 0 ) {
	wp_update_post( [ 'ID' => $cart, 'post_content' => '[woocommerce_cart]' ] );
}
$terms = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Termini', 'post_name' => 'termini', 'post_content' => 'Termini e condizioni' ] );
update_option( 'woocommerce_terms_page_id', $terms );

// niente banner «coming soon» sopra il pulsante dell'ordine
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
