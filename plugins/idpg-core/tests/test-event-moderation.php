<?php
/**
 * Юнит-тесты модерации событий организаторов (IDPG_Event_Moderation).
 *
 * Проверяется:
 *  - организатор не может опубликовать событие программно (status → pending);
 *  - администратор публикует событие без изменения;
 *  - правка уже опубликованного события организатором не сбрасывает статус;
 *  - уведомления шлются админам и автору (перехват через фильтр wp_mail).
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Event_Moderation
 */
class Test_Event_Moderation extends WP_UnitTestCase {

	/**
	 * Инстанс модерации.
	 *
	 * @var IDPG_Event_Moderation|null
	 */
	protected $moderation;

	/**
	 * Буфер перехваченных писем.
	 *
	 * @var array
	 */
	protected $mails = array();

	/**
	 * Подготовка и перехват wp_mail.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->moderation = IDPG_Event_Moderation::instance();
		$this->mails      = array();
		add_filter( 'wp_mail', array( $this, 'capture_mail' ) );
	}

	/**
	 * Перехват wp_mail.
	 *
	 * @param array $args Аргументы wp_mail.
	 * @return array
	 */
	public function capture_mail( $args ) {
		$this->mails[] = $args;
		return $args;
	}

	/**
	 * Создать организатора.
	 *
	 * @param string $login Логин.
	 * @return WP_User
	 */
	protected function make_organizer( $login ) {
		$user_id = $this->factory()->user->create( array(
			'user_login' => $login,
			'role'       => 'subscriber',
		) );

		$user = new WP_User( $user_id );

		foreach ( $user->roles as $role ) {
			$user->remove_role( $role );
		}
		$user->add_role( IDPG_Roles::ROLE_SLUG );

		return $user;
	}

	/**
	 * Создать администратора.
	 *
	 * @param string $login Логин.
	 * @return WP_User
	 */
	protected function make_admin( $login ) {
		$user_id = $this->factory()->user->create( array(
			'user_login' => $login,
			'role'       => 'administrator',
		) );

		return new WP_User( $user_id );
	}

	/**
	 * Организатор программно публикует новое событие — статус равен pending.
	 */
	public function test_organizer_cannot_publish_new_event() {
		IDPG_Roles::instance()->create_roles();
		$org = $this->make_organizer( 'mod_org_a' );

		wp_set_current_user( $org->ID );

		$post_id = $this->factory()->post->create( array(
			'post_type'   => 'event',
			'post_status' => 'publish',
			'post_title'  => 'Событие на проверку',
			'post_author' => $org->ID,
		) );

		$this->assertSame( 'pending', get_post_status( $post_id ) );
	}

	/**
	 * Администратор публикует событие — статус остаётся publish.
	 */
	public function test_admin_can_publish_event() {
		IDPG_Roles::instance()->create_roles();
		$admin = $this->make_admin( 'mod_admin_a' );

		wp_set_current_user( $admin->ID );

		$post_id = $this->factory()->post->create( array(
			'post_type'   => 'event',
			'post_status' => 'publish',
			'post_title'  => 'Одобренное событие',
			'post_author' => $admin->ID,
		) );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * Правка организатором уже опубликованного события не сбрасывает статус.
	 */
	public function test_organizer_edit_published_event_keeps_publish() {
		IDPG_Roles::instance()->create_roles();
		$admin = $this->make_admin( 'mod_admin_b' );
		$org   = $this->make_organizer( 'mod_org_b' );

		// Админ публикует событие организатора.
		wp_set_current_user( $admin->ID );
		$post_id = $this->factory()->post->create( array(
			'post_type'   => 'event',
			'post_status' => 'publish',
			'post_title'  => 'Уже опубликовано',
			'post_author' => $org->ID,
		) );

		$this->assertSame( 'publish', get_post_status( $post_id ) );

		// Организатор правит своё опубликованное событие — статус сохраняется.
		wp_set_current_user( $org->ID );

		wp_update_post( array(
			'ID'          => $post_id,
			'post_title'  => 'Уже опубликовано (правка)',
			'post_status' => 'publish',
		) );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * При отправке на модерацию (draft → pending) админ получает письмо.
	 */
	public function test_pending_notifies_moderator() {
		IDPG_Roles::instance()->create_roles();
		$this->make_admin( 'mod_admin_mail' );

		$org = $this->make_organizer( 'mod_org_mail' );

		$post_id = $this->factory()->post->create( array(
			'post_type'   => 'event',
			'post_status' => 'draft',
			'post_title'  => 'Событие с письмом',
			'post_author' => $org->ID,
		) );

		wp_set_current_user( $org->ID );
		wp_update_post( array(
			'ID'          => $post_id,
			'post_status' => 'pending',
		) );

		$titles = array();
		foreach ( $this->mails as $mail ) {
			$titles[] = $mail['subject'];
		}

		$this->assertNotEmpty( $titles );
		$this->assertStringContainsString( 'на модерации', implode( ' | ', $titles ) );
	}

	/**
	 * При публикации из pending автор получает письмо об одобрении.
	 */
	public function test_publish_notifies_author() {
		IDPG_Roles::instance()->create_roles();
		$admin = $this->make_admin( 'mod_admin_pub' );
		$org   = $this->make_organizer( 'mod_org_pub' );

		$post_id = $this->factory()->post->create( array(
			'post_type'   => 'event',
			'post_status' => 'pending',
			'post_title'  => 'Событие на одобрение',
			'post_author' => $org->ID,
		) );

		$this->mails = array();

		wp_set_current_user( $admin->ID );
		wp_update_post( array(
			'ID'          => $post_id,
			'post_status' => 'publish',
		) );

		$subjects = array();
		foreach ( $this->mails as $mail ) {
			$subjects[] = $mail['subject'];
		}

		$this->assertNotEmpty( $subjects );
		$this->assertStringContainsString( 'опубликовано', implode( ' | ', $subjects ) );
	}
}