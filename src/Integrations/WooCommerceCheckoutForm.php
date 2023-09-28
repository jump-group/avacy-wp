<?php

namespace Jumpgroup\Avacy\Integrations;

use Jumpgroup\Avacy\Form;
use Jumpgroup\Avacy\Interfaces\Integration;
use Jumpgroup\Avacy\SendFormsToConsentSolution;
use Jumpgroup\Avacy\FormSubmission;
use WP_Query;

class WooCommerceCheckoutForm implements Integration
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
        $checkoutForm = self::getWcCheckoutTemplate();

        $identifier = get_option('avacy_WooCommerce_Checkout_Form_form_user_identifier'); // TODO: get identifier from settings
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        $proofs = json_encode($checkoutForm);
        $posted_data = wc_get_order($order_id)->get_data()['billing'];
        
        $fields = self::getFields();
        $selectedFields = [];

        foreach($fields as $field) {
            if(isset($posted_data[$field])) 
                $selectedFields[$field] = $posted_data[$field];
        }

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

    private static function getWcCheckoutTemplate() {
        ob_start();

        wc_get_template(
            'checkout/form-checkout.php',
            array(
                'checkout' => wc()->checkout(),
            )
        );

        $checkoutForm = ob_get_contents();
        ob_end_clean();

        return $checkoutForm;
    }

    private static function getFields() {
        $options = wp_load_alloptions();
        $formFields = array_filter($options, function($key) {
            return strpos($key, 'avacy_form_field_wc_') === 0;
        }, ARRAY_FILTER_USE_KEY);
    
        $fieldNames = array_keys($formFields);
        return array_map( function($field) {
            return str_replace('avacy_form_field_wc_', '', $field);
            }, 
            $fieldNames
        );
    }
}
