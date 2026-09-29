<?php
/**
 * Юнит-тесты DEV-81: default_role не должна быть subscriber.
 *
 * Модель сайта: роль `subscriber` не должна существовать как роль
 * пользователя — все пользователи сайта организаторы. `default_role`
 * в `wp_options` отвечает за роль нового пользователя, созданного без
 * явного указания роли, и потому тоже не должна быть `subscriber`.
 *
 * Отдельно зафиксировано поведение ядра WordPress: `sanitize_option()`
 * для `default_role` подставляет `subscriber`, если переданного значения
 * нет среди существующих ролей, а роль `subscriber` есть
 * (wp-includes/formatting.php). Поэтому `update_option( 'default_role', '' )`
 * **не работает** — нужно прямое обновление строки в БД. Это и есть
 * то, что проверяет данный набор.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Default_Role
 */
class Test_Default_Role extends WP_UnitTestCase {

	/**
	 * Маршрут регистрации.
	 */
	const REGISTER = '/idpg/v1/auth/register';

	/**
	 * Исходное значение опции для восстановления.
	 *
	 * @var mixed
	 */
	private $original_default_role;

	/**
	 * Подготовка окружения.
	 */
	public function set_up() {
		parent::set_up();

		$this->original_default_role = get_option( 'default_role' );

		// Конструктор вешает register_routes на rest_api_init.
		IDPG_REST_Auth::instance();

		IDPG_Roles::instance()->create_roles();

		// Лимит — 3 регистрации в час с одного IP.
		delete_transient( 'idpg_rl_' . md5( '127.0.0.1' ) );
	}

	/**
	 * Очистка окружения: возвращаем исходное значение опции.
	 */
	public function tear_down() {
		update_option( 'default_role', $this->original_default_role );

		parent::tear_down();
	}

	/**
	 * Записать default_role в обход sanitize_option() ядра.
	 *
	 * update_option( 'default_role', '' ) не срабатывает: ядро подставляет
	 * 'subscriber', пока такая роль существует. Здесь пишем в таблицу
	 * напрямую — ровно так же придётся поступить на боевом сайте.
	 *
	 * @param string $value Значение опции.
	 */
	private function force_default_role( $value ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->options,
			array( 'option_value' => $value ),
			array( 'option_name' => 'default_role' )
		);

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'default_role', 'options' );
	}

	/**
	 * Запрос регистрации организатора.
	 *
	 * @param array $params Поля поверх email/password.
	 * @return WP_REST_Response
	 */
	protected function register_organizer( $params ) {
		$request = new WP_REST_Request( 'POST', self::REGISTER );
		$request->set_body_params(
			array_merge(
				array(
					'type'         => 'organizer',
					'name'         => 'Иван Иванов',
					'org_name'     => 'Организация «Тест»',
					'contact_name' => 'Иван Иванов',
					'email'        => 'dev81-organizer@example.com',
					'phone'        => '+7 999 000-00-00',
					'password'     => 'parol12345',
					'terms'        => true,
					// 152-ФЗ: согласие на обработку ПДн обязательно (G8).
					'idpg_pdn_consent' => true,
				),
				$params
			)
		);

		return rest_do_request( $request );
	}

	// =====================================================================
	// Поведение ядра
	// =====================================================================

	/**
	 * update_option( 'default_role', '' ) ядром отвергается.
	 *
	 * Это не баг нашего кода, а защита WordPress: пока роль `subscriber`
	 * существует, пустая default_role превращается в 'subscriber'.
	 * Тест фиксирует это поведение, чтобы следующий разработчик не искал
	 * причину, почему опция «не сохраняется».
	 */
	public function test_core_rejects_empty_default_role_via_update_option() {
		$this->force_default_role( 'subscriber' );

		$this->assertSame( 'subscriber', get_option( 'default_role' ) );

		// update_option молча не делает ничего и возвращает false.
		$this->assertFalse( update_option( 'default_role', '' ) );
		$this->assertSame( 'subscriber', get_option( 'default_role' ) );
	}

	/**
	 * Прямое обновление строки опции работает.
	 */
	public function test_direct_db_write_sets_empty_default_role() {
		$this->force_default_role( 'subscriber' );

		$this->force_default_role( '' );

		$this->assertSame( '', get_option( 'default_role' ) );
	}

	// =====================================================================
	// Регистрация организатора при пустой default_role
	// =====================================================================

	/**
	 * Регистрация организатора работает при default_role = ''.
	 */
	public function test_organizer_registration_works_with_empty_default_role() {
		$this->force_default_role( '' );

		$this->assertSame( '', get_option( 'default_role' ) );

		$response = $this->register_organizer(
			array(
				'org_name'     => 'ООО «Рога и копыта»',
				'contact_name' => 'Иван Иванов',
				'phone'        => '+7 999 000-00-00',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertTrue( $response->get_data()['pending_email'] );

		$user = get_user_by( 'email', 'dev81-organizer@example.com' );

		$this->assertNotFalse( $user );

		// Роль event_organizer ещё НЕ выдана — только после подтверждения.
		$this->assertNotContains( IDPG_Roles::ROLE_SLUG, (array) $user->roles );

		// Регистрация обязана явно поставить subscriber: default_role пустая,
		// и полагаться на неё нельзя.
		$this->assertContains( 'subscriber', (array) $user->roles );

		// Данные организации сохранились сразу, до подтверждения.
		$this->assertSame( 'ООО «Рога и копыта»', get_user_meta( $user->ID, 'idpg_org_name', true ) );
		$this->assertSame( 'Иван Иванов', get_user_meta( $user->ID, 'idpg_contact_name', true ) );
		$this->assertSame( '+7 999 000-00-00', get_user_meta( $user->ID, 'idpg_phone', true ) );

		// Флаг ожидания подтверждения выставлен.
		$this->assertNotEmpty( get_user_meta( $user->ID, 'idpg_org_pending', true ) );
	}

	/**
	 * Регистрация организатора даёт тот же результат при default_role
	 * = 'subscriber': явный set_role() делает поведение независимым
	 * от значения опции.
	 */
	public function test_organizer_registration_is_independent_of_default_role() {
		$this->force_default_role( 'subscriber' );

		$response = $this->register_organizer(
			array(
				'email'    => 'dev81-org-sub@example.com',
				'org_name' => 'Организация',
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$user = get_user_by( 'email', 'dev81-org-sub@example.com' );

		$this->assertNotFalse( $user );
		$this->assertContains( 'subscriber', (array) $user->roles );
		$this->assertNotContains( IDPG_Roles::ROLE_SLUG, (array) $user->roles );
	}

	/**
	 * Роль subscriber досоздаётся плагином, даже если её нет в wp_roles.
	 *
	 * Это критично для DEV-81: пустая default_role допустима только пока
	 * IDPG_Roles умеет восстановить subscriber — иначе set_role( 'subscriber' )
	 * в регистрации организатора молча ничего не сделал бы.
	 *
	 * Оба метода (create_roles() и sync_role_caps()) выходят рано по флагу
	 * `idpg_roles_version`, поэтому сначала сбрасываем его — как это делает
	 * remove_roles() при откате ролей.
	 */
	public function test_subscriber_role_is_recreated_by_plugin() {
		remove_role( 'subscriber' );
		delete_option( 'idpg_roles_version' );

		$this->assertNull( get_role( 'subscriber' ) );

		IDPG_Roles::instance()->sync_role_caps();

		$subscriber = get_role( 'subscriber' );

		$this->assertNotNull( $subscriber );
		$this->assertTrue( $subscriber->has_cap( 'read' ) );
		$this->assertTrue( $subscriber->has_cap( 'upload_files' ) );
	}

	// =====================================================================
	// Создание пользователя без явной роли (как из админки)
	// =====================================================================

	/**
	 * Пользователь, созданный без роли, не получает subscriber.
	 *
	 * Сценарий «Добавить пользователя» в админке с пустым выбором роли.
	 */
	public function test_user_created_without_role_gets_no_role() {
		$this->force_default_role( '' );

		$user_id = wp_insert_user(
			array(
				'user_login' => 'dev81_norole',
				'user_pass'  => 'dev81-password',
				'user_email' => 'dev81-norole@example.com',
			)
		);

		$this->assertIsInt( $user_id );

		// Роли нет — ни subscriber, ни event_organizer.
		$this->assertSame( array(), array_values( ( new WP_User( $user_id ) )->roles ) );
		$this->assertFalse( IDPG_Organizer_URL::user_is_organizer( $user_id ) );
	}

	/**
	 * Тот же сценарий при default_role = 'subscriber' — для сравнения.
	 */
	public function test_user_created_without_role_gets_subscriber_when_default_set() {
		$this->force_default_role( 'subscriber' );

		$user_id = wp_insert_user(
			array(
				'user_login' => 'dev81_withrole',
				'user_pass'  => 'dev81-password',
				'user_email' => 'dev81-withrole@example.com',
			)
		);

		$this->assertIsInt( $user_id );
		$this->assertContains( 'subscriber', array_values( ( new WP_User( $user_id ) )->roles ) );
	}

	/**
	 * Безролевой пользователь не попадает в кабинет организатора.
	 *
	 * Следствие DEV-81: guard IDPG_Auth::restrict_admin_access() проверяет
	 * только роль `subscriber`, поэтому безролевого пользователя он не
	 * перенаправляет. В кабинет такие не пускает уже сам роутер (403).
	 */
	public function test_roleless_user_gets_403_on_cabinet() {
		$this->force_default_role( '' );

		$user_id = wp_insert_user(
			array(
				'user_login' => 'dev81_roleless',
				'user_pass'  => 'dev81-password',
				'user_email' => 'dev81-roleless@example.com',
			)
		);

		$this->assertSame(
			IDPG_Organizer_URL::ACCESS_DENIED,
			IDPG_Organizer_URL::get_access_decision( $user_id )
		);
	}
}
