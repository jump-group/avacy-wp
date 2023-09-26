<?php

namespace Jumpgroup\Avacy\Integrations;

use Elementor\Plugin;
use Jumpgroup\Avacy\Form;
use Jumpgroup\Avacy\Interfaces\Integration;
use Jumpgroup\Avacy\SendFormsToConsentSolution;
use Jumpgroup\Avacy\FormSubmission;
use WP_Query;

class ElementorForms implements Integration {
    
    public static function listen() : void {
        add_action('elementor_pro/forms/new_record', [__CLASS__, 'elementorFormSubmitted'], 10, 2);
    }

    public static function convertToFormSubmission($contact_form) : FormSubmission {
        $identifier = get_option('avacy_identifier'); // TODO: get identifier from settings
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        $submittedData = $contact_form;

        $fields = self::getFields();
        $selectedFields = [];

        foreach($fields as $field) {
            if(isset($submittedData[$field])) {
                $selectedFields[$field] = $submittedData[$field];
            }
        }

        $proofs = json_encode([]);

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

    public static function elementorFormSubmitted($record, $handler) {
        $formData = [];
        $values = $record->get_formatted_data();

        foreach($values as $k => $v) {
            $formData[strtolower($k)] = $v;
        }

        self::sendFormData($formData);
    }

    public static function sendFormData($contact_form) : void {
        $form = self::convertToFormSubmission($contact_form);
        SendFormsToConsentSolution::send($form);
    }

    public static function detectAllForms() : array {
        $forms = [];
        $args = array(
            'post_type'      => array( 'post', 'page', 'elementor_library' ),
            'posts_per_page' => - 1,
            'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                array(
                    'key'     => '_elementor_data',
                    'value'   => 'form',
                    'compare' => 'LIKE',
                ),
            ),
        );

        $q = new WP_Query();

        $posts = $q->query( $args );
        if ( ! empty( $posts ) ) {
            foreach ( $posts as $post ) {
                // get form data.
                $elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
                $form_ids       = array();

                if ( $elementor_data ) {
                    $elementor_data = json_decode( $elementor_data, true );

                    self::find_elementor_form_id( $elementor_data, $form_ids );
                }

                $elementor = \ElementorPro\Plugin::elementor();

                foreach ( $form_ids as $key => $form_id ) {
                    $document = $elementor->documents->get( $post->ID );
                    if ( $document ) {
                        $elementor_form = \ElementorPro\Modules\Forms\Module::find_element_recursive( $document->get_elements_data(), $form_id );
                    }

                    if ( ! empty( $elementor_form ) ) {
                        // Set the form name as in Elementor builder.
                        $id = $elementor_form['id'];
                        $fields = self::parseFields($elementor_form['settings']['form_fields']);

                        $forms[] = new Form($id, 'Elementor Forms', $fields);
                    }
                }
            }
        }

        return $forms;
    }

    private static function parseFields($fields) {
        $parsedFields = [];
        foreach($fields as $field) {
            if($field['custom_id'] !== '') {
                $parsedFields[] = [
                    'name' => $field['custom_id'],
                    'type' => 'elementorForms'
                ];
            }
        }

        return $parsedFields;
    }

    private static function getFields() {
        $options = wp_load_alloptions();
        $formFields = array_filter($options, function($key) {
            return strpos($key, 'avacy_form_field_elementorForms_') === 0;
        }, ARRAY_FILTER_USE_KEY);
    
        $fieldNames = array_keys($formFields);
        return array_map( function($field) {
            return str_replace('avacy_form_field_elementorForms_', '', $field);
            }, 
            $fieldNames
        );
    }

    private static function find_elementor_form_id( $data, &$form_ids ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $item ) {
				if ( isset( $item['elType'] ) && 'widget' === $item['elType'] && 'form' === $item['widgetType'] ) {
					$form_ids[] = $item['id'];
				} elseif ( isset( $item['elements'] ) && is_array( $item['elements'] ) ) {
					self::find_elementor_form_id( $item['elements'], $form_ids );
				}
			}
		}
	}
}