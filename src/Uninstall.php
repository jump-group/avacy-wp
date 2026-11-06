<?php

namespace Jumpgroup\Avacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// «Elimina» nella pagina Plugin (#586): via i dati tecnici e le credenziali.
// Le impostazioni restano: chi reinstalla ritrova banner e form come prima.
class Uninstall
{
    const OPTIONS = [
        // dati tecnici, il plugin li ricrea da solo
        PreemptiveBlock::OPTION_BLACKLIST,
        PreemptiveBlock::OPTION_LAST_REFRESH,
        PreemptiveBlock::OPTION_LAST_SUCCESS,
        BannerVersionGate::OPTION_VERSION,
        Hosts::OPTION_CDN,
        // credenziali, Access Key compresa
        'avacy_tenant',
        'avacy_webspace_key',
        'avacy_webspace_id',
        'avacy_api_token',
    ];

    public static function run() {
        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
        delete_transient(PreemptiveBlock::LOCK_KEY);
        PreemptiveBlock::clearScheduledRefresh();
    }
}
