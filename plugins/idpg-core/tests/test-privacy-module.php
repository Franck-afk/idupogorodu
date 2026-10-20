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
}
