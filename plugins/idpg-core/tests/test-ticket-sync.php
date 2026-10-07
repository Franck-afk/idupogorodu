<?php
/**
 * Юнит-тесты DEV-18: ручной запуск синхронизации билетов и WP-CLI.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Ticket_Sync
 */
class Test_Ticket_Sync extends WP_UnitTestCase {

	/**
	 * Подготовка окружения.
	 */
	public function set_up() {
		parent::set_up();

		// Конструктор вешает cron-хуки.
		IDPG_Ticket_Sync::instance();

		// Чистим расписание, чтобы тесты не зависели от прошлых прогонов.
		wp_clear_scheduled_hook( IDPG_Ticket_Sync::CRON_HOOK );
	}

	/**
	 * Создаёт опубликованное событие для синхронизации.
	 *
	 * @return int
	 */
	private function make_event( $status = 'in_sale' ) {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $event_id, '_idpg_ticket_status', $status );

		return $event_id;
	}

	/**
	 * Интервал синхронизации зарегистрирован в расписаниях cron.
	 */
	public function test_cron_interval_registered() {
		$schedules = apply_filters( 'cron_schedules', array() );

		$this->assertArrayHasKey( 'idpg_every_3_hours', $schedules );
		$this->assertSame( IDPG_Ticket_Sync::INTERVAL, $schedules['idpg_every_3_hours']['interval'] );
	}

	/**
	 * Исполнитель cron-задачи подвешен на хук.
	 */
	public function test_cron_hook_bound() {
		$this->assertNotFalse( has_action( IDPG_Ticket_Sync::CRON_HOOK ) );
	}

	/**
	 * schedule_sync() ставит задачу и не дублирует её.
	 */
	public function test_schedule_is_idempotent() {
		$sync = IDPG_Ticket_Sync::instance();

		$sync->schedule_sync();
		$first = wp_next_scheduled( IDPG_Ticket_Sync::CRON_HOOK );

		$this->assertNotFalse( $first );

		$sync->schedule_sync();

		$this->assertSame( $first, wp_next_scheduled( IDPG_Ticket_Sync::CRON_HOOK ) );
	}

	/**
	 * run_sync_counting() возвращает число событий к синхронизации.
	 */
	public function test_counting_returns_number_of_events() {
		$this->make_event();
		$this->make_event();
		$this->make_event();

		$this->assertSame( 3, IDPG_Ticket_Sync::instance()->run_sync_counting() );
	}

	/**
	 * Отменённые события в синхронизацию не попадают.
	 */
	public function test_cancelled_events_are_skipped() {
		$this->make_event();
		$this->make_event( 'cancelled' );

		$this->assertSame( 1, IDPG_Ticket_Sync::instance()->run_sync_counting() );
	}

	/**
	 * Черновики в синхронизацию не попадают.
	 */
	public function test_draft_events_are_skipped() {
		self::factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'draft',
			)
		);

		$this->assertSame( 0, IDPG_Ticket_Sync::instance()->run_sync_counting() );
	}

	/**
	 * Без событий возвращается 0, а не ошибка.
	 */
	public function test_no_events_returns_zero() {
		$this->assertSame( 0, IDPG_Ticket_Sync::instance()->run_sync_counting() );
	}

	/**
	 * run_sync_now() ставит расписание и возвращает число событий (DEV-18).
	 */
	public function test_run_sync_now_returns_count_and_schedules() {
		$this->make_event();
		$this->make_event();

		$processed = IDPG_Ticket_Sync::instance()->run_sync_now();

		$this->assertSame( 2, $processed );
		$this->assertNotFalse( wp_next_scheduled( IDPG_Ticket_Sync::CRON_HOOK ) );
	}

	/**
	 * Ключевая регрессия DEV-18: метод объявлен как @return int, значит
	 * обязан отдавать число, а не true.
	 */
	public function test_run_sync_now_returns_integer_not_bool() {
		$this->make_event();

		$processed = IDPG_Ticket_Sync::instance()->run_sync_now();

		$this->assertIsInt( $processed );
	}

	/**
	 * Без событий run_sync_now() честно отдаёт 0, а не true.
	 */
	public function test_run_sync_now_without_events_is_zero() {
		$processed = IDPG_Ticket_Sync::instance()->run_sync_now();

		$this->assertSame( 0, $processed );
	}

	/**
	 * Контракт cron-хука не сломан: run_sync() по-прежнему ничего не возвращает.
	 */
	public function test_run_sync_still_returns_void() {
		$this->make_event();

		$this->assertNull( IDPG_Ticket_Sync::instance()->run_sync() );
	}

	/**
	 * WP-CLI-команда синхронизации существует и читаема.
	 */
	public function test_cli_command_exists() {
		$this->assertTrue( method_exists( 'IDPG_CLI', 'cli_sync_tickets' ) );
	}

	/**
	 * CLI-регистрация обёрнута в if ( defined( 'WP_CLI' ) ) — файл можно
	 * подключать вне WP-CLI (тесты, другие точки входа).
	 */
	public function test_cli_registration_is_guarded() {
		$source = file_get_contents( IDPG_CORE_DIR . 'includes/class-cli.php' );

		$this->assertStringContainsString( "defined( 'WP_CLI' ) && WP_CLI", $source );
		$this->assertStringContainsString( "'idpg sync-tickets'", $source );
	}

	/**
	 * Регистрация команд: два add_command внутри одного
	 * if ( defined( 'WP_CLI' ) ) блока, без дублей.
	 */
	public function test_cli_registration_block_is_single() {
		$source = file_get_contents( IDPG_CORE_DIR . 'includes/class-cli.php' );

		$this->assertSame(
			2,
			substr_count( $source, 'WP_CLI::add_command' ),
			'Ожидались ровно две регистрации команд'
		);

		$this->assertSame(
			1,
			substr_count( $source, "if ( defined( 'WP_CLI' ) && WP_CLI ) {" ),
			'Ожидался один блок if ( defined( WP_CLI ) )'
		);
	}

	/**
	 * Ранее существовавшая команда cleanup-subscribers не потеряна (AGENTS.md, п.1).
	 */
	public function test_existing_cli_command_preserved() {
		$source = file_get_contents( IDPG_CORE_DIR . 'includes/class-cli.php' );

		$this->assertTrue( method_exists( 'IDPG_CLI', 'cli_cleanup_subscribers' ) );
		$this->assertStringContainsString( "'idpg cleanup-subscribers'", $source );
		$this->assertTrue( method_exists( 'IDPG_CLI', 'cleanup' ) );
	}
}
