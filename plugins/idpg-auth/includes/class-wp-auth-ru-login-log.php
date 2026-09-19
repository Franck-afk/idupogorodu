<?php
/**
 * Журнал входов пользователей (IDPG Auth).
 *
 * Хранит подробные сведения о каждом входе в отдельной БД-таблице:
 * какой аккаунт, с какого IP-адреса, из какого браузера/ОС/устройства,
 * через какой провайдер (VK / Яндекс / MAX / логин-пароль) и когда.
 *
 * Таблица создаётся лениво при первой записи или при первом просмотре вкладки
 * «Входы» в админке. Письма/данные не маскируются — раздел доступен
 * только администраторам (manage_options).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_Auth_Ru_Login_Log {

	const DB_VERSION = '1.0.0';
	const DB_VERSION_KEY = 'idpg_auth_logins_db_version';

	/**
	 * Провайдер текущего логина. Устанавливается провайдерами непосредственно
	 * перед вызовом do_action( 'wp_login' ) при OAuth-входе, затем сбрасывается.
	 *
	 * @var string
	 */
	public static $current_provider = '';

	/**
	 * Флаг: таблица уже проверялась/создавалась.
	 *
	 * @var bool
	 */
	private static $table_created = false;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_login', array( $this, 'on_wp_login' ), 20, 2 );
	}

	/**
	 * Имя таблицы журнала входов.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'idpg_auth_logins';
	}

	/**
	 * Создаёт таблицу, если её ещё нет (лениво).
	 */
	public function maybe_create_table() {
		if ( self::$table_created ) {
			return;
		}
		self::$table_created = true;

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
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_login VARCHAR(191) NOT NULL DEFAULT '',
			user_email VARCHAR(191) NOT NULL DEFAULT '',
			display_name VARCHAR(191) NOT NULL DEFAULT '',
			provider VARCHAR(32) NOT NULL DEFAULT 'password',
			ip VARCHAR(45) NOT NULL DEFAULT '',
			browser VARCHAR(64) NOT NULL DEFAULT '',
			os VARCHAR(64) NOT NULL DEFAULT '',
			device VARCHAR(16) NOT NULL DEFAULT 'desktop',
			user_agent VARCHAR(500) NOT NULL DEFAULT '',
			login_time DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY provider (provider),
			KEY login_time (login_time)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::DB_VERSION_KEY, self::DB_VERSION, false );
	}

	/**
	 * Хук wp_login: фиксирует успешный вход.
	 *
	 * @param string   $user_login Логин.
	 * @param WP_User  $user       Объект пользователя.
	 */
	public function on_wp_login( $user_login, $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return;
		}

		$provider = self::$current_provider;
		self::$current_provider = '';
		if ( '' === $provider ) {
			$provider = 'password';
		}

		$this->record_login( $user->ID, $provider );
	}

	/**
	 * Записывает один вход пользователя.
	 *
	 * @param int    $user_id  ID пользователя WP.
	 * @param string $provider Провайдер: vk / yandex / max / password и т.п.
	 */
	public function record_login( $user_id, $provider = '' ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$this->maybe_create_table();

		$provider = sanitize_key( $provider );
		if ( '' === $provider ) {
			$provider = 'password';
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		$parsed     = $this->parse_user_agent( $user_agent );

		global $wpdb;
		$wpdb->insert(
			self::table_name(),
			array(
				'user_id'      => (int) $user_id,
				'user_login'   => $user->user_login,
				'user_email'   => $user->user_email,
				'display_name' => $user->display_name,
				'provider'     => $provider,
				'ip'           => $this->get_client_ip(),
				'browser'      => $parsed['browser'],
				'os'           => $parsed['os'],
				'device'       => $parsed['device'],
				'user_agent'   => mb_substr( $user_agent, 0, 500 ),
				'login_time'   => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Выборка записей с фильтрами и пагинацией.
	 *
	 * @param array $args search, provider, date_from, date_to, per_page, paged.
	 *
	 * @return array {items, total, pages, per_page, paged}
	 */
	public function get_logs( $args = array() ) {
		$this->maybe_create_table();
		global $wpdb;

		$defaults = array(
			'search'    => '',
			'provider'  => '',
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

		if ( '' !== $args['search'] ) {
			$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = $wpdb->prepare(
				'(user_login LIKE %s OR user_email LIKE %s OR display_name LIKE %s OR ip LIKE %s)',
				$like, $like, $like, $like
			);
		}

		if ( '' !== $args['provider'] ) {
			$where[] = $wpdb->prepare( 'provider = %s', $args['provider'] );
		}

		if ( '' !== $args['date_from'] ) {
			$where[] = $wpdb->prepare( 'login_time >= %s', $args['date_from'] . ' 00:00:00' );
		}

		if ( '' !== $args['date_to'] ) {
			$where[] = $wpdb->prepare( 'login_time <= %s', $args['date_to'] . ' 23:59:59' );
		}

		$where_sql = implode( ' AND ', $where );
		$table     = self::table_name();

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );

		$items = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY login_time DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ),
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
	 * Сводная статистика по журналу входов.
	 *
	 * @return array
	 */
	public function get_stats() {
		$this->maybe_create_table();
		global $wpdb;

		$table = self::table_name();
		$stats = array(
			'total'     => 0,
			'today'     => 0,
			'week'      => 0,
			'providers' => array(),
		);

		$stats['total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$stats['today'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE login_time >= %s", current_time( 'Y-m-d' ) . ' 00:00:00' ) );
		$stats['week']  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $table . ' WHERE login_time >= %s', gmdate( 'Y-m-d H:i:s', strtotime( '-7 days', current_time( 'timestamp' ) ) ) ) );

		$rows = $wpdb->get_results( "SELECT provider, COUNT(*) AS c FROM {$table} GROUP BY provider ORDER BY c DESC", ARRAY_A );
		foreach ( $rows as $row ) {
			$stats['providers'][ $row['provider'] ] = (int) $row['c'];
		}

		return $stats;
	}

	/**
	 * Очищает журнал входов полностью.
	 */
	public function clear_logs() {
		$this->maybe_create_table();
		global $wpdb;
		$wpdb->query( "DELETE FROM " . self::table_name() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * IP-адрес клиента с учётом прокси (X-Forwarded-For).
	 *
	 * @return string
	 */
	protected function get_client_ip() {
		$ip = '';

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forward = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$candidate = trim( (string) ( $forward[0] ?? '' ) );
			$ip = filter_var( $candidate, FILTER_VALIDATE_IP );
		}

		if ( ! $ip && ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP );
		}

		return $ip ? (string) $ip : '';
	}

	/**
	 * Разбор User-Agent на браузер, ОС и тип устройства.
	 *
	 * @param string $ua User-Agent.
	 *
	 * @return array {browser, os, device}
	 */
	protected function parse_user_agent( $ua ) {
		$browser = 'Неизвестно';
		$os      = 'Неизвестно';
		$device  = 'desktop';
		$ua      = ' ' . $ua . ' ';

		// ── ОС ────────────────────────────────────────────────────────────────
		$os_map = array(
			'Windows NT 10.0' => 'Windows 10/11',
			'Windows NT 6.3'  => 'Windows 8.1',
			'Windows NT 6.2'  => 'Windows 8',
			'Windows NT 6.1'  => 'Windows 7',
			'Windows NT 6.0'  => 'Windows Vista',
			'Windows NT 5.1'  => 'Windows XP',
			'Android'         => 'Android',
			'iPhone'          => 'iOS (iPhone)',
			'iPad'            => 'iPadOS (iPad)',
			'iPod'            => 'iOS (iPod)',
			'Mac OS X'        => 'macOS',
			'Linux'           => 'Linux',
			'CrOS'            => 'ChromeOS',
		);
		foreach ( $os_map as $needle => $name ) {
			if ( false !== stripos( $ua, $needle ) ) {
				$os = $name;
				break;
			}
		}

		// ── Устройство ────────────────────────────────────────────────────────
		if ( preg_match( '/bot|crawler|spider|robot|slurp|curl|preview/i', $ua ) ) {
			$device = 'bot';
		} elseif ( preg_match( '/iPad|Tablet|Silk/i', $ua ) ) {
			$device = 'tablet';
		} elseif ( preg_match( '/Mobile|Android|iPhone|iPod|Opera Mini|IEMobile|Windows Phone/i', $ua ) ) {
			$device = 'mobile';
		}

		// ── Браузер ──────────────────────────────────────────────────────────
		if ( preg_match( '/Edg\\/([0-9.]+)/i', $ua, $m ) ) {
			$browser = 'Edge ' . $m[1];
		} elseif ( preg_match( '/YaBrowser\\/([0-9.]+)/i', $ua, $m ) ) {
			$browser = 'Яндекс.Браузер ' . $m[1];
		} elseif ( preg_match( '/OPR\\/([0-9.]+)/i', $ua, $m ) ) {
			$browser = 'Opera ' . $m[1];
		} elseif ( preg_match( '/Firefox\\/([0-9.]+)/i', $ua, $m ) ) {
			$browser = 'Firefox ' . $m[1];
		} elseif ( preg_match( '/Chrome\\/([0-9.]+)/i', $ua, $m ) ) {
			$browser = 'Chrome ' . $m[1];
		} elseif ( preg_match( '/Safari\\/([0-9.]+)/i', $ua, $m ) ) {
			$browser = 'Safari ' . $m[1];
		}

		return array(
			'browser' => $browser,
			'os'      => $os,
			'device'  => $device,
		);
	}
}