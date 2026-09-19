<?php
/**
 * Юнит-тесты iCal-парсера (IDPG_Provider_iCal).
 *
 * @package Idpg_Event_Import
 */

/**
 * Класс Test_Provider_iCal
 */
class Test_Provider_iCal extends WP_UnitTestCase {

	/**
	 * Валидный календарь разбирается в события.
	 */
	public function test_parse_valid_calendar() {
		$ical = "BEGIN:VCALENDAR\r\n"
			. "VERSION:2.0\r\n"
			. "BEGIN:VEVENT\r\n"
			. "UID:abc-123@partner\r\n"
			. "SUMMARY:Спектакль «Тест»\r\n"
			. "DESCRIPTION:Первый акт и второй\r\n"
			. "DTSTART:20260910T183000Z\r\n"
			. "DTEND:20260910T203000Z\r\n"
			. "LOCATION:ДК Тестовый, зал 1\r\n"
			. "URL:https://tickets.example.com/abc\r\n"
			. "END:VEVENT\r\n"
			. "BEGIN:VEVENT\r\n"
			. "UID:def-456@partner\r\n"
			. "SUMMARY:Концерт\r\n"
			. "DTSTART;VALUE=DATE:20261120\r\n"
			. "END:VEVENT\r\n"
			. "END:VCALENDAR\r\n";

		$provider = new IDPG_Provider_iCal( array( 'source_id' => 't' ) );
		$events   = $provider->parse_ical( $ical );

		$this->assertCount( 2, $events );

		$first = $events[0];
		$this->assertSame( 'abc-123@partner', $first['external_id'] );
		$this->assertSame( 'Спектакль «Тест»', $first['title'] );
		$this->assertSame( '2026-09-10T18:30', $first['starts_at'] );
		$this->assertSame( '2026-09-10T20:30', $first['ends_at'] );
		$this->assertSame( 'ДК Тестовый, зал 1', $first['venue_name'] );
		$this->assertSame( 'https://tickets.example.com/abc', $first['buy_url'] );

		// Событие только с датой (VALUE=DATE).
		$second = $events[1];
		$this->assertSame( '2026-11-20', $second['starts_at'] );
		$this->assertSame( '', $second['ends_at'] );
	}

	/**
	 * Свёрнутые строки (RFC 5545: continuation с ведущим пробелом) склеиваются.
	 *
	 * Ведущий WSP continuation-строки удаляется, контент приклеивается.
	 * Пробелы между словами содержатся в самих строках свёртки
	 * (так сворачивают Google Calendar и большинство фидов).
	 */
	public function test_unfold_multiline_description() {
		$ical = "BEGIN:VCALENDAR\n"
			. "BEGIN:VEVENT\n"
			. "UID:fold-1@p\n"
			. "SUMMARY:Тест свёртки\n"
			. "DESCRIPTION:Первая строка\n"
			. "  продолжает вторая\n"
			. "  и третья\n"
			. "DTSTART:20260910T100000Z\n"
			. "END:VEVENT\n"
			. "END:VCALENDAR\n";

		$provider = new IDPG_Provider_iCal( array( 'source_id' => 't' ) );
		$events   = $provider->parse_ical( $ical );

		$this->assertCount( 1, $events );
		// Первая строка не кончается пробелом — части склеены без разделителя
		// (строгое поведение RFC 5545), все continuation-строки вошли.
		$this->assertSame(
			'Первая строка продолжает вторая и третья',
			$events[0]['description']
		);
	}

	/**
	 * Битый/пустой календарь не ломает парсер.
	 */
	public function test_parse_garbage() {
		$provider = new IDPG_Provider_iCal( array( 'source_id' => 't' ) );

		$this->assertSame( array(), $provider->parse_ical( '' ) );
		$this->assertSame( array(), $provider->parse_ical( 'мусор без VEVENT' ) );
		$this->assertSame( array(), $provider->parse_ical( "BEGIN:VEVENT\nDTSTART:20260910\nEND:VEVENT" ) ); // Нет SUMMARY — отброшено.
	}

	/**
	 * fetch_page: вторая страница всегда пуста (без пагинации).
	 */
	public function test_fetch_page_no_pagination() {
		$provider = new IDPG_Provider_iCal( array( 'source_id' => 't' ) );

		$this->assertSame( array(), $provider->fetch_page( 2 ) );
	}
}
