<?php
/**
 * Регистрация пользовательских типов записей и таксономий.
 *
 * @package IDPG_Core
 */

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_CPT
 *
 * Регистрирует CPT (event, venue) и таксономии (event_category, age_rating, city).
 */
class IDPG_CPT {

	/**
	 * Экземпляр синглтона.
	 *
	 * @var IDPG_CPT|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_CPT
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор. Вызывает регистрацию CPT и таксономий напрямую.
	 *
	 * Важно: не хукаем на init, потому что конструктор уже вызывается
	 * из init → WordPress не вызовет новый callback на текущей загрузке.
	 */
	private function __construct() {
		$this->register_post_types();
		$this->register_taxonomies();
	}

	/**
	 * Регистрация пользовательских типов записей.
	 */
	public function register_post_types() {
		$this->register_event_post_type();
		$this->register_venue_post_type();
	}

	/**
	 * Регистрация CPT "event" (Событие).
	 */
	private function register_event_post_type() {
		$labels = array(
			'name'                  => _x( 'События', 'Общее название', 'idpg-core' ),
			'singular_name'         => _x( 'Событие', 'Одна запись', 'idpg-core' ),
			'menu_name'             => _x( 'Афиша', 'Название в меню', 'idpg-core' ),
			'all_items'             => __( 'Все события', 'idpg-core' ),
			'add_new_item'          => __( 'Добавить новое событие', 'idpg-core' ),
			'add_new'               => __( 'Добавить событие', 'idpg-core' ),
			'new_item'              => __( 'Новое событие', 'idpg-core' ),
			'edit_item'             => __( 'Редактировать событие', 'idpg-core' ),
			'update_item'           => __( 'Обновить событие', 'idpg-core' ),
			'view_item'             => __( 'Посмотреть событие', 'idpg-core' ),
			'search_items'          => __( 'Найти событие', 'idpg-core' ),
			'not_found'             => __( 'События не найдены', 'idpg-core' ),
			'not_found_in_trash'    => __( 'В корзине нет событий', 'idpg-core' ),
			'featured_image'        => _x( 'Постер события', 'Заголовок области обложки', 'idpg-core' ),
			'set_featured_image'    => _x( 'Задать постер', 'Заголовок области обложки', 'idpg-core' ),
			'remove_featured_image' => _x( 'Удалить постер', 'Заголовок области обложки', 'idpg-core' ),
			'use_featured_image'    => _x( 'Использовать как постер', 'Заголовок области обложки', 'idpg-core' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'afisha', 'with_front' => false ),
			'capability_type'    => 'event',
			'map_meta_cap'       => true,
			'has_archive'        => true,
			'hierarchical'       => false,
			'menu_position'      => 5,
			'menu_icon'          => 'dashicons-calendar-alt',
			'supports'           => array( 'title', 'editor', 'thumbnail' ),
		);

		register_post_type( 'event', $args );
	}

	/**
	 * Регистрация CPT "venue" (Площадка).
	 */
	private function register_venue_post_type() {
		$labels = array(
			'name'                  => _x( 'Площадки', 'Общее название', 'idpg-core' ),
			'singular_name'         => _x( 'Площадка', 'Одна запись', 'idpg-core' ),
			'menu_name'             => _x( 'Площадки', 'Название в меню', 'idpg-core' ),
			'all_items'             => __( 'Все площадки', 'idpg-core' ),
			'add_new_item'          => __( 'Добавить новую площадку', 'idpg-core' ),
			'add_new'               => __( 'Добавить площадку', 'idpg-core' ),
			'new_item'              => __( 'Новая площадка', 'idpg-core' ),
			'edit_item'             => __( 'Редактировать площадку', 'idpg-core' ),
			'update_item'           => __( 'Обновить площадку', 'idpg-core' ),
			'view_item'             => __( 'Посмотреть площадку', 'idpg-core' ),
			'search_items'          => __( 'Найти площадку', 'idpg-core' ),
			'not_found'             => __( 'Площадки не найдены', 'idpg-core' ),
			'not_found_in_trash'    => __( 'В корзине нет площадок', 'idpg-core' ),
			'featured_image'        => _x( 'Фото площадки', 'Заголовок области обложки', 'idpg-core' ),
			'set_featured_image'    => _x( 'Задать фото', 'Заголовок области обложки', 'idpg-core' ),
			'remove_featured_image' => _x( 'Удалить фото', 'Заголовок области обложки', 'idpg-core' ),
			'use_featured_image'    => _x( 'Использовать как фото', 'Заголовок области обложки', 'idpg-core' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'venues', 'with_front' => false ),
			'capability_type'    => 'venue',
			'map_meta_cap'       => true,
			'has_archive'        => true,
			'hierarchical'       => false,
			'menu_position'      => 6,
			'menu_icon'          => 'dashicons-location',
			'supports'           => array( 'title', 'editor', 'thumbnail' ),
		);

		register_post_type( 'venue', $args );
	}

	/**
	 * Регистрация таксономий.
	 */
	public function register_taxonomies() {
		$this->register_event_category_taxonomy();
		$this->register_age_rating_taxonomy();
		$this->register_city_taxonomy();
	}

	/**
	 * Регистрация таксономии "event_category" (Категории событий).
	 *
	 * Иерархическая (как категории).
	 */
	private function register_event_category_taxonomy() {
		$labels = array(
			'name'              => _x( 'Категории событий', 'Общее название', 'idpg-core' ),
			'singular_name'     => _x( 'Категория событий', 'Одна запись', 'idpg-core' ),
			'search_items'      => __( 'Найти категорию', 'idpg-core' ),
			'all_items'         => __( 'Все категории', 'idpg-core' ),
			'parent_item'       => __( 'Родительская категория', 'idpg-core' ),
			'parent_item_colon' => __( 'Родительская категория:', 'idpg-core' ),
			'edit_item'         => __( 'Редактировать категорию', 'idpg-core' ),
			'update_item'       => __( 'Обновить категорию', 'idpg-core' ),
			'add_new_item'      => __( 'Добавить новую категорию', 'idpg-core' ),
			'new_item_name'     => __( 'Название новой категории', 'idpg-core' ),
			'menu_name'         => __( 'Категории', 'idpg-core' ),
		);

		$args = array(
			'hierarchical'      => true,
			'labels'            => $labels,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'event-category' ),
			'capabilities'      => array(
				'manage_terms' => 'edit_events',
				'edit_terms'   => 'edit_events',
				'delete_terms' => 'manage_categories',
				'assign_terms' => 'edit_events',
			),
		);

		register_taxonomy( 'event_category', array( 'event' ), $args );
	}

	/**
	 * Регистрация таксономии "age_rating" (Возрастной рейтинг).
	 *
	 * Не иерархическая (как теги).
	 */
	private function register_age_rating_taxonomy() {
		$labels = array(
			'name'              => _x( 'Возрастные рейтинги', 'Общее название', 'idpg-core' ),
			'singular_name'     => _x( 'Возрастной рейтинг', 'Одна запись', 'idpg-core' ),
			'search_items'      => __( 'Найти рейтинг', 'idpg-core' ),
			'all_items'         => __( 'Все рейтинги', 'idpg-core' ),
			'edit_item'         => __( 'Редактировать рейтинг', 'idpg-core' ),
			'update_item'       => __( 'Обновить рейтинг', 'idpg-core' ),
			'add_new_item'      => __( 'Добавить новый рейтинг', 'idpg-core' ),
			'new_item_name'     => __( 'Название нового рейтинга', 'idpg-core' ),
			'menu_name'         => __( 'Возрастной рейтинг', 'idpg-core' ),
		);

		$args = array(
			'hierarchical'      => false,
			'labels'            => $labels,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'age-rating' ),
			'capabilities'      => array(
				'manage_terms' => 'manage_categories',
				'edit_terms'   => 'manage_categories',
				'delete_terms' => 'manage_categories',
				'assign_terms' => 'edit_events',
			),
		);

		register_taxonomy( 'age_rating', array( 'event' ), $args );
	}

	/**
	 * Регистрация таксономии "city" (Города).
	 *
	 * Иерархическая. Привязана к обоим CPT (event, venue).
	 * Rewrite slug: gorod.
	 */
	private function register_city_taxonomy() {
		$labels = array(
			'name'              => _x( 'Города', 'Общее название', 'idpg-core' ),
			'singular_name'     => _x( 'Город', 'Одна запись', 'idpg-core' ),
			'search_items'      => __( 'Найти город', 'idpg-core' ),
			'all_items'         => __( 'Все города', 'idpg-core' ),
			'parent_item'       => __( 'Родительский город', 'idpg-core' ),
			'parent_item_colon' => __( 'Родительский город:', 'idpg-core' ),
			'edit_item'         => __( 'Редактировать город', 'idpg-core' ),
			'update_item'       => __( 'Обновить город', 'idpg-core' ),
			'add_new_item'      => __( 'Добавить новый город', 'idpg-core' ),
			'new_item_name'     => __( 'Название нового города', 'idpg-core' ),
			'menu_name'         => __( 'Города', 'idpg-core' ),
		);

		$args = array(
			'hierarchical'      => true,
			'labels'            => $labels,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'gorod', 'with_front' => false ),
			'capabilities'      => array(
				'manage_terms' => 'manage_categories',
				'edit_terms'   => 'manage_categories',
				'delete_terms' => 'manage_categories',
				'assign_terms' => 'edit_events',
			),
		);

		register_taxonomy( 'city', array( 'event', 'venue' ), $args );
	}
}
