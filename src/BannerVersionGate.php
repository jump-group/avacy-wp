<?php
namespace Jumpgroup\Avacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Gate puro: legge banner_config_version e basta, nessuna chiamata di rete.
 * Valore assente o sconosciuto = v2 (sbagliare verso v2 è innocuo, vedi 14f).
 * Il valore lo scrive AddAdminInterface::checkSaasAccount(), leggendolo dalla
 * risposta di /wp/validate/ che la pagina admin riceve comunque: nessuna
 * chiamata dedicata, e nessun aggiornamento periodico.
 */
class BannerVersionGate {

    const OPTION_VERSION = 'avacy_banner_config_version';

    public static function isV3() {
        return get_option(self::OPTION_VERSION) === 'v3';
    }

    /** Il valore cosi' com'e' salvato, per la UI. Stringa vuota = mai saputo. */
    public static function current() {
        return (string) get_option(self::OPTION_VERSION, '');
    }

    public static function store($rawValue) {
        update_option(self::OPTION_VERSION, $rawValue === 'v3' ? 'v3' : 'v2');
    }
}
