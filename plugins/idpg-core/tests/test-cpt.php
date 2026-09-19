<?php
/**
 * Юнит-тесты регистрации CPT и таксономий плагина IDPG Core.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_CPT
 */
class Test_CPT extends WP_UnitTestCase {

	/**
	 * Пользовательские типы записей регистрируются при загрузке плагина.
	 */
	public function test_event_post_type_registered() {
		$this->assertTrue( post_type_exists( 'event' ) );
		$this->assertTrue( post_type_exists( 'venue' ) );
	}

	/**
	 * Таксономии регистрируются и привязаны к CPT.
	 */
	public function test_taxonomies_registered() {
		$this->assertTrue( taxonomy_exists( 'event_category' ) );
		$this->assertTrue( taxonomy_exists( 'age_rating' ) );
		$this->assertTrue( taxonomy_exists( 'city' ) );
	}

	/**
	 * Таксономия city привязана и к event, и к venue.
	 */
	public function test_city_taxonomy_object_types() {
		$tax = get_taxonomy( 'city' );
		$this->assertIsArray( $tax->object_type );
		$this->assertContains( 'event', $tax->object_type );
		$this->assertContains( 'venue', $tax->object_type );
	}

	/**
	 * Создание события сохраняет пост и связанные таксономии.
	 */
	public function test_create_event_with_taxonomy() {
		$city_id = $this->factory()->term->create( array(
			'taxonomy' => 'city',
			'name'     => 'Тестовый город',
		) );

		$post_id = $this->factory()->post->create( array(
			'post_type'   => 'event',
			'post_status' => 'publish',
			'post_title'  => 'Тестовое событие',
		) );

		wp_set_post_terms( $post_id, array( $city_id ), 'city' );

		$this->assertSame( 'event', get_post_type( $post_id ) );
		$terms = wp_get_post_terms( $post_id, 'city', array( 'fields' => 'ids' ) );
		$this->assertContains( $city_id, $terms );
	}

	/**
	 * Создание площадки сохраняется в CPT venue.
	 */
	public function test_create_venue() {
		$post_id = $this->factory()->post->create( array(
			'post_type'   => 'venue',
			'post_status' => 'publish',
			'post_title'  => 'Тестовая площадка',
		) );

		$this->assertSame( 'venue', get_post_type( $post_id ) );
	}

	/**
	 * Архив события доступен.
	 */
	public function test_event_has_archive() {
		$pt = get_post_type_object( 'event' );
		$this->assertTrue( $pt->has_archive );
	}
}
