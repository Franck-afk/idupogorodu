<?php
/**
 * Запуск JS-тестов auth.js из PHPUnit-набора.
 *
 * PHP-тесты не видят, что именно уходит из браузера в REST. 28.09.2026 поле
 * согласия не отправлялось из auth.js, и набор оставался зелёным. Тест
 * исполняет настоящий assets/js/auth.js в node и проверяет payload.
 *
 * Если node не установлен — тест пропускается, а не падает.
 *
 * @package idpg-core
 */

/**
 * Class Test_Auth_Js
 */
class Test_Auth_Js extends WP_UnitTestCase {

	/**
	 * Путь к JS-тесту.
	 *
	 * @return string
	 */
	private function test_script() {
		return __DIR__ . '/js/auth-registration.test.js';
	}

	/**
	 * node доступен?
	 *
	 * @return bool
	 */
	private function has_node() {
		$which = shell_exec( 'node --version 2>&1' );

		return is_string( $which ) && '' !== trim( $which );
	}

	/**
	 * Основная проверка: JS-тест регистрации проходит.
	 */
	public function test_auth_js_registration_path() {
		if ( ! $this->has_node() ) {
			$this->markTestSkipped( 'node не установлен — JS-тесты пропущены.' );
		}

		if ( ! file_exists( $this->test_script() ) ) {
			$this->markTestSkipped( 'Файл JS-теста не найден: ' . $this->test_script() );
		}

		$node = trim( (string) shell_exec( 'node --version 2>&1' ) );
		$cmd  = escapeshellcmd( 'node' ) . ' ' . escapeshellarg( $this->test_script() ) . ' 2>&1';

		$output = array();
		$status = 0;
		exec( $cmd, $output, $status );

		$output = implode( "\n", $output );

		$this->assertSame(
			0,
			$status,
			"JS-тесты auth.js упали (node {$node}):\n" . $output
		);

		$this->assertStringContainsString(
			'FAIL=0',
			$output,
			"JS-тесты auth.js сообщили о провалах:\n" . $output
		);
	}
}
