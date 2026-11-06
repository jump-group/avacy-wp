<?php
namespace Jumpgroup\Avacy\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Jumpgroup\Avacy\ConsentFeatures;
use Jumpgroup\Avacy\Form;
use WPCF7_Submission;
use Jumpgroup\Avacy\Interfaces\Form as FormInterface;
use Jumpgroup\Avacy\SendFormsToConsentSolution;
use Jumpgroup\Avacy\FormSubmission;
use WP_Query;
use WPCF7_ContactForm;

class ContactForm7 implements FormInterface
{

    public static function listen() : void
    {
        add_action('wpcf7_before_send_mail', [__CLASS__, 'sendFormData']);
    }

    public static function convertToFormSubmission($contact_form) : FormSubmission
    {
        $submission = WPCF7_Submission::get_instance();
        $posted_data = $submission->get_posted_data();
        $id = $contact_form->id();

        $fields = self::getFields($id);
        $selectedFields = [];
        foreach($fields as $field) {
            if(!empty($field)) {
                $selectedFields[] = [
                    'label' => $field,
                    'value' => sanitize_text_field(self::flatten($posted_data[$field] ?? ''))
                ];
            }
        }

        $identifierKey = get_option('avacy_contact_form_7_'. $id .'_form_user_identifier'); // TODO: get identifier from settings
        $remoteAddr = sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
        $ipAddress = $remoteAddr ?: '0.0.0.0';
        $proof = self::getHTMLForm($id);
        $consentData = wp_json_encode($selectedFields);
        $identifier = $posted_data[$identifierKey] ?? null;
        $consentFeatures = ConsentFeatures::accepted('Contact Form 7', $id, self::tickedBoxes($contact_form, $posted_data));

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

    public static function sendFormData($contact_form) : void
    {
        if (!SendFormsToConsentSolution::isEnabled('Contact Form 7', $contact_form->id())) {
            return;
        }
        $form = self::convertToFormSubmission($contact_form);
        SendFormsToConsentSolution::send($form);
    }

    public static function detectAllForms() : array {
		$forms = [];

        if(class_exists('WPCF7_ContactForm')) {
            $wpCf7Forms = WPCF7_ContactForm::find();
            
            // Loop through each post
            foreach ($wpCf7Forms as $wpCf7Form) {
                $fields = self::parseFormFields($wpCf7Form);
                $form = new Form($wpCf7Form->id(), 'Contact Form 7', $fields, self::parseCheckboxes($wpCf7Form), $wpCf7Form->title());
                $forms[] = $form;
            }
        }

		return $forms;
	}

    private static function parseFormFields($form) {
        if (!$form) {
            return false;
        }

        $fields = array();

        foreach ($form->scan_form_tags() as $tag) {
            if($tag->name !== '')
                $fields[] = [
                    'name' => sanitize_text_field($tag->name),
                    'type' => 'wpcf7'
                ];
        }

        return $fields;
    }

    // Un campo a scelta multipla arriva come array (la casella spuntata): lo si riduce a testo.
    private static function flatten($value) {
        return is_array($value) ? implode(' | ', $value) : $value;
    }

    // Le caselle del form. Un acceptance e' obbligatorio salvo l'opzione 'optional': is_required() per lui da' sempre false.
    private static function parseCheckboxes($form) : array {
        $boxes = [];
        foreach ($form->scan_form_tags() as $tag) {
            if ($tag->name === '' || !in_array($tag->basetype, ['acceptance', 'checkbox'], true)) {
                continue;
            }
            $boxes[] = [
                'name' => $tag->name,
                'required' => $tag->basetype === 'acceptance' ? !$tag->has_option('optional') : $tag->is_required()
            ];
        }
        return $boxes;
    }

    // Gli slug delle caselle spuntate. Con l'opzione 'invert' di un acceptance il consenso e' la casella NON spuntata.
    private static function tickedBoxes($contact_form, $posted_data) : array {
        $ticked = [];
        foreach ($contact_form->scan_form_tags() as $tag) {
            if ($tag->name === '' || !in_array($tag->basetype, ['acceptance', 'checkbox'], true)) {
                continue;
            }
            $isTicked = ConsentFeatures::isTicked($posted_data[$tag->name] ?? '');
            if ($tag->basetype === 'acceptance' && $tag->has_option('invert')) {
                $isTicked = !$isTicked;
            }
            if ($isTicked) {
                $ticked[] = ConsentFeatures::slug($tag->name);
            }
        }
        return $ticked;
    }

    private static function getFields($id) {
        $options = wp_load_alloptions();
        $formFields = array_filter($options, function($key) use($id) {
            return strpos($key, 'avacy_form_field_wpcf7_' . $id . '_') === 0;
        }, ARRAY_FILTER_USE_KEY);
    
        $fieldNames = array_keys($formFields);
        return array_map( function($field) use ($id) {
            $field = sanitize_text_field($field);
			if(get_option($field) === 'on') {
                return str_replace('avacy_form_field_wpcf7_' . $id . '_', '', $field);
            }
        }, $fieldNames);
    }

    /**
     * This function retrieves the HTML form for the Contact Form 7 from the id
     */
    public static function getHTMLForm($id): string {
        $shortcode = '[contact-form-7 id="' . $id . '"]';
        return self::renderShortcode($shortcode);
    }

    protected static function renderShortcode(string $shortcode): string {
        ob_start();
        echo do_shortcode($shortcode);
        return ob_get_clean();
    }
}
