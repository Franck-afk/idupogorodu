<?php
/**
 * Настройки баннера на главной.
 *
 * Отдельная вкладка «Баннер на главной» на странице «IDPG»: все
 * настройки вывода баннера как самостоятельного блока главной страницы —
 * вкл/выкл показа, авто-ротация карусели, интервал ротации, число
 * слайдов, срок кэша выбора события, а также флаг «Скрыть баннер
 * полностью» для конкретного события (перенесён сюда из вкладки
 * «События», мета `_idpg_hide_full_banner` та же). Значения хранятся
 * в option IDPG_Banner_Settings::OPTION и подставляются в баннер через
 * геттеры с fallback на дефолт. Настройка позиции (hero/middle/bottom)
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
	 * Имя option с настройками баннера.
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
	}

	/**
	 * Дефолтные настройки.
	 *
	 * @return array
	 */
	public static function defaults() {
		$fields = array();
		foreach ( self::FIELDS as $field ) {
			$fields[ $field ] = 'yes';
		}

		return array(
			// Показывать ли баннер на главной (самостоятельный блок).
			'enabled'           => 'yes',
			// Авто-ротация карусели (переключение по таймеру).
			'auto_rotate'       => 'yes',
			// Интервал авто-ротации карусели, мс.
			'carousel_interval' => 6000,
			// Максимум оплаченных событий в карусели.
			'paid_limit'        => 20,
			// Срок кэша выбора баннера, минут.
			'cache_ttl'         => 10,
			// Какие поля события выводить в баннере.
			'fields'            => $fields,
			// Свой заголовок баннера (пусто — название события).
			'banner_title'      => '',
			// Свой текст анонса (пусто — анонс события).
			'banner_text'       => '',
			// Текст кнопки «Подробнее» в баннере.
			'button_text'       => __( 'Подробнее', 'idpg-core' ),
			// Длина анонса в символах (0 — без обрезки).
			'excerpt_length'    => 0,
			// Высота баннера, px (на мобильных перебивается медиа-запросом).
			'banner_height'     => 400,
		);
	}

	/**
	 * Сохранённые настройки, дополненные дефолтами.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
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
	 * Неизвестное поле всегда выключено; для полей, которых нет в option,
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
	 * Санитизация присланных значений формы (вход не доверяем).
	 *
	 * Вызывается из handle_save() перед записью option: чекбоксы
	 * приводятся к 'yes'/'no' (нет поля в POST — выключен), числа —
	 * absint с зажатием в допустимые диапазоны.
	 *
	 * @param array $raw Данные $_POST.
	 * @return array Массив настроек для записи в option.
	 */
	public static function sanitize_settings( $raw ) {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$defaults = self::defaults();

		$enabled     = isset( $raw['idpg_banner_enabled'] ) && 'yes' === sanitize_key( wp_unslash( $raw['idpg_banner_enabled'] ) )
			? 'yes'
			: 'no';
		$auto_rotate = isset( $raw['idpg_banner_autorotate'] ) && 'yes' === sanitize_key( wp_unslash( $raw['idpg_banner_autorotate'] ) )
			? 'yes'
			: 'no';

		$interval = isset( $raw['idpg_banner_interval'] ) ? absint( $raw['idpg_banner_interval'] ) : $defaults['carousel_interval'];
		$paid     = isset( $raw['idpg_banner_paid_limit'] ) ? absint( $raw['idpg_banner_paid_limit'] ) : $defaults['paid_limit'];
		$ttl      = isset( $raw['idpg_banner_cache_ttl'] ) ? absint( $raw['idpg_banner_cache_ttl'] ) : $defaults['cache_ttl'];

		// Поля ввода параметров вывода.
		$button_text    = isset( $raw['idpg_banner_button_text'] ) ? sanitize_text_field( wp_unslash( $raw['idpg_banner_button_text'] ) ) : '';
		$excerpt_length = isset( $raw['idpg_banner_excerpt_length'] ) ? absint( $raw['idpg_banner_excerpt_length'] ) : 0;
		$banner_height  = isset( $raw['idpg_banner_banner_height'] ) ? absint( $raw['idpg_banner_banner_height'] ) : $defaults['banner_height'];
		$banner_title   = isset( $raw['idpg_banner_banner_title'] ) ? sanitize_text_field( wp_unslash( $raw['idpg_banner_banner_title'] ) ) : '';
		$banner_text    = isset( $raw['idpg_banner_banner_text'] ) ? sanitize_textarea_field( wp_unslash( $raw['idpg_banner_banner_text'] ) ) : '';

		// Поля баннера: чекбоксы формы, неизвестные ключи отбрасываются.
		$fields = array();
		foreach ( self::FIELDS as $field ) {
			$fields[ $field ] = isset( $raw[ 'idpg_banner_field_' . $field ] ) ? 'yes' : 'no';
		}

		return array(
			'enabled'           => $enabled,
			'auto_rotate'       => $auto_rotate,
			'carousel_interval' => max( 2000, $interval ),
			'paid_limit'        => max( 1, min( 50, $paid ) ),
			'cache_ttl'         => max( 1, $ttl ),
			'fields'            => $fields,
			'button_text'       => ( '' !== $button_text ) ? $button_text : $defaults['button_text'],
			'excerpt_length'    => max( 0, min( 500, $excerpt_length ) ),
			'banner_height'     => max( 200, min( 800, $banner_height ) ),
			'banner_title'      => $banner_title,
			'banner_text'       => $banner_text,
		);
	}

	/**
	 * События для селектора «Скрыть баннер полностью».
	 *
	 * Последние события всех редактируемых статусов — тот же принцип,
	 * что в селекторе вкладки «События». Дополнительно в список всегда
	 * попадают все события с уже установленным флагом: иначе событие
	 * вне лимита нельзя было бы увидеть и снять флаг.
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
	 * Вызывается из handle_save(): событие приходит отдельным полем
	 * idpg_hide_event_id (выбрано в селекторе на вкладке), значение —
	 * чекбокс idpg_hide_full_banner. Мета та же, что редактировалась
	 * во вкладке «События» (IDPG_Current_Event_Banner::META_HIDE_FULL),
	 * логика баннера не меняется; пустое значение снимает флаг.
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_banner_tab = isset( $_GET['tab'] ) && self::TAB_SLUG === sanitize_key( wp_unslash( $_GET['tab'] ) );

	$saved_meta = (int) get_user_meta( get_current_user_id(), self::USER_SAVED_META, true );

		if ( $saved_meta && $is_banner_tab ) {
			delete_user_meta( get_current_user_id(), self::USER_SAVED_META );
			?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Настройки баннера сохранены.', 'idpg-core' ); ?></p></div>
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

			<h2 class="idpg-privacy-section-title"><?php esc_html_e( 'Данные баннера', 'idpg-core' ); ?></h2>

			<div class="idpg-option-field">
				<label class="idpg-option-field-label" for="idpg_banner_banner_title">
					<span><?php esc_html_e( 'Заголовок баннера', 'idpg-core' ); ?></span>
				</label>
				<div class="idpg-option-field-body">
					<input type="text" id="idpg_banner_banner_title" name="idpg_banner_banner_title" class="regular-text"
						value="<?php echo esc_attr( IDPG_Banner_Settings::get_banner_title() ); ?>"
						placeholder="<?php esc_attr_e( 'Название события', 'idpg-core' ); ?>">
					<p class="description"><?php esc_html_e( 'Свой заголовок поверх картинки. Пустое значение — название события.', 'idpg-core' ); ?></p>
				</div>
			</div>

			<div class="idpg-option-field">
				<label class="idpg-option-field-label" for="idpg_banner_banner_text">
					<span><?php esc_html_e( 'Текст анонса', 'idpg-core' ); ?></span>
				</label>
				<div class="idpg-option-field-body">
					<textarea id="idpg_banner_banner_text" name="idpg_banner_banner_text" class="large-text" rows="3"><?php echo esc_textarea( IDPG_Banner_Settings::get_banner_text() ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Свой текст под заголовком. Пустое значение — анонс события.', 'idpg-core' ); ?></p>
				</div>
			</div>

			<div class="idpg-option-field">
				<label class="idpg-option-field-label" for="idpg_banner_button_text">
					<span><?php esc_html_e( 'Текст кнопки', 'idpg-core' ); ?></span>
				</label>
				<div class="idpg-option-field-body">
					<input type="text" id="idpg_banner_button_text" name="idpg_banner_button_text" class="regular-text"
						value="<?php echo esc_attr( IDPG_Banner_Settings::get_button_text() ); ?>">
					<p class="description"><?php esc_html_e( 'Подпись на кнопке в баннере. Пустое значение — «Подробнее».', 'idpg-core' ); ?></p>
				</div>
			</div>

			<div class="idpg-option-field">
				<label class="idpg-option-field-label" for="idpg_banner_excerpt_length">
					<span><?php esc_html_e( 'Длина анонса (символов)', 'idpg-core' ); ?></span>
				</label>
				<div class="idpg-option-field-body">
					<input type="number" min="0" max="500" step="10" id="idpg_banner_excerpt_length" name="idpg_banner_excerpt_length" class="small-text"
						value="<?php echo esc_attr( (string) IDPG_Banner_Settings::get_excerpt_length() ); ?>">
					<p class="description"><?php esc_html_e( 'Анонс события обрезается до указанной длины. 0 — показывать полностью.', 'idpg-core' ); ?></p>
				</div>
			</div>

			<div class="idpg-option-field">
				<label class="idpg-option-field-label" for="idpg_banner_banner_height">
					<span><?php esc_html_e( 'Высота баннера (px)', 'idpg-core' ); ?></span>
				</label>
				<div class="idpg-option-field-body">
					<input type="number" min="200" max="800" step="20" id="idpg_banner_banner_height" name="idpg_banner_banner_height" class="small-text"
						value="<?php echo esc_attr( (string) IDPG_Banner_Settings::get_banner_height() ); ?>">
					<p class="description"><?php esc_html_e( 'Высота блока на десктопе, от 200 до 800 px. На мобильных фиксирована.', 'idpg-core' ); ?></p>
				</div>
			</div>

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
		update_option( self::OPTION, self::sanitize_settings( $_POST ), false );

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
