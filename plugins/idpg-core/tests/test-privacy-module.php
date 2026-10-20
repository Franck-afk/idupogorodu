<?php
/**
 * Тесты модуля обработки персональных данных (DEV-288).
 *
 * Проверяют контракт IDPG_Privacy: фиксацию и отзыв согласия, cookie-баннер,
 * права субъекта (выгрузка). Класс подключён плагином idpg-core.
 *
 * @package idpg-core
 */

/**
 * Class Test_Privacy_Module
 */
class Test_Privacy_Module extends WP_UnitTestCase {

	/**
	 * Создаёт подписчика и возвращает его ID.
	 *
	 * @return int
	 */
	private function make_user() {
		return (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Модуль загружен плагином.
	 */
	public function test_module_loaded() {
		$this->assertTrue( class_exists( 'IDPG_Privacy' ), 'IDPG_Privacy не подключён плагином.' );
		$this->assertTrue( method_exists( 'IDPG_Privacy', 'record_consent' ) );
		$this->assertTrue( method_exists( 'IDPG_Privacy', 'withdraw_consent' ) );
	}

	/**
	 * Фиксация согласия пишет запись с датой, редакцией, источником и хешем IP.
	 */
	public function test_record_consent_stores_meta() {
		$user_id                = $this->make_user();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		$result = IDPG_Privacy::record_consent( $user_id, 'organizer' );
		$this->assertTrue( $result );

		$record = IDPG_Privacy::get_consent( $user_id );
		$this->assertSame( 'organizer', $record['source'] );
		$this->assertSame( IDPG_Privacy::CONSENT_VERSION, $record['version'] );
		$this->assertNotEmpty( $record['given'], 'Нет даты согласия.' );
		$this->assertNotEmpty( $record['ip_hash'], 'Нет хеша IP.' );

		// Сырой IP не хранится — только хеш, отличный от адреса.
		$this->assertNotSame( '203.0.113.7', $record['ip_hash'] );
	}

	/**
	 * Согласие считается действующим после записи.
	 */
	public function test_has_consent_after_record() {
		$user_id = $this->make_user();
		$this->assertFalse( IDPG_Privacy::has_consent( $user_id ) );

		IDPG_Privacy::record_consent( $user_id, 'user' );

		$this->assertTrue( IDPG_Privacy::has_consent( $user_id ) );
	}

	/**
	 * Отзыв согласия: has_consent() ложно, is_withdrawn() истинно.
	 */
	public function test_has_consent_false_after_withdraw() {
		$user_id = $this->make_user();

		IDPG_Privacy::record_consent( $user_id, 'user' );
		IDPG_Privacy::withdraw_consent( $user_id, 'self' );

		$this->assertFalse( IDPG_Privacy::has_consent( $user_id ) );
		$this->assertTrue( IDPG_Privacy::is_withdrawn( $user_id ) );

		$withdrawal = IDPG_Privacy::get_withdrawal( $user_id );
		$this->assertSame( 'self', $withdrawal['source'] );
	}

	/**
	 * Повторное согласие снимает отзыв.
	 */
	public function test_record_clears_withdrawal() {
		$user_id = $this->make_user();

		IDPG_Privacy::record_consent( $user_id, 'user' );
		IDPG_Privacy::withdraw_consent( $user_id, 'self' );
		$this->assertTrue( IDPG_Privacy::is_withdrawn( $user_id ) );

		IDPG_Privacy::record_consent( $user_id, 'user' );

		$this->assertFalse( IDPG_Privacy::is_withdrawn( $user_id ) );
		$this->assertTrue( IDPG_Privacy::has_consent( $user_id ) );
	}

	/**
	 * Согласия нет у несуществующего пользователя.
	 */
	public function test_has_consent_false_without_record() {
		$this->assertFalse( IDPG_Privacy::has_consent( 0 ) );
		$this->assertFalse( IDPG_Privacy::has_consent( 999999 ) );
	}

	/**
	 * Cookie-баннер: разметка содержит форму и кнопки выбора, nonce.
	 */
	public function test_banner_markup_present() {
		unset( $_COOKIE[ IDPG_Privacy::COOKIE_NAME ] );

		ob_start();
		IDPG_Privacy::instance()->render_banner();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'class="idpg-cookie"', $html );
		$this->assertStringContainsString( 'idpg-cookie__btn', $html );
		$this->assertStringContainsString( 'value="all"', $html );
		$this->assertStringContainsString( 'value="necessary"', $html );
		$this->assertStringContainsString( 'idpg_privacy_banner_nonce', $html );
	}

	/**
	 * Баннер не выводится, если выбор уже сделан.
	 */
	public function test_banner_hidden_when_choice_made() {
		$_COOKIE[ IDPG_Privacy::COOKIE_NAME ] = 'all';

		ob_start();
		IDPG_Privacy::instance()->render_banner();
		$html = ob_get_clean();

		unset( $_COOKIE[ IDPG_Privacy::COOKIE_NAME ] );

		$this->assertSame( '', $html );
	}

	/**
	 * Выбор в баннере валидируется по safelist.
	 */
	public function test_banner_choice_safelist() {
		$this->assertSame( array( 'all', 'necessary' ), IDPG_Privacy::COOKIE_CHOICES );
		$this->assertContains( IDPG_Privacy::COOKIE_NAME, array( 'idpg_cookie_consent' ) );
	}

	/**
	 * Выгрузка данных содержит профиль, согласие и метаданные.
	 */
	public function test_build_user_export() {
		$user_id = $this->make_user();
		IDPG_Privacy::record_consent( $user_id, 'user' );

		$method = new ReflectionMethod( 'IDPG_Privacy', 'build_user_export' );
		$method->setAccessible( true );
		$export = $method->invoke( IDPG_Privacy::instance(), $user_id );

		$this->assertSame( $user_id, $export['user']['id'] );
		$this->assertArrayHasKey( 'user_login', $export['user'] );
		$this->assertSame( 'user', $export['consent']['source'] );
		$this->assertArrayHasKey( 'meta', $export );
	}

	/**
	 * Хеш IP детерминирован для одного адреса и различается для разных.
	 */
	public function test_hash_client_ip_is_stable_and_distinct() {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		$first                  = IDPG_Privacy::hash_client_ip();
		$second                 = IDPG_Privacy::hash_client_ip();
		$this->assertSame( $first, $second );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.2';
		$this->assertNotSame( $first, IDPG_Privacy::hash_client_ip() );
	}

	// =====================================================================
	// Отзыв согласия (перенесено из test-privacy-withdrawal.php, DEV-310).
	// =====================================================================

	/**
	 * Запись об отзыве содержит дату, редакцию и источник.
	 */
	public function test_withdrawal_record_has_date_version_source() {
		$user_id = $this->make_user();
		IDPG_Privacy::record_consent( $user_id, 'organizer' );

		$this->assertTrue( IDPG_Privacy::withdraw_consent( $user_id, 'self' ) );

		$record = IDPG_Privacy::get_withdrawal( $user_id );
		$this->assertIsArray( $record );
		$this->assertNotEmpty( $record['withdrawn'], 'В записи об отзыве нет даты.' );
		$this->assertArrayHasKey( 'version', $record );
		$this->assertSame( 'self', $record['source'] );
	}

	/**
	 * Запись о самом согласии не затирается отзывом — нужен след для доказательства.
	 */
	public function test_consent_record_survives_withdrawal() {
		$user_id = $this->make_user();
		IDPG_Privacy::record_consent( $user_id, 'organizer' );

		$before = IDPG_Privacy::get_consent( $user_id );
		IDPG_Privacy::withdraw_consent( $user_id );
		$after = IDPG_Privacy::get_consent( $user_id );

		$this->assertSame( $before, $after, 'Отзыв стёр запись о согласии.' );
		$this->assertNotEmpty( $after['given'] );
		$this->assertSame( 'organizer', $after['source'] );
	}

	/**
	 * Повторный отзыв безопасен (идемпотентность, без дублей-списков).
	 */
	public function test_repeat_withdrawal_is_safe() {
		$user_id = $this->make_user();
		IDPG_Privacy::record_consent( $user_id, 'user' );

		IDPG_Privacy::withdraw_consent( $user_id );
		$first = IDPG_Privacy::get_withdrawal( $user_id );

		IDPG_Privacy::withdraw_consent( $user_id );
		$second = IDPG_Privacy::get_withdrawal( $user_id );

		$this->assertTrue( IDPG_Privacy::is_withdrawn( $user_id ) );
		$this->assertArrayNotHasKey( 0, $second, 'Запись об отзыве стала списком.' );
		$this->assertNotEmpty( $first['withdrawn'] );
		$this->assertNotEmpty( $second['withdrawn'] );
	}

	/**
	 * Хук отзыва согласия срабатывает с ID пользователя и записью.
	 */
	public function test_withdrawn_action_fires() {
		$user_id = $this->make_user();
		IDPG_Privacy::record_consent( $user_id, 'user' );

		$seen = array();
		add_action(
			'idpg_privacy_consent_withdrawn',
			static function ( $fired_user_id, $record ) use ( &$seen ) {
				$seen = array( $fired_user_id, $record );
			},
			10,
			2
		);

		IDPG_Privacy::withdraw_consent( $user_id );

		$this->assertCount( 2, $seen, 'Хук отзыва не сработал.' );
		$this->assertSame( $user_id, $seen[0] );
		$this->assertNotEmpty( $seen[1]['withdrawn'] );
	}

	/**
	 * Отзыв для несуществующего пользователя безопасен.
	 */
	public function test_withdrawal_rejects_invalid_user() {
		$this->assertFalse( IDPG_Privacy::withdraw_consent( 0 ) );
		$this->assertFalse( IDPG_Privacy::withdraw_consent( -1 ) );
	}

	// =====================================================================
	// Форма прав субъекта на странице политики (перенесено, DEV-310).
	// =====================================================================

	/**
	 * Создаёт страницу политики и возвращает её ID.
	 *
	 * @return int
	 */
	private function make_privacy_page() {
		return (int) self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Политика конфиденциальности',
			)
		);
	}

	/**
	 * Авторизованному субъекту выводится форма отзыва согласия.
	 */
	public function test_subject_tools_render_withdraw_form_for_logged_in() {
		$user_id = $this->make_user();
		IDPG_Privacy::record_consent( $user_id, 'organizer' );
		wp_set_current_user( $user_id );

		$html = IDPG_Privacy::instance()->render_subject_tools( $this->make_privacy_page() );

		$this->assertStringContainsString( 'idpg-privacy-tools__block', $html );
		$this->assertStringContainsString( 'idpg_privacy_subject_nonce', $html );
		$this->assertStringContainsString( 'value="withdraw"', $html );
	}

	/**
	 * Гостю форма отзыва не выводится — только предложение войти.
	 */
	public function test_subject_tools_hidden_for_guests() {
		wp_set_current_user( 0 );

		$html = IDPG_Privacy::instance()->render_subject_tools( $this->make_privacy_page() );

		$this->assertStringNotContainsString( 'value="withdraw"', $html );
		$this->assertStringContainsString( 'Войдите', $html );
	}

	/**
	 * После отзыва форма отзыва исчезает и показывается факт отзыва.
	 */
	public function test_subject_tools_withdraw_form_disappears_after_withdrawal() {
		$user_id = $this->make_user();
		IDPG_Privacy::record_consent( $user_id, 'organizer' );
		IDPG_Privacy::withdraw_consent( $user_id, 'self' );
		wp_set_current_user( $user_id );

		$html = IDPG_Privacy::instance()->render_subject_tools( $this->make_privacy_page() );

		$this->assertStringNotContainsString( 'value="withdraw"', $html );
		$this->assertStringContainsString( 'отозвано', $html );
	}

	/**
	 * Авторизованному без записи о согласии форма отзыва не выводится.
	 */
	public function test_subject_tools_withdraw_form_hidden_without_consent() {
		$user_id = $this->make_user();
		wp_set_current_user( $user_id );

		$html = IDPG_Privacy::instance()->render_subject_tools( $this->make_privacy_page() );

		$this->assertStringNotContainsString( 'value="withdraw"', $html );
	}

	/**
	 * Форма само-выгрузки и запрос удаления выводятся с nonce.
	 */
	public function test_subject_tools_export_form_has_nonce() {
		$user_id = $this->make_user();
		wp_set_current_user( $user_id );

		$html = IDPG_Privacy::instance()->render_subject_tools( $this->make_privacy_page() );

		$this->assertStringContainsString( 'idpg_privacy_self_export', $html );
		$this->assertStringContainsString( 'idpg_privacy_self_export_nonce', $html );
		$this->assertStringContainsString( 'value="delete_request"', $html );
	}

	// =====================================================================
	// Ассеты и выгрузка (перенесено, DEV-310).
	// =====================================================================

	/**
	 * Стиль cookie-баннера подключается, пока выбор не сделан.
	 */
	public function test_banner_assets_enqueued_without_choice() {
		unset( $_COOKIE[ IDPG_Privacy::COOKIE_NAME ] );

		IDPG_Privacy::instance()->enqueue_assets();

		$this->assertTrue( wp_style_is( 'idpg-privacy-banner', 'enqueued' ) );
	}

	/**
	 * Токены сессий не попадают в выгрузку — иначе это риск компрометации.
	 */
	public function test_session_tokens_excluded_from_export() {
		$user_id = $this->make_user();
		update_user_meta( $user_id, 'session_tokens', array( 'dummy' => 'token-value' ) );
		update_user_meta( $user_id, 'idpg_org_name', 'ООО «Рога и копыта»' );

		// Контроль: мета действительно записана и видна в экспорте.
		$this->assertArrayHasKey( 'session_tokens', get_user_meta( $user_id ) );

		$method = new ReflectionMethod( 'IDPG_Privacy', 'build_user_export' );
		$method->setAccessible( true );
		$export = $method->invoke( IDPG_Privacy::instance(), $user_id );

		$this->assertArrayHasKey( 'meta', $export );
		$this->assertArrayHasKey( 'idpg_org_name', $export['meta'], 'Обычная мета должна выгружаться.' );
		$this->assertArrayNotHasKey( 'session_tokens', $export['meta'], 'Токены сессий попали в выгрузку.' );
	}

	/**
	 * Само-выгрузка защищена требованием авторизации.
	 */
	public function test_self_export_requires_login() {
		wp_set_current_user( 0 );

		$this->assertFalse( is_user_logged_in() );
		$this->assertTrue( is_callable( array( IDPG_Privacy::instance(), 'handle_self_export' ) ) );

		$this->expectException( 'WPDieException' );
		IDPG_Privacy::instance()->handle_self_export();
	}
}

/**
 * Интеграция модуля приватности с REST-регистрацией (перенесено, DEV-310).
 *
 * Проверяет, что согласие на обработку ПДн действительно фиксируется при
 * регистрации организатора через REST и что запись о согласии не создаётся
 * при отказе.
 */
class Test_Privacy_Rest extends WP_UnitTestCase {

	/**
	 * Маршрут регистрации.
	 */
	const REGISTER = '/idpg/v1/auth/register';

	/**
	 * Подготовка окружения.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( 0 );

		IDPG_REST_Auth::instance();
		IDPG_Roles::instance()->create_roles();
	}

	/**
	 * Запрос регистрации организатора.
	 *
	 * @param array $overrides Поля для замены; null убирает параметр.
	 * @return WP_REST_Response
	 */
	private function register_request( $overrides = array() ) {
		wp_set_current_user( 0 );

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

	/**
	 * Без согласия регистрация отклоняется и аккаунт не создаётся.
	 */
	public function test_registration_requires_consent() {
		$response = $this->register_request( array( 'idpg_pdn_consent' => false ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pdn_consent_required', $response->get_data()['code'] );
		$this->assertFalse( email_exists( 'privacy@example.com' ) );
	}

	/**
	 * Успешная регистрация фиксирует согласие с источником «organizer».
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
	}

	/**
	 * Отказ по типу регистрации предшествует проверке согласия.
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
}
