<?php
/**
 * Регистрация мета-полей для CPT event и venue.
 *
 * Мета-поля регистрируются через register_post_meta() для видимости
 * в REST API, а формы в админке строятся на Carbon Fields.
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
 * Регистрирует мета-поля для REST API и создаёт формы админки
 * через Carbon Fields.
 */
class IDPG_Meta {

	/**
	 * Единый префикс мета-ключей плагина.
	 *
	 * Carbon Fields сохраняет поле с именем `idpg_foo` в мета-ключ
	 * `_idpg_foo`, поэтому имена CF-полей задаются без ведущего
	 * подчёркивания.
	 *
	 * @var string
	 */
	const PREFIX = '_idpg_';

	/**
	 * Ключ JSON-зеркала сеансов.
	 *
	 * Carbon Fields хранит complex-поле `idpg_sessions` в наборе
	 * служебных ключей (`_idpg_sessions|time|0|0|value` и т.п.), которые
	 * неудобно читать напрямую. При каждом сохранении записи актуальный
	 * список сеансов дублируется сюда простой JSON-строкой — её читает
	 * IDPG_Template_Helper::get_event_sessions().
	 *
	 * @var string
	 */
	const SESSIONS_MIRROR = '_idpg_sessions_json';

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
		// Приоритет 1 — чтобы блок встал выше остальных normal/high боксов (Основная, Билеты) и не ломал Carbon Fields.
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
	 * Carbon Fields к аккуратному виду.
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
				if ( current_user_can( 'event_organizer' ) && ! current_user_can( 'manage_categories' ) ) {
					echo '<script>(function(){["age_rating","city"].forEach(function(t){var a=document.getElementById(t+"-adder");if(a)a.style.display="none";var b=document.querySelector("#"+t+"div .category-add, #tagsdiv-"+t+" .category-add");if(b)b.style.display="none";});})();</script>';
				}
				// Принудительно раскрываем метабокс «Медиа» при загрузке страницы.
				echo '<script>
(function(){
  var id="carbon_fields_container_c24797c4";
  var box=document.getElementById(id);
  if(!box)return;
  if(box.classList.contains("closed")){
    var toggle=box.querySelector(".handlediv,button.handlediv");
    if(toggle) toggle.click();
  }
  var h=box.querySelector(".hndle, .handle");
  if(h&&!box.classList.contains("carbon-fields-media-open")){
    box.classList.add("carbon-fields-media-open");
  }
})();
</script>';
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
	 * Чекбоксы Carbon Fields хранят строку `yes` (или пустое значение),
	 * поэтому регистрируются как `string`, а не `boolean`.
	 *
	 * Complex-поле сеансов и media_gallery галереи здесь не регистрируются:
	 * Carbon Fields отдаёт их в REST через собственный сервис, а простые
	 * значения доступны через зеркало SESSIONS_MIRROR.
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
			'_idpg_venue_lat'      => array( 'type' => 'string' ),
			'_idpg_venue_lng'      => array( 'type' => 'string' ),
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
	// Carbon Fields
	// =========================================================================

	/**
	 * Регистрация полей админки через Carbon Fields.
	 *
	 * Вызывается на хуке carbon_fields_register_fields. Поля именуются
	 * без ведущего подчёркивания: Carbon Fields сохраняет их с ключом
	 * "_имя_поля", что даёт единый префикс меты `_idpg_`.
	 */
	public static function register_carbon_fields() {
		if ( ! class_exists( '\Carbon_Fields\Container' ) ) {
			return;
		}

		self::register_event_main_fields();
		self::register_event_sidebar_fields();
		self::register_event_ticket_fields();
		self::register_event_media_fields();
		self::register_venue_fields();

		add_filter( 'carbon_fields_before_field_save', array( __CLASS__, 'sanitize_carbon_field' ) );
	}

	/**
	 * Группа 1: основная информация (даты, весь день, сеансы).
	 */
	private static function register_event_main_fields() {
		\Carbon_Fields\Container::make( 'post_meta', __( 'Основная информация', 'idpg-core' ) )
			->where( 'post_type', '=', 'event' )
			->set_context( 'normal' )
			->set_priority( 'high' )
			->add_fields( array(
				\Carbon_Fields\Field::make( 'date', 'idpg_start_date', __( 'Дата начала', 'idpg-core' ) )
					->set_storage_format( 'Y-m-d' )
					->set_input_format( 'Y-m-d', 'Y-m-d' )
					->set_picker_options( array(
						'allowInput' => true,
						'altInput'   => false,
					) )
					->set_help_text( __( 'Укажите дату начала. Поле обязательно для публикации.', 'idpg-core' ) )
					->set_width( 33 ),
				\Carbon_Fields\Field::make( 'date', 'idpg_end_date', __( 'Дата окончания', 'idpg-core' ) )
					->set_storage_format( 'Y-m-d' )
					->set_input_format( 'Y-m-d', 'Y-m-d' )
					->set_picker_options( array(
						'allowInput' => true,
						'altInput'   => false,
					) )
					->set_help_text( __( 'Оставьте пустым для события одного дня.', 'idpg-core' ) )
					->set_width( 33 ),
				\Carbon_Fields\Field::make( 'checkbox', 'idpg_all_day', __( 'Весь день', 'idpg-core' ) )
					->set_help_text( __( 'Событие идёт весь день — время сеансов не указывается.', 'idpg-core' ) )
					->set_width( 33 ),
				\Carbon_Fields\Field::make( 'complex', 'idpg_sessions', __( 'Сеансы', 'idpg-core' ) )
					->set_layout( 'tabbed-horizontal' )
					->set_header_template( '<%- time %>' )
					->set_help_text( __( 'Время начала, цена и ссылка на билеты для каждого сеанса.', 'idpg-core' ) )
					->add_fields( array(
						\Carbon_Fields\Field::make( 'time', 'time', __( 'Время', 'idpg-core' ) )
							->set_storage_format( 'H:i' )
							->set_input_format( 'H:i', 'H:i' )
							->set_picker_options( array(
								'enableSeconds' => false,
								'time_24hr'     => true,
								'altInput'      => false,
							) )
							->set_help_text( __( 'Например: 19:00', 'idpg-core' ) )
							->set_width( 33 ),
						\Carbon_Fields\Field::make( 'text', 'price', __( 'Цена', 'idpg-core' ) )
							->set_attribute( 'type', 'number' )
							->set_attribute( 'min', '0' )
							->set_attribute( 'step', '0.01' )
							->set_help_text( __( 'Цена билета для этого сеанса.', 'idpg-core' ) )
							->set_width( 33 ),
						\Carbon_Fields\Field::make( 'text', 'url', __( 'Ссылка на билеты', 'idpg-core' ) )
							->set_attribute( 'type', 'url' )
							->set_attribute( 'placeholder', 'https://' )
							->set_help_text( __( 'Ссылка на покупку билета для этого сеанса (необязательно).', 'idpg-core' ) )
							->set_width( 33 ),
					) ),
			) );
	}

	/**
	 * Группы события в боковой панели редактора Gutenberg.
	 *
	 * «Место проведения» и «Дополнительно» объединены в единый сайдбар-контейнер
	 * с вкладками (add_tab). В правой панели Gutenberg поля попадают в отдельные
	 * табы вместо двух отдельных метабоксов.
	 */
	private static function register_event_sidebar_fields() {
		$place_fields = array(
			\Carbon_Fields\Field::make( 'select', 'idpg_venue', __( 'Площадка', 'idpg-core' ) )
				->set_options( array( __CLASS__, 'get_venue_options' ) )
				->set_help_text( __( 'Выберите площадку. Если её нет — укажите адрес вручную.', 'idpg-core' ) )
				->set_width( 100 ),
			\Carbon_Fields\Field::make( 'text', 'idpg_address', __( 'Адрес', 'idpg-core' ) )
				->set_help_text( __( 'Заполняется, если адрес отличается от адреса площадки.', 'idpg-core' ) )
				->set_width( 100 ),
		);

		\Carbon_Fields\Container::make( 'post_meta', __( 'Событие: место и продвижение', 'idpg-core' ) )
			->where( 'post_type', '=', 'event' )
			->set_context( 'side' )
			->set_priority( 'high' )
			->add_tab( __( 'Место проведения', 'idpg-core' ), $place_fields )
			->add_tab( __( 'Дополнительно', 'idpg-core' ), self::build_extra_tab_fields() );
	}

	/**
	 * Поля вкладки «Дополнительно».
	 *
	 * Часть полей — системные (просмотры, промо, баннеры, источник): они
	 * доступны только администраторам. Гейт здесь серверный (current_user_can),
	 * потому что клиентская условная логика Carbon Fields `current_user_capability`
	 * не поддерживается его Gutenberg-рантаймом и роняет редактор
	 * (серые «Опубликовать») у пользователей без прав.
	 *
	 * @return \Carbon_Fields\Field[] Массив полей вкладки.
	 */
	private static function build_extra_tab_fields() {
		$admin_fields = array();

		if ( current_user_can( 'manage_options' ) ) {
			// Источник импорта — системное поле, организаторы не видят.
			$admin_fields[] = \Carbon_Fields\Field::make( 'text', 'idpg_source', __( 'Источник импорта', 'idpg-core' ) )
				->set_help_text( __( 'Название сервиса, из которого импортировано событие.', 'idpg-core' ) )
				->set_width( 100 );

			// Закрепление в топе — платная услуга, управление системой.
			$admin_fields[] = \Carbon_Fields\Field::make( 'checkbox', 'idpg_promoted', __( 'Закрепить в топе', 'idpg-core' ) )
				->set_help_text( __( 'Событие поднимается наверх в списках и подборках.', 'idpg-core' ) )
				->set_width( 50 );

			// Срок закрепления: без него закрепление было бы бессрочным,
			// и снимать его мог бы только cron вручную.
			$admin_fields[] = \Carbon_Fields\Field::make( 'date', 'idpg_pin_expires', __( 'Закрепить до', 'idpg-core' ) )
				->set_help_text( __( 'Дата автоматического снятия закрепления. Пусто — закрепление бессрочное.', 'idpg-core' ) )
				->set_storage_format( 'Y-m-d' )
				->set_width( 50 );

			// Скрытие из баннеров — системные настройки, организаторы не видят.
			$admin_fields[] = \Carbon_Fields\Field::make( 'checkbox', 'idpg_hide_from_banner', __( 'Скрыть из баннера на главной', 'idpg-core' ) )
				->set_help_text( __( 'Событие не будет показываться в hero-баннере на главной странице.', 'idpg-core' ) )
				->set_width( 100 );

			$admin_fields[] = \Carbon_Fields\Field::make( 'checkbox', 'idpg_hide_full_banner', __( 'Скрыть баннер полностью', 'idpg-core' ) )
				->set_help_text( __( 'Событие не будет показано в баннере и не будет заменено следующим — баннер на главной скроется полностью.', 'idpg-core' ) )
				->set_width( 100 );

			// Счётчики — только чтение, организаторы не видят.
			$admin_fields[] = \Carbon_Fields\Field::make( 'text', 'idpg_views', __( 'Просмотры', 'idpg-core' ) )
				->set_attribute( 'type', 'number' )
				->set_attribute( 'min', '0' )
				->set_attribute( 'readOnly', 'readonly' )
				->set_help_text( __( 'Счётчик заполняется автоматически.', 'idpg-core' ) )
				->set_width( 50 );

			$admin_fields[] = \Carbon_Fields\Field::make( 'text', 'idpg_clicks', __( 'Клики', 'idpg-core' ) )
				->set_attribute( 'type', 'number' )
				->set_attribute( 'min', '0' )
				->set_attribute( 'readOnly', 'readonly' )
				->set_help_text( __( 'Счётчик заполняется автоматически.', 'idpg-core' ) )
				->set_width( 50 );

			// Подтверждение оплаты показа в баннере на главной.
			// Организатор подаёт заявку (idpg_banner_request), админ после
			// фактической оплаты ставит эту галочку — событие попадает в баннер.
			$admin_fields[] = \Carbon_Fields\Field::make( 'checkbox', 'idpg_banner_approved', __( 'Показ в баннере оплачен и подтверждён', 'idpg-core' ) )
				->set_help_text( __( 'Отметьте после получения оплаты за показ. Событие появится в баннере на главной (приоритет перед автоматическим показом).', 'idpg-core' ) )
				->set_width( 100 );
		}

		// Заявка организатора на показ события в баннере на главной.
		// Поле доступно всем ролям: организатор подаёт заявку, админ видит её
		// и подтверждает после оплаты через idpg_banner_approved.
		$request_field = \Carbon_Fields\Field::make( 'checkbox', 'idpg_banner_request', __( 'Подать заявку на показ в баннере на главной', 'idpg-core' ) )
			->set_help_text( __( 'Показ платный. После отправки заявки администратор свяжется с вами для оплаты; событие попадёт в баннер после подтверждения.', 'idpg-core' ) )
			->set_width( 100 );

		// Онлайн-событие — организатор может включать.
		return array_merge(
			$admin_fields,
			array(
				$request_field,
				\Carbon_Fields\Field::make( 'checkbox', 'idpg_is_online', __( 'Онлайн-событие', 'idpg-core' ) )
					->set_help_text( __( 'Отметьте, если событие проходит онлайн (трансляция в интернете).', 'idpg-core' ) )
					->set_width( 50 ),
				\Carbon_Fields\Field::make( 'text', 'idpg_stream_url', __( 'Ссылка на трансляцию', 'idpg-core' ) )
					->set_attribute( 'type', 'url' )
					->set_attribute( 'placeholder', 'https://' )
					->set_help_text( __( 'Ссылка на страницу/трансляцию, куда перейдут зрители.', 'idpg-core' ) )
					->set_width( 100 ),
			)
		);
	}

	/**
	 * Группа 3: билеты и цены.
	 */
	private static function register_event_ticket_fields() {
		\Carbon_Fields\Container::make( 'post_meta', __( 'Билеты и цены', 'idpg-core' ) )
			->where( 'post_type', '=', 'event' )
			->set_context( 'normal' )
			->set_priority( 'high' )
			->add_fields( array(
				\Carbon_Fields\Field::make( 'select', 'idpg_ticket_status', __( 'Статус билетов', 'idpg-core' ) )
					->set_default_value( 'in_sale' )
					->add_options( array(
						'in_sale'   => __( 'В продаже', 'idpg-core' ),
						'sold_out'  => __( 'Распроданы', 'idpg-core' ),
						'cancelled' => __( 'Отменено', 'idpg-core' ),
						'postponed' => __( 'Перенесено', 'idpg-core' ),
					) )
					->set_help_text( __( 'Укажите состояние продажи билетов — например «Распроданы», если мест нет.', 'idpg-core' ) )
					->set_width( 25 ),
				\Carbon_Fields\Field::make( 'checkbox', 'idpg_is_free', __( 'Бесплатное событие', 'idpg-core' ) )
					->set_help_text( __( 'Цены ниже игнорируются — на фронте выводится «Вход свободный».', 'idpg-core' ) )
					->set_width( 25 ),
				\Carbon_Fields\Field::make( 'select', 'idpg_currency', __( 'Валюта', 'idpg-core' ) )
					->set_default_value( 'RUB' )
					->add_options( array(
						'RUB' => 'RUB',
						'USD' => 'USD',
						'EUR' => 'EUR',
					) )
					->set_help_text( __( 'Валюта, в которой указаны цены билетов.', 'idpg-core' ) )
					->set_width( 25 ),
				\Carbon_Fields\Field::make( 'text', 'idpg_vip_price', __( 'VIP-цена', 'idpg-core' ) )
					->set_attribute( 'type', 'number' )
					->set_attribute( 'min', '0' )
					->set_attribute( 'step', '0.01' )
					->set_help_text( __( 'Цена VIP-билета. Оставьте пустым, если VIP-билетов нет.', 'idpg-core' ) )
					->set_width( 25 ),
				\Carbon_Fields\Field::make( 'text', 'idpg_price_from', __( 'Цена от', 'idpg-core' ) )
					->set_attribute( 'type', 'number' )
					->set_attribute( 'min', '0' )
					->set_attribute( 'step', '0.01' )
					->set_help_text( __( 'Минимальная цена билета.', 'idpg-core' ) )
					->set_width( 25 ),
				\Carbon_Fields\Field::make( 'text', 'idpg_price_to', __( 'Цена до', 'idpg-core' ) )
					->set_attribute( 'type', 'number' )
					->set_attribute( 'min', '0' )
					->set_attribute( 'step', '0.01' )
					->set_help_text( __( 'Максимальная цена билета.', 'idpg-core' ) )
					->set_width( 25 ),
				\Carbon_Fields\Field::make( 'select', 'idpg_ticket_type', __( 'Тип продажи билетов', 'idpg-core' ) )
					->set_default_value( 'external_link' )
					->add_options( array(
						'external_link' => __( 'Внешняя ссылка', 'idpg-core' ),
						'widget_yandex' => __( 'Виджет Яндекс.Билетов', 'idpg-core' ),
					) )
					->set_help_text( __( '«Внешняя ссылка» — кнопка ведёт на ваш сайт продаж; «Виджет Яндекс.Билетов» — вставьте код виджета ниже.', 'idpg-core' ) )
					->set_width( 25 ),
				\Carbon_Fields\Field::make( 'text', 'idpg_external_id', __( 'ID во внешней системе', 'idpg-core' ) )
					->set_help_text( __( 'Используется cron-синхронизацией статусов билетов.', 'idpg-core' ) )
					->set_width( 25 ),
				\Carbon_Fields\Field::make( 'text', 'idpg_ticket_url', __( 'URL страницы продажи', 'idpg-core' ) )
					->set_attribute( 'type', 'url' )
					->set_attribute( 'placeholder', 'https://' )
					->set_help_text( __( 'Ссылка, по которой посетители купят билет. Открывается в новой вкладке.', 'idpg-core' ) )
					->set_conditional_logic( array(
						array(
							'field' => 'idpg_ticket_type',
							'value' => 'external_link',
						),
					) ),
				\Carbon_Fields\Field::make( 'text', 'idpg_yandex_form_url', __( 'Ссылка на Яндекс.Форму', 'idpg-core' ) )
					->set_attribute( 'type', 'url' )
					->set_attribute( 'placeholder', 'https://forms.yandex.ru/' )
					->set_help_text( __( 'Организатор указывает ссылку на Яндекс.Форму — на странице события появится кнопка «Зарегистрироваться на событие».', 'idpg-core' ) ),
				\Carbon_Fields\Field::make( 'html', 'idpg_yandex_form_button' )
					->set_html(
						sprintf(
							'<a class="button" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
							esc_url( 'https://forms.yandex.ru' ),
							esc_html__( 'Создать Яндекс.Форму', 'idpg-core' )
						)
					),
				\Carbon_Fields\Field::make( 'textarea', 'idpg_widget_code', __( 'Код виджета', 'idpg-core' ) )
					->set_rows( 6 )
					->set_help_text( __( 'HTML/JS код виджета продажи билетов. Вставляется на страницу события как есть.', 'idpg-core' ) )
					->set_conditional_logic( array(
						array(
							'field' => 'idpg_ticket_type',
							'value' => 'widget_yandex',
						),
					) ),
			) );
	}

	/**
	 * Группа 4: медиа.
	 */
	private static function register_event_media_fields() {
		\Carbon_Fields\Container::make( 'post_meta', __( 'Медиа', 'idpg-core' ) )
			->where( 'post_type', '=', 'event' )
			->set_context( 'normal' )
			->set_priority( 'default' )
			->add_fields( array(
				\Carbon_Fields\Field::make( 'text', 'idpg_video_trailer', __( 'Видео-трейлер', 'idpg-core' ) )
					->set_attribute( 'type', 'url' )
					->set_attribute( 'placeholder', 'https://' )
					->set_help_text( __( 'Ссылка на трейлер: YouTube, VK Видео, Rutube.', 'idpg-core' ) ),
				\Carbon_Fields\Field::make( 'media_gallery', 'idpg_gallery', __( 'Галерея', 'idpg-core' ) )
					->set_type( 'image' )
					->set_duplicates_allowed( false )
					->set_help_text( __( 'Несколько изображений; порядок задаётся перетаскиванием.', 'idpg-core' ) ),
				// Код HTML-плеера для встраивания трансляции (YouTube, VK Video, Rutube).
				\Carbon_Fields\Field::make( 'textarea', 'idpg_stream_embed', __( 'Код встраивания трансляции', 'idpg-core' ) )
					->set_rows( 5 )
					->set_help_text( __( 'Вставьте HTML-код плеера для онлайн-трансляции. Доступно также в «Настройки организатора».', 'idpg-core' ) ),
			) );
	}

	/**
	 * Поля для CPT venue.
	 */
	private static function register_venue_fields() {
		\Carbon_Fields\Container::make( 'post_meta', __( 'Данные площадки', 'idpg-core' ) )
			->where( 'post_type', '=', 'venue' )
			->set_context( 'normal' )
			->set_priority( 'high' )
			->add_fields( array(
				\Carbon_Fields\Field::make( 'select', 'idpg_venue_type', __( 'Тип площадки', 'idpg-core' ) )
					->add_options( array(
						''        => __( '— Не выбран —', 'idpg-core' ),
						'theater' => __( 'Театр', 'idpg-core' ),
						'cinema'  => __( 'Кинотеатр', 'idpg-core' ),
						'museum'  => __( 'Музей', 'idpg-core' ),
						'concert' => __( 'Концертный зал', 'idpg-core' ),
					) )
					->set_width( 33 ),
				\Carbon_Fields\Field::make( 'text', 'idpg_venue_address', __( 'Адрес', 'idpg-core' ) )
					->set_width( 33 ),
				\Carbon_Fields\Field::make( 'text', 'idpg_venue_phone', __( 'Телефон', 'idpg-core' ) )
					->set_attribute( 'type', 'tel' )
					->set_width( 33 ),
				\Carbon_Fields\Field::make( 'text', 'idpg_venue_email', __( 'Email', 'idpg-core' ) )
					->set_attribute( 'type', 'email' )
					->set_width( 33 ),
				\Carbon_Fields\Field::make( 'text', 'idpg_venue_website', __( 'Сайт', 'idpg-core' ) )
					->set_attribute( 'type', 'url' )
					->set_attribute( 'placeholder', 'https://' )
					->set_width( 33 ),
				\Carbon_Fields\Field::make( 'text', 'idpg_venue_capacity', __( 'Вместимость', 'idpg-core' ) )
					->set_attribute( 'type', 'number' )
					->set_attribute( 'min', '0' )
					->set_width( 33 ),
				// Координаты для гео-разметки (Schema.org GeoCoordinates, карты).
				\Carbon_Fields\Field::make( 'text', 'idpg_venue_lat', __( 'Широта', 'idpg-core' ) )
					->set_attribute( 'type', 'text' )
					->set_attribute( 'placeholder', '56.8527' )
					->set_help_text( __( 'Десятичные градусы, например 56.8527. Используется для микроразметки GeoCoordinates.', 'idpg-core' ) )
					->set_width( 33 ),
				\Carbon_Fields\Field::make( 'text', 'idpg_venue_lng', __( 'Долгота', 'idpg-core' ) )
					->set_attribute( 'type', 'text' )
					->set_attribute( 'placeholder', '53.2115' )
					->set_help_text( __( 'Десятичные градусы, например 53.2115. Используется для микроразметки GeoCoordinates.', 'idpg-core' ) )
					->set_width( 33 ),
			) );
	}

	/**
	 * Список площадок для селекта.
	 *
	 * Вызывается лениво Carbon Fields при отрисовке поля.
	 *
	 * @return array Массив ID => Название.
	 */
	public static function get_venue_options() {
		$options = array( '0' => __( '— Не выбрана —', 'idpg-core' ) );

		$venues = get_posts( array(
			'post_type'   => 'venue',
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		) );

		foreach ( $venues as $venue ) {
			$options[ strval( $venue->ID ) ] = $venue->post_title;
		}

		return $options;
	}

	// =========================================================================
	// Зеркало сеансов
	// =========================================================================

	/**
	 * Дублирование complex-поля сеансов в простую JSON-строку.
	 *
	 * Carbon Fields хранит complex-поля в наборе служебных мета-ключей.
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

		if ( ! function_exists( 'carbon_get_post_meta' ) ) {
			return;
		}

		$rows     = carbon_get_post_meta( $post_id, 'idpg_sessions' );
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

	/**
	 * Санитизация значений Carbon Fields перед сохранением.
	 *
	 * @param \Carbon_Fields\Field\Field $field Поле.
	 * @return \Carbon_Fields\Field\Field
	 */
	public static function sanitize_carbon_field( $field ) {
		$name  = $field->get_base_name();
		$value = $field->get_value();

		switch ( $name ) {
			case 'idpg_venue':
				$field->set_value( absint( $value ) );
				break;

			case 'idpg_views':
			case 'idpg_clicks':
			case 'idpg_venue_capacity':
				$field->set_value( absint( $value ) );
				break;

			case 'idpg_ticket_url':
			case 'idpg_video_trailer':
			case 'idpg_stream_url':
			case 'idpg_venue_website':
				$field->set_value( esc_url_raw( $value ) );
				break;

			case 'idpg_widget_code':
				// Код виджета содержит <script>, поэтому сохраняется как есть,
				// но только для пользователей с правом unfiltered_html.
				if ( ! current_user_can( 'unfiltered_html' ) ) {
					$field->set_value( wp_kses_post( $value ) );
				}
				break;

			case 'idpg_stream_embed':
				// Код встраивания плеера — HTML-фрагмент.
				// Для организаторов (без unfiltered_html) очищаем через wp_kses_post.
				if ( ! current_user_can( 'unfiltered_html' ) ) {
					$field->set_value( wp_kses_post( $value ) );
				}
				break;
		}

		return $field;
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

		if ( current_user_can( 'administrator' ) ) {
			return;
		}

		if ( ! current_user_can( 'event_organizer' ) ) {
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
			'carbon_fields_container_c24797c4',
			'carbon_fields_container_6feccbaf',
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
			'carbon_fields_container_c24797c4',
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

add_action( 'carbon_fields_register_fields', array( 'IDPG_Meta', 'register_carbon_fields' ) );
add_action( 'carbon_fields_post_meta_container_saved', array( 'IDPG_Meta', 'mirror_sessions' ) );
