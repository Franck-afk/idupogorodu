<?php
/**
 * REST API для фронтенда.
 *
 * Регистрирует маршруты для создания/редактирования событий
 * и получения статистики организатора.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_REST_API
 *
 * Неймспейс: idpg/v1
 * Обязательная проверка: nonce (X-WP-Nonce) + capabilities.
 */
class IDPG_REST_API {

	/**
	 * Синглтон.
	 *
	 * @var IDPG_REST_API|null
	 */
	private static $instance = null;

	/**
	 * Префикс неймспейса API.
	 *
	 * @var string
	 */
	const NAMESPACE = 'idpg/v1';

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_REST_API
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор.
	 */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Регистрация REST-маршрутов.
	 */
	public function register_routes() {
		// === EVENTS ===

		// GET /events — публичный список с фильтрацией.
		register_rest_route(
			self::NAMESPACE,
			'/events',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_events' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'city'     => array( 'required' => false, 'sanitize_callback' => 'absint' ),
					'category' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
					'date_from' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'date_to'   => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'search'    => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'page'      => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 1 ),
					'per_page'  => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 12 ),
				),
			)
		);

		// === VENUES ===

		// GET /venues — публичный список площадок.
		register_rest_route(
			self::NAMESPACE,
			'/venues',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_venues' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'city'      => array( 'required' => false, 'sanitize_callback' => 'absint' ),
					'search'    => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'page'      => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 1 ),
					'per_page'  => array( 'required' => false, 'sanitize_callback' => 'absint', 'default' => 12 ),
				),
			)
		);

		// GET /venues/(?P<id>\d+) — одна площадка.
		register_rest_route(
			self::NAMESPACE,
			'/venues/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_venue' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		// POST /venues/create — создание площадки.
		register_rest_route(
			self::NAMESPACE,
			'/venues/create',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_venue' ),
				'permission_callback' => array( $this, 'can_manage_events' ),
				'args'                => $this->get_venue_schema(),
			)
		);

		// PUT /venues/(?P<id>\d+) — редактирование площадки.
		register_rest_route(
			self::NAMESPACE,
			'/venues/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_venue' ),
				'permission_callback' => array( $this, 'can_manage_events' ),
				'args'                => array_merge(
					$this->get_venue_schema(),
					array(
						'id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					)
				),
			)
		);

		// === TAXONOMIES ===

		// GET /cities — список городов.
		register_rest_route(
			self::NAMESPACE,
			'/cities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_terms' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'taxonomy' => array( 'required' => false, 'default' => 'city', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		// GET /categories — список категорий событий.
		register_rest_route(
			self::NAMESPACE,
			'/categories',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_terms' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'taxonomy' => array( 'required' => false, 'default' => 'event_category', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		// GET /age-ratings — список возрастных рейтингов.
		register_rest_route(
			self::NAMESPACE,
			'/age-ratings',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_terms' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'taxonomy' => array( 'required' => false, 'default' => 'age_rating', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		// GET /event-dates — даты с событиями за месяц (для календаря).
		register_rest_route(
			self::NAMESPACE,
			'/event-dates',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_event_dates' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'year'  => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'month' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// GET /search/live — live-поиск событий для автодополнения в шапке.
		register_rest_route(
			self::NAMESPACE,
			'/search/live',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_live' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'    => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'city' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// GET /search/popular — популярное для подсказок при фокусе (без запроса).
		register_rest_route(
			self::NAMESPACE,
			'/search/popular',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_popular' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'city' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /subscribe — подписка на дайджест из виджета IDPG_Subscribe_Widget.
		// Публичный маршрут: honeypot + rate limiter вместо nonce.
		register_rest_route(
			self::NAMESPACE,
			'/subscribe',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'subscribe' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					),
					'idpg_company' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'consent' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'consent_required' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /city/select — смена города (установка cookie idpg_selected_city).
		// Публичный маршрут: не требует авторизации, но обязателен valid nonce 'wp_rest'.
		register_rest_route(
			self::NAMESPACE,
			'/city/select',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'select_city' ),
				'permission_callback' => array( $this, 'can_select_city' ),
				'args'                => array(
					'city_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

	}

	// =========================================================================
	// Permission Callbacks
	// =========================================================================

	/**
	 * Проверка прав на создание/редактирование контента.
	 *
	 * Требует валидный nonce и одну из ролей: administrator, editor.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return bool|WP_Error True или объект ошибки.
	 */
	public function can_manage_events( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'idpg_rest_nonce_failed',
				__( 'Неверный или отсутствующий nonce-токен.', 'idpg-core' ),
				array( 'status' => 403 )
			);
		}

		$user = wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			return new WP_Error(
				'idpg_rest_not_logged_in',
				__( 'Необходима авторизация.', 'idpg-core' ),
				array( 'status' => 401 )
			);
		}

		// Администраторы и редакторы управляют всем.
		if ( current_user_can( 'edit_posts' ) || current_user_can( 'edit_others_events' ) ) {
			return true;
		}

		// Организатор: только свои события и свои площадки.
		if ( current_user_can( 'edit_events' ) || current_user_can( 'publish_venues' ) ) {
			$id = (int) $request->get_param( 'id' );

			// Создание нового объекта — разрешаем (автор = текущий пользователь).
			if ( ! $id ) {
				return true;
			}

			$post = get_post( $id );

			if (
				! $post
				|| ! in_array( $post->post_type, array( 'event', 'venue' ), true )
				|| (int) $post->post_author !== $user->ID
			) {
				return new WP_Error(
					'idpg_rest_forbidden_owner',
					__( 'Можно управлять только своими объектами.', 'idpg-core' ),
					array( 'status' => 403 )
				);
			}

			return true;
		}

		return new WP_Error(
			'idpg_rest_forbidden',
			__( 'Недостаточно прав для выполнения этого действия.', 'idpg-core' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Проверка nonce для публичного маршрута смены города.
	 *
	 * Авторизация не требуется (анонимный посетитель тоже может выбрать
	 * город), но запрос обязан содержать валидный nonce 'wp_rest'.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return bool|WP_Error True или объект ошибки.
	 */
	public function can_select_city( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'idpg_rest_nonce_failed',
				__( 'Неверный или отсутствующий nonce-токен.', 'idpg-core' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	// =========================================================================
	// Callbacks
	// =========================================================================

	/**
	 * Схема валидации для полей площадки.
	 *
	 * @return array Аргументы для register_rest_route.
	 */
	private function get_venue_schema() {
		return array(
			'title'    => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $value ) {
					return ! empty( trim( $value ) );
				},
			),
			'content'  => array(
				'required'          => false,
				'sanitize_callback' => 'wp_kses_post',
			),
			'address'  => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			'phone'    => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			'email'    => array( 'required' => false, 'sanitize_callback' => 'sanitize_email' ),
			'website'  => array( 'required' => false, 'sanitize_callback' => 'esc_url_raw' ),
			'lat'      => array( 'required' => false, 'sanitize_callback' => 'floatval' ),
			'lng'      => array( 'required' => false, 'sanitize_callback' => 'floatval' ),
			'capacity' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
			'city'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			'thumbnail' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
		);
	}

	// =========================================================================
	// ШАГ 5: Публичные эндпоинты — События с фильтрацией
	// =========================================================================

	/**
	 * GET /events — публичный список событий с фильтрами.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response Ответ.
	 */
	public function get_events( $request ) {
		$args = array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => $request->get_param( 'per_page' ),
			'paged'          => $request->get_param( 'page' ),
			'orderby'        => 'date',
			'order'          => 'ASC',
		);

		$tax_query = array(); // phpcs:ignore WordPress.DB.SlowDBQuery

		$city = $request->get_param( 'city' );
		if ( $city ) {
			$tax_query[] = array(
				'taxonomy' => 'city',
				'field'    => 'term_id',
				'terms'    => $city,
			);
		}

		$category = $request->get_param( 'category' );
		if ( $category ) {
			$tax_query[] = array(
				'taxonomy' => 'event_category',
				'field'    => 'term_id',
				'terms'    => $category,
			);
		}

		if ( ! empty( $tax_query ) ) {
			$tax_query['relation'] = 'AND';
			$args['tax_query']     = $tax_query;
		}

		$date_from = $request->get_param( 'date_from' );
		$date_to   = $request->get_param( 'date_to' );

		$date_query = array(); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $date_from ) {
			$date_query[] = array(
				'after'  => $date_from,
				'inclusive' => true,
			);
		}
		if ( $date_to ) {
			$date_query[] = array(
				'before' => $date_to,
				'inclusive' => true,
			);
		}
		if ( ! empty( $date_query ) ) {
			$date_query['relation'] = 'AND';
			$args['date_query']     = $date_query;
		}

		$search = $request->get_param( 'search' );
		if ( $search ) {
			$args['s'] = $search;
		}

		$query     = new WP_Query( $args );
		$events    = array();

		foreach ( $query->posts as $post ) {
			$events[] = $this->format_event_response( $post );
		}

		return new WP_REST_Response( array(
			'events'    => $events,
			'total'     => $query->found_posts,
			'pages'     => $query->max_num_pages,
			'page'      => (int) $request->get_param( 'page' ),
			'per_page'  => (int) $request->get_param( 'per_page' ),
		), 200 );
	}

	/**
	 * Форматирование данных события для REST-ответа.
	 *
	 * @param WP_Post $post Объект события.
	 * @return array Отформатированный массив.
	 */
	private function format_event_response( $post ) {
		$terms_city = wp_get_post_terms( $post->ID, 'city', array( 'fields' => 'ids' ) );
		$terms_cat  = wp_get_post_terms( $post->ID, 'event_category', array( 'fields' => 'ids' ) );
		$venue_id   = (int) get_post_meta( $post->ID, '_idpg_venue', true );
		$venue      = $venue_id ? get_post( $venue_id ) : null;
		$venue_name = ( $venue && 'venue' === $venue->post_type && 'publish' === $venue->post_status )
			? $venue->post_title
			: '';

		return array(
			'id'              => $post->ID,
			'title'           => $post->post_title,
			'permalink'       => get_permalink( $post->ID ),
			'date_start'      => get_post_meta( $post->ID, '_idpg_start_date', true ),
			'date_end'        => get_post_meta( $post->ID, '_idpg_end_date', true ),
			'all_day'         => 'yes' === get_post_meta( $post->ID, '_idpg_all_day', true ),
			'status'          => get_post_meta( $post->ID, '_idpg_ticket_status', true ),
			'price_from'      => get_post_meta( $post->ID, '_idpg_price_from', true ),
			'price_to'        => get_post_meta( $post->ID, '_idpg_price_to', true ),
			'vip_price'       => get_post_meta( $post->ID, '_idpg_vip_price', true ),
			'is_free'         => 'yes' === get_post_meta( $post->ID, '_idpg_is_free', true ),
			'currency'        => get_post_meta( $post->ID, '_idpg_currency', true ),
			'ticket_type'     => get_post_meta( $post->ID, '_idpg_ticket_type', true ),
			'ticket_url'      => get_post_meta( $post->ID, '_idpg_ticket_url', true ),
			'city'            => ! is_wp_error( $terms_city ) ? $terms_city : array(),
			'category'        => ! is_wp_error( $terms_cat ) ? $terms_cat : array(),
			'thumbnail'       => IDPG_Media::idpg_get_poster_url( $post->ID, 'medium' ),
			'views'           => (int) get_post_meta( $post->ID, '_idpg_views', true ),
			'clicks'          => (int) get_post_meta( $post->ID, '_idpg_clicks', true ),
			'venue_id'        => $venue_id,
			'venue_name'      => $venue_name,
			'address'         => get_post_meta( $post->ID, '_idpg_address', true ),
			'promoted'        => 'yes' === get_post_meta( $post->ID, '_idpg_promoted', true ),
			'is_online'       => 'yes' === get_post_meta( $post->ID, '_idpg_is_online', true ),
			'stream_url'      => get_post_meta( $post->ID, '_idpg_stream_url', true ),
			'video_trailer'   => get_post_meta( $post->ID, '_idpg_video_trailer', true ),
		);
	}

	// =========================================================================
	// ШАГ 5: Площадки — CRUD
	// =========================================================================

	/**
	 * GET /venues — список площадок.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response Ответ.
	 */
	public function get_venues( $request ) {
		$args = array(
			'post_type'      => 'venue',
			'post_status'    => 'publish',
			'posts_per_page' => $request->get_param( 'per_page' ),
			'paged'          => $request->get_param( 'page' ),
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$city = $request->get_param( 'city' );
		if ( $city ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'city',
					'field'    => 'term_id',
					'terms'    => $city,
				),
			);
		}

		$search = $request->get_param( 'search' );
		if ( $search ) {
			$args['s'] = $search;
		}

		$query  = new WP_Query( $args );
		$venues = array();

		foreach ( $query->posts as $post ) {
			$venues[] = $this->format_venue_response( $post );
		}

		return new WP_REST_Response( array(
			'venues'   => $venues,
			'total'    => $query->found_posts,
			'pages'    => $query->max_num_pages,
		), 200 );
	}

	/**
	 * GET /venues/(?P<id>\d+) — одна площадка.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error Ответ.
	 */
	public function get_venue( $request ) {
		$post = get_post( $request->get_param( 'id' ) );

		if ( ! $post || 'venue' !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error(
				'idpg_rest_not_found',
				__( 'Площадка не найдена.', 'idpg-core' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $this->format_venue_response( $post ), 200 );
	}

	/**
	 * POST /venues/create — создание площадки.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error Ответ.
	 */
	public function create_venue( $request ) {
		$title = sanitize_text_field( $request->get_param( 'title' ) );

		if ( empty( $title ) ) {
			return new WP_Error(
				'idpg_rest_missing_title',
				__( 'Обязательное поле: title.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

		$post_data = array(
			'post_title'   => $title,
			'post_content' => wp_kses_post( $request->get_param( 'content' ) ),
			'post_type'    => 'venue',
			'post_status'  => 'publish',
			'post_author'  => get_current_user_id(),
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->save_venue_meta( $post_id, $request );

		$city = $request->get_param( 'city' );
		if ( $city ) {
			if ( ! is_array( $city ) ) {
				$city = array( absint( $city ) );
			}
			wp_set_object_terms( $post_id, array_filter( array_map( 'absint', $city ) ), 'city' );
		}

		$this->handle_thumbnail( $post_id, $request );

		return new WP_REST_Response( array(
			'id'      => $post_id,
			'message' => __( 'Площадка создана.', 'idpg-core' ),
		), 201 );
	}

	/**
	 * PUT /venues/(?P<id>\d+) — редактирование площадки.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error Ответ.
	 */
	public function update_venue( $request ) {
		$post_id = $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'venue' !== $post->post_type ) {
			return new WP_Error(
				'idpg_rest_not_found',
				__( 'Площадка не найдена.', 'idpg-core' ),
				array( 'status' => 404 )
			);
		}

		$post_data = array( 'ID' => $post_id );

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$post_data['post_title'] = sanitize_text_field( $title );
		}

		$content = $request->get_param( 'content' );
		if ( null !== $content ) {
			$post_data['post_content'] = wp_kses_post( $content );
		}

		wp_update_post( $post_data );

		$this->save_venue_meta( $post_id, $request );

		$city = $request->get_param( 'city' );
		if ( null !== $city ) {
			if ( ! is_array( $city ) ) {
				$city = array( absint( $city ) );
			}
			wp_set_object_terms( $post_id, array_filter( array_map( 'absint', $city ) ), 'city' );
		}

		$this->handle_thumbnail( $post_id, $request );

		return new WP_REST_Response( array(
			'id'      => $post_id,
			'message' => __( 'Площадка обновлена.', 'idpg-core' ),
		), 200 );
	}

	/**
	 * Форматирование данных площадки для REST-ответа.
	 *
	 * @param WP_Post $post Объект площадки.
	 * @return array Отформатированный массив.
	 */
	private function format_venue_response( $post ) {
		$terms_city = wp_get_post_terms( $post->ID, 'city', array( 'fields' => 'ids' ) );

		return array(
			'id'         => $post->ID,
			'title'      => $post->post_title,
			'permalink'  => get_permalink( $post->ID ),
			'address'    => get_post_meta( $post->ID, '_idpg_venue_address', true ),
			'phone'      => get_post_meta( $post->ID, '_idpg_venue_phone', true ),
			'email'      => get_post_meta( $post->ID, '_idpg_venue_email', true ),
			'website'    => get_post_meta( $post->ID, '_idpg_venue_website', true ),
			'capacity'   => (int) get_post_meta( $post->ID, '_idpg_venue_capacity', true ),
			'city'       => ! is_wp_error( $terms_city ) ? $terms_city : array(),
			'thumbnail'  => IDPG_Media::idpg_get_poster_url( $post->ID, 'medium' ),
		);
	}

	/**
	 * Сохранение мета-полов площадки.
	 *
	 * @param int             $post_id ID поста.
	 * @param WP_REST_Request $request Запрос.
	 */
	private function save_venue_meta( $post_id, $request ) {
		$fields = array(
			'_idpg_venue_address'  => 'address',
			'_idpg_venue_phone'    => 'phone',
			'_idpg_venue_email'    => 'email',
			'_idpg_venue_website'  => 'website',
			'_idpg_venue_capacity' => 'capacity',
		);

		foreach ( $fields as $meta_key => $param ) {
			$value = $request->get_param( $param );
			if ( null !== $value && '' !== $value ) {
				$clean = 'email' === $param ? sanitize_email( $value ) : sanitize_text_field( $value );
				update_post_meta( $post_id, $meta_key, $clean );
			}
		}
	}

	// =========================================================================
	// ШАГ 5: Таксономии
	// =========================================================================

	/**
	 * GET /cities, /categories, /age-ratings — публичный список терминов.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response Ответ.
	 */
	public function get_terms( $request ) {
		$taxonomy = $request->get_param( 'taxonomy' );

		$allowed = array( 'city', 'event_category', 'age_rating' );

		if ( ! in_array( $taxonomy, $allowed, true ) ) {
			return new WP_Error(
				'idpg_rest_invalid_taxonomy',
				__( 'Недопустимая таксономия.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

		$terms = get_terms( array(
			'taxonomy'   => $taxonomy,
			'orderby'    => 'name',
			'order'      => 'ASC',
			'hide_empty' => true,
		) );

		$result = array();

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$result[] = array(
					'id'    => $term->term_id,
					'name'  => $term->name,
					'slug'  => $term->slug,
					'count' => $term->count,
				);
			}
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /city/select — установка cookie выбранного города.
	 *
	 * Делегирует валидацию термина и запись cookie существующему
	 * IDPG_City_Filter::set_city_cookie() (тот же механизм, что у AJAX
	 * wp_ajax_idpg_set_city). Плюс возвращает slug города для перехода
	 * на /afisha/{slug}/.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error Ответ.
	 */
	public function select_city( $request ) {
		$city_id = absint( $request->get_param( 'city_id' ) );

		if ( ! $city_id || ! taxonomy_exists( 'city' ) || ! term_exists( $city_id, 'city' ) ) {
			return new WP_Error(
				'idpg_rest_invalid_city',
				__( 'Неверный город.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

		if ( ! class_exists( 'IDPG_City_Filter' ) || ! method_exists( 'IDPG_City_Filter', 'set_city_cookie' ) ) {
			return new WP_Error(
				'idpg_rest_city_unavailable',
				__( 'Определение города недоступно.', 'idpg-core' ),
				array( 'status' => 503 )
			);
		}

		IDPG_City_Filter::set_city_cookie( $city_id );

		return new WP_REST_Response(
			array(
				'success'   => true,
				'city_id'   => $city_id,
				'city_name' => IDPG_City_Filter::get_current_city_name(),
				'city_slug' => IDPG_City_Filter::get_current_city_slug(),
			),
			200
		);
	}

	/**
	 * Даты с событиями за месяц (для календаря-фильтра).
	 *
	 * GET /idpg/v1/event-dates?year=2026&month=8
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response
	 */
	public function get_event_dates( $request ) {
		$year  = $request->get_param( 'year' );
		$month = $request->get_param( 'month' );

		$month_start = sprintf( '%04d-%02d-01', $year, $month );
		$next_month  = ( $month === 12 )
			? sprintf( '%04d-01-01', $year + 1 )
			: sprintf( '%04d-%02d-01', $year, $month + 1 );

		global $wpdb;

		// Собираем все _idpg_start_date и _idpg_end_date за диапазон,
		// чтобы покрыть и многодневные события.
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
				 WHERE meta_key IN ('_idpg_start_date', '_idpg_end_date')
				   AND post_id IN (
				     SELECT ID FROM {$wpdb->posts}
				     WHERE post_type = 'event'
				       AND post_status = 'publish'
				   )
				   AND meta_value >= %s AND meta_value < %s
				 ORDER BY meta_value ASC",
				$month_start,
				$next_month
			)
		);

		// Также добавляем все даты между start и end для многодневных событий.
		$dates = array();
		if ( ! empty( $results ) ) {
			// Получаем события с датами для раскрытия диапазонов.
			$events = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID,
					        MAX(CASE WHEN pm.meta_key = '_idpg_start_date' THEN pm.meta_value END) AS date_start,
					        MAX(CASE WHEN pm.meta_key = '_idpg_end_date' THEN pm.meta_value END) AS date_end
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					WHERE p.post_type = 'event'
					  AND p.post_status = 'publish'
					  AND pm.meta_key IN ('_idpg_start_date', '_idpg_end_date')
					GROUP BY p.ID
					HAVING date_start < %s AND (date_end IS NULL OR date_end = '' OR date_end >= %s)
					   OR (date_start >= %s AND date_start < %s)",
					$next_month,
					$month_start,
					$month_start,
					$next_month
				)
			);

			foreach ( $events as $ev ) {
				$start = $ev->date_start;
				$end   = $ev->date_end ?: $start;
				if ( $end < $month_start ) {
					$end = $start;
				}
				if ( $start < $month_start ) {
					$start = $month_start;
				}
				if ( $end >= $next_month ) {
					$end = gmdate( 'Y-m-d', strtotime( $next_month . ' -1 day' ) );
				}

				$current = $start;
				while ( $current <= $end ) {
					$dates[] = $current;
					$current = gmdate( 'Y-m-d', strtotime( $current . ' +1 day' ) );
				}
			}
		}

		$dates = array_unique( $dates );
		sort( $dates );

		return new WP_REST_Response(
			array(
				'year'  => (int) $year,
				'month' => (int) $month,
				'dates' => array_values( $dates ),
			),
			200
		);
	}

	/**
	 * Live-поиск событий для автодополнения.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function search_live( $request ) {
		$q    = trim( (string) $request->get_param( 'q' ) );
		$city = trim( (string) $request->get_param( 'city' ) );

		if ( mb_strlen( $q ) < 2 ) {
			return new WP_REST_Response( array( 'results' => array() ), 200 );
		}

		// Базовый город для всех подзапросов.
		$city_id = 0;
		if ( $city ) {
			$term = get_term_by( 'slug', $city, 'city' );
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
		}

		// 1) По заголовку/контенту (WP s).
		$ids = array();
		$q1 = new WP_Query( array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			's'              => $q,
			'posts_per_page' => 6,
			'orderby'        => 'relevance',
			'tax_query'      => $city_tax, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'fields'         => 'ids',
		) );
		$ids = array_merge( $ids, (array) $q1->posts );

		// 2) По площадкам: venue title LIKE %q%.
		$venue_ids = get_posts( array(
			'post_type'      => 'venue',
			'post_status'    => 'publish',
			's'              => $q,
			'posts_per_page' => 10,
			'fields'         => 'ids',
		) );
		if ( $venue_ids ) {
			$q2 = new WP_Query( array(
				'post_type'      => 'event',
				'post_status'    => 'publish',
				'posts_per_page' => 6,
				'tax_query'      => $city_tax, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_idpg_venue',
						'value'   => array_map( 'strval', $venue_ids ),
						'compare' => 'IN',
					),
				),
				'fields'         => 'ids',
			) );
			$ids = array_merge( $ids, (array) $q2->posts );
		}

		// 3) По категориям/тегам: event_category, age_rating.
		$cat_ids = array();
		foreach ( array( 'event_category', 'age_rating' ) as $tax ) {
			$terms = get_terms( array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
				'search'     => $q,
				'number'     => 5,
			) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				foreach ( $terms as $t ) {
					$cat_ids[] = $t->term_id;
				}
			}
		}
		if ( $cat_ids ) {
			$tax_q = $city_tax;
			$tax_q[] = array(
				'taxonomy' => 'event_category',
				'field'    => 'term_id',
				'terms'    => $cat_ids,
			);
			// Для age_rating — отдельный запрос, но можно объединить через OR (tax_query relation OR).
			$q3 = new WP_Query( array(
				'post_type'      => 'event',
				'post_status'    => 'publish',
				'posts_per_page' => 6,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					'relation' => 'AND',
					$city_tax[0] ?? array( 'taxonomy' => 'city', 'field' => 'term_id', 'terms' => $city_id ),
					array(
						'relation' => 'OR',
						array( 'taxonomy' => 'event_category', 'field' => 'term_id', 'terms' => $cat_ids ),
						array( 'taxonomy' => 'age_rating', 'field' => 'term_id', 'terms' => $cat_ids ),
					),
				),
				'fields'         => 'ids',
			) );
			// Fallback если город не задан — без city_tax.
			if ( empty( $city_tax ) ) {
				$q3 = new WP_Query( array(
					'post_type'      => 'event',
					'post_status'    => 'publish',
					'posts_per_page' => 6,
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						'relation' => 'OR',
						array( 'taxonomy' => 'event_category', 'field' => 'term_id', 'terms' => $cat_ids ),
						array( 'taxonomy' => 'age_rating', 'field' => 'term_id', 'terms' => $cat_ids ),
					),
					'fields'         => 'ids',
				) );
			}
			$ids = array_merge( $ids, (array) $q3->posts );
		}

		$ids = array_unique( array_map( 'intval', $ids ) );
		$ids = array_slice( $ids, 0, 6 );

		if ( empty( $ids ) ) {
			return new WP_REST_Response( array( 'results' => array() ), 200 );
		}

		$query   = new WP_Query( array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'post__in'       => $ids,
			'orderby'        => 'post__in',
			'posts_per_page' => 6,
		) );
		$results = array();

		foreach ( $query->posts as $post ) {
			$url = class_exists( 'IDPG_City_URL' ) ? IDPG_City_URL::event_url( $post->ID ) : get_permalink( $post->ID );
			$thumb = IDPG_Media::idpg_get_poster_url( $post->ID, 'thumbnail' );
			$date  = get_post_meta( $post->ID, '_idpg_start_date', true );
			$venue = '';
			$venue_id = (int) get_post_meta( $post->ID, '_idpg_venue', true );
			if ( $venue_id ) {
				$venue_post = get_post( $venue_id );
				if ( $venue_post ) {
					$venue = $venue_post->post_title;
				}
			}
			$results[] = array(
				'id'    => $post->ID,
				'title' => $post->post_title,
				'url'   => $url,
				'thumb' => $thumb ?: '',
				'date'  => $date ?: '',
				'venue' => $venue,
			);
		}

		// Группы подсказок: события + площадки + рубрики.
		// Ключ results оставлен для обратной совместимости.
		$groups = array();
		if ( $results ) {
			array_unshift(
				$groups,
				array(
					'type'  => 'events',
					'title' => __( 'События', 'idpg-core' ),
					'items' => $results,
				)
			);
		}
		if ( ! empty( $venue_ids ) ) {
			$venue_items = array();
			foreach ( array_slice( array_map( 'intval', (array) $venue_ids ), 0, 3 ) as $venue_id ) {
				$venue_post = get_post( $venue_id );
				if ( ! $venue_post || 'venue' !== $venue_post->post_type ) {
					continue;
				}
				$venue_items[] = array(
					'id'    => $venue_id,
					'title' => $venue_post->post_title,
					'url'   => get_permalink( $venue_id ),
				);
			}
			if ( $venue_items ) {
				$groups[] = array(
					'type'  => 'venues',
					'title' => __( 'Площадки', 'idpg-core' ),
					'items' => $venue_items,
				);
			}
		}
		if ( ! empty( $cat_ids ) ) {
			$cat_items = array();
			foreach ( array_slice( array_unique( array_map( 'intval', (array) $cat_ids ) ), 0, 4 ) as $term_id ) {
				$term = get_term( $term_id );
				if ( ! $term instanceof WP_Term ) {
					continue;
				}
				$term_link = get_term_link( $term );
				if ( is_wp_error( $term_link ) ) {
					continue;
				}
				$cat_items[] = array(
					'id'    => $term_id,
					'title' => $term->name,
					'url'   => $term_link,
				);
			}
			if ( $cat_items ) {
				$groups[] = array(
					'type'  => 'cats',
					'title' => __( 'Рубрики', 'idpg-core' ),
					'items' => $cat_items,
				);
			}
		}

		return new WP_REST_Response( array( 'results' => $results, 'groups' => $groups ), 200 );
	}

	/**
	 * Популярное для подсказок при фокусе на пустом поле поиска.
	 *
	 * Возвращает ближайшие события города и топ рубрик. Публичный маршрут.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function search_popular( $request ) {
		$city = trim( (string) $request->get_param( 'city' ) );

		// Город — как в search_live().
		$city_id = 0;
		if ( $city ) {
			$term = get_term_by( 'slug', $city, 'city' );
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
		}

		// Ближайшие события города по дате начала.
		$events = array();
		$query  = new WP_Query( array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'tax_query'      => $city_tax, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_idpg_start_date',
					'value'   => current_time( 'Y-m-d' ),
					'compare' => '>=',
					'type'    => 'DATE',
				),
			),
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_key'       => '_idpg_start_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		) );
		foreach ( $query->posts as $post ) {
			$url   = class_exists( 'IDPG_City_URL' ) ? IDPG_City_URL::event_url( $post->ID ) : get_permalink( $post->ID );
			$thumb = IDPG_Media::idpg_get_poster_url( $post->ID, 'thumbnail' );
			$date  = get_post_meta( $post->ID, '_idpg_start_date', true );
			$events[] = array(
				'id'    => $post->ID,
				'title' => $post->post_title,
				'url'   => $url,
				'thumb' => $thumb ?: '',
				'date'  => $date ?: '',
				'venue' => '',
			);
		}

		// Топ рубрик по количеству событий.
		$cats  = array();
		$terms = get_terms( array(
			'taxonomy'   => 'event_category',
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => 6,
		) );
		if ( ! is_wp_error( $terms ) && $terms ) {
			foreach ( $terms as $term ) {
				$term_link = get_term_link( $term );
				if ( is_wp_error( $term_link ) ) {
					continue;
				}
				$cats[] = array(
					'id'    => $term->term_id,
					'title' => $term->name,
					'url'   => $term_link,
				);
			}
		}

		return new WP_REST_Response( array( 'events' => $events, 'cats' => $cats ), 200 );
	}

	/**
	 * Подписка на дайджест из виджета.
	 *
	 * Проверяет email, honeypot-капчу и rate limiter (не более 3 подписок
	 * в час с одного IP), сохраняет email в таблицу подписчиков
	 * и отправляет приветственное письмо. Публичный маршрут.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function subscribe( $request ) {
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error(
				'idpg_subscribe_bad_email',
				__( 'Укажите корректный email.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

		// Honeypot: заполненное скрытое поле = бот. Отвечаем «успехом»,
		// чтобы не выдавать защиту, но ничего не сохраняем.
		$honeypot = trim( (string) $request->get_param( 'idpg_company' ) );
		if ( '' !== $honeypot ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Спасибо! Проверьте почту для подтверждения подписки.', 'idpg-core' ),
				),
				200
			);
		}

		// Согласие на обработку данных, если виджет его требует.
		if ( '1' === (string) $request->get_param( 'consent_required' )
			&& '1' !== (string) $request->get_param( 'consent' ) ) {
			return new WP_Error(
				'idpg_subscribe_no_consent',
				__( 'Подтвердите согласие на обработку персональных данных.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

		// Rate limiter: не более 3 подписок в час с одного IP (Transients API).
		$ip      = IDPG_Auth_Rate_Limiter::instance()->get_client_ip();
		$rl_key  = 'idpg_sub_attempts_' . md5( $ip );
		$rl_data = get_transient( $rl_key );
		if ( false === $rl_data ) {
			$rl_data = array(
				'count'         => 0,
				'first_attempt' => time(),
			);
		}
		if ( $rl_data['count'] >= 3 ) {
			if ( time() - $rl_data['first_attempt'] < HOUR_IN_SECONDS ) {
				return new WP_Error(
					'idpg_subscribe_rate_limited',
					__( 'Слишком много попыток. Попробуйте позже.', 'idpg-core' ),
					array( 'status' => 429 )
				);
			}
			$rl_data = array(
				'count'         => 0,
				'first_attempt' => time(),
			);
		}
		$rl_data['count']++;
		set_transient( $rl_key, $rl_data, HOUR_IN_SECONDS );

		global $wpdb;
		$table = $wpdb->prefix . 'idpg_subscribers';

		// Таблица создаётся при активации; если её нет — честная ошибка.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return new WP_Error(
				'idpg_subscribe_no_table',
				__( 'Сервис временно недоступен. Попробуйте позже.', 'idpg-core' ),
				array( 'status' => 500 )
			);
		}

		// Повторная подписка — дружелюбный успех вместо ошибки.
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $exists ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'Этот email уже подписан на дайджест.', 'idpg-core' ),
				),
				200
			);
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'email'      => $email,
				'ip'         => $ip,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return new WP_Error(
				'idpg_subscribe_db_error',
				__( 'Не удалось сохранить подписку. Попробуйте позже.', 'idpg-core' ),
				array( 'status' => 500 )
			);
		}

		// Приветственное письмо (ошибка отправки подписку не отменяет).
		wp_mail(
			$email,
			__( 'Спасибо за подписку на дайджест!', 'idpg-core' ),
			'<p>' . esc_html__( 'Вы подписались на дайджест событий «Иду по городу».', 'idpg-core' ) . '</p>'
				. '<p>' . esc_html__( 'Раз в неделю будем присылать самое интересное.', 'idpg-core' ) . '</p>'
		);

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Спасибо! Проверьте почту для подтверждения подписки.', 'idpg-core' ),
			),
			200
		);
	}
}

