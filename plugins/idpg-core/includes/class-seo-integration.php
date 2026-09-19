<?php
/**
 * Интеграция с плагином Yoast SEO.
 *
 * Когда Yoast активен:
 *  - в его граф JSON-LD добавляются сущности Event (страница события)
 *    и Place (страница площадки) с гео-данными площадки (GeoCoordinates),
 *    городом события (addressLocality) и ценами (Offer);
 *  - og:image/twitter:image для событий и площадок гарантированно берётся
 *    из постера (миниатюра записи = постер проекта);
 *  - тема объявляет поддержку крошек Yoast (yoast_breadcrumb), вывод оборачивается
 *    в собственные стили темы (breadcrumbs.php).
 *
 * Дублирующую разметку Event, которую раньше печатала тема, при активном Yoast
 * отключает сам шаблонный тег idpg_core_event_jsonld() (см. template-tags.php) —
 * одна сущность на страницу.
 *
 * Модуль самодостаточен: не зависит от функций темы. Если Yoast не активен,
 * хуки не вешаются и разметку продолжает выводить тема.
 *
 * @package Idpg_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_SEO_Integration
 */
class IDPG_SEO_Integration {

	/**
	 * Единственный экземпляр класса.
	 *
	 * @var IDPG_SEO_Integration|null
	 */
	private static $instance = null;

	/**
	 * Константа версии модуля (для будущих апгрейдов).
	 */
	const VERSION = '2.0.0';

	/**
	 * Получение единственного экземпляра класса.
	 *
	 * @return IDPG_SEO_Integration
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Конструктор: проверка Yoast и навешивание хуков.
	 */
	private function __construct() {
		// Плагин интеграции должен быть активен.
		if ( ! $this->is_yoast_active() ) {
			return;
		}

		// --- Schema.org (JSON-LD граф Yoast) ---
		add_filter( 'wpseo_schema_graph', array( $this, 'add_event_schema' ), 10, 2 );
		add_filter( 'wpseo_schema_graph', array( $this, 'add_venue_schema' ), 11, 2 );

		// --- Open Graph / Twitter Cards: постер события/площадки как запасная картинка ---
		add_filter( 'wpseo_opengraph_image', array( $this, 'filter_opengraph_image' ), 10, 2 );
		add_filter( 'wpseo_twitter_image', array( $this, 'filter_twitter_image' ), 10, 2 );

		// --- Крошки Yoast: тема должна знать о поддержке ---
		// Вызываем сразу: модуль стартует на init/plugins_loaded, а after_setup_theme
		// к этому моменту уже отработал — хук на него не сработает.
		$this->declare_breadcrumb_support();
	}

	// =========================================================================
	// Проверки окружения
	// =========================================================================

	/**
	 * Активен ли плагин Yoast SEO.
	 *
	 * Проверка по константе самого плагина (работает и при ранней загрузке
	 * классов — не зависит от is_plugin_active).
	 *
	 * @return bool
	 */
	public function is_yoast_active() {
		return defined( 'WPSEO_VERSION' ) || function_exists( 'yoast_breadcrumb' );
	}

	// =========================================================================
	// Schema.org
	// =========================================================================

	/**
	 * Добавить сущность Event в граф Yoast на странице отдельного события.
	 *
	 * @param array $graph Собранный граф Yoast.
	 * @param mixed $context Контекст Meta_Tags_Context (не используется).
	 * @return array
	 */
	public function add_event_schema( $graph, $context = null ) {
		// Только страница отдельного события.
		if ( ! is_singular( 'event' ) ) {
			return $graph;
		}

		$schema = $this->build_event_schema( get_the_ID() );

		if ( $schema ) {
			// Свой @id, чтобы не конфликтовать с WebPage (main_schema_id страницы).
			$schema['@id'] = get_permalink( get_the_ID() ) . '#idpg-event';
			$graph[]       = $schema;
		}

		return $graph;
	}

	/**
	 * Добавить сущность Place в граф Yoast на странице отдельной площадки.
	 *
	 * @param array $graph Собранный граф Yoast.
	 * @param mixed $context Контекст Meta_Tags_Context (не используется).
	 * @return array
	 */
	public function add_venue_schema( $graph, $context = null ) {
		// Только страница отдельной площадки.
		if ( ! is_singular( 'venue' ) ) {
			return $graph;
		}

		$schema = $this->build_venue_schema( get_the_ID() );

		if ( $schema ) {
			$schema['@id'] = get_permalink( get_the_ID() ) . '#idpg-venue';
			$graph[]       = $schema;
		}

		return $graph;
	}

	/**
	 * Собрать Schema.org/Event для события.
	 *
	 * Чистый builder без хуков — покрывается юнит-тестами.
	 *
	 * @param int $post_id ID события.
	 * @return array|null Массив схемы или null, если событие не найдено.
	 */
	public function build_event_schema( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || 'event' !== get_post_type( $post_id ) ) {
			return null;
		}

		$schema = array(
			'@type' => 'Event',
			'name'  => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
			'url'   => get_permalink( $post_id ),
		);

		// --- Даты (формат хранилища: Y-m-d\TH:i) ---
		$start = get_post_meta( $post_id, '_idpg_start_date', true );
		$end   = get_post_meta( $post_id, '_idpg_end_date', true );

		if ( $start ) {
			$schema['startDate'] = $start;
		}
		if ( $end ) {
			$schema['endDate'] = $end;
		}

		// --- Статус (перенесено/отменено) ---
		$status   = get_post_meta( $post_id, '_idpg_ticket_status', true );
		$statuses = array(
			'postponed' => 'https://schema.org/EventPostponed',
			'cancelled' => 'https://schema.org/EventCancelled',
		);
		if ( isset( $statuses[ $status ] ) ) {
			$schema['eventStatus'] = $statuses[ $status ];
		}

		// --- Место проведения (площадка или адрес) ---
		$location = $this->build_place_node( $post_id );

		if ( $location ) {
			$schema['location'] = $location;
		}

		// --- Изображение (постер) ---
		if ( class_exists( 'IDPG_Media' ) && IDPG_Media::idpg_get_poster_id( $post_id ) ) {
			$image = IDPG_Media::idpg_get_poster_url( $post_id, 'large' );
			if ( $image ) {
				$schema['image'] = $image;
			}
		}

		// --- Описание ---
		$description = has_excerpt( $post_id )
			? get_the_excerpt( $post_id )
			: wp_trim_words( wp_strip_all_tags( (string) get_post( $post_id )->post_content ), 30, '…' );

		if ( $description ) {
			$schema['description'] = wp_strip_all_tags( $description );
		}

		// --- Предложение (цена/билеты) ---
		$offer = $this->build_offer_node( $post_id );

		if ( $offer ) {
			$schema['offers'] = $offer;
		}

		return $schema;
	}

	/**
	 * Собрать узел Place (место проведения события) с гео-данными.
	 *
	 * @param int $post_id ID события.
	 * @return array|null
	 */
	public function build_place_node( $post_id ) {
		$venue_id = (int) get_post_meta( $post_id, '_idpg_venue', true );

		$name    = '';
		$address = (string) get_post_meta( $post_id, '_idpg_venue_address', true );

		if ( $venue_id && 'venue' === get_post_type( $venue_id ) ) {
			$venue   = get_post( $venue_id );
			$name    = html_entity_decode( $venue->post_title, ENT_QUOTES, 'UTF-8' );
			$address = $address ? $address : (string) get_post_meta( $venue_id, '_idpg_venue_address', true );
		} else {
			$venue_id = 0;
		}

		if ( ! $venue_id && '' === $address ) {
			return null;
		}

		$place = array( '@type' => 'Place' );

		if ( $name ) {
			$place['name'] = $name;
		}

		// Город события: первый связанный терм таксономии city.
		$cities = wp_get_post_terms( $post_id, 'city', array( 'fields' => 'names' ) );

		if ( ! is_wp_error( $cities ) && ! empty( $cities ) ) {
			$place['address'] = array(
				'@type'           => 'PostalAddress',
				'addressLocality' => $cities[0],
			);
			if ( $address ) {
				$place['address']['streetAddress'] = $address;
			}
		} elseif ( $address ) {
			$place['address'] = array(
				'@type'         => 'PostalAddress',
				'streetAddress' => $address,
			);
		}

		// --- Гео-данные площадки (GeoCoordinates) ---
		if ( $venue_id ) {
			$geo = $this->build_geo_node( $venue_id );
			if ( $geo ) {
				$place['geo'] = $geo;
			}
		}

		return $place;
	}

	/**
	 * Собрать узел GeoCoordinates для площадки.
	 *
	 * @param int $venue_id ID площадки.
	 * @return array|null
	 */
	public function build_geo_node( $venue_id ) {
		$venue_id = absint( $venue_id );

		if ( ! $venue_id ) {
			return null;
		}

		$lat = get_post_meta( $venue_id, '_idpg_venue_lat', true );
		$lng = get_post_meta( $venue_id, '_idpg_venue_lng', true );

		// Строгая числовая валидация координат.
		$lat_f = is_numeric( $lat ) ? (float) $lat : false;
		$lng_f = is_numeric( $lng ) ? (float) $lng : false;

		if ( false === $lat_f || false === $lng_f || 0.0 === $lat_f || 0.0 === $lng_f ) {
			return null;
		}

		// Диапазоны: широта -90..90, долгота -180..180.
		if ( abs( $lat_f ) > 90 || abs( $lng_f ) > 180 ) {
			return null;
		}

		return array(
			'@type'     => 'GeoCoordinates',
			'latitude'  => $lat_f,
			'longitude' => $lng_f,
		);
	}

	/**
	 * Собрать узел Offer (цена/билеты).
	 *
	 * @param int $post_id ID события.
	 * @return array|null
	 */
	public function build_offer_node( $post_id ) {
		$is_free    = 'yes' === get_post_meta( $post_id, '_idpg_is_free', true );
		$price_from = (float) get_post_meta( $post_id, '_idpg_price_from', true );
		$currency   = get_post_meta( $post_id, '_idpg_currency', true );
		$ticket_url = get_post_meta( $post_id, '_idpg_ticket_url', true );
		$status     = get_post_meta( $post_id, '_idpg_ticket_status', true );

		// Нечего публиковать — оффера нет.
		if ( ! $is_free && $price_from <= 0 && ! $ticket_url ) {
			return null;
		}

		$offer = array( '@type' => 'Offer' );

		if ( $ticket_url ) {
			$offer['url'] = esc_url_raw( $ticket_url );
		}

		if ( $is_free ) {
			$offer['price']         = 0;
			$offer['priceCurrency'] = $currency ? strtoupper( $currency ) : 'RUB';
		} elseif ( $price_from > 0 ) {
			$offer['price']         = $price_from;
			$offer['priceCurrency'] = $currency ? strtoupper( $currency ) : 'RUB';
		}

		// Доступность билетов.
		$availability = array(
			'in_sale'  => 'https://schema.org/InStock',
			'sold_out' => 'https://schema.org/SoldOut',
		);
		if ( isset( $availability[ $status ] ) ) {
			$offer['availability'] = $availability[ $status ];
		}

		return $offer;
	}

	/**
	 * Собрать Schema.org/Place для страницы площадки.
	 *
	 * @param int $post_id ID площадки.
	 * @return array|null
	 */
	public function build_venue_schema( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || 'venue' !== get_post_type( $post_id ) ) {
			return null;
		}

		$venue = get_post( $post_id );

		$schema = array(
			'@type' => 'Place',
			'name'  => html_entity_decode( $venue->post_title, ENT_QUOTES, 'UTF-8' ),
			'url'   => get_permalink( $post_id ),
		);

		// --- Адрес + город ---
		$address = get_post_meta( $post_id, '_idpg_venue_address', true );
		$cities  = wp_get_post_terms( $post_id, 'city', array( 'fields' => 'names' ) );

		if ( $address || ( ! is_wp_error( $cities ) && ! empty( $cities ) ) ) {
			$postal = array( '@type' => 'PostalAddress' );

			if ( ! is_wp_error( $cities ) && ! empty( $cities ) ) {
				$postal['addressLocality'] = $cities[0];
			}
			if ( $address ) {
				$postal['streetAddress'] = $address;
			}

			$schema['address'] = $postal;
		}

		// --- Гео-координаты ---
		$geo = $this->build_geo_node( $post_id );

		if ( $geo ) {
			$schema['geo'] = $geo;
		}

		// --- Контактные данные ---
		$phone = get_post_meta( $post_id, '_idpg_venue_phone', true );
		$site  = get_post_meta( $post_id, '_idpg_venue_website', true );

		if ( $phone ) {
			$schema['telephone'] = $phone;
		}
		if ( $site ) {
			$schema['website'] = esc_url_raw( $site );
		}

		// --- Изображение (постер площадки) ---
		if ( class_exists( 'IDPG_Media' ) && IDPG_Media::idpg_get_poster_id( $post_id ) ) {
			$image = IDPG_Media::idpg_get_poster_url( $post_id, 'large' );
			if ( $image ) {
				$schema['image'] = $image;
			}
		}

		return $schema;
	}

	// =========================================================================
	// Open Graph / Twitter
	// =========================================================================

	/**
	 * Постер текущего события/площадки (из контекста запроса) для og:image.
	 *
	 * @return string URL или пустая строка.
	 */
	public function get_seo_image() {
		$post_id = get_the_ID();

		if ( ! $post_id || ! class_exists( 'IDPG_Media' ) ) {
			return '';
		}

		if ( ! IDPG_Media::idpg_get_poster_id( $post_id ) ) {
			return '';
		}

		return (string) IDPG_Media::idpg_get_poster_url( $post_id, 'large' );
	}

	/**
	 * Подставить постер события/площадки в og:image, если Yoast не нашёл картинку.
	 *
	 * @param string $image_url Текущий URL og:image.
	 * @param mixed  $presentation Indexable_Presentation (не используется).
	 * @return string
	 */
	public function filter_opengraph_image( $image_url, $presentation = null ) {
		if ( '' !== (string) $image_url ) {
			return $image_url;
		}

		// На фронте og генерится только для сингулярных страниц.
		if ( ! is_singular( 'event' ) && ! is_singular( 'venue' ) ) {
			return $image_url;
		}

		$poster = $this->get_seo_image();

		return $poster ? $poster : $image_url;
	}

	/**
	 * Подставить постер события/площадки в twitter:image, если Yoast не нашёл картинку.
	 *
	 * @param string $image_url Текущий URL twitter:image.
	 * @param mixed  $presentation Indexable_Presentation (не используется).
	 * @return string
	 */
	public function filter_twitter_image( $image_url, $presentation = null ) {
		if ( '' !== (string) $image_url ) {
			return $image_url;
		}

		if ( ! is_singular( 'event' ) && ! is_singular( 'venue' ) ) {
			return $image_url;
		}

		$poster = $this->get_seo_image();

		return $poster ? $poster : $image_url;
	}

	// =========================================================================
	// Крошки Yoast
	// =========================================================================

	/**
	 * Объявить поддержку крошек Yoast (активирует их в теме).
	 *
	 * @return void
	 */
	public function declare_breadcrumb_support() {
		add_theme_support( 'yoast-seo-breadcrumbs' );
	}
}