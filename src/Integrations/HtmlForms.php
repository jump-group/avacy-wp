<?php

namespace Jumpgroup\Avacy\Integrations;

use DOMDocument;
use Jumpgroup\Avacy\Form;
use Jumpgroup\Avacy\FormSubmission;
use Jumpgroup\Avacy\Interfaces\Integration;
use Jumpgroup\Avacy\SendFormsToConsentSolution;
use WP_Query;

class HtmlForms implements Integration {
    public static function listen() : void {
        add_action('hf_form_success', [__CLASS__, 'formSubmitted']);
    }

    public static function convertToFormSubmission($contact_form) : FormSubmission {
        $identifier = get_option('avacy_HTML_Forms_form_user_identifier'); // TODO: get identifier from settings
        $ipAddress = $_SERVER['REMOTE_ADDR'];

        $proofs = json_encode($contact_form['source']);
        $fields = self::getFields();
        
        $selectedFields = [];
        foreach($fields as $field) {
            $selectedFields[$field] = $contact_form['submission'][$field];
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
            fields: $selectedFields,
            identifier: $identifier,
            ipAddress: $ipAddress,
            proofs: $proofs,
            legalNotices: $legalNotices,
            preferences: $preferences
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
                        'name' => strtolower($attrValue->nodeValue),
                        'type' => 'HTMLForms'
                    ];
                }

            }
        }

        return $parsedFields;
    }

    private static function getFields() {
        $options = wp_load_alloptions();
        $formFields = array_filter($options, function($key) {
            return strpos($key, 'avacy_form_field_HTMLForms_') === 0;
        }, ARRAY_FILTER_USE_KEY);
    
        $fieldNames = array_keys($formFields);
        return array_map( function($field) {
            return str_replace('avacy_form_field_HTMLForms_', '', $field);
            }, 
            $fieldNames
        );
    }

}