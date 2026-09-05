<?php
/**
 * Избранное: хранение в user meta, REST API, счётчик, страница /lk/favorites/.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IDPG_Favorites {

	const META_KEY   = '_idpg_favorites';
	const REST_NS    = 'idpg/v1';
	const PAGE_QVAR  = 'idpg_favorites_page';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'init', array( __CLASS__, 'rewrites' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 30 );
	}

	// =========================================================================
	// Данные
	// =========================================================================

	public static function get_ids( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();

		if ( ! $user_id ) {
			return array();
		}

		$ids = get_user_meta( $user_id, self::META_KEY, true );

		return is_array( $ids ) ? array_values( array_map( 'absint', array_filter( $ids ) ) ) : array();
	}

	public static function count( $user_id = 0 ) {
		return count( self::get_ids( $user_id ) );
	}

	private static function save( $ids, $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		update_user_meta( $user_id, self::META_KEY, array_values( array_unique( array_map( 'absint', $ids ) ) ) );
	}

	// =========================================================================
	// REST API
	// =========================================================================

	public static function register_routes() {
		register_rest_route( self::REST_NS, '/favorites/add', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'rest_add' ),
			'permission_callback' => static fn() => is_user_logged_in(),
			'args'                => array(
				'event_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( self::REST_NS, '/favorites/remove', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'rest_remove' ),
			'permission_callback' => static fn() => is_user_logged_in(),
			'args'                => array(
				'event_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( self::REST_NS, '/favorites', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'rest_list' ),
			'permission_callback' => static fn() => is_user_logged_in(),
		) );
	}

	private static function rest_event_payload( $event_id ) {
		return array(
			'id'    => $event_id,
			'title' => html_entity_decode( get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' ),
			'url'   => IDPG_City_URL::event_url( $event_id ),
		);
	}

	public static function rest_add( $request ) {
		$event_id = (int) $request->get_param( 'event_id' );

		if ( ! $event_id || 'event' !== get_post_type( $event_id ) || 'publish' !== get_post_status( $event_id ) ) {
			return new WP_Error( 'idpg_fav_invalid', __( 'Событие не найдено.', 'idpg-core' ), array( 'status' => 404 ) );
		}

		$ids = self::get_ids();

		if ( ! in_array( $event_id, $ids, true ) ) {
			$ids[] = $event_id;
			self::save( $ids );
		}

		return rest_ensure_response( array(
			'added' => true,
			'count' => count( $ids ),
			'event' => self::rest_event_payload( $event_id ),
		) );
	}

	public static function rest_remove( $request ) {
		$event_id = (int) $request->get_param( 'event_id' );
		$ids      = self::get_ids();
		$ids      = array_values( array_diff( $ids, array( $event_id ) ) );

		self::save( $ids );

		return rest_ensure_response( array(
			'removed' => true,
			'count'   => count( $ids ),
			'event'   => array( 'id' => $event_id ),
		) );
	}

	public static function rest_list( $request ) {
		$ids     = self::get_ids();
		$events  = array();

		foreach ( $ids as $id ) {
			if ( 'event' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
				$events[] = self::rest_event_payload( $id );
			}
		}

		return rest_ensure_response( array( 'count' => count( $events ), 'events' => $events ) );
	}

	// =========================================================================
	// Фронтенд: ассеты, кнопка-состояние
	// =========================================================================

	public static function assets() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		wp_enqueue_style(
			'idpg-favorites',
			IDPG_CORE_URL . 'assets/css/favorites.css',
			array(),
			IDPG_CORE_VERSION
		);

		wp_enqueue_script(
			'idpg-favorites',
			IDPG_CORE_URL . 'assets/js/favorites.js',
			array(),
			IDPG_CORE_VERSION,
			true
		);

		wp_localize_script( 'idpg-favorites', 'idpgFav', array(
			'restUrl' => esc_url_raw( rest_url( self::REST_NS . '/favorites' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'count'   => self::count(),
			'ids'     => self::get_ids(),
		) );
	}

	/**
	 * Кнопка сердца для карточки события.
	 */
	public static function button( $event_id ) {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$active = in_array( (int) $event_id, self::get_ids(), true );

		printf(
			'<button type="button" class="idpg-fav-btn%1$s" data-fav-id="%2$d" aria-label="%3$s"><span class="idpg-fav-btn__icon" aria-hidden="true">♥</span></button>',
			$active ? ' is-active' : '',
			(int) $event_id,
			esc_attr__( 'В избранное', 'idpg-core' )
		);
	}

	// =========================================================================
	// Страница /lk/favorites/
	// =========================================================================

	public static function rewrites() {
		add_rewrite_rule( '^lk/favorites/?$', 'index.php?' . self::PAGE_QVAR . '=1', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::PAGE_QVAR;
		return $vars;
	}

	public static function template( $template ) {
		if ( ! get_query_var( self::PAGE_QVAR ) ) {
			return $template;
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( home_url( '/login/' ) );
			exit;
		}

		$fav_template = locate_template( 'page-favorites.php' );

		return $fav_template ? $fav_template : $template;
	}
}

IDPG_Favorites::init();
