<?php

namespace JumpGroup\ImageHanding;
use JumpGroup\ImageHanding\WebpConvert;
use WP_CLI;

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

class WebpCli {
  public static function init() {
    add_action( 'cli_init',  function () {
      WP_CLI::add_command( 'webp', WebpConvert::class );
    });
  }
}