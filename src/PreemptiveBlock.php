<?php

namespace Jumpgroup\Avacy;

use DOMDocument;

class PreemptiveBlock {

	public static $blackList = [
        'google' => [
            'id' => '755',
            'name' => 'Google',
            'purposes' => [1, 3, 4],
            'attribute' => 'data-iab-vendor',
            'sources' => [
                'apis.google.com/js/api.js',
                'cse.google.com/cse.js',
                'google-analytics.com/analytics.js',
                'www.googletagmanager.com/gtag/js',
                'apis.google.com',
                'maps.google.it/maps',
                'maps.google.com/maps',
                'www.google.com/maps/embed',
            ]
        ],
        'youtube' => [
            'id' => 'd411',
            'name' => 'YouTube',
            'purposes' => [2,3,4,5,6,7,8,9,10],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'youtube.com/iframe_api',
                'youtube.com/embed/',
                'youtube.com/player_api',
                'youtube.com/youtubei/v1/player',
                'youtube.com/youtubei/v1/next',
                'youtube.com/youtubei/v1/log_event',
                'youtube.com/youtubei/v1/browse',
                'youtube.com/youtubei/v1/search'
            ]
        ],
        'gsn' => [
            'id' => 'd1000',
            'name' => 'GSN (Gigya Socialize Now) Engage',
            'purposes' => [1],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'loader.engage.gsfn.us/loader.js'
            ]
        ],
        'headway' => [
            'id' => 'd156',
            'name' => 'Headway',
            'purposes' => [1],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'headwayapp.co/widget.js'
            ]
        ],
        'freshchat' => [
            'id' => 'd1001',
            'name' => 'Freshchat',
            'purposes' => [1],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'wchat.freshchat.com'
            ]
        ],
        'uservoice' => [
            'id' => 'd1002',
            'name' => 'UserVoice',
            'purposes' => [1],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'widget.uservoice.com',
                'UserVoice.push'
            ]
        ],
        'olark' => [
            'id' => 'd236',
            'name' => 'Olark',
            'purposes' => [2,3,4,5,6,7,8,9,10],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'static.olark.com/jsclient/loader0.js'
            ]
        ],
        'elevio' => [
            'id' => 'd1003',
            'name' => 'Elevio',
            'purposes' => [1],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'cdn.elev.io'
            ]
        ],
        'paypal' => [
            'id' => 'd252',
            'name' => 'PayPal',
            'purposes' => [2,3,4,6,7,8,9,10],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'paypalobjects.com/js/external/api.js',
                'paypalobjects.com/api/checkout.js'
            ]
        ],
        'twitter' => [
            'id' => 'd343',
            'name' => 'Twitter',
            'purposes' => [2,3,4,5,6,7,8,9,10],
            'features' => [1, 2],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'platform.twitter.com/widgets.js',
                'platform.twitter.com'
            ]
        ],
        'instagram' => [
            'id' => 'd173',
            'name' => 'Instagram',
            'purposes' => [2,3,4,5,6,7,8,9,10],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'instawidget.net/js/instawidget.js'
            ]
        ],
        'disqus' => [
            'id' => '833',
            'name' => 'Disqus',
            'purposes' => [1,2,3,4,5,6,7,8,9,10],
            'features' => [1],
            'attribute' => 'data-iab-vendor',
            'sources' => [
                'disqus.com/embed.js'
            ]
        ],
        'linkedin' => [
            'id' => 804,
            'name' => 'LinkedIn',
            'purposes' => [1,3,4],
            'features' => [1],
            'attribute' => 'data-iab-vendor',
            'sources' => [
                'platform.linkedin.com/in.js'
            ]
        ],
        'pinterest' => [
            'id' => 'd257',
            'name' => 'Pinterest',
            'purposes' => [2,3,4,5,6,7,8,9,10],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'pinterest.com/js/pinit.js'
            ]
        ],
        'codepen' => [
            'id' => 'd1004',
            'name' => 'CodePen',
            'purposes' => [1],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'codepen.io'
            ]
        ],
        'addthis' => [
            'id' => 'd4',
            'name' => 'AddThis',
            'purposes' => [2,3,4,5,6,7,8,9,10],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'addthis.com/js/'
            ]
        ],
        'bing' => [
            'id' => 'd42',
            'name' => 'Bing',
            'purposes' => [1],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'bat.bing.com'
            ]
        ],
        'facebook' => [
            'id' => 'd116',
            'name' => 'Facebook',
            'purposes' => [2,3,4,5,6,7,8,9,10],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'connect.facebook.net',
                'www.facebook.com/plugins/like.php',
                'www.facebook.com/*/plugins/like.php',
                'www.facebook.com/plugins/likebox.php',
                'www.facebook.com/*/plugins/likebox.php'
            ]
        ],
        'sharethis' => [
            'id' => 33,
            'name' => 'ShareThis',
            'purposes' => [1,3,5],
            'features' => [1],
            'attribute' => 'data-iab-vendor',
            'sources' => [
                'sharethis.com/button/buttons.js'
            ]
        ],
        'scorecardresearch' => [
            'id' => 'd1005',
            'name' => 'Scorecard Research',
            'purposes' => [1],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'scorecardresearch.com/beacon.js'
            ]
        ],
        'neodatagroup' => [
            'id' => 38,
            'name' => 'Neo Data Group',
            'purposes' => [1],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'neodatagroup.com'
            ]
        ],
        'lp4' => [
            'id' => 39,
            'name' => 'LP4',
            'purposes' => [1],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'lp4.io'
            ]
        ],
        'optimizely' => [
            'id' => 40,
            'name' => 'Optimizely',
            'purposes' => [1],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'cdn.optimizely.com/js/'
            ]
        ],
        'segment' => [
            'id' => 41,
            'name' => 'Segment',
            'purposes' => [1],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'cdn.segment.io/analytics.js',
                'cdn.segment.com/analytics.js'
            ]
        ],
        'kissmetrics' => [
            'id' => 42,
            'name' => 'Kissmetrics',
            'purposes' => [1],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'i.kissmetrics.com/i.js'
            ]
        ],
        'mixpanel' => [
            'id' => 43,
            'name' => 'Mixpanel',
            'purposes' => [1],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'cdn.mxpnl.com'
            ]
        ],
        'pingdom' => [
            'id' => 44,
            'name' => 'Pingdom',
            'purposes' => [1],
            'features' => [1],
            'attribute' => 'data-custom-vendor',
            'sources' => [
                'rum-static.pingdom.net/prum.min.js'
            ]
        ]
    ];

    public static function init() {
        add_action( 'template_redirect', [static::class, 'output_start'], 0 ); // al primo momento che php comincia a stampare l'output, catturalo
        add_action( 'shutdown', [static::class, 'output_end'], 100 ); // prima di chiudere php, ri dai l'output al buffer
    }

    public static function output_start() { 
        if ( ! is_admin() ){
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
                $type = $script->getAttribute('type');

                if ( ( $src !== '' && ($emt = self::src_contains($src, self::$blackList)) ) || 
                     ( $emt = self::inner_html_contains($script) ) ) {
                    // change script type to text/plain
                    $script->setAttribute('type', 'as-oil');
    
                    // add avacy attributes
                    $preserveType = $type ?? 'text/javascript';
                    $script->setAttribute('data-managed', 'as-oil');
                    $script->setAttribute('data-type', $preserveType);
    
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
        if ( ! is_admin() && ob_get_level() )
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

    private static function inner_html_contains($node) {
        $blackList = self::$blackList;
        // $innerHTML = self::get_inner_html($node);
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