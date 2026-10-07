<?php
/**
 * Юнит-тесты DEV-100: каждый класс плагина должен быть инициализирован.
 *
 * `require_once` подключает файл, но не запускает класс: хуки вешаются
 * в конструкторе, а конструктор вызывается из `::instance()` или
 * `::init()`. Класс без такого вызова молча не работает — REST-маршруты
 * не регистрируются, фильтры не висят, а тесты падают «Undefined array
 * key /idpg/v1/...». Именно так в бою были потеряны пять классов
 * организатора (DEV-102), поэтому набор фиксирует полный список
 * инициализаций и ловит новый неинициализированный класс.
 *
 * Набор проверяет четыре разных способа инициализации и отдельно —
 * что список покрывает вообще все файлы в `includes/`.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Class_Init
 */
class Test_Class_Init extends WP_UnitTestCase {

	/**
	 * Классы, инициализируемые через `::instance()` в `idpg_core_init()`
	 * либо при загрузке файла.
	 *
	 * Проверяется рефлексией: приватное статическое поле `$instance`
	 * должно быть не-null, то есть конструктор уже отработал.
	 *
	 * @var array<string,string>
	 */
	private const SINGLETONS = array(
		'IDPG_CPT'                  => 'idpg_core_init()',
		'IDPG_Roles'                => 'idpg_core_init()',
		'IDPG_City_Filter'          => 'idpg_core_init()',
		'IDPG_Media'                => 'idpg_core_init()',
		'IDPG_Ticket_Sync'          => 'idpg_core_init()',
		'IDPG_REST_API'             => 'idpg_core_init()',
		'IDPG_iCal_Export'          => 'idpg_core_init()',
		'IDPG_Meta'                 => 'idpg_core_init()',
		'IDPG_Migrations'           => 'idpg_core_init()',
		'IDPG_Views'                => 'idpg_core_init()',
		'IDPG_Notifications'        => 'idpg_core_init()',
		'IDPG_Auth'                 => 'idpg_core_init()',
		'IDPG_REST_Auth'            => 'idpg_core_init()',
		'IDPG_Auth_Emails'          => 'idpg_core_init()',
		'IDPG_Organizer_Verify'     => 'idpg_core_init()',
		'IDPG_Maintenance'          => 'idpg_core_init()',
		'IDPG_Settings_Page'        => 'idpg_core_init()',
		'IDPG_Dashboard_Stats'      => 'idpg_core_init()',
		'IDPG_Event_Moderation'     => 'idpg_core_init()',
		'IDPG_REST_Organizer'       => 'idpg_core_init()',
		'IDPG_Event_Rejection'      => 'idpg_core_init()',
		'IDPG_Promotion'            => 'idpg_core_init()',
		'IDPG_SEO_Integration'      => 'idpg_core_init()',
		'IDPG_Current_Event_Banner' => 'idpg-core.php:99, при загрузке файла',
		'IDPG_User_Purge'           => 'конец class-user-purge.php',
		'IDPG_Privacy'              => 'конец class-privacy.php',
	);

	/**
	 * Классы, инициализируемые статическим `::init()`.
	 *
	 * У них нет состояния, поэтому проверяется сам факт регистрации
	 * хука: конкретный хук и метод, которые `init()` обязан повесить.
	 *
	 * @var array<string,array>
	 */
	private const STATIC_INIT = array(
		'IDPG_City_URL'       => array(
			'wp_head' => 'canonical',
		),
		'IDPG_Favorites'      => array(
			'wp_enqueue_scripts' => 'assets',
		),
		// DEV-197: статический init() вызывается в конце class-organizer-meta.php.
		// Приватного статического $instance у класса нет, поэтому проверяются
		// именно зарегистрированные хуки, а не синглтон.
		'IDPG_Organizer_Meta'  => array(
			'carbon_fields_register_fields' => 'register_fields',
			'personal_options_update'       => 'protect_verification_status',
			'edit_user_profile_update'      => 'protect_verification_status',
		),
		'IDPG_Organizer_Scope' => array(
			'pre_get_posts' => 'scope_admin_attachments',
		),
	);

	/**
	 * Виджеты: регистрируются на `widgets_init`, а не через синглтон.
	 *
	 * @var array<string,string>
	 */
	private const WIDGETS = array(
		'IDPG_City_Selector_Widget'    => 'idpg_core_register_plugin_widgets()',
		'IDPG_Event_Calendar_Widget'   => 'idpg_core_register_plugin_widgets()',
		'IDPG_Expected_Events_Widget'  => 'idpg_core_register_plugin_widgets()',
	);

	/**
	 * Классы, которым инициализация не требуется.
	 *
	 * Каждое исключение обосновано: либо это статический helper без
	 * состояния, либо ленивая инициализация по первому обращению.
	 *
	 * @var array<string,string>
	 */
	private const EXCEPTIONS = array(
		'IDPG_Search'          => 'Статический helper: все методы public static, состояния нет, хуки не нужны.',
		'IDPG_Template_Helper' => 'Статический helper: все методы public static, состояния нет, хуки не нужны.',
		'IDPG_Helpers'         => 'Статический helper (DEV-22): общие get_client_ip()/get_city_tree() и геттеры мета билетов, состояния нет, хуки не нужны.',
		'IDPG_Organizer_URL' => 'Роутер /organizer/* отключён (DEV-235): init() не вызывается, хуки не регистрируются. Класс подключён: profile/access/social-логика нужна REST и редиректам.',
		'IDPG_CLI'             => 'Команды WP-CLI: регистрация происходит в блоке if ( defined( \'WP_CLI\' ) ), в обычном запросе WP_CLI не определён.',
		'IDPG_Auth_Rate_Limiter' => 'Ленивая инициализация: экземпляр создаётся при первом обращении из обработчиков class-rest-auth.php и class-rest-api.php, до первого запроса он не нужен.',
		'IDPG_GeoService'      => 'Ленивая инициализация: экземпляр создаётся по требованию. ВНИМАНИЕ: единственный потребитель сейчас — тесты, в production-коде вызовов нет. Кандидат на удаление (DEV-100).',
	);

	/**
	 * Подготовка окружения: прогоняем инициализацию плагина.
	 *
	 * В тестовой среде `init` мог уже отработать, поэтому функция
	 * вызывается явно: `idpg_core_init()` идемпотентна, все её вызовы
	 * ведут в уже созданные синглтоны.
	 */
	public function set_up() {
		parent::set_up();

		idpg_core_init();

		// `widgets_init` в тестовой среде мог уже отработать (или не
		// отработать вовсе) — принудительно прогоняем регистрацию,
		// иначе проверка виджетов зависела бы от порядка бутстрапа.
		if ( ! did_action( 'widgets_init' ) ) {
			do_action( 'widgets_init' );
		}
	}

	// =====================================================================
	// Полнота списка
	// =====================================================================

	/**
	 * Все классы из includes/ попадают в один из списков набора.
	 *
	 * Главная проверка DEV-100: новый файл в `includes/` без записи в
	 * одном из списков валит тест — пока не будет решено, как он
	 * инициализируется.
	 */
	public function test_every_class_in_includes_is_accounted_for() {
		$found     = $this->collect_classes();
		$accounted = array_merge(
			array_keys( self::SINGLETONS ),
			array_keys( self::STATIC_INIT ),
			array_keys( self::WIDGETS ),
			array_keys( self::EXCEPTIONS )
		);

		$this->assertNotEmpty( $found, 'Не удалось прочитать ни одного класса из includes/.' );

		$this->assertSame(
			array(),
			array_values( array_diff( array_keys( $found ), $accounted ) ),
			'Классы из includes/, для которых не описан способ инициализации: '
				. implode( ', ', array_diff( array_keys( $found ), $accounted ) )
		);
	}

	/**
	 * В списках нет классов, которых больше нет на диске.
	 *
	 * Защита от обратной стороны расхождения: переименование класса не должно
	 * молча оставлять проверку вхолостую.
	 */
	public function test_no_stale_entries_in_lists() {
		$found = $this->collect_classes();

		$accounted = array_merge(
			array_keys( self::SINGLETONS ),
			array_keys( self::STATIC_INIT ),
			array_keys( self::WIDGETS ),
			array_keys( self::EXCEPTIONS )
		);

		$this->assertSame(
			array(),
			array_values( array_diff( $accounted, array_keys( $found ) ) ),
			'В списках есть классы, отсутствующие в includes/: '
				. implode( ', ', array_diff( $accounted, array_keys( $found ) ) )
		);
	}

	/**
	 * Каждое исключение обосновано.
	 *
	 * Исключение без комментария — это замаскированная неинициализация.
	 */
	public function test_every_exception_is_documented() {
		foreach ( self::EXCEPTIONS as $class => $reason ) {
			$this->assertNotSame(
				'',
				trim( $reason ),
				"Для класса {$class} не указано обоснование исключения."
			);
			$this->assertGreaterThan(
				20,
				strlen( trim( $reason ) ),
				"Обоснование для {$class} слишком короткое, чтобы быть осмысленным."
			);
		}
	}

	/**
	 * Каждый файл includes/ подключён в idpg-core.php.
	 *
	 * Парная к DEV-100 проверка: файл, который никто не подключил, —
	 * это тот же дефект с другой стороны.
	 */
	public function test_every_includes_file_is_required_by_bootstrap() {
		$bootstrap = file_get_contents( IDPG_CORE_DIR . 'idpg-core.php' );

		$missing = array();

		foreach ( glob( IDPG_CORE_DIR . 'includes/*.php' ) as $file ) {
			if ( false === strpos( $bootstrap, 'includes/' . basename( $file ) ) ) {
				$missing[] = basename( $file );
			}
		}

		$this->assertSame(
			array(),
			$missing,
			'Файлы includes/, не подключённые в idpg-core.php: ' . implode( ', ', $missing )
		);
	}

	// =====================================================================
	// Синглтоны
	// =====================================================================

	/**
	 * Конструктор каждого синглтона уже отработал.
	 *
	 * @dataProvider data_singletons
	 *
	 * @param string $class  Имя класса.
	 * @param string $origin Где вызывается инициализация.
	 */
	public function test_singleton_is_instantiated( $class, $origin ) {
		$this->assertTrue( class_exists( $class ), "Класс {$class} не объявлен." );

		$property = new ReflectionProperty( $class, 'instance' );
		$property->setAccessible( true );

		$this->assertNotNull(
			$property->getValue(),
			"{$class}: экземпляр не создан, инициализация в {$origin} не сработала."
		);
	}

	/**
	 * Данные для проверки синглтонов.
	 *
	 * @return array
	 */
	public function data_singletons() {
		$cases = array();

		foreach ( self::SINGLETONS as $class => $origin ) {
			$cases[ $class ] = array( $class, $origin );
		}

		return $cases;
	}

	// =====================================================================
	// Статическая инициализация
	// =====================================================================

	/**
	 * Хуки статической инициализации зарегистрированы.
	 *
	 * @dataProvider data_static_init
	 *
	 * @param string $class  Имя класса.
	 * @param array  $hooks  Хук => метод, которые обязан повесить init().
	 */
	public function test_static_init_registers_hooks( $class, $hooks ) {
		$this->assertTrue( class_exists( $class ), "Класс {$class} не объявлен." );

		foreach ( $hooks as $hook => $method ) {
			$this->assertNotFalse(
				has_action( $hook, array( $class, $method ) )
					|| has_filter( $hook, array( $class, $method ) ),
				"{$class}::init() не зарегистрировал {$hook} → {$method}."
			);
		}
	}

	/**
	 * Данные для проверки статической инициализации.
	 *
	 * @return array
	 */
	public function data_static_init() {
		$cases = array();

		foreach ( self::STATIC_INIT as $class => $hooks ) {
			$cases[ $class ] = array( $class, $hooks );
		}

		return $cases;
	}

	// =====================================================================
	// Виджеты
	// =====================================================================

	/**
	 * Виджеты зарегистрированы в фабрике WordPress.
	 *
	 * @dataProvider data_widgets
	 *
	 * @param string $class  Имя класса виджета.
	 * @param string $origin Где вызывается регистрация.
	 */
	public function test_widget_is_registered( $class, $origin ) {
		global $wp_widget_factory;

		$this->assertTrue( class_exists( $class ), "Класс {$class} не объявлен." );

		$this->assertArrayHasKey(
			$class,
			$wp_widget_factory->widgets,
			"Виджет {$class} не зарегистрирован: {$origin} не отработал."
		);
	}

	/**
	 * Данные для проверки виджетов.
	 *
	 * @return array
	 */
	public function data_widgets() {
		$cases = array();

		foreach ( self::WIDGETS as $class => $origin ) {
			$cases[ $class ] = array( $class, $origin );
		}

		return $cases;
	}

	// =====================================================================
	// Регрессия DEV-102
	// =====================================================================

	/**
	 * Классы, потерянные в DEV-102, инициализируются.
	 *
	 * Отдельный набор вместо общей таблицы: именно эти пять классов
	 * были подключены, но не запущены, и дефект молчал месяц.
	 */
	public function test_dev102_classes_are_active() {
		// REST-маршруты организатора зарегистрированы.
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/idpg/v1/organizer/events', $routes );
		$this->assertArrayHasKey( '/idpg/v1/organizer/venues', $routes );
		$this->assertArrayHasKey( '/idpg/v1/organizer/profile', $routes );

		// Отклонение события модератором.
		$this->assertHookBound( 'post_row_actions', 'IDPG_Event_Rejection', 'add_row_action' );

		// Снятие закрепления по cron. Хук снимается на priority 10
		// предыдущими тестами набора, поэтому проверяем через
		// перерегистрацию: add_action возвращает true для новой связки.
		$this->assertTrue(
			add_action( IDPG_Promotion::CRON_HOOK, array( 'IDPG_Promotion', 'unpin_expired' ) )
		);
		$this->assertHookBound( IDPG_Promotion::CRON_HOOK, 'IDPG_Promotion', 'unpin_expired' );

		// Модерация публикации событий организатора.
		$this->assertHookBound( 'wp_insert_post_data', 'IDPG_Event_Moderation', 'enforce_pending_for_organizer' );

		// Приватность: админ-страница и cookie-баннер.
		$this->assertHookBound( 'admin_menu', 'IDPG_Privacy', 'register_page' );
		$this->assertHookBound( 'wp_footer', 'IDPG_Privacy', 'render_banner' );
	}

	/**
	 * Проверяет, что на хуке есть колбэк нужного класса и метода.
	 *
	 * `has_action()` для колбэков вида `array( $this, 'method' )` не
	 * работает: объект синглтона не равен строке с именем класса. Поэтому
	 * перебираем зарегистрированные колбэки и сравниваем класс объекта.
	 *
	 * @param string $hook   Имя хука.
	 * @param string $class  Ожидаемый класс.
	 * @param string $method Ожидаемый метод.
	 */
	private function assertHookBound( $hook, $class, $method ) {
		global $wp_filter;

		$this->assertArrayHasKey( $hook, (array) $wp_filter, "Хук {$hook} не зарегистрирован." );

		$found = false;

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];

				if ( is_array( $fn ) && is_object( $fn[0] ) && $fn[0] instanceof $class && $fn[1] === $method ) {
					$found = true;
					break 2;
				}
			}
		}

		$this->assertTrue( $found, "На хуке {$hook} нет колбэка {$class}::{$method}()." );
	}

	// =====================================================================
	// Вспомогательное
	// =====================================================================

	/**
	 * Собирает карту «файл → класс» по includes/.
	 *
	 * Разбирает исходники регулярным выражением, а не через
	 * class_exists: файл может содержать класс под другим именем либо
	 * не содержать класс вовсе, и такой случай тоже нужно видеть.
	 *
	 * @return array<string,string> Имя класса => имя файла.
	 */
	private function collect_classes() {
		$map = array();

		foreach ( glob( IDPG_CORE_DIR . 'includes/*.php' ) as $file ) {
			$source = file_get_contents( $file );

			if ( preg_match( '/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/mi', $source, $m ) ) {
				$map[ $m[1] ] = basename( $file );
			}
		}

		ksort( $map );

		return $map;
	}
}
