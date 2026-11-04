<?php
/** 
 * Настройки баннера на главной.
 * 
 * Отдельная вкладка «Баннер на главной» на странице «IDPG»: все
 * настройки вывода баннера как самостоятельного блока главной страницы —
 * вкл/выкл показа, авто-ротация карусели, интервал ротации, число
 * слайдов, срок кэша выбора события, а также флаг «Скрыть баннер
 * полностью» для конкретного события (перенесён сюда из вкладки
 * «События», мета `_idpg_hide_full_banner` та же). Настройки хранятся
 * как индивидуальные meta-поля с префиксом `_idpg_banner_` (как поля события),
 * что делает их независимыми от единого option-файла. Подставляются в баннер
 * через геттеры с fallback на дефолт. Настройка позиции (hero/middle/bottom)
 * удалена по решению владельца — баннер показывается только в hero,
 * как и до появления вкладки.
 * 
 * Мета-поля конкретных событий (заявка, подтверждение оплаты, скрытие)
 * остаются во вкладке «События» — здесь только глобальные параметры.
 * 
 * @package idpg-core
 */

 /** 
  * Класс IDPG_Banner_Settings
  */

class IDPG_Banner_Settings {

	/** 
	 * Слаг вкладки на странице «IDPG».
	 * 
	 * @var string
	 */ 
	const TAB_SLUG = 'banner';

	/** 
	 * Префикс meta-ключей для настроек баннера (как у полей события).
	 * 
	 * @var string
	 */ 
	const META_PREFIX = '_idpg_banner_';

	/** 
	 * Имя option с настройками баннера (оставляем для обратной совместимости при миграции).
	 * 
	 * @var string
	 */ 
	const OPTION = 'idpg_banner_settings';

	/** 
	 * Действие nonce формы.
	 * 
	 * @var string
	 */ 
	const NONCE_ACTION = 'idpg_save_banner_settings';

	/** 
	 * Имя поля nonce.
	 * 
	 * @var string
	 */ 
	const NONCE_NAME = 'idpg_banner_settings_nonce';

	/** 
	 * User meta одноразового сообщения «Настройки баннера сохранены.».
	 * 
	 * @var string
	 */ 
	const USER_SAVED_META = 'idpg_banner_tab_saved';

	/** 
	 * Поля баннера — тот же набор, что у карточки события.
	 * 
	 * Чекбоксы полей удалены из вкладки по решению владельца: все поля
	 * события выводятся в баннере всегда. Константа и механизм
	 * is_field_enabled()/sanitize оставлены для совместимости — рендер
	 * баннера их больше не использует.
	 * 
	 * @var array
	 */ 
	const FIELDS = array(
		'chips',
		'date',
		'time',
		'rubrics',
		'city',
		'age',
		'venue',
		'price',
		'vip',
		'favorites',
		'button',
		'excerpt',
	);

	/** 
	 * Подписи полей баннера для формы настроек.
	 * 
	 * @return array Массив ключ => подпись.
	 */ 
	public static function get_field_labels() {
		return array(
			'chips'     => __( 'Бейджи события (статус билетов, «Бесплатно», «Популярное», «ТОП», «Онлайн», «Новый»)', 'idpg-core' ),
			'date'      => __( 'Дата проведения', 'idpg-core' ),
			'time'      => __( 'Время сеанса («весь день»)', 'idpg-core' ),
			'rubrics'   => __( 'Рубрики (до двух)', 'idpg-core' ),
			'city'      => __( 'Город', 'idpg-core' ),
			'age'       => __( 'Возрастной рейтинг', 'idpg-core' ),
			'venue'     => __( 'Площадка и адрес', 'idpg-core' ),
			'price'     => __( 'Цена', 'idpg-core' ),
		'vip'       => __( 'VIP-цена', 'idpg-core' ),
		'favorites' => __( 'Сердце «в избранное»', 'idpg-core' ),
		'is_free'   => __( 'Бесплатное событие', 'idpg-core' ),
		'video'     => __( 'Видео-трейлер', 'idpg-core' ),
		'gallery'   => __( 'Галерея изображений', 'idpg-core' ),
		'stream'    => __( 'Ссылка на трансляцию', 'idpg-core' ),
		'ticket_status' => __( 'Статус билетов', 'idpg-core' ),
		'ticket_type' => __( 'Тип продажи', 'idpg-core' ),
		'yandex_form' => __( 'Яндекс.Форма', 'idpg-core' ),
		'widget_code' => __( 'Код виджета', 'idpg-core' ),
		'is_free'   => __( 'Бесплатное событие', 'idpg-core' ),
		'video'     => __( 'Видео-трейлер', 'idpg-core' ),
		'gallery'   => __( 'Галерея изображений', 'idpg-core' ),
		'stream'    => __( 'Ссылка на трансляцию', 'idpg-core' ),
		'ticket_status' => __( 'Статус билетов', 'idpg-core' ),
		'ticket_type' => __( 'Тип продажи', 'idpg-core' ),
		'yandex_form' => __( 'Яндекс.Форма', 'idpg-core' ),
		'widget_code' => __( 'Код виджета', 'idpg-core' ),
			'button'    => __( 'Кнопка «Подробнее»', 'idpg-core' ),
			'excerpt'   => __( 'Анонс события', 'idpg-core' ),
		);
	}

	/** 
	 * Синглтон.
	 * 
	 * @var IDPG_Banner_Settings|null
	 */ 
	private static $instance = null;

	/** 
	 * Получение экземпляра синглтона.
	 * 
	 * @return IDPG_Banner_Settings
	 */ 
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** 
	 * Конструктор: хуки вкладки и обработчика сохранения.
	 */ 
	private function __construct() {
		add_filter( 'idpg_widget_areas_admin_tabs', array( $this, 'register_admin_tab' ) );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Ассеты вкладки «Баннер на главной»: кастомный датапикер Pikaday.
	 *
	 * Вкладки живут на странице «Иду по городу» (idpg-widget-areas),
	 * поэтому проверяем хук этой страницы.
	 *
	 * @param string $hook_suffix Хук текущей админ-страницы.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_idpg-widget-areas' !== $hook_suffix ) {
			return;
		}

		$css_version = file_exists( IDPG_CORE_DIR . 'assets/css/pikaday.css' )
			? (string) filemtime( IDPG_CORE_DIR . 'assets/css/pikaday.css' )
			: IDPG_CORE_VERSION;

		$js_version = file_exists( IDPG_CORE_DIR . 'assets/js/admin-banner-fields.js' )
			? (string) filemtime( IDPG_CORE_DIR . 'assets/js/admin-banner-fields.js' )
			: IDPG_CORE_VERSION;

		wp_enqueue_style(
			'idpg-banner-pikaday',
			IDPG_CORE_URL . 'assets/css/pikaday.css',
			array(),
			$css_version
		);

		wp_enqueue_script(
			'idpg-banner-pikaday',
			IDPG_CORE_URL . 'assets/vendor/pikaday/pikaday.js',
			array(),
			'1.8.0',
			true
		);

		wp_enqueue_script(
			'idpg-banner-fields',
			IDPG_CORE_URL . 'assets/js/admin-banner-fields.js',
			array( 'idpg-banner-pikaday' ),
			$js_version,
			true
		);
	}

	/**
	 * Ожидает ли пользователь плашку «Настройки баннера сохранены.».
	 *
	 * Флаг ставится после сохранения и живёт до первого показа на вкладке
	 * «Банер на главной». Используется для рендера плашки; тост на всей
	 * админке подключает IDPG_Admin_Toasts.
	 *
	 * @return bool
	 */
	private function has_saved_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только чтение вкладки для показа плашки.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( self::TAB_SLUG !== $tab ) {
			return false;
		}

		return (bool) get_user_meta( get_current_user_id(), self::USER_SAVED_META, true );
	}

	/**
	 * Дефолтные настройки (массив ключ => значение по умолчанию).
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Показывать ли баннер на главной (самостоятельный блок).
			'enabled'            => 'yes',
			// Авто-ротация карусели (переключение по таймеру).
			'auto_rotate'        => 'yes',
			// Интервал авто-ротации карусели, мс.
			'carousel_interval'  => 6000,
			// Максимум слайдов в карусели.
			'paid_limit'         => 20,
			// События, выбранные для показа в баннере (ID, порядок — как в форме).
			'banner_event_ids'   => array(),
			// Срок кэша выбора баннера, минут.
			'cache_ttl'          => 10,
			// Свой заголовок баннера (пусто — название события).
			'banner_title'       => '',
			// Свой текст анонса (пусто — анонс события).
			'banner_text'        => '',
			// Текст кнопки «Подробнее» в баннере.
			'button_text'        => __( 'Подробнее', 'idpg-core' ),
			// Длина анонса в символах (0 — без обрезки).
			'excerpt_length'     => 0,
			// Высота баннера, px (на мобильных перебивается медиа-запросом).
			'banner_height'      => 400,

			// --- Собственные данные баннера (независимо от события) ---
			// Даты и время.
			'banner_start_date'      => '',
			'banner_end_date'        => '',
			'banner_all_day'         => '',
			'banner_time'            => '',
			// Место.
			'banner_address'         => '',
			'banner_is_online'       => '',
			// Билеты и цены.
			'banner_ticket_status'   => '',
			'banner_is_free'         => '',
			'banner_currency'        => 'RUB',
			'banner_price_from'      => '',
			'banner_price_to'        => '',
			'banner_vip_price'       => '',
			'banner_ticket_type'     => 'external_link',
			'banner_ticket_url'      => '',
			'banner_yandex_form_url' => '',
			// Медиа и формы.
			'banner_video_trailer'   => '',
			'banner_widget_code'     => '',
			'banner_stream_embed'    => '',
		);
	}

	/**
	 * Получение сохранённых настроек, дополненных дефолтами.
	 *
	 * Стратегия чтения (приоритет сверху вниз):
	 * 1. Индивидуальные option с префиксом `idpg_banner_*` — делает настройки
	 *    независимыми (каждое сохраняется отдельно, не зависит от одного option-файла).
	 * 2. Единый option `idpg_banner_settings` — для обратной совместимости с
	 *    существующими данными и тестами.
	 * 3. Дефолтные значения — если ничего не сохранено.
	 *
	 * @return array Массив настроек.
	 */
	public static function get_settings() {
		// Сначала пробуем единый option для обратной совместимости (тесты и старые данные).
		$stored = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && ! empty( $stored ) ) {
			return wp_parse_args( $stored, self::defaults() );
		}
		
		// Единого option нет или он пустой — читаем индивидуальные option с префиксом idpg_banner_*
		// Это делает настройки "самостоятельными" — не зависит от единого option-файла.
		$settings = array();
		$defaults = self::defaults();
		foreach ( $defaults as $key => $default ) {
			// Ключ в option без префикса _ (как в тестах: enabled, auto_rotate и т.д.)
			$option_key = $key;
			$value = get_option( 'idpg_banner_' . $option_key, '' );
			if ( '' !== $value ) {
				$settings[ $key ] = $value;
			} else {
				// Если в option пусто, проверяем, было ли сохранено ранее через старый формат
				// и оставляем дефолт.
				$settings[ $key ] = $default;
			}
		}
		
		return wp_parse_args( $settings, self::defaults() );
	}

	/** 
	 * Интервал авто-ротации карусели, мс.
	 * 
	 * @return int
	 */ 
	public static function get_carousel_interval() {
		$s = self::get_settings();
		return max( 2000, (int) $s['carousel_interval'] );
	}

	/** 
	 * Максимум оплаченных событий в карусели.
	 * 
	 * @return int
	 */ 
	public static function get_paid_limit() {
		$s = self::get_settings();
		return max( 1, min( 50, (int) $s['paid_limit'] ) );
	}

	/**
	 * События, выбранные для показа в баннере во вкладке «Банер на главной».
	 *
	 * Возвращаются только существующие опубликованные события CPT `event`;
	 * порядок — как в форме (порядок слайдов карусели).
	 *
	 * @return int[] ID событий.
	 */
	public static function get_banner_event_ids() {
		$s   = self::get_settings();
		$ids = ( isset( $s['banner_event_ids'] ) && is_array( $s['banner_event_ids'] ) )
			? $s['banner_event_ids']
			: array();

		$valid = array();
		foreach ( $ids as $id ) {
			$id = absint( $id );
			if ( ! $id || 'event' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			$valid[] = $id;
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * События для селектора баннера во вкладке «Банер на главной».
	 *
	 * @param int $limit Сколько событий брать.
	 * @return WP_Post[] Опубликованные события, сначала новые.
	 */
	public static function get_banner_events( $limit = 200 ) {
		return get_posts(
			array(
				'post_type'      => 'event',
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, (int) $limit ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
	}

	/** 
	 * Срок кэша выбора баннера, в секундах.
	 * 
	 * @return int
	 */ 
	public static function get_cache_ttl() {
		$s = self::get_settings();
		return max( 1, (int) $s['cache_ttl'] ) * MINUTE_IN_SECONDS;
	}

	/** 
	 * Текст кнопки в баннере (пустая строка → дефолт «Подробнее»).
	 * 
	 * @return string
	 */ 
	public static function get_button_text() {
		$s = self::get_settings();
		$t = isset( $s['button_text'] ) ? trim( (string) $s['button_text'] ) : '';
		return ( '' !== $t ) ? $t : __( 'Подробнее', 'idpg-core' );
	}

	/** 
	 * Свой заголовок баннера (пустая строка — использовать название события).
	 * 
	 * @return string
	 */ 
	public static function get_banner_title() {
		$s = self::get_settings();
		return isset( $s['banner_title'] ) ? trim( (string) $s['banner_title'] ) : '';
	}

	/** 
	 * Свой текст анонса баннера (пустая строка — использовать анонс события).
	 * 
	 * @return string
	 */ 
	public static function get_banner_text() {
		$s = self::get_settings();
		return isset( $s['banner_text'] ) ? trim( (string) $s['banner_text'] ) : '';
	}

	/** 
	 * Длина анонса в символах (0 — без обрезки).
	 * 
	 * @return int
	 */ 
	public static function get_excerpt_length() {
		$s = self::get_settings();
		return max( 0, min( 500, (int) $s['excerpt_length'] ) );
	}

	/** 
	 * Высота баннера, px.
	 * 
	 * @return int
	 */ 
	public static function get_banner_height() {
		$s = self::get_settings();
		return max( 200, min( 800, (int) $s['banner_height'] ) );
	}

	/**
	 * Определения полей ввода данных баннера.
	 *
	 * Единый источник правды для формы и санитизации: тип поля
	 * определяет и партиал вывода, и способ очистки значения.
	 *
	 * @return array Карта key => определение.
	 */
	public static function get_output_field_definitions() {
		return array(
			'banner_title'   => array(
				'id'          => 'idpg_banner_banner_title',
				'key'         => 'banner_title',
				'type'        => 'text',
				'label'       => __( 'Заголовок баннера', 'idpg-core' ),
				'description' => __( 'Свой заголовок поверх картинки. Пустое значение — название события.', 'idpg-core' ),
				'placeholder' => __( 'Название события', 'idpg-core' ),
				'default'     => '',
			),
			'banner_text'    => array(
				'id'          => 'idpg_banner_banner_text',
				'key'         => 'banner_text',
				'type'        => 'textarea',
				'label'       => __( 'Текст анонса', 'idpg-core' ),
				'description' => __( 'Свой текст под заголовком. Пустое значение — анонс события.', 'idpg-core' ),
				'rows'        => 3,
				'default'     => '',
			),
			'button_text'    => array(
				'id'          => 'idpg_banner_button_text',
				'key'         => 'button_text',
				'type'        => 'text',
				'label'       => __( 'Текст кнопки', 'idpg-core' ),
				'description' => __( 'Подпись на кнопке в баннере. Пустое значение — «Подробнее».', 'idpg-core' ),
				'default'     => __( 'Подробнее', 'idpg-core' ),
			),
			'excerpt_length' => array(
				'id'          => 'idpg_banner_excerpt_length',
				'key'         => 'excerpt_length',
				'type'        => 'number',
				'label'       => __( 'Длина анонса (символов)', 'idpg-core' ),
				'description' => __( 'Анонс события обрезается до указанной длины. 0 — показывать полностью.', 'idpg-core' ),
				'min'         => 0,
				'max'         => 500,
				'step'        => 10,
				'default'     => 0,
			),
			'banner_height'  => array(
				'id'          => 'idpg_banner_banner_height',
				'key'         => 'banner_height',
				'type'        => 'number',
				'label'       => __( 'Высота баннера (px)', 'idpg-core' ),
				'description' => __( 'Высота блока на десктопе, от 200 до 800 px. На мобильных фиксирована.', 'idpg-core' ),
				'min'         => 200,
				'max'         => 800,
				'step'        => 20,
				'default'     => 400,
			),

			// --- Даты и время ---
			'banner_start_date'      => array(
				'id'          => 'idpg_banner_banner_start_date',
				'key'         => 'banner_start_date',
				'type'        => 'date',
				'section'     => 'dates',
				'label'       => __( 'Дата начала', 'idpg-core' ),
				'description' => __( 'Пусто — дата начала события.', 'idpg-core' ),
				'default'     => '',
			),
			'banner_end_date'        => array(
				'id'          => 'idpg_banner_banner_end_date',
				'key'         => 'banner_end_date',
				'type'        => 'date',
				'section'     => 'dates',
				'label'       => __( 'Дата окончания', 'idpg-core' ),
				'description' => __( 'Пусто — дата окончания события.', 'idpg-core' ),
				'default'     => '',
			),
			'banner_all_day'         => array(
				'id'          => 'idpg_banner_banner_all_day',
				'key'         => 'banner_all_day',
				'type'        => 'select',
				'section'     => 'dates',
				'label'       => __( 'Весь день', 'idpg-core' ),
				'description' => __( 'Событие идёт весь день без конкретного времени.', 'idpg-core' ),
				'options'     => array(
					''    => __( 'Из события', 'idpg-core' ),
					'yes' => __( 'Да', 'idpg-core' ),
					'no'  => __( 'Нет', 'idpg-core' ),
				),
				'default'     => '',
			),
			'banner_time'            => array(
				'id'          => 'idpg_banner_banner_time',
				'key'         => 'banner_time',
				'type'        => 'text',
				'section'     => 'dates',
				'label'       => __( 'Время', 'idpg-core' ),
				'description' => __( 'Время начала, например 19:00. Пусто — время сеанса события.', 'idpg-core' ),
				'placeholder' => '19:00',
				'default'     => '',
			),

			// --- Место ---
			'banner_address'         => array(
				'id'          => 'idpg_banner_banner_address',
				'key'         => 'banner_address',
				'type'        => 'text',
				'section'     => 'place',
				'label'       => __( 'Адрес площадки', 'idpg-core' ),
				'description' => __( 'Пусто — адрес площадки события.', 'idpg-core' ),
				'placeholder' => __( 'ул. Пушкинская, 245', 'idpg-core' ),
				'default'     => '',
			),
			'banner_is_online'       => array(
				'id'          => 'idpg_banner_banner_is_online',
				'key'         => 'banner_is_online',
				'type'        => 'select',
				'section'     => 'place',
				'label'       => __( 'Онлайн-событие', 'idpg-core' ),
				'description' => __( 'В баннере выводится метка «Онлайн».', 'idpg-core' ),
				'options'     => array(
					''    => __( 'Из события', 'idpg-core' ),
					'yes' => __( 'Да', 'idpg-core' ),
					'no'  => __( 'Нет', 'idpg-core' ),
				),
				'default'     => '',
			),

			// --- Билеты и цены ---
			'banner_ticket_status'   => array(
				'id'          => 'idpg_banner_banner_ticket_status',
				'key'         => 'banner_ticket_status',
				'type'        => 'select',
				'section'     => 'tickets',
				'label'       => __( 'Статус билетов', 'idpg-core' ),
				'description' => __( 'Выводится бейджем в баннере.', 'idpg-core' ),
				'options'     => array(
					''          => __( 'Из события', 'idpg-core' ),
					'in_sale'   => __( 'В продаже', 'idpg-core' ),
					'sold_out'  => __( 'Распроданы', 'idpg-core' ),
					'cancelled' => __( 'Отменено', 'idpg-core' ),
					'postponed' => __( 'Перенесено', 'idpg-core' ),
				),
				'default'     => '',
			),
			'banner_is_free'         => array(
				'id'          => 'idpg_banner_banner_is_free',
				'key'         => 'banner_is_free',
				'type'        => 'select',
				'section'     => 'tickets',
				'label'       => __( 'Бесплатное событие', 'idpg-core' ),
				'description' => __( 'Цены игнорируются, выводится «Вход свободный».', 'idpg-core' ),
				'options'     => array(
					''    => __( 'Из события', 'idpg-core' ),
					'yes' => __( 'Да', 'idpg-core' ),
					'no'  => __( 'Нет', 'idpg-core' ),
				),
				'default'     => '',
			),
			'banner_currency'        => array(
				'id'          => 'idpg_banner_banner_currency',
				'key'         => 'banner_currency',
				'type'        => 'select',
				'section'     => 'tickets',
				'label'       => __( 'Валюта', 'idpg-core' ),
				'description' => __( 'Валюта цен баннера.', 'idpg-core' ),
				'options'     => array(
					'RUB' => 'RUB',
					'USD' => 'USD',
					'EUR' => 'EUR',
				),
				'default'     => 'RUB',
			),
			'banner_price_from'      => array(
				'id'          => 'idpg_banner_banner_price_from',
				'key'         => 'banner_price_from',
				'type'        => 'number',
				'section'     => 'tickets',
				'label'       => __( 'Цена от', 'idpg-core' ),
				'description' => __( 'Пусто — цена события.', 'idpg-core' ),
				'min'         => 0,
				'max'         => 1000000,
				'step'        => 50,
				'default'     => '',
			),
			'banner_price_to'        => array(
				'id'          => 'idpg_banner_banner_price_to',
				'key'         => 'banner_price_to',
				'type'        => 'number',
				'section'     => 'tickets',
				'label'       => __( 'Цена до', 'idpg-core' ),
				'description' => __( 'Пусто — цена события.', 'idpg-core' ),
				'min'         => 0,
				'max'         => 1000000,
				'step'        => 50,
				'default'     => '',
			),
			'banner_vip_price'       => array(
				'id'          => 'idpg_banner_banner_vip_price',
				'key'         => 'banner_vip_price',
				'type'        => 'number',
				'section'     => 'tickets',
				'label'       => __( 'VIP-цена', 'idpg-core' ),
				'description' => __( 'Пусто — VIP-цена события.', 'idpg-core' ),
				'min'         => 0,
				'max'         => 1000000,
				'step'        => 50,
				'default'     => '',
			),
			'banner_ticket_type'     => array(
				'id'          => 'idpg_banner_banner_ticket_type',
				'key'         => 'banner_ticket_type',
				'type'        => 'select',
				'section'     => 'tickets',
				'label'       => __( 'Тип продажи билетов', 'idpg-core' ),
				'description' => __( 'Куда ведёт кнопка баннера.', 'idpg-core' ),
				'options'     => array(
					'external_link' => __( 'Внешняя ссылка', 'idpg-core' ),
					'widget_yandex' => __( 'Виджет Яндекс.Билетов', 'idpg-core' ),
				),
				'default'     => 'external_link',
			),
			'banner_ticket_url'      => array(
				'id'          => 'idpg_banner_banner_ticket_url',
				'key'         => 'banner_ticket_url',
				'type'        => 'url',
				'section'     => 'tickets',
				'label'       => __( 'URL страницы продажи', 'idpg-core' ),
				'description' => __( 'Пусто — ссылка события.', 'idpg-core' ),
				'placeholder' => 'https://',
				'default'     => '',
			),
			'banner_yandex_form_url' => array(
				'id'          => 'idpg_banner_banner_yandex_form_url',
				'key'         => 'banner_yandex_form_url',
				'type'        => 'url',
				'section'     => 'tickets',
				'label'       => __( 'Ссылка на Яндекс.Форму', 'idpg-core' ),
				'description' => __( 'Пусто — ссылка события.', 'idpg-core' ),
				'placeholder' => 'https://forms.yandex.ru/',
				'default'     => '',
			),

			// --- Медиа и формы ---
			'banner_video_trailer'   => array(
				'id'          => 'idpg_banner_banner_video_trailer',
				'key'         => 'banner_video_trailer',
				'type'        => 'url',
				'section'     => 'media',
				'label'       => __( 'Видео-трейлер', 'idpg-core' ),
				'description' => __( 'YouTube, VK Видео, Rutube. Пусто — трейлер события.', 'idpg-core' ),
				'placeholder' => 'https://',
				'default'     => '',
			),
			'banner_widget_code'     => array(
				'id'          => 'idpg_banner_banner_widget_code',
				'key'         => 'banner_widget_code',
				'type'        => 'textarea',
				'section'     => 'media',
				'label'       => __( 'Код виджета', 'idpg-core' ),
				'description' => __( 'HTML-код виджета продаж. Пусто — код события.', 'idpg-core' ),
				'sanitize'    => 'html',
				'rows'        => 4,
				'default'     => '',
			),
			'banner_stream_embed'    => array(
				'id'          => 'idpg_banner_banner_stream_embed',
				'key'         => 'banner_stream_embed',
				'type'        => 'textarea',
				'section'     => 'media',
				'label'       => __( 'Код встраивания трансляции', 'idpg-core' ),
				'description' => __( 'iframe-плеер прямого эфира. Пусто — код события.', 'idpg-core' ),
				'sanitize'    => 'html',
				'rows'        => 4,
				'default'     => '',
			),
		);
	}

	/**
	 * Текущее значение поля ввода по его ключу.
	 *
	 * @param string $key Ключ поля (banner_title, button_text, ...).
	 * @return mixed
	 */
	public static function get_output_value( $key ) {
		$definitions = self::get_output_field_definitions();
		if ( ! isset( $definitions[ $key ] ) ) {
			return '';
		}

		$settings = self::get_settings();
		$default  = $definitions[ $key ]['default'];

		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Рендер одного поля настроек через PHP-партиал по типу.
	 *
	 * Партиалы: templates/partials/banner-settings/field-{type}.php.
	 * Неизвестный тип партиала не выводится (safelist).
	 *
	 * @param array $field Определение поля.
	 * @return void
	 */
	public static function render_output_field( $field ) {
		$allowed = array( 'text', 'textarea', 'number', 'date', 'checkbox', 'select', 'url' );
		if ( ! isset( $field['type'] ) || ! in_array( $field['type'], $allowed, true ) ) {
			return;
		}

		$partial = IDPG_CORE_DIR . 'templates/partials/banner-settings/field-' . $field['type'] . '.php';
		if ( ! file_exists( $partial ) ) {
			return;
		}

		$value = self::get_output_value( $field['key'] );

		// Переменные для партиала: $field и $value.
		include $partial;
	}

	/**
	 * Включён ли блок баннера на главной.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$s = self::get_settings();
		return 'yes' === $s['enabled'];
	}

	/** 
	 * Включена ли авто-ротация карусели.
	 * 
	 * @return bool
	 */ 
	public static function is_auto_rotate() {
		$s = self::get_settings();
		return 'yes' === $s['auto_rotate'];
	}

	/** 
	 * Выводится ли поле баннера.
	 * 
	 * Неизвестное поле всегда выключено; для полей, которых нет в настройках,
	 * действует дефолт (включено).
	 * 
	 * @param string $field Ключ поля из self::FIELDS.
	 * @return bool
	 */ 
	public static function is_field_enabled( $field ) {
		if ( ! in_array( $field, self::FIELDS, true ) ) {
			return false;
		}

		$s      = self::get_settings();
		$fields = ( isset( $s['fields'] ) && is_array( $s['fields'] ) ) ? $s['fields'] : array();

		if ( ! array_key_exists( $field, $fields ) ) {
			return true;
		}

		return 'yes' === $fields[ $field ];
	}

	/**
	 * Безопасная санитизация текстового поля формы.
	 *
	 * sanitize_text_field()/sanitize_textarea_field() внутри вызывают
	 * wp_check_invalid_utf8(), который приводит аргумент к строке —
	 * массив во входящих данных даёт "Array to string conversion".
	 * Хелпер молча возвращает пустую строку для массивов/объектов.
	 *
	 * @param array  $raw  Данные $_POST.
	 * @param string $key  Ключ поля.
	 * @param string $mode 'text' или 'textarea'.
	 * @return string
	 */
	public static function sanitize_text_value( $raw, $key, $mode = 'text' ) {
		if ( ! is_array( $raw ) || ! isset( $raw[ $key ] ) ) {
			return '';
		}

		$value = wp_unslash( $raw[ $key ] );

		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$value = (string) $value;

		return ( 'textarea' === $mode )
			? sanitize_textarea_field( $value )
			: sanitize_text_field( $value );
	}

	/**
	 * Санитизация значения одного поля данных баннера по его определению.
	 *
	 * Тип поля задаёт способ очистки:
	 * - number   — absint + зажатие в min/max; пусто → дефолт (если он числовой)
	 *              или пустая строка (чтобы работал fallback на данные события);
	 * - checkbox — 'yes'/'no';
	 * - select   — только значение из options (safelist), иначе дефолт;
	 * - url      — esc_url_raw;
	 * - date     — только Y-m-d;
	 * - textarea — sanitize_textarea_field;
	 * - text     — sanitize_text_field.
	 *
	 * @param array $field Определение поля.
	 * @param array $raw   Данные $_POST.
	 * @return string|int
	 */
	public static function sanitize_output_field_value( $field, $raw ) {
		$id      = isset( $field['id'] ) ? $field['id'] : '';
		$type    = isset( $field['type'] ) ? $field['type'] : 'text';
		$default = isset( $field['default'] ) ? $field['default'] : '';

		switch ( $type ) {
			case 'number':
				$is_empty = ! isset( $raw[ $id ] ) || '' === $raw[ $id ] || is_array( $raw[ $id ] );
				if ( $is_empty ) {
					// Числовые настройки (высота, длина анонса) возвращаются
					// к дефолту; цены — к пустой строке (fallback на событие).
					return ( '' === $default ) ? '' : (int) $default;
				}
				$value = absint( $raw[ $id ] );
				$min   = isset( $field['min'] ) ? (int) $field['min'] : 0;
				$max   = isset( $field['max'] ) ? (int) $field['max'] : PHP_INT_MAX;
				return max( $min, min( $max, $value ) );

			case 'checkbox':
				return isset( $raw[ $id ] ) ? 'yes' : 'no';

			case 'select':
				$value   = self::sanitize_text_value( $raw, $id, 'text' );
				$options = ( isset( $field['options'] ) && is_array( $field['options'] ) ) ? $field['options'] : array();
				if ( '' === $value ) {
					return $default;
				}
				return array_key_exists( $value, $options ) ? $value : $default;

			case 'url':
				$value = self::sanitize_text_value( $raw, $id, 'text' );
				return ( '' !== $value ) ? esc_url_raw( $value ) : '';

			case 'date':
				$value = self::sanitize_text_value( $raw, $id, 'text' );
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';

			case 'textarea':
				// Код виджета и iframe трансляции — HTML-фрагменты:
				// сохраняем разметку (как в полях события).
				if ( isset( $field['sanitize'] ) && 'html' === $field['sanitize'] ) {
					if ( ! isset( $raw[ $id ] ) || is_array( $raw[ $id ] ) ) {
						return '';
					}
					$html = (string) wp_unslash( $raw[ $id ] );
					return current_user_can( 'unfiltered_html' ) ? $html : wp_kses_post( $html );
				}
				return self::sanitize_text_value( $raw, $id, 'textarea' );

			default:
				return self::sanitize_text_value( $raw, $id, 'text' );
		}
	}

	/**
	 * Санитизация присланных значений формы (вход не доверяем).
	 *
	 * Вызывается из handle_save() перед записью: чекбоксы приводятся к 'yes'/'no'
	 * (нет поля в POST — выключен), числа — absint с зажатием в допустимые диапазоны.
	 * Результат сохраняется как отдельные option с префиксом idpg_banner_* (делает
	 * настройки независимыми), и также обновляет единый option для обратной
	 * совместимости с существующими данными и тестами.
	 *
	 * @param array $raw Данные $_POST.
	 * @return array Массив настроек для записи.
	 */
	public static function sanitize_settings( $raw ) {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$defaults = self::defaults();

		// Санитизация переключателей (чекбоксы) — ключи формы начинаются с idpg_banner_
		$enabled     = isset( $raw['idpg_banner_enabled'] ) && 'yes' === sanitize_key( wp_unslash( $raw['idpg_banner_enabled'] ) )
			? 'yes'
			: 'no';

		$auto_rotate = isset( $raw['idpg_banner_autorotate'] ) && 'yes' === sanitize_key( wp_unslash( $raw['idpg_banner_autorotate'] ) )
			? 'yes'
			: 'no';

		// Числовые поля с диапазонами
		$interval = isset( $raw['idpg_banner_interval'] ) ? absint( $raw['idpg_banner_interval'] ) : $defaults['carousel_interval'];
		$paid     = isset( $raw['idpg_banner_paid_limit'] ) ? absint( $raw['idpg_banner_paid_limit'] ) : $defaults['paid_limit'];
		$ttl      = isset( $raw['idpg_banner_cache_ttl'] ) ? absint( $raw['idpg_banner_cache_ttl'] ) : $defaults['cache_ttl'];

		// Поля ввода данных баннера — санитизация по определениям
		// (get_output_field_definitions()): тип поля задаёт способ очистки,
		// sanitize_text_value() защищает от массивов во входящих данных.
		$output_values = array();
		foreach ( self::get_output_field_definitions() as $key => $field ) {
			$output_values[ $key ] = self::sanitize_output_field_value( $field, $raw );
		}

		// Пустой текст кнопки → дефолт «Подробнее».
		if ( '' === $output_values['button_text'] ) {
			$output_values['button_text'] = $defaults['button_text'];
		}

		// Поля баннера: чекбоксы формы, неизвестные ключи отбрасываются.
		//
		// Чекбоксов полей в форме вкладки больше нет, поэтому при их
		// отсутствии сохраняем прежний набор, а не сбрасываем всё в «no».
		// Если хотя бы один ключ пришёл (старая форма/тесты) — снимаем
		// неустановленные флаги, как раньше.
		$field_keys_sent = false;
		foreach ( self::FIELDS as $field ) {
			if ( array_key_exists( 'idpg_banner_field_' . $field, $raw ) ) {
				$field_keys_sent = true;
				break;
			}
		}

		$fields = array();
		if ( $field_keys_sent ) {
			foreach ( self::FIELDS as $field ) {
				$fields[ $field ] = isset( $raw[ 'idpg_banner_field_' . $field ] ) ? 'yes' : 'no';
			}
		} else {
			$current  = self::get_settings();
			$existing = ( isset( $current['fields'] ) && is_array( $current['fields'] ) ) ? $current['fields'] : array();
			foreach ( self::FIELDS as $field ) {
				$fields[ $field ] = ( array_key_exists( $field, $existing ) && 'no' === $existing[ $field ] ) ? 'no' : 'yes';
			}
		}

		// Формируем массив для записи — индивидуальные option (независимые) и единый option (обратная совместимость)
		$individual_settings = array(
			'enabled'            => $enabled,
			'auto_rotate'        => $auto_rotate,
			'carousel_interval'  => max( 2000, $interval ),
			'paid_limit'         => max( 1, min( 50, $paid ) ),
			'cache_ttl'          => max( 1, $ttl ),
		);

		// Все поля данных баннера добавляются автоматически по определениям.
		foreach ( $output_values as $output_key => $output_value ) {
			$individual_settings[ $output_key ] = $output_value;
		}

		$individual_settings['fields'] = $fields;

		// События баннера: только существующие опубликованные CPT `event`.
		// Пустой выбор — баннер не выводится (автоподстановки нет).
		$event_ids = array();
		if ( isset( $raw['idpg_banner_event_ids'] ) ) {
			$raw_event_ids = $raw['idpg_banner_event_ids'];
			if ( ! is_array( $raw_event_ids ) ) {
				$raw_event_ids = array( $raw_event_ids );
			}

			foreach ( $raw_event_ids as $raw_event_id ) {
				$event_id = absint( $raw_event_id );
				if ( ! $event_id || 'event' !== get_post_type( $event_id ) ) {
					continue;
				}
				if ( 'publish' !== get_post_status( $event_id ) ) {
					continue;
				}
				$event_ids[] = $event_id;
			}
		}
		$individual_settings['banner_event_ids'] = array_values( array_unique( $event_ids ) );

		// Обновляем индивидуальные option (делает настройки независимыми)
		foreach ( $individual_settings as $key => $value ) {
			// Скаляры и массивы (список событий баннера) сохраняем как option.
			if ( is_string( $value ) || is_bool( $value ) || is_int( $value ) || is_array( $value ) ) {
				update_option( 'idpg_banner_' . $key, $value );
			}
		}

		// Также обновляем единый option для обратной совместимости (есть старые данные и тесты)
		update_option( self::OPTION, $individual_settings, false );

		return $individual_settings;
	}

	/** 
	 * События для селектора «Скрыть баннер полностью».
	 * 
	 * @param int $limit Сколько событий брать (без учёта уже отмеченных).
	 * @return array Массив объектов WP_Post.
	 */ 
	public static function get_hide_events( $limit = 100 ) {
		$events = get_posts(
			array(
				'post_type'      => 'event',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$ids = array_map( 'intval', wp_list_pluck( $events, 'ID' ) );

		$flagged = get_posts(
			array(
				'post_type'      => 'event',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => -1,
				'meta_key'       => IDPG_Current_Event_Banner::META_HIDE_FULL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		foreach ( $flagged as $event ) {
			if ( ! in_array( (int) $event->ID, $ids, true ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/** 
	 * Запись флага «Скрыть баннер полностью» для выбранного события.
	 * 
	 * @param array $raw Данные $_POST.
	 * @return int ID события, если мета обновлена; иначе 0.
	 */ 
	public static function save_hide_full_flag( $raw ) {
		if ( ! is_array( $raw ) ) {
			return 0;
		}

		$event_id = isset( $raw['idpg_hide_event_id'] ) ? absint( $raw['idpg_hide_event_id'] ) : 0;

		if ( ! $event_id || 'event' !== get_post_type( $event_id ) ) {
			return 0;
		}

		$flag = isset( $raw['idpg_hide_full_banner'] ) && 'yes' === sanitize_key( wp_unslash( $raw['idpg_hide_full_banner'] ) )
			? 'yes'
			: '';

		update_post_meta( $event_id, IDPG_Current_Event_Banner::META_HIDE_FULL, $flag );

		return $event_id;
	}

	/** 
	 * Регистрация вкладки «Баннер на главной».
	 * 
	 * @param array $tabs Карта вкладок `slug => ['label','callback']`.
	 * @return array
	 */ 
	public function register_admin_tab( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			$tabs = array();
		}
		$tabs[ self::TAB_SLUG ] = array(
			'label'    => __( 'Баннер на главной', 'idpg-core' ),
			'callback' => array( $this, 'render_admin_tab' ),
		);
		return $tabs;
	}

	/** 
	 * Рендер вкладки: скрытие баннера по событию + глобальные настройки.
	 */ 
	public function render_admin_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s = self::get_settings();

		// Событие, выбранное для показа в баннере (первое из сохранённых).
		$banner_event_ids   = self::get_banner_event_ids();
		$banner_selected_id = ! empty( $banner_event_ids ) ? (int) $banner_event_ids[0] : 0;
		$banner_events      = self::get_banner_events();

		// Событие для флага «Скрыть баннер полностью» — параметр
		// hide_event в URL (выпадающий селектор с вкладки удалён
		// по решению владельца).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$hide_event_id = isset( $_GET['hide_event'] ) ? absint( $_GET['hide_event'] ) : 0;
		$hide_event    = $hide_event_id ? get_post( $hide_event_id ) : null;

		if ( $hide_event && 'event' !== $hide_event->post_type ) {
			$hide_event = null;
		}

		/* 
		 * Сообщение после сохранения — одноразовое.
		 * 
		 * Ставится user meta в handle_save() и читается здесь только на
		 * активной вкладке «Баннер на главной»: callbacks вкладок страницы
		 * IDPG выполняются все при каждой загрузке, поэтому без проверки
		 * вкладки плашка дублировалась бы в видимом контейнере «События»
		 * (код idpg_msg=saved в URL общий). Мета удаляется после показа —
		 * F5, назад/вперёд и возвраты на вкладку не дублируют плашку
		 * (как «Поля события сохранены.» на вкладке «События»).
		 */ 
		if ( $this->has_saved_notice() ) {
			delete_user_meta( get_current_user_id(), self::USER_SAVED_META );
			?>
			<div class="notice notice-success is-dismissible idpg-banner-saved-notice" hidden><p><?php esc_html_e( 'Настройки баннера сохранены.', 'idpg-core' ); ?></p></div>
			<noscript><style>.idpg-banner-saved-notice[hidden]{display:block !important;}</style></noscript>
			<?php
		}
		?>
		<h2 class="idpg-privacy-section-title"><?php esc_html_e( 'Баннер на главной', 'idpg-core' ); ?></h2>

		<p class="idpg-privacy-tools__text">
			<?php esc_html_e( 'Все настройки вывода баннера событий как самостоятельного блока главной страницы. Флаг «Скрыть баннер полностью» для конкретного события — в разделе ниже; заявки организаторов и подтверждение оплаты остаются во вкладке «События».', 'idpg-core' ); ?>
		</p>

		<h2 class="idpg-privacy-section-title"><?php esc_html_e( 'Скрыть баннер полностью', 'idpg-core' ); ?></h2>

		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="idpg_banner_action" value="save">

			<?php if ( $hide_event ) : ?>
				<div class="idpg-option-field">
					<label class="idpg-option-field-label" for="idpg_hide_full_banner">
						<span><?php esc_html_e( 'Скрыть баннер полностью', 'idpg-core' ); ?></span>
					</label>
					<div class="idpg-option-field-body">
						<label for="idpg_hide_full_banner">
							<input type="checkbox" id="idpg_hide_full_banner" name="idpg_hide_full_banner" value="yes" <?php checked( get_post_meta( $hide_event->ID, IDPG_Current_Event_Banner::META_HIDE_FULL, true ), 'yes' ); ?>>
							<?php
							/* translators: %s: название события. */
							printf( esc_html__( 'Скрыть баннер для события «%s»', 'idpg-core' ), esc_html( $hide_event->post_title ) );
							?>
						</label>
						<input type="hidden" name="idpg_hide_event_id" value="<?php echo esc_attr( (string) $hide_event->ID ); ?>">
						<p class="description"><?php esc_html_e( 'Событие не будет показано в баннере и не будет заменено следующим — баннер на главной скроется полностью.', 'idpg-core' ); ?></p>
					</div>
				</div>
			<?php endif; ?>

			<h2 class="idpg-privacy-section-title"><?php esc_html_e( 'События баннера', 'idpg-core' ); ?></h2>

			<div class="idpg-option-field">
				<label class="idpg-option-field-label" for="idpg_banner_event_ids">
					<span><?php esc_html_e( 'Событие для показа', 'idpg-core' ); ?></span>
				</label>
				<div class="idpg-option-field-body">
					<select id="idpg_banner_event_ids" name="idpg_banner_event_ids[]" class="idpg-banner-events-select">
						<option value="" <?php selected( 0, $banner_selected_id ); ?>><?php esc_html_e( '— Не выбрано —', 'idpg-core' ); ?></option>
						<?php foreach ( $banner_events as $banner_event ) : ?>
							<?php
							$banner_start = (string) get_post_meta( $banner_event->ID, IDPG_Current_Event_Banner::META_START, true );
							$banner_label = $banner_event->post_title;
							if ( '' !== $banner_start ) {
								$banner_label .= ' — ' . $banner_start;
							}
							?>
							<option value="<?php echo esc_attr( (string) $banner_event->ID ); ?>" <?php selected( (int) $banner_event->ID, $banner_selected_id ); ?>>
								<?php echo esc_html( $banner_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Выберите событие из списка. Баннер показывает только выбранное событие; без выбора баннер не выводится. Закончившиеся события пропускаются.', 'idpg-core' ); ?>
					</p>
				</div>
			</div>

			<h2 class="idpg-privacy-section-title"><?php esc_html_e( 'Глобальные параметры', 'idpg-core' ); ?></h2>

			<div class="idpg-option-field">
				<label class="idpg-option-field-label" for="idpg_banner_enabled">
					<span><?php esc_html_e( 'Показывать баннер', 'idpg-core' ); ?></span>
				</label>
				<div class="idpg-option-field-body">
					<label for="idpg_banner_enabled">
						<input type="checkbox" id="idpg_banner_enabled" name="idpg_banner_enabled" value="yes" <?php checked( $s['enabled'], 'yes' ); ?>>
						<?php esc_html_e( 'Включить блок баннера на главной', 'idpg-core' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Если выключить — баннер не выводится ни в одной позиции, данные и кэш при этом не трогаются.', 'idpg-core' ); ?></p>
				</div>
			</div>

			<div class="idpg-option-field">
				<label class="idpg-option-field-label" for="idpg_banner_autorotate">
					<span><?php esc_html_e( 'Авто-ротация карусели', 'idpg-core' ); ?></span>
				</label>
				<div class="idpg-option-field-body">
					<label for="idpg_banner_autorotate">
						<input type="checkbox" id="idpg_banner_autorotate" name="idpg_banner_autorotate" value="yes" <?php checked( $s['auto_rotate'], 'yes' ); ?>>
						<?php esc_html_e( 'Переключать слайды автоматически', 'idpg-core' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'При выключении слайды переключаются только вручную — точками и свайпом. Интервал ниже при этом не используется.', 'idpg-core' ); ?></p>
				</div>
			</div>

			<div class="idpg-option-field">
				<label class="idpg-option-field-label" for="idpg_banner_interval">
					<span><?php esc_html_e( 'Интервал ротации (мс)', 'idpg-core' ); ?></span>
				</label>
				<div class="idpg-option-field-body">
					<input type="number" min="2000" step="500" id="idpg_banner_interval" name="idpg_banner_interval" value="<?php echo esc_attr( (string) $s['carousel_interval'] ); ?>" class="small-text">
					<p class="description"><?php esc_html_e( 'Как часто карусель оплаченных событий переключает слайд. Минимум 2000 мс.', 'idpg-core' ); ?></p>
				</div>
			</div>

			<div class="idpg-option-field">
				<label class="idpg-option-field-label" for="idpg_banner_paid_limit">
					<span><?php esc_html_e( 'Слайдов в карусели', 'idpg-core' ); ?></span>
				</label>
				<div class="idpg-option-field-body">
					<input type="number" min="1" max="50" step="1" id="idpg_banner_paid_limit" name="idpg_banner_paid_limit" value="<?php echo esc_attr( (string) $s['paid_limit'] ); ?>" class="small-text">
					<p class="description"><?php esc_html_e( 'Максимум подтверждённых (оплаченных) событий в карусели на главной.', 'idpg-core' ); ?></p>
				</div>
			</div>

			<div class="idpg-option-field">
				<label class="idpg-option-field-label" for="idpg_banner_cache_ttl">
					<span><?php esc_html_e( 'Срок кэша (мин)', 'idpg-core' ); ?></span>
				</label>
				<div class="idpg-option-field-body">
					<input type="number" min="1" step="1" id="idpg_banner_cache_ttl" name="idpg_banner_cache_ttl" value="<?php echo esc_attr( (string) $s['cache_ttl'] ); ?>" class="small-text">
					<p class="description"><?php esc_html_e( 'Как долго кэшируется выбор баннера по городу. Кэш сбрасывается при сохранении или удалении события.', 'idpg-core' ); ?></p>
				</div>
			</div>

			<?php
			/*
			 * Поля баннера рендерятся через PHP-партиалы и сгруппированы
			 * по секциям: определения — get_output_field_definitions(),
			 * вывод — render_output_field() по типу поля.
			 * Пустые значения — fallback на данные события.
			 */
			$idpg_sections = array(
				'main'    => array(
					__( 'Данные баннера', 'idpg-core' ),
					__( 'Поля ввода, которые выводятся в баннере на главной. Пустые значения — данные события.', 'idpg-core' ),
				),
				'dates'   => array(
					__( 'Даты и время', 'idpg-core' ),
					__( 'Собственные даты баннера. Пустые — даты события.', 'idpg-core' ),
				),
				'place'   => array(
					__( 'Место', 'idpg-core' ),
					__( 'Адрес и формат проведения. Пустые — данные площадки события.', 'idpg-core' ),
				),
				'tickets' => array(
					__( 'Билеты и цены', 'idpg-core' ),
					__( 'Собственные цены и статус билетов. Пустые — данные события.', 'idpg-core' ),
				),
				'media'   => array(
					__( 'Медиа и формы', 'idpg-core' ),
					__( 'Трейлер, виджеты и трансляция. Пустые — данные события.', 'idpg-core' ),
				),
			);

			$idpg_definitions = self::get_output_field_definitions();

			foreach ( $idpg_sections as $idpg_section_key => $idpg_section_meta ) {
				$idpg_section_fields = array();
				foreach ( $idpg_definitions as $idpg_field ) {
					$idpg_field_section = isset( $idpg_field['section'] ) ? $idpg_field['section'] : 'main';
					if ( $idpg_field_section === $idpg_section_key ) {
						$idpg_section_fields[] = $idpg_field;
					}
				}

				if ( empty( $idpg_section_fields ) ) {
					continue;
				}

				$section_title       = $idpg_section_meta[0];
				$section_description = $idpg_section_meta[1];
				include IDPG_CORE_DIR . 'templates/partials/banner-settings/section.php';

				foreach ( $idpg_section_fields as $idpg_field ) {
					self::render_output_field( $idpg_field );
				}
			}
			?>


			<p class="idpg-save-container">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Сохранить настройки баннера', 'idpg-core' ); ?><span class="idpg-ico-help" aria-hidden="true" title="<?php esc_attr_e( 'Что такое это поле?', 'idpg-core' ); ?>"></span></button>
			</p>
		</form>
		<?php
	}

	/** 
	 * Обработчик сохранения настроек (admin_init).
	 */ 
	public function handle_save() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверяется ниже.
		if ( ! isset( $_POST['idpg_banner_action'] ) || 'save' !== $_POST['idpg_banner_action'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав для сохранения настроек баннера.', 'idpg-core' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		// Флаг «Скрыть баннер полностью» — для выбранного в селекторе
		// события; 0, если событие не выбрано — мета не трогается.
		$hide_event_id = self::save_hide_full_flag( $_POST );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- санитизация в sanitize_settings().
		// Обновляем настройки: теперь они сохраняются как индивидуальные option,
		// что делает их независимыми, но также обновляем единый option для обратной совместимости.
		self::sanitize_settings( $_POST );

		// Новые настройки и флаг события вступают в силу сразу —
		// сбрасываем кэш выбора (уже с учётом записанной меты).
		if ( class_exists( 'IDPG_Current_Event_Banner' ) ) {
			IDPG_Current_Event_Banner::instance()->purge_cache();
		}

		/* 
		 * Одноразовое «Настройки баннера сохранены.»: код кладём в user
		 * meta, а не в URL — иначе idpg_msg=saved висел бы в адресе при
		 * JS-переключении вкладок и F5, и плашка повторялась бы при
		 * каждом возврате на вкладку «Баннер на главной» (как это было
		 * решено для вкладки «События»).
		 */ 
		update_user_meta( get_current_user_id(), self::USER_SAVED_META, 1 );

		$redirect = admin_url( 'admin.php?page=idpg-widget-areas&tab=' . self::TAB_SLUG );

		// Возвращаемся к тому же событию в селекторе, чтобы после
		// сохранения сразу видеть его текущее состояние.
		if ( $hide_event_id ) {
			$redirect = add_query_arg( 'hide_event', $hide_event_id, $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}