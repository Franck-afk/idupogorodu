<?php
/**
 * Провайдер авторизации Яндекс ID.
 *
 * OAuth 2.0 Authorization Code flow: oauth.yandex.ru / login.yandex.ru.
 *
 * @package IDPG_Core
 */

namespace Idpg\SocialAuth;

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс Provider_Yandex
 *
 * Реализует OAuth для Яндекс ID: формирование URL авторизации,
 * обмен code на access_token, получение профиля пользователя.
 * Наследует Social_Provider.
 */
class Provider_Yandex extends Social_Provider {

	/**
	 * Слаг провайдера.
	 *
	 * @var string
	 */
	const SLUG = 'yandex';

	/**
	 * Адрес страницы авторизации.
	 *
	 * @var string
	 */
	const AUTH_URL = 'https://oauth.yandex.ru/authorize';

	/**
	 * Адрес получения токена.
	 *
	 * @var string
	 */
	const TOKEN_URL = 'https://oauth.yandex.ru/token';

	/**
	 * Адрес API (профиль пользователя).
	 *
	 * @var string
	 */
	const API_URL = 'https://login.yandex.ru/info';

	/**
	 * Время жизни state-транзиента (10 минут).
	 *
	 * @var int
	 */
	const STATE_TTL = 600;

	/**
	 * Экземпляр синглтона.
	 *
	 * @var Provider_Yandex|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return Provider_Yandex
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
	 * Имя провайдера по ТЗ.
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
		return 'Яндекс';
	}

	/**
	 * Стартует OAuth-авторизацию (ТЗ: /auth/yandex/ -> start_auth).
	 *
	 * @return void
	 */
	public function start_auth() {
		if ( ! Social_Admin::is_provider_ready( $this->get_id() ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][yandex] start_auth: провайдер не настроен' );
			wp_safe_redirect( add_query_arg( array( 'idpg_social_error' => 'provider_not_configured' ), home_url( '/login/' ) ) );
			exit;
		}

		$url = $this->get_authorize_url();

		if ( empty( $url ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][yandex] start_auth: пустой authorize_url' );
			wp_safe_redirect( add_query_arg( array( 'idpg_social_error' => 'auth_url_failed' ), home_url( '/login/' ) ) );
			exit;
		}

		wp_redirect( $url );
		exit;
	}

	/**
	 * Формирует URL для редиректа на Яндекс OAuth (по ТЗ: get_authorize_url).
	 *
	 * Параметры: client_id, redirect_uri, response_type=code, state.
	 * State сохраняется в транзиенте на 10 минут (защита CSRF).
	 *
	 * @return string URL авторизации.
	 */
	public function get_authorize_url() {
		$app_id = $this->get_app_id();

		if ( empty( $app_id ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][yandex] get_authorize_url: пустой APP_ID (yandex_app_id не настроен)' );
		}

		$state = wp_generate_password( 32, false, false );
		set_transient( 'idpg_social_state_' . $state, self::SLUG, self::STATE_TTL );

		$args = array(
			'client_id'     => $app_id,
			'redirect_uri'  => $this->get_callback_url(),
			'response_type' => 'code',
			'state'         => $state,
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
			error_log( '[SOCIAL-AUTH][yandex] validate_state: несоответствие state' );

			return false;
		}

		// Одноразовое использование.
		delete_transient( 'idpg_social_state_' . $state );

		return true;
	}

	/**
	 * Формирует URL аватара Яндекс.
	 *
	 * @param string $avatar_id Идентификатор аватара (default_avatar_id).
	 * @param int    $size      Размер острова (по умолчанию 200).
	 * @return string URL аватара или пустая строка.
	 */
	public function get_avatar_url( $avatar_id, $size = 200 ) {
		$avatar_id = sanitize_text_field( (string) $avatar_id );
		$size      = absint( $size );

		if ( empty( $avatar_id ) || '0' === $avatar_id ) {
			return '';
		}

		if ( empty( $size ) ) {
			$size = 200;
		}

		// Ограничиваем размеры до допустимых Яндексом (28..300).
		$size = max( 28, min( 300, $size ) );

		return sprintf( 'https://avatars.yandex.net/get-yapic/%s/islands-%d', rawurlencode( $avatar_id ), $size );
	}

	/**
	 * Обменивает code на access_token и получает данные пользователя (по ТЗ: get_user_data).
	 *
	 * Выполняет POST https://oauth.yandex.ru/token, затем
	 * GET https://login.yandex.ru/info?format=json с полями
	 * id, login, display_name, first_name, last_name, default_email,
	 * default_avatar_id, emails, birthday, sex, is_avatar_empty.
	 *
	 * @param string $code Код авторизации из коллбэка Яндекса.
	 * @return array|WP_Error Нормализованный профиль или ошибка.
	 */
	public function get_user_data( $code ) {
		$code = sanitize_text_field( (string) $code );

		if ( empty( $code ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][yandex] get_user_data: пустой code' );

			return new \WP_Error( 'missing_code', __( 'Отсутствует код авторизации.', 'idpg-core' ) );
		}

		// 1. Обмен code на токен — POST.
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => $this->get_app_id(),
					'client_secret' => $this->get_app_secret(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][yandex] get_user_data: wp_remote_post TOKEN_URL ошибка: ' . $response->get_error_message() );

			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][yandex] get_user_data: пустой ответ от ' . self::TOKEN_URL . ' status=' . $status );

			return new \WP_Error( 'empty_token_response', __( 'Пустой ответ от Яндекс при получении токена.', 'idpg-core' ), array( 'status' => $status ) );
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$error = isset( $data['error'] ) ? $data['error'] : 'invalid_token_response';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][yandex] get_user_data: не удалось получить access_token, body=' . mb_substr( $body, 0, 500 ) );

			return new \WP_Error( $error, __( 'Не удалось получить токен от Яндекс.', 'idpg-core' ), array( 'status' => $status ) );
		}

		$access_token = (string) $data['access_token'];

		// 2. Профиль — GET login.yandex.ru/info?format=json.
		$url = add_query_arg(
			array( 'format' => 'json' ),
			self::API_URL
		);

		$profile_response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'OAuth ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $profile_response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][yandex] get_user_data: wp_remote_get API_URL ошибка: ' . $profile_response->get_error_message() );

			return $profile_response;
		}

		$profile_status = wp_remote_retrieve_response_code( $profile_response );
		$profile_body   = wp_remote_retrieve_body( $profile_response );

		if ( empty( $profile_body ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][yandex] get_user_data: пустой ответ от API info status=' . $profile_status );

			return new \WP_Error( 'empty_profile_response', __( 'Пустой ответ от Яндекс API.', 'idpg-core' ), array( 'status' => $profile_status ) );
		}

		$profile_data = json_decode( $profile_body, true );

		if ( ! is_array( $profile_data ) || ( empty( $profile_data['id'] ) && empty( $profile_data['login'] ) ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][yandex] get_user_data: пустой профиль, body=' . mb_substr( $profile_body, 0, 500 ) );

			return new \WP_Error( 'empty_profile', __( 'Не удалось получить профиль Яндекс.', 'idpg-core' ) );
		}

		// Email: если default_email пуст — взять первый из emails.
		$email = isset( $profile_data['default_email'] ) ? sanitize_email( (string) $profile_data['default_email'] ) : '';

		if ( empty( $email ) && ! empty( $profile_data['emails'] ) && is_array( $profile_data['emails'] ) ) {
			foreach ( $profile_data['emails'] as $em ) {
				$em = sanitize_email( (string) $em );
				if ( is_email( $em ) ) {
					$email = $em;
					break;
				}
			}
		}

		// Аватар.
		$avatar_id = isset( $profile_data['default_avatar_id'] ) ? sanitize_text_field( (string) $profile_data['default_avatar_id'] ) : '';
		$is_empty  = ! empty( $profile_data['is_avatar_empty'] );
		$avatar    = '';

		if ( ! $is_empty && ! empty( $avatar_id ) ) {
			$avatar = $this->get_avatar_url( $avatar_id, 200 );
		}

		// display_name может содержать "Имя Фамилия" — оставляем как есть для Social_User.
		$display_name = isset( $profile_data['display_name'] ) ? sanitize_text_field( (string) $profile_data['display_name'] ) : '';

		return array(
			'provider'       => self::SLUG,
			'id'             => isset( $profile_data['id'] ) ? (string) $profile_data['id'] : '',
			'login'          => isset( $profile_data['login'] ) ? sanitize_user( (string) $profile_data['login'] ) : '',
			'display_name'   => $display_name,
			'first_name'     => isset( $profile_data['first_name'] ) ? sanitize_text_field( (string) $profile_data['first_name'] ) : '',
			'last_name'      => isset( $profile_data['last_name'] ) ? sanitize_text_field( (string) $profile_data['last_name'] ) : '',
			'email'          => $email,
			'username'       => isset( $profile_data['login'] ) ? sanitize_user( (string) $profile_data['login'] ) : ( isset( $profile_data['display_name'] ) ? sanitize_user( (string) $profile_data['display_name'] ) : '' ),
			'photo'          => $avatar,
			'avatar'         => $avatar,
			'avatar_id'      => $avatar_id,
			'default_email'  => $email,
			'birthday'       => isset( $profile_data['birthday'] ) ? sanitize_text_field( (string) $profile_data['birthday'] ) : '',
			'sex'            => isset( $profile_data['sex'] ) ? sanitize_key( (string) $profile_data['sex'] ) : '',
			'default_phone'  => isset( $profile_data['default_phone'] ) ? sanitize_text_field( (string) $profile_data['default_phone'] ) : '',
		);
	}

	/**
	 * Обменивает код авторизации на токен доступа (совместимость с фасадом).
	 *
	 * @param array $params Параметры коллбэка (code, state).
	 * @return array|WP_Error Массив ответа (access_token) или ошибка.
	 */
	public function get_access_token( array $params ) {
		$code = isset( $params['code'] ) ? sanitize_text_field( $params['code'] ) : '';

		if ( empty( $code ) ) {
			return new \WP_Error( 'missing_code', __( 'Отсутствует код авторизации.', 'idpg-core' ) );
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
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][yandex] get_access_token: wp_remote_post ошибка: ' . $response->get_error_message() );

			return $response;
		}

		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );
		$status = wp_remote_retrieve_response_code( $response );

		if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$error = isset( $data['error'] ) ? $data['error'] : 'invalid_response';
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][yandex] get_access_token: пустой access_token, body=' . mb_substr( $body, 0, 500 ) );

			return new \WP_Error( $error, __( 'Не удалось получить токен от Яндекс.', 'idpg-core' ), array( 'status' => $status ) );
		}

		return $data;
	}

	/**
	 * Получает профиль пользователя по токену (совместимость).
	 *
	 * @param string $access_token Токен доступа.
	 * @return array Нормализованный профиль.
	 */
	public function get_profile( $access_token ) {
		$access_token = sanitize_text_field( (string) $access_token );

		if ( empty( $access_token ) ) {
			return array();
		}

		$url = add_query_arg( array( 'format' => 'json' ), self::API_URL );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'OAuth ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][yandex] get_profile: wp_remote_get ошибка: ' . $response->get_error_message() );

			return array();
		}

		$body = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][yandex] get_profile: пустой ответ' );

			return array();
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ( empty( $data['id'] ) && empty( $data['login'] ) ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[SOCIAL-AUTH][yandex] get_profile: пустой профиль' );

			return array();
		}

		$email = isset( $data['default_email'] ) ? sanitize_email( (string) $data['default_email'] ) : '';

		if ( empty( $email ) && ! empty( $data['emails'] ) && is_array( $data['emails'] ) ) {
			foreach ( $data['emails'] as $em ) {
				$em = sanitize_email( (string) $em );
				if ( is_email( $em ) ) {
					$email = $em;
					break;
				}
			}
		}

		$avatar_id = isset( $data['default_avatar_id'] ) ? sanitize_text_field( (string) $data['default_avatar_id'] ) : '';
		$avatar    = ( ! empty( $avatar_id ) && empty( $data['is_avatar_empty'] ) ) ? $this->get_avatar_url( $avatar_id, 200 ) : '';

		return array(
			'provider'   => self::SLUG,
			'id'         => isset( $data['id'] ) ? (string) $data['id'] : '',
			'first_name' => isset( $data['first_name'] ) ? sanitize_text_field( (string) $data['first_name'] ) : '',
			'last_name'  => isset( $data['last_name'] ) ? sanitize_text_field( (string) $data['last_name'] ) : '',
			'email'      => $email,
			'username'   => isset( $data['login'] ) ? sanitize_user( (string) $data['login'] ) : '',
			'photo'      => $avatar,
			'avatar'     => $avatar,
		);
	}
}
