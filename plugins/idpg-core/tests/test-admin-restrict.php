<?php
/**
 * DEV-106: организатор не попадает в /wp-admin.
 *
 * У организатора есть права `edit_events`, `publish_venues`, `edit_venues` —
 * они нужны кабинету (`IDPG_REST_Organizer::can_create_venue()` требует
 * `edit_venues`, `IDPG_REST_API::can_manage_events()` — `edit_events` или
 * `publish_venues`, `IDPG_Dashboard_Stats` — `edit_events`). Закрывается
 * доступ к интерфейсу админки, а не набор прав.
 *
 * Тестируется не сам `restrict_admin_access()`: он заканчивается `exit`,
 * который в PHPUnit не перехватывается. Проверяется решение — приватный
 * `admin_access_redirect()`, — плюс отдельная проверка, что метод действительно
 * обёрнут в редирект и что guard на AJAX стоит до редиректа.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Admin_Restrict
 */
class Test_Admin_Restrict extends WP_UnitTestCase {

	/**
	 * Подготовка ролей.
	 *
	 * Без явного `create_roles()` роль `event_organizer` в тестовой базе не
	 * существует, и `add_role()` молча её не выдаёт: пользователь остаётся
	 * подписчиком, а проверки прав проходят, ничего не проверяя.
	 */
	public function set_up() {
		parent::set_up();

		IDPG_Roles::instance()->create_roles();
	}

	/**
	 * Создать пользователя с набором ролей.
	 *
	 * @param string $login Логин.
	 * @param array  $roles Роли.
	 * @return WP_User
	 */
	protected function make_user( $login, $roles ) {
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

		foreach ( $roles as $role ) {
			$user->add_role( $role );
		}

		$this->assertSame(
			$roles,
			array_values( (array) $user->roles ),
			'Роли пользователя выданы не те, что запрошены: проверка была бы пустой.'
		);

		return $user;
	}

	/**
	 * Решение метода: адрес редиректа либо пустая строка.
	 *
	 * @param WP_User $user Пользователь.
	 * @return string
	 */
	protected function redirect_target( $user ) {
		$auth = new ReflectionClass( 'IDPG_Auth' );
		$auth->getMethod( 'instance' )->setAccessible( true );

		$instance = IDPG_Auth::instance();

		$method = $auth->getMethod( 'admin_access_redirect' );
		$method->setAccessible( true );

		return (string) $method->invoke( $instance, $user );
	}

	/**
	 * Исходник метода целиком.
	 *
	 * Граница находится подсчётом скобок: угадывать конец по отступу
	 * нельзя — тело метода заканчивается `\t}`, но такой же отступ встречается
	 * внутри вложенных конструкций, и метод «размазывался» на полфайла.
	 *
	 * @param string $name Имя метода.
	 * @return string
	 */
	protected function method_source( $name ) {
		$file  = ( new ReflectionClass( 'IDPG_Auth' ) )->getFileName();
		$lines = file( $file );
		$out   = '';
		$depth = 0;
		$seen  = false;

		foreach ( $lines as $line ) {
			if ( ! $seen ) {
				if ( false !== strpos( $line, 'function ' . $name . '(' ) ) {
					$seen = true;
				} else {
					continue;
				}
			}

			$out .= $line . "\n";
			$depth += substr_count( $line, '{' ) - substr_count( $line, '}' );

			if ( $seen && $depth <= 0 ) {
				break;
			}
		}

		$this->assertTrue( $seen, 'Метод ' . $name . ' не найден в исходнике.' );

		return $out;
	}

	// =====================================================================
	// Приёмка
	// =====================================================================

	/**
	 * Организатор отправляется в кабинет, а не остаётся в админке.
	 */
	public function test_organizer_redirected_to_cabinet() {
		$user    = $this->make_user( 'dev106_org', array( IDPG_Roles::ROLE_SLUG ) );
		$target  = $this->redirect_target( $user );

		$this->assertNotSame( '', $target, 'Организатор не должен попадать в /wp-admin.' );
		$this->assertStringContainsString(
			'/organizer',
			$target,
			'Организатора нужно отправлять в кабинет, получено: ' . $target
		);
	}

	/**
	 * Подписчик уходит на главную, как и раньше.
	 */
	public function test_subscriber_redirected_home() {
		$user = $this->make_user( 'dev106_sub', array( 'subscriber' ) );

		$this->assertSame( home_url( '/' ), $this->redirect_target( $user ) );
	}

	/**
	 * Администратор и редактор проходят в админку.
	 *
	 * Им нужна админка для модерации: публикация, статус билетов, отклонение.
	 *
	 * @dataProvider provide_staff_roles
	 *
	 * @param string $role Роль.
	 */
	public function test_staff_passes_through( $role ) {
		$user = $this->make_user( 'dev106_' . $role, array( $role ) );

		$this->assertSame(
			'',
			$this->redirect_target( $user ),
			$role . ' должен проходить в админку без редиректа.'
		);
	}

	/**
	 * Роли персонала.
	 *
	 * @return array
	 */
	public function provide_staff_roles() {
		return array(
			'administrator' => array( 'administrator' ),
			'editor'        => array( 'editor' ),
		);
	}

	/**
	 * Роль от стороннего плагина ведёт себя как раньше — пропускается.
	 */
	public function test_unknown_role_passes_through() {
		$user = $this->make_user( 'dev106_other', array( 'contributor' ) );

		$this->assertSame( '', $this->redirect_target( $user ) );
	}

	/**
	 * Организатор с дополнительной ролью редактора сохраняет админку.
	 *
	 * Проверка порядка: `administrator`/`editor` разбираются раньше
	 * организатора, иначе редактор-организатор потерял бы модерацию.
	 */
	public function test_organizer_with_editor_role_keeps_admin() {
		$user = $this->make_user( 'dev106_both', array( IDPG_Roles::ROLE_SLUG, 'editor' ) );

		$this->assertSame( '', $this->redirect_target( $user ) );
	}

	// =====================================================================
	// Права не должны пострадать
	// =====================================================================

	/**
	 * У организатора остаются права, нужные кабинету.
	 *
	 * Регресс: если бы права забрали, кабинет перестал бы работать.
	 */
	public function test_organizer_keeps_cabinet_capabilities() {
		$user = $this->make_user( 'dev106_caps', array( IDPG_Roles::ROLE_SLUG ) );

		foreach ( array( 'edit_venues', 'publish_venues', 'edit_events' ) as $cap ) {
			$this->assertTrue(
				user_can( $user, $cap ),
				'У организатора должно остаться право ' . $cap . ': кабинет без него не работает.'
			);
		}
	}

	// =====================================================================
	// Обёртка: guard'ы и сам редирект
	// =====================================================================

	/**
	 * `restrict_admin_access()` действительно редиректит по решению метода.
	 *
	 * Само исполнение проверить нельзя — оно завершается `exit`. Поэтому
	 * проверяется, что решение обёрнуто в `wp_safe_redirect()` с `exit`,
	 * а не вызывается вхолостую.
	 */
	public function test_restrict_admin_wraps_decision_into_redirect() {
		$source = $this->method_source( 'restrict_admin_access' );

		$this->assertStringContainsString( 'admin_access_redirect(', $source );
		$this->assertStringContainsString( 'wp_safe_redirect( $target )', $source );
		$this->assertSame(
			2,
			substr_count( $source, 'exit;' ),
			'Ожидались два выхода: после неавторизованного и после редиректа.'
		);
	}

	/**
	 * Guard на AJAX стоит до редиректа.
	 *
	 * `admin-ajax.php` обслуживает сам кабинет: редирект обрушил бы загрузку
	 * медиа и прочие фоновые запросы. Проверяется порядком в исходнике, а не
	 * исполнением: константу `DOING_AJAX` в процессе тестов объявить можно
	 * один раз и уже нельзя отменить, то есть такой тест ломал бы все
	 * последующие. Живая проверка вынесена в приёмку DEV-106.
	 */
	public function test_ajax_guard_precedes_redirect() {
		$source = $this->method_source( 'restrict_admin_access' );

		$ajax = strpos( $source, 'DOING_AJAX' );
		$call = strpos( $source, 'admin_access_redirect(' );

		$this->assertNotFalse( $ajax, 'Нет guard на DOING_AJAX.' );
		$this->assertNotFalse( $call, 'Метод не вызывает решение о редиректе.' );
		$this->assertLessThan(
			$call,
			$ajax,
			'Guard на DOING_AJAX должен стоять до редиректа, иначе admin-ajax.php перестанет работать.'
		);
	}
}
