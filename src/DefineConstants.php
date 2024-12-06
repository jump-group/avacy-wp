<?php
namespace Jumpgroup\Avacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class DefineConstants {

    public static function init() {
      define( 'AVACY_PLUGIN_URL', plugins_url( '', __FILE__ ) );
      define( 'AVACY_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
      define( 'AVACY_PLUGIN_REL_PATH', dirname( AVACY_PLUGIN_BASENAME ) . '/' );
      define( 'AVACY_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
    }
}