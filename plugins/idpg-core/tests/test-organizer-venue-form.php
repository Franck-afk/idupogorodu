<?php
/**
 * Юнит-тесты DEV-50: форма площадки в кабинете организатора.
 *
 * Площадка — самостоятельная сущность (CPT `venue`) с полями в контейнере
 * «Данные площадки» Carbon Fields. Проверяется, что форма, REST и
 * обработчик no-JS работают с одним и тем же набором полей и не позволяют
 *organizator'у выйти за пределы своих записей.
 *
 * Список полей не зашит: берётся из `docs/carbon-fields-export.json`.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Organizer_Venue_Form
 */
class Test_Organizer_Venue_Form extends WP_UnitTestCase {

	/**
	 * Распакованная схема Carbon Fields.
	 *
	 * @var array|null
	 */
	protected static $schema = null;

	/**
	 * Подготовка окружения.
	 */
	public function set_up() {
		parent::set_up();

		// Меню кабинета живёт в теме. Подключаем только этот файл, а не всю
		// functions.php: функция idpg_organizer_menu() самодостаточна, а
		// полная загрузка темы тянула бы за собой ассеты и регистрации CPT.
		if ( ! function_exists( 'idpg_organizer_menu' ) ) {
			$menu_file = $this->theme_path() . '/inc/organizer-menu.php';

			if ( is_readable( $menu_file ) ) {
				require_once $menu_file;
			}
		}

		IDPG_Meta::instance();
		IDPG_Roles::instance()->create_roles();
		IDPG_REST_Organizer::instance();
		IDPG_REST_Organizer::instance()->register_post_status();
		IDPG_Organizer_URL::init();
	}

	// =====================================================================
	// Вспомогательное
	// =====================================================================

	/**
	 * Схема Carbon Fields.
	 *
	 * @return array
	 */
	protected function schema() {
		if ( null !== self::$schema ) {
			return self::$schema;
		}

		$file = dirname( dirname( IDPG_CORE_DIR ) ) . '/docs/carbon-fields-export.json';

		if ( ! is_readable( $file ) ) {
			$this->markTestSkipped( 'Файл схемы недоступен: ' . $file );
		}

		self::$schema = json_decode( file_get_contents( $file ), true );

		$this->assertIsArray( self::$schema );

		return self::$schema;
	}

	/**
	 * Поля контейнера «Данные площадки».
	 *
	 * @return array
	 */
	protected function venue_schema_fields() {
		foreach ( $this->schema()['containers'] as $container ) {
			if ( 'Данные площадки' === $container['title'] ) {
				return $container['fields'];
			}
		}

		$this->fail( 'В схеме нет контейнера «Данные площадки».' );
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
	 * Запрос от имени текущего пользователя.
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
	 * Настоящее вложение с файлом.
	 *
	 * `set_post_thumbnail()` требует, чтобы у вложения реально существовал
	 * размер `thumbnail`, иначе он молча удаляет мета. Вложение без файла
	 * (например, `attachment->create_object()`) для этой проверки не годится.
	 *
	 * @return int
	 */
	protected function make_attachment() {
		$file = DIR_TESTDATA . '/images/canola.jpg';

		if ( ! is_readable( $file ) ) {
			$this->markTestSkipped( 'Эталонное изображение недоступно: ' . $file );
		}

		$id = $this->factory()->attachment->create_upload_object( $file );

		$this->assertIsInt( $id );
		$this->assertNotEmpty( wp_get_attachment_image( $id, 'thumbnail' ) );

		return $id;
	}

	/**
	 * Валидный набор полей площадки.
	 *
	 * @param array $over Что заменить.
	 * @return array
	 */
	protected function valid_payload( $over = array() ) {
		$city = $this->factory()->term->create( array( 'taxonomy' => 'city' ) );

		return array_merge(
			array(
				'title'          => 'ДК «Тест»',
				'city'           => $city,
				'venue_address'  => 'ул. Ленина, 1',
				'venue_type'     => 'theater',
				'venue_phone'    => '+7 900 000-00-00',
				'venue_email'    => 'hall@example.com',
				'venue_website'  => 'https://example.com',
				'venue_capacity' => '500',
				'venue_lat'      => '56.8527',
				'venue_lng'      => '53.2114',
			),
			$over
		);
	}

	/**
	 * Создать площадку организатора.
	 *
	 * @param WP_User $organizer Владелец.
	 * @return int
	 */
	protected function make_venue( $organizer ) {
		wp_set_current_user( $organizer->ID );

		$response = $this->request( 'POST', '/idpg/v1/organizer/venues', $this->valid_payload() );

		$this->assertSame( 201, $response->get_status() );

		return (int) $response->get_data()['data']['id'];
	}

	// =====================================================================
	// Обязательные поля
	// =====================================================================

	/**
	 * Без названия площадка не создаётся.
	 */
	public function test_title_required_on_create() {
		wp_set_current_user( $this->make_organizer( 'vf_title' )->ID );

		$response = $this->request( 'POST', '/idpg/v1/organizer/venues', $this->valid_payload( array( 'title' => '   ' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'idpg_org_venue_title_required', $response->get_data()['code'] );
	}

	/**
	 * Без города площадка не создаётся.
	 *
	 * Площадка без города не попадает ни в одну подборку: все выборки
	 * фильтруются по таксономии `city`.
	 */
	public function test_city_required_on_create() {
		wp_set_current_user( $this->make_organizer( 'vf_city' )->ID );

		$response = $this->request( 'POST', '/idpg/v1/organizer/venues', $this->valid_payload( array( 'city' => 0 ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'idpg_org_venue_city_required', $response->get_data()['code'] );
	}

	/**
	 * Без адреса площадка не создаётся.
	 */
	public function test_address_required_on_create() {
		wp_set_current_user( $this->make_organizer( 'vf_addr' )->ID );

		$response = $this->request( 'POST', '/idpg/v1/organizer/venues', $this->valid_payload( array( 'venue_address' => '' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'idpg_org_venue_address_required', $response->get_data()['code'] );
	}

	/**
	 * Те же проверки на правке: иначе можно было бы создать с адресом, а
	 * обнулить его последующей правкой.
	 */
	public function test_required_fields_on_update() {
		$organizer = $this->make_organizer( 'vf_upd' );
		$venue_id  = $this->make_venue( $organizer );

		foreach ( array( 'title', 'venue_address' ) as $field ) {
			$response = $this->request(
				'PUT',
				'/idpg/v1/organizer/venues/' . $venue_id,
				$this->valid_payload( array( $field => '' ) )
			);

			$this->assertSame( 400, $response->get_status(), $field . ' должен быть обязателен и при правке.' );
		}

		// Пустое название — отдельный код.
		$response = $this->request( 'PUT', '/idpg/v1/organizer/venues/' . $venue_id, $this->valid_payload( array( 'title' => '' ) ) );
		$this->assertSame( 'idpg_org_venue_title_required', $response->get_data()['code'] );
	}

	// =====================================================================
	// Скоуп
	// =====================================================================

	/**
	 * Чужая площадка не читается, не правится и не удаляется.
	 */
	public function test_foreign_venue_is_inaccessible() {
		$owner = $this->make_organizer( 'vf_owner' );
		$venue_id = $this->make_venue( $owner );

		$other = $this->make_organizer( 'vf_other' );
		wp_set_current_user( $other->ID );

		$this->assertSame( 403, $this->request( 'GET', '/idpg/v1/organizer/venues/' . $venue_id )->get_status() );
		$this->assertSame(
			403,
			$this->request( 'PUT', '/idpg/v1/organizer/venues/' . $venue_id, $this->valid_payload() )->get_status()
		);
		$this->assertSame( 403, $this->request( 'DELETE', '/idpg/v1/organizer/venues/' . $venue_id )->get_status() );

		$this->assertSame( 'ДК «Тест»', get_post_field( 'post_title', $venue_id ) );
	}

	/**
	 * Несуществующая площадка — 404, а не 403: иначе по коду можно было бы
	 * отличить «нет такой» от «чужая» и перебором узнать о чужих ID.
	 */
	public function test_missing_venue_returns_404() {
		wp_set_current_user( $this->make_organizer( 'vf_404' )->ID );

		$response = $this->request( 'GET', '/idpg/v1/organizer/venues/999999' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'idpg_org_venue_not_found', $response->get_data()['code'] );
	}

	/**
	 * Гость и подписчик не допускаются.
	 */
	public function test_guest_and_subscriber_rejected() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'GET', '/idpg/v1/organizer/venues' )->get_status() );

		$subscriber = $this->factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber->ID );
		$this->assertSame( 403, $this->request( 'GET', '/idpg/v1/organizer/venues' )->get_status() );
	}

	/**
	 * Без nonce — 403: проверка nonce не должна обходиться подменой метода.
	 */
	public function test_missing_nonce_rejected() {
		wp_set_current_user( $this->make_organizer( 'vf_nonce' )->ID );

		$request = new WP_REST_Request( 'GET', '/idpg/v1/organizer/venues' );
		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'idpg_org_nonce_failed', $response->get_data()['code'] );
	}

	// =====================================================================
	// Совместимость с Carbon Fields
	// =====================================================================

	/**
	 * Каждое поле контейнера «Данные площадки» пишется под своим ключом CF.
	 */
	public function test_venue_fields_use_carbon_meta_keys() {
		$organizer = $this->make_organizer( 'vf_cf' );

		wp_set_current_user( $organizer->ID );

		$response = $this->request( 'POST', '/idpg/v1/organizer/venues', $this->valid_payload() );
		$venue_id = (int) $response->get_data()['data']['id'];

		$params = $this->valid_payload();

		foreach ( $this->venue_schema_fields() as $field ) {
			// В схеме `name` — это уже готовый ключ мета с ведущим
			// подчёркиванием (`_idpg_venue_type`), а REST принимает тот же
			// ключ без него (`venue_type`). Сходимость проверяется здесь,
			// чтобы значение не ушло в поле, которого никто не читает.
			$meta_key = $field['name'];
			$param    = preg_replace( '/^_?idpg_/', '', $meta_key );

			$this->assertStringStartsWith( '_idpg_', $meta_key, 'Ключ мета вне ожидаемого пространства имён.' );
			$this->assertArrayHasKey( $param, $params, 'Для поля ' . $meta_key . ' нет значения в наборе.' );

			$this->assertSame(
				$params[ $param ],
				get_post_meta( $venue_id, $meta_key, true ),
				'Поле ' . $meta_key . ' не сохранено.'
			);
		}
	}

	/**
	 * Постер площадки — нативное поле WP.
	 */
	public function test_venue_poster_saved() {
		$organizer = $this->make_organizer( 'vf_poster' );
		wp_set_current_user( $organizer->ID );

		$attachment = $this->make_attachment();

		$response = $this->request(
			'POST',
			'/idpg/v1/organizer/venues',
			$this->valid_payload( array( 'poster_id' => $attachment ) )
		);

		$venue_id = (int) $response->get_data()['data']['id'];

		$this->assertSame( $attachment, get_post_thumbnail_id( $venue_id ) );
	}

	/**
	 * Чужой ID в качестве постера не принимается.
	 */
	public function test_venue_poster_must_be_attachment() {
		$organizer = $this->make_organizer( 'vf_poster_bad' );
		wp_set_current_user( $organizer->ID );

		$plain = $this->factory()->post->create( array( 'post_type' => 'event' ) );

		$response = $this->request(
			'POST',
			'/idpg/v1/organizer/venues',
			$this->valid_payload( array( 'poster_id' => $plain ) )
		);

		$venue_id = (int) $response->get_data()['data']['id'];

		$this->assertNotSame( $plain, get_post_thumbnail_id( $venue_id ) );
	}

	/**
	 * Значения полей возвращаются в ответе без потерь.
	 */
	public function test_venue_round_trip() {
		$organizer = $this->make_organizer( 'vf_round' );
		$venue_id  = $this->make_venue( $organizer );

		$data = $this->request( 'GET', '/idpg/v1/organizer/venues/' . $venue_id )->get_data()['data'];

		foreach ( array( 'venue_type', 'venue_address', 'venue_phone', 'venue_email', 'venue_website', 'venue_capacity', 'venue_lat', 'venue_lng' ) as $field ) {
			$this->assertSame(
				$this->valid_payload()[ $field ],
				$data[ $field ],
				'Поле ' . $field . ' не вернулось в ответе.'
			);
		}

		$this->assertSame( 'ДК «Тест»', $data['title'] );
		$this->assertNotEmpty( $data['city'] );
	}

	/**
	 * Тип площадки проверяется по перечислению.
	 */
	public function test_venue_type_enum_enforced() {
		wp_set_current_user( $this->make_organizer( 'vf_enum' )->ID );

		$response = $this->request(
			'POST',
			'/idpg/v1/organizer/venues',
			$this->valid_payload( array( 'venue_type' => 'стадион' ) )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	// =====================================================================
	// Удаление
	// =====================================================================

	/**
	 * Площадка, на которую ссылается событие, не удаляется.
	 *
	 * Иначе удаление обнулило бы `_idpg_venue` у события, и оно молча потеряло
	 * бы место проведения.
	 */
	public function test_delete_returns_409_when_venue_in_use() {
		$organizer = $this->make_organizer( 'vf_del_busy' );
		$venue_id  = $this->make_venue( $organizer );

		$event_id = $this->factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'publish',
				'post_author' => $organizer->ID,
			)
		);
		update_post_meta( $event_id, '_idpg_venue', $venue_id );

		$response = $this->request( 'DELETE', '/idpg/v1/organizer/venues/' . $venue_id );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'idpg_org_venue_in_use', $response->get_data()['code'] );
		$this->assertNotNull( get_post( $venue_id ), 'Площадка не должна была удалиться.' );

		// После разрыва связи удаление проходит.
		delete_post_meta( $event_id, '_idpg_venue' );

		$this->assertSame( 200, $this->request( 'DELETE', '/idpg/v1/organizer/venues/' . $venue_id )->get_status() );
		$this->assertNull( get_post( $venue_id ) );
	}

	/**
	 * Удаление свободной площадки проходит.
	 */
	public function test_delete_free_venue() {
		$organizer = $this->make_organizer( 'vf_del_ok' );
		$venue_id  = $this->make_venue( $organizer );

		$response = $this->request( 'DELETE', '/idpg/v1/organizer/venues/' . $venue_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertNull( get_post( $venue_id ) );
	}

	// =====================================================================
	// Маршруты
	// =====================================================================

	/**
	 * Адреса формы площадки.
	 */
	public function test_venue_form_urls() {
		$this->assertSame( home_url( '/organizer/venue/new/' ), IDPG_Organizer_URL::venue_form_url( 0 ) );
		$this->assertSame( home_url( '/organizer/venue/15/' ), IDPG_Organizer_URL::venue_form_url( 15 ) );
		$this->assertSame( home_url( '/organizer/venue/new/' ), IDPG_Organizer_URL::venue_form_url( 'abc' ) );
	}

	/**
	 * Правила формы площадки зарегистрированы.
	 */
	public function test_venue_rewrite_rules_registered() {
		global $wp_rewrite;

		update_option( IDPG_Organizer_URL::FLUSH_OPT, IDPG_Organizer_URL::REWRITE_VERSION );
		IDPG_Organizer_URL::rewrites();

		$venue_rules = array();

		foreach ( (array) $wp_rewrite->extra_rules_top as $regex => $query ) {
			if ( false !== strpos( (string) $query, IDPG_Organizer_URL::VENUE_QVAR ) ) {
				$venue_rules[ $regex ] = $query;
			}
		}

		$this->assertCount( 2, $venue_rules, 'Ожидались два правила формы площадки.' );
		$this->assertArrayHasKey( '^organizer/venue/new/?$', $venue_rules );
		$this->assertArrayHasKey( '^organizer/venue/([0-9]+)/?$', $venue_rules );
	}

	/**
	 * Query-переменная площадки зарегистрирована.
	 */
	public function test_venue_qvar_registered() {
		$this->assertContains( IDPG_Organizer_URL::VENUE_QVAR, IDPG_Organizer_URL::query_vars( array() ) );
	}

	/**
	 * Версия сброса правил поднята: правила площадки добавлены после
	 * предыдущего сброса.
	 */
	public function test_rewrite_version_covers_venue_rules() {
		$this->assertGreaterThanOrEqual( 3, IDPG_Organizer_URL::REWRITE_VERSION );
	}

	// =====================================================================
	// Форма
	// =====================================================================

	/**
	 * В шаблоне формы есть каждое поле контейнера «Данные площадки».
	 */
	public function test_form_has_every_venue_field() {
		$file = $this->theme_path() . '/template-parts/organizer/venue-form.php';

		$this->assertFileExists( $file );

		$source = file_get_contents( $file );

		foreach ( $this->venue_schema_fields() as $field ) {
			$param = preg_replace( '/^idpg_/', '', $field['base_name'] );

			$this->assertSame(
				1,
				preg_match( '/name="' . preg_quote( $param, '/' ) . '"/', $source ),
				'Поле ' . $field['name'] . ' отсутствует в форме (ожидается параметр ' . $param . ').'
			);
		}
	}

	/**
	 * В форме нет полей, которых нет в схеме площадки.
	 */
	public function test_form_has_no_foreign_fields() {
		$source = file_get_contents( $this->theme_path() . '/template-parts/organizer/venue-form.php' );

		// Нативные поля WordPress, которые форма использует осознанно.
		$allowed = array( 'title', 'content', 'city', 'poster_id' );

		foreach ( $this->venue_schema_fields() as $field ) {
			$allowed[] = preg_replace( '/^idpg_/', '', $field['base_name'] );
		}

		// Служебные поля формы и страницы.
		$allowed[] = 'idpg_org_venue_submit';
		$allowed[] = 'idpg_org_venue_nonce';

		preg_match_all( '/name="([a-z_0-9]+)"/', $source, $m );

		foreach ( array_unique( $m[1] ) as $name ) {
			$this->assertContains(
				$name,
				$allowed,
				'Поле ' . $name . ' есть в форме, но его нет в схеме площадки.'
			);
		}
	}

	/**
	 * Обязательные поля помечены required — иначе браузер пропустил бы пустую
	 * форму, которую сервер затем отверг бы с 400.
	 */
	public function test_required_fields_marked_in_form() {
		$source = file_get_contents( $this->theme_path() . '/template-parts/organizer/venue-form.php' );

		foreach ( array( 'title', 'city', 'venue_address' ) as $field ) {
			$this->assertSame(
				1,
				preg_match( '/name="' . $field . '"[^>]*\brequired\b/s', $source ),
				'Поле ' . $field . ' должно быть обязательным в форме.'
			);
		}
	}

	/**
	 * Типы полей соответствуют ожидаемым.
	 *
	 * Порядок атрибутов в разметке не фиксируется: `type` может стоять
	 * до `name`, поэтому атрибуты ищутся внутри одного тега, а не по
	 * позиции относительно `name`.
	 */
	public function test_field_types() {
		$source = file_get_contents( $this->theme_path() . '/template-parts/organizer/venue-form.php' );

		$expect = array(
			'venue_phone'    => 'tel',
			'venue_email'    => 'email',
			'venue_website'  => 'url',
			'venue_capacity' => 'number',
		);

		foreach ( $expect as $name => $type ) {
			$tag = $this->input_tag( $source, $name );

			$this->assertNotNull( $tag, 'Поле ' . $name . ' не найдено в форме.' );
			$this->assertSame( 1, preg_match( '/\btype="' . $type . '"/', $tag ), 'У поля ' . $name . ' ожидался type="' . $type . '".' );
		}

		// Вместимость не может быть отрицательной.
		$capacity = $this->input_tag( $source, 'venue_capacity' );
		$this->assertSame( 1, preg_match( '/\bmin="0"/', $capacity ) );

		// Координаты намеренно не `number`: в локалях с запятой как
		// десятичным разделителем такое поле отправляет пустое значение.
		// Вместо этого ставится текстовое поле с подсказкой ввода.
		foreach ( array( 'venue_lat', 'venue_lng' ) as $name ) {
			$tag = $this->input_tag( $source, $name );

			$this->assertNotNull( $tag, 'Поле ' . $name . ' не найдено в форме.' );
			$this->assertSame( 1, preg_match( '/\btype="text"/', $tag ), 'Поле ' . $name . ' должно быть текстовым.' );
			$this->assertSame( 1, preg_match( '/\binputmode="decimal"/', $tag ), 'Поле ' . $name . ' должно подсказывать десятичный ввод.' );
		}
	}

	/**
	 * Тег `<input>` по имени поля.
	 *
	 * @param string $source Разметка формы.
	 * @param string $name   Имя поля.
	 * @return string|null
	 */
	protected function input_tag( $source, $name ) {
		if ( ! preg_match_all( '/<input\b[^>]*>/s', $source, $all ) ) {
			return null;
		}

		// Ищем тот тег, в котором встречается нужное имя.
		foreach ( $all[0] as $tag ) {
			if ( false !== strpos( $tag, 'name="' . $name . '"' ) ) {
				return $tag;
			}
		}

		return null;
	}

	/**
	 * В форме есть no-JS fallback: скрытое поле-маркер и nonce.
	 */
	public function test_form_has_nojs_fallback() {
		$source = file_get_contents( $this->theme_path() . '/template-parts/organizer/venue-form.php' );

		$this->assertStringContainsString( 'name="idpg_org_venue_submit"', $source );
		$this->assertStringContainsString( 'idpg_organizer_venue', $source );
		$this->assertStringContainsString( 'method="post"', $source );

		// Шаблон страницы обрабатывает этот POST.
		$page = file_get_contents( $this->theme_path() . '/page-organizer-venue.php' );
		$this->assertStringContainsString( 'idpg_org_venue_submit', $page );
		$this->assertStringContainsString( 'wp_verify_nonce', $page );
		$this->assertStringContainsString( 'wp_safe_redirect', $page );
	}

	/**
	 * Постер выбирается через wp.media, и скрипты подключаются по наличию
	 * upload_files.
	 */
	public function test_venue_form_enqueues_media_only_with_capability() {
		$source = file_get_contents( $this->theme_path() . '/template-parts/organizer/venue-form.php' );

		$this->assertStringContainsString( 'wp_enqueue_media()', $source );
		$this->assertStringContainsString( "current_user_can( 'upload_files' )", $source );
	}

	// =====================================================================
	// Область видимости партиала
	//
	// Регресс. Партиал подключается через `get_template_part()`, то есть
	// внутри `load_template()`, и локальные переменные страницы в его области
	// не существуют. Когда партиал брал их оттуда, форма выводилась с
	// пустыми значениями и `disabled` на всех полях — то есть без
	// JavaScript заполнить её было невозможно. Тесты рендерят партиал
	// именно через `load_template()`, а не прямым include: прямой include
	// выполнялся бы в области теста и такой регресс не увидел бы.
	// =====================================================================

	/**
	 * Отрисовать партиал в его настоящей области видимости.
	 *
	 * @return string
	 */
	protected function render_form_part() {
		ob_start();
		load_template( $this->theme_path() . '/template-parts/organizer/venue-form.php', false );

		return (string) ob_get_clean();
	}

	/**
	 * Подготовить глобалы, которые читает партиал.
	 *
	 * @param array $over Что заменить.
	 * @return void
	 */
	protected function prime_form_globals( $over = array() ) {
		global $idpg_venue_id, $idpg_venue_data, $idpg_venue_cities, $idpg_venue_can_write;

		$idpg_venue_id = 4242;
		$idpg_venue_data = array_merge(
			array(
				'title'          => 'ДК «Тест»',
				'content'        => 'Описание',
				'city'           => array( 15 ),
				'venue_type'     => 'theater',
				'venue_address'  => 'ул. Ленина, 1',
				'venue_phone'    => '+7 900 000-00-00',
				'venue_email'    => 'hall@example.com',
				'venue_website'  => 'https://example.com',
				'venue_capacity' => '500',
				'venue_lat'      => '56.8527',
				'venue_lng'      => '53.2114',
				'poster_id'      => 0,
			),
			$over
		);
		$idpg_venue_cities    = array( 15 => 'Иваново' );
		$idpg_venue_can_write = true;
	}

	/**
	 * Убрать глобалы, чтобы следующий тест не видел чужих данных.
	 *
	 * @return void
	 */
	protected function clear_form_globals() {
		global $idpg_venue_id, $idpg_venue_data, $idpg_venue_cities, $idpg_venue_can_write;

		$idpg_venue_id         = null;
		$idpg_venue_data       = null;
		$idpg_venue_cities     = null;
		$idpg_venue_can_write  = null;
	}

	/**
	 * У организатора с правом записи поля формы доступны.
	 *
	 * Без этого путь без JavaScript нерабочий: `disabled` на всех полях не
	 * даёт ничего ввести.
	 */
	public function test_form_part_renders_enabled_fields_for_writer() {
		$this->prime_form_globals();

		$html = $this->render_form_part();

		$this->clear_form_globals();

		$this->assertNotSame( '', $html );
		$this->assertStringNotContainsString(
			'disabled=',
			$html,
			'Поля формы не должны быть disabled при наличии права записи.'
		);
	}

	/**
	 * Партиал получает значения площадки, а не пустую форму.
	 */
	public function test_form_part_prefills_from_globals() {
		$this->prime_form_globals();

		$html = $this->render_form_part();

		$this->clear_form_globals();

		$this->assertStringContainsString( 'value="ДК «Тест»"', $html );
		$this->assertStringContainsString( 'value="ул. Ленина, 1"', $html );
		$this->assertStringContainsString( 'value="+7 900 000-00-00"', $html );
		$this->assertStringContainsString( 'value="hall@example.com"', $html );
		$this->assertStringContainsString( 'value="https://example.com"', $html );
		$this->assertStringContainsString( 'value="500"', $html );
		$this->assertStringContainsString( 'value="56.8527"', $html );
		$this->assertStringContainsString( 'value="53.2114"', $html );
		$this->assertStringContainsString( 'ДК «Тест»', $html, 'Описание должно быть в textarea.' );

		// Выбранные значения — дефолт, который иначе пришлось бы выбирать
		// заново при каждой правке.
		$this->assertStringContainsString( 'value="theater"  selected', $html );
		$this->assertStringContainsString( 'value="15"  selected', $html );
	}

	/**
	 * У площадки, которая уже создана, есть кнопка удаления.
	 */
	public function test_form_part_offers_delete_for_existing_venue() {
		$this->prime_form_globals();

		$html = $this->render_form_part();

		$this->clear_form_globals();

		$this->assertStringContainsString( 'data-idpg-venue-delete', $html );
		$this->assertStringContainsString( 'venue-form__danger', $html );
	}

	/**
	 * У новой площадки кнопки удаления нет: удалять ещё нечего.
	 */
	public function test_form_part_has_no_delete_for_new_venue() {
		$this->prime_form_globals( array() );
		global $idpg_venue_id;

		$idpg_venue_id = 0;

		$html = $this->render_form_part();

		$this->clear_form_globals();

		$this->assertStringNotContainsString( 'data-idpg-venue-delete', $html );
	}

	/**
	 * Без глобалов партиал не падает и не рассыпает уведомлений PHP.
	 *
	 * Поля при этом закрыты: прав на запись у вызывающего кода не было.
	 */
	public function test_form_part_without_globals_is_safe() {
		$this->clear_form_globals();

		$html = $this->render_form_part();

		$this->assertNotSame( '', $html );
		$this->assertStringNotContainsString( 'Warning:', $html );
		$this->assertStringNotContainsString( 'Notice:', $html );
		$this->assertStringNotContainsString( 'Undefined', $html );
		$this->assertStringContainsString( 'disabled=', $html, 'Без прав на запись поля должны быть закрыты.' );
	}


	// =====================================================================
	// Раздел кабинета
	// =====================================================================

	/**
	 * Раздел «Площадки» есть и в маршрутах, и в меню.
	 */
	public function test_venues_section_exists() {
		$this->assertContains( 'venues', IDPG_Organizer_URL::get_sections() );
		$this->assertArrayHasKey( 'venues', idpg_organizer_menu() );
		$this->assertSame( 'venues', idpg_organizer_menu()['venues']['slug'] );
	}

	/**
	 * Для раздела есть шаблон, и он содержит каркас списка.
	 */
	public function test_venues_section_template_exists() {
		$file = $this->theme_path() . '/template-parts/organizer/section-venues.php';

		$this->assertFileExists( $file );

		$source = file_get_contents( $file );

		$this->assertStringContainsString( 'data-idpg-venues', $source );
		$this->assertStringContainsString( 'data-idpg-venues-list-url', $source );
		$this->assertStringContainsString( 'data-idpg-venues-new-url', $source );
	}

	/**
	 * Скрипт списка подключается на страницах кабинета.
	 */
	public function test_organizer_js_handles_venues_section() {
		$source = file_get_contents( $this->theme_path() . '/assets/js/organizer.js' );

		$this->assertStringContainsString( 'initVenues', $source );
		$this->assertStringContainsString( 'data-idpg-venues-body', $source );
	}
}
