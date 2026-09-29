<?php
/**
 * Страница доуполнения анкеты организатора /organizer/complete/.
 *
 * Организатор, созданный через соц-кнопку с intent=organizer
 * (мета-флаг _idpg_org_pending), попадает сюда после входа.
 * Форма отправляется в REST /idpg/v1/auth/organizer-complete
 * (класс IDPG_REST_Auth).
 *
 * @package IDPG_Core
 */

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Organizer_Page
 *
 * Rewrite /organizer/complete/ + шаблон + ассеты + защита доступа.
 */
class IDPG_Organizer_Page {

	/**
	 * Query-переменная маршрута.
	 */
	const PAGE_QVAR = 'idpg_organizer_complete';

	/**
	 * Версия rewrite-правил. Инкремент → принудительный flush один раз.
	 */
	const REWRITE_VERSION = 1;
	const FLUSH_OPT       = 'idpg_organizer_complete_rewrite_flushed';

	/**
	 * Инициализация.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrites' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 30 );

		// Pending-организатор в админке: уведомление со ссылкой на анкету.
		add_action( 'admin_notices', array( __CLASS__, 'admin_pending_notice' ) );
	}

	/**
	 * Требует ли текущий пользователь доуполнения анкеты.
	 *
	 * @return bool
	 */
	public static function is_pending() {
		$user = wp_get_current_user();

		return $user->exists()
			&& in_array( 'event_organizer', (array) $user->roles, true )
			&& get_user_meta( $user->ID, '_idpg_org_pending', true );
	}

	/**
	 * Уведомления в админке для организатора.
	 *
	 * Pending: анкета не заполнена — блокирующее внимание.
	 *
	 * @return void
	 */
	public static function admin_pending_notice() {
		if ( ! self::is_pending() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Завершите регистрацию:', 'idpg-core' ),
			esc_html__( 'заполните анкету организации, чтобы публиковать события.', 'idpg-core' ),
			esc_url( home_url( '/organizer/complete/' ) ),
			esc_html__( 'Заполнить анкету', 'idpg-core' )
		);
	}

	/**
	 * Rewrite-правило /organizer/complete/.
	 *
	 * @return void
	 */
	public static function rewrites() {
		add_rewrite_rule( '^organizer/complete/?$', 'index.php?' . self::PAGE_QVAR . '=1', 'top' );

		if ( (string) get_option( self::FLUSH_OPT ) !== (string) self::REWRITE_VERSION ) {
			flush_rewrite_rules();
			update_option( self::FLUSH_OPT, self::REWRITE_VERSION );
		}
	}

	/**
	 * Регистрация query-переменной.
	 *
	 * @param array $vars Существующие query-переменные.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::PAGE_QVAR;

		return $vars;
	}

	/**
	 * Подключение шаблона /organizer/complete/.
	 *
	 * Неавторизованные — на страницу входа.
	 *
	 * @param string $template Текущий шаблон.
	 * @return string
	 */
	public static function template( $template ) {
		if ( ! get_query_var( self::PAGE_QVAR ) ) {
			return $template;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( home_url( '/login/' ) );
			exit;
		}

		$page_template = locate_template( 'page-organizer-complete.php' );

		return $page_template ? $page_template : $template;
	}

	/**
	 * Ассеты страницы.
	 *
	 * Скрипт формы подключается темой (functions.php) вместе с auth.js —
	 * файл лежит в assets/js темы. Плагин отвечает только за маршрут
	 * и шаблон.
	 *
	 * @return void
	 */
	public static function assets() {
		// Маршрут и шаблон регистрируются этим классом; JS-ассет — в теме.
	}
}
