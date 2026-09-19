<?php
/**
 * Экспорт событий в формат iCalendar (.ics).
 *
 * Генерирует валидный .ics-файл для добавления события
 * в календарь пользователя (Google Calendar, Apple Calendar и т.д.).
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_iCal_Export
 *
 * Перехватывает запросы с ?idpg_action=ical и отдает .ics-файл.
 */
class IDPG_iCal_Export {

	/**
	 * Синглтон.
	 *
	 * @var IDPG_iCal_Export|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_iCal_Export
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
		add_action( 'init', array( $this, 'maybe_export' ) );
	}

	/**
	 * Проверка запроса и генерация .ics при необходимости.
	 *
	 * Перехватывает URL вида:
	 * `?idpg_action=ical&event_id=123`
	 * или
	 * `?idpg_action=ical_all&city=gorod` (экспорт всех событий города).
	 */
	public function maybe_export() {
		if ( ! isset( $_GET['idpg_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_GET['idpg_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'ical' === $action ) {
			$this->export_single();
		} elseif ( 'ical_all' === $action ) {
			$this->export_all();
		} elseif ( 'ical_month' === $action ) {
			$this->export_month();
		}
	}

	/**
	 * Экспорт событий за месяц (из виджета календаря).
	 *
	 * Принимает: ?idpg_action=ical_month&ym=YYYY-MM[&category=&city=].
	 */
	private function export_month() {
		$ym = isset( $_GET['ym'] ) ? sanitize_text_field( wp_unslash( $_GET['ym'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! preg_match( '/^(\d{4})-(\d{1,2})$/', $ym, $mt ) ) {
			wp_die(
				esc_html__( 'Неверный формат месяца.', 'idpg-core' ),
				esc_html__( 'Ошибка', 'idpg-core' ),
				array( 'response' => 400 )
			);
		}

		$y = (int) $mt[1];
		$m = (int) $mt[2];
		$days = (int) gmdate( 't', mktime( 0, 0, 0, $m, 1, $y ) );

		$category = isset( $_GET['category'] ) ? absint( $_GET['category'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$city     = isset( $_GET['city'] ) ? absint( $_GET['city'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		$args = array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => '_idpg_start_date',
					'value'   => array(
						sprintf( '%04d-%02d-01', $y, $m ),
						sprintf( '%04d-%02d-%02d', $y, $m, $days ),
					),
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				),
			),
		);

		if ( $category || $city ) {
			$tax = array( 'relation' => 'AND' );
			if ( $category ) {
				$tax[] = array( 'taxonomy' => 'event_category', 'field' => 'term_id', 'terms' => $category );
			}
			if ( $city ) {
				$tax[] = array( 'taxonomy' => 'city', 'field' => 'term_id', 'terms' => $city );
			}
			$args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		$events = get_posts( $args );

		if ( empty( $events ) ) {
			wp_die(
				esc_html__( 'Нет событий за этот месяц.', 'idpg-core' ),
				esc_html__( 'Ошибка', 'idpg-core' ),
				array( 'response' => 404 )
			);
		}

		$this->send_bulk_headers( 'idpg-month-' . $y . '-' . $m );
		echo esc_html( $this->generate_multi_event_ical( $events ) );
		exit;
	}

	/**
	 * Экспорт одного события.
	 */
	private function export_single() {
		$event_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $event_id ) {
			wp_die(
				esc_html__( 'Не указан ID события.', 'idpg-core' ),
				esc_html__( 'Ошибка', 'idpg-core' ),
				array( 'response' => 400 )
			);
		}

		$post = get_post( $event_id );

		if ( ! $post || 'event' !== $post->post_type || 'publish' !== $post->post_status ) {
			wp_die(
				esc_html__( 'Событие не найдено.', 'idpg-core' ),
				esc_html__( 'Ошибка', 'idpg-core' ),
				array( 'response' => 404 )
			);
		}

		$ical = $this->generate_event_ical( $post );
		$this->send_headers( $post );
		echo esc_html( $ical );
		exit;
	}

	/**
	 * Экспорт всех событий (опционально фильтр по городу).
	 */
	private function export_all() {
		$city_slug = isset( $_GET['city'] ) ? sanitize_text_field( wp_unslash( $_GET['city'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$args = array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
		);

		if ( $city_slug ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'city',
					'field'    => 'slug',
					'terms'    => $city_slug,
				),
			);
		}

		$events = get_posts( $args );

		if ( empty( $events ) ) {
			wp_die(
				esc_html__( 'Нет событий для экспорта.', 'idpg-core' ),
				esc_html__( 'Ошибка', 'idpg-core' ),
				array( 'response' => 404 )
			);
		}

		$ical = $this->generate_multi_event_ical( $events );

		$filename = 'idpg-events';
		if ( $city_slug ) {
			$filename .= '-' . sanitize_file_name( $city_slug );
		}

		$this->send_bulk_headers( $filename );
		echo esc_html( $ical );
		exit;
	}

	/**
	 * Генерация iCal-строки для одного события.
	 *
	 * @param WP_Post $post Объект события.
	 * @return string Содержимое .ics-файла.
	 */
	private function generate_event_ical( $post ) {
		$date_start = get_post_meta( $post->ID, '_idpg_start_date', true );
		$date_end   = get_post_meta( $post->ID, '_idpg_end_date', true );
		$venue      = $this->get_venue_name( $post->ID );
		$city       = $this->get_city_name( $post->ID );
		$url        = get_permalink( $post->ID );

		$dtstart = $this->format_ical_date( $date_start );
		$dtend   = ! empty( $date_end )
			? $this->format_ical_date( $date_end )
			: $this->format_ical_date( strtotime( '+2 hours', strtotime( $date_start ) ) );

		$location = $this->build_location( $venue, $city );

		$description = wp_strip_all_tags( $post->post_content );
		$description = str_replace( array( "\r\n", "\r", "\n" ), ' ', $description );

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//IDPG//Afisha//RU',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:' . $post->ID . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
			'DTSTAMP:' . $this->format_ical_date( current_time( 'timestamp' ) ),
			'DTSTART:' . $dtstart,
			'DTEND:' . $dtend,
			'SUMMARY:' . $this->escape_ical_text( $post->post_title ),
		);

		if ( ! empty( $location ) ) {
			$lines[] = 'LOCATION:' . $this->escape_ical_text( $location );
		}

		if ( ! empty( $description ) ) {
			$lines[] = 'DESCRIPTION:' . $this->escape_ical_text( mb_substr( $description, 0, 500 ) );
		}

		if ( ! empty( $url ) ) {
			$lines[] = 'URL:' . $url;
		}

		$lines[] = 'STATUS:CONFIRMED';
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Генерация iCal-строки для нескольких событий.
	 *
	 * @param WP_Post[] $events Массив событий.
	 * @return string Содержимое .ics-файла.
	 */
	private function generate_multi_event_ical( $events ) {
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//IDPG//Afisha//RU',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . $this->escape_ical_text( __( 'Афиша IDPG', 'idpg-core' ) ),
		);

		foreach ( $events as $post ) {
			$date_start = get_post_meta( $post->ID, '_idpg_start_date', true );
			$date_end   = get_post_meta( $post->ID, '_idpg_end_date', true );
			$venue      = $this->get_venue_name( $post->ID );
			$city       = $this->get_city_name( $post->ID );
			$url        = get_permalink( $post->ID );

			if ( empty( $date_start ) ) {
				continue;
			}

			$dtstart = $this->format_ical_date( $date_start );
			$dtend   = ! empty( $date_end )
				? $this->format_ical_date( $date_end )
				: $this->format_ical_date( strtotime( '+2 hours', strtotime( $date_start ) ) );

			$location = $this->build_location( $venue, $city );

			$description = wp_strip_all_tags( $post->post_content );
			$description = str_replace( array( "\r\n", "\r", "\n" ), ' ', $description );

			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . $post->ID . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
			$lines[] = 'DTSTAMP:' . $this->format_ical_date( current_time( 'timestamp' ) );
			$lines[] = 'DTSTART:' . $dtstart;
			$lines[] = 'DTEND:' . $dtend;
			$lines[] = 'SUMMARY:' . $this->escape_ical_text( $post->post_title );

			if ( ! empty( $location ) ) {
				$lines[] = 'LOCATION:' . $this->escape_ical_text( $location );
			}

			if ( ! empty( $description ) ) {
				$lines[] = 'DESCRIPTION:' . $this->escape_ical_text( mb_substr( $description, 0, 500 ) );
			}

			if ( ! empty( $url ) ) {
				$lines[] = 'URL:' . $url;
			}

			$lines[] = 'STATUS:CONFIRMED';
			$lines[] = 'END:VEVENT';
		}

		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", $lines ) . "\r\n";
	}

	// =========================================================================
	// Вспомогательные методы
	// =========================================================================

	/**
	 * Форматирование даты для iCal (UTC, формат DTSTART/DTEND).
	 *
	 * @param string|int $date Дата (строка strtotime или timestamp).
	 * @return string Формат: YYYYMMDDTHHMMSSZ.
	 */
	private function format_ical_date( $date ) {
		$timestamp = is_numeric( $date ) ? (int) $date : strtotime( $date );

		if ( ! $timestamp || -1 === $timestamp ) {
			return gmdate( 'Ymd\THis\Z', time() );
		}

		return gmdate( 'Ymd\THis\Z', $timestamp );
	}

	/**
	 * Экранирование текста для iCal.
	 *
	 * Спецсимволы: запятые, точки с запятой, обратные слэши.
	 *
	 * @param string $text Исходный текст.
	 * @return string Экранированный текст.
	 */
	private function escape_ical_text( $text ) {
		$text = str_replace(
			array( '\\', ',', ';' ),
			array( '\\\\', '\\,', '\\;' ),
			$text
		);

		return trim( $text );
	}

	/**
	 * Получение названия площадки для события.
	 *
	 * @param int $post_id ID события.
	 * @return string Название площадки или пустая строка.
	 */
	private function get_venue_name( $post_id ) {
		$venues = get_post_meta( $post_id, '_idpg_venue', true );

		if ( ! $venues ) {
			return '';
		}

		if ( is_array( $venues ) ) {
			$venues = reset( $venues );
		}

		$venue_post = get_post( absint( $venues ) );

		return ( $venue_post && 'venue' === $venue_post->post_type )
			? $venue_post->post_title
			: '';
	}

	/**
	 * Получение названия города для события.
	 *
	 * @param int $post_id ID события.
	 * @return string Название города или пустая строка.
	 */
	private function get_city_name( $post_id ) {
		$terms = wp_get_post_terms( $post_id, 'city', array( 'fields' => 'names' ) );

		return ( ! is_wp_error( $terms ) && ! empty( $terms ) )
			? $terms[0]
			: '';
	}

	/**
	 * Сборка строки LOCATION.
	 *
	 * @param string $venue Название площадки.
	 * @param string $city  Название города.
	 * @return string Строка вида "Площадка, Город".
	 */
	private function build_location( $venue, $city ) {
		$parts = array_filter( array( $venue, $city ) );

		return implode( ', ', $parts );
	}

	/**
	 * Отправка HTTP-заголовков для iCal-файла одного события.
	 *
	 * @param WP_Post $post Объект события.
	 */
	private function send_headers( $post ) {
		$slug = sanitize_file_name( $post->post_name );

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $slug . '.ics"' );
	}

	/**
	 * Отправка HTTP-заголовков для iCal-файла нескольких событий.
	 *
	 * @param string $filename Базовое имя файла (без расширения).
	 */
	private function send_bulk_headers( $filename ) {
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '.ics"' );
	}
}
