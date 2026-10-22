<?php
/**
 * Plugin Name:       IDPG Core
 * Plugin URI:        https://example.com/idpg-core
 * Description:       Ядро плагина для сайта-афиши. Регистрирует CPT, таксономии, роли и API для событий и площадок.
 * Version:           1.1.2
 * Requires at least: 6.0
 * Tested up to:      6.7
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
 * Константы плагина.
 */
define( 'IDPG_CORE_VERSION', '1.1.2' );
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
require_once IDPG_CORE_DIR . 'includes/class-helpers.php';
require_once IDPG_CORE_DIR . 'includes/class-cpt.php';
require_once IDPG_CORE_DIR . 'includes/class-roles.php';
require_once IDPG_CORE_DIR . 'includes/class-city-filter.php';
require_once IDPG_CORE_DIR . 'includes/class-city-url.php';
require_once IDPG_CORE_DIR . 'includes/class-search.php';
require_once IDPG_CORE_DIR . 'includes/class-media.php';
require_once IDPG_CORE_DIR . 'includes/class-favorites.php';
require_once IDPG_CORE_DIR . 'includes/class-organizer-scope.php';
require_once IDPG_CORE_DIR . 'includes/class-organizer-url.php';
require_once IDPG_CORE_DIR . 'includes/class-organizer-meta.php';
require_once IDPG_CORE_DIR . 'includes/class-template-helpers.php';
require_once IDPG_CORE_DIR . 'includes/class-logger.php';
require_once IDPG_CORE_DIR . 'includes/class-ticket-sync.php';
require_once IDPG_CORE_DIR . 'includes/class-rest-api.php';
require_once IDPG_CORE_DIR . 'includes/class-ical-export.php';
require_once IDPG_CORE_DIR . 'includes/class-current-event-banner.php';

/**
 * Инициализация баннера текущего события (file-load).
 *
 * Синглтон создаётся на этапе загрузки файла (а не в idpg_core_init),
 * чтобы подписки в конструкторе успевали сработать до init.
 */
IDPG_Current_Event_Banner::instance();
require_once IDPG_CORE_DIR . 'includes/class-meta.php';
require_once IDPG_CORE_DIR . 'includes/class-event-fields.php';
require_once IDPG_CORE_DIR . 'includes/class-venue-fields.php';
require_once IDPG_CORE_DIR . 'includes/class-geo-service.php';
require_once IDPG_CORE_DIR . 'includes/class-migrations.php';
require_once IDPG_CORE_DIR . 'includes/class-views.php';
require_once IDPG_CORE_DIR . 'includes/class-notifications.php';
require_once IDPG_CORE_DIR . 'includes/class-auth.php';
require_once IDPG_CORE_DIR . 'includes/class-rest-auth.php';
require_once IDPG_CORE_DIR . 'includes/class-auth-rate-limiter.php';
require_once IDPG_CORE_DIR . 'includes/class-auth-emails.php';
require_once IDPG_CORE_DIR . 'includes/class-organizer-verify.php';
require_once IDPG_CORE_DIR . 'includes/class-city-selector-widget.php';
require_once IDPG_CORE_DIR . 'includes/class-event-calendar-widget.php';
require_once IDPG_CORE_DIR . 'includes/class-expected-events-widget.php';
require_once IDPG_CORE_DIR . 'includes/class-user-purge.php';
require_once IDPG_CORE_DIR . 'includes/class-privacy.php';
require_once IDPG_CORE_DIR . 'includes/class-geo-ranges.php';
require_once IDPG_CORE_DIR . 'includes/class-rest-organizer.php';
require_once IDPG_CORE_DIR . 'includes/class-dashboard-stats.php';
require_once IDPG_CORE_DIR . 'includes/class-event-moderation.php';
require_once IDPG_CORE_DIR . 'includes/class-promotion.php';
require_once IDPG_CORE_DIR . 'includes/class-cli.php';

// Интеграция с Yoast SEO (schema/OG/крошки; работает только при активном Yoast).
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

	// Нативные метабоксы полей события + вкладка «События» на странице IDPG.
	IDPG_Event_Fields::instance();

	// Нативные метабоксы полей площадки.
	IDPG_Venue_Fields::instance();

	IDPG_Migrations::instance();
	IDPG_Views::instance();
	IDPG_Notifications::instance();
	IDPG_Auth::instance();
	IDPG_REST_Auth::instance();
	IDPG_Auth_Emails::instance();
	IDPG_Organizer_Verify::instance();

	// Статистика проекта на dashboard (админ / организатор).
	IDPG_Dashboard_Stats::instance();

	// Модерация публикации событий организаторов (pending + уведомления).
	IDPG_Event_Moderation::instance();

	// DEV-235: роутер /organizer/* отключён (шаблоны кабинета удалены).
	// Класс подключён: profile/access-логика нужна REST и редиректам.

	// REST-маршруты кабинета организатора (события, площадки, профиль).
	IDPG_REST_Organizer::instance();

	// Снятие «закреплённости» по истечении срока акции (cron-хуки).
	IDPG_Promotion::instance();

	// Приватность (152-ФЗ): согласие, cookie-баннер, права субъекта, вкладка «IDPG».
	IDPG_Privacy::instance();

	// DEV-305: локальная база IP→регион (242-ФЗ), вкладка «Гео» на странице «IDPG».
	IDPG_Geo_Ranges::instance();

	// Интеграция с Yoast SEO (хуки вешаются только если плагин активен).
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
 * Создаёт таблицу диапазонов IP → регион (DEV-305, 242-ФЗ).
 *
 * Таблица {$wpdb->prefix}idpg_geo_ranges: начало/конец диапазона IPv4 (целое)
 * и название региона РФ. Наполняется импортом CSV на вкладке «Гео» страницы
 * «IDPG». Вызывается при активации; безопасна при повторном вызове (dbDelta).
 *
 * @return void
 */
function idpg_core_create_geo_ranges_table() {
	global $wpdb;

	$table           = $wpdb->prefix . IDPG_Geo_Ranges::TABLE;
	$charset_collate = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "CREATE TABLE $table (
	id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	ip_start BIGINT(20) UNSIGNED NOT NULL,
	ip_end BIGINT(20) UNSIGNED NOT NULL,
	region VARCHAR(191) NOT NULL DEFAULT '',
	PRIMARY KEY  (id),
	KEY range_lookup (ip_start,ip_end)
) $charset_collate;";

	dbDelta( $sql );

	// Проверять наличие таблицы при следующем запросе нужно заново.
	IDPG_Geo_Ranges::table_exists( true );
}

/**
 * Добавляет namespace «idpg» в белый список REST-маршрутов AIOS.
 *
 * AIOS-опция «Disallow Unauthorized REST Requests» блокирует все
 * незалогиненные запросы к /wp-json/*, включая idpg/v1/* (поиск,
 * календарь, подписка, избранное, авторизация). Маршрут в белом списке
 * не блокируется. Вызывается при активации плагина и защищён фильтром
 * на случай, если админ пересохранил настройки AIOS и затёр список.
 *
 * @return void
 */
function idpg_core_aios_whitelist_rest() {
	// AIOS не активен — whitelist не нужен.
	if ( ! class_exists( 'AIO_WP_Security' ) ) {
		return;
	}

	$configs = get_option( 'aio_wp_security_configs', array() );
	if ( ! is_array( $configs ) ) {
		$configs = array();
	}

	// Блокировка незалогиненных REST-запросов не включена — whitelist не нужен.
	if ( ! isset( $configs['aiowps_disallow_unauthorized_rest_requests'] ) || '1' != $configs['aiowps_disallow_unauthorized_rest_requests'] ) {
		return;
	}

	$whitelist = isset( $configs['aios_whitelisted_rest_routes'] ) && is_array( $configs['aios_whitelisted_rest_routes'] )
		? $configs['aios_whitelisted_rest_routes']
		: array();

	// Маршрут уже в списке.
	if ( in_array( 'idpg', $whitelist, true ) ) {
		return;
	}

	$whitelist[] = 'idpg';

	// Через API AIOS (если доступен глобальный объект) — с сохранением в БД.
	if ( isset( $GLOBALS['aio_wp_security'] ) && is_object( $GLOBALS['aio_wp_security'] ) && ! empty( $GLOBALS['aio_wp_security']->configs ) ) {
		$GLOBALS['aio_wp_security']->configs->set_value( 'aios_whitelisted_rest_routes', $whitelist, true );
		return;
	}

	// Fallback: прямое обновление опции (структура та же, что сохраняет AIOS).
	$configs['aios_whitelisted_rest_routes'] = $whitelist;
	update_option( 'aio_wp_security_configs', $configs );
}

/**
 * Safety-net: маршрут idpg всегда в whitelist AIOS на этапе запроса.
 *
 * Работает даже если настройки AIOS пересохранены и «idpg» удалили из
 * списка — фильтр aios_whitelisted_rest_routes применяется AIOS при
 * проверке каждого REST-запроса (wp-security-general-init-tasks.php).
 *
 * @param array $routes Белый список маршрутов AIOS.
 * @return array
 */
function idpg_core_aios_whitelist_filter( $routes ) {
	if ( ! is_array( $routes ) ) {
		$routes = array();
	}
	if ( ! in_array( 'idpg', $routes, true ) ) {
		$routes[] = 'idpg';
	}
	return $routes;
}
add_filter( 'aios_whitelisted_rest_routes', 'idpg_core_aios_whitelist_filter' );

/**
 * Действия при активации плагина.
 *
 * Регистрирует CPT, таксономии, создаёт роли и планирует крон.
 */
	function idpg_core_activate() {
		IDPG_CPT::instance()->register_post_types();
		IDPG_CPT::instance()->register_taxonomies();
		IDPG_Roles::instance()->create_roles();

		// DEV-98: роль организатора выдаётся только после подтверждения
		// email, поэтому ролью по умолчанию она быть не может.
		IDPG_Roles::instance()->ensure_safe_default_role();

	IDPG_Ticket_Sync::instance()->schedule_sync();

	// Таблица подписчиков дайджеста (виджет IDPG_Subscribe_Widget).
	idpg_core_create_subscribers_table();

	// DEV-305: таблица диапазонов IP→регион для локального geo-определения.
	idpg_core_create_geo_ranges_table();

	// Белый список REST-маршрутов AIOS: без него AIOS блокирует idpg/v1/*.
	idpg_core_aios_whitelist_rest();

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

/**
 * Предупреждение администратору о конфликте с All-In-One WP Security (AIOS).
 *
 * Плагин активно использует REST API (поиск, подписки, избранное, авторизация,
 * синк билетов, календарь) и кастомный URL входа /login/.
 * Если в AIOS включено «Disallow Unauthorized REST Requests» без белого списка
 * маршрута idpg, или включено «Rename Login Page» — функционал плагина ломается.
 * Показываем notice только администраторам и только когда AIOS реально активен.
 *
 * @return void
 */
function idpg_core_aios_compat_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// AIOS не установлен/активен — конфликта быть не может.
	if ( ! class_exists( 'AIO_WP_Security' ) ) {
		return;
	}

	// Все настройки AIOS хранятся одной опцией с ключами aiowps_*.
	$aios_config = get_option( 'aio_wp_security_configs', array() );
	if ( ! is_array( $aios_config ) ) {
		return;
	}

	$issues = array();

	// 1. REST API заблокирован для неавторизованных запросов (затрагивает весь idpg/v1/*).
	if ( isset( $aios_config['aiowps_disallow_unauthorized_rest_requests'] ) && '1' == $aios_config['aiowps_disallow_unauthorized_rest_requests'] ) {
		// Маршрут idpg уже в белом списке AIOS — блокировка плагин не задевает.
		$whitelist      = isset( $aios_config['aios_whitelisted_rest_routes'] ) ? $aios_config['aios_whitelisted_rest_routes'] : array();
		$idpg_whitelisted = false;
		if ( is_array( $whitelist ) ) {
			foreach ( $whitelist as $route ) {
				if ( is_string( $route ) && 0 === strpos( $route, 'idpg' ) ) {
					$idpg_whitelisted = true;
					break;
				}
			}
		}

		if ( ! $idpg_whitelisted ) {
			$issues[] = __( 'REST API заблокирован для незалогиненных пользователей (AIOS «Disallow Unauthorized REST Requests»). Без этого плагин IDPG не работает: поиск, календарь, подписка, избранное, вход по ссылкам. Добавьте маршрут «idpg» в белый список AIOS: WP Security → Firewall → WAF Config → REST API → Whitelist.', 'idpg-core' );
		}
	}

	// 2. Включено переименование страницы входа — конфликтует с кастомным /login/.
	if ( isset( $aios_config['aiowps_enable_rename_login_page'] ) && '1' == $aios_config['aiowps_enable_rename_login_page'] ) {
		$issues[] = __( 'Включено «Rename Login Page» (AIOS). Плагин IDPG использует собственную страницу входа /login/, поэтому редиректы и защита AIOS не работают. Отключите переименование в AIOS (Brute Force → Rename Login Page) либо не используйте кастомную страницу IDPG.', 'idpg-core' );
	}

	if ( empty( $issues ) ) {
		return;
	}

	echo '<div class="notice notice-warning idpg-aios-notice"><p><strong>' . esc_html__( 'Совместимость с All-In-One WP Security', 'idpg-core' ) . '</strong></p><ul>';
	foreach ( $issues as $issue ) {
		echo '<li>' . esc_html( $issue ) . '</li>';
	}
	echo '</ul></div>';
}
add_action( 'admin_notices', 'idpg_core_aios_compat_notice' );

/**
 * Скрытие уведомления об обновлении ядра WordPress у не-администраторов.
 *
 * Ядро показывает не-админам (роли без cap update_core) плашку
 * «Доступна новая версия WordPress — сообщите администратору».
 * Для организаторов и подписчиков этот nag в кабинете не имеет смысла,
 * поэтому убираем список обновлений ядра из transient только для залогиненных
 * пользователей без права update_core. Администраторы, cron и автообновления
 * не затрагиваются.
 *
 * @param object|false $value Transient update_core.
 * @return object|false
 */
function idpg_core_hide_core_update_nag( $value ) {
	// Администраторы, неавторизованные и cron видят обновления как обычно.
	if ( ! is_user_logged_in() || current_user_can( 'update_core' ) ) {
		return $value;
	}

	// Фразы плашки ядра зависят от наличия response; очищаем список обновлений.
	if ( is_object( $value ) && isset( $value->response ) && is_array( $value->response ) ) {
		$value->response = array();
	}

	return $value;
}
add_filter( 'site_transient_update_core', 'idpg_core_hide_core_update_nag', 10 );
