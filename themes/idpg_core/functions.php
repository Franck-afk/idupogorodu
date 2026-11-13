<?php
/**
 * idpg_core functions and definitions
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package idpg_core
 */

if ( ! defined( 'IDPG_VERSION' ) ) {
	/*
	 * Версия темы. Единый источник для кэш-бастинга ассетов: значение
	 * подставляется в `?ver=` у style.css и скриптов темы.
	 *
	 * ВАЖНО: должна совпадать с заголовком `Version:` в style.css.
	 * 1.0.78: синхронизация после отката DEV-102 (DEV-138). Ранее в дереве
	 * оставалась 1.0.75 при заявленной DEV-84 версии 1.0.77 — версия не росла,
	 * поэтому браузеры отдавали ассеты из кэша.
	 */
	define( 'IDPG_VERSION', '1.0.79' );
}

// Load helper functions
require_once get_template_directory() . '/inc/functions-helpers.php';

// Load menu functions
require_once get_template_directory() . '/inc/functions-menus.php';

// Load setup functions
require_once get_template_directory() . '/inc/functions-setup.php';

// Load page creation functions
require_once get_template_directory() . '/inc/functions-pages.php';

// Load legal document functions
require_once get_template_directory() . '/inc/functions-legal.php';

/**
 * Carbon Fields bootstrap.
 *
 * ПЕРЕНЕСЕНО В ПЛАГИН idpg-core: boot выполняется в idpg-core.php
 * (after_setup_theme, приоритет 5). Закомментировано во избежание
 * конфликта имён функции и двойной инициализации.



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
 * Меню и вспомогательные функции кабинета организатора.
 *
 * Файл делегирует проверку страниц и построение URL классу
 * IDPG_Organizer_URL плагина idpg-core и содержит безопасные
 * заглушки, если плагин неактивен, — поэтому подключается
 * безусловно.
 *
 * DEV-234 удалял файл вместе с ЛК; DEV-312 вернул кабинет в объёме чтения.
 */
require_once get_template_directory() . '/inc/organizer-menu.php';


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

