<?php
/**
 * Bootstrap тестов плагина idpg-event-import.
 *
 * Окружение WordPress Tests + idpg-core (CPT event) + этот плагин.
 *
 * @package Idpg_Event_Import
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	echo "WP_TESTS_DIR не задан. См. idpg-core/bin/install-wp-tests.cmd\n"; // phpcs:ignore
	exit( 1 );
}

$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Не найден {$_tests_dir}/includes/functions.php\n"; // phpcs:ignore
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

/**
 * Ручная загрузка тестируемых плагинов: сначала idpg-core (зависимость),
 * затем idpg-event-import.
 */
function _idpgei_manually_load_plugins() {
	require dirname( dirname( __DIR__ ) ) . '/idpg-core/idpg-core.php';
	require dirname( dirname( __DIR__ ) ) . '/idpg-event-import/idpg-event-import.php';

	// Инициализация ядра (CPT/roles) до установки тестовой среды.
	IDPG_Event_Import::instance();
}

tests_add_filter( 'muplugins_loaded', '_idpgei_manually_load_plugins' );

// Старт тестовой среды WordPress.
require "{$_tests_dir}/includes/bootstrap.php";
