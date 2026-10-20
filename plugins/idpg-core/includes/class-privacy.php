<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Имя файла по конвенции плагина (class-<класс без префикса>.php), как у остальных классов.
/**
 * Модуль обработки персональных данных (152-ФЗ).
 *
 * Написан заново 20.10.2026 (DEV-288) взамен утраченного class-privacy.php.
 * Не восстанавливался из git и не переносился в idpg-city-admin — живёт в idpg-core.
 *
 * Состав:
 *   — фиксация согласия на обработку ПДн (дата, редакция, источник, хеш IP);
 *   — отзыв согласия и его хранение отдельной меткой;
 *   — cookie-баннер (выбор «все / только необходимые», cookie на год);
 *   — права субъекта: само-выгрузка данных, запрос на удаление, отзыв согласия;
 *   — админ-вкладка «Приватность (152-ФЗ)» на странице «IDPG» (тема отдаёт
 *     точку расширения фильтром idpg_widget_areas_admin_tabs).
 *
 * Сырой IP-адрес не хранится: сохраняется только wp_hash() от него.
 *
 * @package IDPG_Core
 */

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Privacy
 *
 * Публичный контракт совместим с прежним (class-rest-auth.php:574,
 * page-login.php:246, tests/test-registration-form.php): класс, константа
 * CONSENT_FIELD и статический record_consent( $user_id, $source ).
 */
class IDPG_Privacy {

	/**
	 * Экземпляр синглтона.
	 *
	 * @var IDPG_Privacy|null
	 */
	private static $instance = null;

	/**
	 * Имя поля чекбокса/параметра согласия.
	 *
	 * @var string
	 */
	const CONSENT_FIELD = 'idpg_pdn_consent';

	/**
	 * Ключ user meta с записью о согласии.
	 *
	 * @var string
	 */
	const CONSENT_META = 'idpg_pdn_consent';

	/**
	 * Ключ user meta с записью об отзыве согласия.
	 *
	 * Отзыв хранится отдельно, чтобы не затирать факт «согласие дано».
	 *
	 * @var string
	 */
	const WITHDRAWN_META = '_idpg_pdn_consent_withdrawn';

	/**
	 * Редакция документов (политики/согласия) по умолчанию.
	 *
	 * @var string
	 */
	const CONSENT_VERSION = '2026-10-20';

	/**
	 * Имя cookie выбора в баннере.
	 *
	 * @var string
	 */
	const COOKIE_NAME = 'idpg_cookie_consent';

	/**
	 * Срок жизни cookie баннера (год).
	 *
	 * @var int
	 */
	const COOKIE_LIFETIME = YEAR_IN_SECONDS;

	/**
	 * Допустимые значения cookie баннера.
	 *
	 * @var string[]
	 */
	const COOKIE_CHOICES = array( 'all', 'necessary' );

	/**
	 * Слаг страницы политики конфиденциальности (fallback, если опция не задана).
	 *
	 * @var string
	 */
	const PRIVACY_SLUG = 'privacy';

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
	 * Конструктор. Вешает хуки фронтенда и админки.
	 */
	private function __construct() {
		// Точка расширения страницы «IDPG» в теме: вкладка «Приватность».
		add_filter( 'idpg_widget_areas_admin_tabs', array( $this, 'register_admin_tab' ) );

		// Cookie-баннер: ассеты и разметка на фронтенде.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_banner' ) );

		// Обработка POST баннера и запросов субъекта.
		add_action( 'template_redirect', array( $this, 'handle_banner_submit' ) );
		add_action( 'template_redirect', array( $this, 'handle_subject_request' ) );

		// Само-выгрузка авторизованного пользователя.
		add_action( 'admin_post_idpg_privacy_self_export', array( $this, 'handle_self_export' ) );

		// Админ-обработчики прав субъекта (только manage_options).
		add_action( 'admin_post_idpg_privacy_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_idpg_privacy_preview', array( $this, 'handle_delete_preview' ) );
		add_action( 'admin_post_idpg_privacy_delete', array( $this, 'handle_delete' ) );

		// Блок инструментов субъекта на странице политики.
		add_filter( 'the_content', array( $this, 'append_subject_tools' ) );
	}

	// Согласие.

	/**
	 * Текущая редакция документов.
	 *
	 * @return string
	 */
	public static function get_consent_version() {
		/**
		 * Фильтр редакции согласия.
		 *
		 * @param string $version Редакция по умолчанию.
		 */
		return (string) apply_filters( 'idpg_privacy_consent_version', self::CONSENT_VERSION );
	}

	/**
	 * Хеш IP-адреса клиента (сырой IP не хранится).
	 *
	 * @return string
	 */
	public static function hash_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return '';
		}
		return wp_hash( $ip );
	}

	/**
	 * Запись согласия пользователя.
	 *
	 * @param int    $user_id ID пользователя.
	 * @param string $source  Источник согласия (user|organizer|…).
	 * @return bool true при успешной записи.
	 */
	public static function record_consent( $user_id, $source = 'user' ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}

		$record = array(
			'given'      => current_time( 'mysql' ),
			'version'    => self::get_consent_version(),
			'source'     => sanitize_key( $source ),
			'ip_hash'    => self::hash_client_ip(),
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] )
				? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 )
				: '',
		);

		update_user_meta( $user_id, self::CONSENT_META, $record );

		// Повторное согласие снимает прежний отзыв.
		delete_user_meta( $user_id, self::WITHDRAWN_META );

		/**
		 * Действие после фиксации согласия.
		 *
		 * @param int   $user_id ID пользователя.
		 * @param array $record  Запись о согласии.
		 */
		do_action( 'idpg_privacy_consent_record', $user_id, $record );

		return true;
	}

	/**
	 * Есть ли действующее согласие.
	 *
	 * @param int $user_id ID пользователя.
	 * @return bool
	 */
	public static function has_consent( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( self::is_withdrawn( $user_id ) ) {
			return false;
		}
		return (bool) get_user_meta( $user_id, self::CONSENT_META, true );
	}

	/**
	 * Запись о согласии.
	 *
	 * @param int $user_id ID пользователя.
	 * @return array
	 */
	public static function get_consent( $user_id ) {
		$record = get_user_meta( (int) $user_id, self::CONSENT_META, true );
		return is_array( $record ) ? $record : array();
	}

	/**
	 * Отзыв согласия пользователя.
	 *
	 * @param int    $user_id ID пользователя.
	 * @param string $source  Источник отзыва.
	 * @return bool
	 */
	public static function withdraw_consent( $user_id, $source = 'user' ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}

		$record = array(
			'withdrawn' => current_time( 'mysql' ),
			'version'   => self::get_consent_version(),
			'source'    => sanitize_key( $source ),
		);

		update_user_meta( $user_id, self::WITHDRAWN_META, $record );

		/**
		 * Действие после отзыва согласия.
		 *
		 * @param int   $user_id ID пользователя.
		 * @param array $record  Запись об отзыве.
		 */
		do_action( 'idpg_privacy_consent_withdrawn', $user_id, $record );

		return true;
	}

	/**
	 * Отозвано ли согласие.
	 *
	 * @param int $user_id ID пользователя.
	 * @return bool
	 */
	public static function is_withdrawn( $user_id ) {
		return (bool) get_user_meta( (int) $user_id, self::WITHDRAWN_META, true );
	}

	/**
	 * Запись об отзыве согласия.
	 *
	 * @param int $user_id ID пользователя.
	 * @return array
	 */
	public static function get_withdrawal( $user_id ) {
		$record = get_user_meta( (int) $user_id, self::WITHDRAWN_META, true );
		return is_array( $record ) ? $record : array();
	}

	// Cookie-баннер.

	/**
	 * URL страницы политики конфиденциальности.
	 *
	 * @return string
	 */
	public static function get_privacy_page_url() {
		$page_id = (int) get_option( 'wp_page_for_privacy_policy' );
		if ( $page_id > 0 ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				return $url;
			}
		}
		return home_url( '/' . self::PRIVACY_SLUG . '/' );
	}

	/**
	 * Текущий выбор пользователя в баннере.
	 *
	 * @return string ''|'all'|'necessary'
	 */
	public static function get_banner_choice() {
		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return '';
		}
		$choice = sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		return in_array( $choice, self::COOKIE_CHOICES, true ) ? $choice : '';
	}

	/**
	 * Подключение ассетов баннера (только когда выбор ещё не сделан).
	 */
	public function enqueue_assets() {
		if ( is_admin() || '' !== self::get_banner_choice() ) {
			return;
		}
		wp_enqueue_style(
			'idpg-privacy-banner',
			IDPG_CORE_URL . 'assets/css/privacy.css',
			array(),
			IDPG_CORE_VERSION
		);
	}

	/**
	 * Разметка cookie-баннера на фронтенде.
	 */
	public function render_banner() {
		if ( is_admin() || '' !== self::get_banner_choice() ) {
			return;
		}

		$privacy_url = self::get_privacy_page_url();

		$banner_text = apply_filters(
			'idpg_privacy_banner_text',
			__( 'Мы используем cookie, чтобы сайт работал корректно и был удобнее. Продолжая пользоваться сайтом, вы соглашаетесь с обработкой данных в соответствии с политикой конфиденциальности.', 'idpg-core' )
		);
		?>
		<div class="idpg-cookie" id="idpg-cookie" role="region" aria-label="<?php esc_attr_e( 'Использование cookie', 'idpg-core' ); ?>">
			<form class="idpg-cookie__form" method="post" action="">
				<p class="idpg-cookie__text">
					<?php echo esc_html( $banner_text ); ?>
					<a class="idpg-cookie__policy" href="<?php echo esc_url( $privacy_url ); ?>"><?php esc_html_e( 'Политика конфиденциальности', 'idpg-core' ); ?></a>
				</p>
				<div class="idpg-cookie__actions">
					<?php wp_nonce_field( 'idpg_privacy_banner', 'idpg_privacy_banner_nonce' ); ?>
					<button type="submit" class="idpg-cookie__btn" name="idpg_banner_choice" value="necessary">
						<?php esc_html_e( 'Только необходимые', 'idpg-core' ); ?>
					</button>
					<button type="submit" class="idpg-cookie__btn idpg-cookie__btn--primary" name="idpg_banner_choice" value="all">
						<?php esc_html_e( 'Принять все', 'idpg-core' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Обработка выбора в баннере (POST).
	 */
	public function handle_banner_submit() {
		if ( ! isset( $_POST['idpg_privacy_banner_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['idpg_privacy_banner_nonce'] ) ), 'idpg_privacy_banner' ) ) {
			return;
		}

		$choice = isset( $_POST['idpg_banner_choice'] ) ? sanitize_key( wp_unslash( $_POST['idpg_banner_choice'] ) ) : '';
		if ( ! in_array( $choice, self::COOKIE_CHOICES, true ) ) {
			return;
		}

		setcookie(
			self::COOKIE_NAME,
			$choice,
			array(
				'expires'  => time() + self::COOKIE_LIFETIME,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE_NAME ] = $choice;

		// Перезагрузка, чтобы баннер больше не выводился.
		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = home_url( '/' );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	// Права субъекта.

	/**
	 * Блок инструментов субъекта на странице политики.
	 *
	 * @param string $content Контент записи.
	 * @return string
	 */
	public function append_subject_tools( $content ) {
		if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( ! $this->is_privacy_page( get_the_ID() ) ) {
			return $content;
		}
		return $content . $this->render_subject_tools( get_the_ID() );
	}

	/**
	 * Признак страницы политики конфиденциальности.
	 *
	 * @param int $post_id ID записи.
	 * @return bool
	 */
	private function is_privacy_page( $post_id ) {
		$post_id = (int) $post_id;
		$page_id = (int) get_option( 'wp_page_for_privacy_policy' );
		if ( $page_id > 0 ) {
			return $page_id === $post_id;
		}
		$page = get_post( $post_id );
		return ( $page && self::PRIVACY_SLUG === $page->post_name );
	}

	/**
	 * Разметка инструментов прав субъекта.
	 *
	 * @param int $post_id ID страницы политики (для redirect_to).
	 * @return string
	 */
	public function render_subject_tools( $post_id ) {
		$privacy_url = get_permalink( (int) $post_id );
		$msg         = isset( $_GET['idpg_privacy_msg'] ) ? sanitize_key( wp_unslash( $_GET['idpg_privacy_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- сообщение только для показа.

		ob_start();
		?>
		<section class="idpg-privacy-tools">
			<h2 class="idpg-privacy-tools__title"><?php esc_html_e( 'Ваши права', 'idpg-core' ); ?></h2>

			<?php if ( $msg ) : ?>
				<p class="idpg-privacy-tools__note idpg-privacy-tools__note--ok"><?php echo esc_html( $this->subject_message_text( $msg ) ); ?></p>
			<?php endif; ?>

			<?php if ( is_user_logged_in() ) : ?>
				<?php $this->render_withdraw_block( $privacy_url ); ?>
				<div class="idpg-privacy-tools__block">
					<h3 class="idpg-privacy-tools__subtitle"><?php esc_html_e( 'Распоряжение данными', 'idpg-core' ); ?></h3>
					<p class="idpg-privacy-tools__text"><?php esc_html_e( 'Вы можете выгрузить свои данные или запросить их удаление.', 'idpg-core' ); ?></p>
					<div class="idpg-privacy-tools__form">
						<form method="post" action="<?php echo esc_url( home_url( '/wp-admin/admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="idpg_privacy_self_export">
							<?php wp_nonce_field( 'idpg_privacy_self_export', 'idpg_privacy_self_export_nonce' ); ?>
							<button type="submit" class="idpg-privacy-tools__submit"><?php esc_html_e( 'Выгрузить мои данные', 'idpg-core' ); ?></button>
						</form>
						<form method="post" action="">
							<?php wp_nonce_field( 'idpg_privacy_subject', 'idpg_privacy_subject_nonce' ); ?>
							<input type="hidden" name="idpg_privacy_action" value="delete_request">
							<button type="submit" class="idpg-privacy-tools__submit"><?php esc_html_e( 'Запросить удаление моих данных', 'idpg-core' ); ?></button>
						</form>
					</div>
				</div>
			<?php else : ?>
				<p class="idpg-privacy-tools__text"><?php esc_html_e( 'Войдите в аккаунт, чтобы выгрузить свои данные, отозвать согласие или запросить удаление.', 'idpg-core' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Блок отзыва согласия.
	 *
	 * @param string $privacy_url URL страницы политики.
	 */
	private function render_withdraw_block( $privacy_url ) {
		$user_id = get_current_user_id();
		if ( self::is_withdrawn( $user_id ) ) {
			?>
			<div class="idpg-privacy-tools__block">
				<h3 class="idpg-privacy-tools__subtitle"><?php esc_html_e( 'Отзыв согласия', 'idpg-core' ); ?></h3>
				<p class="idpg-privacy-tools__note idpg-privacy-tools__note--ok">
					<?php esc_html_e( 'Согласие на обработку персональных данных отозвано.', 'idpg-core' ); ?>
				</p>
			</div>
			<?php
			return;
		}
		if ( ! self::has_consent( $user_id ) ) {
			return;
		}
		?>
		<div class="idpg-privacy-tools__block">
			<h3 class="idpg-privacy-tools__subtitle"><?php esc_html_e( 'Отзыв согласия', 'idpg-core' ); ?></h3>
			<p class="idpg-privacy-tools__text">
				<?php
				printf(
					/* translators: %s: URL страницы политики. */
					esc_html__( 'Отозвать согласие на обработку персональных данных можно здесь. Подробнее — в <a class="idpg-privacy-tools__link" href="%s">политике конфиденциальности</a>.', 'idpg-core' ),
					esc_url( $privacy_url )
				);
				?>
			</p>
			<form method="post" action="" class="idpg-privacy-tools__form">
				<?php wp_nonce_field( 'idpg_privacy_subject', 'idpg_privacy_subject_nonce' ); ?>
				<input type="hidden" name="idpg_privacy_action" value="withdraw">
				<button type="submit" class="idpg-privacy-tools__submit"><?php esc_html_e( 'Отозвать согласие на обработку ПДн', 'idpg-core' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Текст уведомления для пользователя.
	 *
	 * @param string $msg Код сообщения.
	 * @return string
	 */
	private function subject_message_text( $msg ) {
		switch ( $msg ) {
			case 'withdrawn':
				return __( 'Согласие на обработку персональных данных отозвано.', 'idpg-core' );
			case 'delete_requested':
				return __( 'Запрос на удаление данных отправлен. Администрация свяжется с вами.', 'idpg-core' );
			case 'export_ready':
				return __( 'Выгрузка данных готова и загружается.', 'idpg-core' );
			default:
				return '';
		}
	}

	/**
	 * Обработка запросов субъекта (отзыв согласия, запрос на удаление).
	 */
	public function handle_subject_request() {
		if ( ! isset( $_POST['idpg_privacy_subject_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['idpg_privacy_subject_nonce'] ) ), 'idpg_privacy_subject' ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}

		$action   = isset( $_POST['idpg_privacy_action'] ) ? sanitize_key( wp_unslash( $_POST['idpg_privacy_action'] ) ) : '';
		$user_id  = get_current_user_id();
		$redirect = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		$msg      = '';

		if ( 'withdraw' === $action ) {
			self::withdraw_consent( $user_id, 'self' );
			$msg = 'withdrawn';
		} elseif ( 'delete_request' === $action ) {
			$this->notify_admin_about_request( $user_id, 'delete' );
			$msg = 'delete_requested';
		}

		if ( '' !== $msg ) {
			$redirect = add_query_arg( 'idpg_privacy_msg', $msg, $redirect );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Само-выгрузка данных авторизованного пользователя (JSON).
	 */
	public function handle_self_export() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Требуется авторизация.', 'idpg-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'idpg_privacy_self_export', 'idpg_privacy_self_export_nonce' );

		$data = $this->build_user_export( get_current_user_id() );
		$this->stream_json( $data, 'idpg-my-data.json' );
	}

	/**
	 * Уведомление администратора о запросе субъекта.
	 *
	 * @param int    $user_id ID пользователя.
	 * @param string $type    Тип запроса: delete|export.
	 */
	private function notify_admin_about_request( $user_id, $type ) {
		/**
		 * Действие при запросе субъекта (для уведомлений/логов).
		 *
		 * @param int    $user_id ID пользователя.
		 * @param string $type    Тип запроса.
		 */
		do_action( 'idpg_privacy_subject_request', $user_id, $type );

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$admin_email = get_option( 'admin_email' );
		if ( ! is_email( $admin_email ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: тип запроса. */
			__( 'Запрос субъекта ПДн: %s', 'idpg-core' ),
			$type
		);
		$message = sprintf(
			/* translators: 1: логин, 2: email, 3: тип запроса, 4: дата. */
			__( "Пользователь %1\$s (%2\$s) отправил запрос «%3\$s».\nВремя: %4\$s\n\nПроверьте раздел «Приватность» на странице IDPG.", 'idpg-core' ),
			$user->user_login,
			$user->user_email,
			$type,
			current_time( 'mysql' )
		);

		wp_mail( $admin_email, $subject, $message );
	}

	/**
	 * Формирование выгрузки данных пользователя.
	 *
	 * @param int $user_id ID пользователя.
	 * @return array
	 */
	private function build_user_export( $user_id ) {
		$user_id = (int) $user_id;
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}

		$meta  = get_user_meta( $user_id );
		$clean = array();
		if ( is_array( $meta ) ) {
			foreach ( $meta as $key => $values ) {
				$clean[ $key ] = array();
				foreach ( (array) $values as $value ) {
					$clean[ $key ][] = maybe_unserialize( $value );
				}
			}
		}

		$export = array(
			'exported_at' => current_time( 'mysql' ),
			'version'     => self::get_consent_version(),
			'user'        => array(
				'id'           => $user_id,
				'user_login'   => $user->user_login,
				'user_email'   => $user->user_email,
				'display_name' => $user->display_name,
				'registered'   => $user->user_registered,
				'roles'        => (array) $user->roles,
			),
			'consent'     => self::get_consent( $user_id ),
			'withdrawal'  => self::get_withdrawal( $user_id ),
			'meta'        => $clean,
		);

		/**
		 * Фильтр выгрузки данных пользователя.
		 *
		 * @param array $export  Данные выгрузки.
		 * @param int   $user_id ID пользователя.
		 */
		return (array) apply_filters( 'idpg_privacy_user_export', $export, $user_id );
	}

	/**
	 * Отдача JSON-файла на скачивание.
	 *
	 * @param array  $data     Данные.
	 * @param string $filename Имя файла.
	 */
	private function stream_json( $data, $filename ) {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $filename ) );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	// Админ-обработчики прав субъекта (manage_options).

	/**
	 * Выгрузка данных пользователя администратором.
	 */
	public function handle_export() {
		$this->guard_admin_request( 'idpg_privacy_admin' );
		$target_id = isset( $_REQUEST['user_id'] ) ? absint( wp_unslash( $_REQUEST['user_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce проверен в guard_admin_request().
		$data      = $this->build_user_export( $target_id );
		$this->stream_json( $data, 'idpg-user-' . (int) $target_id . '.json' );
	}

	/**
	 * Предпросмотр удаления данных пользователя.
	 */
	public function handle_delete_preview() {
		$this->guard_admin_request( 'idpg_privacy_admin' );
		$target_id = isset( $_REQUEST['user_id'] ) ? absint( wp_unslash( $_REQUEST['user_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce проверен в guard_admin_request().
		$user      = get_userdata( $target_id );

		$counts = array(
			'posts' => (int) count_user_posts( $target_id, 'any', true ),
			'meta'  => is_array( get_user_meta( $target_id ) ) ? count( get_user_meta( $target_id ) ) : 0,
		);

		$data = array(
			'user_id' => $target_id,
			'login'   => $user ? $user->user_login : '',
			'email'   => $user ? $user->user_email : '',
			'counts'  => $counts,
			'preview' => true,
		);
		$this->stream_json( $data, 'idpg-delete-preview-' . (int) $target_id . '.json' );
	}

	/**
	 * Удаление данных пользователя администратором.
	 */
	public function handle_delete() {
		$this->guard_admin_request( 'idpg_privacy_delete' );
		$target_id = isset( $_REQUEST['user_id'] ) ? absint( wp_unslash( $_REQUEST['user_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce проверен в guard_admin_request().
		if ( $target_id <= 0 ) {
			wp_die( esc_html__( 'Некорректный пользователь.', 'idpg-core' ), '', array( 'response' => 400 ) );
		}

		// Не позволяем удалять администраторов: это защита от самоблокировки.
		if ( user_can( $target_id, 'manage_options' ) ) {
			wp_die( esc_html__( 'Нельзя удалить администратора через этот интерфейс.', 'idpg-core' ), '', array( 'response' => 403 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $target_id );

		$redirect = add_query_arg(
			array(
				'page'     => 'idpg-widget-areas',
				'tab'      => 'privacy',
				'idpg_msg' => 'privacy_deleted',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Проверка прав и nonce для админ-обработчика.
	 *
	 * @param string $action Действие nonce.
	 */
	private function guard_admin_request( $action ) {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'idpg-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	// Админ-вкладка «Приватность (152-ФЗ)» на странице «IDPG».

	/**
	 * Регистрация вкладки на странице «IDPG» (фильтр темы).
	 *
	 * @param array $tabs Вкладки страницы.
	 * @return array
	 */
	public function register_admin_tab( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			$tabs = array();
		}
		$tabs['privacy'] = array(
			'label'    => __( 'Приватность (152-ФЗ)', 'idpg-core' ),
			'callback' => array( $this, 'render_admin_tab' ),
		);
		return $tabs;
	}

	/**
	 * Рендер вкладки «Приватность (152-ФЗ)».
	 */
	public function render_admin_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$msg = isset( $_GET['idpg_msg'] ) ? sanitize_key( wp_unslash( $_GET['idpg_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- сообщение только для показа.
		if ( $msg ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $this->admin_message_text( $msg ) )
			);
		}

		$version   = self::get_consent_version();
		$user_id   = get_current_user_id();
		$privacy   = self::get_privacy_page_url();
		$with_tool = self::has_consent( $user_id );
		?>
		<div class="idpg-option-field">
			<div class="idpg-option-field-label">
				<?php esc_html_e( 'Редакция документов', 'idpg-core' ); ?>
			</div>
			<div class="idpg-option-field-body">
				<strong><?php echo esc_html( $version ); ?></strong>
				<p class="description">
					<?php esc_html_e( 'Редакция согласия и документов, фиксируемая вместе с согласием пользователя.', 'idpg-core' ); ?>
				</p>
			</div>
		</div>

		<div class="idpg-option-field">
			<div class="idpg-option-field-label">
				<?php esc_html_e( 'Cookie-баннер', 'idpg-core' ); ?>
			</div>
			<div class="idpg-option-field-body">
				<p class="description">
					<?php esc_html_e( 'Баннер показывается гостям, пока не сделан выбор. Выбор хранится в cookie idpg_cookie_consent сроком на год.', 'idpg-core' ); ?>
				</p>
			</div>
		</div>

		<div class="idpg-option-field">
			<div class="idpg-option-field-label">
				<?php esc_html_e( 'Права субъекта', 'idpg-core' ); ?>
			</div>
			<div class="idpg-option-field-body">
				<p class="description">
					<?php
					printf(
						/* translators: %s: URL страницы политики. */
						wp_kses_post( __( 'Инструменты выгрузки, удаления и отзыва согласия выведены на <a href="%s">странице политики конфиденциальности</a>.', 'idpg-core' ) ),
						esc_url( $privacy )
					);
					?>
				</p>
				<?php if ( $with_tool ) : ?>
					<p class="description"><?php esc_html_e( 'Ваше согласие зафиксировано.', 'idpg-core' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<?php $this->render_requests_section(); ?>
		<?php
	}

	/**
	 * Секция «Управление данными пользователя» на вкладке.
	 */
	private function render_requests_section() {
		?>
		<h3 class="idpg-privacy-section-title"><?php esc_html_e( 'Управление данными пользователя', 'idpg-core' ); ?></h3>
		<div class="idpg-option-field">
			<div class="idpg-option-field-label">
				<?php esc_html_e( 'Действия по ID пользователя', 'idpg-core' ); ?>
			</div>
			<div class="idpg-option-field-body">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="number" min="1" step="1" name="user_id" value="" class="small-text" placeholder="<?php esc_attr_e( 'ID', 'idpg-core' ); ?>" />
					<?php wp_nonce_field( 'idpg_privacy_admin' ); ?>
					<button type="submit" class="button" name="action" value="idpg_privacy_export"><?php esc_html_e( 'Выгрузить данные', 'idpg-core' ); ?></button>
					<button type="submit" class="button" name="action" value="idpg_privacy_preview"><?php esc_html_e( 'Предпросмотр удаления', 'idpg-core' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Удалить пользователя и все его данные? Действие необратимо.', 'idpg-core' ) ); ?>');">
					<input type="number" min="1" step="1" name="user_id" value="" class="small-text" placeholder="<?php esc_attr_e( 'ID', 'idpg-core' ); ?>" />
					<?php wp_nonce_field( 'idpg_privacy_delete' ); ?>
					<button type="submit" class="button button-link-delete" name="action" value="idpg_privacy_delete"><?php esc_html_e( 'Удалить данные пользователя', 'idpg-core' ); ?></button>
				</form>
				<p class="description">
					<?php esc_html_e( 'Удаление не затрагивает администраторов. Запросы субъектов приходят письмом на адрес администратора.', 'idpg-core' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Текст админ-уведомления вкладки.
	 *
	 * @param string $msg Код.
	 * @return string
	 */
	private function admin_message_text( $msg ) {
		switch ( $msg ) {
			case 'privacy_deleted':
				return __( 'Данные пользователя удалены.', 'idpg-core' );
			default:
				return '';
		}
	}
}

// Инициализация модуля (синглтон вешает хуки в конструкторе).
IDPG_Privacy::instance();
