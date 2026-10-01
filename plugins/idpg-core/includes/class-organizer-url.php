<?php
/**
 * Маршруты личного кабинета организатора: /organizer/ и /organizer/{section}/.
 *
 * Регистрирует rewrite-правила и query-переменную раздела, подключает шаблон
 * темы `page-organizer.php` и предоставляет чистую (тестируемую) логику доступа.
 *
 * Дополнительно обслуживает форму события (G5):
 * `/organizer/event/new/` и `/organizer/event/{id}/` — они живут внутри
 * /organizer/*, но не являются разделами кабинета (нет боковой навигации),
 * поэтому вынесены в отдельную query-переменную и отдельный шаблон
 * `page-organizer-event.php`. Проверка прав у формы та же — её делает шаблон
 * через `get_access_decision()`.
 *
 * Решения по доступу (применяет шаблон `page-organizer.php`):
 *  - гость → редирект на `/login/`;
 *  - пользователь без роли `event_organizer` (и не `administrator`/`editor`) → 403;
 *  - организатор и выше → кабинет.
 *
 * Маршрут намеренно не опирается на запись WP: как и у `/lk/favorites/`
 * (`IDPG_Favorites`), запрос обслуживается query-переменной и шаблоном темы.
 * Реальную страницу `/organizer/` всё равно создаёт тема
 * (`idpg_core_create_organizer_page()`) — она нужна для крошек, пунктов
 * меню и исключения из sitemap Yoast.
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
	 * Query-переменная формы события: `new` либо ID события (G5).
	 *
	 * Значение `new` означает создание, положительное целое — правку
	 * существующего события. Отдельная переменная, а не раздел кабинета:
	 * форма события — полноэкранная страница вне боковой навигации, и
	 * показывать её в списке разделов было бы неверно.
	 */
	const EVENT_QVAR = 'idpg_organizer_event';

	/**
	 * Значение `EVENT_QVAR` для режима создания.
	 */
	const EVENT_NEW = 'new';

	/**
	 * Query-переменная формы площадки: `new` либо ID площадки (DEV-50).
	 *
	 * Отдельная переменная, а не переиспользование `EVENT_QVAR`: площадка
	 * и событие — разные сущности, и их нельзя было бы различить в
	 * шаблоне.
	 *
	 * @var string
	 */
	const VENUE_QVAR = 'idpg_organizer_venue';

	/**
	 * Значение `VENUE_QVAR` для режима создания.
	 *
	 * @var string
	 */
	const VENUE_NEW = 'new';

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
	/**
	 * Разделы кабинета по умолчанию.
	 *
	 * `venues` добавлен DEV-50: площадки — самостоятельная сущность
	 * организатора (CPT venue), и их некуда было деть: создать площадку
	 * можно было только через модалку в форме события.
	 */
	const SECTIONS = array( 'dashboard', 'events', 'venues', 'profile' );

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
	 * Авторизован, но прав на кабинет нет.
	 */
	const ACCESS_DENIED = 'denied';

	/**
	 * Доступ разрешён.
	 */
	const ACCESS_ALLOWED = 'allowed';

	/**
	 * Мета-ключ названия организации.
	 *
	 * Заполняется при регистрации организатора, а не в отдельной анкете.
	 *
	 * @var string
	 */
	const META_ORG_NAME = 'idpg_org_name';

	/**
	 * Мета-ключ контактного лица организатора.
	 *
	 * Редактируется в профиле кабинета (`/organizer/profile/`).
	 *
	 * @var string
	 */
	const META_CONTACT_NAME = 'idpg_contact_name';

	/**
	 * Мета-ключ телефона организатора.
	 *
	 * Редактируется в профиле кабинета (`/organizer/profile/`).
	 *
	 * @var string
	 */
	const META_PHONE = 'idpg_phone';

	/**
	 * Мета-ключ аватара организатора (ID вложения).
	 *
	 * Дублирует константу `IDPG_Media::AVATAR_META_KEY`, чтобы профиль
	 * не зависел от загрузки медиамодуля.
	 *
	 * @var string
	 */
	const META_AVATAR_ID = 'idpg_avatar_attachment_id';

	/**
	 * Мета-ключ организационно-правовой формы.
	 *
	 * Значения: 'ip' | 'ooo' | 'fiz' | 'nko'.
	 *
	 * @var string
	 */
	const META_LEGAL_TYPE = 'idpg_legal_type';

	/**
	 * Мета-ключ ИНН организатора.
	 *
	 * @var string
	 */
	const META_INN = 'idpg_inn';

	/**
	 * Мета-ключ статуса верификации.
	 *
	 * Только администратор может менять. Организатор видит, но не правит.
	 *
	 * @var string
	 */
	const META_VERIFIED = 'idpg_verified';

	/**
	 * Мета-ключ документов организатора.
	 *
	 * Массив ID вложений (JSON), загружаемых через wp.media.
	 *
	 * @var string
	 */
	const META_DOCUMENTS = 'idpg_documents';

	/**
	 * Допустимые значения организационно-правовой формы.
	 *
	 * @var string[]
	 */
	const LEGAL_TYPES = array( 'ip', 'ooo', 'fiz', 'nko' );

	/**
	 * Максимальная длина текстовых полей профиля.
	 *
	 * @var int
	 */
	const PROFILE_FIELD_MAXLEN = 190;

	/**
	 * Провайдеры idpg-auth, для которых подключается редирект в кабинет.
	 *
	 * Значения должны совпадать с константой `PROVIDER` соответствующего
	 * класса `WP_Auth_Ru_*`: плагин применяет фильтр
	 * `wp_auth_ru_{PROVIDER}_after_login_redirect`.
	 *
	 * @var string[]
	 */
	const SOCIAL_PROVIDERS = array( 'yandex', 'vk', 'max' );

	/**
	 * Версия rewrite-правил. Инкремент → принудительный flush один раз.
	 *
	 * 4 (DEV-141): удалены остатки анкеты `/organizer/complete/` вместе с
	 * классом `IDPG_Organizer_Page`, который регистрировал это правило.
	 * Инкремент нужен, чтобы flush убрал уже сохранённое правило из опции
	 * `rewrite_rules` — иначе `/organizer/complete/` продолжал бы совпадать
	 * с мёртвым правилом и отдавал бы не 404.
	 */
	const REWRITE_VERSION = 4;

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
		add_filter( 'wp_redirect', array( __CLASS__, 'guard_cabinet_redirect' ), 10, 2 );

		self::init_social_login_redirects();
	}

	// =========================================================================
	// Соцвход
	// =========================================================================

	/**
	 * Подключает редирект в кабинет после входа через соцсеть.
	 *
	 * Фильтры `wp_auth_ru_*_after_login_redirect` применяет плагин
	 * idpg-auth, когда соцсеть вернула пользователя. К этому моменту
	 * `wp_set_current_user()` уже отработал, поэтому роль известна.
	 *
	 * Фильтр срабатывает не всегда: если провайдер сохранил в transient
	 * локальный адрес из `redirect_to`, он используется как есть и фильтр
	 * не вызывается. Наш фильтр отвечает только за случай «адрес не задан»
	 * — то есть за прямой вход через соцсеть с `/login/`.
	 *
	 * @return void
	 */
	public static function init_social_login_redirects() {
		foreach ( self::SOCIAL_PROVIDERS as $provider ) {
			add_filter(
				'wp_auth_ru_' . $provider . '_after_login_redirect',
				array( __CLASS__, 'social_login_redirect' )
			);
		}
	}

	/**
	 * Куда отправить пользователя после входа через соцсеть.
	 *
	 * Организатору — в личный кабинет, остальным оставляем адрес,
	 * предложенный плагином (по умолчанию главная страница).
	 *
	 * Роль при соцвходе не меняется: существующий пользователь находится
	 * по email, новому назначается роль по умолчанию. Поэтому проверка
	 * идёт по уже установленной роли, а не по факту входа.
	 *
	 * @param string $redirect_to Адрес, предложенный плагином idpg-auth.
	 * @return string
	 */
	public static function social_login_redirect( $redirect_to ) {
		$url = self::after_login_url( get_current_user_id() );

		if ( '' !== $url ) {
			return $url;
		}

		return $redirect_to;
	}

	// =========================================================================
	// Защита редиректов в кабинет
	// =========================================================================

	/**
	 * Не пускает в кабинет пользователя, которому там нельзя.
	 *
	 * Фильтр `wp_auth_ru_*_after_login_redirect` не является единственной
	 * точкой входа в кабинет: плагин idpg-auth применяет его только когда
	 * в transient не оказалось локального `redirect_to`
	 * (`class-wp-auth-ru-oauth-provider.php`, ветка `empty( $redirect_to )`).
	 * С явным `redirect_to=/organizer/` фильтр не вызывается вовсе, и
	 * подписчик после соцвхода оказывается в кабинете, откуда его
	 * выбрасывает обратно на страницу входа — замыкается цикл
	 * «вход → кабинет → вход».
	 *
	 * Поэтому решение о допуске в кабинет принимается на любом редиректе
	 * сайта: право проверяется по `user_is_organizer()`, то есть по тому же
	 * правилу, что и `get_access_decision()`. Администраторы и редакторы
	 * доступ в кабинет сохраняют, им редирект не трогаем.
	 *
	 * Редиректы за пределы кабинета не изменяются вовсе.
	 *
	 * @param string $location Адрес редиректа.
	 * @param int    $status   HTTP-код редиректа.
	 * @return string
	 */
	public static function guard_cabinet_redirect( $location, $status = 302 ) {
		if ( ! is_string( $location ) || ! self::is_cabinet_url( $location ) ) {
			return $location;
		}

		if ( self::user_is_organizer() ) {
			return $location;
		}

		/**
		 * Фильтр адреса, которым подменяем редирект в кабинет.
		 *
		 * Позволяет, например, отправить несовместимого пользователя
		 * на страницу входа, а не на главную.
		 *
		 * @param string $fallback Адрес для замены.
		 * @param string $location Адрес, который заменяем.
		 */
		return (string) apply_filters( 'idpg_organizer_denied_redirect_url', home_url(), $location );
	}

	/**
	 * Указывает ли адрес на кабинет организатора.
	 *
	 * Сравнение идёт по корню `/organizer/` без учёта слэша и query-строки,
	 * поэтому `/organizer`, `/organizer/` и `/organizer/events/` считаются
	 * кабинетом, а `/organizers-2026` — нет.
	 *
	 * @param string $url Проверяемый адрес.
	 * @return bool
	 */
	public static function is_cabinet_url( $url ) {
		$base = untrailingslashit( home_url( '/' . self::PAGE_SLUG ) );
		$url  = untrailingslashit( (string) $url );

		// Ограничиваемся тем же доменом: чужой адрес кабинетом не считается.
		if ( 0 !== stripos( $url, $base ) ) {
			return false;
		}

		$rest = substr( $url, strlen( $base ) );

		return '' === $rest || '/' === $rest[0] || '?' === $rest[0];
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

		// Форма площадки (DEV-50) живёт внутри /organizer/* и тоже нуждается
		// в ассетах кабинета, хотя разделом не является.
		if ( '' !== (string) get_query_var( self::VENUE_QVAR ) ) {
			return true;
		}

		// Форма события (G5) живёт внутри /organizer/* и тоже нуждается в
		// ассетах кабинета, хотя разделом не является.
		if ( '' !== (string) get_query_var( self::EVENT_QVAR ) ) {
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
	// Форма события (G5)
	// =========================================================================

	/**
	 * Обслуживает ли текущий запрос форму события.
	 *
	 * @return bool
	 */
	public static function is_event_form_request() {
		return '' !== (string) get_query_var( self::EVENT_QVAR );
	}

	/**
	 * ID события, который правит текущий запрос формы.
	 *
	 * Возвращает 0 в режиме создания (`new`) и для любого нечислового
	 * значения — вызывающий код трактует 0 как «создать новое».
	 *
	 * @return int
	 */
	public static function get_current_event_id() {
		$raw = get_query_var( self::EVENT_QVAR );

		if ( self::EVENT_NEW === (string) $raw ) {
			return 0;
		}

		return absint( $raw );
	}

	/**
	 * URL формы события.
	 *
	 * @param int $event_id ID события; 0 — форма создания.
	 * @return string
	 */
	public static function event_form_url( $event_id = 0 ) {
		$event_id = absint( $event_id );

		if ( ! $event_id ) {
			return home_url( '/' . self::PAGE_SLUG . '/event/' . self::EVENT_NEW . '/' );
		}

		return home_url( '/' . self::PAGE_SLUG . '/event/' . $event_id . '/' );
	}

	// =========================================================================
	// Форма площадки (DEV-50)
	// =========================================================================

	/**
	 * Обслуживает ли текущий запрос форму площадки.
	 *
	 * @return bool
	 */
	public static function is_venue_form_request() {
		return '' !== (string) get_query_var( self::VENUE_QVAR );
	}

	/**
	 * ID площадки, которую правит текущий запрос формы.
	 *
	 * Возвращает 0 в режиме создания (`new`) и для любого нечислового
	 * значения — вызывающий код трактует 0 как «создать новую».
	 *
	 * @return int
	 */
	public static function get_current_venue_id() {
		$raw = get_query_var( self::VENUE_QVAR );

		if ( self::VENUE_NEW === (string) $raw ) {
			return 0;
		}

		return absint( $raw );
	}

	/**
	 * URL формы площадки.
	 *
	 * @param int $venue_id ID площадки; 0 — форма создания.
	 * @return string
	 */
	public static function venue_form_url( $venue_id = 0 ) {
		$venue_id = absint( $venue_id );

		if ( ! $venue_id ) {
			return home_url( '/' . self::PAGE_SLUG . '/venue/' . self::VENUE_NEW . '/' );
		}

		return home_url( '/' . self::PAGE_SLUG . '/venue/' . $venue_id . '/' );
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
	 * Анкеты организатора больше нет (DEV-72): после подтверждения email
	 * или при соцвходе организатор сразу попадает в кабинет, поэтому
	 * промежуточного состояния «pending» не существует.
	 *
	 * @param WP_User|int|string|null $user Пользователь, ID или null (текущий).
	 * @return string Одна из констант ACCESS_*.
	 */
	public static function get_access_decision( $user = null ) {
		$user = self::resolve_user( $user );

		if ( ! $user->exists() ) {
			return self::ACCESS_GUEST;
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

		// Форма события (G5): /organizer/event/new/ и /organizer/event/{id}/.
		//
		// Порядок важен: WP_Rewrite::add_rule() делает обычное присваивание
		// (`extra_rules_top[$regex] = $query`), поэтому порядок элементов
		// массива совпадает с порядком регистрации, и при разборе адреса
		// правила проверяются именно в этом порядке. Правила формы идут
		// первыми, поэтому выигрывают у раздела с тем же слагом (`event`),
		// если его когда-нибудь добавят через фильтр get_sections().
		add_rewrite_rule(
			'^' . self::PAGE_SLUG . '/event/' . self::EVENT_NEW . '/?$',
			'index.php?' . self::PAGE_QVAR . '=1&' . self::EVENT_QVAR . '=' . self::EVENT_NEW,
			'top'
		);

		add_rewrite_rule(
			'^' . self::PAGE_SLUG . '/event/([0-9]+)/?$',
			'index.php?' . self::PAGE_QVAR . '=1&' . self::EVENT_QVAR . '=$matches[1]',
			'top'
		);

		// Форма площадки (DEV-50): /organizer/venue/new/ и
		// /organizer/venue/{id}/. Объявлены до разделов, потому что
		// `venue` — и форма, и возможный будущий слаг раздела.
		add_rewrite_rule(
			'^' . self::PAGE_SLUG . '/venue/' . self::VENUE_NEW . '/?$',
			'index.php?' . self::PAGE_QVAR . '=1&' . self::VENUE_QVAR . '=' . self::VENUE_NEW,
			'top'
		);

		add_rewrite_rule(
			'^' . self::PAGE_SLUG . '/venue/([0-9]+)/?$',
			'index.php?' . self::PAGE_QVAR . '=1&' . self::VENUE_QVAR . '=$matches[1]',
			'top'
		);

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
	 * Важно: `flush_rewrite_rules()` до срабатывания `wp_loaded` только
	 * *откладывает* генерацию правил — реальная перезапись опции
	 * `rewrite_rules` происходит на хуке `wp_loaded`. Если выставить маркер
	 * сразу, следующий запрос увидит маркер и не повторит сброс, а правила
	 * так и останутся в старом виде. Поэтому маркер ставим после фактического
	 * сброса: на `init` планируем повторный вызов на `wp_loaded` с
	 * приоритетом выше 10 (сам сброс ядро ставит на приоритете 10).
	 *
	 * @return void
	 */
	private static function maybe_flush() {
		if ( (string) get_option( self::FLUSH_OPT ) === (string) self::REWRITE_VERSION ) {
			return;
		}

		if ( ! did_action( 'wp_loaded' ) ) {
			// Closure, а не array( __CLASS__, 'maybe_flush' ): хуки вызываются
			// ядром из глобальной области, приватный метод напрямую недоступен,
			// а замыкание сохраняет область класса.
			add_action(
				'wp_loaded',
				function () {
					self::maybe_flush();
				},
				20
			);
			flush_rewrite_rules();
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
		$vars[] = self::EVENT_QVAR;
		$vars[] = self::VENUE_QVAR;

		return $vars;
	}

	/**
	 * Шаблон темы, обслуживающий форму события (G5).
	 */
	const EVENT_TEMPLATE = 'page-organizer-event.php';

	/**
	 * Шаблон темы, обслуживающий форму площадки (DEV-50).
	 */
	const VENUE_TEMPLATE = 'page-organizer-venue.php';

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
		$is_cabinet = '' !== (string) get_query_var( self::SECTION_QVAR );
		$is_event   = '' !== (string) get_query_var( self::EVENT_QVAR );
		$is_venue   = '' !== (string) get_query_var( self::VENUE_QVAR );

		if ( ! $is_cabinet && ! $is_event && ! $is_venue ) {
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

		if ( $is_venue ) {
			$wanted = self::VENUE_TEMPLATE;
		} elseif ( $is_event ) {
			$wanted = self::EVENT_TEMPLATE;
		} else {
			$wanted = self::TEMPLATE;
		}

		$found = locate_template( $wanted );

		return $found ? $found : $template;
	}

	// =========================================================================
	// Профиль организатора (DEV-73)
	//
	// Логика профиля живёт здесь, а не в REST-классе и не в шаблоне: и
	// `PUT /idpg/v1/organizer/profile`, и no-JS форма в `page-organizer.php`
	// вызывают одни и те же методы, поэтому санитайз не может разъехаться
	// между путями сохранения.
	// =========================================================================

	/**
	 * Данные профиля пользователя для вывода и заполнения формы.
	 *
	 * Email намеренно только для чтения: смена адреса требует
	 * подтверждения (DEV-68), и в рамках DEV-73 её не реализуем.
	 *
	 * @param WP_User|int|string|null $user Пользователь, ID или null (текущий).
	 * @return array {
	 *     @type int    $user_id      ID пользователя.
	 *     @type string $display_name Имя в системе.
	 *     @type string $email        Email (только чтение).
	 *     @type string $org_name     Название организации.
	 *     @type string $contact_name Контактное лицо.
	 *     @type string $phone        Телефон.
	 *     @type int    $avatar_id    ID вложения-аватара (0 — нет).
	 *     @type string $avatar_url   URL аватара ('' — нет).
	 * }
	 */
	/**
	 * Читает и валидирует список документов организатора.
	 *
	 * Возвращает только существующие вложения (attachment).
	 *
	 * @param int $user_id ID пользователя.
	 * @return int[] Массив ID вложений.
	 */
	private static function parse_documents_meta( $user_id ) {
		$raw = get_user_meta( (int) $user_id, self::META_DOCUMENTS, true );

		if ( ! $raw ) {
			return array();
		}

		$ids = is_array( $raw ) ? $raw : json_decode( $raw, true );

		if ( ! is_array( $ids ) ) {
			return array();
		}

		$valid = array();

		foreach ( $ids as $id ) {
			$id = absint( $id );

			if ( ! $id ) {
				continue;
			}

			$attachment = get_post( $id );

			if ( $attachment && 'attachment' === $attachment->post_type ) {
				$valid[] = $id;
			}
		}

		return array_values( array_unique( $valid ) );
	}

	public static function get_profile_data( $user = null ) {
		$user = self::resolve_user( $user );

		if ( ! $user->exists() ) {
			return array(
				'user_id'      => 0,
				'display_name' => '',
				'email'        => '',
				'org_name'     => '',
				'contact_name' => '',
				'phone'        => '',
				'avatar_id'    => 0,
				'avatar_url'   => '',
			);
		}

		$avatar_id   = (int) get_user_meta( $user->ID, self::META_AVATAR_ID, true );
		$legal_type  = (string) get_user_meta( $user->ID, self::META_LEGAL_TYPE, true );
		$inn         = (string) get_user_meta( $user->ID, self::META_INN, true );
		$verified    = (bool) get_user_meta( $user->ID, self::META_VERIFIED, true );
		$documents   = self::parse_documents_meta( $user->ID );

		return array(
			'user_id'      => (int) $user->ID,
			'display_name' => (string) $user->display_name,
			'email'        => (string) $user->user_email,
			'org_name'     => (string) get_user_meta( $user->ID, self::META_ORG_NAME, true ),
			'contact_name' => (string) get_user_meta( $user->ID, self::META_CONTACT_NAME, true ),
			'phone'        => (string) get_user_meta( $user->ID, self::META_PHONE, true ),
			'avatar_id'    => $avatar_id,
			'avatar_url'   => $avatar_id ? (string) wp_get_attachment_image_url( $avatar_id, 'medium' ) : '',
			'legal_type'   => in_array( $legal_type, self::LEGAL_TYPES, true ) ? $legal_type : '',
			'inn'          => $inn,
			'verified'     => $verified,
			'documents'    => $documents,
		);
	}

	/**
	 * Санитайз и проверка полей профиля.
	 *
	 * Поля, которых нет во входном массиве, не попадают в результат —
	 * так частичное обновление (например, только телефон из JS-формы)
	 * не затирает остальные мета.
	 *
	 * @param array $input Необработанные значения.
	 * @return array|WP_Error Очищенные значения либо ошибка.
	 */
	public static function sanitize_profile_input( $input ) {
		if ( ! is_array( $input ) ) {
			return new WP_Error(
				'idpg_profile_invalid_input',
				__( 'Некорректные данные профиля.', 'idpg_core' ),
				array( 'status' => 400 )
			);
		}

		$clean = array();

		foreach ( array( 'display_name', 'org_name', 'contact_name', 'phone' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$value = sanitize_text_field( (string) $input[ $field ] );

			$clean[ $field ] = mb_substr( $value, 0, self::PROFILE_FIELD_MAXLEN );
		}

		if ( array_key_exists( 'avatar_id', $input ) ) {
			$avatar_id = absint( $input['avatar_id'] );

			if ( $avatar_id ) {
				$attachment = get_post( $avatar_id );

				// Принимаем только вложение-изображение: произвольный ID
				// поста не должен становиться аватаром.
				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					return new WP_Error(
						'idpg_profile_invalid_avatar',
						__( 'Аватар должен быть файлом из медиатеки.', 'idpg_core' ),
						array( 'status' => 400 )
					);
				}
			}

			$clean['avatar_id'] = $avatar_id;
		}

		// Организационно-правовая форма.
		if ( array_key_exists( 'legal_type', $input ) ) {
			$lt = sanitize_key( (string) $input['legal_type'] );

			if ( in_array( $lt, self::LEGAL_TYPES, true ) ) {
				$clean['legal_type'] = $lt;
			} else {
				$clean['legal_type'] = '';
			}
		}

		// ИНН: 10 или 12 цифр.
		if ( array_key_exists( 'inn', $input ) ) {
			$inn = sanitize_text_field( (string) $input['inn'] );

			if ( '' === $inn || preg_match( '/^\d{10}$|^\d{12}$/', $inn ) ) {
				$clean['inn'] = $inn;
			} else {
				return new WP_Error(
					'idpg_profile_invalid_inn',
					__( 'ИНН должен содержать 10 или 12 цифр.', 'idpg_core' ),
					array( 'status' => 400 )
				);
			}
		}

		// Документы: массив ID вложений.
		if ( array_key_exists( 'documents', $input ) ) {
			$docs = is_array( $input['documents'] ) ? $input['documents'] : array();
			$valid = array();

			foreach ( $docs as $doc_id ) {
				$doc_id = absint( $doc_id );

				if ( ! $doc_id ) {
					continue;
				}

				$attachment = get_post( $doc_id );

				if ( $attachment && 'attachment' === $attachment->post_type ) {
					$valid[] = $doc_id;
				}
			}

			$clean['documents'] = array_values( array_unique( $valid ) );
		}

		/**
		 * Фильтр позволяет добавить собственные поля профиля.
		 *
		 * @param array $clean Очищенные значения.
		 * @param array $input Необработанные значения.
		 */
		return apply_filters( 'idpg_organizer_profile_sanitized', $clean, $input );
	}

	/**
	 * Сохранение профиля пользователя.
	 *
	 * Пишет только те поля, которые реально присутствуют в $clean —
	 * частичное обновление не затирает остальные мета.
	 *
	 * @param WP_User|int|string|null $user  Пользователь, ID или null (текущий).
	 * @param array                    $clean Результат sanitize_profile_input().
	 * @return array|WP_Error Обновлённые данные профиля либо ошибка.
	 */
	public static function update_profile( $user, $clean ) {
		$user = self::resolve_user( $user );

		if ( ! $user->exists() ) {
			return new WP_Error(
				'idpg_profile_user_not_found',
				__( 'Пользователь не найден.', 'idpg_core' ),
				array( 'status' => 404 )
			);
		}

		if ( ! is_array( $clean ) || ! $clean ) {
			return new WP_Error(
				'idpg_profile_nothing_to_update',
				__( 'Нет данных для сохранения.', 'idpg_core' ),
				array( 'status' => 400 )
			);
		}

		// display_name — стандартное поле пользователя, не мета.
		if ( array_key_exists( 'display_name', $clean ) ) {
			$result = wp_update_user(
				array(
					'ID'           => $user->ID,
					'display_name' => $clean['display_name'],
				)
			);

			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					'idpg_profile_update_failed',
					$result->get_error_message(),
					array( 'status' => 400 )
				);
			}
		}

		$meta_map = array(
			'org_name'     => self::META_ORG_NAME,
			'contact_name' => self::META_CONTACT_NAME,
			'phone'        => self::META_PHONE,
			'legal_type'   => self::META_LEGAL_TYPE,
			'inn'          => self::META_INN,
		);

		foreach ( $meta_map as $field => $meta_key ) {
			if ( ! array_key_exists( $field, $clean ) ) {
				continue;
			}

			update_user_meta( $user->ID, $meta_key, $clean[ $field ] );
		}

		if ( array_key_exists( 'avatar_id', $clean ) ) {
			update_user_meta( $user->ID, self::META_AVATAR_ID, $clean['avatar_id'] );
		}

		if ( array_key_exists( 'documents', $clean ) ) {
			update_user_meta( $user->ID, self::META_DOCUMENTS, wp_json_encode( $clean['documents'] ) );
		}

		/**
		 * Действие после успешного сохранения профиля.
		 *
		 * @param int   $user_id ID пользователя.
		 * @param array $clean   Сохранённые значения.
		 */
		do_action( 'idpg_organizer_profile_updated', $user->ID, $clean );

		return self::get_profile_data( $user->ID );
	}
}
