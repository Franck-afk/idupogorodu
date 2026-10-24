<?php
/**
 * Юнит-тесты DEV-02 (часть 1): устойчивость синхронизации билетов и журнал.
 *
 * Сценарии: повторные попытки, обработка HTTP-ошибок, кольцевой буфер
 * IDPG_Logger и точки расширения под реальный API агрегатора.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Ticket_Sync_Resilience
 */
class Test_Ticket_Sync_Resilience extends WP_UnitTestCase {

	/**
	 * Захваченные HTTP-запросы (url + аргументы).
	 *
	 * @var array[]
	 */
	private $requests = array();

	/**
	 * Подготовка окружения.
	 */
	public function set_up() {
		parent::set_up();

		// Конструктор вешает cron-хуки.
		IDPG_Ticket_Sync::instance();

		// Чистим расписание и журнал, чтобы тесты не зависели от прошлых прогонов.
		wp_clear_scheduled_hook( IDPG_Ticket_Sync::CRON_HOOK );
		IDPG_Logger::clear();

		// Без реальных пауз между попытками.
		add_filter( 'idpg_ticket_sync_retry_delay', '__return_zero' );

		// В тестовой среде базовый URL — заглушка; подменяем домен, чтобы
		// сценарии запросов вообще доходили до мока HTTP. Тест самой заглушки
		// (test_stub_url_no_request) снимает этот фильтр.
		add_filter(
			'idpg_ticket_sync_api_url',
			function ( $url ) {
				return str_replace( 'api.example.com', 'api.tickets.test', $url );
			}
		);
	}

	/**
	 * Создаёт событие с внешними билетами для синхронизации.
	 *
	 * @param string $status Стартовый статус билетов.
	 * @return int ID события.
	 */
	private function make_external_event( $status = 'in_sale' ) {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $event_id, IDPG_Helpers::META_TICKET_STATUS, $status );
		update_post_meta( $event_id, IDPG_Helpers::META_TICKET_TYPE, 'external_link' );
		update_post_meta( $event_id, '_idpg_external_id', 'EXT-42' );

		return $event_id;
	}

	/**
	 * Мок HTTP: очередь ответов на pre_http_request, запросы считаются.
	 *
	 * @param array $responses Очередь ответов (массив или WP_Error).
	 */
	private function mock_http( array $responses ) {
		$this->requests = array();

		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( &$responses ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $parsed_args,
				);

				if ( empty( $responses ) ) {
					return new WP_Error( 'idpg_test_no_more_mocks', 'Лишний HTTP-запрос в тесте.' );
				}

				return array_shift( $responses );
			},
			10,
			3
		);
	}

	/**
	 * Ответ HTTP API в формате WP (как из wp_remote_get).
	 *
	 * @param int    $code Код ответа.
	 * @param string $body Тело ответа.
	 * @return array
	 */
	private function http_response( $code, $body ) {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => 'Test',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Записи журнала указанного уровня.
	 *
	 * @param string $level Уровень: info|warning|error.
	 * @return array[]
	 */
	private function entries_by_level( $level ) {
		return array_values(
			wp_list_filter( IDPG_Logger::get_recent( IDPG_Logger::LOG_LIMIT ), array( 'level' => $level ) )
		);
	}

	/**
	 * Журнал пишет запись, хранит источник/уровень и ограничен лимитом.
	 */
	public function test_logger_writes_and_caps() {
		IDPG_Logger::log( 'unit-test', 'первая запись' );

		$recent = IDPG_Logger::get_recent();
		$this->assertCount( 1, $recent );
		$this->assertSame( 'unit-test', $recent[0]['source'] );
		$this->assertSame( 'info', $recent[0]['level'] );
		$this->assertSame( 'первая запись', $recent[0]['message'] );

		for ( $i = 0; $i < 104; $i++ ) {
			IDPG_Logger::log( 'unit-test', 'запись ' . $i );
		}

		$entries = get_option( IDPG_Logger::OPTION_NAME );

		$this->assertCount( IDPG_Logger::LOG_LIMIT, $entries );
		$this->assertSame( 'запись 103', $entries[ count( $entries ) - 1 ]['message'] );

		// Опция журнала не должна грузиться автозагрузкой.
		wp_cache_delete( 'alloptions', 'options' );
		$alloptions = wp_load_alloptions();

		$this->assertArrayNotHasKey( IDPG_Logger::OPTION_NAME, $alloptions );
	}

	/**
	 * Транзиентные сбои (таймаут, HTTP 500) лечатся повторами: третий
	 * ответ 200 применяет статус.
	 */
	public function test_retry_on_transient_errors() {
		$event_id = $this->make_external_event( 'in_sale' );

		$this->mock_http(
			array(
				new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ),
				$this->http_response( 500, 'server error' ),
				$this->http_response( 200, '{"status":"sold_out"}' ),
			)
		);

		$processed = IDPG_Ticket_Sync::instance()->run_sync_counting();

		$this->assertSame( 1, $processed );
		$this->assertCount( 3, $this->requests );
		$this->assertSame( 'sold_out', get_post_meta( $event_id, IDPG_Helpers::META_TICKET_STATUS, true ) );

		$this->assertCount( 2, $this->entries_by_level( 'warning' ) );
		$this->assertNotEmpty( $this->entries_by_level( 'info' ) );
	}

	/**
	 * Постоянный сбой не портит событие: статус прежний, в журнале error.
	 */
	public function test_all_attempts_fail_keeps_status() {
		$event_id = $this->make_external_event( 'in_sale' );

		$this->mock_http(
			array(
				$this->http_response( 500, 'boom' ),
				$this->http_response( 500, 'boom' ),
				$this->http_response( 500, 'boom' ),
			)
		);

		$processed = IDPG_Ticket_Sync::instance()->run_sync_counting();

		$this->assertSame( 1, $processed );
		$this->assertCount( 3, $this->requests );
		$this->assertSame( 'in_sale', get_post_meta( $event_id, IDPG_Helpers::META_TICKET_STATUS, true ) );

		$errors = $this->entries_by_level( 'error' );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'HTTP 500', $errors[0]['message'] );
	}

	/**
	 * Постоянная ошибка клиента (4xx) не повторяется.
	 */
	public function test_client_error_no_retry() {
		$event_id = $this->make_external_event( 'in_sale' );

		$this->mock_http( array( $this->http_response( 404, 'not found' ) ) );

		IDPG_Ticket_Sync::instance()->run_sync_counting();

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'in_sale', get_post_meta( $event_id, IDPG_Helpers::META_TICKET_STATUS, true ) );

		$errors = $this->entries_by_level( 'error' );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( 'HTTP 404', $errors[0]['message'] );
	}

	/**
	 * На заглушечный api.example.com запросы не уходят вовсе.
	 */
	public function test_stub_url_no_request() {
		// Тест осмыслен только на дефолтной заглушке.
		$this->assertStringContainsString( 'example.com', IDPG_TICKET_API_URL );

		// Снимаем подмену домена из set_up(): проверяем именно защиту заглушки.
		remove_all_filters( 'idpg_ticket_sync_api_url' );

		$event_id = $this->make_external_event();

		// Любой запрос попадёт сюда и будет посчитан — ожидаем ноль.
		$this->mock_http( array() );

		IDPG_Ticket_Sync::instance()->run_sync_counting();

		$this->assertCount( 0, $this->requests );
		$this->assertSame( 'in_sale', get_post_meta( $event_id, IDPG_Helpers::META_TICKET_STATUS, true ) );

		$warnings = $this->entries_by_level( 'warning' );
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'заглушка', $warnings[0]['message'] );
	}

	/**
	 * Смена статуса обновляет мета и вызывает idpg_ticket_status_changed.
	 */
	public function test_status_change_updates_meta_and_fires_hook() {
		$event_id = $this->make_external_event( 'sold_out' );

		$captured = null;
		add_action(
			'idpg_ticket_status_changed',
			function ( $event, $old_status, $new_status, $data ) use ( &$captured ) {
				$captured = array(
					'event_id' => $event,
					'old'      => $old_status,
					'new'      => $new_status,
					'data'     => $data,
				);
			},
			10,
			4
		);

		$this->mock_http( array( $this->http_response( 200, '{"status":"active","tickets_available":42}' ) ) );

		IDPG_Ticket_Sync::instance()->run_sync_counting();

		$this->assertSame( 'in_sale', get_post_meta( $event_id, IDPG_Helpers::META_TICKET_STATUS, true ) );
		$this->assertIsArray( $captured );
		$this->assertSame( $event_id, $captured['event_id'] );
		$this->assertSame( 'sold_out', $captured['old'] );
		$this->assertSame( 'in_sale', $captured['new'] );
		$this->assertSame( 'active', $captured['data']['status'] );
	}

	/**
	 * Неизвестный статус не меняет мета, в журнале warning.
	 */
	public function test_unknown_status_keeps_meta() {
		$event_id = $this->make_external_event( 'in_sale' );

		$this->mock_http( array( $this->http_response( 200, '{"status":"weird"}' ) ) );

		IDPG_Ticket_Sync::instance()->run_sync_counting();

		$this->assertSame( 'in_sale', get_post_meta( $event_id, IDPG_Helpers::META_TICKET_STATUS, true ) );

		$warnings = $this->entries_by_level( 'warning' );
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'Неизвестный статус', $warnings[0]['message'] );
	}

	/**
	 * Фильтр idpg_ticket_sync_api_url подменяет URL запроса.
	 */
	public function test_api_url_filter() {
		$event_id = $this->make_external_event();

		add_filter(
			'idpg_ticket_sync_api_url',
			function () {
				return 'https://real.example.net/status/EXT-42';
			}
		);

		$this->mock_http( array( $this->http_response( 200, '{"status":"sold_out"}' ) ) );

		IDPG_Ticket_Sync::instance()->run_sync_counting();

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://real.example.net/status/EXT-42', $this->requests[0]['url'] );
		$this->assertSame( 'sold_out', get_post_meta( $event_id, IDPG_Helpers::META_TICKET_STATUS, true ) );
	}

	/**
	 * Таймаут запроса по умолчанию — 15 с (спека §2.4).
	 */
	public function test_timeout_default() {
		$this->make_external_event();

		$this->mock_http( array( $this->http_response( 200, '{"status":"sold_out"}' ) ) );

		IDPG_Ticket_Sync::instance()->run_sync_counting();

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 15, (int) $this->requests[0]['args']['timeout'] );
	}

	/**
	 * Таймаут запроса берётся из фильтра idpg_ticket_sync_timeout.
	 */
	public function test_timeout_filter() {
		$this->make_external_event();

		add_filter(
			'idpg_ticket_sync_timeout',
			function () {
				return 7;
			}
		);

		$this->mock_http( array( $this->http_response( 200, '{"status":"sold_out"}' ) ) );

		IDPG_Ticket_Sync::instance()->run_sync_counting();

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 7, (int) $this->requests[0]['args']['timeout'] );
	}

	/**
	 * Фильтр idpg_ticket_sync_response_data приводит нестандартный JSON.
	 */
	public function test_response_data_filter() {
		$event_id = $this->make_external_event();

		add_filter(
			'idpg_ticket_sync_response_data',
			function ( $data ) {
				return array(
					'status' => isset( $data['state'] ) ? $data['state'] : '',
				);
			}
		);

		$this->mock_http( array( $this->http_response( 200, '{"state":"sold_out"}' ) ) );

		IDPG_Ticket_Sync::instance()->run_sync_counting();

		$this->assertSame( 'sold_out', get_post_meta( $event_id, IDPG_Helpers::META_TICKET_STATUS, true ) );
	}

	/**
	 * Фильтр idpg_ticket_sync_status_map добавляет свой статус.
	 */
	public function test_status_map_filter() {
		$event_id = $this->make_external_event();

		add_filter(
			'idpg_ticket_sync_status_map',
			function ( $map ) {
				$map['pre_sale'] = 'postponed';

				return $map;
			}
		);

		$this->mock_http( array( $this->http_response( 200, '{"status":"pre_sale"}' ) ) );

		IDPG_Ticket_Sync::instance()->run_sync_counting();

		$this->assertSame( 'postponed', get_post_meta( $event_id, IDPG_Helpers::META_TICKET_STATUS, true ) );
	}

	/**
	 * Бэкофф: пауза между попытками растёт (база ×2) — спека §2.3.
	 *
	 * База снижена до 1 с, поэтому паузы 1 с и 2 с вместо 2 с и 4 с.
	 */
	public function test_exponential_backoff() {
		$this->make_external_event();

		add_filter(
			'idpg_ticket_sync_retry_delay',
			function () {
				return 1;
			}
		);

		$this->mock_http(
			array(
				new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
				$this->http_response( 500, 'server error' ),
				$this->http_response( 200, '{"status":"sold_out"}' ),
			)
		);

		IDPG_Ticket_Sync::instance()->run_sync_counting();

		$this->assertCount( 3, $this->requests );

		$warnings = $this->entries_by_level( 'warning' );
		$this->assertCount( 2, $warnings );
		$this->assertStringContainsString( 'повтор через 1 с.', $warnings[0]['message'] );
		$this->assertStringContainsString( 'повтор через 2 с.', $warnings[1]['message'] );
	}
}
