<?php
/**
 * Юнит-тесты автосоздания страницы кабинета организатора (DEV-39).
 *
 * Покрывают `idpg_core_create_organizer_page()`, `idpg_core_get_organizer_page_id()`
 * и исключение страницы из sitemap, а также идемпотентность: страница
 * создаётся один раз, повторный вызов не плодит дубликаты.
 *
 * Тема подключается тем же способом, что и в `test-theme.php`.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Organizer_Page_Creation
 */
class Test_Organizer_Page_Creation extends WP_UnitTestCase {

	/**
	 * Подключает тему, если её функции ещё не загружены.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( function_exists( 'idpg_core_create_organizer_page' ) ) {
			return;
		}

		$theme_root = getenv( 'IDPG_THEME_DIR' )
			? getenv( 'IDPG_THEME_DIR' )
			: __DIR__ . '/../../../themes/idpg_core';

		$functions = $theme_root . '/functions.php';

		if ( ! file_exists( $functions ) ) {
			$this->markTestSkipped( 'Тема idpg_core недоступна в тестовой среде.' );
		}

		$parent = dirname( $theme_root );

		add_filter(
			'theme_root',
			static function () use ( $parent ) {
				return $parent;
			}
		);
		add_filter(
			'stylesheet_root',
			static function () use ( $parent ) {
				return $parent;
			}
		);
		add_filter(
			'template_root',
			static function () use ( $parent ) {
				return $parent;
			}
		);

		register_theme_directory( $parent );

		switch_theme( 'idpg_core' );

		if ( ! function_exists( 'idpg_core_create_organizer_page' ) ) {
			require_once $functions;
		}
	}

	/**
	 * Сбрасывает состояние: удаляет опцию и возможные страницы organizer.
	 *
	 * @return void
	 */
	protected function reset_organizer_page() {
		delete_option( '_idpg_organizer_page_id' );

		foreach ( get_posts(
			array(
				'post_type'        => 'page',
				'name'             => 'organizer',
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		) as $page_id ) {
			wp_delete_post( $page_id, true );
		}
	}

	/**
	 * Страница создаётся с нужным слагом и публикуется.
	 */
	public function test_creates_page_with_slug_and_publish_status() {
		$this->reset_organizer_page();

		$this->assertNull( get_page_by_path( 'organizer' ) );

		idpg_core_create_organizer_page();

		$page = get_page_by_path( 'organizer' );

		$this->assertInstanceOf( WP_Post::class, $page );
		$this->assertSame( 'page', $page->post_type );
		$this->assertSame( 'publish', $page->post_status );
		$this->assertSame( 'organizer', $page->post_name );
	}

	/**
	 * ID созданной страницы записывается в опцию.
	 */
	public function test_creates_page_and_stores_option() {
		$this->reset_organizer_page();

		idpg_core_create_organizer_page();

		$page = get_page_by_path( 'organizer' );

		$this->assertSame( (int) $page->ID, (int) get_option( '_idpg_organizer_page_id' ) );
		$this->assertSame( (int) $page->ID, idpg_core_get_organizer_page_id() );
	}

	/**
	 * Повторный вызов не создаёт вторую страницу и не меняет ID в опции.
	 */
	public function test_repeated_call_does_not_duplicate() {
		$this->reset_organizer_page();

		idpg_core_create_organizer_page();
		$first_id = (int) get_option( '_idpg_organizer_page_id' );

		idpg_core_create_organizer_page();
		$second_id = (int) get_option( '_idpg_organizer_page_id' );

		$pages = get_posts(
			array(
				'post_type'   => 'page',
				'name'        => 'organizer',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);

		$this->assertCount( 1, $pages, 'Должна остаться ровно одна страница organizer.' );
		$this->assertSame( $first_id, $second_id );
	}

	/**
	 * Уже существующая страница со слагом organizer просто получает
	 * привязку к опции — новая запись не заводится.
	 */
	public function test_existing_page_is_reused() {
		$this->reset_organizer_page();

		$existing_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'organizer',
				'post_status' => 'publish',
			)
		);

		idpg_core_create_organizer_page();

		$this->assertSame( $existing_id, (int) get_option( '_idpg_organizer_page_id' ) );
		$this->assertSame(
			'page-organizer.php',
			get_post_meta( $existing_id, '_wp_page_template', true )
		);
	}

	/**
	 * Страница закрыта от индексации: попадает в список исключений Yoast.
	 */
	public function test_page_is_excluded_from_sitemap() {
		$this->reset_organizer_page();

		idpg_core_create_organizer_page();

		$excluded = idpg_core_exclude_organizer_page_from_sitemap( array() );

		$this->assertContains(
			idpg_core_get_organizer_page_id(),
			$excluded
		);
	}

	/**
	 * Фильтр Yoast не ломается на мусорном аргументе.
	 */
	public function test_sitemap_exclusion_tolerates_non_array() {
		$this->reset_organizer_page();

		idpg_core_create_organizer_page();

		$excluded = idpg_core_exclude_organizer_page_from_sitemap( null );

		$this->assertIsArray( $excluded );
	}

	/**
	 * Геттер восстанавливает опцию по слагу, если она потеряна
	 * (например, после импорта базы).
	 */
	public function test_get_page_id_recovers_from_slug_when_option_lost() {
		$this->reset_organizer_page();

		idpg_core_create_organizer_page();
		$expected = (int) get_option( '_idpg_organizer_page_id' );

		delete_option( '_idpg_organizer_page_id' );

		$this->assertSame( $expected, idpg_core_get_organizer_page_id() );
		$this->assertSame( $expected, (int) get_option( '_idpg_organizer_page_id' ) );
	}

	/**
	 * Геттер не берёт из опции ID не-страницы (например, удалённой записи).
	 */
	public function test_get_page_id_ignores_stale_option() {
		$this->reset_organizer_page();

		$post_id = self::factory()->post->create();

		update_option( '_idpg_organizer_page_id', $post_id );

		$this->assertSame( 0, idpg_core_get_organizer_page_id() );
	}

	/**
	 * Если страницы нет вовсе, геттер честно отдаёт 0.
	 */
	public function test_get_page_id_returns_zero_without_page() {
		$this->reset_organizer_page();

		$this->assertSame( 0, idpg_core_get_organizer_page_id() );
	}
}
