<?php
/**
 * Юнит-тесты редиректа в кабинет после входа через соцсеть (DEV-63).
 *
 * idpg-auth в тестовом окружении не загружен, поэтому проверяется слой
 * ядра: регистрация фильтров `wp_auth_ru_*_after_login_redirect` и сама
 * логика выбора адреса. Фактическое применение фильтра плагином
 * покрыто тестами idpg-auth (tests/test-social-roles.php).
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Organizer_Social_Login
 */
class Test_Organizer_Social_Login extends WP_UnitTestCase {

	/**
	 * Регистрирует роль организатора.
	 *
	 * Без этого `self::factory()->user->create( array( 'role' => 'event_organizer' ) )`
	 * молча создаст подписчика: роль не зарегистрирована, и WordPress
	 * игнорирует неизвестную. Тот же приём использует `test-roles.php`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		IDPG_Roles::instance()->create_roles();
	}

	/**
	 * Адрес, который плагин idpg-auth предлагает по умолчанию.
	 *
	 * @return string
	 */
	private function provider_default() {
		return home_url();
	}

	/**
	 * Фильтры подключены для всех провайдеров idpg-auth.
	 */
	public function test_registers_redirect_filter_for_every_provider() {
		foreach ( IDPG_Organizer_URL::SOCIAL_PROVIDERS as $provider ) {
			$this->assertTrue(
				has_filter( 'wp_auth_ru_' . $provider . '_after_login_redirect' ),
				"Фильтр для провайдера {$provider} должен быть подключён."
			);
		}
	}

	/**
	 * Список провайдеров совпадает с набором классов idpg-auth.
	 */
	public function test_provider_list_covers_known_providers() {
		$this->assertSame(
			array( 'yandex', 'vk', 'max' ),
			array_values( IDPG_Organizer_URL::SOCIAL_PROVIDERS )
		);
	}

	/**
	 * Организатор после соцвхода попадает в кабинет.
	 */
	public function test_organizer_is_redirected_to_cabinet() {
		$user_id = self::factory()->user->create( array( 'role' => 'event_organizer' ) );
		wp_set_current_user( $user_id );

		$this->assertSame(
			home_url( '/organizer/' ),
			IDPG_Organizer_URL::social_login_redirect( $this->provider_default() )
		);
	}

	/**
	 * Подписчику остаётся адрес, предложенный плагином.
	 */
	public function test_subscriber_keeps_provider_default() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->assertSame(
			$this->provider_default(),
			IDPG_Organizer_URL::social_login_redirect( $this->provider_default() )
		);
	}

	/**
	 * Без авторизации адрес не меняется.
	 */
	public function test_guest_keeps_provider_default() {
		wp_set_current_user( 0 );

		$this->assertSame(
			$this->provider_default(),
			IDPG_Organizer_URL::social_login_redirect( $this->provider_default() )
		);
	}

	/**
	 * Администратору и редактору кабинет не подставляется: after_login_url()
	 * для них пуст, поэтому остаётся исходный адрес.
	 *
	 * @dataProvider data_staff_roles
	 *
	 * @param string $role Роль редакции.
	 */
	public function test_staff_roles_keep_provider_default( $role ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		$this->assertSame(
			$this->provider_default(),
			IDPG_Organizer_URL::social_login_redirect( $this->provider_default() )
		);
	}

	/**
	 * Роли редакции для проверки.
	 *
	 * @return array[]
	 */
	public function data_staff_roles() {
		return array(
			'администратор' => array( 'administrator' ),
			'редактор'      => array( 'editor' ),
		);
	}

	/**
	 * Повторный вызов фильтра не меняет роль пользователя: редирект только
	 * читает роль, никаких правок в базе не делает.
	 */
	public function test_filter_does_not_change_role() {
		$user_id = self::factory()->user->create( array( 'role' => 'event_organizer' ) );
		wp_set_current_user( $user_id );

		IDPG_Organizer_URL::social_login_redirect( $this->provider_default() );
		IDPG_Organizer_URL::social_login_redirect( $this->provider_default() );

		$user = get_userdata( $user_id );

		$this->assertContains( 'event_organizer', (array) $user->roles );
	}

	/**
	 * Подписчик, которому фильтр отдал главную страницу, не становится
	 * организатором по ошибке.
	 */
	public function test_subscriber_is_not_promoted() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		IDPG_Organizer_URL::social_login_redirect( $this->provider_default() );

		$user = get_userdata( $user_id );

		$this->assertNotContains( 'event_organizer', (array) $user->roles );
	}

	/**
	 * Фильтр пропускает любой предложенный адрес, если роль не организатор:
	 * значение не перетирается «главной».
	 */
	public function test_non_organizer_keeps_arbitrary_provider_value() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->assertSame(
			home_url( '/events/' ),
			IDPG_Organizer_URL::social_login_redirect( home_url( '/events/' ) )
		);
	}

	/**
	 * Защитный фильтр подключён.
	 */
	public function test_redirect_guard_is_registered() {
		$this->assertTrue( has_filter( 'wp_redirect' ) );
	}

	/**
	 * Редирект организатора в кабинет не трогаем.
	 */
	public function test_guard_lets_organizer_into_cabinet() {
		$user_id = self::factory()->user->create( array( 'role' => 'event_organizer' ) );
		wp_set_current_user( $user_id );

		$this->assertSame(
			home_url( '/organizer/' ),
			IDPG_Organizer_URL::guard_cabinet_redirect( home_url( '/organizer/' ) )
		);
	}

	/**
	 * Подписчика в кабинет не пускаем: это замыкает цикл
	 * «вход → кабинет → вход», который возникает при явном
	 * `redirect_to=/organizer/`, минующем фильтр провайдера.
	 */
	public function test_guard_sends_subscriber_home() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->assertSame(
			home_url(),
			IDPG_Organizer_URL::guard_cabinet_redirect( home_url( '/organizer/' ) )
		);
	}

	/**
	 * Гостя в кабинет не пускаем.
	 */
	public function test_guard_sends_guest_home() {
		wp_set_current_user( 0 );

		$this->assertSame(
			home_url(),
			IDPG_Organizer_URL::guard_cabinet_redirect( home_url( '/organizer/events/' ) )
		);
	}

	/**
	 * Администратор и редактор в кабинет проходят: доступ у них есть,
	 * guard не должен ломать существующие редиректы.
	 *
	 * @dataProvider data_staff_roles
	 *
	 * @param string $role Роль редакции.
	 */
	public function test_guard_lets_staff_into_cabinet( $role ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		$this->assertSame(
			home_url( '/organizer/' ),
			IDPG_Organizer_URL::guard_cabinet_redirect( home_url( '/organizer/' ) )
		);
	}

	/**
	 * Редиректы за пределы кабинета не изменяются ни для кого.
	 */
	public function test_guard_ignores_non_cabinet_redirects() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->assertSame(
			home_url( '/events/' ),
			IDPG_Organizer_URL::guard_cabinet_redirect( home_url( '/events/' ) )
		);
	}

	/**
	 * Адрес-замена из фильтра `idpg_organizer_denied_redirect_url`.
	 */
	public function test_guard_honours_denied_redirect_filter() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		add_filter(
			'idpg_organizer_denied_redirect_url',
			static function () {
				return home_url( '/login/' );
			}
		);

		$this->assertSame(
			home_url( '/login/' ),
			IDPG_Organizer_URL::guard_cabinet_redirect( home_url( '/organizer/' ) )
		);
	}

	/**
	 * Адреса кабинета опознаются независимо от слэша и query-строки.
	 *
	 * @dataProvider data_cabinet_urls
	 *
	 * @param string $url      Проверяемый адрес.
	 * @param bool   $expected Ожидаемый результат.
	 */
	public function test_is_cabinet_url( $url, $expected ) {
		$this->assertSame( $expected, IDPG_Organizer_URL::is_cabinet_url( $url ) );
	}

	/**
	 * Набор адресов для проверки опознания кабинета.
	 *
	 * `is_cabinet_url()` — общая префиксная проверка «внутри /organizer/»,
	 * ей не важно, существует ли такой подпуть. Поэтому и /organizer/complete/
	 * (анкета удалена с DEV-72, страницы больше нет) всё ещё опознаётся как
	 * кабинетный адрес: это защищает guard от редирект-петли на несуществующей
	 * странице, а не утверждение о её существовании.
	 *
	 * @return array[]
	 */
	public function data_cabinet_urls() {
		return array(
			'корень со слэшем'    => array( 'http://example.org/organizer/', true ),
			'корень без слэша'    => array( 'http://example.org/organizer', true ),
			'раздел'              => array( 'http://example.org/organizer/events/', true ),
			'с query-строкой'     => array( 'http://example.org/organizer/?utm=1', true ),
			'удалённый подпуть'   => array( 'http://example.org/organizer/complete/', true ),
			'похожий префикс'     => array( 'http://example.org/organizers-2026/', false ),
			'чужой раздел'        => array( 'http://example.org/blog/', false ),
			'чужой домен'         => array( 'http://evil.example.org/organizer/', false ),
			'пустая строка'       => array( '', false ),
		);
	}
}
