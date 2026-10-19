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
  public static function init()
  {
    self::registerFormListeners();
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
    return get_option(self::enabledOptionName($type, $id)) === 'on';
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
