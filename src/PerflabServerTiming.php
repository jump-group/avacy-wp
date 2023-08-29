<?php

namespace JumpGroup\ImageHanding;

class PerflabServerTiming {
  public static function init() {
    add_filter('perflab_server_timing_use_output_buffer',function($use_output_buffer){    
      return true;
    });
  }
}