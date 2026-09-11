<?php
/**
 * Авторизация через Яндекс ID (Яндекс OAuth 2.0).
 *
 * Redirect URI: https://ВАШ-САЙТ.ru/wp-json/wp-auth-ru/v1/yandex/callback
 * Права:        login:info, login:email
 *
 * Общий OAuth-флоу реализован в WP_Auth_Ru_OAuth_Provider.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Auth_Ru_Yandex extends WP_Auth_Ru_OAuth_Provider {

    const PROVIDER           = 'yandex';
    const PROVIDER_LABEL     = 'Яндекс';
    const ENABLED_KEY        = 'yandex_enabled';
    const CLIENT_ID_KEY      = 'yandex_client_id';
    const CLIENT_SECRET_KEY  = 'yandex_client_secret';
    const START_ROUTE        = 'yandex/start';
    const CALLBACK_ROUTE     = 'yandex/callback';
    const ERROR_QUERY_ARG    = 'yandex_oauth_error';
    const ERROR_HEADER       = 'Ошибка входа через Яндекс:';
    const STATE_META_KEY     = 'wp_auth_ru_yandex_oauth_id';
    const NONCE_KEY          = 'wp_auth_ru_yandex_state';
    const AUTH_URL           = 'https://oauth.yandex.ru/authorize';
    const TOKEN_URL          = 'https://oauth.yandex.ru/token';
    const USERINFO_URL       = 'https://login.yandex.ru/info?format=json';
    const DEFAULT_USERNAME_BASE = 'yandex_user';

    protected function get_rest_routes() {
        return array(
            array( 'route' => '/yandex/start',    'methods' => 'GET', 'callback' => array( $this, 'rest_start' ) ),
            array( 'route' => '/yandex/callback', 'methods' => 'GET', 'callback' => array( $this, 'rest_callback' ) ),
        );
    }

    protected function auth_params( $state ) {
        return array(
            'response_type' => 'code',
            'client_id'     => $this->settings['yandex_client_id'] ?? '',
            'redirect_uri'  => static::get_callback_url(),
            'state'         => $state,
            'force_confirm' => 'yes',
        );
    }

    protected function no_callback_message() {
        return 'Яндекс не вернул код авторизации.';
    }

    protected function exchange_code_for_token( $code ) {
        $response = wp_remote_post( self::TOKEN_URL, array(
            'timeout' => 20,
            'body'    => array(
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'client_id'     => $this->settings['yandex_client_id']     ?? '',
                'client_secret' => $this->settings['yandex_client_secret'] ?? '',
                'redirect_uri'  => static::get_callback_url(),
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            $msg = 'Ошибка запроса токена: ' . $response->get_error_message();
            WP_Auth_Ru_Logger::log( 'ERROR', self::PROVIDER, $msg );
            return new WP_Error( 'yandex_token_request', $msg );
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $body['error'] ) ) {
            $msg = 'Яндекс: ' . ( $body['error_description'] ?? $body['error'] );
            WP_Auth_Ru_Logger::log( 'ERROR', self::PROVIDER, $msg, array( 'http' => $http_code ) );
            return new WP_Error( 'yandex_token_error', $msg );
        }

        if ( empty( $body['access_token'] ) ) {
            $msg = 'Яндекс не вернул access_token.';
            WP_Auth_Ru_Logger::log( 'ERROR', self::PROVIDER, $msg, array( 'http' => $http_code, 'body' => wp_remote_retrieve_body( $response ) ) );
            return new WP_Error( 'yandex_no_token', $msg );
        }

        WP_Auth_Ru_Logger::log( 'SUCCESS', self::PROVIDER, 'Токен получен (HTTP ' . $http_code . ')' );
        return $body['access_token'];
    }

    protected function get_user_profile( $access_token ) {
        $response = wp_remote_get( self::USERINFO_URL, array(
            'timeout' => 20,
            'headers' => array( 'Authorization' => 'OAuth ' . $access_token ),
        ) );

        if ( is_wp_error( $response ) ) {
            $msg = 'Ошибка запроса профиля: ' . $response->get_error_message();
            WP_Auth_Ru_Logger::log( 'ERROR', self::PROVIDER, $msg );
            return new WP_Error( 'yandex_profile_request', $msg );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body ) || ! is_array( $body ) ) {
            $msg = 'Не удалось получить данные профиля от Яндекс.';
            WP_Auth_Ru_Logger::log( 'ERROR', self::PROVIDER, $msg, array( 'http' => wp_remote_retrieve_response_code( $response ) ) );
            return new WP_Error( 'yandex_profile_empty', $msg );
        }

        WP_Auth_Ru_Logger::log( 'INFO', self::PROVIDER, 'Профиль получен: ' . WP_Auth_Ru_Logger::mask_email( $body['default_email'] ?? '' ) );
        return $body;
    }

    protected function normalize_profile( $profile ) {
        $first_name = sanitize_text_field( $profile['first_name'] ?? '' );
        $last_name  = sanitize_text_field( $profile['last_name']  ?? '' );
        $login      = sanitize_text_field( $profile['login']      ?? '' );
        $real_name  = sanitize_text_field( $profile['real_name']  ?? '' );
        $display    = $real_name ?: trim( $first_name . ' ' . $last_name ) ?: $login ?: 'Яндекс Пользователь';

        return array(
            'id'            => (string) ( $profile['id'] ?? '' ),
            'email'         => $profile['default_email'] ?? '',
            'first_name'    => $first_name,
            'last_name'     => $last_name,
            'display'       => $display,
            'username_base' => $login ?: $display,
        );
    }

    protected function email_required() {
        return true;
    }

    protected function no_email_message() {
        return 'Яндекс не предоставил email. Разрешите доступ к email в настройках OAuth-приложения.';
    }

    protected function sync_email_on_login() {
        return true;
    }

    protected function log_subject( $email, $provider_id ) {
        return WP_Auth_Ru_Logger::mask_email( $email );
    }

    private function yandex_icon() {
        return '<svg class="wp-auth-ru-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="12" fill="#FC3F1D"/><path d="M13.8 6H12.12C10.08 6 8.88 7.08 8.88 8.88C8.88 10.44 9.6 11.4 11.04 12.36L12.12 13.08L8.76 18H11.04L14.16 13.44L13.2 12.84C12.0 12.0 11.28 11.28 11.28 8.76C11.28 8.04 11.64 7.44 12.48 7.44H13.8V6Z" fill="white"/><path d="M13.8 6H15.12V18H13.8V6Z" fill="white"/></svg>';
    }

    public function render_button_wp() {
        $redirect_to = ! empty( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
        ?>
        <div class="wp-auth-ru-wrap">
            <a href="<?php echo esc_url( static::get_start_url( $redirect_to ) ); ?>" class="wp-auth-ru-btn wp-auth-ru-btn--yandex">
                <?php echo $this->yandex_icon(); ?> Войти через Яндекс
            </a>
            <div class="wp-auth-ru-divider"><span>или</span></div>
        </div>
        <?php
    }

    public function render_button_woo() {
        if ( is_user_logged_in() ) return;
        $redirect_to = ! empty( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
        ?>
        <div class="wp-auth-ru-wrap wp-auth-ru-wrap--woo">
            <a href="<?php echo esc_url( static::get_start_url( $redirect_to ) ); ?>" class="wp-auth-ru-btn wp-auth-ru-btn--yandex">
                <?php echo $this->yandex_icon(); ?> Войти через Яндекс
            </a>
            <div class="wp-auth-ru-divider"><span>или</span></div>
        </div>
        <?php
    }

    public function render_checkout_banner() {
        if ( is_user_logged_in() ) return;
        $url = static::get_start_url( wc_get_checkout_url() );
        $this->output_styles();
        ?>
        <div class="wp-auth-ru-checkout-banner">
            <p class="wp-auth-ru-checkout-title">Быстрый вход перед оформлением:</p>
            <a href="<?php echo esc_url( $url ); ?>" class="wp-auth-ru-btn wp-auth-ru-btn--yandex wp-auth-ru-btn--checkout">
                <?php echo $this->yandex_icon(); ?> Войти через Яндекс
            </a>
            <p class="wp-auth-ru-hint">После входа вернётесь к оформлению заказа</p>
        </div>
        <?php
    }

    public function output_styles() {
        static $printed = false;
        if ( $printed ) return;
        $printed = true;
        ?>
<style id="wp-auth-ru-styles">
.wp-auth-ru-wrap{margin-bottom:20px}.wp-auth-ru-wrap--woo{margin-bottom:24px}
.wp-auth-ru-checkout-banner{margin-bottom:24px;padding:20px;border:1px solid #ffe0d0;border-radius:12px;background:#fff8f6}
.wp-auth-ru-checkout-title{margin:0 0 10px;font-weight:600;font-size:15px}
.wp-auth-ru-hint{font-size:12px;color:#6b7280;margin:8px 0 0}
.wp-auth-ru-btn{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:12px 24px;border-radius:8px;text-decoration:none!important;font-size:16px;font-weight:600;line-height:1.5;box-sizing:border-box;cursor:pointer;border:none;transition:opacity .2s ease;color:#fff!important;margin-bottom:10px}
.wp-auth-ru-btn:hover{opacity:.88;color:#fff!important}
.wp-auth-ru-btn:active{opacity:.8}
.wp-auth-ru-btn--yandex{background:#FC3F1D}
.wp-auth-ru-btn--mailru{background:#005FF9}
.wp-auth-ru-btn--checkout{font-size:16px;padding:12px 24px}
.wp-auth-ru-icon{width:22px;height:22px;flex-shrink:0;display:block}
.wp-auth-ru-divider{position:relative;text-align:center;margin:4px 0 14px;color:#aaa;font-size:13px}
.wp-auth-ru-divider::before{content:"";position:absolute;top:50%;left:0;right:0;border-top:1px solid #ddd}
.wp-auth-ru-divider span{position:relative;background:#fff;padding:0 12px}
</style>
        <?php
    }
}