<?php
/**
 * Провайдер импорта: JSON REST API.
 *
 * Ожидает от API массив событий (или объект с полем-списком,
 * имя которого настраивается). Пагинация — по номеру страницы.
 *
 * @package Idpg_Event_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Provider_REST
 */
class IDPG_Provider_REST extends IDPG_Import_Provider {

	/**
	 * Максимальное число страниц за прогон (защита от бесконечной пагинации).
	 */
	const MAX_PAGES = 20;

	/**
	 * Человекочитаемое название.
	 *
	 * @return string
	 */
	public static function get_label() {
		return __( 'JSON REST API', 'idpg-event-import' );
	}

	/**
	 * Slug типа провайдера.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return 'rest';
	}

	/**
	 * Загрузка одной страницы событий.
	 *
	 * Формат ответа (настраивается в источнике, поле list_path):
	 * 1. [ {...}, {...} ]                 — корневой массив;
	 * 2. { "data": [ ... ] }              — список внутри ключа "data";
	 * 3. { "data": { "items": [ ... ] } } — вложенность через точку: "data.items".
	 *
	 * @param int $page Номер страницы (начиная с 1).
	 * @return array|WP_Error Массив сырых событий или ошибка.
	 */
	public function fetch_page( $page ) {
		$url = add_query_arg( $this->get_page_param( $page ), $this->get_endpoint() );

		$response = $this->http_get( $url, array(
			'headers' => $this->get_headers(),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'idpgei_http_' . $code,
				sprintf( /* translators: %s: URL */ __( 'API вернул код %d для %s', 'idpg-event-import' ), $code, $url )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'idpgei_bad_json',
				__( 'Ответ API не является корректным JSON-объектом/массивом.', 'idpg-event-import' )
			);
		}

		$items = $this->extract_list( $body );

		if ( ! is_array( $items ) ) {
			return new WP_Error(
				'idpgei_no_list',
				sprintf( /* translators: %s: имя поля */ __( 'В ответе API не найден список событий (поле «%s»).', 'idpg-event-import' ), $this->get_list_path() )
			);
		}

		return $items;
	}

	/**
	 * Endpoint источника.
	 *
	 * @return string
	 */
	protected function get_endpoint() {
		$url = isset( $this->source['endpoint'] ) ? (string) $this->source['endpoint'] : '';

		// Санитизация: только http/https.
		$url = esc_url_raw( $url, array( 'http', 'https' ) );

		return $url;
	}

	/**
	 * Параметр пагинации.
	 *
	 * @param int $page Номер страницы.
	 * @return array
	 */
	protected function get_page_param( $page ) {
		// Имя параметра настраивается (page|p|offset...), по умолчанию page.
		$param = ! empty( $this->source['page_param'] ) ? sanitize_key( $this->source['page_param'] ) : 'page';

		return array( $param => $page );
	}

	/**
	 * HTTP-заголовки (авторизация Bearer).
	 *
	 * @return array
	 */
	protected function get_headers() {
		$headers = array( 'Accept' => 'application/json' );

		if ( ! empty( $this->source['api_key'] ) ) {
			$headers['Authorization'] = 'Bearer ' . (string) $this->source['api_key'];
		}

		return $headers;
	}

	/**
	 * Извлечь список событий из тела ответа по пути list_path.
	 *
	 * @param array $body Распарсенный JSON.
	 * @return array|null
	 */
	protected function extract_list( $body ) {
		$path = $this->get_list_path();

		if ( '' === $path ) {
			return is_array( $body ) ? $body : null;
		}

		// Навигация по вложенности через точку: "data.items".
		$node = $body;

		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) || ! is_array( $node[ $key ] ) ) {
				return null;
			}
			$node = $node[ $key ];
		}

		return $node;
	}

	/**
	 * Путь к списку событий в ответе.
	 *
	 * @return string Пустая строка = корневой массив.
	 */
	protected function get_list_path() {
		$path = isset( $this->source['list_path'] ) ? (string) $this->source['list_path'] : 'data';

		return trim( $path );
	}
}
