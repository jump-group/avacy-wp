(function () {
    'use strict';

    var GCM_TO_WP = {
        preferences: ['personalization_storage'],
        statistics: ['analytics_storage'],
        'statistics-anonymous': ['analytics_storage'],
        marketing: ['ad_storage', 'ad_user_data', 'ad_personalization']
    };

    /**
     * Traduce i permessi GCM nelle categorie della WP Consent API.
     *
     * Accetta due forme perche' le due versioni del banner le mandano diverse:
     * il v2 una lista dei soli permessi concessi, il v3 l'oggetto a 7 chiavi.
     * Nessuna delle due significa «webspace senza GCM»: li' non sappiamo, e non
     * scriviamo.
     */
    function applyFromGcm(gcm) {
        if (typeof window.wp_set_consent !== 'function') {
            return;
        }

        // I cookie tecnici non dipendono dal GCM: si concedono sempre, e prima
        // dell'uscita anticipata qui sotto.
        window.wp_set_consent('functional', 'allow');

        var granted = Array.isArray(gcm)
            ? gcm
            : gcm && typeof gcm === 'object'
                ? Object.keys(gcm).filter(function (key) { return gcm[key] === 'granted'; })
                : null;

        if (granted === null) {
            return;
        }

        Object.keys(GCM_TO_WP).forEach(function (category) {
            var ok = GCM_TO_WP[category].some(function (flag) {
                return granted.indexOf(flag) !== -1;
            });
            window.wp_set_consent(category, ok ? 'allow' : 'deny');
        });
    }

    function detailOf(event) {
        return (event && event.detail) || {};
    }

    // In pagina c'e' un banner solo, quindi ne scatta sempre e solo uno:
    // l'adapter non ha bisogno di sapere quale versione sia installata.
    window.addEventListener('avacy_consent', function (event) {
        applyFromGcm(detailOf(event).gcm);
    });
    document.addEventListener('avacy:consent-saved', function (event) {
        applyFromGcm(detailOf(event).gcm);
    });
})();
