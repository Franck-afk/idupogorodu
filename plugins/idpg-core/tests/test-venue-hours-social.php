<?php
/**
 * Юнит-тесты DEV-11: часы работы и соцсети площадки.
 *
 * Проверяется:
 *  - REST-схема регистрирует зеркальные мета-ключи;
 *  - зеркальные функции mirror_venue_hours/social пишут JSON;
 *  - пустые значения дают пустые массивы (не ломают вёрстку);
 *  - JSON-зеркала читаются корректно.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Venue_Hours_Social
 */
class Test_Venue_Hours_Social extends WP_UnitTestCase {

	/**
	 * Подготовка окружения.
	 */
	public function set_up() {
		parent::set_up();
		// Регистрация мета может быть пропущена, если init уже прошёл.
		IDPG_Meta::instance()->register_meta();
	}

	/**
	 * Создать площадку с мета.
	 *
	 * @param array $meta Мета-поля.
	 * @return int ID площадки.
	 */
	protected function make_venue( array $meta = array() ) {
		$post_id = $this->factory()->post->create(
			array(
				'post_type'   => 'venue',
				'post_title'  => 'Test Venue',
				'post_status' => 'publish',
			)
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/**
	 * REST-схема содержит зеркальные ключи.
	 */
	public function test_rest_schema_has_mirror_keys() {
		$keys = get_registered_meta_keys( 'post', 'venue' );

		$this->assertArrayHasKey( '_idpg_venue_hours_json', $keys, 'REST schema for _idpg_venue_hours_json missing' );
		$this->assertArrayHasKey( '_idpg_venue_social_json', $keys, 'REST schema for _idpg_venue_social_json missing' );
		$this->assertTrue( $keys['_idpg_venue_hours_json']['show_in_rest'] );
		$this->assertTrue( $keys['_idpg_venue_social_json']['show_in_rest'] );
	}

	/**
	 * Зеркальные мета-ключи читаются как JSON-массивы.
	 */
	public function test_mirror_meta_reads_as_arrays() {
		$hours = array(
			array( 'day' => 'mon', 'open' => '09:00', 'close' => '18:00', 'is_closed' => false ),
			array( 'day' => 'sun', 'open' => '', 'close' => '', 'is_closed' => true ),
		);
		$social = array(
			array( 'network' => 'vk', 'url' => 'https://vk.com/test' ),
			array( 'network' => 'tg', 'url' => 'https://t.me/test' ),
		);

		$venue_id = $this->make_venue(
			array(
				'_idpg_venue_hours_json'  => wp_json_encode( $hours ),
				'_idpg_venue_social_json' => wp_json_encode( $social ),
			)
		);

		$hours_raw  = get_post_meta( $venue_id, '_idpg_venue_hours_json', true );
		$social_raw = get_post_meta( $venue_id, '_idpg_venue_social_json', true );

		$hours_decoded  = json_decode( $hours_raw, true );
		$social_decoded = json_decode( $social_raw, true );

		$this->assertIsArray( $hours_decoded );
		$this->assertIsArray( $social_decoded );
		$this->assertCount( 2, $hours_decoded );
		$this->assertCount( 2, $social_decoded );
		$this->assertSame( 'mon', $hours_decoded[0]['day'] );
		$this->assertTrue( $hours_decoded[1]['is_closed'] );
		$this->assertSame( 'vk', $social_decoded[0]['network'] );
	}

	/**
	 * Пустые зеркала дают пустые массивы.
	 */
	public function test_empty_mirrors_return_empty_arrays() {
		$venue_id = $this->make_venue();

		$hours_raw  = get_post_meta( $venue_id, '_idpg_venue_hours_json', true );
		$social_raw = get_post_meta( $venue_id, '_idpg_venue_social_json', true );

		$this->assertSame( '', $hours_raw );
		$this->assertSame( '', $social_raw );
	}

	/**
	 * Невалидный JSON даёт пустой массив при decode (не ломает вёрстку).
	 */
	public function test_invalid_json_decodes_to_null() {
		$venue_id = $this->make_venue(
			array(
				'_idpg_venue_hours_json'  => 'not-json',
				'_idpg_venue_social_json' => '{broken',
			)
		);

		$hours  = json_decode( get_post_meta( $venue_id, '_idpg_venue_hours_json', true ), true );
		$social = json_decode( get_post_meta( $venue_id, '_idpg_venue_social_json', true ), true );

		$this->assertNull( $hours );
		$this->assertNull( $social );
	}
}
