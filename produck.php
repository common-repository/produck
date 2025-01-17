<?php
/**
 * Plugin Name: Produck
 * Plugin URI: https://www.produck.de
 * Description: This plugin enables you to show Quacks in your shop and integrate the ProDuck Chat Service.
 * Version: 1.1.0
 * Author: MonsTec UG (haftungsbeschränkt)
 * Author URI: https://www.monstec.de
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */
/*
Copyright (C) 2019 MonsTec UG (haftungsbeschränkt)

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

// IMPORTANT! permalinks currently have to be set to something other than the default (first) option

// WordPress code security check
// prevent direct access
defined('ABSPATH') or die('Quidquid agis, prudenter agas et respice finem!');

// development / debug mode switches
// has to go in 'wp-config.php' to work properly
//define('WP_DEBUG', true);
//define('WP_DEBUG_LOG', true);
//define('SCRIPT_DEBUG', true);
//define('WP_DEBUG_DISPLAY', true);
//define('SAVEQUERIES', true);

require_once 'vpage/page.php';
require_once 'vpage/content.php';
require_once 'vpage/controller.php';
require_once 'vpage/templateloader.php';
require_once 'gui/quackpagecontent.php';
require_once 'gui/quacksoverviewpagecontent.php';
require_once 'gui/widget.php';
require_once 'gui/chat.php';
require_once 'api/produckconnector.php';
require_once 'api/produckapi.php';
require_once 'api/produckcache.php';
require_once 'pluginadministration.php';

use MonsTec\Produck\ProduckApi;
use MonsTec\Produck\ProduckCache;
use MonsTec\Produck\ProduckConnector;
use MonsTec\Produck\Page;
use MonsTec\Produck\Controller;
use MonsTec\Produck\TemplateLoader;
use MonsTec\Produck\QuackPageContent;
use MonsTec\Produck\OverviewPageContent;
use MonsTec\Produck\ProduckQuacksWidget;
use MonsTec\Produck\Chat;

/*** activation hook ***/
// only run when plugin is activated via administration
register_activation_hook(__FILE__, 'produck_activatePlugin');

function produck_activatePlugin() {
    // This will add all the needed options but only if they don't exist yet.
    // That means deacivating the plugin and then activating it again will not reset the settings.
    add_option('produck_config', array(
        'customerId' => null,
        'quackToken' => null,
        'numberOfQuacksShown' => '5',
        'maxQuackUrlTitleLength' => '100',
        'openQuackInNewPage' => 1,
        'chatEnabled' => 1,
        'useThemeTemplate' => 0,
        'poweredByLinkAllowed' => -1
    ));
}
/*** end of activation hook ***/

if (is_admin()) {
    $produckAdministration = new ProduckPluginAdministration();
}

/**
 * Main class of plugin functionality.
 */
class ProduckPlugin {
    const TEMPLATE_SUB_DIR = 'templates';
    const PRODUCK_URL = 'https://localhost/';
    const PRODUCK_CHAT_URL = 'https://localhost/chat.html';

    // replacements for pretty urls
    public static $urlReplaceFrom = array(' ', 'ä', 'ö', 'ü', 'ß');
    public static $urlReplaceTo = array('-', 'ae', 'oe', 'ue', 'ss');
    public static $urlReplaceRegexp = '/[^a-z0-9 -]/';

    private static $options;
    private static $pluginPath;
    private static $pluginUrl;

    public function __construct() {
        $this->controller = new Controller(new TemplateLoader(ProduckPlugin::getPluginPath()));

        $produckApi = new ProduckApi(ProduckPlugin::getQuackToken());
        $produckCache = new ProduckCache();
        $this->connector = new ProduckConnector($produckApi, $produckCache);
    }

    public function init() {
        // add hooks for custom dynamic pages
        // a possible alternative could be using "custom post types"
        add_action('init', array($this->controller, 'init'));
        // Note! The following filter does not work on admin pages!
        add_filter('do_parse_request', array($this->controller, 'dispatch'), PHP_INT_MAX, 2);

        add_action('loop_end', function(\WP_Query $query) {
            if (isset($query->virtual_page) && ! empty($query->virtual_page)) {
                $query->virtual_page = NULL;
            }
        });

        add_filter('the_permalink', function($plink) {
            global $post, $wp_query;
            if ($wp_query->is_page
                    && isset($wp_query->virtual_page)
                    && $wp_query->virtual_page instanceof Page
                    && isset($post->is_virtual)
                    && $post->is_virtual) {
                $plink = home_url($wp_query->virtual_page->getUrl());
            }

            return $plink;
        } );

        // add script and style dependencies
        // for an overview of already defined script handles (like 'jquery') see:
        // https://developer.wordpress.org/reference/functions/wp_enqueue_script/
        // Note! Calling third party CDNs for reasons other than font inclusions is forbidden (WP-Policy);
        // all non-service related JavaScript and CSS must be included locally
        add_action( 'wp_enqueue_scripts', function() {
            wp_enqueue_script('jquery');
            wp_enqueue_script('produck-scripts', ProduckPlugin::getPluginUrl().'/js/produck.min.js');
            wp_enqueue_script('cookie-lib', ProduckPlugin::getPluginUrl().'/js/js.cookie.js');
            wp_enqueue_script('shariff-lib', ProduckPlugin::getPluginUrl().'/js/shariff.min.js');
            
            wp_enqueue_style('shariff-style', ProduckPlugin::getPluginUrl().'/css/shariff.min.css');
            wp_enqueue_style('font-awesome-style', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css');
            wp_enqueue_style('produck-chat-style', ProduckPlugin::getPluginUrl().'/css/produckchat.min.css');
            wp_enqueue_style('produck-quack-style', ProduckPlugin::getPluginUrl().'/css/quacks.min.css');
        });

        // add dynamic pages for the produck plugin
        add_action('produck_virtual_pages', function($controller) {
            $controller->addPage(new Page('quacks'))
            ->setContent(new OverviewPageContent($this->connector))
            ->setTemplate('quacksoverview.php');
        });

        add_action('produck_virtual_pages', function($controller) {
            $controller->addPage(new Page('quack'))
            ->setContent(new QuackPageContent($this->connector))
            ->setTemplate('quackdetail.php');
        });

        // Register the widget
        add_action('widgets_init', array($this, 'registerQuacksWidget'));

        if (ProduckPlugin::isChatEnabled()) {
            add_action('wp_footer', function() {
                $chat = new Chat();
                echo $chat->getHtml();
            });
        }
    }

    public function registerQuacksWidget() {
        register_widget('ProduckQuacksWidget');
    }

    public static function initOptions() {
        ProduckPlugin::$options = get_option('produck_config');
    }

    public static function getPluginPath() {
        if (!ProduckPlugin::$pluginPath) {
            ProduckPlugin::$pluginPath = plugin_dir_path(__FILE__);
        }

        return ProduckPlugin::$pluginPath;
    }

    public static function getPluginUrl() {
        if (!ProduckPlugin::$pluginUrl) {
            ProduckPlugin::$pluginUrl = plugins_url('produck');
        }

        return ProduckPlugin::$pluginUrl;
    }

    public static function getCustomerId() {
        if (isset(ProduckPlugin::$options['customerId'])) {
            return ProduckPlugin::$options['customerId'];
        } else {
            return null;
        }
    }

    public static function getQuackToken() {
        if (isset(ProduckPlugin::$options['quackToken'])) {
            return ProduckPlugin::$options['quackToken'];
        } else {
            return null;
        }
    }

    public static function getNumberOfQuacksShown() {
        $num = 5;
        if (isset(ProduckPlugin::$options['numberOfQuacksShown'])) {
            $num = ProduckPlugin::$options['numberOfQuacksShown'];
        }
        return $num;
    }

    public static function getCustomerProduckLink() {
        if (isset(ProduckPlugin::$options['customerId'])) {
            return self::PRODUCK_CHAT_URL.'?cid='.ProduckPlugin::$options['customerId'];
        } else {
            return self::PRODUCK_CHAT_URL;
        }
    }

    public static function getQuackOverviewUrl() {
        return home_url('quacks');
    }

    public static function getImageURL($imageName) {
        return ProduckPlugin::getPluginUrl().'/img/'.$imageName;
    }

    public static function isOpenQuackInNewPage() {
        return isset(ProduckPlugin::$options['openQuackInNewPage'])
            && boolval(ProduckPlugin::$options['openQuackInNewPage']);
    }

    public static function isChatEnabled() {
        return isset(ProduckPlugin::$options['chatEnabled'])
            && boolval(ProduckPlugin::$options['chatEnabled']);
    }

    public static function useThemeTemplate() {
        return isset(ProduckPlugin::$options['useThemeTemplate'])
            && boolval(ProduckPlugin::$options['useThemeTemplate']);
    }

    /**
     * Will return an integer < 0 if the user did not decide yet, 0 if powered-by-links must not be shown
     * and an integer > 0 if powered-by-link are allowed.
     */
    public static function isPoweredByLinkAllowed() {
        if (isset(ProduckPlugin::$options['poweredByLinkAllowed'])) {
            return intval(ProduckPlugin::$options['poweredByLinkAllowed']);
        } else {
            return -1;
        }
    }

    public static function transformTitleToUrlPart($title) {
        $maxlength = 100;
        if (isset(ProduckPlugin::$options['maxQuackUrlTitleLength'])) {
            $maxlength = ProduckPlugin::$options['maxQuackUrlTitleLength'];
        }

        return substr(preg_replace(ProduckPlugin::$urlReplaceRegexp,
                                   '',
                                    str_replace(ProduckPlugin::$urlReplaceFrom, ProduckPlugin::$urlReplaceTo, strtolower($title))),
                      0,
                      $maxlength);
    }

    public static function getHash($string) {
        return substr(base_convert(md5($string), 16, 10) , -5);
    }

    /**
	 * Determine if a given string starts with a given substring.
	 *
	 * @param  string  $haystack
	 * @param  string|array  $needle
	 * @return bool
	 */
	public static function startsWith($haystack, $needle) {
		foreach ((array) $needle as $currentChar) {
			if ($currentChar != '' && strpos($haystack, $needle) === 0) return true;
        }

		return false;
	}

    public static function getNotFoundContent() {
        return '<div>Die Seite konnte nicht gefunden werden</div>';
    }
}

ProduckPlugin::initOptions(); // must be called before instantiation of the main class ProduckPlugin
$pluginInstance = new ProduckPlugin();
$pluginInstance->init();
?>