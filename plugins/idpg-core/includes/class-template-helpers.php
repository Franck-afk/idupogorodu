<?php
/**
 * Хелперы для шаблонов темы.
 *
 * Статические методы, которые тема вызывает для получения
 * данных или готового HTML-кода простых элементов (кнопки, виджеты).
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Template_Helper
 *
 * Содержит только статические методы. Не выводит сложную верстку —
 * только массивы данных и простые HTML-фрагменты.
 */
class IDPG_Template_Helper {

	/**
	 * Prefix для ключей кэша.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'idpg_helper_';

	/**
	 * Получение HTML-кода кнопки покупки билета.
	 *
	 * Читает мета-поля события:
	 * - `_idpg_ticket_status` — статус (in_sale / sold_out / cancelled / postponed).
	 * - `_idpg_ticket_type`   — тип (external_link / widget_yandex).
	 * - `_idpg_ticket_url`    — URL внешнего сайта продажи.
	 * - `_idpg_widget_code`   — код виджета продажи билетов.
	 * - `_idpg_is_free`       — бесплатное событие.
	 *
	 * @param int $post_id ID записи события.
	 * @return string HTML-код кнопки или сообщения. Пустая строка, если данных нет.
	 */
	public static function get_ticket_button( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return '';
		}

		$cache_key  = self::CACHE_PREFIX . 'btn_' . $post_id;
		$cached     = wp_cache_get( $cache_key, 'idpg' );

		if ( false !== $cached ) {
			return $cached;
		}

		$status      = get_post_meta( $post_id, '_idpg_ticket_status', true );
		$ticket_type = get_post_meta( $post_id, '_idpg_ticket_type', true );
		$ticket_url  = get_post_meta( $post_id, '_idpg_ticket_url', true );
		$widget_code = get_post_meta( $post_id, '_idpg_widget_code', true );
		$is_free     = 'yes' === get_post_meta( $post_id, '_idpg_is_free', true );

		$html = '';

		switch ( $status ) {
			case 'sold_out':
			case 'cancelled':
			case 'postponed':
				$labels = array(
					'sold_out'  => __( 'Билеты закончились', 'idpg-core' ),
					'cancelled' => __( 'Мероприятие отменено', 'idpg-core' ),
					'postponed' => __( 'Мероприятие перенесено', 'idpg-core' ),
				);
				$html   = sprintf(
					'<span class="idpg-sold-out idpg-sold-out--%s">%s</span>',
					esc_attr( $status ),
					esc_html( $labels[ $status ] )
				);
				break;

			case 'in_sale':
			default:
				if ( 'widget_yandex' === $ticket_type ) {
					// Код виджета — доверенный HTML от администратора.
					$html = ! empty( $widget_code )
						? $widget_code
						: sprintf( '<div id="idpg-yandex-widget" data-event-id="%d"></div>', $post_id );
					break;
				}

				$url = ! empty( $ticket_url ) ? esc_url( $ticket_url, array( 'http', 'https' ) ) : '';

				if ( $url ) {
					$html = sprintf(
						'<a href="%s" class="idpg-btn idpg-btn--primary" data-event-id="%d" target="_blank" rel="noopener noreferrer">%s</a>',
						$url,
						$post_id,
						esc_html__( 'Купить билет', 'idpg-core' )
					);
				} elseif ( $is_free ) {
					$html = sprintf(
						'<span class="idpg-free">%s</span>',
						esc_html__( 'Вход свободный', 'idpg-core' )
					);
				}
				break;
		}

		wp_cache_set( $cache_key, $html, 'idpg', HOUR_IN_SECONDS );

		return $html;
	}

	/**
	 * Получение списка сеансов/сеансов события.
	 *
	 * Читает JSON-зеркало complex-поля Carbon Fields
	 * (IDPG_Meta::SESSIONS_MIRROR). Возвращает отформатированный массив
	 * сеансов. Дата сеанса не хранится — она задаётся полями
	 * `_idpg_start_date` / `_idpg_end_date` события.
	 *
	 * Формат элемента возврата:
	 * ```
	 * array(
	 *     'time'     => '19:00',
	 *     'price'    => '1500',
	 *     'url'      => 'https://...',
	 *     'date'     => '',       // только для legacy-данных
	 *     'hall'     => '',       // только для legacy-данных
	 *     'currency' => '',       // только для legacy-данных
	 * )
	 * ```
	 *
	 * @param int $post_id ID записи события.
	 * @return array Массив сеансов или пустой массив.
	 */
	public static function get_event_sessions( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return array();
		}

		$cache_key = self::CACHE_PREFIX . 'sessions_' . $post_id;
		$cached    = wp_cache_get( $cache_key, 'idpg' );

		if ( false !== $cached ) {
			return $cached;
		}

		$raw = get_post_meta( $post_id, IDPG_Meta::SESSIONS_MIRROR, true );

		if ( empty( $raw ) ) {
			wp_cache_set( $cache_key, array(), 'idpg', HOUR_IN_SECONDS );
			return array();
		}

		$sessions = self::parse_sessions( $raw );

		$sessions = array_map( array( self::class, 'sanitize_session' ), $sessions );

		// Обязательно только время: дата берётся из полей события.
		$sessions = array_filter( $sessions, function ( $session ) {
			return ! empty( $session['time'] );
		} );

		$sessions = array_values( $sessions );

		wp_cache_set( $cache_key, $sessions, 'idpg', HOUR_IN_SECONDS );

		return $sessions;
	}

	/**
	 * Парсинг сырых данных сеансов.
	 *
	 * Поддерживает JSON и serialize().
	 *
	 * @param string $raw Сырая строка из мета-поля.
	 * @return array Массив сеансов.
	 */
	private static function parse_sessions( $raw ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( is_serialized( $raw ) ) {
			$decoded = maybe_unserialize( $raw );
			return is_array( $decoded ) ? $decoded : array();
		}

		$decoded = json_decode( $raw, true );

		if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
			return $decoded;
		}

		return array();
	}

	/**
	 * Санитизация одного сеанса.
	 *
	 * @param array $session Данные сеанса.
	 * @return array Очищенный массив.
	 */
	private static function sanitize_session( $session ) {
		if ( ! is_array( $session ) ) {
			return array();
		}

		return array(
			'date'     => sanitize_text_field( $session['date'] ?? '' ),
			'time'     => sanitize_text_field( $session['time'] ?? '' ),
			'hall'     => sanitize_text_field( $session['hall'] ?? '' ),
			'price'    => sanitize_text_field( $session['price'] ?? '' ),
			'currency' => sanitize_text_field( $session['currency'] ?? '' ),
			'url'      => esc_url_raw( $session['url'] ?? '' ),
		);
	}

	/**
	 * Получение мета-данных события одним массивом.
	 *
	 * Удобный метод для темы, чтобы не читать каждое meta-поле отдельно.
	 *
	 * @param int $post_id ID записи события.
	 * @return array Ассоциативный массив с данными.
	 */
	public static function get_event_meta( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return array();
		}

		$cache_key = self::CACHE_PREFIX . 'meta_' . $post_id;
		$cached    = wp_cache_get( $cache_key, 'idpg' );

		if ( false !== $cached ) {
			return $cached;
		}

		// DEV-95: организатор работает только в рублях, а организаторский путь
		// выставляет `_idpg_currency` явно при сохранении. События, созданные
		// до этого правила или в обход REST (импорт, CLI, прямой
		// wp_insert_post), остаются с пустой валютой — и карточка выводила бы
		// цену вроде «500» вообще без символа. Пустое значение здесь
		// заменяется на RUB, чтобы карточка и прайс всегда показывали ₽.
		$currency = (string) get_post_meta( $post_id, '_idpg_currency', true );

		$meta = array(
			'status'      => get_post_meta( $post_id, '_idpg_ticket_status', true ),
			'ticket_type' => get_post_meta( $post_id, '_idpg_ticket_type', true ),
			'ticket_url'  => get_post_meta( $post_id, '_idpg_ticket_url', true ),
			'date_start'  => get_post_meta( $post_id, '_idpg_start_date', true ),
			'date_end'    => get_post_meta( $post_id, '_idpg_end_date', true ),
			'all_day'     => 'yes' === get_post_meta( $post_id, '_idpg_all_day', true ),
			'price_from'  => get_post_meta( $post_id, '_idpg_price_from', true ),
			'price_to'    => get_post_meta( $post_id, '_idpg_price_to', true ),
			'vip_price'   => get_post_meta( $post_id, '_idpg_vip_price', true ),
			'is_free'     => 'yes' === get_post_meta( $post_id, '_idpg_is_free', true ),
			'currency'    => '' === trim( $currency ) ? 'RUB' : $currency,
			'address'     => get_post_meta( $post_id, '_idpg_address', true ),
			'is_online'   => 'yes' === get_post_meta( $post_id, '_idpg_is_online', true ),
			'stream_url'  => get_post_meta( $post_id, '_idpg_stream_url', true ),
			'video'       => get_post_meta( $post_id, '_idpg_video_trailer', true ),
		);

		wp_cache_set( $cache_key, $meta, 'idpg', HOUR_IN_SECONDS );

		return $meta;
	}

	/**
	 * Получение списка городов (терминов таксономии city).
	 *
	 * Возвращает массив вида [ term_id => name, ... ].
	 * Кэширует результат, т.к. города меняются редко.
	 *
	 * @return array Ассоциативный массив ID => Название.
	 */
	public static function get_cities() {
		$cache_key = self::CACHE_PREFIX . 'cities_list';
		$cached    = wp_cache_get( $cache_key, 'idpg' );

		if ( false !== $cached ) {
			return $cached;
		}

		$terms = get_terms( array(
			'taxonomy'   => 'city',
			'orderby'    => 'name',
			'order'      => 'ASC',
			'hide_empty' => true,
		) );

		$cities = array();

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$cities[ $term->term_id ] = $term->name;
			}
		}

		wp_cache_set( $cache_key, $cities, 'idpg', DAY_IN_SECONDS );

		return $cities;
	}
}
