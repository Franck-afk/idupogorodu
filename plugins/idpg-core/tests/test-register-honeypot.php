<?php
/**
 * Юнит-тесты DEV-150: honeypot в регистрации организатора.
 *
 * Контекст. DEV-05 была помечена выполненной фиктивно: поля `idpg_company`
 * не было ни в схеме маршрута, ни в обработчике, ни в форме, а
 * `git log --all -S 'HONEYPOT_FIELD'` не давал ни одного коммита. Маршрут
 * `/auth/register` при этом публичный (`permission_callback =>
 * '__return_true'`), поэтому заполнить его мог кто угодно без nonce.
 *
 * Honeypot — приманка, а не стена: проверяется, что бот, заполнивший
 * скрытое поле, не создаёт аккаунт и не расходует лимит регистрации,
 * а живой человек по-прежнему регистрируется.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Register_Honeypot
 */
class Test_Register_Honeypot extends WP_UnitTestCase {

	/**
	 * Маршрут регистрации.
	 */
	const ROUTE = '/idpg/v1/auth/register';

	/**
	 * Email бота из проб.
	 */
	const BOT_EMAIL = 'bot@example.com';

	/**
	 * Email живого пользователя из проб.
	 */
	const HUMAN_EMAIL = 'human@example.com';

	/**
	 * Подготовка окружения: маршруты зарегистрированы, счётчик регистраций
	 * с одного IP чист. Лимит — 3 в час, иначе проверки упираются в 429
	 * раньше, чем дойдут до honeypot.
	 */
	public function set_up() {
		parent::set_up();

		IDPG_REST_Auth::instance();

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
	 * Валидный запрос регистрации организатора.
	 *
	 * @param array $overrides Поля для замены; null — убрать параметр.
	 * @return WP_REST_Request
	 */
	private function register_request( $overrides = array() ) {
		$params = array_merge(
			array(
				'type'             => 'organizer',
				'name'             => 'Иван Иванов',
				'org_name'         => 'Организация «Тест»',
				'contact_name'     => 'Иван Иванов',
				'email'            => self::HUMAN_EMAIL,
				'phone'            => '+7 999 000-00-00',
				'password'         => 'parol12345',
				'terms'            => true,
				'idpg_pdn_consent' => true,
			),
			$overrides
		);

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
	 * Диспатч запроса.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response
	 */
	private function dispatch( $request ) {
		return rest_get_server()->dispatch( $request );
	}

	/* ---------------------------------------------------------------------
	 * Приёмка DEV-150
	 * ------------------------------------------------------------------ */

	/**
	 * Главный сценарий: бот с заполненным honeypot не создаёт пользователя.
	 *
	 * Проверяется и то, что ответ неотличим от успешной регистрации: код
	 * 201, `success` и `pending_email` — как у настоящего ответа. Иначе бот
	 * отличил бы приманку по коду и просто перестроил форму.
	 */
	public function test_honeypot_filled_does_not_create_user() {
		$response = $this->dispatch(
			$this->register_request(
				array(
					'email'        => self::BOT_EMAIL,
					'idpg_company' => 'Acme Bot LLC',
				)
			)
		);

		$data = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertTrue( $data['pending_email'], 'Ответ приманки должен выглядеть как успешная регистрация.' );
		$this->assertFalse(
			get_user_by( 'email', self::BOT_EMAIL ),
			'Бот с заполненным honeypot создал пользователя.'
		);
	}

	/**
	 * Живой человек (пустой honeypot) регистрируется как обычно.
	 */
	public function test_honeypot_empty_registers_normally() {
		$response = $this->dispatch(
			$this->register_request(
				array(
					'email'        => self::HUMAN_EMAIL,
					'idpg_company' => '',
				)
			)
		);

		$data = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertNotEmpty( $data['user_id'] );
		$this->assertNotSame(
			0,
			$data['user_id'],
			'Настоящая регистрация обязана вернуть ненулевой user_id — по нему отличается приманка.'
		);
		$this->assertNotFalse(
			get_user_by( 'email', self::HUMAN_EMAIL ),
			'Обычная регистрация с пустым honeypot должна работать.'
		);
	}

	/**
	 * Поле можно не передавать вовсе — старый клиент без него работает.
	 */
	public function test_honeypot_absent_registers_normally() {
		$response = $this->dispatch(
			$this->register_request(
				array(
					'email'        => self::HUMAN_EMAIL,
					'idpg_company' => null,
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertNotFalse( get_user_by( 'email', self::HUMAN_EMAIL ) );
	}

	/* ---------------------------------------------------------------------
	 * Порядок проверок и устойчивость
	 * ------------------------------------------------------------------ */

	/**
	 * Honeypot проверяется раньше остальных правил.
	 *
	 * Иначе бот с приманкой получал бы в ответ ошибки валидации
	 * (пустой org_name, нет согласия) и по ним изучал форму.
	 */
	public function test_honeypot_wins_over_field_validation() {
		$response = $this->dispatch(
			$this->register_request(
				array(
					'email'        => self::BOT_EMAIL,
					'org_name'     => null,
					'terms'        => null,
					'idpg_pdn_consent' => null,
					'idpg_company' => 'Acme Bot LLC',
				)
			)
		);

		$this->assertSame(
			201,
			$response->get_status(),
			'Ошибки валидации раньше honeypot выдают боту правила формы.'
		);
		$this->assertFalse( get_user_by( 'email', self::BOT_EMAIL ) );
	}

	/**
	 * Приманка не расходует лимит регистрации.
	 *
	 * Лимит — 3 в час с IP. Если бы honeypot стоял после проверки лимита,
	 * бот исчерпал бы его, и настоящий человек с того же адреса получил бы
	 * 429 вместо регистрации.
	 */
	public function test_honeypot_does_not_consume_rate_limit() {
		for ( $i = 0; $i < IDPG_Auth_Rate_Limiter::REGISTRATION_LIMIT + 2; $i++ ) {
			$response = $this->dispatch(
				$this->register_request(
					array(
						'email'        => 'bot' . $i . '@example.com',
						'idpg_company' => 'Acme Bot LLC',
					)
				)
			);

			$this->assertSame( 201, $response->get_status() );
		}

		$response = $this->dispatch( $this->register_request() );

		$this->assertSame(
			201,
			$response->get_status(),
			'Живой человек с того же IP получил отказ: honeypot расходует лимит.'
		);
		$this->assertNotFalse( get_user_by( 'email', self::HUMAN_EMAIL ) );
	}

	/**
	 * Массив вместо строки не обходит приманку.
	 *
	 * Регрессия DEV-150: со `sanitize_text_field` в схеме массив
	 * превращался в пустую строку до проверки, и бот получал настоящую
	 * регистрацию. Теперь значение не санитизируется и доходит до обработчика
	 * как есть, где не-строка считается ботом.
	 */
	public function test_array_value_does_not_bypass_honeypot() {
		$response = $this->dispatch(
			$this->register_request(
				array(
					'email'        => self::BOT_EMAIL,
					'idpg_company' => array( 'Acme Bot LLC' ),
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertFalse(
			get_user_by( 'email', self::BOT_EMAIL ),
			'Массив в honeypot обошёл защиту и создал пользователя.'
		);
	}

	/* ---------------------------------------------------------------------
	 * Форма
	 * ------------------------------------------------------------------ */

	/**
	 * В форме регистрации есть приманка с правильным именем.
	 *
	 * Проверяется и разметка, и то, что она не спрятана через
	 * display:none: такие поля браузер не отправляет, и приманка выпадает
	 * из POST вместе с защитой.
	 */
	/**
	 * Каталог настоящей темы.
	 *
	 * `get_template_directory()` в тестовом окружении указывает на
	 * `themedir1/default` из состава wordpress-develop, поэтому берём путь
	 * от расположения плагина — так же, как это делает test-css-build.php.
	 *
	 * @return string
	 */
	private function theme_dir() {
		return dirname( dirname( dirname( __DIR__ ) ) ) . '/themes/idpg_core';
	}

	public function test_register_form_carries_honeypot() {
		$html = file_get_contents( $this->theme_dir() . '/page-login.php' );

		$this->assertStringContainsString(
			'name="idpg_company"',
			$html,
			'В форме регистрации нет поля-приманки.'
		);
		$this->assertStringContainsString(
			'auth__hp',
			$html,
			'Поле-приманка не размечено классом .auth__hp.'
		);

		// Приманка должна стоять внутри формы регистрации, а не в другой
		// панели страницы — иначе она уйдёт в POST только одной из форм.
		$form_pos = strpos( $html, 'id="register-organizer-form"' );
		$field_pos = strpos( $html, 'name="idpg_company"' );
		$form_end = strpos( $html, '</form>', $form_pos );

		$this->assertNotFalse( $form_pos, 'Форма регистрации организатора не найдена.' );
		$this->assertGreaterThan( $form_pos, $field_pos, 'Приманка стоит до формы регистрации.' );
		$this->assertLessThan( $form_end, $field_pos, 'Приманка стоит после закрытия формы регистрации.' );

		// Скрытие должно быть уводом за экран, а не display:none.
		$css = file_get_contents( $this->theme_dir() . '/style.css' );

		$this->assertMatchesRegularExpression(
			'/\.auth__hp\s*\{[^}]*left:\s*-9999px/',
			$css,
			'.auth__hp должен уводиться за границу экрана, иначе бот его не заполнит.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\.auth__hp\s*\{[^}]*display:\s*none/',
			$css,
			'display:none на приманке выбросит поле из POST.'
		);

		// Правило обязано быть и в SCSS-исходнике, иначе `npm run compile:css`
		// его сотрёт (см. tests/test-css-build.php).
		$scss = file_get_contents(
			$this->theme_dir() . '/assets/scss/pages/_auth.scss'
		);

		$this->assertStringContainsString( '&__hp', $scss, 'В SCSS-партиале нет .auth__hp.' );
	}

	/**
	 * Приманку отправляет и JavaScript-путь регистрации.
	 *
	 * Без JS форма уходит в PHP-fallback, и там приманка проверяется. Основной
	 * путь — `auth.js`, а payload там собирается вручную по списку полей, а не
	 * из всей формы. Стоит забыть про поле, и приманку не отправляет ни один
	 * бот на реальном браузере: форма без JS оказывается единственной
	 * защищённой, то есть защиты нет. Это не теория — DEV-05 закрывалась с
	 * ложной отметкой, будто `auth.js` поле передаёт.
	 */
	public function test_js_register_path_sends_honeypot() {
		$js = file_get_contents( $this->theme_dir() . '/assets/js/auth.js' );

		$this->assertStringContainsString(
			'idpg_company',
			$js,
			'auth.js не отправляет honeypot: обход становится основным путём.'
		);

		// Поле должно попасть именно в payload регистрации, а не упоминаться
		// где-то в файле.
		$payload_pos = strpos( $js, 'idpg_company' );

		$this->assertGreaterThan(
			strpos( $js, 'handleRegisterOrganizerSubmit' ),
			$payload_pos,
			'Приманка упоминается до обработчика регистрации — возможно, не в том payload.'
		);
	}
}
