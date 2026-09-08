<?php
/**
 * Провайдер импорта: iCal (.ics).
 *
 * Для партнёров без полноценного API — календарные ленты.
 * Парсит VEVENT: DTSTART/DTEND/SUMMARY/DESCRIPTION/LOCATION/URL/UID.
 *
 * @package Idpg_Event_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Provider_iCal
 */
class IDPG_Provider_iCal extends IDPG_Import_Provider {

	/**
	 * Человекочитаемое название.
	 *
	 * @return string
	 */
	public static function get_label() {
		return __( 'iCal (.ics)', 'idpg-event-import' );
	}

	/**
	 * Slug типа провайдера.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return 'ical';
	}

	/**
	 * iCal не пагинируется: первая страница возвращает всё, дальше — пусто.
	 *
	 * @param int $page Номер страницы.
	 * @return array|WP_Error Массив сырых событий (псевдо-API формат) или ошибка.
	 */
	public function fetch_page( $page ) {
		if ( $page > 1 ) {
			return array(); // Одна «страница» — весь календарь.
		}

		$url = isset( $this->source['endpoint'] ) ? esc_url_raw( (string) $this->source['endpoint'], array( 'http', 'https' ) ) : '';

		$response = $this->http_get( $url );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'idpgei_http_' . $code,
				sprintf( /* translators: %d: HTTP-код */ __( 'Календарь вернул код %d.', 'idpg-event-import' ), $code )
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );

		return $this->parse_ical( $body );
	}

	/**
	 * Разбор iCal-текста в массив событий.
	 *
	 * Каждый элемент получает поля псевдо-API:
	 * external_id (UID), title (SUMMARY), description (DESCRIPTION),
	 * starts_at/ends_at (DTSTART/DTEND), venue_name (LOCATION),
	 * buy_url (URL).
	 *
	 * @param string $ical Текст .ics.
	 * @return array Массив событий (может быть пустым при отсутствии VEVENT).
	 */
	public function parse_ical( $ical ) {
		$events = array();
		$lines  = $this->unfold_lines( (string) $ical );

		$current = null;
		$field   = null;

		foreach ( $lines as $line ) {
			// Начало события.
			if ( 0 === strpos( $line, 'BEGIN:VEVENT' ) ) {
				$current = array(
					'external_id' => '',
					'title'       => '',
					'description' => '',
					'starts_at'   => '',
					'ends_at'     => '',
					'venue_name'  => '',
					'buy_url'     => '',
				);
				$field = null;
				continue;
			}

			// Конец события.
			if ( 0 === strpos( $line, 'END:VEVENT' ) ) {
				if ( is_array( $current ) && ! empty( $current['title'] ) ) {
					$events[] = $current;
				}
				$current = null;
				$field   = null;
				continue;
			}

			if ( ! is_array( $current ) ) {
				continue; // Вне VEVENT (VTIMEZONE, VCALENDAR и пр.).
			}

			// Строки свойств: NAME;PARAM=VAL:value или NAME:value.
			if ( preg_match( '/^([A-Z-]+)(?:;[^:]*)?:(.*)$/', $line, $m ) ) {
				$name  = strtoupper( $m[1] );
				$value = trim( $m[2] );

				switch ( $name ) {
					case 'UID':
						$current['external_id'] = $value;
						$field                  = null;
						break;
					case 'SUMMARY':
						$current['title'] = $value;
						$field             = null;
						break;
					case 'DESCRIPTION':
						$current['description'] = $value;
						$field                  = null;
						break;
					case 'LOCATION':
						$current['venue_name'] = $value;
						$field                 = null;
						break;
					case 'URL':
						$current['buy_url'] = $value;
						$field              = null;
						break;
					case 'DTSTART':
						$current['starts_at'] = $this->parse_ical_date( $value );
						$field                = null;
						break;
					case 'DTEND':
						$current['ends_at'] = $this->parse_ical_date( $value );
						$field              = null;
						break;
					default:
						$field = null; // Неизвестное свойство целиком.
				}
			}
		}

		return $events;
	}

	/**
	 * Развернуть «свёрнутые» строки iCal (продолжение с пробела/таба).
	 *
	 * @param string $ical Текст календаря.
	 * @return array Строки.
	 */
	protected function unfold_lines( $ical ) {
		// Нормализация переводов строк.
		$ical = str_replace( array( "\r\n", "\r" ), "\n", $ical );

		// Свёрнутая строка: продолжение начинается с пробела или таба.
		$raw   = explode( "\n", $ical );
		$lines = array();

		foreach ( $raw as $line ) {
			if ( ( '' === $line ) ) {
				continue;
			}

			if ( ( 0 === strpos( $line, ' ' ) || 0 === strpos( $line, "\t" ) ) && ! empty( $lines ) ) {
				// Продолжение предыдущей строки.
				$lines[ count( $lines ) - 1 ] .= substr( $line, 1 );
				continue;
			}

			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * Разбор даты iCal в формат Y-m-d\TH:i.
	 *
	 * Поддерживает DATE (YYYYMMDD) и DATE-TIME (YYYYMMDDTHHMMSS[Z]).
	 *
	 * @param string $value Значение DTSTART/DTEND.
	 * @return string Дата в формате хранилища или исходное значение.
	 */
	protected function parse_ical_date( $value ) {
		$clean = trim( preg_replace( '/^VALUE=DATE(:|$)/', '', $value ) );

		// Полный формат с временем: 20260910T183000Z.
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z?$/', $clean, $m ) ) {
			return sprintf( '%s-%s-%sT%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5] );
		}

		// Формат только дата: 20260910.
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $clean, $m ) ) {
			return sprintf( '%s-%s-%s', $m[1], $m[2], $m[3] );
		}

		return $clean; // Не распознано — отдаём как есть (маппер отбросит).
	}
}
