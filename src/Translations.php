<?php
namespace Jumpgroup\Avacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Translations {
    public static function init() {
      add_action( 'plugins_loaded', array( static::class, 'load_textdomain' ) );
    }

    public static function load_textdomain() {
      // dd( dirname(plugin_basename( __FILE__ )));
      load_plugin_textdomain( 'avacy', false, AVACY_PLUGIN_BASE_REL_PATH . 'languages/' );
    }
}