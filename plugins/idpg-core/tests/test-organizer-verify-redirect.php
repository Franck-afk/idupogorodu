<?php
/**
 * Юнит-тесты DEV-67: редирект в ЛК организатора после подтверждения email.
 *
 * Сценарий: организатор регистрируется, получает временную ссылку, кликает
 * по ней — роль event_organizer выдаётся, и редирект после подтверждения
 * ведёт в /organizer/, а не на главную.
 *
 * Шаблон `page-login.php` считает адрес выражением
 * `IDPG_Organizer_URL::after_login_url( $confirm_result->ID )` — тесты
 * фиксируют именно это выражение, чтобы изменение шаблона не тихо
 * вернуло редирект на главную.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Organizer_Verify_Redirect
 */
class Test_Organizer_Verify_Redirect extends WP_UnitTestCase {

	/**
	 * Регистрирует роли плагина: event_organizer иначе не существует.
	 */
	public function set_up() {
		parent::set_up();

		IDPG_Roles::instance()->create_roles();
	}

	/**
	 * Создаёт организатора, ожидающего подтверждения, и возвращает ключ.
	 *
	 * Повторяет состояние сразу после успешной регистрации: роль не выдана,
	 * в user_meta лежит хэш ключа и флаг ожидания.
	 *
	 * @param string $login Логин (латиница).
	 * @return array [ int $user_id, string $key ].
	 */
	private function make_pending_organizer( $login ) {
		$user_id = $this->factory()->user->create(
			array(
				'user_login' => $login,
				'role'       => 'subscriber',
			)
		);

		// «Чистый» пользователь без ролей — так он выглядит после регистрации.
		$user = new WP_User( $user_id );

		foreach ( (array) $user->roles as $role ) {
			$user->remove_role( $role );
		}

		$key = IDPG_Organizer_Verify::instance()->prepare( $user_id );

		return array( (int) $user_id, $key );
	}

	/**
	 * Адрес, в который ведёт шаблон после подтверждения.
	 *
	 * Ровно то выражение, которое стоит в `page-login.php`.
	 *
	 * @param int $user_id ID организатора.
	 * @return string
	 */
	private function redirect_after_confirm( $user_id ) {
		return IDPG_Organizer_URL::after_login_url( $user_id );
	}

	/* ---------------------------------------------------------------------
	 * Приёмка DEV-67: после подтверждения организатор попадает в /organizer/.
	 * ------------------------------------------------------------------ */

	/**
	 * Основной сценарий: подтверждение ведёт в кабинет, а не на главную.
	 */
	public function test_confirmed_organizer_lands_in_cabinet() {
		list( $user_id, $key ) = $this->make_pending_organizer( 'dev67-happy' );

		$result = IDPG_Organizer_Verify::instance()->confirm( $user_id, $key );

		$this->assertNotWPError( $result );

		$url = $this->redirect_after_confirm( $result->ID );

		$this->assertSame( home_url( '/organizer/' ), $url );
		$this->assertNotSame( home_url( '/' ), $url );
	}

	/**
	 * Подтверждение действительно выдаёт роль организатора.
	 */
	public function test_confirm_grants_organizer_role() {
		list( $user_id, $key ) = $this->make_pending_organizer( 'dev67-role' );

		$this->assertFalse( $this->has_organizer_role( $user_id ), 'до подтверждения роли быть не должно' );

		IDPG_Organizer_Verify::instance()->confirm( $user_id, $key );

		$this->assertTrue( $this->has_organizer_role( $user_id ) );
	}

	/**
	 * До подтверждения редиректа в кабинет нет — адрес решения отдаёт пустую
	 * строку, то есть в кабинет попасть нельзя.
	 *
	 * Это и есть причина, по которой порядок «сначала роль, потом адрес»
	 * в шаблоне принципиален.
	 */
	public function test_before_confirm_there_is_no_cabinet_url() {
		list( $user_id ) = $this->make_pending_organizer( 'dev67-before' );

		$this->assertSame( '', $this->redirect_after_confirm( $user_id ) );
	}

	/**
	 * Данные подтверждения очищаются, иначе ссылка осталась бы активной.
	 */
	public function test_confirm_clears_pending_data() {
		list( $user_id, $key ) = $this->make_pending_organizer( 'dev67-clean' );

		$this->assertTrue( IDPG_Organizer_Verify::instance()->is_pending( $user_id ) );

		IDPG_Organizer_Verify::instance()->confirm( $user_id, $key );

		$this->assertFalse( IDPG_Organizer_Verify::instance()->is_pending( $user_id ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, IDPG_Organizer_Verify::META_KEY_HASH, true ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, IDPG_Organizer_Verify::META_KEY_EXPIRES, true ) );
	}

	/**
	 * Повторный клик по ссылке не считается ошибкой и не ломает редирект.
	 */
	public function test_repeated_confirm_is_idempotent() {
		list( $user_id, $key ) = $this->make_pending_organizer( 'dev67-twice' );

		$first = IDPG_Organizer_Verify::instance()->confirm( $user_id, $key );
		$again = IDPG_Organizer_Verify::instance()->confirm( $user_id, $key );

		$this->assertNotWPError( $first );
		$this->assertNotWPError( $again );
		$this->assertSame( home_url( '/organizer/' ), $this->redirect_after_confirm( $user_id ) );
	}

	/**
	 * Ссылка из письма ведёт на страницу входа с action=confirm.
	 */
	public function test_confirmation_url_shape() {
		list( $user_id, $key ) = $this->make_pending_organizer( 'dev67-url' );

		$url = IDPG_Organizer_Verify::instance()->get_confirmation_url( $user_id, $key );

		$this->assertStringContainsString( 'action=confirm', $url );
		$this->assertStringContainsString( 'idpg_user=' . $user_id, $url );
		$this->assertStringContainsString( 'idpg_key=' . rawurlencode( $key ), $url );
	}

	/* ---------------------------------------------------------------------
	 * Неуспешные ветки: редиректа в кабинет быть не должно.
	 * ------------------------------------------------------------------ */

	/**
	 * Неверный ключ не подтверждает и не открывает кабинет.
	 */
	public function test_invalid_key_keeps_organizer_locked() {
		list( $user_id ) = $this->make_pending_organizer( 'dev67-badkey' );

		$result = IDPG_Organizer_Verify::instance()->confirm( $user_id, 'wrong-key' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_key', $result->get_error_code() );
		$this->assertFalse( $this->has_organizer_role( $user_id ) );
		$this->assertSame( '', $this->redirect_after_confirm( $user_id ) );
	}

	/**
	 * Истёкшая ссылка не подтверждает и не открывает кабинет.
	 */
	public function test_expired_key_keeps_organizer_locked() {
		list( $user_id, $key ) = $this->make_pending_organizer( 'dev67-expired' );

		update_user_meta(
			$user_id,
			IDPG_Organizer_Verify::META_KEY_EXPIRES,
			time() - HOUR_IN_SECONDS
		);

		$result = IDPG_Organizer_Verify::instance()->confirm( $user_id, $key );

		$this->assertWPError( $result );
		$this->assertSame( 'expired_key', $result->get_error_code() );
		$this->assertFalse( $this->has_organizer_role( $user_id ) );
		$this->assertSame( '', $this->redirect_after_confirm( $user_id ) );
	}

	/**
	 * Несуществующий пользователь не приводит ни к какой активности.
	 */
	public function test_unknown_user_is_rejected() {
		$result = IDPG_Organizer_Verify::instance()->confirm( 999999, 'any-key' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_user', $result->get_error_code() );
	}

	/**
	 * Проверка роли организатора со свежим чтением из БД.
	 *
	 * @param int $user_id ID пользователя.
	 * @return bool
	 */
	private function has_organizer_role( $user_id ) {
		$user = get_user_by( 'id', $user_id );

		return $user && in_array( IDPG_Roles::ROLE_SLUG, (array) $user->roles, true );
	}
}
