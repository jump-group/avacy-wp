<?php

namespace Jumpgroup\Avacy;

use Jumpgroup\Avacy\FormPlugins\ContactForm7;
use Jumpgroup\Avacy\FormPlugins\WooCommerceCheckoutForm;

class ConsentSolutionLogger
{
  public static function init()
  {
    self::registerFormListeners();
  }

  private static function registerFormListeners()
  {
    ContactForm7::listen();
    WooCommerceCheckoutForm::listen();

    // listener 2
    // listener 3
    // listener 4
    // ...
  }

  public static function send(ConsentForm $form)
  {
    $payload = $form->getPayload();
    $apiToken = get_option('avacy_api_token');
    $tenant = get_option('avacy_tenant');
    $webspaceId = get_option('avacy_webspace_id');

    // Headers for the request
    $headers = array(
      'Accept'        => 'application/json',
      'Authorization' => $apiToken,
    );

    // API endpoint URL
    $url = 'https://api.avacy.eu/' . $tenant . '/domains_groups/' . $webspaceId . '/consents';

    // Set the arguments for the POST request
    $args = array(
      'method'      => 'POST',
      'headers'     => $headers,
      'body'        => $payload,
    );

    // print_r($args); die();
    wp_remote_request($url, $args);
    // Make the POST request using wp_remote_request()
    
  }
}
