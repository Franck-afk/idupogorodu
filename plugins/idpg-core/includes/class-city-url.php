<?php
/**
 * Город в URL (механика DVHAB-стиля).
 *
 * - /afisha/{city}/            — афиша города (архив event)
 * - /afisha/{city}/events/{slug}/ — каноническая карточка события
 * - /venues/{city}/            — площадки города
 * - /gorod/{slug}/             — 301 → /afisha/{slug}/
 * - /afisha/, /venues/         — 301 → версия с текущим городом
 * - ?city=…                    — 301 → /afisha/{city}/
 *
 * Rewrite-правила строятся из РЕАЛЬНЫХ слагов городов, поэтому
 * не конфликтуют с пермалинками одиночных событий (/afisha/<slug>/).
 * Кэш слагов сбрасывается при сохранении термина city.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IDPG_City_URL {

	/**
	 * Кастомный query var: slug города из пути.
	 */
	const QVAR = 'idpg_city_path';

	public static function init() {
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'init', array( __CLASS__, 'rewrites' ), 20 );
		add_action( 'pre_get_posts', array( __CLASS__, 'resolve' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'redirects' ), 1 );
		add_filter( 'post_type_link', array( __CLASS__, 'event_permalink' ), 10, 2 );
		add_action( 'wp_head', array( __CLASS__ , 'canonical' ), 20 );
		add_action( 'saved_term', array( __CLASS__, 'maybe_rebuild' ), 10, 3 );
	}

	// =========================================================================
	// ЧПУ-фильтры афиши
	// =========================================================================

	/**
	 * URL фильтра афиши для текущего города.
	 *
	 * @param string $type  today|tomorrow|weekend|free|date|category|base.
	 * @param string $value Значение (для date/category).
	 */
	public static function filter_url( $type, $value = null ) {
		$slug = IDPG_City_Filter::get_current_city_slug();
		$base = '/afisha/' . sanitize_title( $slug ) . '/';

		switch ( $type ) {
			case 'today':
				return home_url( user_trailingslashit( $base . 'today/' ) );
			case 'tomorrow':
				return home_url( user_trailingslashit( $base . 'tomorrow/' ) );
			case 'weekend':
				return home_url( user_trailingslashit( $base . 'weekend/' ) );
			case 'free':
				return home_url( user_trailingslashit( $base . 'free/' ) );
			case 'date':
				return home_url( user_trailingslashit( $base . 'date/' . sanitize_text_field( (string) $value ) . '/' ) );
			case 'category':
				return home_url( user_trailingslashit( $base . 'category/' . sanitize_title( (string) $value ) . '/' ) );
			default:
				return home_url( user_trailingslashit( $base ) );
		}
	}

	// =========================================================================
	// Слаги городов
	// =========================================================================

	/**
	 * Список слагов городов (кэш 12ч).
	 *
	 * @return string[]
	 */
	public static function city_slugs() {
		$slugs = get_transient( 'idpg_city_slugs' );

		if ( is_array( $slugs ) && $slugs ) {
			return $slugs;
		}

		$terms = get_terms( array(
			'taxonomy'   => 'city',
			'hide_empty' => false,
			'fields'     => 'slugs',
		) );

		$slugs = ( is_wp_error( $terms ) || ! $terms ) ? array() : array_values( $terms );

		set_transient( 'idpg_city_slugs', $slugs, 12 * HOUR_IN_SECONDS );

		return $slugs;
	}

	/**
	 * Regex-фрагмент из слагов: (?:izhevsk|sarapul|…)
	 *
	 * @return string
	 */
	private static function slugs_regex() {
		$slugs = self::city_slugs();

		if ( ! $slugs ) {
			return '';
		}

		return '(?:' . implode( '|', array_map( 'preg_quote', $slugs ) ) . ')';
	}

	/**
	 * Пересбор правил при изменении термина city.
	 */
	public static function maybe_rebuild( $term_id, $tt_id, $taxonomy ) {
		if ( 'city' !== $taxonomy ) {
			return;
		}
		delete_transient( 'idpg_city_slugs' );
		flush_rewrite_rules( false );
	}

	// =========================================================================
	// Rewrite + query vars
	// =========================================================================

	public static function query_vars( $vars ) {
		$vars[] = self::QVAR;
		$vars[] = 'idpg_fd'; // today|tomorrow|weekend|Y-m-d.
		$vars[] = 'idpg_fc'; // slug категории.
		$vars[] = 'idpg_ff'; // 1 = только бесплатные.
		return $vars;
	}

	public static function rewrites() {
		$slugs = self::city_slugs();

		if ( ! $slugs ) {
			return;
		}

		$c  = '(' . implode( '|', array_map( 'preg_quote', $slugs ) ) . ')'; // Город — группа 1.
		$pt = 'index.php?post_type=event&' . self::QVAR . '=$matches[1]';

		add_rewrite_rule(
			'^venues/' . $c . '/page/([0-9]{1,})/?$',
			'index.php?post_type=venue&' . self::QVAR . '=$matches[1]&paged=$matches[2]',
			'top'
		);
		add_rewrite_rule(
			'^venues/' . $c . '/?$',
			'index.php?post_type=venue&' . self::QVAR . '=$matches[1]',
			'top'
		);

		add_rewrite_rule(
			'^afisha/' . $c . '/page/([0-9]{1,})/?$',
			$pt . '&paged=$matches[2]',
			'top'
		);

		add_rewrite_rule(
			'^afisha/' . $c . '/?$',
			$pt,
			'top'
		);

		add_rewrite_rule(
			'^afisha/' . $c . '/events/([^/]+)/?$',
			$pt . '&name=$matches[2]',
			'top'
		);

		// Поиск по событиям города: /afisha/{city}/search/?s=… (GET 's' — нативный).
		add_rewrite_rule(
			'^afisha/' . $c . '/search/?$',
			$pt,
			'top'
		);

		// ─── ЧПУ-фильтры: пресеты дат, бесплатные, конкретная дата, категория ───
		foreach ( array( 'today', 'tomorrow', 'weekend' ) as $preset ) {
			add_rewrite_rule(
				'^afisha/' . $c . '/' . $preset . '/page/([0-9]{1,})/?$',
				$pt . '&idpg_fd=' . $preset . '&paged=$matches[2]',
				'top'
			);
			add_rewrite_rule(
				'^afisha/' . $c . '/' . $preset . '/?$',
				$pt . '&idpg_fd=' . $preset,
				'top'
			);
		}

		add_rewrite_rule(
			'^afisha/' . $c . '/free/page/([0-9]{1,})/?$',
			$pt . '&idpg_ff=1&paged=$matches[2]',
			'top'
		);
		add_rewrite_rule(
			'^afisha/' . $c . '/free/?$',
			$pt . '&idpg_ff=1',
			'top'
		);

		add_rewrite_rule(
			'^afisha/' . $c . '/date/([0-9]{4}-[0-9]{2}-[0-9]{2})/?$',
			$pt . '&idpg_fd=$matches[2]',
			'top'
		);

		add_rewrite_rule(
			'^afisha/' . $c . '/category/([^/]+)/page/([0-9]{1,})/?$',
			$pt . '&idpg_fc=$matches[2]&paged=$matches[3]',
			'top'
		);
		add_rewrite_rule(
			'^afisha/' . $c . '/category/([^/]+)/?$',
			$pt . '&idpg_fc=$matches[2]',
			'top'
		);
	}

	// =========================================================================
	// Разбор запроса
	// =========================================================================

	public static function resolve( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$slug = sanitize_title( (string) get_query_var( self::QVAR ) );

		if ( '' === $slug ) {
			return;
		}

		$term = get_term_by( 'slug', $slug, 'city' );

		if ( ! $term || is_wp_error( $term ) ) {
			$query->set_404();
			return;
		}

		// Одиночное событие с городом в URL: проверяем принадлежность.
		if ( $query->is_singular( 'event' ) ) {
			$post_cities = wp_get_post_terms( (int) $query->get( 'p' ) ? (int) $query->get( 'p' ) : 0, 'city', array( 'fields' => 'slugs' ) );

			// p может быть не заполнен на этом этапе — берём по name позже (redirects).
			if ( empty( $post_cities ) ) {
				return; // Проверка принадлежности — в redirects() по канону.
			}

			if ( ! in_array( $slug, $post_cities, true ) ) {
				$GLOBALS['idpg_city_mismatch'] = true;
			}
			return;
		}

		// Архивы: применяем городской tax_query.
		$tax = $query->get( 'tax_query' );
		$tax = is_array( $tax ) ? $tax : array();
		$tax[] = array(
			'taxonomy' => 'city',
			'field'    => 'slug',
			'terms'    => $slug,
		);
		$query->set( 'tax_query', $tax ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query

		// ─── ЧПУ-фильтры: категория ───
		$idpg_fc = sanitize_title( (string) $query->get( 'idpg_fc' ) );
		if ( $idpg_fc && get_term_by( 'slug', $idpg_fc, 'event_category' ) ) {
			$tax[] = array(
				'taxonomy' => 'event_category',
				'field'    => 'slug',
				'terms'    => $idpg_fc,
			);
			$query->set( 'tax_query', $tax );
		}

		// ─── ЧПУ-фильтры: бесплатные ───
		if ( '1' === (string) $query->get( 'idpg_ff' ) ) {
			$mq = $query->get( 'meta_query' );
			$mq = is_array( $mq ) ? $mq : array();
			$mq[] = array(
				'key'   => '_idpg_is_free',
				'value' => 'yes',
			);
			$query->set( 'meta_query', $mq ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		// ─── ЧПУ-фильтры: даты (та же семантика, что у ?date= в functions.php) ───
		$fd = sanitize_text_field( (string) $query->get( 'idpg_fd' ) );

		if ( ! $fd ) {
			return;
		}

		$today = gmdate( 'Y-m-d', current_time( 'timestamp' ) );
		$s     = '';
		$e     = '';

		switch ( $fd ) {
			case 'today':
				$s = $e = $today;
				break;
			case 'tomorrow':
				$s = $e = gmdate( 'Y-m-d', strtotime( '+1 day', current_time( 'timestamp' ) ) );
				break;
			case 'weekend':
				list( $s, $e ) = idpg_core_get_weekend_range();
				break;
			default:
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fd ) ) {
					$s = $e = $fd;
				}
		}

		if ( ! $s || ! $e ) {
			return;
		}

		$query->set( 'meta_query', array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'AND',
			array(
				'key'     => '_idpg_start_date',
				'value'   => $e,
				'compare' => '<=',
				'type'    => 'DATE',
			),
			array(
				'relation' => 'OR',
				array(
					'key'     => '_idpg_end_date',
					'value'   => $s,
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
						'value'   => $s,
						'compare' => '=',
						'type'    => 'DATE',
					),
				),
 			),
 		) );
 	}

 	// =========================================================================
 	// URL-хелперы
 	// =========================================================================

	/**
	 * Slug первого города события.
	 */
	public static function event_city_slug( $post_id ) {
		$slugs = wp_get_post_terms( $post_id, 'city', array( 'fields' => 'slugs' ) );

		return ( $slugs && ! is_wp_error( $slugs ) ) ? $slugs[0] : '';
	}

	/**
	 * URL афиши города. Текущий город — если слаг не задан.
	 *
	 * @param string|null $slug    Slug города (пусто = текущий).
	 * @param bool        $preserve Сохранить текущие GET-параметры (date/sort/is_free).
	 */
	public static function afisha_url( $slug = null, $preserve = false ) {
		$slug = $slug ? sanitize_title( $slug ) : IDPG_City_Filter::get_current_city_slug();

		$url = home_url( user_trailingslashit( '/afisha/' . $slug . '/' ) );

		if ( $preserve && ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$get = (array) $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			unset( $get['city'] );
			if ( $get ) {
				$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $get ) ), $url );
			}
		}

		return $url;
	}

	/**
	 * URL площадок города.
	 */
	public static function venues_url( $slug = null ) {
		$slug = $slug ? sanitize_title( $slug ) : IDPG_City_Filter::get_current_city_slug();

		return home_url( user_trailingslashit( '/venues/' . $slug . '/' ) );
	}

	/**
	 * Канонический пермалинк события с городом в пути.
	 *
	 * Callback для post_type_link.
	 *
	 * @param string  $permalink Прежний URL.
	 * @param WP_Post $post      Пост.
	 * @return string
	 */
	public static function event_permalink( $permalink, $post ) {
		if ( ! $post instanceof WP_Post || 'event' !== $post->post_type ) {
			return $permalink;
		}

		// Уже новый формат — не дублируем сегмент.
		if ( false !== strpos( $permalink, '/events/' ) ) {
			return $permalink;
		}

		$slug = self::event_city_slug( $post->ID );

		// Без города остаётся прежний формат (редирект не зациклится).
		if ( ! $slug ) {
			return $permalink;
		}

		return home_url(
			user_trailingslashit( '/afisha/' . $slug . '/events/' . $post->post_name . '/' )
		);
	}

	/**
	 * Канонический URL карточки события.
	 */
	public static function event_url( $post_id ) {
		$slug = self::event_city_slug( $post_id );

		if ( ! $slug ) {
			return get_permalink( $post_id ); // Без города — старый формат.
		}

		return home_url( user_trailingslashit( '/afisha/' . $slug . '/events/' . get_post_field( 'post_name', $post_id ) . '/' ) );
	}

	// =========================================================================
	// Редиректы и канонизация
	// =========================================================================

	private static function should_run() {
		return ! is_admin()
			&& ! wp_doing_ajax()
			&& ! wp_doing_cron()
			&& ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST );
	}

	public static function redirects() {
		if ( ! self::should_run() || is_feed() ) {
			return;
		}

		global $wp_query;

		$path_city = (string) get_query_var( self::QVAR );

		// 1) Одиночное событие БЕЗ города в URL → канонический URL с городом.
		if ( is_singular( 'event' ) && '' === $path_city ) {
			$target = self::event_url( get_the_ID() );

			if ( untrailingslashit( $target ) !== untrailingslashit( home_url( $_SERVER['REQUEST_URI'] ?? '' ) ) ) {
				wp_safe_redirect( $target, 301 );
				exit;
			}
			return;
		}

		// 2) Событие открыто НЕ в своём городе → на его город.
		if ( is_singular( 'event' ) && ! empty( $GLOBALS['idpg_city_mismatch'] ) ) {
			wp_safe_redirect( self::event_url( get_the_ID() ), 301 );
			exit;
		}

		// 3) Старый архив города /gorod/{slug}/ → /afisha/{slug}/.
		if ( is_tax( 'city' ) ) {
			$slug = (string) get_query_var( 'city' );

			if ( $slug && in_array( $slug, self::city_slugs(), true ) ) {
				wp_safe_redirect( home_url( user_trailingslashit( '/afisha/' . $slug . '/' ) ), 301 );
				exit;
			}
		}

		// 3.5) Легаси ?date= / ?is_free= на афише → ЧПУ-адреса.
		if ( is_post_type_archive( 'event' ) ) {
			$legacy_date = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$legacy_free = isset( $_GET['is_free'] ) ? (int) $_GET['is_free'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( $legacy_date && '' === $path_city ) {
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $legacy_date ) ) {
					$target = home_url( user_trailingslashit( '/afisha/' . IDPG_City_Filter::get_current_city_slug() . '/date/' . $legacy_date . '/' ) );
					wp_safe_redirect( $target, 301 );
					exit;
				}
				if ( in_array( $legacy_date, array( 'today', 'tomorrow', 'weekend' ), true ) ) {
					$target = home_url( user_trailingslashit( '/afisha/' . IDPG_City_Filter::get_current_city_slug() . '/' . $legacy_date . '/' ) );
					wp_safe_redirect( $target, 301 );
					exit;
				}
			}

			if ( $legacy_free && '' === $path_city ) {
				wp_safe_redirect( self::filter_url( 'free' ), 301 );
				exit;
			}
		}

 		// 4) Голая /afisha/ → версия с текущим городом (+сохраняем GET).
		if ( is_post_type_archive( 'event' ) && '' === $path_city ) {
			$target = self::afisha_url( null, true );

			if ( untrailingslashit( $target ) !== untrailingslashit( home_url( $_SERVER['REQUEST_URI'] ?? '' ) ) ) {
				wp_safe_redirect( $target, 301 );
				exit;
			}
		}

		// 5) Голая /venues/ → /venues/{текущий город}/ (+сохраняем ?type= фильтра).
		if ( is_post_type_archive( 'venue' ) && '' === $path_city ) {
			$target = self::venues_url();

			$v_type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( in_array( $v_type, array( 'theater', 'cinema', 'museum', 'concert' ), true ) ) {
				$target = add_query_arg( 'type', $v_type, $target );
			}

			if ( untrailingslashit( $target ) !== untrailingslashit( home_url( $_SERVER['REQUEST_URI'] ?? '' ) ) ) {
				wp_safe_redirect( $target, 301 );
				exit;
			}
		}

		// 6) ?city=… где угодно → /afisha/{city}/ (или чистый URL для неизвестного).
		if ( isset( $_GET['city'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$cs = sanitize_title( wp_unslash( $_GET['city'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			$target = ( $cs && in_array( $cs, self::city_slugs(), true ) )
				? home_url( user_trailingslashit( '/afisha/' . $cs . '/' ) )
				: remove_query_arg( 'city' );

			wp_safe_redirect( $target, 301 );
			exit;
		}
	}

	/**
	 * Канонический URL для страниц с городом в пути.
	 */
	public static function canonical() {
		if ( ! self::should_run() ) {
			return;
		}

		$path_city = (string) get_query_var( self::QVAR );

		if ( '' === $path_city ) {
			return;
		}

		// Не конфликтуем с Yoast SEO: canonical для страниц с городом выводит он.
		if ( defined( 'WPSEO_VERSION' ) ) {
			return;
		}

		printf( '<link rel="canonical" href="%s">' . "\n", esc_url( home_url( $GLOBALS['wp']->request ? '/' . ltrim( $GLOBALS['wp']->request, '/' ) : '/' ) ) );
	}
}

IDPG_City_URL::init();
