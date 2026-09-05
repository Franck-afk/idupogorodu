<?php
/**
 * Провайдер авторизации VK ID.
 *
 * Новый OAuth 2.1 Authorization Code flow (устаревший oauth.vk.com
 * отключён VK): id.vk.com/authorize + id.vk.com/oauth2/token.
 * Поддерживает вход через VK, OK и Mail.ru.
 *
 * PKCE (S256) обязателен по OAuth 2.1: code_verifier генерируется при
 * старте и хранится в транзиенте до обмена кода на токен.
 *
 * @package IDPG_Core
 */

namespace Idpg\SocialAuth;

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс Provider_Vk
 *
 * Реализует OAuth 2.1 для VK ID: формирование URL авторизации (с state
 * и PKCE code_challenge), обмен code на access_token, получение профиля
 * через id.vk.com/oauth2/user_info (фолбэк — users.get).
 */
class Provider_Vk extends Social_Provider {

	/**
	 * Слаг провайдера.
	 *
	 * @var string
	 */
	const SLUG = 'vk';

	/**
	 * Адрес страницы авторизации VK ID.
	 *
	 * @var string
	 */
	const AUTH_URL = 'https://id.vk.com/authorize';

	/**
	 * Адрес получения токена (OAuth 2.1).
	 *
	 * @var string
	 */
	const TOKEN_URL = 'https://id.vk.com/oauth2/token';

	/**
	 * Адрес получения профиля по токену (VK ID UserInfo).
	 *
	 * @var string
	 */
	const USERINFO_URL = 'https://id.vk.com/oauth2/user_info';

	/**
	 * Адрес legacy-профильного API (фолбэк, токены VK ID совместимы).
	 *
	 * @var string
	 */
	const API_URL = 'https://api.vk.com/method/users.get';

	/**
	 * Версия legacy API VK.
	 *
	 * @var string
	 */
	const API_VERSION = '5.131';

	/**
	 * Запрашиваемые scope VK ID (через пробел).
	 *
	 * vkid.personal_info — имя/фамилия/аватар; email — e-mail пользователя.
	 *
	 * @var string
	 */
	const SCOPE = 'vkid.personal_info email';

	/**
	 * Время жизни state-транзиента (10 минут).
	 *
	 * @var int
	 */
	const STATE_TTL = 600;

	/**
	 * Префикс транзиента PKCE-верификатора.
	 *
	 * @var string
	 */
	const VERIFIER_KEY = 'idpg_vk_pkce_';

	/**
	 * Экземпляр синглтона.
	 *
	 * @var Provider_Vk|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return Provider_Vk
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Слаг провайдера (совместимость с базовым классом).
	 *
	 * @return string
	 */
	public function get_id() {
		return self::SLUG;
	}

	/**
	 * Имя провайдера.
	 *
	 * @return string
	 */
	public function get_provider_name() {
		return self::SLUG;
	}

	/**
	 * Отображаемое название провайдера.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'VK ID';
	}

	/**
	 * Стартует OAuth-авторизацию (ТЗ: /auth/vk/ -> start_auth).
	 *
	 * @return void
	 */
	public function start_auth() {
		if ( ! Social_Admin::is_provider_ready( $this->get_id() ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][vk] start_auth: провайдер не настроен' );
			wp_safe_redirect( add_query_arg( array( 'idpg_social_error' => 'provider_not_configured' ), home_url( '/login/' ) ) );
			exit;
		}

		$url = $this->get_authorize_url();

		if ( empty( $url ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][vk] start_auth: пустой authorize_url' );
			wp_safe_redirect( add_query_arg( array( 'idpg_social_error' => 'auth_url_failed' ), home_url( '/login/' ) ) );
			exit;
		}

		// Внешний хост id.vk.com — wp_safe_redirect его отбросит, нужен wp_redirect.
		wp_redirect( $url );
		exit;
	}

	/**
	 * Генерирует PKCE code_verifier (43-128 символов, unreserved).
	 *
	 * @return string
	 */
	private function generate_code_verifier() {
		return wp_generate_password( 64, false, false );
	}

	/**
	 * Вычисляет PKCE code_challenge (BASE64URL(SHA256(verifier))).
	 *
	 * @param string $verifier Code verifier.
	 * @return string
	 */
	private function code_challenge( $verifier ) {
		$hash = hash( 'sha256', $verifier, true );

		return rtrim( strtr( base64_encode( $hash ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Формирует URL авторизации VK ID (по ТЗ: get_authorize_url).
	 *
	 * Параметры: client_id, redirect_uri, response_type=code, scope,
	 * state (CSRF, транзиент), code_challenge + code_challenge_method=S256 (PKCE).
	 *
	 * @return string URL авторизации.
	 */
	public function get_authorize_url() {
		$app_id = $this->get_app_id();

		if ( empty( $app_id ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][vk] get_authorize_url: пустой APP_ID (vk_app_id не настроен)' );
		}

		// State — одноразовый CSRF-токен через фасад.
		$state = $this->create_state();

		// PKCE: verifier храним до обмена кода (10 минут), challenge уходит в URL.
		$verifier = $this->generate_code_verifier();
		set_transient( self::VERIFIER_KEY . $state, $verifier, self::STATE_TTL );

		$args = array(
			'response_type'         => 'code',
			'client_id'             => $app_id,
			'redirect_uri'          => $this->get_callback_url(),
			'scope'                 => self::SCOPE,
			'state'                 => $state,
			'code_challenge'        => $this->code_challenge( $verifier ),
			'code_challenge_method' => 'S256',
		);

		return add_query_arg( $args, self::AUTH_URL );
	}

	/**
	 * Совместимость: базовый класс требует get_auth_url().
	 *
	 * @return string
	 */
	public function get_auth_url() {
		return $this->get_authorize_url();
	}

	/**
	 * Проверяет state из сессии (транзиента).
	 *
	 * Одноразовое использование. Вызывается опционально — фасад
	 * Social_Auth::verify_state() делает ту же проверку.
	 *
	 * @param string $state Значение state из коллбэка.
	 * @return bool
	 */
	public function validate_state( $state ) {
		$state = preg_replace( '/[^A-Za-z0-9]/', '', (string) $state );

		if ( empty( $state ) ) {
			return false;
		}

		$stored = get_transient( 'idpg_social_state_' . $state );

		if ( false === $stored || self::SLUG !== $stored ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][vk] validate_state: несоответствие state' );

			return false;
		}

		delete_transient( 'idpg_social_state_' . $state );

		return true;
	}

	/**
	 * Обменивает code на access_token (VK ID, OAuth 2.1 + PKCE).
	 *
	 * POST https://id.vk.com/oauth2/token c grant_type=authorization_code,
	 * code_verifier из транзиента по state. Ответ содержит access_token
	 * и объект user (имя, аватар, email — при выданном scope).
	 *
	 * @param array $params Параметры коллбэка (code, state).
	 * @return array|WP_Error Массив (access_token, email, user) или ошибка.
	 */
	public function get_access_token( array $params ) {
		$code  = isset( $params['code'] ) ? sanitize_text_field( $params['code'] ) : '';
		$state = isset( $params['state'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $params['state'] ) : '';

		if ( empty( $code ) ) {
			return new \WP_Error( 'missing_code', __( 'Отсутствует код авторизации.', 'idpg-core' ) );
		}

		// PKCE: verifier обязателен для обмена кода.
		$verifier = $state ? get_transient( self::VERIFIER_KEY . $state ) : false;
		delete_transient( self::VERIFIER_KEY . $state );

		if ( empty( $verifier ) || ! is_string( $verifier ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][vk] get_access_token: PKCE verifier не найден (state=' . $state . ')' );

			return new \WP_Error( 'pkce_verifier_missing', __( 'Истёк срок авторизации. Попробуйте войти заново.', 'idpg-core' ) );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => $this->get_app_id(),
					'client_secret' => $this->get_app_secret(),
					'redirect_uri'  => $this->get_callback_url(),
					'code_verifier' => $verifier,
					'state'         => $state,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][vk] get_access_token: wp_remote_post ошибка: ' . $response->get_error_message() );

			return $response;
		}

		$body   = wp_remote_retrieve_body( $response );
		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( $body, true );

		if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$error = isset( $data['error'] ) ? sanitize_key( (string) $data['error'] ) : 'invalid_token_response';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][vk] get_access_token: пустой access_token, status=' . $status . ' body=' . mb_substr( $body, 0, 500 ) );

			return new \WP_Error( $error, __( 'Не удалось получить токен от VK ID.', 'idpg-core' ), array( 'status' => $status ) );
		}

		// Дополнительная сверка state из ответа (если VK прислал).
		if ( ! empty( $data['state'] ) && $state && sanitize_text_field( (string) $data['state'] ) !== $state ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][vk] get_access_token: state в ответе не совпал' );

			return new \WP_Error( 'state_mismatch', __( 'Ошибка безопасности. Попробуйте войти заново.', 'idpg-core' ) );
		}

		// Email приходит внутри объекта user (scope email).
		$email = '';
		if ( isset( $data['user']['email'] ) ) {
			$email = sanitize_email( (string) $data['user']['email'] );
		}

		return array(
			'access_token'  => (string) $data['access_token'],
			'refresh_token' => isset( $data['refresh_token'] ) ? (string) $data['refresh_token'] : '',
			'expires_in'    => isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 0,
			'id_token'      => isset( $data['id_token'] ) ? (string) $data['id_token'] : '',
			'user'          => isset( $data['user'] ) && is_array( $data['user'] ) ? $data['user'] : array(),
			'email'         => $email,
		);
	}

	/**
	 * Получает профиль пользователя по токену.
	 *
	 * Основной источник — POST id.vk.com/oauth2/user_info (client_id +
	 * access_token). Фолбэк — legacy users.get (токены VK ID совместимы).
	 *
	 * @param string $access_token Токен доступа.
	 * @return array Нормализованный профиль.
	 */
	public function get_profile( $access_token ) {
		$access_token = sanitize_text_field( (string) $access_token );

		if ( empty( $access_token ) ) {
			return array();
		}

		// 1. VK ID UserInfo.
		$response = wp_remote_post(
			self::USERINFO_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'    => $this->get_app_id(),
					'access_token' => $access_token,
				),
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( is_array( $data ) && ! empty( $data['user']['user_id'] ) ) {
				$u = $data['user'];

				$email = isset( $u['email'] ) ? sanitize_email( (string) $u['email'] ) : '';

				return array(
					'provider'   => self::SLUG,
					'id'         => (string) $u['user_id'],
					'first_name' => isset( $u['first_name'] ) ? sanitize_text_field( (string) $u['first_name'] ) : '',
					'last_name'  => isset( $u['last_name'] ) ? sanitize_text_field( (string) $u['last_name'] ) : '',
					'email'      => $email,
					'username'   => $email ? sanitize_user( strstr( $email, '@', true ) ) : '',
					'photo'      => isset( $u['avatar'] ) ? esc_url_raw( (string) $u['avatar'] ) : '',
					'avatar'     => isset( $u['avatar'] ) ? esc_url_raw( (string) $u['avatar'] ) : '',
				);
			}
		} else {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][vk] get_profile: user_info ошибка: ' . $response->get_error_message() );
		}

		// 2. Фолбэк: users.get.
		return $this->get_profile_users_get( $access_token );
	}

	/**
	 * Фолбэк-профиль через users.get (legacy API).
	 *
	 * @param string $access_token Токен доступа.
	 * @return array
	 */
	private function get_profile_users_get( $access_token ) {
		$url = add_query_arg(
			array(
				'fields'       => 'photo_100,domain',
				'access_token' => $access_token,
				'v'            => self::API_VERSION,
			),
			self::API_URL
		);

		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][vk] get_profile_users_get: wp_remote_get ошибка: ' . $response->get_error_message() );

			return array();
		}

		$body = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			return array();
		}

		$data = json_decode( $body, true );
		$user = isset( $data['response'][0] ) && is_array( $data['response'][0] ) ? $data['response'][0] : array();

		if ( empty( $user['id'] ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][vk] get_profile_users_get: пустой user, body=' . mb_substr( $body, 0, 500 ) );

			return array();
		}

		return array(
			'provider'   => self::SLUG,
			'id'         => (string) $user['id'],
			'first_name' => isset( $user['first_name'] ) ? sanitize_text_field( $user['first_name'] ) : '',
			'last_name'  => isset( $user['last_name'] ) ? sanitize_text_field( $user['last_name'] ) : '',
			'email'      => '',
			'username'   => isset( $user['domain'] ) ? sanitize_user( $user['domain'] ) : '',
			'photo'      => isset( $user['photo_100'] ) ? esc_url_raw( $user['photo_100'] ) : '',
			'avatar'     => isset( $user['photo_100'] ) ? esc_url_raw( $user['photo_100'] ) : '',
		);
	}

	/**
	 * Полный поток «code → токен → профиль» (совместимость по ТЗ: get_user_data).
	 *
	 * @param string $code Код авторизации из коллбэка VK ID.
	 * @param string $state State из коллбэка (для PKCE-верификатора).
	 * @return array|WP_Error Нормализованный профиль или ошибка.
	 */
	public function get_user_data( $code, $state = '' ) {
		$token = $this->get_access_token( array(
			'code'  => $code,
			'state' => $state,
		) );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$profile = $this->get_profile( $token['access_token'] );

		if ( empty( $profile['id'] ) && ! empty( $token['user']['user_id'] ) ) {
			// user_info недоступен — собираем профиль из ответа токена.
			$u    = $token['user'];
			$file = isset( $u['avatar'] ) ? esc_url_raw( (string) $u['avatar'] ) : '';

			$profile = array(
				'provider'   => self::SLUG,
				'id'         => (string) $u['user_id'],
				'first_name' => isset( $u['first_name'] ) ? sanitize_text_field( (string) $u['first_name'] ) : '',
				'last_name'  => isset( $u['last_name'] ) ? sanitize_text_field( (string) $u['last_name'] ) : '',
				'email'      => $token['email'],
				'username'   => $token['email'] ? sanitize_user( strstr( $token['email'], '@', true ) ) : '',
				'photo'      => $file,
				'avatar'     => $file,
			);
		}

		if ( empty( $profile['email'] ) ) {
			$profile['email'] = $token['email'];
		}

		return $profile;
	}
}
