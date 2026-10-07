<?php
/**
 * Юнит-тесты DEV-19: подтверждение города, определённого по IP.
 *
 * Суть карточки: раньше cookie idpg_selected_city ставилась и автоопределением
 * по IP, и ручным выбором, поэтому определённый по IP город нельзя было
 * отличить от выбранного. Теперь факт выбора хранится отдельной меткой
 * idpg_city_confirmed.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_City_Confirmation
 */
class Test_City_Confirmation extends WP_UnitTestCase {

	/**
	 * Маршрут подтверждения.
	 */
	const ROUTE = '/idpg/v1/city/confirm';

	/**
	 * Маршрут выбора города.
	 */
	const SELECT_ROUTE = '/idpg/v1/city/select';

	/**
	 * Бэкап глобального состояния запроса.
	 *
	 * @var array
	 */
	private $server_backup = array();

	/**
	 * Бэкап cookie.
	 *
	 * @var array
	 */
	private $cookie_backup = array();

	/**
	 * Подготовка: маршруты REST и чистые cookie.
	 */
	public function set_up() {
		parent::set_up();

		// Тесты меняют $_SERVER/$_COOKIE; возвращаем их как было — иначе
		// последующие тесты (например, сброс пароля) получат неполный запрос.
		$this->server_backup = $_SERVER;
		$this->cookie_backup = $_COOKIE;

		unset(
			$_COOKIE[ IDPG_City_Filter::COOKIE_NAME ],
			$_COOKIE[ IDPG_City_Filter::COOKIE_CONFIRMED ],
			$_GET['idpg_confirm_city'],
			$_GET['_wpnonce']
		);

		$this->reset_detected_flag();

		// Счётчик экземпляров блока подтверждения живёт в статике — сбрасываем,
		// иначе тесты получали бы id с суффиксами предыдущих тестов.
		IDPG_City_Selector_Widget::reset_confirmation_bars();

		// Конструктор вешает register_routes на rest_api_init.
		IDPG_REST_API::instance();
	}

	/**
	 * Восстановление глобального состояния запроса.
	 */
	public function tear_down() {
		$_SERVER = $this->server_backup;
		$_COOKIE = $this->cookie_backup;

		$this->reset_detected_flag();

		parent::tear_down();
	}

	/**
	 * Сброс приватной статической метки «город определён по IP».
	 *
	 * Статика живёт между тестами, а флаг выставляется только реальным
	 * автоопределением — здесь готовим состояние явно.
	 *
	 * @param bool $value Новое значение метки.
	 */
	private function reset_detected_flag( $value = false ) {
		$property = new ReflectionProperty( 'IDPG_City_Filter', 'detected_from_ip' );
		$property->setAccessible( true );
		$property->setValue( null, $value );
	}

	/**
	 * Каталог темы — для проверок её JS (как в test-theme-version.php).
	 *
	 * @return string
	 */
	private function theme_dir() {
		$dir = getenv( 'IDPG_THEME_DIR' );

		return $dir ? $dir : dirname( dirname( dirname( __DIR__ ) ) ) . '/themes/idpg_core';
	}

	/**
	 * Создаёт город в таксономии city.
	 *
	 * @param string $name Название города.
	 * @return int
	 */
	private function make_city( $name = 'Ижевск' ) {
		return self::factory()->term->create(
			array(
				'taxonomy' => 'city',
				'name'     => $name,
			)
		);
	}

	/**
	 * Имитирует cookie, которую прислал браузер с автоопределённым городом.
	 *
	 * Именно так состояние выглядит на следующем запросе: city-cookie есть,
	 * метки подтверждения — нет. Сам set_city_cookie() в тестах не пишет
	 * $_COOKIE: он целиком внутри проверки headers_sent(), а PHPUnit к этому
	 * моменту уже вывел служебный вывод, поэтому setcookie() невозможен.
	 *
	 * @param int $city_id Term ID.
	 */
	private function given_ip_detected_city( $city_id ) {
		$_COOKIE[ IDPG_City_Filter::COOKIE_NAME ] = (string) $city_id;
	}

	/**
	 * Запрос подтверждения с валидным nonce.
	 *
	 * @return WP_REST_Request
	 */
	private function confirm_request() {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	/**
	 * Cookie подтверждения выставляется и читается.
	 */
	public function test_mark_city_confirmed_sets_cookie() {
		$this->assertFalse( IDPG_City_Filter::is_city_confirmed() );

		IDPG_City_Filter::mark_city_confirmed();

		$this->assertTrue( IDPG_City_Filter::is_city_confirmed() );
		$this->assertSame(
			IDPG_City_Filter::CONFIRMED_VALUE,
			$_COOKIE[ IDPG_City_Filter::COOKIE_CONFIRMED ]
		);
	}

	/**
	 * Регрессия DEV-19: город, пришедший из IP-определения, не считается
	 * выбором посетителя — метки подтверждения нет.
	 */
	public function test_ip_detected_city_is_not_confirmed() {
		$city_id = $this->make_city();

		$this->given_ip_detected_city( $city_id );

		$this->assertSame(
			(string) $city_id,
			(string) $_COOKIE[ IDPG_City_Filter::COOKIE_NAME ]
		);
		$this->assertFalse(
			IDPG_City_Filter::is_city_confirmed(),
			'IP-определение не должно выглядеть как выбор посетителя.'
		);
	}

	/**
	 * Ручной выбор подтверждает город.
	 */
	public function test_chosen_city_is_confirmed() {
		$city_id = $this->make_city( 'Казань' );

		// City-cookie PHPUnit выставить не даёт (см. given_ip_detected_city),
		// поэтому проверяем ту часть set_chosen_city(), что видна в тестах.
		IDPG_City_Filter::set_chosen_city( $city_id );

		$this->assertTrue(
			IDPG_City_Filter::is_city_confirmed(),
			'Ручной выбор обязан подтверждать город.'
		);
	}

	/**
	 * Диалог предлагается только для неподтверждённого IP-города.
	 */
	public function test_needs_confirmation_only_for_unconfirmed_ip_city() {
		$city_id = $this->make_city();
		$this->given_ip_detected_city( $city_id );

		// Город не из IP (например, пришёл из ссылки ?city=) — диалога нет.
		$this->assertFalse( IDPG_City_Filter::needs_city_confirmation() );

		// Тот же город «из IP» и ещё не подтверждён — диалог нужен.
		$this->reset_detected_flag( true );
		$this->assertTrue( IDPG_City_Filter::needs_city_confirmation() );

		// Подтвердили — диалог исчезает.
		IDPG_City_Filter::mark_city_confirmed();
		$this->assertFalse( IDPG_City_Filter::needs_city_confirmation() );
	}

	/**
	 * Без подтверждения диалог не показывается, даже если город есть.
	 */
	public function test_no_confirmation_without_ip_detection() {
		$this->given_ip_detected_city( $this->make_city() );

		$this->assertFalse( IDPG_City_Filter::needs_city_confirmation() );
	}

	/**
	 * Готовит окружение обычного посетителя: публичный IP и браузерный UA.
	 *
	 * Без этого auto_detect_city() выходит ещё до выборки города
	 * (проверка UA и валидности IP), и тест ничего не проверяет.
	 */
	private function given_browser_request() {
		$_SERVER['REMOTE_ADDR']      = '203.0.113.10';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
	}

	/**
	 * Кладёт в кеш «город для IP определён успешно».
	 *
	 * @param string $ip      IP.
	 * @param int    $city_id Term ID.
	 */
	private function given_cached_ip_detection( $ip, $city_id ) {
		set_transient(
			'idpg_city_ip_' . md5( $ip ),
			array(
				'status'  => 'ok',
				'term_id' => (int) $city_id,
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * Подтверждённый город автоопределение больше не трогает.
	 */
	public function test_auto_detect_skips_confirmed_city() {
		$city_id = $this->make_city();
		$this->given_browser_request();
		$this->given_cached_ip_detection( $_SERVER['REMOTE_ADDR'], $city_id );
		$this->given_ip_detected_city( $city_id );
		IDPG_City_Filter::mark_city_confirmed();

		IDPG_City_Filter::instance()->auto_detect_city();

		$this->assertFalse(
			IDPG_City_Filter::was_detected_from_ip(),
			'Подтверждённый город не должен переопределяться по IP.'
		);
	}

	/**
	 * Регресс: подтверждение без city-cookie тоже останавливает автоопределение.
	 *
	 * Именно этот случай отличает проверку is_city_confirmed() от проверки
	 * isset( $_COOKIE[COOKIE_NAME] ): посетитель подтвердил выбор, city-cookie
	 * могла протухнуть или быть удалена — иначе сайт молча вернулся бы к
	 * IP-угадайке и снова показал диалог.
	 */
	public function test_auto_detect_skips_when_confirmed_without_city_cookie() {
		$this->given_browser_request();
		$this->given_cached_ip_detection( $_SERVER['REMOTE_ADDR'], $this->make_city() );
		IDPG_City_Filter::mark_city_confirmed();

		IDPG_City_Filter::instance()->auto_detect_city();

		$this->assertFalse( IDPG_City_Filter::was_detected_from_ip() );
		$this->assertArrayNotHasKey( IDPG_City_Filter::COOKIE_NAME, $_COOKIE );
	}

	/**
	 * Контроль к тестам выше: без подтверждения тот же сценарий определяет город.
	 */
	public function test_auto_detect_applies_ip_city_when_unconfirmed() {
		$this->given_browser_request();
		$this->given_cached_ip_detection( $_SERVER['REMOTE_ADDR'], $this->make_city() );

		IDPG_City_Filter::instance()->auto_detect_city();

		$this->assertTrue( IDPG_City_Filter::was_detected_from_ip() );
	}

	/**
	 * Ссылка «Да, мой город» без JavaScript: подтверждение по GET с nonce.
	 */
	public function test_confirm_link_with_valid_nonce() {
		$this->given_ip_detected_city( $this->make_city() );

		$_GET['idpg_confirm_city'] = '1';
		$_GET['_wpnonce']         = wp_create_nonce( IDPG_City_Filter::CONFIRM_ACTION );

		IDPG_City_Filter::instance()->maybe_confirm_city_from_link();

		$this->assertTrue( IDPG_City_Filter::is_city_confirmed() );
	}

	/**
	 * Ссылка с чужим или пустым nonce ничего не подтверждает.
	 */
	public function test_confirm_link_with_bad_nonce_is_ignored() {
		$this->given_ip_detected_city( $this->make_city() );

		$_GET['idpg_confirm_city'] = '1';
		$_GET['_wpnonce']         = 'невалидный';

		IDPG_City_Filter::instance()->maybe_confirm_city_from_link();

		$this->assertFalse( IDPG_City_Filter::is_city_confirmed() );
	}

	/**
	 * Подтверждать нечего, если города нет в cookie.
	 */
	public function test_confirm_link_without_city_is_ignored() {
		$_GET['idpg_confirm_city'] = '1';
		$_GET['_wpnonce']         = wp_create_nonce( IDPG_City_Filter::CONFIRM_ACTION );

		IDPG_City_Filter::instance()->maybe_confirm_city_from_link();

		$this->assertFalse( IDPG_City_Filter::is_city_confirmed() );
	}

	/**
	 * Разметка блока подтверждения содержит вопрос и обе кнопки.
	 */
	public function test_confirmation_bar_markup() {
		$this->given_ip_detected_city( $this->make_city() );
		$this->reset_detected_flag( true );

		$html = IDPG_City_Selector_Widget::render_confirmation_bar( 'modal' );

		$this->assertStringContainsString( 'idpg-city-confirm', $html );
		$this->assertStringContainsString( 'Ижевск', $html );
		$this->assertStringContainsString( 'idpg-city-confirm-yes', $html );
		$this->assertStringContainsString( 'idpg-city-confirm-other', $html );
		$this->assertStringContainsString( 'data-idpg-city-open="modal"', $html );
		$this->assertStringContainsString(
			'idpg_confirm_city',
			$html,
			'Ссылка подтверждения должна нести nonce-параметр.'
		);
		$this->assertStringContainsString( '_wpnonce', $html );
	}

	/**
	 * Блок выводится в трёх местах страницы (модалка шапки, панель шапки,
	 * виджет), поэтому id у экземпляров должны различаться — иначе в DOM
	 * появляются дубли, а обработчики цепляются только к первому блоку.
	 */
	public function test_confirmation_bar_ids_are_unique() {
		$this->given_ip_detected_city( $this->make_city() );
		$this->reset_detected_flag( true );

		$html = IDPG_City_Selector_Widget::render_confirmation_bar( 'modal' )
			. IDPG_City_Selector_Widget::render_confirmation_bar( 'panel' )
			. IDPG_City_Selector_Widget::render_confirmation_bar( 'panel' );

		preg_match_all( '/\sid="([^"]+)"/', $html, $matches );
		$ids = $matches[1];

		$this->assertCount( 9, $ids, 'Три блока × три id (блок, «Да», «Другой»).' );
		$this->assertSame(
			array_unique( $ids ),
			$ids,
			'Все id блока подтверждения должны быть уникальны на странице.'
		);

		// Первый экземпляр сохраняет базовые id — на них может опираться
		// разметка, добавленная сторонним плагином.
		$this->assertStringContainsString( 'id="idpg-city-confirm"', $html );
		$this->assertStringContainsString( 'id="idpg-city-confirm-yes"', $html );
		$this->assertStringContainsString( 'id="idpg-city-confirm-other"', $html );
		$this->assertStringContainsString( 'id="idpg-city-confirm-2"', $html );
		$this->assertStringContainsString( 'id="idpg-city-confirm-3"', $html );
	}

	/**
	 * Navigation.js вешает обработчики по классу: иначе клик по кнопке
	 * во втором и третьем блоке ничего не делал бы.
	 */
	public function test_navigation_js_binds_all_confirmation_bars_by_class() {
		$js = (string) file_get_contents( $this->theme_dir() . '/assets/js/navigation.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringContainsString(
			"document.querySelectorAll('.idpg-city-confirm')",
			$js,
			'Обработчики подтверждения должны вешаться на все экземпляры по классу.'
		);
		$this->assertStringNotContainsString(
			"getElementById('idpg-city-confirm-yes')",
			$js,
			'Обращение к блоку по фиксированному id ловит только первый экземпляр.'
		);
	}

	/**
	 * Подтверждённому посетителю блок не выводится.
	 */
	public function test_confirmation_bar_empty_when_confirmed() {
		$this->given_ip_detected_city( $this->make_city() );
		$this->reset_detected_flag( true );
		IDPG_City_Filter::mark_city_confirmed();

		$this->assertSame( '', IDPG_City_Selector_Widget::render_confirmation_bar( 'panel' ) );
	}

	/**
	 * Блок не рендерится без IP-определения.
	 */
	public function test_confirmation_bar_empty_without_ip_detection() {
		$this->assertSame( '', IDPG_City_Selector_Widget::render_confirmation_bar( 'panel' ) );
	}

	/**
	 * REST-маршрут подтверждения зарегистрирован и защищён nonce.
	 */
	public function test_confirm_route_registered() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::ROUTE, $routes );
		$this->assertNotSame( '__return_true', $routes[ self::ROUTE ][0]['permission_callback'] );
	}

	/**
	 * Без nonce REST-подтверждение отклоняется.
	 */
	public function test_confirm_route_requires_nonce() {
		$this->given_ip_detected_city( $this->make_city() );

		$request  = new WP_REST_Request( 'POST', self::ROUTE );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertFalse( IDPG_City_Filter::is_city_confirmed() );
	}

	/**
	 * REST-подтверждение с валидным nonce ставит метку.
	 */
	public function test_confirm_route_with_nonce_succeeds() {
		$city_id = $this->make_city();
		$this->given_ip_detected_city( $city_id );

		$response = rest_get_server()->dispatch( $this->confirm_request() );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['confirmed'] );
		$this->assertSame( $city_id, $data['city_id'] );
		$this->assertTrue( IDPG_City_Filter::is_city_confirmed() );
	}

	/**
	 * REST-подтверждение без города отвечает 400, а не молча ставит метку.
	 */
	public function test_confirm_route_without_city_returns_error() {
		$response = rest_get_server()->dispatch( $this->confirm_request() );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'idpg_rest_no_city_to_confirm', $data['code'] );
		$this->assertFalse( IDPG_City_Filter::is_city_confirmed() );
	}

	/**
	 * REST-выбор города подтверждает выбор (важно для city-selector.js).
	 */
	public function test_select_route_marks_city_confirmed() {
		$city_id = $this->make_city( 'Сочи' );

		$request = new WP_REST_Request( 'POST', self::SELECT_ROUTE );
		$request->set_param( 'city_id', $city_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['confirmed'] );
		$this->assertTrue( IDPG_City_Filter::is_city_confirmed() );
	}

	/**
	 * Регресс: маршрут выбора по-прежнему требует nonce.
	 */
	public function test_select_route_still_requires_nonce() {
		$city_id = $this->make_city();

		$request = new WP_REST_Request( 'POST', self::SELECT_ROUTE );
		$request->set_param( 'city_id', $city_id );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}
}
