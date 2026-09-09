<?php
/**
 * Авторизация через ВКонтакте (VK OAuth 2.0).
 *
 * Redirect URI: https://ВАШ-САЙТ.ru/wp-json/wp-auth-ru/v1/vk/callback
 * Email возвращается прямо в ответе token endpoint (особенность VK API).
 *
 * Общий OAuth-флоу реализован в WP_Auth_Ru_OAuth_Provider.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Auth_Ru_VK extends WP_Auth_Ru_OAuth_Provider {

    const PROVIDER           = 'vk';
    const PROVIDER_LABEL     = 'ВКонтакте';
    const ENABLED_KEY        = 'vk_enabled';
    const CLIENT_ID_KEY      = 'vk_client_id';
    const CLIENT_SECRET_KEY  = 'vk_client_secret';
    const START_ROUTE        = 'vk/start';
    const CALLBACK_ROUTE     = 'vk/callback';
    const ERROR_QUERY_ARG    = 'vk_oauth_error';
    const ERROR_HEADER       = 'Ошибка входа через VK:';
    const STATE_META_KEY     = 'wp_auth_ru_vk_oauth_id';
    const NONCE_KEY          = 'wp_auth_ru_vk_state';
    const AUTH_URL           = 'https://oauth.vk.com/authorize';
    const TOKEN_URL          = 'https://oauth.vk.com/access_token';
    const USERS_API_URL      = 'https://api.vk.com/method/users.get';
    const API_VERSION        = '5.199';
    const DEFAULT_USERNAME_BASE = 'vk_user';

    protected function get_rest_routes() {
        return array(
            array( 'route' => '/vk/start',    'methods' => 'GET', 'callback' => array( $this, 'rest_start' ) ),
            array( 'route' => '/vk/callback', 'methods' => 'GET', 'callback' => array( $this, 'rest_callback' ) ),
        );
    }

    protected function auth_params( $state ) {
        return array(
            'client_id'    => $this->settings['vk_client_id'] ?? '',
            'redirect_uri' => static::get_callback_url(),
            'response_type' => 'code',
            'scope'        => 'email',
            'state'        => $state,
            'v'            => self::API_VERSION,
        );
    }

    protected function no_callback_message() {
        return 'ВКонтакте не вернул код авторизации.';
    }

    protected function exchange_code_for_token( $code ) {
        $response = wp_remote_post( self::TOKEN_URL, array(
            'timeout' => 20,
            'body'    => array(
                'client_id'     => $this->settings['vk_client_id']     ?? '',
                'client_secret' => $this->settings['vk_client_secret'] ?? '',
                'redirect_uri'  => static::get_callback_url(),
                'code'          => $code,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            $msg = 'Ошибка запроса токена: ' . $response->get_error_message();
            WP_Auth_Ru_Logger::log( 'ERROR', self::PROVIDER, $msg );
            return new WP_Error( 'vk_token_request', $msg );
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $body['error'] ) ) {
            $msg = 'VK: ' . ( $body['error_description'] ?? $body['error'] );
            WP_Auth_Ru_Logger::log( 'ERROR', self::PROVIDER, $msg, array( 'http' => $http_code ) );
            return new WP_Error( 'vk_token_error', $msg );
        }

        if ( empty( $body['access_token'] ) ) {
            $msg = 'VK не вернул access_token.';
            WP_Auth_Ru_Logger::log( 'ERROR', self::PROVIDER, $msg, array( 'http' => $http_code ) );
            return new WP_Error( 'vk_no_token', $msg );
        }

        WP_Auth_Ru_Logger::log( 'SUCCESS', self::PROVIDER, 'Токен получен (HTTP ' . $http_code . ', email: ' . WP_Auth_Ru_Logger::mask_email( $body['email'] ?? '' ) . ')' );
        return $body; // VK возвращает email и user_id прямо здесь
    }

    protected function get_user_profile( $token_data ) {
        $response = wp_remote_get( add_query_arg( array(
            'user_ids'     => $token_data['user_id'] ?? '',
            'fields'       => 'first_name,last_name,photo_100',
            'access_token' => $token_data['access_token'],
            'v'            => self::API_VERSION,
            'lang'         => 'ru',
        ), self::USERS_API_URL ), array( 'timeout' => 20 ) );

        if ( is_wp_error( $response ) ) {
            $msg = 'Ошибка запроса профиля: ' . $response->get_error_message();
            WP_Auth_Ru_Logger::log( 'ERROR', self::PROVIDER, $msg );
            return new WP_Error( 'vk_profile_request', $msg );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['response'][0] ) ) {
            $msg = 'Не удалось получить данные профиля от VK.';
            WP_Auth_Ru_Logger::log( 'ERROR', self::PROVIDER, $msg, array( 'http' => wp_remote_retrieve_response_code( $response ) ) );
            return new WP_Error( 'vk_profile_empty', $msg );
        }

        $profile              = $body['response'][0];
        $profile['email']     = $token_data['email'] ?? '';
        $profile['vk_id']     = (string) ( $token_data['user_id'] ?? $profile['id'] ?? '' );

        WP_Auth_Ru_Logger::log( 'INFO', self::PROVIDER, 'Профиль получен: vk_id=' . $profile['vk_id'] . ', email=' . WP_Auth_Ru_Logger::mask_email( $profile['email'] ) );
        return $profile;
    }

    protected function normalize_profile( $profile ) {
        $first_name = sanitize_text_field( $profile['first_name'] ?? '' );
        $last_name  = sanitize_text_field( $profile['last_name']  ?? '' );
        $display    = trim( $first_name . ' ' . $last_name ) ?: 'VK Пользователь';
        $vk_id      = (string) ( $profile['vk_id'] ?? $profile['id'] ?? '' );

        return array(
            'id'            => $vk_id,
            'email'         => $profile['email'] ?? '',
            'first_name'    => $first_name,
            'last_name'     => $last_name,
            'display'       => $display,
            'username_base' => 'vk_' . ( $vk_id ?: $display ),
        );
    }

    protected function email_required() {
        return true;
    }

    protected function no_email_message() {
        return 'ВКонтакте не предоставил email. Разрешите доступ к email в настройках приложения.';
    }

    protected function log_subject( $email, $provider_id ) {
        return WP_Auth_Ru_Logger::mask_email( $email );
    }

    private function icon() {
        return '<img src="' . esc_url( IDPG_AUTH_PLUGIN_URL . 'assets/Image/vk-svgrepo-com.svg' ) . '" class="wp-auth-ru-icon" alt="VK" width="22" height="22">';
    }

    public function render_button_wp() {
        $redirect_to = ! empty( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
        ?><div class="wp-auth-ru-wrap"><a href="<?php echo esc_url( static::get_start_url( $redirect_to ) ); ?>" class="wp-auth-ru-btn wp-auth-ru-btn--vk"><?php echo $this->icon(); ?> Войти через ВКонтакте</a><div class="wp-auth-ru-divider"><span>или</span></div></div><?php
    }

    public function render_button_woo() {
        if ( is_user_logged_in() ) return;
        $redirect_to = ! empty( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
        ?><div class="wp-auth-ru-wrap wp-auth-ru-wrap--woo"><a href="<?php echo esc_url( static::get_start_url( $redirect_to ) ); ?>" class="wp-auth-ru-btn wp-auth-ru-btn--vk"><?php echo $this->icon(); ?> Войти через ВКонтакте</a><div class="wp-auth-ru-divider"><span>или</span></div></div><?php
    }

    public function render_checkout_banner() {
        if ( is_user_logged_in() ) return;
        ?><div class="wp-auth-ru-checkout-banner" style="border-color:#d0dcf0;background:#f0f4ff;"><p class="wp-auth-ru-checkout-title">Войдите для оформления заказа:</p><a href="<?php echo esc_url( static::get_start_url( wc_get_checkout_url() ) ); ?>" class="wp-auth-ru-btn wp-auth-ru-btn--vk wp-auth-ru-btn--checkout"><?php echo $this->icon(); ?> Войти через ВКонтакте</a><p class="wp-auth-ru-hint">После входа вернётесь к оформлению заказа</p></div><?php
    }

    public function output_styles() {
        static $printed = false;
        if ( $printed ) return;
        $printed = true;
        ?><style id="wp-auth-ru-vk-styles">.wp-auth-ru-btn--vk{background:#5281B8;box-shadow:0 2px 10px rgba(82,129,184,.35)}</style><?php
    }
}