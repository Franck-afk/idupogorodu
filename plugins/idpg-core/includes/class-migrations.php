<?php
/**
 * Одноразовые миграции данных плагина.
 *
 * Переносит мета-поля CPT event со старого префикса `_idpg_event_`
 * на единый префикс `_idpg_` (см. IDPG_Meta) и приводит значения
 * к новым форматам.
 *
 * Миграция идемпотентна и возобновляема: каждая обработанная запись
 * помечается мета-ключом MARKER_META, поэтому повторный запуск
 * не портит данные и не начинает работу заново.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Migrations
 */
class IDPG_Migrations {

	/**
	 * Опция с версией выполненной миграции.
	 *
	 * @var string
	 */
	const OPTION_VERSION = 'idpg_meta_migration_version';

	/**
	 * Мета-ключ-маркер обработанной записи.
	 *
	 * @var string
	 */
	const MARKER_META = '_idpg_meta_migrated';

	/**
	 * Текущая версия миграций.
	 *
	 * @var int
	 */
	const VERSION = 1;

	/**
	 * Сколько записей обрабатывается за один проход.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 200;

	/**
	 * Ключ блокировки от параллельных запусков.
	 *
	 * @var string
	 */
	const LOCK = 'idpg_migration_lock';

	/**
	 * Синглтон.
	 *
	 * @var IDPG_Migrations|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Migrations
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
		add_action( 'admin_init', array( $this, 'maybe_run' ) );
	}

	/**
	 * Карта переименования мета-ключей: старый => новый.
	 *
	 * @return array
	 */
	public static function get_key_map() {
		return array(
			'_idpg_event_date_start'       => '_idpg_start_date',
			'_idpg_event_date_end'         => '_idpg_end_date',
			'_idpg_event_status'           => '_idpg_ticket_status',
			'_idpg_event_ticket_type'      => '_idpg_ticket_type',
			'_idpg_event_ticket_url'       => '_idpg_ticket_url',
			'_idpg_event_price_from'       => '_idpg_price_from',
			'_idpg_event_price_to'         => '_idpg_price_to',
			'_idpg_event_currency'         => '_idpg_currency',
			'_idpg_event_external_id'      => '_idpg_external_id',
			'_idpg_event_venue_id'         => '_idpg_venue',
			'_idpg_event_views'            => '_idpg_views',
			'_idpg_event_sessions'         => IDPG_Meta::SESSIONS_MIRROR,
			'_idpg_event_hide_from_banner' => '_idpg_hide_from_banner',
			'_idpg_event_hide_full_banner' => '_idpg_hide_full_banner',
		);
	}

	/**
	 * Запуск миграции при необходимости.
	 *
	 * Работает только для администраторов, чтобы тяжёлый проход
	 * не выполнялся на обычных admin-ajax запросах.
	 */
	public function maybe_run() {
		if ( (int) get_option( self::OPTION_VERSION, 0 ) >= self::VERSION ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_transient( self::LOCK ) ) {
			return;
		}

		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$this->run_batch();

		delete_transient( self::LOCK );
	}

	/**
	 * Обработка одной порции событий.
	 *
	 * @return int Количество обработанных записей.
	 */
	public function run_batch() {
		$ids = get_posts( array(
			'post_type'        => 'event',
			'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' ),
			'posts_per_page'   => self::BATCH_SIZE,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => self::MARKER_META,
					'compare' => 'NOT EXISTS',
				),
			),
		) );

		foreach ( $ids as $post_id ) {
			$this->migrate_post( (int) $post_id );
		}

		// Порция меньше лимита — записей больше не осталось.
		if ( count( $ids ) < self::BATCH_SIZE ) {
			update_option( self::OPTION_VERSION, self::VERSION, false );
		}

		return count( $ids );
	}

	/**
	 * Полный прогон миграции до конца (активация плагина, WP-CLI).
	 *
	 * @return int Общее количество обработанных записей.
	 */
	public static function run_all() {
		$migrations = self::instance();
		$total      = 0;

		do {
			$processed = $migrations->run_batch();
			$total    += $processed;
		} while ( $processed >= self::BATCH_SIZE );

		return $total;
	}

	/**
	 * Сброс состояния миграции для принудительного повторного прогона.
	 */
	public static function reset() {
		delete_post_meta_by_key( self::MARKER_META );
		delete_option( self::OPTION_VERSION );
	}

	/**
	 * Миграция мета-полей одной записи.
	 *
	 * @param int $post_id ID события.
	 */
	private function migrate_post( $post_id ) {
		// Старое поле даты начала хранило и время (Y-m-d\TH:i). Новое поле —
		// только дата, поэтому время сохраняем отдельно, в сеансах.
		$legacy_start = (string) get_post_meta( $post_id, '_idpg_event_date_start', true );

		foreach ( self::get_key_map() as $old_key => $new_key ) {
			if ( ! metadata_exists( 'post', $post_id, $old_key ) ) {
				continue;
			}

			$old_value = get_post_meta( $post_id, $old_key, true );

			// Новое поле не перетираем, если оно уже заполнено.
			if ( '' === (string) get_post_meta( $post_id, $new_key, true ) ) {
				$new_value = $this->convert_value( $new_key, $old_value );

				if ( '' !== (string) $new_value ) {
					update_post_meta( $post_id, $new_key, $new_value );
				}
			}

			delete_post_meta( $post_id, $old_key );
		}

		$this->seed_sessions( $post_id, $legacy_start );

		IDPG_Meta::purge_helper_cache( $post_id );

		update_post_meta( $post_id, self::MARKER_META, self::VERSION );
	}

	/**
	 * Приведение значения к формату нового поля.
	 *
	 * @param string $new_key Новый мета-ключ.
	 * @param mixed  $value   Старое значение.
	 * @return mixed Новое значение.
	 */
	private function convert_value( $new_key, $value ) {
		switch ( $new_key ) {
			case '_idpg_start_date':
			case '_idpg_end_date':
				return $this->to_date( $value );

			case '_idpg_ticket_status':
				// Старое "active" соответствует новому "in_sale".
				return 'active' === $value ? 'in_sale' : $value;

			case '_idpg_venue':
				return absint( is_array( $value ) ? reset( $value ) : $value );

			case '_idpg_views':
				return absint( $value );

			case '_idpg_hide_from_banner':
			case '_idpg_hide_full_banner':
			case '_idpg_banner_request':
			case '_idpg_banner_approved':
				// Чекбоксы Carbon Fields хранят строку "yes".
				return in_array( (string) $value, array( 'yes', '1', 'true' ), true ) ? 'yes' : '';
		}

		return $value;
	}

	/**
	 * Нормализация даты к формату Y-m-d.
	 *
	 * @param mixed $value Исходное значение (Y-m-d\TH:i, Y-m-d H:i:s и т.п.).
	 * @return string Дата или пустая строка.
	 */
	private function to_date( $value ) {
		$timestamp = strtotime( (string) $value );

		return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '';
	}

	/**
	 * Перенос сеансов в complex-поле Carbon Fields.
	 *
	 * Источники (в порядке приоритета):
	 * 1. Legacy JSON из `_idpg_event_sessions` (уже перенесён в зеркало).
	 * 2. Время из старого поля даты начала, если оно было не 00:00.
	 *
	 * @param int    $post_id      ID события.
	 * @param string $legacy_start Старое значение даты начала со временем.
	 */
	private function seed_sessions( $post_id, $legacy_start ) {
		$rows = $this->build_session_rows( $post_id, $legacy_start );

		if ( empty( $rows ) ) {
			return;
		}

		// Основной ключ и зеркало заполняем всегда — их читают шаблоны.
		$existing = get_post_meta( $post_id, '_idpg_sessions', true );

		if ( empty( $existing ) ) {
			update_post_meta( $post_id, '_idpg_sessions', $rows );
		}

		update_post_meta( $post_id, IDPG_Meta::SESSIONS_MIRROR, wp_json_encode( $rows ) );
	}

	/**
	 * Сборка списка сеансов для миграции.
	 *
	 * @param int    $post_id      ID события.
	 * @param string $legacy_start Старое значение даты начала со временем.
	 * @return array Массив сеансов в формате time/price/url.
	 */
	private function build_session_rows( $post_id, $legacy_start ) {
		$raw     = get_post_meta( $post_id, IDPG_Meta::SESSIONS_MIRROR, true );
		$decoded = array();

		if ( ! empty( $raw ) ) {
			if ( is_array( $raw ) ) {
				$decoded = $raw;
			} elseif ( is_serialized( $raw ) ) {
				$decoded = maybe_unserialize( $raw );
			} else {
				$decoded = json_decode( $raw, true );
			}
		}

		$rows = array();

		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$time = isset( $row['time'] ) ? substr( trim( (string) $row['time'] ), 0, 5 ) : '';

				if ( '' === $time ) {
					continue;
				}

				$rows[] = array(
					'time'  => $time,
					'price' => isset( $row['price'] ) ? trim( (string) $row['price'] ) : '',
					'url'   => isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '',
				);
			}
		}

		if ( ! empty( $rows ) ) {
			return $rows;
		}

		// Сеансов не было — сохраняем время из старой даты начала.
		$timestamp = $legacy_start ? strtotime( $legacy_start ) : false;

		if ( ! $timestamp ) {
			return array();
		}

		$time = gmdate( 'H:i', $timestamp );

		if ( '00:00' === $time ) {
			return array();
		}

		return array(
			array(
				'time'  => $time,
				'price' => (string) get_post_meta( $post_id, '_idpg_price_from', true ),
				'url'   => (string) get_post_meta( $post_id, '_idpg_ticket_url', true ),
			),
		);
	}

	/**
	 * WP-CLI: wp idpg migrate-meta [--force]
	 *
	 * @param array $args       Позиционные аргументы.
	 * @param array $assoc_args Именованные аргументы.
	 */
	public static function cli_migrate( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['force'] ) ) {
			self::reset();
			WP_CLI::log( 'Состояние миграции сброшено, выполняется полный прогон.' );
		}

		$total = self::run_all();

		WP_CLI::success( sprintf( 'Обработано событий: %d', $total ) );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'idpg migrate-meta', array( 'IDPG_Migrations', 'cli_migrate' ) );
}
