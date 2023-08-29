<?php
/*
Plugin Name:  Image Handling Configs
Plugin URI:   https://jumpgroup.it/
Description:  Adds Configs for Image Handling
Version:      3.1.2
Author:       Jump Group
License:      MIT License
*/

namespace JumpGroup\ImageHanding;

use JumpGroup\ImageHanding\AddMimes;
use JumpGroup\ImageHanding\S3UploadsConfig;
use JumpGroup\ImageHanding\WebpCli;
use JumpGroup\ImageHanding\PerflabServerTiming;
use function Env\env;

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
    AddMimes::init();
    S3UploadsConfig::init();

    if (empty(env('WP_ALLOW_MULTISITE')) || env('WP_ALLOW_MULTISITE') === 'false') {
      WebpCli::init();
    }

    if (!empty(env('WP_ALLOW_MULITSITE'))) {
      PerflabServerTiming::init();
    }
  }
}


$instance = Init::get_instance();
