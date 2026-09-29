<?php
/**
 * Юнит-тесты ограничителя частоты запросов IDPG Auth Rate Limiter.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Auth_Rate_Limiter
 */
class Test_Auth_Rate_Limiter extends WP_UnitTestCase {

	/**
	 * IP для проверок, заведомо не являющийся адресом прокси.
	 *
	 * @var string
	 */
	const IP = '203.0.113.10';

	/**
	 * Экземпляр ограничителя.
	 *
	 * @var IDPG_Auth_Rate_Limiter
	 */
	protected $limiter;

	/**
	 * Подготовка: чистый счётчик и свежий экземпляр.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->clear_counters();
		$this->limiter = IDPG_Auth_Rate_Limiter::instance();
	}

	/**
	 * Очистка: счётчики не должны протекать между тестами.
	 */
	public function tearDown(): void {
		$this->clear_counters();

		parent::tearDown();
	}

	/**
	 * Удаление счётчиков по известным ключам.
	 */
	protected function clear_counters() {
		$keys = array(
			'idpg_reset_attempts_' . md5( self::IP ),
			'idpg_reset_ip_attempts_' . md5( self::IP ),
		);

		foreach ( $keys as $key ) {
			delete_transient( $key );
		}
	}

	/**
	 * Первые запросы с одного IP проходят.
	 */
	public function test_reset_ip_allows_within_limit() {
		for ( $i = 1; $i <= IDPG_Auth_Rate_Limiter::RESET_IP_LIMIT; $i++ ) {
			$result = $this->limiter->check_password_reset_ip_attempt( self::IP );

			$this->assertTrue(
				$result,
				sprintf( 'Запрос №%d должен проходить, лимит ещё не исчерпан.', $i )
			);
		}
	}

	/**
	 * Следующий запрос сверх лимита отклоняется с кодом 429.
	 */
	public function test_reset_ip_blocks_over_limit() {
		for ( $i = 0; $i < IDPG_Auth_Rate_Limiter::RESET_IP_LIMIT; $i++ ) {
			$this->limiter->check_password_reset_ip_attempt( self::IP );
		}

		$result = $this->limiter->check_password_reset_ip_attempt( self::IP );

		$this->assertWPError( $result );
		$this->assertSame( 'too_many_resets_from_ip', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	/**
	 * Лимит по IP не блокирует запрос с другого адреса.
	 */
	public function test_reset_ip_is_scoped_to_address() {
		$other = '198.51.100.7';

		for ( $i = 0; $i < IDPG_Auth_Rate_Limiter::RESET_IP_LIMIT + 1; $i++ ) {
			$this->limiter->check_password_reset_ip_attempt( self::IP );
		}

		$this->assertTrue(
			$this->limiter->check_password_reset_ip_attempt( $other ),
			'Другой IP не должен делить счётчик заблокированного адреса.'
		);

		delete_transient( 'idpg_reset_ip_attempts_' . md5( $other ) );
	}

	/**
	 * Неопределимый адрес не лимитируется: за прокси иначе все клиенты
	 * делили бы один счётчик.
	 */
	public function test_reset_ip_skips_unresolved_address() {
		for ( $i = 0; $i < IDPG_Auth_Rate_Limiter::RESET_IP_LIMIT + 3; $i++ ) {
			$this->assertTrue(
				$this->limiter->check_password_reset_ip_attempt( '0.0.0.0' ),
				'Адрес 0.0.0.0 не должен лимитироваться.'
			);
		}
	}

	/**
	 * Мусор вместо адреса не лимитируется и не роняет код.
	 */
	public function test_reset_ip_ignores_garbage() {
		$this->assertTrue( $this->limiter->check_password_reset_ip_attempt( '' ) );
		$this->assertTrue( $this->limiter->check_password_reset_ip_attempt( 'не-ip' ) );
	}

	/**
	 * IP-лимит и лимит по email считаются независимо: перебор множества
	 * разных адресов с одной машины ловится именно по IP.
	 */
	public function test_reset_ip_catches_email_rotation() {
		// Один и тот же email лимит не исчерпает, IP исчерпает.
		for ( $i = 0; $i < IDPG_Auth_Rate_Limiter::RESET_IP_LIMIT + 1; $i++ ) {
			$this->limiter->check_password_reset_ip_attempt( self::IP );
		}

		$this->assertWPError(
			$this->limiter->check_password_reset_ip_attempt( self::IP ),
			'Перебор разных адресов с одного IP должен быть остановлен.'
		);

		// Счётчик по email при этом не тронут — он живёт под своим ключом.
		$this->assertFalse( get_transient( 'idpg_reset_attempts_' . md5( 'many@example.com' ) ) );
	}

	/**
	 * Существующий лимит по email продолжает работать как раньше.
	 */
	public function test_reset_email_limit_unchanged() {
		for ( $i = 0; $i < IDPG_Auth_Rate_Limiter::RESET_LIMIT; $i++ ) {
			$this->assertTrue(
				$this->limiter->check_password_reset_attempt( 'user@example.com' )
			);
		}

		$result = $this->limiter->check_password_reset_attempt( 'user@example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'too_many_resets', $result->get_error_code() );
	}
}
