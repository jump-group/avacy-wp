<?php

namespace Jumpgroup\Avacy\Integrations;

use Jumpgroup\Avacy\Interfaces\Integration;
use Jumpgroup\Avacy\SendFormsToConsentSolution;
use Jumpgroup\Avacy\FormSubmission;

class WooCommerceCheckoutForm implements Integration
{

    public static function listen() : void
    {
        add_action('woocommerce_thankyou', [__CLASS__, 'sendFormData']);
    }

    public static function convertToFormSubmission($order_id) : FormSubmission
    {
        ob_start();

        wc_get_template(
            'checkout/form-checkout.php',
            array(
                'checkout' => wc()->checkout(),
            )
        );

        $checkoutForm = ob_get_contents();
        ob_end_clean();

        $identifier = get_option('avacy_identifier'); // TODO: get identifier from settings
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        $proofs = json_encode($checkoutForm);
        $fields = []; // TODO: get fields using woocommerce api and/or hooks

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

        return new FormSubmission(
            fields: $fields,
            identifier: $identifier,
            ipAddress: $ipAddress,
            proofs: $proofs,
            legalNotices: $legalNotices,
            preferences: $preferences
        );
    }

    public static function sendFormData($order_id) : void
    {
        $form = self::convertToFormSubmission($order_id);
        SendFormsToConsentSolution::send($form);
    }

    public static function detectAllForms(): array {
        

        return [];
    }
}
