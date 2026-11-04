<?php
/**
 * Баннер текущего события на главной.
 *
 * Рендерит на фронте баннер с событиями CPT "event", выбранными во
 * вкладке «Банер на главной» (`IDPG_Banner_Settings`). Одно событие —
 * обычный баннер, несколько — карусель. События без выбора (в том числе
 * новые) в баннер не попадают; закончившиеся события исключаются.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Current_Event_Banner
 */
class IDPG_Current_Event_Banner {

	/**
	 * Мета-ключи дат события.
	 *
	 * @var string
	 */
	const META_START = '_idpg_start_date';
	const META_END   = '_idpg_end_date';

	/**
	 * Мета-ключ полного скрытия баннера.
	 *
	 * @var string
	 */
	const META_HIDE_FULL = '_idpg_hide_full_banner';

	/**
	 * Мета-ключ скрытия события из баннера.
	 *
	 * @var string
	 */
	const META_HIDE_FROM = '_idpg_hide_from_banner';

	/**
	 * Мета-ключ заявки организатора на платный показ в баннере.
	 *
	 * @var string
	 */
	const META_BANNER_REQUEST = '_idpg_banner_request';

	/**
	 * Мета-ключ подтверждённой администратором оплаты показа в баннере.
	 *
	 * Показ активируется только при значении "yes": событие получает
	 * приоритет перед автоматическим выбором ближайшего события.
	 *
	 * @var string
	 */
	const META_BANNER_APPROVED = '_idpg_banner_approved';

	/**
	 * Мета-ключи полей карточки события, которые выводятся в баннере.
	 *
	 * @var string
	 */
	const META_IS_FREE   = '_idpg_is_free';
	const META_IS_ONLINE = '_idpg_is_online';
	const META_VIP_PRICE = '_idpg_vip_price';
	const META_ALL_DAY   = '_idpg_all_day';
	const META_CURRENCY  = '_idpg_currency';
	const META_VIEWS     = '_idpg_views';
	const META_SESSIONS  = '_idpg_sessions_json';

	/**
	 * Префикс транзиентов кэша списка оплаченных событий (на город).
	 *
	 * @var string
	 */
	const PAID_CACHE_PREFIX = 'idpg_paid_banner_ids_';

	/**
	 * Интервал авто-ротации карусели, мс.
	 *
	 * @var int
	 */
	const CAROUSEL_INTERVAL = 6000;

	/**
	 * Таксономия рубрик событий.
	 *
	 * @var string
	 */
	const TAX_CAT = 'event_category';

	/**
	 * Синглтон.
	 *
	 * @var IDPG_Current_Event_Banner|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра.
	 *
	 * @return IDPG_Current_Event_Banner
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'idpg_banner_position', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'save_post_event', array( $this, 'purge_cache' ) );
		add_action( 'delete_post_event', array( $this, 'purge_cache' ) );
	}

	/**
	 * Сброс кэша баннера при сохранении или удалении события.
	 *
	 * Сбрасывает кэши всех городов.
	 */
	public function purge_cache() {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_idpg_current_event_id_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$like = $wpdb->esc_like( '_transient_timeout_idpg_current_event_id_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Кэш списка оплаченных событий (по городам).
		$like = $wpdb->esc_like( '_transient_' . self::PAID_CACHE_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$like = $wpdb->esc_like( '_transient_timeout_' . self::PAID_CACHE_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Slug города для баннера.
	 *
	 * Порядок определения:
	 * 1. Город из URL (/gorod/<slug>/ или ?city=<slug>) — приоритетнее cookie.
	 * 2. Текущий город из cookie через IDPG_City_Filter.
	 * 3. Дефолт 'izhevsk'.
	 *
	 * @return string Slug термина таксономии city.
	 */
	public function get_city_slug() {
		$city_var = get_query_var( 'city' );

		if ( ! empty( $city_var ) && is_string( $city_var ) ) {
			return sanitize_title( $city_var );
		}

		if ( class_exists( 'IDPG_City_Filter' ) ) {
			return IDPG_City_Filter::get_current_city_slug();
		}

		return 'izhevsk';
	}

	/**
	 * Ключ транзиента кэша баннера с учётом города.
	 *
	 * @return string Например: idpg_current_event_id_izhevsk.
	 */
	private function get_cache_key() {
		return 'idpg_current_event_id_' . $this->get_city_slug();
	}

	/**
	 * Регистрация CSS на фронте, только на главной.
	 */
	public function register_assets() {
		if ( ! is_front_page() ) {
			return;
		}
		wp_enqueue_style(
			'idpg-current-event-banner',
			IDPG_CORE_URL . 'assets/css/current-event-banner.css',
			array(),
			IDPG_CORE_VERSION
		);

		// JS авто-ротации карусели оплаченных событий (пауза на наведении,
		// переключение по точкам, swipe на мобильных).
		wp_enqueue_script(
			'idpg-current-event-banner',
			IDPG_CORE_URL . 'assets/js/current-event-banner.js',
			array(),
			IDPG_CORE_VERSION,
			true
		);
	}

	/**
	 * Публичный рендер (callback для хука idpg_banner_position).
	 *
	 * @param string $position Позиция: hero / middle / bottom.
	 */
	public function render( $position ) {
		if ( ! $this->should_render( $position ) ) {
			return;
		}

		/*
		 * События для баннера выбираются во вкладке «Банер на главной».
		 * Одно событие — обычный баннер, несколько — карусель с авто-ротацией.
		 *
		 * Автоматический выбор ближайшего события отключён: новые и уже
		 * существующие события не попадают в баннер без явного выбора.
		 */
		$selected_html = $this->render_selected_html();
		if ( $selected_html ) {
			echo $selected_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Выводить ли баннер в указанной позиции хука.
	 *
	 * Гейты: блок включён в настройках вкладки «Баннер на главной»,
	 * позиция hero (настройка позиции удалена — баннер показывается
	 * только в hero, как и до появления вкладки), запрос — главная
	 * страница.
	 *
	 * @param string $position Позиция хука: hero / middle / bottom.
	 * @return bool
	 */
	private function should_render( $position ) {
		if ( ! IDPG_Banner_Settings::is_enabled() ) {
			return false;
		}

		if ( 'hero' !== $position ) {
			return false;
		}

		if ( ! is_front_page() ) {
			return false;
		}

		return true;
	}

	/**
	 * Ближайшее текущее или предстоящее событие (по дате начала), с кэшем в transient.
	 *
	 * Показывает события, которые ещё не закончились (дата окончания в будущем),
	 * в том числе идущие прямо сейчас, и только с миниатюрой — баннеру
	 * обязательно нужно изображение.
	 *
	 * События фильтруются по текущему городу (cookie, URL или дефолт izhevsk),
	 * кэш ведётся отдельно для каждого города.
	 *
	 * @return WP_Post|null
	 */
	private function get_current_event() {
		$cache_key = $this->get_cache_key();
		$cached_id = get_transient( $cache_key );
		if ( $cached_id ) {
			$cached = get_post( (int) $cached_id );
			if ( $cached && 'publish' === $cached->post_status && absint( IDPG_Media::idpg_get_poster_id( $cached->ID ) ) ) {
				if ( ! $this->is_full_banner_hidden( $cached->ID ) ) {
					return $cached;
				}
			}
		}

		$args = array(
			'post_type'              => 'event',
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'orderby'                => 'meta_value',
			'meta_key'               => self::META_START,
			'order'                  => 'ASC',
			'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'city',
					'field'    => 'slug',
					'terms'    => $this->get_city_slug(),
				),
			),
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'AND',
				// Баннеру нужно изображение: выбираем только события с миниатюрой,
				// иначе render_html() вернёт пустую строку и баннер исчезнет.
				array(
					'key'     => '_thumbnail_id',
					'compare' => 'EXISTS',
				),
				// Событие ещё не закончилось: дата окончания в будущем;
				// при пустой дате окончания — дата начала в будущем.
				// (Текущее и идущее прямо сейчас событие тоже показываем.)
				array(
					'relation' => 'OR',
					array(
						'key'     => self::META_END,
						'value'   => current_time( 'mysql' ),
						'compare' => '>=',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'AND',
						array(
							'key'     => self::META_END,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => self::META_START,
							'value'   => current_time( 'mysql' ),
							'compare' => '>=',
							'type'    => 'DATETIME',
						),
					),
					array(
						'relation' => 'AND',
						array(
							'key'     => self::META_END,
							'value'   => '',
							'compare' => '=',
						),
						array(
							'key'     => self::META_START,
							'value'   => current_time( 'mysql' ),
							'compare' => '>=',
							'type'    => 'DATETIME',
						),
					),
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => self::META_HIDE_FROM,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_HIDE_FROM,
						'value'   => 'yes',
						'compare' => '!=',
					),
				),
			),
		);

		// Кэш выбора баннера по городу (срок задаётся в настройках баннера).
		$cache_ttl = IDPG_Banner_Settings::get_cache_ttl();

		$posts = get_posts( $args );
		if ( empty( $posts ) ) {
			set_transient( $cache_key, 0, $cache_ttl );
			return null;
		}

		// Полное скрытие баннера: вместо замены следующим событием
		// баннер на главной не показывается вовсе.
		if ( $this->is_full_banner_hidden( (int) $posts[0]->ID ) ) {
			set_transient( $cache_key, 0, $cache_ttl );
			return null;
		}

		set_transient( $cache_key, (int) $posts[0]->ID, $cache_ttl );
		return $posts[0];
	}

	/**
	 * Полностью ли скрыт баннер для события.
	 *
	 * @param int $post_id ID события.
	 * @return bool
	 */
	private function is_full_banner_hidden( $post_id ) {
		return 'yes' === get_post_meta( $post_id, self::META_HIDE_FULL, true );
	}

	/**
	 * Закончилось ли событие на текущий момент.
	 *
	 * Даты события хранятся как Y-m-d (событие идёт весь день) либо
	 * Y-m-d\TH:i / Y-m-d H:i (с точным временем). Дата без времени
	 * считается актуальной до конца дня окончания; дата со временем —
	 * до указанного момента.
	 *
	 * @param int $post_id ID события.
	 * @return bool True, если событие уже закончилось.
	 */
	private function is_event_expired( $post_id ) {
		$start = trim( (string) get_post_meta( $post_id, self::META_START, true ) );
		$end   = trim( (string) get_post_meta( $post_id, self::META_END, true ) );

		$value = ( '' !== $end ) ? $end : $start;
		if ( '' === $value ) {
			return false;
		}

		// Единый вид для сравнения: «T» в дате-времени заменяем пробелом.
		$value = str_replace( 'T', ' ', $value );

		// Дата без времени: событие показывается весь день окончания.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value < current_time( 'Y-m-d' );
		}

		return $value < current_time( 'mysql' );
	}

	/**
	 * Название первого термина таксономии (город, возрастной рейтинг).
	 *
	 * @param int    $post_id  ID события.
	 * @param string $taxonomy Таксономия.
	 * @return string Пустая строка, если терминов нет.
	 */
	private function get_first_term_name( $post_id, $taxonomy ) {
		$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'number' => 1 ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		return $terms[0]->name;
	}

	/**
	 * Рубрики события (до двух) — как в карточке события.
	 *
	 * @param int $post_id ID события.
	 * @param int $limit   Сколько рубрик вернуть.
	 * @return array Массив названий рубрик.
	 */
	private function get_rubrics( $post_id, $limit = 2 ) {
		$terms = wp_get_post_terms( $post_id, self::TAX_CAT, array( 'number' => $limit ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$names = array();
		foreach ( $terms as $term ) {
			$names[] = $term->name;
		}

		return $names;
	}

	/**
	 * Бейджи события — те же, что у карточки события.
	 *
	 * Порядок и набор совпадают с карточкой: статус билетов, «Бесплатно»,
	 * «Популярное», «ТОП», «Онлайн», «Новый». Порог «популярного»
	 * считает тема для секций главной ($GLOBALS['idpg_popular_min_views']).
	 *
	 * @param int $post_id ID события.
	 * @return array Массив бейджей: label (текст), type (тип для класса).
	 */
	private function get_event_chips( $post_id ) {
		$chips = array();

		// Чипы — только поля вкладки «Банер на главной»; событие в банкер
		// не читается: не статус, не цены, не «популярное», не «ТОП», не
		// «новое» — всё берётся строго из настроек баннера.
		$status_labels = array(
			'in_sale'   => __( 'Билеты в продаже', 'idpg-core' ),
			'sold_out'  => __( 'Билеты закончились', 'idpg-core' ),
			'cancelled' => __( 'Отменено', 'idpg-core' ),
			'postponed' => __( 'Перенесено', 'idpg-core' ),
		);

		$b_status_setting = IDPG_Banner_Settings::get_output_value( 'banner_ticket_status' );
		if ( '' !== $b_status_setting && isset( $status_labels[ $b_status_setting ] ) ) {
			$chips[] = array(
				'label' => $status_labels[ $b_status_setting ],
				'type'  => 'status',
			);
		}

		$b_free_setting = IDPG_Banner_Settings::get_output_value( 'banner_is_free' );
		if ( 'yes' === $b_free_setting ) {
			$chips[] = array(
				'label' => __( 'Бесплатно', 'idpg-core' ),
				'type'  => 'free',
			);
		}

		$b_online_setting = IDPG_Banner_Settings::get_output_value( 'banner_is_online' );
		if ( 'yes' === $b_online_setting ) {
			$chips[] = array(
				'label' => __( 'Онлайн', 'idpg-core' ),
				'type'  => 'online',
			);
		}

		return $chips;
		}

	/**
	 * Время события: «весь день» или время первого сеанса.
	 *
	 * Сеансы читаются из зеркала `_idpg_sessions_json` (см. IDPG_Meta).
	 *
	 * @param int $post_id ID события.
	 * @return string Пустая строка, если времени нет.
	 */
	private function get_event_time_label( $post_id ) {
		if ( 'yes' === get_post_meta( $post_id, self::META_ALL_DAY, true ) ) {
			return __( 'весь день', 'idpg-core' );
		}

		$raw = (string) get_post_meta( $post_id, self::META_SESSIONS, true );
		if ( '' === $raw ) {
			return '';
		}

		$sessions = json_decode( $raw, true );
		if ( ! is_array( $sessions ) || empty( $sessions[0]['time'] ) ) {
			return '';
		}

		return (string) $sessions[0]['time'];
	}

	/**
	 * VIP-цена события — как в карточке события.
	 *
	 * @param int $post_id ID события.
	 * @return string Пустая строка, если VIP-цены нет.
	 */
	private function get_vip_price( $post_id ) {
		$vip = trim( (string) get_post_meta( $post_id, self::META_VIP_PRICE, true ) );

		// Пустая или незначимая цена (< 1) — VIP-строка не выводится.
		if ( '' === $vip || (float) $vip < 1 ) {
			return '';
		}

		$currency = trim( (string) get_post_meta( $post_id, self::META_CURRENCY, true ) );
		if ( '' === $currency ) {
			$currency = 'RUB';
		}

		$symbols = array(
			'RUB' => '₽',
			'USD' => '$',
			'EUR' => '€',
		);
		$symbol  = isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : $currency;

		return sprintf(
			/* translators: 1: VIP-цена, 2: символ валюты */
			__( 'VIP: %1$s %2$s', 'idpg-core' ),
			number_format_i18n( (float) $vip ),
			$symbol
		);
	}

	/**
	 * HTML бейджей события.
	 *
	 * @param array $chips Бейджи из get_event_chips().
	 * @return string Пустая строка, если бейджей нет.
	 */
	private function render_chips_html( $chips ) {
		if ( empty( $chips ) ) {
			return '';
		}

		$html = '<span class="idpg-current-event-banner__chips">';

		foreach ( $chips as $chip ) {
			$html .= '<span class="idpg-current-event-banner__chip idpg-current-event-banner__chip--'
				. esc_attr( $chip['type'] ) . '">' . esc_html( $chip['label'] ) . '</span>';
		}

		$html .= '</span>';

		return $html;
	}

	/**
	 * Форматирование цены с символом валюты.
	 *
	 * @param string $from     Цена «от».
	 * @param string $to       Цена «до».
	 * @param string $currency Код валюты (RUB/USD/EUR).
	 * @return string Пустая строка, если цены нет.
	 */
	private function format_price( $from, $to, $currency ) {
		$from = trim( (string) $from );
		$to   = trim( (string) $to );

		if ( '' === $from && '' === $to ) {
			return '';
		}

		$symbols = array(
			'RUB' => '₽',
			'USD' => '$',
			'EUR' => '€',
		);
		$symbol  = isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : trim( (string) $currency );

		if ( $from && $to && $from !== $to ) {
			return sprintf( '%s–%s %s', $from, $to, $symbol );
		}
		if ( $from ) {
			return sprintf( 'от %s %s', $from, $symbol );
		}

		return sprintf( 'до %s %s', $to, $symbol );
	}

	/**
	 * Название площадки (venue) и её адрес.
	 *
	 * @param int $post_id ID события.
	 * @return string Пустая строка, если площадка не привязана.
	 */
	private function get_venue( $post_id ) {
		// Адрес события имеет приоритет над адресом площадки.
		$address  = trim( (string) get_post_meta( $post_id, '_idpg_address', true ) );
		$venue_id = (int) get_post_meta( $post_id, '_idpg_venue', true );

		if ( ! $venue_id ) {
			return $address;
		}

		$venue = get_post( $venue_id );
		if ( ! $venue || 'publish' !== $venue->post_status ) {
			return $address;
		}

		$name = trim( (string) get_the_title( $venue ) );

		if ( '' === $address ) {
			$address = trim( (string) get_post_meta( $venue_id, '_idpg_venue_address', true ) );
		}

		if ( ! $name && ! $address ) {
			return '';
		}

		return $name . ( $address ? ', ' . $address : '' );
	}

	/**
	 * Строка дополнительных данных события: цена, город, возраст, площадка.
	 *
	 * @param int $post_id ID события.
	 * @return string Пустая строка, если данных нет.
	 */
	private function get_event_meta_line( $post_id ) {
		$items = array();

		// Только поля вкладки «Банер на главной»: город, рубрики и возраст
		// не выводятся в баннере. Свои цены, VIP, адрес и онлайн берутся
		// строго из настроек, данные события не читаются.
		$b_free_setting = IDPG_Banner_Settings::get_output_value( 'banner_is_free' );
		$b_price_from   = IDPG_Banner_Settings::get_output_value( 'banner_price_from' );
		$b_price_to     = IDPG_Banner_Settings::get_output_value( 'banner_price_to' );
		$b_currency     = IDPG_Banner_Settings::get_output_value( 'banner_currency' );
		$b_vip          = IDPG_Banner_Settings::get_output_value( 'banner_vip_price' );
		$b_address      = IDPG_Banner_Settings::get_output_value( 'banner_address' );
		$b_online_setting = IDPG_Banner_Settings::get_output_value( 'banner_is_online' );
		$b_online       = ( '' !== $b_online_setting )
			? ( 'yes' === $b_online_setting )
			: ( 'yes' === get_post_meta( $post_id, self::META_IS_ONLINE, true ) );

		$has_own_prices = ( '' !== $b_price_from || '' !== $b_price_to );

		if ( 'yes' === $b_free_setting ) {
			// Явно отмечено «бесплатно» в настройках баннера.
			$price = __( 'Вход свободный', 'idpg-core' );
		} elseif ( $has_own_prices ) {
			// Свои цены важнее статуса события.
			$price = $this->format_price( $b_price_from, $b_price_to, $b_currency );
		} else {
			// Нет своих цен — без цены.
			$price = '';
		}
		if ( $price ) {
			$items['price'] = '<span class="idpg-current-event-banner__meta-item idpg-current-event-banner__meta-item--price">'
				. esc_html( $price ) . '</span>';
		}

		// VIP-цена: только своя.
		$vip = ( '' !== $b_vip )
			? sprintf(
				/* translators: %s: цена */
				__( 'VIP: %s', 'idpg-core' ),
				number_format_i18n( (float) $b_vip ) . ' ' . $this->currency_symbol( $b_currency )
			)
			: '';
		if ( $vip ) {
			$items['vip'] = '<span class="idpg-current-event-banner__meta-item idpg-current-event-banner__meta-item--vip">' . esc_html( $vip ) . '</span>';
		}

		// Адрес: только своя.
		$venue = ( '' !== $b_address ) ? $b_address : '';
		if ( $venue ) {
			$items['venue'] = '<span class="idpg-current-event-banner__meta-item">' . esc_html( $venue ) . '</span>';
		}

		// Метка «Онлайн» из настроек баннера.
		if ( $b_online ) {
			$items['online'] = '<span class="idpg-current-event-banner__meta-item idpg-current-event-banner__meta-item--online">'
				. esc_html__( 'Онлайн', 'idpg-core' ) . '</span>';
		}

		if ( empty( $items ) ) {
			return '';
		}

		return implode( '', $items );
		}

	/**
	 * Символ валюты по коду.
	 *
	 * @param string $currency Код валюты (RUB/USD/EUR).
	 * @return string
	 */
	private function currency_symbol( $currency ) {
		$symbols = array(
			'RUB' => '₽',
			'USD' => '$',
			'EUR' => '€',
		);

		$currency = strtoupper( trim( (string) $currency ) );

		return isset( $symbols[ $currency ] ) ? $symbols[ $currency ] : $currency;
	}

	/**
	 * Форматирование диапазона дат события.
	 *
	 * Поля Carbon Fields date_time хранятся в формате Y-m-d\TH:i
	 * (например 2026-08-10T19:00). Функция выводит диапазон
	 * «с … по …» с указанием времени начала, если оно задано.
	 *
	 * @param string $start Дата начала (Y-m-d\TH:i).
	 * @param string $end   Дата окончания (Y-m-d\TH:i).
	 * @return string
	 */
	private function format_date_range( $start, $end ) {
		$start = trim( (string) $start );
		$end   = trim( (string) $end );

		if ( '' === $start && '' === $end ) {
			return '';
		}

		$months = array(
			1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
			5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
			9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
		);

		$ts1 = $start ? strtotime( $start ) : false;
		$ts2 = $end   ? strtotime( $end )   : false;

		if ( ! $ts1 && ! $ts2 ) {
			return '';
		}

		// Время начала и окончания, если задано (формат H:i).
		$time1 = ( $ts1 && '00:00' !== date( 'H:i', $ts1 ) ) ? date( 'H:i', $ts1 ) : '';
		$time2 = ( $ts2 && '00:00' !== date( 'H:i', $ts2 ) ) ? date( 'H:i', $ts2 ) : '';

		$fmt_date = function ( $ts ) use ( $months ) {
			return sprintf(
				'%d %s %d',
				(int) date( 'j', $ts ),
				$months[ (int) date( 'n', $ts ) ],
				(int) date( 'Y', $ts )
			);
		};

		if ( $ts1 && $ts2 ) {
			$y1 = (int) date( 'Y', $ts1 );
			$m1 = (int) date( 'n', $ts1 );
			$d1 = (int) date( 'j', $ts1 );
			$y2 = (int) date( 'Y', $ts2 );
			$m2 = (int) date( 'n', $ts2 );
			$d2 = (int) date( 'j', $ts2 );

			// Один день: «10 августа 2026, 19:00 – 22:00».
			if ( $y1 === $y2 && $m1 === $m2 && $d1 === $d2 ) {
				$time = $time1;
				if ( $time2 && $time2 !== $time1 ) {
					$time .= ' – ' . $time2;
				}
				return $fmt_date( $ts1 ) . ( $time ? ', ' . $time : '' );
			}
			// Один месяц: «с 10 по 12 августа 2026».
			if ( $y1 === $y2 && $m1 === $m2 ) {
				$end = sprintf( '%d %s %d', $d2, $months[ $m2 ], $y2 );
				if ( $time2 ) {
					$end .= ', ' . $time2;
				}
				return sprintf( 'с %d по %s', $d1, $end );
			}
			// Один год: «с 10 августа по 12 сентября 2026».
			if ( $y1 === $y2 ) {
				$end = sprintf( '%d %s %d', $d2, $months[ $m2 ], $y2 );
				if ( $time2 ) {
					$end .= ', ' . $time2;
				}
				return sprintf( 'с %d %s по %s', $d1, $months[ $m1 ], $end );
			}
			// Разные годы: «с 10 августа 2026 по 12 сентября 2027».
			$end = sprintf( '%d %s %d', $d2, $months[ $m2 ], $y2 );
			if ( $time2 ) {
				$end .= ', ' . $time2;
			}
			return sprintf( 'с %d %s %d по %s', $d1, $months[ $m1 ], $y1, $end );
		}

		$ts = $ts1 ? $ts1 : $ts2;
		return $fmt_date( $ts ) . ( $time1 ? ', ' . $time1 : '' );
	}

	/**
	 * Сборка HTML баннера для ближайшего события (авто-показ).
	 *
	 * @return string
	 */
	private function render_html() {
		$event = $this->get_current_event();
		if ( ! $event ) {
			return '';
		}

		return $this->render_single_html( $event );
	}

	/**
	 * Сборка HTML баннера текущего события.
	 *
	 * @param WP_Post $event Событие.
	 * @return string Пустая строка, если у события нет постера.
	 */
	private function render_single_html( $event ) {
		$image_id = (int) IDPG_Media::idpg_get_poster_id( $event->ID );
		$img_url  = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
		if ( ! $img_url ) {
			return '';
		}

		// Только поля вкладки «Банер на главной»: заголовок, текст, даты,
		// время и площадка не зависят от события.
		$title = IDPG_Banner_Settings::get_banner_title();

		$excerpt = IDPG_Banner_Settings::get_banner_text();
		// Длина анонса из настроек (0 — без обрезки).
		$excerpt_length = IDPG_Banner_Settings::get_excerpt_length();
		if ( $excerpt_length > 0 && '' !== $excerpt && mb_strlen( $excerpt ) > $excerpt_length ) {
			$excerpt = mb_substr( $excerpt, 0, $excerpt_length ) . '…';
		}

		$b_start   = IDPG_Banner_Settings::get_output_value( 'banner_start_date' );
		$b_end     = IDPG_Banner_Settings::get_output_value( 'banner_end_date' );
		$b_time    = IDPG_Banner_Settings::get_output_value( 'banner_time' );
		$b_all_day = ( 'yes' === IDPG_Banner_Settings::get_output_value( 'banner_all_day' ) );

		$dates = ( '' !== $b_start || '' !== $b_end )
			? $this->format_date_range( $b_start, $b_end )
			: '';

		if ( $b_all_day ) {
			$time = '';
		} elseif ( '' !== $b_time ) {
			$time = $b_time;
		} else {
			$time = '';
		}

		$chips_html = $this->render_chips_html( $this->get_event_chips( $event->ID ) );
		$meta     = $this->get_event_meta_line( $event->ID );

		/*
		 * Все поля события выводятся всегда: чекбоксы «Поля события
		 * в баннере» удалены по решению владельца, поэтому гейты
		 * is_field_enabled() здесь не применяются. Рубрики, цена, город,
		 * возраст и VIP уже собраны в $meta (get_event_meta_line()),
		 * отдельно их выводить не нужно — иначе дубли.
		 */

		// Время события дописываем к дате — как в карточке события.
		if ( $time ) {
			$dates = $dates ? $dates . ', ' . $time : $time;
		}

		$content = '';
		if ( $title || $excerpt || $dates || $meta || $chips_html ) {
			$content .= '<div class="idpg-current-event-banner__content">';

			if ( $chips_html ) {
				$content .= $chips_html;
			}
			if ( $dates ) {
				$content .= '<span class="idpg-current-event-banner__dates">' . esc_html( $dates ) . '</span>';
			}
			if ( $title ) {
				$content .= '<span class="idpg-current-event-banner__title">' . esc_html( $title ) . '</span>';
			}
			if ( $excerpt ) {
				$content .= '<span class="idpg-current-event-banner__subtitle">' . esc_html( $excerpt ) . '</span>';
			}
			if ( $meta ) {
				$content .= '<span class="idpg-current-event-banner__meta">' . $meta . '</span>';
			}
			$content .= '<span class="idpg-current-event-banner__btn">' . esc_html( IDPG_Banner_Settings::get_button_text() ) . '</span>';
			$content .= '</div>';
		}

		$overlay_bottom = 0.4;
		$overlay_top    = round( $overlay_bottom * 0.35, 2 );
		$overlay        = '<div class="idpg-current-event-banner__overlay" style="background:linear-gradient(180deg, rgba(0,0,0,'
			. $overlay_top . ') 0%, rgba(0,0,0,' . $overlay_bottom . ') 100%)"></div>';

		$picture = '<picture class="idpg-current-event-banner__picture">'
			. '<img class="idpg-current-event-banner__img" src="' . esc_url( $img_url ) . '" alt="' . esc_attr( $title ) . '" loading="lazy">'
			. '</picture>';

		// Сердце «в избранное» — как в карточке события (только для
		// авторизованных пользователей).
		$fav = '';
		if ( class_exists( 'IDPG_Favorites' ) ) {
			ob_start();
			IDPG_Favorites::button( $event->ID );
			$fav = (string) ob_get_clean();
		}

		// Микроразметка Schema.org/Event — как у карточки события.
		$jsonld = '';
		if ( function_exists( 'idpg_core_event_jsonld' ) ) {
			ob_start();
			idpg_core_event_jsonld( $event->ID );
			$jsonld = (string) ob_get_clean();
		}

		// Высота баннера — из настроек (CSS-переменная, чтобы медиа-запрос
		// на мобильных её перебил).
		$banner_height = IDPG_Banner_Settings::get_banner_height();

		// Ссылка баннера: своя страница продажи из настроек → иначе страница события.
		$b_ticket_url = IDPG_Banner_Settings::get_output_value( 'banner_ticket_url' );
		$link_url     = ( '' !== $b_ticket_url ) ? $b_ticket_url : get_permalink( $event );
		$link_extra   = ( '' !== $b_ticket_url ) ? ' target="_blank" rel="noopener"' : '';

		return '<a class="idpg-current-event-banner" style="--idpg-banner-h:' . esc_attr( (string) $banner_height ) . 'px" href="' . esc_url( $link_url ) . '"' . $link_extra . '>'
			. $fav . $picture . $overlay . $content . '</a>' . $jsonld;
	}

	/**
	 * Подтверждённые (оплаченные) события для баннера текущего города.
	 *
	 * Возвращает опубликованные события, у которых verbose:
	 *  - _idpg_banner_approved = "yes" — оплата подтверждена администратором;
	 *  - дата окончания/начала ещё не наступила;
	 *  - есть постер (баннер без картинки не строится);
	 *  - событие не скрыто полностью из баннера (META_HIDE_FULL).
	 *
	 * Если событий несколько — все попадают в карусель на главной.
	 * Список кэшируется транзиентом на город (10 минут).
	 *
	 * @return WP_Post[] Список подтверждённых событий по дате начала (ASC).
	 */
	private function get_paid_events() {
		$city = $this->get_city_slug();
		$key  = self::PAID_CACHE_PREFIX . $city;

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			$posts = array();
			foreach ( $cached as $post_id ) {
				$post = get_post( (int) $post_id );
				if ( ! $post || 'publish' !== $post->post_status ) {
					continue;
				}

				// Кэш мог сохраниться до окончания события — перепроверяем даты.
				if ( $this->is_full_banner_hidden( (int) $post->ID ) ) {
					continue;
				}

				if ( $this->is_event_expired( (int) $post->ID ) ) {
					continue;
				}

				$posts[] = $post;
			}
			return $posts;
		}

		$paid_limit = IDPG_Banner_Settings::get_paid_limit();
		$cache_ttl  = IDPG_Banner_Settings::get_cache_ttl();

		$args = array(
			'post_type'              => 'event',
			'post_status'            => 'publish',
			'posts_per_page'         => $paid_limit,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'orderby'                => 'meta_value',
			'meta_key'               => self::META_START,
			'order'                  => 'ASC',
			'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'city',
					'field'    => 'slug',
					'terms'    => $city,
				),
			),
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'AND',
				array(
					'key'     => self::META_BANNER_APPROVED,
					'value'   => 'yes',
					'compare' => '=',
				),
				array(
					'key'     => '_thumbnail_id',
					'compare' => 'EXISTS',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => self::META_END,
						'value'   => current_time( 'mysql' ),
						'compare' => '>=',
						'type'    => 'DATETIME',
					),
					array(
						'relation' => 'AND',
						array(
							'key'     => self::META_END,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => self::META_START,
							'value'   => current_time( 'mysql' ),
							'compare' => '>=',
							'type'    => 'DATETIME',
						),
					),
					array(
						'relation' => 'AND',
						array(
							'key'     => self::META_END,
							'value'   => '',
							'compare' => '=',
						),
						array(
							'key'     => self::META_START,
							'value'   => current_time( 'mysql' ),
							'compare' => '>=',
							'type'    => 'DATETIME',
						),
					),
				),
			),
		);

		$posts = get_posts( $args );

		// Скрытые и закончившиеся события в ротацию не попадают.
		$paid = array();
		foreach ( $posts as $post ) {
			if ( $this->is_full_banner_hidden( (int) $post->ID ) ) {
				continue;
			}

			if ( $this->is_event_expired( (int) $post->ID ) ) {
				continue;
			}

			$paid[] = $post;
		}

		$ids = wp_list_pluck( $paid, 'ID' );
		set_transient( $key, $ids, $cache_ttl );

		return $paid;
	}

	/**
	 * События, выбранные во вкладке «Банер на главной».
	 *
	 * Порядок — как в форме. Пропускаются неопубликованные, скрытые
	 * полностью, закончившиеся события и события без постера.
	 *
	 * @return WP_Post[] Список событий.
	 */
	private function get_selected_events() {
		$ids = IDPG_Banner_Settings::get_banner_event_ids();
		if ( empty( $ids ) ) {
			return array();
		}

		// Число слайдов карусели ограничено настройкой вкладки.
		$limit = IDPG_Banner_Settings::get_paid_limit();
		if ( count( $ids ) > $limit ) {
			$ids = array_slice( $ids, 0, $limit );
		}

		$events = array();
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post || 'event' !== $post->post_type || 'publish' !== $post->post_status ) {
				continue;
			}

			if ( $this->is_full_banner_hidden( (int) $post->ID ) ) {
				continue;
			}

			if ( $this->is_event_expired( (int) $post->ID ) ) {
				continue;
			}

			if ( ! IDPG_Media::idpg_get_poster_id( (int) $post->ID ) ) {
				continue;
			}

			$events[] = $post;
		}

		return $events;
	}

	/**
	 * Рендер событий, выбранных во вкладке «Банер на главной».
	 *
	 * @return string Пустая строка, если события не выбраны.
	 */
	private function render_selected_html() {
		return $this->render_events_html( $this->get_selected_events() );
	}

	/**
	 * Рендер подтверждённых (оплаченных) событий.
	 *
	 * Оставлен для обратной совместимости; баннер на главной использует
	 * выбор из вкладки «Банер на главной» (render_selected_html()).
	 *
	 * @return string Пустая строка, если подтверждённых событий нет.
	 */
	private function render_paid_html() {
		return $this->render_events_html( $this->get_paid_events() );
	}

	/**
	 * HTML одного баннера или карусели для набора событий.
	 *
	 * @param WP_Post[] $events События.
	 * @return string Пустая строка, если событий нет.
	 */
	private function render_events_html( $events ) {
		if ( empty( $events ) ) {
			return '';
		}

		if ( 1 === count( $events ) ) {
			return $this->render_single_html( $events[0] );
		}

		// Собираем слайды и точки в одном цикле, чтобы индексы совпадали.
		$slides = '';
		$dots   = '';
		$index  = 0;

		foreach ( $events as $event ) {
			$html = $this->render_single_html( $event );
			if ( ! $html ) {
				continue;
			}

			$current = ( 0 === $index ) ? ' is-active' : '';
			$slides .= '<div class="idpg-current-event-banner-carousel__slide' . $current . '">' . $html . '</div>';
			$dots   .= '<button type="button" class="idpg-current-event-banner-carousel__dot' . $current
				. '" data-slide="' . $index . '" aria-label="' . esc_attr( $event->post_title ) . '"></button>';
			$index++;
		}

		if ( ! $slides ) {
			return '';
		}

		return '<div class="idpg-current-event-banner-carousel" data-idpg-banner-carousel' . $this->get_carousel_data_attrs() . '>'
			. '<div class="idpg-current-event-banner-carousel__viewport">' . $slides . '</div>'
			. '<div class="idpg-current-event-banner-carousel__dots">' . $dots . '</div>'
			. '</div>';
	}

	/**
	 * Атрибуты data-* контейнера карусели: интервал и авто-ротация.
	 *
	 * Берутся из настроек вкладки «Баннер на главной»; JS карусели
	 * читает data-autorotate и при "0" не запускает таймер ротации.
	 *
	 * @return string Строка атрибутов с ведущими пробелами.
	 */
	private function get_carousel_data_attrs() {
		return ' data-interval="' . esc_attr( (string) IDPG_Banner_Settings::get_carousel_interval() ) . '"'
			. ' data-autorotate="' . ( IDPG_Banner_Settings::is_auto_rotate() ? '1' : '0' ) . '"';
	}
}
