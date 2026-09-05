<?php
/**
 * Класс-загрузчик WP Cloud Sync (удалённая часть).
 *
 * Работает на удалённом сайте: по крону (раз в 5 минут) проверяет
 * Яндекс.Диск, скачивает новые файлы из папки синхронизации на сервер
 * и удаляет их из облака. После 3 неудачных попыток файл пропускается.
 *
 * @package WPCloudSync
 */

namespace WPCloudSync;

/**
 * Класс WP_Cloud_Sync_Downloader.
 */
class WP_Cloud_Sync_Downloader {

	/**
	 * Событие крона.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wp_cloud_sync_download_cron';

	/**
	 * Интервал крона (каждые 5 минут).
	 *
	 * @var string
	 */
	const CRON_INTERVAL = 'wp_cloud_sync_every_5_minutes';

	/**
	 * Опция с количеством неудачных попыток по файлам.
	 *
	 * @var string
	 */
	const OPTION_RETRIES = 'wp_cloud_sync_download_retries';

	/**
	 * Транзиент-блокировка от параллельного запуска.
	 *
	 * @var string
	 */
	const LOCK_TRANSIENT = 'wp_cloud_sync_dl_lock';

	/**
	 * Лог-файл загрузчика.
	 *
	 * @var string
	 */
	const LOG_FILE = 'debug-cloud-sync.log';

	/**
	 * Максимум попыток на файл.
	 *
	 * @var int
	 */
	const MAX_RETRIES = 3;

	/**
	 * Сколько файлов обрабатывать за один запуск крона.
	 *
	 * @var int
	 */
	const MAX_FILES_PER_RUN = 20;

	/**
	 * Через сколько секунд блокировку считать «зависшей» и сбросить.
	 *
	 * @var int
	 */
	const LOCK_TTL_STALE = 30;

	/**
	 * Опция состояния ручного run-запуска (прогресс скачивания).
	 *
	 * @var string
	 */
	const OPTION_RUN_STATE = 'wp_cloud_sync_dl_run_state';

	/**
	 * Максимальный размер скачиваемого файла (1 ГБ).
	 *
	 * @var int
	 */
	const MAX_FILE_SIZE = 1073741824;

	/**
	 * Базовый URL API Яндекс.Диска.
	 *
	 * @var string
	 */
	const DISK_API_URL = 'https://cloud-api.yandex.net/v1/disk';

	/**
	 * Синглтон.
	 *
	 * @var WP_Cloud_Sync_Downloader|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра.
	 *
	 * @return WP_Cloud_Sync_Downloader
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор: вешаем хуки.
	 */
	private function __construct() {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( $this, 'cron_run' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
		add_action( 'wp_ajax_wp_cloud_sync_download', array( $this, 'ajax_download' ) );
		add_action( 'wp_ajax_wp_cloud_sync_run_download', array( $this, 'ajax_run_download' ) );
		add_action( 'wp_ajax_wp_cloud_sync_db_import', array( $this, 'handle_db_import' ) );
	}

	/**
	 * Регистрация интервала «каждые 5 минут».
	 *
	 * @param array $schedules Существующие расписания.
	 * @return array
	 */
	public function cron_schedules( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
			$schedules[ self::CRON_INTERVAL ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'WP Cloud Sync: каждые 5 минут', 'wp-cloud-sync' ),
			);
		}
		return $schedules;
	}

	/**
	 * Планирование крона, если включён режим загрузчика и автозагрузка.
	 */
	public function maybe_schedule() {
		$settings = $this->get_settings();
		if ( ! empty( $settings['downloader_mode'] ) && ! empty( $settings['downloader_cron'] ) ) {
			$this->schedule_cron();
		} else {
			$this->clear_cron();
		}
	}

	/**
	 * Крон-вызов: скачивание только при включённой автозагрузке.
	 */
	public function cron_run() {
		$settings = $this->get_settings();
		if ( ! empty( $settings['downloader_cron'] ) ) {
			$this->run_download();
		}
	}

	/**
	 * Запланировать событие крона (идемпотентно).
	 */
	public function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	/**
	 * Снять событие крона.
	 */
	public function clear_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Текущие настройки (общие с основным классом).
	 *
	 * @return array
	 */
	private function get_settings() {
		$saved = get_option( 'wp_cloud_sync_settings', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, array(
			'yandex_access_token' => '',
			'remote_path'         => '',
			'downloader_mode'     => 0,
			'downloader_cron'     => 0,
			'excluded_dirs'       => '',
			'local_root'          => '',
			'custom_theme'        => '',
			'custom_plugins'      => '',
		) );
	}

	/**
	 * Корень сайта для сохранения скачанных файлов (local_root или ABSPATH).
	 *
	 * @return string
	 */
	private function local_root() {
		$path = trim( (string) $this->get_settings()['local_root'] );
		if ( '' === $path ) {
			$path = ABSPATH;
		}
		return trailingslashit( wp_normalize_path( $path ) );
	}

	/**
	 * Список исключённых папок (дефолтные + из настроек).
	 *
	 * @return array
	 */
	private function get_excluded_dirs() {
		$list = array( 'wp-content/cache', 'wp-content/upgrade', '.git', 'node_modules' );
		$raw  = (string) $this->get_settings()['excluded_dirs'];

		if ( '' !== $raw ) {
			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
				$line = trim( $line, " \t/" );
				if ( '' !== $line ) {
					$list[] = wp_normalize_path( $line );
				}
			}
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * Проверка, что относительный путь попадает в исключённую папку.
	 *
	 * @param string $rel Относительный путь.
	 * @return bool
	 */
	private function is_excluded( $rel ) {
		foreach ( $this->get_excluded_dirs() as $ex ) {
			if ( $rel === $ex || 0 === strpos( $rel, $ex . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Разрешён ли путь для скачивания (только настроенные тема и плагины).
	 *
	 * Если поле custom_theme пусто — папка тем не скачивается вовсе,
	 * аналогично custom_plugins для папки плагинов.
	 *
	 * @param string $rel Относительный путь.
	 * @return bool
	 */
	private function is_allowed_rel( $rel ) {
		$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );

		foreach ( $this->get_allowed_prefixes() as $prefix ) {
			if ( $rel === $prefix || 0 === strpos( $rel, $prefix . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Список разрешённых относительных префиксов (из настроек).
	 *
	 * @return array
	 */
	private function get_allowed_prefixes() {
		$settings = $this->get_settings();
		$prefixes = array();

		$theme = trim( (string) $settings['custom_theme'] );
		if ( '' !== $theme ) {
			$prefixes[] = 'wp-content/themes/' . sanitize_file_name( $theme );
		}

		$plugins = trim( (string) $settings['custom_plugins'] );
		if ( '' !== $plugins ) {
			foreach ( explode( ',', $plugins ) as $plugin ) {
				$plugin = trim( $plugin, " \t/" );
				if ( '' !== $plugin ) {
					$prefixes[] = 'wp-content/plugins/' . sanitize_file_name( $plugin );
				}
			}
		}

		return $prefixes;
	}

	/**
	 * Нужно ли заходить в папку при обходе облака:
	 * папка лежит на пути к разрешённому префиксу, совпадает с ним
	 * или находится внутри него.
	 *
	 * @param string $dir_rel Относительный путь папки (от корня синхронизации).
	 * @return bool
	 */
	private function dir_has_allowed_prefix( $dir_rel ) {
		foreach ( $this->get_allowed_prefixes() as $prefix ) {
			if ( $dir_rel === $prefix || 0 === strpos( $prefix, $dir_rel . '/' ) || 0 === strpos( $dir_rel, $prefix . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Корень синхронизации на Диске (например /wp-cloud-sync).
	 *
	 * @return string
	 */
	private function remote_root() {
		$path = trim( (string) $this->get_settings()['remote_path'] );
		if ( '' === $path ) {
			$path = '/wp-cloud-sync';
		}
		return '/' . trim( $path, '/' );
	}

	/**
	 * Запись строки в лог-файл.
	 *
	 * @param string $message Сообщение.
	 * @param string $level   Уровень (info/error).
	 */
	private function log( $message, $level = 'info' ) {
		$line = sprintf( '[%s] [%s] %s', current_time( 'mysql' ), strtoupper( $level ), $message ) . PHP_EOL;
		$file = trailingslashit( WP_CONTENT_DIR ) . self::LOG_FILE;
		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Последние строки лога загрузчика.
	 *
	 * @param int $lines Сколько строк.
	 * @return string
	 */
	public function get_log_tail( $lines = 20 ) {
		$file = trailingslashit( WP_CONTENT_DIR ) . self::LOG_FILE;
		if ( ! is_file( $file ) ) {
			return '';
		}
		$data = @file_get_contents( $file );
		if ( false === $data ) {
			return '';
		}
		$parts = array_filter( explode( PHP_EOL, $data ), 'strlen' );
		return implode( PHP_EOL, array_slice( $parts, -$lines ) );
	}

	/**
	 * Универсальный HTTP-запрос (GET/PUT/DELETE, бинарное тело).
	 *
	 * @param string $method HTTP-метод.
	 * @param string $url    URL.
	 * @param array  $opts   Доп. опции: headers.
	 * @return array{ code: int, body: string }
	 */
	private function http_request( $method, $url, $opts = array() ) {
		$ch = curl_init();
		curl_setopt_array( $ch, array(
			CURLOPT_URL            => $url,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION  => true,
			CURLOPT_TIMEOUT        => 120,
		) );

		$headers = isset( $opts['headers'] ) ? $opts['headers'] : array();
		if ( $headers ) {
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		}

		$raw     = curl_exec( $ch );
		$code    = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curl_er = curl_error( $ch );
		curl_close( $ch );

		if ( false === $raw ) {
			return array( 'code' => 0, 'body' => $curl_er );
		}

		return array( 'code' => $code, 'body' => $raw );
	}

	/**
	 * Ссылка на скачивание файла (временная, с истечением).
	 *
	 * @param string $remote Удалённый путь.
	 * @param string $token  OAuth-токен.
	 * @return string|false
	 */
	private function get_download_href( $remote, $token ) {
		$url = add_query_arg( 'path', $remote, self::DISK_API_URL . '/resources/download' );
		$resp = $this->http_request( 'GET', $url, array( 'headers' => array( 'Authorization: OAuth ' . $token ) ) );

		if ( 200 !== $resp['code'] ) {
			$this->log( sprintf( __( 'Не удалось получить ссылку скачивания %s (HTTP %d).', 'wp-cloud-sync' ), $remote, $resp['code'] ), 'error' );
			return false;
		}

		$decoded = json_decode( $resp['body'], true );
		return ( is_array( $decoded ) && ! empty( $decoded['href'] ) ) ? $decoded['href'] : false;
	}

	/**
	 * Рекурсивный обход папки на Диске.
	 *
	 * @param string $path    Удалённый путь (папка).
	 * @param string $token   OAuth-токен.
	 * @param array  $result  Накапливаемый список файлов.
	 * @return bool
	 */
	private function list_remote_files( $path, $token, &$result ) {
		$url  = add_query_arg( array( 'path' => $path, 'limit' => 1000 ), self::DISK_API_URL . '/resources' );
		$resp = $this->http_request( 'GET', $url, array( 'headers' => array( 'Authorization: OAuth ' . $token ) ) );

		if ( 200 !== $resp['code'] ) {
			if ( 404 === $resp['code'] ) {
				$this->log(
					sprintf(
						__( 'Папки %s нет на Яндекс.Диске. Проверьте настройку «Remote Path» (сейчас: %s).', 'wp-cloud-sync' ),
						$path,
						$this->remote_root()
					),
					'error'
				);
			} else {
				$this->log( sprintf( __( 'Ошибка списка %s (HTTP %d).', 'wp-cloud-sync' ), $path, $resp['code'] ), 'error' );
			}
			return false;
		}

		$decoded = json_decode( $resp['body'], true );
		if ( ! is_array( $decoded ) || empty( $decoded['_embedded']['items'] ) ) {
			return true;
		}

		foreach ( $decoded['_embedded']['items'] as $item ) {
			$type = isset( $item['type'] ) ? $item['type'] : 'file';
			$name = isset( $item['name'] ) ? $item['name'] : '';

			if ( 'dir' === $type ) {
				$dir_remote = $path . '/' . $name;
				$dir_rel    = $this->local_rel_for( $dir_remote );
				if ( $this->dir_has_allowed_prefix( $dir_rel ) ) {
					$this->list_remote_files( $dir_remote, $token, $result );
				}
				continue;
			}

			if ( 'file' === $type && '' !== $name ) {
				$result[] = array(
					'remote' => $path . '/' . $name,
					'name'   => $name,
				);
			}
		}

		return true;
	}

	/**
	 * Относительный путь файла (от корня синхронизации на Диске).
	 *
	 * @param string $remote Удалённый путь.
	 * @return string
	 */
	private function local_rel_for( $remote ) {
		$root = $this->remote_root();

		if ( 0 === strpos( $remote, $root . '/' ) ) {
			return substr( $remote, strlen( $root ) + 1 );
		}

		return ltrim( $remote, '/' );
	}

	/**
	 * Локальный путь для удалённого файла (с защитой от path traversal).
	 *
	 * @param string $remote Удалённый путь.
	 * @return string
	 */
	private function local_path_for( $remote ) {
		$rel = $this->local_rel_for( $remote );

		$clean = array();
		foreach ( explode( '/', str_replace( '\\', '/', $rel ) ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				continue;
			}
			$clean[] = $part;
		}

		return trailingslashit( $this->local_root() ) . implode( '/', $clean );
	}

	/**
	 * Скачивание файла целиком во временный файл и переименование.
	 *
	 * @param string $remote Удалённый путь.
	 * @param string $local  Локальный путь.
	 * @param string $token  OAuth-токен.
	 * @return bool
	 */
	private function download_file( $remote, $local, $token ) {
		$href = $this->get_download_href( $remote, $token );
		if ( ! $href ) {
			return false;
		}

		$dir = dirname( $local );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			$this->log( sprintf( __( 'Не удалось создать папку %s.', 'wp-cloud-sync' ), $dir ), 'error' );
			return false;
		}

		if ( ! is_writable( $dir ) ) {
			$this->log( sprintf( __( 'Нет прав на запись в папку %s.', 'wp-cloud-sync' ), $dir ), 'error' );
			return false;
		}

		$resp = $this->http_request( 'GET', $href, array() );
		if ( 200 !== $resp['code'] ) {
			$this->log( sprintf( __( 'HTTP %d при скачивании %s.', 'wp-cloud-sync' ), $resp['code'], $remote ), 'error' );
			return false;
		}

		$tmp = $local . '.part';
		if ( false === @file_put_contents( $tmp, $resp['body'] ) ) {
			@unlink( $tmp );
			$this->log( sprintf( __( 'Не удалось записать %s.', 'wp-cloud-sync' ), $tmp ), 'error' );
			return false;
		}

		if ( ! @rename( $tmp, $local ) ) {
			@unlink( $tmp );
			$this->log( sprintf( __( 'Не удалось сохранить %s.', 'wp-cloud-sync' ), $local ), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * Импорт БД из облака (action: wp_ajax_wp_cloud_sync_db_import).
	 */
	public function handle_db_import() {
		check_ajax_referer( 'wp_cloud_sync_db', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'wp-cloud-sync' ) ) );
		}

		$settings = $this->get_settings();
		if ( empty( $settings['yandex_access_token'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Нет токена доступа. Сначала получите токен.', 'wp-cloud-sync' ) ) );
		}

		$name = isset( $_REQUEST['name'] ) ? sanitize_file_name( wp_unslash( $_REQUEST['name'] ) ) : '';
		if ( '' !== $name && 1 !== preg_match( '/^db_dump_\d{8}_\d{6}\.sql$/', $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Некорректное имя дампа.', 'wp-cloud-sync' ) ) );
		}

		$result = $this->db_import( $settings['yandex_access_token'], $name );
		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result );
	}

	/**
	 * Скачивание дампа БД из облака и применение к текущей базе.
	 *
	 * @param string $token OAuth-токен.
	 * @param string $name  Имя дампа (пусто — свежайший).
	 * @return array
	 */
	public function db_import( $token, $name = '' ) {
		@set_time_limit( 0 );

		$dumps = wp_cloud_sync()->list_db_dumps( $token );

		if ( '' === $name ) {
			if ( empty( $dumps ) ) {
				$this->log( '[DB] ' . __( 'Дампы не найдены в облаке.', 'wp-cloud-sync' ), 'error' );
				return array(
					'success' => false,
					'message' => __( 'В облаке нет дампов (db_dump_*.sql).', 'wp-cloud-sync' ),
					'log'     => $this->get_log_tail( 30 ),
				);
			}
			$name = key( $dumps );
		} elseif ( ! isset( $dumps[ $name ] ) ) {
			$this->log( '[DB] ' . sprintf( __( 'Дамп %s не найден в облаке.', 'wp-cloud-sync' ), $name ), 'error' );
			return array(
				'success' => false,
				'message' => sprintf( __( 'Дамп %s не найден в облаке.', 'wp-cloud-sync' ), $name ),
				'log'     => $this->get_log_tail( 30 ),
			);
		}

		$remote = $this->remote_root() . '/' . $name;
		$this->log( '[DB] ' . sprintf( __( 'Импорт: применяем дамп %s.', 'wp-cloud-sync' ), $remote ) );

		$href = $this->get_download_href( $remote, $token );
		if ( ! $href ) {
			return array(
				'success' => false,
				'message' => __( 'Не удалось получить ссылку на скачивание дампа.', 'wp-cloud-sync' ),
				'log'     => $this->get_log_tail( 30 ),
			);
		}

		$tmp = get_temp_dir() . 'wpcs-db-' . wp_generate_password( 8, false ) . '.sql';
		if ( ! $this->download_to_file( $href, $tmp ) ) {
			@unlink( $tmp );
			$this->log( '[DB] ' . __( 'Не удалось скачать дамп из облака.', 'wp-cloud-sync' ), 'error' );
			return array(
				'success' => false,
				'message' => __( 'Не удалось скачать дамп из облака.', 'wp-cloud-sync' ),
				'log'     => $this->get_log_tail( 30 ),
			);
		}

		$this->log( '[DB] ' . sprintf( __( 'Дамп скачан (%d КБ), применяем к базе...', 'wp-cloud-sync' ), (int) round( (int) @filesize( $tmp ) / 1024 ) ) );

		$applied = wp_cloud_sync()->apply_db_dump( $tmp );
		@unlink( $tmp );

		if ( ! empty( $applied['success'] ) ) {
			$this->log( '[DB] ' . sprintf( __( 'Импорт завершён: %s.', 'wp-cloud-sync' ), $applied['message'] ) );
			return array(
				'success' => true,
				'message' => __( 'База данных импортирована — ' , 'wp-cloud-sync' ) . $applied['message'],
				'log'     => $this->get_log_tail( 30 ),
			);
		}

		$this->log( '[DB] ' . $applied['message'], 'error' );
		return array(
			'success' => false,
			'message' => $applied['message'],
			'log'     => $this->get_log_tail( 30 ),
		);
	}

	/**
	 * Скачивание файла по URL сразу в файл (без чтения в память).
	 *
	 * @param string $url    URL.
	 * @param string $target Локальный путь назначения.
	 * @return bool
	 */
	private function download_to_file( $url, $target ) {
		$fp = @fopen( $target, 'wb' );
		if ( false === $fp ) {
			return false;
		}

		$ch = curl_init();
		curl_setopt_array( $ch, array(
			CURLOPT_URL            => $url,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_FILE           => $fp,
		) );

		$ok   = curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		fclose( $fp );

		return ( false !== $ok && 200 === $code );
	}

	/**
	 * Удаление файла с Диска (204 — успех, 404 — уже нет).
	 *
	 * @param string $remote Удалённый путь.
	 * @param string $token  OAuth-токен.
	 * @return bool
	 */
	private function delete_remote_file( $remote, $token ) {
		$url  = add_query_arg( 'path', $remote, self::DISK_API_URL . '/resources' );
		$resp = $this->http_request( 'DELETE', $url, array( 'headers' => array( 'Authorization: OAuth ' . $token ) ) );
		return in_array( $resp['code'], array( 204, 404 ), true );
	}

	/**
	 * Запуск загрузки из облака (крон или кнопка в админке).
	 *
	 * Одиночный прогон: обрабатывает до MAX_FILES_PER_RUN файлов за вызов.
	 * $force = true — принудительный режим: игнорирует счётчик неудачных
	 * попыток и скачивает всё найденное в remote_path.
	 *
	 * @param bool $force Принудительный режим.
	 * @return array
	 */
	public function run_download( $force = false ) {
		@set_time_limit( 0 );

		$stats = array(
			'found'      => 0,
			'downloaded' => 0,
			'failed'     => 0,
			'skipped'    => 0,
			'message'    => '',
			'log'        => '',
		);

		$settings = $this->get_settings();

		if ( empty( $settings['downloader_mode'] ) ) {
			$stats['message'] = __( 'Режим загрузчика выключен в настройках.', 'wp-cloud-sync' );
			return $stats;
		}

		if ( empty( $settings['yandex_access_token'] ) ) {
			$this->log( __( 'Нет токена доступа — загрузка пропущена.', 'wp-cloud-sync' ), 'error' );
			$stats['message'] = __( 'Нет токена доступа.', 'wp-cloud-sync' );
			$stats['log']     = $this->get_log_tail( 20 );
			return $stats;
		}

		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			$stats['message'] = __( 'Загрузка уже выполняется.', 'wp-cloud-sync' );
			$stats['log']     = $this->get_log_tail( 20 );
			return $stats;
		}
		set_transient( self::LOCK_TRANSIENT, array( 'id' => 'cron', 'at' => time() ), 5 * MINUTE_IN_SECONDS );

		$token   = $settings['yandex_access_token'];
		$root    = $this->remote_root();
		$this->log( '[DEBUG] Проверка папки: ' . $root );
		$files   = array();
		$retries = get_option( self::OPTION_RETRIES, array() );
		if ( ! is_array( $retries ) ) {
			$retries = array();
		}

		$list_ok = $this->list_remote_files( $root, $token, $files );
		$files   = array_slice( $files, 0, self::MAX_FILES_PER_RUN );

		$stats['found'] = count( $files );

		if ( ! $list_ok ) {
			delete_transient( self::LOCK_TRANSIENT );
			$stats['message'] = sprintf( __( 'Не удалось получить список папки %s — проверьте настройки.', 'wp-cloud-sync' ), $root );
			$stats['log']     = $this->get_log_tail( 20 );
			return $stats;
		}

		if ( empty( $files ) ) {
			delete_transient( self::LOCK_TRANSIENT );
			$this->log( __( 'Новых файлов в облаке нет.', 'wp-cloud-sync' ) );
			$stats['message'] = __( 'Новых файлов в облаке нет.', 'wp-cloud-sync' );
			$stats['log']     = $this->get_log_tail( 20 );
			return $stats;
		}

		$this->log( sprintf( __( 'Запуск: найдено %d файлов.', 'wp-cloud-sync' ), count( $files ) ) );

		foreach ( $files as $file ) {
			$this->process_remote_file( $file['remote'], $token, $force, $retries, $stats, '' );
		}

		update_option( self::OPTION_RETRIES, $retries, false );
		delete_transient( self::LOCK_TRANSIENT );

		$stats['message'] = sprintf(
			__( 'Загрузка завершена: скачано %d из %d.', 'wp-cloud-sync' ),
			$stats['downloaded'],
			$stats['found']
		);
		$stats['log']     = $this->get_log_tail( 20 );

		return $stats;
	}

	/**
	 * Обработка одного удалённого файла: фильтры, скачивание, удаление с Диска.
	 *
	 * @param string $remote     Удалённый путь.
	 * @param string $token      OAuth-токен.
	 * @param bool   $force      Принудительно (игнорировать счётчик попыток).
	 * @param array  $retries    Карта неудачных попыток (по ссылке).
	 * @param array  $stats      Счётчики (по ссылке).
	 * @param string $log_prefix Префикс строк лога (например '[DOWNLOAD] ').
	 */
	private function process_remote_file( $remote, $token, $force, &$retries, &$stats, $log_prefix = '' ) {
		$tries = isset( $retries[ $remote ] ) ? (int) $retries[ $remote ] : 0;

		if ( ! $force && $tries >= self::MAX_RETRIES ) {
			$stats['skipped']++;
			$this->log( $log_prefix . sprintf( __( 'Пропуск %s: %d неудачных попыток.', 'wp-cloud-sync' ), $remote, $tries ), 'error' );
			return;
		}

		$local = $this->local_path_for( $remote );
		$rel   = $this->local_rel_for( $remote );

		if ( $this->is_excluded( $rel ) ) {
			$stats['skipped']++;
			$this->log( $log_prefix . sprintf( __( 'Пропуск (исключённая папка): %s.', 'wp-cloud-sync' ), $remote ) );
			return;
		}

		if ( ! $this->is_allowed_rel( $rel ) ) {
			$stats['skipped']++;
			$this->log( $log_prefix . sprintf( __( 'Пропуск (вне папок темы/плагинов): %s.', 'wp-cloud-sync' ), $remote ) );
			return;
		}

		if ( $this->download_file( $remote, $local, $token ) ) {
			if ( $this->delete_remote_file( $remote, $token ) ) {
				unset( $retries[ $remote ] );
				$stats['downloaded']++;
				$this->log( $log_prefix . sprintf( __( 'Скачан: %s -> %s (и удалён из облака).', 'wp-cloud-sync' ), $remote, $local ) );
			} else {
				$stats['failed']++;
				$this->log( $log_prefix . sprintf( __( 'Файл скачан, но не удалён из облака: %s.', 'wp-cloud-sync' ), $remote ), 'error' );
			}
		} else {
			$retries[ $remote ] = $tries + 1;
			$stats['failed']++;
			$this->log(
				$log_prefix . sprintf(
					__( 'Ошибка скачивания %s (попытка %d из %d).', 'wp-cloud-sync' ),
					$remote,
					$retries[ $remote ],
					self::MAX_RETRIES
				),
				'error'
			);
		}
	}

	/**
	 * Ручной запуск загрузчика из админки (AJAX, одиночный прогон).
	 */
	public function ajax_download() {
		check_ajax_referer( 'wp_cloud_sync_download', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'wp-cloud-sync' ) ) );
		}

		$stats = $this->run_download();

		wp_send_json_success( $stats );
	}

	/**
	 * Ручной запуск загрузчика с прогрессом (AJAX, run-механизм).
	 *
	 * Каждый вызов обрабатывает одно «окно» файлов (MAX_FILES_PER_RUN) и
	 * возвращает прогресс. Защита от конфликтов — как у синхронизации:
	 * блокировка с run_id, зависшая блокировка сбрасывается через 30 сек.
	 */
	public function ajax_run_download() {
		check_ajax_referer( 'wp_cloud_sync_download', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'wp-cloud-sync' ) ) );
		}

		$settings = $this->get_settings();
		if ( empty( $settings['downloader_mode'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Режим загрузчика выключен в настройках.', 'wp-cloud-sync' ) ) );
		}
		if ( empty( $settings['yandex_access_token'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Нет токена доступа. Сначала получите токен.', 'wp-cloud-sync' ) ) );
		}

		$run_id = isset( $_REQUEST['run_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['run_id'] ) ) : '';
		$force  = ! empty( $_REQUEST['force'] );
		if ( '' === $run_id ) {
			wp_send_json_error( array( 'message' => __( 'Нет run_id.', 'wp-cloud-sync' ) ) );
		}

		$lock = get_transient( self::LOCK_TRANSIENT );

		// Блокировка существует.
		if ( $lock ) {
			$lock_at  = is_array( $lock ) ? (int) $lock['at'] : 0;
			$lock_id  = is_array( $lock ) ? $lock['id'] : '';
			$is_stale = 0 === $lock_at || ( time() - $lock_at ) >= self::LOCK_TTL_STALE;

			// Другой запуск активен (< 30 сек) — блокируем повторный.
			if ( ! $is_stale && $lock_id !== $run_id ) {
				wp_send_json_error( array( 'message' => __( 'Процесс уже запущен.', 'wp-cloud-sync' ) ) );
			}
			// Своя цепочка или зависшая блокировка — сбрасываем и идём дальше.
			delete_transient( self::LOCK_TRANSIENT );
		}

		set_transient( self::LOCK_TRANSIENT, array( 'id' => $run_id, 'at' => time() ), 5 * MINUTE_IN_SECONDS );

		$result = $this->run_batch_download( $settings['yandex_access_token'], $run_id, $force );
		wp_send_json_success( $result );
	}

	/**
	 * Одна итерация ручной загрузки (окно файлов).
	 *
	 * @param string $token  OAuth-токен.
	 * @param string $run_id Идентификатор запуска.
	 * @param bool   $force  Принудительный режим.
	 * @return array
	 */
	public function run_batch_download( $token, $run_id = '', $force = false ) {
		@set_time_limit( 0 );

		$state = $this->get_run_state();

		// Новый запуск (другой run_id или завершённый проход): собираем список заново.
		$fresh_run = empty( $state ) || ! isset( $state['run_id'] ) || $state['run_id'] !== $run_id || ! empty( $state['done'] );
		if ( $fresh_run ) {
			$files = array();
			$ok    = $this->list_remote_files( $this->remote_root(), $token, $files );

			if ( ! $ok ) {
				$this->reset_run_state();
				delete_transient( self::LOCK_TRANSIENT );
				return array(
					'total'    => 0,
					'current'  => 0,
					'done'     => true,
					'aborted'  => true,
					'error'    => sprintf( __( 'Не удалось получить список папки %s — проверьте настройки.', 'wp-cloud-sync' ), $this->remote_root() ),
					'counters' => array( 'downloaded' => 0, 'failed' => 0, 'skipped' => 0 ),
					'log'      => $this->get_log_tail( 20 ),
				);
			}

			$state = array(
				'run_id'   => $run_id,
				'force'    => (bool) $force,
				'files'    => $files,
				'offset'   => 0,
				'total'    => count( $files ),
				'counters' => array( 'downloaded' => 0, 'failed' => 0, 'skipped' => 0 ),
				'done'     => false,
			);

			if ( empty( $files ) ) {
				$this->reset_run_state();
				delete_transient( self::LOCK_TRANSIENT );
				$this->log( __( 'Новых файлов в облаке нет.', 'wp-cloud-sync' ) );
				return array(
					'total'    => 0,
					'current'  => 0,
					'done'     => true,
					'counters' => $state['counters'],
					'log'      => $this->get_log_tail( 20 ),
				);
			}

			$this->log(
				'[DOWNLOAD] ' . sprintf(
					__( 'Запуск: найдено %d файлов (%s).', 'wp-cloud-sync' ),
					count( $files ),
					$force ? __( 'принудительно', 'wp-cloud-sync' ) : __( 'инкрементально', 'wp-cloud-sync' )
				)
			);
		}

		$total = (int) $state['total'];
		$start = (int) $state['offset'];
		$end   = min( $start + self::MAX_FILES_PER_RUN, $total );

		// Все файлы обработаны — завершаем прогон.
		if ( $start >= $total ) {
			$state['done'] = true;
			$this->save_run_state( $state );
			delete_transient( self::LOCK_TRANSIENT );
			$this->log( '[DOWNLOAD] ' . __( 'Загрузка завершена.', 'wp-cloud-sync' ) );
			return array(
				'total'    => $total,
				'current'  => $total,
				'done'     => true,
				'counters' => $state['counters'],
				'log'      => $this->get_log_tail( 20 ),
			);
		}

		$retries = get_option( self::OPTION_RETRIES, array() );
		if ( ! is_array( $retries ) ) {
			$retries = array();
		}

		$files = $state['files'];
		for ( $i = $start; $i < $end; $i++ ) {
			if ( isset( $files[ $i ]['remote'] ) ) {
				$this->process_remote_file( $files[ $i ]['remote'], $token, $force, $retries, $state['counters'], '[DOWNLOAD] ' );
			}
		}

		$state['offset'] = $end;
		$this->save_run_state( $state );
		update_option( self::OPTION_RETRIES, $retries, false );

		return array(
			'total'    => $total,
			'current'  => $end,
			'done'     => false,
			'counters' => $state['counters'],
			'log'      => $this->get_log_tail( 20 ),
		);
	}

	/**
	 * Состояние ручного прогона (напрямую из БД, без кэша).
	 *
	 * @return array
	 */
	private function get_run_state() {
		$state = $this->option_fresh( self::OPTION_RUN_STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Сохранение состояния ручного прогона.
	 *
	 * @param array $state Состояние.
	 */
	private function save_run_state( $state ) {
		update_option( self::OPTION_RUN_STATE, $state, false );
	}

	/**
	 * Сброс состояния ручного прогона.
	 */
	private function reset_run_state() {
		delete_option( self::OPTION_RUN_STATE );
	}

	/**
	 * Чтение опции напрямую из БД (минуя кэш).
	 *
	 * @param string $name    Имя опции.
	 * @param mixed  $default Значение по умолчанию.
	 * @return mixed
	 */
	private function option_fresh( $name, $default = false ) {
		global $wpdb;

		$row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( null === $row ) {
			return $default;
		}
		return maybe_unserialize( $row );
	}
}
