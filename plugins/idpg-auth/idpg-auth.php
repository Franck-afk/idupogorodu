<?php
/**
 * Plugin Name:       IDPG Auth
 * Plugin URI:        https://idupogorodu.ru
 * Description:       Авторизация через ВКонтакте, Яндекс ID и MAX. Блокировка иностранных сервисов (КоАП РФ). Журнал событий.
 * Version:           1.0.0
 * Author:            idpg
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       idpg-auth
 * Requires at least: 5.8
 * Tested up to:      6.7
 * Requires PHP:      7.4
 * WC requires at least: 5.0
 * WC tested up to:   9.9
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'IDPG_AUTH_VERSION',         '1.0.0' );
define( 'IDPG_AUTH_PLUGIN_FILE',     __FILE__ );
define( 'IDPG_AUTH_PLUGIN_DIR',      plugin_dir_path( __FILE__ ) );
define( 'IDPG_AUTH_PLUGIN_URL',      plugin_dir_url( __FILE__ ) );
define( 'IDPG_AUTH_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'IDPG_AUTH_OPTIONS_KEY',     'idpg_auth_settings' );

require_once IDPG_AUTH_PLUGIN_DIR . 'includes/class-wp-auth-ru-logger.php';
require_once IDPG_AUTH_PLUGIN_DIR . 'includes/class-wp-auth-ru-compliance.php';
require_once IDPG_AUTH_PLUGIN_DIR . 'includes/class-wp-auth-ru-admin.php';
require_once IDPG_AUTH_PLUGIN_DIR . 'includes/class-wp-auth-ru-yandex.php';
require_once IDPG_AUTH_PLUGIN_DIR . 'includes/class-wp-auth-ru-vk.php';
require_once IDPG_AUTH_PLUGIN_DIR . 'includes/class-wp-auth-ru-max.php';

function idpg_auth_init() {
    WP_Auth_Ru_Compliance::instance();
    WP_Auth_Ru_Admin::instance();
    WP_Auth_Ru_Yandex::instance();
    WP_Auth_Ru_VK::instance();
    WP_Auth_Ru_Max::instance();
}
add_action( 'plugins_loaded', 'idpg_auth_init' );

// WooCommerce HPOS совместимость
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) &&
         method_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil', 'declare_compatibility' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', IDPG_AUTH_PLUGIN_FILE, true
        );
    }
} );
