<?php
/**
 * Юнит-тесты меню личного кабинета организатора (DEV-38).
 *
 * Покрывают `themes/idpg_core/inc/organizer-menu.php`: состав и порядок
 * разделов, фильтр `idpg_organizer_menu_items`, отсев некорректных
 * пунктов и адреса разделов.
 *
 * Тема подключается тем же способом, что и в `test-theme.php`: если её
 * функции ещё не загружены, регистрируем папку темы и подключаем
 * functions.php. Если файла темы нет (чистая установка плагина), тесты
 * пропускаются, а не падают.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Organizer_Menu
 */
class Test_Organizer_Menu extends WP_UnitTestCase {

	/**
	 * Подключает тему, если её функции ещё не загружены.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( function_exists( 'idpg_organizer_menu' ) ) {
			return;
		}

		$theme_root = getenv( 'IDPG_THEME_DIR' )
			? getenv( 'IDPG_THEME_DIR' )
			: __DIR__ . '/../../../themes/idpg_core';

		$functions = $theme_root . '/functions.php';

		if ( ! file_exists( $functions ) ) {
			$this->markTestSkipped( 'Тема idpg_core недоступна в тестовой среде.' );
		}

		// Регистрируем родительскую папку темы, чтобы WordPress её увидел.
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

		if ( ! function_exists( 'idpg_organizer_menu' ) ) {
			require_once $functions;
		}
	}

	/**
	 * Меню по умолчанию содержит четыре раздела кабинета.
	 *
	 * Раздел «Площадки» добавлен DEV-50 и стоит между «Событиями» и
	 * «Профилем»: он относится к подготовке события, как и сам список
	 * событий.
	 */
	public function test_menu_default_sections() {
		$items = idpg_organizer_menu();

		$this->assertSame(
			array( 'dashboard', 'events', 'venues', 'profile' ),
			array_keys( $items )
		);
	}

	/**
	 * Пункты меню отсортированы по полю `order`.
	 */
	public function test_menu_is_sorted_by_order() {
		$items = idpg_organizer_menu();
		$order = array_values( wp_list_pluck( $items, 'order' ) );

		$sorted = $order;
		sort( $sorted );

		$this->assertSame( $sorted, $order );
		$this->assertSame( 10, $items['dashboard']['order'] );
		$this->assertSame( 20, $items['events']['order'] );
		$this->assertSame( 25, $items['venues']['order'] );
		$this->assertSame( 30, $items['profile']['order'] );
	}

	/**
	 * Фильтр может добавить свой раздел, и он встаёт по своему `order`.
	 */
	public function test_menu_items_filter_adds_section() {
		add_filter(
			'idpg_organizer_menu_items',
			static function ( $items ) {
				$items['billing'] = array(
					'slug'  => 'billing',
					'label' => 'Оплата',
					'url'   => home_url( '/organizer/billing/' ),
					'icon'  => '₽',
					'order' => 5,
				);

				return $items;
			}
		);

		$items = idpg_organizer_menu();

		$this->assertArrayHasKey( 'billing', $items );
		$this->assertSame( 'billing', array_key_first( $items ) );
		$this->assertSame( 'Оплата', $items['billing']['label'] );
	}

	/**
	 * Фильтр может убрать штатный раздел.
	 */
	public function test_menu_items_filter_removes_section() {
		add_filter(
			'idpg_organizer_menu_items',
			static function ( $items ) {
				unset( $items['events'] );

				return $items;
			}
		);

		$items = idpg_organizer_menu();

		$this->assertArrayNotHasKey( 'events', $items );
		$this->assertArrayHasKey( 'dashboard', $items );
		$this->assertArrayHasKey( 'profile', $items );
	}

	/**
	 * Если фильтр вернул не массив, меню остаётся пустым, а не падает.
	 */
	public function test_menu_items_filter_non_array_yields_empty_menu() {
		add_filter( 'idpg_organizer_menu_items', '__return_empty_string' );

		$this->assertSame( array(), idpg_organizer_menu() );
	}

	/**
	 * Пункты без слага или без подписи отбрасываются.
	 */
	public function test_menu_items_without_slug_or_label_dropped() {
		add_filter(
			'idpg_organizer_menu_items',
			static function () {
				return array(
					'good'   => array(
						'slug'  => 'good',
						'label' => 'Годный',
					),
					'no_slug' => array(
						'label' => 'Без слага',
					),
					'no_label' => array(
						'slug' => 'no_label',
					),
					'not_array' => 'строка',
				);
			}
		);

		$items = idpg_organizer_menu();

		$this->assertSame( array( 'good' ), array_keys( $items ) );
	}

	/**
	 * Компаратор сортировки сам по себе.
	 */
	public function test_menu_sort_comparator() {
		$low  = array(
			'order' => 10,
		);
		$high = array(
			'order' => 20,
		);

		$this->assertSame( -1, idpg_organizer_menu_sort( $low, $high ) );
		$this->assertSame( 1, idpg_organizer_menu_sort( $high, $low ) );
		$this->assertSame( 0, idpg_organizer_menu_sort( $low, array( 'order' => 10 ) ) );
	}

	/**
	 * Отсутствующий `order` считается равным 10.
	 */
	public function test_menu_sort_defaults_missing_order() {
		$this->assertSame( 0, idpg_organizer_menu_sort( array(), array( 'order' => 10 ) ) );
		$this->assertSame( -1, idpg_organizer_menu_sort( array(), array( 'order' => 20 ) ) );
	}

	/**
	 * Адрес раздела дашборда ведёт в корень кабинета.
	 */
	public function test_section_url_dashboard() {
		$this->assertSame( home_url( '/organizer/' ), idpg_organizer_section_url( 'dashboard' ) );
	}

	/**
	 * Адрес раздела событий.
	 */
	public function test_section_url_events() {
		$this->assertSame( home_url( '/organizer/events/' ), idpg_organizer_section_url( 'events' ) );
	}

	/**
	 * Адрес раздела профиля.
	 */
	public function test_section_url_profile() {
		$this->assertSame( home_url( '/organizer/profile/' ), idpg_organizer_section_url( 'profile' ) );
	}

	/**
	 * Мусорный слаг не подставляется в адрес, а откатывается на раздел
	 * по умолчанию: раздел нельзя вывести за пределы кабинета.
	 */
	public function test_section_url_invalid_slug_falls_back_to_default() {
		$this->assertSame(
			home_url( '/organizer/' ),
			idpg_organizer_section_url( 'events/?x=1' )
		);
	}

	/**
	 * Пустой слаг — это корень кабинета.
	 */
	public function test_section_url_empty_is_cabinet_root() {
		$this->assertSame( home_url( '/organizer/' ), idpg_organizer_section_url( '' ) );
	}

	/**
	 * Список слагов разделов совпадает с составом меню.
	 */
	public function test_menu_sections_returns_slugs() {
		$this->assertSame(
			array( 'dashboard', 'events', 'venues', 'profile' ),
			idpg_organizer_menu_sections()
		);
	}

	// =========================================================================
	// Адрес формы события (G5)
	// =========================================================================

	/**
	 * Без ID — адрес создания нового события.
	 */
	public function test_event_form_url_new() {
		$this->assertSame( home_url( '/organizer/event/new/' ), idpg_organizer_event_form_url() );
		$this->assertSame( home_url( '/organizer/event/new/' ), idpg_organizer_event_form_url( 0 ) );
	}

	/**
	 * С ID — адрес правки существующего события.
	 */
	public function test_event_form_url_existing() {
		$this->assertSame( home_url( '/organizer/event/42/' ), idpg_organizer_event_form_url( 42 ) );
	}

	/**
	 * Мусорный ID не попадает в адрес и не ломает маршрут.
	 */
	public function test_event_form_url_sanitizes_id() {
		// Нечисловой мусор не попадает в адрес.
		$this->assertSame( home_url( '/organizer/event/new/' ), idpg_organizer_event_form_url( 'abc' ) );

		// Числовая строка — обычный ID, а не мусор.
		$this->assertSame( home_url( '/organizer/event/7/' ), idpg_organizer_event_form_url( '7' ) );
	}

	/**
	 * Адрес формы согласован с адресом, который отдаёт плагин: разделы
	 * вызывают эти хелперы из шаблонов, и расхождение дало бы битые ссылки.
	 */
	public function test_event_form_url_matches_plugin_helper() {
		$this->assertSame(
			IDPG_Organizer_URL::event_form_url( 0 ),
			idpg_organizer_event_form_url()
		);

		$this->assertSame(
			IDPG_Organizer_URL::event_form_url( 15 ),
			idpg_organizer_event_form_url( 15 )
		);
	}
}
