<?php
/**
 * REST API endpoints для авторизации.
 *
 * @package IDPG_Core
 */

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_REST_Auth
 *
 * Регистрирует эндпоинты для входа, регистрации, выхода,
 * восстановления пароля и получения данных текущего пользователя.
 *
 * Неймспейс: idpg/v1
 */
class IDPG_REST_Auth {

	/**
	 * Синглтон.
	 *
	 * @var IDPG_REST_Auth|null
	 */
	private static $instance = null;

	/**
	 * Префикс неймспейса API.
	 *
	 * @var string
	 */
	const NAMESPACE = 'idpg/v1';

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_REST_Auth
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Приватный конструктор.
	 */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Регистрация маршрутов авторизации.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/auth/login',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_login' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_login_param' ),
					),
					'password' => array(
						'required'          => true,
						'type'              => 'string',
					),
					'remember' => array(
						'required'          => false,
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
						'default'           => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/auth/register',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_register' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'type'     => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'user', 'organizer' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'name'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'    => array(
						'required'          => true,
						'type'              => 'string',
						'format'            => 'email',
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => 'is_email',
					),
					'password' => array(
						'required'          => true,
						'type'              => 'string',
					),
					'org_name' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'contact_name' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'phone'    => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'terms'    => array(
						'required'          => false,
						'type'              => 'boolean',
						'description'       => 'User consent to site terms.',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/auth/logout',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_logout' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/auth/forgot-password',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_forgot_password' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email' => array(
						'required'          => true,
						'type'              => 'string',
						'format'            => 'email',
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => 'is_email',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/auth/reset-password',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_reset_password' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'user_id'      => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'reset_key'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'new_password' => array(
						'required'          => true,
						'type'              => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/auth/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_me' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/auth/vkid',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_vkid_login' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'access_token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'id_token'     => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Вход через VK ID OneTap (обмен code выполнен SDK в браузере).
	 *
	 * Принимает access_token, проверяет его на сервере через
	 * id.vk.com/oauth2/user_info (Provider_Vk::get_profile) и входит
	 * либо регистрирует пользователя (Social_User::login, subscriber).
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_vkid_login( $request ) {
		$access_token = (string) $request->get_param( 'access_token' );

		if ( empty( $access_token ) ) {
			return new WP_Error(
				'empty_token',
				__( 'Токен VK ID не передан.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

		if ( ! class_exists( '\Idpg\SocialAuth\Provider_Vk' ) || ! class_exists( '\Idpg\SocialAuth\Social_User' ) ) {
			return new WP_Error(
				'social_auth_unavailable',
				__( 'Модуль социальной авторизации недоступен.', 'idpg-core' ),
				array( 'status' => 500 )
			);
		}

		// Серверная проверка токена: id.vk.com/oauth2/user_info.
		$profile = \Idpg\SocialAuth\Provider_Vk::instance()->get_profile( $access_token );

		if ( empty( $profile['id'] ) ) {
			error_log( '[SOCIAL-AUTH][vkid] REST: не удалось получить профиль по токену' );

			return new WP_Error(
				'profile_failed',
				__( 'Не удалось проверить токен VK ID. Попробуйте войти заново.', 'idpg-core' ),
				array( 'status' => 401 )
			);
		}

		// Email может отсутствовать в user_info — берём из профиля как есть.
		$user_id = \Idpg\SocialAuth\Social_User::instance()->login( 'vk', $profile );

		if ( is_wp_error( $user_id ) ) {
			error_log( '[SOCIAL-AUTH][vkid] REST: ошибка входа: ' . $user_id->get_error_message() );

			return new WP_Error(
				'login_failed',
				__( 'Ошибка входа через VK ID.', 'idpg-core' ),
				array( 'status' => 500 )
			);
		}

		$user = get_userdata( $user_id );
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );
		do_action( 'wp_login', $user->user_login, $user );

		error_log( '[SOCIAL-AUTH][vkid] REST: вход #' . $user_id . ' через VK ID OneTap' );

		$role         = $this->get_user_role( $user );
		$redirect_url = $this->get_redirect_url_by_role( $role );

		return new WP_REST_Response(
			array(
				'success'      => true,
				'user_id'      => $user_id,
				'role'         => $role,
				'redirect_url' => $redirect_url,
				'nonce'        => wp_create_nonce( 'wp_rest' ),
			),
			200
		);
	}

	/**
	 * Авторизация пользователя.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_login( $request ) {
		$email    = $request->get_param( 'email' );
		$password = $request->get_param( 'password' );
		$remember = (bool) $request->get_param( 'remember' );

		if ( empty( $password ) ) {
			return new WP_Error(
				'empty_password',
				__( 'Пароль не может быть пустым.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

		$ip = IDPG_Auth_Rate_Limiter::instance()->get_client_ip();
		$rate_check = IDPG_Auth_Rate_Limiter::instance()->check_login_attempt( $ip );

		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$user = wp_authenticate( $email, $password );

		if ( is_wp_error( $user ) ) {
			return new WP_Error(
				'invalid_credentials',
				__( 'Неверный email или пароль.', 'idpg-core' ),
				array( 'status' => 401 )
			);
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember );
		do_action( 'wp_login', $user->user_login, $user );

		// Сброс счетчика попыток.
		IDPG_Auth_Rate_Limiter::instance()->reset_attempts( $ip );

		$role         = $this->get_user_role( $user );
		$redirect_url = $this->get_redirect_url_by_role( $role );

		/**
		 * Действие после успешного входа.
		 *
		 * @param WP_User $user Авторизованный пользователь.
		 */
		do_action( 'idpg_auth_after_login', $user );

		return new WP_REST_Response(
			array(
				'success'      => true,
				'user_id'      => $user->ID,
				'role'         => $role,
				'redirect_url' => $redirect_url,
				'nonce'        => wp_create_nonce( 'wp_rest' ),
			),
			200
		);
	}

	/**
	 * Регистрация нового пользователя.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_register( $request ) {
		if ( is_user_logged_in() ) {
			return new WP_Error(
				'already_logged_in',
				__( 'Вы уже авторизованы.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

		$type     = $request->get_param( 'type' );
		$name     = $request->get_param( 'name' );
		$email    = $request->get_param( 'email' );
		$password = $request->get_param( 'password' );

		if ( strlen( $password ) < 8 ) {
			return new WP_Error(
				'short_password',
				__( 'Пароль должен содержать минимум 8 символов.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

		$ip = IDPG_Auth_Rate_Limiter::instance()->get_client_ip();
		$rate_check = IDPG_Auth_Rate_Limiter::instance()->check_registration_attempt( $ip );

		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		if ( email_exists( $email ) ) {
			return new WP_Error(
				'email_exists',
				__( 'Этот email уже зарегистрирован. Используйте другой email или войдите в систему.', 'idpg-core' ),
				array( 'status' => 409 )
			);
		}

		// Валидация полей организатора.
		if ( 'organizer' === $type ) {
			$org_name     = $request->get_param( 'org_name' );
			$contact_name = $request->get_param( 'contact_name' );
			$phone        = $request->get_param( 'phone' );

			if ( empty( $org_name ) ) {
				return new WP_Error(
					'empty_org_name',
					__( 'Введите название организации.', 'idpg-core' ),
					array( 'status' => 400 )
				);
			}

			if ( empty( $contact_name ) ) {
				return new WP_Error(
					'empty_contact_name',
					__( 'Введите ФИО представителя.', 'idpg-core' ),
					array( 'status' => 400 )
				);
			}

			if ( empty( $phone ) ) {
				return new WP_Error(
					'empty_phone',
					__( 'Введите номер телефона.', 'idpg-core' ),
					array( 'status' => 400 )
				);
			}
		}

		// Валидация согласия с правилами сайта.
		$terms = $request->get_param( 'terms' );

		if ( ! (bool) $terms ) {
			return new WP_Error(
				'terms_required',
				__( 'Необходимо согласие с правилами сайта.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

		$user_id = wp_create_user( $email, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			return new WP_Error(
				'registration_failed',
				__( 'Ошибка при создании пользователя.', 'idpg-core' ),
				array( 'status' => 500 )
			);
		}

		// Обновление отображаемого имени.
		// Для организатора используем ФИО представителя (contact_name),
		// для обычного пользователя — введённое имя (name).
		$display_name = ( 'organizer' === $type && ! empty( $request->get_param( 'contact_name' ) ) )
			? $request->get_param( 'contact_name' )
			: $name;

		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => sanitize_text_field( $display_name ),
			)
		);

		// Назначение роли.
		$user = new WP_User( $user_id );

		if ( 'organizer' === $type ) {
			$user->set_role( 'event_organizer' );

			// Дополнительные поля для организатора.
			$org_name     = $request->get_param( 'org_name' );
			$contact_name = $request->get_param( 'contact_name' );
			$phone        = $request->get_param( 'phone' );

			if ( ! empty( $org_name ) ) {
				update_user_meta( $user_id, 'idpg_org_name', sanitize_text_field( $org_name ) );
			}

			if ( ! empty( $contact_name ) ) {
				update_user_meta( $user_id, 'idpg_contact_name', sanitize_text_field( $contact_name ) );
			}

			if ( ! empty( $phone ) ) {
				update_user_meta( $user_id, 'idpg_phone', sanitize_text_field( $phone ) );
			}
		} else {
			$user->set_role( 'subscriber' );
		}

		// Автоматическая авторизация после регистрации.
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id );

		$role         = $this->get_user_role( $user );
		$redirect_url = $this->get_redirect_url_by_role( $role );

		/**
		 * Действие после регистрации.
		 *
		 * @param int    $user_id ID нового пользователя.
		 * @param string $type    Тип регистрации: user|organizer.
		 */
		do_action( 'idpg_auth_after_register', $user_id, $type );

		return new WP_REST_Response(
			array(
				'success'      => true,
				'user_id'      => $user_id,
				'role'         => $role,
				'redirect_url' => $redirect_url,
			),
			201
		);
	}

	/**
	 * Выход из системы.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_logout() {
		wp_logout();

		return new WP_REST_Response(
			array(
				'success'      => true,
				'redirect_url' => home_url( '/' ),
			),
			200
		);
	}

	/**
	 * Запрос на восстановление пароля.
	 *
	 * Всегда возвращает успех, даже если email не найден
	 * (защита от определения существующих пользователей).
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response
	 */
	public function handle_forgot_password( $request ) {
		$email = $request->get_param( 'email' );

		$rate_check = IDPG_Auth_Rate_Limiter::instance()->check_password_reset_attempt( $email );

		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		// retrieve_password отправляет письмо, если пользователь существует.
		retrieve_password( $email );

		/**
		 * Вне зависимости от результата возвращаем одинаковый ответ,
		 * чтобы не раскрывать факт существования email в системе.
		 */
		return new WP_REST_Response(
			array(
				'success'      => true,
				'message'      => __( 'Если email существует, инструкции отправлены.', 'idpg-core' ),
				'redirect_url' => home_url( '/login/' ),
			),
			200
		);
	}

	/**
	 * Сброс пароля по ключу.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_reset_password( $request ) {
		$user_id     = (int) $request->get_param( 'user_id' );
		$reset_key   = $request->get_param( 'reset_key' );
		$new_password = $request->get_param( 'new_password' );

		if ( empty( $new_password ) || strlen( $new_password ) < 8 ) {
			return new WP_Error(
				'short_password',
				__( 'Пароль должен содержать минимум 8 символов.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return new WP_Error(
				'invalid_user',
				__( 'Пользователь не найден.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

		$check = check_password_reset_key( $reset_key, $user->user_login );

		if ( is_wp_error( $check ) ) {
			$error_code = $check->get_error_code();

			if ( 'expired_key' === $error_code ) {
				return new WP_Error(
					'expired_key',
					__( 'Ключ сброса истёк. Запросите восстановление пароля заново.', 'idpg-core' ),
					array( 'status' => 400 )
				);
			}

			return new WP_Error(
				'invalid_key',
				__( 'Неверный ключ сброса пароля.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

		reset_password( $user, $new_password );

		return new WP_REST_Response(
			array(
				'success'      => true,
				'message'      => __( 'Пароль изменён.', 'idpg-core' ),
				'redirect_url' => home_url( '/login/' ),
			),
			200
		);
	}

	/**
	 * Получение данных текущего пользователя.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_me() {
		$user = wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			return new WP_Error(
				'not_logged_in',
				__( 'Пользователь не авторизован.', 'idpg-core' ),
				array( 'status' => 401 )
			);
		}

		return new WP_REST_Response(
			array(
				'user_id'      => $user->ID,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
				'role'         => $this->get_user_role( $user ),
			),
			200
		);
	}

	/**
	 * Возвращает первую значимую роль пользователя.
	 *
	 * Приоритет: administrator > editor > event_organizer > subscriber.
	 *
	 * @param WP_User $user Объект пользователя.
	 * @return string
	 */
	private function get_user_role( $user ) {
		$roles = (array) $user->roles;

		if ( in_array( 'administrator', $roles, true ) ) {
			return 'administrator';
		}

		if ( in_array( 'editor', $roles, true ) ) {
			return 'editor';
		}

		if ( in_array( 'event_organizer', $roles, true ) ) {
			return 'event_organizer';
		}

		return 'subscriber';
	}

	/**
	 * Валидация логина (email или username).
	 *
	 * @param mixed           $value   Значение параметра.
	 * @param WP_REST_Request $request Запрос.
	 * @param string          $param   Имя параметра.
	 * @return bool
	 */
	public function validate_login_param( $value, $request, $param ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return false;
		}
		// Email — всегда ок, username — проверяем существование или минимум 3 символа (чтобы не палить существование).
		if ( is_email( $value ) ) {
			return true;
		}
		if ( username_exists( $value ) ) {
			return true;
		}
		return strlen( $value ) >= 3;
	}

	/**
	 * Возвращает URL перенаправления в зависимости от роли.
	 *
	 * @param string $role Название роли.
	 * @return string
	 */
	private function get_redirect_url_by_role( $role ) {
		if ( in_array( $role, array( 'administrator', 'editor' ), true ) ) {
			return admin_url();
		}

		if ( 'subscriber' === $role ) {
			return home_url( '/lk/' );
		}

		return home_url( '/' );
	}
}
