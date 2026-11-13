<?php
/**
 * Setup and script functions for idpg_core theme
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

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
	// Версия по filemtime — кэш браузера сбрасывается при каждой правке файла.
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

	// Кабинет организатора (/organizer/*): стили каркаса и общая логика
	// разделов. Данные для скриптов передаются data-атрибутами разметки,
	// поэтому wp_localize_script не нужен.
	// DEV-312: вернулиссь organizer.css и organizer.js. Скрипты формы не
	// возвратщаем — форма в сборке нет, они ждут переписания на нативные поля.
	if ( function_exists( 'idpg_core_is_organizer_cabinet' ) && idpg_core_is_organizer_cabinet() ) {
		$org_css_ver = file_exists( get_template_directory() . '/assets/css/organizer.css' )
			? (string) filemtime( get_template_directory() . '/assets/css/organizer.css' )
			: IDPG_VERSION;
		wp_enqueue_style( 'idpg_core-organizer', get_template_directory_uri() . '/assets/css/organizer.css', array( 'idpg_core-style' ), $org_css_ver );
		wp_enqueue_script( 'idpg_core-organizer', get_template_directory_uri() . '/assets/js/organizer.js', array(), IDPG_VERSION, true );
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
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'homeUrl'   => home_url( '/' ),
			'restUrl'   => esc_url_raw( home_url( '/wp-json/idpg/v1' ) ),
			'citySlug'  => $current_city_slug,
		)
	);
	// Те же данные для live-поиска (изолированный хендл)
	wp_localize_script(
		'idpg_core-live-search',
		'idpgData',
		array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'idpg_nonce' ),
			'cityNonce' => wp_create_nonce( 'idpg_set_city' ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
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
