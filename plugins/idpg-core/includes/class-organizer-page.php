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
	 * Мета-ключ флага «анкета ожидает доуполнения» (исторический вариант,
	 * используется этим классом и шаблоном page-organizer-complete.php).
	 *
	 * @var string
	 */
	const META_PENDING = '_idpg_org_pending';

	/**
	 * Альтернативный мета-ключ того же флага (`IDPG_Organizer_Verify`).
	 *
	 * Дублирование ключей — следствие G7: флаг писался/читался под двумя
	 * именами. Здесь оба варианта обрабатываются симметрично.
	 *
	 * @var string
	 */
	const META_PENDING_ALT = 'idpg_org_pending';

	/**
	 * Мета-ключ отметки времени завершения анкеты организатора
	 * (timestamp, write в обработчике /auth/organizer-complete).
	 *
	 * @var string
	 */
	const META_COMPLETED_AT = '_idpg_org_completed_at';

	/**
	 * Мета-ключ названия организации (заполняется в анкете организатора).
	 *
	 * Служит признаком «анкета заполнена» для страховки на лету.
	 *
	 * @var string
	 */
	const META_ORG_NAME = 'idpg_org_name';

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

		// Унификация источника pending-флага: соц-организатор (мета
		// wp_auth_ru_provider) помечается анкетой при первом входе.
		add_action( 'wp_login', array( __CLASS__, 'maybe_mark_social_organizer_pending' ), 10, 2 );
	}

	/**
	 * Требует ли текущий пользователь доуполнения анкеты.
	 *
	 * @return bool
	 */
	public static function is_pending() {
		$user = wp_get_current_user();

		if ( ! $user->exists() ) {
			return false;
		}

		if ( ! in_array( 'event_organizer', (array) $user->roles, true ) ) {
			return false;
		}

		// Явный флаг под любым из двух вариантов ключа.
		if ( get_user_meta( $user->ID, self::META_PENDING, true ) ) {
			return true;
		}

		if ( get_user_meta( $user->ID, self::META_PENDING_ALT, true ) ) {
			return true;
		}

		// Страховка на лету: организатор, никогда не заполнявший анкету
		// (нет отметки о завершении и названия организации), считается
		// pending независимо от выставленного флага.
		if ( ! self::is_questionnaire_complete( $user->ID ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Мета-флаг pending только для соц-организатора (созданного через
	 * соц-кнопку) при первом входе.
	 *
	 * Условия: роль event_organizer, есть мета wp_auth_ru_provider
	 * (провайдер idpg-auth) и анкета ещё не заполнена
	 * (_idpg_org_completed_at отсутствует). Ставятся оба варианта ключа
	 * флага — закрытие рассинхрона G7.
	 *
	 * @param string   $user_login Логин пользователя.
	 * @param WP_User  $user       Объект пользователя.
	 * @return void
	 */
	public static function maybe_mark_social_organizer_pending( $user_login, $user ) {
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return;
		}

		if ( ! in_array( 'event_organizer', (array) $user->roles, true ) ) {
			return;
		}

		// Только соц-организаторы: созданы через idpg-auth с провайдером.
		if ( ! get_user_meta( $user->ID, 'wp_auth_ru_provider', true ) ) {
			return;
		}

		// Уже заполнял анкету — не возвращаем в pending при повторных входах.
		if ( get_user_meta( $user->ID, self::META_COMPLETED_AT, true ) ) {
			return;
		}

		// Ставим оба варианта ключа флага (симметрия G7).
		update_user_meta( $user->ID, self::META_PENDING, 1 );
		update_user_meta( $user->ID, self::META_PENDING_ALT, 1 );
	}

	/**
	 * Заполнена ли анкета организатора.
	 *
	 * Признаки завершения: отметка времени завершения или название
	 * организации. Используется как страховка на лету — см. is_pending()
	 * и IDPG_Organizer_URL::is_pending_organizer().
	 *
	 * @param int $user_id ID пользователя.
	 * @return bool
	 */
	public static function is_questionnaire_complete( $user_id ) {
		$user_id = (int) $user_id;

		if ( get_user_meta( $user_id, self::META_COMPLETED_AT, true ) ) {
			return true;
		}

		$org_name = get_user_meta( $user_id, self::META_ORG_NAME, true );

		return '' !== (string) $org_name;
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
