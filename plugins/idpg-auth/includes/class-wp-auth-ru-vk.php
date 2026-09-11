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
    const AUTH_URL           = 'https://id.vk.com/authorize';
    const TOKEN_URL          = 'https://id.vk.com/oauth2/auth';
    const USERINFO_URL       = 'https://id.vk.com/oauth2/user_info';
    const USERS_API_URL      = 'https://api.vk.com/method/users.get';
    const API_VERSION        = '5.199';
    const DEFAULT_USERNAME_BASE = 'vk_user';

    /**
     * VK ID-приложения работают только через OAuth 2.1 с PKCE:
     * authorize — GET id.vk.com/authorize (code_challenge S256), обмен кода —
     * POST id.vk.com/oauth2/auth?grant_type=…&code_verifier=… (code в form-теле,
     * client_secret не используется), профиль — POST id.vk.com/oauth2/user_info
     * (формат по эталону официального SDK @vkid/sdk). Старый oauth.vk.com для
     * VK ID-приложений возвращает Security Error.
     */
    const USE_PKCE           = true;

    protected function get_rest_routes() {
        return array(
            array( 'route' => '/vk/start',    'methods' => 'GET', 'callback' => array( $this, 'rest_start' ) ),
            array( 'route' => '/vk/callback', 'methods' => 'GET', 'callback' => array( $this, 'rest_callback' ) ),
        );
    }

    /**
     * VK ID требует OAuth 2.1 с PKCE.
     *
     * @return bool
     */
    protected function uses_pkce() {
        return self::USE_PKCE;
    }

    protected function auth_params( $state ) {
        return array(
            'client_id'    => $this->settings['vk_client_id'] ?? '',
            'redirect_uri' => static::get_callback_url(),
            'response_type' => 'code',
            'scope'        => 'vkid.personal_info email',
            'state'        => $state,
        );
    }

    /**
     * Дополнительные параметры authorize-запроса для OAuth 2.1 (PKCE).
     *
     * @param string $state   State.
     * @param string $verifier code_verifier (S256), сохранённый в transient.
     *
     * @return array
     */
    protected function extra_auth_params( $state, $verifier ) {
        return array(
            'code_challenge'        => self::pkce_s256( $verifier ),
            'code_challenge_method' => 'S256',
        );
    }

    protected function no_callback_message() {
        return 'ВКонтакте не вернул код авторизации.';
    }

    /**
     * Генерация code_verifier для PKCE (43-128 символов base64url).
     *
     * @return string
     */
    public static function pkce_generate_verifier() {
        // 48 случайных байт → base64url без padding (64 символа).
        return rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
    }

    /**
     * Преобразование code_verifier в code_challenge (S256).
     *
     * @param string $verifier code_verifier.
     *
     * @return string
     */
    public static function pkce_s256( $verifier ) {
        return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
    }

    /**
     * Ключ transient для code_verifier.
     *
     * @param string $state State из authorize-запроса.
     *
     * @return string
     */
    protected function verifier_transient_key( $state ) {
        return 'wp_auth_ru_vk_pkce_' . $state;
    }

    /**
     * Сохранение code_verifier до обмена кода (10 минут).
     *
     * @param string $state    State.
     * @param string $verifier code_verifier.
     */
    protected function store_pkce_verifier( $state, $verifier ) {
        set_transient( $this->verifier_transient_key( $state ), $verifier, 10 * MINUTE_IN_SECONDS );
    }

    /**
     * Извлечение code_verifier (однократное).
     *
     * @param string $state State.
     *
     * @return string Пустая строка, если verifier не найден/истёк.
     */
    protected function get_pkce_verifier( $state ) {
        $verifier = (string) get_transient( $this->verifier_transient_key( $state ) );
        delete_transient( $this->verifier_transient_key( $state ) );
        return $verifier;
    }

    protected function exchange_code_for_token( $code ) {
        // VK ID (OAuth 2.1): обмен кода требует code_verifier из PKCE-флоу.
        // Он сохранён в transient под ключом verifier_transient_key(state) —
        // вытаскиваем по state из callback-запроса. VK отдаёт device_id
        // вместе с code в callback-URL.
        $request_state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        $device_id     = isset( $_GET['device_id'] ) ? sanitize_text_field( wp_unslash( $_GET['device_id'] ) ) : '';
        $verifier      = $request_state ? $this->get_pkce_verifier( $request_state ) : '';

        if ( '' !== $verifier ) {
            // Основной путь — VK ID (по эталону официального SDK @vkid/sdk):
            // POST /oauth2/auth?grant_type=authorization_code&redirect_uri=…
            // &client_id=…&code_verifier=…&state=…&device_id=…
            // code передаётся в form-теле, client_secret НЕ используется.
            $query = add_query_arg( array(
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => static::get_callback_url(),
                'client_id'     => $this->settings['vk_client_id'] ?? '',
                'code_verifier' => $verifier,
                'state'         => $request_state,
                'device_id'     => $device_id,
            ), self::TOKEN_URL );

            $response = wp_remote_post( $query, array(
                'timeout' => 20,
                'body'    => array( 'code' => $code ),
            ) );
        } else {
            // Fallback на классический обмен (для старых standalone-приложений
            // oauth.vk.com, если приложение когда-либо сменят обратно).
            $response = wp_remote_post( 'https://oauth.vk.com/access_token', array(
                'timeout' => 20,
                'body'    => array(
                    'client_id'     => $this->settings['vk_client_id']     ?? '',
                    'client_secret' => $this->settings['vk_client_secret'] ?? '',
                    'redirect_uri'  => static::get_callback_url(),
                    'code'          => $code,
                ),
            ) );
        }

        if ( is_wp_error( $response ) ) {
            $msg = 'Ошибка запроса токена: ' . $response->get_error_message();
            WP_Auth_Ru_Logger::log( 'ERROR', self::PROVIDER, $msg );
            return new WP_Error( 'vk_token_request', $msg );
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) ) {
            // Не-JSON (например, HTML 404) — логируем фрагмент для диагностики.
            $msg = 'VK ответил не-JSON на запрос токена (HTTP ' . $http_code . ').';
            WP_Auth_Ru_Logger::log( 'ERROR', self::PROVIDER, $msg, array( 'http' => $http_code, 'body' => mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 200 ) ) );
            return new WP_Error( 'vk_token_bad_response', $msg );
        }

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
        return $body;
    }

    protected function get_user_profile( $token_data ) {
        // 1. Основной путь VK ID: POST id.vk.com/oauth2/user_info.
        // Возвращает {"user": {user_id, first_name, last_name, email, avatar}}.
        $response = wp_remote_post( self::USERINFO_URL, array(
            'timeout' => 20,
            'body'    => array(
                'client_id'    => $this->settings['vk_client_id'] ?? '',
                'access_token' => $token_data['access_token'],
            ),
        ) );

        if ( ! is_wp_error( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( is_array( $data ) && ! empty( $data['user']['user_id'] ) ) {
                $u = $data['user'];

                $profile = array(
                    'first_name' => (string) ( $u['first_name'] ?? '' ),
                    'last_name'  => (string) ( $u['last_name']  ?? '' ),
                    'email'      => (string) ( $u['email']      ?? '' ),
                    'photo'      => (string) ( $u['avatar']     ?? '' ),
                    'vk_id'      => (string) ( $u['user_id'] ?? '' ),
                );

                WP_Auth_Ru_Logger::log( 'INFO', self::PROVIDER, 'Профиль получен (user_info): vk_id=' . $profile['vk_id'] . ', email=' . WP_Auth_Ru_Logger::mask_email( $profile['email'] ) );
                return $profile;
            }

            WP_Auth_Ru_Logger::log( 'WARNING', self::PROVIDER, 'user_info не вернул профиль, пробую users.get', array( 'http' => wp_remote_retrieve_response_code( $response ) ) );
        } else {
            WP_Auth_Ru_Logger::log( 'WARNING', self::PROVIDER, 'Ошибка запроса user_info: ' . $response->get_error_message() . ', пробую users.get' );
        }

        // 2. Fallback: legacy API users.get (токен VK ID обычно работает
        // и с ним, формат ответа — response[0]).
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

        WP_Auth_Ru_Logger::log( 'INFO', self::PROVIDER, 'Профиль получен (users.get): vk_id=' . $profile['vk_id'] . ', email=' . WP_Auth_Ru_Logger::mask_email( $profile['email'] ) );
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
        ?><style id="wp-auth-ru-vk-styles">.wp-auth-ru-btn--vk{background:#5281B8}</style><?php
    }
}