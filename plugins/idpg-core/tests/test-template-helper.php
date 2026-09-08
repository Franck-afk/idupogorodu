<?php
/**
 * Юнит-тесты хелперов шаблонов плагина IDPG Core.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Template_Helper
 */
class Test_Template_Helper extends WP_UnitTestCase {

	/**
	 * Пустой ID возвращает пустую строку кнопки.
	 */
	public function test_ticket_button_zero_id() {
		$this->assertSame( '', IDPG_Template_Helper::get_ticket_button( 0 ) );
	}

	/**
	 * Статус sold_out возвращает span с отметкой.
	 */
	public function test_ticket_button_sold_out() {
		$post_id = $this->factory()->post->create( array(
			'post_type' => 'event',
		) );
		update_post_meta( $post_id, '_idpg_ticket_status', 'sold_out' );

		$html = IDPG_Template_Helper::get_ticket_button( $post_id );
		$this->assertStringContainsString( 'idpg-sold-out', $html );
		$this->assertStringContainsString( 'sold_out', $html );
	}

	/**
	 * Статус cancelled возвращает отметку об отмене.
	 */
	public function test_ticket_button_cancelled() {
		$post_id = $this->factory()->post->create( array( 'post_type' => 'event' ) );
		update_post_meta( $post_id, '_idpg_ticket_status', 'cancelled' );

		$html = IDPG_Template_Helper::get_ticket_button( $post_id );
		$this->assertStringContainsString( 'idpg-sold-out--cancelled', $html );
	}

	/**
	 * Внешняя ссылка продажи формирует валидную кнопку.
	 */
	public function test_ticket_button_external_link() {
		$post_id = $this->factory()->post->create( array( 'post_type' => 'event' ) );
		update_post_meta( $post_id, '_idpg_ticket_status', 'in_sale' );
		update_post_meta( $post_id, '_idpg_ticket_type', 'external_link' );
		update_post_meta( $post_id, '_idpg_ticket_url', 'https://example.com/buy' );

		$html = IDPG_Template_Helper::get_ticket_button( $post_id );
		$this->assertStringContainsString( 'idpg-btn--primary', $html );
		$this->assertStringContainsString( 'https://example.com/buy', $html );
	}

	/**
	 * Бесплатное событие без ссылки возвращает отметку «Вход свободный».
	 */
	public function test_ticket_button_free() {
		$post_id = $this->factory()->post->create( array( 'post_type' => 'event' ) );
		update_post_meta( $post_id, '_idpg_ticket_status', 'in_sale' );
		update_post_meta( $post_id, '_idpg_is_free', 'yes' );

		$html = IDPG_Template_Helper::get_ticket_button( $post_id );
		$this->assertStringContainsString( 'idpg-free', $html );
	}

	/**
	 * get_event_meta возвращает корректные значения для события.
	 */
	public function test_get_event_meta() {
		$post_id = $this->factory()->post->create( array( 'post_type' => 'event' ) );
		update_post_meta( $post_id, '_idpg_ticket_status', 'in_sale' );
		update_post_meta( $post_id, '_idpg_start_date', '2026-10-01 18:00:00' );
		update_post_meta( $post_id, '_idpg_is_free', 'yes' );

		$meta = IDPG_Template_Helper::get_event_meta( $post_id );

		$this->assertIsArray( $meta );
		$this->assertSame( 'in_sale', $meta['status'] );
		$this->assertSame( '2026-10-01 18:00:00', $meta['date_start'] );
		$this->assertTrue( $meta['is_free'] );
	}

	/**
	 * get_event_meta возвращает пустой массив при нулевом ID.
	 */
	public function test_get_event_meta_zero() {
		$this->assertSame( array(), IDPG_Template_Helper::get_event_meta( 0 ) );
	}

	/**
	 * get_event_sessions парсит JSON-данные сеансов.
	 */
	public function test_get_event_sessions() {
		$post_id = $this->factory()->post->create( array( 'post_type' => 'event' ) );
		$raw     = wp_json_encode( array(
			array( 'time' => '19:00', 'price' => '1500', 'url' => 'https://example.com/t' ),
			array( 'time' => '', 'price' => '500' ),
		) );
		update_post_meta( $post_id, IDPG_Meta::SESSIONS_MIRROR, $raw );

		$sessions = IDPG_Template_Helper::get_event_sessions( $post_id );

		$this->assertIsArray( $sessions );
		$this->assertCount( 1, $sessions );
		$this->assertSame( '19:00', $sessions[0]['time'] );
		$this->assertSame( '1500', $sessions[0]['price'] );
	}

	/**
	 * get_event_sessions возвращает пустой массив без данных.
	 */
	public function test_get_event_sessions_empty() {
		$post_id = $this->factory()->post->create( array( 'post_type' => 'event' ) );
		$this->assertSame( array(), IDPG_Template_Helper::get_event_sessions( $post_id ) );
	}

	/**
	 * get_cities возвращает только города с привязанными записями (hide_empty).
	 */
	public function test_get_cities() {
		$city_a = $this->factory()->term->create( array( 'taxonomy' => 'city', 'name' => 'Город А' ) );
		$city_b = $this->factory()->term->create( array( 'taxonomy' => 'city', 'name' => 'Город Б' ) );

		// hide_empty исключает города без записей — привязываем постам.
		$post = $this->factory()->post->create( array(
			'post_type'   => 'event',
			'post_status' => 'publish',
		) );
		wp_set_post_terms( $post, array( $city_a, $city_b ), 'city' );

		$cities = IDPG_Template_Helper::get_cities();

		$this->assertIsArray( $cities );
		$this->assertContains( 'Город А', $cities );
		$this->assertContains( 'Город Б', $cities );
		$this->assertArrayHasKey( $city_a, $cities );
	}
}
