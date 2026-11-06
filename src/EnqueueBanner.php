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

        if (empty($avacy_team) || empty($avacy_uuid)) {
            return;
        }

        // Un tag solo per tutti i webspace, lo stesso del template GTM e dello
        // snippet manuale: e' la CDN a servire il loader giusto (v2 o v3) in
        // base al pin del webspace. Il loader legge banner.json e carica da se'
        // bundle e add-on, stub TCF compreso. Versione `null`: a WordPress non
        // interessa mettere un `&ver=` su un file che la CDN versiona gia'.
        wp_enqueue_script(
            'avacy-cmp-web',
            Hosts::tenantCdn($avacy_team) . '/v3/avacy-cmp-web.js?webspace=' . $avacy_uuid,
            array(),
            null,
            false
        );
    }
}