<?php

namespace Jumpgroup\Avacy;

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

  public static function send(FormSubmission $form)
  {
    $payload = $form->getPayload();
    $apiToken = get_option('avacy_api_token');
    $tenant = get_option('avacy_tenant');
    $webspaceId = get_option('avacy_webspace_id');

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

    wp_remote_request($url, $args);
    // Make the POST request using wp_remote_request()
    
  }
}
