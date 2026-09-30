<?php
/**
 * Авторизация через мессенджер MAX (max.ru) — Mini App HMAC-SHA256.
 *
 * MAX не поддерживает стандартный OAuth 2.0.
 * Авторизация через initData + HMAC-SHA256 (аналогично Telegram Web App).
 *
 * Общая логика входа/создания пользователя унаследована от WP_Auth_Ru_Provider.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Auth_Ru_Max extends WP_Auth_Ru_Provider {

    const PROVIDER           = 'max';
    const PROVIDER_LABEL     = 'MAX';
    const ENABLED_KEY        = 'max_enabled';
    const ERROR_QUERY_ARG    = 'max_oauth_error';
    const ERROR_HEADER       = 'Ошибка входа через MAX:';
    const NONCE_ACTION       = 'wp_auth_ru_max_nonce';
    const STATE_META_KEY     = 'wp_auth_ru_max_oauth_id';
    const DEFAULT_USERNAME_BASE = 'max_user';

    protected function get_rest_routes() {
        return array(
            array( 'route' => '/max/validate', 'methods' => 'POST', 'callback' => array( $this, 'rest_validate_initdata' ) ),
        );
    }

    protected function register_additional_hooks() {
        add_action( 'wp_footer', array( $this, 'inject_miniapp_js' ) );
    }

    // ─── HMAC-SHA256 валидация initData ──────────────────────────────────────

    private function validate_init_data( $init_data_raw ) {
        $bot_token = trim( $this->settings['max_bot_token'] ?? '' );
        if ( empty( $bot_token ) || empty( $init_data_raw ) ) return false;

        $params = array();
        parse_str( $init_data_raw, $params );

        $provided_hash = $params['hash'] ?? '';
        if ( empty( $provided_hash ) ) return false;
        unset( $params['hash'] );

        if ( ! empty( $params['auth_date'] ) && ( time() - (int) $params['auth_date'] ) > 3600 ) {
            WP_Auth_Ru_Logger::log( 'WARNING', 'max', 'initData просрочен: auth_date=' . $params['auth_date'] );
            return false;
        }

        ksort( $params );
        $data_check_string = implode( "\n", array_map( function( $k, $v ) { return "$k=$v"; }, array_keys( $params ), array_values( $params ) ) );
        $secret_key    = hash_hmac( 'sha256', $bot_token, 'WebAppData', true );
        $expected_hash = hash_hmac( 'sha256', $data_check_string, $secret_key );

        return hash_equals( $expected_hash, $provided_hash );
    }

    private function extract_user_from_initdata( $init_data_raw ) {
        $params = array();
        parse_str( $init_data_raw, $params );
        $user_json = $params['user'] ?? '';
        if ( empty( $user_json ) ) return null;
        $user = json_decode( $user_json, true );
        return is_array( $user ) ? $user : null;
    }

    // ─── REST: валидация initData ─────────────────────────────────────────────

    public function rest_validate_initdata( WP_REST_Request $request ) {
        $nonce = sanitize_text_field( $request->get_param( 'nonce' ) ?? '' );
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            WP_Auth_Ru_Logger::log( 'WARNING', 'max', 'Неверный nonce при валидации initData', array( 'ip' => $_SERVER['REMOTE_ADDR'] ?? '' ) );
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Security check failed' ), 403 );
        }

        if ( is_user_logged_in() ) {
            return new WP_REST_Response( array( 'success' => true, 'redirect' => home_url() ) );
        }

        $raw = sanitize_text_field( $request->get_param( 'init_data' ) ?? '' );
        if ( empty( $raw ) ) {
            WP_Auth_Ru_Logger::log( 'WARNING', 'max', 'Пустой init_data' );
            return new WP_REST_Response( array( 'success' => false, 'message' => 'init_data is empty' ), 400 );
        }

        WP_Auth_Ru_Logger::log( 'INFO', 'max', 'Получен initData, проверка подписи HMAC-SHA256' );

        if ( ! $this->validate_init_data( $raw ) ) {
            WP_Auth_Ru_Logger::log( 'ERROR', 'max', 'Неверная подпись MAX initData — отклонено' );
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid MAX signature' ), 403 );
        }

        $user_info = $this->extract_user_from_initdata( $raw );
        if ( empty( $user_info ) ) {
            WP_Auth_Ru_Logger::log( 'ERROR', 'max', 'В initData нет данных пользователя' );
            return new WP_REST_Response( array( 'success' => false, 'message' => 'No user data in initData' ), 400 );
        }

        $user_id = $this->login_or_create_user( $user_info );
        if ( is_wp_error( $user_id ) ) {
            WP_Auth_Ru_Logger::log( 'ERROR', 'max', 'Ошибка создания/входа: ' . $user_id->get_error_message() );
            return new WP_REST_Response( array( 'success' => false, 'message' => $user_id->get_error_message() ), 500 );
        }

        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true );
        $user_obj = get_userdata( $user_id );
        if ( $user_obj ) do_action( 'wp_login', $user_obj->user_login, $user_obj );

        $redirect = home_url();
        $candidate = esc_url_raw( $request->get_param( 'redirect' ) ?? '' );
        if ( $candidate && strpos( $candidate, home_url() ) === 0 ) $redirect = $candidate;

        return new WP_REST_Response( array( 'success' => true, 'redirect' => $redirect ) );
    }

    // ─── Приведение профиля к общему формату ─────────────────────────────────

    protected function normalize_profile( $user_info ) {
        $fname   = sanitize_text_field( $user_info['first_name'] ?? '' );
        $lname   = sanitize_text_field( $user_info['last_name']  ?? '' );
        $uname   = sanitize_text_field( $user_info['username']   ?? '' );
        $display = trim( $fname . ' ' . $lname ) ?: $uname ?: 'MAX Пользователь';

        return array(
            'id'            => (string) ( $user_info['id'] ?? '' ),
            'email'         => '',
            'first_name'    => $fname,
            'last_name'     => $lname,
            'display'       => $display,
            'username_base' => $uname ?: $display,
        );
    }

    protected function log_subject( $email, $provider_id ) {
        return 'MAX ID: ' . $provider_id;
    }

    private function get_bot_url() {
        $username = trim( $this->settings['max_bot_username'] ?? '' );
        return $username ? 'https://max.ru/' . ltrim( $username, '@' ) : '';
    }

    private function icon() {
        return '<img src="' . esc_url( IDPG_AUTH_PLUGIN_URL . 'assets/Image/MAX.svg' ) . '" class="wp-auth-ru-icon" alt="MAX" width="22" height="22">';
    }

    public function render_button_wp() {
        $bot_url = $this->get_bot_url();
        ?><div class="wp-auth-ru-wrap"><?php if ( $bot_url ) : ?><a href="<?php echo esc_url( $bot_url ); ?>" class="wp-auth-ru-btn wp-auth-ru-btn--max" target="_blank" rel="noopener noreferrer"><?php echo $this->icon(); ?> Войти через MAX</a><?php else : ?><span class="wp-auth-ru-btn wp-auth-ru-btn--max wp-auth-ru-btn--disabled"><?php echo $this->icon(); ?> Войти через MAX</span><?php endif; ?><p class="wp-auth-ru-hint">Откройте сайт через бота в MAX — вход произойдёт автоматически</p><div class="wp-auth-ru-divider"><span>или</span></div></div><?php
    }

    public function render_button_woo() {
        if ( is_user_logged_in() ) return;
        $bot_url = $this->get_bot_url();
        ?><div class="wp-auth-ru-wrap wp-auth-ru-wrap--woo"><?php if ( $bot_url ) : ?><a href="<?php echo esc_url( $bot_url ); ?>" class="wp-auth-ru-btn wp-auth-ru-btn--max" target="_blank" rel="noopener noreferrer"><?php echo $this->icon(); ?> Войти через MAX</a><?php else : ?><span class="wp-auth-ru-btn wp-auth-ru-btn--max wp-auth-ru-btn--disabled"><?php echo $this->icon(); ?> Войти через MAX</span><?php endif; ?><p class="wp-auth-ru-hint">Откройте сайт через бота в MAX — вход произойдёт автоматически</p><div class="wp-auth-ru-divider"><span>или</span></div></div><?php
    }

    public function render_checkout_banner() {
        if ( is_user_logged_in() ) return;
        $bot_url = $this->get_bot_url();
        if ( empty( $bot_url ) ) return;
        ?><div class="wp-auth-ru-checkout-banner" style="border-color:#c0c8ff;background:#f0f2ff;"><p class="wp-auth-ru-checkout-title">Быстрый вход перед оформлением:</p><a href="<?php echo esc_url( $bot_url ); ?>" class="wp-auth-ru-btn wp-auth-ru-btn--max wp-auth-ru-btn--checkout" target="_blank" rel="noopener noreferrer"><?php echo $this->icon(); ?> Войти через MAX</a><p class="wp-auth-ru-hint">Откройте сайт через бота MAX — вход произойдёт автоматически</p></div><?php
    }

    public function inject_miniapp_js() {
        if ( is_user_logged_in() ) return;
        $nonce    = wp_create_nonce( self::NONCE_ACTION );
        $rest_url = rest_url( self::REST_NAMESPACE . '/max/validate' );
        ?>
<script id="wp-auth-ru-max-js">
(function(){
    'use strict';
    var REST_URL = <?php echo wp_json_encode( $rest_url ); ?>;
    var NONCE    = <?php echo wp_json_encode( $nonce ); ?>;
    var initData = null;
    if (window.MaxApp && typeof window.MaxApp.initData === 'string' && window.MaxApp.initData) initData = window.MaxApp.initData;
    if (!initData && window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.initData) initData = window.Telegram.WebApp.initData;
    if (!initData) return;
    var fd = new FormData();
    fd.append('nonce', NONCE);
    fd.append('init_data', initData);
    fd.append('redirect', window.location.href);
    fetch(REST_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(resp){ if (resp && resp.success && resp.redirect) window.location.href = resp.redirect; })
        .catch(function(){});
})();
</script>
        <?php
    }

    public function output_styles() {
        static $printed = false;
        if ( $printed ) return; $printed = true;
        ?><style id="wp-auth-ru-max-styles">.wp-auth-ru-btn--max{background:linear-gradient(135deg,#4ccfff 0%,#5533ee 66%,#9933dd 100%)}.wp-auth-ru-btn--disabled{opacity:.55;cursor:default;pointer-events:none}</style><?php
    }
}