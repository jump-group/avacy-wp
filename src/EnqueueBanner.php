<?php
namespace Jumpgroup\Avacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class EnqueueBanner {

    public static function init() {
        add_action( 'wp_enqueue_scripts', [static::class, 'enqueueScripts'] );
    }

    public static function enqueueScripts() {
        $avacy_team = esc_attr(get_option('avacy_tenant'));

        $avacy_account_token = esc_attr(get_option('avacy_webspace_key'));
        if(strpos($avacy_account_token, '|')) {
            $avacy_account_token = explode('|', $avacy_account_token);
            $avacy_team = $avacy_account_token[0];
            $avacy_uuid = $avacy_account_token[1];
        } else {
            $avacy_team = esc_attr(get_option('avacy_tenant'));
            $avacy_uuid = esc_attr(get_option('avacy_webspace_key'));
        }

        if (!empty($avacy_team) && !empty($avacy_uuid)) {
            wp_enqueue_script( 'avacy-stub', 'https://' . $avacy_team . '.avacy-cdn.com/current/dist/oilstub.min.js', array(), '1.0.0', false );
            wp_enqueue_script( 'avacy-oil', 'https://' . $avacy_team . '.avacy-cdn.com/current/dist/oil.min.js?team='.$avacy_team.'&uuid='.$avacy_uuid , array(), '1.0.0', false );
        }
    }
}