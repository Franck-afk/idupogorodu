<?php
/**
 * Абстрактный провайдер импорта событий.
 *
 * Контракт: каждый источник (REST API, iCal и т.д.) реализует
 * получение «сырых» событий и знает, как получить следующую страницу.
 *
 * @package Idpg_Event_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Import_Provider
 */
abstract class IDPG_Import_Provider {

	/**
	 * Конфигурация источника (строка из репозитория idpgei_sources).
	 *
	 * @var array
	 */
	protected $source = array();

	/**
	 * Транспорт для HTTP-запросов (тестируемость: подменяется фильтром).
	 *
	 * @var callable|null
	 */
	protected $transport = null;

	/**
	 * Конструктор.
	 *
	 * @param array $source Конфигурация источника.
	 */
	public function __construct( $source ) {
		$this->source = $source;
	}

	/**
	 * Человекочитаемое название типа провайдера.
	 *
	 * @return string
	 */
	abstract public static function get_label();

	/**
	 * Slug типа провайдера.
	 *
	 * @return string
	 */
	abstract public static function get_slug();

	/**
	 * Загрузка одной страницы событий.
	 *
	 * @param int $page Номер страницы (начиная с 1).
	 * @return array|WP_Error Массив сырых событий или ошибка.
	 */
	abstract public function fetch_page( $page );

	/**
	 * HTTP-запрос через wp_remote_get с подменой для тестов.
	 *
	 * @param string $url     URL запроса.
	 * @param array  $args    Аргументы wp_remote_get.
	 * @return array|WP_Error Ответ WP.
	 */
	protected function http_get( $url, $args = array() ) {
		$args = wp_parse_args( $args, array(
			'timeout'    => 15,
			'user-agent' => 'IdpgEventImport/' . IDPGEI_VERSION . '; ' . home_url( '/' ),
		) );

		/**
		 * Фильтр транспорта HTTP: позволяет тестам и кастомным сценариям
		 * подменить реальный запрос.
		 *
		 * @param callable|null $transport null = штатный wp_remote_get.
		 * @param string        $url      URL запроса.
		 */
		$transport = apply_filters( 'idpgei_http_transport', null, $url );

		if ( is_callable( $transport ) ) {
			return call_user_func( $transport, $url, $args );
		}

		return wp_remote_get( $url, $args );
	}
}
