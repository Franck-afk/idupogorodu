<?php
/**
 * Тонкий фасад гео-логики.
 *
 * Оркестрирует существующие модули без их слияния:
 * - IDPG_City_Filter — определение текущего города
 * - IDPG_Meta — ensure city/date
 *
 * Не дублирует логику, только делегирует.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IDPG_GeoService {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Текущий город (ID).
	 */
	public function get_current_city_id() {
		if ( class_exists( 'IDPG_City_Filter' ) && method_exists( 'IDPG_City_Filter', 'get_current_city_id' ) ) {
			return (int) IDPG_City_Filter::get_current_city_id();
		}
		return (int) defined( 'IDPG_DEFAULT_CITY_ID' ) ? IDPG_DEFAULT_CITY_ID : 15;
	}

	/**
	 * Текущий город (WP_Term|null).
	 */
	public function get_current_city() {
		$id = $this->get_current_city_id();
		$term = get_term( $id, 'city' );
		return ( $term && ! is_wp_error( $term ) ) ? $term : null;
	}


}
