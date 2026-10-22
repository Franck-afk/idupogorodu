<?php
/**
 * Кастомные селекты Tom Select на вкладке «События».
 *
 * Все <select> на вкладке получают класс idpg-js-select, чтобы
 * event-fields.js инициализировал их Tom Select (локальная библиотека
 * vendor/tom-select). Стили — в editor.css.
 *
 * @package idpg-core
 */

/**
 * Class Test_Event_Fields_Custom_Select
 */
class Test_Event_Fields_Custom_Select extends WP_UnitTestCase {

	/**
	 * Подключает тему и admin-хелперы (по образцу Test_Widget_Areas_Page_Notice).
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

		unset( $_GET['tab'], $_GET['event_id'], $_GET['event_search'] );
	}

	/**
	 * Сбрасывает глобальное состояние после теста.
	 */
	protected function tearDown(): void {
		unset( $_GET['tab'], $_GET['event_id'], $_GET['event_search'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Рендер страницы «IDPG» в буфер.
	 *
	 * @return string HTML страницы.
	 */
	private function render_page() {
		ob_start();
		idpg_widget_areas_admin_page();
		return (string) ob_get_clean();
	}

	/**
	 * Каждый <select> на форме полей события несёт класс idpg-js-select.
	 *
	 * Иначе JS не подхватит селект и останется нативное выпадающее меню.
	 */
	public function test_selects_on_event_form_have_custom_select_class() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// CPT event использует capability_type 'event' + map_meta_cap:
		// без этих примитивных капов render_event_form() не отрисует
		// кнопку «Открыть в редакторе WP» (get_edit_post_link() вернёт null).
		// Капы выдаются до wp_set_current_user: тот кэширует allcaps.
		$user = new WP_User( $admin_id );
		$user->add_cap( 'edit_events' );
		$user->add_cap( 'edit_others_events' );
		$user->add_cap( 'edit_published_events' );

		wp_set_current_user( $admin_id );

		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'publish',
				'post_title'  => 'Тестовое событие',
				'post_author' => $admin_id,
			)
		);

		$_GET['tab']      = 'events';
		$_GET['event_id'] = (string) $event_id;

		$html = $this->render_page();

		// Проверяем только саму форму события: на странице есть и другие
		// вкладки (область виджетов), чьи селекты не трогаем.
		$this->assertSame( 1, preg_match( '/<form[^>]*idpg-event-fields-form.*?<\/form>/s', $html, $m ) );
		$form = $m[0];

		$select_total = substr_count( $form, '<select ' );

		$this->assertGreaterThan( 0, $select_total );
		$this->assertSame( $select_total, substr_count( $form, 'class="idpg-js-select"' ) );
		$this->assertSame( $select_total, substr_count( $form, '<select id="' ) );
	}

	/**
	 * Tom Select подключается на страницу вкладки «События».
	 *
	 * Хук admin_enqueue_scripts не используется напрямую: ядро на этом
	 * хуке зовёт site health, которому в тестах не хватает $current_screen.
	 */
	public function test_tom_select_assets_enqueued_on_tab_hook() {
		require_once ABSPATH . 'wp-admin/includes/media.php';

		IDPG_Event_Fields::instance()->enqueue_assets( 'toplevel_page_idpg-widget-areas' );

		$this->assertTrue( wp_style_is( 'idpg-tom-select', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'idpg-tom-select', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'idpg-event-fields', 'enqueued' ) );

		// На чужом экране вкладка ничего не подключает.
		// Tom Select — зависимость idpg-event-fields: dequeue родителя,
		// иначе recurse_deps() вернёт «enqueued» для зависимости.
		wp_dequeue_style( 'idpg-tom-select' );
		wp_dequeue_script( 'idpg-tom-select' );
		wp_dequeue_script( 'idpg-event-fields' );
		IDPG_Event_Fields::instance()->enqueue_assets( 'index.php' );
		$this->assertFalse( wp_style_is( 'idpg-tom-select', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'idpg-tom-select', 'enqueued' ) );
	}
}
