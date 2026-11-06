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

class WooCommerceCheckoutForm implements FormInterface
{

    private const WC_CHECKOUT_DEFAULT_FIELDS = [
        [
            'name' => 'first_name',
            'type' => 'wc',
        ],
        [
            'name' => 'last_name',
            'type' => 'wc',
        ],
        [
            'name' => 'company_name',
            'type' => 'wc',
        ],
        [
            'name' => 'country',
            'type' => 'wc',
        ],
        [
            'name' => 'postcode',
            'type' => 'wc',
        ],
        [
            'name' => 'city',
            'type' => 'wc',
        ],
        [
            'name' => 'province',
            'type' => 'wc',
        ],
        [
            'name' => 'email',
            'type' => 'wc',
        ],
        [
            'name' => 'phone',
            'type' => 'wc',
        ]
    ];

    // Fixed id of the checkout in the dashboard: the option names (switch, fields) depend on it.
    // Keep it empty: sites that already saved the switch use it, and the fields need it empty
    // (convertToFormSubmission strips only leading underscores from the field name).
    public const FORM_ID = '';

    public static function listen() : void
    {
        add_action('woocommerce_thankyou', [__CLASS__, 'sendFormData']);
        // l'ordine non conserva le caselle spuntate (i termini non sono salvati): le annotiamo al checkout
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'saveTickedBoxes'], 10, 2);
    }

    public static function convertToFormSubmission($order_id) : FormSubmission
    {
        $identifierKey = get_option(SendFormsToConsentSolution::identifierOptionName('WooCommerce Checkout Form', self::FORM_ID));
        
        $remoteAddr = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
        $ipAddress = $remoteAddr ?: '0.0.0.0';
        $posted_data = wc_get_order($order_id)->get_data()['billing'];
        $proofs = self::getHTMLForm($order_id);
        
        $fields = self::getFields();        
        $selectedFields = [];

        foreach($fields as $field) {
            // remove first char in $field
            $field = ltrim($field, '_');    

            if($field && isset($posted_data[$field])) {
                $selectedFields[] = [
                    'label' => $field,
                    'value' => sanitize_text_field($posted_data[$field])
                ];
            }
        }

        // il campo scelto; se manca o e' vuoto, l'email
        $identifier = is_string($identifierKey) && !empty($posted_data[$identifierKey])
            ? $posted_data[$identifierKey]
            : ($posted_data['email'] ?? null);
        $consentFeatures = ConsentFeatures::accepted('WooCommerce Checkout Form', self::FORM_ID, (array) wc_get_order($order_id)->get_meta(self::TICKED_META));

        $consentData = wp_json_encode($selectedFields);

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

    // Chiave del meta dell'ordine con gli slug delle caselle spuntate al checkout.
    private const TICKED_META = '_avacy_ticked_boxes';

    public static function saveTickedBoxes($order, $data) : void
    {
        if (!SendFormsToConsentSolution::isEnabled('WooCommerce Checkout Form', self::FORM_ID)) {
            return;
        }
        $ticked = [];
        foreach (self::checkboxes() as $box) {
            // il valore inviato, non $data: per un checkbox WooCommerce vi mette 1 anche se il campo e' arrivato vuoto
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- il nonce lo verifica WooCommerce prima di creare l'ordine
            if (ConsentFeatures::isTicked(isset($_POST[$box['name']]) ? sanitize_text_field(wp_unslash($_POST[$box['name']])) : '')) {
                $ticked[] = ConsentFeatures::slug($box['name']);
            }
        }
        $order->update_meta_data(self::TICKED_META, $ticked);
    }

    // Le caselle del checkout: i termini (se attivi, obbligatori) e le caselle aggiunte ai campi da temi o plugin.
    private static function checkboxes() : array
    {
        $boxes = [];
        try {
            foreach (WC()->checkout()->get_checkout_fields() as $group) {
                foreach ($group as $key => $field) {
                    if (($field['type'] ?? '') === 'checkbox') {
                        $boxes[] = ['name' => $key, 'required' => !empty($field['required'])];
                    }
                }
            }
        } catch (\Throwable $e) {
            // campi non leggibili in questo contesto: restano i termini
        }
        if (function_exists('wc_terms_and_conditions_checkbox_enabled') && wc_terms_and_conditions_checkbox_enabled()) {
            $boxes[] = ['name' => 'terms', 'required' => true];
        }
        return $boxes;
    }

    public static function sendFormData($order_id) : void
    {
        // un ordine che non si trova non deve rompere la pagina di ringraziamento
        if (!SendFormsToConsentSolution::isEnabled('WooCommerce Checkout Form', self::FORM_ID) || !wc_get_order($order_id)) {
            return;
        }
        $form = self::convertToFormSubmission($order_id);
        SendFormsToConsentSolution::send($form);
    }

    public static function detectAllForms(): array {
        $forms = [];
        if(class_exists('WooCommerce')) {
            
            $args = array(
                's'              => 'woocommerce_checkout', // Search term
                'posts_per_page' => -1, // Retrieve all matching posts
            );

            $query = new WP_Query($args);

            // Check if there are posts found
            if ($query->have_posts()) {
                $form = new Form(self::FORM_ID, 'WooCommerce Checkout Form', self::WC_CHECKOUT_DEFAULT_FIELDS, self::checkboxes(), 'WooCommerce checkout');
                $forms[] = $form;
            }
        }

        return $forms;
    }

    public static function getHTMLForm($id) : string {
        ob_start();

        wc_get_template(
            'checkout/form-checkout.php',
            array(
                'checkout' => wc()->checkout(),
            )
        );

        $checkoutForm = ob_get_contents();
        ob_end_clean();

        return json_encode($checkoutForm);
    }

    private static function getFields() {
        $options = wp_load_alloptions();
        $formFields = array_filter($options, function($key) {
            return strpos($key, 'avacy_form_field_wc_') === 0;
        }, ARRAY_FILTER_USE_KEY);
    
        $fieldNames = array_keys($formFields);
        return array_map( function($field) {
            $field = sanitize_text_field($field);
			if(get_option($field) === 'on') {
                return str_replace('avacy_form_field_wc_', '', $field);
            }
        }, $fieldNames);
    }
}
