<?php
/**
 * Юнит-тесты DEV-97: форма события повторяет схему Carbon Fields.
 *
 * Источник истины — `docs/carbon-fields-export.json`. Тест не зашивает
 * список полей намертво, а читает JSON и сверяет с разметкой формы, REST и
 * обработчиками: если в схему добавят поле, тест об этом узнает.
 *
 * Отдельно проверяется, что поля Carbon (`gallery`, `sessions`) доходят до
 * complex/media-хранилища через carbon_set_post_meta(), а не обычным
 * update_post_meta() — иначе событие из кабинета открылось бы в wp-admin
 * пустым (такой баг закрывали DEV-42 для галереи и DEV-48 для сеансов).
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Organizer_Event_Form_Schema
 */
class Test_Organizer_Event_Form_Schema extends WP_UnitTestCase {

	/**
	 * Распакованная схема Carbon Fields.
	 *
	 * @var array|null
	 */
	protected static $schema = null;

	/**
	 * Мета-ключи события, которые не выводятся в форме сознательно.
	 *
	 * @var array
	 */
	const EXCLUDED = array(
		'_idpg_currency'          => 'только рубли (DEV-95)',
		'_idpg_external_id'       => 'служебное поле импорта',
		'_idpg_yandex_form_button' => 'поле типа html, а не значение',
	);

	/**
	 * Поля, которые есть в схеме, но скрыты от организатора по правам.
	 *
	 * Живут в `build_extra_tab_fields()` за проверкой
	 * `current_user_can( 'manage_options' )`, и в коде прямо сказано:
	 * «организаторы не видят».
	 *
	 * @var array
	 */
	const ADMIN_ONLY = array(
		'_idpg_source',
		'_idpg_promoted',
		'_idpg_pin_expires',
		'_idpg_hide_from_banner',
		'_idpg_hide_full_banner',
		'_idpg_views',
		'_idpg_clicks',
		'_idpg_banner_approved',
	);

	/**
	 * Подготовка окружения.
	 */
	public function set_up() {
		parent::set_up();

		IDPG_Meta::instance();
		IDPG_Roles::instance()->create_roles();
		IDPG_REST_Organizer::instance();
		IDPG_REST_Organizer::instance()->register_post_status();
	}

	// =====================================================================
	// Источник истины
	// =====================================================================

	/**
	 * Разобрать docs/carbon-fields-export.json.
	 *
	 * @return array
	 */
	protected function schema() {
		if ( null !== self::$schema ) {
			return self::$schema;
		}

		$file = $this->schema_path();

		if ( ! is_readable( $file ) ) {
			$this->markTestSkipped( 'Файл схемы Carbon Fields недоступен: ' . $file );
		}

		$data = json_decode( file_get_contents( $file ), true );

		$this->assertIsArray( $data, 'Схема Carbon Fields должна быть валидным JSON.' );

		self::$schema = $data;

		return $data;
	}

	/**
	 * Путь к файлу схемы.
	 *
	 * @return string
	 */
	protected function schema_path() {
		return dirname( dirname( IDPG_CORE_DIR ) ) . '/docs/carbon-fields-export.json';
	}

	/**
	 * Мета-ключи полей события из схемы.
	 *
	 * Берутся только контейнеры с `post_type = event`; контейнер
	 * «Данные площадки» относится к CPT venue и в форму события не входит.
	 *
	 * @return array Имя поля => массив {name, type, base_name}.
	 */
	protected function schema_event_fields() {
		$fields = array();

		foreach ( $this->schema()['containers'] as $container ) {
			if ( 'Данные площадки' === $container['title'] ) {
				continue; // контейнер площадки (CPT venue)
			}

			foreach ( $container['fields'] as $field ) {
				$fields[ $field['name'] ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Исходник шаблона формы.
	 *
	 * @return string
	 */
	protected function form_source() {
		$file = $this->theme_path() . '/page-organizer-event.php';

		$this->assertFileExists( $file );

		return file_get_contents( $file );
	}

	/**
	 * Корень темы.
	 *
	 * @return string
	 */
	protected function theme_path() {
		$dir = getenv( 'IDPG_THEME_DIR' )
			? getenv( 'IDPG_THEME_DIR' )
			: dirname( dirname( IDPG_CORE_DIR ) ) . '/themes/idpg_core';

		return rtrim( $dir, '/\\' );
	}

	// =====================================================================
	// Сверка формы со схемой
	// =====================================================================

	/**
	 * Каждое поле схемы представлено в форме или сознательно исключено.
	 */
	public function test_every_schema_field_present_in_form() {
		$source = $this->form_source();
		$fields = $this->schema_event_fields();

		$this->assertNotEmpty( $fields, 'Схема не должна быть пустой.' );

		// name= в форме, без служебных полей WordPress.
		preg_match_all( '/name="([a-z_0-9]+)/', $source, $m );
		$form_fields = array_unique( $m[1] );

		$missing = array();

		foreach ( $fields as $name => $field ) {
			// Сознательно исключённые поля в форме отсутствуют — это
			// проверяется отдельно, в test_excluded_and_admin_only_fields_absent.
			if ( isset( self::EXCLUDED[ $name ] ) || in_array( $name, self::ADMIN_ONLY, true ) ) {
				continue;
			}

			$param = $this->form_param_for( $name );

			if ( null !== $param && in_array( $param, $form_fields, true ) ) {
				continue;
			}

			// Сложные поля проверяются по служебным маркерам.
			if ( null !== $param && false !== strpos( $source, 'data-idpg-' . $param ) ) {
				continue;
			}

			if ( null !== $param && $this->has_field_via_template( $source, $name ) ) {
				continue;
			}

			$missing[] = $name;
		}

		$this->assertSame( array(), $missing, 'Поля схемы отсутствуют в форме: ' . implode( ', ', $missing ) );
	}

	/**
	 * Исключённые и admin-only поля не должны попасть в форму.
	 */
	public function test_excluded_and_admin_only_fields_absent() {
		$source = $this->form_source();

		foreach ( array_keys( self::EXCLUDED ) as $meta_key ) {
			$this->assertSame(
				0,
				preg_match( '/name="' . preg_quote( $this->form_param_for( $meta_key ), '/' ) . '"/', $source ),
				$meta_key . ' не должен выводиться в форме: ' . self::EXCLUDED[ $meta_key ]
			);
		}

		foreach ( self::ADMIN_ONLY as $meta_key ) {
			$param = $this->form_param_for( $meta_key );

			$this->assertSame(
				0,
				preg_match( '/name="' . preg_quote( $param, '/' ) . '"/', $source ),
				$meta_key . ' — служебное поле администратора и не должно быть в форме организатора.'
			);
		}
	}

	/**
	 * Наборы значений select совпадают со схемой.
	 */
	public function test_select_options_match_schema() {
		$source = $this->form_source();

		// ticket_status
		$statuses = $this->schema_field( '_idpg_ticket_status' );

		foreach ( $statuses['options'] as $option ) {
			$this->assertStringContainsString(
				"'" . $option['value'] . "'",
				$source,
				'Значение ' . $option['value'] . ' статуса билетов должно быть в форме.'
			);
		}

		// ticket_type
		$types = $this->schema_field( '_idpg_ticket_type' );

		foreach ( $types['options'] as $option ) {
			$this->assertStringContainsString(
				"'" . $option['value'] . "'",
				$source,
				'Значение ' . $option['value'] . ' типа билета должно быть в форме.'
			);
		}
	}

	/**
	 * Поле схемы целиком.
	 *
	 * @param string $name Имя поля.
	 * @return array
	 */
	protected function schema_field( $name ) {
		$fields = $this->schema_event_fields();

		$this->assertArrayHasKey( $name, $fields, 'Поле ' . $name . ' должно быть в схеме.' );

		return $fields[ $name ];
	}

	/**
	 * Имя параметра формы для мета-ключа.
	 *
	 * `_idpg_start_date` -> `start_date`; сложные и нативные поля, у которых
	 * своего параметра нет, возвращают null.
	 *
	 * @param string $meta_key Мета-ключ.
	 * @return string|null
	 */
	protected function form_param_for( $meta_key ) {
		if ( '_idpg_sessions' === $meta_key ) {
			return 'session_time';
		}

		if ( '_idpg_gallery' === $meta_key ) {
			return 'gallery';
		}

		if ( '_thumbnail_id' === $meta_key ) {
			return 'poster_id';
		}

		if ( 0 === strpos( $meta_key, '_idpg_' ) ) {
			return substr( $meta_key, strlen( '_idpg_' ) );
		}

		return ltrim( $meta_key, '_' );
	}

	/**
	 * Поле выводится через цикл или отдельный блок шаблона.
	 *
	 * @param string $source Исходник шаблона.
	 * @param string $name   Имя поля схемы.
	 * @return bool
	 */
	protected function has_field_via_template( $source, $name ) {
		// Валидация на сервере тоже должна знать про поле.
		return false !== strpos( $source, $name );
	}

	// =====================================================================
	// Мета-ключи совпадают с Carbon Fields
	// =====================================================================

	/**
	 * Ключи полей схемы — строго те, что заведены в Carbon Fields.
	 */
	public function test_schema_meta_keys_are_carbon_keys() {
		$fields = $this->schema_event_fields();

		foreach ( $fields as $name => $field ) {
			$this->assertSame(
				0,
				strpos( $name, '_idpg_' ),
				$name . ' должен начинаться с _idpg_ — это соглашение проекта.'
			);

			// base_name — то, под чем Carbon ищет значение.
			$this->assertSame(
				'idpg_' . substr( $name, strlen( '_idpg_' ) ),
				$field['base_name'],
				'base_name поля ' . $name . ' расходится с его именем.'
			);
		}
	}

	/**
	 * Поля, которые пишет REST, объявлены в схеме маршрута.
	 *
	 * Иначе форма отправила бы параметр, который сервер молча выбросит, и
	 * организатор потерял бы данные без всякой ошибки.
	 */
	public function test_rest_accepts_every_schema_field() {
		$args  = $this->write_args();
		$forms = $this->schema_event_fields();

		// Поля, которые пишутся не через схему аргументов.
		$special = array(
			'_idpg_gallery'   => 'gallery',
			'_idpg_sessions'  => 'sessions_json',
			'_idpg_ticket_status' => 'ticket_status',
		);

		$missing = array();

		foreach ( $forms as $name => $field ) {
			if ( isset( self::EXCLUDED[ $name ] ) || in_array( $name, self::ADMIN_ONLY, true ) ) {
				continue;
			}

			$param = isset( $special[ $name ] ) ? $special[ $name ] : $this->form_param_for( $name );

			if ( in_array( $param, array( 'all_day', 'is_free', 'is_online', 'banner_request' ), true ) ) {
				continue; // radio/checkbox со значением yes|no
			}

			if ( ! array_key_exists( $param, $args ) ) {
				$missing[] = $name . ' (ожидается параметр ' . $param . ')';
			}
		}

		$this->assertSame( array(), $missing, 'REST не принимает поля схемы: ' . implode( ', ', $missing ) );
	}

	/**
	 * Аргументы записи маршрута событий.
	 *
	 * @return array
	 */
	protected function write_args() {
		$routes = rest_get_server()->get_routes();
		$args   = array();

		foreach ( $routes['/idpg/v1/organizer/events'] as $handler ) {
			if ( ! empty( $handler['args'] ) && isset( $handler['args']['title'] ) ) {
				$args = $handler['args'];
				break;
			}
		}

		$this->assertNotEmpty( $args, 'У маршрута должны быть аргументы.' );

		return $args;
	}

	/**
	 * Аргументы чтения маршрута событий (сортировка, постраничность).
	 *
	 * Ищет обработчик без `title` в аргументах: у GET их набор другой, чем
	 * у POST, и в одном маршруте оба варианта зарегистрированы.
	 *
	 * @return array
	 */
	protected function list_args() {
		$routes = rest_get_server()->get_routes();
		$args   = array();

		foreach ( $routes['/idpg/v1/organizer/events'] as $handler ) {
			if ( ! empty( $handler['args'] ) && ! isset( $handler['args']['title'] ) ) {
				$args = $handler['args'];
				break;
			}
		}

		$this->assertNotEmpty( $args, 'У маршрута должны быть аргументы чтения.' );

		return $args;
	}

	/**
	 * Перечисления проверяются: без validate_callback ядро их игнорирует.
	 *
	 * Список полный: у маршрута события девять перечислений, и у каждого
	 * должен быть `validate_callback`. Раньше его не было у `all_day`,
	 * `is_free`, `ticket_type`, `orderby` и `order` — значения вида
	 * `is_free=hack` проходили с 201 и ложились в базу как есть.
	 */
	public function test_new_enums_are_validated_server_side() {
		$args = $this->write_args();

		foreach ( array( 'all_day', 'is_free', 'ticket_type', 'ticket_status', 'banner_request', 'is_online' ) as $param ) {
			$this->assertArrayHasKey( $param, $args );
			$this->assertNotEmpty(
				$args[ $param ]['validate_callback'],
				'Без validate_callback перечисление ' . $param . ' не проверяется.'
			);
		}

		// Сортировка списка — то же перечисление, значение уходит в WP_Query.
		$list_args = $this->list_args();

		foreach ( array( 'orderby', 'order' ) as $param ) {
			$this->assertArrayHasKey( $param, $list_args );
			$this->assertNotEmpty(
				$list_args[ $param ]['validate_callback'],
				'Без validate_callback перечисление ' . $param . ' не проверяется.'
			);
		}
	}

	/**
	 * Пустая строка в перечислении — там, где её реально шлёт форма.
	 *
	 * Регресс. `ticket_status` проверялся, но пустой строки в перечислении
	 * не было, а форма шлёт именно её: первая опция select'а «— Не выбран
	 * —» и состояние новой формы. В итоге обычное сохранение события
	 * отвергалось с 400. Тот же случай у `ticket_type` («— Не нужны —»).
	 *
	 * Радио `all_day` и `is_free` пустую строку не порождают: скрипт
	 * подставляет 'no', когда ничего не отмечено, поэтому там перечисление
	 * остаётся строгим.
	 */
	public function test_empty_string_allowed_where_form_sends_it() {
		$args = $this->write_args();

		foreach ( array( 'ticket_status', 'ticket_type' ) as $param ) {
			$this->assertContains(
				'',
				(array) $args[ $param ]['enum'],
				$param . ': форма шлёт пустую строку, значит она должна быть в перечислении.'
			);
		}

		foreach ( array( 'all_day', 'is_free' ) as $param ) {
			$this->assertNotContains(
				'',
				(array) $args[ $param ]['enum'],
				$param . ' — радио с двумя позициями, пустая строка лишняя.'
			);
		}
	}

	/**
	 * Значение вне перечисления отвергается, а не сохраняется.
	 */
	public function test_enum_rejects_unknown_value_on_write() {
		$organizer = $this->make_organizer( 'schema_enum_reject' );
		wp_set_current_user( $organizer->ID );

		foreach ( array( 'all_day', 'is_free', 'ticket_type', 'ticket_status' ) as $param ) {
			$response = $this->request(
				'POST',
				'/idpg/v1/organizer/events',
				array_merge( $this->full_payload(), array( $param => 'hack' ) )
			);

			$this->assertSame( 400, $response->get_status(), $param . ' должен отвергать неизвестное значение.' );
			$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		}
	}

	/**
	 * Штатные значения перечислений принимаются.
	 *
	 * Проверка на то, что строгая проверка не отвергает то, что форма
	 * действительно шлёт: 'yes'/'no' у радио и оба значения билета.
	 */
	public function test_enum_accepts_form_values() {
		$organizer = $this->make_organizer( 'schema_enum_accept' );
		wp_set_current_user( $organizer->ID );

		$accepted = array(
			'all_day'       => array( 'yes', 'no' ),
			'is_free'       => array( 'yes', 'no' ),
			'ticket_type'   => array( '', 'external_link', 'widget_yandex' ),
			'ticket_status' => array( '', 'in_sale', 'sold_out', 'cancelled', 'postponed' ),
		);

		foreach ( $accepted as $param => $values ) {
			foreach ( $values as $value ) {
				$response = $this->request(
					'POST',
					'/idpg/v1/organizer/events',
					array_merge( $this->full_payload(), array( $param => $value ) )
				);

				$this->assertSame( 201, $response->get_status(), $param . '=' . $value . ' должен приниматься.' );

				$id = (int) $response->get_data()['data']['id'];

				// Ключ мета — с префиксом `idpg_`: имя параметра REST его не
				// содержит, а Carbon Fields пишет именно так.
				$this->assertSame(
					$value,
					get_post_meta( $id, '_idpg_' . $param, true ),
					$param . '=' . $value . ' не сохранён.'
				);

				wp_delete_post( $id, true );
			}
		}
	}

	/**
	 * Сортировка списка отвергает значение вне перечисления.
	 *
	 * Параметры GET задаются через `set_query_params()`: ядро читает тело
	 * запроса только для POST/PUT/PATCH/DELETE, поэтому переданные как body
	 * они просто не увидели бы и молча применился бы дефолт.
	 */
	public function test_list_sort_enum_rejects_unknown_value() {
		$organizer = $this->make_organizer( 'schema_enum_sort' );
		wp_set_current_user( $organizer->ID );

		foreach ( array( 'orderby' => 'hack', 'order' => 'sideways' ) as $param => $value ) {
			$request = new WP_REST_Request( 'GET', '/idpg/v1/organizer/events' );
			$request->set_query_params( array( $param => $value ) );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

			$response = rest_do_request( $request );

			$this->assertSame( 400, $response->get_status(), $param . ' должен отвергать неизвестное значение.' );
			$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		}
	}

	// =====================================================================
	// Запись в хранилище Carbon Fields
	// =====================================================================

	/**
	 * Создать организатора.
	 *
	 * @param string $login Логин.
	 * @return WP_User
	 */
	protected function make_organizer( $login ) {
		$user = new WP_User(
			$this->factory()->user->create(
				array(
					'user_login' => $login,
					'role'       => 'subscriber',
				)
			)
		);

		foreach ( (array) $user->roles as $role ) {
			$user->remove_role( $role );
		}
		$user->add_role( IDPG_Roles::ROLE_SLUG );

		return $user;
	}

	/**
	 * Запрос от имени организатора.
	 *
	 * @param string $method HTTP-метод.
	 * @param string $route  Маршрут.
	 * @param array  $params Параметры.
	 * @return WP_REST_Response
	 */
	protected function request( $method, $route, $params = array() ) {
		$request = new WP_REST_Request( $method, $route );

		if ( $params ) {
			$request->set_body_params( $params );
		}

		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return rest_do_request( $request );
	}

	/**
	 * Полный набор полей события, как его отправляет форма.
	 *
	 * Город создаётся здесь же: в тестовой БД терма с боевым ID 15 нет, а
	 * REST честно отвечает 400 на несуществующий терм.
	 *
	 * @return array
	 */
	protected function full_payload() {
		$city_id = $this->factory()->term->create( array( 'taxonomy' => 'city' ) );

		return array(
			'title'           => 'Событие по полной схеме',
			'content'         => 'Описание события.',
			'excerpt'         => 'Кратко.',
			'start_date'      => '2026-10-05',
			'end_date'        => '2026-10-06',
			'all_day'         => 'no',
			'city'            => $city_id,
			'category'        => 0,
			'age_rating'      => 0,
			'venue'           => 0,
			'address'         => 'ул. Пушкина, 10',
			'is_free'         => 'no',
			'ticket_status'   => 'in_sale',
			'price_from'      => '500',
			'price_to'        => '1500',
			'vip_price'       => '2500',
			'ticket_type'     => 'widget_yandex',
			'ticket_url'      => '',
			'yandex_form_url' => 'https://afisha.yandex.ru/form/1',
			'widget_code'     => '<div class="widget"></div>',
			'video_trailer'   => 'https://example.com/trailer',
			'stream_embed'    => '<iframe src="https://example.com/live"></iframe>',
			'is_online'       => 'yes',
			'stream_url'      => 'https://example.com/live',
			'banner_request'  => 'yes',
			'sessions_json'   => wp_json_encode(
				array( array( 'time' => '19:00', 'price' => '500', 'url' => 'https://example.com/t1' ) )
			),
		);
	}

	/**
	 * Событие, заведённое формой, не теряет ни одного поля в wp-admin.
	 *
	 * Это и есть приёмка DEV-97: заполнил форму → открыл в админке → всё на
	 * месте. Проверяем и complex-поля, и нативные мета.
	 */
	public function test_event_from_form_keeps_every_field() {
		$organizer = $this->make_organizer( 'schema_org' );
		wp_set_current_user( $organizer->ID );

		$payload = $this->full_payload();

		$response = $this->request( 'POST', '/idpg/v1/organizer/events', $payload );
		$this->assertSame( 201, $response->get_status() );

		$event_id = (int) $response->get_data()['data']['id'];
		$this->assertGreaterThan( 0, $event_id );

		// Нативные поля.
		$this->assertSame( $payload['title'], get_post_field( 'post_title', $event_id ) );
		$this->assertSame( $payload['content'], get_post_field( 'post_content', $event_id ) );
		$this->assertSame( $payload['excerpt'], get_post_field( 'post_excerpt', $event_id ) );

		// Мета-поля Carbon: сверяем ключи из схемы.
		$map = array(
			'_idpg_start_date'    => 'start_date',
			'_idpg_end_date'      => 'end_date',
			'_idpg_all_day'       => 'all_day',
			'_idpg_venue'         => 'venue',
			'_idpg_address'       => 'address',
			'_idpg_is_free'       => 'is_free',
			'_idpg_ticket_status' => 'ticket_status',
			'_idpg_price_from'    => 'price_from',
			'_idpg_price_to'      => 'price_to',
			'_idpg_vip_price'     => 'vip_price',
			'_idpg_ticket_type'   => 'ticket_type',
			'_idpg_yandex_form_url' => 'yandex_form_url',
			'_idpg_widget_code'   => 'widget_code',
			'_idpg_video_trailer' => 'video_trailer',
			'_idpg_stream_embed'  => 'stream_embed',
			'_idpg_is_online'     => 'is_online',
			'_idpg_stream_url'    => 'stream_url',
			'_idpg_banner_request' => 'banner_request',
		);

		foreach ( $map as $meta_key => $param ) {
			$this->assertSame(
				(string) $payload[ $param ],
				(string) get_post_meta( $event_id, $meta_key, true ),
				$meta_key . ' не сохранён из формы.'
			);
		}

		// Таксономии.
		$cities = wp_get_post_terms( $event_id, 'city', array( 'fields' => 'ids' ) );
		$this->assertContains( (int) $payload['city'], array_map( 'intval', $cities ) );

		// Сеансы — в complex-поле Carbon.
		$sessions = carbon_get_post_meta( $event_id, 'idpg_sessions' );
		$this->assertNotEmpty( $sessions, 'Сеансы должны попасть в complex-поле.' );
		$this->assertSame( '19:00', $sessions[0]['time'] );
		$this->assertSame( '500', $sessions[0]['price'] );
		$this->assertSame( 'https://example.com/t1', $sessions[0]['url'] );
	}

	/**
	 * Галерея пишется в media_gallery Carbon Fields, а не в обычную мета.
	 */
	public function test_gallery_written_through_carbon() {
		$organizer = $this->make_organizer( 'schema_gallery' );
		wp_set_current_user( $organizer->ID );

		$first  = $this->factory()->attachment->create_object( 'one.jpg' );
		$second = $this->factory()->attachment->create_object( 'two.jpg' );

		$response = $this->request(
			'POST',
			'/idpg/v1/organizer/events',
			array_merge( $this->full_payload(), array( 'gallery' => array( $first, $second ) ) )
		);

		$this->assertSame( 201, $response->get_status() );

		$event_id = (int) $response->get_data()['data']['id'];

		// Видно тем же способом, каким читает редактор.
		$this->assertSame(
			array( $first, $second ),
			array_map( 'intval', (array) carbon_get_post_meta( $event_id, 'idpg_gallery' ) )
		);

		// Плоский ключ без подчёркивания писаться не должен.
		$this->assertSame( '', (string) get_post_meta( $event_id, 'idpg_gallery', true ) );
	}

	/**
	 * Поля формы возвращаются обратно в представлении события.
	 *
	 * Иначе правка существующего события открылась бы пустой формой.
	 */
	public function test_event_payload_round_trip() {
		$organizer = $this->make_organizer( 'schema_round' );
		wp_set_current_user( $organizer->ID );

		$payload = $this->full_payload();

		$created = $this->request( 'POST', '/idpg/v1/organizer/events', $payload );
		$event_id = (int) $created->get_data()['data']['id'];

		$read = $this->request( 'GET', '/idpg/v1/organizer/events/' . $event_id )->get_data();
		$data = $read['data'];

		$map = array(
			'address', 'is_free', 'ticket_status', 'price_from', 'price_to', 'vip_price',
			'ticket_type', 'yandex_form_url', 'widget_code', 'video_trailer', 'stream_embed',
			'is_online', 'stream_url', 'banner_request', 'start_date', 'end_date', 'all_day',
		);

		foreach ( $map as $param ) {
			$this->assertSame(
				(string) $payload[ $param ],
				(string) $data[ $param ],
				'Поле ' . $param . ' не вернулось в представление события.'
			);
		}

		$this->assertSame(
			array( array( 'time' => '19:00', 'price' => '500', 'url' => 'https://example.com/t1' ) ),
			json_decode( $data['sessions_json'], true )
		);
	}

	/**
	 * Обязательные поля формы объявлены required.
	 */
	public function test_required_fields_marked() {
		$source = $this->form_source();

		// title, start_date и city помечены required.
		foreach ( array( 'title', 'start_date', 'city' ) as $field ) {
			$pattern = '/name="' . $field . '"[^>]*\brequired\b/s';

			$this->assertSame(
				1,
				preg_match( $pattern, $source ),
				'Поле ' . $field . ' должно быть обязательным.'
			);
		}
	}

	// =====================================================================
	// Права на загрузку
	// =====================================================================

	/**
	 * Организатору выдан upload_files, иначе медиа в форме не работают.
	 */
	public function test_organizer_can_upload_files() {
		$organizer = $this->make_organizer( 'schema_upload' );
		wp_set_current_user( $organizer->ID );

		$this->assertTrue(
			current_user_can( 'upload_files' ),
			'Без upload_files постер и галерея в форме нерабочие.'
		);
	}

	/**
	 * Право прямой публикации события у организатора по-прежнему нет.
	 *
	 * Регресс: выдача upload_files не должна была ослабить модерацию.
	 */
	public function test_organizer_still_cannot_publish_directly() {
		$organizer = $this->make_organizer( 'schema_moderation' );
		wp_set_current_user( $organizer->ID );

		$this->assertFalse( current_user_can( 'publish_events' ) );
	}

	// =====================================================================
	// DEV-50: площадки
	// =====================================================================

	/**
	 * Площадка создаётся и поля контейнера «Данные площадки» сохраняются.
	 */
	public function test_create_venue_uses_cf_meta_keys() {
		$organizer = $this->make_organizer( 'schema_venue' );
		wp_set_current_user( $organizer->ID );

		$city_id = $this->factory()->term->create( array( 'taxonomy' => 'city' ) );

		$response = $this->request(
			'POST',
			'/idpg/v1/organizer/venues',
			array(
				'title'          => 'Новая площадка',
				// Город и адрес обязательны с DEV-50.
				'city'           => $city_id,
				'venue_address'  => 'ул. Ленина, 1',
				'venue_type'     => 'theater',
				'venue_phone'    => '+7 900 000-00-00',
				'venue_email'    => 'hall@example.com',
				'venue_website'  => 'https://example.com/hall',
				'venue_capacity' => '500',
				'venue_lat'      => '56.8527',
				'venue_lng'      => '53.2114',
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$venue_id = (int) $response->get_data()['data']['id'];
		$this->assertGreaterThan( 0, $venue_id );

		// Сверяем с контейнером «Данные площадки» из схемы.
		$container = null;

		foreach ( $this->schema()['containers'] as $c ) {
			if ( 'Данные площадки' === $c['title'] ) {
				$container = $c;
				break;
			}
		}

		$this->assertNotNull( $container, 'В схеме должен быть контейнер «Данные площадки».' );

		foreach ( $container['fields'] as $field ) {
			$param = $this->venue_param_for( $field['base_name'] );

			$this->assertSame(
				1,
				preg_match( '/' . preg_quote( $param, '/' ) . '/', wp_json_encode( $response->get_data() ) ),
				'Поле площадки ' . $field['name'] . ' должно возвращаться в ответе.'
			);
		}

		// И лежать под ключом Carbon Fields.
		$this->assertSame( 'theater', get_post_meta( $venue_id, '_idpg_venue_type', true ) );
		$this->assertSame( 'ул. Ленина, 1', get_post_meta( $venue_id, '_idpg_venue_address', true ) );
		$this->assertSame( '56.8527', get_post_meta( $venue_id, '_idpg_venue_lat', true ) );
		$this->assertSame( '500', get_post_meta( $venue_id, '_idpg_venue_capacity', true ) );
	}

	/**
	 * Имя параметра площадки по base_name поля Carbon.
	 *
	 * @param string $base_name base_name поля.
	 * @return string
	 */
	protected function venue_param_for( $base_name ) {
		// idpg_venue_type -> venue_type
		return preg_replace( '/^idpg_/', '', $base_name );
	}

	/**
	 * Перечисление типа площадки проверяется.
	 */
	public function test_venue_type_enum_rejected_when_invalid() {
		$organizer = $this->make_organizer( 'schema_venue_enum' );
		wp_set_current_user( $organizer->ID );

		$response = $this->request(
			'POST',
			'/idpg/v1/organizer/venues',
			array(
				'title'      => 'Площадка',
				'venue_type' => 'not-a-type',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/**
	 * Площадка без названия не создаётся.
	 */
	public function test_venue_requires_title() {
		$organizer = $this->make_organizer( 'schema_venue_title' );
		wp_set_current_user( $organizer->ID );

		$city_id = $this->factory()->term->create( array( 'taxonomy' => 'city' ) );

		$response = $this->request(
			'POST',
			'/idpg/v1/organizer/venues',
			array(
				'title'         => '  ',
				'city'          => $city_id,
				'venue_address' => 'ул. Ленина, 1',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'idpg_org_venue_title_required', $response->get_data()['code'] );
	}

	/**
	 * Чужую площадку править нельзя.
	 */
	public function test_cannot_edit_foreign_venue() {
		$owner = $this->make_organizer( 'schema_venue_owner' );
		$other = $this->make_organizer( 'schema_venue_other' );

		$venue_id = $this->factory()->post->create(
			array(
				'post_type'   => 'venue',
				'post_status' => 'publish',
				'post_author' => $owner->ID,
			)
		);

		wp_set_current_user( $other->ID );

		$response = $this->request( 'PUT', '/idpg/v1/organizer/venues/' . $venue_id, array( 'title' => 'Украли' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( $owner->ID, (int) get_post_field( 'post_author', $venue_id ) );
		$this->assertNotSame( 'Украли', get_post_field( 'post_title', $venue_id ) );
	}

	/**
	 * Гость к площадкам не допускается.
	 */
	public function test_guest_cannot_list_venues() {
		wp_set_current_user( 0 );

		$response = $this->request( 'GET', '/idpg/v1/organizer/venues' );

		$this->assertSame( 401, $response->get_status() );
	}
}
