<?php
namespace Jumpgroup\Avacy\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use DOMDocument;
use Jumpgroup\Avacy\Form;
use Jumpgroup\Avacy\FormSubmission;
use Jumpgroup\Avacy\Interfaces\Integration;
use Jumpgroup\Avacy\SendFormsToConsentSolution;
use WP_Query;

class HtmlForms implements Integration {
    public static function listen() : void {
        add_action('hf_form_success', [__CLASS__, 'formSubmitted'], 10, 2);
    }

    public static function convertToFormSubmission($contact_form) : FormSubmission {
        $identifier = get_option('avacy_html_forms_'. $contact_form['id'] . '_form_user_identifier');
        $remoteAddr = sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
        $ipAddress = $remoteAddr ?: '0.0.0.0';

        $proofs = wp_json_encode($contact_form['source']);
        $fields = self::getFields($contact_form['id']);

        $selectedFields = [];
        foreach($fields as $field) {
            $selectedFields[$field] = sanitize_text_field($contact_form['submission'][$field]);
        }

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

    public static function formSubmitted($submission, $form) : void {
        // eventually we want to do something with the form...
        $submissionInput = [];
        $submissionData = $submission->data;
        foreach($submissionData as $field => $value) {
            $submissionInput[strtolower($field)] = $value;
        }

        $formData['submission'] = $submissionInput;
        $formData['id'] = $submission->form_id;
        $formData['source'] = htmlentities($form->markup);

        self::sendFormData($formData);
    }

    public static function sendFormData($contact_form) : void {
        $form = self::convertToFormSubmission($contact_form);
        SendFormsToConsentSolution::send($form);
    }

    public static function detectAllForms() : array {
        $forms = [];
        $args = array(
            'post_type' => 'html-form', // Specify the custom post type you want to query
            'posts_per_page' => -1,    // To retrieve all posts of the specified post type
        );

        $query = new WP_Query($args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                
                $content = get_the_content();
                $fields = self::parseFields($content);
                $forms[] = new Form(get_the_ID(), 'HTML Forms', $fields);

            }

            // Restore the global post data
            wp_reset_postdata();
        }

        return $forms;
    }

    private static function parseFields($fields) {
        $parsedFields = [];

        $dom = new DOMDocument();
        $dom->loadHTML($fields);
        $inputs = $dom->getElementsByTagName('input');

        foreach($inputs as $input) {
            $attrs = $input->attributes;

            foreach($attrs as $attrName => $attrValue) {
                if($attrName === 'name') {
                    $parsedFields[] = [
                        'name' => strtolower(sanitize_text_field($attrValue->nodeValue)),
                        'type' => 'htmlforms',
                        'label' => 'htmlforms'
                    ];
                }

            }
        }

        return $parsedFields;
    }

    private static function getFields($id) {
        $options = wp_load_alloptions();
        $formFields = array_filter($options, function($key) use($id) {
            return strpos($key, 'avacy_form_field_htmlforms_' . $id . '_') === 0;
        }, ARRAY_FILTER_USE_KEY);
    
        $fieldNames = array_keys($formFields);
        return array_map( function($field) use ($id) {
            return str_replace('avacy_form_field_htmlforms_' . $id . '_', '', $field);
            }, 
            $fieldNames
        );
    }

}