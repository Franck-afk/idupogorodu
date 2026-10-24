<?php
/**
 * Юнит-тесты DEV-70: регистрация открыта только организаторам.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Register_Organizer_Only
 */
class Test_Register_Organizer_Only extends WP_UnitTestCase {

	/**
	 * Текст отказа, зафиксированный продуктовым решением.
	 * Меняем его только вместе с решением, поэтому проверяем строкой.
	 */
	const DENIED_MESSAGE = 'Регистрация доступна только организаторам.';

	/**
	 * Маршрут регистрации.
	 */
	const ROUTE = '/idpg/v1/auth/register';

	/**
	 * Подготовка окружения: гарантируем зарегистрированные маршруты
	 * и чистый счётчик регистраций с одного IP.
	 */
	public function set_up() {
		parent::set_up();

		// Конструктор вешает register_routes на rest_api_init.
		IDPG_REST_Auth::instance();

		// Лимит — 3 регистрации в час с одного IP, иначе тесты упираются
		// в 429 раньше, чем дойдут до проверяемой логики.
		delete_transient( $this->rate_key() );
	}

	/**
	 * Ключ transient счётчика регистраций для текущего IP.
	 *
	 * @return string
	 */
	private function rate_key() {
		return 'idpg_reg_attempts_' . md5( IDPG_Auth_Rate_Limiter::instance()->get_client_ip() );
	}

	/**
	 * Собирает валидный запрос регистрации организатора.
	 *
	 * @param array $overrides Поля, которые нужно заменить или убрать.
	 * @return WP_REST_Request
	 */
	private function register_request( $overrides = array() ) {
		$params = array_merge(
			array(
				'type'         => 'organizer',
				'name'         => 'Иван Иванов',
				'org_name'     => 'Организация «Тест»',
				'contact_name' => 'Иван Иванов',
				'email'        => 'org@example.com',
				'phone'        => '+7 999 000-00-00',
				'password'     => 'parol12345',
				'terms'        => true,
				// 152-ФЗ: согласие на обработку ПДн обязательно (G8).
				'idpg_pdn_consent' => true,
			),
			$overrides
		);

		// null означает «убрать параметр целиком».
		foreach ( $params as $key => $value ) {
			if ( null === $value ) {
				unset( $params[ $key ] );
			}
		}

		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_body_params( $params );

		return $request;
	}

	/**
	 * Проверяет, что ответ — 403 с продуктовым текстом.
	 *
	 * @param WP_REST_Response $response Ответ маршрута.
	 */
	private function assertDenied( $response ) {
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'registration_disabled', $response->get_data()['code'] );
		$this->assertSame( self::DENIED_MESSAGE, $response->get_data()['message'] );
	}

	/* ---------------------------------------------------------------------
	 * Пункт 1 приёмки: type=user → 403.
	 * ------------------------------------------------------------------ */

	/**
	 * type=user отклоняется с 403 и не создаёт пользователя.
	 */
	public function test_type_user_is_forbidden() {
		$response = rest_do_request( $this->register_request( array( 'type' => 'user' ) ) );

		$this->assertDenied( $response );
		$this->assertFalse( email_exists( 'org@example.com' ) );
	}

	/**
	 * Отсутствие type отклоняется с 403, а не 400.
	 *
	 * Регрессия: пока в схеме стояло required => true, WordPress отвечал
	 * на такой запрос 400 rest_missing_callback_param, не доходя до колбэка.
	 */
	public function test_missing_type_is_forbidden() {
		$response = rest_do_request( $this->register_request( array( 'type' => null ) ) );

		$this->assertDenied( $response );
		$this->assertFalse( email_exists( 'org@example.com' ) );
	}

	/**
	 * Любой посторонний тип отклоняется тем же 403.
	 *
	 * @dataProvider data_rejected_types
	 *
	 * @param mixed $type Значение параметра type.
	 */
	public function test_other_types_are_forbidden( $type ) {
		$response = rest_do_request( $this->register_request( array( 'type' => $type ) ) );

		$this->assertDenied( $response );
		$this->assertFalse( email_exists( 'org@example.com' ) );
	}

	/**
	 * Значения, которые обязаны отклоняться.
	 *
	 * @return array[]
	 */
	public function data_rejected_types() {
		return array(
			'пустая строка' => array( '' ),
			'пользователь'  => array( 'user' ),
			'с неизвестным'  => array( 'administrator' ),
			'с мусором'     => array( 'ORG' ),
			'не строка'     => array( array( 'organizer' ) ),
		);
	}

	/**
	 * Схема маршрута не должна отсекать type раньше колбэка.
	 *
	 * Требование и enum здесь означали бы ответ 400 вместо требуемого 403.
	 */
	public function test_route_schema_defers_type_check_to_callback() {
		$routes = rest_get_server()->get_routes();
		$args   = $routes[ self::ROUTE ][0]['args'];

		$this->assertArrayHasKey( 'type', $args );
		$this->assertFalse( $args['type']['required'] );
		$this->assertArrayNotHasKey( 'enum', $args['type'] );
	}

	/* ---------------------------------------------------------------------
	 * Пункт 1 приёмки: type=organizer → успех.
	 * ------------------------------------------------------------------ */

	/**
	 * Регистрация организатора проходит и создаёт неподтверждённого подписчика.
	 */
	public function test_type_organizer_registers_successfully() {
		$response = rest_do_request( $this->register_request() );

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertTrue( $data['pending_email'] );

		$user = get_user_by( 'email', 'org@example.com' );
		$this->assertNotFalse( $user );
		$this->assertSame( $data['user_id'], $user->ID );
		$this->assertSame( 'Иван Иванов', $user->display_name );

		// Роль выдаётся только после подтверждения по ссылке из письма.
		$this->assertSame( array( 'subscriber' ), array_values( (array) $user->roles ) );

		$this->assertSame( 'Организация «Тест»', get_user_meta( $user->ID, 'idpg_org_name', true ) );
		$this->assertSame( 'Иван Иванов', get_user_meta( $user->ID, 'idpg_contact_name', true ) );
		$this->assertSame( '+7 999 000-00-00', get_user_meta( $user->ID, 'idpg_phone', true ) );

		// Пользователь числится ожидающим подтверждения.
		$this->assertTrue( IDPG_Organizer_Verify::instance()->is_pending( $user->ID ) );
	}

	/**
	 * Организатор без названия организации всё ещё отклоняется валидацией.
	 */
	public function test_organizer_still_validates_required_fields() {
		$response = rest_do_request( $this->register_request( array( 'org_name' => null ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'empty_org_name', $response->get_data()['code'] );
		$this->assertFalse( email_exists( 'org@example.com' ) );
	}

	/* ---------------------------------------------------------------------
	 * Регрессии: не должны сломаться прежние проверки.
	 * ------------------------------------------------------------------ */

	/**
	 * Авторизованному пользователю регистрация по-прежнему недоступна.
	 */
	public function test_logged_in_user_is_still_rejected() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = rest_do_request( $this->register_request() );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'already_logged_in', $response->get_data()['code'] );
	}

	/**
	 * Отказы по типу не расходуют лимит: проверка стоит в handle_register()
	 * до обращения к IDPG_Auth_Rate_Limiter, поэтому 403-запросы не блокируют
	 * настоящую регистрацию организатора.
	 */
	public function test_denied_attempts_do_not_consume_rate_limit() {
		for ( $i = 0; $i < IDPG_Auth_Rate_Limiter::REGISTRATION_LIMIT + 2; $i++ ) {
			$this->assertDenied( rest_do_request( $this->register_request( array( 'type' => 'user' ) ) ) );
		}

		// Настоящая регистрация после серии отказов всё ещё проходит.
		$response = rest_do_request( $this->register_request() );

		$this->assertSame( 201, $response->get_status() );
	}

	/**
	 * Реальные попытки регистрации ограничены: 429 на четвёртой.
	 */
	public function test_successful_registrations_are_rate_limited() {
		for ( $i = 0; $i < IDPG_Auth_Rate_Limiter::REGISTRATION_LIMIT; $i++ ) {
			$response = rest_do_request( $this->register_request( array( 'email' => "org{$i}@example.com" ) ) );
			$this->assertSame( 201, $response->get_status() );
		}

		$response = rest_do_request( $this->register_request( array( 'email' => 'org-last@example.com' ) ) );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'too_many_registrations', $response->get_data()['code'] );
	}

	/* ---------------------------------------------------------------------
	 * DEV-33: отказы по данным не должны расходовать лимит регистрации.
	 * ------------------------------------------------------------------ */

	/**
	 * Текущее значение счётчика регистраций для нашего IP.
	 *
	 * @return int
	 */
	private function rate_count() {
		$data = get_transient( $this->rate_key() );

		return is_array( $data ) && isset( $data['count'] ) ? (int) $data['count'] : 0;
	}

	/**
	 * Запрос с уже занятым email отклоняется до траты лимита.
	 */
	public function test_duplicate_email_does_not_consume_rate_limit() {
		rest_do_request( $this->register_request( array( 'email' => 'taken@example.com' ) ) );
		$before = $this->rate_count();

		$response = rest_do_request( $this->register_request( array( 'email' => 'taken@example.com' ) ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'email_exists', $response->get_data()['code'] );
		$this->assertSame( $before, $this->rate_count() );
	}

	/**
	 * Пустое название организации отклоняется до траты лимита.
	 */
	public function test_missing_org_name_does_not_consume_rate_limit() {
		$before = $this->rate_count();

		$response = rest_do_request( $this->register_request( array( 'org_name' => null ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'empty_org_name', $response->get_data()['code'] );
		$this->assertSame( $before, $this->rate_count() );
	}

	/**
	 * Отсутствие телефона отклоняется до траты лимита.
	 */
	public function test_missing_phone_does_not_consume_rate_limit() {
		$before = $this->rate_count();

		$response = rest_do_request( $this->register_request( array( 'phone' => null ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'empty_phone', $response->get_data()['code'] );
		$this->assertSame( $before, $this->rate_count() );
	}

	/**
	 * Отсутствие согласия с правилами отклоняется до траты лимита.
	 */
	public function test_missing_terms_does_not_consume_rate_limit() {
		$before = $this->rate_count();

		$response = rest_do_request( $this->register_request( array( 'terms' => false ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'terms_required', $response->get_data()['code'] );
		$this->assertSame( $before, $this->rate_count() );
	}

	/**
	 * Главный сценарий DEV-33: серия заведомо невалидных попыток не
	 * закрывает лимит настоящей регистрации с того же IP.
	 */
	public function test_invalid_attempts_do_not_lock_out_a_valid_registration() {
		for ( $i = 0; $i < IDPG_Auth_Rate_Limiter::REGISTRATION_LIMIT + 2; $i++ ) {
			$response = rest_do_request( $this->register_request( array( 'org_name' => null ) ) );
			$this->assertSame( 400, $response->get_status() );
		}

		$response = rest_do_request( $this->register_request( array( 'email' => 'valid@example.com' ) ) );

		$this->assertSame( 201, $response->get_status() );
	}

	/**
	 * Короткий пароль отклоняется до траты лимита.
	 */
	public function test_short_password_does_not_consume_rate_limit() {
		$before = $this->rate_count();

		$response = rest_do_request( $this->register_request( array( 'password' => 'short' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'short_password', $response->get_data()['code'] );
		$this->assertSame( $before, $this->rate_count() );
	}

	/**
	 * Истёкшее окно сбрасывает счётчик: регистрация снова проходит,
	 * а в счётчике остаётся ровно одна новая попытка.
	 */
	public function test_expired_window_resets_registration_counter() {
		set_transient(
			$this->rate_key(),
			array(
				'count'         => IDPG_Auth_Rate_Limiter::REGISTRATION_LIMIT,
				'first_attempt' => time() - IDPG_Auth_Rate_Limiter::REGISTRATION_WINDOW - 1,
			),
			IDPG_Auth_Rate_Limiter::REGISTRATION_WINDOW
		);

		$response = rest_do_request( $this->register_request( array( 'email' => 'after-window@example.com' ) ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 1, $this->rate_count() );
	}
}
