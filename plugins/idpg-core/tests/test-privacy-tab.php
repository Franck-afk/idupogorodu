<?php
/**
 * Тесты вкладки «Приватность (152-ФЗ)» на странице «IDPG» (DEV-288).
 *
 * Тема отдаёт точку расширения фильтром idpg_widget_areas_admin_tabs,
 * плагин регистрирует вкладку. Тест проверяет регистрацию и рендер
 * для администратора и отсутствие вывода для подписчика.
 *
 * @package idpg-core
 */

/**
 * Class Test_Privacy_Tab
 */
class Test_Privacy_Tab extends WP_UnitTestCase {

	/**
	 * Каталог рабочих тем.
	 *
	 * @return string
	 */
	private function theme_dir() {
		return dirname( dirname( dirname( __DIR__ ) ) ) . '/themes';
	}

	/**
	 * Исходник админ-страницы областей.
	 *
	 * @return string
	 */
	private function page_source() {
		return (string) file_get_contents( $this->theme_dir() . '/idpg_core/inc/widget-areas.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Подключает тему (по образцу Test_Widget_Areas_Admin_Page).
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'idpg_widget_areas_admin_page' ) ) {
			$theme_root = getenv( 'IDPG_THEME_DIR' )
				? getenv( 'IDPG_THEME_DIR' )
				: __DIR__ . '/../../../themes/idpg_core';

			$parent = dirname( $theme_root );
			add_filter(
				'theme_root',
				function () use ( $parent ) {
					return $parent;
				}
			);
			add_filter(
				'stylesheet_root',
				function () use ( $parent ) {
					return $parent;
				}
			);
			add_filter(
				'template_root',
				function () use ( $parent ) {
					return $parent;
				}
			);
			register_theme_directory( $parent );

			switch_theme( 'idpg_core' );

			if ( ! function_exists( 'idpg_widget_areas_admin_page' ) ) {
				require_once $theme_root . '/functions.php';
			}
		}

		require_once ABSPATH . 'wp-admin/includes/template.php';

		add_action( 'admin_menu', 'idpg_widget_areas_admin_menu' );

		unset( $_GET['tab'], $_GET['edit'], $_GET['idpg_msg'] );
	}

	/**
	 * Сброс состояния.
	 */
	protected function tearDown(): void {
		unset( $_GET['tab'], $_GET['edit'], $_GET['idpg_msg'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Тема объявляет точку расширения вкладок.
	 */
	public function test_theme_exposes_extension_point() {
		$src = $this->page_source();

		$this->assertStringContainsString( "apply_filters( 'idpg_widget_areas_admin_tabs', array() )", $src );
		$this->assertStringContainsString( '$allowed_tabs', $src );
		$this->assertStringContainsString( 'class="idpg-tab-in js-idpg-tab-item', $src );
	}

	/**
	 * Плагин регистрирует вкладку через фильтр.
	 */
	public function test_privacy_tab_registered() {
		$tabs = apply_filters( 'idpg_widget_areas_admin_tabs', array() );

		$this->assertArrayHasKey( 'privacy', $tabs );
		$this->assertNotEmpty( $tabs['privacy']['label'] );
		$this->assertTrue( is_callable( $tabs['privacy']['callback'] ) );
	}

	/**
	 * Фиксация cookie вкладки поддерживает любой слаг (не только list/form).
	 */
	public function test_tab_cookie_accepts_any_slug() {
		$this->assertStringContainsString( 'tab-idpg=([a-z0-9_-]+)', $this->page_source() );
	}

	/**
	 * Вкладка рендерится для администратора вместе со страницей.
	 */
	public function test_privacy_tab_renders_for_admin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['tab'] = 'privacy';

		ob_start();
		idpg_widget_areas_admin_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="idpg-privacy"', $html );
		$this->assertStringContainsString( 'Приватность (152-ФЗ)', $html );
		$this->assertStringContainsString( 'id="tab-idpg-privacy"', $html );
		$this->assertStringContainsString( 'js-idpg-tab-item active', $html );
	}

	/**
	 * Без manage_options страница не рендерится вовсе.
	 */
	public function test_privacy_tab_requires_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_GET['tab'] = 'privacy';

		ob_start();
		idpg_widget_areas_admin_page();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * Рендер вкладки напрямую: без прав — пусто.
	 */
	public function test_render_admin_tab_guards_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		IDPG_Privacy::instance()->render_admin_tab();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}
}
