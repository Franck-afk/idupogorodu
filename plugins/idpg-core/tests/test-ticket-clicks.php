<?php
/**
 * Юнит-тесты DEV-17: учёт кликов по билетным ссылкам.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Ticket_Clicks
 */
class Test_Ticket_Clicks extends WP_UnitTestCase {

	/**
	 * Маршрут учёта клика.
	 */
	const ROUTE = '/idpg/v1/track/click';

	/**
	 * Подготовка: гарантируем зарегистрированные маршруты.
	 */
	public function set_up() {
		parent::set_up();

		// Конструктор вешает register_routes на rest_api_init.
		IDPG_REST_API::instance();
	}

	/**
	 * Создаёт опубликованное событие.
	 *
	 * @return int
	 */
	private function make_event() {
		return self::factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Запрос с валидным nonce.
	 *
	 * @param int $event_id ID события.
	 * @return WP_REST_Request
	 */
	private function click_request( $event_id ) {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'event_id', $event_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	/**
	 * Маршрут /track/click зарегистрирован.
	 */
	public function test_route_registered() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::ROUTE, $routes );
	}

	/**
	 * У маршрута есть permission_callback и обязательный event_id.
	 */
	public function test_route_arguments_declared() {
		$routes = rest_get_server()->get_routes();
		$route  = $routes[ self::ROUTE ][0];

		$this->assertArrayHasKey( 'permission_callback', $route );
		$this->assertNotSame( '__return_true', $route['permission_callback'] );
		$this->assertTrue( $route['args']['event_id']['required'] );
		$this->assertSame( 'absint', $route['args']['event_id']['sanitize_callback'] );
	}

	/**
	 * Без nonce запрос отклоняется — счётчик защищён от чужих вызовов.
	 */
	public function test_request_without_nonce_is_rejected() {
		$event_id = $this->make_event();

		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'event_id', $event_id );

		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 0, IDPG_Views::get_clicks( $event_id ) );
	}

	/**
	 * Клик увеличивает счётчик ровно на единицу.
	 */
	public function test_click_increments_counter() {
		$event_id = $this->make_event();

		$response = rest_do_request( $this->click_request( $event_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, IDPG_Views::get_clicks( $event_id ) );
		$this->assertSame( 1, $response->get_data()['clicks'] );
	}

	/**
	 * Несколько кликов накапливаются.
	 */
	public function test_repeated_clicks_accumulate() {
		$event_id = $this->make_event();

		for ( $i = 0; $i < 3; $i++ ) {
			rest_do_request( $this->click_request( $event_id ) );
		}

		$this->assertSame( 3, IDPG_Views::get_clicks( $event_id ) );
	}

	/**
	 * Клики одного события не задевают другое.
	 */
	public function test_clicks_are_per_event() {
		$first  = $this->make_event();
		$second = $this->make_event();

		rest_do_request( $this->click_request( $first ) );
		rest_do_request( $this->click_request( $first ) );
		rest_do_request( $this->click_request( $second ) );

		$this->assertSame( 2, IDPG_Views::get_clicks( $first ) );
		$this->assertSame( 1, IDPG_Views::get_clicks( $second ) );
	}

	/**
	 * Несуществующее событие даёт 404 и не создаёт счётчик.
	 */
	public function test_unknown_event_returns_404() {
		$response = rest_do_request( $this->click_request( 999999 ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'idpg_invalid_event', $response->get_data()['code'] );
	}

	/**
	 * Неопубликованное событие недоступно для учёта.
	 */
	public function test_draft_event_is_rejected() {
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'draft',
			)
		);

		$response = rest_do_request( $this->click_request( $event_id ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 0, IDPG_Views::get_clicks( $event_id ) );
	}

	/**
	 * Запись не-события (другая CPT) отклоняется.
	 */
	public function test_non_event_post_is_rejected() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$response = rest_do_request( $this->click_request( $post_id ) );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Скрипт учёта кликов подключается на фронте.
	 */
	public function test_click_script_registered() {
		$this->go_to( get_permalink( $this->make_event() ) );

		IDPG_Views::instance()->register_click_assets();

		$this->assertTrue( wp_script_is( 'idpg-ticket-clicks', 'enqueued' ) );
	}

	/**
	 * Скрипт передаёт билетные ссылки и REST-nonce на фронт.
	 */
	public function test_click_script_gets_rest_nonce() {
		$this->go_to( get_permalink( $this->make_event() ) );

		IDPG_Views::instance()->register_click_assets();

		$data = wp_scripts()->get_data( 'idpg-ticket-clicks', 'data' );

		$this->assertStringContainsString( 'idpgTicketClicks', $data );
		$this->assertStringContainsString( '/idpg/v1', $data );
	}

	/**
	 * В JS учёт клика не отменяет переход по ссылке.
	 */
	public function test_click_js_does_not_block_navigation() {
		$js_path = IDPG_CORE_DIR . 'assets/js/ticket-clicks.js';

		$this->assertFileExists( $js_path );

		$js = file_get_contents( $js_path );

		// preventDefault в обработчике клика означал бы сломанную покупку билета.
		$this->assertStringNotContainsString( 'preventDefault', $js );
		$this->assertStringContainsString( 'track/click', $js );
		$this->assertStringContainsString( 'data-event-id', $js );
	}

	/**
	 * Кнопка билета несёт data-event-id, на который вешается учёт.
	 */
	public function test_ticket_button_carries_event_id() {
		$event_id = $this->make_event();

		update_post_meta( $event_id, '_idpg_ticket_url', 'https://tickets.example.com/e/1' );
		wp_cache_flush();

		$html = IDPG_Template_Helper::get_ticket_button( $event_id );

		$this->assertStringContainsString( 'data-event-id="' . $event_id . '"', $html );
	}

	/**
	 * Регресс: маршрут учёта клика принимает nonce из URL, а не только из
	 * заголовка. navigator.sendBeacon() заголовки ставить не умеет, поэтому
	 * без этого весь beacon-трафик получал бы 403 и терялся.
	 */
	public function test_click_route_accepts_nonce_from_query() {
		$event_id = $this->make_event();

		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'event_id', $event_id );
		$request->set_param( '_wpnonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Тот же маршрут продолжает принимать заголовок X-WP-Nonce (fetch-путь).
	 */
	public function test_click_route_accepts_nonce_from_header() {
		$event_id = $this->make_event();

		$response = rest_get_server()->dispatch( $this->click_request( $event_id ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Пустой или чужой nonce в URL не проходит.
	 */
	public function test_click_route_rejects_bad_query_nonce() {
		$event_id = $this->make_event();

		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'event_id', $event_id );
		$request->set_param( '_wpnonce', 'невалидный' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 0, IDPG_Views::get_clicks( $event_id ) );
	}

	/**
	 * У маршрута свой permission_callback, а не общий городской.
	 */
	public function test_click_route_uses_its_own_permission_callback() {
		$routes = rest_get_server()->get_routes();

		$this->assertSame(
			array( IDPG_REST_API::instance(), 'can_track_click' ),
			$routes[ self::ROUTE ][0]['permission_callback']
		);
	}

	/**
	 * В JS основной путь — fetch с keepalive, а beacon несёт nonce в URL.
	 */
	public function test_click_js_passes_nonce_to_beacon_url() {
		$js = file_get_contents( IDPG_CORE_DIR . 'assets/js/ticket-clicks.js' );

		$this->assertStringContainsString( 'keepalive: true', $js, 'fetch должен переживать уход со страницы.' );
		$this->assertStringContainsString( 'X-WP-Nonce', $js, 'fetch-путь передаёт nonce заголовком.' );
		$this->assertStringContainsString(
			'_wpnonce=',
			$js,
			'beacon-путь обязан передавать nonce в URL.'
		);
		$this->assertStringContainsString(
			'encodeURIComponent(config.restNonce)',
			$js
		);
	}
}
