<?php
/**
 * Юнит-тесты DEV-73: профиль организатора в кабинете.
 *
 * Проверяется:
 *  - GET/PUT /idpg/v1/organizer/profile: авторизация и права (401 / 403);
 *  - сохранение полей профиля и отдача их обратно;
 *  - санитайз (HTML-разметка вырезается, длина ограничена);
 *  - отказ на аватаре, который не является вложением;
 *  - частичное обновление не затирает остальные поля;
 *  - скоуп: маршрут работает только с текущим пользователем, подсунуть
 *    чужой user_id нельзя;
 *  - слой логики `IDPG_Organizer_URL` отдельно от REST-транспорта —
 *    этим же кодом пользуется no-JS форма в `page-organizer.php`.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Organizer_Profile
 */
class Test_Organizer_Profile extends WP_UnitTestCase {

	/**
	 * Маршрут профиля.
	 */
	const ROUTE = '/idpg/v1/organizer/profile';

	/**
	 * Подготовка окружения.
	 */
	public function set_up() {
		parent::set_up();

		// Конструктор вешает register_routes на rest_api_init.
		IDPG_REST_Auth::instance();

		// Роль организатора регистрируется плагином.
		IDPG_Roles::instance()->create_roles();
	}

	/**
	 * Создать пользователя с указанной ролью.
	 *
	 * @param string $login Логин (латиница).
	 * @param string $role  Слаг роли.
	 * @return WP_User
	 */
	protected function make_user( $login, $role ) {
		$user_id = $this->factory()->user->create(
			array(
				'user_login' => $login,
				'user_email' => $login . '@example.com',
				'role'       => $role,
			)
		);

		return new WP_User( $user_id );
	}

	/**
	 * Создать организатора с чистой ролью event_organizer.
	 *
	 * @param string $login Логин (латиница).
	 * @return WP_User
	 */
	protected function make_organizer( $login ) {
		$user = $this->make_user( $login, 'subscriber' );

		foreach ( (array) $user->roles as $role ) {
			$user->remove_role( $role );
		}
		$user->add_role( IDPG_Roles::ROLE_SLUG );

		return $user;
	}

	/**
	 * GET-запрос профиля от имени текущего пользователя.
	 *
	 * @return WP_REST_Response
	 */
	protected function get_profile() {
		return rest_do_request( new WP_REST_Request( 'GET', self::ROUTE ) );
	}

	/**
	 * PUT-запрос профиля от имени текущего пользователя.
	 *
	 * @param array $params Поля профиля.
	 * @return WP_REST_Response
	 */
	protected function put_profile( $params ) {
		$request = new WP_REST_Request( 'PUT', self::ROUTE );
		$request->set_body_params( $params );

		return rest_do_request( $request );
	}

	/**
	 * Заполнить профиль пользователя произвольными значениями.
	 *
	 * @param WP_User $user Пользователь.
	 * @param array   $meta Мета-ключ => значение.
	 */
	protected function seed_profile( $user, $meta ) {
		foreach ( $meta as $key => $value ) {
			update_user_meta( $user->ID, $key, $value );
		}
	}

	// =====================================================================
	// Права
	// =====================================================================

	/**
	 * Гость не читает профиль.
	 */
	public function test_guest_cannot_read_profile() {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->get_profile()->get_status() );
	}

	/**
	 * Гость не сохраняет профиль.
	 */
	public function test_guest_cannot_update_profile() {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->put_profile( array( 'org_name' => 'Взлом' ) )->get_status() );
	}

	/**
	 * Подписчик (роль есть, но не организатор) получает 403.
	 *
	 * Проверка именно строгая: у подписчика есть capability `read`,
	 * поэтомуpermission_callback не может опираться только на неё.
	 */
	public function test_subscriber_is_forbidden() {
		$subscriber = $this->make_user( 'profile_sub', 'subscriber' );

		wp_set_current_user( $subscriber->ID );

		$this->assertTrue( current_user_can( 'read' ) );
		$this->assertSame( 403, $this->get_profile()->get_status() );
		$this->assertSame( 403, $this->put_profile( array( 'org_name' => 'Взлом' ) )->get_status() );

		// Мета подписчика не должны измениться.
		$this->assertSame( '', get_user_meta( $subscriber->ID, 'idpg_org_name', true ) );
	}

	/**
	 * Администратор (роль «и выше») профилем управляет.
	 */
	public function test_admin_can_update_profile() {
		$admin = $this->make_user( 'profile_admin', 'administrator' );

		wp_set_current_user( $admin->ID );

		$this->assertSame( 200, $this->get_profile()->get_status() );
		$this->assertSame( 200, $this->put_profile( array( 'org_name' => 'Админская организация' ) )->get_status() );
	}

	// =====================================================================
	// GET
	// =====================================================================

	/**
	 * GET отдаёт данные текущего организатора.
	 */
	public function test_get_returns_current_organizer_data() {
		$organizer = $this->make_organizer( 'profile_get' );

		$this->seed_profile(
			$organizer,
			array(
				'idpg_org_name'     => 'ООО «Рога и копыта»',
				'idpg_contact_name' => 'Иван Иванов',
				'idpg_phone'        => '+7 999 000-00-00',
			)
		);

		wp_set_current_user( $organizer->ID );

		$data = $this->get_profile()->get_data();

		$this->assertSame( $organizer->ID, $data['user_id'] );
		$this->assertSame( 'ООО «Рога и копыта»', $data['org_name'] );
		$this->assertSame( 'Иван Иванов', $data['contact_name'] );
		$this->assertSame( '+7 999 000-00-00', $data['phone'] );
		$this->assertSame( $organizer->user_email, $data['email'] );
	}

	/**
	 * Пустой профиль отдаёт пустые строки, а не отсутствующие ключи.
	 */
	public function test_get_returns_all_keys_for_empty_profile() {
		$organizer = $this->make_organizer( 'profile_empty' );

		wp_set_current_user( $organizer->ID );

		$data = $this->get_profile()->get_data();

		foreach ( array( 'user_id', 'display_name', 'email', 'org_name', 'contact_name', 'phone', 'avatar_id', 'avatar_url' ) as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}

		$this->assertSame( '', $data['org_name'] );
		$this->assertSame( 0, $data['avatar_id'] );
	}

	/**
	 * Email в профиле только для чтения: PUT его не меняет.
	 */
	public function test_update_does_not_change_email() {
		$organizer = $this->make_organizer( 'profile_email' );
		$before    = $organizer->user_email;

		wp_set_current_user( $organizer->ID );

		$this->put_profile( array( 'email' => 'hacker@example.com' ) );

		$this->assertSame( $before, get_user_by( 'id', $organizer->ID )->user_email );
	}

	// =====================================================================
	// PUT
	// =====================================================================

	/**
	 * PUT сохраняет все поля профиля.
	 */
	public function test_update_saves_all_fields() {
		$organizer = $this->make_organizer( 'profile_save' );

		wp_set_current_user( $organizer->ID );

		$response = $this->put_profile(
			array(
				'display_name' => 'ООО «Рога и копыта»',
				'org_name'     => 'Рога и копыта',
				'contact_name' => 'Пётр Петров',
				'phone'        => '+7 900 123-45-67',
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( 'ООО «Рога и копыта»', $data['display_name'] );
		$this->assertSame( 'Рога и копыта', $data['org_name'] );
		$this->assertSame( 'Пётр Петров', $data['contact_name'] );
		$this->assertSame( '+7 900 123-45-67', $data['phone'] );

		// Значения действительно записаны в БД.
		$user = get_user_by( 'id', $organizer->ID );

		$this->assertSame( 'ООО «Рога и копыта»', $user->display_name );
		$this->assertSame( 'Рога и копыта', get_user_meta( $organizer->ID, 'idpg_org_name', true ) );
		$this->assertSame( 'Пётр Петров', get_user_meta( $organizer->ID, 'idpg_contact_name', true ) );
		$this->assertSame( '+7 900 123-45-67', get_user_meta( $organizer->ID, 'idpg_phone', true ) );
	}

	/**
	 * Частичное обновление не затирает остальные поля.
	 */
	public function test_partial_update_keeps_other_fields() {
		$organizer = $this->make_organizer( 'profile_partial' );

		$this->seed_profile(
			$organizer,
			array(
				'idpg_org_name'     => 'Старое название',
				'idpg_contact_name' => 'Старый контакт',
				'idpg_phone'        => '+7 900 000-00-00',
			)
		);

		wp_set_current_user( $organizer->ID );

		$this->assertSame( 200, $this->put_profile( array( 'phone' => '+7 900 111-22-33' ) )->get_status() );

		$this->assertSame( '+7 900 111-22-33', get_user_meta( $organizer->ID, 'idpg_phone', true ) );
		$this->assertSame( 'Старое название', get_user_meta( $organizer->ID, 'idpg_org_name', true ) );
		$this->assertSame( 'Старый контакт', get_user_meta( $organizer->ID, 'idpg_contact_name', true ) );
	}

	/**
	 * Пустое тело — ошибка, а не молчаливый 200.
	 */
	public function test_empty_update_is_rejected() {
		$organizer = $this->make_organizer( 'profile_emptybody' );

		wp_set_current_user( $organizer->ID );

		$response = $this->put_profile( array() );

		$this->assertSame( 400, $response->get_status() );
	}

	// =====================================================================
	// Санитайз
	// =====================================================================

	/**
	 * HTML-разметка вырезается из текстовых полей.
	 */
	public function test_html_is_stripped() {
		$organizer = $this->make_organizer( 'profile_html' );

		wp_set_current_user( $organizer->ID );

		$response = $this->put_profile( array( 'org_name' => '<script>alert(1)</script>Рога' ) );
		$data     = $response->get_data();

		$this->assertStringNotContainsString( '<script>', $data['org_name'] );
		$this->assertStringNotContainsString( '</script>', $data['org_name'] );
		$this->assertSame( $data['org_name'], get_user_meta( $organizer->ID, 'idpg_org_name', true ) );
	}

	/**
	 * Слишком длинное значение обрезается до PROFILE_FIELD_MAXLEN.
	 */
	public function test_long_value_is_truncated() {
		$organizer = $this->make_organizer( 'profile_long' );
		$long      = str_repeat( 'я', 500 );

		wp_set_current_user( $organizer->ID );

		$data = $this->put_profile( array( 'org_name' => $long ) )->get_data();

		$this->assertSame( IDPG_Organizer_URL::PROFILE_FIELD_MAXLEN, mb_strlen( $data['org_name'] ) );
	}

	/**
	 * Поле, которого нет во входе, не появляется в результате санитайза.
	 */
	public function test_absent_fields_are_not_in_clean() {
		$clean = IDPG_Organizer_URL::sanitize_profile_input( array( 'phone' => '+7 900' ) );

		$this->assertIsArray( $clean );
		$this->assertArrayHasKey( 'phone', $clean );
		$this->assertArrayNotHasKey( 'org_name', $clean );
		$this->assertArrayNotHasKey( 'display_name', $clean );
	}

	/**
	 * Не-массив на входе даёт ошибку, а не фаталь.
	 */
	public function test_non_array_input_is_rejected() {
		$clean = IDPG_Organizer_URL::sanitize_profile_input( 'строка' );

		$this->assertWPError( $clean );
		$this->assertSame( 'idpg_profile_invalid_input', $clean->get_error_code() );
	}

	/**
	 * Случайные поля во входе игнорируются (whitelist, не blocklist).
	 */
	public function test_unknown_fields_are_ignored() {
		$clean = IDPG_Organizer_URL::sanitize_profile_input(
			array(
				'org_name'          => 'Рога',
				'role'              => 'administrator',
				'wp_capabilities'   => 'a:1:{s:13:"administrator";b:1;}',
				'idpg_org_pending2' => '1',
			)
		);

		$this->assertIsArray( $clean );
		$this->assertSame( array( 'org_name' ), array_keys( $clean ) );
	}

	// =====================================================================
	// Аватар
	// =====================================================================

	/**
	 * Валидный ID вложения сохраняется как аватар.
	 */
	public function test_avatar_accepts_attachment() {
		$organizer  = $this->make_organizer( 'profile_avatar' );
		$attachment = $this->factory()->attachment->create_object(
			array(
				'file'           => 'avatar.png',
				'post_mime_type' => 'image/png',
			)
		);

		wp_set_current_user( $organizer->ID );

		$response = $this->put_profile( array( 'avatar_id' => $attachment ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( (int) $attachment, (int) get_user_meta( $organizer->ID, 'idpg_avatar_attachment_id', true ) );
	}

	/**
	 * ID обычной записи (не вложения) аватаром быть не может.
	 */
	public function test_avatar_rejects_non_attachment() {
		$organizer = $this->make_organizer( 'profile_badavatar' );
		$post_id   = $this->factory()->post->create();

		wp_set_current_user( $organizer->ID );

		$response = $this->put_profile( array( 'avatar_id' => $post_id ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'idpg_profile_invalid_avatar', $response->get_data()['code'] );
		$this->assertSame( '', get_user_meta( $organizer->ID, 'idpg_avatar_attachment_id', true ) );
	}

	/**
	 * Несуществующий ID отбрасывается.
	 */
	public function test_avatar_rejects_missing_id() {
		$organizer = $this->make_organizer( 'profile_noavatar' );

		wp_set_current_user( $organizer->ID );

		$this->assertSame( 400, $this->put_profile( array( 'avatar_id' => 999999 ) )->get_status() );
	}

	/**
	 * avatar_id = 0 сбрасывает аватар (это разрешённое значение).
	 */
	public function test_avatar_zero_clears_avatar() {
		$organizer  = $this->make_organizer( 'profile_clearavatar' );
		$attachment = $this->factory()->attachment->create_object( 'avatar.png' );

		update_user_meta( $organizer->ID, 'idpg_avatar_attachment_id', $attachment );

		wp_set_current_user( $organizer->ID );

		$this->assertSame( 200, $this->put_profile( array( 'avatar_id' => 0 ) )->get_status() );
		$this->assertSame( 0, (int) get_user_meta( $organizer->ID, 'idpg_avatar_attachment_id', true ) );
	}

	// =====================================================================
	// Скоуп
	// =====================================================================

	/**
	 * Чужой user_id во входе игнорируется: пишем в свой профиль.
	 *
	 * Это главная защита от подмены — маршрут не принимает идентификатор
	 * жертвы, а update_profile() работает с текущим пользователем.
	 */
	public function test_cannot_write_to_another_users_profile() {
		$attacker = $this->make_organizer( 'profile_attacker' );
		$victim   = $this->make_organizer( 'profile_victim' );

		update_user_meta( $victim->ID, 'idpg_org_name', 'Жертва' );

		wp_set_current_user( $attacker->ID );

		$response = $this->put_profile(
			array(
				'user_id'  => $victim->ID,
				'org_name' => 'Взломано',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $attacker->ID, $response->get_data()['user_id'] );

		$this->assertSame( 'Жертва', get_user_meta( $victim->ID, 'idpg_org_name', true ) );
		$this->assertSame( 'Взломано', get_user_meta( $attacker->ID, 'idpg_org_name', true ) );
	}

	/**
	 * Чужой профиль не читается: GET отдаёт данные текущего пользователя.
	 */
	public function test_cannot_read_another_users_profile() {
		$attacker = $this->make_organizer( 'profile_read_att' );
		$victim   = $this->make_organizer( 'profile_read_vic' );

		update_user_meta( $victim->ID, 'idpg_org_name', 'Секрет жертвы' );

		wp_set_current_user( $attacker->ID );

		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_param( 'user_id', $victim->ID );

		$data = rest_do_request( $request )->get_data();

		$this->assertSame( $attacker->ID, $data['user_id'] );
		$this->assertNotSame( 'Секрет жертвы', $data['org_name'] );
	}

	// =====================================================================
	// Слой логики (используется и no-JS формой)
	// =====================================================================

	/**
	 * update_profile() для несуществующего пользователя — ошибка 404.
	 */
	public function test_update_profile_unknown_user() {
		$result = IDPG_Organizer_URL::update_profile( 999999, array( 'org_name' => 'X' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'idpg_profile_user_not_found', $result->get_error_code() );
	}

	/**
	 * update_profile() с пустым набором полей — ошибка 400.
	 */
	public function test_update_profile_with_empty_clean() {
		$organizer = $this->make_organizer( 'profile_noclean' );

		$result = IDPG_Organizer_URL::update_profile( $organizer->ID, array() );

		$this->assertWPError( $result );
		$this->assertSame( 'idpg_profile_nothing_to_update', $result->get_error_code() );
	}

	/**
	 * get_profile_data() для несуществующего пользователя не падает.
	 */
	public function test_get_profile_data_unknown_user() {
		$data = IDPG_Organizer_URL::get_profile_data( 999999 );

		$this->assertSame( 0, $data['user_id'] );
		$this->assertSame( '', $data['display_name'] );
	}

	/**
	 * После сохранения срабатывает хук idpg_organizer_profile_updated.
	 */
	public function test_update_fires_action() {
		$organizer = $this->make_organizer( 'profile_action' );
		$captured  = array();

		$callback = static function ( $user_id, $clean ) use ( &$captured ) {
			$captured = array(
				'user_id' => $user_id,
				'clean'   => $clean,
			);
		};

		add_action( 'idpg_organizer_profile_updated', $callback, 10, 2 );

		wp_set_current_user( $organizer->ID );
		$this->put_profile( array( 'org_name' => 'Из хука' ) );

		remove_action( 'idpg_organizer_profile_updated', $callback, 10 );

		$this->assertSame( $organizer->ID, $captured['user_id'] );
		$this->assertSame( 'Из хука', $captured['clean']['org_name'] );
	}

	/**
	 * Поля, добавленные фильтром idpg_organizer_profile_sanitized, доходят до записи.
	 */
	public function test_sanitized_filter_runs() {
		$organizer = $this->make_organizer( 'profile_filter' );

		$callback = static function ( $clean ) {
			$clean['extra_field'] = 'добавлено фильтром';

			return $clean;
		};

		add_filter( 'idpg_organizer_profile_sanitized', $callback );
		wp_set_current_user( $organizer->ID );
		$this->put_profile( array( 'org_name' => 'Рога' ) );
		remove_filter( 'idpg_organizer_profile_sanitized', $callback );

		// update_profile пишет только известные поля, поэтому лишний ключ
		// из фильтра не должен превращаться в мета-ключ.
		$this->assertSame( 'Рога', get_user_meta( $organizer->ID, 'idpg_org_name', true ) );
		$this->assertSame( '', get_user_meta( $organizer->ID, 'extra_field', true ) );
	}
}
