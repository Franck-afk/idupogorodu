<?php
/**
 * Query and filter functions for idpg_core theme
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

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

