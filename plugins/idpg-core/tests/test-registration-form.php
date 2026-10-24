<?php
/**
 * Тесты формы регистрации организатора — PHP-fallback, без JavaScript.
 *
 * Зачем: REST-слой покрыт (tests/test-privacy.php), а форма темы и разбор
 * $_POST в page-login.php не были покрыты ничем. Из-за этого 28.09.2026
 * проскочили две ошибки, которые зелёные тесты не видели:
 *
 *   1) `name="<?php echo esc_attr( $idpg_pdn_field ); ?>"` выводился с
 *      PHP-warning, потому что переменная была не в той области видимости;
 *   2) чекбокс согласия стоял в форме ВХОДА, а не в форме регистрации —
 *      регистрация без JS была невозможна в принципе.
 *
 * Тест рендерит настоящий page-login.php из рабочей темы, поэтому ловит
 * именно класс «правка разметки», а не только серверную логику.
 *
 * @package idpg-core
 */

/**
 * Class Test_Registration_Form
 */
class Test_Registration_Form extends WP_UnitTestCase {

	/**
	 * Каталог тем, который был до подключения рабочей темы.
	 *
	 * @var array
	 */
	private static $saved_theme_dirs = array();

	/**
	 * Подключаем рабочий каталог тем, чтобы шаблоны брались из него.
	 *
	 * register_theme_directory() добавляет каталог В КОНЕЦ списка, а
	 * get_theme_root() возвращает первый — поэтому ставим каталог в начало.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		global $wp_theme_directories;
		self::$saved_theme_dirs = $wp_theme_directories;

		$real = dirname( dirname( dirname( __DIR__ ) ) ) . '/themes';

		if ( ! is_dir( $real ) ) {
			return;
		}

		$wp_theme_directories = array_values(
			array_unique( array_merge( array( $real ), (array) $wp_theme_directories ) )
		);
	}

	/**
	 * Возвращаем исходный список каталогов тем.
	 */
	public static function tearDownAfterClass(): void {
		global $wp_theme_directories;
		$wp_theme_directories = self::$saved_theme_dirs;

		parent::tearDownAfterClass();
	}

	/**
	 * Подготовка: реальная тема активна, POST чист, лимит регистраций сброшен.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! is_dir( $this->real_theme_dir() ) ) {
			$this->markTestSkipped( 'Рабочий каталог тем не найден рядом с плагином.' );
		}

		wp_clean_themes_cache();
		update_option( 'template', 'idpg_core' );
		update_option( 'stylesheet', 'idpg_core' );
		wp_clean_themes_cache();

		// Шаблон должен быть доступен именно из рабочей темы.
		if ( ! $this->has_real_theme() ) {
			$this->markTestSkipped( 'Рабочая тема не подхватилась: page-login.php не найден.' );
		}

		// Тестовая установка WordPress неполна: нет block-library/common.css
		// и js/wp-emoji-loader.js. На рендер разметки формы это не влияет,
		// поэтому отключаем именно эти хуки, а не глушим проверки целиком.
		$this->setExpectedIncorrectUsage( 'wp_maybe_inline_styles' );
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_footer_scripts', 'print_emoji_detection_script', 20 );

		$_GET                      = array();
		$_POST                     = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		wp_set_current_user( 0 );

		$this->reset_registration_rate_limit();
	}

	/**
	 * Каталог рабочих тем: wp-content/themes рядом с плагином.
	 *
	 * @return string
	 */
	private function real_theme_dir() {
		return dirname( dirname( dirname( __DIR__ ) ) ) . '/themes';
	}

	/**
	 * Рабочая тема доступна для рендера?
	 *
	 * @return bool
	 */
	private function has_real_theme() {
		return file_exists( get_template_directory() . '/page-login.php' );
	}

	/**
	 * Сбрасывает счётчик попыток регистрации — иначе тесты упираются в лимит 3/час.
	 */
	private function reset_registration_rate_limit() {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_idpg_reg_attempts_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	}

	/**
	 * Рендерит page-login.php и отдаёт HTML.
	 *
	 * @param array $post   Данные $_POST.
	 * @param string $method HTTP-метод.
	 * @return string
	 */
	private function render_login_page( array $post = array(), $method = 'GET' ) {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_POST                     = $post;
		$_REQUEST                  = $post;

		ob_start();
		try {
			include get_template_directory() . '/page-login.php';
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}

		return ob_get_clean();
	}

	/**
	 * Вырезает кусок HTML, относящийся к одной панели-форме.
	 *
	 * @param string $html     Весь HTML.
	 * @param string $panel_id id панели.
	 * @return string
	 */
	private function form_region( $html, $panel_id ) {
		$start = strpos( $html, 'id="' . $panel_id . '"' );

		if ( false === $start ) {
			return '';
		}

		$end = strpos( $html, '</form>', $start );

		if ( false === $end ) {
			return '';
		}

		return substr( $html, $start, $end - $start );
	}

	/**
	 * Корректный набор полей регистрации.
	 *
	 * @param array $override Что заменить.
	 * @return array
	 */
	private function registration_post( array $override = array() ) {
		return array_merge(
			array(
				'org_name'         => 'ООО Ромашка',
				'contact_name'     => 'Иванов Иван',
				'phone'            => '+7 999 000-00-00',
				'email'            => 'org-' . wp_generate_password( 10, false ) . '@ex.test',
				'password'         => 'super-secret-1',
				'password_confirm' => 'super-secret-1',
				'terms'            => '1',
				'idpg_pdn_consent' => '1',
				// Общий CSRF-гейт страницы (page-login.php:136) — без него
				// блокируется вся обработка POST, включая регистрацию.
				'idpg_login_nonce' => wp_create_nonce( 'idpg_login' ),
			),
			$override
		);
	}

	/* ---------------------------------------------------------------------
	 * Разметка формы
	 * ------------------------------------------------------------------ */

	/**
	 * Чекбокс согласия на ПДн обязан быть в форме РЕГИСТРАЦИИ.
	 *
	 * Регрессия: блок стоял в форме входа, и регистрация без JS была
	 * невозможна — сервер требовал поле, которого в форме не было.
	 */
	public function test_consent_checkbox_is_inside_registration_form() {
		$html  = $this->render_login_page();
		$panel = $this->form_region( $html, 'register-organizer-form' );

		$this->assertNotSame( '', $panel, 'Панель регистрации не отрисована.' );
		$this->assertStringContainsString(
			'name="idpg_pdn_consent"',
			$panel,
			'В форме регистрации нет чекбокса согласия на обработку ПДн.'
		);
	}

	/**
	 * В форме входа согласие на ПДн спрашивать не о чем.
	 *
	 * Обработчик входа (page-login.php) поле не читает и запись согласия
	 * не делает, поэтому `required` на форме входа был требованием галочки,
	 * которая ничего не значит, — и блокировала отправку входа в браузере.
	 */
	public function test_login_form_has_no_pdn_consent_field() {
		$html  = $this->render_login_page();
		$panel = $this->form_region( $html, 'login-form' );

		$this->assertNotSame( '', $panel, 'Панель входа не отрисована.' );
		$this->assertStringNotContainsString(
			'idpg_pdn_consent',
			$panel,
			'Форма входа не должна требовать согласие на обработку ПДн.'
		);
	}

	/**
	 * Согласие на оферту и согласие на ПДн — два разных поля.
	 */
	public function test_offer_and_pdn_consents_are_separate_fields() {
		$panel = $this->form_region( $this->render_login_page(), 'register-organizer-form' );

		$this->assertStringContainsString( 'name="terms"', $panel, 'Нет чекбокса оферты.' );
		$this->assertStringContainsString(
			'name="idpg_pdn_consent"',
			$panel,
			'Нет отдельного чекбокса согласия на ПДн.'
		);
	}

	/**
	 * Имя поля выводится точно, без артефактов PHP.
	 *
	 * Регрессия: переменная была определена не в той области видимости, и
	 * в `name` попадал warning вместе с мусором.
	 */
	public function test_consent_field_name_is_exact_and_escaped() {
		$html = $this->render_login_page();

		$this->assertStringContainsString( 'name="idpg_pdn_consent"', $html );
		$this->assertStringNotContainsString( 'name=""', $html, 'Пустое имя поля в разметке.' );
		$this->assertStringNotContainsString( 'name="&', $html, 'Имя поля закодировано — артефакт вывода.' );
	}

	/**
	 * В отрендеренной странице нет PHP-диагностик.
	 *
	 * Именно так проявлялся warning из регрессии с именем поля.
	 */
	public function test_rendered_page_has_no_php_diagnostics() {
		$html = $this->render_login_page();

		foreach ( array( 'Warning:', 'Notice:', 'Deprecated:', 'Fatal error:', 'Parse error:' ) as $needle ) {
			$this->assertStringNotContainsString(
				$needle,
				$html,
				'В разметку просочилась PHP-диагностика: ' . $needle
			);
		}
	}

	/**
	 * Форма регистрации обязана содержать nonce: проверка в строке 136
	 * страницы гейтит весь POST, и без поля регистрация не выполняется вовсе.
	 */
	public function test_registration_form_contains_nonce_field() {
		$panel = $this->form_region( $this->render_login_page(), 'register-organizer-form' );

		$this->assertStringContainsString(
			'name="idpg_login_nonce"',
			$panel,
			'В форме регистрации нет nonce-поля, а проверка на строке 136 гейтит весь POST.'
		);
	}

	/**
	 * Без валидного nonce регистрация не проходит.
	 */
	public function test_post_without_nonce_creates_no_user() {
		$post = $this->registration_post( array( 'idpg_login_nonce' => 'bogus' ) );

		$this->render_login_page( $post, 'POST' );

		$this->assertFalse( email_exists( $post['email'] ), 'Без nonce пользователь создан.' );
	}

	/* ---------------------------------------------------------------------
	 * Разбор $_POST — серверная проверка согласия
	 * ------------------------------------------------------------------ */

	/**
	 * Без согласия на ПДн аккаунт не создаётся.
	 */
	public function test_post_without_pdn_consent_creates_no_user() {
		$post = $this->registration_post();
		unset( $post['idpg_pdn_consent'] );

		$this->render_login_page( $post, 'POST' );

		$this->assertFalse(
			email_exists( $post['email'] ),
			'Без согласия на ПДн пользователь всё равно создан.'
		);
	}

	/**
	 * Без согласия показывается именно ошибка про ПДн, а не молчание.
	 */
	public function test_post_without_pdn_consent_explains_why() {
		$post = $this->registration_post();
		unset( $post['idpg_pdn_consent'] );

		$html = $this->render_login_page( $post, 'POST' );

		$this->assertStringContainsString(
			'персональных данных',
			$html,
			'Не показана причина отказа по согласию на ПДн.'
		);
	}

	/**
	 * Согласие с офертой не заменяет согласие на ПДн.
	 */
	public function test_offer_consent_alone_is_not_enough() {
		$post = $this->registration_post();
		unset( $post['idpg_pdn_consent'] );

		$this->render_login_page( $post, 'POST' );

		$this->assertFalse( email_exists( $post['email'] ) );
	}

	/**
	 * Согласие на ПДн под другим именем поля не засчитывается.
	 */
	public function test_wrong_field_name_does_not_count_as_consent() {
		$post = $this->registration_post();
		unset( $post['idpg_pdn_consent'] );
		$post['idpg_pdn_agreement'] = '1';

		$this->render_login_page( $post, 'POST' );

		$this->assertFalse(
			email_exists( $post['email'] ),
			'Согласие под чужим именем поля было засчитано.'
		);
	}

	/**
	 * С согласием аккаунт создаётся.
	 */
	public function test_post_with_pdn_consent_creates_user() {
		$post = $this->registration_post();

		$this->render_login_page( $post, 'POST' );

		$user = get_user_by( 'email', $post['email'] );

		$this->assertNotFalse( $user, 'Пользователь не создан при согласии на ПДн.' );
		$this->assertSame( 'subscriber', implode( ',', $user->roles ), 'Роль до подтверждения не subscriber.' );
	}

	/**
	 * Факт согласия фиксируется в метаданных.
	 */
	public function test_consent_is_recorded_with_organizer_source() {
		if ( ! class_exists( 'IDPG_Privacy' ) ) {
			$this->markTestSkipped( 'IDPG_Privacy вынесен в плагин idpg-city-admin (DEV-271).' );
		}

		$post = $this->registration_post();

		$this->render_login_page( $post, 'POST' );

		$user = get_user_by( 'email', $post['email'] );

		$this->assertNotFalse( $user );
		$this->assertTrue( IDPG_Privacy::has_consent( $user->ID ), 'Запись согласия не создана.' );

		$consent = IDPG_Privacy::get_consent( $user->ID );

		$this->assertSame( 'organizer', $consent['source'] );
		$this->assertSame( IDPG_Privacy::CONSENT_VERSION, $consent['version'] );
		$this->assertNotEmpty( $consent['given'], 'В записи согласия нет даты.' );
		$this->assertArrayHasKey( 'ip_hash', $consent, 'В записи согласия нет хэша IP.' );
		$this->assertNotEmpty( $consent['ip_hash'] );
	}

	/**
	 * Аккаунт ждёт подтверждения по ссылке из письма.
	 */
	public function test_user_waits_for_email_confirmation() {
		$post = $this->registration_post();

		$this->render_login_page( $post, 'POST' );

		$user = get_user_by( 'email', $post['email'] );

		$this->assertNotFalse( $user );
		$this->assertNotEmpty( get_user_meta( $user->ID, 'idpg_org_pending', true ), 'Нет флага ожидания подтверждения.' );
		$this->assertTrue( user_can( $user, 'read' ) && ! user_can( $user, 'edit_posts' ) );
	}
}
