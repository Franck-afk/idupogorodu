<?php
/**
 * Регистрация мета-полей для CPT event и venue.
 *
 * Мета-поля регистрируются через register_post_meta() для видимости
 * в REST API, а формы в админке строятся на нативных метабоксах
 * (IDPG_Event_Fields, IDPG_Venue_Fields).
 *
 * Все ключи меты используют единый префикс `_idpg_`.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Meta
 *
 * Регистрирует мета-поля для REST API и содержит зеркала
 * complex-полей (сеансы, часы, соцсети).
 */
class IDPG_Meta {

	/**
	 * Единый префикс мета-ключей плагина.
	 *
	 * @var string
	 */
	const PREFIX = '_idpg_';

	/**
	 * Ключ JSON-зеркала сеансов.
	 *
	 * Сеансы хранятся в `_idpg_sessions` (сериализованный PHP-массив),
	 * а для удобства чтения шаблонами дублируются сюда простой
	 * JSON-строкой — её читает IDPG_Template_Helper::get_event_sessions().
	 *
	 * @var string
	 */
	const SESSIONS_MIRROR = '_idpg_sessions_json';

	/**
	 * Ключ JSON-зеркала часов работы площадки.
	 *
	 * @var string
	 */
	const VENUE_HOURS_MIRROR = '_idpg_venue_hours_json';

	/**
	 * Ключ JSON-зеркала соцсетей площадки.
	 *
	 * @var string
	 */
	const VENUE_SOCIAL_MIRROR = '_idpg_venue_social_json';

	/**
	 * Синглтон.
	 *
	 * @var IDPG_Meta|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Meta
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор. Привязывает методы к хукам WordPress.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_meta' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor_styles' ) );
		// Памятка-инструкция для организаторов в редакторе события.
		// Приоритет 1 — чтобы блок встал выше остальных normal/high боксов (Основная, Билеты).
		add_action( 'add_meta_boxes', array( $this, 'register_editor_help_meta_box' ), 1 );
		// Фильтруем список событий в админке: организатор видит только свои.
		add_action( 'pre_get_posts', array( $this, 'filter_events_for_organizer' ) );
		// Гарантируем город и дату для событий организатора, чтобы они попали на главную.
		add_action( 'save_post_event', array( $this, 'ensure_event_city_and_date' ), 20, 3 );
		// Медиа у организатора всегда открыта и видима.
		add_filter( 'get_user_option_closedpostboxes_event', array( $this, 'force_media_open' ) );
		add_filter( 'get_user_option_metaboxhidden_event', array( $this, 'force_media_visible' ) );
		// Колонка «Баннер» в списке событий — статус заявки/оплаты показа на главной.
		add_filter( 'manage_event_posts_columns', array( $this, 'add_banner_column' ) );
		add_action( 'manage_event_posts_custom_column', array( $this, 'render_banner_column' ), 10, 2 );
		// Таксономии (категории, теги, город) не скрыты по умолчанию в Screen Options.
		// Принудительно убираем их из сохранённого списка скрытых метабоксов
		// до того, как WordPress прочтёт опцию (надёжнее фильтров).
		add_action( 'admin_head', array( $this, 'force_taxonomies_visible' ) );
		// Для организатора — оставляем нативный metabox видимым (резерв).
	}

	/**
	 * Определяет, работаем ли мы на локальном сервере.
	 *
	 * На локалке отключаем внешние запросы (Nominatim, Яндекс.Карты API)
	 * и используем только координаты площадки.
	 *
	 * @return bool
	 */
	public static function is_local() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
		return preg_match( '/(^|\.)(test|localhost)$/', $host )
			|| false !== strpos( $host, '127.0.0.1' )
			|| false !== strpos( $host, '::1' );
	}

	/**
	 * Подключение стилей редактора Gutenberg для CPT event/venue.
	 *
	 * Расширяет боковую панель блочного редактора и приводит метабоксы
	 * к аккуратному виду.
	 *
	 * @return void
	 */
	public function enqueue_editor_styles() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || empty( $screen->post_type ) || ! in_array( $screen->post_type, array( 'event', 'venue' ), true ) ) {
			return;
		}

		$css_version = file_exists( IDPG_CORE_DIR . 'assets/css/editor.css' )
			? (string) filemtime( IDPG_CORE_DIR . 'assets/css/editor.css' )
			: IDPG_CORE_VERSION;

		wp_enqueue_style( 'idpg-editor', IDPG_CORE_URL . 'assets/css/editor.css', array(), $css_version );

		// Библиотека медиафайлов для метабокса постера события — только для event.
		// Нужна штатному flow ядра (wp.media.featuredImage).
		if ( 'event' === $screen->post_type ) {
			wp_enqueue_media();

			// Выводим JS через admin_footer, так как idpg-editor — это CSS-хендл
			// и wp_add_inline_script не работает со стилями.
			add_action( 'admin_footer', function() {
				// Организатору — скрыть "Добавить" для возраста/города, но оставить для категорий (может создавать свои).
				// DEV-258: роль организатора проверяется хелпером, а не `current_user_can( 'event_organizer' )` —
				// такой capability не зарегистрирован и работал бы только по совпадению имени с ролью.
				if ( IDPG_Roles::instance()->is_current_user_organizer() && ! current_user_can( 'manage_categories' ) ) {
					echo '<script>(function(){["age_rating","city"].forEach(function(t){var a=document.getElementById(t+"-adder");if(a)a.style.display="none";var b=document.querySelector("#"+t+"div .category-add, #tagsdiv-"+t+" .category-add");if(b)b.style.display="none";});})();</script>';
				}
			}, 100 );
		}
	}

	/**
	 * Регистрация метабокса-памятки в редакторе события.
	 *
	 * Показывается организаторам и администраторам при создании/редактировании
	 * события, чтобы пояснить, как корректно заполнить ключевые поля.
	 *
	 * @return void
	 */
	public function register_editor_help_meta_box() {
		if ( ! $this->can_see_editor_help() ) {
			return;
		}

		$is_new = ( 'add' === $this->editor_screen_action() );
		$title  = $is_new ? __( 'Как создать событие', 'idpg-core' ) : __( 'Памятка по заполнению события', 'idpg-core' );

		add_meta_box(
			'idpg_editor_help',
			$title,
			array( $this, 'render_editor_help_meta_box' ),
			'event',
			'normal',
			'high'
		);
	}

	/**
	 * Вывод содержимого метабокса-памятки.
	 *
	 * @return void
	 */
	public function render_editor_help_meta_box() {
		$items = $this->build_editor_help_items();
		?>
		<div class="idpg-editor-help">
			<ol class="idpg-editor-help__list">
				<?php foreach ( $items as $item ) : ?>
					<li><?php echo esc_html( $item ); ?></li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}

	/**
	 * Список пунктов памятки по заполнению события.
	 *
	 * @return string[]
	 */
	protected function build_editor_help_items() {
		return array(
			__( 'Укажите название и добавьте описание события в основном поле.', 'idpg-core' ),
			__( 'В блоке «Основная информация» заполните дату начала и, при необходимости, дату окончания и сеансы.', 'idpg-core' ),
			__( 'В «Месте проведения» выберите площадку или укажите адрес и координаты вручную.', 'idpg-core' ),
			__( 'В «Билетах и ценах» укажите цены (или отметьте «Бесплатное событие»), тип продажи и ссылку на покупку билетов.', 'idpg-core' ),
			__( 'Если нужна регистрация участников через Яндекс.Форму — создайте её по кнопке и вставьте ссылку в поле «Ссылка на Яндекс.Форму».', 'idpg-core' ),
			__( 'Для онлайн-мероприятия отметьте «Онлайн-событие» и укажите ссылку на трансляцию во вкладке «Дополнительно».', 'idpg-core' ),
			__( 'Загрузите обложку (медиафайл) и нажмите «Опубликовать».', 'idpg-core' ),
		);
	}

	/**
	 * Доступна ли памятка текущему пользователю (организаторам/админам).
	 *
	 * @return bool
	 */
	protected function can_see_editor_help() {
		$user = wp_get_current_user();
		return ! ! $user && array_intersect( array( 'administrator', 'editor', 'event_organizer' ), (array) $user->roles );
	}

	/**
	 * Возвращает action текущего экрана редактора события ('add' / 'edit').
	 *
	 * @return string
	 */
	protected function editor_screen_action() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return 'edit';
		}
		$screen = get_current_screen();
		return ( $screen && 'add' === $screen->action ) ? 'add' : 'edit';
	}

	// =========================================================================
	// Регистрация мета-полей
	// =========================================================================

	/**
	 * Регистрация всех мета-полей через register_post_meta().
	 *
	 * Делает мета-поля видимыми в REST API и задаёт схему валидации.
	 */
	public function register_meta() {
		$this->register_event_meta();
		$this->register_venue_meta();
	}

	/**
	 * Мета-поля для CPT event.
	 *
	 * Чекбоксы хранят строку `yes` (или пустое значение),
	 * поэтому регистрируются как `string`, а не `boolean`.
	 *
	 * Сеансы и media_gallery галереи здесь не регистрируются:
	 * простые значения доступны через зеркало SESSIONS_MIRROR.
	 */
	private function register_event_meta() {
		$fields = array(
			// Группа 1 — основная информация.
			'_idpg_start_date'       => array( 'type' => 'string' ),
			'_idpg_end_date'         => array( 'type' => 'string' ),
			'_idpg_all_day'          => array( 'type' => 'string' ),
			self::SESSIONS_MIRROR    => array( 'type' => 'string' ),

			// Группа 2 — место проведения.
			'_idpg_venue'            => array( 'type' => 'integer', 'default' => 0 ),
			// Группа 3 — билеты и цены.
			'_idpg_price_from'       => array( 'type' => 'string' ),
			'_idpg_price_to'         => array( 'type' => 'string' ),
			'_idpg_is_free'          => array( 'type' => 'string' ),
			'_idpg_vip_price'        => array( 'type' => 'string' ),
			'_idpg_ticket_url'       => array( 'type' => 'string' ),
			'_idpg_yandex_form_url'  => array( 'type' => 'string' ),
			'_idpg_widget_code'      => array( 'type' => 'string' ),
			'_idpg_ticket_status'    => array(
				'type' => 'string',
				'enum' => array( 'in_sale', 'sold_out', 'cancelled', 'postponed' ),
			),
			'_idpg_ticket_type'      => array(
				'type' => 'string',
				'enum' => array( 'external_link', 'widget_yandex' ),
			),
			'_idpg_currency'         => array( 'type' => 'string' ),
			'_idpg_external_id'      => array( 'type' => 'string' ),

			// Группа 4 — медиа.
			'_idpg_video_trailer'    => array( 'type' => 'string' ),
			'_idpg_stream_embed'     => array( 'type' => 'string' ),

			// Дополнительно.
			'_idpg_source'           => array( 'type' => 'string' ),
			'_idpg_promoted'         => array( 'type' => 'string' ),
			// Срок закрепления в топе (Y-m-d). Cron IDPG_Promotion снимает
			// закрепление по истечении даты.
			'_idpg_pin_expires'      => array( 'type' => 'string' ),
			'_idpg_is_online'        => array( 'type' => 'string' ),
			'_idpg_stream_url'       => array( 'type' => 'string' ),
			'_idpg_views'            => array( 'type' => 'integer', 'default' => 0 ),
			'_idpg_clicks'           => array( 'type' => 'integer', 'default' => 0 ),
			'_idpg_hide_from_banner' => array( 'type' => 'string' ),
			'_idpg_hide_full_banner' => array( 'type' => 'string' ),

			// Платный показ события в баннере на главной.
			// request — заявка организатора на показ; approved — подтверждённая
			// администратором оплата (показ активируется только при approved=yes).
			'_idpg_banner_request'   => array( 'type' => 'string' ),
			'_idpg_banner_approved'  => array( 'type' => 'string' ),
		);

		foreach ( $fields as $key => $args ) {
			register_post_meta( 'event', $key, $this->build_schema( $args ) );
		}
	}

	/**
	 * Мета-поля для CPT venue.
	 */
	private function register_venue_meta() {
		$fields = array(
			'_idpg_venue_type'     => array(
				'type' => 'string',
				'enum' => array( '', 'theater', 'cinema', 'museum', 'concert' ),
			),
			'_idpg_venue_address'  => array( 'type' => 'string' ),
			'_idpg_venue_phone'    => array( 'type' => 'string' ),
			'_idpg_venue_email'    => array( 'type' => 'string', 'format' => 'email' ),
			'_idpg_venue_website'  => array( 'type' => 'string' ),
			'_idpg_venue_capacity' => array( 'type' => 'integer', 'default' => 0 ),
			// Координаты площадки для гео-разметки Schema.org (GeoCoordinates).
			'_idpg_venue_lat'       => array( 'type' => 'string' ),
			'_idpg_venue_lng'       => array( 'type' => 'string' ),
			// Зеркала complex-полей (DEV-11) — JSON-строки для REST и шаблонов.
			'_idpg_venue_hours_json'  => array( 'type' => 'string' ),
			'_idpg_venue_social_json' => array( 'type' => 'string' ),
		);

		foreach ( $fields as $key => $args ) {
			register_post_meta( 'venue', $key, $this->build_schema( $args ) );
		}
	}

	/**
	 * Сборка схемы для register_post_meta().
	 *
	 * @param array $args Описание поля (type, default, enum, format).
	 * @return array Схема для register_post_meta().
	 */
	private function build_schema( $args ) {
		$schema = array(
			'type'          => $args['type'],
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => array( $this, 'meta_auth_callback' ),
		);

		if ( isset( $args['default'] ) ) {
			$schema['default'] = $args['default'];
		}
		if ( isset( $args['enum'] ) ) {
			$schema['enum'] = $args['enum'];
		}
		if ( isset( $args['format'] ) ) {
			$schema['format'] = $args['format'];
		}

		return $schema;
	}

	/**
	 * Auth callback для мета-полей.
	 *
	 * Проверяет, может ли текущий пользователь редактировать запись.
	 *
	 * @param bool   $allowed  Разрешено ли (ядро WP).
	 * @param int    $post_id  ID записи.
	 * @param string $meta_key Ключ мета-поля.
	 * @return bool
	 */
	public function meta_auth_callback( $allowed, $post_id, $meta_key ) {
		if ( ! $post_id ) {
			return false;
		}
		return current_user_can( 'edit_post', $post_id );
	}

	// =========================================================================
	// Зеркало сеансов
	// =========================================================================

	/**
	 * Дублирование сеансов в простую JSON-строку.
	 *
	 * Сеансы хранятся в `_idpg_sessions` (сериализованный PHP-массив).
	 * Чтобы шаблоны и внешний код могли читать сеансы одним
	 * get_post_meta(), после каждого сохранения записи актуальный список
	 * пишется в SESSIONS_MIRROR.
	 *
	 * @param int $post_id ID записи.
	 */
	public static function mirror_sessions( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || 'event' !== get_post_type( $post_id ) ) {
			return;
		}

		$rows     = get_post_meta( $post_id, '_idpg_sessions', true );
		$sessions = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$time = isset( $row['time'] ) ? trim( (string) $row['time'] ) : '';

				if ( '' === $time ) {
					continue;
				}

				$sessions[] = array(
					'time'  => $time,
					'price' => isset( $row['price'] ) ? trim( (string) $row['price'] ) : '',
					'url'   => isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '',
				);
			}
		}

		if ( empty( $sessions ) ) {
			delete_post_meta( $post_id, self::SESSIONS_MIRROR );
		} else {
			update_post_meta( $post_id, self::SESSIONS_MIRROR, wp_json_encode( $sessions ) );
		}

		self::purge_helper_cache( $post_id );
	}

	/**
	 * Дублирование часов работы площадки в JSON (DEV-11).
	 *
	 * @param int $post_id ID записи.
	 */
	public static function mirror_venue_hours( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || 'venue' !== get_post_type( $post_id ) ) {
			return;
		}

		$rows  = get_post_meta( $post_id, '_idpg_venue_hours', true );
		$hours = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$day = isset( $row['day'] ) ? trim( (string) $row['day'] ) : '';

				if ( '' === $day ) {
					continue;
				}

				$hours[] = array(
					'day'       => $day,
					'open'      => isset( $row['open'] ) ? trim( (string) $row['open'] ) : '',
					'close'     => isset( $row['close'] ) ? trim( (string) $row['close'] ) : '',
					'is_closed' => ! empty( $row['is_closed'] ),
				);
			}
		}

		if ( empty( $hours ) ) {
			delete_post_meta( $post_id, self::VENUE_HOURS_MIRROR );
		} else {
			update_post_meta( $post_id, self::VENUE_HOURS_MIRROR, wp_json_encode( $hours ) );
		}
	}

	/**
	 * Дублирование соцсетей площадки в JSON (DEV-11).
	 *
	 * @param int $post_id ID записи.
	 */
	public static function mirror_venue_social( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || 'venue' !== get_post_type( $post_id ) ) {
			return;
		}

		$rows   = get_post_meta( $post_id, '_idpg_venue_social', true );
		$social = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$network = isset( $row['network'] ) ? trim( (string) $row['network'] ) : '';
				$url     = isset( $row['url'] ) ? trim( (string) $row['url'] ) : '';

				if ( '' === $network || '' === $url ) {
					continue;
				}

				$social[] = array(
					'network' => $network,
					'url'     => esc_url_raw( $url ),
				);
			}
		}

		if ( empty( $social ) ) {
			delete_post_meta( $post_id, self::VENUE_SOCIAL_MIRROR );
		} else {
			update_post_meta( $post_id, self::VENUE_SOCIAL_MIRROR, wp_json_encode( $social ) );
		}
	}

	/**
	 * Скрывает нативный metabox категорий для организатора — ОТКЛЮЧЕНО.
	 * Оставляем нативный видимым.
	 */
	public function hide_category_metabox_for_organizer() {
		return;
	}

	/**
	 * Сброс объектного кэша хелперов шаблонов для события.
	 *
	 * @param int $post_id ID записи.
	 */
	public static function purge_helper_cache( $post_id ) {
		foreach ( array( 'btn_', 'sessions_', 'meta_' ) as $bucket ) {
			wp_cache_delete( IDPG_Template_Helper::CACHE_PREFIX . $bucket . $post_id, 'idpg' );
		}
	}

	/**
	 * Гарантирует город и дату у события при публикации.
	 *
	 * Организатор часто не выбирает город и дату — без них событие
	 * не попадает в выборки главной (фильтр по city и meta _idpg_start_date
	 * в front-page.php). Берём город площадки или текущий город (Ижевск 15),
	 * дату — сегодня.
	 *
	 * @param int     $post_id ID события.
	 * @param WP_Post $post    Объект поста.
	 * @param bool    $update  Обновление ли это.
	 */
	public function ensure_event_city_and_date( $post_id, $post, $update ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		$terms = wp_get_object_terms( $post_id, 'city', array( 'fields' => 'ids' ) );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			$city_id = 0;
			$venue_id = (int) get_post_meta( $post_id, '_idpg_venue', true );
			if ( $venue_id ) {
				$v_terms = wp_get_object_terms( $venue_id, 'city', array( 'fields' => 'ids' ) );
				if ( ! is_wp_error( $v_terms ) && ! empty( $v_terms ) ) {
					$city_id = (int) $v_terms[0];
				}
			}
			if ( ! $city_id ) {
				$city_id = class_exists( 'IDPG_City_Filter' ) ? (int) IDPG_City_Filter::get_current_city_id() : 15;
				if ( ! $city_id || ! term_exists( $city_id, 'city' ) ) {
					$city_id = 15;
				}
			}
			if ( $city_id ) {
				wp_set_object_terms( $post_id, array( $city_id ), 'city', false );
			}
		}

		$start = get_post_meta( $post_id, '_idpg_start_date', true );
		if ( empty( $start ) ) {
			$today = gmdate( 'Y-m-d', current_time( 'timestamp' ) );
			update_post_meta( $post_id, '_idpg_start_date', $today );
		}
	}

	// =========================================================================
	// Фильтрация списка событий для организатора
	// =========================================================================

	/**
	 * Организатор видит в админке только свои события.
	 *
	 * При фильтрации учитывается, что у роли event_organizer
	 * edit_others_events = false, поэтому логика дублирует
	 * ограничение на уровне запроса.
	 *
	 * DEV-258: «свой ли это пользователь» решается по зарегистрированным
	 * правам и по роли. Админ отсекается через `manage_options` (так же,
	 * как в остальном плагине), организатор — через
	 * `IDPG_Roles::is_current_user_organizer()`: cap `edit_events` есть и у
	 * редактора, и у администратора, и организатора от неё не отличить.
	 *
	 * @param WP_Query $query Объект запроса.
	 * @return void
	 */
	public static function filter_events_for_organizer( $query ) {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! $query->is_main_query() ) {
			return;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		// DEV-258: роль организатора проверяется хелпером `IDPG_Roles`
		// (незарегистрированный `current_user_can( 'event_organizer' )` заменён).
		if ( ! IDPG_Roles::instance()->is_current_user_organizer() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		$allowed  = array( 'event', 'venue' );

		if ( is_string( $post_type ) ) {
			$post_type = array( $post_type );
		}

		if ( ! is_array( $post_type ) || ! array_intersect( $allowed, $post_type ) ) {
			return;
		}

		// Не фильтруем, если в URL явно задан автор (редактирование чужого события админом).
		$author = $query->get( 'author' );
		if ( '' !== $author && is_numeric( $author ) ) {
			return;
		}

		$query->set( 'author', get_current_user_id() );
	}

	/**
	 * Принудительно держит бокс Медиа открытым и видимым у организатора.
	 *
	 * @param mixed $value Значение из user_option.
	 * @return mixed
	 */
	public function force_media_open( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		// Медиа, Основная, Постер и таксономии — всегда открыты у организатора
		$hide = array(
			'idpg_event_media',
			'idpg_event_main',
			'postimagediv',
			'event_categorydiv',
			'tagsdiv-age_rating',
			'citydiv',
		);
		return array_values( array_diff( $value, $hide ) );
	}

	/**
	 * Принудительно показывает бокс Медиа в Screen Options.
	 *
	 * @param mixed $value Значение из user_option.
	 * @return mixed
	 */
	public function force_media_visible( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		return array_values( array_diff( $value, array(
			'idpg_event_media',
			'event_categorydiv',
			'tagsdiv-age_rating',
			'citydiv',
		) ) );
	}

	/**
	 * Убирает таксономии из сохранённого списка скрытых метабоксов.
	 *
	 * Принудительно удаляет ID таксономий из опции `metaboxhidden_event`
	 * (или venue) текущего пользователя до того, как WordPress прочтёт её
	 * в `get_hidden_meta_boxes()`. Это гарантирует, что метабоксы категорий,
	 * тегов и города будут видны в редакторе и в Screen Options.
	 *
	 * @return void
	 */
	public function force_taxonomies_visible() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || empty( $screen->post_type ) || ! in_array( $screen->post_type, array( 'event', 'venue' ), true ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$hidden = get_user_option( 'metaboxhidden_' . $screen->id, $user_id );
		if ( ! is_array( $hidden ) ) {
			return;
		}

		$remove = array( 'event_categorydiv', 'tagsdiv-age_rating', 'citydiv' );
		$diff   = array_values( array_diff( $hidden, $remove ) );

		if ( $diff !== $hidden ) {
			update_user_option( $user_id, 'metaboxhidden_' . $screen->id, $diff );
		}
	}

	/**
	 * Добавляет колонку «Баннер» в список событий.
	 *
	 * @param array $columns Существующие колонки.
	 * @return array
	 */
	public function add_banner_column( $columns ) {
		$columns['idpg_banner'] = __( 'Баннер', 'idpg-core' );
		return $columns;
	}

	/**
	 * Выводит статус заявки/оплаты показа в баннере в списке событий.
	 *
	 * @param string $column  Имя колонки.
	 * @param int    $post_id ID события.
	 * @return void
	 */
	public function render_banner_column( $column, $post_id ) {
		if ( 'idpg_banner' !== $column ) {
			return;
		}

		$approved = 'yes' === get_post_meta( $post_id, '_idpg_banner_approved', true );
		$request  = 'yes' === get_post_meta( $post_id, '_idpg_banner_request', true );

		if ( $approved ) {
			echo '<span style="color:#27ae60;">' . esc_html__( 'Оплачен ✓', 'idpg-core' ) . '</span>';
		} elseif ( $request ) {
			echo '<span style="color:#e67e22;">' . esc_html__( 'Заявка', 'idpg-core' ) . '</span>';
		} else {
			echo '<span aria-hidden="true">—</span>';
		}
	}
}

// Зеркала пересобираются после сохранения поста (нативные метабоксы
// IDPG_Event_Fields / IDPG_Venue_Fields пишут зеркала напрямую, эти
// хуки — страховка для программных обновлений).
add_action( 'save_post_event', array( 'IDPG_Meta', 'mirror_sessions' ), 30 );
add_action( 'save_post_venue', array( 'IDPG_Meta', 'mirror_venue_hours' ), 30 );
add_action( 'save_post_venue', array( 'IDPG_Meta', 'mirror_venue_social' ), 30 );
