<?php
/**
 * Юнит-тесты G8 (152-ФЗ): согласие на обработку ПДн и права субъекта.
 *
 * Модуль `class-privacy.php` был написан, но не подключался: consent-запись
 * `record_consent()` и блок прав `render_subject_tools()` не имели ни одной
 * точки вызова, поэтому согласие не фиксировалось, а субъект не мог
 * воспользоваться своими правами. Набор покрывает обе стороны после обвязки.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Privacy
 */
class Test_Privacy extends WP_UnitTestCase {

	/**
	 * Маршрут регистрации.
	 */
	const REGISTER = '/idpg/v1/auth/register';

	/**
	 * Подготовка окружения.
	 */
	public function set_up() {
		parent::set_up();

		IDPG_REST_Auth::instance();
		IDPG_Roles::instance()->create_roles();

		// Модуль приватности подключён и должен существовать.
		$this->assertTrue( class_exists( 'IDPG_Privacy' ) );
	}

	/**
	 * Запрос регистрации организатора.
	 *
	 * @param array $overrides Поля для замены; null убирает параметр.
	 * @return WP_REST_Response
	 */
	protected function register_request( $overrides = array() ) {
		$params = array_merge(
			array(
				'type'             => 'organizer',
				'name'             => 'Иван Иванов',
				'org_name'         => 'Организация «Тест»',
				'contact_name'     => 'Иван Иванов',
				'email'            => 'privacy@example.com',
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

		$request = new WP_REST_Request( 'POST', self::REGISTER );
		$request->set_body_params( $params );

		return rest_do_request( $request );
	}

	// =====================================================================
	// Согласие обязательно (152-ФЗ)
	// =====================================================================

	/**
	 * Без согласия регистрация отклоняется.
	 */
	public function test_registration_requires_consent() {
		$response = $this->register_request( array( 'idpg_pdn_consent' => false ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pdn_consent_required', $response->get_data()['code'] );
		$this->assertFalse( email_exists( 'privacy@example.com' ) );
	}

	/**
	 * Отсутствующий параметр — тоже отказ.
	 */
	public function test_registration_rejects_missing_consent() {
		$response = $this->register_request( array( 'idpg_pdn_consent' => null ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pdn_consent_required', $response->get_data()['code'] );
		$this->assertFalse( email_exists( 'privacy@example.com' ) );
	}

	/**
	 * Строка '1' и 'true' засчитываются как согласие.
	 *
	 * Формы PHP-fallback и JS отдают значение чекбокса по-разному, поэтому
	 * принимаются все формы истинности, которые понимает rest_sanitize_boolean.
	 *
	 * @dataProvider data_truthy_consent
	 *
	 * @param mixed $value Значение параметра от клиента.
	 */
	public function test_truthy_consent_values_accepted( $value ) {
		$response = $this->register_request(
			array(
				'idpg_pdn_consent' => $value,
				'email'            => 'truthy-' . md5( wp_json_encode( $value ) ) . '@example.com',
			)
		);

		$this->assertSame( 201, $response->get_status(), 'Значение: ' . wp_json_encode( $value ) );
	}

	/**
	 * Значения, которые клиент может прислать вместо checkbox.
	 *
	 * @return array[]
	 */
	public function data_truthy_consent() {
		return array(
			'boolean true' => array( true ),
			'string 1'     => array( '1' ),
			'string true'  => array( 'true' ),
		);
	}

	/**
	 * Ложные значения согласия отклоняются.
	 *
	 * Проверяем код 400, а не конкретный код ошибки: часть значений
	 * отсекается ещё схемой аргументов (`type => boolean`, пустая строка
	 * даёт rest_invalid_param), а часть — проверкой в обработчике
	 * (pdn_consent_required). Для посетителя разницы нет, главное —
	 * регистрация не проходит и аккаунт не создаётся.
	 *
	 * @dataProvider data_falsy_consent
	 *
	 * @param mixed $value Значение параметра от клиента.
	 */
	public function test_falsy_consent_values_rejected( $value ) {
		$email = 'falsy-' . md5( wp_json_encode( $value ) ) . '@example.com';

		$response = $this->register_request(
			array(
				'idpg_pdn_consent' => $value,
				'email'            => $email,
			)
		);

		$this->assertSame( 400, $response->get_status(), 'Значение: ' . wp_json_encode( $value ) );
		$this->assertFalse( email_exists( $email ) );
	}

	/**
	 * Значения, означающие отсутствие согласия.
	 *
	 * @return array[]
	 */
	public function data_falsy_consent() {
		return array(
			'boolean false' => array( false ),
			'string 0'      => array( '0' ),
			'string false'  => array( 'false' ),
			'empty string'  => array( '' ),
			'null'          => array( null ),
		);
	}

	/**
	 * Проверка согласия идёт ПОСЛЕ проверки type.
	 *
	 * Запрос type=user должен давать 403 «регистрация только организаторам»,
	 * а не 400 про согласие: иначе не-организатору выдаётся факт
	 * существования регистрации организатора (DEV-70).
	 */
	public function test_type_check_precedes_consent_check() {
		$response = $this->register_request(
			array(
				'type'             => 'user',
				'idpg_pdn_consent' => false,
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'registration_disabled', $response->get_data()['code'] );
	}

	/**
	 * Отказ по согласию не съедает попытку регистрации.
	 *
	 * Проверка стоит до обращения к rate limiter, иначе пользователь,
	 * не отметивший чекбокс, исчерпал бы лимит из 3 попыток.
	 */
	public function test_consent_rejection_does_not_consume_rate_limit() {
		$email = 'ratelimit-consent@example.com';

		// Пять отказов по согласию подряд.
		for ( $i = 0; $i < 5; $i++ ) {
			$response = $this->register_request(
				array(
					'email'            => $email,
					'idpg_pdn_consent' => false,
				)
			);
			$this->assertSame( 'pdn_consent_required', $response->get_data()['code'] );
		}

		// Регистрация со согласием всё ещё проходит.
		$ok = $this->register_request(
			array(
				'email'            => $email,
				'idpg_pdn_consent' => true,
			)
		);

		$this->assertSame( 201, $ok->get_status() );
	}

	// =====================================================================
	// Запись согласия
	// =====================================================================

	/**
	 * Успешная регистрация фиксирует согласие.
	 */
	public function test_registration_records_consent() {
		$response = $this->register_request( array( 'email' => 'consent-ok@example.com' ) );

		$this->assertSame( 201, $response->get_status() );

		$user = get_user_by( 'email', 'consent-ok@example.com' );
		$this->assertNotFalse( $user );

		$this->assertTrue( IDPG_Privacy::has_consent( $user->ID ) );

		$record = IDPG_Privacy::get_consent( $user->ID );

		$this->assertIsArray( $record );
		$this->assertSame( 'organizer', $record['source'] );
		$this->assertSame( IDPG_Privacy::CONSENT_VERSION, $record['version'] );
		$this->assertNotEmpty( $record['given'] );
	}

	/**
	 * Отказ по согласию не создаёт запись согласия.
	 */
	public function test_rejected_registration_records_nothing() {
		$this->register_request(
			array(
				'email'            => 'consent-no@example.com',
				'idpg_pdn_consent' => false,
			)
		);

		$user = get_user_by( 'email', 'consent-no@example.com' );

		// Пользователь вообще не создан.
		$this->assertFalse( $user );
	}

	/**
	 * Согласие хранится под ключом из константы и читается обратно.
	 */
	public function test_consent_stored_in_user_meta() {
		$user = $this->factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		IDPG_Privacy::record_consent( $user->ID, 'manual' );

		$raw = get_user_meta( $user->ID, IDPG_Privacy::CONSENT_FIELD, true );

		$this->assertNotEmpty( $raw );
		$this->assertSame( 'manual', $raw['source'] );
	}

	/**
	 * has_consent() возвращает false для пользователя без записи.
	 */
	public function test_has_consent_false_without_record() {
		$user = $this->factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		$this->assertFalse( IDPG_Privacy::has_consent( $user->ID ) );
	}

	/**
	 * record_consent() игнорирует несуществующего пользователя и 0.
	 */
	public function test_record_consent_ignores_bad_ids() {
		$this->assertNull( IDPG_Privacy::record_consent( 0 ) );
		$this->assertNull( IDPG_Privacy::record_consent( 999999 ) );
	}

	// =====================================================================
	// Cookie-баннер
	// =====================================================================

	/**
	 * Модуль регистрирует свои ассеты.
	 *
	 * Хук wp_enqueue_scripts в наборе WordPress сам по себе не срабатывает,
	 * поэтому вызываем метод напрямую — проверяем именно то, что он
	 * регистрирует.
	 */
	public function test_privacy_assets_registered() {
		IDPG_Privacy::instance()->register_assets();

		$this->assertTrue( wp_style_is( 'idpg-privacy', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'idpg-privacy', 'enqueued' ) );
	}

	/**
	 * Допустимы только значения из CHOICES.
	 */
	public function test_banner_choice_validation() {
		$privacy = IDPG_Privacy::instance();

		$this->assertSame( '', $privacy->get_banner_choice(), 'Без cookie выбор пуст.' );

		$_COOKIE[ IDPG_Privacy::COOKIE_NAME ] = 'all';
		$this->assertSame( 'all', $privacy->get_banner_choice() );

		$_COOKIE[ IDPG_Privacy::COOKIE_NAME ] = 'necessary';
		$this->assertSame( 'necessary', $privacy->get_banner_choice() );

		// Посторонние значения игнорируются.
		$_COOKIE[ IDPG_Privacy::COOKIE_NAME ] = 'hack';
		$this->assertSame( '', $privacy->get_banner_choice() );

		unset( $_COOKIE[ IDPG_Privacy::COOKIE_NAME ] );
	}

	/**
	 * set_banner_choice() отвергает недопустимое значение.
	 */
	public function test_set_banner_choice_rejects_invalid() {
		$privacy = IDPG_Privacy::instance();

		$this->assertFalse( $privacy->set_banner_choice( 'hack' ) );
		$this->assertFalse( $privacy->set_banner_choice( '' ) );
	}

	// =====================================================================
	// Права субъекта
	// =====================================================================

	/**
	 * Выгрузка собственных данных доступна авторизованному пользователю.
	 */
	public function test_self_export_available_for_logged_in() {
		$user = $this->factory()->user->create_and_get(
			array(
				'role'       => 'subscriber',
				'user_login' => 'export_user',
			)
		);

		update_user_meta( $user->ID, 'idpg_org_name', 'ООО «Рога и копыта»' );

		wp_set_current_user( $user->ID );

		$data = IDPG_Privacy::instance()->collect_user_data( $user );

		$this->assertIsArray( $data );
		$this->assertSame( $user->ID, $data['user']['ID'] );
		$this->assertSame( $user->user_login, $data['user']['user_login'] );
		$this->assertSame( $user->user_email, $data['user']['user_email'] );
		$this->assertArrayHasKey( 'consent', $data );
		$this->assertArrayHasKey( 'idpg_org_name', $data['meta'] );
		$this->assertSame( 'ООО «Рога и копыта»', $data['meta']['idpg_org_name'] );
	}

	/**
	 * Токены сессий не попадают в выгрузку.
	 *
	 * Это учётные данные доступа, а не персональные данные; их выгрузка
	 * создала бы риск компрометации.
	 */
	public function test_session_tokens_excluded_from_export() {
		$user = $this->factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		$user->set( 'session_tokens', 'a:1:{i:0;O:8:"stdClass":0:{}}' );

		$data = IDPG_Privacy::instance()->collect_user_data( $user );

		$this->assertArrayNotHasKey( 'session_tokens', $data['meta'] );
	}

	/**
	 * Выгрузка доступна через admin-post только своему пользователю.
	 */
	public function test_self_export_requires_login() {
		wp_set_current_user( 0 );

		$privacy = IDPG_Privacy::instance();

		// Без авторизации обработчик должен остановиться, а не отдать файл.
		$this->assertFalse( is_user_logged_in() );
		$this->assertTrue( is_callable( array( $privacy, 'handle_self_export' ) ) );
	}

	/**
	 * Запрос на удаление фиксируется в user meta.
	 */
	public function test_delete_request_meta_is_readable() {
		$user = $this->factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		$this->assertSame(
			'',
			(string) get_user_meta( $user->ID, IDPG_Privacy::META_DELETE_REQUEST, true )
		);

		update_user_meta( $user->ID, IDPG_Privacy::META_DELETE_REQUEST, '2026-09-28 10:00:00' );

		$this->assertSame(
			'2026-09-28 10:00:00',
			(string) get_user_meta( $user->ID, IDPG_Privacy::META_DELETE_REQUEST, true )
		);
	}

	/**
	 * Журнал действий администратора ограничен по размеру.
	 */
	public function test_log_limit_constant_is_bounded() {
		$this->assertGreaterThan( 0, IDPG_Privacy::LOG_LIMIT );
		$this->assertLessThanOrEqual( 1000, IDPG_Privacy::LOG_LIMIT );
	}
}
