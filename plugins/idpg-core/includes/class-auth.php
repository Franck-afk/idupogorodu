<?php
/**
 * Управление авторизацией, редиректами и доступом к админке.
 *
 * @package IDPG_Core
 */

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Auth
 *
 * Управляет редиректами после входа и выхода, ограничивает доступ
 * к админ-панели для неавторизованных пользователей,
 * отключает стандартную регистрацию WordPress.
 */
class IDPG_Auth {

	/**
	 * Экземпляр синглтона.
	 *
	 * @var IDPG_Auth|null
	 */
	private static $instance = null;

	/**
	 * URL страницы входа.
	 *
	 * @var string
	 */
	const LOGIN_URL = '/login/';

	/**
	 * URL восстановления пароля.
	 *
	 * @var string
	 */
	const RESET_PASSWORD_URL = '/reset-password/';

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Auth
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Приватный конструктор.
	 *
	 * Регистрирует все хуки и фильтры авторизации.
	 */
	private function __construct() {
		add_filter( 'login_redirect', array( $this, 'redirect_after_login' ), 10, 3 );
		add_action( 'admin_init', array( $this, 'restrict_admin_access' ) );
		add_action( 'wp_logout', array( $this, 'redirect_after_logout' ) );
		add_action( 'login_init', array( $this, 'disable_default_registration' ) );
		add_filter( 'register_url', array( $this, 'get_login_url' ) );
		add_filter( 'login_url', array( $this, 'set_login_url' ), 10, 3 );
		add_action( 'template_redirect', array( $this, 'redirect_if_logged_in' ) );
		add_filter( 'redirect_canonical', array( $this, 'disable_admin_locations_redirect' ), 10, 2 );
	}

	/**
	 * Редирект после успешного входа.
	 *
	 * Направляет подписчиков и остальных на главную страницу сайта.
	 * При WP_Error — на страницу входа.
	 *
	 * @param string           $redirect_to URL по умолчанию.
	 * @param string           $request     Запрошенный URL из формы.
	 * @param WP_User|WP_Error $user        Объект пользователя или ошибка.
	 * @return string
	 */
	public function redirect_after_login( $redirect_to, $request, $user ) {
		if ( is_wp_error( $user ) ) {
			return home_url( self::LOGIN_URL );
		}

		$roles = (array) $user->roles;

		if ( in_array( 'administrator', $roles, true )
			|| in_array( 'editor', $roles, true )
			|| in_array( 'event_organizer', $roles, true )
		) {
			return admin_url();
		}

		return home_url( '/' );
	}

	/**
	 * Ограничение доступа к админ-панели.
	 *
	 * Пропускает AJAX и Cron-запросы.
	 * Перенаправляет неавторизованных на страницу входа.
	 * Перенаправляет подписчиков на главную страницу.
	 *
	 * @return void
	 */
	public function restrict_admin_access() {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$user = wp_get_current_user();

		if ( in_array( 'subscriber', (array) $user->roles, true ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
	}

	/**
	 * Редирект после выхода.
	 *
	 * Направляет на главную страницу сайта.
	 * Не срабатывает для REST-запросов (иначе ломает JSON-ответ
	 * эндпоинта /auth/logout).
	 *
	 * @return void
	 */
	public function redirect_after_logout() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * Отключение стандартной регистрации WordPress.
	 *
	 * Перенаправляет /wp-login.php?action=register на /login/.
	 * Перенаправляет /wp-login.php?action=lostpassword на /reset-password/.
	 * Перенаправляет прямой заход на /wp-login.php (без action) на /login/,
	 * чтобы стандартная форма WordPress не была доступна снаружи.
	 *
	 * Не трогает POST-запросы (сама форма входа на wp-login.php работает
	 * через POST и редиректится только после обработки), а также служебные
	 * action: logout (выход), postpass (пароль записи) — их обрабатывает ядро.
	 *
	 * @return void
	 */
	public function disable_default_registration() {
		// POST-запросы не редиректим: ядро WP само обработает вход
		// и после login_redirect отправит на кастомную страницу.
		$request_method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		if ( 'POST' === $request_method ) {
			return;
		}

		$action = isset( $_GET['action'] )
			? sanitize_key( wp_unslash( $_GET['action'] ) )
			: '';

		if ( 'register' === $action ) {
			wp_safe_redirect( home_url( self::LOGIN_URL ) );
			exit;
		}

		if ( 'lostpassword' === $action || 'retrievepassword' === $action ) {
			wp_safe_redirect( home_url( self::RESET_PASSWORD_URL ) );
			exit;
		}

		// Служебные action, которые обрабатывает само ядро WordPress
		// и которые не должны вести на страницу входа.
		$core_actions = array( 'logout', 'postpass', 'rp', 'resetpass', 'confirmaction' );

		if ( in_array( $action, $core_actions, true ) ) {
			return;
		}

		// Прямой GET-заход на /wp-login.php (в т.ч. после неудачного входа,
		// action=login) — отправляем на кастомную страницу входа.
		wp_safe_redirect( home_url( self::LOGIN_URL ) );
		exit;
	}

	/**
	 * Подменяет URL входа на кастомную страницу.
	 *
	 * Фильтр login_url — все ссылки wp_login_url() будут вести на /login/.
	 *
	 * @param string $login_url    URL входа.
	 * @param string $redirect     URL перенаправления после входа.
	 * @param bool   $force_reauth Принудительная повторная аутентификация.
	 * @return string
	 */
	public function set_login_url( $login_url, $redirect, $force_reauth ) {
		return home_url( self::LOGIN_URL );
	}

	/**
	 * Редирект авторизованного пользователя со страницы входа.
	 *
	 * Если пользователь уже вошёл и открывает /login/,
	 * перенаправляет его на главную страницу.
	 *
	 * @return void
	 */
	public function redirect_if_logged_in() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$request_path = isset( $_SERVER['REQUEST_URI'] )
			? wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH )
			: '';

		if ( self::LOGIN_URL !== $request_path && rtrim( self::LOGIN_URL, '/' ) !== $request_path ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * Возвращает URL страницы входа.
	 *
	 * Callback для фильтра register — подменяет ссылку регистрации
	 * на кастомную страницу /login/.
	 *
	 * @return string
	 */
	public function get_login_url() {
		return home_url( self::LOGIN_URL );
	}

	/**
	 * Отключает редирект wp_redirect_admin_locations для /login/.
	 *
	 * WordPress по умолчанию редиректит /login на wp_login_url(),
	 * который через фильтр login_url возвращает /login/ — возникает цикл.
	 *
	 * @param string $redirect_url URL редиректа.
	 * @param string $request_url  Запрошенный URL.
	 * @return string|false
	 */
	public function disable_admin_locations_redirect( $redirect_url, $request_url ) {
		if ( ! is_404() ) {
			return $redirect_url;
		}

		$path = untrailingslashit( wp_parse_url( $request_url, PHP_URL_PATH ) );

		if ( untrailingslashit( self::LOGIN_URL ) === $path ) {
			return false;
		}

		return $redirect_url;
	}
}
