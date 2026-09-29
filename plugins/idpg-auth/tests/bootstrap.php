<?php
/**
 * PHPUnit bootstrap file для модульных тестов IDPG Auth.
 *
 * @package Idpg_Auth
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	/*
	 * Постоянный каталог стенда проверяем до sys_get_temp_dir(): очистка
	 * %TEMP% удаляла библиотеку WordPress, и тесты переставали запускаться
	 * с сообщением «Could not find .../wordpress-tests-lib». Каталог взят из
	 * bin\install-wp-tests.cmd плагина idpg-core. sys_get_temp_dir() сохранён
	 * как последний вариант, чтобы ничего не сломать.
	 */
	$_local_app_data = getenv( 'LOCALAPPDATA' );
	$_durable_dir   = $_local_app_data
		? rtrim( $_local_app_data, '/\\' ) . '/idpg-tests/wordpress-develop/tests/phpunit'
		: '';

	$_tests_dir = ( '' !== $_durable_dir && file_exists( "{$_durable_dir}/includes/functions.php" ) )
		? $_durable_dir
		: rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( dirname( __FILE__ ) ) . '/idpg-auth.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";