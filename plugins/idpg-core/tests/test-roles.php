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
	 *
	 * Публикация событий — только через модерацию: publish_events
	 * у организатора отсутствует, его события уходят в pending.
	 */
	public function test_organizer_event_caps() {
		IDPG_Roles::instance()->create_roles();
		$role = get_role( IDPG_Roles::ROLE_SLUG );
		$this->assertTrue( $role->has_cap( 'edit_events' ) );
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
}
