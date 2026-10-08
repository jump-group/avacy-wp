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

        // Un banner per versione. Quale, lo dice BannerVersionGate, che si
        // aggiorna quando un amministratore apre la pagina Avacy; valore assente
        // = v2, quindi un sito che non ha ancora sentito il SaaS resta dov'era.
        if ( BannerVersionGate::isV3() ) {
            // Un tag solo, lo stesso che il pannello mostra a chi installa a
            // mano: il loader legge banner.json e carica da se' bundle e
            // add-on, stub TCF compreso. Niente oilstub qui, lo stub del v3 e'
            // un add-on. Versione `null`: a WordPress non interessa mettere un
            // `&ver=` su un file che la CDN versiona gia' per conto suo.
            wp_enqueue_script(
                'avacy-cmp-web',
                Hosts::tenantCdn($avacy_team) . '/v3/avacy-cmp-web.js?webspace=' . $avacy_uuid,
                array(),
                null,
                false
            );

            return;
        }

        wp_enqueue_script( 'avacy-stub', Hosts::tenantCdn($avacy_team) . '/current/dist/oilstub.min.js', array(), '1.0.0', false );
        wp_enqueue_script( 'avacy-oil', Hosts::tenantCdn($avacy_team) . '/current/dist/oil.min.js?team='.$avacy_team.'&uuid='.$avacy_uuid , array(), '1.0.0', false );
    }
}