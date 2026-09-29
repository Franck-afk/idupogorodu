<?php
/**
 * Юнит-тесты DEV-48: синхронизация сеансов Carbon Fields ↔ зеркало.
 *
 * Сеансы хранятся в complex-поле Carbon Fields (`idpg_sessions`, подполя
 * time/price/url). Carbon Fields раскладывает complex-поле по служебным
 * мета-ключам (`_idpg_sessions|time|0|0|value` и т.п.), а шаблоны читают
 * простое зеркало `_idpg_sessions_json`.
 *
 * Проверяется, что обе стороны видят правки друг друга:
 *  - организатор пишет сеансы через REST → редактор CF их видит;
 *  - администратор правит сеансы в редакторе → организатор видит их в API;
 *  - пустое значение не остаётся строкой-заглушкой ни в зеркале, ни в API;
 *  - битое значение в хранилище CF не роняет разбор.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Sessions_Sync
 */
class Test_Sessions_Sync extends WP_UnitTestCase {

	/**
	 * Маршрут списка и создания.
	 */
	const EVENTS = '/idpg/v1/organizer/events';

	/**
	 * Мета-зеркало сеансов.
	 */
	const MIRROR = '_idpg_sessions_json';

	/**
	 * Подготовка окружения.
	 */
	public function set_up() {
		parent::set_up();

		IDPG_Meta::instance();
		IDPG_REST_Organizer::instance();
		IDPG_Roles::instance()->create_roles();
		IDPG_REST_Organizer::instance()->register_post_status();
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
	 * Запрос с валидным nonce.
	 *
	 * @param string $method HTTP-метод.
	 * @param string $route  Маршрут.
	 * @param array  $params Параметры.
	 * @return WP_REST_Response
	 */
	protected function request( $method, $route, $params = array() ) {
		$request = new WP_REST_Request( $method, $route );

		if ( $params ) {
			if ( in_array( $method, array( 'GET', 'DELETE' ), true ) ) {
				$request->set_query_params( $params );
			} else {
				$request->set_body_params( $params );
			}
		}

		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return rest_do_request( $request );
	}

	/**
	 * Создать событие организатора через REST.
	 *
	 * @param WP_User $organizer Организатор.
	 * @param array   $params    Параметры запроса.
	 * @return array [WP_REST_Response, int ID события].
	 */
	protected function create_via_rest( $organizer, $params ) {
		wp_set_current_user( $organizer->ID );

		$response = $this->request( 'POST', self::EVENTS, $params );
		$data     = $response->get_data();

		return array(
			$response,
			isset( $data['data']['id'] ) ? (int) $data['data']['id'] : 0,
		);
	}

	/**
	 * Сеансы в том виде, в каком их читает редактор Carbon Fields.
	 *
	 * CF добавляет служебный ключ `_type`, поэтому сравниваются только
	 * содержательные подполя.
	 *
	 * @param int $post_id ID события.
	 * @return array Список {time, price, url}.
	 */
	protected function sessions_from_carbon( $post_id ) {
		$out = array();

		foreach ( (array) carbon_get_post_meta( $post_id, 'idpg_sessions' ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$out[] = array(
				'time'  => isset( $row['time'] ) ? (string) $row['time'] : '',
				'price' => isset( $row['price'] ) ? (string) $row['price'] : '',
				'url'   => isset( $row['url'] ) ? (string) $row['url'] : '',
			);
		}

		return $out;
	}

	/**
	 * Эмулировать сохранение сеансов администратором в редакторе.
	 *
	 * Так же, как это делает `Post_Meta_Container::save()`: значение пишется
	 * в complex-поле, затем срабатывает хук
	 * `carbon_fields_post_meta_container_saved`, на котором висит
	 * `IDPG_Meta::mirror_sessions()`.
	 *
	 * @param int   $post_id ID события.
	 * @param array $rows    Список сеансов.
	 * @return void
	 */
	protected function save_as_admin( $post_id, $rows ) {
		carbon_set_post_meta( $post_id, 'idpg_sessions', $rows );

		do_action( 'carbon_fields_post_meta_container_saved', $post_id, null );
	}

	// =====================================================================
	// Направление 1: организатор → редактор
	// =====================================================================

	/**
	 * Сеансы организатора видны в редакторе Carbon Fields.
	 *
	 * Регресс DEV-48: раньше `save_meta()` писал только зеркало, поэтому
	 * `carbon_get_post_meta()` возвращал пусто, и администратор в wp-admin
	 * сеансов организатора не видел — в точности как DEV-42 обнаружил для
	 * галереи.
	 */
	public function test_organizer_sessions_visible_in_carbon() {
		$organizer = $this->make_organizer( 'sync_org' );

		list( $response, $event_id ) = $this->create_via_rest(
			$organizer,
			array(
				'title'         => 'Сеансы организатора',
				'sessions_json' => wp_json_encode(
					array(
						array( 'time' => '19:00', 'price' => '500', 'url' => 'https://example.com/t1' ),
						array( 'time' => '21:30', 'price' => '900' ),
					)
				),
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertGreaterThan( 0, $event_id );

		$this->assertSame(
			array(
				array( 'time' => '19:00', 'price' => '500', 'url' => 'https://example.com/t1' ),
				array( 'time' => '21:30', 'price' => '900', 'url' => '' ),
			),
			$this->sessions_from_carbon( $event_id ),
			'Сеансы организатора должны попасть в complex-поле Carbon Fields.'
		);
	}

	/**
	 * Зеркало собирается из complex-поля, а не хранит сырую строку запроса.
	 */
	public function test_mirror_matches_carbon_after_organizer_save() {
		$organizer = $this->make_organizer( 'sync_mirror' );

		list( , $event_id ) = $this->create_via_rest(
			$organizer,
			array(
				'title'         => 'Зеркало',
				'sessions_json' => wp_json_encode(
					array( array( 'time' => '18:00', 'price' => '300', 'url' => 'https://example.com/a' ) )
				),
			)
		);

		$mirror = get_post_meta( $event_id, self::MIRROR, true );

		$this->assertSame(
			$this->sessions_from_carbon( $event_id ),
			json_decode( $mirror, true ),
			'Зеркало и complex-поле должны содержать одни и те же сеансы.'
		);
	}

	/**
	 * Правка сеансов организатором тоже доходит до редактора.
	 */
	public function test_organizer_update_sessions_reaches_carbon() {
		$organizer = $this->make_organizer( 'sync_update' );

		list( , $event_id ) = $this->create_via_rest(
			$organizer,
			array(
				'title'         => 'Черновик',
				'sessions_json' => wp_json_encode( array( array( 'time' => '12:00', 'price' => '0' ) ) ),
			)
		);

		$this->assertSame( '12:00', $this->sessions_from_carbon( $event_id )[0]['time'] );

		$response = $this->request(
			'PUT',
			self::EVENTS . '/' . $event_id,
			array(
				'title'         => 'Черновик',
				'sessions_json' => wp_json_encode(
					array( array( 'time' => '16:45', 'price' => '1200' ) )
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$sessions = $this->sessions_from_carbon( $event_id );

		$this->assertCount( 1, $sessions );
		$this->assertSame( '16:45', $sessions[0]['time'] );
		$this->assertSame( '1200', $sessions[0]['price'] );
	}

	// =====================================================================
	// Направление 2: редактор → организатор
	// =====================================================================

	/**
	 * Сеансы из админки видны организатору через API.
	 */
	public function test_admin_sessions_visible_to_organizer() {
		$organizer = $this->make_organizer( 'sync_admin' );

		$event_id = $this->factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'draft',
				'post_author' => $organizer->ID,
			)
		);

		$this->save_as_admin(
			$event_id,
			array(
				array( 'time' => '17:15', 'price' => '450', 'url' => 'https://example.com/admin' ),
				array( 'time' => '20:00', 'price' => '750', 'url' => '' ),
			)
		);

		// Сравниваем декодированные данные, а не строку: wp_json_encode()
		// не экранирует слэши, и посимвольная проверка была бы хрупкой.
		$this->assertSame(
			array(
				array( 'time' => '17:15', 'price' => '450', 'url' => 'https://example.com/admin' ),
				array( 'time' => '20:00', 'price' => '750', 'url' => '' ),
			),
			json_decode( get_post_meta( $event_id, self::MIRROR, true ), true )
		);

		wp_set_current_user( $organizer->ID );

		$data = $this->request( 'GET', self::EVENTS . '/' . $event_id )->get_data();

		$this->assertSame(
			array(
				array( 'time' => '17:15', 'price' => '450', 'url' => 'https://example.com/admin' ),
				array( 'time' => '20:00', 'price' => '750', 'url' => '' ),
			),
			json_decode( $data['data']['sessions_json'], true )
		);
	}

	/**
	 * Полный круг: организатор записал сеанс, админ его поправил,
	 * организатор видит правку.
	 */
	public function test_full_roundtrip_both_directions() {
		$organizer = $this->make_organizer( 'sync_round' );

		list( , $event_id ) = $this->create_via_rest(
			$organizer,
			array(
				'title'         => 'Круг',
				'sessions_json' => wp_json_encode( array( array( 'time' => '10:00', 'price' => '100' ) ) ),
			)
		);

		// Органиторский сеанс дошёл до редактора.
		$this->assertSame( '10:00', $this->sessions_from_carbon( $event_id )[0]['time'] );

		// Администратор меняет его в редакторе.
		$this->save_as_admin(
			$event_id,
			array( array( 'time' => '11:30', 'price' => '250', 'url' => 'https://example.com/fixed' ) )
		);

		// И организатор видит правку.
		wp_set_current_user( $organizer->ID );
		$data = $this->request( 'GET', self::EVENTS . '/' . $event_id )->get_data();

		$this->assertSame(
			array( array( 'time' => '11:30', 'price' => '250', 'url' => 'https://example.com/fixed' ) ),
			json_decode( $data['data']['sessions_json'], true )
		);

		// Обратно: правка организатора уходит в редактор.
		$this->request(
			'PUT',
			self::EVENTS . '/' . $event_id,
			array(
				'title'         => 'Круг',
				'sessions_json' => wp_json_encode( array( array( 'time' => '13:00', 'price' => '350' ) ) ),
			)
		);

		$sessions = $this->sessions_from_carbon( $event_id );

		$this->assertCount( 1, $sessions );
		$this->assertSame( '13:00', $sessions[0]['time'] );
	}

	// =====================================================================
	// Пустые значения
	// =====================================================================

	/**
	 * Пустой список сеансов не остаётся строкой-заглушкой.
	 *
	 * Carbon Fields хранит пустое complex-поле как служебные значения с
	 * суффиксом `|||0|_empty`. Такое не должно ни попасть в зеркало, ни
	 * уехать в API.
	 */
	public function test_empty_sessions_leave_no_placeholder() {
		$organizer = $this->make_organizer( 'sync_empty' );

		list( , $event_id ) = $this->create_via_rest(
			$organizer,
			array(
				'title'         => 'Пусто',
				'sessions_json' => wp_json_encode( array() ),
			)
		);

		$this->assertSame( array(), $this->sessions_from_carbon( $event_id ) );
		$this->assertSame(
			false,
			metadata_exists( 'post', $event_id, self::MIRROR ),
			'При пустых сеансах зеркало должно удаляться, а не храниться пустым.'
		);

		wp_set_current_user( $organizer->ID );
		$data = $this->request( 'GET', self::EVENTS . '/' . $event_id )->get_data();

		$this->assertSame( '', $data['data']['sessions_json'] );
		$this->assertStringNotContainsString( '_empty', $data['data']['sessions_json'] );
		$this->assertStringNotContainsString( '|||', $data['data']['sessions_json'] );
	}

	/**
	 * Пустая строка и отсутствие параметра не создают сеансов.
	 */
	public function test_blank_and_missing_sessions_are_noop() {
		$organizer = $this->make_organizer( 'sync_blank' );

		// Пустая строка.
		list( , $blank_id ) = $this->create_via_rest(
			$organizer,
			array( 'title' => 'Пустая строка', 'sessions_json' => '' )
		);

		$this->assertSame( array(), $this->sessions_from_carbon( $blank_id ) );
		$this->assertFalse( metadata_exists( 'post', $blank_id, self::MIRROR ) );

		// Параметр не передан — событие остаётся без сеансов.
		list( , $plain_id ) = $this->create_via_rest( $organizer, array( 'title' => 'Без параметра' ) );

		$this->assertSame( array(), $this->sessions_from_carbon( $plain_id ) );
		$this->assertFalse( metadata_exists( 'post', $plain_id, self::MIRROR ) );
	}

	/**
	 * Очистка сеансов через REST удаляет их из редактора.
	 */
	public function test_clearing_sessions_via_rest_empties_carbon() {
		$organizer = $this->make_organizer( 'sync_clear' );

		list( , $event_id ) = $this->create_via_rest(
			$organizer,
			array(
				'title'         => 'С очисткой',
				'sessions_json' => wp_json_encode( array( array( 'time' => '15:00', 'price' => '100' ) ) ),
			)
		);

		$this->assertCount( 1, $this->sessions_from_carbon( $event_id ) );

		$this->request(
			'PUT',
			self::EVENTS . '/' . $event_id,
			array( 'title' => 'С очисткой', 'sessions_json' => wp_json_encode( array() ) )
		);

		$this->assertSame( array(), $this->sessions_from_carbon( $event_id ) );
		$this->assertFalse( metadata_exists( 'post', $event_id, self::MIRROR ) );
	}

	// =====================================================================
	// Некорректные данные
	// =====================================================================

	/**
	 * Битый JSON отклоняется с 400 и ничего не записывает.
	 */
	public function test_invalid_json_rejected() {
		$organizer = $this->make_organizer( 'sync_bad_json' );

		list( $response, $event_id ) = $this->create_via_rest(
			$organizer,
			array( 'title' => 'Битый JSON', 'sessions_json' => '{не json' )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'idpg_org_invalid_sessions', $response->get_data()['code'] );
		$this->assertSame( array(), $this->sessions_from_carbon( $event_id ) );
	}

	/**
	 * Строка без времени не является сеансом и отбрасывается.
	 */
	public function test_rows_without_time_are_skipped() {
		$organizer = $this->make_organizer( 'sync_no_time' );

		list( , $event_id ) = $this->create_via_rest(
			$organizer,
			array(
				'title'         => 'Без времени',
				'sessions_json' => wp_json_encode(
					array(
						array( 'price' => '500' ),
						array( 'time' => '  ', 'price' => '600' ),
						array( 'time' => '19:00', 'price' => '700' ),
					)
				),
			)
		);

		$this->assertSame(
			array( array( 'time' => '19:00', 'price' => '700', 'url' => '' ) ),
			$this->sessions_from_carbon( $event_id )
		);
	}

	/**
	 * Небезопасная ссылка отбрасывается, а не попадает в сеанс.
	 */
	public function test_unsafe_session_url_is_dropped() {
		$organizer = $this->make_organizer( 'sync_url' );

		list( , $event_id ) = $this->create_via_rest(
			$organizer,
			array(
				'title'         => 'Ссылка',
				'sessions_json' => wp_json_encode(
					array( array( 'time' => '19:00', 'price' => '500', 'url' => 'javascript:alert(1)' ) )
				),
			)
		);

		$this->assertSame( '', $this->sessions_from_carbon( $event_id )[0]['url'] );
	}

	/**
	 * Битое значение в служебных мета-ключах CF не роняет разбор.
	 *
	 * Хранилище CF — набор отдельных мета-строк, поэтому в него можно
	 * положить мусор в обход API. `mirror_sessions()` обязан пережить это
	 * молча: событие не должно падать на фронте из-за испорченной записи.
	 */
	public function test_corrupt_carbon_storage_does_not_fatal() {
		$organizer = $this->make_organizer( 'sync_corrupt' );

		$event_id = $this->factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'draft',
				'post_author' => $organizer->ID,
			)
		);

		// Мусор в ключах, которые читает complex-поле.
		update_post_meta( $event_id, '_idpg_sessions|time|0|0|value', '19:00' );
		update_post_meta( $event_id, '_idpg_sessions|||0|value', '_' );
		update_post_meta( $event_id, '_idpg_sessions', 'полностью мусорная строка' );

		// Не должно быть ни фатала, ни исключения.
		IDPG_Meta::mirror_sessions( $event_id );

		$this->assertTrue( true, 'Разбор битого хранилища CF не должен приводить к ошибке.' );

		wp_set_current_user( $organizer->ID );
		$response = $this->request( 'GET', self::EVENTS . '/' . $event_id );

		$this->assertSame( 200, $response->get_status() );
	}
}
