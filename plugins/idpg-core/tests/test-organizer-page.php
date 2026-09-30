<?php
/**
 * Юнит-тесты: доступ к кабинету организатора (/organizer/).
 *
 * Проверяется:
 *  - решение по доступу для гостя, организатора и обычного подписчика
 *    (роль subscriber);
 *  - администратор и редактор тоже допускаются («организатор и выше»);
 *  - нормализация слага раздела и адреса разделов;
 *  - регистрация query-переменной и rewrite-правил;
 *  - адрес после входа: организатор — в кабинет, администратор и редактор — в wp-admin
 *    (`IDPG_Organizer_URL::after_login_url()` и `IDPG_Auth::redirect_organizer_to_cabinet()`).
 *
 * Анкеты организатора больше нет (DEV-72), поэтому отдельного состояния
 * «pending» и флагов анкеты в тестах не осталось: доступ определяется
 * только наличием роли. Флаг подтверждения email (`idpg_org_pending`)
 * живёт в `IDPG_Organizer_Verify` и на решение по доступу не влияет.
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
	 * Выставить query-переменную глобального запроса.
	 *
	 * В наборе тестов WP нет хелпера на этот случай, а `set_query_var()` из
	 * ядра только читает текущее значение. Поэтому правим $wp_query
	 * напрямую — ровно то, что делает WP_Rewrite при разборе адреса.
	 *
	 * @param string $key   Имя переменной.
	 * @param string $value Значение.
	 * @return void
	 */
	protected function set_cabinet_query_var( $key, $value ) {
		global $wp_query;

		if ( ! $wp_query instanceof WP_Query ) {
			$wp_query = new WP_Query();
		}

		$wp_query->set( $key, $value );
	}

	/**
	 * Гость (неавторизованный) — решение «guest».
	 */
	public function test_guest_is_redirected_to_login() {
		wp_set_current_user( 0 );

		$this->assertSame( IDPG_Organizer_URL::ACCESS_GUEST, IDPG_Organizer_URL::get_access_decision() );
	}

	/**
	 * Организатор получает доступ к кабинету.
	 *
	 * С DEV-72 отдельной анкеты нет, поэтому никаких дополнительных
	 * признаков заполнения не требуется — достаточно роли event_organizer.
	 */
	public function test_organizer_is_allowed() {
		$organizer = $this->make_organizer( 'org_cabinet' );

		wp_set_current_user( $organizer->ID );

		$this->assertTrue( IDPG_Organizer_URL::user_is_organizer( $organizer->ID ) );
		$this->assertSame( IDPG_Organizer_URL::ACCESS_ALLOWED, IDPG_Organizer_URL::get_access_decision() );
	}

	/**
	 * Организатор без заполненного `idpg_org_name` всё равно допускается:
	 * данные профиля собираются в кабинете (DEV-73), а не в отдельной анкете.
	 */
	public function test_organizer_without_org_name_is_allowed() {
		$organizer = $this->make_organizer( 'org_no_name' );

		$this->assertSame( '', (string) get_user_meta( $organizer->ID, 'idpg_org_name', true ) );
		$this->assertSame( IDPG_Organizer_URL::ACCESS_ALLOWED, IDPG_Organizer_URL::get_access_decision( $organizer->ID ) );
	}

	/**
	 * Флаг подтверждения email (`idpg_org_pending`) не влияет на доступ:
	 * пользователь без роли получает 403, а не «pending».
	 */
	public function test_email_pending_flag_does_not_grant_access() {
		$user_id = $this->make_roleless_user( 'org_email_pending' );

		update_user_meta( $user_id, 'idpg_org_pending', 1 );

		$this->assertSame( IDPG_Organizer_URL::ACCESS_DENIED, IDPG_Organizer_URL::get_access_decision( $user_id ) );
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
	 * Базовый набор разделов.
	 *
	 * `venues` добавлен DEV-50 вместе с самостоятельной формой площадки:
	 * до этого площадку можно было завести только модалкой из формы
	 * события, отдельного адреса у неё не было.
	 */
	public function test_default_sections() {
		$this->assertSame(
			array( 'dashboard', 'events', 'venues', 'profile' ),
			IDPG_Organizer_URL::get_sections()
		);
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
	 * Правила опознаются по query-переменной раздела. Анкеты
	 * `/organizer/complete/` больше нет: DEV-72 снял её продуктово, DEV-141
	 * удалил остатки (класс `IDPG_Organizer_Page`, шаблон, скрипт и его
	 * rewrite-правило). Поэтому правил кабинета ровно два:
	 * `/organizer/{section}/` и база `/organizer/`.
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

		$this->assertContains( '^organizer/(dashboard|events|venues|profile)/?$', $regexes );
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

	// =========================================================================
	// Форма события (G5): /organizer/event/new/ и /organizer/event/{id}/
	// =========================================================================

	/**
	 * Query-переменная формы события зарегистрирована.
	 *
	 * Без неё rewrite-правила подставят параметр в адрес, но WP его
	 * отбросит и get_query_var() вернёт пустую строку — то есть страница
	 * формы отрендерилась бы обычным шаблоном записи.
	 */
	public function test_event_qvar_registered() {
		$vars = IDPG_Organizer_URL::query_vars( array( 'p' ) );

		$this->assertContains( IDPG_Organizer_URL::EVENT_QVAR, $vars );
		$this->assertContains( 'p', $vars );
	}

	/**
	 * Rewrite-правила формы события: создание и правка.
	 */
	public function test_event_rewrite_rules_registered() {
		global $wp_rewrite;

		update_option( IDPG_Organizer_URL::FLUSH_OPT, IDPG_Organizer_URL::REWRITE_VERSION );

		IDPG_Organizer_URL::rewrites();

		$event_rules = array();

		foreach ( (array) $wp_rewrite->extra_rules_top as $regex => $query ) {
			if ( false !== strpos( (string) $query, IDPG_Organizer_URL::EVENT_QVAR ) ) {
				$event_rules[ $regex ] = $query;
			}
		}

		$this->assertCount( 2, $event_rules, 'Ожидались два правила формы события.' );

		$regexes = array_keys( $event_rules );

		$this->assertContains( '^organizer/event/new/?$', $regexes );
		$this->assertContains( '^organizer/event/([0-9]+)/?$', $regexes );

		// Порядок проверяем по полному списку правил, а не по отфильтрованному.
		// WP_Rewrite::add_rule() делает обычное присваивание в extra_rules_top,
		// поэтому порядок массива = порядок регистрации, и правила проверяются
		// именно в нём. Правила формы объявлены первыми, значит в массиве они
		// должны стоять раньше правил разделов — иначе раздел с слагом `event`
		// перехватил бы /organizer/event/.
		$all_regexes = array_keys( (array) $wp_rewrite->extra_rules_top );

		$event_new_pos = array_search( '^organizer/event/new/?$', $all_regexes, true );
		$section_pos   = array_search( '^organizer/(dashboard|events|venues|profile)/?$', $all_regexes, true );

		$this->assertNotFalse( $event_new_pos, 'Правило создания события должно регистрироваться.' );
		$this->assertNotFalse( $section_pos, 'Правило разделов должно регистрироваться.' );
		$this->assertLessThan(
			$section_pos,
			$event_new_pos,
			'Правила формы должны проверяться раньше правил разделов.'
		);

		// Числовой ID подставляется в query-переменную, `new` — константой.
		$this->assertStringContainsString(
			IDPG_Organizer_URL::EVENT_QVAR . '=$matches[1]',
			$event_rules['^organizer/event/([0-9]+)/?$']
		);

		$this->assertStringContainsString(
			IDPG_Organizer_URL::EVENT_QVAR . '=' . IDPG_Organizer_URL::EVENT_NEW,
			$event_rules['^organizer/event/new/?$']
		);
	}

	/**
	 * Адреса формы события: создание и правка.
	 */
	public function test_event_form_urls() {
		$this->assertSame(
			home_url( '/organizer/event/new/' ),
			IDPG_Organizer_URL::event_form_url( 0 )
		);

		$this->assertSame(
			home_url( '/organizer/event/42/' ),
			IDPG_Organizer_URL::event_form_url( 42 )
		);

		// Мусор на входе не должен попасть в адрес.
		$this->assertSame(
			home_url( '/organizer/event/new/' ),
			IDPG_Organizer_URL::event_form_url( 'abc' )
		);
	}

	/**
	 * ID события из query-переменной формы.
	 */
	public function test_current_event_id_from_query_var() {
		$this->set_cabinet_query_var( IDPG_Organizer_URL::EVENT_QVAR, '42' );
		$this->assertSame( 42, IDPG_Organizer_URL::get_current_event_id() );
		$this->assertTrue( IDPG_Organizer_URL::is_event_form_request() );

		// `new` и отсутствие значения — оба означают «создать».
		$this->set_cabinet_query_var( IDPG_Organizer_URL::EVENT_QVAR, IDPG_Organizer_URL::EVENT_NEW );
		$this->assertSame( 0, IDPG_Organizer_URL::get_current_event_id() );

		$this->set_cabinet_query_var( IDPG_Organizer_URL::EVENT_QVAR, '' );
		$this->assertSame( 0, IDPG_Organizer_URL::get_current_event_id() );
		$this->assertFalse( IDPG_Organizer_URL::is_event_form_request() );
	}

	/**
	 * Форма события — часть кабинета: ей нужны те же ассеты.
	 */
	public function test_event_form_is_part_of_cabinet() {
		$this->set_cabinet_query_var( IDPG_Organizer_URL::EVENT_QVAR, IDPG_Organizer_URL::EVENT_NEW );

		$this->assertTrue( IDPG_Organizer_URL::is_cabinet_request() );
	}

	/**
	 * Запрос формы обслуживается отдельным шаблоном.
	 */
	public function test_event_form_template_selected() {
		$page_template = locate_template( 'page-organizer.php' );

		// Вне кабинета шаблон не подменяется.
		$this->assertSame(
			$page_template,
			IDPG_Organizer_URL::template( $page_template )
		);

		$this->set_cabinet_query_var( IDPG_Organizer_URL::PAGE_QVAR, '1' );
		$this->set_cabinet_query_var( IDPG_Organizer_URL::EVENT_QVAR, IDPG_Organizer_URL::EVENT_NEW );

		$this->assertSame(
			locate_template( IDPG_Organizer_URL::EVENT_TEMPLATE ),
			IDPG_Organizer_URL::template( $page_template )
		);
	}

	/**
	 * Правила сброса постоянных ссылок подняты: правила формы добавлены
	 * после последнего сброса, поэтому старая версия должна форсировать новый.
	 */
	public function test_rewrite_version_covers_event_rules() {
		$this->assertGreaterThanOrEqual(
			2,
			IDPG_Organizer_URL::REWRITE_VERSION,
			'Правила формы события требуют пересборки rewrite_rules.'
		);
	}
}
