<?php
namespace Jumpgroup\Avacy\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WpConsentApi {

    public static function init(): void {
        add_action( 'plugins_loaded', [ static::class, 'boot' ], 20 );
    }

    public static function boot(): void {
        if ( ! self::isActive() ) {
            return;
        }

        $basename = plugin_basename( AVACY_PLUGIN_BASE_PATH );
        add_filter( "wp_consent_api_registered_{$basename}", '__return_true' );

        add_filter( 'wp_get_consent_type', [ static::class, 'forceOptin' ] );

        add_action( 'wp_enqueue_scripts', [ static::class, 'enqueueAdapter' ], 20 );
    }

    public static function isActive(): bool {
        return function_exists( 'wp_has_consent' );
    }

    public static function forceOptin( $type ) {
        return $type ?: 'optin';
    }

    public static function enqueueAdapter(): void {
        $plugin_file = WP_PLUGIN_DIR . '/' . AVACY_PLUGIN_BASE_PATH;
        $asset_path  = plugin_dir_url( $plugin_file ) . 'assets/js/wp-consent-api-adapter.js';
        $asset_ver   = file_exists( $plugin_file ) ? (string) filemtime( dirname( $plugin_file ) . '/assets/js/wp-consent-api-adapter.js' ) : '1.0.0';

        wp_enqueue_script(
            'avacy-wp-consent-adapter',
            $asset_path,
            [],
            $asset_ver,
            false
        );
    }
}
