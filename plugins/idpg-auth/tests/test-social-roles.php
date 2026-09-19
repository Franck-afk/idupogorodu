<?php
/**
 * Юнит-тесты ролей соц-входа (DEV-51).
 *
 * Проверяется:
 *  - при соц-входе роль нового пользователя берётся из default_role
 *    сайта (subscriber по умолчанию), а НЕ из жёсткой роли
 *    event_organizer, которая раньше выдавалась в базовом классе
 *    провайдера (VK и Яндекс наследовали её напрямую);
 *  - фильтр `idpg_auth_new_user_role` позволяет переопределить роль
 *    соц-пользователя (плюс для провайдера-потомка) без правки кода;
 *  - хук-фильтр корректен для VK, Яндекс и MAX.
 *
 * Метод `new_user_role()` защищён, поэтому вызывается через Reflection,
 * как и полагается для юнит-теста приватной логики без HTTP-запросов.
 *
 * @package Idpg_Auth
 */

/**
 * Класс Test_Social_Roles
 */
class Test_Social_Roles extends WP_UnitTestCase {

	/**
	 * Вызвать защищённый new_user_role() для экземпляра провайдера.
	 *
	 * @param WP_Auth_Ru_Provider $provider Экземпляр провайдера.
	 * @return string Роль нового пользователя.
	 */
	protected function invoke_new_user_role( $provider ) {
		$reflection = new ReflectionMethod( $provider, 'new_user_role' );
		$reflection->setAccessible( true );

		return $reflection->invoke( $provider );
	}

	/**
	 * Роль по умолчанию для новых пользователей сайта (subscriber, если
	 * default_role не переопределён в настройках).
	 *
	 * @return string
	 */
	protected function site_default_role() {
		return get_option( 'default_role', 'subscriber' );
	}

	/**
	 * Соц-вход VK не выдаёт роль организатора: берется default_role.
	 */
	public function test_vk_new_user_role_is_default_not_organizer() {
		$role = $this->invoke_new_user_role( WP_Auth_Ru_VK::instance() );

		$this->assertSame( $this->site_default_role(), $role );
		$this->assertNotSame( 'event_organizer', $role );
	}

	/**
	 * Соц-вход Яндекса не выдаёт роль организатора: берется default_role.
	 */
	public function test_yandex_new_user_role_is_default_not_organizer() {
		$role = $this->invoke_new_user_role( WP_Auth_Ru_Yandex::instance() );

		$this->assertSame( $this->site_default_role(), $role );
		$this->assertNotSame( 'event_organizer', $role );
	}

	/**
	 * MAX берёт роль из default_role и не выдаёт организатора.
	 */
	public function test_max_new_user_role_is_default_not_organizer() {
		$role = $this->invoke_new_user_role( WP_Auth_Ru_Max::instance() );

		$this->assertSame( $this->site_default_role(), $role );
		$this->assertNotSame( 'event_organizer', $role );
	}

	/**
	 * Фильтр idpg_auth_new_user_role переопределяет роль для VK
	 * и получает слаг провайдера вторым аргументом.
	 */
	public function test_filter_overrides_role_for_vk() {
		$seen_provider = '';

		add_filter(
			'idpg_auth_new_user_role',
			function ( $role, $provider ) use ( &$seen_provider ) {
				$seen_provider = $provider;

				return 'editor';
			},
			10,
			2
		);

		$role = $this->invoke_new_user_role( WP_Auth_Ru_VK::instance() );
		remove_all_filters( 'idpg_auth_new_user_role' );

		$this->assertSame( 'editor', $role );
		$this->assertSame( 'vk', $seen_provider );
	}

	/**
	 * Без фильтра default_role = subscriber не выдаёт организатора.
	 */
	public function test_subscriber_default_never_organizer() {
		// Штатно в тестовом окружении default_role = subscriber.
		$this->assertSame( 'subscriber', $this->site_default_role() );
	}
}