<?php
/**
 * idpg_core functions and definitions
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package idpg_core
 */

if ( ! defined( 'IDPG_VERSION' ) ) {
	define( 'IDPG_VERSION', '1.0.75' ); // 1.0.75: bump для сброса кэша style.css (фикс отступа оверлея поиска)
}



/**
 * Родительские пункты меню «Афиша» и «Площадки» — неактивные (без ссылки),
 * подменю при этом продолжают работать.
 *
 * @param array $items Элементы меню.
 * @return array
 */
function idpg_core_disable_parent_menu_items( $items ) {
	$disabled_paths = array( '/afisha/', '/venues/' );

	foreach ( $items as $item ) {
		if ( ! in_array( 'menu-item-has-children', (array) $item->classes, true ) ) {
			continue;
		}

		$path = parse_url( (string) $item->url, PHP_URL_PATH );
		if ( ! in_array( $path, $disabled_paths, true ) ) {
			continue;
		}

		$item->url     = '';
		$item->classes = array_merge( (array) $item->classes, array( 'idpg-nav__item--disabled' ) );
	}

	return $items;
}
add_filter( 'wp_nav_menu_objects', 'idpg_core_disable_parent_menu_items' );

/**
 * Возвращает URL страницы входа (страница с шаблоном page-login.php,
 * иначе дефолтный /login/). Нужен для фильтра футер-меню.
 *
 * @return string
 */
function idpg_core_get_login_url_in_theme() {
	$login_page = get_posts(
		array(
			'post_type'   => 'page',
			'numberposts' => 1,
			'meta_key'    => '_wp_page_template',
			'meta_value'  => 'page-login.php',
			'fields'      => 'ids',
		)
	);

	if ( ! empty( $login_page ) ) {
		return trailingslashit( get_permalink( $login_page[0] ) );
	}

	return trailingslashit( home_url( '/login/' ) );
}

/**
 * Убирает пункт «Войти» из футер-меню.
 *
 * Вход доступен в шапке, дублирование ссылки в подвале не требуется.
 * Пункт определяется по URL страницы входа, поэтому работает и на проде,
 * независимо от слага страницы (/login/, /vhod/ и т.п.).
 *
 * @param array    $items Элементы меню.
 * @param stdClass $args  Аргументы wp_nav_menu().
 * @return array
 */
function idpg_core_remove_footer_login_item( $items, $args ) {
	if ( empty( $args->theme_location ) || 'footer' !== $args->theme_location ) {
		return $items;
	}

	$login_url = untrailingslashit( idpg_core_get_login_url_in_theme() );

	foreach ( $items as $key => $item ) {
		if ( untrailingslashit( (string) $item->url ) === $login_url ) {
			unset( $items[ $key ] );
		}
	}

	return $items;
}
add_filter( 'wp_nav_menu_objects', 'idpg_core_remove_footer_login_item', 20, 2 );

/**
 * Composer autoload.
 *
 * ПЕРЕНЕСЕНО В ПЛАГИН idpg-core (см. idpg-core.php):
 * Carbon Fields устанавливается через Composer в плагине.
 * Тема больше не грузит собственный vendor.
 */
// $composer_autoload = get_template_directory() . '/vendor/autoload.php';
// if ( file_exists( $composer_autoload ) ) {
// 	require_once $composer_autoload;
// }

/**
 * Carbon Fields bootstrap.
 *
 * ПЕРЕНЕСЕНО В ПЛАГИН idpg-core: boot выполняется в idpg-core.php
 * (after_setup_theme, приоритет 5). Закомментировано во избежание
 * конфликта имён функции и двойной инициализации.
 */
// add_action( 'after_setup_theme', 'idpg_carbon_fields_boot', 5 );
// function idpg_carbon_fields_boot() {
// 	if ( function_exists( 'carbon_get_theme_option' ) ) {
// 		return;
// 	}
// 	\Carbon_Fields\Carbon_Fields::boot();
// }

/**
 * Sets up theme defaults and registers support for various WordPress features.
 *
 * Note that this function is hooked into the after_setup_theme hook, which
 * runs before the init hook. The init hook is too late for some features, such
 * as indicating support for post thumbnails.
 */
function idpg_core_setup() {
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'widgets' );
	add_theme_support( 'editor-styles' );
	add_editor_style( 'assets/css/editor-style.css' );
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 250,
			'width'       => 250,
			'flex-width'  => true,
			'flex-height' => true,
		)
	);

	register_nav_menus(
		array(
			'menu-1'  => esc_html__( 'Primary', 'idpg_core' ),
			'footer'  => esc_html__( 'Footer', 'idpg_core' ),
		)
	);

	add_theme_support(
		'html5',
		array(
			'search-form',
			'style',
			'script',
		)
	);
}
add_action( 'after_setup_theme', 'idpg_core_setup' );

/**
 * Enqueue scripts and styles.
 */
function idpg_core_scripts() {
	wp_enqueue_style( 'idpg_core-style', get_stylesheet_uri(), array(), IDPG_VERSION );
	// Версия по filemtime — кэш браузера сбрасывается автоматически при каждой правке файла.
	$templates_ver = file_exists( get_template_directory() . '/assets/css/templates.css' )
		? (string) filemtime( get_template_directory() . '/assets/css/templates.css' )
		: IDPG_VERSION;
	wp_enqueue_style( 'idpg_core-templates', get_template_directory_uri() . '/assets/css/templates.css', array( 'idpg_core-style' ), $templates_ver );
	wp_enqueue_script( 'idpg_core-canvas-fix', get_template_directory_uri() . '/assets/js/canvas-fix.js', array(), IDPG_VERSION, false );
	wp_enqueue_script( 'idpg_core-navigation', get_template_directory_uri() . '/assets/js/navigation.js', array(), IDPG_VERSION, true );
	wp_enqueue_script( 'idpg_core-live-search', get_template_directory_uri() . '/assets/js/live-search.js', array(), IDPG_VERSION, true );

	// Виджет «Подписка на дайджест»: AJAX-логика и стили.
	wp_enqueue_script( 'idpg_core-subscribe', get_template_directory_uri() . '/assets/js/subscribe.js', array(), IDPG_VERSION, true );
	wp_localize_script(
		'idpg_core-subscribe',
		'idpgSubscribe',
		array(
			'restUrl' => esc_url_raw( home_url( '/wp-json/idpg/v1' ) ),
		)
	);
	$subscribe_css_ver = file_exists( get_template_directory() . '/assets/css/subscribe.css' )
		? (string) filemtime( get_template_directory() . '/assets/css/subscribe.css' )
		: IDPG_VERSION;
	wp_enqueue_style( 'idpg_core-subscribe', get_template_directory_uri() . '/assets/css/subscribe.css', array( 'idpg_core-style' ), $subscribe_css_ver );

	// Замер высоты шапки → CSS custom property --idpg-header-h.
	add_action( 'wp_footer', 'idpg_core_header_height_js' );

	// Селектор города в шапке — Tom Select из плагина idpg-core.
	if ( defined( 'IDPG_CORE_URL' ) ) {
		wp_enqueue_style( 'idpg-city-selector-tom-select' );
		wp_enqueue_style( 'idpg-city-selector' );
		wp_enqueue_script( 'idpg-city-selector-tom-select' );
		wp_enqueue_script( 'idpg-city-selector' );
	}

	if ( is_page_template( 'page-login.php' ) || is_page( 'login' ) || is_page_template( 'page-reset-password.php' ) || is_page( 'reset-password' ) ) {
		wp_enqueue_script( 'idpg_core-auth', get_template_directory_uri() . '/assets/js/auth.js', array(), IDPG_VERSION, true );
	}

	// Передача данных в JS
	$current_city_slug = class_exists( 'IDPG_City_Filter' ) ? IDPG_City_Filter::get_current_city_slug() : '';
	wp_localize_script(
		'idpg_core-navigation',
		'idpgData',
		array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'idpg_nonce' ),
			'cityNonce' => wp_create_nonce( 'idpg_set_city' ),
			'homeUrl'   => home_url( '/' ),
			'restUrl'   => esc_url_raw( home_url( '/wp-json/idpg/v1' ) ),
			'citySlug'  => $current_city_slug,
		)
	);
	// Те же данные для live-поиска (изолированный хэндл)
	wp_localize_script(
		'idpg_core-live-search',
		'idpgData',
		array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'idpg_nonce' ),
			'cityNonce' => wp_create_nonce( 'idpg_set_city' ),
			'homeUrl'   => home_url( '/' ),
			'restUrl'   => esc_url_raw( home_url( '/wp-json/idpg/v1' ) ),
			'citySlug'  => $current_city_slug,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'idpg_core_scripts' );
add_action( 'admin_enqueue_scripts', function() {
	wp_enqueue_script( 'idpg_core-canvas-fix-admin', get_template_directory_uri() . '/assets/js/canvas-fix.js', array(), IDPG_VERSION, false );
} );

/**
 * Замер высоты шапки и проброс в CSS custom property --idpg-header-h.
 * Вызывается из wp_footer, чтобы .idpg-header уже был отрисован.
 */
function idpg_core_header_height_js() {
	?>
	<script>
	(function(){
		var h = document.querySelector('.idpg-header');
		if(!h) return;
		var rect = h.getBoundingClientRect();
		var total = rect.bottom;
		document.documentElement.style.setProperty('--idpg-header-h', total + 'px');
		// Обновить padding контейнера баннера на главной
		var bannerWrap = document.querySelector('.home > .container');
		if (bannerWrap) {
			bannerWrap.style.paddingTop = total + 'px';
		}
	})();
	</script>
	<?php
}

/**
 * Юридические страницы (оферта, политика): выводит контент,
 * добавляет якоря к h2 и собирает оглавление.
 *
 * @param string $fallback_html Дефолтный текст, если контент страницы пуст.
 * @return array { toc: array[], html: string }
 */
function idpg_core_legal_document( $fallback_html = '' ) {
	ob_start();
	the_content();
	$html = trim( ob_get_clean() );

	if ( '' === $html ) {
		$html = $fallback_html;
	}

	$toc     = array();
	$counter = 0;

	$html = preg_replace_callback(
		'/<h2([^>]*)>(.*?)<\/h2>/is',
		function ( $m ) use ( &$toc, &$counter ) {
			$counter++;
			$id    = 'section-' . $counter;
			$toc[] = array(
				'id'    => $id,
				'title' => wp_strip_all_tags( $m[2] ),
			);
			return '<h2 id="' . esc_attr( $id ) . '"' . $m[1] . '>' . $m[2] . '</h2>';
		},
		$html
	);

	return array(
		'toc'  => $toc,
		'html' => $html,
	);
}

/**
 * Печать блока юридического документа: оглавление + текст.
 *
 * @param array $doc Результат idpg_core_legal_document().
 */
function idpg_core_legal_render( $doc ) {
	$has_toc = count( $doc['toc'] ) > 1;
	?>
	<div class="idpg-legal-layout<?php echo $has_toc ? ' has-toc' : ''; ?>">
		<?php if ( $has_toc ) : ?>
			<nav class="idpg-legal-toc" aria-label="<?php esc_attr_e( 'Содержание документа', 'idpg_core' ); ?>">
				<span class="idpg-legal-toc__caption"><?php esc_html_e( 'Содержание', 'idpg_core' ); ?></span>
				<ul class="idpg-legal-toc__list">
					<?php foreach ( $doc['toc'] as $item ) : ?>
						<li><a href="#<?php echo esc_attr( $item['id'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>
		<?php endif; ?>
		<div class="idpg-legal-body">
			<?php echo wp_kses_post( $doc['html'] ); ?>
		</div>
	</div>
	<?php
}

/**
 * Кастомные размеры изображений.
 */
function idpg_core_image_sizes() {
	add_image_size( 'event-card', 400, 300, true );       // Карточка события (обрезка)
	add_image_size( 'event-featured', 800, 450, true );   // Главное изображение события
}
add_action( 'after_setup_theme', 'idpg_core_image_sizes' );

/**
 * Print font faces from theme.json and Font Library.
 */
function idpg_core_print_fonts() {
	if ( function_exists( 'wp_print_font_faces' ) ) {
		wp_print_font_faces();
	}
}
add_action( 'wp_head', 'idpg_core_print_fonts', 1 );

/**
 * Theme helper functions.
 */
require_once get_template_directory() . '/inc/theme-helpers.php';

/**
 * Template tags для специализированных шаблонов (события, площадки).
 */
require_once get_template_directory() . '/inc/template-tags.php';

/**
 * Хлебные крошки (с поддержкой Yoast SEO).
 */
require_once get_template_directory() . '/inc/breadcrumbs.php';

/**
 * Видимость секций главной страницы (админ-чекбоксы).
 */
require_once get_template_directory() . '/inc/home-sections.php';

/**
 * Advertising widget + sidebar areas.
 */
require_once get_template_directory() . '/inc/widgets.php';

/**
 * Кастомный Walker для навигации.
 */
require_once get_template_directory() . '/inc/class-idpg-nav-walker.php';

/**
 * Управление областями виджетов.
 */
require_once get_template_directory() . '/inc/widget-areas.php';

/**
 * Автосоздание меню навигации по умолчанию.
 *
 * Создаёт меню «Основное меню» с пунктами:
 * - Афиша (с dropdown: Все события, Сегодня, Завтра, Этот уикенд, Бесплатные)
 * - Места (с dropdown: Все площадки, Театры, Кинотеатры, Музеи, Концертные залы)
 * - Личности
 * - Блог
 */
function idpg_core_create_default_menu() {
	$menu_name = 'Основное меню';
	$menu_exists = wp_get_nav_menu_object( $menu_name );

	if ( $menu_exists ) {
		return;
	}

	// Создаём меню
	$menu_id = wp_create_nav_menu( $menu_name, array(
		'menu-name' => $menu_name,
	) );

	if ( is_wp_error( $menu_id ) ) {
		return;
	}

	// Структура меню из CSV
	$menu_items = array(
		// Афиша (с подменю)
		array(
			'title'  => 'Афиша',
			'url'    => home_url( '/afisha/' ),
			'children' => array(
				array( 'title' => 'Все события',   'url' => home_url( '/afisha/' ) ),
				array( 'title' => 'Сегодня',       'url' => home_url( '/afisha/?date=today' ) ),
				array( 'title' => 'Завтра',        'url' => home_url( '/afisha/?date=tomorrow' ) ),
				array( 'title' => 'Этот уикенд',   'url' => home_url( '/afisha/?date=weekend' ) ),
				array( 'title' => 'Бесплатные',    'url' => home_url( '/afisha/?is_free=1' ) ),
			),
		),
		// Места (с подменю)
		array(
			'title'  => 'Места',
			'url'    => home_url( '/venues/' ),
			'children' => array(
				array( 'title' => 'Все площадки',    'url' => home_url( '/venues/' ) ),
				array( 'title' => 'Театры',          'url' => home_url( '/venues/?type=theater' ) ),
				array( 'title' => 'Кинотеатры',      'url' => home_url( '/venues/?type=cinema' ) ),
				array( 'title' => 'Музеи',           'url' => home_url( '/venues/?type=museum' ) ),
				array( 'title' => 'Концертные залы', 'url' => home_url( '/venues/?type=concert' ) ),
			),
		),
		// Личности (без подменю)
		array(
			'title' => 'Личности',
			'url'   => home_url( '/personalities/' ),
		),
		// Блог (без подменю)
		array(
			'title' => 'Блог',
			'url'   => home_url( '/blog/' ),
		),
	);

	// Родительские ID для подменю
	$parent_ids = array();

	foreach ( $menu_items as $item ) {
		// Добавляем родительский пункт
		$item_id = wp_update_nav_menu_item( $menu_id, 0, array(
			'menu-item-title'  => $item['title'],
			'menu-item-url'    => $item['url'],
			'menu-item-status' => 'publish',
		) );

		// Сохраняем ID родителя
		$parent_ids[ $item['title'] ] = $item_id;

		// Добавляем подменю (дети)
		if ( ! empty( $item['children'] ) ) {
			foreach ( $item['children'] as $child ) {
				wp_update_nav_menu_item( $menu_id, 0, array(
					'menu-item-title'      => $child['title'],
					'menu-item-url'        => $child['url'],
					'menu-item-status'     => 'publish',
					'menu-item-parent-id'  => $item_id,
				) );
			}
		}
	}

	// Привязываем меню к локации
	$locations = get_theme_mod( 'nav_menu_locations', array() );
	$locations['menu-1'] = $menu_id;
	set_theme_mod( 'nav_menu_locations', $locations );
}
add_action( 'after_setup_theme', 'idpg_core_create_default_menu' );

/**
 * Автосоздание страницы входа.
 *
 * Создаёт страницу /login/ с шаблоном page-login.php,
 * если она ещё не существует.
 */
function idpg_core_create_login_page() {
	$page_slug = 'login';
	$page_title = 'Вход';
	$page_template = 'page-login.php';

	$existing_page = get_page_by_path( $page_slug );

	if ( $existing_page ) {
		$template = get_post_meta( $existing_page->ID, '_wp_page_template', true );
		if ( $template === $page_template ) {
			return;
		}
	}

	$page_data = array(
		'post_title'   => $page_title,
		'post_name'    => $page_slug,
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '',
		'post_parent'  => 0,
	);

	$page_id = wp_insert_post( $page_data, true );

	if ( ! is_wp_error( $page_id ) ) {
		update_post_meta( $page_id, '_wp_page_template', $page_template );
	}
}
add_action( 'after_setup_theme', 'idpg_core_create_login_page' );

/**
 * Автосоздание страницы восстановления пароля.
 *
 * Создаёт страницу /reset-password/ с шаблоном page-reset-password.php,
 * если она ещё не существует.
 */
function idpg_core_create_reset_password_page() {
	$page_slug = 'reset-password';
	$page_title = 'Восстановление пароля';
	$page_template = 'page-reset-password.php';

	$existing_page = get_page_by_path( $page_slug );

	if ( $existing_page ) {
		$template = get_post_meta( $existing_page->ID, '_wp_page_template', true );
		if ( $template === $page_template ) {
			return;
		}
	}

	$page_data = array(
		'post_title'   => $page_title,
		'post_name'    => $page_slug,
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '',
		'post_parent'  => 0,
	);

	$page_id = wp_insert_post( $page_data, true );

	if ( ! is_wp_error( $page_id ) ) {
		update_post_meta( $page_id, '_wp_page_template', $page_template );
	}
}
add_action( 'after_setup_theme', 'idpg_core_create_reset_password_page' );

/**
 * Автосоздание страницы «Правила сайта / оферта».
 *
 * Создаёт страницу /terms/ с шаблоном page-terms.php и
 * предзаполненным текстом оферты, если она ещё не существует.
 */
function idpg_core_create_terms_page() {
	$page_slug     = 'terms';
	$page_title    = 'Правила сайта';
	$page_template = 'page-terms.php';

	$existing_page = get_page_by_path( $page_slug );

	if ( $existing_page ) {
		$template = get_post_meta( $existing_page->ID, '_wp_page_template', true );
		if ( $template === $page_template ) {
			return;
		}

		$page_id = $existing_page->ID;
	} else {
		$page_data = array(
			'post_title'   => $page_title,
			'post_name'    => $page_slug,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '<h2>1. Общие положения</h2>' . "\n\n" .
				'<p>Настоящие правила определяют условия использования сайта и сервисов, размещённых на нём.</p>' . "\n\n" .
				'<h2>2. Предмет Оферты</h2>' . "\n\n" .
				'<p>Сайт предоставляет информационные услуги: публикацию афиши событий, сведений о площадках и возможность взаимодействия пользователей с сервисом.</p>' . "\n\n" .
				'<h2>3. Регистрация</h2>' . "\n\n" .
				'<p>Для доступа к части сервисов требуется регистрация. Регистрируясь, пользователь обязуется предоставлять достоверные сведения.</p>' . "\n\n" .
				'<h2>4. Заключительные положения</h2>' . "\n\n" .
				'<p>Администрация вправе изменять настоящие правила в одностороннем порядке. Актуальная версия доступна на этой странице.</p>',
			'post_parent'  => 0,
		);

		$page_id = wp_insert_post( $page_data, true );

		if ( is_wp_error( $page_id ) ) {
			return;
		}
	}

	update_post_meta( $page_id, '_wp_page_template', $page_template );
}
add_action( 'after_setup_theme', 'idpg_core_create_terms_page' );

/**
 * Автосоздание страницы «Политика конфиденциальности».
 *
 * Создаёт страницу /privacy/ с шаблоном page-privacy.php и
 * предзаполненным текстом политики, если она ещё не существует.
 */
function idpg_core_create_privacy_page() {
	$page_slug     = 'privacy';
	$page_title    = 'Политика конфиденциальности';
	$page_template = 'page-privacy.php';

	$existing_page = get_page_by_path( $page_slug );

	if ( $existing_page ) {
		$template = get_post_meta( $existing_page->ID, '_wp_page_template', true );
		if ( $template === $page_template ) {
			return;
		}

		$page_id = $existing_page->ID;
	} else {
		$page_data = array(
			'post_title'   => $page_title,
			'post_name'    => $page_slug,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '<h2>1. Общие положения</h2>' . "\n\n" .
				'<p>Настоящая Политика конфиденциальности определяет порядок обработки и защиты персональных данных пользователей сайта.</p>' . "\n\n" .
				'<h2>2. Какие данные мы обрабатываем</h2>' . "\n\n" .
				'<p>При регистрации могут обрабатываться имя, адрес электронной почты, номер телефона и иные сведения, предоставляемые пользователем добровольно.</p>' . "\n\n" .
				'<h2>3. Цели обработки</h2>' . "\n\n" .
				'<p>Данные обрабатываются для предоставления доступа к сервисам, создания учётной записи и направления уведомлений.</p>' . "\n\n" .
				'<h2>4. Защита данных</h2>' . "\n\n" .
				'<p>Администрация принимает организационные и технические меры для защиты персональных данных от неправомерного доступа.</p>' . "\n\n" .
				'<h2>5. Заключительные положения</h2>' . "\n\n" .
				'<p>Администрация вправе изменять настоящую Политику в одностороннем порядке. Актуальная версия доступна на этой странице.</p>',
			'post_parent'  => 0,
		);

		$page_id = wp_insert_post( $page_data, true );

		if ( is_wp_error( $page_id ) ) {
			return;
		}
	}

	update_post_meta( $page_id, '_wp_page_template', $page_template );
}
add_action( 'after_setup_theme', 'idpg_core_create_privacy_page' );

/**
 * Автосоздание страницы «Оферта».
 *
 * Создаёт страницу /offer/ с шаблоном page-terms.php (светлый hero без TOC),
 * если она ещё не существует. Использует тот же fallback, что и «Правила».
 */
function idpg_core_create_offer_page() {
	$page_slug     = 'offer';
	$page_title    = 'Оферта';
	$page_template = 'page-terms.php';

	$existing_page = get_page_by_path( $page_slug );

	if ( $existing_page ) {
		$template = get_post_meta( $existing_page->ID, '_wp_page_template', true );
		if ( $template === $page_template ) {
			return;
		}
		$page_id = $existing_page->ID;
	} else {
		$page_data = array(
			'post_title'   => $page_title,
			'post_name'    => $page_slug,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '<h2>1. Общие положения</h2>' . "\n\n" .
				'<p>Настоящая оферта определяет условия использования сайта.</p>' . "\n\n" .
				'<h2>2. Предмет оферты</h2>' . "\n\n" .
				'<p>Сайт предоставляет информационные услуги.</p>' . "\n\n" .
				'<h2>3. Заключительные положения</h2>' . "\n\n" .
				'<p>Администрация вправе изменять настоящую оферту. Актуальная версия доступна на этой странице.</p>',
			'post_parent'  => 0,
		);

		$page_id = wp_insert_post( $page_data, true );

		if ( is_wp_error( $page_id ) ) {
			return;
		}
	}

	update_post_meta( $page_id, '_wp_page_template', $page_template );
}
add_action( 'after_setup_theme', 'idpg_core_create_offer_page' );

/**
 * Диапазон ближайших выходных (пятница — воскресенье).
 *
 * Если сегодня пт/сб/вс — возвращаются текущие выходные,
 * иначе — ближайшие наступающие. Используется фильтром афиши
 * (?date=weekend) и секционным блоком главной.
 *
 * @return array{0:string,1:string} [начало Y-m-d, конец Y-m-d].
 */
function idpg_core_get_weekend_range() {
	$ts  = current_time( 'timestamp' );
	$dow = (int) gmdate( 'N', $ts ); // 1 = Пн … 7 = Вс.

	// Пятница этой недели, если мы уже в выходных; иначе ближайшая пятница.
	$offset = ( $dow >= 5 ) ? - ( $dow - 5 ) : ( 5 - $dow );

	$start = gmdate( 'Y-m-d', strtotime( "{$offset} days", $ts ) );
	$end   = gmdate( 'Y-m-d', strtotime( '+2 days', strtotime( $start ) ) ); // Воскресенье.

	return array( $start, $end );
}

/**
 * Live-поиск и фильтры афиши.
 *
 * - Поиск: /afisha/{city}/search/?s=… и /?s=… → только события текущего города.
 * - /afisha/?date=today|tomorrow|weekend|Y-m-d — выборка по датам события.
 * - /afisha/?is_free=1 — только бесплатные.
 * - /gorod/<slug>/ — архив города показывает только события.
 *
 * @param WP_Query $query Запрос.
 */
function idpg_core_pre_get_posts_filters( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	// Поиск: только события, фильтр по городу + по площадкам/категориям.
	if ( $query->is_search() ) {
		// Защита от рекурсии внутренних запросов.
		if ( $query->get( 'idpg_search_extended' ) ) {
			return;
		}

		$query->set( 'post_type', 'event' );
		$query->set( 'posts_per_page', 12 );

		// Город: из URL или текущий.
		$city_id = 0;
		$qvar_city = get_query_var( idpg_core_city_qvar() );
		if ( $qvar_city ) {
			$term = get_term_by( 'slug', $qvar_city, 'city' );
			if ( $term && ! is_wp_error( $term ) ) {
				$city_id = (int) $term->term_id;
			}
		} elseif ( class_exists( 'IDPG_City_Filter' ) ) {
			$city_id = (int) IDPG_City_Filter::get_current_city_id();
		}

		$city_tax = array();
		if ( $city_id && get_term( $city_id, 'city' ) instanceof WP_Term ) {
			$city_tax = array(
				array(
					'taxonomy'         => 'city',
					'field'            => 'term_id',
					'terms'            => $city_id,
					'include_children' => true,
				),
			);
			$tax = $query->get( 'tax_query' );
			$tax = is_array( $tax ) ? $tax : array();
			$tax = array_merge( $tax, $city_tax );
			$query->set( 'tax_query', $tax );
		}

		// Расширяем поиск: заголовок/контент + площадки + категории (в пределах того же города).
		// Логика вынесена в IDPG_Search (общая для темы и REST).
		$q = trim( (string) $query->get( 's' ) );
		if ( mb_strlen( $q ) < 2 ) {
			return;
		}

		$all_ids = class_exists( 'IDPG_Search' ) ? IDPG_Search::find_event_ids( $q, $city_id ) : array();

		if ( $all_ids ) {
			// Заменяем s-поиск на точный список ID (OR-результат).
			$query->set( 's', '' );
			$query->set( 'post__in', $all_ids );
			$query->set( 'orderby', 'post__in' );
		} else {
			// Ничего не найдено ни в заголовках, ни в площадках/категориях — оставляем пустой s (покажет 0 результатов).
			$query->set( 'post__in', array( 0 ) );
		}
		return;
	}

	// Архив города: показываем только события.
	if ( $query->is_tax( 'city' ) ) {
		$query->set( 'post_type', 'event' );
		return;
	}

	// Архив площадок: фильтр ?type= (theater|cinema|museum|concert).
	if ( $query->is_post_type_archive( 'venue' ) ) {
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $type, array( 'theater', 'cinema', 'museum', 'concert' ), true ) ) {
			$query->set(
				'meta_query',
				array(
					array(
						'key'   => '_idpg_venue_type',
						'value' => $type,
					),
				)
			);
		}
		return;
	}

	// Архив афиши: фильтры ?date= и ?is_free=.
	if ( ! $query->is_post_type_archive( 'event' ) ) {
		return;
	}

	$meta_query = array(); // phpcs:ignore WordPress.DB.SlowDBQuery

	$is_free = isset( $_GET['is_free'] ) ? (int) $_GET['is_free'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $is_free ) {
		$meta_query[] = array(
			'key'   => '_idpg_is_free',
			'value' => 'yes',
		);
	}

	// ?sort=popular — сортировка по просмотрам.
	$sort = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'popular' === $sort ) {
		$query->set( 'meta_key', '_idpg_views' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$query->set( 'orderby', 'meta_value_num' );
		$query->set( 'order', 'DESC' );
	}

	$date = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( $date ) {
		$today = gmdate( 'Y-m-d', current_time( 'timestamp' ) );
		$start = '';
		$end   = '';

		switch ( $date ) {
			case 'today':
				$start = $today;
				$end   = $today;
				break;

			case 'tomorrow':
				$start = gmdate( 'Y-m-d', strtotime( '+1 day', current_time( 'timestamp' ) ) );
				$end   = $start;
				break;

			case 'weekend':
				// Пятница — воскресенье ближайших (или текущих) выходных.
				list( $start, $end ) = idpg_core_get_weekend_range();
				break;

			default:
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
					$start = $date;
					$end   = $date;
				}
		}

		if ( $start && $end ) {
			$meta_query[] = array(
				'relation' => 'AND',
				array(
					'key'     => '_idpg_start_date',
					'value'   => $end,
					'compare' => '<=',
					'type'    => 'DATE',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => '_idpg_end_date',
						'value'   => $start,
						'compare' => '>=',
						'type'    => 'DATE',
					),
					array(
						'relation' => 'AND',
						array(
							'key'   => '_idpg_end_date',
							'value' => '',
						),
						array(
							'key'     => '_idpg_start_date',
							'value'   => $start,
							'compare' => '=',
							'type'    => 'DATE',
						),
					),
				),
			);
		}
	}

	if ( ! empty( $meta_query ) ) {
		$query->set( 'meta_query', $meta_query );
	}
}
add_action( 'pre_get_posts', 'idpg_core_pre_get_posts_filters' );

/**
 * Настройки кастомайзера: логотип в секции «Свойства сайта» (title_tagline).
 *
 * Изображение логотипа выбирается базовым контролом WordPress «Логотип»
 * (настройка custom_logo, его использует the_custom_logo()). Наш единственный
 * дополнительный элемент — ползунок:
 * - idpg_logo_width — ширина логотипа, range 50–400 px (высота автоматически
 *   сохраняет пропорции);
 * Live-превью: selective refresh partial для блока .idpg-header__logo.
 *
 * @param WP_Customize_Manager $wp_customize Менеджер кастомайзера.
 */
function idpg_core_customize_register( $wp_customize ) {
	$wp_customize->add_setting(
		'idpg_logo_width',
		array(
			'default'           => 180,
			'sanitize_callback' => 'absint',
			'transport'         => 'postMessage',
		)
	);
	$wp_customize->add_control(
		'idpg_logo_width',
		array(
			'label'       => esc_html__( 'Ширина логотипа', 'idpg_core' ),
			'description' => esc_html__( 'Высота подстраивается автоматически', 'idpg_core' ),
			'section'     => 'title_tagline',
			'type'        => 'range',
			'priority'    => 9,
			'input_attrs' => array(
				'min'  => 50,
				'max'  => 400,
				'step' => 5,
			),
		)
	);

	// Live-превью: перерисовка логотипа в шапке при смене изображения
	// (базовая настройка custom_logo). Ширина в partial НЕ включена: её
	// в реальном времени применяет assets/js/customize-preview.js
	// (CSS-переменная + атрибут), иначе серверный рендер «отбивал» бы
	// drag-значение обратно к сохранённому.
	if ( isset( $wp_customize->selective_refresh ) ) {
		$wp_customize->selective_refresh->add_partial(
			'idpg_logo',
			array(
				'selector'        => '.idpg-header__logo',
				'settings'        => array( 'custom_logo' ),
				'render_callback' => 'idpg_render_logo',
			)
		);
	}
}
add_action( 'customize_register', 'idpg_core_customize_register' );

/**
 * Вывод логотипа шапки.
 *
 * Логика:
 * - Если загружен нативный custom-logo (секция «Свойства сайта», настройка
 *   custom_logo) — выводит его в контейнере .idpg-logo, чтобы ширина из
 *   настройки idpg_logo_width применялась через CSS-переменную и ползунок
 *   кастомайзера влиял на картинку в реальном времени;
 * - Если указан idpg_logo (загружен в прежней версии темы) — выводит <img>
 *   с шириной из idpg_logo_width (обратная совместимость);
 * - Иначе — название сайта.
 *
 * Используется в header.php и как render_callback для selective refresh.
 */
function idpg_render_logo() {
	// Основной путь: нативный custom-logo из Site Identity.
	if ( function_exists( 'has_custom_logo' ) && has_custom_logo() ) {
		echo '<div class="idpg-header__logo idpg-logo">';
		the_custom_logo();
		echo '</div>';
		return;
	}

	// Обратная совместимость: логотип, загруженный ранее через настройку idpg_logo.
	$logo_url = get_theme_mod( 'idpg_logo', '' );

	if ( $logo_url ) {
		$width = max( 50, min( 400, (int) get_theme_mod( 'idpg_logo_width', 180 ) ) );

		// alt: подпись изображения из галереи или название сайта.
		$alt     = get_bloginfo( 'name' );
		$logo_id = attachment_url_to_postid( $logo_url );
		if ( $logo_id ) {
			$alt = get_post_meta( $logo_id, '_wp_attachment_image_alt', true );
			if ( ! $alt ) {
				$alt = get_bloginfo( 'name' );
			}
		}

		echo '<a href="' . esc_url( home_url( '/' ) ) . '" class="idpg-header__logo idpg-logo" rel="home">';
		echo '<img class="idpg-logo__img" src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $alt ) . '" width="' . (int) $width . '">';
		echo '</a>';
		return;
	}

	echo '<a href="' . esc_url( home_url( '/' ) ) . '" class="idpg-header__logo" rel="home">' . esc_html( get_bloginfo( 'name' ) ) . '</a>';
}

/**
 * Скрипт живого превью кастомайзера (логотип).
 */
function idpg_core_customize_preview_js() {
	wp_enqueue_script(
		'idpg_core-customize-preview',
		get_template_directory_uri() . '/assets/js/customize-preview.js',
		array( 'customize-preview', 'jquery' ),
		IDPG_VERSION,
		true
	);
}
add_action( 'customize_preview_init', 'idpg_core_customize_preview_js' );

/**
 * Стили логотипа в шапке (ширина из кастомайзера + адаптив).
 *
 * Выводятся в wp_head, чтобы переопределить дефолтный размер «height: 40px;
 * width: auto» для .idpg-header__logo img из style.css (правило позже по
 * порядку источника). Ширина задаётся через CSS-переменную --idpg-logo-w,
 * её же в реальном времени меняет assets/js/customize-preview.js. Высота —
 * auto, пропорции сохраняются. На мобильных ширина уменьшается на 30 %.
 */
function idpg_core_logo_css() {
	$has_logo    = function_exists( 'has_custom_logo' ) && has_custom_logo();
	$logo_url    = get_theme_mod( 'idpg_logo', '' );

	if ( ! $has_logo && ! $logo_url ) {
		return;
	}

	$width = max( 50, min( 400, (int) get_theme_mod( 'idpg_logo_width', 180 ) ) );
	?>
	<style id="idpg-logo-css">
	.idpg-logo {
		--idpg-logo-w: <?php echo (int) $width; ?>px;
	}
	.idpg-logo img {
		display: inline-block;
		width: var(--idpg-logo-w);
		height: auto;
		max-width: 100%;
	}
	@media (max-width: 767px) {
		/* Шапка в одну строку: логотип уменьшается до 70 % от заданной
		   ширины и никогда не выталкивает кнопку меню за экран. */
		.idpg-header__logo {
			flex-shrink: 1;
			min-width: 0;
			max-width: calc(100% - 64px);
		}
		.idpg-logo img {
			width: calc(var(--idpg-logo-w) * 0.7);
			height: auto;
		}
	}
	</style>
	<?php
}
add_action( 'wp_head', 'idpg_core_logo_css', 20 );

