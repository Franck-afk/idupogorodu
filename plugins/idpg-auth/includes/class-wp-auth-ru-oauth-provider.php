<?php
/**
 * Базовый класс OAuth 2.0-провайдера (VK, Яндекс ID).
 *
 * Реализует стандартный поток авторизации:
 * /start → редирект на провайдера → /callback (код) → токен → профиль →
 * вход/создание пользователя.
 *
 * Провайдер описывает через константы/методы только свои отличия:
 * URL точек входа, параметры authorize-запроса, получение токена и профиля.
 *
 * @package WP_Auth_Ru
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class WP_Auth_Ru_OAuth_Provider extends WP_Auth_Ru_Provider {

    /**
     * URL callback (для настроек приложений провайдеров и обмена токена).
     *
     * @return string
     */
    public static function get_callback_url() {
        return rest_url( self::REST_NAMESPACE . '/' . static::CALLBACK_ROUTE );
    }

    /**
     * URL старта OAuth-потока.
     *
     * @param string $redirect_to Куда вернуть пользователя после входа.
     *
     * @return string
     */
    public static function get_start_url( $redirect_to = '' ) {
        $url = rest_url( self::REST_NAMESPACE . '/' . static::START_ROUTE );
        if ( $redirect_to ) $url = add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $url );
        return $url;
    }

    /**
     * Алиас для get_start_url() (обратная совместимость).
     *
     * @param string $redirect_to Куда вернуть пользователя после входа.
     *
     * @return string
     */
    public static function get_auth_start_url( $redirect_to = '' ) {
        return static::get_start_url( $redirect_to );
    }

    /**
     * Ключ transient для state.
     *
     * @param string $state State из authorize-запроса.
     *
     * @return string
     */
    protected function state_transient_key( $state ) {
        return 'wp_auth_ru_' . static::PROVIDER . '_state_' . $state;
    }

    /**
     * Ключ transient для redirect_to.
     *
     * @param string $state State из authorize-запроса.
     *
     * @return string
     */
    protected function redir_transient_key( $state ) {
        return 'wp_auth_ru_' . static::PROVIDER . '_redir_' . $state;
    }

    /**
     * REST: старт OAuth-потока (редирект на authorize-URL провайдера).
     *
     * @param WP_REST_Request $request Запрос.
     */
    public function rest_start( WP_REST_Request $request ) {
        if ( is_user_logged_in() ) {
            wp_safe_redirect( home_url() );
            exit;
        }

        $state = wp_create_nonce( static::NONCE_KEY );
        set_transient( $this->state_transient_key( $state ), 1, 10 * MINUTE_IN_SECONDS );
        WP_Auth_Ru_Logger::log( 'INFO', static::PROVIDER, 'OAuth flow начат', array( 'ip' => $_SERVER['REMOTE_ADDR'] ?? '' ) );

        $redirect_to = $request->get_param( 'redirect_to' );
        if ( $redirect_to ) {
            set_transient( $this->redir_transient_key( $state ), esc_url_raw( urldecode( $redirect_to ) ), 10 * MINUTE_IN_SECONDS );
        }

        // Защита от некорректной настройки: провайдер включён, но ключи не заполнены.
        $client_id = trim( (string) ( $this->settings[ static::CLIENT_ID_KEY ] ?? '' ) );
        if ( '' === $client_id ) {
            $msg = static::ERROR_HEADER . ' Приложение не настроено: укажите Client ID в настройках плагина.';
            WP_Auth_Ru_Logger::log( 'ERROR', static::PROVIDER, $msg );
            wp_safe_redirect( add_query_arg( static::ERROR_QUERY_ARG, urlencode( $msg ), wp_login_url() ) );
            exit;
        }

        wp_redirect( static::AUTH_URL . '?' . http_build_query( $this->auth_params( $state ) ) );
        exit;
    }

    /**
     * Параметры authorize-запроса к провайдеру.
     *
     * @param string $state State.
     *
     * @return array
     */
    abstract protected function auth_params( $state );

    /**
     * REST: callback провайдера (обмен кода на токен, профиль, вход).
     *
     * @param WP_REST_Request $request Запрос.
     */
    public function rest_callback( WP_REST_Request $request ) {
        $error = $request->get_param( 'error' );
        if ( $error ) {
            $msg = sanitize_text_field( $error );
            WP_Auth_Ru_Logger::log( 'ERROR', static::PROVIDER, static::PROVIDER_LABEL . ' вернул ошибку: ' . $msg );
            wp_safe_redirect( add_query_arg( static::ERROR_QUERY_ARG, urlencode( $msg ), wp_login_url() ) );
            exit;
        }

        $code  = sanitize_text_field( $request->get_param( 'code' )  ?? '' );
        $state = sanitize_text_field( $request->get_param( 'state' ) ?? '' );

        if ( empty( $code ) ) {
            $this->redirect_with_error( $this->no_callback_message() );
            return;
        }

        if ( empty( $state ) || ! get_transient( $this->state_transient_key( $state ) ) ) {
            WP_Auth_Ru_Logger::log( 'WARNING', static::PROVIDER, 'Недействительный state — возможна CSRF-атака' );
            $this->redirect_with_error( 'Недействительный state. Попробуйте снова.' );
            return;
        }
        delete_transient( $this->state_transient_key( $state ) );

        $token = $this->exchange_code_for_token( $code );
        if ( is_wp_error( $token ) ) {
            $this->redirect_with_error( $token->get_error_message() );
            return;
        }

        $profile = $this->get_user_profile( $token );
        if ( is_wp_error( $profile ) ) {
            $this->redirect_with_error( $profile->get_error_message() );
            return;
        }

        $user_id = $this->login_or_create_user( $profile );
        if ( is_wp_error( $user_id ) ) {
            $this->redirect_with_error( $user_id->get_error_message() );
            return;
        }

        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true );
        $user_obj = get_userdata( $user_id );
        if ( $user_obj ) do_action( 'wp_login', $user_obj->user_login, $user_obj );

        $redirect_to = get_transient( $this->redir_transient_key( $state ) );
        delete_transient( $this->redir_transient_key( $state ) );
        if ( empty( $redirect_to ) || strpos( $redirect_to, home_url() ) !== 0 ) {
            $redirect_to = apply_filters( 'wp_auth_ru_' . static::PROVIDER . '_after_login_redirect', home_url() );
        }
        wp_safe_redirect( $redirect_to );
        exit;
    }

    /**
     * Обмен code на токен (формат токена зависит от провайдера).
     *
     * @param string $code Код авторизации.
     *
     * @return mixed|WP_Error Токен или объект ошибки.
     */
    abstract protected function exchange_code_for_token( $code );

    /**
     * Получение профиля по токену (формат зависит от провайдера).
     *
     * @param mixed $token Токен из exchange_code_for_token().
     *
     * @return array|WP_Error Профиль или объект ошибки.
     */
    abstract protected function get_user_profile( $token );

    /**
     * Текст ошибки: провайдер не вернул код авторизации.
     *
     * @return string
     */
    abstract protected function no_callback_message();

    /**
     * Редирект на страницу входа с сообщением об ошибке.
     *
     * @param string $message Текст ошибки.
     */
    protected function redirect_with_error( $message ) {
        WP_Auth_Ru_Logger::log( 'ERROR', static::PROVIDER, $message );
        wp_safe_redirect( add_query_arg( static::ERROR_QUERY_ARG, urlencode( $message ), wp_login_url() ) );
        exit;
    }
}