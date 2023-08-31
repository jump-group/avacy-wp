<?php

namespace Jumpgroup\Avacy\FormPlugins;

use Jumpgroup\Avacy\Interfaces\FormPlugin;
use Jumpgroup\Avacy\ConsentSolutionLogger;
use Jumpgroup\Avacy\ConsentForm;

class WooCommerceCheckoutForm implements FormPlugin
{

    public static function listen() : void
    {
        add_action('woocommerce_thankyou', [__CLASS__, 'sendFormData']);
    }

    public static function convertToConsentForm($order_id) : ConsentForm
    {
        $order = wc_get_order($order_id);

        $email = $order->get_billing_email();
        $name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $identifier = get_option('avacy_identifier'); // TODO: get identifier from settings
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        $proofs = json_encode('<html>...</html>');

        // TODO: get legal notices from settings
        $legalNotices = [
            ["name" => "privacy_policy"],
            ["name" => "cookie_policy"]
        ];

        // TODO: get preferences from settings
        $preferences = [
            [
                "name" => "newsletter",
                "accepted" => true
            ],
            [
                "name" => "updates",
                "accepted" => true
            ]
        ];

        return new ConsentForm(
            name: $name,
            mail: $email,
            identifier: $identifier,
            ipAddress: $ipAddress,
            proofs: $proofs,
            legalNotices: $legalNotices,
            preferences: $preferences
        );
    }

    public static function sendFormData($order_id) : void
    {
        $form = self::convertToConsentForm($order_id);
        ConsentSolutionLogger::send($form);
    }
}
