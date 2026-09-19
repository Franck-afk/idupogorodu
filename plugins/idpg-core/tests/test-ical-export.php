<?php
/**
 * Юнит-тесты экспорта iCalendar плагина IDPG Core.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_ICal_Export
 */
class Test_ICal_Export extends WP_UnitTestCase {

	/**
	 * Сиглтон возвращает единственный экземпляр.
	 */
	public function test_instance() {
		$this->assertInstanceOf( 'IDPG_iCal_Export', IDPG_iCal_Export::instance() );
	}

	/**
	 * Если нет idpg_action — maybe_export ничего не делает.
	 */
	public function test_maybe_export_no_action() {
		unset( $_GET['idpg_action'] );
		$ical = IDPG_iCal_Export::instance();

		// Экспорт завершается exit(); без действия метод просто выходит.
		$ical->maybe_export();
		$this->assertTrue( true );
	}

	/**
	 * Экранирование спецсимволов iCal.
	 */
	public function test_escape_ical_text() {
		$ref   = new ReflectionMethod( 'IDPG_iCal_Export', 'escape_ical_text' );
		$ref->setAccessible( true );
		$ical  = IDPG_iCal_Export::instance();

		$result = $ref->invoke( $ical, 'Foo, Bar; Baz\\Qux' );
		$this->assertSame( 'Foo\\, Bar\\; Baz\\\\Qux', $result );
	}

	/**
	 * Экранирование строки больше 255 для ical.
	 */
	public function test_escape_ical_trim() {
		$ref  = new ReflectionMethod( 'IDPG_iCal_Export', 'escape_ical_text' );
		$ref->setAccessible( true );
		$result = $ref->invoke( IDPG_iCal_Export::instance(), '  Test: ok  ' );
		$this->assertSame( 'Test: ok', $result );
	}

	/**
	 * Форматирование валидной даты в формат iCal.
	 */
	public function test_format_ical_date_valid() {
		$ref  = new ReflectionMethod( 'IDPG_iCal_Export', 'format_ical_date' );
		$ref->setAccessible( true );

		$timestamp = strtotime( '2026-10-01 12:30:00 UTC' );
		$result    = $ref->invoke( IDPG_iCal_Export::instance(), $timestamp );

		$this->assertMatchesRegularExpression( '/^\d{8}T\d{6}Z$/', $result );
		$this->assertStringStartsWith( '2026', $result );
	}

	/**
	 * Некорректная дата приводит к fallback на текущее время.
	 */
	public function test_format_ical_date_invalid() {
		$ref  = new ReflectionMethod( 'IDPG_iCal_Export', 'format_ical_date' );
		$ref->setAccessible( true );

		$result = $ref->invoke( IDPG_iCal_Export::instance(), 'not-a-date' );
		$this->assertMatchesRegularExpression( '/^\d{8}T\d{6}Z$/', $result );
	}

	/**
	 * build_location объединяет площадку и город.
	 */
	public function test_build_location() {
		$ref  = new ReflectionMethod( 'IDPG_iCal_Export', 'build_location' );
		$ref->setAccessible( true );

		$this->assertSame(
			'ДК ЗИЛ, Москва',
			$ref->invoke( IDPG_iCal_Export::instance(), 'ДК ЗИЛ', 'Москва' )
		);
	}

	/**
	 * build_location пропускает пустые части.
	 */
	public function test_build_location_empty_parts() {
		$ref  = new ReflectionMethod( 'IDPG_iCal_Export', 'build_location' );
		$ref->setAccessible( true );

		$this->assertSame( 'Москва', $ref->invoke( IDPG_iCal_Export::instance(), '', 'Москва' ) );
		$this->assertSame( '', $ref->invoke( IDPG_iCal_Export::instance(), '', '' ) );
	}
}
