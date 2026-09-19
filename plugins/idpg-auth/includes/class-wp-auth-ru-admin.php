<?php
/**
 * Страница настроек IDPG Auth.
 * Вкладки: Настройки | Журнал событий
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Auth_Ru_Admin {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',    array( $this, 'add_menu' ) );
        add_action( 'admin_init',    array( $this, 'register_settings' ) );
        add_action( 'admin_notices', array( $this, 'maybe_show_setup_notice' ) );
        add_action( 'login_message', array( $this, 'show_blocked_message' ) );
        add_action( 'admin_post_idpg_auth_clear_logs', array( $this, 'handle_clear_logs' ) );
        add_action( 'admin_post_idpg_auth_clear_login_log', array( $this, 'handle_clear_login_log' ) );
        add_action( 'admin_post_idpg_auth_clear_security', array( $this, 'handle_clear_security' ) );
        add_action( 'admin_post_idpg_auth_unblock_ip', array( $this, 'handle_unblock_ip' ) );
    }

    public function add_menu() {
        add_options_page(
            'IDPG Auth',
            'Авторизация',
            'manage_options',
            'idpg-auth',
            array( $this, 'render_page' )
        );
    }

    public function register_settings() {
        register_setting( 'idpg_auth_group', IDPG_AUTH_OPTIONS_KEY,
            array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );
    }

    public function sanitize_settings( $input ) {
        $clean = array();
        $fields = array(
            'yandex_enabled',    'yandex_client_id',    'yandex_client_secret',
            'vk_enabled',        'vk_client_id',        'vk_client_secret',
            'max_enabled',       'max_bot_token',       'max_bot_username',
            'compliance_mode',
            // Мониторинг безопасности
            'security_enabled',
            'security_block_bruteforce',
            'security_block_user_enum',
            'security_email_alerts',
            'security_email_recipient',
        );
        foreach ( $fields as $f ) $clean[ $f ] = isset( $input[ $f ] ) ? sanitize_text_field( $input[ $f ] ) : '';

        // Числа и диапазоны.
        $clean['security_block_threshold']  = isset( $input['security_block_threshold'] )  ? max( 3, absint( $input['security_block_threshold'] ) )  : 5;
        $clean['security_lockout_minutes']  = isset( $input['security_lockout_minutes'] )  ? max( 5, absint( $input['security_lockout_minutes'] ) )  : 15;
        $clean['security_max_entries']      = isset( $input['security_max_entries'] )      ? min( 10000, max( 100, absint( $input['security_max_entries'] ) ) ) : 500;

        if ( isset( $input['security_email_recipient'] ) ) {
            $clean['security_email_recipient'] = sanitize_email( $input['security_email_recipient'] );
        }

        return $clean;
    }

    public function maybe_show_setup_notice() {
        $screen = get_current_screen();
        if ( $screen && 'settings_page_idpg-auth' === $screen->id ) return;
        $opts        = get_option( IDPG_AUTH_OPTIONS_KEY, array() );
        $any_enabled = ! empty( $opts['yandex_enabled'] ) ||
                       ! empty( $opts['vk_enabled'] ) ||
                       ! empty( $opts['max_enabled'] );
        if ( ! $any_enabled ) {
            echo '<div class="notice notice-info is-dismissible"><p><strong>IDPG Auth:</strong> Ни один OAuth-провайдер не настроен. <a href="' . esc_url( admin_url( 'options-general.php?page=idpg-auth' ) ) . '">Перейти к настройкам</a>.</p></div>';
        }
    }

    public function show_blocked_message( $message ) {
        if ( ! empty( $_GET['login'] ) && $_GET['login'] === 'foreign_blocked' ) {
            $message .= '<p class="message" style="color:#d63638;background:#fff3f3;padding:8px 12px;border-radius:4px;margin-top:8px;"><strong>Вход заблокирован.</strong> Авторизация через иностранные сервисы запрещена. Используйте ВКонтакте, Яндекс ID или MAX.</p>';
        }
        return $message;
    }

    public function handle_clear_logs() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Нет прав' );
        check_admin_referer( 'idpg_auth_clear_logs' );
        WP_Auth_Ru_Logger::clear_logs();
        wp_safe_redirect( admin_url( 'options-general.php?page=idpg-auth&tab=logs&cleared=1' ) );
        exit;
    }

    public function handle_clear_login_log() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Нет прав' );
        check_admin_referer( 'idpg_auth_clear_login_log' );
        WP_Auth_Ru_Login_Log::instance()->clear_logs();
        wp_safe_redirect( admin_url( 'options-general.php?page=idpg-auth&tab=logins&cleared=1' ) );
        exit;
    }

    public function handle_clear_security() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Нет прав' );
        check_admin_referer( 'idpg_auth_clear_security' );
        WP_Auth_Ru_Security::instance()->clear_alerts();
        wp_safe_redirect( admin_url( 'options-general.php?page=idpg-auth&tab=security&cleared=1' ) );
        exit;
    }

    public function handle_unblock_ip() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Нет прав' );
        check_admin_referer( 'idpg_auth_unblock_ip' );
        $ip = isset( $_POST['unblock_ip'] ) ? sanitize_text_field( wp_unslash( $_POST['unblock_ip'] ) ) : '';
        if ( $ip ) {
            WP_Auth_Ru_Security::instance()->unblock_ip( $ip );
        }
        wp_safe_redirect( admin_url( 'options-general.php?page=idpg-auth&tab=security&unblocked=1' ) );
        exit;
    }

    // ─── Роутер страниц ────────────────────────────────────────────────────────

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $tab = sanitize_key( $_GET['tab'] ?? 'settings' );
        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:10px;">IDPG Auth <span style="font-size:13px;font-weight:400;color:#888;background:#f0f0f0;padding:2px 10px;border-radius:20px;">v<?php echo esc_html( IDPG_AUTH_VERSION ); ?></span></h1>

            <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
                <a href="<?php echo esc_url( admin_url( 'options-general.php?page=idpg-auth&tab=settings' ) ); ?>"
                   class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">Настройки</a>
                <a href="<?php echo esc_url( admin_url( 'options-general.php?page=idpg-auth&tab=logins' ) ); ?>"
                   class="nav-tab <?php echo $tab === 'logins' ? 'nav-tab-active' : ''; ?>">
                    Входы
                    <?php
                    $login_stats = WP_Auth_Ru_Login_Log::instance()->get_stats();
                    if ( $login_stats['total'] > 0 ) {
                        echo '<span style="margin-left:5px;background:#4d6bfe;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;">' . (int) $login_stats['total'] . '</span>';
                    }
                    ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'options-general.php?page=idpg-auth&tab=logs' ) ); ?>"
                   class="nav-tab <?php echo $tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    Журнал событий
                    <?php
                    $stats = WP_Auth_Ru_Logger::get_stats();
                    if ( $stats['error'] > 0 ) {
                        echo '<span style="margin-left:5px;background:#d63638;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;">' . (int) $stats['error'] . ' ошибок</span>';
                    }
                    ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'options-general.php?page=idpg-auth&tab=security' ) ); ?>"
                   class="nav-tab <?php echo $tab === 'security' ? 'nav-tab-active' : ''; ?>">
                    Безопасность
                    <?php
                    $sec_stats = WP_Auth_Ru_Security::instance()->get_stats();
                    if ( $sec_stats['critical'] + $sec_stats['high'] > 0 ) {
                        echo '<span style="margin-left:5px;background:#dc2626;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;">' . (int) ( $sec_stats['critical'] + $sec_stats['high'] ) . '</span>';
                    }
                    ?>
                </a>
            </nav>

            <?php
            if ( $tab === 'logins' ) {
                $this->render_logins_tab();
            } elseif ( $tab === 'logs' ) {
                $this->render_logs_tab();
            } elseif ( $tab === 'security' ) {
                $this->render_security_tab();
            } else {
                $this->render_settings_tab();
            }
            ?>
        </div>
        <?php
    }

    // ─── Вкладка: Безопасность ────────────────────────────────────────────────

    private function render_security_tab() {
        $sec = WP_Auth_Ru_Security::instance();

        if ( ! empty( $_GET['cleared'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Журнал безопасности очищен.</p></div>';
        }
        if ( ! empty( $_GET['unblocked'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>IP разблокирован.</p></div>';
        }

        $f_type     = isset( $_GET['f_type'] )     ? sanitize_key( $_GET['f_type'] )                        : '';
        $f_severity = isset( $_GET['f_severity'] ) ? sanitize_key( $_GET['f_severity'] )                    : '';
        $f_search   = isset( $_GET['f_search'] )   ? sanitize_text_field( wp_unslash( $_GET['f_search'] ) ) : '';
        $f_date_from = isset( $_GET['f_date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['f_date_from'] ) ) : '';
        $f_date_to   = isset( $_GET['f_date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['f_date_to'] ) )   : '';
        $paged      = isset( $_GET['paged'] )       ? max( 1, absint( $_GET['paged'] ) )                     : 1;

        $data = $sec->get_alerts(
            array(
                'type'      => $f_type,
                'severity'  => $f_severity,
                'search'    => $f_search,
                'date_from' => $f_date_from,
                'date_to'   => $f_date_to,
                'per_page'  => 30,
                'paged'     => $paged,
            )
        );

        $stats   = $sec->get_stats();
        $blocked = $sec->list_blocked_ips();

        $type_labels = WP_Auth_Ru_Security::type_labels();
        $sev_labels  = WP_Auth_Ru_Security::severity_labels();
        $sev_colors  = WP_Auth_Ru_Security::severity_colors();

        $base_url = admin_url( 'options-general.php?page=idpg-auth&tab=security' );

        // Сохраняем текущие фильтры при навигации по страницам.
        $build_url = function ( $extra = array() ) use ( $base_url, $f_type, $f_severity, $f_search, $f_date_from, $f_date_to ) {
            $params = array(
                'f_type'      => $f_type,
                'f_severity'  => $f_severity,
                'f_search'    => $f_search,
                'f_date_from' => $f_date_from,
                'f_date_to'   => $f_date_to,
            );
            $params = array_merge( $params, $extra );
            foreach ( $params as $k => $v ) {
                if ( '' === $v ) {
                    unset( $params[ $k ] );
                }
            }
            return $params ? add_query_arg( $params, $base_url ) : $base_url;
        };

        ?>
        <style>
        .idpg-sec-stat { display:inline-flex;flex-direction:column;align-items:center;padding:10px 18px;border-radius:10px;min-width:86px;text-align:center; }
        .idpg-sec-stat-n { font-size:24px;font-weight:700;line-height:1; }
        .idpg-sec-stat-l { font-size:11px;margin-top:3px;opacity:.75; }
        .idpg-sec-type { font-size:11px;color:#888;white-space:nowrap; }
        .idpg-sec-chip { display:inline-block;font-size:11px;font-weight:700;padding:1px 8px;border-radius:10px;color:#fff;white-space:nowrap; }
        .idpg-sec-monospace { font-family:monospace;font-size:12px; }
        .idpg-sec-blocked { display:flex;gap:12px;flex-wrap:wrap; }
        .idpg-sec-block-card { background:#fff0f0;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;font-size:13px; }
        </style>

        <?php if ( ! $this->security_enabled() ) : ?>
        <div class="notice notice-warning is-dismissible">
            <p><strong>Мониторинг безопасности выключен.</strong>
            Включите его в <a href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $base_url ) ); ?>">разделе «Настройки»</a>, чтобы фиксировать попытки взлома и кражи.</p>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px;">
            <div class="idpg-sec-stat" style="background:#f3f4f6;border:1px solid #d1d5db;">
                <span class="idpg-sec-stat-n"><?php echo (int) $stats['total']; ?></span>
                <span class="idpg-sec-stat-l">Всего</span>
            </div>
            <div class="idpg-sec-stat" style="background:#ffe4e6;border:1px solid #fda4af;">
                <span class="idpg-sec-stat-n" style="color:#e11d48;"><?php echo (int) $stats['critical']; ?></span>
                <span class="idpg-sec-stat-l" style="color:#e11d48;">Критично</span>
            </div>
            <div class="idpg-sec-stat" style="background:#fff1f2;border:1px solid #fecdd3;">
                <span class="idpg-sec-stat-n" style="color:#f43f5e;"><?php echo (int) $stats['high']; ?></span>
                <span class="idpg-sec-stat-l" style="color:#f43f5e;">Высокий</span>
            </div>
            <div class="idpg-sec-stat" style="background:#fffbeb;border:1px solid #fcd34d;">
                <span class="idpg-sec-stat-n" style="color:#d97706;"><?php echo (int) $stats['medium']; ?></span>
                <span class="idpg-sec-stat-l" style="color:#d97706;">Средний</span>
            </div>
            <div class="idpg-sec-stat" style="background:#f0f9ff;border:1px solid #7dd3fc;">
                <span class="idpg-sec-stat-n" style="color:#0284c7;"><?php echo (int) $stats['low']; ?></span>
                <span class="idpg-sec-stat-l" style="color:#0284c7;">Низкий</span>
            </div>
            <div class="idpg-sec-stat" style="background:#f5f3ff;border:1px solid #c4b5fd;">
                <span class="idpg-sec-stat-n" style="color:#7c3aed;"><?php echo (int) $stats['last_24h']; ?></span>
                <span class="idpg-sec-stat-l" style="color:#7c3aed;">за 24 часа</span>
            </div>
        </div>

        <?php if ( ! empty( $blocked ) ) : ?>
        <div style="background:#fff;border:1px solid #fecaca;border-radius:8px;padding:14px 16px;margin:0 0 16px;">
            <h2 style="margin:0 0 10px;font-size:14px;color:#b91c1c;">Заблокированные IP-адреса</h2>
            <div class="idpg-sec-blocked">
                <?php foreach ( $blocked as $b ) : ?>
                <div class="idpg-sec-block-card">
                    <span class="idpg-sec-monospace"><?php echo esc_html( $b['ip'] ); ?></span><br>
                    <span style="font-size:11px;color:#6b7280;">до <?php echo esc_html( $b['until'] ); ?></span>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:8px;">
                        <input type="hidden" name="action" value="idpg_auth_unblock_ip">
                        <input type="hidden" name="unblock_ip" value="<?php echo esc_attr( $b['ip'] ); ?>">
                        <?php wp_nonce_field( 'idpg_auth_unblock_ip' ); ?>
                        <button type="submit" class="button-link" onclick="return confirm('Разблокировать IP <?php echo esc_js( $b['ip'] ); ?>?');">Разблокировать</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <form method="get" style="margin:0 0 16px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <input type="hidden" name="page" value="idpg-auth">
            <input type="hidden" name="tab" value="security">
            <input type="text" name="f_search" value="<?php echo esc_attr( $f_search ); ?>" placeholder="Сообщение, IP, URL" style="min-width:200px;">
            <select name="f_severity">
                <option value="">Все уровни</option>
                <?php foreach ( $sev_labels as $sk => $sl ) : ?>
                <option value="<?php echo esc_attr( $sk ); ?>" <?php selected( $f_severity, $sk ); ?>><?php echo esc_html( $sl ); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="f_type">
                <option value="">Все типы</option>
                <?php foreach ( $type_labels as $tk => $tl ) : ?>
                <option value="<?php echo esc_attr( $tk ); ?>" <?php selected( $f_type, $tk ); ?>><?php echo esc_html( $tl ); ?></option>
                <?php endforeach; ?>
            </select>
            <label style="font-size:12px;color:#666;">от <input type="date" name="f_date_from" value="<?php echo esc_attr( $f_date_from ); ?>"></label>
            <label style="font-size:12px;color:#666;">до <input type="date" name="f_date_to" value="<?php echo esc_attr( $f_date_to ); ?>"></label>
            <button type="submit" class="button">Фильтровать</button>
            <?php if ( $f_search || $f_severity || $f_type || $f_date_from || $f_date_to ) : ?>
            <a href="<?php echo esc_url( $base_url ); ?>" class="button button-link">Сбросить</a>
            <?php endif; ?>
        </form>

        <div style="display:flex;gap:10px;align-items:center;margin:0 0 16px;">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                <input type="hidden" name="action" value="idpg_auth_clear_security">
                <?php wp_nonce_field( 'idpg_auth_clear_security' ); ?>
                <button type="submit" class="button button-secondary" onclick="return confirm('Очистить весь журнал безопасности?');">Очистить журнал</button>
            </form>
            <button type="button" class="button" onclick="location.reload();">Обновить</button>
        </div>

        <?php if ( empty( $data['items'] ) ) : ?>
        <div style="background:#f9fafb;border:1px dashed #d1d5db;border-radius:8px;padding:32px;text-align:center;color:#6b7280;">
            <p style="font-size:15px;margin:0 0 8px;">Подозрительных событий пока не зафиксировано</p>
            <p style="margin:0;font-size:13px;">Здесь будет журнал попыток взлома (брутфорс, перечисление аккаунтов, доступ к .git/бэкапам/конфигам) и действий в админке (новые администраторы, активация/удаление плагинов).</p>
        </div>
        <?php else : ?>
        <div style="max-height:620px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;padding:10px;background:#fafafa;">
            <?php foreach ( $data['items'] as $e ) :
                $c = $sev_colors[ $e['severity'] ] ?? $sev_colors['low'];
                $tl = $type_labels[ $e['type'] ] ?? ucfirst( $e['type'] );
                $sl = $sev_labels[ $e['severity'] ] ?? ucfirst( $e['severity'] );
                ?>
            <div style="display:flex;gap:10px;align-items:flex-start;padding:8px 10px;border-radius:6px;margin-bottom:4px;border-left:3px solid <?php echo esc_attr( $c['border'] ); ?>;background:<?php echo esc_attr( $c['bg'] ); ?>;">
                <span style="font-size:11px;color:#888;white-space:nowrap;min-width:130px;padding-top:2px;"><?php echo esc_html( $e['created_at'] ); ?></span>
                <span class="idpg-sec-chip" style="background:<?php echo esc_attr( $c['badge'] ); ?>;min-width:60px;text-align:center;"><?php echo esc_html( $sl ); ?></span>
                <div style="flex:1;">
                    <div style="font-size:13px;"><?php echo esc_html( $e['message'] ); ?></div>
                    <div class="idpg-sec-type">
                        <?php echo esc_html( $tl ); ?> ·
                        IP <span class="idpg-sec-monospace"><?php echo esc_html( $e['ip'] ); ?></span> ·
                        <?php echo esc_html( $e['request_method'] ); ?>
                        <?php if ( $e['request_uri'] ) : ?>· <span title="<?php echo esc_attr( $e['request_uri'] ); ?>"><?php echo esc_html( mb_substr( $e['request_uri'], 0, 70 ) ); echo strlen( $e['request_uri'] ) > 70 ? '…' : ''; ?></span><?php endif; ?>
                        <?php if ( $e['user_agent'] ) : ?>· UA: <span title="<?php echo esc_attr( $e['user_agent'] ); ?>"><?php echo esc_html( mb_substr( $e['user_agent'], 0, 40 ) ); echo strlen( $e['user_agent'] ) > 40 ? '…' : ''; ?></span><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ( $data['pages'] > 1 ) : ?>
        <div style="margin-top:12px;">
            <?php echo paginate_links(
                array(
                    'base'    => $build_url( array( 'paged' => '%#%' ) ),
                    'format'  => '',
                    'current' => $data['paged'],
                    'total'   => $data['pages'],
                    'type'    => 'list',
                )
            ); ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <p style="color:#888;font-size:12px;margin-top:16px;">Журнал безопасности фиксирует попытки взлома и кражи проекта. Данные доступны только администраторам.</p>
        <?php
    }

    private function security_enabled() {
        $opts = get_option( IDPG_AUTH_OPTIONS_KEY, array() );
        return ! empty( $opts['security_enabled'] );
    }

    // ─── Вкладка: Входы ────────────────────────────────────────────────────────

    private function render_logins_tab() {
        $log = WP_Auth_Ru_Login_Log::instance();

        if ( ! empty( $_GET['cleared'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Журнал входов очищен.</p></div>';
        }

        // Фильтры
        $f_search    = isset( $_GET['f_search'] )    ? sanitize_text_field( wp_unslash( $_GET['f_search'] ) )    : '';
        $f_provider  = isset( $_GET['f_provider'] )  ? sanitize_key( $_GET['f_provider'] )                       : '';
        $f_date_from = isset( $_GET['f_date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['f_date_from'] ) ) : '';
        $f_date_to   = isset( $_GET['f_date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['f_date_to'] ) )   : '';
        $paged       = isset( $_GET['paged'] )       ? max( 1, absint( $_GET['paged'] ) )                        : 1;

        $data = $log->get_logs(
            array(
                'search'    => $f_search,
                'provider'  => $f_provider,
                'date_from' => $f_date_from,
                'date_to'   => $f_date_to,
                'per_page'  => 30,
                'paged'     => $paged,
            )
        );

        $stats = $log->get_stats();

        $provider_labels = array(
            'password' => 'Логин и пароль',
            'yandex'   => 'Яндекс ID',
            'vk'       => 'ВКонтакте',
            'max'      => 'MAX',
        );

        $provider_dots = array(
            'password' => '#6b7280',
            'yandex'   => '#FC3F1D',
            'vk'       => '#5281B8',
            'max'      => '#5533ee',
        );

        $device_labels = array(
            'desktop' => 'Компьютер',
            'mobile'  => 'Телефон',
            'tablet'  => 'Планшет',
            'bot'     => 'Бот',
        );

        // Базовый URL вкладки без query-параметров фильтров.
        $base_url = admin_url( 'options-general.php?page=idpg-auth&tab=logins' );

        // Построение URL фильтра (сохраняет текущие фильтры).
        $build_url = function ( $extra = array() ) use ( $base_url, $f_search, $f_date_from, $f_date_to, $f_provider ) {
            $params = array(
                'f_search'    => $f_search,
                'f_provider'  => $f_provider,
                'f_date_from' => $f_date_from,
                'f_date_to'   => $f_date_to,
            );
            $params = array_merge( $params, $extra );
            foreach ( $params as $k => $v ) {
                if ( '' === $v ) {
                    unset( $params[ $k ] );
                }
            }
            return $params ? add_query_arg( $params, $base_url ) : $base_url;
        };

        ?>
        <style>
        .idpg-login-stat { display:inline-block;padding:8px 18px;border-radius:8px;font-size:13px;margin-right:8px; }
        .idpg-login-tbl { width:100%;border-collapse:collapse;background:#fff;font-size:13px; }
        .idpg-login-tbl th { text-align:left;padding:9px 10px;border-bottom:2px solid #e5e7eb;color:#374151;background:#f9fafb; }
        .idpg-login-tbl td { padding:9px 10px;border-bottom:1px solid #f0f0f0;vertical-align:top; }
        .idpg-login-tbl tr:hover td { background:#fafbff; }
        .idpg-user-avatar img { border-radius:50%; }
        .idpg-user-phone:empty { display:none; }
        .idpg-flagcode { display:inline-flex;align-items:center;gap:6px; }
        .idpg-login-provider { display:inline-flex;align-items:center;gap:6px;font-weight:600;font-size:12px; }
        .idpg-login-device { font-size:12px;color:#6b7280; }
        .idpg-login-ip { font-family:monospace;font-size:12px;color:#0f172a; }
        .idpg-pagination { margin-top:12px; }
        .idpg-empty { background:#f9fafb;border:1px dashed #d1d5db;border-radius:8px;padding:32px;text-align:center;color:#6b7280; }
        </style>

        <?php if ( ! empty( $_GET['new_table'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Таблица журнала входов создана.</p></div>
        <?php endif; ?>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px;">
            <div class="idpg-login-stat" style="background:#f3f4f6;border:1px solid #d1d5db;">
                <strong><?php echo (int) $stats['total']; ?></strong> всего
            </div>
            <div class="idpg-login-stat" style="background:#f0fff4;border:1px solid #86efac;">
                <strong style="color:#16a34a;"><?php echo (int) $stats['today']; ?></strong> сегодня
            </div>
            <div class="idpg-login-stat" style="background:#f0f9ff;border:1px solid #7dd3fc;">
                <strong style="color:#0284c7;"><?php echo (int) $stats['week']; ?></strong> за 7 дней
            </div>
            <?php foreach ( $stats['providers'] as $p => $c ) : ?>
            <div class="idpg-login-stat" style="background:#fff;border:1px solid #e5e7eb;">
                <span class="idpg-login-provider"><span style="width:8px;height:8px;border-radius:50%;display:inline-block;background:<?php echo esc_attr( $provider_dots[ $p ] ?? '#999' ); ?>;"></span><?php echo esc_html( $provider_labels[ $p ] ?? ucfirst( $p ) ); ?></span>
                <strong><?php echo (int) $c; ?></strong>
            </div>
            <?php endforeach; ?>
        </div>

        <form method="get" style="margin:0 0 16px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <input type="hidden" name="page" value="idpg-auth">
            <input type="hidden" name="tab" value="logins">
            <input type="text" name="f_search" value="<?php echo esc_attr( $f_search ); ?>" placeholder="Логин, email, имя, IP" style="min-width:220px;">
            <select name="f_provider">
                <option value="">Все провайдеры</option>
                <?php foreach ( $provider_labels as $pk => $pl ) : ?>
                <option value="<?php echo esc_attr( $pk ); ?>" <?php selected( $f_provider, $pk ); ?>><?php echo esc_html( $pl ); ?></option>
                <?php endforeach; ?>
            </select>
            <label style="font-size:12px;color:#666;">от <input type="date" name="f_date_from" value="<?php echo esc_attr( $f_date_from ); ?>"></label>
            <label style="font-size:12px;color:#666;">до <input type="date" name="f_date_to" value="<?php echo esc_attr( $f_date_to ); ?>"></label>
            <button type="submit" class="button">Фильтровать</button>
            <?php if ( $f_search || $f_provider || $f_date_from || $f_date_to ) : ?>
            <a href="<?php echo esc_url( $base_url ); ?>" class="button button-link">Сбросить</a>
            <?php endif; ?>
        </form>

        <div style="display:flex;gap:10px;align-items:center;margin:0 0 16px;">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                <input type="hidden" name="action" value="idpg_auth_clear_login_log">
                <?php wp_nonce_field( 'idpg_auth_clear_login_log' ); ?>
                <button type="submit" class="button button-secondary" onclick="return confirm('Очистить весь журнал входов?');">Очистить журнал входов</button>
            </form>
            <button type="button" class="button" onclick="location.reload();">Обновить</button>
        </div>

        <?php if ( empty( $data['items'] ) ) : ?>
        <div class="idpg-empty">
            <p style="font-size:15px;margin:0 0 8px;">Входы пока не зафиксированы</p>
            <p style="margin:0;font-size:13px;">Записи появятся при каждом успешном входе пользователей.</p>
        </div>
        <?php else : ?>
        <div style="overflow-x:auto;border:1px solid #e5e7eb;border-radius:8px;">
            <table class="idpg-login-tbl">
                <thead>
                    <tr>
                        <th>Аккаунт</th>
                        <th>Провайдер</th>
                        <th>IP-адрес</th>
                        <th>Браузер / ОС</th>
                        <th>Устройство</th>
                        <th>Когда</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $data['items'] as $item ) : ?>
                    <?php
                    $pname = $provider_labels[ $item['provider'] ] ?? ucfirst( $item['provider'] );
                    $dots  = $provider_dots[ $item['provider'] ] ?? '#999';
                    ?>
                    <tr>
                        <td>
                            <?php
                            $edit_url = '';
                            $user = get_user_by( 'id', $item['user_id'] );
                            if ( $user && current_user_can( 'edit_user', $user->ID ) ) {
                                $edit_url = get_edit_user_link( $user->ID );
                            }
                            ?>
                            <?php echo get_avatar( $item['user_id'], 32, '', '', array( 'class' => 'idpg-user-avatar' ) ); ?>
                            <?php if ( $edit_url ) : ?>
                            <a href="<?php echo esc_url( $edit_url ); ?>" style="text-decoration:none;color:inherit;">
                            <?php endif; ?>
                            <strong><?php echo esc_html( $item['display_name'] ? $item['display_name'] : $item['user_login'] ); ?></strong>
                            <?php if ( $edit_url ) : ?></a><?php endif; ?>
                            <br><span style="font-size:11px;color:#888;">@<?php echo esc_html( $item['user_login'] ); ?></span>
                            <?php if ( $item['user_email'] ) : ?>
                            <br><span style="font-size:11px;color:#6b7280;" title="<?php echo esc_attr( $item['user_email'] ); ?>"><?php echo esc_html( $item['user_email'] ); ?></span>
                            <?php endif; ?>
                            <?php if ( $edit_url ) : ?>
                            <br><a href="<?php echo esc_url( $edit_url ); ?>" class="button-link" style="font-size:11px;">&rarr; Редактировать профиль</a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="idpg-login-provider">
                                <span style="width:8px;height:8px;border-radius:50%;display:inline-block;background:<?php echo esc_attr( $dots ); ?>;"></span>
                                <?php echo esc_html( $pname ); ?>
                            </span>
                        </td>
                        <td><span class="idpg-login-ip"><?php echo esc_html( $item['ip'] ); ?></span></td>
                        <td>
                            <?php echo esc_html( $item['browser'] ); ?>
                            <br><span class="idpg-login-device"><?php echo esc_html( $item['os'] ); ?></span>
                        </td>
                        <td><span class="idpg-login-provider"><?php echo esc_html( $device_labels[ $item['device'] ] ?? $item['device'] ); ?></span></td>
                        <td style="white-space:nowrap;font-size:12px;color:#374151;">
                            <?php echo esc_html( $item['login_time'] ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ( $data['pages'] > 1 ) : ?>
        <div class="idpg-pagination">
            <?php
            echo paginate_links(
                array(
                    'base'    => $build_url( array( 'paged' => '%#%' ) ),
                    'format'  => '',
                    'current' => $data['paged'],
                    'total'   => $data['pages'],
                    'type'    => 'list',
                )
            );
            ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <p style="color:#888;font-size:12px;margin-top:16px;">В журнале входов фиксируется каждый успешный вход: аккаунт, провайдер, IP-адрес, браузер, ОС и тип устройства. Данные доступны только администраторам.</p>
        <?php
    }

    // ─── Вкладка: Журнал ───────────────────────────────────────────────────────

    private function render_logs_tab() {
        $stats = WP_Auth_Ru_Logger::get_stats();
        $logs  = WP_Auth_Ru_Logger::get_logs();

        if ( ! empty( $_GET['cleared'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Журнал очищен.</p></div>';
        }

        $level_colors = array(
            'SUCCESS' => array( 'bg' => '#f0fff4', 'border' => '#22c55e', 'badge' => '#16a34a', 'text' => '#166534' ),
            'ERROR'   => array( 'bg' => '#fff0f0', 'border' => '#ef4444', 'badge' => '#dc2626', 'text' => '#991b1b' ),
            'WARNING' => array( 'bg' => '#fffbeb', 'border' => '#f59e0b', 'badge' => '#d97706', 'text' => '#92400e' ),
            'INFO'    => array( 'bg' => '#f0f9ff', 'border' => '#38bdf8', 'badge' => '#0284c7', 'text' => '#075985' ),
        );

        $provider_labels = array(
            'yandex'    => 'Яндекс',
            'vk'        => 'ВКонтакте',
            'max'       => 'MAX',
            'system'    => 'Система',
            'compliance'=> 'Блокировка',
        );

        ?>
        <style>
        .idpg-stat { display:inline-flex;flex-direction:column;align-items:center;padding:12px 20px;border-radius:10px;min-width:90px;text-align:center; }
        .idpg-stat-n { font-size:28px;font-weight:700;line-height:1; }
        .idpg-stat-l { font-size:11px;margin-top:3px;opacity:.75; }
        .idpg-log-row { display:flex;gap:8px;align-items:flex-start;padding:7px 10px;border-radius:6px;margin-bottom:4px;border-left:3px solid; }
        .idpg-log-time { font-size:11px;color:#888;white-space:nowrap;min-width:130px;padding-top:1px; }
        .idpg-log-badge { font-size:10px;font-weight:700;padding:1px 7px;border-radius:10px;white-space:nowrap;color:#fff;min-width:62px;text-align:center; }
        .idpg-log-provider { font-size:11px;min-width:80px;white-space:nowrap; }
        .idpg-log-msg { font-size:13px;flex:1; }
        .idpg-log-ctx { font-size:10px;color:#888;margin-top:2px; }
        </style>

        <div style="display:flex;gap:12px;flex-wrap:wrap;margin:0 0 20px;">
            <div class="idpg-stat" style="background:#f3f4f6;border:1px solid #d1d5db;">
                <span class="idpg-stat-n"><?php echo (int) $stats['total']; ?></span>
                <span class="idpg-stat-l">Всего</span>
            </div>
            <div class="idpg-stat" style="background:#f0fff4;border:1px solid #86efac;">
                <span class="idpg-stat-n" style="color:#16a34a;"><?php echo (int) $stats['success']; ?></span>
                <span class="idpg-stat-l" style="color:#16a34a;">Успех</span>
            </div>
            <div class="idpg-stat" style="background:#fff0f0;border:1px solid #fca5a5;">
                <span class="idpg-stat-n" style="color:#dc2626;"><?php echo (int) $stats['error']; ?></span>
                <span class="idpg-stat-l" style="color:#dc2626;">Ошибки</span>
            </div>
            <div class="idpg-stat" style="background:#fffbeb;border:1px solid #fcd34d;">
                <span class="idpg-stat-n" style="color:#d97706;"><?php echo (int) $stats['warning']; ?></span>
                <span class="idpg-stat-l" style="color:#d97706;">Предупр.</span>
            </div>
            <div class="idpg-stat" style="background:#f0f9ff;border:1px solid #7dd3fc;">
                <span class="idpg-stat-n" style="color:#0284c7;"><?php echo (int) $stats['info']; ?></span>
                <span class="idpg-stat-l" style="color:#0284c7;">Инфо</span>
            </div>
        </div>

        <?php if ( ! empty( $stats['providers'] ) ) : ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px;">
            <?php foreach ( $stats['providers'] as $p => $ps ) : ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:8px 14px;font-size:13px;">
                <?php echo esc_html( $provider_labels[ $p ] ?? ucfirst( $p ) ); ?>:
                <strong><?php echo (int) $ps['total']; ?></strong> всего,
                <span style="color:#16a34a;"><?php echo (int) $ps['success']; ?> ok</span>
                <?php if ( $ps['error'] > 0 ) : ?>
                , <span style="color:#dc2626;"><?php echo (int) $ps['error']; ?> err</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:10px;align-items:center;margin:0 0 16px;">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                <input type="hidden" name="action" value="idpg_auth_clear_logs">
                <?php wp_nonce_field( 'idpg_auth_clear_logs' ); ?>
                <button type="submit" class="button button-secondary" onclick="return confirm('Очистить весь журнал?');">Очистить журнал</button>
            </form>
            <button type="button" class="button" onclick="location.reload();">Обновить</button>
            <span style="color:#888;font-size:12px;">Хранится <?php echo WP_Auth_Ru_Logger::MAX_ENTRIES; ?> последних записей</span>
        </div>

        <?php if ( empty( $logs ) ) : ?>
        <div style="background:#f9fafb;border:1px dashed #d1d5db;border-radius:8px;padding:40px;text-align:center;color:#6b7280;">
            <p style="font-size:16px;margin:0 0 8px;">Журнал пуст</p>
            <p style="margin:0;font-size:13px;">События появятся, когда пользователи начнут входить через кнопки OAuth.</p>
        </div>
        <?php else : ?>
        <div style="max-height:600px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;padding:12px;background:#fafafa;">
            <?php foreach ( $logs as $entry ) :
                $level = strtoupper( $entry['level'] ?? 'INFO' );
                $c     = $level_colors[ $level ] ?? $level_colors['INFO'];
                $prov  = $entry['provider'] ?? '';
                $pname = $provider_labels[ $prov ] ?? ucfirst( $prov );
                ?>
            <div class="idpg-log-row" style="background:<?php echo esc_attr( $c['bg'] ); ?>;border-color:<?php echo esc_attr( $c['border'] ); ?>;">
                <span class="idpg-log-time"><?php echo esc_html( $entry['time'] ?? '' ); ?></span>
                <span class="idpg-log-badge" style="background:<?php echo esc_attr( $c['badge'] ); ?>;"><?php echo esc_html( $level ); ?></span>
                <span class="idpg-log-provider" style="color:<?php echo esc_attr( $c['text'] ); ?>;"><?php echo esc_html( $pname ); ?></span>
                <span class="idpg-log-msg">
                    <?php echo esc_html( $entry['msg'] ?? '' ); ?>
                    <?php if ( ! empty( $entry['ctx'] ) ) : ?>
                    <br><span class="idpg-log-ctx"><?php foreach ( $entry['ctx'] as $k => $v ) echo esc_html( $k ) . '=' . esc_html( $v ) . ' '; ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <p style="color:#888;font-size:12px;margin-top:12px;">Email-адреса в журнале маскируются. Журнал хранится в базе данных сайта и доступен только администраторам.</p>
        <?php
    }

    // ─── Вкладка: Настройки ────────────────────────────────────────────────────

    private function render_settings_tab() {
        $opts    = get_option( IDPG_AUTH_OPTIONS_KEY, array() );
        $key     = IDPG_AUTH_OPTIONS_KEY;
        $foreign = class_exists( 'WP_Auth_Ru_Compliance' ) ? WP_Auth_Ru_Compliance::get_active_foreign_plugins() : array();
        ?>

        <?php if ( ! empty( $foreign ) ) : ?>
        <div style="background:#fff0f0;border:2px solid #d63638;border-radius:8px;padding:16px 20px;margin:0 0 20px;max-width:900px;">
            <h2 style="margin:0 0 8px;font-size:15px;color:#d63638;">Обнаружены плагины иностранной авторизации</h2>
            <ul style="margin:0 0 8px;padding-left:20px;font-size:13px;color:#444;">
                <?php foreach ( $foreign as $slug => $name ) : ?>
                <li><strong><?php echo esc_html( $name ); ?></strong> <code style="font-size:11px;color:#888;">(<?php echo esc_html( $slug ); ?>)</code></li>
                <?php endforeach; ?>
            </ul>
            <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>" class="button button-secondary">Управление плагинами</a>
        </div>
        <?php endif; ?>

        <!-- Блок закона -->
        <div style="background:#fff8e6;border:2px solid #f0b429;border-radius:8px;padding:16px 20px;margin:0 0 20px;max-width:900px;">
            <h2 style="margin:0 0 10px;font-size:15px;color:#7a4a00;">Штрафы за авторизацию через иностранные сервисы (до 700 000 руб.)</h2>
            <p style="margin:0 0 8px;font-size:13px;color:#555;line-height:1.6;">Госдума приняла поправки в КоАП РФ. Авторизация через Google, Apple, Facebook и другие иностранные сервисы запрещена. Используйте только российские провайдеры.</p>
            <table style="font-size:13px;border-collapse:collapse;width:100%;max-width:500px;">
                <tr style="background:#ffeeba;"><th style="padding:4px 10px;text-align:left;border:1px solid #f0d080;">Нарушитель</th><th style="padding:4px 10px;text-align:left;border:1px solid #f0d080;">Штраф</th></tr>
                <tr><td style="padding:4px 10px;border:1px solid #f0d080;">Граждане</td><td style="padding:4px 10px;border:1px solid #f0d080;">10 000 — 20 000 руб.</td></tr>
                <tr style="background:#fffdf0;"><td style="padding:4px 10px;border:1px solid #f0d080;">Должностные лица</td><td style="padding:4px 10px;border:1px solid #f0d080;">30 000 — 50 000 руб.</td></tr>
                <tr><td style="padding:4px 10px;border:1px solid #f0d080;"><strong>Юридические лица</strong></td><td style="padding:4px 10px;border:1px solid #f0d080;"><strong>500 000 — 700 000 руб.</strong></td></tr>
            </table>
            <p style="margin:8px 0 0;font-size:12px;color:#888;">Законопроект А. Горелкина (зам. председателя комитета ГД по информполитике). Принят во 2 и 3 чтениях.</p>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields( 'idpg_auth_group' ); ?>

            <!-- Режим соответствия -->
            <div style="background:#f0fff4;border:1px solid #4caf50;border-radius:8px;padding:14px 18px;margin:0 0 24px;max-width:900px;">
                <h2 style="margin:0 0 8px;font-size:14px;color:#2e7d32;">Режим соответствия законодательству РФ</h2>
                <p style="margin:0 0 10px;font-size:13px;color:#444;">При включении — автоматически блокирует вход и регистрацию через иностранные OAuth-сервисы.</p>
                <label style="font-size:13px;font-weight:600;">
                    <input type="checkbox" name="<?php echo esc_attr( $key ); ?>[compliance_mode]" value="1" <?php checked( ! empty( $opts['compliance_mode'] ) ); ?>>
                    Включить блокировку иностранной авторизации
                </label>
            </div>

            <?php
            $providers = array(
                'yandex' => array( 'label' => 'Яндекс ID',     'color' => '#FC3F1D', 'btn' => 'Войти через Яндекс',    'note' => 'Зарегистрируйте приложение на <a href="https://oauth.yandex.ru/" target="_blank">oauth.yandex.ru</a>. Тип: <strong>Веб-сервисы</strong>. Права: <code>login:info</code>, <code>login:email</code>.',        'callback_fn' => 'WP_Auth_Ru_Yandex' ),
                'vk'     => array( 'label' => 'ВКонтакте',       'color' => '#5281B8', 'btn' => 'Войти через ВКонтакте', 'note' => 'Зарегистрируйте приложение на <a href="https://dev.vk.com/ru/admin/apps-list" target="_blank">dev.vk.com</a>. Тип: <strong>Веб-сайт</strong>. В настройках включите доступ к email.',              'callback_fn' => 'WP_Auth_Ru_VK' ),
            );

            foreach ( $providers as $pkey => $p ) :
                $cb_url = class_exists( $p['callback_fn'] ) ? call_user_func( array( $p['callback_fn'], 'get_callback_url' ) ) : '';
            ?>
            <h2 style="margin-top:24px;padding:10px 16px;background:rgba(0,0,0,.03);border-left:4px solid <?php echo esc_attr( $p['color'] ); ?>;border-radius:0 6px 6px 0;"><?php echo esc_html( $p['label'] ); ?></h2>
            <p><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[<?php echo esc_attr( $pkey ); ?>_enabled]" value="1" <?php checked( ! empty( $opts[ $pkey . '_enabled' ] ) ); ?>> <strong>Включить «<?php echo esc_html( $p['btn'] ); ?>»</strong></label></p>
            <p style="color:#555;font-size:13px;margin:4px 0 10px;"><?php echo wp_kses_post( $p['note'] ); ?></p>
            <div style="background:#fafafa;border:1px solid #e0e0e0;border-radius:6px;padding:10px 14px;margin:0 0 12px;max-width:700px;">
                <strong>Redirect URI</strong> — скопируйте в настройки приложения у провайдера:<br>
                <code style="background:#fff;padding:3px 8px;border:1px solid #ddd;border-radius:4px;display:inline-block;margin-top:6px;word-break:break-all;font-size:13px;"><?php echo esc_html( $cb_url ); ?></code>
                <button type="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $cb_url ); ?>');this.textContent='Скопировано'" style="margin-left:8px;padding:3px 10px;cursor:pointer;font-size:12px;">Копировать</button>
            </div>
            <table class="form-table" role="presentation" style="margin-top:0;">
                <tr><th style="width:160px;"><label>Client ID</label></th>
                    <td><input type="text" name="<?php echo esc_attr( $key ); ?>[<?php echo esc_attr( $pkey ); ?>_client_id]" value="<?php echo esc_attr( $opts[ $pkey . '_client_id' ] ?? '' ); ?>" class="regular-text" autocomplete="off"></td></tr>
                <tr><th><label>Client Secret</label></th>
                    <td><input type="password" name="<?php echo esc_attr( $key ); ?>[<?php echo esc_attr( $pkey ); ?>_client_secret]" value="<?php echo esc_attr( $opts[ $pkey . '_client_secret' ] ?? '' ); ?>" class="regular-text" autocomplete="new-password"></td></tr>
            </table>
            <?php endforeach; ?>

            <!-- MAX -->
            <h2 style="margin-top:24px;padding:10px 16px;background:rgba(0,0,0,.03);border-left:4px solid #5533ee;border-radius:0 6px 6px 0;">MAX Мессенджер</h2>
            <p><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[max_enabled]" value="1" <?php checked( ! empty( $opts['max_enabled'] ) ); ?>> <strong>Включить «Войти через MAX»</strong></label></p>
            <div style="background:#f8f6ff;border:1px solid #d0c8ff;border-radius:6px;padding:12px 16px;margin:0 0 12px;max-width:700px;font-size:13px;color:#444;">
                <strong>MAX использует Mini App, а не стандартный OAuth.</strong><br>
                1. Перейдите на <a href="https://max.ru/partner" target="_blank">max.ru/partner</a> - Чат-боты - Интеграция - получите <strong>Bot Token</strong>.<br>
                2. В Мини-приложение укажите URL вашего сайта.
            </div>
            <table class="form-table" role="presentation" style="margin-top:0;">
                <tr><th style="width:160px;"><label>Bot Token</label></th>
                    <td><input type="password" name="<?php echo esc_attr( $key ); ?>[max_bot_token]" value="<?php echo esc_attr( $opts['max_bot_token'] ?? '' ); ?>" class="regular-text" autocomplete="new-password"></td></tr>
                <tr><th><label>Username бота</label></th>
                    <td><input type="text" name="<?php echo esc_attr( $key ); ?>[max_bot_username]" value="<?php echo esc_attr( $opts['max_bot_username'] ?? '' ); ?>" class="regular-text" autocomplete="off" placeholder="@your_bot_username">
                    <p class="description">Кнопка ведёт на https://max.ru/your_bot_username</p></td></tr>
            </table>

            <!-- Мониторинг безопасности -->
            <h2 style="margin-top:24px;padding:10px 16px;background:rgba(0,0,0,.03);border-left:4px solid #dc2626;border-radius:0 6px 6px 0;">Мониторинг безопасности</h2>
            <p style="color:#555;font-size:13px;margin:4px 0 10px;">Отслеживает попытки взлома и кражи проекта: брутфорс, перечисление пользователей, доступ к <code>.git</code> / бэкапам / конфигурации, манипуляции с плагинами и правами. События видны на вкладке «Безопасность».</p>

            <div style="background:#fff0f2;border:1px solid #fecaca;border-radius:6px;padding:12px 16px;margin:0 0 14px;max-width:700px;">
                <label style="font-size:13px;font-weight:600;">
                    <input type="checkbox" name="<?php echo esc_attr( $key ); ?>[security_enabled]" value="1" <?php checked( ! empty( $opts['security_enabled'] ) ); ?>>
                    Включить мониторинг безопасности
                </label>
            </div>

            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;max-width:700px;">
                <table class="form-table" role="presentation" style="margin:0;">
                    <tr>
                        <th style="width:190px;"><label>Защита от брутфорса</label></th>
                        <td>
                            <label style="font-size:13px;">
                                <input type="checkbox" name="<?php echo esc_attr( $key ); ?>[security_block_bruteforce]" value="1" <?php checked( ! empty( $opts['security_block_bruteforce'] ) ); ?>>
                                Блокировать IP после неудачных попыток входа
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Порог попыток</label></th>
                        <td>
                            <input type="number" name="<?php echo esc_attr( $key ); ?>[security_block_threshold]" value="<?php echo esc_attr( $opts['security_block_threshold'] ?? 5 ); ?>" min="3" max="100" style="width:90px;">
                            <p class="description">Количество неудачных входов, после которого IP блокируется.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Время блокировки</label></th>
                        <td>
                            <input type="number" name="<?php echo esc_attr( $key ); ?>[security_lockout_minutes]" value="<?php echo esc_attr( $opts['security_lockout_minutes'] ?? 15 ); ?>" min="5" max="1440" style="width:90px;">
                            <p class="description">Минут, на которые IP блокируется (мин. 5).</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Блокировать перечисление REST</label></th>
                        <td>
                            <label style="font-size:13px;">
                                <input type="checkbox" name="<?php echo esc_attr( $key ); ?>[security_block_user_enum]" value="1" <?php checked( ! empty( $opts['security_block_user_enum'] ) ); ?>>
                                Возвращать 403 на <code>/wp/v2/users</code> для гостей
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Email-оповещения</label></th>
                        <td>
                            <label style="font-size:13px;">
                                <input type="checkbox" name="<?php echo esc_attr( $key ); ?>[security_email_alerts]" value="1" <?php checked( ! empty( $opts['security_email_alerts'] ) ); ?>>
                                Присылать письмо о критичных/высоких событиях
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Email для оповещений</label></th>
                        <td>
                            <input type="email" name="<?php echo esc_attr( $key ); ?>[security_email_recipient]" value="<?php echo esc_attr( $opts['security_email_recipient'] ?? '' ); ?>" class="regular-text" placeholder="Пусто — admin_email сайта">
                            <p class="description">Получатель уведомлений о подозрительной активности (не чаще 1 письма в 5 минут).</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Макс. записей журнала</label></th>
                        <td>
                            <input type="number" name="<?php echo esc_attr( $key ); ?>[security_max_entries]" value="<?php echo esc_attr( $opts['security_max_entries'] ?? 500 ); ?>" min="100" max="10000" style="width:110px;">
                            <p class="description">Сколько последних событий хранить (не сбрасывается при очистке).</p>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button( 'Сохранить настройки' ); ?>
        </form>

        <hr style="margin-top:24px;">
        <p style="color:#888;font-size:12px;">IDPG Auth v<?php echo esc_html( IDPG_AUTH_VERSION ); ?></p>
        <?php
    }
}
