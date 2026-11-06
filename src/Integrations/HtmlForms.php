<?php
namespace Jumpgroup\Avacy\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use DOMDocument;
use Jumpgroup\Avacy\ConsentFeatures;
use Jumpgroup\Avacy\Form;
use Jumpgroup\Avacy\FormSubmission;
use Jumpgroup\Avacy\Interfaces\Form as FormInterface;
use Jumpgroup\Avacy\SendFormsToConsentSolution;

class HtmlForms implements FormInterface {
    public static function listen() : void {
        add_action('hf_form_success', [__CLASS__, 'formSubmitted'], 10, 2);
    }

    public static function convertToFormSubmission($contact_form) : FormSubmission {
        $identifierKey = get_option('avacy_html_forms_'. $contact_form['id'] . '_form_user_identifier');
        $identifier = self::flatten($contact_form['submission'][$identifierKey] ?? null);
        $remoteAddr = sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
        $ipAddress = $remoteAddr ?: '0.0.0.0';

        $proof = self::getHTMLForm($contact_form['slug']);
        $fields = self::getFields($contact_form['id']);

        $selectedFields = [];
        foreach($fields as $field) {
            if(!empty($field)) {
                $selectedFields[] = [
                    'label' => $field,
                    'value' => sanitize_text_field(self::flatten($contact_form['submission'][$field] ?? ''))
                ];
            }
        }

        $consentData = wp_json_encode($selectedFields);

        $consentFeatures = ConsentFeatures::accepted('HTML Forms', $contact_form['id'], self::tickedBoxes($contact_form['submission']));

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
        $formData['slug'] = htmlentities($form->slug);

        self::sendFormData($formData);
    }

    public static function sendFormData($contact_form) : void {
        if (!SendFormsToConsentSolution::isEnabled('HTML Forms', $contact_form['id'])) {
            return;
        }
        $form = self::convertToFormSubmission($contact_form);
        SendFormsToConsentSolution::send($form);
    }

    public static function detectAllForms() : array {
        $forms = [];
        // Il tipo esiste solo col plugin attivo: senza, i form orfani restano fuori (#747)
        if (!post_type_exists('html-form')) {
            return $forms;
        }
        $args = array(
            'post_type' => 'html-form', // Specify the custom post type you want to query
            'posts_per_page' => -1,    // To retrieve all posts of the specified post type
        );

        foreach (get_posts($args) as $post) {
            $checkboxes = [];
            $fields = self::parseFields($post->post_content, $checkboxes);
            $forms[] = new Form($post->ID, 'HTML Forms', $fields, $checkboxes, $post->post_title);
        }

        return $forms;
    }

    // Le caselle (input checkbox) vanno in $checkboxes; obbligatorie se hanno l'attributo required.
    private static function parseFields($fields, array &$checkboxes = []) {
        $parsedFields = [];
    
        if (empty($fields) || !is_string($fields)) {
            return $parsedFields;
        }
    
        // Gestione errori libxml
        libxml_use_internal_errors(true);
    
        // Escape degli & non validi
        $fields = preg_replace('/&(?![a-zA-Z0-9#]+;)/', '&amp;', $fields);
    
        $dom = new DOMDocument();
        try {
            $dom->loadHTML($fields, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } catch (\Exception $e) {
            // Log or handle the error if needed
            libxml_clear_errors();
            return $parsedFields;
        }
    
        $inputs = $dom->getElementsByTagName('input');
    
        foreach($inputs as $input) {
            if(!$input->hasAttribute('name')) {
                continue;
            }
            // `interessi[]` arriva nell'invio come `interessi` (PHP toglie le parentesi): stesso nome qui
            $name = preg_replace('/\[\]$/', '', strtolower(sanitize_text_field($input->getAttribute('name'))));
            $isCheckbox = strtolower($input->getAttribute('type')) === 'checkbox';
            // un gruppo di caselle con lo stesso nome e' un campo solo
            if(in_array($name, array_column($parsedFields, 'name'), true)) {
                // un campo nascosto con lo stesso nome prima della casella: la casella resta collegabile
                if($isCheckbox && !in_array($name, array_column($checkboxes, 'name'), true)) {
                    $checkboxes[] = [
                        'name' => $name,
                        'required' => $input->hasAttribute('required')
                    ];
                }
                continue;
            }
            if($isCheckbox) {
                $checkboxes[] = [
                    'name' => $name,
                    'required' => $input->hasAttribute('required')
                ];
            }
            $parsedFields[] = [
                'name' => $name,
                'type' => 'htmlforms',
                'label' => 'htmlforms'
            ];
        }
    
        libxml_clear_errors();
    
        return $parsedFields;
    }

    // Un gruppo di caselle (`name="interessi[]"`) arriva come lista delle scelte: la si riduce a testo.
    private static function flatten($value) {
        return is_array($value) ? implode(' | ', $value) : $value;
    }

    // Gli slug delle caselle spuntate: un checkbox non spuntato non arriva nell'invio.
    private static function tickedBoxes($submission) : array {
        $ticked = [];
        foreach($submission as $name => $value) {
            if(ConsentFeatures::isTicked($value)) {
                $ticked[] = ConsentFeatures::slug($name);
            }
        }
        return $ticked;
    }

    private static function getFields($id) {
        $options = wp_load_alloptions();
        $formFields = array_filter($options, function($key) use($id) {
            return strpos($key, 'avacy_form_field_htmlforms_' . $id . '_') === 0;
        }, ARRAY_FILTER_USE_KEY);
    
        $fieldNames = array_keys($formFields);
        return array_map( function($field) use ($id) {
            $field = sanitize_text_field($field);
			if(get_option($field) === 'on') {
                return str_replace('avacy_form_field_htmlforms_' . $id . '_', '', $field);
            }
        }, $fieldNames);
    }

    public static function getHTMLForm($id) : string
    {
        $shortcode = '[hf_form slug="' . $id . '"]';
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