<?php
/**
 * Юнит-тесты: организаторы (роль event_organizer) и их события с разной
 * полнотой заполнения полей.
 *
 * Проверяется:
 *  - создание нескольких организаторов с ролью event_organizer;
 *  - каждому организатору принадлежат только его события (post_author);
 *  - полнота полей события сохраняется корректно (полное / среднее /
 *    минимальное) в мета-полях, читаемых темой idpg_core;
 *  - организатор не редактирует чужие события (текущий пользователь).
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Organizer_Events
 */
class Test_Organizer_Events extends WP_UnitTestCase {

	/**
	 * Создать организатора с чистой ролью event_organizer.
	 *
	 * @param string $login Логин (латиница).
	 * @return WP_User
	 */
	protected function make_organizer( $login ) {
		$user_id = $this->factory()->user->create( array(
			'user_login' => $login,
			'role'       => 'subscriber',
		) );

		$organizer = new WP_User( $user_id );

		// Чистая роль: убираем все и оставляем только event_organizer.
		foreach ( $organizer->roles as $role ) {
			$organizer->remove_role( $role );
		}
		$organizer->add_role( IDPG_Roles::ROLE_SLUG );

		return $organizer;
	}

	/**
	 * Создать терм города.
	 *
	 * @param string $name Название.
	 * @return int
	 */
	protected function make_city( $name ) {
		return $this->factory()->term->create( array(
			'taxonomy' => 'city',
			'name'     => $name,
		) );
	}

	/**
	 * Создать терм категории.
	 *
	 * @param string $name Название.
	 * @return int
	 */
	protected function make_category( $name ) {
		return $this->factory()->term->create( array(
			'taxonomy' => 'event_category',
			'name'     => $name,
		) );
	}

	/**
	 * Создать площадку (venue).
	 *
	 * @param string $name Название.
	 * @return int
	 */
	protected function make_venue( $name ) {
		return $this->factory()->post->create( array(
			'post_type'   => 'venue',
			'post_status' => 'publish',
			'post_title'  => $name,
		) );
	}

	/**
	 * Создать событие от имени организатора.
	 *
	 * @param int   $organizer_id Автор (организатор).
	 * @param string $title        Заголовок.
	 * @param array $meta         Доп. мета-поля.
	 * @param array $terms        Таксономии: city_id / category_id.
	 * @return int
	 */
	protected function make_event( $organizer_id, $title, $meta = array(), $terms = array() ) {
		$post_id = $this->factory()->post->create( array(
			'post_type'   => 'event',
			'post_status' => 'publish',
			'post_title'  => $title,
			'post_author' => $organizer_id,
		) );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		if ( ! empty( $terms['city_id'] ) ) {
			wp_set_object_terms( $post_id, array( $terms['city_id'] ), 'city', false );
		}
		if ( ! empty( $terms['category_id'] ) ) {
			wp_set_object_terms( $post_id, array( $terms['category_id'] ), 'event_category', false );
		}

		return (int) $post_id;
	}

	/**
	 * Переключить текущего пользователя.
	 *
	 * @param int $user_id ID пользователя (0 = сброс).
	 * @return void
	 */
	protected function set_user( $user_id ) {
		wp_set_current_user( $user_id );
	}

	/**
	 * Роли организатора создаются единоразово и содержат нужную роль.
	 */
	public function test_event_organizer_role_created() {
		IDPG_Roles::instance()->create_roles();

		$this->assertTrue( get_role( IDPG_Roles::ROLE_SLUG ) instanceof WP_Role );
	}

	/**
	 * Несколько организаторов, у каждого — несколько событий, события
	 * привязаны к своему автору, количество совпадает.
	 */
	public function test_each_organizer_owns_own_events() {
		IDPG_Roles::instance()->create_roles();

		$org_a = $this->make_organizer( 'orga_alpha' );
		$org_b = $this->make_organizer( 'orga_beta' );

		$id_a_1 = $this->make_event( $org_a->ID, 'Событие A1' );
		$id_a_2 = $this->make_event( $org_a->ID, 'Событие A2' );
		$id_b_1 = $this->make_event( $org_b->ID, 'Событие B1' );

		// Авторство.
		$this->assertSame( (int) get_post( $id_a_1 )->post_author, (int) $org_a->ID );
		$this->assertSame( (int) get_post( $id_a_2 )->post_author, (int) $org_a->ID );
		$this->assertSame( (int) get_post( $id_b_1 )->post_author, (int) $org_b->ID );

		// Количество событий у каждого организатора.
		$events_a = get_posts( array(
			'post_type'      => 'event',
			'author'         => $org_a->ID,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		$events_b = get_posts( array(
			'post_type'      => 'event',
			'author'         => $org_b->ID,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$this->assertCount( 2, $events_a );
		$this->assertCount( 1, $events_b );

		// Организатор не пересекается с чужим контентом.
		$this->assertNotContains( $id_b_1, $events_a );
		$this->assertNotContains( $id_a_1, $events_b );
	}

	/**
	 * Полное событие: все мета-поля, читаемые темой, сохранены корректно.
	 */
	public function test_full_event_stores_all_meta() {
		IDPG_Roles::instance()->create_roles();
		$org = $this->make_organizer( 'orga_full' );

		$city_id    = $this->make_city( 'Нижний Новгород' );
		$category_id = $this->make_category( 'Концерт' );
		$venue_id   = $this->make_venue( 'Кремлёвский концертный зал' );

		$post_id = $this->make_event( $org->ID, 'Полный фестиваль', array(
			'_idpg_start_date'    => '2026-09-10T18:00',
			'_idpg_end_date'      => '2026-09-12T23:00',
			'_idpg_ticket_url'    => 'https://tickets.example/event/1',
			'_idpg_ticket_status' => 'in_sale',
			'_idpg_price_from'    => '500',
			'_idpg_price_to'      => '2500',
			'_idpg_currency'      => 'RUB',
			'_idpg_is_free'       => 'yes',
			'_idpg_venue'         => $venue_id,
			'_idpg_venue_address' => 'Кремль, корпус 1',
		), array(
			'city_id'     => $city_id,
			'category_id' => $category_id,
		) );

		// Мета.
		$this->assertSame( '2026-09-10T18:00', get_post_meta( $post_id, '_idpg_start_date', true ) );
		$this->assertSame( '2026-09-12T23:00', get_post_meta( $post_id, '_idpg_end_date', true ) );
		$this->assertSame( 'https://tickets.example/event/1', get_post_meta( $post_id, '_idpg_ticket_url', true ) );
		$this->assertSame( 'in_sale', get_post_meta( $post_id, '_idpg_ticket_status', true ) );
		$this->assertSame( '500', get_post_meta( $post_id, '_idpg_price_from', true ) );
		$this->assertSame( '2500', get_post_meta( $post_id, '_idpg_price_to', true ) );
		$this->assertSame( 'RUB', get_post_meta( $post_id, '_idpg_currency', true ) );
		$this->assertSame( 'yes', get_post_meta( $post_id, '_idpg_is_free', true ) );
		$this->assertSame( (string) $venue_id, (string) get_post_meta( $post_id, '_idpg_venue', true ) );
		$this->assertSame( 'Кремль, корпус 1', get_post_meta( $post_id, '_idpg_venue_address', true ) );

		// Термы.
		$cities = wp_get_object_terms( $post_id, 'city', array( 'fields' => 'ids' ) );
		$cats   = wp_get_object_terms( $post_id, 'event_category', array( 'fields' => 'ids' ) );
		$this->assertContains( (int) $city_id, $cities );
		$this->assertContains( (int) $category_id, $cats );
	}

	/**
	 * Минимальное событие: только заголовок и дата старта — остальное пусто/нет.
	 */
	public function test_minimal_event_has_only_required() {
		IDPG_Roles::instance()->create_roles();
		$org = $this->make_organizer( 'orga_minimal' );

		$post_id = $this->make_event( $org->ID, 'Минимальное событие', array(
			'_idpg_start_date' => '2026-10-01T12:00',
		) );

		$this->assertSame( '2026-10-01T12:00', get_post_meta( $post_id, '_idpg_start_date', true ) );

		// Прочие поля пусты.
		$this->assertSame( '', get_post_meta( $post_id, '_idpg_end_date', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_idpg_price_from', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_idpg_ticket_url', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_idpg_venue', true ) );
	}

	/**
	 * Средняя полнота: платное, с билетами, без площадки и категории.
	 */
	public function test_medium_event_keeps_partial_fields() {
		IDPG_Roles::instance()->create_roles();
		$org = $this->make_organizer( 'orga_medium' );

		$post_id = $this->make_event( $org->ID, 'Среднее событие', array(
			'_idpg_start_date'    => '2026-11-05T19:30',
			'_idpg_price_from'    => '800',
			'_idpg_ticket_url'    => 'https://tickets.example/event/2',
			'_idpg_ticket_status' => 'in_sale',
		) );

		// Заполнено.
		$this->assertSame( '2026-11-05T19:30', get_post_meta( $post_id, '_idpg_start_date', true ) );
		$this->assertSame( '800', get_post_meta( $post_id, '_idpg_price_from', true ) );
		$this->assertSame( 'https://tickets.example/event/2', get_post_meta( $post_id, '_idpg_ticket_url', true ) );
		$this->assertSame( 'in_sale', get_post_meta( $post_id, '_idpg_ticket_status', true ) );

		// Без площадки и цены до (средняя полнота).
		$this->assertSame( '', get_post_meta( $post_id, '_idpg_venue', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_idpg_price_to', true ) );
	}

	/**
	 * Изоляция: организатор не может редактировать чужое событие.
	 */
	public function test_organizer_cannot_edit_other_organizers_event() {
		IDPG_Roles::instance()->create_roles();

		$org_a = $this->make_organizer( 'orga_iso_a' );
		$org_b = $this->make_organizer( 'orga_iso_b' );

		$event_a = $this->make_event( $org_a->ID, 'Событие организатора A' );

		// Текущий пользователь — организатор B, чужое событие A.
		$this->set_user( $org_b->ID );

		$this->assertTrue( current_user_can( 'edit_events' ) );
		$this->assertFalse( current_user_can( 'edit_others_events' ) );
		$this->assertFalse( current_user_can( 'edit_post', $event_a ) );
		$this->assertFalse( current_user_can( 'delete_post', $event_a ) );

		// Своё событие B может править.
		$event_b = $this->make_event( $org_b->ID, 'Событие организатора B' );
		$this->assertTrue( current_user_can( 'edit_post', $event_b ) );
	}
}
