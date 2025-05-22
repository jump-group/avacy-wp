<?php
namespace Jumpgroup\Avacy\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Jumpgroup\Avacy\Form;
use WPCF7_Submission;
use Jumpgroup\Avacy\Interfaces\Form as FormInterface;
use Jumpgroup\Avacy\SendFormsToConsentSolution;
use Jumpgroup\Avacy\FormSubmission;
use WP_Query;
use WPCF7_ContactForm;

class ContactForm7 implements FormInterface
{

    public static function listen() : void
    {
        add_action('wpcf7_before_send_mail', [__CLASS__, 'sendFormData']);
    }

    public static function convertToFormSubmission($contact_form) : FormSubmission
    {
        $submission = WPCF7_Submission::get_instance();
        $posted_data = $submission->get_posted_data();
        $id = $contact_form->id();

        $fields = self::getFields($id);
        $selectedFields = [];
        foreach($fields as $field) {
            if(!empty($field)) {
                $selectedFields[] = [
                    'label' => $field,
                    'value' => sanitize_text_field($posted_data[$field])
                ];
            }
        }

        $identifierKey = get_option('avacy_contact_form_7_'. $id .'_form_user_identifier'); // TODO: get identifier from settings
        $remoteAddr = sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
        $ipAddress = $remoteAddr ?: '0.0.0.0';
        $proof = self::getHTMLForm($id);
        $consentData = wp_json_encode($selectedFields);
        $identifier = $posted_data[$identifierKey] ?? null;
        $consentFeatures = [
            'privacy_policy',
            'cookie_policy'
        ];

        $sub = new FormSubmission(
            $ipAddress,
            'form',
            'accepted',
            $consentData,
            $identifier,
            'plugin',
            $consentFeatures,
            $proof
        );

        return $sub;
    }

    public static function sendFormData($contact_form) : void
    {
        $form = self::convertToFormSubmission($contact_form);
        SendFormsToConsentSolution::send($form);
    }

    public static function detectAllForms() : array {
		$forms = [];

        if(class_exists('WPCF7_ContactForm')) {
            $wpCf7Forms = WPCF7_ContactForm::find();
            
            // Loop through each post
            foreach ($wpCf7Forms as $wpCf7Form) {
                $fields = self::parseFormFields($wpCf7Form);
                $form = new Form($wpCf7Form->id, 'Contact Form 7', $fields);
                $forms[] = $form;
            }
        }

		return $forms;
	}

    private static function parseFormFields($form) {
        if (!$form) {
            return false;
        }

        $fields = array();

        foreach ($form->scan_form_tags() as $tag) {
            if($tag->name !== '')
                $fields[] = [
                    'name' => sanitize_text_field($tag->name),
                    'type' => 'wpcf7'
                ];
        }

        return $fields;
    }

    private static function getFields($id) {
        $options = wp_load_alloptions();
        $formFields = array_filter($options, function($key) use($id) {
            return strpos($key, 'avacy_form_field_wpcf7_' . $id . '_') === 0;
        }, ARRAY_FILTER_USE_KEY);
    
        $fieldNames = array_keys($formFields);
        return array_map( function($field) use ($id) {
            $field = sanitize_text_field($field);
			if(get_option($field) === 'on') {
                return str_replace('avacy_form_field_wpcf7_' . $id . '_', '', $field);
            }
        }, $fieldNames);
    }

    /**
     * This function retrieves the HTML form for the Contact Form 7 from the id
     */
    public static function getHTMLForm($id): string {
        $shortcode = '[contact-form-7 id="' . $id . '"]';
        return self::renderShortcode($shortcode);
    }

    protected static function renderShortcode(string $shortcode): string {
        ob_start();
        echo do_shortcode($shortcode);
        return ob_get_clean();
    }
}
