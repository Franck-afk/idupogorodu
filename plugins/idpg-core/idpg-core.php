<?php
/**
 * Plugin Name:       IDPG Core
 * Plugin URI:        https://example.com/idpg-core
 * Description:       Ядро плагина для сайта-афиши. Регистрирует CPT, таксономии, роли и API для событий и площадок.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            IDPG Team
 * Author URI:        https://example.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       idpg-core
 * Domain Path:       /languages
 *
 * @package IDPG_Core
 */

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composer autoload (Carbon Fields).
 *
 * Подключается до загрузки классов и инициализации синглтонов,
 * чтобы библиотека была доступна на всём протяжении загрузки плагина
 * (и темы, которая загружается позже).
 */
$composer_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $composer_autoload ) ) {
	require_once $composer_autoload;
}

/**
 * Carbon Fields bootstrap.
 *
 * Тот же тайминг, что был у темы: after_setup_theme с приоритетом 5.
 * Плагины загружаются раньше темы, поэтому этот boot выполнится первым,
 * а одноимённый boot темы (если ещё присутствует) пропустится по guard'у.
 */
add_action( 'after_setup_theme', 'idpg_carbon_fields_boot', 5 );
function idpg_carbon_fields_boot() {
	if ( function_exists( 'carbon_get_theme_option' ) ) {
		return;
	}
	\Carbon_Fields\Carbon_Fields::boot();
}

/**
 * Константы плагина.
 */
define( 'IDPG_CORE_VERSION', '1.1.0' );
define( 'IDPG_CORE_FILE', __FILE__ );
define( 'IDPG_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'IDPG_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'IDPG_CORE_BASENAME', plugin_basename( __FILE__ ) );

// ID города по умолчанию для фильтрации (терм таксономии city).
if ( ! defined( 'IDPG_DEFAULT_CITY_ID' ) ) {
	define( 'IDPG_DEFAULT_CITY_ID', 15 );
}



// Базовый URL внешнего API агрегатора билетов.
if ( ! defined( 'IDPG_TICKET_API_URL' ) ) {
	define( 'IDPG_TICKET_API_URL', 'https://api.example.com/v1' );
}

/**
 * Подключение файлов классов.
 */
require_once IDPG_CORE_DIR . 'includes/class-cpt.php';
require_once IDPG_CORE_DIR . 'includes/class-roles.php';
require_once IDPG_CORE_DIR . 'includes/class-city-filter.php';
require_once IDPG_CORE_DIR . 'includes/class-city-url.php';
require_once IDPG_CORE_DIR . 'includes/class-search.php';
require_once IDPG_CORE_DIR . 'includes/class-media.php';
require_once IDPG_CORE_DIR . 'includes/class-favorites.php';
require_once IDPG_CORE_DIR . 'includes/class-organizer-scope.php';
require_once IDPG_CORE_DIR . 'includes/class-template-helpers.php';
require_once IDPG_CORE_DIR . 'includes/class-ticket-sync.php';
require_once IDPG_CORE_DIR . 'includes/class-rest-api.php';
require_once IDPG_CORE_DIR . 'includes/class-ical-export.php';
require_once IDPG_CORE_DIR . 'includes/class-current-event-banner.php';
require_once IDPG_CORE_DIR . 'includes/class-settings-page.php';

/**
 * Инициализация баннера текущего события (file-load).
 *
 * Синглтон создаётся на этапе загрузки файла (а не в idpg_core_init),
 * чтобы подписка на carbon_fields_register_fields — выполняемая в
 * конструкторе — успевала сработать до init priority 0, на котором CF
 * диспатчит этот хук.
 */
IDPG_Current_Event_Banner::instance();
require_once IDPG_CORE_DIR . 'includes/class-meta.php';
require_once IDPG_CORE_DIR . 'includes/class-geo-service.php';
require_once IDPG_CORE_DIR . 'includes/class-migrations.php';
require_once IDPG_CORE_DIR . 'includes/class-views.php';
require_once IDPG_CORE_DIR . 'includes/class-notifications.php';
require_once IDPG_CORE_DIR . 'includes/class-auth.php';
require_once IDPG_CORE_DIR . 'includes/class-rest-auth.php';
require_once IDPG_CORE_DIR . 'includes/class-auth-rate-limiter.php';
require_once IDPG_CORE_DIR . 'includes/class-auth-emails.php';
require_once IDPG_CORE_DIR . 'includes/class-maintenance.php';
require_once IDPG_CORE_DIR . 'includes/class-city-selector-widget.php';
require_once IDPG_CORE_DIR . 'includes/class-event-calendar-widget.php';
require_once IDPG_CORE_DIR . 'includes/class-expected-events-widget.php';
require_once IDPG_CORE_DIR . 'includes/class-user-purge.php';
require_once IDPG_CORE_DIR . 'includes/class-organizer-page.php';
// Интеграция с Rank Math SEO (schema/OG; работает только при активном Rank Math).
require_once IDPG_CORE_DIR . 'includes/class-seo-integration.php';
/**
 * Инициализация компонентов плагина.
 *
 * Запускает регистрацию CPT, таксономий и ролей после загрузки ядра WordPress.
 */
function idpg_core_init() {
	IDPG_CPT::instance();
	IDPG_Roles::instance();
	IDPG_City_Filter::instance();
	IDPG_Media::instance();
	IDPG_Ticket_Sync::instance();
	IDPG_REST_API::instance();
	IDPG_iCal_Export::instance();
	IDPG_Meta::instance();
	IDPG_Migrations::instance();
	IDPG_Views::instance();
	IDPG_Notifications::instance();
	IDPG_Auth::instance();
	IDPG_REST_Auth::instance();
	IDPG_Auth_Emails::instance();
	IDPG_Maintenance::instance();
	IDPG_Settings_Page::instance();

	// Страница доуполнения анкеты организатора /organizer/complete/.
	IDPG_Organizer_Page::init();

	// Интеграция с Rank Math SEO (хуки вешаются только если плагин активен).
	IDPG_SEO_Integration::instance();
}
add_action( 'init', 'idpg_core_init' );

/**
 * Регистрация виджетов плагина IDPG.
 */
function idpg_core_register_plugin_widgets() {
	register_widget( 'IDPG_City_Selector_Widget' );
	register_widget( 'IDPG_Event_Calendar_Widget' );
	register_widget( 'IDPG_Expected_Events_Widget' );
}
add_action( 'widgets_init', 'idpg_core_register_plugin_widgets' );

/**
 * Подмена аватара пользователя загруженным изображением.
 *
 * Если у пользователя задан meta-ключ idpg_avatar_attachment_id
 * (загруженный аватар через ЛК), возвращает URL этого вложения
 * вместо стандартного Gravatar. Работает только на фронтенде —
 * в админке WordPress остаётся стандартный аватар.
 *
 * @param string $url           URL аватара.
 * @param mixed  $id_or_email   ID пользователя, email или объект.
 * @param array  $args          Аргументы (size и пр.).
 * @return string
 */
function idpg_core_get_avatar_url( $url, $id_or_email, $args ) {
	// Подменяем аватар только на фронтенде; админку WordPress не трогаем.
	if ( is_admin() ) {
		return $url;
	}

	if ( $id_or_email instanceof WP_User ) {
		$user_id = $id_or_email->ID;
	} elseif ( is_numeric( $id_or_email ) ) {
		$user_id = (int) $id_or_email;
	} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
		$user = get_user_by( 'email', $id_or_email );
		$user_id = $user ? $user->ID : 0;
	} else {
		return $url;
	}

	if ( ! $user_id ) {
		return $url;
	}

	// Через единый медиа-хелпер: ID вложения аватара и его URL.
	$attachment_id = IDPG_Media::idpg_get_avatar_id( $user_id );
	if ( ! $attachment_id ) {
		return $url;
	}

	$size = isset( $args['size'] ) ? (int) $args['size'] : 96;
	$size = min( $size, 512 );

	$custom_url = wp_get_attachment_image_url( $attachment_id, array( $size, $size ) );

	if ( ! $custom_url ) {
		return $url;
	}

	return $custom_url;
}
add_filter( 'get_avatar_url', 'idpg_core_get_avatar_url', 10, 3 );

/**
 * Создаёт таблицу подписчиков дайджеста.
 *
 * Таблица {$wpdb->prefix}idpg_subscribers: email (уникальный), IP и дата подписки.
 * Вызывается при активации плагина; безопасна при повторном вызове (dbDelta).
 *
 * @return void
 */
function idpg_core_create_subscribers_table() {
	global $wpdb;

	$table           = $wpdb->prefix . 'idpg_subscribers';
	$charset_collate = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "CREATE TABLE $table (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		email VARCHAR(100) NOT NULL,
		ip VARCHAR(45) NOT NULL DEFAULT '',
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY email (email),
		KEY created_at (created_at)
	) $charset_collate;";

	dbDelta( $sql );
}

/**
 * Действия при активации плагина.
 *
 * Регистрирует CPT, таксономии, создаёт роли и планирует крон.
 */
function idpg_core_activate() {
	IDPG_CPT::instance()->register_post_types();
	IDPG_CPT::instance()->register_taxonomies();
	IDPG_Roles::instance()->create_roles();

	IDPG_Ticket_Sync::instance()->schedule_sync();

	// Таблица подписчиков дайджеста (виджет IDPG_Subscribe_Widget).
	idpg_core_create_subscribers_table();

	// Перенос мета-полей на единый префикс _idpg_.
	IDPG_Migrations::run_all();

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'idpg_core_activate' );

/**
 * Действия при деактивации плагина.
 */
function idpg_core_deactivate() {
	IDPG_Roles::instance()->remove_roles();
	IDPG_Ticket_Sync::instance()->unschedule_sync();
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'idpg_core_deactivate' );
