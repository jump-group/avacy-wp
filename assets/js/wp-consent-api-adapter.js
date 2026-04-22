(function () {
    'use strict';

    var GCM_TO_WP = {
        preferences: ['personalization_storage'],
        statistics: ['analytics_storage'],
        'statistics-anonymous': ['analytics_storage'],
        marketing: ['ad_storage', 'ad_user_data', 'ad_personalization']
    };

    function applyFromGcm(gcm) {
        if (typeof window.wp_set_consent !== 'function') {
            return;
        }

        window.wp_set_consent('functional', 'allow');

        Object.keys(GCM_TO_WP).forEach(function (category) {
            var granted = GCM_TO_WP[category].some(function (flag) {
                return gcm.indexOf(flag) !== -1;
            });
            window.wp_set_consent(category, granted ? 'allow' : 'deny');
        });
    }

    window.addEventListener('avacy_consent', function (event) {
        var detail = (event && event.detail) || {};
        var gcm = Array.isArray(detail.gcm) ? detail.gcm : [];
        applyFromGcm(gcm);
    });
})();
