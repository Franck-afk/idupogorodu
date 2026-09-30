<?php
/**
 * Юнит-тесты DEV-151: nonce в форме пароля maintenance.
 *
 * Контекст. DEV-07 была помечена выполненной фиктивно: `class-maintenance.php`
 * размазывал `phpcs:ignore WordPress.Security.NonceVerification` по всему
 * файлу, а `wp_verify_nonce` в проекте не было вовсе —
 * `git log --all -S 'is_access_nonce_valid'` даёт ноль коммитов. Форма пароля
 * доступа принимала любой POST: подделка чужого запроса не была защищена.
 *
 * Тесты бьют по решению (`is_access_request_valid()`), а не по
 * `handle_access_password()`: успешная ветка ставит cookie и делает `exit`, а
 * `setcookie()` в CLI — no-op. Позитивный сценарий иначе нечем проверить.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Maintenance_Nonce
 */
class Test_Maintenance_Nonce extends WP_UnitTestCase {

	/**
	 * Пароль доступа, выставленный в опции.
	 */
	const PASSWORD = 'super-secret-pass';

	/**
	 * Имя поля с паролем в форме заглушки.
	 */
	const PASS_FIELD = 'idpg_maintenance_pass';

	/**
	 * Подготовка: пароль доступа задан, POST очищен.
	 */
	public function set_up() {
		parent::set_up();

		update_option( 'idpg_maintenance_password', self::PASSWORD );

		$_POST = array();
	}

	/**
	 * Очистка: опция и POST возвращены в исходное состояние.
	 */
	public function tear_down() {
		$_POST = array();

		delete_option( 'idpg_maintenance_password' );

		parent::tear_down();
	}

	/**
	 * Вызов приватного метода решения — в тестах это единственный способ
	 * добраться до логики, не спотыкаясь об `exit` в обработчике.
	 *
	 * @return bool
	 */
	private function is_valid() {
		$method = new ReflectionMethod( 'IDPG_Maintenance', 'is_access_request_valid' );
		$method->setAccessible( true );

		return $method->invoke( IDPG_Maintenance::instance() );
	}

	/**
	 * Заполнить POST как это делает браузер с формой заглушки.
	 *
	 * @param string|null $password Значение поля пароля; null — не передавать.
	 * @param string|null $nonce    Значение поля nonce; null — не передавать.
	 */
	private function submit( $password, $nonce ) {
		$_POST = array();

		if ( null !== $password ) {
			$_POST[ self::PASS_FIELD ] = $password;
		}

		if ( null !== $nonce ) {
			$_POST[ IDPG_Maintenance::ACCESS_NONCE_FIELD ] = $nonce;
		}
	}

	/* ---------------------------------------------------------------------
	 * Приёмка DEV-151
	 * ------------------------------------------------------------------ */

	/**
	 * Без nonce отказ, даже с верным паролем.
	 *
	 * Главный сценарий DEV-151. Именно его не закрывал поддел: подделав
	 * запрос, злоумышленник знал пароль (или не знал — тогда запрос отвергали
	 * сравнением) и получал cookie доступа без всякой защиты формы.
	 */
	public function test_correct_password_without_nonce_is_rejected() {
		$this->submit( self::PASSWORD, null );

		$this->assertFalse(
			$this->is_valid(),
			'Верный пароль без nonce принят: подделка запроса по-прежнему работает.'
		);
	}

	/**
	 * С невалидным nonce отказ — при верном пароле.
	 */
	public function test_correct_password_with_invalid_nonce_is_rejected() {
		$this->submit( self::PASSWORD, 'not-a-real-nonce' );

		$this->assertFalse(
			$this->is_valid(),
			'Произвольная строка вместо nonce принята.'
		);
	}

	/**
	 * С валидным nonce и верным паролем доступ выдаётся.
	 *
	 * Это тот самый сценарий, ради которого приёмка и требовала теста: правка
	 * не должна была сломать живую форму.
	 */
	public function test_valid_nonce_and_correct_password_grants_access() {
		$this->submit(
			self::PASSWORD,
			wp_create_nonce( IDPG_Maintenance::ACCESS_NONCE_ACTION )
		);

		$this->assertTrue(
			$this->is_valid(),
			'Верный пароль с валидным nonce отвергнут: форма перестала работать.'
		);
	}

	/* ---------------------------------------------------------------------
	 * Порядок проверок
	 * ------------------------------------------------------------------ */

	/**
	 * Nonce проверяется раньше пароля.
	 *
	 * Иначе верный пароль без nonce доходил бы до выдачи cookie, и вся правка
	 * была бы косметикой: подделка с паролем работала бы по-прежнему.
	 */
	public function test_nonce_is_checked_before_password() {
		$this->submit( self::PASSWORD, null );

		$this->assertFalse( $this->is_valid() );

		// Тот же POST с валидным nonce обязан пройти — значит, отказ выше был
		// из-за nonce, а не из-за пароля.
		$this->submit(
			self::PASSWORD,
			wp_create_nonce( IDPG_Maintenance::ACCESS_NONCE_ACTION )
		);

		$this->assertTrue( $this->is_valid() );
	}

	/**
	 * Верный nonce не спасает неверный пароль.
	 */
	public function test_valid_nonce_with_wrong_password_is_rejected() {
		$this->submit(
			'wrong-pass',
			wp_create_nonce( IDPG_Maintenance::ACCESS_NONCE_ACTION )
		);

		$this->assertFalse( $this->is_valid() );
	}

	/**
	 * Пустой пароль в опции — доступ не выдаётся ни при каких POST.
	 */
	public function test_no_password_configured_never_grants_access() {
		delete_option( 'idpg_maintenance_password' );

		$this->submit(
			'',
			wp_create_nonce( IDPG_Maintenance::ACCESS_NONCE_ACTION )
		);

		$this->assertFalse( $this->is_valid() );
	}

	/**
	 * Пустой POST отвергается и не считается попыткой входа.
	 */
	public function test_empty_post_is_rejected() {
		$this->submit( null, null );

		$this->assertFalse( $this->is_valid() );
	}

	/**
	 * Массив вместо nonce не роняет обработчик и не проходит.
	 *
	 * Через браузер такое не пришлёшь, но обработчик вызывается и из кода.
	 * `sanitize_text_field` на массиве вернёт пустую строку — то есть отказ,
	 * а не PHP notice.
	 */
	public function test_array_nonce_is_rejected_without_notice() {
		$_POST = array(
			self::PASS_FIELD                              => self::PASSWORD,
			IDPG_Maintenance::ACCESS_NONCE_FIELD => array( 'x' ),
		);

		$this->assertFalse( $this->is_valid() );
	}

	/* ---------------------------------------------------------------------
	 * Форма
	 * ------------------------------------------------------------------ */

	/**
	 * В форме заглушки есть nonce с тем же action и тем же именем поля.
	 *
	 * Имя и action проверяются по исходнику, а не вызовом `wp_nonce_field()`:
	 * сверяется именно то, что попадёт в HTML, — опечатка в строке action или
	 * в имени поля дала бы вечную ошибку «Неверный пароль» живому человеку.
	 */
	public function test_form_renders_nonce_field() {
		$file = dirname( __DIR__ ) . '/includes/class-maintenance.php';
		$src  = file_get_contents( $file );

		$this->assertStringContainsString(
			'wp_nonce_field( self::ACCESS_NONCE_ACTION, self::ACCESS_NONCE_FIELD )',
			$src,
			'В форме заглушки нет wp_nonce_field().'
		);

		// Поле должно быть внутри <form>, иначе браузер его не отправит.
		$form_pos  = strpos( $src, 'idpg-maintenance__password' );
		$nonce_pos = strpos( $src, 'wp_nonce_field' );
		$form_end  = strpos( $src, '</form>', $form_pos );

		$this->assertNotFalse( $form_pos, 'Форма пароля не найдена.' );
		$this->assertGreaterThan( $form_pos, $nonce_pos, 'Nonce стоит до открытия формы.' );
		$this->assertLessThan( $form_end, $nonce_pos, 'Nonce стоит после закрытия формы.' );

		// Поле пароля тоже должно быть внутри формы.
		$this->assertGreaterThan(
			$form_pos,
			strpos( $src, 'name="idpg_maintenance_pass"' ),
			'Поле пароля оказалось вне формы.'
		);
	}

	/**
	 * Приглушение phpcs снято, а сам phpcs-запрет больше не игнорируется.
	 *
	 * Пока в файле стоял `phpcs:ignore WordPress.Security.NonceVerification`,
	 * проверка была выключена для всего файла — и новая проверка nonce
	 * прошла бы молча, даже если бы её удалили.
	 */
	public function test_nonce_verification_ignore_is_removed() {
		$file = dirname( __DIR__ ) . '/includes/class-maintenance.php';
		$src  = file_get_contents( $file );

		$this->assertStringNotContainsString(
			'phpcs:ignore WordPress.Security.NonceVerification',
			$src,
			'Приглушение phpcs для nonce всё ещё стоит — проверку phpcs не возобновить.'
		);

		// Остальные приглушения не трогаем: они не про nonce.
		$this->assertStringContainsString(
			'phpcs:ignore WordPress.Security.ValidatedSanitizedInput',
			$src,
			'Приглушения по другим правилам пропали — правка не должна была их трогать.'
		);
	}
}
