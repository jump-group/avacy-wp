<?php
/*
Plugin Name:  Avacy Wordpress Plugin
Plugin URI:   https://jumpgroup.it/
Description:  Consent Solution Wordpress Plugin
Version:      0.0.1
Author:       Jump Group
License:      MIT License
*/

namespace Jumpgroup\Avacy;

use Jumpgroup\Avacy\ConsentSolutionLogger;

if (!defined('WPINC')) {
  die;
}

class Init
{

  protected static $instance;

  public static function get_instance()
  {
    if (null == self::$instance) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  protected function __construct()
  {
    ConsentSolutionLogger::init();
  }
}

$instance = Init::get_instance();
