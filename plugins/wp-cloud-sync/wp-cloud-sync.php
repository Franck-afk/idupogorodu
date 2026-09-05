<?php
/**
 * Plugin Name:       WP Cloud Sync
 * Description:       Базовый каркас синхронизации сайта с облачным хранилищем: страница настроек Яндекс OAuth (Client ID / Client Secret) и удалённый путь. Логика синхронизации будет добавлена позже.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            IDPG
 * Text Domain:       wp-cloud-sync
 * License:           GPL-2.0-or-later
 *
 * @package WPCloudSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_CLOUD_SYNC_VERSION', '0.1.0' );
define( 'WP_CLOUD_SYNC_FILE', __FILE__ );
define( 'WP_CLOUD_SYNC_DIR', plugin_dir_path( __FILE__ ) );

require_once WP_CLOUD_SYNC_DIR . 'includes/class-wp-cloud-sync.php';
require_once WP_CLOUD_SYNC_DIR . 'includes/class-wp-cloud-sync-downloader.php';

/**
 * Единственная точка входа в плагин.
 *
 * @return \WPCloudSync\WP_Cloud_Sync
 */
function wp_cloud_sync() {
	return \WPCloudSync\WP_Cloud_Sync::instance();
}

/**
 * Загрузчик (удалённая часть).
 *
 * @return \WPCloudSync\WP_Cloud_Sync_Downloader
 */
function wp_cloud_sync_downloader() {
	return \WPCloudSync\WP_Cloud_Sync_Downloader::instance();
}

wp_cloud_sync();
wp_cloud_sync_downloader();

/**
 * Активация: планируем крон загрузчика (если включён режим и автозагрузка).
 */
function wp_cloud_sync_activate() {
	wp_cloud_sync_downloader()->maybe_schedule();
}

/**
 * Деактивация: снимаем крон загрузчика.
 */
function wp_cloud_sync_deactivate() {
	wp_cloud_sync_downloader()->clear_cron();
}

register_activation_hook( __FILE__, 'wp_cloud_sync_activate' );
register_deactivation_hook( __FILE__, 'wp_cloud_sync_deactivate' );
