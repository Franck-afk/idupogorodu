<?php
/**
 * Customizer functions for idpg_core theme
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

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
