<?php
/*
Plugin Name:  Avacy
Plugin URI:   https://jumpgroup.it/
Description:  Avacy configurator plugin for Wordpress
Version:      0.0.1
Author:       Jump Group
License:      MIT License
*/

namespace Jumpgroup\Avacy;

use Jumpgroup\Avacy\SendFormsToConsentSolution;
use Jumpgroup\Avacy\AddAdminInterface;
use Jumpgroup\Avacy\PreemptiveBlock;

// require_once __DIR__ . '/vendor/autoload.php';

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
    SendFormsToConsentSolution::init();
    AddAdminInterface::init();
    PreemptiveBlock::init();
  }
}

$instance = Init::get_instance();
