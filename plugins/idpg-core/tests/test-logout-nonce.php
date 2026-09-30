<?php
/**
 * Тесты проверки nonce на маршруте выхода из аккаунта (DEV-149).
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Logout_Nonce
 */
class Test_Logout_Nonce extends WP_UnitTestCase {

	/**
	 * Маршрут выхода.
	 *
	 * @var string
	 */
	const ROUTE = '/idpg/v1/auth/logout';

	/**
	 * Авторизованный пользователь для проверок.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Хук редиректа после выхода, который нужно временно снять.
	 *
	 * `IDPG_Auth::redirect_after_logout()` заканчивается `exit`, поэтому
	 * внутри теста он обрывает процесс. Константу `REST_REQUEST` вместо
	 * этого определять нельзя: её читают 45 мест в плагине, и глобальная
	 * установка изменила бы поведение остальных тестов набора.
	 *
	 * @var callable|null
	 */
	protected $logout_hook;

	/**
	 * Подготовка: пользователь авторизован, редирект после выхода снят.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->user_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->logout_hook = array( IDPG_Auth::instance(), 'redirect_after_logout' );

		remove_action( 'wp_logout', $this->logout_hook );
	}

	/**
	 * Очистка: хук возвращается на место.
	 */
	public function tearDown(): void {
		if ( $this->logout_hook ) {
			add_action( 'wp_logout', $this->logout_hook );
			$this->logout_hook = null;
		}

		parent::tearDown();
	}

	/**
	 * Диспатч запроса выхода.
	 *
	 * @param string|bool|null $nonce    Значение nonce, true — создать валидный
	 *                                   уже после авторизации, null — не передавать.
	 * @param bool             $as_param true — передать в `_wpnonce`, false — в заголовке.
	 * @param int|null         $user_id  Пользователь для авторизации; null — тестовый.
	 * @return WP_REST_Response
	 */
	protected function dispatch_logout( $nonce = null, $as_param = false, $user_id = null ) {
		wp_set_current_user( null === $user_id ? $this->user_id : $user_id );

		if ( true === $nonce ) {
			// Токен привязан к текущему пользователю и его сессии, поэтому
			// создаётся строго после `wp_set_current_user()` выше.
			$nonce = wp_create_nonce( IDPG_REST_Auth::REST_NONCE_ACTION );
		}

		$request = new WP_REST_Request( 'POST', self::ROUTE );

		if ( null !== $nonce ) {
			if ( $as_param ) {
				$request->set_param( '_wpnonce', $nonce );
			} else {
				$request->set_header( 'X-WP-Nonce', $nonce );
			}
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Без nonce выход отклоняется, сессия сохраняется.
	 *
	 * Главный сценарий DEV-149: запрос без токена не должен разлогинивать
	 * пользователя.
	 */
	public function test_no_nonce_is_rejected() {
		$response = $this->dispatch_logout();

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'idpg_logout_nonce_missing', $response->get_data()['code'] );
		$this->assertTrue( is_user_logged_in(), 'Пользователь не должен выходить из системы без nonce.' );
	}

	/**
	 * Неверный nonce отклоняется, сессия сохраняется.
	 */
	public function test_invalid_nonce_is_rejected() {
		$response = $this->dispatch_logout( 'deadbeef' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'idpg_logout_nonce_invalid', $response->get_data()['code'] );
		$this->assertTrue( is_user_logged_in(), 'Пользователь не должен выходить из системы с неверным nonce.' );
	}

	/**
	 * Валидный nonce в заголовке разлогинивает пользователя.
	 */
	public function test_valid_nonce_logs_out() {
		$response = $this->dispatch_logout( true );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertSame( home_url( '/' ), $data['redirect_url'] );
		$this->assertFalse( is_user_logged_in(), 'Валидный nonce должен разлогинивать пользователя.' );
	}

	/**
	 * Nonce в параметре `_wpnonce` принимается наравне с заголовком.
	 */
	public function test_nonce_as_param_is_accepted() {
		$response = $this->dispatch_logout( true, true );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( is_user_logged_in() );
	}

	/**
	 * Гость с валидным nonce получает 401, а не выход из системы.
	 */
	public function test_guest_is_rejected() {
		$response = $this->dispatch_logout( true, false, 0 );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'idpg_logout_not_logged_in', $response->get_data()['code'] );
	}

	/**
	 * Маршрут остаётся POST-only: GET не вызывает выход.
	 *
	 * Защита от подделки запроса через `<img src=".../auth/logout">` держится
	 * в том числе на методе маршрута.
	 */
	public function test_route_is_not_gettable() {
		$this->dispatch_logout( true );

		wp_set_current_user( $this->user_id );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertTrue( is_user_logged_in(), 'GET-запрос не должен разлогинивать пользователя.' );
	}
}
