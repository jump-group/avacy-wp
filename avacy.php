<?php
/*
Plugin Name:  Avacy: GDPR Consent Solution, Cookie Banner and more
Plugin URI:   https://avacysolution.com/
Description:  Avacy's compliance plugin offers <strong>an all-in-one</strong>, <strong>easily set-up</strong> solution for a GDPR compliant website, with features verified by experienced lawyers.
Version:      1.0.2
Contributors: jumptech
Author: Jump Group
Tags: consent, cookie, cookie banner, tracking, privacy, gdpr, cookie consent, cookie notice, privacy policy
Requires at least: 5.3
Tested up to: 6.5
PHP: 7.4
Stable tag: 1.0.2
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
    define('AVACY_PLUGIN_DIR_URL', plugin_dir_url(__FILE__));
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
