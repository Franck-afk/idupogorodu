<?php
/**
 * Ядро импорта событий.
 *
 * Управляет источниками (репозиторий в опции), расписанием (cron),
 * прогоном импорта (пагинация → маппинг → upsert), дедупликацией,
 * присвоением термов, загрузкой постеров и статистикой.
 *
 * @package Idpg_Event_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Event_Import
 */
class IDPG_Event_Import {

	/**
	 * Синглтон.
	 *
	 * @var IDPG_Event_Import|null
	 */
	private static $instance = null;

	/**
	 * Имя cron-хука.
	 */
	const CRON_HOOK = 'idpgei_cron_import';

	/**
	 * Имя опции-репозитория источников.
	 */
	const SOURCES_OPTION = 'idpgei_sources';

	/**
	 * Лимит событий за один прогон источника.
	 */
	const RUN_LIMIT = 500;

	/**
	 * Получение синглтона.
	 *
	 * @return IDPG_Event_Import
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Конструктор: хуки.
	 */
	private function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'run_cron' ) );
	}

	// =========================================================================
	// Репозиторий источников
	// =========================================================================

	/**
	 * Все источники.
	 *
	 * @return array Массив source_id => конфиг.
	 */
	public function get_sources() {
		$sources = get_option( self::SOURCES_OPTION, array() );

		return is_array( $sources ) ? $sources : array();
	}

	/**
	 * Один источник по ID.
	 *
	 * @param string $source_id ID источника.
	 * @return array|null
	 */
	public function get_source( $source_id ) {
		$sources = $this->get_sources();

		return isset( $sources[ $source_id ] ) ? $sources[ $source_id ] : null;
	}

	/**
	 * Сохранить источник (upsert) и перепланировать cron.
	 *
	 * @param array $source Конфиг (source_id обязателен).
	 * @return bool
	 */
	public function save_source( $source ) {
		if ( ! is_array( $source ) || empty( $source['source_id'] ) ) {
			return false;
		}

		$source = $this->sanitize_source( $source );

		if ( '' === $source['source_id'] ) {
			return false;
		}

		$sources = $this->get_sources();

		$sources[ $source['source_id'] ] = $source;

		update_option( self::SOURCES_OPTION, $sources, false );

		$this->reschedule();

		return true;
	}

	/**
	 * Удалить источник (посты не трогаем — только связь).
	 *
	 * @param string $source_id ID источника.
	 * @return bool
	 */
	public function delete_source( $source_id ) {
		$sources = $this->get_sources();
		$source_id = sanitize_key( (string) $source_id );

		if ( ! isset( $sources[ $source_id ] ) ) {
			return false;
		}

		unset( $sources[ $source_id ] );

		update_option( self::SOURCES_OPTION, $sources, false );

		$this->reschedule();

		return true;
	}

	/**
	 * Санитизация конфига источника.
	 *
	 * @param array $source Сырой конфиг.
	 * @return array
	 */
	public function sanitize_source( $source ) {
		$defaults = array(
			'source_id'       => '',
			'name'            => '',
			'provider'        => 'rest',
			'endpoint'        => '',
			'api_key'         => '',
			'list_path'       => 'data',
			'page_param'      => 'page',
			'field_map'       => array(),
			'defaults'        => array(),
			'city_term'       => 0,
			'organizer_id'    => 0,
			'post_status'     => 'draft',
			'schedule'        => 21600, // 6 часов.
			'overwrite'       => false,
			'auto_publish'    => false,
			'create_missing_venues' => false,
		);

		$out = array();

		$out['source_id'] = isset( $source['source_id'] ) ? sanitize_key( (string) $source['source_id'] ) : '';
		$out['name'] = isset( $source['name'] ) ? sanitize_text_field( (string) $source['name'] ) : '';
		$out['provider'] = isset( $source['provider'] ) ? sanitize_key( (string) $source['provider'] ) : 'rest';
		$out['endpoint'] = isset( $source['endpoint'] ) ? esc_url_raw( (string) $source['endpoint'], array( 'http', 'https' ) ) : '';
		// Ключ не санитизируется жёстко (токены бывают с символами), но хранится как есть.
		$out['api_key'] = isset( $source['api_key'] ) ? (string) $source['api_key'] : '';
		$out['list_path'] = isset( $source['list_path'] ) ? sanitize_text_field( (string) $source['list_path'] ) : 'data';
		$out['page_param'] = isset( $source['page_param'] ) ? sanitize_key( (string) $source['page_param'] ) : 'page';
		$out['field_map'] = isset( $source['field_map'] ) && is_array( $source['field_map'] )
			? array_map( 'sanitize_text_field', $source['field_map'] )
			: array();
		$out['defaults'] = isset( $source['defaults'] ) && is_array( $source['defaults'] )
			? array_map( 'sanitize_text_field', $source['defaults'] )
			: array();

		$out['city_term'] = isset( $source['city_term'] ) ? absint( $source['city_term'] ) : 0;
		$out['organizer_id'] = isset( $source['organizer_id'] ) ? absint( $source['organizer_id'] ) : 0;
		$out['post_status'] = in_array( isset( $source['post_status'] ) ? $source['post_status'] : '', array( 'publish', 'draft', 'pending' ), true )
			? $source['post_status']
			: 'draft';

		// Расписание: 15 минут .. 7 дней.
		$schedule = isset( $source['schedule'] ) ? (int) $source['schedule'] : 21600;
		$out['schedule'] = max( 900, min( WEEK_IN_SECONDS, $schedule ) );

		$out['overwrite'] = ! empty( $source['overwrite'] );
		$out['auto_publish'] = ! empty( $source['auto_publish'] );
		$out['create_missing_venues'] = ! empty( $source['create_missing_venues'] );

		return array_merge( $defaults, $out );
	}

	// =========================================================================
	// Cron
	// =========================================================================

	/**
	 * Перепланирование единого cron-хука (вызывается при CRUD источников).
	 *
	 * Хук «раз в час» решает сам, какому источнику пора, по last_run + schedule.
	 *
	 * @return void
	 */
	public function reschedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Прогон cron: обрабатывает источники, у которых наступило время.
	 *
	 * @return void
	 */
	public function run_cron() {
		foreach ( $this->get_sources() as $source ) {
			$last_run = isset( $source['last_run'] ) ? (int) $source['last_run'] : 0;
			$due      = $last_run + (int) $source['schedule'];

			if ( time() < $due ) {
				continue; // Ещё не время.
			}

			$this->process_source( $source['source_id'] );
		}
	}

	// =========================================================================
	// Прогон импорта
	// =========================================================================

	/**
	 * Импорт одного источника.
	 *
	 * @param string $source_id ID источника.
	 * @return array|WP_Error Статистика или ошибка.
	 */
	public function process_source( $source_id ) {
		$source = $this->get_source( $source_id );

		if ( ! $source ) {
			return new WP_Error( 'idpgei_unknown_source', __( 'Источник не найден.', 'idpg-event-import' ) );
		}

		$provider = $this->get_provider( $source );

		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$stats = array(
			'created'  => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => 0,
		);

		$total = 0;
		$ext_ids = array();

		for ( $page = 1; $page <= 50; $page++ ) {
			$items = $provider->fetch_page( $page );

			if ( is_wp_error( $items ) ) {
				// Первая страница упала — фиксируем ошибку источника.
				if ( 1 === $page ) {
					$this->finish_source_run( $source, $stats, $items->get_error_message() );
					return $items;
				}
				break; // Ошибка пагинации — работаем с тем, что успели.
			}

			if ( empty( $items ) ) {
				break; // Страницы кончились.
			}

			foreach ( $items as $raw ) {
				if ( $total >= self::RUN_LIMIT ) {
					break 2; // Лимит прогона.
				}

				$total++;

				$mapped = IDPG_Import_Mapper::map_event( $raw, $source );

				if ( null === $mapped ) {
					$stats['skipped']++;
					continue;
				}

				$ext_ids[] = $mapped['external_id'];

				$result = $this->upsert_event( $mapped, $source );

				if ( is_wp_error( $result ) ) {
					$stats['errors']++;
				} elseif ( 'updated' === $result ) {
					$stats['updated']++;
				} else {
					$stats['created']++;
				}
			}
		}

		$this->finish_source_run( $source, $stats, '' );

		/**
		 * Хук после завершения импорта источника.
		 *
		 * @param string $source_id ID источника.
		 * @param array  $stats     Статистика (created/updated/skipped/errors).
		 */
		do_action( 'idpgei_import_completed', $source_id, $stats );

		return $stats;
	}

	/**
	 * Провайдер по типу источника.
	 *
	 * @param array $source Конфиг источника.
	 * @return IDPG_Import_Provider|WP_Error
	 */
	public function get_provider( $source ) {
		$provider_class = null;

		switch ( isset( $source['provider'] ) ? $source['provider'] : '' ) {
			case 'rest':
				$provider_class = 'IDPG_Provider_REST';
				break;
			case 'ical':
				$provider_class = 'IDPG_Provider_iCal';
				break;
		}

		/**
		 * Кастомные провайдеры: фильтр возвращает имя класса.
		 *
		 * @param string|null $provider_class Класс провайдера.
		 * @param array       $source        Конфиг источника.
		 */
		$provider_class = apply_filters( 'idpgei_provider_class', $provider_class, $source );

		if ( ! class_exists( $provider_class ) ) {
			return new WP_Error( 'idpgei_unknown_provider', __( 'Неизвестный тип провайдера.', 'idpg-event-import' ) );
		}

		return new $provider_class( $source );
	}

	// =========================================================================
	// Upsert
	// =========================================================================

	/**
	 * Найти существующее событие по паре источник + внешний ID.
	 *
	 * @param string $source_id  ID источника.
	 * @param string $external_id Внешний ID.
	 * @return int ID поста или 0.
	 */
	public function find_existing( $source_id, $external_id ) {
		$found = get_posts( array(
			'post_type'      => 'event',
			'post_status'    => get_post_stati(), // Любой статус, включая корзину.
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'   => '_idpg_source',
					'value' => sanitize_key( (string) $source_id ),
				),
				array(
					'key'   => '_idpg_external_id',
					'value' => sanitize_text_field( (string) $external_id ),
				),
			),
		) );

		return ! empty( $found ) ? (int) $found[0] : 0;
	}

	/**
	 * Создать или обновить событие.
	 *
	 * @param array $mapped Нормализованные данные маппера.
	 * @param array $source Конфиг источника.
	 * @return string|WP_Error 'created'|'updated' или ошибка.
	 */
	public function upsert_event( $mapped, $source ) {
		$existing_id = $this->find_existing( $source['source_id'], $mapped['external_id'] );

		$postarr = array(
			'post_type'   => 'event',
			'post_status' => $source['post_status'],
			'post_author' => $source['organizer_id'] ? (int) $source['organizer_id'] : get_current_user_id(),
			'meta_input' => array(
				'_idpg_source'       => $source['source_id'],
				'_idpg_external_id'  => $mapped['external_id'],
				'_idpg_start_date'   => $mapped['starts_at'],
				'_idpg_ticket_url'   => $mapped['buy_url'],
				'_idpg_ticket_status' => $mapped['status'] ? $mapped['status'] : 'in_sale',
			),
		);

		if ( '' !== $mapped['ends_at'] ) {
			$postarr['meta_input']['_idpg_end_date'] = $mapped['ends_at'];
		}

		if ( null !== $mapped['price_min'] ) {
			$postarr['meta_input']['_idpg_price_from'] = (string) $mapped['price_min'];
		}

		if ( null !== $mapped['price_max'] ) {
			$postarr['meta_input']['_idpg_price_to'] = (string) $mapped['price_max'];
		}

		if ( 'yes' === $mapped['is_free'] ) {
			$postarr['meta_input']['_idpg_is_free'] = 'yes';
		}

		if ( '' !== $mapped['currency'] ) {
			$postarr['meta_input']['_idpg_currency'] = $mapped['currency'];
		}

		if ( '' !== $mapped['venue_address'] ) {
			$postarr['meta_input']['_idpg_venue_address'] = $mapped['venue_address'];
		}

		// Площадка: поиск по названию среди venue.
		$venue_id = 0;

		if ( '' !== $mapped['venue_name'] ) {
			$venue_id = $this->find_venue_by_name( $mapped['venue_name'] );

			if ( ! $venue_id && ! empty( $source['create_missing_venues'] ) ) {
				$venue_id = $this->create_venue( $mapped, $source );
			}
		}

		if ( $venue_id ) {
			$postarr['meta_input']['_idpg_venue'] = $venue_id;
		}

		if ( $existing_id ) {
			// Обновление: заголовок и контент — только при политике overwrite.
			$postarr['ID'] = $existing_id;

			// Статус публикации: если уже опубликовано — не переводим обратно в draft.
			$existing_status = get_post_status( $existing_id );

			if ( 'publish' === $existing_status ) {
				$postarr['post_status'] = 'publish';
			}

			if ( ! empty( $source['overwrite'] ) ) {
				$postarr['post_title']   = $mapped['title'];
				$postarr['post_content'] = $mapped['description'];
			}

			$post_id = wp_update_post( $postarr, true );
		} else {
			$postarr['post_title']   = $mapped['title'];
			$postarr['post_content'] = $mapped['description'];

			$post_id = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// --- Термы ---
		$this->assign_terms( $post_id, $mapped, $source );

		// --- Постер ---
		if ( '' !== $mapped['image'] ) {
			$this->sideload_poster( $post_id, $mapped['image'] );
		}

		return $existing_id ? 'updated' : 'created';
	}

	/**
	 * Присвоить термы (город, категория).
	 *
	 * @param int   $post_id ID события.
	 * @param array $mapped  Данные маппера.
	 * @param array $source Конфиг источника.
	 * @return void
	 */
	protected function assign_terms( $post_id, $mapped, $source ) {
		// Город: терм источника имеет приоритет, затем город из API.
		$city_id = 0;

		if ( ! empty( $source['city_term'] ) && term_exists( (int) $source['city_term'], 'city' ) ) {
			$city_id = (int) $source['city_term'];
		} elseif ( '' !== $mapped['city'] ) {
			$term = get_term_by( 'name', $mapped['city'], 'city' );

			if ( $term ) {
				$city_id = (int) $term->term_id;
			}
		}

		if ( $city_id ) {
			wp_set_object_terms( $post_id, array( $city_id ), 'city', false );
		}

		// Категория: только если терм существует (не создаём мусор).
		if ( '' !== $mapped['category'] ) {
			$cat = get_term_by( 'name', $mapped['category'], 'event_category' );

			if ( $cat ) {
				wp_set_object_terms( $post_id, array( (int) $cat->term_id ), 'event_category', false );
			}
		}
	}

	/**
	 * Поиск площадки по точному названию.
	 *
	 * @param string $name Название.
	 * @return int ID площадки или 0.
	 */
	public function find_venue_by_name( $name ) {
		$found = get_posts( array(
			'post_type'      => 'venue',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'title'          => $name,
			'fields'         => 'ids',
		) );

		return ! empty( $found ) ? (int) $found[0] : 0;
	}

	/**
	 * Создать площадку по данным события.
	 *
	 * @param array $mapped Данные маппера.
	 * @param array $source Конфиг источника.
	 * @return int ID новой площадки или 0.
	 */
	protected function create_venue( $mapped, $source ) {
		$venue_id = wp_insert_post( array(
			'post_type'   => 'venue',
			'post_status' => 'publish',
			'post_title'  => $mapped['venue_name'],
			'post_author' => $source['organizer_id'] ? (int) $source['organizer_id'] : get_current_user_id(),
			'meta_input'  => array(
				'_idpg_venue_address' => $mapped['venue_address'],
			),
		), true );

		if ( is_wp_error( $venue_id ) || ! $venue_id ) {
			return 0;
		}

		// Город площадке тоже.
		if ( ! empty( $source['city_term'] ) && term_exists( (int) $source['city_term'], 'city' ) ) {
			wp_set_object_terms( $venue_id, array( (int) $source['city_term'] ), 'city', false );
		}

		return (int) $venue_id;
	}

	/**
	 * Загрузка постера во вложения и установка как миниатюра.
	 *
	 * @param int    $post_id ID события.
	 * @param string $image_url URL изображения.
	 * @return bool|int ID вложения или false.
	 */
	public function sideload_poster( $post_id, $image_url ) {
		$post_id  = absint( $post_id );
		$image_url = esc_url_raw( (string) $image_url, array( 'http', 'https' ) );

		if ( ! $post_id || '' === $image_url ) {
			return false;
		}

		// Уже есть постер — не перезаписываем (политика контента).
		if ( has_post_thumbnail( $post_id ) ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $image_url, 15 );

		if ( is_wp_error( $tmp ) ) {
			return false;
		}

		// Имя файла без расширения (или пустое) — подставляем безопасный дефолт,
		// иначе media_handle_sideload отклонит файл.
		$file_array = array(
			'name'     => basename( (string) parse_url( $image_url, PHP_URL_PATH ) ),
			'tmp_name' => $tmp,
		);

		if ( '' === $file_array['name'] || false === strpos( $file_array['name'], '.' ) ) {
			$file_array['name'] = 'import-' . $post_id . '.jpg';
		}

		$id = media_handle_sideload( $file_array, $post_id );

		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return false;
		}

		set_post_thumbnail( $post_id, $id );

		return (int) $id;
	}

	// =========================================================================
	// Статистика
	// =========================================================================

	/**
	 * Зафиксировать результат прогона источника.
	 *
	 * @param array  $source  Конфиг источника.
	 * @param array  $stats   Статистика.
	 * @param string $error   Текст ошибки (пусто = успех).
	 * @return void
	 */
	protected function finish_source_run( $source, $stats, $error ) {
		$sources = $this->get_sources();
		$sid     = $source['source_id'];

		if ( ! isset( $sources[ $sid ] ) ) {
			return;
		}

		$sources[ $sid ]['last_run']    = time();
		$sources[ $sid ]['last_status'] = '' === $error ? 'ok' : 'error';
		$sources[ $sid ]['last_error']  = $error;
		$sources[ $sid ]['stats']       = $stats;

		update_option( self::SOURCES_OPTION, $sources, false );
	}
}
