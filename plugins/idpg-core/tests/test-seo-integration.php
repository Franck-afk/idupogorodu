<?php
/**
 * Юнит-тесты SEO-интеграции с Yoast (IDPG_SEO_Integration).
 *
 * Builders — чистые методы: тестируются без активного Yoast.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_SEO_Integration
 */
class Test_SEO_Integration extends WP_UnitTestCase {

	/**
	 * Экземпляр модуля.
	 *
	 * @var IDPG_SEO_Integration
	 */
	private $seo;

	/**
	 * Подготовка окружения.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->seo = IDPG_SEO_Integration::instance();
	}

	/**
	 * Вспомогательный метод: создать площадку с координатами.
	 *
	 * @param string $address Адрес площадки.
	 * @param string $lat     Широта.
	 * @param string $lng     Долгота.
	 * @return int ID площадки.
	 */
	private function create_venue( $address = 'ул. Тестовая, 1', $lat = '56.8527', $lng = '53.2115' ) {
		$venue_id = $this->factory()->post->create( array(
			'post_type'   => 'venue',
			'post_status' => 'publish',
			'post_title'  => 'ДК Тестовый',
		) );

		update_post_meta( $venue_id, '_idpg_venue_address', $address );
		update_post_meta( $venue_id, '_idpg_venue_lat', $lat );
		update_post_meta( $venue_id, '_idpg_venue_lng', $lng );
		update_post_meta( $venue_id, '_idpg_venue_phone', '+7 900 000-00-00' );

		return $venue_id;
	}

	/**
	 * Вспомогательный метод: создать событие с привязкой к площадке.
	 *
	 * @param int $venue_id ID площадки.
	 * @return int ID события.
	 */
	private function create_event( $venue_id ) {
		$event_id = $this->factory()->post->create( array(
			'post_type'   => 'event',
			'post_status' => 'publish',
			'post_title'  => 'Тестовый концерт',
		) );

		update_post_meta( $event_id, '_idpg_venue', $venue_id );
		update_post_meta( $event_id, '_idpg_start_date', '2026-09-10T18:00' );
		update_post_meta( $event_id, '_idpg_price_from', '500' );
		update_post_meta( $event_id, '_idpg_ticket_url', 'https://tickets.example.com/test' );
		update_post_meta( $event_id, '_idpg_ticket_status', 'in_sale' );

		return $event_id;
	}

	/**
	 * build_geo_node возвращает GeoCoordinates для валидных координат.
	 */
	public function test_build_geo_node_valid() {
		$venue_id = $this->create_venue();

		$geo = $this->seo->build_geo_node( $venue_id );

		$this->assertIsArray( $geo );
		$this->assertSame( 'GeoCoordinates', $geo['@type'] );
		$this->assertEqualsWithDelta( 56.8527, $geo['latitude'], 0.0001 );
		$this->assertEqualsWithDelta( 53.2115, $geo['longitude'], 0.0001 );
	}

	/**
	 * build_geo_node отклоняет мусор вместо координат.
	 */
	public function test_build_geo_node_invalid() {
		$venue_id = $this->create_venue( 'ул. Тестовая, 2', 'abc', 'xyz' );

		$this->assertNull( $this->seo->build_geo_node( $venue_id ) );
	}

	/**
	 * build_geo_node отклоняет координаты вне диапазона (широта > 90).
	 */
	public function test_build_geo_node_out_of_range() {
		$venue_id = $this->create_venue( 'ул. Тестовая, 3', '95.5', '53.2' );

		$this->assertNull( $this->seo->build_geo_node( $venue_id ) );
	}

	/**
	 * build_geo_node возвращает null без площадки.
	 */
	public function test_build_geo_node_empty_id() {
		$this->assertNull( $this->seo->build_geo_node( 0 ) );
	}

	/**
	 * build_venue_schema собирает Place с адресом, городом и гео.
	 */
	public function test_build_venue_schema() {
		$city   = $this->factory()->term->create( array(
			'taxonomy' => 'city',
			'name'     => 'Ижевск',
		) );
		$venue_id = $this->create_venue();

		wp_set_post_terms( $venue_id, array( $city ), 'city' );

		$schema = $this->seo->build_venue_schema( $venue_id );

		$this->assertIsArray( $schema );
		$this->assertSame( 'Place', $schema['@type'] );
		$this->assertSame( 'ДК Тестовый', $schema['name'] );
		$this->assertSame( 'Ижевск', $schema['address']['addressLocality'] );
		$this->assertSame( 'ул. Тестовая, 1', $schema['address']['streetAddress'] );
		$this->assertSame( '+7 900 000-00-00', $schema['telephone'] );
		$this->assertSame( 'GeoCoordinates', $schema['geo']['@type'] );
	}

	/**
	 * build_venue_schema возвращает null для не-площадки.
	 */
	public function test_build_venue_schema_wrong_post_type() {
		$post_id = $this->factory()->post->create( array( 'post_type' => 'post' ) );

		$this->assertNull( $this->seo->build_venue_schema( $post_id ) );
	}

	/**
	 * build_event_schema собирает Event с местом, гео и оффером.
	 */
	public function test_build_event_schema() {
		$city   = $this->factory()->term->create( array(
			'taxonomy' => 'city',
			'name'     => 'Ижевск',
		) );
		$venue_id = $this->create_venue();
		$event_id = $this->create_event( $venue_id );

		wp_set_post_terms( $event_id, array( $city ), 'city' );

		$schema = $this->seo->build_event_schema( $event_id );

		$this->assertIsArray( $schema );
		$this->assertSame( 'Event', $schema['@type'] );
		$this->assertSame( 'Тестовый концерт', $schema['name'] );
		$this->assertSame( '2026-09-10T18:00', $schema['startDate'] );

		// Место проведения — площадка с гео и городом.
		$this->assertSame( 'Place', $schema['location']['@type'] );
		$this->assertSame( 'ДК Тестовый', $schema['location']['name'] );
		$this->assertSame( 'Ижевск', $schema['location']['address']['addressLocality'] );
		$this->assertSame( 'GeoCoordinates', $schema['location']['geo']['@type'] );

		// Оффер с ценой и наличием.
		$this->assertSame( 'Offer', $schema['offers']['@type'] );
		$this->assertEqualsWithDelta( 500, $schema['offers']['price'], 0.01 );
		$this->assertSame( 'https://schema.org/InStock', $schema['offers']['availability'] );
	}

	/**
	 * build_offer_node возвращает null без цены, бесплатности и билетов.
	 */
	public function test_build_offer_node_empty() {
		$event_id = $this->factory()->post->create( array(
			'post_type'   => 'event',
			'post_status' => 'publish',
		) );

		$this->assertNull( $this->seo->build_offer_node( $event_id ) );
	}

	/**
	 * build_offer_node помечает бесплатные события ценой 0 RUB.
	 */
	public function test_build_offer_node_free() {
		$event_id = $this->factory()->post->create( array(
			'post_type'   => 'event',
			'post_status' => 'publish',
		) );

		update_post_meta( $event_id, '_idpg_is_free', 'yes' );

		$offer = $this->seo->build_offer_node( $event_id );

		$this->assertIsArray( $offer );
		$this->assertSame( 0, $offer['price'] );
		$this->assertSame( 'RUB', $offer['priceCurrency'] );
	}

	/**
	 * build_place_node возвращает null без площадки и адреса.
	 */
	public function test_build_place_node_empty() {
		$event_id = $this->factory()->post->create( array(
			'post_type'   => 'event',
			'post_status' => 'publish',
		) );

		$this->assertNull( $this->seo->build_place_node( $event_id ) );
	}

	/**
	 * Модуль не создаёт экземпляр-хуки при неактивном Yoast
	 * (is_yoast_active проверяем отдельно от состояния окружения).
	 */
	public function test_is_yoast_active_returns_bool() {
		$this->assertIsBool( $this->seo->is_yoast_active() );
	}
}