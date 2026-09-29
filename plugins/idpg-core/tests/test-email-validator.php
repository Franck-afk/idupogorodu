<?php
/**
 * Юнит-тесты DEV-78: email-валидатор в REST-маршрутах.
 *
 * REST вызывает validate_callback как ( $value, $request, $key ). У is_email()
 * второй аргумент помечен устаревшим с 3.0.0, поэтому строка
 * 'validate_callback' => 'is_email' подставляла объект запроса в $deprecated
 * и давала notice на каждом вызове. Тесты проверяют и форму валидатора,
 * и реальное поведение маршрутов.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Email_Validator
 */
class Test_Email_Validator extends WP_UnitTestCase {

	/**
	 * Маршрут восстановления пароля.
	 */
	const FORGOT = '/idpg/v1/auth/forgot-password';

	/**
	 * Маршрут сброса пароля по ключу.
	 */
	const RESET = '/idpg/v1/auth/reset-password';

	/**
	 * Подготовка окружения: гарантируем зарегистрированные маршруты.
	 */
	public function set_up() {
		parent::set_up();

		// Конструктор вешает register_routes на rest_api_init.
		IDPG_REST_Auth::instance();
	}

	/**
	 * Снимает лимит запросов восстановления пароля для email.
	 *
	 * @param string $email Email, для которого сбрасываем счётчик.
	 */
	private function clear_reset_limit( $email ) {
		delete_transient( 'idpg_reset_attempts_' . md5( $email ) );
	}

	/**
	 * Собирает запрос к маршруту.
	 *
	 * @param string $route  Путь маршрута.
	 * @param array  $params Параметры тела.
	 * @return WP_REST_Request
	 */
	private function request( $route, $params ) {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( $params );

		return $request;
	}

	/* ---------------------------------------------------------------------
	 * Форма валидатора: главный регресс DEV-78.
	 *
	 * Тестовый набор WordPress превращает неожиданный _deprecated_* в
	 * провал теста, поэтому зелёный прогон этих тестов и означает, что
	 * notice не летит. Отдельной проверки на «notice не был» не требуется.
	 * ------------------------------------------------------------------ */

	/**
	 * Ни один маршрут не использует голую строку 'is_email' как валидатор.
	 *
	 * Сквозная проверка: закрывает и текущие места, и будущие правки.
	 */
	public function test_no_route_uses_bare_is_email_validate_callback() {
		$offenders = array();

		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( ! is_array( $handlers ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				if ( empty( $handler['args'] ) || ! is_array( $handler['args'] ) ) {
					continue;
				}

				foreach ( $handler['args'] as $arg_name => $definition ) {
					if ( isset( $definition['validate_callback'] )
						&& 'is_email' === $definition['validate_callback']
					) {
						$offenders[] = $route . ' → ' . $arg_name;
					}
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'Маршруты с validate_callback => is_email: ' . implode( ', ', $offenders )
		);
	}

	/**
	 * Затронутый маршрут проверяет email замыканием.
	 *
	 * Второе вхождение дефекта было в маршруте анкеты организатора
	 * `/auth/organizer-complete`, но с DEV-72 анкеты больше нет, поэтому
	 * остаётся единственный маршрут — forgot-password.
	 */
	public function test_target_routes_use_closure_validator() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::FORGOT, $routes );
		$this->assertInstanceOf(
			'Closure',
			$routes[ self::FORGOT ][0]['args']['email']['validate_callback'],
			self::FORGOT
		);
	}

	/**
	 * Валидатор принимает корректный email и отвергает остальное.
	 *
	 * is_email() возвращает сам адрес при успехе и false при ошибке, а
	 * REST проверяет возвращаемое значение на истинность — поэтому здесь
	 * assertNotEmpty, а не assertTrue.
	 */
	public function test_validator_closure_behaviour() {
		$routes   = rest_get_server()->get_routes();
		$validate = $routes[ self::FORGOT ][0]['args']['email']['validate_callback'];

		$this->assertNotEmpty( $validate( 'user@example.com' ) );
		$this->assertFalse( $validate( 'not-an-email' ) );
		$this->assertFalse( $validate( 'нет@собаки' ) );
		$this->assertFalse( $validate( '' ) );
	}

	/* ---------------------------------------------------------------------
	 * forgot-password.
	 * ------------------------------------------------------------------ */

	/**
	 * Корректный email проходит и не вызывает notice.
	 */
	public function test_forgot_password_accepts_valid_email() {
		$this->clear_reset_limit( 'user@example.com' );

		$response = rest_do_request( $this->request( self::FORGOT, array( 'email' => 'user@example.com' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
	}

	/**
	 * Некорректный email отклоняется валидацией с 400.
	 */
	public function test_forgot_password_rejects_invalid_email() {
		$this->clear_reset_limit( 'broken' );

		$response = rest_do_request( $this->request( self::FORGOT, array( 'email' => 'broken' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/**
	 * Существование email не раскрывается: ответ одинаков для обоих случаев.
	 */
	public function test_forgot_password_does_not_reveal_user_existence() {
		$existing = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'known@example.com',
			)
		);

		$this->clear_reset_limit( 'known@example.com' );
		$this->clear_reset_limit( 'nobody@example.com' );

		$known  = rest_do_request( $this->request( self::FORGOT, array( 'email' => 'known@example.com' ) ) );
		$absent = rest_do_request( $this->request( self::FORGOT, array( 'email' => 'nobody@example.com' ) ) );

		$this->assertSame( 200, $known->get_status() );
		$this->assertSame( 200, $absent->get_status() );
		$this->assertSame( $known->get_data()['message'], $absent->get_data()['message'] );

		unset( $existing );
	}

	/* ---------------------------------------------------------------------
	 * reset-password: email-аргумента у маршрута нет вообще.
	 * ------------------------------------------------------------------ */

	/**
	 * У маршрута сброса пароля нет параметра email — дефекта там не было.
	 */
	public function test_reset_password_has_no_email_argument() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayNotHasKey( 'email', $routes[ self::RESET ][0]['args'] );
	}

	/**
	 * Сброс пароля по верному ключу работает.
	 */
	public function test_reset_password_accepts_valid_key() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );
		$key     = get_password_reset_key( $user );

		$response = rest_do_request(
			$this->request(
				self::RESET,
				array(
					'user_id'      => $user_id,
					'reset_key'    => $key,
					'new_password' => 'novyparol123',
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertTrue(
			wp_check_password( 'novyparol123', get_user_by( 'id', $user_id )->user_pass, $user_id )
		);
	}

	/**
	 * Неверный ключ сброса отклоняется.
	 */
	public function test_reset_password_rejects_invalid_key() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$response = rest_do_request(
			$this->request(
				self::RESET,
				array(
					'user_id'      => $user_id,
					'reset_key'    => 'wrong-key',
					'new_password' => 'novyparol123',
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_key', $response->get_data()['code'] );
	}

	/**
	 * Короткий пароль отклоняется до проверки ключа.
	 */
	public function test_reset_password_rejects_short_password() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_user_by( 'id', $user_id );

		$response = rest_do_request(
			$this->request(
				self::RESET,
				array(
					'user_id'      => $user_id,
					'reset_key'    => get_password_reset_key( $user ),
					'new_password' => 'koro',
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'short_password', $response->get_data()['code'] );
	}
}
