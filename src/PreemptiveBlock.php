<?php
namespace Jumpgroup\Avacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use DOMDocument;

class PreemptiveBlock {

    private static $blackList;

    public static function init() {
        // do a get request
        $blackList = [];
        $url = 'https://assets.avacy-cdn.com/config/' . get_option('avacy_tenant') . '/' . get_option('avacy_webspace_key') . '/custom-vendor-list.json';
        // $url = 'https://avacy-cdn.s3.eu-central-1.amazonaws.com/config/test-production/90990663-c953-493f-9311-97aeef0833dc/custom-vendor-list.json';
        $customVendorListRequest = wp_remote_get($url);

        if($customVendorListRequest['response']['code'] === 200) {
            $vendors = json_decode($customVendorListRequest['body'], true)['vendors'];
            
            // dd('asd', $customVendorListRequest, $customVendorListRequest['body'], json_decode($customVendorListRequest['body'], true));
            foreach($vendors as $vendor) {
                $purposes = empty($vendor['purposes']) ? [1] : $vendor['purposes'];

                $blackList[$vendor['name']]['attribute'] = 'data-custom-vendor';
                $blackList[$vendor['name']]['purposes'] = $purposes;
                $blackList[$vendor['name']]['name'] = $vendor['name'];
                $blackList[$vendor['name']]['id'] = $vendor['id'];
                $blackList[$vendor['name']]['sources'] = [];
    
                foreach($vendor['blockUrls'] as $url) {
                    $blackList[$vendor['name']]['sources'][] = $url;
                }
            }
        }

        self::$blackList = $blackList;

        add_action( 'template_redirect', [static::class, 'output_start'], 0 );
        add_action( 'shutdown', [static::class, 'output_end'], 100 );
    }

    public static function output_start() {
        if ( !is_admin() && !wp_doing_ajax() && !defined('REST_REQUEST') ){ // portare le stesse condizioni anche nell'output_end
            if(!empty(get_option('avacy_enable_preemptive_block'))) {
                ob_start([static::class, 'output_callback']);
            }
        }   
    }

    public static function output_callback( $buffer ) {
        // Modify $buffer (HTML content) here

        $dom = new DOMDocument();
        $dom->loadHTML($buffer, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        if( !empty($buffer) ) {
    
            $scripts = $dom->getElementsByTagName('script');
            foreach($scripts as $script) {
                // if src is in the list of scripts to block
                $src = $script->getAttribute('src');

                if ( ( $src !== '' && ($emt = self::src_contains($src, self::$blackList)) ) || 
                     ( $emt = self::inner_html_contains($script, self::$blackList) ) ) {

                    // change script type to text/plain
                    $script->setAttribute('type', 'as-oil');
                    $script->setAttribute('data-src', $src);
    
                    // add avacy attributes
                    $script->setAttribute('data-managed', 'as-oil');
                    $script->setAttribute('data-type', 'text/javascript');
    
                    // add vendor
                    $script->setAttribute($emt['attribute'], $emt['id']);
    
                    // add purposes
                    $script->setAttribute('data-purposes', implode(',', $emt['purposes']));
                }
            }

        }

        $buffer = $dom->saveHTML();
        return $buffer;
    }

    public static function output_end() {
        if ( ! is_admin() && !wp_doing_ajax() && !defined('REST_REQUEST') && ob_get_level() )
            ob_end_flush();
    }

    private static function src_contains($src, $blackList) {
        foreach($blackList as $item) {
            foreach($item['sources'] as $source) {
                if (str_contains($src, $source) !== false) {
                    return $item;
                }
            }
        }

        return false;
    }

    private static function inner_html_contains($node, $blackList) {
        $innerHTML = $node->textContent;

        foreach($blackList as $item) {
            foreach($item['sources'] as $source) {
                if (str_contains($innerHTML, $source) !== false) {
                    return $item;
                }
            }
        }

        return null;
    }
}