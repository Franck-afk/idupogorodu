<?php
/**
 * Юнит-тесты DEV-42: REST кабинета организатора — события.
 *
 * Проверяется главное в этих маршрутах: изоляция данных и жизненный цикл.
 * Ошибка скоупа здесь означала бы, что организатор видит и правит чужие
 * события, а снятие блокировки на `pending` — что он обходит модерацию,
 * правя событие прямо во время проверки.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Rest_Organizer
 */
class Test_Rest_Organizer extends WP_UnitTestCase {

	/**
	 * Маршрут списка и создания.
	 */
	const EVENTS = '/idpg/v1/organizer/events';

	/**
	 * Маршрут статистики.
	 */
	const STATS = '/idpg/v1/organizer/stats';

	/**
	 * Подготовка окружения.
	 */
	public function set_up() {
		parent::set_up();

		IDPG_REST_Organizer::instance();
		IDPG_Roles::instance()->create_roles();
		IDPG_Event_Moderation::instance();

		// Статус rejected регистрируется на init, который к моменту тестов
		// уже отработал, поэтому регистрируем явно.
		IDPG_REST_Organizer::instance()->register_post_status();

		$this->reset_moderation_state();
	}

	/**
	 * Создать организатора.
	 *
	 * @param string $login Логин.
	 * @return WP_User
	 */
	protected function make_organizer( $login ) {
		$user = new WP_User(
			$this->factory()->user->create(
				array(
					'user_login' => $login,
					'role'       => 'subscriber',
				)
			)
		);

		foreach ( (array) $user->roles as $role ) {
			$user->remove_role( $role );
		}
		$user->add_role( IDPG_Roles::ROLE_SLUG );

		return $user;
	}

	/**
	 * Запрос с валидным nonce.
	 *
	 * permission_callback проверяет X-WP-Nonce явно, поэтому без заголовка
	 * любой маршрут отвечает 403 ещё до проверки прав.
	 *
	 * @param string $method HTTP-метод.
	 * @param string $route  Маршрут.
	 * @param array  $params Параметры.
	 * @return WP_REST_Response
	 */
	protected function request( $method, $route, $params = array() ) {
		$request = new WP_REST_Request( $method, $route );

		if ( $params ) {
			// Важно: для GET параметры читаются из query, а не из body.
			// WP_REST_Request::get_param() для GET вообще не смотрит в
			// набор 'POST', поэтому set_body_params() молча игнорировался бы.
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
	 * Сброс статиков IDPG_Event_Moderation.
	 *
	 * Модуль рассчитан на один запуск на HTTP-запрос: статики
	 * $phase_running / $phase_post_id / $phase_scheduled намеренно живут весь
	 * процесс. В PHPUnit все тесты делят один процесс, поэтому после первого
	 * прогона run_price_moderation() последующие вызовы молча ничего не делали
	 * бы. Сбрасываем статики рефлексией — это тестовая обвязка, продакшн-код
	 * не трогаем.
	 *
	 * @return void
	 */
	protected function reset_moderation_state() {
		$defaults = array(
			'phase_running'   => false,
			'phase_post_id'   => 0,
			'phase_scheduled' => false,
		);

		foreach ( $defaults as $name => $value ) {
			$property = new ReflectionProperty( 'IDPG_Event_Moderation', $name );

			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
	}

	/**
	 * Запрос без nonce — для проверки 403.
	 *
	 * @param string $method HTTP-метод.
	 * @param string $route  Маршрут.
	 * @return WP_REST_Response
	 */
	protected function request_without_nonce( $method, $route ) {
		$request = new WP_REST_Request( $method, $route );

		return rest_do_request( $request );
	}

	/**
	 * Создать событие указанного автора и статуса.
	 *
	 * @param int    $author_id ID автора.
	 * @param string $status    Статус.
	 * @param array  $meta      Мета-ключ => значение.
	 * @return int
	 */
	protected function make_event( $author_id, $status = 'draft', $meta = array() ) {
		$post_id = $this->factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => $status,
				'post_author' => $author_id,
				'post_title'  => 'Тестовое событие',
			)
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	// =====================================================================
	// Права и скоуп
	// =====================================================================

	/**
	 * Гость получает 401 на всех маршрутах кабинета.
	 */
	public function test_guest_gets_401() {
		wp_set_current_user( 0 );

		$event_id = $this->make_event( 1, 'publish' );

		$this->assertSame( 401, $this->request( 'GET', self::EVENTS )->get_status() );
		$this->assertSame( 401, $this->request( 'POST', self::EVENTS )->get_status() );
		$this->assertSame( 401, $this->request( 'GET', self::STATS )->get_status() );
		$this->assertSame( 401, $this->request( 'GET', self::EVENTS . '/' . $event_id )->get_status() );
	}

	/**
	 * Подписчик (не организатор) получает 403.
	 */
	public function test_subscriber_gets_403() {
		$subscriber = $this->factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $subscriber->ID );

		$this->assertSame( 403, $this->request( 'GET', self::EVENTS )->get_status() );
	}

	/**
	 * Без nonce — 403, даже для организатора со своим событием.
	 */
	public function test_missing_nonce_gets_403() {
		$organizer = $this->make_organizer( 'nonce_org' );

		wp_set_current_user( $organizer->ID );

		$response = $this->request_without_nonce( 'GET', self::EVENTS );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'idpg_org_nonce_failed', $response->get_data()['code'] );
	}

	/**
	 * Неверный nonce — 403.
	 */
	public function test_wrong_nonce_gets_403() {
		$organizer = $this->make_organizer( 'bad_nonce_org' );

		wp_set_current_user( $organizer->ID );

		$request = new WP_REST_Request( 'GET', self::EVENTS );
		$request->set_header( 'X-WP-Nonce', 'not-a-real-nonce' );

		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}

	/**
	 * Чужое событие недоступно: 403, а не 404.
	 */
	public function test_cannot_read_another_organizers_event() {
		$owner    = $this->make_organizer( 'owner_org' );
		$attacker = $this->make_organizer( 'attacker_org' );

		$event_id = $this->make_event( $owner->ID, 'publish' );

		wp_set_current_user( $attacker->ID );

		$response = $this->request( 'GET', self::EVENTS . '/' . $event_id );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'idpg_org_forbidden_owner', $response->get_data()['code'] );
	}

	/**
	 * Чужое событие нельзя изменить или удалить.
	 */
	public function test_cannot_modify_another_organizers_event() {
		$owner    = $this->make_organizer( 'owner_org2' );
		$attacker = $this->make_organizer( 'attacker_org2' );

		$event_id = $this->make_event( $owner->ID, 'draft' );

		wp_set_current_user( $attacker->ID );

		$this->assertSame( 403, $this->request( 'PUT', self::EVENTS . '/' . $event_id, array( 'title' => 'Взлом' ) )->get_status() );
		$this->assertSame( 403, $this->request( 'DELETE', self::EVENTS . '/' . $event_id )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', self::EVENTS . '/' . $event_id . '/submit' )->get_status() );

		// Событие не тронуто.
		$this->assertSame( 'Тестовое событие', get_post( $event_id )->post_title );
		$this->assertSame( 'draft', get_post_status( $event_id ) );
	}

	/**
	 * Список содержит только свои события.
	 */
	public function test_list_contains_only_own_events() {
		$mine   = $this->make_organizer( 'list_mine' );
		$theirs = $this->make_organizer( 'list_theirs' );

		$my_id    = $this->make_event( $mine->ID, 'draft' );
		$their_id = $this->make_event( $theirs->ID, 'draft' );

		wp_set_current_user( $mine->ID );

		$data = $this->request( 'GET', self::EVENTS )->get_data();

		$ids = wp_list_pluck( $data['items'], 'id' );

		$this->assertContains( $my_id, $ids );
		$this->assertNotContains( $their_id, $ids );
		$this->assertSame( 1, $data['total'] );
	}

	/**
	 * Администратор (edit_others_events) видит чужие события.
	 */
	public function test_admin_can_access_others_events() {
		$owner = $this->make_organizer( 'adm_owner' );
		$admin = $this->factory()->user->create_and_get( array( 'role' => 'administrator' ) );

		$event_id = $this->make_event( $owner->ID, 'publish' );

		wp_set_current_user( $admin->ID );

		$this->assertSame( 200, $this->request( 'GET', self::EVENTS . '/' . $event_id )->get_status() );
	}

	// =====================================================================
	// Создание и обновление
	// =====================================================================

	/**
	 * Создание возвращает 201 и создаёт черновик от имени текущего пользователя.
	 */
	public function test_create_event_makes_draft() {
		$organizer = $this->make_organizer( 'create_org' );

		wp_set_current_user( $organizer->ID );

		$response = $this->request(
			'POST',
			self::EVENTS,
			array(
				'title'      => 'Новое событие',
				'content'    => 'Описание',
				'start_date' => '2026-10-01 12:00:00',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );

		$post = get_post( $response->get_data()['data']['id'] );

		$this->assertSame( 'event', $post->post_type );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( $organizer->ID, (int) $post->post_author );
		$this->assertSame( 'Новое событие', $post->post_title );
		$this->assertSame( '2026-10-01 12:00:00', get_post_meta( $post->ID, '_idpg_start_date', true ) );
	}

	/**
	 * Создание без обязательных полей не падает: черновик допускает неполные данные.
	 */
	public function test_create_allows_empty_draft() {
		$organizer = $this->make_organizer( 'create_empty' );

		wp_set_current_user( $organizer->ID );

		$this->assertSame( 201, $this->request( 'POST', self::EVENTS, array() )->get_status() );
	}

	/**
	 * HTML в content не попадает в запись как есть.
	 */
	public function test_create_sanitizes_content() {
		$organizer = $this->make_organizer( 'create_html' );

		wp_set_current_user( $organizer->ID );

		$response = $this->request(
			'POST',
			self::EVENTS,
			array( 'content' => '<script>alert(1)</script><p>Текст</p>' )
		);

		$content = get_post( $response->get_data()['data']['id'] )->post_content;

		$this->assertStringNotContainsString( '<script>', $content );
		$this->assertStringContainsString( '<p>Текст</p>', $content );
	}

	/**
	 * Несуществующая площадка отклоняется.
	 */
	public function test_create_rejects_invalid_venue() {
		$organizer = $this->make_organizer( 'create_venue' );

		wp_set_current_user( $organizer->ID );

		$response = $this->request( 'POST', self::EVENTS, array( 'venue' => 999999 ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'idpg_org_invalid_venue', $response->get_data()['code'] );
	}

	/**
	 * Кривый JSON сеансов отклоняется.
	 */
	public function test_create_rejects_invalid_sessions_json() {
		$organizer = $this->make_organizer( 'create_sessions' );

		wp_set_current_user( $organizer->ID );

		$response = $this->request( 'POST', self::EVENTS, array( 'sessions_json' => '{не json' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'idpg_org_invalid_sessions', $response->get_data()['code'] );
	}

	/**
	 * Валидный JSON сеансов сохраняется.
	 *
	 * DEV-48 изменил контракт хранения: раньше зеркало получало строку
	 * запроса как есть, поэтому в него попадали и поля, которых нет в
	 * модели (тест ниже передавал `from`/`to`, тогда как complex-поле
	 * содержит только time/price/url). Теперь зеркало собирается из
	 * complex-поля Carbon Fields, поэтому проверяем нормализованный
	 * результат, а не исходную строку. Смысл теста — «валидный JSON
	 * принимается и сохраняется» — сохранён.
	 */
	public function test_create_accepts_valid_sessions_json() {
		$organizer = $this->make_organizer( 'create_sessions_ok' );

		wp_set_current_user( $organizer->ID );

		$json = wp_json_encode(
			array(
				array( 'time' => '12:00', 'price' => '300' ),
				array( 'time' => '15:30', 'price' => '500', 'url' => 'https://example.com/s' ),
			)
		);

		$response = $this->request( 'POST', self::EVENTS, array( 'sessions_json' => $json ) );
		$event_id = $response->get_data()['data']['id'];

		$this->assertSame( 201, $response->get_status() );

		$this->assertSame(
			array(
				array( 'time' => '12:00', 'price' => '300', 'url' => '' ),
				array( 'time' => '15:30', 'price' => '500', 'url' => 'https://example.com/s' ),
			),
			json_decode( get_post_meta( $event_id, '_idpg_sessions_json', true ), true )
		);

		// Сесансы видны и редактору Carbon Fields — это DEV-48.
		$this->assertSame( '12:00', carbon_get_post_meta( $event_id, 'idpg_sessions' )[0]['time'] );
	}

	/**
	 * Обновление своего черновика работает.
	 */
	public function test_update_own_draft() {
		$organizer = $this->make_organizer( 'update_org' );
		$event_id  = $this->make_event( $organizer->ID, 'draft' );

		wp_set_current_user( $organizer->ID );

		$response = $this->request( 'PUT', self::EVENTS . '/' . $event_id, array( 'title' => 'Изменённое' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Изменённое', get_post( $event_id )->post_title );
	}

	/**
	 * Событие в статусе pending править нельзя — 409.
	 */
	public function test_cannot_update_pending_event() {
		$organizer = $this->make_organizer( 'pending_org' );
		$event_id  = $this->make_event( $organizer->ID, 'pending' );

		wp_set_current_user( $organizer->ID );

		$response = $this->request( 'PUT', self::EVENTS . '/' . $event_id, array( 'title' => 'Правка' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'idpg_org_event_locked', $response->get_data()['code'] );
		$this->assertSame( 'Тестовое событие', get_post( $event_id )->post_title );
	}

	/**
	 * Опубликованное событие править нельзя — 403.
	 */
	public function test_cannot_update_published_event() {
		$organizer = $this->make_organizer( 'published_org' );
		$event_id  = $this->make_event( $organizer->ID, 'publish' );

		wp_set_current_user( $organizer->ID );

		$this->assertSame( 403, $this->request( 'PUT', self::EVENTS . '/' . $event_id, array( 'title' => 'Правка' ) )->get_status() );
	}

	/**
	 * Несуществующее событие — 404.
	 */
	public function test_unknown_event_is_404() {
		$organizer = $this->make_organizer( 'unknown_org' );

		wp_set_current_user( $organizer->ID );

		$this->assertSame( 404, $this->request( 'GET', self::EVENTS . '/999999' )->get_status() );
	}

	// =====================================================================
	// Удаление
	// =====================================================================

	/**
	 * Свой черновик удаляется.
	 */
	public function test_delete_own_draft() {
		$organizer = $this->make_organizer( 'delete_org' );
		$event_id  = $this->make_event( $organizer->ID, 'draft' );

		wp_set_current_user( $organizer->ID );

		$response = $this->request( 'DELETE', self::EVENTS . '/' . $event_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( get_post( $event_id ) );
	}

	/**
	 * Опубликованное событие удалить нельзя — 403.
	 */
	public function test_cannot_delete_published_event() {
		$organizer = $this->make_organizer( 'delete_pub_org' );
		$event_id  = $this->make_event( $organizer->ID, 'publish' );

		wp_set_current_user( $organizer->ID );

		$this->assertSame( 403, $this->request( 'DELETE', self::EVENTS . '/' . $event_id )->get_status() );
		$this->assertNotNull( get_post( $event_id ) );
	}

	/**
	 * Событие на модерации удалить нельзя — 409.
	 */
	public function test_cannot_delete_pending_event() {
		$organizer = $this->make_organizer( 'delete_pending_org' );
		$event_id  = $this->make_event( $organizer->ID, 'pending' );

		wp_set_current_user( $organizer->ID );

		$this->assertSame( 409, $this->request( 'DELETE', self::EVENTS . '/' . $event_id )->get_status() );
	}

	// =====================================================================
	// Отправка на модерацию
	// =====================================================================

	/**
	 * Отправка неполного черновика отклоняется с перечнем полей.
	 */
	public function test_submit_requires_mandatory_fields() {
		$organizer = $this->make_organizer( 'submit_incomplete' );
		$event_id  = $this->make_event( $organizer->ID, 'draft' );

		wp_set_current_user( $organizer->ID );

		$response = $this->request( 'POST', self::EVENTS . '/' . $event_id . '/submit' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'idpg_org_submit_incomplete', $response->get_data()['code'] );
		$this->assertSame( 'draft', get_post_status( $event_id ) );
	}

	/**
	 * Заполненный черновик уходит в pending.
	 */
	public function test_submit_moves_draft_to_pending() {
		$organizer = $this->make_organizer( 'submit_ok' );
		$event_id  = $this->make_event( $organizer->ID, 'draft' );

		update_post_meta( $event_id, '_idpg_start_date', '2026-10-01 12:00:00' );

		$city = $this->factory()->term->create( array( 'taxonomy' => 'city' ) );
		wp_set_object_terms( $event_id, array( $city ), 'city' );

		// Платное без билетных данных, чтобы модерация не опубликовала сразу.
		update_post_meta( $event_id, '_idpg_is_free', 'no' );
		update_post_meta( $event_id, '_idpg_price_from', '500' );
		update_post_meta( $event_id, '_idpg_ticket_type', 'external_link' );
		update_post_meta( $event_id, '_idpg_ticket_url', 'https://tickets.example' );

		wp_set_current_user( $organizer->ID );

		$response = $this->request( 'POST', self::EVENTS . '/' . $event_id . '/submit' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pending', get_post_status( $event_id ) );
	}

	/**
	 * Хук отправки срабатывает.
	 */
	public function test_submit_fires_action() {
		$organizer = $this->make_organizer( 'submit_action' );
		$event_id  = $this->make_event( $organizer->ID, 'draft' );

		update_post_meta( $event_id, '_idpg_start_date', '2026-10-01 12:00:00' );

		$city = $this->factory()->term->create( array( 'taxonomy' => 'city' ) );
		wp_set_object_terms( $event_id, array( $city ), 'city' );

		update_post_meta( $event_id, '_idpg_is_free', 'no' );
		update_post_meta( $event_id, '_idpg_price_from', '500' );
		update_post_meta( $event_id, '_idpg_ticket_type', 'external_link' );
		update_post_meta( $event_id, '_idpg_ticket_url', 'https://tickets.example' );

		$captured = 0;
		$callback = static function ( $post_id ) use ( &$captured ) {
			$captured = (int) $post_id;
		};

		add_action( 'idpg_organizer_event_submitted', $callback );
		wp_set_current_user( $organizer->ID );
		$this->request( 'POST', self::EVENTS . '/' . $event_id . '/submit' );
		remove_action( 'idpg_organizer_event_submitted', $callback );

		$this->assertSame( $event_id, $captured );
	}

	/**
	 * Повторная отправка события, уже стоящего на модерации, — 409.
	 */
	public function test_submit_pending_event_conflicts() {
		$organizer = $this->make_organizer( 'submit_twice' );
		$event_id  = $this->make_event( $organizer->ID, 'pending' );

		wp_set_current_user( $organizer->ID );

		$this->assertSame( 409, $this->request( 'POST', self::EVENTS . '/' . $event_id . '/submit' )->get_status() );
	}

	/**
	 * Бесплатное событие автопубликуется по правилам модерации.
	 *
	 * Проверяет интеграцию с IDPG_Event_Moderation: бесплатное событие не
	 * должно ждать модератора.
	 */
	public function test_free_event_auto_publishes_on_submit() {
		$organizer = $this->make_organizer( 'submit_free' );
		$event_id  = $this->make_event( $organizer->ID, 'draft' );

		update_post_meta( $event_id, '_idpg_start_date', '2026-10-01 12:00:00' );
		update_post_meta( $event_id, '_idpg_is_free', 'yes' );

		$city = $this->factory()->term->create( array( 'taxonomy' => 'city' ) );
		wp_set_object_terms( $event_id, array( $city ), 'city' );

		wp_set_current_user( $organizer->ID );

		$this->request( 'POST', self::EVENTS . '/' . $event_id . '/submit' );

		// schedule_price_moderation() откладывает правила на shutdown;
		// в тесте вызываем их напрямую.
		IDPG_Event_Moderation::instance()->run_price_moderation();

		$this->assertSame( 'publish', get_post_status( $event_id ) );
	}

	/**
	 * Платное событие без билетных данных возвращается в черновик.
	 */
	public function test_paid_event_without_tickets_downgrades_to_draft() {
		$organizer = $this->make_organizer( 'submit_paid_bad' );
		$event_id  = $this->make_event( $organizer->ID, 'draft' );

		update_post_meta( $event_id, '_idpg_start_date', '2026-10-01 12:00:00' );
		update_post_meta( $event_id, '_idpg_is_free', 'no' );
		update_post_meta( $event_id, '_idpg_price_from', '500' );
		// Тип билета задан, но ссылка пустая — данных не хватает.

		$city = $this->factory()->term->create( array( 'taxonomy' => 'city' ) );
		wp_set_object_terms( $event_id, array( $city ), 'city' );

		wp_set_current_user( $organizer->ID );

		$this->request( 'POST', self::EVENTS . '/' . $event_id . '/submit' );
		IDPG_Event_Moderation::instance()->run_price_moderation();

		$this->assertSame( 'draft', get_post_status( $event_id ) );
	}

	/**
	 * Галерея пишется и читается в том же хранилище, что у редактора.
	 *
	 * Регрессия: `idpg_gallery` — поле media_gallery Carbon Fields. CF
	 * хранит его не под ключом `_idpg_gallery`, а в своём формате
	 * `_idpg_gallery|||0|value`, поэтому читать и писать его нужно через
	 * carbon_get/carbon_set_post_meta(). Прежний код писал `idpg_gallery`
	 * (без подчёркивания) обычным update_post_meta() — такие данные были
	 * не видны в админке, и наоборот.
	 */
	public function test_gallery_roundtrip_uses_carbon_storage() {
		$organizer = $this->make_organizer( 'gallery_org' );

		wp_set_current_user( $organizer->ID );

		$attachment = $this->factory()->attachment->create_object( 'photo.jpg' );

		$response = $this->request(
			'POST',
			self::EVENTS,
			array( 'gallery' => array( $attachment ) )
		);

		$this->assertSame( 201, $response->get_status() );

		$event_id = $response->get_data()['data']['id'];

		// Значение видно тем же способом, каким его читает редактор CF.
		$this->assertSame(
			array( (string) $attachment ),
			array_map( 'strval', (array) carbon_get_post_meta( $event_id, 'idpg_gallery' ) )
		);

		// Ключа без подчёркивания быть не должно — именно его писала старая
		// версия, и редактор его не читал.
		$this->assertSame(
			'',
			(string) get_post_meta( $event_id, 'idpg_gallery', true ),
			'Галерея писалась в неверный ключ без подчёркивания.'
		);

		// И значение возвращается в ответе API.
		$this->assertSame( array( (int) $attachment ), $response->get_data()['data']['gallery'] );

		// И при повторном чтении через GET.
		$read = $this->request( 'GET', self::EVENTS . '/' . $event_id )->get_data();

		$this->assertSame( array( (int) $attachment ), $read['data']['gallery'] );
	}

	/**
	 * Пустая галерея не превращается в мусор при чтении.
	 *
	 * CF хранит пустое значение как строку-заглушку `...|||0|_empty`; наружу
	 * оно должно уходить пустым массивом, а не этой строкой.
	 */
	public function test_empty_gallery_reads_as_empty_array() {
		$organizer = $this->make_organizer( 'gallery_empty' );

		wp_set_current_user( $organizer->ID );

		$event_id = $this->make_event( $organizer->ID, 'draft' );

		// Имитируем состояние «поле есть, но не заполнено».
		carbon_set_post_meta( $event_id, 'idpg_gallery', array() );

		$data = $this->request( 'GET', self::EVENTS . '/' . $event_id )->get_data();

		$this->assertSame( array(), $data['data']['gallery'] );
	}

	// =====================================================================
	// Фильтры списка
	// =====================================================================

	/**
	 * Фильтр по статусу работает.
	 */
	public function test_list_filters_by_status() {
		$organizer = $this->make_organizer( 'filter_org' );

		$draft_id   = $this->make_event( $organizer->ID, 'draft' );
		$publish_id = $this->make_event( $organizer->ID, 'publish' );

		wp_set_current_user( $organizer->ID );

		$data = $this->request( 'GET', self::EVENTS, array( 'status' => 'draft' ) )->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertSame( array( $draft_id ), wp_list_pluck( $data['items'], 'id' ) );

		$data = $this->request( 'GET', self::EVENTS, array( 'status' => 'publish' ) )->get_data();

		$this->assertSame( array( $publish_id ), wp_list_pluck( $data['items'], 'id' ) );
	}

	/**
	 * Поиск работает по заголовку.
	 */
	public function test_list_search() {
		$organizer = $this->make_organizer( 'search_org' );

		$match_id  = $this->make_event( $organizer->ID, 'draft' );
		$other_id  = $this->make_event( $organizer->ID, 'draft' );

		wp_update_post( array( 'ID' => $match_id, 'post_title' => 'Джаз в Ижевске' ) );
		wp_update_post( array( 'ID' => $other_id, 'post_title' => 'Рок-концерт' ) );

		wp_set_current_user( $organizer->ID );

		$data = $this->request( 'GET', self::EVENTS, array( 'search' => 'Джаз' ) )->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertSame( $match_id, $data['items'][0]['id'] );
	}

	/**
	 * per_page ограничен сверху.
	 */
	public function test_list_per_page_capped() {
		$organizer = $this->make_organizer( 'perpage_org' );

		wp_set_current_user( $organizer->ID );

		$response = $this->request( 'GET', self::EVENTS, array( 'per_page' => 500 ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Сортировка по заголовку работает.
	 */
	public function test_list_orderby_title() {
		$organizer = $this->make_organizer( 'orderby_org' );

		$bbb = $this->make_event( $organizer->ID, 'draft' );
		$aaa = $this->make_event( $organizer->ID, 'draft' );

		wp_update_post( array( 'ID' => $bbb, 'post_title' => 'Буга' ) );
		wp_update_post( array( 'ID' => $aaa, 'post_title' => 'Ага' ) );

		wp_set_current_user( $organizer->ID );

		$data = $this->request(
			'GET',
			self::EVENTS,
			array(
				'orderby' => 'title',
				'order'   => 'asc',
			)
		)->get_data();

		$this->assertSame( array( $aaa, $bbb ), wp_list_pluck( $data['items'], 'id' ) );
	}

	// =====================================================================
	// Статистика
	// =====================================================================

	/**
	 * Счётчики считают только свои события.
	 */
	public function test_stats_counts_own_events() {
		$organizer = $this->make_organizer( 'stats_org' );
		$other    = $this->make_organizer( 'stats_other' );

		$this->make_event( $organizer->ID, 'draft' );
		$this->make_event( $organizer->ID, 'publish' );
		$this->make_event( $other->ID, 'publish' );
		$this->make_event( $other->ID, 'publish' );

		wp_set_current_user( $organizer->ID );

		$data = $this->request( 'GET', self::STATS )->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 1, $data['data']['counts']['draft'] );
		$this->assertSame( 1, $data['data']['counts']['publish'] );
		$this->assertSame( 0, $data['data']['counts']['pending'] );
		$this->assertSame( 2, $data['data']['total'] );
	}

	/**
	 * Просмотры и клики суммируются по своим событиям.
	 */
	public function test_stats_sums_views_and_clicks() {
		$organizer = $this->make_organizer( 'views_org' );
		$other    = $this->make_organizer( 'views_other' );

		$mine = $this->make_event( $organizer->ID, 'publish' );
		$this->make_event( $organizer->ID, 'publish' );
		$theirs = $this->make_event( $other->ID, 'publish' );

		update_post_meta( $mine, IDPG_Views::META_KEY, 10 );
		update_post_meta( $mine, IDPG_Views::META_CLICKS, 3 );
		update_post_meta( $theirs, IDPG_Views::META_KEY, 999 );

		wp_set_current_user( $organizer->ID );

		$data = $this->request( 'GET', self::STATS )->get_data();

		$this->assertSame( 10, $data['data']['views'] );
		$this->assertSame( 3, $data['data']['clicks'] );
	}

	// =====================================================================
	// Статус rejected
	// =====================================================================

	/**
	 * Статус «Отклонено» зарегистрирован.
	 */
	public function test_rejected_status_registered() {
		$this->assertTrue( post_type_exists( 'event' ) );

		$status = get_post_status_object( IDPG_REST_Organizer::STATUS_REJECTED );

		$this->assertNotNull( $status );
		$this->assertSame( IDPG_REST_Organizer::LABEL_REJECTED, $status->label );
		$this->assertTrue( $status->protected );
	}

	/**
	 * Отклонённое событие снова можно править и отправить.
	 */
	public function test_rejected_event_is_editable_and_submittable() {
		$organizer = $this->make_organizer( 'rejected_org' );

		// Статус мог быть не зарегистрирован в момент создания записи —
		// регистрируем явно, как это делает init в реальном запросе.
		IDPG_REST_Organizer::instance()->register_post_status();

		$event_id = $this->make_event( $organizer->ID, 'draft' );
		wp_update_post( array( 'ID' => $event_id, 'post_status' => IDPG_REST_Organizer::STATUS_REJECTED ) );

		update_post_meta( $event_id, '_idpg_start_date', '2026-10-01 12:00:00' );

		$city = $this->factory()->term->create( array( 'taxonomy' => 'city' ) );
		wp_set_object_terms( $event_id, array( $city ), 'city' );

		update_post_meta( $event_id, '_idpg_is_free', 'no' );
		update_post_meta( $event_id, '_idpg_price_from', '500' );
		update_post_meta( $event_id, '_idpg_ticket_type', 'external_link' );
		update_post_meta( $event_id, '_idpg_ticket_url', 'https://tickets.example' );

		wp_set_current_user( $organizer->ID );

		$this->assertSame( 200, $this->request( 'PUT', self::EVENTS . '/' . $event_id, array( 'title' => 'Исправлено' ) )->get_status() );
		$this->assertSame( 'Исправлено', get_post( $event_id )->post_title );

		$this->assertSame( 200, $this->request( 'POST', self::EVENTS . '/' . $event_id . '/submit' )->get_status() );
		$this->assertSame( 'pending', get_post_status( $event_id ) );
	}
}
