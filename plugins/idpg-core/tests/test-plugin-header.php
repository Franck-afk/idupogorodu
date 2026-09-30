<?php
/**
 * Сторож заголовка плагина idpg-core: обязательные поля и их согласованность.
 *
 * Контекст. DEV-23 требовал привести заголовки плагинов к единому виду. У
 * `idpg-auth` поля `Requires at least` / `Tested up to` / `Requires PHP` были,
 * а у `idpg-core` не хватало `Tested up to` — из-за чего карточку нельзя было
 * закрыть (DEV-139).
 *
 * Важная деталь WordPress: `get_plugin_data()` разбирает НЕ все заголовки.
 * Его ключи — `RequiresWP` (`Requires at least`) и `RequiresPHP`
 * (`Requires PHP`); заголовка `Tested up to` в списке по умолчанию нет вовсе
 * (см. `wp-admin/includes/plugin.php`), поэтому он читается отдельно через
 * `get_file_data()`. Тест учитывает это, иначе поля «не находились» бы при
 * верном коде.
 *
 * @package idpg-core
 */

/**
 * Class Test_Plugin_Header
 */
class Test_Plugin_Header extends WP_UnitTestCase {

	/**
	 * Путь к файлу плагина idpg-core.
	 *
	 * @return string
	 */
	private function plugin_file() {
		return dirname( __DIR__ ) . '/idpg-core.php';
	}

	/**
	 * Путь к файлу плагина idpg-auth (для сверки единообразия).
	 *
	 * @return string
	 */
	private function auth_plugin_file() {
		return dirname( dirname( __DIR__ ) ) . '/idpg-auth/idpg-auth.php';
	}

	/**
	 * Разобранный заголовок через штатный get_plugin_data().
	 *
	 * @param string $file Путь к файлу плагина.
	 * @return array
	 */
	private function header( $file ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_plugin_data( $file, false, false );
	}

	/**
	 * Произвольный заголовок, которого нет в get_plugin_data().
	 *
	 * @param string $file  Путь к файлу плагина.
	 * @param string $label Имя заголовка.
	 * @return string
	 */
	private function raw_header( $file, $label ) {
		$data = get_file_data( $file, array( 'Value' => $label ), 'plugin' );

		return isset( $data['Value'] ) ? trim( (string) $data['Value'] ) : '';
	}

	/**
	 * Файлы плагинов на месте.
	 */
	public function test_plugin_files_exist() {
		$this->assertFileExists( $this->plugin_file() );
		$this->assertFileExists( $this->auth_plugin_file() );
	}

	/**
	 * Заголовок вообще разбирается: имя и версия непустые.
	 */
	public function test_header_parses() {
		$data = $this->header( $this->plugin_file() );

		$this->assertNotSame( '', $data['Name'], 'get_plugin_data() не увидел Plugin Name.' );
		$this->assertNotSame( '', $data['Version'], 'get_plugin_data() не увидел Version.' );
	}

	/**
	 * `Requires at least` присутствует и похоже на версию WordPress.
	 */
	public function test_requires_wp_present() {
		$data = $this->header( $this->plugin_file() );

		$this->assertNotSame( '', $data['RequiresWP'], 'Нет заголовка Requires at least.' );
		$this->assertMatchesRegularExpression( '/^\d+(\.\d+)*$/', $data['RequiresWP'] );
	}

	/**
	 * `Requires PHP` присутствует и похоже на версию PHP.
	 */
	public function test_requires_php_present() {
		$data = $this->header( $this->plugin_file() );

		$this->assertNotSame( '', $data['RequiresPHP'], 'Нет заголовка Requires PHP.' );
		$this->assertMatchesRegularExpression( '/^\d+(\.\d+)*$/', $data['RequiresPHP'] );
	}

	/**
	 * `Tested up to` присутствует (главный предмет DEV-139).
	 */
	public function test_tested_up_to_present() {
		$tested = $this->raw_header( $this->plugin_file(), 'Tested up to' );

		$this->assertNotSame( '', $tested, 'Нет заголовка Tested up to.' );
		$this->assertMatchesRegularExpression( '/^\d+(\.\d+)*$/', $tested );
	}

	/**
	 * `Tested up to` не ниже `Requires at least` — иначе заявление бессмысленно.
	 */
	public function test_tested_up_to_not_below_requires_wp() {
		$data   = $this->header( $this->plugin_file() );
		$tested = $this->raw_header( $this->plugin_file(), 'Tested up to' );

		$this->assertTrue(
			version_compare( $tested, $data['RequiresWP'], '>=' ),
			'Tested up to ниже Requires at least.'
		);
	}

	/**
	 * Единообразие с idpg-auth: требования к PHP и WP-потолок совпадают.
	 *
	 * DEV-139: значения сознательно оставлены фактическими (PHP 7.4), а не
	 * подняты до 8.2 — код не использует PHP 8-фич, а расхождение с idpg-auth
	 * и темой ломало бы единообразие.
	 */
	public function test_consistent_with_auth_plugin() {
		$core = $this->header( $this->plugin_file() );
		$auth = $this->header( $this->auth_plugin_file() );

		$this->assertSame(
			$auth['RequiresPHP'],
			$core['RequiresPHP'],
			'Requires PHP у idpg-core и idpg-auth расходятся.'
		);
		$this->assertSame(
			$this->raw_header( $this->auth_plugin_file(), 'Tested up to' ),
			$this->raw_header( $this->plugin_file(), 'Tested up to' ),
			'Tested up to у idpg-core и idpg-auth расходятся.'
		);
	}
}
