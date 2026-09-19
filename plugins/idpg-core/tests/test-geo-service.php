<?php
/**
 * Юнит-тесты гео-сервиса плагина IDPG Core.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Geo_Service
 */
class Test_Geo_Service extends WP_UnitTestCase {

	/**
	 * Сиглтон возвращает единственный экземпляр.
	 */
	public function test_instance() {
		$this->assertInstanceOf( 'IDPG_GeoService', IDPG_GeoService::instance() );
	}

	/**
	 * Константа города по умолчанию определена.
	 */
	public function test_default_city_constant() {
		$this->assertTrue( defined( 'IDPG_DEFAULT_CITY_ID' ) );
		$this->assertSame( 15, IDPG_DEFAULT_CITY_ID );
	}

	/**
	 * get_current_city_id возвращает целое число.
	 */
	public function test_current_city_id_is_int() {
		$id = IDPG_GeoService::instance()->get_current_city_id();
		$this->assertIsInt( $id );
	}

	/**
	 * get_current_city возвращает WP_Term или null, но не ошибку WP.
	 */
	public function test_current_city() {
		$city = IDPG_GeoService::instance()->get_current_city();
		// Доступно множество допустимых состояний: null (город не задан) или WP_Term.
		$this->assertTrue( null === $city || $city instanceof WP_Term );
	}
}
