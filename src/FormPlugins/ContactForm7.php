<?php

namespace Jumpgroup\Avacy\FormPlugins;

use WPCF7_Submission;
use Jumpgroup\Avacy\Interfaces\FormPlugin;
use Jumpgroup\Avacy\ConsentSolutionLogger;
use Jumpgroup\Avacy\ConsentForm;

class ContactForm7 implements FormPlugin
{

    public static function listen() : void
    {
        add_action('wpcf7_before_send_mail', [__CLASS__, 'sendFormData']);
    }

    public static function convertToConsentForm($contact_form) : ConsentForm
    {
        $submission = WPCF7_Submission::get_instance();
        $posted_data = $submission->get_posted_data();

        $identifier = get_option('avacy_identifier'); // TODO: get identifier from settings
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        $proofs = json_encode($contact_form->form);

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
            name: $posted_data['nome'],
            mail: $posted_data['email'],
            identifier: $identifier,
            ipAddress: $ipAddress,
            proofs: $proofs,
            legalNotices: $legalNotices,
            preferences: $preferences
        );
    }

    public static function sendFormData($contact_form) : void
    {
        $form = self::convertToConsentForm($contact_form);
        ConsentSolutionLogger::send($form);
    }
}
