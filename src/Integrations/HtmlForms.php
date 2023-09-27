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
        add_action('hf_form_success', [__CLASS__, 'formSubmitted'], 10, 2);
    }

    public static function convertToFormSubmission($contact_form) : FormSubmission {
        $identifier = get_option('avacy_identifier'); // TODO: get identifier from settings
        $ipAddress = $_SERVER['REMOTE_ADDR'];

        $proofs = json_encode($contact_form['source']);
        $fields = self::getFields($contact_form['submission']->data);

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

    public static function formSubmitted($submission, $form) : void {
        
        // eventually we want to do something with the form...
        $formData['submission'] = $submission;
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

    private static function getFields($fields) {
        $res = [];
        foreach($fields as $field => $value) {
            $res[strtolower($field)] = $value;
        }

        return $res;
    }

}