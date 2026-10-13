<?php
namespace Jumpgroup\Avacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Gli indirizzi dei server di Avacy: il default e' la produzione, e da
 * `wp-config.php` si possono puntare altrove.
 *
 * Servono a provare il plugin contro un ambiente che non sia la produzione.
 * Una PR o lo staging hanno API e CDN su domini diversi, e senza queste due
 * costanti il plugin parla solo con api.avacy.eu e avacy-cdn.com - quindi
 * l'unico modo di provarlo e' il finto Avacy dei test, che verifica come si
 * comporta il plugin ma non che plugin e SaaS si capiscano.
 *
 * VANNO CAMBIATE TUTTE E DUE INSIEME. Cambiarne una sola lascia il sito a
 * meta' fra due ambienti - credenziali validate di qua, banner e lista
 * fornitori presi di la' - ed e' uno stato che sembra funzionare: costa ore
 * riconoscerlo, molto piu' di un sito rotto del tutto.
 *
 *   define('AVACY_API_URL', 'https://pr540.preview.avacy.eu');
 *   define('AVACY_CDN_URL', 'cdn-pr540.preview.avacy.eu');
 */
class Hosts {

    const DEFAULT_API = 'https://api.avacy.eu';
    const DEFAULT_CDN = 'avacy-cdn.com';

    /** Il dominio che il SaaS ha risposto, quando lo risponde. */
    const OPTION_CDN = 'avacy_cdn_url';

    /** Senza barra finale: chi la usa ci attacca il percorso. */
    public static function api() {
        $url = defined('AVACY_API_URL') ? trim((string) AVACY_API_URL) : '';

        return $url !== '' ? rtrim($url, '/') : self::DEFAULT_API;
    }

    /**
     * Solo il dominio: il tenant gli va davanti e il percorso dietro.
     *
     * Nell'ordine: la costante, perche' e' una scelta esplicita di chi tiene il
     * sito e deve vincere su tutto; poi quello che il SaaS ha risposto; poi la
     * produzione. Se il SaaS vincesse sulla costante, puntare il plugin a un
     * altro ambiente sarebbe una corsa contro il valore salvato l'ultima volta.
     */
    public static function cdn() {
        $constant = defined('AVACY_CDN_URL') ? trim((string) AVACY_CDN_URL) : '';
        if ($constant !== '') {
            return trim($constant, '/');
        }

        $fromSaas = trim((string) get_option(self::OPTION_CDN, ''));

        return $fromSaas !== '' ? $fromSaas : self::DEFAULT_CDN;
    }

    /**
     * Il dominio come lo risponde /wp/validate, salvato accanto alla versione
     * del banner: stessa chiamata, stesso momento, nessuna richiesta in piu'.
     *
     * Solo un nome di dominio. Quel valore finisce dentro gli URL da cui il sito
     * carica il banner, quindi uno schema, un percorso o una barra non ci
     * entrano: meglio restare sul dominio di prima che caricare da un posto
     * che non sappiamo leggere.
     */
    public static function storeCdn($rawValue) {
        $host = is_string($rawValue) ? trim($rawValue) : '';

        if ($host === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', $host)) {
            return;
        }

        update_option(self::OPTION_CDN, $host);
    }

    /** `https://{tenant}.{cdn}`, la forma in cui il SaaS scrive i suoi indirizzi. */
    public static function tenantCdn($tenant) {
        return 'https://' . $tenant . '.' . self::cdn();
    }
}
