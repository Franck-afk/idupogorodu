<?php
/**
 * Кастомный Walker для навигации с BEM-разметкой
 *
 * Выводит меню с классами:
 * - .idpg-nav__list (ul)
 * - .idpg-nav__item (li)
 * - .idpg-nav__link (a)
 * - .idpg-nav__submenu (вложенный ul)
 *
 * @package idpg_core
 */

class IDPG_Nav_Walker extends Walker_Nav_Menu {

	/**
	 * Начало вывода списка.
	 *
	 * @param string   $output Используемый буфер.
	 * @param int      $depth  Глубина вложенности.
	 * @param stdClass $args   Аргументы меню.
	 */
	public function start_lvl( &$output, $depth = 0, $args = null ) {
		$output .= '<ul class="idpg-nav__submenu">';
	}

	/**
	 * Начало вывода элемента меню.
	 *
	 * @param string   $output Используемый буфер.
	 * @param WP_Post  $item   Текущий элемент меню.
	 * @param int      $depth  Глубина вложенности.
	 * @param stdClass $args   Аргументы меню.
	 * @param int      $id     ID элемента.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		$classes = empty( $item->classes ) ? array() : (array) $item->classes;

		// Формируем BEM-класс для li
		$li_classes = array( 'idpg-nav__item' );
		if ( in_array( 'menu-item-has-children', $classes, true ) ) {
			$li_classes[] = 'idpg-nav__item--has-children';
		}
		if ( in_array( 'current-menu-item', $classes, true ) ) {
			$li_classes[] = 'idpg-nav__item--current';
		}

		$output .= '<li class="' . esc_attr( implode( ' ', $li_classes ) ) . '">';

		// Атрибуты ссылки
		$atts           = array();
		$atts['title']  = ! empty( $item->attr_title ) ? $item->attr_title : '';
		$atts['target'] = ! empty( $item->target )     ? $item->target     : '';
		$atts['rel']    = ! empty( $item->xfn )        ? $item->xfn        : '';
		$atts['href']   = ! empty( $item->url )        ? $item->url        : '';

		// BEM-класс для ссылки
		$atts['class'] = 'idpg-nav__link';

		// Текущий элемент
		if ( in_array( 'current-menu-item', $classes, true ) ) {
			$atts['aria-current'] = 'page';
		}

		$atts = apply_filters( 'nav_menu_link_attributes', $atts, $item, $args, $depth );

		$attributes = '';
		foreach ( $atts as $attr => $value ) {
			if ( is_scalar( $value ) && '' !== $value ) {
				$value       = ( 'href' === $attr ) ? esc_url( $value ) : esc_attr( $value );
				$attributes .= ' ' . $attr . '="' . $value . '"';
			}
		}

		$item_output  = $args->before;
		$item_output .= '<a' . $attributes . '>';
		$item_output .= $args->link_before . apply_filters( 'the_title', $item->title, $item->ID ) . $args->link_after;

		// Стрелка для пунктов с подменю
		if ( in_array( 'menu-item-has-children', $classes, true ) ) {
			$item_output .= '<svg class="idpg-nav__arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>';
		}

		$item_output .= '</a>';
		$item_output .= $args->after;

		$output .= apply_filters( 'walker_nav_menu_start_el', $item_output, $item, $depth, $args );
	}

	/**
	 * Конец вывода элемента меню.
	 *
	 * @param string   $output Используемый буфер.
	 * @param WP_Post  $item   Текущий элемент меню.
	 * @param int      $depth  Глубина вложенности.
	 * @param stdClass $args   Аргументы меню.
	 */
	public function end_el( &$output, $item, $depth = 0, $args = null ) {
		$output .= '</li>';
	}

	/**
	 * Конец вывода списка.
	 *
	 * @param string   $output Используемый буфер.
	 * @param int      $depth  Глубина вложенности.
	 * @param stdClass $args   Аргументы меню.
	 */
	public function end_lvl( &$output, $depth = 0, $args = null ) {
		$output .= '</ul>';
	}
}
