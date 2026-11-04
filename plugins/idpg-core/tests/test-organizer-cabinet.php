<?php
/**
 * Тесты DEV-312: кабинет организатора снова включён.
 *
 * Набор фиксирует то, что было снято DEV-234/DEV-235 и восстановлено
 * DEV-312: вызов `IDPG_Organizer_URL::init()`, выбор шаблона темы,
 * наличие файлов кабинета, меню, подключение ассетов только на
 * `/organizer/*` и отсутствие зависимостей от Carbon Fields (DEV-311).
 *
 * Большая часть проверок — статические: они читают исходники темы и
 * плагина, потому что шаблоны живут в теме, а тесты — в плагине.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Organizer_Cabinet
 */
class Test_Organizer_Cabinet extends WP_UnitTestCase {

	/**
	 * Каталог темы проекта.
	 *
	 * @return string
	 */
	private function theme_dir() {
		return dirname( dirname( dirname( __DIR__ ) ) ) . '/themes/idpg_core';
	}

	/**
	 * Содержимое файла проекта.
	 *
	 * @param string $relative Путь относительно корня проекта.
	 * @return string Пустая строка, если файла нет.
	 */
	private function source( $relative ) {
		$path = dirname( dirname( dirname( __DIR__ ) ) ) . '/' . $relative;

		if ( ! file_exists( $path ) ) {
			return '';
		}

		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Роутер включён: init() вызывается из idpg_core_init(), ровно один раз.
	 */
	public function test_router_init_called() {
		$src = $this->source( 'plugins/idpg-core/idpg-core.php' );

		$this->assertStringContainsString( 'IDPG_Organizer_URL::init();', $src );
		$this->assertSame(
			1,
			substr_count( $src, 'IDPG_Organizer_URL::init();' ),
			'init() роутера должен вызываться ровно один раз.'
		);
	}

	/**
	 * init() регистрирует хуки маршрута, шаблона и guard-редиректа.
	 */
	public function test_router_hooks_registered() {
		$class = 'IDPG_Organizer_URL';

		$this->assertNotFalse(
			has_filter( 'template_include', array( $class, 'template' ) ),
			'template_include не перехватывается: кабинет отдаст 404.'
		);
		$this->assertNotFalse( has_filter( 'query_vars', array( $class, 'query_vars' ) ) );
		$this->assertNotFalse( has_action( 'init', array( $class, 'rewrites' ) ) );
		$this->assertNotFalse( has_filter( 'wp_redirect', array( $class, 'guard_cabinet_redirect' ) ) );
	}

	/**
	 * Константа шаблона вернулась вместе с файлом.
	 */
	public function test_template_constant_restored() {
		$this->assertSame( 'page-organizer.php', IDPG_Organizer_URL::TEMPLATE );
		$this->assertFileExists( $this->theme_dir() . '/' . IDPG_Organizer_URL::TEMPLATE );
	}

	/**
	 * template() снова выбирает файл темы, а не отдаёт текущий как есть.
	 */
	public function test_template_selection_uses_locate_template() {
		$src = $this->source( 'plugins/idpg-core/includes/class-organizer-url.php' );

		$this->assertStringContainsString( 'locate_template( self::TEMPLATE )', $src );
	}

	/**
	 * Шаблоны кабинета на месте: страница и четыре раздела.
	 */
	public function test_cabinet_templates_exist() {
		$files = array(
			'page-organizer.php',
			'template-parts/organizer/section-dashboard.php',
			'template-parts/organizer/section-events.php',
			'template-parts/organizer/section-venues.php',
			'template-parts/organizer/section-profile.php',
		);

		foreach ( $files as $file ) {
			$this->assertFileExists( $this->theme_dir() . '/' . $file, "Нет шаблона кабинета: {$file}" );
		}
	}

	/**
	 * Разделы страницы совпадают с SECTIONS роутера.
	 *
	 * Без `event` — это форма, а не раздел со своим template-part.
	 */
	public function test_sections_have_matching_parts() {
		foreach ( array( 'dashboard', 'events', 'venues', 'profile' ) as $section ) {
			$this->assertContains( $section, IDPG_Organizer_URL::SECTIONS );
			$this->assertFileExists(
				$this->theme_dir() . '/template-parts/organizer/section-' . $section . '.php'
			);
		}
	}

	/**
	 * Меню кабинета восстановлено и подключено темой.
	 */
	public function test_menu_restored_and_required() {
		$menu = $this->source( 'themes/idpg_core/inc/organizer-menu.php' );

		$this->assertNotSame( '', $menu, 'Файл меню кабинета отсутствует.' );
		$this->assertSame( 8, preg_match_all( '/^function idpg_organizer_/m', $menu ) );

		$this->assertStringContainsString(
			"require_once get_template_directory() . '/inc/organizer-menu.php';",
			$this->source( 'themes/idpg_core/functions.php' )
		);
	}

	/**
	 * Доступ: гость, пользователь без роли и организатор.
	 */
	public function test_access_decision() {
		wp_set_current_user( 0 );
		$this->assertSame( IDPG_Organizer_URL::ACCESS_GUEST, IDPG_Organizer_URL::get_access_decision() );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertSame(
			IDPG_Organizer_URL::ACCESS_DENIED,
			IDPG_Organizer_URL::get_access_decision( $subscriber )
		);

		$organizer = self::factory()->user->create( array( 'role' => IDPG_Roles::ROLE_SLUG ) );
		$this->assertSame(
			IDPG_Organizer_URL::ACCESS_ALLOWED,
			IDPG_Organizer_URL::get_access_decision( $organizer )
		);
	}

	/**
	 * Отказ в доступе отдаётся кодом 403, а не 200 с текстом.
	 */
	public function test_denied_response_is_403() {
		$this->assertStringContainsString(
			'status_header( 403 )',
			$this->source( 'themes/idpg_core/page-organizer.php' )
		);
	}

	/**
	 * Ассеты кабинета подключаются только внутри проверки кабинета.
	 */
	public function test_assets_enqueued_only_for_cabinet() {
		$src = $this->source( 'themes/idpg_core/functions.php' );

		$guard = strpos( $src, 'idpg_core_is_organizer_cabinet()' );
		$css   = strpos( $src, "'idpg_core-organizer'" );
		$next  = strpos( $src, '// Передача данных в JS' );

		$this->assertNotFalse( $guard );
		$this->assertNotFalse( $css );
		$this->assertNotFalse( $next );
		$this->assertGreaterThan( $guard, $css, 'Enqueue кабинета должен идти после проверки кабинета.' );
		$this->assertLessThan( $next, $css, 'Enqueue кабинета должен оставаться внутри блока кабинета.' );
	}

	/**
	 * Страница кабинета создаётся на after_setup_theme и исключена из sitemap.
	 */
	public function test_page_creation_and_sitemap() {
		$src = $this->source( 'themes/idpg_core/functions.php' );

		$this->assertStringContainsString(
			"add_action( 'after_setup_theme', 'idpg_core_create_organizer_page' )",
			$src
		);
		$this->assertStringContainsString(
			"add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', 'idpg_core_exclude_organizer_page_from_sitemap' )",
			$src
		);
	}

	/**
	 * Формы события и площадки не отдают битую страницу: уводят в раздел.
	 */
	public function test_form_routes_redirect_to_sections() {
		$src = $this->source( 'plugins/idpg-core/includes/class-organizer-url.php' );

		$this->assertStringContainsString( "wp_safe_redirect( self::section_url( 'events' ) )", $src );
		$this->assertStringContainsString( "wp_safe_redirect( self::section_url( 'venues' ) )", $src );
	}

	/**
	 * В восстановленных файлах нет вызовов Carbon Fields (удалён DEV-311).
	 */
	public function test_no_carbon_fields_in_cabinet() {
		$files = array(
			'themes/idpg_core/page-organizer.php',
			'themes/idpg_core/inc/organizer-menu.php',
			'themes/idpg_core/template-parts/organizer/section-dashboard.php',
			'themes/idpg_core/template-parts/organizer/section-events.php',
			'themes/idpg_core/template-parts/organizer/section-venues.php',
			'themes/idpg_core/template-parts/organizer/section-profile.php',
			'themes/idpg_core/assets/js/organizer.js',
		);

		foreach ( $files as $file ) {
			$src = $this->source( $file );

			$this->assertNotSame( '', $src, "Файл не найден: {$file}" );
			$this->assertSame( 0, substr_count( $src, 'carbon_' ), "Carbon Fields в файле {$file}" );
		}
	}

	/**
	 * В изменённых файлах нет забытых маркеров конфликта.
	 */
	public function test_no_merge_markers() {
		$files = array(
			'themes/idpg_core/page-organizer.php',
			'themes/idpg_core/inc/organizer-menu.php',
			'themes/idpg_core/functions.php',
			'plugins/idpg-core/includes/class-organizer-url.php',
			'plugins/idpg-core/idpg-core.php',
		);

		foreach ( $files as $file ) {
			$src = $this->source( $file );

			$this->assertStringNotContainsString( '<<<<<<<', $src, "Маркер конфликта в {$file}" );
			$this->assertStringNotContainsString( '>>>>>>>', $src, "Маркер конфликта в {$file}" );
		}
	}
}

