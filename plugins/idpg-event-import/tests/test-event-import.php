<?php
/**
 * Юнит-тесты ядра импорта (IDPG_Event_Import): upsert, дедуп, термы.
 *
 * HTTP-транспорт подменяется фильтром idpgei_http_transport.
 *
 * @package Idpg_Event_Import
 */

/**
 * Класс Test_Event_Import
 */
class Test_Event_Import extends WP_UnitTestCase {

	/**
	 * Импортёр.
	 *
	 * @var IDPG_Event_Import
	 */
	private $importer;

	/**
	 * ID источника в тестах.
	 *
	 * @var string
	 */
	private $source_id = 'test-src';

	/**
	 * Подготовка.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->importer = IDPG_Event_Import::instance();

		// Чистим источники между тестами.
		delete_option( IDPG_Event_Import::SOURCES_OPTION );
	}

	/**
	 * Конфиг тестового источника.
	 *
	 * @return array
	 */
	private function source_config() {
		return array(
			'source_id'   => $this->source_id,
			'name'        => 'Тестовый API',
			'provider'    => 'rest',
			'endpoint'    => 'https://api.partner.test/events',
			'list_path'   => 'data',
			'page_param'  => 'page',
			'post_status' => 'draft',
			'organizer_id' => 0,
			'city_term'   => 0,
			'overwrite'   => false,
			'schedule'    => 3600,
		);
	}

	/**
	 * Подменить HTTP-транспорт: первая страница — $body, дальше — пусто
	 * (как реальный API: следующая страница за пределами данных пуста).
	 *
	 * @param array $body Тело ответа страницы 1 (уже JSON-массив).
	 * @return void
	 */
	private function mock_transport( $body ) {
		// Снимаем предыдущие моки (тесты могут вызывать повторно).
		remove_all_filters( 'idpgei_http_transport' );

		// Счётчик запросов: живёт между вызовами транспорта.
		$requests = 0;

		add_filter( 'idpgei_http_transport', function () use ( $body, &$requests ) {
			return function () use ( $body, &$requests ) {
				$requests++;

				if ( $requests > 1 ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'data' => array() ) ),
					);
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( $body ),
				);
			};
		} );
	}

	/**
	 * Сохранить тестовый источник.
	 *
	 * @param array $overrides Переопределения конфига.
	 * @return array Конфиг.
	 */
	private function seed_source( $overrides = array() ) {
		$source = array_merge( $this->source_config(), $overrides );
		$this->importer->save_source( $source );

		return $source;
	}

	/**
	 * process_source создаёт события из валидного ответа.
	 */
	public function test_process_source_creates_events() {
		$this->seed_source();

		$this->mock_transport( array(
			'data' => array(
				array( 'id' => 'e-1', 'title' => 'Событие 1', 'starts_at' => '2026-09-10T18:00' ),
				array( 'id' => 'e-2', 'title' => 'Событие 2', 'starts_at' => '2026-09-11T18:00', 'venue' => 'Неизвестная площадка' ),
				array( 'id' => 'e-3', 'title' => '', 'starts_at' => '2026-09-12' ), // Невалидное — пропуск.
			),
		) );

		$stats = $this->importer->process_source( $this->source_id );

		$this->assertIsArray( $stats );
		$this->assertSame( 2, $stats['created'] );
		$this->assertSame( 1, $stats['skipped'] );

		// Мета-поля проставлены.
		$found = $this->importer->find_existing( $this->source_id, 'e-1' );
		$this->assertGreaterThan( 0, $found );
		$this->assertSame( '2026-09-10T18:00', get_post_meta( $found, '_idpg_start_date', true ) );
		$this->assertSame( $this->source_id, get_post_meta( $found, '_idpg_source', true ) );
	}

	/**
	 * Повторный прогон обновляет, а не дублирует (дедупликация).
	 */
	public function test_upsert_no_duplicates() {
		$this->seed_source();

		$payload = array( 'data' => array(
			array( 'id' => 'e-1', 'title' => 'Событие 1', 'starts_at' => '2026-09-10T18:00' ),
		) );

		$this->mock_transport( $payload );
		$this->importer->process_source( $this->source_id );

		$this->mock_transport( $payload );
		$stats = $this->importer->process_source( $this->source_id );

		$this->assertSame( 0, $stats['created'] );
		$this->assertSame( 1, $stats['updated'] );

		// В БД ровно одно событие этого источника (в любом статусе).
		$all = get_posts( array(
			'post_type'      => 'event',
			'post_status'    => get_post_stati(),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'   => '_idpg_source',
					'value' => $this->source_id,
				),
			),
		) );

		$this->assertCount( 1, $all );
	}

	/**
	 * Политика overwrite: заголовок не перезаписывается без флага.
	 */
	public function test_overwrite_policy() {
		$this->seed_source();

		$this->mock_transport( array( 'data' => array(
			array( 'id' => 'e-1', 'title' => 'Старое название', 'starts_at' => '2026-09-10T18:00' ),
		) ) );
		$this->importer->process_source( $this->source_id );

		$post_id = $this->importer->find_existing( $this->source_id, 'e-1' );

		// Редактор поменял заголовок руками.
		wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Редакторское название' ) );

		// Прогон без overwrite: заголовок сохранён.
		$this->mock_transport( array( 'data' => array(
			array( 'id' => 'e-1', 'title' => 'Новое из API', 'starts_at' => '2026-09-10T19:00' ),
		) ) );
		$this->importer->process_source( $this->source_id );

		$this->assertSame( 'Редакторское название', get_the_title( $post_id ) );
		// Но дата обновилась.
		$this->assertSame( '2026-09-10T19:00', get_post_meta( $post_id, '_idpg_start_date', true ) );
	}

	/**
	 * Опубликованные события не переводятся обратно в черновик.
	 */
	public function test_published_stays_published() {
		$this->seed_source( array( 'post_status' => 'draft' ) );

		$this->mock_transport( array( 'data' => array(
			array( 'id' => 'e-1', 'title' => 'Событие', 'starts_at' => '2026-09-10T18:00' ),
		) ) );
		$this->importer->process_source( $this->source_id );

		$post_id = $this->importer->find_existing( $this->source_id, 'e-1' );
		wp_publish_post( $post_id );

		// Повторный прогон (источник всё ещё draft).
		$this->importer->process_source( $this->source_id );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * Город источника присваивается событию.
	 */
	public function test_city_term_assigned() {
		$city = $this->factory()->term->create( array( 'taxonomy' => 'city', 'name' => 'Тестгород' ) );

		$this->seed_source( array( 'city_term' => $city ) );

		$this->mock_transport( array( 'data' => array(
			array( 'id' => 'e-1', 'title' => 'Событие', 'starts_at' => '2026-09-10T18:00' ),
		) ) );
		$this->importer->process_source( $this->source_id );

		$post_id = $this->importer->find_existing( $this->source_id, 'e-1' );
		$terms  = wp_get_post_terms( $post_id, 'city', array( 'fields' => 'ids' ) );

		$this->assertContains( $city, $terms );
	}

	/**
	 * Площадка создаётся при включённой политике create_missing_venues.
	 */
	public function test_venue_created_when_missing() {
		$this->seed_source( array( 'create_missing_venues' => true ) );

		$this->mock_transport( array( 'data' => array(
			array(
				'id'      => 'e-1',
				'title'   => 'Событие',
				'starts_at' => '2026-09-10T18:00',
				'venue'   => 'Новая площадка XYZ',
				'address' => 'ул. Новая, 5',
			),
		) ) );
		$this->importer->process_source( $this->source_id );

		$post_id  = $this->importer->find_existing( $this->source_id, 'e-1' );
		$venue_id = (int) get_post_meta( $post_id, '_idpg_venue', true );

		$this->assertGreaterThan( 0, $venue_id );
		$this->assertSame( 'Новая площадка XYZ', get_the_title( $venue_id ) );
		$this->assertSame( 'ул. Новая, 5', get_post_meta( $venue_id, '_idpg_venue_address', true ) );

		// Повторный импорт не плодит площадки.
		$this->importer->process_source( $this->source_id );
		$this->assertSame( $venue_id, (int) get_post_meta( $post_id, '_idpg_venue', true ) );
	}

	/**
	 * Существующая площадка подхватывается по названию.
	 */
	public function test_existing_venue_found_by_name() {
		$venue_id = $this->factory()->post->create( array(
			'post_type'   => 'venue',
			'post_status' => 'publish',
			'post_title'  => 'Известная площадка',
		) );

		$this->seed_source();

		$this->mock_transport( array( 'data' => array(
			array(
				'id'      => 'e-1',
				'title'   => 'Событие',
				'starts_at' => '2026-09-10T18:00',
				'venue'   => 'Известная площадка',
			),
		) ) );
		$this->importer->process_source( $this->source_id );

		$post_id = $this->importer->find_existing( $this->source_id, 'e-1' );

		$this->assertSame( $venue_id, (int) get_post_meta( $post_id, '_idpg_venue', true ) );
	}

	/**
	 * Ошибка HTTP фиксируется в last_status источника.
	 */
	public function test_http_error_recorded() {
		$this->seed_source();

		add_filter( 'idpgei_http_transport', function () {
			return function () {
				return array( 'response' => array( 'code' => 500 ), 'body' => '' );
			};
		} );

		$result = $this->importer->process_source( $this->source_id );

		$this->assertWPError( $result );

		$source = $this->importer->get_source( $this->source_id );
		$this->assertSame( 'error', $source['last_status'] );
		$this->assertNotEmpty( $source['last_error'] );
	}

	/**
	 * iCal-источник проходит полный цикл через process_source.
	 */
	public function test_ical_source_end_to_end() {
		$this->seed_source( array(
			'provider' => 'ical',
			'endpoint' => 'https://partner.test/feed.ics',
			'list_path' => '',
		) );

		$ical = "BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:cal-1@p\nSUMMARY:Из календаря\nDTSTART:20261001T120000Z\nEND:VEVENT\nEND:VCALENDAR\n";

		add_filter( 'idpgei_http_transport', function () use ( $ical ) {
			return function () use ( $ical ) {
				return array( 'response' => array( 'code' => 200 ), 'body' => $ical );
			};
		} );

		$stats = $this->importer->process_source( $this->source_id );

		$this->assertSame( 1, $stats['created'] );

		$post_id = $this->importer->find_existing( $this->source_id, 'cal-1@p' );
		$this->assertGreaterThan( 0, $post_id );
		$this->assertSame( 'Из календаря', get_the_title( $post_id ) );
	}

	/**
	 * Санитизация источника: schedule ограничен, endpoint только http(s).
	 */
	public function test_sanitize_source() {
		$source = $this->importer->sanitize_source( array(
			'name'     => 'Тест',
			'endpoint' => 'javascript:alert(1)',
			'schedule' => 5, // Слишком часто.
			'post_status' => 'publish999',
		) );

		$this->assertSame( '', $source['endpoint'] );
		$this->assertSame( 900, $source['schedule'] ); // Минимум 15 минут.
		$this->assertSame( 'draft', $source['post_status'] );
	}

	/**
	 * find_existing не находит чужие события.
	 */
	public function test_find_existing_isolation() {
		$this->factory()->post->create( array(
			'post_type'  => 'event',
			'meta_input' => array( '_idpg_source' => 'other-src', '_idpg_external_id' => 'e-1' ),
		) );

		$this->assertSame( 0, $this->importer->find_existing( $this->source_id, 'e-1' ) );
	}
}
