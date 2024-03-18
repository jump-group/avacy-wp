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

class WpForms implements Integration {
    
    public static function listen() : void {
        add_action('wpforms_process_complete', [__CLASS__, 'wpfFormSubmitted'], 10, 4);
    }

    public static function convertToFormSubmission($contact_form) : FormSubmission {
        $id = absint($contact_form['id']);
        $identifier = get_option('avacy_wp_forms_' . $id . '_form_user_identifier');
        $ipAddress = sanitize_text_field($_SERVER['REMOTE_ADDR']);
        
        $fields = self::getFields();
        $selectedFields = [];

        $formContent = wpforms()->form->get( $id, array( 'content_only' => true ) );

        $submittedFields = $contact_form['fields'];
        foreach($fields as $field) {

            foreach($submittedFields as $inputValue) {
                $slug = strtolower(str_replace(' ', '_', $inputValue['name']));
                if($field === $slug) {
                    $selectedFields[$field] = sanitize_text_field($inputValue['value']);
                }
            }
        }

        $proofs = wp_json_encode($formContent);

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
            $selectedFields,
            $identifier,
            $ipAddress,
            $proofs,
            $legalNotices,
            $preferences
        );
    }

    public static function wpfFormSubmitted($fields, $entry, $form_data, $entry_id) {
        $formData['fields'] = $fields;
        $formData['id'] = absint($entry['id']);

        self::sendFormData($formData);
    }

    public static function sendFormData($contact_form) : void {

        $form = self::convertToFormSubmission($contact_form);
        SendFormsToConsentSolution::send($form);
    }

    public static function detectAllForms() : array {
        // query all posts of type 'wpforms'
        $args = array(
            'post_type' => 'wpforms',
            'posts_per_page' => -1,
        );

        $forms = [];
        $posts = get_posts($args);
        foreach($posts as $post) {
            $postArray = $post->to_array();
            $fields = json_decode($postArray['post_content'], true)['fields'];

            $parsedFields = self::parseFields($fields);
            $form = new Form(absint($postArray['ID']), 'WP Forms', $parsedFields);
            $forms[] = $form;
        }

        return $forms;
    }

    private static function parseFields($fields) {
        $parsedFields = [];
        foreach($fields as $field) {
            if($field['label'] !== '') {
                $parsedFields[] = [
                    'name' => sanitize_text_field(strtolower(trim($field['label']))),
                    'type' => 'wpforms'
                ];
            }
        }

        return $parsedFields;
    }

    private static function getFields() {
        $options = wp_load_alloptions();
        $formFields = array_filter($options, function($key) {
            return strpos($key, 'avacy_form_field_wpforms_') === 0;
        }, ARRAY_FILTER_USE_KEY);
    
        $fieldNames = array_keys($formFields);
        return array_map( function($field) {
            return str_replace('avacy_form_field_wpforms_', '', $field);
            }, 
            $fieldNames
        );
    }
}