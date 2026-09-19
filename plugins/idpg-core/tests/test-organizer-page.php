<?php
/**
 * Юнит-тесты: доступ к кабинету организатора (/organizer/).
 *
 * Проверяется:
 *  - решение по доступу для гостя, организатора, pending-организатора
 *    и обычного подписчика (роль subscriber);
 *  - администратор и редактор тоже допускаются («организатор и выше»);
 *  - флаг анкеты распознаётся под обоими ключами
 *    (`_idpg_org_pending` и `idpg_org_pending`);
 *  - нормализация слага раздела и адреса разделов;
 *  - регистрация query-переменной и rewrite-правил;
 *  - адрес после входа: организатор — в кабинет, администратор и редактор — в wp-admin
 *    (`IDPG_Organizer_URL::after_login_url()` и `IDPG_Auth::redirect_organizer_to_cabinet()`).
 *
 * Логика доступа — чистая функция `IDPG_Organizer_URL::get_access_decision()`
 * без редиректов и вывода, поэтому проверяется без HTTP-запросов.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Organizer_Page
 */
class Test_Organizer_Page extends WP_UnitTestCase {

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
				'role'       => $role,
			)
		);

		return new WP_User( $user_id );
	}

	/**
	 * Создать организатора с чистой ролью event_organizer.
	 *
	 * Роль event_organizer регистрируется плагином, поэтому перед выдачей
	 * вызываем create_roles(). Роли пользователя предварительно очищаем,
	 * чтобы получился именно организатор, без лишних ролей.
	 *
	 * @param string $login Логин (латиница).
	 * @return WP_User
	 */
	protected function make_organizer( $login ) {
		IDPG_Roles::instance()->create_roles();

		$user_id = $this->factory()->user->create(
			array(
				'user_login' => $login,
				'role'       => 'subscriber',
			)
		);

		$user = new WP_User( $user_id );

		foreach ( (array) $user->roles as $role ) {
			$user->remove_role( $role );
		}
		$user->add_role( IDPG_Roles::ROLE_SLUG );

		return $user;
	}

	/**
	 * Создать пользователя без единой роли (как сразу после регистрации).
	 *
	 * @param string $login Логин (латиница).
	 * @return int ID пользователя.
	 */
	protected function make_roleless_user( $login ) {
		$user_id = $this->factory()->user->create( array( 'user_login' => $login ) );
		$user    = new WP_User( $user_id );

		foreach ( (array) $user->roles as $role ) {
			$user->remove_role( $role );
		}

		return (int) $user_id;
	}

	/**
	 * Гость (неавторизованный) — решение «guest».
	 */
	public function test_guest_is_redirected_to_login() {
		wp_set_current_user( 0 );

		$this->assertSame( IDPG_Organizer_URL::ACCESS_GUEST, IDPG_Organizer_URL::get_access_decision() );
	}

	/**
	 * Организатор с заполненной анкетой получает доступ к кабинету.
	 *
	 * Организатор без анкеты (нет _idpg_org_completed_at и idpg_org_name)
	 * теперь считается pending (страховка DEV-50), поэтому для allowed
	 * анкета должна быть отмечена как заполненная.
	 */
	public function test_organizer_is_allowed() {
		$organizer = $this->make_organizer( 'org_cabinet' );

		// Признаки заполненной анкеты (см. is_questionnaire_complete()).
		update_user_meta( $organizer->ID, 'idpg_org_name', 'ООО «Рога и копыта»' );
		update_user_meta( $organizer->ID, '_idpg_org_completed_at', time() );

		wp_set_current_user( $organizer->ID );

		$this->assertTrue( IDPG_Organizer_URL::user_is_organizer( $organizer->ID ) );
		$this->assertFalse( IDPG_Organizer_Page::is_pending() );
		$this->assertSame( IDPG_Organizer_URL::ACCESS_ALLOWED, IDPG_Organizer_URL::get_access_decision() );
	}

	/**
	 * Организатор с незаполненной анкетой уходит на /organizer/complete/.
	 */
	public function test_pending_organizer_is_sent_to_anketa() {
		$organizer = $this->make_organizer( 'org_pending' );

		update_user_meta( $organizer->ID, '_idpg_org_pending', 1 );

		wp_set_current_user( $organizer->ID );

		$this->assertTrue( IDPG_Organizer_URL::is_pending_organizer( $organizer->ID ) );
		$this->assertSame( IDPG_Organizer_URL::ACCESS_PENDING, IDPG_Organizer_URL::get_access_decision() );
	}

	/**
	 * Флаг анкеты под «историческим» ключом без подчёркивания
	 * (idpg_org_pending) тоже распознаётся — именно его пишет
	 * IDPG_Organizer_Verify::prepare().
	 */
	public function test_pending_flag_without_underscore_is_recognized() {
		$organizer = $this->make_organizer( 'org_pending_alt' );

		update_user_meta( $organizer->ID, IDPG_Organizer_Verify::META_PENDING, 1 );

		$this->assertTrue( IDPG_Organizer_URL::is_pending_organizer( $organizer->ID ) );
		$this->assertSame( IDPG_Organizer_URL::ACCESS_PENDING, IDPG_Organizer_URL::get_access_decision( $organizer->ID ) );
	}

	/**
	 * Сразу после регистрации роли у пользователя ещё нет, но флаг анкеты
	 * уже стоит: он должен попасть на /organizer/complete/, а не получить 403.
	 */
	public function test_roleless_pending_user_goes_to_anketa() {
		$user_id = $this->make_roleless_user( 'org_fresh' );

		update_user_meta( $user_id, '_idpg_org_pending', 1 );

		$this->assertSame( array(), (array) get_user_by( 'id', $user_id )->roles );
		$this->assertSame( IDPG_Organizer_URL::ACCESS_PENDING, IDPG_Organizer_URL::get_access_decision( $user_id ) );
	}

	/**
	 * Обычный подписчик доступа не получает.
	 */
	public function test_subscriber_is_denied() {
		$subscriber = $this->make_user( 'sub_cabinet', 'subscriber' );

		wp_set_current_user( $subscriber->ID );

		$this->assertFalse( IDPG_Organizer_URL::user_is_organizer( $subscriber->ID ) );
		$this->assertSame( IDPG_Organizer_URL::ACCESS_DENIED, IDPG_Organizer_URL::get_access_decision() );
	}

	/**
	 * Администратор и редактор тоже допускаются («организатор и выше»).
	 */
	public function test_admin_and_editor_are_allowed() {
		$admin  = $this->make_user( 'admin_cabinet', 'administrator' );
		$editor = $this->make_user( 'editor_cabinet', 'editor' );

		$this->assertSame( IDPG_Organizer_URL::ACCESS_ALLOWED, IDPG_Organizer_URL::get_access_decision( $admin->ID ) );
		$this->assertSame( IDPG_Organizer_URL::ACCESS_ALLOWED, IDPG_Organizer_URL::get_access_decision( $editor->ID ) );
	}

	/**
	 * Гость не считается организатором.
	 */
	public function test_guest_is_not_organizer() {
		$this->assertFalse( IDPG_Organizer_URL::user_is_organizer( 0 ) );
		$this->assertFalse( IDPG_Organizer_URL::is_pending_organizer( 0 ) );
	}

	/**
	 * Неизвестный, пустой или «мусорный» слаг раздела заменяется
	 * разделом по умолчанию.
	 */
	public function test_section_normalization() {
		$this->assertSame( 'dashboard', IDPG_Organizer_URL::normalize_section( '' ) );
		$this->assertSame( 'dashboard', IDPG_Organizer_URL::normalize_section( 'nope' ) );
		$this->assertSame( 'dashboard', IDPG_Organizer_URL::normalize_section( '<script>alert(1)</script>' ) );
		$this->assertSame( 'dashboard', IDPG_Organizer_URL::normalize_section( array( 'events' ) ) );
		$this->assertSame( 'events', IDPG_Organizer_URL::normalize_section( 'events' ) );
		$this->assertSame( 'profile', IDPG_Organizer_URL::normalize_section( 'PROFILE' ) );
	}

	/**
	 * Раздел по умолчанию живёт на коротком адресе, остальные — с сегментом.
	 */
	public function test_section_urls() {
		$this->assertSame( home_url( '/organizer/' ), IDPG_Organizer_URL::section_url( 'dashboard' ) );
		$this->assertSame( home_url( '/organizer/events/' ), IDPG_Organizer_URL::section_url( 'events' ) );
		$this->assertSame( home_url( '/organizer/profile/' ), IDPG_Organizer_URL::section_url( 'profile' ) );

		// Неизвестный раздел отдаёт адрес раздела по умолчанию.
		$this->assertSame( home_url( '/organizer/' ), IDPG_Organizer_URL::section_url( 'unknown' ) );
	}

	/**
	 * Базовый набор разделов — три штуки.
	 */
	public function test_default_sections() {
		$this->assertSame( array( 'dashboard', 'events', 'profile' ), IDPG_Organizer_URL::get_sections() );
	}

	/**
	 * Query-переменные кабинета зарегистрированы.
	 */
	public function test_query_vars_registered() {
		$vars = IDPG_Organizer_URL::query_vars( array( 'p' ) );

		$this->assertContains( 'idpg_organizer_section', $vars );
		$this->assertContains( 'idpg_organizer_page', $vars );
		$this->assertContains( 'p', $vars );
	}

	/**
	 * Rewrite-правила кабинета регистрируются.
	 *
	 * Правило опознаётся по query-переменной раздела: у /organizer/complete/
	 * (IDPG_Organizer_Page) она не используется.
	 */
	public function test_rewrite_rules_registered() {
		global $wp_rewrite;

		// Маркер сброса выставлен, чтобы тест не дёргал flush_rewrite_rules().
		update_option( IDPG_Organizer_URL::FLUSH_OPT, IDPG_Organizer_URL::REWRITE_VERSION );

		IDPG_Organizer_URL::rewrites();

		$section_rules = array();

		foreach ( (array) $wp_rewrite->extra_rules_top as $regex => $query ) {
			if ( false !== strpos( (string) $query, IDPG_Organizer_URL::SECTION_QVAR ) ) {
				$section_rules[ $regex ] = $query;
			}
		}

		// Два правила: /organizer/{section}/ и база /organizer/.
		$this->assertCount( 2, $section_rules, 'Ожидались два правила кабинета.' );

		$regexes = array_keys( $section_rules );

		$this->assertContains( '^organizer/(dashboard|events|profile)/?$', $regexes );
		$this->assertContains( '^organizer/?$', $regexes );

		// Раздел по умолчанию подставляется в базовом правиле.
		$this->assertStringContainsString(
			IDPG_Organizer_URL::SECTION_QVAR . '=' . IDPG_Organizer_URL::DEFAULT_SECTION,
			$section_rules['^organizer/?$']
		);
	}

	/**
	 * После входа в кабинет ведём только «чистого» организатора.
	 *
	 * Администратор и редактор тоже имеют доступ к кабинету, но их основное
	 * рабочее место — wp-admin, поэтому их авторизацию не перехватываем.
	 */
	public function test_after_login_url_only_for_plain_organizer() {
		$cabinet = home_url( '/organizer/' );

		$this->assertSame( $cabinet, IDPG_Organizer_URL::after_login_url( $this->make_organizer( 'after-login-org' ) ) );
		$this->assertSame( '', IDPG_Organizer_URL::after_login_url( $this->make_user( 'after-login-admin', 'administrator' ) ) );
		$this->assertSame( '', IDPG_Organizer_URL::after_login_url( $this->make_user( 'after-login-editor', 'editor' ) ) );
	}

	/**
	 * Организатор с дополнительной ролью administrator тоже остаётся в wp-admin.
	 */
	public function test_after_login_url_skips_organizer_with_admin_role() {
		$organizer        = $this->make_organizer( 'after-login-mixed' );
		$organizer->roles = array( 'event_organizer', 'administrator' );

		$this->assertSame( '', IDPG_Organizer_URL::after_login_url( $organizer ) );
	}

	/**
	 * Гость, несуществующий ID и пользователь без ролей в кабинет не отправляются.
	 */
	public function test_after_login_url_for_guest_and_roleless() {
		$this->assertSame( '', IDPG_Organizer_URL::after_login_url( 0 ) );
		$this->assertSame( '', IDPG_Organizer_URL::after_login_url( 999999 ) );

		$roleless        = $this->make_organizer( 'after-login-roleless' );
		$roleless->roles = array();

		$this->assertSame( '', IDPG_Organizer_URL::after_login_url( $roleless ) );
	}

	/**
	 * Адрес кабинета после входа расширяется фильтром.
	 */
	public function test_after_login_url_is_filterable() {
		$organizer = $this->make_organizer( 'after-login-filter' );

		$callback = static function () {
			return 'https://example.test/custom-cabinet/';
		};

		add_filter( 'idpg_organizer_after_login_url', $callback );
		$this->assertSame( 'https://example.test/custom-cabinet/', IDPG_Organizer_URL::after_login_url( $organizer ) );
		remove_filter( 'idpg_organizer_after_login_url', $callback );

		$this->assertSame( home_url( '/organizer/' ), IDPG_Organizer_URL::after_login_url( $organizer ) );
	}

	/**
	 * Фильтр login_redirect для основного редиректа зарегистрирован.
	 *
	 * Кабинетный перехват redirect_organizer_to_cabinet() появится вместе
	 * с обвязкой DEV-34 (каркас ЛК, аудит G1) — сейчас метода нет.
	 */
	public function test_login_redirect_hook_registered() {
		$auth = IDPG_Auth::instance();

		$this->assertSame( 10, has_filter( 'login_redirect', array( $auth, 'redirect_after_login' ) ) );
		$this->assertFalse( method_exists( $auth, 'redirect_organizer_to_cabinet' ) );
	}

	/**
	 * IDPG_Auth::redirect_after_login() учитывает роли.
	 */
	public function test_auth_redirect_after_login_roles() {
		$auth      = IDPG_Auth::instance();
		$admin_url = admin_url();

		// Организатор (как и редактор/администратор) — в wp-admin:
		// редирект в кабинет /organizer/ появится с обвязкой DEV-34.
		$this->assertSame(
			$admin_url,
			$auth->redirect_after_login( $admin_url, '', $this->make_organizer( 'auth-redirect-org' ) )
		);

		$this->assertSame(
			home_url( '/login/' ),
			$auth->redirect_after_login( $admin_url, '', new WP_Error( 'idpg_test' ) )
		);

		$this->assertSame(
			$admin_url,
			$auth->redirect_after_login( $admin_url, '', $this->make_user( 'auth-redirect-admin', 'administrator' ) )
		);

		// Подписчик — на главную.
		$this->assertSame(
			home_url( '/' ),
			$auth->redirect_after_login( $admin_url, '', $this->make_user( 'auth-redirect-sub', 'subscriber' ) )
		);
	}
}
