<?php
/**
 * Юнит-тесты функций темы idpg_core.
 *
 * Тема подключается только тогда, когда она реально активна в тестовой
 * среде (get_template_directory() указывает на неё). В противном случае
 * тесты пропускаются, чтобы не грузить functions.php зависимостями
 * от активной темы среды разработки.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Theme
 */
class Test_Theme extends WP_UnitTestCase {

	/**
	 * Подключение functions.php темы при активной теме idpg_core.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! function_exists( 'idpg_core_disable_parent_menu_items' ) ) {
			$theme_root = getenv( 'IDPG_THEME_DIR' )
				? getenv( 'IDPG_THEME_DIR' )
				: __DIR__ . '/../../../themes/idpg_core';

			// Регистрируем родительскую папку темы, чтобы WordPress увидел тему.
			$parent = dirname( $theme_root );
			add_filter( 'theme_root', function () use ( $parent ) {
				return $parent;
			} );
			add_filter( 'stylesheet_root', function () use ( $parent ) {
				return $parent;
			} );
			add_filter( 'template_root', function () use ( $parent ) {
				return $parent;
			} );
			register_theme_directory( $parent );

			switch_theme( 'idpg_core' );

			if ( ! function_exists( 'idpg_core_disable_parent_menu_items' ) ) {
				require_once $theme_root . '/functions.php';
			}
		}
	}

	/**
	 * Родительский пункт /afisha/ с подменю становится неактивным.
	 */
	public function test_disable_parent_menu_items_afisha() {
		$item = new WP_Post( (object) array(
			'ID'      => 1,
			'url'     => 'https://example.com/afisha/',
			'classes' => array( 'menu-item-has-children' ),
		) );

		$result = idpg_core_disable_parent_menu_items( array( $item ) );

		$this->assertSame( '', $result[0]->url );
		$this->assertContains( 'idpg-nav__item--disabled', $result[0]->classes );
	}

	/**
	 * Родительский пункт /venues/ также отключается.
	 */
	public function test_disable_parent_menu_items_venues() {
		$item = new WP_Post( (object) array(
			'ID'      => 2,
			'url'     => 'https://example.com/venues/',
			'classes' => array( 'menu-item-has-children' ),
		) );

		$result = idpg_core_disable_parent_menu_items( array( $item ) );

		$this->assertSame( '', $result[0]->url );
		$this->assertContains( 'idpg-nav__item--disabled', $result[0]->classes );
	}

	/**
	 * Обычный пункт (не parent) не трогается.
	 */
	public function test_disable_parent_menu_items_regular() {
		$item = new WP_Post( (object) array(
			'ID'      => 3,
			'url'     => 'https://example.com/about/',
			'classes' => array( 'menu-item' ),
		) );

		$result = idpg_core_disable_parent_menu_items( array( $item ) );

		$this->assertSame( 'https://example.com/about/', $result[0]->url );
		$this->assertNotContains( 'idpg-nav__item--disabled', $result[0]->classes );
	}

	/**
	 * Пункт с другим URL не отключается, даже если имеет подменю.
	 */
	public function test_disable_parent_menu_items_other_url() {
		$item = new WP_Post( (object) array(
			'ID'      => 4,
			'url'     => 'https://example.com/contact/',
			'classes' => array( 'menu-item-has-children' ),
		) );

		$result = idpg_core_disable_parent_menu_items( array( $item ) );

		$this->assertSame( 'https://example.com/contact/', $result[0]->url );
	}

	/**
	 * Константа версии темы определена (если тема активна).
	 */
	public function test_idpg_version_constant() {
		$this->assertTrue( defined( 'IDPG_VERSION' ) );
	}
}
