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
    // check if Contact Form 7 is active
    if (class_exists('WPCF7')) {
      ContactForm7::listen();
    }

    if (class_exists('WooCommerce')) {
      WooCommerceCheckoutForm::listen();
    }

    if (class_exists('WPForms')) {
      WpForms::listen();
    }

    if (class_exists('ElementorPro\Modules\Forms\Module')) {
      ElementorForms::listen();
    }

    if (class_exists('HtmlForms')) {
      HtmlForms::listen();
    }
  }

  public static function send(FormSubmission $form)
  {
    $payload = $form->getPayload();
    $apiToken = get_option('avacy_api_token');
    $tenant = get_option('avacy_tenant');
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
    $url = 'https://api.avacy.eu/' . $tenant . '/v2/webspaces/' . $webspaceId . '/consents';

    // Set the arguments for the POST request
    $args = array(
      'method'      => 'POST',
      'headers'     => $headers,
      'body'        => $payload,
    );

    // Sanitize and escape the payload
    $args['body'] = wp_kses_post($args['body']);

    // Make the POST request using wp_remote_request()
    wp_remote_request($url, $args);
  }
}
