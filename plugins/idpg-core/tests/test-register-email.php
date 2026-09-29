<?php
/**
 * Юнит-тесты DEV-68: письмо подтверждения отправляется только организатору.
 *
 * Задача — проверить, что письмо со временной ссылкой уходит только при
 * `type=organizer` в REST /auth/register, а роль event_organizer выдаётся
 * лишь после перехода по ссылке.
 *
 * Письма перехватываются фильтром `pre_wp_mail` — отправка не выполняется,
 * а содержимое доступно для проверки.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Register_Email
 */
class Test_Register_Email extends WP_UnitTestCase {

	/**
	 * Маршрут регистрации.
	 */
	const ROUTE = '/idpg/v1/auth/register';

	/**
	 * Тема письма подтверждения организатора.
	 */
	const VERIFY_SUBJECT = 'Подтверждение регистрации организатора — Иду по городу';

	/**
	 * Перехваченные письма: по одному на вызов wp_mail().
	 *
	 * @var array
	 */
	private $sent = array();

	/**
	 * Подготовка окружения.
	 */
	public function set_up() {
		parent::set_up();

		// Конструктор вешает register_routes на rest_api_init.
		IDPG_REST_Auth::instance();

		// Роль организатора должна существовать, иначе набор не отражает прод.
		IDPG_Roles::instance()->create_roles();

		// Лимит — 3 регистрации в час с одного IP.
		delete_transient( $this->rate_key() );

		$this->sent = array();

		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/**
	 * Очистка окружения.
	 */
	public function tear_down() {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );

		parent::tear_down();
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
	 * Перехватывает письмо вместо отправки.
	 *
	 * @param null|bool $short_circuit Значение, на котором wp_mail() останавливается.
	 * @param array     $atts          Параметры письма.
	 * @return bool
	 */
	public function capture_mail( $short_circuit, $atts ) {
		$this->sent[] = $atts;

		return true;
	}

	/**
	 * Собирает запрос регистрации организатора.
	 *
	 * @param array $overrides Поля для замены; null убирает параметр.
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
	 * Письма с темой подтверждения.
	 *
	 * @return array
	 */
	private function verification_mails() {
		return array_values(
			array_filter(
				$this->sent,
				function ( $mail ) {
					return self::VERIFY_SUBJECT === $mail['subject'];
				}
			)
		);
	}

	/**
	 * Ссылка подтверждения из письма.
	 *
	 * @return string URL.
	 */
	private function link_from_verification_mail() {
		$mails = $this->verification_mails();
		$body  = $mails[0]['message'];

		// Кнопка ведёт обычной ссылкой — вытаскиваем первый href.
		preg_match( '#href="([^"]+)"#', $body, $matches );

		return html_entity_decode( $matches[1] );
	}

	/* ---------------------------------------------------------------------
	 * Пункт 1 приёмки: организатор получает письмо подтверждения.
	 * ------------------------------------------------------------------ */

	/**
	 * При type=organizer письмо с временной ссылкой отправляется.
	 *
	 * Создание ресурса — 201, `pending_email` подтверждает, что аккаунт
	 * ждёт подтверждения.
	 */
	public function test_organizer_receives_verification_email() {
		$response = rest_do_request( $this->register_request() );

		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $response->get_data()['pending_email'] );

		$mails = $this->verification_mails();

		$this->assertCount( 1, $mails, 'организатор должен получить ровно одно письмо подтверждения' );
		$this->assertSame( 'org@example.com', $mails[0]['to'] );
	}

	/**
	 * Письмо уходит на адрес организатора, а не на посторонний.
	 */
	public function test_verification_email_goes_to_organizer_address() {
		rest_do_request( $this->register_request( array( 'email' => 'boss@example.com' ) ) );

		$mails = $this->verification_mails();

		$this->assertSame( 'boss@example.com', $mails[0]['to'] );
	}

	/**
	 * В письме есть рабочая ссылка подтверждения.
	 */
	public function test_verification_email_contains_confirm_link() {
		$response = rest_do_request( $this->register_request() );
		$user_id  = $response->get_data()['user_id'];

		$link = $this->link_from_verification_mail();

		$this->assertStringContainsString( 'action=confirm', $link );
		$this->assertStringContainsString( 'idpg_user=' . $user_id, $link );
		$this->assertStringContainsString( 'idpg_key=', $link );
	}

	/* ---------------------------------------------------------------------
	 * Пункт 2 приёмки: роль event_organizer — только после подтверждения.
	 * ------------------------------------------------------------------ */

	/**
	 * Сразу после регистрации роль организатора ещё не выдана.
	 */
	public function test_role_is_not_granted_before_confirmation() {
		$response = rest_do_request( $this->register_request() );
		$user_id  = $response->get_data()['user_id'];
		$user     = get_user_by( 'id', $user_id );

		$this->assertNotContains( IDPG_Roles::ROLE_SLUG, (array) $user->roles );
		$this->assertTrue( IDPG_Organizer_Verify::instance()->is_pending( $user_id ) );
	}

	/**
	 * Ссылка из письма подтверждает email и открывает доступ в ЛК.
	 *
	 * Это полный сценарий приёмки DEV-68: зарегистрировался → получил
	 * письмо → подтвердил → получил роль → попал в кабинет.
	 */
	public function test_confirming_email_grants_role_and_cabinet() {
		rest_do_request( $this->register_request() );

		$link = $this->link_from_verification_mail();
		$query = array();
		parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $query );

		$result = IDPG_Organizer_Verify::instance()->confirm(
			(int) $query['idpg_user'],
			sanitize_text_field( wp_unslash( $query['idpg_key'] ) )
		);

		$this->assertNotWPError( $result );

		$user = get_user_by( 'id', $result->ID );

		$this->assertContains( IDPG_Roles::ROLE_SLUG, (array) $user->roles );
		$this->assertSame( home_url( '/organizer/' ), IDPG_Organizer_URL::after_login_url( $result->ID ) );
	}

	/**
	 * Приветственное письмо организатору не дублируется письмом подтверждения.
	 *
	 * `send_welcome_email()` пропускает организатора, пока в буфере лежит
	 * запомненный пароль, поэтому уходит ровно одно письмо.
	 */
	public function test_organizer_gets_no_welcome_letter() {
		rest_do_request( $this->register_request() );

		$this->assertCount(
			1,
			$this->sent,
			'организатору должно уйти только письмо подтверждения, а не приветствие'
		);
	}

	/* ---------------------------------------------------------------------
	 * Пункт 3 приёмки: остальным письмо не отправляется.
	 * ------------------------------------------------------------------ */

	/**
	 * type=user отклоняется и письмо не отправляется.
	 */
	public function test_plain_user_gets_no_email() {
		$response = rest_do_request( $this->register_request( array( 'type' => 'user' ) ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertCount( 0, $this->verification_mails() );
		$this->assertFalse( email_exists( 'org@example.com' ) );
	}

	/**
	 * Без типа регистрации письмо не отправляется.
	 */
	public function test_missing_type_gets_no_email() {
		$response = rest_do_request( $this->register_request( array( 'type' => null ) ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertCount( 0, $this->verification_mails() );
		$this->assertFalse( email_exists( 'org@example.com' ) );
	}

	/**
	 * Произвольный тип не обходит проверку и письма не вызывает.
	 */
	public function test_arbitrary_type_gets_no_email() {
		$response = rest_do_request( $this->register_request( array( 'type' => 'administrator' ) ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertCount( 0, $this->verification_mails() );
		$this->assertFalse( email_exists( 'org@example.com' ) );
	}
}
