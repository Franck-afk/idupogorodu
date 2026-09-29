<?php
/**
 * Юнит-тесты DEV-95: валюта организатора — только рубли.
 *
 * Решение владельца: организатор не выбирает валюту. Портал российский,
 * оплата ведётся внешними ссылками, конвертировать нечего. Поле
 * `_idpg_currency` остаётся в модели Carbon для администратора, но
 * организаторский путь его не принимает и не выводит.
 *
 * Что проверяется:
 *  - новое событие организатора сохраняется в рублях;
 *  - переданный через REST `currency` игнорируется (и при создании,
 *    и при правке);
 *  - валюта, выставленная администратором, не затирается правкой
 *    организатора;
 *  - `currency` не объявлен в схеме аргументов маршрута, то есть
 *    отказ — на уровне контракта, а не на уровне проверки в коде;
 *  - форма организатора не содержит поля валюты;
 *  - REST не отдаёт валюту в представлении события.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Organizer_Currency
 */
class Test_Organizer_Currency extends WP_UnitTestCase {

	/**
	 * Маршрут списка и создания.
	 */
	const EVENTS = '/idpg/v1/organizer/events';

	/**
	 * Мета-поле валюты.
	 */
	const META = '_idpg_currency';

	/**
	 * Подготовка окружения.
	 */
	public function set_up() {
		parent::set_up();

		IDPG_REST_Organizer::instance();
		IDPG_Roles::instance()->create_roles();
		IDPG_REST_Organizer::instance()->register_post_status();
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
	 * @param string $method HTTP-метод.
	 * @param string $route  Маршрут.
	 * @param array  $params Параметры.
	 * @return WP_REST_Response
	 */
	protected function request( $method, $route, $params = array() ) {
		$request = new WP_REST_Request( $method, $route );

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
	 * ID события из ответа на создание.
	 *
	 * @param WP_REST_Response $response Ответ.
	 * @return int
	 */
	protected function created_id( $response ) {
		$data = $response->get_data();

		return isset( $data['data']['id'] ) ? (int) $data['data']['id'] : 0;
	}

	// =====================================================================
	// Создание
	// =====================================================================

	/**
	 * Новое событие организатора сохраняется в рублях.
	 */
	public function test_new_organizer_event_saves_rub() {
		$organizer = $this->make_organizer( 'cur_create' );

		wp_set_current_user( $organizer->ID );

		$response = $this->request( 'POST', self::EVENTS, array( 'title' => 'Событие в рублях' ) );

		$this->assertSame( 201, $response->get_status() );

		$event_id = $this->created_id( $response );

		$this->assertGreaterThan( 0, $event_id );
		$this->assertSame( 'RUB', get_post_meta( $event_id, self::META, true ) );
	}

	/**
	 * Валюта, переданная при создании, игнорируется: событие остаётся в рублях.
	 *
	 * Ожидаем именно игнорирование, а не 400: `currency` не входит в схему
	 * аргументов, поэтому WordPress просто не передаёт его в обработчик.
	 * Отвергать запрос было бы сменой контракта без требования задачи.
	 */
	public function test_create_ignores_currency_param() {
		$organizer = $this->make_organizer( 'cur_create_usd' );

		wp_set_current_user( $organizer->ID );

		$response = $this->request(
			'POST',
			self::EVENTS,
			array(
				'title'    => 'Попытка выбрать доллар',
				'currency' => 'USD',
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$event_id = $this->created_id( $response );

		$this->assertSame( 'RUB', get_post_meta( $event_id, self::META, true ) );
	}

	// =====================================================================
	// Правка
	// =====================================================================

	/**
	 * Валюта, переданная при правке, игнорируется.
	 */
	public function test_update_ignores_currency_param() {
		$organizer = $this->make_organizer( 'cur_update_usd' );

		wp_set_current_user( $organizer->ID );

		$created   = $this->request( 'POST', self::EVENTS, array( 'title' => 'Черновик' ) );
		$event_id  = $this->created_id( $created );
		$organizer = get_userdata( $organizer->ID );

		$response = $this->request(
			'PUT',
			self::EVENTS . '/' . $event_id,
			array(
				'title'    => 'Обновлённый черновик',
				'currency' => 'EUR',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'RUB', get_post_meta( $event_id, self::META, true ) );
	}

	/**
	 * Правка организатором не затирает валюту, выставленную администратором.
	 *
	 * Поле остаётся в модели для админа: организатор может отредактировать
	 * описание события с USD и не должен при этом молча переводить цены в
	 * рубли. Организатор при этом и не может её изменить — см. тест выше.
	 */
	public function test_update_does_not_clobber_admin_currency() {
		$organizer = $this->make_organizer( 'cur_admin_usd' );

		wp_set_current_user( $organizer->ID );

		$created  = $this->request( 'POST', self::EVENTS, array( 'title' => 'Импорт' ) );
		$event_id = $this->created_id( $created );

		// Администратор осознанно ставит доллар (например, при импорте).
		update_post_meta( $event_id, self::META, 'USD' );

		$response = $this->request(
			'PUT',
			self::EVENTS . '/' . $event_id,
			array( 'title' => 'Импорт, описание поправили' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'USD', get_post_meta( $event_id, self::META, true ) );
	}

	// =====================================================================
	// Контракт
	// =====================================================================

	/**
	 * `currency` не объявлен в схеме аргументов маршрута.
	 *
	 * Это корень решения: раз параметра нет в контракте, его невозможно
	 * применить ни одной из веток сохранения. Проверяются все обработчики
	 * маршрута (список, создание) — чтобы добавление валюты в любой из них
	 * ломало тест.
	 */
	public function test_currency_not_in_route_args() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::EVENTS, $routes );
		$this->assertNotEmpty( $routes[ self::EVENTS ] );

		$checked = 0;

		foreach ( $routes[ self::EVENTS ] as $handler ) {
			if ( empty( $handler['args'] ) || ! is_array( $handler['args'] ) ) {
				continue;
			}

			$checked++;

			$this->assertArrayNotHasKey(
				'currency',
				$handler['args'],
				'В схеме аргументов маршрута событий не должно быть валюты.'
			);
		}

		// Санитарная проверка: хотя бы один обработчик с аргументами должен
		// был попасть в цикл, иначе тест проходит вхолостую.
		$this->assertGreaterThan( 0, $checked );
	}

	/**
	 * REST не отдаёт валюту в представлении события.
	 *
	 * Организатору показывать нечего: он не выбирает валюту и не должен
	 * видеть поле, которого у него нет в форме.
	 */
	public function test_currency_not_exposed_in_event_payload() {
		$organizer = $this->make_organizer( 'cur_payload' );

		wp_set_current_user( $organizer->ID );

		$created  = $this->request( 'POST', self::EVENTS, array( 'title' => 'Без валюты в ответе' ) );
		$event_id = $this->created_id( $created );

		$response = $this->request( 'GET', self::EVENTS . '/' . $event_id );
		$data     = $response->get_data();

		$this->assertArrayNotHasKey( 'currency', $data['data'] );
		$this->assertArrayNotHasKey( '_idpg_currency', $data['data'] );
	}

	// =====================================================================
	// Форма
	// =====================================================================

	/**
	 * Путь к корню темы.
	 *
	 * @return string
	 */
	protected function theme_path() {
		$dir = getenv( 'IDPG_THEME_DIR' ) ? getenv( 'IDPG_THEME_DIR' ) : __DIR__ . '/../../../themes/idpg_core';

		return rtrim( $dir, '/\\' );
	}

	/**
	 * В шаблоне формы организатора нет ни поля, ни ввода валюты.
	 *
	 * Проверка по исходнику, а не по отрисованной странице: шаблон
	 * читается как текст, поэтому ловится и поле, добавленное позже.
	 */
	public function test_organizer_form_has_no_currency_field() {
		$form = $this->theme_path() . '/page-organizer-event.php';

		$this->assertFileExists( $form );

		$source = file_get_contents( $form );

		$this->assertIsString( $source );

		// Ни одного input/select с валютой: ни по имени поля, ни по ключу
		// мета, ни по подписи.
		$this->assertSame( 0, preg_match( '/name=["\']currency["\']/', $source ) );
		$this->assertSame( 0, preg_match( '/idpg-ev-currency/', $source ) );
		$this->assertSame( 0, preg_match( '/_idpg_currency/', $source ) );
	}

	/**
	 * Скрипт формы не отправляет валюту.
	 *
	 * Даже если поле вернётся в разметку, клиент не должен отправлять
	 * валюту: сервер всё равно её отбросит, а лишний параметр вводил бы
	 * в заблуждение при чтении кода.
	 *
	 * Проверяется не любое слово «currency» в файле, а именно передача
	 * значения: в скрипте есть комментарий, объясняющий, почему валюты
	 * там нет, и он не должен считаться нарушением.
	 */
	public function test_form_script_does_not_send_currency() {
		$script = $this->theme_path() . '/assets/js/organizer-event-form.js';

		$this->assertFileExists( $script );

		$source = file_get_contents( $script );

		$this->assertIsString( $source );

		// Ключ в объекте payload: `currency:` или `currency: `.
		$this->assertSame( 0, preg_match( '/[\'"]?currency[\'"]?\s*:/i', $source ) );

		// Обращение к полю формы по имени.
		$this->assertSame( 0, preg_match( '/name=\\\\?[\'"]currency\\\\?[\'"]/i', $source ) );
	}
}
