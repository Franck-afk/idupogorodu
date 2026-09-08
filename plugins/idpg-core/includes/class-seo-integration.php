<?php
/**
 * Интеграция с плагином Rank Math SEO.
 *
 * Когда Rank Math активен:
 *  - в его граф JSON-LD добавляются сущности Event (страница события)
 *    и Place (страница площадки) с гео-данными площадки (GeoCoordinates),
 *    городом события (addressLocality) и ценами (Offer);
 *  - дублирующая разметка Event в теме отключается (одна сущность на страницу);
 *  - хлебные крошки Rank Math объявляются поддерживаемыми темой.
 *
 * og:image/twitter:image Rank Math берёт из миниатюры записи — у нас
 * это и есть постер события/площадки, дополнительной подстановки не требуется.
 *
 * Модуль самодостаточен: не зависит от функций темы. Если Rank Math
 * не активен, хуки не вешаются и разметку продолжает выводить тема.
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
	const VERSION = '1.0.0';

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
	 * Конструктор: проверка Rank Math и навешивание хуков.
	 */
	private function __construct() {
		// Плагин интеграции должен быть активен.
		if ( ! $this->is_rank_math_active() ) {
			return;
		}

		// --- Schema.org (JSON-LD граф Rank Math) ---
		add_filter( 'rank_math/json_ld', array( $this, 'add_event_schema' ), 10, 2 );
		add_filter( 'rank_math/json_ld', array( $this, 'add_venue_schema' ), 11, 2 );

		// Дефолтные Article-схемы Rank Math для event/venue заменяются нашими.
		add_filter( 'rank_math/schema/default_type', array( $this, 'filter_default_type' ), 10, 3 );

		// --- Open Graph / Twitter Cards ---
		// Постер события = миниатюра записи: Rank Math подставляет её в
		// og:image/twitter:image самостоятельно. Дополнительно не добавляем —
		// иначе получался дубль тегов. get_seo_image() остаётся публичным API.

		// --- Sitemap: подключаем CPT проекта ---
		add_filter( 'rank_math/excluded_post_types', array( $this, 'excluded_post_types' ) );

		// --- Хлебные крошки: Rank Math должен знать о поддержке темой ---
		add_action( 'after_setup_theme', array( $this, 'declare_breadcrumb_support' ) );
	}

	// =========================================================================
	// Проверки окружения
	// =========================================================================

	/**
	 * Активен ли Rank Math SEO.
	 *
	 * Проверка по функции-флагу самого плагина (надёжнее is_plugin_active:
	 * работает и при загрузке классов раньше плагинов).
	 *
	 * @return bool
	 */
	public function is_rank_math_active() {
		return defined( 'RANK_MATH_VERSION' ) || function_exists( 'rank_math_the_breadcrumbs' );
	}

	// =========================================================================
	// Schema.org
	// =========================================================================

	/**
	 * Добавить сущность Event в граф Rank Math.
	 *
	 * @param array                       $data   Собранный граф.
	 * @param RankMath\Schema\JsonLD|null $jsonld Экземпляр JsonLD.
	 * @return array
	 */
	public function add_event_schema( $data, $jsonld = null ) {
		// Только страница отдельного события.
		if ( ! is_singular( 'event' ) ) {
			return $data;
		}

		$schema = $this->build_event_schema( get_the_ID() );

		if ( $schema ) {
			$data['idpg-event'] = $schema;
		}

		return $data;
	}

	/**
	 * Добавить сущность Place в граф Rank Math.
	 *
	 * @param array                       $data   Собранный граф.
	 * @param RankMath\Schema\JsonLD|null $jsonld Экземпляр JsonLD.
	 * @return array
	 */
	public function add_venue_schema( $data, $jsonld = null ) {
		// Только страница отдельной площадки.
		if ( ! is_singular( 'venue' ) ) {
			return $data;
		}

		$schema = $this->build_venue_schema( get_the_ID() );

		if ( $schema ) {
			$data['idpg-venue'] = $schema;
		}

		return $data;
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
		$status = get_post_meta( $post_id, '_idpg_ticket_status', true );
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
				'@type'        => 'PostalAddress',
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
	 * Постер текущего события/площадки для og:image.
	 *
	 * Публичный API: Rank Math подставляет миниатюру (= постер) сам,
	 * метод полезен для кастомных сценариев и тестов.
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

	// =========================================================================
	// Мелкие фильтры
	// =========================================================================

	/**
	 * Дефолтные Article-схемы Rank Math для event/venue не нужны:
	 * сущности Event/Place добавляет этот модуль.
	 *
	 * @param string $schema    Тип схемы.
	 * @param string $post_type Тип записи.
	 * @param int    $post_id   ID записи.
	 * @return string
	 */
	public function filter_default_type( $schema, $post_type, $post_id ) {
		if ( 'event' === $post_type ) {
			return '';
		}

		if ( 'venue' === $post_type ) {
			return '';
		}

		return $schema;
	}

	/**
	 * Исключить «служебные» типы записей из SEO-метабоксов Rank Math.
	 *
	 * @param array $types Исключённые типы.
	 * @return array
	 */
	public function excluded_post_types( $types ) {
		// События и площадки — SEO-значимые, остальное решение за администратором.
		return $types;
	}

	/**
	 * Объявить поддержку хлебных крошек Rank Math (активирует их в теме).
	 *
	 * @return void
	 */
	public function declare_breadcrumb_support() {
		add_theme_support( 'rank-math-breadcrumbs' );
	}
}
