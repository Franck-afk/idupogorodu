<?php
/**
 * Helper functions for idpg_core theme.
 *
 * 1a3e3c3f3b353a41 44433d3a463839 343b4f 32413f3e3c3e333042353b4c3d4b45 44433d3a463839: 3a4d48-4030373e324b35 44433d3a463838, 3f403e3235403a30 414349354142323e32303d384f 3a3b3041413e32, URL 33353d354030423e404b.
 *
 * @package idpg_core
 */

if ( ! function_exists( 'idpg_core_class_exists' ) ) {
	/**
	 * Cache for class_exists checks to avoid repeated autoloading.
	 * Performance: Reduces redundant class_exists() calls by caching results in a static array.
	 *
	 * @param string $class_name Class name to check.
	 * @return bool Whether the class exists.
	 */
	function idpg_core_class_exists( $class_name ) {
		static $class_cache = array();
		
		if ( ! isset( $class_cache[ $class_name ] ) ) {
			$class_cache[ $class_name ] = class_exists( $class_name );
		}
		
		return $class_cache[ $class_name ];
	}
}

if ( ! function_exists( 'idpg_core_get_afisha_url' ) ) {
	/**
	 * Get the afisha (events archive) URL.
	 * Uses IDPG_City_URL::afisha_url() if available, otherwise falls back to get_post_type_archive_link.
	 * This consolidates duplicate URL logic across the theme.
	 *
	 * @return string The afisha URL.
	 */
	function idpg_core_get_afisha_url() {
		if ( idpg_core_class_exists( 'IDPG_City_URL' ) ) {
			return IDPG_City_URL::afisha_url();
		}
		return get_post_type_archive_link( 'event' );
	}
}

if ( ! function_exists( 'idpg_core_get_organizer_url' ) ) {
	/**
	 * Get the organizer cabinet base URL.
	 * Uses IDPG_Organizer_URL::section_url() if available, otherwise falls back to home_url('/organizer/').
	 * This consolidates duplicate organizer URL logic across the theme.
	 *
	 * @param string $section Optional section slug.
	 * @return string The organizer cabinet URL.
	 */
	function idpg_core_get_organizer_url( $section = '' ) {
		if ( class_exists( 'IDPG_Organizer_URL' ) && method_exists( 'IDPG_Organizer_URL', 'section_url' ) ) {
			return IDPG_Organizer_URL::section_url( $section );
		}
		return home_url( '/organizer/' . ( $section ? $section . '/' : '' ) );
	}
}
