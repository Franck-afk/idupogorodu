<?php
/**
 * Базовый класс провайдера входа через соцсети/мессенджеры.
 *
 * Общая логика для VK, Яндекс ID и MAX:
 * - синглтон + подключение хуков;
 * - REST-маршруты провайдера;
 * - вход/создание пользователя (по провайдерскому ID, по email, иначе регистрация);
 * - генерация уникального логина, вывод кнопок и стилей, показ ошибок.
 *
 * Провайдеры описывают только свои отличия через константы и хуки-методы.
 *
 * @package WP_Auth_Ru
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class WP_Auth_Ru_Provider {

    const REST_NAMESPACE = 'wp-auth-ru/v1';

    /**
     * Реестр синглтонов: по одному экземпляру на каждый класс провайдера.
     *
     * @var array
     */
    protected static $instances = array();

    /**
     * Настройки плагина (опция idpg_auth_settings).
     *
     * @var array
     */
    protected $settings = array();

    /**
     * Возвращает экземпляр конкретного провайдера (late static binding).
     *
     * @return static
     */
    public static function instance() {
        $class = get_called_class();
        if ( empty( static::$instances[ $class ] ) ) {
            static::$instances[ $class ] = new static();
        }
        return static::$instances[ $class ];
    }

    /**
     * Конструктор: общие хуки для всех провайдеров.
     */
    private function __construct() {
        $this->settings = self::merge_env_settings( get_option( IDPG_AUTH_OPTIONS_KEY, array() ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        if ( empty( $this->settings[ static::ENABLED_KEY ] ) ) {
            return;
        }
        add_action( 'login_form',            array( $this, 'render_button_wp' ) );
        add_action( 'register_form',         array( $this, 'render_button_wp' ) );
        add_action( 'login_enqueue_scripts', array( $this, 'output_styles' ) );
        add_action( 'login_message',         array( $this, 'show_error' ) );
        if ( class_exists( 'WooCommerce' ) ) {
            add_action( 'woocommerce_login_form_start',     array( $this, 'render_button_woo' ) );
            add_action( 'woocommerce_register_form_start',  array( $this, 'render_button_woo' ) );
            add_action( 'woocommerce_before_checkout_form', array( $this, 'render_checkout_banner' ) );
        }
        add_action( 'wp_head', array( $this, 'maybe_output_frontend_styles' ) );
        $this->register_additional_hooks();
    }

    /**
     * Подстраховка настроек из wp-config.php (константы IDPG_AUTH_*).
     *
     * Переносы БД/деплой могут потерять значения опции idpg_auth_settings.
     * Критичные ключи (client_id/secret, enabled) можно задать константами:
     *   define( 'IDPG_AUTH_VK_CLIENT_ID', '54748265' );
     *   define( 'IDPG_AUTH_VK_CLIENT_SECRET', '...' );
     *   define( 'IDPG_AUTH_YANDEX_CLIENT_ID', '...' );
     *   define( 'IDPG_AUTH_VK_ENABLED', true );
     * Константы приоритетнее опции: значение из БД используется, только если
     * константа не задана (кроме пустой опции — тогда берётся константа).
     *
     * @param array $settings Настройки из БД.
     *
     * @return array Настройки с наложением констант.
     */
    private static function merge_env_settings( $settings ) {
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        $map = array(
            'IDPG_AUTH_VK_ENABLED'         => 'vk_enabled',
            'IDPG_AUTH_VK_CLIENT_ID'       => 'vk_client_id',
            'IDPG_AUTH_VK_CLIENT_SECRET'   => 'vk_client_secret',
            'IDPG_AUTH_YANDEX_ENABLED'     => 'yandex_enabled',
            'IDPG_AUTH_YANDEX_CLIENT_ID'   => 'yandex_client_id',
            'IDPG_AUTH_YANDEX_CLIENT_SECRET' => 'yandex_client_secret',
            'IDPG_AUTH_MAX_ENABLED'        => 'max_enabled',
            'IDPG_AUTH_MAX_BOT_TOKEN'      => 'max_bot_token',
            'IDPG_AUTH_MAX_BOT_USERNAME'   => 'max_bot_username',
            'IDPG_AUTH_COMPLIANCE_MODE'    => 'compliance_mode',
        );

        foreach ( $map as $const => $key ) {
            if ( defined( $const ) ) {
                $settings[ $key ] = constant( $const );
            }
        }

        return $settings;
    }

    /**
     * Дополнительные хуки провайдера (по умолчанию пусто).
     */
    protected function register_additional_hooks() {}

    /**
     * Регистрирует REST-маршруты провайдера.
     */
    public function register_rest_routes() {
        foreach ( $this->get_rest_routes() as $route ) {
            register_rest_route( self::REST_NAMESPACE, $route['route'], array(
                'methods'             => $route['methods'],
                'callback'            => $route['callback'],
                'permission_callback' => '__return_true',
            ) );
        }
    }

    /**
     * Список REST-маршрутов провайдера: {route, methods, callback}.
     *
     * @return array
     */
    abstract protected function get_rest_routes();

    /**
     * Вывод стилей кнопок (только на страницах входа).
     */
    public function maybe_output_frontend_styles() {
        if ( is_page( 'login' ) || ( function_exists( 'is_account_page' ) && is_account_page() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
            $this->output_styles();
        }
    }

    /**
     * Стили кнопки провайдера.
     */
    abstract public function output_styles();

    /**
     * Показ ошибок провайдера на странице логина (login_message).
     *
     * @param string $message Текущее сообщение.
     *
     * @return string
     */
    public function show_error( $message ) {
        if ( ! empty( $_GET[ static::ERROR_QUERY_ARG ] ) ) {
            $err = sanitize_text_field( wp_unslash( $_GET[ static::ERROR_QUERY_ARG ] ) );
            $message .= '<p class="message" style="color:#d63638;background:#fff3f3;padding:8px 12px;border-radius:4px;margin-top:8px;"><strong>' . esc_html( static::ERROR_HEADER ) . '</strong> ' . esc_html( $err ) . '</p>';
        }
        return $message;
    }

    /**
     * Вход или создание пользователя по профилю провайдера.
     *
     * Порядок: 1) по провайдерскому ID (meta), 2) по email, 3) регистрация.
     *
     * @param array $profile Сырые данные профиля от провайдера.
     *
     * @return int|WP_Error ID пользователя или ошибка.
     */
    protected function login_or_create_user( $profile ) {
        $profile = $this->normalize_profile( $profile );

        $provider_id = (string) $profile['id'];
        $email       = sanitize_email( $profile['email'] );
        $first_name  = sanitize_text_field( $profile['first_name'] );
        $last_name   = sanitize_text_field( $profile['last_name'] );
        $display     = (string) $profile['display'];

        // 1) Найти по провайдерскому ID.
        if ( $provider_id ) {
            $found = get_users( array( 'meta_key' => static::STATE_META_KEY, 'meta_value' => $provider_id, 'number' => 1, 'fields' => 'ids' ) );
            if ( ! empty( $found ) ) {
                $uid = (int) $found[0];
                wp_update_user( array( 'ID' => $uid, 'display_name' => $display, 'first_name' => $first_name, 'last_name' => $last_name ) );
                if ( $this->sync_email_on_login() && $email && ! email_exists( $email ) ) {
                    $existing = get_userdata( $uid );
                    if ( $existing && $existing->user_email !== $email ) {
                        wp_update_user( array( 'ID' => $uid, 'user_email' => $email ) );
                    }
                }
                $this->log_login( $uid, $email, $provider_id );
                return $uid;
            }
        }

        // 2) Найти по email.
        if ( $email ) {
            $user_by_email = get_user_by( 'email', $email );
            if ( $user_by_email ) {
                $uid = $user_by_email->ID;
                if ( $provider_id ) update_user_meta( $uid, static::STATE_META_KEY, $provider_id );
                wp_update_user( array( 'ID' => $uid, 'display_name' => $display, 'first_name' => $first_name, 'last_name' => $last_name ) );
                $this->log_login_email( $uid, $email );
                return $uid;
            }
        }

        // 3) Создать нового пользователя.
        if ( $this->email_required() && empty( $email ) ) {
            $msg = $this->no_email_message();
            WP_Auth_Ru_Logger::log( 'ERROR', static::PROVIDER, $msg );
            return new WP_Error( static::PROVIDER . '_no_email', $msg );
        }

        $username = $this->make_unique_username( $profile['username_base'] );
        $user_args = array(
            'user_login'   => $username,
            'user_pass'    => wp_generate_password( 24, true, true ),
            'display_name' => $display,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'role'         => $this->new_user_role(),
        );
        if ( $email ) $user_args['user_email'] = $email;

        $user_id = wp_insert_user( $user_args );
        if ( is_wp_error( $user_id ) ) {
            WP_Auth_Ru_Logger::log( 'ERROR', static::PROVIDER, 'Ошибка создания пользователя: ' . $user_id->get_error_message() );
            return $user_id;
        }

        if ( $provider_id ) update_user_meta( $user_id, static::STATE_META_KEY, $provider_id );
        update_user_meta( $user_id, 'wp_auth_ru_provider', static::PROVIDER );
        $this->log_registration( $user_id, $email, $provider_id );
        return $user_id;
    }

    /**
     * Приводит профиль провайдера к единому формату.
     *
     * Возвращает: id, email, first_name, last_name, display, username_base.
     *
     * @param array $raw Сырой профиль.
     *
     * @return array
     */
    abstract protected function normalize_profile( $raw );

    /**
     * Обязателен ли email для создания нового пользователя.
     *
     * @return bool
     */
    protected function email_required() {
        return false;
    }

    /**
     * Текст ошибки при отсутствии email (если email_required()).
     *
     * @return string
     */
    protected function no_email_message() {
        return '';
    }

    /**
     * Обновлять email пользователя при входе по провайдерскому ID.
     *
     * @return bool
     */
    protected function sync_email_on_login() {
        return false;
    }

    /**
     * Роль нового пользователя.
     *
     * Берётся из `default_role` сайта (обычно subscriber), а не из
     * жёсткой роли организатора. Чтобы соц-вход выдавал другую роль,
     * используйте фильтр `idpg_auth_new_user_role`.
     *
     * @return string
     */
    protected function new_user_role() {
        $role = get_option( 'default_role', 'subscriber' );

        /**
         * Фильтр роли нового пользователя при соц-входе.
         *
         * @param string $role     Роль (из default_role).
         * @param string $provider Слаг провайдера (vk, yandex, max...).
         */
        return apply_filters( 'idpg_auth_new_user_role', $role, static::PROVIDER );
    }

    /**
     * Уникальный логин на основе имени/логина от провайдера.
     *
     * @param string $base_name Базовое имя.
     *
     * @return string
     */
    protected function make_unique_username( $base_name ) {
        $base = sanitize_user( strtolower( str_replace( array( ' ', '-', '.' ), '_', $base_name ) ), true );
        if ( empty( $base ) ) $base = static::DEFAULT_USERNAME_BASE;
        $username = $base; $i = 1;
        while ( username_exists( $username ) ) $username = $base . '_' . $i++;
        return $username;
    }

    /**
     * Тема письма лога для пользователя (email или ID).
     *
     * @param string $email       Email (может быть пустым).
     * @param string $provider_id ID у провайдера.
     *
     * @return string
     */
    abstract protected function log_subject( $email, $provider_id );

    /**
     * Журналирует вход по провайдерскому ID.
     *
     * @param int    $uid         ID пользователя WP.
     * @param string $email       Email.
     * @param string $provider_id ID у провайдера.
     */
    protected function log_login( $uid, $email, $provider_id ) {
        WP_Auth_Ru_Logger::log( 'SUCCESS', static::PROVIDER, 'Вход: пользователь #' . $uid . ' (' . $this->log_subject( $email, $provider_id ) . ')' );
    }

    /**
     * Журналирует вход по email.
     *
     * @param int    $uid   ID пользователя WP.
     * @param string $email Email.
     */
    protected function log_login_email( $uid, $email ) {
        WP_Auth_Ru_Logger::log( 'SUCCESS', static::PROVIDER, 'Вход по email: пользователь #' . $uid . ' (' . $this->log_subject( $email, '' ) . ')' );
    }

    /**
     * Журналирует регистрацию нового пользователя.
     *
     * @param int    $uid         ID пользователя WP.
     * @param string $email       Email.
     * @param string $provider_id ID у провайдера.
     */
    protected function log_registration( $uid, $email, $provider_id ) {
        WP_Auth_Ru_Logger::log( 'SUCCESS', static::PROVIDER, 'Регистрация: новый пользователь #' . $uid . ' (' . $this->log_subject( $email, $provider_id ) . ')' );
    }
}