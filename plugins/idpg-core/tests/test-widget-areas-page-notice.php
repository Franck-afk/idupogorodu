<?php
/**
 * Страницное сообщение админ-страницы «IDPG» (плашка id="message").
 *
 * Страница областей виджетов знает только свои коды idpg_msg
 * (added, updated, deleted, empty) и свои вкладки (list, form).
 * Вкладки плагина показывают свои сообщения сами: «События» —
 * «Поля события сохранены.», «Гео» — «Файл пуст или не распознан.».
 *
 * Ловит два дефекта: пустую зелёную плашку под H1 для чужого кода
 * (saved/imported/error/privacy_deleted) и чужой текст «Не указано
 * название области.» при коде 'empty' на вкладке «Гео».
 *
 * @package idpg-core
 */

/**
 * Class Test_Widget_Areas_Page_Notice
 */
class Test_Widget_Areas_Page_Notice extends WP_UnitTestCase {

	/**
	 * Подключает тему и admin-хелперы (по образцу Test_Widget_Areas_Admin_Page).
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

		unset( $_GET['tab'], $_GET['edit'], $_GET['idpg_msg'], $_GET['event_id'], $_GET['event_search'] );
	}

	/**
	 * Сбрасывает глобальное состояние после теста.
	 */
	protected function tearDown(): void {
		unset( $_GET['tab'], $_GET['edit'], $_GET['idpg_msg'], $_GET['event_id'], $_GET['event_search'] );
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
	 * Чужой код idpg_msg не даёт пустой плашки под H1.
	 *
	 * После сохранения вкладки «События» (tab=events&idpg_msg=saved)
	 * страницная плашка id="message" не рисуется (код чужой), а своё
	 * сообщение вкладка показывает сама.
	 */
	public function test_foreign_msg_code_renders_no_page_notice() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['tab']      = 'events';
		$_GET['idpg_msg'] = 'saved';

		$html = $this->render_page();

		$this->assertStringNotContainsString( 'id="message"', $html );
		$this->assertStringContainsString( 'Поля события сохранены.', $html );
	}

	/**
	 * Обратная проба: свои сообщения страницы по-прежнему видны.
	 *
	 * Редирект удаления области (tab=list&idpg_msg=deleted) обязан
	 * показывать и плашку id="message", и текст «Область удалена.» —
	 * иначе условие в widget-areas.php слишком строгое.
	 */
	public function test_own_msg_code_still_renders_page_notice() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['tab']      = 'list';
		$_GET['idpg_msg'] = 'deleted';

		$html = $this->render_page();

		$this->assertStringContainsString( 'id="message"', $html );
		$this->assertStringContainsString( 'Область удалена.', $html );
	}

	/**
	 * Код 'empty' на вкладке «Гео» — это «Файл пуст», а не темное
	 * «Не указано название области.»: страницная плашка не показывается,
	 * своё сообщение рисует вкладка.
	 */
	public function test_geo_empty_msg_does_not_render_widget_notice() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['tab']      = 'geo';
		$_GET['idpg_msg'] = 'empty';

		$html = $this->render_page();

		$this->assertStringNotContainsString( 'id="message"', $html );
		$this->assertStringNotContainsString( 'Не указано название области.', $html );
		$this->assertStringContainsString( 'Файл пуст или не распознан.', $html );
	}
}
