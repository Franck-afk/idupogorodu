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
	 * Действие nonce для REST-запросов.
	 *
	 * Стандартное действие ядра WordPress — `wp_rest`. Совпадает с тем,
	 * которое проверяет ядро в `rest_cookie_check_errors()`, поэтому
	 * один и тот же токен подходит и ядру, и проверке ниже.
	 *
	 * @var string
	 */
	const REST_NONCE_ACTION = 'wp_rest';

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
					// `type` намеренно необязателен и без enum: допустимое
					// значение проверяется в колбэке строгим сравнением, иначе
					// запрос без `type` или с пустой строкой отсекался бы
					// схемой с 400 вместо продуктового 403.
					'type'     => array(
						'required'          => false,
						'type'              => 'string',
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
						// DEV-78: замыкание вместо строки 'is_email',
						// иначе REST передаёт $request в устаревший
						// второй аргумент is_email() и летит notice.
						'validate_callback' => function ( $value ) {
							return is_email( $value );
						},
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
					// Honeypot (DEV-150). Поле скрыто от людей, но остаётся
					// в POST, поэтому его заполняет бот.
					//
					// Значение НЕ санитизируется и не типизируется в схеме
					// намеренно. `sanitize_text_field` отдаёт на массиве пустую
					// строку, а проверка идёт после санитизации — и бот обходил
					// приманку, прислав `idpg_company[]=x` вместо скаляра.
					// Саму строку поле используется только для сравнения с
					// пустотой и никуда не записывается, поэтому санитизировать
					// нечего; не-строку обрабатывает проверка в обработчике.
					'idpg_company' => array(
						'required' => false,
					),
					'terms'            => array(
						'required'          => false,
						'type'              => 'boolean',
						'description'       => 'User consent to site terms.',
					),
					// Согласие на обработку персональных данных (152-ФЗ).
					// rest_sanitize_boolean обязателен: без него строка
					// 'false' прошла бы как истинное значение.
					'idpg_pdn_consent' => array(
						'required'          => false,
						'type'              => 'boolean',
						'description'       => 'Согласие на обработку персональных данных.',
						'sanitize_callback' => 'rest_sanitize_boolean',
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
				'permission_callback' => array( $this, 'can_logout' ),
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
						// DEV-78: не строка 'is_email' — REST вызывает
						// валидатор как ( $value, $request, $key ), и второй
						// аргумент is_email() помечен устаревшим с 3.0.0,
						// из-за чего каждый вызов давал notice.
						'validate_callback' => function ( $value ) {
							return is_email( $value );
						},
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
	}

	/**
	 * Проверка блокировки IP плагином All-In-One WP Security (Login Lockout).
	 *
	 * AIOS вешает block_ip_if_locked на фильтр authenticate (приоритет 1):
	 * при заблокированном IP вызов wp_authenticate() завершается HTML-страницей
	 * wp_die (503) вместо WP_Error — JSON-ответ REST ломается. Поэтому IP
	 * проверяем до аутентификации и отдаём корректный JSON 429.
	 *
	 * @return bool True, если AIOS активен и IP сейчас заблокирован.
	 */
	private function is_aios_login_locked() {
		// AIOS не установлен/активен — блокировки быть не может.
		if ( ! class_exists( 'AIOWPSecurity_User_Login' ) ) {
			return false;
		}

		// Блокировка логином не включена — таблица не участвует.
		$configs = get_option( 'aio_wp_security_configs', array() );
		if ( ! is_array( $configs ) || empty( $configs['aiowps_enable_login_lockdown'] ) ) {
			return false;
		}

		if ( empty( $GLOBALS['aio_wp_security'] ) || empty( $GLOBALS['aio_wp_security']->user_login_obj ) ) {
			return false;
		}

		// AIOS отключил lockout константой — не блокируем.
		if ( $GLOBALS['aio_wp_security']->is_login_lockdown_by_const() ) {
			return false;
		}

		$locked = $GLOBALS['aio_wp_security']->user_login_obj->check_locked_user();

		return ! empty( $locked );
	}

	/**
	 * Обработчик входа через REST.
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

		// Блокировка IP плагином AIOS: отдаём JSON 429 вместо HTML wp_die,
		// который otherwise ломает ответ REST изнутри wp_authenticate().
		if ( $this->is_aios_login_locked() ) {
			return new WP_Error(
				'too_many_attempts',
				__( 'Слишком много попыток входа. Подождите немного и попробуйте снова.', 'idpg-core' ),
				array( 'status' => 429 )
			);
		}

		$user = wp_authenticate( $email, $password );

		if ( is_wp_error( $user ) ) {
			return new WP_Error(
				'invalid_credentials',
				__( 'Неверный email или пароль.', 'idpg-core' ),
				array( 'status' => 401 )
			);
		}

		// Организатор, не подтвердивший регистрацию по ссылке из письма, не может войти.
		if ( IDPG_Organizer_Verify::instance()->is_pending( $user->ID ) ) {
			return new WP_Error(
				'registration_pending',
				__( 'Подтвердите регистрацию по ссылке из письма, отправленного на ваш email.', 'idpg-core' ),
				array( 'status' => 403 )
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
		// Honeypot (DEV-150). Поле `idpg_company` скрыто от людей, но бот его
		// заполняет. Отвечаем ровно тем же, что и успешная регистрация
		// организатора: аккаунт не создаётся, письмо не уходит, лимит
		// регистрации не расходуется. Проверка стоит до всего остального —
		// иначе бот успевает выяснить правила валидации по кодам ошибок.
		$honeypot = $request->get_param( 'idpg_company' );
		$is_bait  = is_string( $honeypot )
			? ( '' !== trim( $honeypot ) )
			: ( null !== $honeypot );

		if ( $is_bait ) {
			return $this->get_register_bait_response();
		}

		if ( is_user_logged_in() ) {
			return new WP_Error(
				'already_logged_in',
				__( 'Вы уже авторизованы.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

		$type     = $request->get_param( 'type' );

		// Регистрация обычных пользователей отключена — доступна только
		// организаторская. Проверка идёт до валидации схемы, поэтому `type`
		// объявлен необязательным: иначе WordPress отвечал бы на запрос без
		// него 400 rest_missing_callback_param, не доходя сюда.
		if ( 'organizer' !== $type ) {
			return new WP_Error(
				'registration_disabled',
				__( 'Регистрация доступна только организаторам.', 'idpg-core' ),
				array( 'status' => 403 )
			);
		}

		// Согласие на обработку персональных данных проверяется до
		// расхода лимита регистрации и до создания аккаунта.
		if ( ! $request->get_param( 'idpg_pdn_consent' ) ) {
			return new WP_Error(
				'pdn_consent_required',
				__( 'Требуется согласие на обработку персональных данных.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

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

		// Лимит регистрации проверяется и тратится последним шагом - уже после
		// всех проверок полей (DEV-33). Раньше счётчик увеличивался до
		// email_exists() и валидации организатора, из-за чего попытки с чужим
		// email или с пустым org_name расходовали лимит впустую, а валидный
		// пользователь с того же IP получал 429.
		$ip         = IDPG_Auth_Rate_Limiter::instance()->get_client_ip();
		$rate_check = IDPG_Auth_Rate_Limiter::instance()->check_registration_attempt( $ip );

		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		// Запоминаем пароль для приветственного письма организатору (до хэширования).
		if ( 'organizer' === $type ) {
			IDPG_Auth_Emails::remember_password( $email, $password );
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

		// Дополнительные поля организатора сохраняем сразу (до подтверждения),
		// чтобы после перехода по ссылке аккаунт был полностью готов.
		if ( 'organizer' === $type ) {
			// Роль event_organizer выдаётся только после подтверждения по ссылке.
			// default_role может быть event_organizer, поэтому явно ставим subscriber.
			$org_user = new WP_User( $user_id );
			$org_user->set_role( 'subscriber' );

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

			// Ключ подтверждения и письмо со ссылкой. Роль event_organizer
			// выдаётся только после перехода по ссылке из письма.
			$verify_key = IDPG_Organizer_Verify::instance()->prepare( $user_id );
			IDPG_Auth_Emails::instance()->send_organizer_verification_email( $user_id, $verify_key );

			/**
			 * Действие после регистрации организатора.
			 *
			 * @param int    $user_id ID нового пользователя.
			 * @param string $type    Тип регистрации: user|organizer.
			 */
			do_action( 'idpg_auth_after_register', $user_id, $type );

			// Фиксация согласия на обработку персональных данных.
			IDPG_Privacy::record_consent( $user_id, 'organizer' );

			return new WP_REST_Response(
				array(
					'success'       => true,
					'user_id'       => $user_id,
					'pending_email' => true,
					'message'       => __( 'Регистрация почти готова! Мы отправили на ваш email письмо со ссылкой для подтверждения. Перейдите по ней, чтобы войти в кабинет организатора.', 'idpg-core' ),
					'redirect_url'  => home_url( '/login/' ),
				),
				201
			);
		}

		// Автоматическая авторизация после регистрации (обычный пользователь).
		$user          = new WP_User( $user_id );
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
	 * Ответ на регистрацию с заполненным honeypot (приманка для ботов).
	 *
	 * Повторяет успешную регистрацию организатора один в один: тот же код
	 * 201, тот же набор ключей и тот же текст. Отличить приманку можно
	 * было бы по коду ответа, поэтому 200 здесь не годится — настоящая
	 * регистрация отвечает 201. Различие остаётся одно, `user_id`: подставить
	 * настоящий ID без создания пользователя нельзя, поэтому здесь 0.
	 *
	 * Текст и код намеренно продублированы из `handle_register()`: общий
	 * хелпер потребовал бы правки работающего кода регистрации, а при
	 * расхождении с ним honeypot перестаёт быть неотличимым.
	 *
	 * @return WP_REST_Response
	 */
	private function get_register_bait_response() {
		return new WP_REST_Response(
			array(
				'success'       => true,
				'user_id'       => 0,
				'pending_email' => true,
				'message'       => __( 'Регистрация почти готова! Мы отправили на ваш email письмо со ссылкой для подтверждения. Перейдите по ней, чтобы войти в кабинет организатора.', 'idpg-core' ),
				'redirect_url'  => home_url( '/login/' ),
			),
			201
		);
	}

	/**
	 * Проверка права на выход из системы.
	 *
	 * Порядок проверок важен: nonce проверяется **до** проверки авторизации.
	 * Ядро WordPress при запросе без nonce обнуляет текущего пользователя
	 * (`wp_set_current_user( 0 )` в `rest_cookie_check_errors()`), поэтому
	 * проверка `is_user_logged_in()` первой вернула бы 401 и замаскировала бы
	 * настоящую причину отказа — отсутствие или подделку токена.
	 *
	 * @param WP_REST_Request $request Текущий запрос.
	 * @return true|WP_Error
	 */
	public function can_logout( $request ) {
		$nonce = $this->check_rest_nonce( $request );

		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'idpg_logout_not_logged_in',
				__( 'Необходима авторизация.', 'idpg-core' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error(
				'idpg_logout_forbidden',
				__( 'Недостаточно прав для выхода из системы.', 'idpg-core' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Проверка nonce `wp_rest` входящего запроса.
	 *
	 * Токен принимается в двух форматах, которые понимает и ядро WordPress:
	 * заголовок `X-WP-Nonce` и параметр `_wpnonce`.
	 *
	 * Отсутствие и невалидность различаются отдельными кодами: «нет токена» —
	 * это неверно собранный клиент, «токен не подошёл» — подделка или протухание.
	 *
	 * @param WP_REST_Request $request Текущий запрос.
	 * @return true|WP_Error
	 */
	private function check_rest_nonce( $request ) {
		$nonce = $request->get_header( 'x_wp_nonce' );

		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! $nonce || ! is_string( $nonce ) ) {
			return new WP_Error(
				'idpg_logout_nonce_missing',
				__( 'Отсутствует nonce безопасности. Обновите страницу и повторите вход.', 'idpg-core' ),
				array( 'status' => 403 )
			);
		}

		if ( ! wp_verify_nonce( $nonce, self::REST_NONCE_ACTION ) ) {
			return new WP_Error(
				'idpg_logout_nonce_invalid',
				__( 'Nonce безопасности недействителен. Обновите страницу и повторите вход.', 'idpg-core' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Выход из системы.
	 *
	 * @param WP_REST_Request|null $request Текущий запрос; null — прямой вызов из кода.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_logout( $request = null ) {
		// Повторная проверка nonce внутри обработчика: маршрут защищён
		// permission_callback, но обработчик вызывается и напрямую из кода
		// (в том числе из тестов), поэтому контракт держим в двух местах.
		if ( $request instanceof WP_REST_Request ) {
			$nonce = $this->check_rest_nonce( $request );

			if ( is_wp_error( $nonce ) ) {
				return $nonce;
			}
		}

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

		// Лимит по IP: ловит перебор множества разных email с одной машины,
		// чего лимит по адресу письма не видит.
		$ip_check = IDPG_Auth_Rate_Limiter::instance()->check_password_reset_ip_attempt( IDPG_Auth_Rate_Limiter::instance()->get_client_ip() );

		if ( is_wp_error( $ip_check ) ) {
			return $ip_check;
		}

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

		return home_url( '/' );
	}
}
