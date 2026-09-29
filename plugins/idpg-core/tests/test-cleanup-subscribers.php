<?php
/**
 * Юнит-тесты DEV-71: команда `wp idpg cleanup-subscribers`.
 *
 * Проверяется главное требование приёмки: dry-run ничего не удаляет,
 * а реальный запуск удаляет ТОЛЬКО аккаунты с ролью subscriber и не
 * трогает администраторов, редакторов и организаторов.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Cleanup_Subscribers
 */
class Test_Cleanup_Subscribers extends WP_UnitTestCase {

	/**
	 * Подготовка окружения.
	 *
	 * Роли IDPG обязаны быть зарегистрированы: WP_User::$roles формируется
	 * только из зарегистрированных в wp_roles() ролей, иначе
	 * `event_organizer` не попадёт в список ролей пользователя.
	 */
	public function set_up() {
		parent::set_up();

		IDPG_Roles::instance()->create_roles();
	}

	/**
	 * Создаёт «мусорный» аккаунт: роль subscriber, pending-флага нет.
	 *
	 * @return int ID пользователя.
	 */
	private function make_stale_subscriber() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		delete_user_meta( $user_id, IDPG_Organizer_Verify::META_PENDING );

		return $user_id;
	}

	/**
	 * Создаёт pending-организатора: роль subscriber, но ждёт подтверждения.
	 *
	 * Именно так выглядит реальный организатор сразу после регистрации —
	 * class-rest-auth.php:531 выдаёт subscriber, роль event_organizer
	 * приходит только после confirm().
	 *
	 * @return int ID пользователя.
	 */
	private function make_pending_organizer() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		IDPG_Organizer_Verify::instance()->prepare( $user_id );

		return $user_id;
	}

	/* ---------------------------------------------------------------------
	 * Отбор кандидатов.
	 * ------------------------------------------------------------------ */

	/**
	 * Обычный subscriber попадает в выборку.
	 */
	public function test_stale_subscriber_is_candidate() {
		$user_id = $this->make_stale_subscriber();

		$ids = wp_list_pluck( IDPG_CLI::get_purge_candidates(), 'id' );

		$this->assertContains( $user_id, $ids );
	}

	/**
	 * Роль subscriber должна быть единственной — мультиролевые не трогаем.
	 */
	public function test_multirole_user_is_skipped() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_role( 'event_organizer' );

		$ids = wp_list_pluck( IDPG_CLI::get_purge_candidates(), 'id' );

		$this->assertNotContains( $user_id, $ids );
	}

	/**
	 * Администратор не попадает в выборку даже при наличии subscriber.
	 */
	public function test_administrator_is_skipped() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$ids = wp_list_pluck( IDPG_CLI::get_purge_candidates(), 'id' );

		$this->assertNotContains( $user_id, $ids );
	}

	/**
	 * Подтверждённый организатор не попадает в выборку.
	 */
	public function test_confirmed_organizer_is_skipped() {
		$user_id = self::factory()->user->create( array( 'role' => 'event_organizer' ) );

		$ids = wp_list_pluck( IDPG_CLI::get_purge_candidates(), 'id' );

		$this->assertNotContains( $user_id, $ids );
	}

	/**
	 * Pending-организатор — живая заявка, а не мусор: сохраняется.
	 */
	public function test_pending_organizer_is_not_purged() {
		$user_id = $this->make_pending_organizer();

		$this->assertTrue( IDPG_Organizer_Verify::instance()->is_pending( $user_id ) );
		$this->assertNotContains(
			$user_id,
			wp_list_pluck( IDPG_CLI::get_purge_candidates(), 'id' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Dry-run ничего не удаляет.
	 * ------------------------------------------------------------------ */

	/**
	 * Dry-run находит кандидата, но не удаляет его.
	 */
	public function test_dry_run_does_not_delete() {
		$user_id = $this->make_stale_subscriber();

		$report = IDPG_CLI::cleanup( true );

		$this->assertTrue( $report['dry_run'] );
		$this->assertSame( 1, $report['found'] );
		$this->assertContains( $user_id, $report['found_ids'] );
		$this->assertSame( 0, $report['deleted'] );
		$this->assertNotNull( get_userdata( $user_id ), 'dry-run не должен удалять аккаунт' );
	}

	/**
	 * Dry-run по умолчанию не удаляет ничего.
	 */
	public function test_cleanup_defaults_to_dry_run() {
		$user_id = $this->make_stale_subscriber();

		$report = IDPG_CLI::cleanup();

		$this->assertTrue( $report['dry_run'] );
		$this->assertNotNull( get_userdata( $user_id ) );
	}

	/**
	 * Dry-run отчитывается о сохранённых pending-организаторах.
	 */
	public function test_dry_run_reports_pending_skipped() {
		$this->make_pending_organizer();

		$report = IDPG_CLI::cleanup( true );

		$this->assertSame( 1, $report['pending_keep'] );
	}

	/* ---------------------------------------------------------------------
	 * Реальный запуск удаляет только subscriber.
	 * ------------------------------------------------------------------ */

	/**
	 * Реальный запуск удаляет мусорный аккаунт.
	 */
	public function test_real_run_deletes_subscriber() {
		$user_id = $this->make_stale_subscriber();

		$report = IDPG_CLI::cleanup( false );

		$this->assertFalse( $report['dry_run'] );
		$this->assertSame( 1, $report['deleted'] );
		$this->assertContains( $user_id, $report['deleted_ids'] );
		$this->assertFalse( get_userdata( $user_id ), 'реальный запуск должен удалить аккаунт' );
	}

	/**
	 * Администратор, редактор и организатор выживают при реальном запуске.
	 */
	public function test_real_run_keeps_privileged_users() {
		$admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$organizer = self::factory()->user->create( array( 'role' => 'event_organizer' ) );

		$pending = $this->make_pending_organizer();

		$this->make_stale_subscriber();

		IDPG_CLI::cleanup( false );

		$this->assertNotFalse( get_userdata( $admin ) );
		$this->assertNotFalse( get_userdata( $editor ) );
		$this->assertNotFalse( get_userdata( $organizer ) );
		$this->assertNotFalse( get_userdata( $pending ), 'pending-организатор должен выжить' );
	}

	/**
	 * Контент удаляемого аккаунта переназначается, если указан ID.
	 */
	public function test_real_run_can_reassign_content() {
		$author_id = $this->make_stale_subscriber();
		$admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$post_id = self::factory()->post->create( array( 'post_author' => $author_id ) );

		$report = IDPG_CLI::cleanup( false, $admin_id );

		$this->assertSame( 1, $report['deleted'] );
		$this->assertSame( $admin_id, $report['reassign_to'] );
		$this->assertSame( $admin_id, (int) get_post_field( 'post_author', $post_id ) );
	}

	/**
	 * Пустая база даёт нулевой отчёт и не падает.
	 */
	public function test_empty_result_produces_clean_report() {
		$report = IDPG_CLI::cleanup( true );

		$this->assertSame( 0, $report['found'] );
		$this->assertSame( 0, $report['deleted'] );
		$this->assertSame( array(), $report['found_ids'] );
	}
}
