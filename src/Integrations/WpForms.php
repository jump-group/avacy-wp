<?php
namespace Jumpgroup\Avacy\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Jumpgroup\Avacy\Form;
use Jumpgroup\Avacy\Interfaces\Form as FormInterface;
use Jumpgroup\Avacy\SendFormsToConsentSolution;
use Jumpgroup\Avacy\FormSubmission;

class WpForms implements FormInterface {
    
    public static function listen() : void {
        add_action('wpforms_process_complete', [__CLASS__, 'wpfFormSubmitted'], 10, 4);
    }

    public static function convertToFormSubmission($contact_form) : FormSubmission {
        $id = absint($contact_form['id']);
        $identifierKey = get_option('avacy_wp_forms_' . $id . '_form_user_identifier');
        $remoteAddr = sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
        $ipAddress = $remoteAddr ?: '0.0.0.0';
        
        $fields = self::getFields($id);
        $selectedFields = [];

        $submittedFields = $contact_form['fields'];
        foreach($fields as $field) {

            foreach($submittedFields as $inputValue) {
                $slug = strtolower(str_replace(' ', '_', $inputValue['name']));
                if($field === $slug) {
                    $selectedFields[$field] = sanitize_text_field($inputValue['value']);
                }
            }
        }

        $identifier = $selectedFields[$identifierKey] ?? null;
        $consentData = wp_json_encode($selectedFields);
        $consentFeatures = [
            'privacy_policy',
            'cookie_policy'
        ];

        $proofs = self::getHTMLForm($id);

        $sub = new FormSubmission(
            $ipAddress,
            'form',
            'accepted',
            $consentData,
            // $versions,
            $identifier,
            'plugin',
            $consentFeatures,
            $proofs
        );

        return $sub;
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
                $sanitizedField = sanitize_text_field(strtolower(trim(str_replace(' ', '_', $field['label']))));
                $parsedFields[] = [
                    'name' => sanitize_text_field(strtolower(trim($field['label']))),
                    'type' => 'wpforms'
                ];
            }
        }

        return $parsedFields;
    }

    private static function getFields($id) {
        $options = wp_load_alloptions();
        $formFields = array_filter($options, function($key) use($id) {
            return strpos($key, 'avacy_form_field_wpforms_' . $id . '_') === 0;
        }, ARRAY_FILTER_USE_KEY);
    
        $fieldNames = array_keys($formFields);
        return array_map( function($field) use($id) {
            $field = sanitize_text_field($field);
			if(get_option($field) === 'on') {
                return str_replace('avacy_form_field_wpforms_' . $id . '_', '', $field);
            }
        }, $fieldNames);
    }

    public static function getHTMLForm($id) : string
    {
        $shortcode = '[wpforms id="' . $id . '"]';
        $form = self::renderShortcode($shortcode);
        return $form;
    }

    public static function renderShortcode($shortcode) : string
    {
        ob_start();
        echo do_shortcode($shortcode);
        return ob_get_clean();
    }
}