<?php
/**
 * Юнит-тесты ролей и capabilities плагина IDPG Core.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Roles
 */
class Test_Roles extends WP_UnitTestCase {

	/**
	 * Экземпляр IDPG_Roles.
	 *
	 * @var IDPG_Roles
	 */
	protected $roles;

	/**
	 * Подготовка: создание ролей перед тестом.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->roles = IDPG_Roles::instance();
	}

	/**
	 * Роль организатора создаётся.
	 */
	public function test_organizer_role_created() {
		IDPG_Roles::instance()->create_roles();
		$role = get_role( IDPG_Roles::ROLE_SLUG );
		$this->assertNotNull( $role );
		$this->assertSame( IDPG_Roles::ROLE_SLUG, $role->name );
	}

	/**
	 * Организатор умеет совершать базовые действия с событиями.
	 */
	public function test_organizer_event_caps() {
		IDPG_Roles::instance()->create_roles();
		$role = get_role( IDPG_Roles::ROLE_SLUG );
		$this->assertTrue( $role->has_cap( 'edit_events' ) );
		// Публикация событий — модерация администратором, у организатора
		// publish_events отсутствует (class-roles.php:186).
		$this->assertFalse( $role->has_cap( 'publish_events' ) );
		$this->assertTrue( $role->has_cap( 'delete_events' ) );
		$this->assertFalse( $role->has_cap( 'edit_others_events' ) );
		$this->assertFalse( $role->has_cap( 'manage_options' ) );
	}

	/**
	 * Организатор не имеет прав чужих площадок.
	 */
	public function test_organizer_venue_ownership_caps() {
		IDPG_Roles::instance()->create_roles();
		$role = get_role( IDPG_Roles::ROLE_SLUG );
		$this->assertTrue( $role->has_cap( 'edit_venues' ) );
		$this->assertFalse( $role->has_cap( 'edit_others_venues' ) );
		$this->assertFalse( $role->has_cap( 'delete_others_venues' ) );
	}

	/**
	 * get_role_capabilities возвращает только активные capabilities.
	 */
	public function test_get_role_capabilities() {
		IDPG_Roles::instance()->create_roles();
		$caps = IDPG_Roles::instance()->get_role_capabilities( IDPG_Roles::ROLE_SLUG );
		$this->assertIsArray( $caps );
		$this->assertContains( 'edit_events', $caps );
	}

	/**
	 * Для несуществующей роли возвращается пустой массив.
	 */
	public function test_get_role_capabilities_unknown() {
		$caps = IDPG_Roles::instance()->get_role_capabilities( 'no_such_role_xyz' );
		$this->assertSame( array(), $caps );
	}

	/**
	 * Удаление роли организатора при деактивации.
	 */
	public function test_remove_roles() {
		IDPG_Roles::instance()->create_roles();
		$this->assertNotNull( get_role( IDPG_Roles::ROLE_SLUG ) );
		IDPG_Roles::instance()->remove_roles();
		$this->assertNull( get_role( IDPG_Roles::ROLE_SLUG ) );
	}

	/**
	 * На фронтенде панель WordPress у организатора скрыта штатным фильтром.
	 */
	public function test_admin_bar_hidden_for_organizer_on_frontend() {
		$this->roles->create_roles();
		wp_set_current_user( $this->factory->user->create( array( 'role' => IDPG_Roles::ROLE_SLUG ) ) );

		$this->assertFalse( $this->roles->hide_frontend_admin_bar( true ) );
	}

	/**
	 * Фильтр не трогает решение ядра у прочих пользователей.
	 */
	public function test_admin_bar_kept_for_other_users_on_frontend() {
		$this->roles->create_roles();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertTrue( $this->roles->hide_frontend_admin_bar( true ) );
		$this->assertFalse( $this->roles->hide_frontend_admin_bar( false ) );
	}

	/**
	 * Гость и неавторизованный пользователь панель не теряют по ошибке.
	 */
	public function test_admin_bar_filter_for_logged_out_user() {
		$this->roles->create_roles();
		wp_set_current_user( 0 );

		$this->assertFalse( $this->roles->hide_frontend_admin_bar( false ) );
	}

	/**
	 * В админке фильтр не принимает решение: там панель скрывается через CSS.
	 *
	 * Is_admin_bar_showing() возвращает true по ветке is_admin() до
	 * применения фильтра, поэтому проверяем, что метод туда не лезет.
	 */
	public function test_admin_bar_filter_does_not_touch_admin() {
		$this->roles->create_roles();
		wp_set_current_user( $this->factory->user->create( array( 'role' => IDPG_Roles::ROLE_SLUG ) ) );

		set_current_screen( 'dashboard' );

		$this->assertTrue( $this->roles->hide_frontend_admin_bar( true ) );

		set_current_screen( 'front' );
	}

	/**
	 * В админке организатору добавляется CSS, скрывающий панель.
	 */
	public function test_admin_bar_css_added_for_organizer_in_admin() {
		$this->roles->create_roles();
		wp_set_current_user( $this->factory->user->create( array( 'role' => IDPG_Roles::ROLE_SLUG ) ) );

		set_current_screen( 'dashboard' );

		wp_enqueue_style( 'admin-bar' );

		$added = $this->admin_bar_css_added_by( array( $this->roles, 'hide_admin_bar_css' ) );

		$this->assertStringContainsString(
			'#wpadminbar{display:none !important;}',
			$added,
			'CSS скрытия панели должен попасть в admin-bar'
		);
		$this->assertStringContainsString(
			'html.wp-toolbar{padding-top:0 !important;}',
			$added,
			'Отступ html.wp-toolbar должен сниматься'
		);

		set_current_screen( 'front' );
	}

	/**
	 * CSS панели не добавляется прочим пользователям.
	 */
	public function test_admin_bar_css_not_added_for_administrator() {
		$this->roles->create_roles();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		set_current_screen( 'dashboard' );

		wp_enqueue_style( 'admin-bar' );

		$added = $this->admin_bar_css_added_by( array( $this->roles, 'hide_admin_bar_css' ) );

		$this->assertStringNotContainsString(
			'#wpadminbar{display:none !important;}',
			$added,
			'Администратору панель скрывать нельзя'
		);

		set_current_screen( 'front' );
	}

	/**
	 * Какая именно часть инлайнового CSS добавлена вызовом $callback.
	 *
	 * Сравнивается прираст, а не итоговое значение: wp_styles() — глобальный
	 * объект, и накопленные ранее правила (в том числе добавленные ядром на
	 * admin_enqueue_scripts) переживают границу между тестами. Проверка итога
	 * давала бы ложное срабатывание при чужом фоне и пропускала бы дефект,
	 * если бы метод перестал добавлять CSS.
	 *
	 * @param callable $callback Вызываемый метод.
	 * @return string Добавленный CSS.
	 */
	private function admin_bar_css_added_by( $callback ) {
		$before = $this->admin_bar_inline_css();

		call_user_func( $callback );

		$after = $this->admin_bar_inline_css();

		if ( '' === $before || 0 !== strpos( $after, $before ) ) {
			// Правила перезаписаны, а не дописаны: берём всё, что появилось.
			return '' === $before ? $after : substr( $after, strlen( $before ) );
		}

		return substr( $after, strlen( $before ) );
	}

	/**
	 * Инлайновый CSS, накопленный на стиле admin-bar.
	 *
	 * @return string Склеенный CSS.
	 */
	private function admin_bar_inline_css() {
		$after = wp_styles()->get_data( 'admin-bar', 'after' );

		return is_array( $after ) ? implode( '', $after ) : (string) $after;
	}
}
