<?php
/**
 * Общие вспомогательные методы плагина (DEV-22).
 *
 * Здесь живут реализации, которые раньше дублировались по нескольку раз:
 *
 * - `get_client_ip()` — была копия в `IDPG_Auth_Rate_Limiter`
 *   (безопасная, с проверкой прокси) и копия в `IDPG_Views`
 *   (без проверки, доверяет подделываемому HTTP_CLIENT_IP);
 * - `get_city_tree()` — была копия в `IDPG_City_Filter` (без сортировки)
 *   и копия в `IDPG_City_Selector_Widget` (с сортировкой и кэшем);
 * - константы и геттеры мета-ключей билетов — строковые литералы
 *   `_idpg_ticket_status` / `_idpg_ticket_type` / `_idpg_ticket_url`
 *   повторялись в семи файлах.
 *
 * Класс статический, без состояния и без собственных хуков: старые
 * точки входа не удалены, они лишь делегируют сюда (AGENTS.md, п.1-2).
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Helpers
 */
class IDPG_Helpers {

	/**
	 * Мета-ключ статуса продажи билетов.
	 */
	const META_TICKET_STATUS = '_idpg_ticket_status';

	/**
	 * Мета-ключ типа продажи билетов.
	 */
	const META_TICKET_TYPE = '_idpg_ticket_type';

	/**
	 * Мета-ключ внешнего URL билетов.
	 */
	const META_TICKET_URL = '_idpg_ticket_url';

	/**
	 * IP, который отдаётся, когда адрес определить не удалось.
	 */
	const IP_UNKNOWN = '0.0.0.0';

	/**
	 * Таксономия городов.
	 */
	const CITY_TAXONOMY = 'city';

	// =========================================================================
	// IP-адрес клиента
	// =========================================================================

	/**
	 * Получение IP-адреса клиента.
	 *
	 * REMOTE_ADDR — адрес, заданный самим веб-сервером, его нельзя подменить.
	 * Заголовки X-Forwarded-For / X-Real-IP / Client-IP доверяются ТОЛЬКО
	 * тогда, когда REMOTE_ADDR указывает на прокси (loopback/приватный адрес),
	 * иначе их можно подделать и обойти ограничения.
	 * Возвращает валидный IPv4/IPv6-адрес или self::IP_UNKNOWN.
	 *
	 * @return string
	 */
	public static function get_client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		// Прямое соединение с публичным адресом — прокси-заголовки не используем.
		if ( filter_var( $remote, FILTER_VALIDATE_IP ) && ! self::is_proxy_address( $remote ) ) {
			return $remote;
		}

		// Соединение через прокси (REMOTE_ADDR — адрес прокси).
		if ( filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			// Приоритет 1: X-Forwarded-For (самый надёжный за прокси).
			if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
				$ips       = explode( ',', $forwarded );
				$first     = trim( $ips[0] );

				if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
					return $first;
				}
			}

			// Приоритет 2: X-Real-IP (используется в nginx).
			if ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) {
				$real_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );

				if ( filter_var( $real_ip, FILTER_VALIDATE_IP ) ) {
					return $real_ip;
				}
			}

			// Приоритет 3: Client-IP (менее стандартный).
			if ( isset( $_SERVER['HTTP_CLIENT_IP'] ) ) {
				$client_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );

				if ( filter_var( $client_ip, FILTER_VALIDATE_IP ) ) {
					return $client_ip;
				}
			}
		}

		if ( filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return $remote;
		}

		return self::IP_UNKNOWN;
	}

	/**
	 * Является ли адрес адресом прокси-сервера.
	 *
	 * Loopback, приватные и зарезервированные диапазоны IPv4/IPv6
	 * считаются адресами прокси.
	 *
	 * @param string $ip IP-адрес.
	 * @return bool
	 */
	private static function is_proxy_address( $ip ) {
		return filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		) === false;
	}

	// =========================================================================
	// Дерево городов
	// =========================================================================

	/**
	 * Дерево городов: регионы → города → районы (один запрос get_terms).
	 *
	 * Единственная реализация: и `IDPG_City_Filter::get_city_tree()`,
	 * и `IDPG_City_Selector_Widget` (через build_city_tree) вызывают её.
	 *
	 * @param string $sort Ключ сортировки: alpha (по имени), popular (по
	 *                     количеству событий), manual (ручной порядок).
	 * @return array Массив регионов с вложенными children.
	 */
	public static function get_city_tree( $sort = 'alpha' ) {
		$orderby = 'name';
		$order   = 'ASC';

		if ( 'popular' === $sort ) {
			$orderby = 'count';
			$order   = 'DESC';
		} elseif ( 'manual' === $sort ) {
			$orderby = 'term_order';
		}

		$terms = get_terms(
			array(
				'taxonomy'   => self::CITY_TAXONOMY,
				'hide_empty' => false,
				'orderby'    => $orderby,
				'order'      => $order,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$flatten = array();
		foreach ( $terms as $term ) {
			$flatten[ (int) $term->term_id ] = array(
				'id'     => (int) $term->term_id,
				'parent' => (int) $term->parent,
				'name'   => $term->name,
				'slug'   => $term->slug,
				'count'  => (int) $term->count,
			);
		}

		$tree = array();
		foreach ( $flatten as $id => $node ) {
			if ( 0 === $node['parent'] ) {
				$tree[ $id ] = $node;
			}
		}

		// Второй проход: прикрепляем города (дети областей) и районы (дети городов).
		foreach ( $flatten as $id => $node ) {
			if ( 0 === $node['parent'] ) {
				continue;
			}
			$parent = $node['parent'];
			if ( isset( $tree[ $parent ] ) ) {
				$tree[ $parent ]['children'][ $id ] = $node;
				continue;
			}
			// Район (уровень 3): ищем его город-родителя внутри области и записываем по ссылке.
			foreach ( $tree as &$region ) {
				if ( isset( $region['children'][ $parent ] ) ) {
					$region['children'][ $parent ]['children'][ $id ] = $node;
					break;
				}
			}
			unset( $region );
		}

		return array_values( $tree );
	}

	// =========================================================================
	// Мета-данные билетов
	// =========================================================================

	/**
	 * Статус продажи билетов события.
	 *
	 * @param int $post_id ID события.
	 * @return string
	 */
	public static function get_ticket_status( $post_id ) {
		return (string) get_post_meta( (int) $post_id, self::META_TICKET_STATUS, true );
	}

	/**
	 * Тип продажи билетов события.
	 *
	 * @param int $post_id ID события.
	 * @return string
	 */
	public static function get_ticket_type( $post_id ) {
		return (string) get_post_meta( (int) $post_id, self::META_TICKET_TYPE, true );
	}

	/**
	 * Внешний URL билетов события.
	 *
	 * @param int $post_id ID события.
	 * @return string
	 */
	public static function get_ticket_url( $post_id ) {
		return (string) get_post_meta( (int) $post_id, self::META_TICKET_URL, true );
	}
}
