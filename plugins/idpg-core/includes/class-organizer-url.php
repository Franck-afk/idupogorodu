<?php
/**
 * Маршруты личного кабинета организатора: /organizer/ и /organizer/{section}/.
 *
 * Регистрирует rewrite-правила и query-переменную раздела, подключает шаблон
 * темы `page-organizer.php` и предоставляет чистую (тестируемую) логику доступа.
 *
 * Решения по доступу (применяет шаблон `page-organizer.php`):
 *  - гость → редирект на `/login/`;
 *  - пользователь с флагом «анкета не доукомплектована» → `/organizer/complete/`;
 *  - пользователь без роли `event_organizer` (и не `administrator`/`editor`) → 403;
 *  - организатор и выше → кабинет.
 *
 * Маршрут намеренно не опирается на запись WP: как и у `/lk/favorites/`
 * (`IDPG_Favorites`) и `/organizer/complete/` (`IDPG_Organizer_Page`), запрос
 * обслуживается query-переменной и шаблоном темы. Реальную страницу `/organizer/`
 * всё равно создаёт тема (`idpg_core_create_organizer_page()`) — она нужна для
 * крошек, пунктов меню и исключения из sitemap Yoast.
 *
 * Сброс постоянных ссылок: правила регистрируются на `init`, поэтому
 * `flush_rewrite_rules()` вызывается при активации плагина
 * (`idpg_core_activate()`) и однократно при смене `REWRITE_VERSION` —
 * чтобы обновление работало и без повторной активации.
 *
 * @package IDPG_Core
 */

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Organizer_URL
 *
 * Rewrite-правила, query-переменные, шаблон и логика доступа кабинета
 * организатора.
 */
class IDPG_Organizer_URL {

	/**
	 * Query-переменная раздела кабинета.
	 */
	const SECTION_QVAR = 'idpg_organizer_section';

	/**
	 * Query-переменная самого маршрута кабинета.
	 */
	const PAGE_QVAR = 'idpg_organizer_page';

	/**
	 * Слаг страницы кабинета (совпадает с именем шаблона темы).
	 */
	const PAGE_SLUG = 'organizer';

	/**
	 * Шаблон темы, обслуживающий кабинет.
	 */
	const TEMPLATE = 'page-organizer.php';

	/**
	 * Разделы кабинета по умолчанию.
	 */
	const SECTIONS = array( 'dashboard', 'events', 'profile' );

	/**
	 * Раздел по умолчанию.
	 */
	const DEFAULT_SECTION = 'dashboard';

	/**
	 * Роли, которым доступен кабинет («организатор и выше»).
	 */
	const ALLOWED_ROLES = array( 'event_organizer', 'administrator', 'editor' );

	/**
	 * Роль организатора — единственная, которую после входа ведём в кабинет.
	 *
	 * Администратор и редактор в `ALLOWED_ROLES` тоже есть (кабинет им не
	 * запрещён), но их основное рабочее место — wp-admin, поэтому их
	 * авторизацию этот метод не перехватывает.
	 */
	const ROLE_ORGANIZER = 'event_organizer';

	/**
	 * Гость — не авторизован.
	 */
	const ACCESS_GUEST = 'guest';

	/**
	 * Организатор с незаполненной анкетой.
	 */
	const ACCESS_PENDING = 'pending';

	/**
	 * Авторизован, но прав на кабинет нет.
	 */
	const ACCESS_DENIED = 'denied';

	/**
	 * Доступ разрешён.
	 */
	const ACCESS_ALLOWED = 'allowed';

	/**
	 * Ключ user meta с флагом «анкета не доукомплектована».
	 */
	const META_PENDING = '_idpg_org_pending';

	/**
	 * Мета-ключ отметки времени завершения анкеты организатора.
	 *
	 * Отсутствие отметки вместе с пустым названием организации — признак
	 * «анкета не заполнялась», используется как страховка на лету
	 * (DEV-50) в is_pending_organizer() / get_access_decision().
	 *
	 * @var string
	 */
	const META_COMPLETED_AT = '_idpg_org_completed_at';

	/**
	 * Мета-ключ названия организации (заполняется в анкете).
	 *
	 * @var string
	 */
	const META_ORG_NAME = 'idpg_org_name';

	/**
	 * Версия rewrite-правил. Инкремент → принудительный flush один раз.
	 */
	const REWRITE_VERSION = 1;

	/**
	 * Опция-маркер выполненного сброса правил.
	 */
	const FLUSH_OPT = 'idpg_organizer_rewrite_flushed';

	/**
	 * Инициализация: хуки маршрута и шаблона.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrites' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 30 );
	}

	// =========================================================================
	// Разделы
	// =========================================================================

	/**
	 * Список слагов разделов кабинета.
	 *
	 * @return string[]
	 */
	public static function get_sections() {
		/**
		 * Фильтр списка разделов кабинета организатора.
		 *
		 * Разделы участвуют в генерации rewrite-правил, которые кэшируются
		 * в опции `rewrite_rules`. Поэтому после добавления раздела через
		 * этот фильтр нужно один раз сбросить постоянные ссылки
		 * (Настройки → Постоянные ссылки → Сохранить).
		 *
		 * @param string[] $sections Слаги разделов.
		 */
		$sections = apply_filters( 'idpg_organizer_sections', self::SECTIONS );

		if ( ! is_array( $sections ) || empty( $sections ) ) {
			return self::SECTIONS;
		}

		$sections = array_filter( array_map( 'sanitize_key', $sections ) );

		return $sections ? array_values( array_unique( $sections ) ) : self::SECTIONS;
	}

	/**
	 * Приводит слаг раздела к допустимому значению.
	 *
	 * Неизвестный или пустой слаг заменяется на раздел по умолчанию.
	 *
	 * @param string $section Слаг раздела из URL.
	 * @return string
	 */
	public static function normalize_section( $section ) {
		$section = is_string( $section ) ? sanitize_key( $section ) : '';

		return in_array( $section, self::get_sections(), true ) ? $section : self::DEFAULT_SECTION;
	}

	/**
	 * Активный раздел текущего запроса.
	 *
	 * @return string
	 */
	public static function get_current_section() {
		return self::normalize_section( (string) get_query_var( self::SECTION_QVAR ) );
	}

	/**
	 * URL раздела кабинета.
	 *
	 * Раздел по умолчанию живёт на коротком адресе `/organizer/`,
	 * остальные — на `/organizer/{section}/`.
	 *
	 * @param string $section Слаг раздела.
	 * @return string
	 */
	public static function section_url( $section ) {
		$section = self::normalize_section( $section );

		if ( self::DEFAULT_SECTION === $section ) {
			return home_url( '/' . self::PAGE_SLUG . '/' );
		}

		return home_url( '/' . self::PAGE_SLUG . '/' . $section . '/' );
	}

	/**
	 * Обслуживает ли текущий запрос кабинет организатора.
	 *
	 * Используется темой для подключения ассетов только на /organizer/*.
	 *
	 * @return bool
	 */
	public static function is_cabinet_request() {
		if ( '' !== (string) get_query_var( self::SECTION_QVAR ) ) {
			return true;
		}

		// Запасные варианты: правила не сброшены либо страница открыта
		// напрямую по записи WP.
		if ( ! function_exists( 'is_page' ) ) {
			return false;
		}

		if ( is_page( self::PAGE_SLUG ) ) {
			return true;
		}

		return (bool) is_page_template( self::TEMPLATE );
	}

	// =========================================================================
	// Доступ
	// =========================================================================

	/**
	 * Приводит вход к объекту WP_User.
	 *
	 * @param WP_User|int|string|null $user Пользователь, ID или null (текущий).
	 * @return WP_User
	 */
	private static function resolve_user( $user = null ) {
		if ( null === $user || '' === $user ) {
			return wp_get_current_user();
		}

		if ( $user instanceof WP_User ) {
			return $user;
		}

		if ( is_numeric( $user ) ) {
			$found = get_user_by( 'id', (int) $user );

			if ( $found ) {
				return $found;
			}
		}

		// Пустой пользователь: WP_User( 0 )->exists() === false.
		return new WP_User( 0 );
	}

	/**
	 * Ожидает ли пользователь доуполнения анкеты организатора.
	 *
	 * Флаг пишет `IDPG_Organizer_Verify::prepare()` под ключом
	 * `idpg_org_pending` (без подчёркивания), а исторические чтения
	 * (`IDPG_Organizer_Page::is_pending()`, `page-organizer-complete.php`)
	 * используют `_idpg_org_pending`. Проверяем оба ключа, чтобы редирект
	 * на анкету работал независимо от того, каким ключом записан флаг.
	 *
	 * Роль намеренно не проверяется: сразу после регистрации организатор
	 * ещё без роли (`event_organizer` выдаётся только после подтверждения
	 * email), но на анкету его отправить нужно.
	 *
	 * @param WP_User|int|string|null $user Пользователь, ID или null (текущий).
	 * @return bool
	 */
	public static function is_pending_organizer( $user = null ) {
		$user = self::resolve_user( $user );

		if ( ! $user->exists() ) {
			return false;
		}

		$keys = array( self::META_PENDING, ltrim( self::META_PENDING, '_' ) );

		if ( class_exists( 'IDPG_Organizer_Verify' ) ) {
			$keys[] = IDPG_Organizer_Verify::META_PENDING;
		}

		// Явный флаг под любым из известных ключей.
		foreach ( array_unique( $keys ) as $key ) {
			if ( get_user_meta( $user->ID, $key, true ) ) {
				return true;
			}
		}

		// Страховка на лету (DEV-50): организатор, который никогда не
		// заполнял анкету — нет отметки о завершении и названия
		// организации, — считается pending независимо от флага.
		// Ограничено ролью event_organizer, чтобы подписчиков и гостей
		// не переводить в pending (G7: флаг мог не проставиться при
		// соц-регистрации).
		if (
			in_array( self::ROLE_ORGANIZER, (array) $user->roles, true )
			&& ! get_user_meta( $user->ID, self::META_COMPLETED_AT, true )
			&& '' === (string) get_user_meta( $user->ID, self::META_ORG_NAME, true )
		) {
			return true;
		}

		return false;
	}

	/**
	 * Есть ли у пользователя право на кабинет («организатор и выше»).
	 *
	 * @param WP_User|int|string|null $user Пользователь, ID или null (текущий).
	 * @return bool
	 */
	public static function user_is_organizer( $user = null ) {
		$user = self::resolve_user( $user );

		if ( ! $user->exists() ) {
			return false;
		}

		$is_allowed = (bool) array_intersect( self::ALLOWED_ROLES, (array) $user->roles );

		/**
		 * Фильтр доступа пользователя в кабинет организатора.
		 *
		 * @param bool    $is_allowed Разрешён ли доступ.
		 * @param WP_User $user       Пользователь.
		 */
		return (bool) apply_filters( 'idpg_organizer_user_allowed', $is_allowed, $user );
	}

	/**
	 * Куда вести организатора после успешной авторизации.
	 *
	 * Возвращает адрес кабинета только для «чистого» организатора: если у
	 * пользователя есть роль `administrator` или `editor`, метод отдаёт
	 * пустую строку — такие пользователи остаются в wp-admin, а кабинет
	 * для них лишь дополнительный вход.
	 *
	 * Пустая строка означает «решение принимает вызывающий код» (обычно
	 * `admin_url()`), поэтому метод безопасно подставлять в любую ветку
	 * редиректа: для администратора поведение не меняется.
	 *
	 * @param WP_User|int|string|null $user Пользователь, ID или null (текущий).
	 * @return string URL кабинета либо пустая строка.
	 */
	public static function after_login_url( $user = null ) {
		$user = self::resolve_user( $user );

		if ( ! $user->exists() ) {
			return '';
		}

		$roles = (array) $user->roles;

		if ( in_array( 'administrator', $roles, true ) || in_array( 'editor', $roles, true ) ) {
			return '';
		}

		if ( ! in_array( self::ROLE_ORGANIZER, $roles, true ) ) {
			return '';
		}

		/**
		 * Фильтр адреса, куда попадает организатор после авторизации.
		 *
		 * @param string  $url  URL кабинета организатора.
		 * @param WP_User $user Пользователь.
		 */
		return (string) apply_filters( 'idpg_organizer_after_login_url', self::section_url( self::DEFAULT_SECTION ), $user );
	}

	/**
	 * Итоговое решение по доступу к кабинету.
	 *
	 * Чистая функция без редиректов и вывода — применяется шаблоном
	 * `page-organizer.php` и покрывается юнит-тестами.
	 *
	 * @param WP_User|int|string|null $user Пользователь, ID или null (текущий).
	 * @return string Одна из констант ACCESS_*.
	 */
	public static function get_access_decision( $user = null ) {
		$user = self::resolve_user( $user );

		if ( ! $user->exists() ) {
			return self::ACCESS_GUEST;
		}

		// Флаг анкеты проверяется раньше роли: pending-организатор ещё
		// без роли, но должен попасть на /organizer/complete/.
		if ( self::is_pending_organizer( $user ) ) {
			return self::ACCESS_PENDING;
		}

		if ( ! self::user_is_organizer( $user ) ) {
			return self::ACCESS_DENIED;
		}

		return self::ACCESS_ALLOWED;
	}

	// =========================================================================
	// Маршрут
	// =========================================================================

	/**
	 * Rewrite-правила кабинета.
	 *
	 * @return void
	 */
	public static function rewrites() {
		$sections = implode( '|', array_map( 'preg_quote', self::get_sections() ) );
		$query    = 'index.php?' . self::PAGE_QVAR . '=1&' . self::SECTION_QVAR . '=';

		// Разделы: /organizer/{section}/.
		add_rewrite_rule(
			'^' . self::PAGE_SLUG . '/(' . $sections . ')/?$',
			$query . '$matches[1]',
			'top'
		);

		// База: /organizer/ — раздел по умолчанию.
		add_rewrite_rule(
			'^' . self::PAGE_SLUG . '/?$',
			$query . self::DEFAULT_SECTION,
			'top'
		);

		self::maybe_flush();
	}

	/**
	 * Однократный сброс постоянных ссылок при смене версии правил.
	 *
	 * @return void
	 */
	private static function maybe_flush() {
		if ( (string) get_option( self::FLUSH_OPT ) === (string) self::REWRITE_VERSION ) {
			return;
		}

		flush_rewrite_rules();
		update_option( self::FLUSH_OPT, self::REWRITE_VERSION );
	}

	/**
	 * Регистрация query-переменных.
	 *
	 * @param array $vars Существующие query-переменные.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::SECTION_QVAR;
		$vars[] = self::PAGE_QVAR;

		return $vars;
	}

	/**
	 * Подключение шаблона кабинета.
	 *
	 * Редиректы и проверку прав выполняет сам шаблон
	 * (`page-organizer.php`) — здесь только выбор файла и корректный
	 * HTTP-статус.
	 *
	 * @param string $template Текущий шаблон.
	 * @return string
	 */
	public static function template( $template ) {
		if ( '' === (string) get_query_var( self::SECTION_QVAR ) ) {
			return $template;
		}

		// Маршрут обслуживается шаблоном темы, а не записью WP: главный
		// запрос не содержит объектов. Снимаем возможный 404-статус, иначе
		// корректная страница отдавалась бы поисковикам с кодом 404.
		global $wp_query;

		if ( $wp_query instanceof WP_Query ) {
			$wp_query->is_404 = false;
		}

		status_header( 200 );

		$cabinet = locate_template( self::TEMPLATE );

		return $cabinet ? $cabinet : $template;
	}
}
