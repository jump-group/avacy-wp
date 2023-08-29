<?php

namespace JumpGroup\ImageHanding;

use function Env\env;

class S3UploadsConfig{

    static $bucketName;
    static $appName;

    public static function init() { 

        if(env('CLOUDFRONT_ID')) {
            self::setS3Constants();

            if(!get_transient('bucket_created')) {
        
                set_transient('bucket_created', self::$bucketName);
            }
        } else {
            
                self::setDOConstants();
            
                add_filter( 's3_uploads_s3_client_params', function ( $params ) {

                if ( defined( 'S3_UPLOADS_ENDPOINT' ) ) {
                    $params['endpoint'] = constant('S3_UPLOADS_ENDPOINT');
                }
                return $params;
            }, 5, 1 );       
        }
    }

    private static function setS3Constants() {
        // define bucket's name as customer-bucket-<customer-name> and upload files on <bucket-name>/<customer-name>
        self::$appName = str_replace('_', '-', env('APP_NAME'));
        self::$bucketName = env('S3_SITE_BUCKET');
        
        $uploadFolder = self::$bucketName . "/" . self::$appName;
        define( 'S3_UPLOADS_BUCKET',  $uploadFolder);

        // access credentials
        define( 'S3_UPLOADS_KEY', env('S3_UPLOADS_KEY'));
        define( 'S3_UPLOADS_SECRET', env('S3_UPLOADS_SECRET'));

        // define region
        define( 'S3_UPLOADS_REGION', env('S3_MAIN_REGION')); // different name from env to constant because trellis deploys set env variables in alphabetic order..
        
        // CDN URL where the file will be uploaded formatted as <cdn-name>/<customer-name>/uploads/.../<filename>
        define( 'S3_UPLOADS_BUCKET_URL', env('S3_UPLOADS_BUCKET_URL') . self::$appName);
    }

    private static function setDOConstants() {
        define( 'S3_UPLOADS_BUCKET', env('S3_UPLOADS_BUCKET'));
        define( 'S3_UPLOADS_KEY', env('S3_UPLOADS_KEY'));
        define( 'S3_UPLOADS_SECRET', env('S3_UPLOADS_SECRET'));
        define( 'S3_UPLOADS_REGION', env('S3_MAIN_REGION')); // different name from env to constant because trellis deploys set env variables in alphabetic order..
        define( 'S3_UPLOADS_BUCKET_URL', env('S3_UPLOADS_BUCKET_URL'));
        define( 'S3_UPLOADS_ENDPOINT', env('S3_UPLOADS_ENDPOINT'));
    }
}