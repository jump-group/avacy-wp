<?php

namespace Jumpgroup\Avacy;

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
    add_menu_page('Avacy Plugin', 'Avacy Plugin', 'manage_options', 'avacy-plugin-settings', [static::class, 'registerSettingsPage']);
  }

  public static function detectAllForms() {
    $cf7Forms = \Jumpgroup\Avacy\Integrations\ContactForm7::detectAllForms();

    // wcForms

    // wpCommentsForms

    // etc. etc.

    return $cf7Forms;
  }

}
