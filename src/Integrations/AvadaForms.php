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

class AvadaForms implements FormInterface {

    // Campi di dati dell'utente. Fuori restano submit, captcha, honeypot, notice, password e upload.
    private const FIELD_TAGS = [
        'fusion_form_text',
        'fusion_form_textarea',
        'fusion_form_email',
        'fusion_form_phone_number',
        'fusion_form_number',
        'fusion_form_date',
        'fusion_form_time',
        'fusion_form_select',
        'fusion_form_radio',
        'fusion_form_checkbox',
        'fusion_form_image_select',
        'fusion_form_range',
        'fusion_form_rating',
        'fusion_form_hidden',
        'fusion_form_consent',
    ];

    // L'invio in attesa dell'esito delle azioni del form (login, registrazione, email...).
    private static $pending = null;

    public static function listen() : void {
        // scatta solo per i form inviati via AJAX, dopo i controlli di Avada (captcha, honeypot, nonce se attivo)
        add_action('fusion_form_submission_data', [__CLASS__, 'avadaFormSubmitted'], 10, 2);
        // la risposta finale al visitatore, con l'esito delle azioni: letta per ultimi, com'e' davvero
        add_filter('awb_form_return_message', [__CLASS__, 'avadaFormAnswered'], PHP_INT_MAX, 2);
    }

    public static function convertToFormSubmission($contact_form) : FormSubmission {
        $id = absint($contact_form['id']);
        $submitted = $contact_form['data'];
        $hidden = $contact_form['hidden'] ?? [];
        $identifierKey = get_option('avacy_avada_forms_' . $id . '_form_user_identifier');
        $identifier = isset($submitted[$identifierKey]) ? self::flatten($submitted[$identifierKey]) : null;
        $remoteAddr = sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
        $ipAddress = $remoteAddr ?: '0.0.0.0';

        $selectedFields = [];
        foreach(self::getFields($id) as $field) {
            if(!empty($field) && !in_array($field, $hidden, true)) {
                $selectedFields[] = [
                    'label' => $field,
                    'value' => sanitize_text_field(self::flatten($submitted[$field] ?? ''))
                ];
            }
        }

        $consentData = wp_json_encode($selectedFields);
        $consentFeatures = ConsentFeatures::accepted('Avada Forms', $id, self::tickedBoxes($submitted));

        $sub = new FormSubmission(
            $ipAddress,
            'form',
            'accepted',
            $consentData,
            $identifier,
            'plugin',
            $consentFeatures,
            self::getHTMLForm($id)
        );

        return $sub;
    }

    public static function avadaFormSubmitted($data, $formPostId) {
        if (!SendFormsToConsentSolution::isEnabled('Avada Forms', absint($formPostId))) {
            return;
        }
        // i campi nascosti dalla logica condizionale non li ha visti il visitatore: Avada li salta anche nelle sue mail
        $hidden = isset($data['hidden_field_names']) && is_array($data['hidden_field_names']) ? $data['hidden_field_names'] : [];
        $form = [
            'id' => absint($formPostId),
            'data' => array_filter($data['data'], function($name) use ($hidden) {
                return !in_array($name, $hidden, true);
            }, ARRAY_FILTER_USE_KEY),
            'hidden' => $hidden
        ];
        if (self::waitsForResult()) {
            self::$pending = $form;
            return;
        }
        self::sendQuietly($form);
    }

    // Il consenso parte solo se Avada risponde che il form e' andato a buon fine.
    public static function avadaFormAnswered($message, $formPostId) {
        $form = self::$pending;
        self::$pending = null;
        if ($form && $form['id'] === absint($formPostId) && ($message['status'] ?? '') === 'success') {
            self::sendQuietly($form);
        }
        return $message;
    }

    // L'invio gira dentro la richiesta del form: un suo errore o avviso non deve toccare la risposta di Avada.
    private static function sendQuietly($form) : void {
        ob_start();
        try {
            self::sendFormData($form);
        } catch (\Throwable $e) {
            error_log('Avacy: consenso del form Avada ' . $form['id'] . ' non inviato: ' . $e->getMessage());
        } finally {
            ob_end_clean();
        }
    }

    // Il filtro con l'esito c'e' da Builder 3.11.6 (assente nella 3.4.1): prima si invia subito, come prima.
    private static function waitsForResult() : bool {
        return defined('FUSION_BUILDER_VERSION') && version_compare(FUSION_BUILDER_VERSION, '3.11.6', '>=');
    }

    public static function sendFormData($contact_form) : void {
        if (!SendFormsToConsentSolution::isEnabled('Avada Forms', $contact_form['id'])) {
            return;
        }
        $form = self::convertToFormSubmission($contact_form);
        SendFormsToConsentSolution::send($form);
    }

    public static function detectAllForms() : array {
        $forms = [];
        // Il tipo esiste solo con Avada attivo: senza, i form orfani restano fuori (#747)
        if (!post_type_exists('fusion_form')) {
            return $forms;
        }
        foreach (get_posts(['post_type' => 'fusion_form', 'posts_per_page' => -1]) as $post) {
            $parsedFields = [];
            foreach (self::readFields($post->post_content) as $field) {
                $parsedFields[] = [
                    'name' => $field['name'],
                    'type' => 'avadaforms'
                ];
            }
            $forms[] = new Form($post->ID, 'Avada Forms', $parsedFields, self::readCheckboxes($post->post_content), $post->post_title);
        }

        return $forms;
    }

    // I campi del form letti dai suoi shortcode, senza renderizzare il form.
    private static function readFields($content) : array {
        $fields = [];
        preg_match_all('/' . get_shortcode_regex(self::FIELD_TAGS) . '/', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $atts = (array) shortcode_parse_atts($match[3]);
            if (empty($atts['name'])) {
                continue;
            }

            $field = [
                'name' => sanitize_text_field($atts['name']),
                'label' => sanitize_text_field($atts['label'] ?? $atts['name']),
                'type' => substr($match[2], strlen('fusion_form_'))
            ];
            if ($match[2] === 'fusion_form_consent') {
                // il testo del consenso e' salvato in base64, come lo decodifica Avada
                $description = $atts['description'] ?? '';
                if (base64_encode(base64_decode($description)) === $description) {
                    $description = base64_decode($description);
                }
                $field['text'] = wp_kses_post($description);
            }
            $fields[] = $field;
        }

        return $fields;
    }

    // Le caselle del form: il consenso a casella e il checkbox. Quello implicito non ha una casella da spuntare.
    // Obbligatorio con required="yes"; sul checkbox "selection" solo se chiede almeno una scelta.
    private static function readCheckboxes($content) : array {
        $boxes = [];
        preg_match_all('/' . get_shortcode_regex(['fusion_form_consent', 'fusion_form_checkbox']) . '/', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $atts = (array) shortcode_parse_atts($match[3]);
            if (empty($atts['name']) || ($match[2] === 'fusion_form_consent' && ($atts['consent_type'] ?? 'checkbox') !== 'checkbox')) {
                continue;
            }
            $required = $atts['required'] ?? 'no';
            $boxes[] = [
                'name' => sanitize_text_field($atts['name']),
                'required' => $required === 'yes' || ($required === 'selection' && (int) ($atts['min_required'] ?? 0) > 0)
            ];
        }

        return $boxes;
    }

    // Gli slug dei campi inviati con un valore: le caselle spuntate (i nascosti dalla logica sono gia' fuori).
    private static function tickedBoxes($submitted) : array {
        $ticked = [];
        foreach ($submitted as $name => $value) {
            if (ConsentFeatures::isTicked($value)) {
                $ticked[] = ConsentFeatures::slug((string) $name);
            }
        }
        return $ticked;
    }

    private static function flatten($value) {
        return is_array($value) ? implode(' | ', $value) : $value;
    }

    private static function getFields($id) {
        $options = wp_load_alloptions();
        $formFields = array_filter($options, function($key) use($id) {
            return strpos($key, 'avacy_form_field_avadaforms_' . $id . '_') === 0;
        }, ARRAY_FILTER_USE_KEY);

        $fieldNames = array_keys($formFields);
        return array_map( function($field) use($id) {
            $field = sanitize_text_field($field);
            if(get_option($field) === 'on') {
                return str_replace('avacy_form_field_avadaforms_' . $id . '_', '', $field);
            }
        }, $fieldNames);
    }

    // La prova e' la definizione del form (campi ed etichette), non il suo HTML: niente render.
    public static function getHTMLForm($id) : string
    {
        $post = get_post($id);
        // form cancellato con lo switch ancora acceso: prova vuota
        // l'archivio la mostra come testo: accenti e link restano leggibili
        return wp_json_encode($post ? self::readFields($post->post_content) : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
