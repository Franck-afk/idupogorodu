<?php
/**
 * Тесты P1: тексты писем и сообщений не обещают недоступный кабинет.
 *
 * Проверяется фильтр `idpg_organizer_cabinet_enabled` и два набора текстов:
 * при выключенном флаге в письмах и ответах регистрации нет утвердительного
 * «кабинет организатора», при включённом — прежние формулировки сохранены.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Organizer_Cabinet_Texts
 */
class Test_Organizer_Cabinet_Texts extends WP_UnitTestCase {

	/**
	 * Последнее «отправленное» письмо (перехват pre_wp_mail).
	 *
	 * @var array
	 */
	private $mail = array();

	/**
	 * Подмена wp_mail: запоминаем аргументы и не отправляем.
	 *
	 * @param mixed $return Значение-заглушка.
	 * @param array $atts   Аргументы wp_mail: to, subject, message.
	 * @return bool
	 */
	public function catch_mail( $return, $atts ) {
		$this->mail = is_array( $atts ) ? $atts : array();

		return true;
	}

	/**
	 * Начать перехват писем.
	 *
	 * @return void
	 */
	private function capture_mail() {
		$this->mail = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
	}

	/**
	 * Принудительно выставить доступность кабинета.
	 *
	 * @param bool $value Доступен ли кабинет.
	 * @return void
	 */
	private function force_flag( $value ) {
		add_filter(
			'idpg_organizer_cabinet_enabled',
			static function () use ( $value ) {
				return $value;
			}
		);
	}

	/**
	 * Сброс фильтров между тестами.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'idpg_organizer_cabinet_enabled' );
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10 );
		$this->mail = array();
		parent::tearDown();
	}

	/**
	 * Флаг подчиняется фильтру в обе стороны.
	 */
	public function test_flag_respects_filter() {
		$this->force_flag( false );
		$this->assertFalse( IDPG_Helpers::organizer_cabinet_enabled() );

		remove_all_filters( 'idpg_organizer_cabinet_enabled' );
		$this->force_flag( true );
		$this->assertTrue( IDPG_Helpers::organizer_cabinet_enabled() );
	}

	/**
	 * Письмо подтверждения без кабинета: нет обещания, есть нейтральный текст.
	 */
	public function test_verification_email_neutral_without_cabinet() {
		$this->force_flag( false );
		$this->capture_mail();

		$user_id = self::factory()->user->create( array( 'user_email' => 'verify-neutral@test.ru' ) );
		IDPG_Auth_Emails::instance()->send_organizer_verification_email( $user_id, 'key-neutral' );

		$this->assertNotEmpty( $this->mail, 'Письмо подтверждения не сформировано.' );

		$body = (string) $this->mail['message'];

		$this->assertStringNotContainsString( 'кабинет', $body );
		$this->assertStringContainsString( 'аккаунт организатора', $body );
	}

	/**
	 * Письмо подтверждения при работающем кабинете сохраняет прежний текст.
	 */
	public function test_verification_email_keeps_promise_with_cabinet() {
		$this->force_flag( true );
		$this->capture_mail();

		$user_id = self::factory()->user->create( array( 'user_email' => 'verify-on@test.ru' ) );
		IDPG_Auth_Emails::instance()->send_organizer_verification_email( $user_id, 'key-on' );

		$this->assertStringContainsString( 'кабинету организатора', (string) $this->mail['message'] );
	}

	/**
	 * Тема приветственного письма без кабинета.
	 */
	public function test_welcome_subject_neutral_without_cabinet() {
		$this->force_flag( false );
		$this->capture_mail();
		$this->send_welcome();

		$subject = (string) $this->mail['subject'];

		$this->assertStringNotContainsString( 'кабинет', $subject );
		$this->assertStringContainsString( 'Регистрация организатора завершена', $subject );
	}

	/**
	 * Тема приветственного письма при работающем кабинете — прежняя.
	 */
	public function test_welcome_subject_keeps_promise_with_cabinet() {
		$this->force_flag( true );
		$this->capture_mail();
		$this->send_welcome();

		$this->assertStringContainsString( 'Ваш кабинет организатора готов', (string) $this->mail['subject'] );
	}

	/**
	 * Отправка приветственного письма (приватный метод).
	 *
	 * @return void
	 */
	private function send_welcome() {
		$user_id = self::factory()->user->create( array( 'user_email' => 'welcome@test.ru' ) );

		$ref = new ReflectionMethod( 'IDPG_Auth_Emails', 'send_organizer_welcome_email' );
		$ref->setAccessible( true );
		$ref->invoke( IDPG_Auth_Emails::instance(), new WP_User( $user_id ), 'Pass1234' );
	}

	/**
	 * Тело приветственного письма без кабинета.
	 */
	public function test_welcome_body_neutral_without_cabinet() {
		$this->force_flag( false );

		$html = $this->render_welcome_body();

		$this->assertStringNotContainsString( 'кабинет организатора', $html );
		$this->assertStringContainsString( 'Личный кабинет временно недоступен', $html );
	}

	/**
	 * Тело приветственного письма при работающем кабинете — прежнее.
	 */
	public function test_welcome_body_keeps_promise_with_cabinet() {
		$this->force_flag( true );

		$html = $this->render_welcome_body();

		$this->assertStringContainsString( 'Ваш кабинет организатора', $html );
		$this->assertStringContainsString( 'публиковать мероприятия', $html );
	}

	/**
	 * Рендер приветственного шаблона (приватный метод).
	 *
	 * @return string
	 */
	private function render_welcome_body() {
		$ref = new ReflectionMethod( 'IDPG_Auth_Emails', 'render_organizer_email_template' );
		$ref->setAccessible( true );

		return (string) $ref->invoke(
			IDPG_Auth_Emails::instance(),
			'Иван',
			'org@test.ru',
			'Pass1234',
			'https://example.ru/login/'
		);
	}

	/**
	 * Сообщение ответа регистрации (bait-ответ honeypot) без кабинета.
	 */
	public function test_registration_message_neutral_without_cabinet() {
		$this->force_flag( false );

		$message = $this->registration_message();

		$this->assertStringNotContainsString( 'кабинет', $message );
		$this->assertStringContainsString( 'завершить регистрацию', $message );
	}

	/**
	 * Сообщение ответа регистрации при работающем кабинете — прежнее.
	 */
	public function test_registration_message_keeps_promise_with_cabinet() {
		$this->force_flag( true );

		$this->assertStringContainsString( 'войти в кабинет организатора', $this->registration_message() );
	}

	/**
	 * Текст ответа регистрации из bait-метода (приватный).
	 *
	 * @return string
	 */
	private function registration_message() {
		$ref = new ReflectionMethod( 'IDPG_REST_Auth', 'get_register_bait_response' );
		$ref->setAccessible( true );

		$response = $ref->invoke( IDPG_REST_Auth::instance() );
		$data     = $response->get_data();

		return isset( $data['message'] ) ? (string) $data['message'] : '';
	}

	/**
	 * Оба места ответа регистрации берут текст из одного метода.
	 */
	public function test_registration_message_uses_single_source() {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-rest-auth.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertSame(
			2,
			substr_count( $src, '$this->get_register_pending_message()' ),
			'Оба ответа регистрации должны использовать общий метод.'
		);

		$this->assertSame(
			1,
			substr_count( $src, 'чтобы войти в кабинет организатора' ),
			'Утвердительный текст должен остаться только внутри метода (ветка «кабинет включён»).'
		);
	}
}
