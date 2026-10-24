<?php
/**
 * Сторож админ-страницы «Области виджетов» (DEV-273).
 *
 * Страница переведена из подменю «Внешний вид» в верхнее меню «IDPG» и
 * оформлена в стилистике Clearfy Pro. Тест статический: читает исходник
 * страницы и CSS и ловит возврат к старой привязке themes.php, потерю
 * разметки/токенов и случайное изменение контракта сохранения.
 *
 * @package idpg-core
 */

/**
 * Class Test_Widget_Areas_Admin_Page
 */
class Test_Widget_Areas_Admin_Page extends WP_UnitTestCase {

	/**
	 * Каталог рабочих тем: wp-content/themes.
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
	 * Стили админ-страницы.
	 *
	 * @return string
	 */
	private function css_source() {
		return (string) file_get_contents( $this->theme_dir() . '/idpg_core/assets/css/widget-areas-admin.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Подключает тему и admin-хелперы (по образцу Test_Theme).
	 *
	 * Файл functions.php грузит inc/widget-areas.php. Хуки перерегистрируются
	 * в каждом setUp: WP_UnitTestCase откатывает $wp_filter после теста,
	 * и registrations из require исчезают вместе с ним.
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
		add_action( 'admin_notices', 'idpg_widget_areas_widgets_notice' );

		unset( $_GET['tab'], $_GET['edit'], $_GET['idpg_msg'] );
	}

	/**
	 * Сбрасывает глобальное состояние после теста.
	 */
	protected function tearDown(): void {
		unset( $_GET['tab'], $_GET['edit'], $_GET['idpg_msg'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Меню «IDPG» — верхнее, страница доступна из него.
	 */
	public function test_menu_is_top_level_idpg() {
		$src = $this->page_source();

		$this->assertStringContainsString( 'add_menu_page(', $src );
		$this->assertStringContainsString( "'IDPG'", $src );
		$this->assertStringContainsString( "'idpg-widget-areas'", $src );
		$this->assertStringContainsString( "'dashicons-welcome-widgets-menus'", $src );
		$this->assertMatchesRegularExpression( "/'dashicons-welcome-widgets-menus',\s*61\s*\)/", $src );
		$this->assertStringContainsString( "add_submenu_page(\n\t\t'idpg-widget-areas'", $src );
	}

	/**
	 * Старая привязка к «Внешний вид» удалена полностью.
	 */
	public function test_legacy_appearance_bindings_removed() {
		$src = $this->page_source();

		$this->assertStringNotContainsString( 'themes.php?page=', $src );
		$this->assertStringNotContainsString( 'appearance_page_', $src );
	}

	/**
	 * Ассеты подключаются на новом экране верхнего уровня.
	 */
	public function test_assets_load_on_new_screen_only() {
		$this->assertStringContainsString(
			"'toplevel_page_idpg-widget-areas' !== \$hook_suffix",
			$this->page_source()
		);
	}

	/**
	 * После действий редирект ведёт на admin.php с нужной вкладкой.
	 */
	public function test_redirects_keep_tab_state() {
		$src = $this->page_source();

		$this->assertStringContainsString( 'admin.php?page=idpg-widget-areas&tab=list&idpg_msg=deleted', $src );
		$this->assertStringContainsString( 'admin.php?page=idpg-widget-areas&tab=form&idpg_msg=empty', $src );
		$this->assertStringContainsString( "admin.php?page=idpg-widget-areas&tab=list&idpg_msg=' . \$msg", $src );
	}

	/**
	 * Разметка повторяет каркас Clearfy Pro.
	 *
	 * Проверяются точные фрагменты, а не голые имена классов: подстрока
	 * вида `js-idpg-save-container` маскировала бы потерю класса-маркера.
	 */
	public function test_clearfy_layout_markup() {
		$src = $this->page_source();

		$markers = array(
			'<div class="wrap idpg idpg-settings-page js-idpg">',
			'class="idpg-pseudo-button"',
			'<h2 class="idpg-tab-wrapper js-idpg-tabs">',
			'class="idpg-tab-in js-idpg-tab-item',
			'<div class="idpg-field-header">',
			'<div class="idpg-option-field">',
			'<div class="idpg-save-container js-idpg-save-container">',
			'<div class="idpg-settings-col idpg-settings-col--right">',
			'<div class="idpg-widget">',
			'<span class="idpg-ico-help"',
		);

		foreach ( $markers as $marker ) {
			$this->assertStringContainsString( $marker, $src, 'Нет фрагмента разметки: ' . $marker );
		}
	}

	/**
	 * Поля «Место» и «Показывать» — нативные select, хелпер не вызывается.
	 */
	public function test_native_selects_replaced_custom_helper() {
		$src = $this->page_source();

		$this->assertStringContainsString( '<select id="idpg_area_position" name="idpg_area_position">', $src );
		$this->assertStringContainsString( '<select id="idpg_area_display" name="idpg_area_display">', $src );
		$this->assertStringContainsString( 'name="idpg_area_pages[]"', $src );
		$this->assertStringNotContainsString( "idpg_widget_areas_select( 'idpg_area_", $src );
		$this->assertStringContainsString( 'function idpg_widget_areas_select(', $src );
	}

	/**
	 * Контракт сохранения и рендера не изменился.
	 */
	public function test_behavior_contract_untouched() {
		$src = $this->page_source();

		$this->assertStringContainsString( "wp_verify_nonce( \$_POST['idpg_widget_areas_nonce'], 'idpg_widget_areas_action' )", $src );
		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $src );
		$this->assertStringContainsString( "update_option( 'idpg_widget_areas'", $src );
		$this->assertStringContainsString( 'function idpg_widget_areas_register_sidebars()', $src );
		$this->assertStringContainsString( 'function idpg_widget_areas_render( $position )', $src );
	}

	/**
	 * Вкладки работают без перезагрузки и запоминаются.
	 */
	public function test_tabs_js_remembers_state() {
		$src = $this->page_source();

		$this->assertStringContainsString( '.js-idpg-tabs', $src );
		$this->assertStringContainsString( '.js-idpg-tab-item', $src );
		$this->assertStringContainsString( 'tab-idpg=', $src );
		$this->assertStringContainsString( 'window.history.replaceState', $src );
	}

	/**
	 * CSS собран на токенах Clearfy Pro.
	 */
	public function test_css_uses_clearfy_tokens() {
		$css = $this->css_source();

		$tokens = array( '#19193d', '#165ff4', '#6da8e1', 'rgba(153, 144, 198, .2)', 'position: sticky', '#f4f4f4' );

		foreach ( $tokens as $token ) {
			$this->assertStringContainsString( $token, $css, 'Нет токена Clearfy: ' . $token );
		}
	}

	/**
	 * Старые стили кастомного селекта удалены вместе с его использованием.
	 */
	public function test_css_drops_legacy_select_styles() {
		$this->assertStringNotContainsString( 'idpg-wa-', $this->css_source() );
	}

	/**
	 * Функциональные проверки (тема подключена в setUp).
	 */

	/**
	 * Хуки меню и напоминания регистрируются в рантайме.
	 */
	public function test_admin_menu_hook_is_registered() {
		$this->assertNotFalse(
			has_action( 'admin_menu', 'idpg_widget_areas_admin_menu' ),
			'Хук admin_menu для страницы областей не зарегистрирован.'
		);
		$this->assertNotFalse(
			has_action( 'admin_notices', 'idpg_widget_areas_widgets_notice' ),
			'Хук admin_notices для напоминания не зарегистрирован.'
		);
	}

	/**
	 * Страница рендерится для администратора: шапка, вкладки, поля формы.
	 */
	public function test_admin_page_renders_for_administrator() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Тестовая страница',
				'post_status' => 'publish',
			)
		);
		$_GET['tab'] = 'form';

		ob_start();
		idpg_widget_areas_admin_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<h1>IDPG</h1>', $html );
		$this->assertStringContainsString( 'idpg-settings-page', $html );
		$this->assertStringContainsString( 'id="idpg-list"', $html );
		$this->assertStringContainsString( 'id="idpg-form"', $html );
		$this->assertStringContainsString( 'js-idpg-tab-item active', $html );
		$this->assertStringContainsString( 'id="idpg_area_title"', $html );
		$this->assertStringContainsString( 'id="idpg_area_display"', $html );
		$this->assertStringContainsString( 'class="idpg-save-container js-idpg-save-container"', $html );
		$this->assertStringContainsString( 'Тестовая страница', $html );
		$this->assertStringContainsString( 'idpg-ico-help', $html );
		$this->assertStringContainsString( 'idpg-widget', $html );
	}

	/**
	 * Сохранённая область появляется в списке с формой удаления.
	 */
	public function test_saved_area_shows_in_list() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option(
			'idpg_widget_areas',
			array(
				'my-area' => array(
					'title'    => 'Моя область',
					'position' => 'below-header',
					'display'  => 'all',
					'pages'    => array(),
				),
			)
		);

		ob_start();
		idpg_widget_areas_admin_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Моя область', $html );
		$this->assertStringContainsString( 'id="idpg-list"', $html );
		$this->assertStringContainsString( 'js-idpg-tab-item active', $html );
		$this->assertStringContainsString( 'name="idpg_area_action" value="delete"', $html );
	}

	/**
	 * Подписчик не видит страницу: вывод пустой.
	 */
	public function test_admin_page_blank_for_subscriber() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		idpg_widget_areas_admin_page();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}
}
