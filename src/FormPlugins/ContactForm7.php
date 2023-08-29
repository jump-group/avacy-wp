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

        return new ConsentForm(
            name: $posted_data['your-name'],
            mail: $posted_data['your-email'],
            message: $posted_data['your-message']
        );
    }

    public static function sendFormData($contact_form) : void
    {
        $form = self::convertToConsentForm($contact_form);
        ConsentSolutionLogger::send($form);
    }
}
