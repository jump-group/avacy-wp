<?php
/**
 * Solo per i Playground dei test: niente view transition di WordPress 7.1 nell'admin.
 * Col Chromium headless di Playwright 1.61 una navigazione da clic con la transizione
 * ferma il disegno della pagina: requestAnimationFrame non scatta più e i clic restano in attesa.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_enqueue_scripts', function () {
	wp_dequeue_style( 'wp-view-transitions-admin' );
}, PHP_INT_MAX );
