<?php
namespace Jumpgroup\Avacy\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Jumpgroup\Avacy\Form;
use WPCF7_Submission;
use Jumpgroup\Avacy\Interfaces\Integration;
use Jumpgroup\Avacy\SendFormsToConsentSolution;
use Jumpgroup\Avacy\FormSubmission;
use WP_Query;
use WPCF7_ContactForm;

class ContactForm7 implements Integration
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
            $selectedFields[$field] = sanitize_text_field($posted_data[$field]);
        }

        $identifier = get_option('avacy_contact_form_7_'. $id .'_form_user_identifier'); // TODO: get identifier from settings
        $remoteAddr = sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
        $ipAddress = $remoteAddr ?: '0.0.0.0';
        $proofs = wp_json_encode($contact_form->form);

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

        $sub = new FormSubmission(
            $selectedFields,
            $identifier,
            $ipAddress,
            $proofs,
            $legalNotices,
            $preferences
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
		
		if( class_exists('WPCF7_ContactForm') ) {
			$args = array(
				'post_type' => 'wpcf7_contact_form',
				'posts_per_page' => -1, // Retrieve all posts
			);

			$query = new WP_Query($args);
			if($query->have_posts()) {
				while($query->have_posts()) {
					$query->the_post();
					$wpCf7Form = WPCF7_ContactForm::get_instance(get_the_ID());
					$fields = self::parseFormFields($wpCf7Form);

					$form = new Form(get_the_ID(), 'Contact Form 7', $fields);
					$forms[] = $form;
				}
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
            return str_replace('avacy_form_field_wpcf7_' . $id . '_', '', sanitize_text_field($field));
            }, 
            $fieldNames
        );
    }
}
