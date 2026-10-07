<?php
/**
 * Синхронизация статусов билетов с внешними сервисами.
 *
 * Периодически опрашивает внешний API агрегаторов билетов
 * и обновляет мета-поля статусов событий.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Ticket_Sync
 *
 * Регистрирует Cron-интервал, ставит задачи в очередь
 * и выполняет синхронизацию статусов билетов.
 */
class IDPG_Ticket_Sync {

	/**
	 * Синглтон.
	 *
	 * @var IDPG_Ticket_Sync|null
	 */
	private static $instance = null;

	/**
	 * Имя Cron-хука.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'idpg_cron_sync_tickets';

	/**
	 * Cron-интервал в секундах (3 часа).
	 *
	 * @var int
	 */
	const INTERVAL = 3 * HOUR_IN_SECONDS;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Ticket_Sync
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
		// Регистрация кастомного интервала.
		add_filter( 'cron_schedules', array( $this, 'register_cron_interval' ) );

		// Привязка исполнителя Cron-задачи.
		add_action( self::CRON_HOOK, array( $this, 'run_sync' ) );
	}

	/**
	 * Регистрация кастомного Cron-интервала.
	 *
	 * @param array $schedules Существующие расписания.
	 * @return array Обновленные расписания.
	 */
	public function register_cron_interval( $schedules ) {
		$schedules['idpg_every_3_hours'] = array(
			'interval' => self::INTERVAL,
			'display'  => __( 'Каждые 3 часа (IDPG)', 'idpg-core' ),
		);

		return $schedules;
	}

	/**
	 * Постановка Cron-задачи в расписание.
	 *
	 * Вызывается при активации плагина.
	 * Если задача уже стоит — не дублирует.
	 */
	public function schedule_sync() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'idpg_every_3_hours', self::CRON_HOOK );
		}
	}

	/**
	 * Удаление Cron-задачи из расписания.
	 *
	 * Вызывается при деактивации плагина.
	 */
	public function unschedule_sync() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Выполнение синхронизации.
	 *
	 * Получает все опубликованные события, у которых статус не `cancelled`.
	 * Для каждого выполняет запрос к внешному API и обновляет статус.
	 */
	public function run_sync() {
		$this->log( 'Синхронизация статусов билетов запущена.' );

		$this->run_sync_counting();

		$this->log( 'Синхронизация статусов билетов завершена.' );
	}

	/**
	 * Получение событий для синхронизации.
	 *
	 * Запрос: CPT `event`, статус `publish`, мета `_idpg_ticket_status` != `cancelled`.
	 *
	 * @return WP_Post[] Массив записей.
	 */
	private function get_events_for_sync() {
		$args = array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => IDPG_Helpers::META_TICKET_STATUS,
					'value'   => 'cancelled',
					'compare' => '!=',
				),
			),
		);

		return get_posts( $args );
	}

	/**
	 * Синхронизация одного события.
	 *
	 * @param WP_Post $event Объект события.
	 */
	private function sync_single_event( $event ) {
		$ticket_type = IDPG_Helpers::get_ticket_type( $event->ID );

		// Синхронизируем только события с внешними билетами.
		if ( 'external_link' !== $ticket_type ) {
			return;
		}

		$api_url = $this->build_api_url( $event );

		if ( empty( $api_url ) ) {
			return;
		}

		$response = wp_remote_get( $api_url, array(
			'timeout' => 15,
			'headers' => array(
				'Accept'       => 'application/json',
				'X-IDPG-Sync'  => '1',
			),
		) );

		if ( is_wp_error( $response ) ) {
			$this->log( sprintf(
				'Ошибка запроса API для события #%d: %s',
				$event->ID,
				$response->get_error_message()
			) );
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			$this->log( sprintf(
				'API вернул HTTP %d для события #%d.',
				$status_code,
				$event->ID
			) );
			return;
		}

		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->log( sprintf(
				'Ошибка парсинга JSON для события #%d: %s',
				$event->ID,
				json_last_error_msg()
			) );
			return;
		}

		$this->process_api_response( $event, $data );
	}

	/**
	 * Построение URL внешнего API для события.
	 *
	 * ЗАГЛУШКА: замените на реальную логику построения URL
	 * в зависимости от внешнего сервиса (Biletix, Kassir и т.д.).
	 *
	 * @param WP_Post $event Объект события.
	 * @return string URL API или пустая строка.
	 */
	private function build_api_url( $event ) {
		$external_id = get_post_meta( $event->ID, '_idpg_external_id', true );

		if ( empty( $external_id ) ) {
			return '';
		}

		$base_url = defined( 'IDPG_TICKET_API_URL' )
			? IDPG_TICKET_API_URL
			: 'https://api.example.com/v1';

		// Защита от запросов на заглушечный (несуществующий) API.
		// Пока в wp-config.php не задан реальный IDPG_TICKET_API_URL,
		// синхронизация пропускается, чтобы cron не слал запросы
		// на несуществующий адрес api.example.com.
		if ( false !== strpos( $base_url, 'example.com' ) ) {
			$this->log( 'IDPG_TICKET_API_URL не настроен (заглушка example.com). Синхронизация события #' . $event->ID . ' пропущена.' );
			return '';
		}

		return sprintf(
			'%s/events/%s/status',
			rtrim( $base_url, '/' ),
			rawurlencode( $external_id )
		);
	}

	/**
	 * Обработка ответа внешнего API и обновление мета-поля.
	 *
	 * Ожидаемый формат ответа:
	 * ```json
	 * { "status": "active|sold_out", "tickets_available": 42 }
	 * ```
	 *
	 * Статусы внешнего API приводятся к значениям мета-поля
	 * `_idpg_ticket_status` (in_sale / sold_out / cancelled / postponed).
	 *
	 * @param WP_Post $event Объект события.
	 * @param array   $data  Распарсенный JSON из ответа API.
	 */
	private function process_api_response( $event, $data ) {
		$api_status = isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : '';

		$status_map = array(
			'active'    => 'in_sale',
			'in_sale'   => 'in_sale',
			'sold_out'  => 'sold_out',
			'cancelled' => 'cancelled',
			'postponed' => 'postponed',
		);

		if ( ! isset( $status_map[ $api_status ] ) ) {
			$this->log( sprintf(
				'Неизвестный статус "%s" от API для события #%d.',
				$api_status,
				$event->ID
			) );
			return;
		}

		$new_status     = $status_map[ $api_status ];
		$current_status = IDPG_Helpers::get_ticket_status( $event->ID );

		if ( $new_status === $current_status ) {
			return; // Статус не изменился — ничего не делаем.
		}

		update_post_meta( $event->ID, IDPG_Helpers::META_TICKET_STATUS, $new_status );

		IDPG_Meta::purge_helper_cache( $event->ID );

		$this->log( sprintf(
			'Событие #%d: статус изменён с "%s" на "%s".',
			$event->ID,
			$current_status,
			$new_status
		) );

		/**
		 * Хук после изменения статуса события через синхронизацию.
		 *
		 * @param int    $event_id     ID события.
		 * @param string $old_status   Предыдущий статус.
		 * @param string $new_status   Новый статус.
		 * @param array  $api_response Полный ответ API.
		 */
		do_action( 'idpg_ticket_status_changed', $event->ID, $current_status, $new_status, $data );
	}

	/**
	 * Ручной запуск синхронизации (для админки или WP-CLI).
	 *
	 * Гарантирует, что расписание стоит, и прогоняет синхронизацию.
	 *
	 * @return int Количество обработанных событий.
	 */
	public function run_sync_now() {
		$this->schedule_sync();

		return $this->run_sync_counting();
	}

	/**
	 * Выполнение синхронизации с возвратом числа обработанных событий.
	 *
	 * Основной метод run_sync() оставлен без возвращаемого значения, чтобы
	 * не менять контракт хука cron (DEV-18): добавляем отдельный метод,
	 * который считает результат, а не правим существующий.
	 *
	 * @return int Количество обработанных событий.
	 */
	public function run_sync_counting() {
		$events = $this->get_events_for_sync();

		if ( empty( $events ) ) {
			$this->log( 'Нет событий для синхронизации.' );

			return 0;
		}

		$this->log( sprintf( 'Найдено %d событий для синхронизации.', count( $events ) ) );

		foreach ( $events as $event ) {
			$this->sync_single_event( $event );
		}

		return count( $events );
	}

	/**
	 * Логирование.
	 *
	 * Запрещено на проде (AGENTS.md, п.11): error_log() в боевом коде не используется.
	 *
	 * @param string $message Сообщение.
	 */
	private function log( $message ) {
		$timestamp = current_time( 'mysql' );
		$line      = sprintf( '[IDPG Sync %s] %s' . "\n", $timestamp, $message );

		// error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}
}
