<?php
/**
 * Юнит-тесты маппера импорта (IDPG_Import_Mapper).
 *
 * @package Idpg_Event_Import
 */

/**
 * Класс Test_Import_Mapper
 */
class Test_Import_Mapper extends WP_UnitTestCase {

	/**
	 * Полный валидный элемент API проходит маппинг целиком.
	 */
	public function test_map_event_full() {
		$raw = array(
			'id'         => 'ext-123',
			'title'      => 'Концерт <b>тест</b>',
			'description' => '<p>Описание с <script>alert(1)</script> html</p>',
			'starts_at'  => '2026-09-10T18:00:00+03:00',
			'ends_at'    => '2026-09-10T20:00:00+03:00',
			'venue'      => 'ДК Тестовый',
			'address'    => 'ул. Тестовая, 1',
			'city'       => 'Ижевск',
			'category'   => 'Концерты',
			'price_min'  => '500',
			'price_max'  => '1500',
			'currency'   => 'rub',
			'ticket_url' => 'https://tickets.example.com/123',
			'status'     => 'active',
			'image'      => 'https://cdn.example.com/poster.jpg',
		);

		$mapped = IDPG_Import_Mapper::map_event( $raw, array( 'source_id' => 'test' ) );

		$this->assertNotNull( $mapped );
		$this->assertSame( 'ext-123', $mapped['external_id'] );
		$this->assertSame( 'Концерт тест', $mapped['title'] );
		$this->assertSame( '2026-09-10T18:00', $mapped['starts_at'] );
		$this->assertSame( '2026-09-10T20:00', $mapped['ends_at'] );
		$this->assertSame( 'ДК Тестовый', $mapped['venue_name'] );
		$this->assertSame( 'ул. Тестовая, 1', $mapped['venue_address'] );
		$this->assertSame( 'Ижевск', $mapped['city'] );
		$this->assertSame( 'RUB', $mapped['currency'] );
		$this->assertEqualsWithDelta( 500.0, $mapped['price_min'], 0.001 );
		$this->assertEqualsWithDelta( 1500.0, $mapped['price_max'], 0.001 );
		$this->assertSame( 'https://tickets.example.com/123', $mapped['buy_url'] );
		$this->assertSame( 'in_sale', $mapped['status'] );

		// HTML: разрешённые теги остаются, script вырезается.
		$this->assertStringContainsString( '<p>', $mapped['description'] );
		$this->assertStringNotContainsString( 'script', $mapped['description'] );
	}

	/**
	 * Событие без заголовка отбрасывается.
	 */
	public function test_map_event_no_title() {
		$this->assertNull( IDPG_Import_Mapper::map_event( array( 'starts_at' => '2026-09-10T18:00', 'id' => 'x' ), array() ) );
	}

	/**
	 * Событие без даты отбрасывается.
	 */
	public function test_map_event_no_date() {
		$this->assertNull( IDPG_Import_Mapper::map_event( array( 'title' => 'Тест', 'id' => 'x' ), array() ) );
	}

	/**
	 * Событие без external_id отбрасывается (дедупликация невозможна).
	 */
	public function test_map_event_no_external_id() {
		$this->assertNull( IDPG_Import_Mapper::map_event( array( 'title' => 'Тест', 'starts_at' => '2026-09-10' ), array() ) );
	}

	/**
	 * field_map источника имеет приоритет над синонимами.
	 */
	public function test_map_event_custom_field_map() {
		$raw = array(
			'weird_id'  => 'q-1',
			'weird_name' => 'Нестандартное имя',
			'weird_date' => '10.09.2026 18:00',
		);

		$mapped = IDPG_Import_Mapper::map_event( $raw, array(
			'field_map' => array(
				'external_id' => 'weird_id',
				'title'       => 'weird_name',
				'starts_at'   => 'weird_date',
			),
		) );

		$this->assertSame( 'q-1', $mapped['external_id'] );
		$this->assertSame( 'Нестандартное имя', $mapped['title'] );
		$this->assertSame( '2026-09-10T18:00', $mapped['starts_at'] );
	}

	/**
	 * Бесплатность распознаётся в разных форматах.
	 */
	public function test_map_event_free_formats() {
		$base = array( 'id' => 'f1', 'title' => 'Т', 'starts_at' => '2026-09-10' );

		$this->assertSame( 'yes', IDPG_Import_Mapper::map_event( $base + array( 'is_free' => true ), array() )['is_free'] );
		$this->assertSame( 'yes', IDPG_Import_Mapper::map_event( $base + array( 'is_free' => 'free' ), array() )['is_free'] );
		$this->assertSame( 'yes', IDPG_Import_Mapper::map_event( $base + array( 'is_free' => 1 ), array() )['is_free'] );
		$this->assertSame( '', IDPG_Import_Mapper::map_event( $base + array( 'is_free' => 'no' ), array() )['is_free'] );
	}

	/**
	 * Цена вытаскивается из строки с мусором.
	 */
	public function test_to_float_from_string() {
		$this->assertEqualsWithDelta( 500.0, IDPG_Import_Mapper::to_float( 'от 500 ₽' ), 0.001 );
		$this->assertEqualsWithDelta( 450.50, IDPG_Import_Mapper::to_float( '450,50' ), 0.001 );
		$this->assertNull( IDPG_Import_Mapper::to_float( 'бесплатно' ) );
	}

	/**
	 * Статусы партнёров приводятся к нашим значениям.
	 */
	public function test_map_status() {
		$this->assertSame( 'in_sale', IDPG_Import_Mapper::map_status( 'Active' ) );
		$this->assertSame( 'sold_out', IDPG_Import_Mapper::map_status( 'SOLDOUT' ) );
		$this->assertSame( 'cancelled', IDPG_Import_Mapper::map_status( 'canceled' ) );
		$this->assertSame( '', IDPG_Import_Mapper::map_status( 'что-то' ) );
	}

	/**
	 * Даты без времени сохраняются как Y-m-d.
	 */
	public function test_normalize_date_date_only() {
		$this->assertSame( '2026-09-10', IDPG_Import_Mapper::normalize_date( '2026-09-10' ) );
		$this->assertSame( '2026-09-10T18:30', IDPG_Import_Mapper::normalize_date( '10.09.2026 18:30' ) );
		$this->assertSame( '', IDPG_Import_Mapper::normalize_date( 'не дата' ) );
	}
}
