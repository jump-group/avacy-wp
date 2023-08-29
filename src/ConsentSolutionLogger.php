<?php

namespace Jumpgroup\Avacy;

use Jumpgroup\Avacy\FormPlugins\ContactForm7;

class ConsentSolutionLogger
{
  public static function init()
  {
    self::registerFormListeners();
  }

  private static function registerFormListeners()
  {
    ContactForm7::listen();
    // listener 2
    // listener 3
    // listener 4
    // ...
  }

  public static function send(ConsentForm $form)
  {

    $data = $form->getData();
    // Headers for the request
    $headers = array(
      'Accept'        => 'application/json',
      'Authorization' => 'Bearer 78|h0XmJRkfkTtsclrNIhVBOKJ88ESl2cqbkTcd12Hg',
    );

    // API endpoint URL
    $url = 'https://api.avacy.eu/jumpgroup/domains_groups/23/consents';

    // Set the arguments for the POST request
    $args = array(
      'method'      => 'POST',
      'headers'     => $headers,
      'body'        => $data,
    );

    // Make the POST request using wp_remote_request()
    wp_remote_request($url, $args);
  }
}
