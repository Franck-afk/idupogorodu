<?php
/**
 * Персональные данные: согласие на обработку, cookie-баннер и права субъекта.
 *
 * Модуль закрывает требования 152-ФЗ в согласованном для проекта объёме:
 *
 *   1. Отдельное согласие на обработку персональных данных при регистрации —
 *      обязательный чекбокс в формах (REST и PHP-fallback без JS), серверная
 *      проверка и запись факта согласия в user meta (кто, когда, редакция
 *      документов, источник).
 *   2. Cookie-баннер с сохранением выбора: работает без JavaScript (обычный
 *      POST-формы) и с ним (мгновенное скрытие). Выбор хранится в cookie
 *      `idpg_cookie_consent` со сроком на год.
 *   3. Инструменты прав субъекта: экспорт данных пользователя (JSON/CSV),
 *      запрос на удаление из личного кабинета и обработка запросов админом
 *      на странице «Иду по городу → Конфиденциальность».
 *
 * Хранение согласий сделано в user meta, без отдельной таблицы: для объёма
 * проекта этого достаточно, а выгрузка субъекта автоматически включает запись
 * о согласии. Журнал действий администратора (экспорт/удаление) хранится
 * в опции `idpg_privacy_log` с ограничением по количеству записей.
 *
 * @package IDPG_Core
 */

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Privacy
 */
class IDPG_Privacy {

	/**
	 * Синглтон.
	 *
	 * @var IDPG_Privacy|null
	 */
	private static $instance = null;

	/**
	 * Slug страницы в админке.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'idpg-privacy';

	/**
	 * Родительское меню — единая страница плагина «Иду по городу».
	 *
	 * @var string
	 */
	const PARENT_SLUG = 'idpg-settings';

	/**
	 * Имя поля чекбокса согласия на обработку ПДн в формах регистрации.
	 *
	 * @var string
	 */
	const CONSENT_FIELD = 'idpg_pdn_consent';

	/**
	 * Ключ user meta с записью о согласии.
	 *
	 * @var string
	 */
	const META_CONSENT = 'idpg_pdn_consent';

	/**
	 * Ключ user meta с датой запроса субъекта на удаление данных.
	 *
	 * @var string
	 */
	const META_DELETE_REQUEST = 'idpg_delete_requested_at';

	/**
	 * Имя cookie с выбором в баннере.
	 *
	 * @var string
	 */
	const COOKIE_NAME = 'idpg_cookie_consent';

	/**
	 * Срок жизни cookie выбора — один год.
	 *
	 * @var int
	 */
	const COOKIE_TTL = YEAR_IN_SECONDS;

	/**
	 * Nonce-действие формы cookie-баннера.
	 *
	 * @var string
	 */
	const BANNER_NONCE = 'idpg_cookie_choice';

	/**
	 * Nonce-действие формы запроса на удаление (фронтенд).
	 *
	 * @var string
	 */
	const REQUEST_NONCE = 'idpg_privacy_request';

	/**
	 * Текущая редакция документов (пишется в журнал согласий).
	 *
	 * @var string
	 */
	const CONSENT_VERSION = '2026-09-15';

	/**
	 * Допустимые варианты выбора в баннере.
	 *
	 * @var array
	 */
	const CHOICES = array( 'all', 'necessary' );

	/**
	 * Ключи user meta, которые не попадают в выгрузку субъекта.
	 *
	 * Токены активных сессий — это учётные данные доступа, а не персональные
	 * данные пользователя; их выгрузка создала бы риск компрометации.
	 *
	 * @var array
	 */
	const EXPORT_META_SKIP = array( 'session_tokens' );

	/**
	 * Опция с журналом действий администратора.
	 *
	 * @var string
	 */
	const LOG_OPTION = 'idpg_privacy_log';

	/**
	 * Максимальное число записей журнала.
	 *
	 * @var int
	 */
	const LOG_LIMIT = 200;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Privacy
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Приватный конструктор. Навешивает хуки модуля.
	 */
	private function __construct() {
		// Cookie-баннер.
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_banner' ) );
		add_action( 'template_redirect', array( $this, 'handle_banner_submit' ) );

		// Запрос субъекта на удаление данных (фронтенд).
		add_action( 'template_redirect', array( $this, 'handle_subject_request' ) );

		// Самостоятельная выгрузка своих данных авторизованным пользователем.
		add_action( 'admin_post_idpg_privacy_self_export', array( $this, 'handle_self_export' ) );

		// Админ-страница.
		// Приоритет 20, а не 10: родительский пункт меню «idpg-settings»
		// регистрирует IDPG_Settings_Page на приоритете 10. Если добавить
		// подстраницу раньше, add_submenu_page() ещё не видит родителя,
		// вычисляет имя хука как admin_page_idpg-privacy вместо
		// idu-po-gorodu_page_idpg-privacy и WordPress отвечает 403
		// «Sorry, you are not allowed to access this page.».
		add_action( 'admin_menu', array( $this, 'register_page' ), 20 );
		add_action( 'admin_post_idpg_privacy_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_idpg_privacy_preview', array( $this, 'handle_delete_preview' ) );
		add_action( 'admin_post_idpg_privacy_delete', array( $this, 'handle_delete' ) );
	}

	// =========================================================================
	// Согласие на обработку персональных данных
	// =========================================================================

	/**
	 * Записывает факт согласия на обработку персональных данных.
	 *
	 * @param int    $user_id ID пользователя.
	 * @param string $source  Источник согласия: register|organizer|manual.
	 * @return void
	 */
	public static function record_consent( $user_id, $source = 'register' ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return;
		}

		$record = array(
			'given'   => current_time( 'mysql' ),
			'version' => self::instance()->get_consent_version(),
			'source'  => sanitize_key( $source ),
			'ip_hash' => self::instance()->hash_client_ip(),
		);

		/**
		 * Фильтр: запись о согласии на обработку ПДн перед сохранением.
		 *
		 * @param array $record  Запись о согласии.
		 * @param int   $user_id ID пользователя.
		 */
		$record = apply_filters( 'idpg_privacy_consent_record', $record, $user_id );

		update_user_meta( $user_id, self::META_CONSENT, $record );
	}

	/**
	 * Проверяет, дано ли пользователем согласие на обработку ПДн.
	 *
	 * @param int $user_id ID пользователя.
	 * @return bool
	 */
	public static function has_consent( $user_id ) {
		$record = get_user_meta( (int) $user_id, self::META_CONSENT, true );

		return ( is_array( $record ) && ! empty( $record['given'] ) );
	}

	/**
	 * Возвращает запись о согласии пользователя.
	 *
	 * @param int $user_id ID пользователя.
	 * @return array Пустой массив, если согласия нет.
	 */
	public static function get_consent( $user_id ) {
		$record = get_user_meta( (int) $user_id, self::META_CONSENT, true );

		return is_array( $record ) ? $record : array();
	}

	/**
	 * Текущая редакция документов для журнала согласий.
	 *
	 * @return string
	 */
	public function get_consent_version() {
		/**
		 * Фильтр: редакция документов, фиксируемая в согласии.
		 *
		 * @param string $version Редакция.
		 */
		return (string) apply_filters( 'idpg_privacy_consent_version', self::CONSENT_VERSION );
	}

	/**
	 * Хэш IP-адреса клиента для журнала согласий.
	 *
	 * Полный IP не храним: для подтверждения факта согласия достаточно
	 * необратимого хэша с солью сайта.
	 *
	 * @return string
	 */
	private function hash_client_ip() {
		$ip = '';

		if ( class_exists( 'IDPG_Auth_Rate_Limiter' ) ) {
			$ip = (string) IDPG_Auth_Rate_Limiter::instance()->get_client_ip();
		} elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		if ( '' === $ip ) {
			return '';
		}

		return wp_hash( 'idpg_privacy_ip:' . $ip );
	}

	// =========================================================================
	// Cookie-баннер
	// =========================================================================

	/**
	 * Регистрация ассетов баннера на фронтенде.
	 *
	 * @return void
	 */
	public function register_assets() {
		$css_path = IDPG_CORE_DIR . 'assets/css/privacy.css';
		$js_path  = IDPG_CORE_DIR . 'assets/js/privacy.js';

		wp_enqueue_style(
			'idpg-privacy',
			IDPG_CORE_URL . 'assets/css/privacy.css',
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : IDPG_CORE_VERSION
		);

		wp_enqueue_script(
			'idpg-privacy',
			IDPG_CORE_URL . 'assets/js/privacy.js',
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : IDPG_CORE_VERSION,
			true
		);

		wp_localize_script(
			'idpg-privacy',
			'idpgPrivacy',
			array(
				'cookieName' => self::COOKIE_NAME,
				'cookieTtl'  => (int) self::COOKIE_TTL,
				'bannerId'   => 'idpg-cookie-banner',
			)
		);
	}

	/**
	 * Выбор пользователя из cookie баннера.
	 *
	 * @return string 'all' | 'necessary' | '' (выбор не сделан).
	 */
	public function get_banner_choice() {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return '';
		}

		$choice = sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		return in_array( $choice, self::CHOICES, true ) ? $choice : '';
	}

	/**
	 * Сохраняет выбор пользователя в cookie.
	 *
	 * @param string $choice 'all' | 'necessary'.
	 * @return bool True, если cookie установлена.
	 */
	public function set_banner_choice( $choice ) {
		if ( ! in_array( $choice, self::CHOICES, true ) ) {
			return false;
		}

		if ( headers_sent() ) {
			return false;
		}

		return setcookie(
			self::COOKIE_NAME,
			$choice,
			array(
				'expires'  => time() + (int) self::COOKIE_TTL,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Обработка отправки формы баннера без JavaScript.
	 *
	 * Форма баннера отправляется на текущий URL обычным POST. Если nonce и
	 * значение выбора валидны — ставим cookie и возвращаемся на ту же страницу
	 * уже без POST. Хук висит на template_redirect, то есть срабатывает раньше
	 * обработчиков шаблонов страниц (в том числе формы входа).
	 *
	 * @return void
	 */
	public function handle_banner_submit() {
		if ( is_admin() || 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			return;
		}

		if ( ! isset( $_POST['idpg_cookie_choice'] ) ) {
			return;
		}

		if ( ! isset( $_POST['idpg_cookie_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['idpg_cookie_nonce'] ) ), self::BANNER_NONCE ) ) {
			return;
		}

		$choice = sanitize_key( wp_unslash( $_POST['idpg_cookie_choice'] ) );

		if ( ! in_array( $choice, self::CHOICES, true ) ) {
			return;
		}

		$this->set_banner_choice( $choice );

		wp_safe_redirect( home_url( $this->current_request_path() ) );
		exit;
	}

	/**
	 * Выводит cookie-баннер, если выбор ещё не сделан.
	 *
	 * @return void
	 */
	public function render_banner() {
		if ( is_admin() || '' !== $this->get_banner_choice() ) {
			return;
		}

		$privacy_url = $this->get_privacy_page_url();
		?>
		<div class="idpg-cookie" id="idpg-cookie-banner" role="dialog" aria-live="polite"
			aria-label="<?php esc_attr_e( 'Использование cookie', 'idpg-core' ); ?>">
			<form class="idpg-cookie__form" method="post">
				<?php wp_nonce_field( self::BANNER_NONCE, 'idpg_cookie_nonce' ); ?>
				<p class="idpg-cookie__text">
					<?php esc_html_e( 'Мы используем необходимые cookie, чтобы сайт работал: вход в аккаунт, выбранный город, защита форм. Дополнительные cookie — только с вашего согласия.', 'idpg-core' ); ?>
					<a class="idpg-cookie__policy" href="<?php echo esc_url( $privacy_url ); ?>"><?php esc_html_e( 'Политика конфиденциальности', 'idpg-core' ); ?></a>
				</p>
				<div class="idpg-cookie__actions">
					<button class="idpg-cookie__btn idpg-cookie__btn--primary" type="submit"
						name="idpg_cookie_choice" value="all">
						<?php esc_html_e( 'Принять все', 'idpg-core' ); ?>
					</button>
					<button class="idpg-cookie__btn" type="submit"
						name="idpg_cookie_choice" value="necessary">
						<?php esc_html_e( 'Только необходимые', 'idpg-core' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * URL страницы политики конфиденциальности.
	 *
	 * @return string
	 */
	public function get_privacy_page_url() {
		$url  = '';
		$page = get_page_by_path( 'privacy' );

		if ( $page instanceof WP_Post ) {
			$url = (string) get_permalink( $page );
		}

		if ( '' === $url ) {
			$url = home_url( '/privacy/' );
		}

		/**
		 * Фильтр: ссылка на политику конфиденциальности в cookie-баннере.
		 *
		 * @param string $url URL страницы политики.
		 */
		return (string) apply_filters( 'idpg_privacy_page_url', $url );
	}

	/**
	 * Путь текущего запроса (без query-строки).
	 *
	 * @return string
	 */
	private function current_request_path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '/';
		}

		$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		return ( is_string( $path ) && '' !== $path ) ? $path : '/';
	}

	// =========================================================================
	// Права субъекта: запрос на удаление из личного кабинета
	// =========================================================================

	/**
	 * Обработка запроса субъекта на удаление данных.
	 *
	 * Доступно только авторизованным. Запрос не удаляет данные сразу: он
	 * фиксируется в user meta и уходит письмом администратору, а удаление
	 * выполняет админ на странице «Конфиденциальность» (с подтверждением).
	 *
	 * @return void
	 */
	public function handle_subject_request() {
		if ( is_admin() || 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			return;
		}

		if ( ! isset( $_POST['idpg_privacy_request'] ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( ! isset( $_POST['idpg_privacy_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['idpg_privacy_nonce'] ) ), self::REQUEST_NONCE ) ) {
			return;
		}

		// Подтверждение запроса. В форме чекбокс помечен required, но без
		// JavaScript браузер его не проверит, а отправку можно подделать —
		// поэтому проверяем на сервере, как и в handle_delete().
		$confirm = ( isset( $_POST['idpg_privacy_confirm'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['idpg_privacy_confirm'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- nonce проверен выше.

		if ( ! $confirm ) {
			wp_safe_redirect(
				add_query_arg( 'idpg_request', 'confirm', home_url( $this->current_request_path() ) )
			);
			exit;
		}

		$user_id = get_current_user_id();

		update_user_meta( $user_id, self::META_DELETE_REQUEST, current_time( 'mysql' ) );

		$this->notify_admin_about_request( $user_id );

		/**
		 * Действие после запроса субъекта на удаление данных.
		 *
		 * @param int $user_id ID пользователя, подавшего запрос.
		 */
		do_action( 'idpg_privacy_delete_requested', $user_id );

		wp_safe_redirect(
			add_query_arg( 'idpg_request', 'ok', home_url( $this->current_request_path() ) )
		);
		exit;
	}

	/**
	 * Письмо администратору о запросе на удаление данных.
	 *
	 * @param int $user_id ID пользователя.
	 * @return void
	 */
	private function notify_admin_about_request( $user_id ) {
		$user = get_userdata( (int) $user_id );

		if ( ! $user ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Запрос на удаление персональных данных', 'idpg-core' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$lines = array(
			__( 'Пользователь запросил удаление своих персональных данных.', 'idpg-core' ),
			'',
			sprintf(
				/* translators: %s: user login */
				__( 'Логин: %s', 'idpg-core' ),
				$user->user_login
			),
			sprintf(
				/* translators: %s: user email */
				__( 'Email: %s', 'idpg-core' ),
				$user->user_email
			),
			'',
			sprintf(
				/* translators: %s: admin page URL */
				__( 'Обработать запрос: %s', 'idpg-core' ),
				$this->page_url()
			),
		);

		wp_mail(
			(string) get_option( 'admin_email' ),
			$subject,
			implode( "\n", $lines )
		);
	}

	/**
	 * Блок «Ваши права» на странице политики конфиденциальности.
	 *
	 * Выводит статус согласия, ссылку на самостоятельную выгрузку данных и
	 * форму запроса на удаление. Вызывается из шаблона темы.
	 *
	 * @return void
	 */
	public function render_subject_tools() {
		$requested = false;

		if ( is_user_logged_in() ) {
			$requested = (bool) get_user_meta( get_current_user_id(), self::META_DELETE_REQUEST, true );
		}

		echo '<section class="idpg-privacy-tools">';
		echo '<h2 class="idpg-privacy-tools__title">' . esc_html__( 'Ваши права на персональные данные', 'idpg-core' ) . '</h2>';

		if ( ! is_user_logged_in() ) {
			echo '<p class="idpg-privacy-tools__text">' . esc_html__( 'Вы можете запросить выгрузку или удаление своих данных, а также отозвать согласие на обработку. Для этого войдите в аккаунт.', 'idpg-core' ) . '</p>';
			echo '<p><a class="idpg-privacy-tools__link" href="' . esc_url( home_url( '/login/' ) ) . '">' . esc_html__( 'Войти в аккаунт', 'idpg-core' ) . '</a></p>';
			echo '</section>';

			return;
		}

		echo '<p class="idpg-privacy-tools__text">' . esc_html__( 'Вы можете в любой момент получить копию своих данных или запросить их удаление.', 'idpg-core' ) . '</p>';

		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=idpg_privacy_self_export' ),
			'idpg_privacy_self_export'
		);

		echo '<p><a class="idpg-privacy-tools__link" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Скачать мои данные (JSON)', 'idpg-core' ) . '</a></p>';

		if ( $requested ) {
			echo '<p class="idpg-privacy-tools__note">' . esc_html__( 'Запрос на удаление данных уже отправлен и ожидает обработки администратором.', 'idpg-core' ) . '</p>';
			echo '</section>';

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification -- не действие, а флаг для показа уведомления после редиректа.
		if ( isset( $_GET['idpg_request'] ) && 'ok' === sanitize_key( wp_unslash( $_GET['idpg_request'] ) ) ) {
			echo '<p class="idpg-privacy-tools__note idpg-privacy-tools__note--ok">' . esc_html__( 'Запрос принят. Администратор обработает его и свяжется с вами.', 'idpg-core' ) . '</p>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification -- не действие, а флаг для показа уведомления после редиректа.
		if ( isset( $_GET['idpg_request'] ) && 'confirm' === sanitize_key( wp_unslash( $_GET['idpg_request'] ) ) ) {
			echo '<p class="idpg-privacy-tools__note idpg-privacy-tools__note--error">' . esc_html__( 'Отметьте подтверждение запроса, чтобы отправить его.', 'idpg-core' ) . '</p>';
		}

		?>
		<form class="idpg-privacy-tools__form" method="post">
			<?php wp_nonce_field( self::REQUEST_NONCE, 'idpg_privacy_nonce' ); ?>
			<input type="hidden" name="idpg_privacy_request" value="1">
			<label class="idpg-privacy-tools__checkbox">
				<input type="checkbox" name="idpg_privacy_confirm" value="1" required>
				<span><?php esc_html_e( 'Подтверждаю запрос на удаление моих персональных данных и аккаунта', 'idpg-core' ); ?></span>
			</label>
			<button class="idpg-privacy-tools__submit" type="submit"><?php esc_html_e( 'Запросить удаление данных', 'idpg-core' ); ?></button>
		</form>
		<?php

		echo '</section>';
	}

	// =========================================================================
	// Админ-страница «Конфиденциальность»
	// =========================================================================

	/**
	 * Регистрация подстраницы в меню плагина.
	 *
	 * @return void
	 */
	public function register_page() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Конфиденциальность', 'idpg-core' ),
			__( 'Конфиденциальность', 'idpg-core' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * URL админ-страницы модуля.
	 *
	 * @param array $args Дополнительные query-параметры.
	 * @return string
	 */
	private function page_url( $args = array() ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		return empty( $args ) ? $url : add_query_arg( $args, $url );
	}

	/**
	 * Рендер админ-страницы.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав для просмотра этой страницы.', 'idpg-core' ) );
		}

		$msg = isset( $_GET['idpg_msg'] ) ? sanitize_key( wp_unslash( $_GET['idpg_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- только вывод уведомления.

		echo '<div class="wrap idpg-privacy-admin">';
		echo '<h1>' . esc_html__( 'Конфиденциальность и персональные данные', 'idpg-core' ) . '</h1>';

		$this->render_notice( $msg );
		$this->render_requests_section();
		$this->render_export_section();
		$this->render_delete_section();
		$this->render_log_section();

		echo '</div>';
	}

	/**
	 * Уведомление о результате действия.
	 *
	 * @param string $msg Ключ сообщения.
	 * @return void
	 */
	private function render_notice( $msg ) {
		$map = array(
			'exported'         => array( 'success', __( 'Выгрузка сформирована.', 'idpg-core' ) ),
			'deleted'          => array( 'success', __( 'Данные пользователя удалены.', 'idpg-core' ) ),
			'delete_failed'    => array( 'error', __( 'Не удалось удалить пользователя. Проверьте журнал.', 'idpg-core' ) ),
			'not_found'        => array( 'error', __( 'Пользователь с такими данными не найден.', 'idpg-core' ) ),
			'empty_user'       => array( 'error', __( 'Укажите email или ID пользователя.', 'idpg-core' ) ),
			'confirm_required' => array( 'error', __( 'Удаление требует явного подтверждения.', 'idpg-core' ) ),
			'admin_protected'  => array( 'error', __( 'Нельзя удалить администратора сайта.', 'idpg-core' ) ),
			'self_protected'   => array( 'error', __( 'Нельзя удалить собственную учётную запись.', 'idpg-core' ) ),
		);

		if ( '' === $msg || ! isset( $map[ $msg ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $map[ $msg ][0] ),
			esc_html( $map[ $msg ][1] )
		);
	}

	/**
	 * Раздел с запросами субъектов на удаление.
	 *
	 * @return void
	 */
	private function render_requests_section() {
		$requests = get_users(
			array(
				'meta_key'     => self::META_DELETE_REQUEST, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_compare' => 'EXISTS',
				'number'       => 50,
				'orderby'      => 'registered',
				'order'        => 'DESC',
			)
		);

		echo '<h2>' . esc_html__( 'Запросы на удаление данных', 'idpg-core' ) . '</h2>';

		if ( empty( $requests ) ) {
			echo '<p>' . esc_html__( 'Новых запросов нет.', 'idpg-core' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Пользователь', 'idpg-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Email', 'idpg-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Дата запроса', 'idpg-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Действие', 'idpg-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $requests as $user ) {
			$requested_at = (string) get_user_meta( $user->ID, self::META_DELETE_REQUEST, true );

			echo '<tr>';
			echo '<td>' . esc_html( $user->user_login ) . ' <span class="description">(ID ' . (int) $user->ID . ')</span></td>';
			echo '<td>' . esc_html( $user->user_email ) . '</td>';
			echo '<td>' . esc_html( $requested_at ) . '</td>';
			echo '<td><a class="button button-secondary" href="' . esc_url( $this->page_url( array( 'idpg_preview' => (int) $user->ID ) ) ) . '">' . esc_html__( 'Обработать', 'idpg-core' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Раздел выгрузки данных субъекта.
	 *
	 * @return void
	 */
	private function render_export_section() {
		echo '<h2>' . esc_html__( 'Выгрузка данных субъекта', 'idpg-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'Скачайте все персональные данные пользователя в формате JSON или CSV (для передачи субъекту по его запросу).', 'idpg-core' ) . '</p>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'idpg_privacy_export' ); ?>
			<input type="hidden" name="action" value="idpg_privacy_export">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="idpg-export-user"><?php esc_html_e( 'Email, логин или ID', 'idpg-core' ); ?></label></th>
					<td><input name="idpg_user" id="idpg-export-user" type="text" class="regular-text" required></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Формат', 'idpg-core' ); ?></th>
					<td>
						<label><input type="radio" name="idpg_format" value="json" checked> JSON</label>
						&nbsp;&nbsp;
						<label><input type="radio" name="idpg_format" value="csv"> CSV</label>
					</td>
				</tr>
			</table>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Скачать выгрузку', 'idpg-core' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Раздел удаления данных пользователя.
	 *
	 * Показывает либо форму поиска, либо (после шага предпросмотра) сводку
	 * по пользователю и форму подтверждения.
	 *
	 * @return void
	 */
	private function render_delete_section() {
		echo '<h2>' . esc_html__( 'Удаление данных по запросу', 'idpg-core' ) . '</h2>';

		$preview_id = isset( $_GET['idpg_preview'] ) ? absint( wp_unslash( $_GET['idpg_preview'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- только предпросмотр, удаление защищено отдельным nonce.

		if ( $preview_id > 0 ) {
			$this->render_delete_preview( $preview_id );

			return;
		}

		echo '<p>' . esc_html__( 'Удаление необратимо: вместе с аккаунтом удаляются все метаданные пользователя и его записи (события, площадки, вложения).', 'idpg-core' ) . '</p>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'idpg_privacy_preview' ); ?>
			<input type="hidden" name="action" value="idpg_privacy_preview">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="idpg-delete-user"><?php esc_html_e( 'Email, логин или ID', 'idpg-core' ); ?></label></th>
					<td><input name="idpg_user" id="idpg-delete-user" type="text" class="regular-text" required></td>
				</tr>
			</table>
			<p><button type="submit" class="button"><?php esc_html_e( 'Проверить и подтвердить', 'idpg-core' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Сводка по пользователю и форма подтверждения удаления.
	 *
	 * @param int $user_id ID пользователя.
	 * @return void
	 */
	private function render_delete_preview( $user_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Пользователь не найден.', 'idpg-core' ) . '</p></div>';

			return;
		}

		$content   = $this->collect_content( $user_id );
		$consent   = self::get_consent( $user_id );
		$requested = (string) get_user_meta( $user_id, self::META_DELETE_REQUEST, true );

		$blocked = '';
		if ( user_can( $user_id, 'manage_options' ) ) {
			$blocked = __( 'Это администратор сайта — удаление запрещено.', 'idpg-core' );
		} elseif ( get_current_user_id() === $user_id ) {
			$blocked = __( 'Это ваша собственная учётная запись — удаление запрещено.', 'idpg-core' );
		}

		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Проверьте данные перед удалением. Действие необратимо.', 'idpg-core' ) . '</p></div>';

		echo '<table class="widefat striped" style="max-width:820px"><tbody>';
		echo '<tr><th style="width:220px">' . esc_html__( 'Логин', 'idpg-core' ) . '</th><td>' . esc_html( $user->user_login ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Email', 'idpg-core' ) . '</th><td>' . esc_html( $user->user_email ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Отображаемое имя', 'idpg-core' ) . '</th><td>' . esc_html( $user->display_name ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Роли', 'idpg-core' ) . '</th><td>' . esc_html( implode( ', ', (array) $user->roles ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Дата регистрации', 'idpg-core' ) . '</th><td>' . esc_html( $user->user_registered ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Записей автора', 'idpg-core' ) . '</th><td>' . (int) count( $content ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Согласие на обработку ПДн', 'idpg-core' ) . '</th><td>' . esc_html( empty( $consent['given'] ) ? __( 'нет данных', 'idpg-core' ) : (string) $consent['given'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Запрос субъекта', 'idpg-core' ) . '</th><td>' . esc_html( '' === $requested ? __( 'не подавал', 'idpg-core' ) : $requested ) . '</td></tr>';
		echo '</tbody></table>';

		if ( '' !== $blocked ) {
			echo '<p><strong>' . esc_html( $blocked ) . '</strong></p>';
			echo '<p><a class="button" href="' . esc_url( $this->page_url() ) . '">' . esc_html__( 'Назад', 'idpg-core' ) . '</a></p>';

			return;
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
			<?php wp_nonce_field( 'idpg_privacy_delete' ); ?>
			<input type="hidden" name="action" value="idpg_privacy_delete">
			<input type="hidden" name="idpg_user_id" value="<?php echo (int) $user_id; ?>">
			<label>
				<input type="checkbox" name="idpg_confirm" value="1" required>
				<?php esc_html_e( 'Подтверждаю удаление аккаунта и всех связанных данных', 'idpg-core' ); ?>
			</label>
			<p>
				<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Удалить безвозвратно', 'idpg-core' ); ?></button>
				<a class="button" href="<?php echo esc_url( $this->page_url() ); ?>"><?php esc_html_e( 'Отмена', 'idpg-core' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * Раздел журнала действий администратора.
	 *
	 * @return void
	 */
	private function render_log_section() {
		$log = $this->get_log();

		echo '<h2>' . esc_html__( 'Журнал действий', 'idpg-core' ) . '</h2>';

		if ( empty( $log ) ) {
			echo '<p>' . esc_html__( 'Записей нет.', 'idpg-core' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Дата', 'idpg-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Действие', 'idpg-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Субъект', 'idpg-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Выполнил', 'idpg-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( array_slice( $log, 0, 30 ) as $entry ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $entry['time'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $entry['action'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $entry['subject'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $entry['actor'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	// =========================================================================
	// Обработчики admin-post
	// =========================================================================

	/**
	 * Шаг предпросмотра перед удалением.
	 *
	 * @return void
	 */
	public function handle_delete_preview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'idpg-core' ), 403 );
		}

		check_admin_referer( 'idpg_privacy_preview' );

		$input = isset( $_POST['idpg_user'] ) ? sanitize_text_field( wp_unslash( $_POST['idpg_user'] ) ) : '';
		$user  = $this->find_user( $input );

		if ( ! $user ) {
			wp_safe_redirect( $this->page_url( array( 'idpg_msg' => 'not_found' ) ) );
			exit;
		}

		wp_safe_redirect( $this->page_url( array( 'idpg_preview' => (int) $user->ID ) ) );
		exit;
	}

	/**
	 * Удаление данных пользователя по подтверждённому запросу.
	 *
	 * @return void
	 */
	public function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'idpg-core' ), 403 );
		}

		check_admin_referer( 'idpg_privacy_delete' );

		$user_id = isset( $_POST['idpg_user_id'] ) ? absint( wp_unslash( $_POST['idpg_user_id'] ) ) : 0;
		$confirm = ( isset( $_POST['idpg_confirm'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['idpg_confirm'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- nonce проверен выше.

		if ( ! $confirm ) {
			wp_safe_redirect( $this->page_url( array( 'idpg_msg' => 'confirm_required' ) ) );
			exit;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			wp_safe_redirect( $this->page_url( array( 'idpg_msg' => 'not_found' ) ) );
			exit;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			wp_safe_redirect( $this->page_url( array( 'idpg_msg' => 'admin_protected' ) ) );
			exit;
		}

		if ( get_current_user_id() === $user_id ) {
			wp_safe_redirect( $this->page_url( array( 'idpg_msg' => 'self_protected' ) ) );
			exit;
		}

		$subject = $user->user_login . ' <' . $user->user_email . '>';

		// wp_delete_user удаляет записи автора и всю user meta; зачистку
		// вложений организатора добавляет IDPG_User_Purge на хуке delete_user.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$deleted = wp_delete_user( $user_id );

		$this->add_log_entry( $deleted ? 'delete' : 'delete_failed', $subject );

		wp_safe_redirect( $this->page_url( array( 'idpg_msg' => $deleted ? 'deleted' : 'delete_failed' ) ) );
		exit;
	}

	/**
	 * Выгрузка данных пользователя администратором.
	 *
	 * @return void
	 */
	public function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'idpg-core' ), 403 );
		}

		check_admin_referer( 'idpg_privacy_export' );

		$input  = isset( $_POST['idpg_user'] ) ? sanitize_text_field( wp_unslash( $_POST['idpg_user'] ) ) : '';
		$format = isset( $_POST['idpg_format'] ) ? sanitize_key( wp_unslash( $_POST['idpg_format'] ) ) : 'json';

		if ( '' === trim( $input ) ) {
			wp_safe_redirect( $this->page_url( array( 'idpg_msg' => 'empty_user' ) ) );
			exit;
		}

		$user = $this->find_user( $input );

		if ( ! $user ) {
			wp_safe_redirect( $this->page_url( array( 'idpg_msg' => 'not_found' ) ) );
			exit;
		}

		$this->add_log_entry( 'export', $user->user_login . ' <' . $user->user_email . '>' );
		$this->send_export( $user, $format );
	}

	/**
	 * Самостоятельная выгрузка своих данных авторизованным пользователем.
	 *
	 * @return void
	 */
	public function handle_self_export() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Требуется вход в аккаунт.', 'idpg-core' ), 403 );
		}

		check_admin_referer( 'idpg_privacy_self_export' );

		$user = wp_get_current_user();

		$this->add_log_entry( 'self_export', $user->user_login . ' <' . $user->user_email . '>' );
		$this->send_export( $user, 'json' );
	}

	// =========================================================================
	// Сбор и отправка данных
	// =========================================================================

	/**
	 * Поиск пользователя по email, логину или ID.
	 *
	 * @param string $input Строка поиска.
	 * @return WP_User|false
	 */
	private function find_user( $input ) {
		$input = trim( (string) $input );

		if ( '' === $input ) {
			return false;
		}

		if ( ctype_digit( $input ) ) {
			$user = get_userdata( (int) $input );

			return $user ? $user : false;
		}

		$by_email = get_user_by( 'email', sanitize_email( $input ) );

		if ( $by_email ) {
			return $by_email;
		}

		$by_login = get_user_by( 'login', sanitize_user( $input ) );

		return $by_login ? $by_login : false;
	}

	/**
	 * Собирает все персональные данные пользователя.
	 *
	 * @param WP_User $user Пользователь.
	 * @return array
	 */
	public function collect_user_data( $user ) {
		$user_id = (int) $user->ID;

		$data = array(
			'site'            => home_url( '/' ),
			'exported_at'     => current_time( 'mysql' ),
			'consent_version' => $this->get_consent_version(),
			'user'            => array(
				'ID'              => $user_id,
				'user_login'      => $user->user_login,
				'user_email'      => $user->user_email,
				'display_name'    => $user->display_name,
				'user_registered' => $user->user_registered,
				'roles'           => array_values( (array) $user->roles ),
			),
			'consent'         => self::get_consent( $user_id ),
			'meta'            => $this->collect_meta( $user_id ),
			'content'         => $this->collect_content( $user_id ),
		);

		/**
		 * Фильтр: состав выгрузки персональных данных пользователя.
		 *
		 * @param array   $data Данные выгрузки.
		 * @param WP_User $user Пользователь.
		 */
		return apply_filters( 'idpg_privacy_export_data', $data, $user );
	}

	/**
	 * Все user meta пользователя, кроме служебных ключей.
	 *
	 * @param int $user_id ID пользователя.
	 * @return array
	 */
	private function collect_meta( $user_id ) {
		$raw = get_user_meta( (int) $user_id );
		$out = array();

		foreach ( (array) $raw as $key => $values ) {
			if ( in_array( $key, self::EXPORT_META_SKIP, true ) ) {
				continue;
			}

			$values = array_map( 'maybe_unserialize', (array) $values );

			$out[ $key ] = ( 1 === count( $values ) ) ? $values[0] : $values;
		}

		return $out;
	}

	/**
	 * Записи, автором которых является пользователь.
	 *
	 * @param int $user_id ID пользователя.
	 * @return array
	 */
	private function collect_content( $user_id ) {
		$post_ids = get_posts(
			array(
				'author'         => (int) $user_id,
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$out = array();

		foreach ( (array) $post_ids as $post_id ) {
			$out[] = array(
				'ID'     => (int) $post_id,
				'type'   => (string) get_post_type( $post_id ),
				'status' => (string) get_post_status( $post_id ),
				'title'  => (string) get_the_title( $post_id ),
				'date'   => (string) get_post_time( 'c', true, $post_id ),
			);
		}

		return $out;
	}

	/**
	 * Отправляет выгрузку пользователю-администратору файлом.
	 *
	 * @param WP_User $user   Пользователь.
	 * @param string  $format 'json' | 'csv'.
	 * @return void
	 */
	private function send_export( $user, $format ) {
		$data = $this->collect_user_data( $user );
		$base = 'idpg-user-' . (int) $user->ID . '-' . gmdate( 'Y-m-d' );

		nocache_headers();

		if ( 'csv' === $format ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $base . '.csv"' );
			$this->output_csv( $data );
			exit;
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $base . '.json"' );

		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Печатает данные в CSV (две колонки: путь к значению и значение).
	 *
	 * @param array $data Данные выгрузки.
	 * @return void
	 */
	private function output_csv( $data ) {
		$handle = fopen( 'php://output', 'w' );

		if ( false === $handle ) {
			return;
		}

		// BOM, чтобы Excel корректно открыл кириллицу.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- поток на php://output, WP_Filesystem здесь неприменим.
		fwrite( $handle, "\xEF\xBB\xBF" );
		fputcsv( $handle, array( 'Поле', 'Значение' ), ';' );

		$rows = array();
		$this->flatten_rows( $data, '', $rows );

		foreach ( $rows as $row ) {
			fputcsv( $handle, $row, ';' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- поток на php://output, WP_Filesystem здесь неприменим.
		fclose( $handle );
	}

	/**
	 * Разворачивает вложенный массив в плоские строки «путь → значение».
	 *
	 * @param mixed  $value  Значение.
	 * @param string $prefix Текущий путь.
	 * @param array  $rows   Накопитель строк (по ссылке).
	 * @return void
	 */
	private function flatten_rows( $value, $prefix, &$rows ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$path = ( '' === $prefix ) ? (string) $key : $prefix . '.' . $key;
				$this->flatten_rows( $item, $path, $rows );
			}

			return;
		}

		if ( is_bool( $value ) ) {
			$value = $value ? 'true' : 'false';
		} elseif ( is_null( $value ) ) {
			$value = '';
		}

		$rows[] = array( $prefix, (string) $value );
	}

	// =========================================================================
	// Журнал действий
	// =========================================================================

	/**
	 * Журнал действий администратора.
	 *
	 * @return array
	 */
	private function get_log() {
		$log = get_option( self::LOG_OPTION, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Добавляет запись в журнал действий.
	 *
	 * @param string $action  Действие: export|delete|delete_failed|self_export.
	 * @param string $subject Субъект данных.
	 * @return void
	 */
	private function add_log_entry( $action, $subject ) {
		$log = $this->get_log();

		array_unshift(
			$log,
			array(
				'time'    => current_time( 'mysql' ),
				'action'  => sanitize_key( $action ),
				'subject' => (string) $subject,
				'actor'   => (string) wp_get_current_user()->user_login,
			)
		);

		update_option( self::LOG_OPTION, array_slice( $log, 0, (int) self::LOG_LIMIT ), false );
	}
}

// Инициализация.
IDPG_Privacy::instance();
