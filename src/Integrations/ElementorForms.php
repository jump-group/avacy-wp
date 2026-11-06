<?php
namespace Jumpgroup\Avacy\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Jumpgroup\Avacy\ConsentFeatures;
use Jumpgroup\Avacy\Form;
use Jumpgroup\Avacy\Interfaces\Form as FormInterface;
use Jumpgroup\Avacy\SendFormsToConsentSolution;
use Jumpgroup\Avacy\FormSubmission;
use WP_Query;

class ElementorForms implements FormInterface {
    
    public static function listen() : void {
        add_action('elementor_pro/forms/new_record', [__CLASS__, 'elementorFormSubmitted'], 10, 2);
    }

    public static function convertToFormSubmission($contact_form) : FormSubmission {
        $form_id = sanitize_text_field($contact_form['id']);
        $identifierKey = get_option('avacy_elementor_forms_' . $form_id . '_form_user_identifier');
        $identifier = $contact_form[$identifierKey] ?? null;
        $remoteAddr = sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
        $ipAddress = $remoteAddr ?: '0.0.0.0';
        $submittedData = $contact_form;

        $fields = self::getFields($form_id);
        $selectedFields = [];
        foreach($fields as $field) {
            if(!empty($field)) {
                $selectedFields[] = [
                    'label' => $field,
                    'value' => $contact_form[$field] ?? null
                ];
            }
        }

        $proof = sanitize_text_field($contact_form['source']);
        $consentData = wp_json_encode($selectedFields);

        $consentFeatures = ConsentFeatures::accepted('Elementor Forms', $form_id, $contact_form['avacy_ticked'] ?? []);

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

    public static function elementorFormSubmitted($record, $handler) {
        $formData = [];

        // i valori per id del campo, come li salva la dashboard: get_formatted_data() e' indicizzato per etichetta
        foreach($record->get('fields') as $id => $field) {
            $formData[(string) $id] = sanitize_text_field($field['value'] ?? '');
        }

        // le caselle spuntate, lette per id del campo
        $formData['avacy_ticked'] = [];
        foreach($record->get('fields') as $id => $field) {
            if(in_array($field['type'] ?? '', self::CHECKBOX_TYPES, true) && ConsentFeatures::isTicked($field['value'] ?? '')) {
                $formData['avacy_ticked'][] = ConsentFeatures::slug((string) $id);
            }
        }

        $formData['id'] = sanitize_text_field($record->get('form_settings')['id']);
        $formData['source'] = sanitize_text_field(wp_json_encode($record->get('fields')));

        self::sendFormData($formData);
    }

    public static function sendFormData($contact_form) : void {
        if (!SendFormsToConsentSolution::isEnabled('Elementor Forms', $contact_form['id'])) {
            return;
        }
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
                            $checkboxes = self::parseCheckboxes($elementor_form['settings']['form_fields']);
    
                            $forms[] = new Form($id, 'Elementor Forms', $fields, $checkboxes, $elementor_form['settings']['form_name'] ?? '');
                        }
                    }
                }
            }
        }

        return $forms;
    }

    private const CHECKBOX_TYPES = ['acceptance', 'checkbox'];

    // Le caselle del form (acceptance e checkbox): obbligatorie col suo interruttore Required.
    private static function parseCheckboxes($fields) : array {
        $boxes = [];
        foreach($fields as $field) {
            if(in_array($field['field_type'] ?? '', self::CHECKBOX_TYPES, true) && ($field['custom_id'] ?? '') !== '') {
                $boxes[] = [
                    'name' => sanitize_text_field($field['custom_id']),
                    'required' => !empty($field['required'])
                ];
            }
        }
        return $boxes;
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
            $field = sanitize_text_field($field);
			if(get_option($field) === 'on') {
                return str_replace('avacy_form_field_elementorforms_' . $form_id . '_', '', $field);
            }
        }, $fieldNames);
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

    public static function getHTMLForm($id) : string
    {
        return '';
    }
}