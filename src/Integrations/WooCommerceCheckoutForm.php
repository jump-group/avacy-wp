<?php
namespace Jumpgroup\Avacy\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

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

    public static function listen() : void
    {
        add_action('woocommerce_thankyou', [__CLASS__, 'sendFormData']);
    }

    public static function convertToFormSubmission($order_id) : FormSubmission
    {

        $identifierKey = get_option('avacy_WooCommerce_Checkout_Form_' . $id . '_form_user_identifier'); // TODO: get identifier from settings
        $identifier = '';

        $remoteAddr = sanitize_text_field( $_SERVER['REMOTE_ADDR'] );
        $ipAddress = $remoteAddr ?: '0.0.0.0';
        $posted_data = wc_get_order($order_id)->get_data()['billing'];
        $proofs = self::getHTMLForm(1);
        
        $fields = self::getFields();
        $selectedFields = [];

        foreach($fields as $field) {
            if(isset($posted_data[$field])) 
                $selectedFields[$field] = [
                    'label' => $field,
                    'value' => sanitize_text_field($posted_data[$field])
                ];
        }

        $selectedFields[] = 
        $identifier = $posted_data[$identifier] ?? null;
        $consentFeatures = [
            'privacy_policy',
            'cookie_policy'
        ];

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

    public static function sendFormData($order_id) : void
    {
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
                $form = new Form(get_the_ID(), 'WooCommerce Checkout Form', self::WC_CHECKOUT_DEFAULT_FIELDS);
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
