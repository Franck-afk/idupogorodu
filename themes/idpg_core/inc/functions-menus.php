<?php
/**
 * Menu-related functions for idpg_core theme.
 *
 * 1a3e3c3f3b353a41 44433d3a463839 343b4f 4030313e424b 41 3c353d4e: 3e423a3b4e47353d3835 41414b3b3e3a, 44383b4c42403046384f 3f433d3a423e32 3c353d4e.
 *
 * @package idpg_core
 */

if ( ! function_exists( 'idpg_core_disable_parent_menu_items' ) ) {
	/**
	 * 1f3e343842353b4c413a3835 3f433d3a424b 3c353d4e 40303734353b3e32 413e314b423839 38 3f3b3e4930343a38
	 *
	 * 1f3e343c353d4b 3c353d4e 41 41414b3b3a303c38 3d30 414240303d38464b 3044384830 38 3f3b3e4930343a38 3032423e3c3042384735413a38 32453e34.
	 *
	 * @param array $items 1d3b353c353d424b 3c353d4e.
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
}
add_filter( 'wp_nav_menu_objects', 'idpg_core_disable_parent_menu_items' );

if ( ! function_exists( 'idpg_core_get_login_url_in_theme' ) ) {
	/**
	 * 123e3732403049303542 URL 414240303d38464b 32453e3430 (414240303d384630 41 page-login.php,
	 * 383d304735 3435443e3b423d4b39 /login/). 1d4336353d 343b4f 44383b4c424030 4443423540-3c353d4e.
	 *
	 * @return string
	 */
	function idpg_core_get_login_url_in_theme() {
		// Performance: Use get_page_by_path first (more efficient than get_posts with meta query)
		$login_page = get_page_by_path( 'login' );
		if ( $login_page ) {
			return trailingslashit( get_permalink( $login_page ) );
		}

		// Fallback: Search for page with specific template
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
}

if ( ! function_exists( 'idpg_core_remove_footer_login_item' ) ) {
	/**
	 * 23313840303542 3f433d3a42 413e314b42384f 3837 4443423540-3c353d4e.
	 *
	 * 12453e34 343e4142433f353d 32 48303f3a35, 3443313b38403e32303d3835 41414b3b3a38 32 3f3e3432303b35 3d35 42403531433542414f.
	 * 1f433d3a42 3e3f403534353b4f3542414f 3f3e URL 414240303d38464b 32453e3430, 3f3e4d423e3c43 4030313e42303542 38 3d30 403e3435,
	 * 3d353730323841383c3e 3e42 413b303330 414240303d38464b (/login/, /vhod/ 38 42.3f.).
	 *
	 * @param array    $items 1d3b353c353d424b 3c353d4e.
	 * @param stdClass $args  104033433c353d424b wp_nav_menu().
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
}
add_filter( 'wp_nav_menu_objects', 'idpg_core_remove_footer_login_item', 20, 2 );
