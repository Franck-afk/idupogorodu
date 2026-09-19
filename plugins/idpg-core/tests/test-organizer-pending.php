<?php
/**
 * Юнит-тесты: пайплайн pending-организатора (DEV-50).
 *
 * Проверяется:
 *  - соц-организатор (роль event_organizer + мета wp_auth_ru_provider),
 *    впервые вошедший в систему, помечается флагом доуполнения анкеты
 *    под обоими ключами (`_idpg_org_pending` и `idpg_org_pending`);
 *  - страховка на лету: организатор без заполненной анкеты (нет
 *    `_idpg_org_completed_at` и `idpg_org_name`) считается pending
 *    даже при отсутствии флага (рассинхрон G7);
 *  - после заполнения анкеты пользователь получает доступ в кабинет
 *    (ACCESS_ALLOWED), более не считается pending;
 *  - повторный вход после завершения анкеты не возвращает флаг
 *    pending (хук wp_login не перезаписывает отметку).
 *
 * Логика проверяется через чисто-функциональные методы
 * `IDPG_Organizer_Page::is_pending()` / `is_questionnaire_complete()`
 * и `IDPG_Organizer_URL::get_access_decision()` без HTTP-запросов.
 *
 * @package Idpg_Core
 */

// Класс кабинета организатора (class-organizer-url.php) в боевой загрузке
// плагина может быть не подключён (аудит G1), поэтому для теста он
// подгружается явно — без изменения обвязки плагина.
if ( ! class_exists( 'IDPG_Organizer_URL' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-organizer-url.php';
}

/**
 * Класс Test_Organizer_Pending
 *
 * @covers IDPG_Organizer_Page
 * @covers IDPG_Organizer_URL
 */
class Test_Organizer_Pending extends WP_UnitTestCase {

	/**
	 * Создать соц-организатора.
	 *
	 * Роль event_organizer + мета wp_auth_ru_provider — типичный профиль
	 * пользователя, созданного через соц-кнопку idpg-auth.
	 *
	 * @param string $login Логин (латиница).
	 * @return WP_User
	 */
	protected function make_social_organizer( $login ) {
		$user_id = $this->factory()->user->create(
			array(
				'user_login' => $login,
				'role'       => 'event_organizer',
			)
		);

		update_user_meta( $user_id, 'wp_auth_ru_provider', 'vkontakte' );

		return new WP_User( $user_id );
	}

	/**
	 * Соц-организатор без анкеты считается pending (страховка на лету):
	 * флага ещё нет, а роль и отсутствие анкеты уже распознаются.
	 */
	public function test_social_organizer_without_questionnaire_is_pending() {
		$user = $this->make_social_organizer( 'social_org_1' );

		wp_set_current_user( $user->ID );

		// Флаг ещё не выставлен (хук wp_login не срабатывал автоматически).
		$this->assertTrue( IDPG_Organizer_Page::is_pending() );
		$this->assertFalse( IDPG_Organizer_Page::is_questionnaire_complete( $user->ID ) );
		$this->assertSame( IDPG_Organizer_URL::ACCESS_PENDING, IDPG_Organizer_URL::get_access_decision( $user->ID ) );
	}

	/**
	 * Хук wp_login ставит оба варианта ключа pending при первом входе
	 * соц-организатора.
	 */
	public function test_wp_login_hook_marks_social_organizer_pending() {
		$user = $this->make_social_organizer( 'social_org_2' );

		// Эмуляция входа: wp_login передаёт логин и объект пользователя.
		IDPG_Organizer_Page::maybe_mark_social_organizer_pending( $user->user_login, $user );

		$this->assertSame( '1', get_user_meta( $user->ID, '_idpg_org_pending', true ) );
		$this->assertSame( '1', get_user_meta( $user->ID, 'idpg_org_pending', true ) );
		$this->assertFalse( IDPG_Organizer_Page::is_questionnaire_complete( $user->ID ) );

		wp_set_current_user( $user->ID );

		$this->assertTrue( IDPG_Organizer_Page::is_pending() );
		$this->assertSame( IDPG_Organizer_URL::ACCESS_PENDING, IDPG_Organizer_URL::get_access_decision( $user->ID ) );
	}

	/**
	 * Хук wp_login пропускает обычного организатора (без wp_auth_ru_provider)
	 * и подписчика — дополнительных флагов им не требуется.
	 */
	public function test_wp_login_hook_skips_non_social_users() {
		$manual_id = $this->factory()->user->create(
			array(
				'user_login' => 'manual_org_1',
				'role'       => 'event_organizer',
			)
		);
		$manual = new WP_User( $manual_id );

		IDPG_Organizer_Page::maybe_mark_social_organizer_pending( $manual->user_login, $manual );

		$this->assertEmpty( get_user_meta( $manual->ID, '_idpg_org_pending', true ) );
		$this->assertEmpty( get_user_meta( $manual->ID, 'idpg_org_pending', true ) );

		$sub_id = $this->factory()->user->create(
			array(
				'user_login' => 'plain_sub_1',
				'role'       => 'subscriber',
			)
		);
		update_user_meta( $sub_id, 'wp_auth_ru_provider', 'yandex' );
		$sub = new WP_User( $sub_id );

		IDPG_Organizer_Page::maybe_mark_social_organizer_pending( $sub->user_login, $sub );

		$this->assertEmpty( get_user_meta( $sub->ID, '_idpg_org_pending', true ) );
		$this->assertEmpty( get_user_meta( $sub->ID, 'idpg_org_pending', true ) );
	}

	/**
	 * После заполнения анкеты организатор перестаёт быть pending и получает
	 * доступ в кабинет (эмуляция успешного /auth/organizer-complete).
	 */
	public function test_after_filling_questionnaire_organizer_is_allowed() {
		$user = $this->make_social_organizer( 'social_org_3' );

		IDPG_Organizer_Page::maybe_mark_social_organizer_pending( $user->user_login, $user );

		// То, что делает handle_organizer_complete() на фронте: сохраняет
		// анкету, снимает оба флага и фиксирует отметку завершения.
		update_user_meta( $user->ID, 'idpg_org_name', 'ООО «Рога и копыта»' );
		update_user_meta( $user->ID, 'idpg_contact_name', 'Иван Иванов' );
		update_user_meta( $user->ID, 'idpg_phone', '+7 900 000-00-00' );
		delete_user_meta( $user->ID, '_idpg_org_pending' );
		delete_user_meta( $user->ID, 'idpg_org_pending' );
		update_user_meta( $user->ID, '_idpg_org_completed_at', time() );

		$this->assertTrue( IDPG_Organizer_Page::is_questionnaire_complete( $user->ID ) );

		wp_set_current_user( $user->ID );

		$this->assertFalse( IDPG_Organizer_Page::is_pending() );
		$this->assertSame( IDPG_Organizer_URL::ACCESS_ALLOWED, IDPG_Organizer_URL::get_access_decision( $user->ID ) );
	}

	/**
	 * Повторный вход после завершения анкеты не возвращает pending:
	 * хук wp_login видит отметку _idpg_org_completed_at и не выставляет
	 * флаги снова.
	 */
	public function test_relogin_after_completion_does_not_set_pending() {
		$user = $this->make_social_organizer( 'social_org_4' );

		// Первый вход — флаги выставлены.
		IDPG_Organizer_Page::maybe_mark_social_organizer_pending( $user->user_login, $user );
		$this->assertSame( '1', get_user_meta( $user->ID, '_idpg_org_pending', true ) );

		// Анкета заполнена (как после REST /auth/organizer-complete).
		update_user_meta( $user->ID, 'idpg_org_name', 'ООО «Рога и копыта»' );
		delete_user_meta( $user->ID, '_idpg_org_pending' );
		delete_user_meta( $user->ID, 'idpg_org_pending' );
		update_user_meta( $user->ID, '_idpg_org_completed_at', time() );

		// Повторный вход: флаги НЕ должны вернуться.
		IDPG_Organizer_Page::maybe_mark_social_organizer_pending( $user->user_login, new WP_User( $user->ID ) );

		$this->assertEmpty( get_user_meta( $user->ID, '_idpg_org_pending', true ) );
		$this->assertEmpty( get_user_meta( $user->ID, 'idpg_org_pending', true ) );

		wp_set_current_user( $user->ID );

		$this->assertFalse( IDPG_Organizer_Page::is_pending() );
		$this->assertSame( IDPG_Organizer_URL::ACCESS_ALLOWED, IDPG_Organizer_URL::get_access_decision( $user->ID ) );
	}
}