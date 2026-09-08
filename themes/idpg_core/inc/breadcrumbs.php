<?php
/**
 * Хлебные крошки темы idpg_core.
 *
 * Если установлен и включён Yoast SEO — выводятся его крошки
 * (тема подключает их через yoast_breadcrumb и фильтр
 * wpseo_breadcrumbs_enabled). Иначе — собственная генерация
 * с микроразметкой schema.org BreadcrumbList.
 *
 * @package idpg_core
 */

if ( ! function_exists( 'idpg_core_breadcrumbs' ) ) {

	/**
	 * Возвращает HTML хлебных крошек текущей страницы.
	 *
	 * @return string
	 */
	function idpg_core_breadcrumbs() {
		if ( is_front_page() ) {
			return '';
		}

		$label = esc_attr__( 'Хлебные крошки', 'idpg_core' );

		/*
		 * Поддержка Rank Math SEO.
		 *
		 * Rank Math — целевой SEO-плагин проекта: если активен, выводим
		 * его крошки (rank_math_the_breadcrumbs) с BreadcrumbList-разметкой.
		 * Тема лишь оборачивает вывод в свою навигационную обёртку.
		 */
		if ( function_exists( 'rank_math_the_breadcrumbs' ) ) {
			ob_start();
			rank_math_the_breadcrumbs( array(
				'wrap_before' => '<nav class="idpg-breadcrumbs" aria-label="' . $label . '"><ol class="idpg-breadcrumbs__list">',
				'wrap_after'  => '</ol></nav>',
			) );
			$idpg_rm_html = trim( (string) ob_get_clean() );

			// Пустой вывод = крошки Rank Math выключены в настройках — идём дальше.
			if ( '' !== trim( (string) wp_strip_all_tags( $idpg_rm_html ) ) || false !== strpos( $idpg_rm_html, '<a' ) ) {
				return $idpg_rm_html;
			}
		}

		/*
		 * Поддержка Yoast SEO.
		 *
		 * Цепочка совместимости с версиями плагина:
		 * 1. классический шаблонный тег yoast_breadcrumb() (Yoast до ~v21);
		 * 2. шорткод [wpseo_breadcrumb] (все современные версии Yoast).
		 *
		 * Вывод оборачивается в буфер: если Yoast не отдал разметку
		 * (функция/шорткод есть, но крошки выключены в настройках
		 * или цепочка пуста) — молча переходим к собственной генерации.
		 */
		if ( function_exists( 'yoast_breadcrumb' ) || shortcode_exists( 'wpseo_breadcrumb' ) ) {
			ob_start();

			if ( function_exists( 'yoast_breadcrumb' ) ) {
				yoast_breadcrumb(
					'<nav class="idpg-breadcrumbs" aria-label="' . $label . '">',
					'</nav>'
				);
			} else {
				echo '<nav class="idpg-breadcrumbs" aria-label="' . $label . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
				echo do_shortcode( '[wpseo_breadcrumb]' ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo '</nav>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}

			$idpg_yoast_html = trim( (string) ob_get_clean() );

			if ( '' !== $idpg_yoast_html ) {
				// Проверяем реальное содержимое внутри обёртки: если Yoast
				// вывел пустой <nav></nav> (крошки выключены в настройках),
				// считаем вывод пустым и переходим к своим крошкам.
				$idpg_inner = trim( (string) preg_replace( '/^<nav[^>]*>/', '', $idpg_yoast_html ) );
				$idpg_inner = trim( (string) preg_replace( '/<\/nav>\s*$/', '', $idpg_inner ) );

				if ( '' !== trim( (string) wp_strip_all_tags( $idpg_inner ) ) || false !== strpos( $idpg_inner, '<a' ) ) {
					if ( 0 !== strpos( $idpg_yoast_html, '<nav' ) ) {
						// Yoast что-то вывел, но без нашей обёртки — добавляем её сами.
						$idpg_yoast_html = '<nav class="idpg-breadcrumbs" aria-label="' . $label . '">' . $idpg_yoast_html . '</nav>';
					}
					return $idpg_yoast_html;
				}
			}
		}

		$items = array(
			array( home_url( '/' ), __( 'Главная', 'idpg_core' ) ),
		);

		if ( is_post_type_archive( 'event' ) ) {
			$items[] = array( '', __( 'Афиша', 'idpg_core' ) );
		} elseif ( is_post_type_archive( 'venue' ) ) {
			$items[] = array( '', __( 'Площадки', 'idpg_core' ) );
		} elseif ( is_tax( 'city' ) || is_tax( 'event_category' ) || is_tax( 'age_rating' ) ) {
			$items[] = array( get_post_type_archive_link( 'event' ), __( 'Афиша', 'idpg_core' ) );
			$items[] = array( '', single_term_title( '', false ) );
		} elseif ( is_tax() ) {
			$items[] = array( '', single_term_title( '', false ) );
		} elseif ( is_singular( 'event' ) ) {
			$items[] = array( get_post_type_archive_link( 'event' ), __( 'Афиша', 'idpg_core' ) );
			$idpg_event_terms = get_the_terms( get_the_ID(), 'event_category' );
			if ( $idpg_event_terms && ! is_wp_error( $idpg_event_terms ) ) {
				$idpg_event_term = reset( $idpg_event_terms );
				$idpg_event_link = get_term_link( $idpg_event_term );
				if ( ! is_wp_error( $idpg_event_link ) ) {
					$items[] = array( $idpg_event_link, $idpg_event_term->name );
				}
			}
			$items[] = array( '', get_the_title() );
		} elseif ( is_singular( 'venue' ) ) {
			$items[] = array( get_post_type_archive_link( 'venue' ), __( 'Площадки', 'idpg_core' ) );
			$items[] = array( '', get_the_title() );
		} elseif ( is_page() ) {
			$idpg_ancestors = array_reverse( get_post_ancestors( get_the_ID() ) );
			foreach ( $idpg_ancestors as $idpg_ancestor ) {
				$items[] = array( get_permalink( $idpg_ancestor ), get_the_title( $idpg_ancestor ) );
			}
			$items[] = array( '', get_the_title() );
		} elseif ( is_singular( 'post' ) ) {
			$idpg_post_cats = get_the_category();
			if ( $idpg_post_cats && ! is_wp_error( $idpg_post_cats ) ) {
				$items[] = array( get_category_link( $idpg_post_cats[0] ), $idpg_post_cats[0]->name );
			}
			$items[] = array( '', get_the_title() );
		} elseif ( is_singular() ) {
			$items[] = array( '', get_the_title() );
		} elseif ( is_search() ) {
			$items[] = array( '', sprintf( __( 'Поиск: %s', 'idpg_core' ), get_search_query() ) );
		} elseif ( is_404() ) {
			$items[] = array( '', __( 'Ошибка 404', 'idpg_core' ) );
		} elseif ( is_author() ) {
			$items[] = array( '', sprintf( __( 'Автор: %s', 'idpg_core' ), get_the_author() ) );
		} elseif ( is_date() ) {
			$items[] = array( '', __( 'Архив по дате', 'idpg_core' ) );
		} elseif ( is_home() ) {
			$items[] = array( '', __( 'Блог', 'idpg_core' ) );
		} elseif ( is_archive() ) {
			$items[] = array( '', wp_strip_all_tags( get_the_archive_title() ) );
		}

		if ( count( $items ) < 2 ) {
			return '';
		}

		$idpg_last = count( $items ) - 1;
		$html      = '<nav class="idpg-breadcrumbs" aria-label="' . $label . '">';
		$html     .= '<ol class="idpg-breadcrumbs__list" itemscope itemtype="https://schema.org/BreadcrumbList">';

		foreach ( $items as $idpg_index => $idpg_item ) {
			list( $idpg_url, $idpg_text ) = $idpg_item;

			$html .= '<li class="idpg-breadcrumbs__item' . ( $idpg_index === $idpg_last ? ' idpg-breadcrumbs__item--current' : '' ) . '" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
			$html .= '<meta itemprop="position" content="' . ( $idpg_index + 1 ) . '">';

			if ( $idpg_url && $idpg_index < $idpg_last ) {
				$html .= '<a class="idpg-breadcrumbs__link" itemprop="item" href="' . esc_url( $idpg_url ) . '"><span itemprop="name">' . esc_html( $idpg_text ) . '</span></a>';
			} else {
				$html .= '<span class="idpg-breadcrumbs__current" itemprop="name">' . esc_html( $idpg_text ) . '</span>';
			}

			$html .= '</li>';

			if ( $idpg_index < $idpg_last ) {
				$html .= '<li class="idpg-breadcrumbs__sep" aria-hidden="true">/</li>';
			}
		}

		$html .= '</ol></nav>';

		return $html;
	}
}