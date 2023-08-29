<?php

namespace JumpGroup\ImageHanding;

class AddMimes {
  public static function init() {
    add_filter('upload_mimes', function($mimes){
      $mimes['svg'] = 'image/svg+xml';
      $mimes['json'] = 'application/json';
      return $mimes;
    });
  }
}