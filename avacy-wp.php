<?php
/*
Plugin Name:  Avacy CMP
Plugin URI:   https://jumpgroup.it/
Description:  Avacy configurator plugin for Wordpress
Version:      1.0.0
Author:       Jump Group
Contributors: Jumpgroup SRL
Tags: consent, tracking, privacy, gdpr
Requires at least: 5.3
Tested up to: 6.4
PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

namespace Jumpgroup\Avacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Jumpgroup\Avacy\SendFormsToConsentSolution;
use Jumpgroup\Avacy\AddAdminInterface;
use Jumpgroup\Avacy\PreemptiveBlock;

require_once __DIR__ . '/vendor/autoload.php';

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
    if(!empty(get_option('avacy_enable_preemptive_block'))) {
      PreemptiveBlock::init();
    }
  }
}

$instance = Init::get_instance();
