<?php
/**
 * Plugin Name:       Иду по городу — Импорт событий
 * Plugin URI:        https://example.com/idpg-event-import
 * Description:       Импорт событий с площадок партнёров: JSON REST API и iCal (.ics). Автоимпорт по расписанию (cron) и ручной запуск из админки / WP-CLI. Работает поверх CPT event плагина idpg-core.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Иду по городу
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * Text Domain:       idpg-event-import
 *
 * @package Idpg_Event_Import
 */

// Прямой доступ запрещён.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IDPGEI_FILE', __FILE__ );
define( 'IDPGEI_DIR', plugin_dir_path( __FILE__ ) );
define( 'IDPGEI_URL', plugin_dir_url( __FILE__ ) );
define( 'IDPGEI_VERSION', '1.0.0' );

/**
 * Проверка окружения: без idpg-core модуль бессмысленен.
 *
 * Активация деактивирует себя, если ядро не найдено.
 *
 * @return void
 */
function idpgei_check_dependencies() {
	if ( class_exists( 'IDPG_CPT' ) || function_exists( 'idpg_core_init' ) ) {
		return; // idpg-core активен — всё в порядке.
	}

	// Ядро не активно: деактивируем себя и объясняем причину.
	deactivate_plugins( plugin_basename( IDPGEI_FILE ) );
	wp_die( esc_html__(
		'Плагину «Иду по городу — Импорт событий» требуется активный плагин idpg-core (типы записей event/venue). Активируйте idpg-core и попробуйте снова.',
		'idpg-event-import'
	) );
}
register_activation_hook( IDPGEI_FILE, 'idpgei_check_dependencies' );

// Классы модуля.
require_once IDPGEI_DIR . 'includes/class-import-provider.php';
require_once IDPGEI_DIR . 'includes/class-provider-rest.php';
require_once IDPGEI_DIR . 'includes/class-provider-ical.php';
require_once IDPGEI_DIR . 'includes/class-import-mapper.php';
require_once IDPGEI_DIR . 'includes/class-event-import.php';
require_once IDPGEI_DIR . 'includes/class-import-admin.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once IDPGEI_DIR . 'includes/class-import-cli.php';
}

/**
 * Активация: единый cron-хук импорта (дважды не планируем).
 *
 * @return void
 */
function idpgei_activate() {
	if ( ! wp_next_scheduled( IDPG_Event_Import::CRON_HOOK ) ) {
		wp_schedule_event( time() + 60, 'hourly', IDPG_Event_Import::CRON_HOOK );
	}
}
register_activation_hook( IDPGEI_FILE, 'idpgei_activate' );

/**
 * Деактивация: снимаем cron.
 *
 * @return void
 */
function idpgei_deactivate() {
	wp_clear_scheduled_hook( IDPG_Event_Import::CRON_HOOK );
}
register_deactivation_hook( IDPGEI_FILE, 'idpgei_deactivate' );

/**
 * Инициализация модуля импорта.
 *
 * @return void
 */
function idpgei_init() {
	// Ядро обязано быть активно (защита от ручной активации без зависимостей).
	if ( ! class_exists( 'IDPG_CPT' ) && ! function_exists( 'idpg_core_init' ) ) {
		add_action( 'admin_notices', function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( '«Импорт событий» неактивен: требуется плагин idpg-core.', 'idpg-event-import' )
			);
		} );
		return;
	}

	IDPG_Event_Import::instance();
	IDPG_Import_Admin::instance();
}
add_action( 'plugins_loaded', 'idpgei_init', 20 );

/**
 * WP-CLI команды.
 *
 * @return void
 */
function idpgei_register_cli() {
	if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'IDPG_Event_Import' ) ) {
		WP_CLI::add_command( 'idpgei', 'IDPGEI_CLI_Commands' );
	}
}
add_action( 'cli_init', 'idpgei_register_cli', 20 );
