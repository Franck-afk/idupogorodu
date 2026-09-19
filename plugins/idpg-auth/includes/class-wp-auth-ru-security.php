<?php
/**
 * Монитор безопасности IDPG Auth.
 *
 * Отслеживает попытки взлома, манипуляции с сайтом и попытки кражи проекта:
 *  - брутфорс входа (wp-login.php и REST через wp_authenticate) с авто-блокировкой IP;
 *  - перечисление пользователей (REST /wp/v2/users, ?author=N, /author/);
 *  - доступ к чувствительным файлам: .git, wp-config.php.*, .env, debug.log, бэкапы/дампы БД;
 *  - манипуляции в админке: смена роли на администратора, новые пользователи,
 *    активация/деактивация/удаление плагинов и тем, обновления, правка файлов
 *    через встроенный редактор;
 *  - email-оповещения о критичных событиях.
 *
 * События хранятся в отдельной БД-таблице (wp_{prefix}_idpg_auth_security) и
 * доступны только администраторам. Каждая запись: тип, уровень угрозы, сообщение,
 * IP, user-agent, URL запроса, метод и время.
 *
 * @package WP_Auth_Ru
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Auth_Ru_Security {

	const DB_VERSION      = '1.0.0';
	const DB_VERSION_KEY  = 'idpg_auth_security_db_version';

	const SEVERITY_CRITICAL = 'critical';
	const SEVERITY_HIGH     = 'high';
	const SEVERITY_MEDIUM   = 'medium';
	const SEVERITY_LOW      = 'low';

	/**
	 * Синглтон.
	 *
	 * @var WP_Auth_Ru_Security|null
	 */
	private static $instance = null;

	/**
	 * Настройки плагина (опция idpg_auth_settings).
	 *
	 * @var array
	 */
	private $settings = array();

	/**
	 * Флаг: таблица уже создана/проверена.
	 *
	 * @var bool
	 */
	private static $table_ready = false;

	/**
	 * Локальный кэш IP-адреса текущего запроса.
	 *
	 * @var string
	 */
	private $client_ip_cache = '';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = get_option( IDPG_AUTH_OPTIONS_KEY, array() );

		// ── Успешные входы — уже пишутся в WP_Auth_Ru_Login_Log ──────────────
		// Неудачные попытки входа (брутфорс).
		add_action( 'wp_login_failed', array( $this, 'on_login_failed' ), 10, 1 );

		// Блокировка IP при превышении порога (работает и для REST через wp_authenticate).
		add_filter( 'authenticate', array( $this, 'check_ip_blocked' ), 30, 3 );
		add_filter( 'login_message', array( $this, 'show_ip_blocked_message' ), 10, 1 );

		// Сканирование входящих запросов на признаки атак/кражи.
		add_action( 'init', array( $this, 'scan_current_request' ), 1 );

		// Злоупотребление REST API (перечисление пользователей).
		add_filter( 'rest_pre_dispatch', array( $this, 'check_rest_abuse' ), 10, 3 );

		// Манипуляции с пользователями и ролями.
		add_action( 'user_register',      array( $this, 'on_user_register' ), 10, 1 );
		add_action( 'set_user_role',      array( $this, 'on_set_user_role' ), 10, 3 );

		// Манипуляции с плагинами/темами и обновлениями.
		add_action( 'activated_plugin',   array( $this, 'on_plugin_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_deactivated' ), 10, 2 );
		add_action( 'delete_plugin',      array( $this, 'on_plugin_deleted' ), 10, 2 );
		add_action( 'delete_theme',       array( $this, 'on_theme_deleted' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade_complete' ), 10, 2 );

		// Правка файлов темы/плагина через встроенный редактор.
		add_action( 'admin_init', array( $this, 'on_admin_file_editor' ) );
	}

	// ─── Утилиты настроек ────────────────────────────────────────────────────

	/**
	 * Значение настройки с дефолтом.
	 *
	 * @param string $key     Ключ настройки.
	 * @param mixed  $default Значение по умолчанию.
	 *
	 * @return mixed
	 */
	private function setting( $key, $default = '' ) {
		return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $default;
	}

	/**
	 * Включён ли мониторинг безопасности.
	 *
	 * @return bool
	 */
	private function is_enabled() {
		return ! empty( $this->setting( 'security_enabled' ) );
	}

	// ─── Таблица ─────────────────────────────────────────────────────────────

	/**
	 * Имя таблицы журнала безопасности.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'idpg_auth_security';
	}

	/**
	 * Создаёт таблицу журнала безопасности (лениво).
	 */
	public function maybe_create_table() {
		if ( self::$table_ready ) {
			return;
		}
		self::$table_ready = true;

		global $wpdb;
		$table = self::table_name();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists === $table ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(40) NOT NULL DEFAULT '',
			severity VARCHAR(16) NOT NULL DEFAULT 'low',
			message TEXT NOT NULL,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_agent VARCHAR(500) NOT NULL DEFAULT '',
			request_uri VARCHAR(500) NOT NULL DEFAULT '',
			request_method VARCHAR(10) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY type (type),
			KEY severity (severity),
			KEY ip (ip),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::DB_VERSION_KEY, self::DB_VERSION, false );
	}

	// ─── Запись события ──────────────────────────────────────────────────────

	/**
	 * Добавляет событие в журнал безопасности.
	 *
	 * @param string $type     Тип события (брутфорс, git_leak и т.п.).
	 * @param string $severity Уровень: critical|high|medium|low.
	 * @param string $message  Сообщение.
	 * @param string $ip       IP-адрес (пусто — определить автоматически).
	 * @param string $uri      URL запроса (пусто — текущий).
	 * @param string $method   HTTP-метод (пусто — текущий).
	 * @param int    $user_id  ID пользователя (0 — без привязки).
	 * @param int    $dedup    Секунды, за которые событие с тем же ip+type не дублируется (0 — всегда).
	 */
	public function add_alert( $type, $severity, $message, $ip = '', $uri = '', $method = '', $user_id = 0, $dedup = 0 ) {
		global $wpdb;

		$severity = in_array( $severity, array( self::SEVERITY_CRITICAL, self::SEVERITY_HIGH, self::SEVERITY_MEDIUM, self::SEVERITY_LOW ), true )
			? $severity : self::SEVERITY_LOW;

		if ( '' === $ip ) {
			$ip = $this->get_client_ip();
		}
		if ( '' === $uri ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		}
		if ( '' === $method ) {
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		}
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';

		$this->maybe_create_table();

		// Дедупликация: не засорять журнал повторными событиями с того же IP.
		if ( $dedup > 0 ) {
			$table  = self::table_name();
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - $dedup );
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE ip = %s AND type = %s AND created_at >= %s LIMIT 1", $ip, $type, $cutoff )
			);
			if ( $exists ) {
				return;
			}
		}

		$wpdb->insert(
			self::table_name(),
			array(
				'type'           => sanitize_key( $type ),
				'severity'       => $severity,
				'message'        => sanitize_text_field( $message ),
				'ip'             => $ip,
				'user_id'        => (int) $user_id,
				'user_agent'     => mb_substr( $user_agent, 0, 500 ),
				'request_uri'    => mb_substr( $uri, 0, 500 ),
				'request_method' => $method,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		$this->trim_table();
		$this->maybe_notify( $severity, $message, $ip );
	}

	/**
	 * Обрезает журнал до лимита записей.
	 */
	private function trim_table() {
		global $wpdb;
		$max = (int) $this->setting( 'security_max_entries', 500 );
		$max = max( 100, min( 10000, $max ) );

		$table = self::table_name();
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $total <= $max ) {
			return;
		}
		$need_to_remove = $total - $max;
		$ids = $wpdb->get_col( "SELECT id FROM {$table} ORDER BY id ASC LIMIT {$need_to_remove}" );
		if ( $ids ) {
			$ids_str = implode( ',', array_map( 'intval', $ids ) );
			$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids_str})" );
		}
	}

	/**
	 * Email-оповещение о критичном/высоком событии (с тротлингом).
	 *
	 * @param string $severity Уровень.
	 * @param string $message  Сообщение.
	 * @param string $ip       IP.
	 */
	private function maybe_notify( $severity, $message, $ip ) {
		if ( empty( $this->setting( 'security_email_alerts' ) ) ) {
			return;
		}
		if ( ! in_array( $severity, array( self::SEVERITY_CRITICAL, self::SEVERITY_HIGH ), true ) ) {
			return;
		}

		// Не чаще одного письма за 5 минут — защита от рассылки при флуде.
		$last = (int) get_option( 'idpg_auth_security_last_email', 0 );
		if ( $last && ( time() - $last ) < 300 ) {
			return;
		}
		update_option( 'idpg_auth_security_last_email', time(), false );

		$to = sanitize_email( (string) $this->setting( 'security_email_recipient', '' ) );
		if ( ! $to ) {
			$to = get_option( 'admin_email' );
		}
		if ( ! $to ) {
			return;
		}

		$subject = '⚠️ IDPG Auth: подозрительная активность (' . home_url() . ')';
		$body    = "Обнаружено событие безопасности.\n\n"
			. "Уровень:    {$severity}\n"
			. "Событие:    {$message}\n"
			. "IP-адрес:   {$ip}\n"
			. "Время:      " . current_time( 'Y-m-d H:i:s' ) . "\n"
			. "Сайт:       " . home_url() . "\n\n"
			. 'Журнал: ' . admin_url( 'options-general.php?page=idpg-auth&tab=security' );

		wp_mail( $to, $subject, $body );
	}

	// ─── Выборка, статистика, очистка ───────────────────────────────────────

	/**
	 * Выборка событий с фильтрами и пагинацией.
	 *
	 * @param array $args type, severity, search, date_from, date_to, per_page, paged.
	 *
	 * @return array {items, total, pages, per_page, paged}
	 */
	public function get_alerts( $args = array() ) {
		$this->maybe_create_table();
		global $wpdb;

		$defaults = array(
			'type'      => '',
			'severity'  => '',
			'search'    => '',
			'date_from' => '',
			'date_to'   => '',
			'per_page'  => 30,
			'paged'     => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$per_page = max( 5, min( 100, absint( $args['per_page'] ) ) );
		$paged    = max( 1, absint( $args['paged'] ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$where = array( '1=1' );

		if ( '' !== $args['type'] ) {
			$where[] = $wpdb->prepare( 'type = %s', $args['type'] );
		}
		if ( '' !== $args['severity'] ) {
			$where[] = $wpdb->prepare( 'severity = %s', $args['severity'] );
		}
		if ( '' !== $args['search'] ) {
			$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = $wpdb->prepare( '(message LIKE %s OR ip LIKE %s OR request_uri LIKE %s)', $like, $like, $like );
		}
		if ( '' !== $args['date_from'] ) {
			$where[] = $wpdb->prepare( 'created_at >= %s', $args['date_from'] . ' 00:00:00' );
		}
		if ( '' !== $args['date_to'] ) {
			$where[] = $wpdb->prepare( 'created_at <= %s', $args['date_to'] . ' 23:59:59' );
		}

		$where_sql = implode( ' AND ', $where );
		$table     = self::table_name();

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );

		$items = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ),
			ARRAY_A
		);

		return array(
			'items'    => $items ? $items : array(),
			'total'    => $total,
			'pages'    => max( 1, (int) ceil( $total / $per_page ) ),
			'per_page' => $per_page,
			'paged'    => $paged,
		);
	}

	/**
	 * Статистика по журналу безопасности.
	 *
	 * @return array
	 */
	public function get_stats() {
		$this->maybe_create_table();
		global $wpdb;

		$table = self::table_name();
		$stats = array(
			'total'     => 0,
			'critical'  => 0,
			'high'      => 0,
			'medium'    => 0,
			'low'       => 0,
			'last_24h'  => 0,
			'by_type'   => array(),
		);

		$stats['total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		foreach ( array( 'critical', 'high', 'medium', 'low' ) as $sv ) {
			$stats[ $sv ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE severity = %s", $sv ) );
		}

		$cutoff_24 = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$stats['last_24h'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $cutoff_24 ) );

		$rows = $wpdb->get_results( "SELECT type, COUNT(*) AS c FROM {$table} GROUP BY type ORDER BY c DESC", ARRAY_A );
		foreach ( $rows as $row ) {
			$stats['by_type'][ $row['type'] ] = (int) $row['c'];
		}

		return $stats;
	}

	/**
	 * Полная очистка журнала безопасности.
	 */
	public function clear_alerts() {
		$this->maybe_create_table();
		global $wpdb;
		$wpdb->query( "DELETE FROM " . self::table_name() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	// ─── Блокировка IP (брутфорс) ────────────────────────────────────────────

	/**
	 * Ключ transient количества попыток для IP.
	 *
	 * @param string $ip IP.
	 *
	 * @return string
	 */
	private function fail_transient_key( $ip ) {
		return 'idpg_auth_fail_' . md5( $ip );
	}

	/**
	 * Ключ transient блокировки IP.
	 *
	 * @param string $ip IP.
	 *
	 * @return string
	 */
	private function lock_transient_key( $ip ) {
		return 'idpg_auth_lock_' . md5( $ip );
	}

	/**
	 * Заблокирован ли IP.
	 *
	 * @return bool
	 */
	public function is_ip_blocked( $ip = '' ) {
		if ( '' === $ip ) {
			$ip = $this->get_client_ip();
		}
		return (bool) get_transient( $this->lock_transient_key( $ip ) );
	}

	/**
	 * Разблокировка IP.
	 *
	 * @param string $ip IP.
	 */
	public function unblock_ip( $ip = '' ) {
		if ( '' === $ip ) {
			return;
		}
		delete_transient( $this->lock_transient_key( $ip ) );
		delete_transient( $this->fail_transient_key( $ip ) );

		// Убрать из карты-соответствий hash → IP.
		$map    = get_option( 'idpg_auth_lock_map', array() );
		$hash   = md5( $ip );
		if ( isset( $map[ $hash ] ) ) {
			unset( $map[ $hash ] );
			update_option( 'idpg_auth_lock_map', $map, false );
		}
	}

	/**
	 * Список заблокированных IP (для панели).
	 *
	 * @return array Список {ip, until}
	 */
	public function list_blocked_ips() {
		$map = get_option( 'idpg_auth_lock_map', array() );
		$out = array();
		foreach ( $map as $hash => $ip ) {
			$ttl = get_option( '_transient_timeout_' . $this->lock_transient_key( $ip ) );
			if ( $ttl && $ttl > time() ) {
				$out[] = array(
					'ip'    => $ip,
					'until' => wp_date( 'Y-m-d H:i:s', $ttl ),
				);
			}
		}
		return $out;
	}

	/**
	 * Неудачная попытка входа — счётчик и блокировка IP.
	 *
	 * @param string $username Пробованный логин.
	 */
	public function on_login_failed( $username ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$ip  = $this->get_client_ip();
		$usr = sanitize_user( $username, true );

		if ( $this->is_ip_blocked( $ip ) ) {
			// Повторные попытки с заблокированного IP — событие high (дедуп 10 мин).
			$this->add_alert( 'blocked_retry', self::SEVERITY_HIGH, 'Попытка входа с заблокированного IP (логин: ' . $usr . ')', $ip, '', '', 0, 600 );
			return;
		}

		$lock_before = $this->is_ip_blocked( $ip );

		if ( ! empty( $this->setting( 'security_block_bruteforce' ) ) ) {
			$threshold = (int) $this->setting( 'security_block_threshold', 5 );
			$threshold = max( 3, min( 100, $threshold ) );
			$window    = (int) $this->setting( 'security_lockout_minutes', 15 );
			$window    = max( 5, min( 1440, $window ) );

			$fail_key = $this->fail_transient_key( $ip );
			$count    = (int) get_transient( $fail_key ) + 1;
			set_transient( $fail_key, $count, $window * MINUTE_IN_SECONDS );

			if ( $count >= $threshold ) {
				// Блокируем IP: transient хранит IP, карта hash→IP — для панели.
				set_transient( $this->lock_transient_key( $ip ), 1, $window * MINUTE_IN_SECONDS );
				$map = get_option( 'idpg_auth_lock_map', array() );
				$map[ md5( $ip ) ] = $ip;
				update_option( 'idpg_auth_lock_map', $map, false );
				delete_transient( $fail_key );

				$this->add_alert( 'brute_force', self::SEVERITY_HIGH, 'IP заблокирован: ' . $count . ' неудачных попыток входа (логин: ' . $usr . ', блок ' . $window . ' мин)' );
				return;
			}

			// Ещё не порог — событие low, дедуп 60 сек.
			$this->add_alert( 'failed_login', self::SEVERITY_LOW, 'Неудачная попытка входа (логин: ' . $usr . ', попытка ' . $count . ' из ' . $threshold . ')' );
			return;
		}

		// Блокировка выключена — просто логируем попытки (дедуп 60 сек).
		$this->add_alert( 'failed_login', self::SEVERITY_LOW, 'Неудачная попытка входа (логин: ' . $usr . ')', '', '', '', 0, 60 );
	}

	/**
	 * Фильтр authenticate: отклоняет вход с заблокированного IP.
	 *
	 * @param mixed    $user     Пользователь (WP_User|WP_Error|null).
	 * @param string   $username Логин.
	 * @param string   $password Пароль.
	 *
	 * @return mixed
	 */
	public function check_ip_blocked( $user, $username, $password ) {
		if ( ! $this->is_enabled() ) {
			return $user;
		}
		$ip = $this->get_client_ip();
		if ( $this->is_ip_blocked( $ip ) ) {
			return new WP_Error(
				'idpg_auth_ip_blocked',
				'<strong>Доступ временно ограничен.</strong> С вашего IP-адреса было слишком много неудачных попыток входа. Попробуйте позже.'
			);
		}
		return $user;
	}

	/**
	 * Сообщение на странице входа для заблокированных IP.
	 *
	 * @param string $message Текущее сообщение.
	 *
	 * @return string
	 */
	public function show_ip_blocked_message( $message ) {
		if ( ! $this->is_enabled() ) {
			return $message;
		}
		if ( $this->is_ip_blocked() ) {
			$message .= '<p class="message" style="color:#d63638;background:#fff0f0;padding:8px 12px;border-radius:4px;margin-top:8px;"><strong>Вход временно заблокирован.</strong> Слишком много неудачных попыток. Попробуйте позже.</p>';
		}
		return $message;
	}

	// ─── Сканирование запросов (кража/взлом) ─────────────────────────────────

	/**
	 * Сканирует текущий запрос на признаки атак, кражи проекта и утечек.
	 */
	public function scan_current_request() {
		if ( ! $this->is_enabled() ) {
			return;
		}
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? rawurldecode( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri ) {
			return;
		}

		// Убираем домен при абсолютном URI.
		$path = $uri;
		if ( preg_match( '#^https?://#i', $path ) ) {
			$path = (string) wp_parse_url( $path, PHP_URL_PATH ) . '?' . (string) wp_parse_url( $path, PHP_URL_QUERY );
		}

		$path_l = strtolower( $path );
		$ip     = $this->get_client_ip();

		// Путь/имя файла без query (для проверки файловых утечек).
		$file = (string) wp_parse_url( 'http://x/' . ltrim( $path, '/' ), PHP_URL_PATH );

		// ── 1. Утечка .git (попытка украсть исходники) ───────────────────────
		if ( preg_match( '#(^|/|\.)git(/|$|/HEAD|/config|/packed-refs)#i', $file ) ) {
			$this->add_alert( 'git_leak', self::SEVERITY_CRITICAL, 'Попытка доступа к каталогу .git (утечка исходного кода): ' . $file, $ip, $uri, '', 0, 300 );
			return;
		}

		// ── 2. Конфиденциальные файлы конфигурации ──────────────────────────
		$config_patterns = array(
			'wp-config\.php\.(bak|save|old|orig|txt|dist|copy|zip|1|~)(/|$|\.|$)',
			'(^|/)\.env(\.[a-z0-9]+)?(/|$|\.|$)',
			'(^|/)\.htpasswd(/|$|\.|$)',
			'(^|/)license\.txt(/|$|\.|$)', // wpvivid/плагины бэкапов нередко кладут рядом.
		);
		foreach ( $config_patterns as $pat ) {
			if ( preg_match( '#' . $pat . '#i', $file ) ) {
				$this->add_alert( 'config_leak', self::SEVERITY_CRITICAL, 'Доступ к конфиденциальному файлу: ' . $file, $ip, $uri, '', 0, 300 );
				return;
			}
		}

		// ── 3. Бэкапы и дампы БД (попытка украсть проект/данные) ─────────────
		if ( preg_match( '#(wpvividbackups|wpvivid_staging|upgrade-temp-backup|/backups?/)|\.(zip|tar\.gz|tgz|gz|sql\.gz|sql\.zip|dump)$#i', $path_l )
			|| preg_match( '#(dump|backup|db)[^/]*\.(sql|zip|tar|gz)#i', $file ) ) {
			$this->add_alert( 'backup_access', self::SEVERITY_HIGH, 'Попытка доступа к бэкапу/дампу БД: ' . $file, $ip, $uri, '', 0, 300 );
			return;
		}

		// ── 4. Журналы отладки ──────────────────────────────────────────────
		if ( preg_match( '#(^|/)(debug\.log|error_log)(\.\d+)?(/|$|\.|$)#i', $file ) ) {
			$this->add_alert( 'log_leak', self::SEVERITY_HIGH, 'Доступ к журналу отладки: ' . $file, $ip, $uri, '', 0, 300 );
			return;
		}

		// ── 5. Обход каталогов (directory traversal) ─────────────────────────
		if ( preg_match( '#(\.\.%2f|\.\./|%2e%2e|\.\.\\\\)#i', $path ) ) {
			$this->add_alert( 'traversal', self::SEVERITY_HIGH, 'Попытка обхода каталогов (path traversal): ' . mb_substr( $path, 0, 200 ), $ip, $uri, '', 0, 300 );
			return;
		}

		// ── 6. Перечисление пользователей через ?author=N / /author/ ────────
		if ( preg_match( '#\?author=\d+#i', $path ) ) {
			$this->add_alert( 'author_enum', self::SEVERITY_MEDIUM, 'Перебор ID авторов (?author=N): ' . $path, $ip, $uri, '', 0, 300 );
			return;
		}
	}

	/**
	 * Злоупотребление REST API (перечисление пользователей).
	 *
	 * @param mixed           $result  Результат диспетчеризации.
	 * @param WP_REST_Server  $server  REST-сервер.
	 * @param WP_REST_Request $request Запрос.
	 *
	 * @return mixed
	 */
	public function check_rest_abuse( $result, $server, $request ) {
		if ( ! $this->is_enabled() ) {
			return $result;
		}
		if ( is_user_logged_in() ) {
			return $result;
		}

		$route = (string) $request->get_route();
		$ip    = $this->get_client_ip();

		// /wp/v2/users и /wp/v2/users/{id} — снятие списка аккаунтов.
		if ( preg_match( '#^/wp/v2/users(/|$)#', $route ) ) {
			$this->add_alert( 'user_enum_rest', self::SEVERITY_HIGH, 'Запрос списка пользователей через REST без авторизации: ' . $route, $ip, '', '', 0, 120 );
			if ( ! empty( $this->setting( 'security_block_user_enum' ) ) ) {
				return new WP_Error( 'idpg_auth_rest_blocked', 'Запрос заблокирован.', array( 'status' => 403 ) );
			}
		}

		return $result;
	}

	// ─── Манипуляции в админке ───────────────────────────────────────────────

	/**
	 * Новый пользователь (особенно администратор от лица не-админа).
	 *
	 * @param int $user_id ID нового пользователя.
	 */
	public function on_user_register( $user_id ) {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( $user && user_can( $user, 'manage_options' ) ) {
			$this->add_alert( 'admin_created', self::SEVERITY_CRITICAL, 'Создан пользователь с правами администратора: ' . $user->user_login, $this->get_client_ip(), '', '', (int) $user_id );
		}
	}

	/**
	 * Повышение роли до администратора.
	 *
	 * @param int    $user_id   ID пользователя.
	 * @param string $role      Новая роль.
	 * @param array  $old_roles Старые роли.
	 */
	public function on_set_user_role( $user_id, $role, $old_roles ) {
		if ( ! $this->is_enabled() ) {
			return;
		}
		if ( 'administrator' !== $role ) {
			return;
		}
		if ( is_array( $old_roles ) && in_array( 'administrator', $old_roles, true ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		$who  = empty( $user ) ? '' : $user->user_login;
		$this->add_alert( 'admin_escalation', self::SEVERITY_CRITICAL, 'Повышение прав пользователя до администратора: ' . $who, $this->get_client_ip(), '', '', (int) $user_id );
	}

	/**
	 * Активация плагина.
	 *
	 * @param string $plugin  Путь к файлу плагина.
	 * @param bool   $network Сетевая активация.
	 */
	public function on_plugin_activated( $plugin, $network ) {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$this->add_alert( 'plugin_activated', self::SEVERITY_MEDIUM, 'Активирован плагин: ' . $plugin, $this->get_client_ip(), '', '', get_current_user_id() );
	}

	/**
	 * Деактивация плагина.
	 *
	 * @param string $plugin  Путь к файлу плагина.
	 * @param bool   $network Сетевая деактивация.
	 */
	public function on_plugin_deactivated( $plugin, $network ) {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$this->add_alert( 'plugin_deactivated', self::SEVERITY_MEDIUM, 'Деактивирован плагин: ' . $plugin, $this->get_client_ip(), '', '', get_current_user_id() );
	}

	/**
	 * Удаление плагина.
	 *
	 * @param string $plugin  Путь к файлу плагина.
	 * @param bool   $network Сетевое удаление.
	 */
	public function on_plugin_deleted( $plugin, $network ) {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$this->add_alert( 'plugin_deleted', self::SEVERITY_HIGH, 'Удалён плагин: ' . $plugin, $this->get_client_ip(), '', '', get_current_user_id() );
	}

	/**
	 * Удаление темы.
	 *
	 * @param string $stylesheet Стиль (имя папки темы).
	 */
	public function on_theme_deleted( $stylesheet ) {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$this->add_alert( 'theme_deleted', self::SEVERITY_HIGH, 'Удалена тема: ' . $stylesheet, $this->get_client_ip(), '', '', get_current_user_id() );
	}

	/**
	 * Завершение апгрейда (плагины, темы, ядро).
	 *
	 * @param WP_Upgrader $upgrader Объект апгрейдера.
	 * @param array       $options  Параметры апгрейда.
	 */
	public function on_upgrade_complete( $upgrader, $options ) {
		if ( ! $this->is_enabled() ) {
			return;
		}
		if ( empty( $options['action'] ) || 'update' !== $options['action'] ) {
			return;
		}

		$type  = ! empty( $options['type'] ) ? $options['type'] : 'unknown';
		$items = '';

		if ( ! empty( $options['plugins'] ) ) {
			$items = implode( ', ', array_map( 'basename', (array) $options['plugins'] ) );
		} elseif ( ! empty( $options['themes'] ) ) {
			$items = implode( ', ', (array) $options['themes'] );
		} elseif ( ! empty( $options['hook_extra']['plugin'] ) ) {
			$items = basename( $options['hook_extra']['plugin'] );
		} elseif ( ! empty( $options['hook_extra']['theme'] ) ) {
			$items = $options['hook_extra']['theme'];
		} else {
			$items = '—';
		}

		$this->add_alert( 'update_done', self::SEVERITY_LOW, 'Обновлено (' . $type . '): ' . $items, $this->get_client_ip(), '', '', get_current_user_id() );
	}

	/**
	 * Правка файлов темы/плагина через встроенный редактор админки.
	 */
	public function on_admin_file_editor() {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$script   = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : '';
		$method   = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$file_arg = isset( $_REQUEST['file'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['file'] ) ) : '';
		$action   = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( ! in_array( $script, array( 'theme-editor.php', 'plugin-editor.php' ), true ) ) {
			return;
		}
		if ( 'POST' !== $method || 'update' !== $action ) {
			return;
		}

		$this->add_alert( 'file_edited', self::SEVERITY_HIGH, 'Изменён файл через WP-редактор (' . $script . '): ' . $file_arg, $this->get_client_ip(), '', '', get_current_user_id() );
	}

	// ─── Утилиты ─────────────────────────────────────────────────────────────

	/**
	 * IP-адрес клиента с учётом прокси (X-Forwarded-For).
	 *
	 * @return string
	 */
	public function get_client_ip() {
		if ( '' !== $this->client_ip_cache ) {
			return $this->client_ip_cache;
		}

		$ip = '';
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forward = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$candidate = trim( (string) ( $forward[0] ?? '' ) );
			$ip = filter_var( $candidate, FILTER_VALIDATE_IP );
		}
		if ( ! $ip && ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = filter_var( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP );
		}

		$this->client_ip_cache = $ip ? (string) $ip : '';
		return $this->client_ip_cache;
	}

	/**
	 * Известные типы событий для подписей в панели.
	 *
	 * @return array
	 */
	public static function type_labels() {
		return array(
			'brute_force'        => 'Брутфорс / блокировка IP',
			'blocked_retry'      => 'Попытка с заблокированного IP',
			'failed_login'       => 'Неудачная попытка входа',
			'git_leak'           => 'Утечка .git',
			'config_leak'        => 'Доступ к конфигурации',
			'backup_access'      => 'Доступ к бэкапу / дампу БД',
			'log_leak'           => 'Доступ к журналам',
			'traversal'          => 'Обход каталогов',
			'author_enum'        => 'Перечисление авторов',
			'user_enum_rest'     => 'Перечисление через REST',
			'admin_created'      => 'Создание администратора',
			'admin_escalation'   => 'Повышение прав до админа',
			'plugin_activated'   => 'Активация плагина',
			'plugin_deactivated' => 'Деактивация плагина',
			'plugin_deleted'     => 'Удаление плагина',
			'theme_deleted'      => 'Удаление темы',
			'update_done'        => 'Обновление',
			'file_edited'        => 'Правка файла',
		);
	}

	/**
	 * Подписи уровней угрозы.
	 *
	 * @return array
	 */
	public static function severity_labels() {
		return array(
			self::SEVERITY_CRITICAL => 'Критично',
			self::SEVERITY_HIGH     => 'Высокий',
			self::SEVERITY_MEDIUM   => 'Средний',
			self::SEVERITY_LOW      => 'Низкий',
		);
	}

	/**
	 * Цвета уровней угрозы.
	 *
	 * @return array
	 */
	public static function severity_colors() {
		return array(
			self::SEVERITY_CRITICAL => array( 'bg' => '#ffe4e6', 'border' => '#f43f5e', 'badge' => '#e11d48', 'text' => '#881337' ),
			self::SEVERITY_HIGH     => array( 'bg' => '#fff1f2', 'border' => '#fb7185', 'badge' => '#f43f5e', 'text' => '#9f1239' ),
			self::SEVERITY_MEDIUM   => array( 'bg' => '#fffbeb', 'border' => '#f59e0b', 'badge' => '#d97706', 'text' => '#92400e' ),
			self::SEVERITY_LOW      => array( 'bg' => '#f0f9ff', 'border' => '#38bdf8', 'badge' => '#0284c7', 'text' => '#075985' ),
		);
	}
}