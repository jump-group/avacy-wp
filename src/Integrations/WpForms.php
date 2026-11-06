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

class WpForms implements FormInterface {
    
    public static function listen() : void {
        add_action('wpforms_process_complete', [__CLASS__, 'wpfFormSubmitted'], 10, 4);
    }

    public static function convertToFormSubmission($contact_form) : FormSubmission {
        $id = absint($contact_form['id']);
        // fino alla 1.4.x il menu salvava la label con gli spazi: si confronta come la nuova
        $identifierKey = str_replace(' ', '_', (string) get_option('avacy_wp_forms_' . $id . '_form_user_identifier'));
        $identifier = '';
        $remoteAddr = sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
        $ipAddress = $remoteAddr ?: '0.0.0.0';
        
        $fields = self::getFields($id);
        $selectedFields = [];

        $submittedFields = $contact_form['fields'];
        foreach($fields as $field) {

            foreach($submittedFields as $inputValue) {
                $slug = strtolower(str_replace(' ', '_', $inputValue['name']));
                if($field === $slug) {
                    $selectedFields[] = [
                        'label' => $field,
                        'value' => sanitize_text_field($inputValue['value'])
                    ];
                }

                if($identifierKey === $slug) {
                    $identifier = $inputValue['value'];
                }
            }
        }

        $consentData = wp_json_encode($selectedFields);
        $consentFeatures = ConsentFeatures::accepted('WP Forms', $id, self::tickedBoxes($submittedFields));

        $proofs = self::getHTMLForm($id);
        $sub = new FormSubmission(
            $ipAddress,
            'form',
            'accepted',
            $consentData,
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
        if (!SendFormsToConsentSolution::isEnabled('WP Forms', $contact_form['id'])) {
            return;
        }
        $form = self::convertToFormSubmission($contact_form);
        SendFormsToConsentSolution::send($form);
    }

    public static function detectAllForms() : array {
        // Il tipo esiste solo col plugin attivo: senza, i form orfani restano fuori (#747)
        if (!post_type_exists('wpforms')) {
            return [];
        }

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
            $form = new Form(absint($postArray['ID']), 'WP Forms', $parsedFields, self::parseCheckboxes($fields), $post->post_title);
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

    private const CHECKBOX_TYPES = ['checkbox', 'gdpr-checkbox'];

    // Le caselle del form. Il gdpr-checkbox e' sempre obbligatorio.
    private static function parseCheckboxes($fields) : array {
        $boxes = [];
        foreach($fields as $field) {
            if(in_array($field['type'] ?? '', self::CHECKBOX_TYPES, true) && ($field['label'] ?? '') !== '') {
                $boxes[] = [
                    'name' => strtolower(trim($field['label'])),
                    // l'id numerico non cambia con la label: le caratteristiche restano agganciate
                    'key' => self::boxKey($field['id'] ?? ''),
                    'required' => $field['type'] === 'gdpr-checkbox' || !empty($field['required'])
                ];
            }
        }
        return $boxes;
    }

    private static function boxKey($fieldId) : string {
        return 'id:' . $fieldId;
    }

    // Le chiavi delle caselle spuntate nell'invio (il valore e' vuoto se nessuna scelta e' spuntata).
    private static function tickedBoxes($submittedFields) : array {
        $ticked = [];
        foreach($submittedFields as $inputValue) {
            if(in_array($inputValue['type'] ?? '', self::CHECKBOX_TYPES, true) && ConsentFeatures::isTicked($inputValue['value'] ?? '')) {
                $ticked[] = ConsentFeatures::slug(self::boxKey($inputValue['id'] ?? ''));
            }
        }
        return $ticked;
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