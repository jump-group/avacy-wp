<?php

namespace Jumpgroup\Avacy;

use Jumpgroup\Avacy\Integrations\ContactForm7;
use Jumpgroup\Avacy\Integrations\ElementorForms;
use Jumpgroup\Avacy\Integrations\WooCommerceCheckoutForm;
use Jumpgroup\Avacy\Integrations\WpForms;
use WP;

class AddAdminInterface
{
  public static function init()
  {
    add_action('admin_menu', function () {
        return static::addMenuPage();
    });
    add_action('admin_init', function () {
        return static::registerSettings();
    });
    add_action('admin_init', function () {
        return static::saveFields();
    });
  }

  public static function registerSettingsPage() {
    wp_register_style(
        'consent_solution_settings',
        plugins_url( '/../styles/consent_solution_settings.css', __FILE__ ),
        array(),
        '2023-09-13',
        'screen'
    );
    wp_enqueue_style( 'consent_solution_settings' );

    require_once(__DIR__ . '/../views/consent_solution_settings.php');
  }

  public static function registerSettings() {
    register_setting('avacy-plugin-settings-group', 'avacy_identifier');
    register_setting('avacy-plugin-settings-group', 'avacy_tenant');
    register_setting('avacy-plugin-settings-group', 'avacy_webspace_id');
    register_setting('avacy-plugin-settings-group', 'avacy_api_token');
  }

  public static function saveFields() {
    // get all the fields from $_REQUEST that start with avacy_form_field_
    $fields = array_filter($_REQUEST, function($key) {
      return strpos($key, 'avacy_form_field_') === 0;
    }, ARRAY_FILTER_USE_KEY);

    foreach(array_keys($fields) as $field) {
      // save the field name in the database
      update_option($field, true);
    }
  }

  public static function addMenuPage() {
    add_menu_page('Avacy Plugin', 'Avacy Plugin', 'manage_options', 'avacy-plugin-settings', function () {
        return static::registerSettingsPage();
    });
  }

  public static function detectAllForms() {
    $cf7Forms = ContactForm7::detectAllForms();

    // wcForms
    $wcForms = WooCommerceCheckoutForm::detectAllForms();

    // wpPosts
    $wpForms = WpForms::detectAllForms();

    // elementor Forms
    $elForms = ElementorForms::detectAllForms();

    // etc. etc.
    return array_merge($cf7Forms, $wcForms, $wpForms, $elForms);
  }

}
