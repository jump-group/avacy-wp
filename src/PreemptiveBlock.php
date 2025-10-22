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

        $webSpaceKey = get_option('avacy_webspace_key');
        $tenant = '';
        if(!empty($webSpaceKey)) {
            if (strpos($webSpaceKey, '|') === false) {
                $tenant = get_option('avacy_tenant');
            } else {
                $parts = explode('|', $webSpaceKey);
                $tenant = $parts[0];
                $webSpaceKey = $parts[1];
            }
        }

        if(empty($tenant) || empty($webSpaceKey)) {
            return;
        }

        $url = 'https://assets.avacy-cdn.com/config/' . $tenant . '/' . $webSpaceKey . '/custom-vendor-list.json';
        $customVendorListRequest = wp_remote_get($url);

        if(!is_wp_error($customVendorListRequest) && $customVendorListRequest['response']['code'] === 200) {
            $vendors = json_decode($customVendorListRequest['body'], true)['vendors'];
            
            // dd('asd', $customVendorListRequest, $customVendorListRequest['body'], json_decode($customVendorListRequest['body'], true));
            foreach($vendors as $vendor) {
                $purposes = empty($vendor['purposes']) ? [1] : $vendor['purposes'];

                $blackList[$vendor['name']]['attribute'] = 'data-custom-vendor';
                $blackList[$vendor['name']]['purposes'] = $purposes;
                $blackList[$vendor['name']]['name'] = $vendor['name'];
                $blackList[$vendor['name']]['id'] = $vendor['id'];
                $blackList[$vendor['name']]['sources'] = [];
    
                if(isset($vendor['blockUrls']) && !empty($vendor['blockUrls'])) {
                    foreach($vendor['blockUrls'] as $url) {
                        $blackList[$vendor['name']]['sources'][] = $url;
                    }
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
        // Suppress warnings from malformed HTML
        libxml_use_internal_errors(true);
    
        if (!empty($buffer)) {
        
            $dom = new DOMDocument();
            $dom->loadHTML($buffer, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

            $scripts = $dom->getElementsByTagName('script');
            foreach($scripts as $script) {
                $src = $script->getAttribute('src');
    
                if (($src !== '' && ($emt = self::src_contains($src, self::$blackList))) ||
                    ($emt = self::inner_html_contains($script, self::$blackList))) {
    
                    $script->setAttribute('type', 'as-oil');
                    $script->setAttribute('data-src', $src);
                    $script->setAttribute('data-managed', 'as-oil');
                    $script->setAttribute('data-type', 'text/javascript');
                    $script->setAttribute($emt['attribute'], $emt['id']);
                    $script->setAttribute('data-purposes', implode(',', $emt['purposes']));
                }
            }
        }else {
            return $buffer;
        }
    
        // Clear libxml errors
        libxml_clear_errors();
    
        return $dom->saveHTML();
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