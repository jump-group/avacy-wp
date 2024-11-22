<?php
/*
Plugin Name:  Avacy: GDPR Consent Solution, Cookie Banner and more
Plugin URI:   https://avacysolution.com/
Description:  Avacy's compliance plugin offers an all-in-one, easy solution for a GDPR compliant website, with features verified by experienced lawyers.
Version:      1.1.0
Contributors: jumptech
Author: Jump Group
Tags: consent, cookie, cookie banner, tracking, privacy, gdpr, cookie consent, cookie notice, privacy policy
Requires at least: 5.3
Tested up to: 6.7
PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: avacy
Domain Path: /languages
*/

namespace Jumpgroup\Avacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Jumpgroup\Avacy\Translations;
use Jumpgroup\Avacy\DefineConstants;
use Jumpgroup\Avacy\SendFormsToConsentSolution;
use Jumpgroup\Avacy\AddAdminInterface;
use Jumpgroup\Avacy\PreemptiveBlock;
use Jumpgroup\Avacy\EnqueueBanner;

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
    define( 'AVACY_PLUGIN_BASE_PATH', plugin_basename( __FILE__ ) );
    define( 'AVACY_PLUGIN_BASE_REL_PATH', dirname( AVACY_PLUGIN_BASE_PATH ) . '/' );
    Translations::init();
    DefineConstants::init();
    SendFormsToConsentSolution::init();
    AddAdminInterface::init();
    if(!empty(get_option('avacy_show_banner'))) {
      EnqueueBanner::init();
    }
    if(!empty(get_option('avacy_enable_preemptive_block'))) {
      PreemptiveBlock::init();
    }
  }
}

$instance = Init::get_instance();
