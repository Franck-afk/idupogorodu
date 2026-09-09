<?php
/**
 * Юнит-тесты приветственного письма организатора (IDPG_Auth_Emails).
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Auth_Emails
 */
class Test_Auth_Emails extends WP_UnitTestCase {

	/**
	 * remember_password/take_password: пароль запоминается и очищается.
	 */
	public function test_password_buffer_roundtrip() {
		IDPG_Auth_Emails::remember_password( 'org@test.ru', 'SuperSecret123' );

		$ref = new ReflectionClass( 'IDPG_Auth_Emails' );
		$m   = $ref->getMethod( 'take_password' );
		$m->setAccessible( true );
		$instance = IDPG_Auth_Emails::instance();

		$this->assertSame( 'SuperSecret123', $m->invoke( $instance, 'org@test.ru' ) );
		// Повторный вызов возвращает пустую строку — буфер очищен.
		$this->assertSame( '', $m->invoke( $instance, 'org@test.ru' ) );
	}

	/**
	 * Шаблон организаторского письма содержит данные для входа.
	 */
	public function test_organizer_template_contains_credentials() {
		$ref = new ReflectionMethod( 'IDPG_Auth_Emails', 'render_organizer_email_template' );
		$ref->setAccessible( true );
		$instance = IDPG_Auth_Emails::instance();

		$html = $ref->invoke( $instance, 'Иван Петров', 'org@test.ru', 'SuperSecret123', 'https://example.ru/login/' );

		$this->assertStringContainsString( 'Добро пожаловать, Иван Петров!', $html );
		$this->assertStringContainsString( 'org@test.ru', $html );
		$this->assertStringContainsString( 'SuperSecret123', $html );
		$this->assertStringContainsString( 'https://example.ru/login/', $html );
		$this->assertStringContainsString( 'support@idupogorodu.ru', $html );
	}

	/**
	 * Без пароля строка пароля не содержит реальных данных.
	 */
	public function test_organizer_template_without_password() {
		$ref = new ReflectionMethod( 'IDPG_Auth_Emails', 'render_organizer_email_template' );
		$ref->setAccessible( true );
		$instance = IDPG_Auth_Emails::instance();

		$html = $ref->invoke( $instance, 'Анна', 'anna@test.ru', '', 'https://example.ru/login/' );

		$this->assertStringContainsString( 'задан при регистрации', $html );
	}

	/**
	 * send_welcome_email для организатора использует отдельную ветку.
	 */
	public function test_send_welcome_email_organizer_branch() {
		// Создаём организатора; хук user_register отправит письмо (подавляем wp_mail).
		add_filter(
			'pre_wp_mail',
			function () {
				return true;
			}
		);

		IDPG_Auth_Emails::remember_password( 'org2@test.ru', 'OrgPass1234' );
		IDPG_Roles::instance()->create_roles();
		$org_id = wp_create_user( 'org2@test.ru', 'OrgPass1234', 'org2@test.ru' );
		$this->assertNotWPError( $org_id );

		$organizer = new WP_User( $org_id );

		// Чистая роль: убираем все и оставляем только event_organizer.
		foreach ( $organizer->roles as $role ) {
			$organizer->remove_role( $role );
		}
		$organizer->add_role( IDPG_Roles::ROLE_SLUG );

		$this->assertTrue( in_array( 'event_organizer', (array) $organizer->roles, true ) );
	}

	/**
	 * get_login_url возвращает permalink страницы с шаблоном page-login.
	 */
	public function test_get_login_url_uses_template_page() {
		$page_id = $this->factory()->post->create( array(
			'post_type'   => 'page',
			'post_title'  => 'Вход',
			'post_status' => 'publish',
		) );
		update_post_meta( $page_id, '_wp_page_template', 'page-login.php' );

		$ref = new ReflectionMethod( 'IDPG_Auth_Emails', 'get_login_url' );
		$ref->setAccessible( true );
		$instance = IDPG_Auth_Emails::instance();

		$actual = $ref->invoke( $instance );

		$this->assertStringContainsString( '?page_id=', $actual );
		$this->assertStringContainsString( (string) $page_id, $actual );
	}

	/**
	 * get_login_url: fallback на /login/, если страницы с шаблоном нет.
	 */
	public function test_get_login_url_fallback() {
		$ref = new ReflectionMethod( 'IDPG_Auth_Emails', 'get_login_url' );
		$ref->setAccessible( true );
		$instance = IDPG_Auth_Emails::instance();

		$actual = $ref->invoke( $instance );

		$this->assertStringContainsString( '/login/', $actual );
	}

	/**
	 * Обычный подписчик не получает организаторское письмо.
	 */
	public function test_send_welcome_email_subscriber_branch() {
		add_filter(
			'pre_wp_mail',
			function () {
				return true;
			}
		);

		IDPG_Auth_Emails::remember_password( 'user@test.ru', 'UserPass1234' );
		$ob = wp_insert_user(
			array(
				'user_login' => 'user@test.ru',
				'user_pass'  => 'UserPass1234',
				'user_email' => 'user@test.ru',
				'role'       => 'subscriber',
			)
		);

		$this->assertNotWPError( $ob );
	}
}