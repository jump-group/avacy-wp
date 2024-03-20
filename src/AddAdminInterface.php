<?php
namespace Jumpgroup\Avacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Jumpgroup\Avacy\Integrations\ContactForm7;
use Jumpgroup\Avacy\Integrations\HtmlForms;
use Jumpgroup\Avacy\Integrations\ElementorForms;
use Jumpgroup\Avacy\Integrations\WooCommerceCheckoutForm;
use Jumpgroup\Avacy\Integrations\WpForms;

class AddAdminInterface
{
  public static function init()
  {
    add_action('admin_menu', [static::class, 'addMenuPage']);
    add_action('admin_init', [static::class, 'registerSettings']);
    add_action('admin_init', [static::class, 'saveFields']);
  }

  public static function registerSettingsPage() {
    wp_register_style(
        'avacy-dashboard',
        plugins_url( '/../styles/avacy-dashboard.css', __FILE__ ),
        array(),
        '2023-09-13',
        'screen'
    );
    wp_enqueue_style( 'avacy-dashboard' );

    require_once(__DIR__ . '/../views/avacy-dashboard.php');
  }

  public static function registerSettings()
  {
    register_setting('avacy-plugin-settings-group', 'avacy_tenant', 'sanitize_text_field');
    register_setting('avacy-plugin-settings-group', 'avacy_webspace_id', 'sanitize_text_field');
    register_setting('avacy-plugin-settings-group', 'avacy_api_token', 'sanitize_text_field');
    register_setting('avacy-plugin-settings-group', 'avacy_enable_preemptive_block', 'sanitize_text_field');
  }

  public static function saveFields() {
    // get all the fields from $_REQUEST that start with avacy_form_field_
    $fields = array_filter($_REQUEST, function($key) {
      return strpos($key, 'avacy_') === 0;
    }, ARRAY_FILTER_USE_KEY);


    foreach ($fields as $field => $value) {
      // sanitize and escape the field value
      $sanitized_value = sanitize_text_field($value);

      // save the field name in the database
      update_option(strtolower($field), $sanitized_value);
    }
  }

  public static function addMenuPage() {
    add_menu_page('Avacy Plugin', 'Avacy Plugin', 'manage_options', 'avacy-plugin-settings', [static::class, 'registerSettingsPage']);
  }

  public static function detectAllForms()
  {
    $cf7Forms = [];
    if (class_exists('WPCF7')) {
      $cf7Forms = ContactForm7::detectAllForms();
    }

    $wcForms = [];
    if (class_exists('WooCommerce')) {
      $wcForms = WooCommerceCheckoutForm::detectAllForms();
    }

    $wpForms = [];
    if (class_exists('WPForms')) {
      $wpForms = WpForms::detectAllForms();
    }

    $elForms = [];
    if (class_exists('ElementorPro\Modules\Forms\Module')) {
      $elForms = ElementorForms::detectAllForms();
    }

    $htmlForms = [];
    if (class_exists('HtmlForms')) {
      $htmlForms = HtmlForms::detectAllForms();
    }

    // etc. etc.
    return array_merge($cf7Forms, $wcForms, $wpForms, $elForms, $htmlForms);
  }

}
