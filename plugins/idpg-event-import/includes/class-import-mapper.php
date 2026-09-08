<?php
/**
 * Маппер импорта: поля партнёра → поля CPT event.
 *
 * Чистый статический класс — легко тестируется.
 *
 * @package Idpg_Event_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Import_Mapper
 */
class IDPG_Import_Mapper {

	/**
	 * Разобрать сырой элемент API в нормализованную структуру.
	 *
	 * Вход (REST-провайдер): поля с настраиваемыми именами (title|name,
	 * description|text, starts_at|date_start, ...).
	 * Вход (iCal): уже нормализованные ключи (title, starts_at, ...).
	 *
	 * @param array $raw    Сырой элемент из API.
	 * @param array $source Конфигурация источника (field_map, defaults).
	 * @return array|null Нормализованная структура или null, если событие
	 *                    не проходит минимальную валидацию.
	 */
	public static function map_event( $raw, $source ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$field_map = isset( $source['field_map'] ) && is_array( $source['field_map'] )
			? $source['field_map']
			: array();

		/**
		 * Возможные имена полей партнёра: сначала явный field_map,
		 * затем стандартные синонимы.
		 */
		$aliases = array(
			'external_id' => array( 'external_id', 'id', 'uid', 'event_id' ),
			'title'       => array( 'title', 'name', 'summary' ),
			'description' => array( 'description', 'text', 'body', 'annotation' ),
			'starts_at'   => array( 'starts_at', 'start', 'date_start', 'startDate', 'begins_at' ),
			'ends_at'     => array( 'ends_at', 'end', 'date_end', 'endDate' ),
			'venue_name'  => array( 'venue_name', 'venue', 'place', 'location', 'hall' ),
			'venue_address' => array( 'venue_address', 'address', 'place_address' ),
			'city'        => array( 'city', 'town', 'locality' ),
			'category'    => array( 'category', 'category_name', 'genre' ),
			'price_min'   => array( 'price_min', 'price_from', 'min_price', 'price' ),
			'price_max'   => array( 'price_max', 'price_to', 'max_price' ),
			'is_free'     => array( 'is_free', 'free' ),
			'currency'    => array( 'currency', 'currency_code' ),
			'buy_url'     => array( 'buy_url', 'ticket_url', 'url', 'tickets_url' ),
			'status'      => array( 'status', 'state', 'ticket_status' ),
			'image'       => array( 'image', 'poster', 'image_url', 'cover', 'thumbnail' ),
		);

		$out = array();

		foreach ( $aliases as $our => $candidates ) {
			// Явный маппинг источника имеет приоритет.
			if ( ! empty( $field_map[ $our ] ) ) {
				array_unshift( $candidates, (string) $field_map[ $our ] );
			}

			$out[ $our ] = self::pick_value( $raw, $candidates );
		}

		// --- Обязательные поля: заголовок, дата начала, внешний ID ---
		$out['title'] = sanitize_text_field( (string) $out['title'] );

		if ( '' === $out['title'] ) {
			return null;
		}

		$out['starts_at'] = self::normalize_date( $out['starts_at'] );

		if ( '' === $out['starts_at'] ) {
			return null; // Без даты событие не показывается в афише.
		}

		// Внешний ID: без него дедупликация невозможна.
		$out['external_id'] = sanitize_text_field( (string) $out['external_id'] );

		if ( '' === $out['external_id'] ) {
			return null;
		}

		// --- Необязательные поля с санитизацией ---
		$out['ends_at'] = self::normalize_date( $out['ends_at'] );

		$description = $out['description'];
		if ( is_array( $description ) ) {
			$description = implode( "\n", array_filter( $description, 'is_scalar' ) );
		}
		$out['description'] = wp_kses_post( (string) $description );

		$out['venue_name']    = sanitize_text_field( (string) $out['venue_name'] );
		$out['venue_address'] = sanitize_text_field( (string) $out['venue_address'] );
		$out['city']          = sanitize_text_field( (string) $out['city'] );
		$out['category']       = sanitize_text_field( (string) $out['category'] );
		$out['currency']      = strtoupper( sanitize_key( (string) $out['currency'] ) );
		$out['buy_url']       = esc_url_raw( (string) $out['buy_url'] );
		$out['image']         = esc_url_raw( (string) $out['image'] );

		// Цена: числа, мусор отбрасываем.
		$out['price_min'] = self::to_float( $out['price_min'] );
		$out['price_max'] = self::to_float( $out['price_max'] );

		// Бесплатность: явный флаг или цена 0 при наличии buy_url.
		$free_raw = $out['is_free'];
		$out['is_free'] = ( true === $free_raw || 1 === (int) $free_raw || 'yes' === $free_raw || 'free' === $free_raw ) ? 'yes' : '';

		// Статус: приведение к нашим значениям.
		$out['status'] = self::map_status( $out['status'] );

		// Дефолты источника (например, валюта по умолчанию).
		$defaults = isset( $source['defaults'] ) && is_array( $source['defaults'] ) ? $source['defaults'] : array();

		if ( '' === $out['currency'] && ! empty( $defaults['currency'] ) ) {
			$out['currency'] = strtoupper( sanitize_key( (string) $defaults['currency'] ) );
		}

		if ( '' === $out['city'] && ! empty( $defaults['city'] ) ) {
			$out['city'] = sanitize_text_field( (string) $defaults['city'] );
		}

		return $out;
	}

	/**
	 * Найти первое непустое значение по списку ключей.
	 *
	 * @param array $raw        Сырой элемент.
	 * @param array $candidates Кандидаты имён полей.
	 * @return mixed|null
	 */
	protected static function pick_value( $raw, $candidates ) {
		foreach ( $candidates as $key ) {
			if ( isset( $raw[ $key ] ) && null !== $raw[ $key ] && '' !== $raw[ $key ] ) {
				return $raw[ $key ];
			}
		}

		return null;
	}

	/**
	 * Нормализация даты в формат хранилища Y-m-d\TH:i (или Y-m-d).
	 *
	 * Локальное время партнёра сохраняется как есть: афиша показывает
	 * время «как заявлено» (без пересчёта часовых поясов).
	 *
	 * @param string $value Сырая дата.
	 * @return string Дата или пустая строка.
	 */
	public static function normalize_date( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		// ISO с зоной: берём локальные компоненты, зону отбрасываем.
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/', $value, $m ) ) {
			return $m[1] . 'T' . $m[2];
		}

		// Только дата.
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return $value;
		}

		// Произвольный формат (10.09.2026 18:30): парсим без смены зоны.
		$ts = strtotime( $value );

		if ( false === $ts || -1 === $ts ) {
			return '';
		}

		return gmdate( 'Y-m-d\TH:i', $ts );
	}

	/**
	 * Число или null.
	 *
	 * @param mixed $value Значение.
	 * @return float|null
	 */
	public static function to_float( $value ) {
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}

		// Число в строке с мусором («от 500 ₽»).
		if ( is_string( $value ) && preg_match( '/\d+(?:[.,]\d+)?/', $value, $m ) ) {
			return (float) str_replace( ',', '.', $m[0] );
		}

		return null;
	}

	/**
	 * Приведение статуса партнёра к нашему enum.
	 *
	 * @param string $status Статус из API.
	 * @return string in_sale|sold_out|cancelled|postponed|'' (не распознан).
	 */
	public static function map_status( $status ) {
		$map = array(
			'active'    => 'in_sale',
			'in_sale'   => 'in_sale',
			'sold_out'  => 'sold_out',
			'soldout'   => 'sold_out',
			'cancelled' => 'cancelled',
			'canceled'  => 'cancelled',
			'postponed' => 'postponed',
		);

		$key = strtolower( trim( sanitize_text_field( (string) $status ) ) );

		return isset( $map[ $key ] ) ? $map[ $key ] : '';
	}
}
