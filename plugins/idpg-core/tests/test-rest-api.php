<?php
/**
 * Юнит-тесты REST API плагина IDPG Core.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_REST_API
 */
class Test_REST_API extends WP_UnitTestCase {

	/**
	 * Маршруты idpg/v1 зарегистрированы в REST-сервере.
	 */
	public function test_routes_registered() {
		$routes = rest_get_server()->get_routes();
		$keys   = array_keys( $routes );
		$found  = false;

		foreach ( $keys as $route ) {
			if ( 0 === strpos( $route, '/idpg/v1' ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'Не найдено ни одного маршрута idpg/v1' );
	}

	/**
	 * Метод subscribe с некорректным email возвращает ошибку 400.
	 */
	public function test_subscribe_bad_email() {
		$request  = new WP_REST_Request( 'POST', '/idpg/v1/subscribe' );
		$request->set_param( 'email', 'not-an-email' );

		$result = IDPG_REST_API::instance()->subscribe( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Метод subscribe с honeypot отвечает «успехом», ничего не сохраняя.
	 */
	public function test_subscribe_honeypot() {
		global $wpdb;
		$request  = new WP_REST_Request( 'POST', '/idpg/v1/subscribe' );
		$request->set_param( 'email', 'bot@example.com' );
		$request->set_param( 'idpg_company', 'bot-filled' );

		$result = IDPG_REST_API::instance()->subscribe( $request );

		$this->assertNotInstanceOf( 'WP_Error', $result );

		// Таблица тут не создана — значит, честный успех мог прийти только от honeypot`а.
		$table = $wpdb->prefix . 'idpg_subscribers';
		$this->assertNotEquals( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	}

	/**
	 * update_venue с несуществующей площадкой возвращает 404.
	 */
	public function test_update_venue_not_found() {
		$request  = new WP_REST_Request( 'PUT', '/idpg/v1/venues/999999' );
		$request->set_param( 'id', 999999 );

		$result = IDPG_REST_API::instance()->update_venue( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 404, $result->get_error_data()['status'] );
	}

	/**
	 * Организатор не может редактировать чужую площадку (защита владельца).
	 */
	public function test_update_venue_forbidden_for_other_owner() {
		$owner = $this->factory()->user->create( array(
			'role' => 'administrator',
		) );

		$venue_id = $this->factory()->post->create( array(
			'post_type'   => 'venue',
			'post_status' => 'publish',
			'post_author' => $owner,
			'post_title'  => 'Чужая площадка',
		) );

		// Организатор, который НЕ владеет площадкой.
		$organizer = $this->factory()->user->create();
		$organizer = new WP_User( $organizer );
		$organizer->add_role( IDPG_Roles::ROLE_SLUG );
		wp_set_current_user( $organizer->ID );
		update_option( 'idpg_roles_created', true );

		$request  = new WP_REST_Request( 'PUT', '/idpg/v1/venues/' . $venue_id );
		$request->set_param( 'id', $venue_id );

		$result = IDPG_REST_API::instance()->update_venue( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'idpg_rest_forbidden_owner', $result->get_error_code() );
	}

	/**
	 * Регресс: "чистый" организатор (только role event_organizer, без
	 * subscriber) имеет edit_posts, но всё равно не может редактировать
	 * чужую площадку. Проверка владельца должна опираться на edit_others_events,
	 * а не edit_posts.
	 */
	public function test_update_venue_forbidden_for_pure_organizer_with_edit_posts() {
		$owner = $this->factory()->user->create( array(
			'role' => 'administrator',
		) );

		$venue_id = $this->factory()->post->create( array(
			'post_type'   => 'venue',
			'post_status' => 'publish',
			'post_author' => $owner,
			'post_title'  => 'Чужая площадка (регресс)',
		) );

		// Организатор с ТОЛЬКО ролью event_organizer (у него есть edit_posts).
		$organizer = $this->factory()->user->create();
		$organizer = new WP_User( $organizer );
		foreach ( $organizer->roles as $role ) {
			$organizer->remove_role( $role );
		}
		$organizer->add_role( IDPG_Roles::ROLE_SLUG );
		IDPG_Roles::instance()->create_roles();
		wp_set_current_user( $organizer->ID );

		// Подтверждаем: именно сценарий "дыры" — у организатора есть edit_posts.
		$this->assertTrue( current_user_can( 'edit_posts' ) );
		$this->assertFalse( current_user_can( 'edit_others_events' ) );

		$request  = new WP_REST_Request( 'PUT', '/idpg/v1/venues/' . $venue_id );
		$request->set_param( 'id', $venue_id );

		$result = IDPG_REST_API::instance()->update_venue( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'idpg_rest_forbidden_owner', $result->get_error_code() );
	}

	/**
	 * Регресс: permission_callback can_manage_events отклоняет "чистого"
	 * организатора при обращении к чужой площадке.
	 */
	public function test_can_manage_events_forbidden_for_pure_organizer_other_owner() {
		$owner = $this->factory()->user->create( array(
			'role' => 'administrator',
		) );

		$venue_id = $this->factory()->post->create( array(
			'post_type'   => 'venue',
			'post_status' => 'publish',
			'post_author' => $owner,
			'post_title'  => 'Чужая площадка (permission)',
		) );

		$organizer = $this->factory()->user->create();
		$organizer = new WP_User( $organizer );
		foreach ( $organizer->roles as $role ) {
			$organizer->remove_role( $role );
		}
		$organizer->add_role( IDPG_Roles::ROLE_SLUG );
		IDPG_Roles::instance()->create_roles();
		wp_set_current_user( $organizer->ID );

		$this->assertTrue( current_user_can( 'edit_posts' ) );

		$request = new WP_REST_Request( 'PUT', '/idpg/v1/venues/' . $venue_id );
		$request->set_param( 'id', $venue_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$result = IDPG_REST_API::instance()->can_manage_events( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'idpg_rest_forbidden_owner', $result->get_error_code() );
	}

	/**
	 * Регресс: permission_callback can_manage_events разрешает "чистому"
	 * организатору управлять СВОЕЙ площадкой.
	 */
	public function test_can_manage_events_allowed_own_venue() {
		$organizer = $this->factory()->user->create();
		$organizer = new WP_User( $organizer );
		foreach ( $organizer->roles as $role ) {
			$organizer->remove_role( $role );
		}
		$organizer->add_role( IDPG_Roles::ROLE_SLUG );
		IDPG_Roles::instance()->create_roles();
		wp_set_current_user( $organizer->ID );

		$venue_id = $this->factory()->post->create( array(
			'post_type'   => 'venue',
			'post_status' => 'publish',
			'post_author' => $organizer->ID,
			'post_title'  => 'Своя площадка (permission)',
		) );

		$request = new WP_REST_Request( 'PUT', '/idpg/v1/venues/' . $venue_id );
		$request->set_param( 'id', $venue_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$result = IDPG_REST_API::instance()->can_manage_events( $request );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertTrue( $result );
	}

	/**
	 * Владелец (автор) может обновить свою площадку.
	 */
	public function test_update_venue_owner_allowed() {
		$organizer = $this->factory()->user->create( array(
			'role' => IDPG_Roles::ROLE_SLUG,
		) );
		wp_set_current_user( $organizer );
		IDPG_Roles::instance()->create_roles();

		$venue_id = $this->factory()->post->create( array(
			'post_type'   => 'venue',
			'post_status' => 'publish',
			'post_author' => $organizer,
			'post_title'  => 'Моя площадка',
		) );

		$request  = new WP_REST_Request( 'PUT', '/idpg/v1/venues/' . $venue_id );
		$request->set_param( 'id', $venue_id );
		$request->set_param( 'title', 'Новое название' );

		$result = IDPG_REST_API::instance()->update_venue( $request );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'Новое название', get_the_title( $venue_id ) );
	}
}
