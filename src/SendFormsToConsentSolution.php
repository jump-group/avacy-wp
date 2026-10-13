<?php
namespace Jumpgroup\Avacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Jumpgroup\Avacy\Integrations\ContactForm7;
use Jumpgroup\Avacy\Integrations\ElementorForms;
use Jumpgroup\Avacy\Integrations\HtmlForms;
use Jumpgroup\Avacy\Integrations\WooCommerceCheckoutForm;
use Jumpgroup\Avacy\Integrations\WpForms;

class SendFormsToConsentSolution
{
  // Set once the switch migration has run: it never runs again.
  public const MIGRATION_DONE_OPTION = 'avacy_form_switch_migrated';
  public const MIGRATION_ATTEMPTS_OPTION = 'avacy_form_switch_migration_attempts';

  public static function init()
  {
    self::registerFormListeners();
    add_action('init', [__CLASS__, 'migrateEnabledSwitches'], 99);
  }

  private static function registerFormListeners()
  {
    ContactForm7::listen();
    WooCommerceCheckoutForm::listen();
    WpForms::listen();
    ElementorForms::listen();
    HtmlForms::listen();
  }

  // Name of the option behind the "Save" switch of a form: shared by dashboard, save and send.
  public static function enabledOptionName(string $type, $id): string
  {
    return 'avacy_' . strtolower(str_replace(' ', '_', $type)) . '_' . $id . '_radio_enabled';
  }

  // The "Save" switch of the form in the dashboard: off or never saved means nothing is sent.
  public static function isEnabled(string $type, $id): bool
  {
    // a submission can never be checked before the migration ran (cheap once done)
    self::migrateEnabledSwitches();
    return get_option(self::enabledOptionName($type, $id)) === 'on';
  }

  // One-time migration (issue #601): until now the switch was ignored, so every form with a
  // selected field was sending. Turn its switch on, so nothing changes for the site. It reads
  // the field options (avacy_form_field_<type>_<id>_<name>), not the forms: no queries on posts.
  // Options are per site: no network loop on multisite.
  public static function migrateEnabledSwitches(): void
  {
    if (get_option(self::MIGRATION_DONE_OPTION)) {
      return;
    }
    // a failing migration is tried at most 3 times
    $attempts = (int) get_option(self::MIGRATION_ATTEMPTS_OPTION, 0);
    if ($attempts >= 3) {
      return;
    }
    update_option(self::MIGRATION_ATTEMPTS_OPTION, $attempts + 1);

    try {
      // field type => form type (the ids of Elementor are alphanumeric; the checkout has an empty id)
      $types = [
        'wpcf7' => 'Contact Form 7',
        'wpforms' => 'WP Forms',
        'elementorforms' => 'Elementor Forms',
        'htmlforms' => 'HTML Forms',
      ];

      foreach (wp_load_alloptions() as $name => $value) {
        if ($value !== 'on') {
          continue;
        }
        if (preg_match('/^avacy_form_field_(wpcf7|wpforms|elementorforms|htmlforms)_([A-Za-z0-9]+)_/', $name, $m)) {
          update_option(self::enabledOptionName($types[$m[1]], $m[2]), 'on');
        } elseif (strpos($name, 'avacy_form_field_wc_') === 0) {
          update_option(self::enabledOptionName('WooCommerce Checkout Form', WooCommerceCheckoutForm::FORM_ID), 'on');
        }
      }

      update_option(self::MIGRATION_DONE_OPTION, '1');
    } catch (\Throwable $e) {
      error_log('Avacy: form switch migration failed (attempt ' . ($attempts + 1) . '): ' . $e->getMessage());
    }
  }

  public static function send(FormSubmission $form)
  {
    global $api_base_url;

    $payload = $form->getPayload();
    $apiToken = get_option('avacy_api_token');
    $tenant = get_option('avacy_tenant');
    if( empty($tenant) && strpos(get_option('avacy_webspace_key'), '|') !== false ) {
      $tenant = explode('|', get_option('avacy_webspace_key'))[0];
    }

    $webspaceId = get_option('avacy_webspace_id');

    // Sanitize and escape the API token, tenant, and webspace ID
    $apiToken = sanitize_text_field($apiToken);
    $tenant = sanitize_text_field($tenant);
    $webspaceId = sanitize_text_field($webspaceId);

    // Validate the API token, tenant, and webspace ID
    if (empty($apiToken) || empty($tenant) || empty($webspaceId)) {
      return; // or handle the validation error
    }

    // Headers for the request
    $headers = array(
      'Accept'        => 'application/json',
      'Authorization' => 'Bearer ' . $apiToken,
    );

    // API endpoint URL
    $url = $api_base_url . '/' . $tenant . '/v4/webspaces/' . $webspaceId . '/consents';

    // Set the arguments for the POST request
    $args = array(
      'method'      => 'POST',
      'headers'     => $headers,
      'body'        => $payload,
    );

    // Sanitize and escape the payload
    // $args['body'] = wp_kses_post($args['body']);

    // Make the POST request using wp_remote_request()
    wp_remote_request($url, $args);
  }
}
