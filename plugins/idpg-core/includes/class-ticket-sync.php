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
	 * Максимум попыток запроса статуса одного события.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * База паузы между попытками (бэкофф ×2), секунды.
	 *
	 * @var int
	 */
	const RETRY_DELAY = 2;

	/**
	 * Потолок паузы между попытками (бэкофф), секунды.
	 *
	 * @var int
	 */
	const RETRY_MAX_DELAY = 8;

	/**
	 * Таймаут одного запроса к API, секунды.
	 *
	 * @var int
	 */
	const REQUEST_TIMEOUT = 15;

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

		$response = $this->request_event_status( $event, $api_url );

		if ( is_wp_error( $response ) ) {
			// Диагностика уже записана в журнал (request_event_status).
			return;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			// Диагностика уже записана в журнал (request_event_status).
			return;
		}

		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$message = sprintf(
				'Ошибка парсинга JSON для события #%d: %s',
				$event->ID,
				json_last_error_msg()
			);

			$this->log( $message, 'error' );
			return;
		}

		if ( ! is_array( $data ) ) {
			$message = sprintf(
				'Ответ API для события #%d не является JSON-объектом.',
				$event->ID
			);

			$this->log( $message, 'error' );
			return;
		}

		/**
		 * Фильтрация распарсенных данных ответа API до маппинга статуса.
		 *
		 * Точка адаптации под формат реального агрегатора (DEV-02):
		 * фильтр может привести произвольный JSON к ожидаемому виду.
		 *
		 * @param array   $data  Распарсенный JSON ответа.
		 * @param WP_Post $event Объект события.
		 */
		$data = apply_filters( 'idpg_ticket_sync_response_data', $data, $event );

		if ( ! is_array( $data ) ) {
			return;
		}

		$this->process_api_response( $event, $data );
	}

	/**
	 * Запрос статуса события к внешнему API с повторными попытками.
	 *
	 * Повтор выполняем при сетевой ошибке (WP_Error), HTTP 429 и HTTP >= 500.
	 * Прочие 4xx — постоянная ошибка: повтор не поможет. Итог каждого сбоя
	 * пишется в журнал плагина (IDPG_Logger).
	 *
	 * @param WP_Post $event   Объект события.
	 * @param string  $api_url URL запроса статуса.
	 * @return array|WP_Error Ответ HTTP API или ошибка.
	 */
	private function request_event_status( $event, $api_url ) {
		$max_attempts = max( 1, (int) apply_filters( 'idpg_ticket_sync_max_attempts', self::MAX_ATTEMPTS, $event ) );
		$retry_delay  = max( 0, (int) apply_filters( 'idpg_ticket_sync_retry_delay', self::RETRY_DELAY, $event ) );
		$timeout      = max( 1, (int) apply_filters( 'idpg_ticket_sync_timeout', self::REQUEST_TIMEOUT, $event ) );

		$response = null;

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$response = wp_remote_get(
				$api_url,
				array(
					'timeout' => $timeout,
					'headers' => array(
						'Accept'      => 'application/json',
						'X-IDPG-Sync' => '1',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$retry  = true;
				$reason = $response->get_error_message();
			} else {
				$code = (int) wp_remote_retrieve_response_code( $response );

				if ( 200 === $code ) {
					return $response;
				}

				$retry  = $this->is_retryable_http_code( $code );
				$reason = sprintf( 'HTTP %d', $code );

				if ( ! $retry ) {
					// Постоянная ошибка: фиксируем и завершаем без повторов.
					$message = sprintf(
						'API вернул HTTP %d для события #%d (повтор не поможет).',
						$code,
						$event->ID
					);

					$this->log( $message, 'error' );

					return $response;
				}
			}

			if ( $attempt < $max_attempts ) {
				// Экспоненциальный бэкофф: база ×2 на каждую следующую попытку, потолок RETRY_MAX_DELAY.
				$wait = min( $retry_delay * ( 2 ** ( $attempt - 1 ) ), self::RETRY_MAX_DELAY );

				$message = sprintf(
					'Событие #%d: попытка %d/%d не удалась (%s), повтор через %d с.',
					$event->ID,
					$attempt,
					$max_attempts,
					$reason,
					$wait
				);

				$this->log( $message, 'warning' );

				if ( $wait > 0 ) {
					usleep( $wait * 1000000 );
				}
			}
		}

		// Все попытки исчерпаны — итоговый сбой запроса.
		if ( is_wp_error( $response ) ) {
			$message = sprintf(
				'Ошибка запроса API для события #%d: %s (попыток: %d).',
				$event->ID,
				$response->get_error_message(),
				$max_attempts
			);
		} else {
			$message = sprintf(
				'API вернул HTTP %d для события #%d (попыток: %d).',
				(int) wp_remote_retrieve_response_code( $response ),
				$event->ID,
				$max_attempts
			);
		}

		$this->log( $message, 'error' );

		return $response;
	}

	/**
	 * Повторяема ли HTTP-ошибка запроса.
	 *
	 * @param int $status_code Код ответа API.
	 * @return bool true — запрос имеет смысл повторить.
	 */
	private function is_retryable_http_code( $status_code ) {
		return ( 429 === $status_code || $status_code >= 500 );
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

		$url = sprintf(
			'%s/events/%s/status',
			rtrim( $base_url, '/' ),
			rawurlencode( $external_id )
		);

		/**
		 * Фильтрация URL запроса статуса события.
		 *
		 * Точка адаптации под реальный API агрегатора (DEV-02):
		 * фильтр может заменить заглушечный URL на боевой.
		 *
		 * @param string  $url         Построенный URL запроса.
		 * @param WP_Post $event       Объект события.
		 * @param string  $external_id Внешний ID события.
		 */
		$url = (string) apply_filters( 'idpg_ticket_sync_api_url', $url, $event, $external_id );

		// Защита от запросов на заглушечный (несуществующий) API: пропускаем
		// событие, только если заглушка осталась в адресе ПОСЛЕ фильтра.
		// Пока в wp-config.php не задан реальный IDPG_TICKET_API_URL,
		// cron не шлёт запросы на несуществующий адрес api.example.com.
		if ( false !== strpos( $url, 'example.com' ) ) {
			$this->log( 'IDPG_TICKET_API_URL не настроен (заглушка example.com). Синхронизация события #' . $event->ID . ' пропущена.', 'warning' );
			return '';
		}

		return $url;
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

		$default_map = array(
			'active'    => 'in_sale',
			'in_sale'   => 'in_sale',
			'sold_out'  => 'sold_out',
			'cancelled' => 'cancelled',
			'postponed' => 'postponed',
		);

		/**
		 * Фильтрация карты соответствия статусов API → мета события.
		 *
		 * Точка адаптации под реальный API агрегатора (DEV-02).
		 *
		 * @param array   $status_map Карта соответствия по умолчанию.
		 * @param WP_Post $event      Объект события.
		 */
		$status_map = apply_filters( 'idpg_ticket_sync_status_map', $default_map, $event );

		if ( ! is_array( $status_map ) ) {
			$status_map = $default_map;
		}

		if ( ! isset( $status_map[ $api_status ] ) ) {
			$message = sprintf(
				'Неизвестный статус "%s" от API для события #%d.',
				$api_status,
				$event->ID
			);

			$this->log( $message, 'warning' );
			return;
		}

		$new_status     = (string) $status_map[ $api_status ];
		$current_status = IDPG_Helpers::get_ticket_status( $event->ID );

		if ( $new_status === $current_status ) {
			return; // Статус не изменился — ничего не делаем.
		}

		update_post_meta( $event->ID, IDPG_Helpers::META_TICKET_STATUS, $new_status );

		IDPG_Meta::purge_helper_cache( $event->ID );

		$message = sprintf(
			'Событие #%d: статус изменён с "%s" на "%s".',
			$event->ID,
			$current_status,
			$new_status
		);

		$this->log( $message );

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
	 * Логирование в журнал плагина.
	 *
	 * Запрещены error_log() и запись в файлы в боевом коде (AGENTS.md, п.11):
	 * события идут в кольцевой буфер IDPG_Logger (DEV-02).
	 *
	 * @param string $message Сообщение.
	 * @param string $level   Уровень: info|warning|error.
	 */
	private function log( $message, $level = 'info' ) {
		IDPG_Logger::log( 'ticket-sync', $message, $level );
	}
}
