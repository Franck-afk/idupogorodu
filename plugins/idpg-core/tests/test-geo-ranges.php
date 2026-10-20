<?php
/**
 * Юнит-тесты DEV-305: локальная база IP → регион (242-ФЗ).
 *
 * Проверяет: таблицу диапазонов, преобразование IP, поиск региона,
 * импорт CSV DB-IP, работу lookup_city_id_by_ip через локальную базу,
 * кэш, фолбэк services=none и отсутствие внешних вызовов.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Geo_Ranges
 */
class Test_Geo_Ranges extends WP_UnitTestCase {

	/**
	 * Бэкап $_SERVER.
	 *
	 * @var array
	 */
	private $server_backup = array();

	/**
	 * Подготовка: создаём таблицу диапазонов.
	 */
	public function set_up() {
		parent::set_up();

		$this->server_backup = $_SERVER;

		// Тесты не запускают activation hook — создаём таблицу сами.
		idpg_core_create_geo_ranges_table();
		IDPG_Geo_Ranges::table_exists( true );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'TRUNCATE TABLE ' . IDPG_Geo_Ranges::table_name() );
	}

	/**
	 * Восстановление $_SERVER.
	 */
	public function tear_down() {
		$_SERVER = $this->server_backup;
		parent::tear_down();
	}

	/**
	 * Вставка диапазона в таблицу.
	 *
	 * @param int    $start  Начало.
	 * @param int    $end    Конец.
	 * @param string $region Регион.
	 */
	private function insert_range( $start, $end, $region ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			IDPG_Geo_Ranges::table_name(),
			array(
				'ip_start' => $start,
				'ip_end'   => $end,
				'region'   => $region,
			),
			array( '%d', '%d', '%s' )
		);
	}

	/**
	 * Таблица диапазонов создаётся.
	 */
	public function test_geo_ranges_table_created() {
		$this->assertTrue( IDPG_Geo_Ranges::table_exists( true ) );
	}

	/**
	 * IPv4 корректно преобразуется в целое.
	 */
	public function test_ip_to_long_ipv4() {
		$this->assertSame( 0, IDPG_Geo_Ranges::ip_to_long( '0.0.0.0' ) );
		$this->assertSame( 3232235777, IDPG_Geo_Ranges::ip_to_long( '192.168.1.1' ) );
		$this->assertSame( 4294967295, IDPG_Geo_Ranges::ip_to_long( '255.255.255.255' ) );
	}

	/**
	 * IPv6 и мусор не поддерживаются — возвращается -1.
	 */
	public function test_ip_to_long_rejects_ipv6_and_garbage() {
		$this->assertSame( -1, IDPG_Geo_Ranges::ip_to_long( '2001:0db8::1' ) );
		$this->assertSame( -1, IDPG_Geo_Ranges::ip_to_long( 'not-an-ip' ) );
		$this->assertSame( -1, IDPG_Geo_Ranges::ip_to_long( '' ) );
	}

	/**
	 * Регион находится по IP внутри диапазона.
	 */
	public function test_lookup_region_in_range() {
		$start = IDPG_Geo_Ranges::ip_to_long( '10.0.0.0' );
		$end   = IDPG_Geo_Ranges::ip_to_long( '10.0.0.255' );

		$this->insert_range( $start, $end, 'Udmurt Republic' );

		$this->assertSame( 'Udmurt Republic', IDPG_Geo_Ranges::lookup_region( '10.0.0.5' ) );
		$this->assertSame( 'Udmurt Republic', IDPG_Geo_Ranges::lookup_region( '10.0.0.255' ) );
	}

	/**
	 * IP вне диапазона не находит регион.
	 */
	public function test_lookup_region_out_of_range() {
		$start = IDPG_Geo_Ranges::ip_to_long( '10.0.0.0' );
		$end   = IDPG_Geo_Ranges::ip_to_long( '10.0.0.255' );

		$this->insert_range( $start, $end, 'Udmurt Republic' );

		$this->assertSame( '', IDPG_Geo_Ranges::lookup_region( '10.0.1.1' ) );
	}

	/**
	 * Пустая таблица — регион не определяется, lookup даёт fail-кэш.
	 */
	public function test_lookup_empty_table_is_fail() {
		$this->assertSame( '', IDPG_Geo_Ranges::lookup_region( '8.8.8.8' ) );

		$term = IDPG_City_Filter::lookup_city_id_by_ip( '8.8.8.8' );
		$this->assertSame( 0, $term );

		$cached = IDPG_City_Filter::get_ip_detection( '8.8.8.8' );
		$this->assertIsArray( $cached );
		$this->assertSame( 'fail', $cached['status'] );
	}

	/**
	 * Отсутствующая таблица — lookup_region возвращает пусто и не падает.
	 */
	public function test_lookup_missing_table_is_empty() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE ' . IDPG_Geo_Ranges::table_name() );
		IDPG_Geo_Ranges::table_exists( true );

		$this->assertFalse( IDPG_Geo_Ranges::table_exists() );

		$wpdb->last_error = '';
		$this->assertSame( '', IDPG_Geo_Ranges::lookup_region( '8.8.8.8' ) );
		// Guard в lookup_region() не даёт выйти запросу к несуществующей таблице.
		$this->assertSame( '', $wpdb->last_error );

		$wpdb->last_error = '';
		$this->assertSame( 0, IDPG_Geo_Ranges::count() );
		$this->assertSame( '', $wpdb->last_error );
	}

	/**
	 * Разбор строки DB-IP: RU, регион и границы.
	 */
	public function test_parse_csv_line_valid() {
		$row = IDPG_Geo_Ranges::parse_csv_line( '10.0.0.0,10.0.0.255,RU,Russia,Udmurt Republic,Izhevsk' );

		$this->assertIsArray( $row );
		$this->assertSame( 'Udmurt Republic', $row['region'] );
		$this->assertSame( IDPG_Geo_Ranges::ip_to_long( '10.0.0.0' ), $row['start'] );
		$this->assertSame( IDPG_Geo_Ranges::ip_to_long( '10.0.0.255' ), $row['end'] );
	}

	/**
	 * Строка другой страны пропускается.
	 */
	public function test_parse_csv_line_skips_non_ru() {
		$this->assertNull( IDPG_Geo_Ranges::parse_csv_line( '10.0.0.0,10.0.0.255,US,United States,California,Los Angeles' ) );
	}

	/**
	 * Битые и пустые строки пропускаются.
	 */
	public function test_parse_csv_line_skips_garbage() {
		$this->assertNull( IDPG_Geo_Ranges::parse_csv_line( '' ) );
		$this->assertNull( IDPG_Geo_Ranges::parse_csv_line( 'just text' ) );
		$this->assertNull( IDPG_Geo_Ranges::parse_csv_line( '10.0.0.255,10.0.0.0,RU,Russia,Region' ) );
	}

	/**
	 * Импорт CSV наполняет таблицу и считает строки.
	 */
	public function test_import_csv() {
		$csv = "10.0.0.0,10.0.0.255,RU,Russia,Udmurt Republic,Izhevsk\n"
			. "10.0.1.0,10.0.1.255,US,United States,California,Los Angeles\n"
			. "10.0.2.0,10.0.2.255,RU,Russia,Moscow,Moscow\n";

		$path = tempnam( sys_get_temp_dir(), 'geo' );
		file_put_contents( $path, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = IDPG_Geo_Ranges::import_csv( $path );
		wp_delete_file( $path );

		$this->assertSame( 2, $result['rows'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 2, IDPG_Geo_Ranges::count() );
		// Регион переведён из англ. (DB-IP) в рус. (карта алиасов).
		$this->assertSame( 'Москва', IDPG_Geo_Ranges::lookup_region( '10.0.2.10' ) );
	}

	/**
	 * Регион из выгрузки переводится в русское название.
	 */
	public function test_localize_region() {
		$this->assertSame( 'Удмуртская Республика', IDPG_Geo_Ranges::localize_region( 'Udmurt Republic' ) );
		$this->assertSame( 'Неизвестный край', IDPG_Geo_Ranges::localize_region( 'Неизвестный край' ) );
	}

	/**
	 * Lookup_city_id_by_ip использует локальную базу: регион → термин города.
	 */
	public function test_lookup_uses_local_db() {
		$region = self::factory()->term->create(
			array(
				'taxonomy' => 'city',
				'name'     => 'Удмуртская Республика',
			)
		);
		self::factory()->term->create(
			array(
				'taxonomy' => 'city',
				'name'     => 'Ижевск',
				'parent'   => $region,
			)
		);

		$start = IDPG_Geo_Ranges::ip_to_long( '11.0.0.0' );
		$end   = IDPG_Geo_Ranges::ip_to_long( '11.0.0.255' );
		$this->insert_range( $start, $end, 'Удмуртская Республика' );

		$term_id = IDPG_City_Filter::lookup_city_id_by_ip( '11.0.0.5' );

		$this->assertGreaterThan( 0, $term_id );

		$cached = IDPG_City_Filter::get_ip_detection( '11.0.0.5' );
		$this->assertSame( 'ok', $cached['status'] );
		$this->assertSame( $term_id, (int) $cached['term_id'] );
	}

	/**
	 * Фолбэк services=none: lookup не выполняет поиск и возвращает 0.
	 */
	public function test_allowed_ip_services_none() {
		$this->assertSame( 0, IDPG_City_Filter::lookup_city_id_by_ip( '8.8.8.8', array( 'none' ) ) );
	}

	/**
	 * Регион из БД сопоставляется с термином-регионом (короткая/полная форма).
	 */
	public function test_match_region_from_db() {
		$this->assertTrue(
			IDPG_City_Filter::region_names_match( 'Удмуртия', 'Удмуртская Республика' )
		);
	}

	/**
	 * DEV-305: в гео-модулях нет внешних вызовов (IP не уходит за рубеж).
	 */
	public function test_no_external_geo_calls() {
		$files = array(
			IDPG_CORE_DIR . 'includes/class-city-filter.php',
			IDPG_CORE_DIR . 'includes/class-geo-ranges.php',
			IDPG_CORE_DIR . 'includes/class-city-selector-widget.php',
		);

		foreach ( $files as $file ) {
			$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			$this->assertDoesNotMatchRegularExpression( '/ip-api\.com|ipapi\.co/', $contents, "Внешний geo-сервис найден в {$file}" );
			$this->assertDoesNotMatchRegularExpression( '/wp_remote_get/', $contents, "HTTP-запрос во внешний сервис найден в {$file}" );
		}
	}

	/**
	 * Вкладка «Гео (IP→регион)» регистрируется фильтром.
	 */
	public function test_geo_tab_registered() {
		$tabs = apply_filters( 'idpg_widget_areas_admin_tabs', array() );

		$this->assertArrayHasKey( 'geo', $tabs );
		$this->assertIsCallable( $tabs['geo']['callback'] );
	}

	/**
	 * Вкладка не рендерит контент без прав manage_options.
	 */
	public function test_geo_tab_requires_manage_options() {
		wp_set_current_user( 0 );

		$this->assertEmpty(
			$this->render_tab(),
			'Вкладка не должна рендериться без manage_options.'
		);
	}

	/**
	 * Импорт защищён nonce: без него ничего не происходит.
	 */
	public function test_import_requires_nonce() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$_POST['idpg_geo_action'] = 'import';

		$geo = IDPG_Geo_Ranges::instance();

		$this->expectException( 'WPDieException' );
		$geo->handle_import();
	}

	/**
	 * Рендер вкладки в буфер.
	 *
	 * @return string
	 */
	private function render_tab() {
		ob_start();
		IDPG_Geo_Ranges::instance()->render_admin_tab();
		return ob_get_clean();
	}
}
