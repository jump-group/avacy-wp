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
    // get all forms saved in the database
    $forms = self::detectAllForms();

    // get all id from $forms
    $formValues = [];

    // create an array of fields that you have to search in $request
    foreach($forms as $form) {
      $type = str_replace(' ', '_',$form->getType());
      $fields = $form->getFields();
      $id = $form->getId();
      
      foreach($fields as $field) {
        $fieldName = str_replace(' ', '_', $field['name']);
        $formFieldOpt = 'avacy_form_field_' . $field['type'] . '_' . $form->getId() . '_' . $fieldName;
        $formValues[$id]['fields'][$formFieldOpt] = get_option($formFieldOpt) ?? 'off';
      }

      $enabledOption = 'avacy_' . $type . '_' . $form->getId() . '_radio_enabled';
      $enabled = get_option($enabledOption) ?? 'off';
      
      $identifierOption = 'avacy_' . $type . '_' . $id . '_form_user_identifier';
      $identifier = get_option($identifierOption) ?? null;

      $formValues[$id][$enabledOption] = $enabled;
      $formValues[$id][$identifierOption] = $identifier;
    }

    if($_REQUEST['option_page'] === 'avacy-plugin-settings-group') {
      // for each field in $fields check if it exists in $request
      foreach($formValues as $key => $value) {

        // update fields
        foreach($value['fields'] as $k => $option) {
          if(isset($_REQUEST[$k])) {
            $v = $_REQUEST[$k];

            // sanitize and escape the field value
            $sanitized_value = sanitize_text_field($v);

            // save the field name in the database then update option
            update_option($k, $sanitized_value);
          } else {
            update_option($k, 'off');
          }
        }

        // take the remaining keys in the array to update
        foreach($value as $k => $v) {
          if($k !== 'fields') {
            if(isset($_REQUEST[$k])) {
              $v = $_REQUEST[$k];

              // sanitize and escape the field value
              $sanitized_value = sanitize_text_field($v);

              // save the field name in the database
              update_option($k, $sanitized_value);
            } else {
              update_option($k, 'off');
            }
          }
        }
      }
    }
  }

  public static function addMenuPage() {
    add_menu_page('Avacy Plugin', 'Avacy Plugin', 'manage_options', 'avacy-plugin-settings', [static::class, 'registerSettingsPage']);
  }

  public static function detectAllForms()
  {
    $cf7Forms = ContactForm7::detectAllForms();
    $wcForms = WooCommerceCheckoutForm::detectAllForms();
    $wpForms = WpForms::detectAllForms();
    $elForms = ElementorForms::detectAllForms();
    $htmlForms = HtmlForms::detectAllForms();

    // etc. etc.
    return array_merge($cf7Forms, $wcForms, $wpForms, $elForms, $htmlForms);
  }

}
