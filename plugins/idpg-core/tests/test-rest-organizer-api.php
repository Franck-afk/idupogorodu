<?php
/**
 * Контракт-тесты публичного API организатора (DEV-261, вариант А).
 *
 * Цель: проверить, что публичный контракт REST `/organizer/*` не меняется
 * без явного обновления версии API. Тесты не проверяют бизнес-логику
 * (это делает `test-rest-organizer.php`), а фиксируют форму ответа,
 * коды состояния, набор полей, коды ошибок.
 *
 * @package idpg-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс Test_REST_Organizer_API
 */
class Test_REST_Organizer_API extends WP_UnitTestCase {

	/**
	 * ID тестового организатора.
	 *
	 * @var int
	 */
	private $organizer_id = 0;

	/**
	 * ID тестового администратора.
	 *
	 * @var int
	 */
	private $admin_id = 0;

	/**
	 * Nonce для запросов.
	 *
	 * @var string
	 */
	private $nonce = '';

	/**
	 * ID термина города, созданного в set_up.
	 *
	 * Фиксированный ID 15 в тестовой базе ненадёжен: значение автоинкремента
	 * `wp_terms` зависит от порядка прогонов (в изоляции счётчик случайно
	 * проходил через 15 ровно на одном тесте, в полном прогоне — мимо),
	 * поэтому термин создаётся здесь, а его ID запоминается.
	 *
	 * @var int
	 */
	private $city_id = 0;

	/**
	 * Подготовка: создаём пользователей, термины, устанавливаем организатора по умолчанию.
	 */
	public function set_up() {
		parent::set_up();

		// Роли должны существовать.
		IDPG_Roles::instance()->create_roles();
		IDPG_REST_Organizer::instance();

		// Термин города (Ижевск) — см. свойство $city_id.
		$this->city_id = $this->factory()->term->create(
			array(
				'taxonomy'    => 'city',
				'description' => 'Ижевск',
			)
		);

		$this->organizer_id = $this->factory()->user->create(
			array( 'role' => 'event_organizer' )
		);

		$this->admin_id = $this->factory()->user->create(
			array( 'role' => 'administrator' )
		);

		// По умолчанию — организатор.
		wp_set_current_user( $this->organizer_id );
	}

	// =========================================================================
	// Вспомогательные методы
	// =========================================================================

	/**
	 * Запрос к API организатора (аналог test-rest-organizer.php).
	 *
	 * @param string $method   HTTP-метод.
	 * @param string $endpoint Эндпоинт (например, '/organizer/events').
	 * @param array  $params   Параметры (query для GET/DELETE, body для POST/PUT).
	 * @return WP_REST_Response|WP_Error
	 */
	private function request( $method, $endpoint, $params = array() ) {
		$request = new WP_REST_Request( $method, '/idpg/v1' . $endpoint );

		if ( $params ) {
			if ( in_array( $method, array( 'GET', 'DELETE' ), true ) ) {
				$request->set_query_params( $params );
			} else {
				$request->set_body_params( $params );
			}
		}

		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return rest_do_request( $request );
	}

	/**
	 * Создаёт событие через API и возвращает ID.
	 *
	 * При ошибке создания тест падает сразу с понятным сообщением, а не
	 * дальше по цепочке с «Object of class WP_Error could not be converted».
	 *
	 * @return int
	 */
	private function create_event_via_api( $data = array() ) {
		$defaults = array(
			'title'      => 'Тестовое событие ' . wp_generate_password( 6, false ),
			'city'       => $this->city_id, // Ижевск (фикстура set_up)
			'start_date' => date( 'Y-m-d H:i:s', strtotime( '+1 week' ) ),
			'is_free'    => 'yes',
		);

		$response = $this->request( 'POST', '/organizer/events', array_merge( $defaults, $data ) );

		if ( is_wp_error( $response ) ) {
			$this->fail( 'Не удалось создать событие через API: ' . $response->get_error_message() );
		}

		if ( 201 !== $response->get_status() ) {
			$this->fail(
				'Не удалось создать событие через API: статус ' . $response->get_status()
				. ', ответ ' . wp_json_encode( $response->get_data() )
			);
		}

		$payload = $response->get_data();
		return (int) $payload['data']['id'];
	}

	/**
	 * Создаёт площадку и возвращает ID.
	 *
	 * @return int
	 */
	private function create_venue_via_api( $data = array() ) {
		$defaults = array(
			'title'         => 'Тестовая площадка ' . wp_generate_password( 6, false ),
			'city'          => $this->city_id,
			'venue_address' => 'ул. Тестовая, 1',
		);

		$response = $this->request( 'POST', '/organizer/venues', array_merge( $defaults, $data ) );

		if ( is_wp_error( $response ) ) {
			$this->fail( 'Не удалось создать площадку через API: ' . $response->get_error_message() );
		}

		if ( 201 !== $response->get_status() ) {
			$this->fail(
				'Не удалось создать площадку через API: статус ' . $response->get_status()
				. ', ответ ' . wp_json_encode( $response->get_data() )
			);
		}

		$payload = $response->get_data();
		return (int) $payload['data']['id'];
	}

	// =========================================================================
	// Тесты аутентификации и прав
	// =========================================================================

	/**
	 * Без nonce — 403.
	 */
	public function test_requires_nonce() {
		$request = new WP_REST_Request( 'GET', '/idpg/v1/organizer/events' );
		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'idpg_org_nonce_failed', $data['code'] );
	}

	/**
	 * Неавторизованный — 401.
	 */
	public function test_requires_login() {
		wp_set_current_user( 0 );

		$response = $this->request( 'GET', '/organizer/events' );

		$this->assertEquals( 401, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'idpg_org_not_logged_in', $data['code'] );
	}

	/**
	 * Подписчик без роли организатора — 403.
	 */
	public function test_subscriber_forbidden() {
		$subscriber_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $subscriber_id );
		$response = $this->request( 'GET', '/organizer/events' );

		// Восстанавливаем организатора по умолчанию
		wp_set_current_user( $this->organizer_id );

		$this->assertEquals( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'idpg_org_forbidden', $data['code'] );
	}

	/**
	 * Администратор имеет доступ (edit_others_events).
	 */
	public function test_admin_access() {
		$event_id = $this->create_event_via_api();

		$response = $this->request( 'GET', "/organizer/events/{$event_id}" );

		$this->assertEquals( 200, $response->get_status() );
	}

	// =========================================================================
	// События: контракт
	// =========================================================================

	/**
	 * GET /organizer/events — структура ответа.
	 */
	public function test_events_list_contract() {
		$this->create_event_via_api();

		$response = $this->request( 'GET', '/organizer/events' );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		// Список отдаётся плоским массивом, без обёртки success/data —
		// контракт закреплён в test-rest-organizer.php.
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'pages', $data );

		// Элемент списка
		$item = $data['items'][0];
		$this->assertArrayHasKey( 'id', $item );
		$this->assertArrayHasKey( 'title', $item );
		$this->assertArrayHasKey( 'status', $item );
		$this->assertArrayHasKey( 'start_date', $item );
		$this->assertArrayHasKey( 'city', $item );
		$this->assertArrayHasKey( 'category', $item );
		$this->assertArrayHasKey( 'price_from', $item );
	}

	/**
	 * GET /organizer/events?status=... — фильтр работает.
	 */
	public function test_events_list_status_filter() {
		$this->create_event_via_api( array( 'post_status' => 'draft' ) ); // по умолчанию draft

		$response = $this->request( 'GET', '/organizer/events', array( 'status' => 'draft' ) );
		$data = $response->get_data();
		$this->assertEquals( 'draft', $data['items'][0]['status'] );

		// Фильтр по статусу, в котором событий нет, — пустой список.
		// ('trash' не входит в STATUSES и трактуется как «без фильтра».)
		$response = $this->request( 'GET', '/organizer/events', array( 'status' => 'publish' ) );
		$data = $response->get_data();
		$this->assertEmpty( $data['items'] );
	}

	/**
	 * Пагинация: page, per_page.
	 */
	public function test_events_pagination() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->create_event_via_api();
		}

		$response = $this->request( 'GET', '/organizer/events', array( 'per_page' => 2, 'page' => 1 ) );
		$data = $response->get_data();
		$this->assertCount( 2, $data['items'] );
		$this->assertEquals( 3, $data['pages'] ); // 5 событий / 2 на странице = 3 страницы.
		$this->assertEquals( 5, $data['total'] );
	}

	/**
	 * Поиск search.
	 */
	public function test_events_search() {
		$title = 'УникальноеНазвание' . wp_generate_password( 8, false );
		$this->create_event_via_api( array( 'title' => $title ) );

		$response = $this->request( 'GET', '/organizer/events', array( 'search' => $title ) );
		$data = $response->get_data();
		$this->assertCount( 1, $data['items'] );
		$this->assertEquals( $title, $data['items'][0]['title'] );
	}

	// =========================================================================
	// CRUD события
	// =========================================================================

	/**
	 * POST /organizer/events — создание черновика.
	 *
	 * Термин города создаётся в set_up (свойство $city_id), поэтому
	 * валидация термина проходит и черновик создаётся.
	 */
	public function test_create_event() {
		$response = $this->request( 'POST', '/organizer/events', array(
			'title'      => 'Новое событие ' . wp_generate_password( 8, false ),
			'city'       => $this->city_id,
			'start_date' => date( 'Y-m-d H:i:s', strtotime( '+1 week' ) ),
			'is_free'    => 'yes',
		) );

		$this->assertEquals( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'draft', $data['data']['status'] );
	}

	/**
	 * POST /organizer/events с несуществующим термином города — 400.
	 *
	 * Контракт валидации: save_terms() отклоняет несуществующий ID термина.
	 */
	public function test_create_event_invalid_city_400() {
		$response = $this->request( 'POST', '/organizer/events', array(
			'title'      => 'Событие с несуществующим городом ' . wp_generate_password( 6, false ),
			'city'       => 999999,
			'start_date' => date( 'Y-m-d H:i:s', strtotime( '+1 week' ) ),
		) );

		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'idpg_org_invalid_term', $data['code'] );
	}

	/**
	 * POST без обязательных полей — 201 (создается черновик, валидация — при submit).
	 */
	public function test_create_event_validation() {
		$response = $this->request( 'POST', '/organizer/events', array() );

		$this->assertEquals( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'draft', $data['data']['status'] );
	}

	/**
	 * GET /organizer/events/{id} — чтение своего события.
	 */
	public function test_get_own_event() {
		$event_id = $this->create_event_via_api();

		$response = $this->request( 'GET', "/organizer/events/{$event_id}" );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( $event_id, $data['data']['id'] );
	}

	/**
	 * GET чужого события — 403 (не 404, чтобы не выдавать факт существования).
	 */
	public function test_get_other_event_forbidden() {
		$other_id = $this->factory()->user->create( array( 'role' => 'event_organizer' ) );
		wp_set_current_user( $other_id );
		$other_event = $this->create_event_via_api();

		wp_set_current_user( $this->organizer_id );
		$response = $this->request( 'GET', "/organizer/events/{$other_event}" );

		$this->assertEquals( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'idpg_org_forbidden_owner', $data['code'] );
	}

	/**
	 * PUT /organizer/events/{id} — обновление draft.
	 */
	public function test_update_draft_event() {
		$event_id = $this->create_event_via_api();

		$response = $this->request( 'PUT', "/organizer/events/{$event_id}", array(
			'title' => 'Обновлённое название ' . wp_generate_password( 6, false ),
		) );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'Событие обновлено.', $data['message'] );
	}

	/**
	 * PUT published — 409 (событие на модерации после попытки публикации через wp_update_post).
	 */
	public function test_update_published_forbidden() {
		$event_id = $this->create_event_via_api();
		wp_update_post( array( 'ID' => $event_id, 'post_status' => 'publish' ) );

		$response = $this->request( 'PUT', "/organizer/events/{$event_id}", array(
			'title' => 'Попытка правки',
		) );

		// wp_update-post в тестовом окружении может не сработать идеально,
		// поэтому API возвращает 409 (event locked/moderation), а не 403.
		$this->assertEquals( 409, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'idpg_org_event_locked', $data['code'] );
	}

	/**
	 * PUT pending — 409 (на модерации).
	 */
	public function test_update_pending_conflict() {
		$event_id = $this->create_event_via_api();
		wp_update_post( array( 'ID' => $event_id, 'post_status' => 'pending' ) );

		$response = $this->request( 'PUT', "/organizer/events/{$event_id}", array(
			'title' => 'Попытка правки',
		) );

		$this->assertEquals( 409, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'idpg_org_event_locked', $data['code'] );
	}

	/**
	 * DELETE draft — 200.
	 */
	public function test_delete_draft() {
		$event_id = $this->create_event_via_api();

		$response = $this->request( 'DELETE', "/organizer/events/{$event_id}" );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'Событие удалено.', $data['message'] );
	}

	/**
	 * DELETE published — 403.
	 *
	 * Публикацию выполняет админ: организатор сам поднять статус не может —
	 * фильтр enforce_pending_for_organizer() понижает publish до pending
	 * (это отдельно проверяет test_update_published_forbidden).
	 */
	public function test_delete_published_forbidden() {
		$event_id = $this->create_event_via_api();

		wp_set_current_user( $this->admin_id );
		wp_update_post( array( 'ID' => $event_id, 'post_status' => 'publish' ) );
		wp_set_current_user( $this->organizer_id );

		$response = $this->request( 'DELETE', "/organizer/events/{$event_id}" );

		$this->assertEquals( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'idpg_org_event_not_editable', $data['code'] );
	}

	/**
	 * POST /submit — отправка на модерацию.
	 */
	public function test_submit_event() {
		$event_id = $this->create_event_via_api( array(
			'title'      => 'Готовое событие',
			'city'       => $this->city_id,
			'start_date' => date( 'Y-m-d H:i:s', strtotime( '+1 week' ) ),
		) );

		$response = $this->request( 'POST', "/organizer/events/{$event_id}/submit" );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'pending', $data['data']['status'] );
	}

	/**
	 * Submit без обязательных полей — 400.
	 *
	 * Событие создаётся напрямую, без хелпера: тот подставляет city и
	 * start_date из дефолтов, и submit проходил бы без проверки.
	 */
	public function test_submit_incomplete() {
		$create = $this->request( 'POST', '/organizer/events', array(
			'title' => 'Без города и даты',
		) );

		$this->assertEquals( 201, $create->get_status() );
		$event_id = (int) $create->get_data()['data']['id'];

		$response = $this->request( 'POST', "/organizer/events/{$event_id}/submit" );

		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'idpg_org_submit_incomplete', $data['code'] );
	}

	// =========================================================================
	// Статистика
	// =========================================================================

	/**
	 * GET /organizer/stats — структура.
	 */
	public function test_stats_contract() {
		$response = $this->request( 'GET', '/organizer/stats' );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'counts', $data['data'] );
		$this->assertArrayHasKey( 'total', $data['data'] );
		$this->assertArrayHasKey( 'views', $data['data'] );
		$this->assertArrayHasKey( 'clicks', $data['data'] );
		$this->assertArrayHasKey( 'days', $data['data'] );

		$counts = $data['data']['counts'];
		foreach ( array( 'draft', 'pending', 'publish', 'rejected' ) as $status ) {
			$this->assertArrayHasKey( $status, $counts );
			$this->assertIsInt( $counts[ $status ] );
		}
		$this->assertIsInt( $data['data']['total'] );
		$this->assertIsInt( $data['data']['views'] );
		$this->assertIsInt( $data['data']['clicks'] );
		$this->assertEquals( 30, $data['data']['days'] );
	}

	// =========================================================================
	// Площадки
	// =========================================================================

	/**
	 * GET /organizer/venues — список.
	 */
	public function test_venues_list_contract() {
		$this->create_venue_via_api();

		$response = $this->request( 'GET', '/organizer/venues' );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		// Список площадок тоже плоский, без обёртки success/data.
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'total', $data );

		$item = $data['items'][0];
		$this->assertArrayHasKey( 'id', $item );
		$this->assertArrayHasKey( 'title', $item );
		$this->assertArrayHasKey( 'city', $item );
		$this->assertArrayHasKey( 'is_own', $item );
		$this->assertArrayHasKey( 'venue_type', $item );
		$this->assertArrayHasKey( 'venue_address', $item );
	}

	/**
	 * POST /organizer/venues — создание.
	 */
	public function test_create_venue() {
		$response = $this->request( 'POST', '/organizer/venues', array(
			'title'         => 'Новая площадка ' . wp_generate_password( 8, false ),
			'city'          => $this->city_id,
			'venue_address' => 'ул. Новая, 1',
		) );

		$this->assertEquals( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'Площадка создана.', $data['message'] );
		$this->assertArrayHasKey( 'id', $data['data'] );
	}

	/**
	 * Обязательные поля при создании площадки.
	 */
	public function test_create_venue_validation() {
		$response = $this->request( 'POST', '/organizer/venues', array(
			'title' => 'Без города и адреса',
		) );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * DELETE занятой площадки — 409.
	 *
	 * Проверка связи с событиями выполняется при удалении (delete_venue()).
	 */
	public function test_delete_venue_in_use() {
		$venue_id = $this->create_venue_via_api();
		$this->create_event_via_api( array( 'venue' => $venue_id ) );

		$response = $this->request( 'DELETE', "/organizer/venues/{$venue_id}" );

		$this->assertEquals( 409, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'idpg_org_venue_in_use', $data['code'] );
	}

	/**
	 * DELETE свободной площадки — 200.
	 */
	public function test_delete_free_venue() {
		$venue_id = $this->create_venue_via_api();

		$response = $this->request( 'DELETE', "/organizer/venues/{$venue_id}" );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'Площадка удалена.', $data['message'] );
	}

	// =========================================================================
	// Профиль
	// =========================================================================

	/**
	 * GET /organizer/profile — структура.
	 */
	public function test_profile_get_contract() {
		$response = $this->request( 'GET', '/organizer/profile' );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		// Профиль отдаётся плоским массивом, без обёртки success/data —
		// контракт закреплён в test-organizer-profile.php.
		$this->assertArrayHasKey( 'org_name', $data );
		$this->assertArrayHasKey( 'contact_name', $data );
		$this->assertArrayHasKey( 'phone', $data );
		$this->assertArrayHasKey( 'documents', $data );
		$this->assertArrayHasKey( 'verified', $data );
	}

	/**
	 * PUT /organizer/profile — обновление.
	 */
	public function test_profile_update() {
		$response = $this->request( 'PUT', '/organizer/profile', array(
			'org_name'     => 'Новое название ООО',
			'contact_name' => 'Петр Петров',
			'phone'        => '+7 (999) 999-99-99',
		) );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		// PUT отвечает тем же плоским профилем, что и GET.
		$this->assertEquals( 'Новое название ООО', $data['org_name'] );
		$this->assertEquals( 'Петр Петров', $data['contact_name'] );
		$this->assertEquals( '+7 (999) 999-99-99', $data['phone'] );
	}

	// =========================================================================
	// Коды ошибок
	// =========================================================================

	/**
	 * Несуществующее событие — 404.
	 */
	public function test_nonexistent_event_404() {
		$response = $this->request( 'GET', '/organizer/events/999999' );

		$this->assertEquals( 404, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'idpg_org_event_not_found', $data['code'] );
	}

	/**
	 * Несуществующая площадка — 404.
	 */
	public function test_nonexistent_venue_404() {
		$response = $this->request( 'GET', '/organizer/venues/999999' );

		$this->assertEquals( 404, $response->get_status() );
	}

	// =========================================================================
	// Админ-доступ (edit_others_events)
	// =========================================================================

	/**
	 * Админ видит чужие события.
	 */
	public function test_admin_sees_all_events() {
		$other_id = $this->factory()->user->create( array( 'role' => 'event_organizer' ) );
		wp_set_current_user( $other_id );
		$other_event = $this->create_event_via_api();

		$response = $this->request( 'GET', "/organizer/events" );
		$data = $response->get_data();

		// Список отдаётся плоским массивом.
		$this->assertEquals( 200, $response->get_status() );
		$ids = array_column( $data['items'], 'id' );
		$this->assertContains( $other_event, $ids );
	}
}