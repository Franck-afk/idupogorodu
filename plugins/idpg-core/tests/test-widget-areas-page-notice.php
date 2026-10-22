<?php
/**
 * Страницное сообщение админ-страницы «IDPG» (плашка id="message").
 *
 * Страница областей виджетов знает только свои коды idpg_msg
 * (added, updated, deleted, empty) и свои вкладки (list, form).
 * Вкладки плагина показывают свои сообщения сами: «Гео» —
 * «Файл пуст или не распознан.», «События» — одноразовое
 * «Поля события сохранены.» через user meta (не через URL).
 *
 * Ловит три дефекта: пустую зелёную плашку под H1 для чужого
 * кода (imported/error/privacy_deleted/legacy saved), чужой текст
 * «Не указано название области.» при коде 'empty' на «Гео» и
 * повторное показ плашки «Событий» при возвратах/F5.
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
	 * Старый URL-код idpg_msg=saved не показывает плашку и не даёт
	 * пустой страницной notice под H1.
	 *
	 * Уведомление «Поля события сохранены.» переехало в одноразовый
	 * user meta (см. USER_SAVED_META); ссылка с legacy-кодом в адресе
	 * больше не воскрешает сообщение.
	 */
	public function test_legacy_url_msg_saved_renders_nothing() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['tab']      = 'events';
		$_GET['idpg_msg'] = 'saved';

		$html = $this->render_page();

		$this->assertStringNotContainsString( 'id="message"', $html );
		$this->assertStringNotContainsString( 'Поля события сохранены.', $html );
	}

	/**
	 * Одноразовость: meta показывается ровно один раз и съедается.
	 *
	 * После рендера на активной вкладке «События» мета удалена —
	 * повторный рендер (F5, возврат) плашку не показывает.
	 */
	public function test_saved_notice_is_one_shot() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_user_meta( get_current_user_id(), IDPG_Event_Fields::USER_SAVED_META, 1 );
		$_GET['tab'] = 'events';

		$html = $this->render_page();
		$this->assertStringContainsString( 'Поля события сохранены.', $html );
		$this->assertSame( '', (string) get_user_meta( get_current_user_id(), IDPG_Event_Fields::USER_SAVED_META, true ) );

		$html_second = $this->render_page();
		$this->assertStringNotContainsString( 'Поля события сохранены.', $html_second );
	}

	/**
	 * Meta не «съедается» скрытым контейнером чужой вкладки.
	 *
	 * Callbacks всех вкладок выполняются при каждой загрузке: на
	 * активной «Гео» сообщение не показывается и meta сохраняется
	 * до реального открытия вкладки «События».
	 */
	public function test_saved_notice_not_consumed_on_other_tab() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_user_meta( get_current_user_id(), IDPG_Event_Fields::USER_SAVED_META, 1 );
		$_GET['tab'] = 'geo';

		$html = $this->render_page();
		$this->assertStringNotContainsString( 'Поля события сохранены.', $html );
		$this->assertSame( '1', (string) get_user_meta( get_current_user_id(), IDPG_Event_Fields::USER_SAVED_META, true ) );

		$_GET['tab'] = 'events';
		$html_events = $this->render_page();
		$this->assertStringContainsString( 'Поля события сохранены.', $html_events );
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
