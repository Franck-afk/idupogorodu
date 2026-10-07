<?php
/**
 * Баннер текущего события на главной.
 *
 * Рендерит на фронте баннер с данными ближайшего предстоящего события
 * из Gutenberg-редактора CPT "event": изображение, рубрика, даты,
 * заголовок и анонс. Настроек в админке не имеет.
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
		if ( 'hero' !== $position || ! is_front_page() ) {
			return;
		}

		// Приоритет — подтверждённые (оплаченные) события: одно — баннер,
		// несколько — карусель с авто-ротацией.
		$paid_html = $this->render_paid_html();
		if ( $paid_html ) {
			echo $paid_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		$html = $this->render_html();
		if ( $html ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
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

		$posts = get_posts( $args );
		if ( empty( $posts ) ) {
			set_transient( $cache_key, 0, 10 * MINUTE_IN_SECONDS );
			return null;
		}

		// Полное скрытие баннера: вместо замены следующим событием
		// баннер на главной не показывается вовсе.
		if ( $this->is_full_banner_hidden( (int) $posts[0]->ID ) ) {
			set_transient( $cache_key, 0, 10 * MINUTE_IN_SECONDS );
			return null;
		}

		set_transient( $cache_key, (int) $posts[0]->ID, 10 * MINUTE_IN_SECONDS );
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
	 * Первая рубрика события.
	 *
	 * @param int $post_id ID события.
	 * @return array|null Массив с name/link или null.
	 */
	private function get_rubric( $post_id ) {
		$terms = wp_get_post_terms( $post_id, self::TAX_CAT, array( 'number' => 1 ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		$term = $terms[0];
		$link = get_term_link( $term, self::TAX_CAT );
		if ( is_wp_error( $link ) ) {
			$link = '';
		}

		return array(
			'name' => $term->name,
			'link' => $link,
		);
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

		$price = 'yes' === get_post_meta( $post_id, '_idpg_is_free', true )
			? __( 'Вход свободный', 'idpg-core' )
			: $this->format_price(
				get_post_meta( $post_id, '_idpg_price_from', true ),
				get_post_meta( $post_id, '_idpg_price_to', true ),
				get_post_meta( $post_id, '_idpg_currency', true )
			);
		if ( $price ) {
			$items['price'] = '<span class="idpg-current-event-banner__meta-item idpg-current-event-banner__meta-item--price">'
				. esc_html( $price ) . '</span>';
		}

		$city = $this->get_first_term_name( $post_id, 'city' );
		if ( $city ) {
			$items['city'] = '<span class="idpg-current-event-banner__meta-item">' . esc_html( $city ) . '</span>';
		}

		$age = $this->get_first_term_name( $post_id, 'age_rating' );
		if ( $age ) {
			$items['age'] = '<span class="idpg-current-event-banner__meta-item">' . esc_html( $age ) . '</span>';
		}

		$venue = $this->get_venue( $post_id );
		if ( $venue ) {
			$items['venue'] = '<span class="idpg-current-event-banner__meta-item">' . esc_html( $venue ) . '</span>';
		}

		if ( empty( $items ) ) {
			return '';
		}

		return implode( '', $items );
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

		$title    = get_the_title( $event );
		$excerpt  = has_excerpt( $event ) ? trim( (string) get_the_excerpt( $event ) ) : '';
		$rubric   = $this->get_rubric( $event->ID );
		$date_raw = get_post_meta( $event->ID, self::META_START, true );
		$end_raw  = get_post_meta( $event->ID, self::META_END, true );
		$dates    = $this->format_date_range( $date_raw, $end_raw );
		$status   = IDPG_Helpers::get_ticket_status( $event->ID );
		$meta     = $this->get_event_meta_line( $event->ID );

		$content = '';
		if ( $title || $excerpt || $rubric || $dates || $meta ) {
			$content .= '<div class="idpg-current-event-banner__content">';

			if ( $rubric ) {
				$content .= '<span class="idpg-current-event-banner__badge">' . esc_html( $rubric['name'] ) . '</span>';
			}
			$status_labels = array(
				'in_sale'   => __( 'Билеты в продаже', 'idpg-core' ),
				'sold_out'  => __( 'Билеты закончились', 'idpg-core' ),
				'cancelled' => __( 'Мероприятие отменено', 'idpg-core' ),
				'postponed' => __( 'Мероприятие перенесено', 'idpg-core' ),
			);
			if ( ! empty( $status ) && isset( $status_labels[ $status ] ) ) {
				$content .= '<span class="idpg-current-event-banner__status idpg-current-event-banner__status--'
					. esc_attr( $status ) . '">' . esc_html( $status_labels[ $status ] ) . '</span>';
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
			$content .= '</div>';
		}

		$overlay_bottom = 0.4;
		$overlay_top    = round( $overlay_bottom * 0.35, 2 );
		$overlay        = '<div class="idpg-current-event-banner__overlay" style="background:linear-gradient(180deg, rgba(0,0,0,'
			. $overlay_top . ') 0%, rgba(0,0,0,' . $overlay_bottom . ') 100%)"></div>';

		$picture = '<picture class="idpg-current-event-banner__picture">'
			. '<img class="idpg-current-event-banner__img" src="' . esc_url( $img_url ) . '" alt="' . esc_attr( $title ) . '" loading="lazy">'
			. '</picture>';

		return '<a class="idpg-current-event-banner" href="' . esc_url( get_permalink( $event ) ) . '">'
			. $picture . $overlay . $content . '</a>';
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
				if ( $post && 'publish' === $post->post_status ) {
					$posts[] = $post;
				}
			}
			return $posts;
		}

		$args = array(
			'post_type'              => 'event',
			'post_status'            => 'publish',
			'posts_per_page'         => 20,
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

		// События, у которых стоит «скрыть баннер полностью», в ротацию не попадают.
		$paid = array();
		foreach ( $posts as $post ) {
			if ( $this->is_full_banner_hidden( (int) $post->ID ) ) {
				continue;
			}
			$paid[] = $post;
		}

		$ids = wp_list_pluck( $paid, 'ID' );
		set_transient( $key, $ids, 10 * MINUTE_IN_SECONDS );

		return $paid;
	}

	/**
	 * Рендер подтверждённых (оплаченных) событий.
	 *
	 * Одно событие — обычный баннер; несколько — карусель с авто-ротацией.
	 *
	 * @return string Пустая строка, если подтверждённых событий нет.
	 */
	private function render_paid_html() {
		$paid = $this->get_paid_events();
		if ( empty( $paid ) ) {
			return '';
		}

		if ( 1 === count( $paid ) ) {
			return $this->render_single_html( $paid[0] );
		}

		// Собираем слайды и точки в одном цикле, чтобы индексы совпадали.
		$slides = '';
		$dots   = '';
		$index  = 0;

		foreach ( $paid as $event ) {
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

		return '<div class="idpg-current-event-banner-carousel" data-idpg-banner-carousel data-interval="' . self::CAROUSEL_INTERVAL . '">'
			. '<div class="idpg-current-event-banner-carousel__viewport">' . $slides . '</div>'
			. '<div class="idpg-current-event-banner-carousel__dots">' . $dots . '</div>'
			. '</div>';
	}
}
