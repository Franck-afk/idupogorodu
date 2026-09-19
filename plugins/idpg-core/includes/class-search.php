<?php
/**
 * Расширенный поиск событий.
 *
 * Единая точка поиска по афише: используется темой (хук pre_get_posts)
 * и REST-эндпоинтом IDPG_REST_API::search_live, чтобы не дублировать
 * одну и ту же логику (s-поиск + события по площадкам + совпадения
 * по названиям таксономий) в двух местах.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Верхний предел количества ID из одного подзапроса расширенного поиска.
 *
 * Защита от неограниченных выборок (posts_per_page => -1) при росте
 * количества событий: страница всё равно показывает не больше 12 записей.
 */
if ( ! defined( 'IDPG_SEARCH_MAX_PER_SOURCE' ) ) {
	define( 'IDPG_SEARCH_MAX_PER_SOURCE', 100 );
}

/**
 * Сервис расширенного поиска событий.
 */
class IDPG_Search {

	/**
	 * Резолвит ID города из слага (или берёт текущий город по умолчанию).
	 *
	 * @param string $slug Слаг города (может быть пустым).
	 *
	 * @return int ID терма таксономии city или 0.
	 */
	public static function resolve_city_id( $slug = '' ) {
		$slug = trim( (string) $slug );

		if ( '' !== $slug ) {
			$term = get_term_by( 'slug', $slug, 'city' );
			if ( $term && ! is_wp_error( $term ) ) {
				return (int) $term->term_id;
			}

			return 0;
		}

		if ( class_exists( 'IDPG_City_Filter' ) ) {
			return (int) IDPG_City_Filter::get_current_city_id();
		}

		return 0;
	}

	/**
	 * Формирует tax_query по городу.
	 *
	 * @param int $city_id ID города.
	 *
	 * @return array
	 */
	public static function city_tax( $city_id ) {
		$city_id  = (int) $city_id;
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
		}

		return $city_tax;
	}

	/**
	 * Уникальные ID событий по расширенному поиску.
	 *
	 * Собирает три набора результатов (OR):
	 * 1) s-поиск по заголовку/контенту события;
	 * 2) площадки (venue) с совпадением в названии → события с этими площадками;
	 * 3) совпадения по названиям таксономий event_category / age_rating → события с ними.
	 *
	 * Город (кроме поиска самих площадок) применяется ко всем подзапросам.
	 *
	 * @param string   $q       Поисковый запрос.
	 * @param int      $city_id ID города (0 — без фильтра по городу).
	 * @param string[] $limits  Лимиты подзапросов: s_events, venues, venue_events, terms, tax_events.
	 *
	 * @return int[] Уникальные ID событий.
	 */
	public static function find_event_ids( $q, $city_id = 0, $limits = array() ) {
		$q = trim( (string) $q );
		if ( mb_strlen( $q ) < 2 ) {
			return array();
		}

		$limits = wp_parse_args(
			$limits,
			array(
				's_events'     => 50,
				'venues'       => 20,
				'venue_events' => IDPG_SEARCH_MAX_PER_SOURCE,
				'terms'        => 10,
				'tax_events'   => IDPG_SEARCH_MAX_PER_SOURCE,
			)
		);

		$city_tax = self::city_tax( $city_id );
		$all_ids  = array();

		// 1) s-поиск (заголовок/контент) — ID собираем отдельно, чтобы сделать OR с остальными.
		$s_ids = get_posts( array(
			'post_type'            => 'event',
			'post_status'          => 'publish',
			's'                    => $q,
			'posts_per_page'       => (int) $limits['s_events'],
			'fields'               => 'ids',
			'tax_query'            => $city_tax, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'idpg_search_extended' => true,
		) );
		$all_ids = array_merge( $all_ids, (array) $s_ids );

		// 2) По площадкам: venue title LIKE %q% → события с этой площадкой.
		$venue_ids = get_posts( array(
			'post_type'            => 'venue',
			'post_status'          => 'publish',
			's'                    => $q,
			'posts_per_page'       => (int) $limits['venues'],
			'fields'               => 'ids',
			'idpg_search_extended' => true,
		) );
		if ( $venue_ids ) {
			$q2 = new WP_Query( array(
				'post_type'            => 'event',
				'post_status'          => 'publish',
				'posts_per_page'       => (int) $limits['venue_events'],
				'tax_query'            => $city_tax, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'meta_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_idpg_venue',
						'value'   => array_map( 'strval', $venue_ids ),
						'compare' => 'IN',
					),
				),
				'fields'               => 'ids',
				'idpg_search_extended' => true,
			) );
			$all_ids = array_merge( $all_ids, (array) $q2->posts );
		}

		// 3) По категориям/возрасту: совпадения названий таксономий.
		$cat_ids = array();
		foreach ( array( 'event_category', 'age_rating' ) as $tax_name ) {
			$terms = get_terms( array(
				'taxonomy'   => $tax_name,
				'hide_empty' => false,
				'search'     => $q,
				'number'     => (int) $limits['terms'],
			) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				foreach ( $terms as $t ) {
					$cat_ids[] = $t->term_id;
				}
			}
		}

		if ( $cat_ids ) {
			if ( $city_tax ) {
				$tax_q = array(
					'relation' => 'AND',
					$city_tax[0],
					array(
						'relation' => 'OR',
						array( 'taxonomy' => 'event_category', 'field' => 'term_id', 'terms' => $cat_ids ),
						array( 'taxonomy' => 'age_rating', 'field' => 'term_id', 'terms' => $cat_ids ),
					),
				);
			} else {
				$tax_q = array(
					'relation' => 'OR',
					array( 'taxonomy' => 'event_category', 'field' => 'term_id', 'terms' => $cat_ids ),
					array( 'taxonomy' => 'age_rating', 'field' => 'term_id', 'terms' => $cat_ids ),
				);
			}

			$q3 = new WP_Query( array(
				'post_type'            => 'event',
				'post_status'          => 'publish',
				'posts_per_page'       => (int) $limits['tax_events'],
				'tax_query'            => $tax_q, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'fields'               => 'ids',
				'idpg_search_extended' => true,
			) );
			$all_ids = array_merge( $all_ids, (array) $q3->posts );
		}

		return array_unique( array_map( 'intval', $all_ids ) );
	}
}