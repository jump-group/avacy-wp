<?php
namespace Jumpgroup\Avacy\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

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
        $form_id = sanitize_text_field($contact_form['id']);
        $identifier = get_option('avacy_elementor_forms_' . $form_id . '_form_user_identifier'); // TODO: get identifier from settings
        $remoteAddr = sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
        $ipAddress = $remoteAddr ?: '0.0.0.0';
        $submittedData = $contact_form;

        $fields = self::getFields($form_id);
        $selectedFields = [];

        foreach($fields as $field) {
            if(isset($submittedData[$field])) {
                $selectedFields[$field] = sanitize_text_field($submittedData[$field]);
            }
        }

        $proofs = sanitize_text_field($contact_form['source']);

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

    public static function elementorFormSubmitted($record, $handler) {
        $formData = [];
        $values = $record->get_formatted_data();

        foreach($values as $k => $v) {
            $formData[strtolower($k)] = sanitize_text_field($v);
        }

        $formData['id'] = sanitize_text_field($record->get('form_settings')['id']);
        $formData['source'] = sanitize_text_field(wp_json_encode($record->get('fields')));

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
                if (class_exists('\ElementorPro\Plugin')) {
                    $elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
                    $form_ids       = array();

                    if ( $elementor_data ) {
                        $elementor_data = is_array($elementor_data)? $elementor_data : json_decode( $elementor_data, true );

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
                            $id = sanitize_text_field($elementor_form['id']);
                            $fields = self::parseFields($elementor_form['settings']['form_fields']);
    
                            $forms[] = new Form($id, 'Elementor Forms', $fields);
                        }
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
                    'name' => sanitize_text_field($field['custom_id']),
                    'type' => 'elementorforms'
                ];
            }
        }

        return $parsedFields;
    }

    private static function getFields($form_id) {
        $options = wp_load_alloptions();
        $formFields = array_filter($options, function($key) use ($form_id) {
            return strpos($key, 'avacy_form_field_elementorforms_' . $form_id . '_') === 0;
        }, ARRAY_FILTER_USE_KEY);
    
        $fieldNames = array_keys($formFields);
        return array_map( function($field) use($form_id) {
            return str_replace('avacy_form_field_elementorforms_' . $form_id . '_', '', sanitize_text_field($field));
            }, 
            $fieldNames
        );
    }

    private static function find_elementor_form_id( $data, &$form_ids ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $item ) {
				if ( isset( $item['elType'] ) && 'widget' === $item['elType'] && 'form' === $item['widgetType'] ) {
					$form_ids[] = sanitize_text_field($item['id']);
				} elseif ( isset( $item['elements'] ) && is_array( $item['elements'] ) ) {
					self::find_elementor_form_id( $item['elements'], $form_ids );
				}
			}
		}
	}
}