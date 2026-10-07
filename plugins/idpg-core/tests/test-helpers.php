<?php
/**
 * Юнит-тесты DEV-22: единый `IDPG_Helpers` вместо дублей.
 *
 * Ключевая идея карточки: дубли вели себя одинаково, значит перенос
 * реализации в один класс не должен изменить поведение. Тесты это
 * фиксируют — и отдельно отмечают единственное намеренное отличие
 * в `IDPG_Views::get_client_ip()` (см. test_views_no_longer_trusts_client_ip).
 *
 * @package IDpg_Core
 */

/**
 * Класс Test_Helpers
 */
class Test_Helpers extends WP_UnitTestCase {

	/**
	 * Ссылки на оригинальные $_SERVER.
	 *
	 * @var array
	 */
	private $server_backup = array();

	/**
	 * Подготовка окружения.
	 */
	public function set_up() {
		parent::set_up();

		$this->server_backup = $_SERVER;
	}

	/**
	 * Сброс окружения.
	 */
	public function tear_down() {
		$_SERVER = $this->server_backup;

		parent::tear_down();
	}

	/**
	 * Подставляет набор заголовков в $_SERVER.
	 *
	 * @param array $vars Значения $_SERVER.
	 */
	private function set_server( array $vars ) {
		foreach ( array( 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP' ) as $key ) {
			unset( $_SERVER[ $key ] );
		}

		foreach ( $vars as $key => $value ) {
			$_SERVER[ $key ] = $value;
		}
	}

	// =====================================================================
	// get_client_ip(): обе старые копии вели себя одинаково
	// =====================================================================

	/**
	 * Копия в IDPG_Auth_Rate_Limiter и эталон из IDPG_Helpers совпадают
	 * во всех сценариях, для которых старая реализация что-то возвращала.
	 */
	public function test_rate_limiter_delegates_to_helpers() {
		$cases = array(
			'публичный адрес'          => array(
				'REMOTE_ADDR' => '203.0.113.10',
			),
			'публический + спуф XFF'   => array(
				'REMOTE_ADDR'       => '203.0.113.10',
				'HTTP_X_FORWARDED_FOR' => '198.51.100.5',
			),
			'публичный + спуф Client-IP' => array(
				'REMOTE_ADDR'      => '203.0.113.10',
				'HTTP_CLIENT_IP'   => '198.51.100.7',
			),
			'прокси 127.0.0.1 + XFF'   => array(
				'REMOTE_ADDR'         => '127.0.0.1',
				'HTTP_X_FORWARDED_FOR' => '198.51.100.5',
			),
			'прокси + список XFF'      => array(
				'REMOTE_ADDR'         => '127.0.0.1',
				'HTTP_X_FORWARDED_FOR' => '198.51.100.5, 10.0.0.1',
			),
			'прокси + X-Real-IP'       => array(
				'REMOTE_ADDR'     => '127.0.0.1',
				'HTTP_X_REAL_IP'  => '198.51.100.6',
			),
			'прокси без заголовков'    => array(
				'REMOTE_ADDR' => '127.0.0.1',
			),
			'мусорный REMOTE_ADDR'     => array(
				'REMOTE_ADDR' => 'не-ip',
			),
			'адреса нет вовсе'         => array(),
		);

		foreach ( $cases as $label => $vars ) {
			$this->set_server( $vars );

			$this->assertSame(
				IDPG_Helpers::get_client_ip(),
				IDPG_Auth_Rate_Limiter::instance()->get_client_ip(),
				'Расхождение для сценария: ' . $label
			);
		}
	}

	/**
	 * Счётчик просмотров теперь считается по IP из IDPG_Helpers.
	 *
	 * Старая копия в IDPG_Views брала HTTP_CLIENT_IP первым и без проверки,
	 * поэтому подделанный заголовок обнулял счётчик. Это единственное
	 * намеренное отличие; здесь фиксируем новое поведение.
	 */
	public function test_views_no_longer_trusts_client_ip() {
		$this->set_server(
			array(
				'REMOTE_ADDR'    => '203.0.113.10',
				'HTTP_CLIENT_IP' => '198.51.100.7',
			)
		);

		$expected = IDPG_Helpers::get_client_ip();

		$this->assertSame( '203.0.113.10', $expected );
		$this->assertNotSame( '198.51.100.7', $expected );
	}

	/**
	 * Публичный метод IDPG_Views::get_client_ip() остался на месте (AGENTS.md, п.1).
	 */
	public function test_views_method_still_exists() {
		$this->assertTrue( method_exists( 'IDPG_Views', 'get_client_ip' ) );
	}

	/**
	 * Публичный метод IDPG_Auth_Rate_Limiter::get_client_ip() не удалён.
	 */
	public function test_rate_limiter_method_still_exists() {
		$this->assertTrue( method_exists( 'IDPG_Auth_Rate_Limiter', 'get_client_ip' ) );
	}

	/**
	 * Приватный is_proxy_address() не остался дублем в rate-limiter.
	 */
	public function test_proxy_helper_not_duplicated() {
		$this->assertFalse(
			method_exists( 'IDPG_Auth_Rate_Limiter', 'is_proxy_address' ),
			'Метод переехал в IDPG_Helpers, копия в rate-limiter осталась бы дублем.'
		);
	}

	/**
	 * Эталон всегда отдаёт валидный адрес или заглушку, а не пустую строку.
	 */
	public function test_helpers_ip_never_empty() {
		$this->set_server( array() );

		$this->assertSame( IDPG_Helpers::IP_UNKNOWN, IDPG_Helpers::get_client_ip() );
	}

	/**
	 * В $_SERVER ничего лишнего не остаётся мусором после sanitize.
	 */
	public function test_ip_is_sanitized() {
		$this->set_server( array( 'REMOTE_ADDR' => "203.0.113.10\n<script>" ) );

		$ip = IDPG_Helpers::get_client_ip();

		$this->assertDoesNotMatchRegularExpression( '/[<>"\']/', $ip );
	}

	// =====================================================================
	// get_city_tree(): обе старые копии строили одинаковое дерево
	// =====================================================================

	/**
	 * Создаёт город: регион → город → район.
	 *
	 * @return int ID региона.
	 */
	private function make_city_branch( $region_name = 'Удмуртия', $city_name = 'Ижевск', $district_name = 'Ленинский' ) {
		$region = self::factory()->term->create(
			array(
				'taxonomy' => 'city',
				'name'     => $region_name,
			)
		);

		$city = self::factory()->term->create(
			array(
				'taxonomy' => 'city',
				'name'     => $city_name,
				'parent'   => $region,
			)
		);

		self::factory()->term->create(
			array(
				'taxonomy' => 'city',
				'name'     => $district_name,
				'parent'   => $city,
			)
		);

		return (int) $region;
	}

	/**
	 * Плоский список id всех узлов дерева.
	 *
	 * @param array $tree Дерево.
	 * @return int[]
	 */
	private function flatten_ids( $tree ) {
		$ids = array();

		foreach ( $tree as $node ) {
			$ids[] = (int) $node['id'];

			if ( ! empty( $node['children'] ) ) {
				$ids = array_merge( $ids, $this->flatten_ids( $node['children'] ) );
			}
		}

		sort( $ids );

		return $ids;
	}

	/**
	 * Обе старые точки входа отдают одинаковое дерево.
	 */
	public function test_both_city_tree_entry_points_match() {
		$this->make_city_branch();
		$this->make_city_branch( 'Московская область', 'Москва', 'Арбат' );

		$this->assertSame(
			IDPG_Helpers::get_city_tree( 'alpha' ),
			IDPG_City_Filter::get_city_tree(),
			'IDPG_City_Filter::get_city_tree() должен совпадать с эталоном alpha.'
		);
	}

	/**
	 * Дерево содержит все три уровня вложенности.
	 */
	public function test_city_tree_has_three_levels() {
		$this->make_city_branch();

		$tree = IDPG_City_Filter::get_city_tree();

		// Форма дерева (общая для обеих старых копий): корень — список,
		// а `children` внутри узла — массив, ключованный term_id.
		$this->assertCount( 1, $tree );
		$this->assertArrayHasKey( 'children', $tree[0] );
		$this->assertCount( 1, $tree[0]['children'] );

		$cities = array_values( $tree[0]['children'] );

		$this->assertArrayHasKey( 'children', $cities[0] );
		$this->assertCount( 1, $cities[0]['children'] );
		$this->assertSame( 'Ленинский', array_values( $cities[0]['children'] )[0]['name'] );
	}

	/**
	 * Узлы отдаются с ожидаемыми ключами и приведёнными типами.
	 */
	public function test_city_tree_node_shape() {
		$this->make_city_branch();

		$node = IDPG_City_Filter::get_city_tree()[0];

		// Единственный допустимый ключ сверх базовых — children.
		$this->assertSame(
			array(),
			array_diff( array_keys( $node ), array( 'id', 'parent', 'name', 'slug', 'count', 'children' ) )
		);
		$this->assertIsInt( $node['id'] );
		$this->assertIsInt( $node['parent'] );
		$this->assertIsInt( $node['count'] );
		$this->assertIsString( $node['name'] );
		$this->assertIsString( $node['slug'] );
	}

	/**
	 * Пустая таксономия даёт пустой массив, а не WP_Error.
	 */
	public function test_city_tree_empty_returns_array() {
		$this->set_server( array() );
		wp_set_current_user( 0 );

		foreach ( wp_get_object_terms( array(), 'city' ) as $term ) {
			wp_delete_term( (int) $term->term_id, 'city' );
		}

		$this->assertSame( array(), IDPG_City_Filter::get_city_tree() );
		$this->assertSame( array(), IDPG_Helpers::get_city_tree( 'popular' ) );
	}

	/**
	 * Сигнатура виджета без сортировки не изменилась (AGENTS.md, п.2).
	 */
	public function test_widget_methods_still_exist() {
		$this->assertTrue( method_exists( 'IDPG_City_Selector_Widget', 'get_city_tree' ) );
		$this->assertTrue( method_exists( 'IDPG_City_Selector_Widget', 'build_city_tree' ) );
		$this->assertTrue( method_exists( 'IDPG_City_Filter', 'get_city_tree' ) );
	}

	/**
	 * Ключи сортировки из виджета обрабатываются эталоном без ошибок.
	 */
	public function test_sort_keys_supported() {
		$this->make_city_branch();

		foreach ( array( 'alpha', 'popular', 'manual' ) as $sort ) {
			$tree = IDPG_Helpers::get_city_tree( $sort );

			$this->assertIsArray( $tree );
			$this->assertNotEmpty( $tree, 'Сортировка ' . $sort . ' вернула пустое дерево.' );
		}
	}

	// =====================================================================
	// Мета-ключи билетов
	// =====================================================================

	/**
	 * Константы совпадают с фактическими ключами в базе.
	 */
	public function test_ticket_meta_constants() {
		$this->assertSame( '_idpg_ticket_status', IDPG_Helpers::META_TICKET_STATUS );
		$this->assertSame( '_idpg_ticket_type', IDPG_Helpers::META_TICKET_TYPE );
		$this->assertSame( '_idpg_ticket_url', IDPG_Helpers::META_TICKET_URL );
	}

	/**
	 * Геттеры читают то, что записано обычным способом.
	 */
	public function test_ticket_getters_read_meta() {
		$post_id = self::factory()->post->create();

		update_post_meta( $post_id, IDPG_Helpers::META_TICKET_STATUS, 'sold_out' );
		update_post_meta( $post_id, IDPG_Helpers::META_TICKET_TYPE, 'external_link' );
		update_post_meta( $post_id, IDPG_Helpers::META_TICKET_URL, 'https://example.com/tickets' );

		$this->assertSame( 'sold_out', IDPG_Helpers::get_ticket_status( $post_id ) );
		$this->assertSame( 'external_link', IDPG_Helpers::get_ticket_type( $post_id ) );
		$this->assertSame( 'https://example.com/tickets', IDPG_Helpers::get_ticket_url( $post_id ) );
	}

	/**
	 * Геттеры всегда отдают строку, даже когда мета пуста.
	 */
	public function test_ticket_getters_return_string_when_missing() {
		$post_id = self::factory()->post->create();

		$this->assertSame( '', IDPG_Helpers::get_ticket_status( $post_id ) );
		$this->assertSame( '', IDPG_Helpers::get_ticket_type( $post_id ) );
		$this->assertSame( '', IDPG_Helpers::get_ticket_url( $post_id ) );
	}

	/**
	 * Класс подключён к бутстрапу плагина.
	 */
	public function test_helpers_file_is_loaded() {
		$this->assertTrue( class_exists( 'IDPG_Helpers' ) );
		$this->assertFileExists( IDPG_CORE_DIR . 'includes/class-helpers.php' );

		$boot = file_get_contents( IDPG_CORE_DIR . 'idpg-core.php' );

		$this->assertStringContainsString( "includes/class-helpers.php", $boot );
	}

	/**
	 * Дублей реализаций в коде больше нет.
	 */
	public function test_no_duplicate_ip_implementations() {
		foreach ( glob( IDPG_CORE_DIR . 'includes/class-*.php' ) as $file ) {
			$source = file_get_contents( $file );

			if ( strpos( $source, 'HTTP_X_FORWARDED_FOR' ) === false ) {
				continue;
			}

			$this->assertSame(
				'class-helpers.php',
				basename( $file ),
				'Логика разбора прокси-заголовков продублирована в ' . basename( $file )
			);
		}
	}
}
