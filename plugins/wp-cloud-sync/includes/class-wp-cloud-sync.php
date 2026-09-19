<?php
/**
 * Основной класс плагина WP Cloud Sync.
 *
 * Регистрирует страницу настроек в меню «Настройки», обрабатывает
 * сохранение опций, обмен OAuth-кода на токен и инкрементальную
 * синхронизацию файлов сайта на Яндекс.Диск (чанковая загрузка по 5 МБ).
 *
 * @package WPCloudSync
 */

namespace WPCloudSync;

/**
 * Класс WP_Cloud_Sync.
 */
class WP_Cloud_Sync {

	/**
	 * Ключ опции с настройками.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'wp_cloud_sync_settings';

	/**
	 * Ключ опции с хешами уже синхронизированных файлов.
	 *
	 * @var string
	 */
	const OPTION_HASHES = 'wp_cloud_sync_hashes';

	/**
	 * Ключ опции с состоянием текущей синхронизации.
	 *
	 * @var string
	 */
	const OPTION_STATE = 'wp_cloud_sync_state';

	/**
	 * Устаревший плейсхолдер старых версий плагина.
	 *
	 * Старые версии при импорте БД заменяли каждый символ '%'
	 * этим токеном (64 hex-символа); финальный шаг восстановления
	 * мог не выполниться, и токен оставался в слагах и опциях.
	 *
	 * @var string
	 */
	const LEGACY_TOKEN = '{a58a51e537b0cf2b7d731cb93015228e577d752d82163fff88afafec4f8eefca}';

	/**
	 * Транзиент-флаг «процесс синхронизации уже запущен».
	 *
	 * @var string
	 */
	const LOCK_TRANSIENT = 'wp_cloud_sync_running';
	/**
	 * Флаг запроса остановки синхронизации (option).
	 *
	 * @var string
	 */
	const STOP_OPTION = 'wp_cloud_sync_stop_requested';

	/**
	 * Расширения файлов, пропускаемые при локальном сканировании
	 * (статичные картинки, которые редко меняются).
	 *
	 * @var array
	 */
	const SKIP_EXTENSIONS = array( '.jpg', '.jpeg', '.png', '.gif', '.svg', '.ico' );

	/**
	 * Через сколько секунд блокировку считать «зависшей» и сбросить.
	 *
	 * @var int
	 */
	const LOCK_TTL_STALE = 30;

	/**
	 * Опция-«сердцебиение»: обновляется каждый раз, когда воркер реально
	 * работает (загружает файлы). Stale-проверка счётчика активных воркеров
	 * опирается на неё, а не на время резервирования окна: окно может
	 * грузиться дольше LOCK_TTL_STALE, и по active_since живой воркер
	 * выглядел бы «зависшим» — счётчик обнулялся бы, и синхронизация
	 * объявлялась бы завершённой до окончания загрузки.
	 *
	 * @var string
	 */
	const OPTION_HEARTBEAT = 'wp_cloud_sync_heartbeat';

	/**
	 * Базовый URL авторизации Яндекс OAuth.
	 *
	 * @var string
	 */
	const YANDEX_AUTH_URL = 'https://oauth.yandex.ru/authorize';

	/**
	 * Эндпоинт обмена кода на токен.
	 *
	 * @var string
	 */
	const YANDEX_TOKEN_URL = 'https://oauth.yandex.ru/token';

	/**
	 * Redirect URI для приложений без собственного сервера.
	 *
	 * @var string
	 */
	const YANDEX_REDIRECT_URI = 'https://oauth.yandex.ru/verification_code';

	/**
	 * Базовый URL API Яндекс.Диска.
	 *
	 * @var string
	 */
	const DISK_API_URL = 'https://cloud-api.yandex.net/v1/disk';

	/**
	 * Сколько файлов обрабатывать за один AJAX-запрос.
	 *
	 * @var int
	 */
	const FILES_PER_BATCH = 8;

	/**
	 * Максимальный размер файла для загрузки (ограничение Яндекс.Диска).
	 *
	 * @var int
	 */
	const MAX_FILE_SIZE = 1073741824;

	/**
	 * Директории, которые не сканируем.
	 *
	 * @var array
	 */
	const EXCLUDED_DIRS = array(
		'.git',
		'node_modules',
		'wp-content/cache',
		'wp-content/upgrade',
	);

	/**
	 * Служебные папки, которые исключаются всегда (по имени на любом уровне вложенности).
	 *
	 * Это жёсткое исключение на уровне итератора: такие папки не попадают в список файлов.
	 *
	 * @var array
	 */
	const HARD_EXCLUDED_DIR_NAMES = array(
		'vendor',
		'node_modules',
		'tests',
		'test',
		'bin',
		'build',
	);

	/**
	 * Синглтон.
	 *
	 * @var WP_Cloud_Sync|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра.
	 *
	 * @return WP_Cloud_Sync
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
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_wp_cloud_sync_exchange', array( $this, 'handle_token_exchange' ) );
		add_action( 'admin_post_wp_cloud_sync_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_post_wp_cloud_sync_download_log', array( $this, 'handle_download_log' ) );
		add_action( 'wp_ajax_wp_cloud_sync_run', array( $this, 'ajax_run_batch' ) );
		add_action( 'wp_ajax_wp_cloud_sync_stop', array( $this, 'ajax_stop_sync' ) );
		add_action( 'wp_ajax_wp_cloud_sync_db_export', array( $this, 'handle_db_export' ) );
		add_action( 'wp_ajax_wp_cloud_sync_db_list', array( $this, 'ajax_db_list' ) );
		add_action( 'wp_ajax_wp_cloud_sync_repair_db', array( $this, 'ajax_repair_db' ) );
	}

	/**
	 * Значения по умолчанию.
	 *
	 * @return array
	 */
		public function get_defaults() {
		return array(
			'yandex_client_id'     => '',
			'yandex_client_secret' => '',
			'remote_path'          => '',
			'yandex_access_token'  => '',
			'downloader_mode'      => 0,
			'downloader_cron'      => 0,
			'excluded_dirs'        => "wp-content/cache\nwp-content/upgrade\n.git\nnode_modules",
			'local_root'           => '',
			'custom_theme'         => '',
			'custom_plugins'       => '',
			'force_reset_hashes'   => 0,
			'replace_db_urls'      => 1,
			'source_urls'          => '',
			'target_url'           => '',
		);
	}

	/**
	 * Текущие настройки (с подстановкой дефолтов).
	 *
	 * @return array
	 */
	public function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, $this->get_defaults() );
	}

	/**
	 * Корневая папка сайта для синхронизации (настройка local_root или ABSPATH).
	 *
	 * @return string
	 */
	public function local_root() {
		$settings = $this->get_settings();
		$path     = trim( (string) $settings['local_root'] );

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
	public function get_excluded_dirs() {
		$list = self::EXCLUDED_DIRS;
		$settings = $this->get_settings();

		if ( ! empty( $settings['excluded_dirs'] ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $settings['excluded_dirs'] ) as $line ) {
				$line = trim( $line, " \t/" );
				if ( '' !== $line ) {
					$list[] = wp_normalize_path( $line );
				}
			}
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * Страница настроек в меню «Настройки».
	 */
	public function register_settings_page() {
		add_options_page(
			__( 'WP Cloud Sync', 'wp-cloud-sync' ),
			__( 'WP Cloud Sync', 'wp-cloud-sync' ),
			'manage_options',
			'wp-cloud-sync',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Регистрация опции (с sanitize-callback).
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_KEY,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->get_defaults(),
			)
		);
	}

	/**
	 * Санитизация входящих данных перед сохранением.
	 *
	 * @param mixed $input Сырые данные из формы.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$sanitized = $this->get_defaults();

		if ( ! is_array( $input ) ) {
			return $sanitized;
		}

		if ( isset( $input['yandex_client_id'] ) ) {
			$sanitized['yandex_client_id'] = sanitize_text_field( $input['yandex_client_id'] );
		}
		if ( isset( $input['yandex_client_secret'] ) ) {
			$sanitized['yandex_client_secret'] = sanitize_text_field( $input['yandex_client_secret'] );
		}
		if ( isset( $input['remote_path'] ) ) {
			$sanitized['remote_path'] = trim( wp_unslash( $input['remote_path'] ) );
		}
		if ( isset( $input['yandex_access_token'] ) ) {
			$sanitized['yandex_access_token'] = sanitize_text_field( $input['yandex_access_token'] );
		}
		$sanitized['downloader_mode'] = empty( $input['downloader_mode'] ) ? 0 : 1;
		$sanitized['downloader_cron'] = empty( $input['downloader_cron'] ) ? 0 : 1;

		if ( isset( $input['excluded_dirs'] ) ) {
			$lines = array();
			foreach ( preg_split( '/\r\n|\r|\n/', sanitize_textarea_field( wp_unslash( $input['excluded_dirs'] ) ) ) as $line ) {
				$line = trim( $line, " \t/" );
				$line = wp_normalize_path( $line );
				if ( '' !== $line ) {
					$lines[ $line ] = true;
				}
			}
			$sanitized['excluded_dirs'] = implode( "\n", array_keys( $lines ) );
		}

		if ( isset( $input['local_root'] ) ) {
			$sanitized['local_root'] = trim( wp_unslash( $input['local_root'] ) );
		}

		if ( isset( $input['custom_theme'] ) ) {
			$sanitized['custom_theme'] = sanitize_file_name( wp_unslash( $input['custom_theme'] ) );
		}

		if ( isset( $input['custom_plugins'] ) ) {
			$sanitized['custom_plugins'] = sanitize_text_field( wp_unslash( $input['custom_plugins'] ) );
		}

		$sanitized['force_reset_hashes'] = empty( $input['force_reset_hashes'] ) ? 0 : 1;
		$sanitized['replace_db_urls']    = empty( $input['replace_db_urls'] ) ? 0 : 1;

		if ( isset( $input['source_urls'] ) ) {
			$lines = array();
			foreach ( preg_split( "/\r\n|\r|\n/", sanitize_textarea_field( wp_unslash( $input['source_urls'] ) ) ) as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$lines[ $line ] = true;
				}
			}
			$sanitized['source_urls'] = implode( "\n", array_keys( $lines ) );
		}

		if ( isset( $input['target_url'] ) ) {
			$sanitized['target_url'] = untrailingslashit( trim( sanitize_text_field( wp_unslash( $input['target_url'] ) ) ) );
		}

		return $sanitized;
	}

	/**
	 * Ссылка авторизации Яндекс OAuth.
	 *
	 * @param string $client_id Yandex Client ID.
	 * @return string
	 */
	public function build_auth_url( $client_id ) {
		return add_query_arg(
			array(
				'response_type' => 'code',
				'client_id'     => $client_id,
				'redirect_uri'  => self::YANDEX_REDIRECT_URI,
				'scope'         => 'cloud_api:disk.read cloud_api:disk.write',
			),
			self::YANDEX_AUTH_URL
		);
	}

	/**
	 * Обмен OAuth-кода на access_token (action: admin_post_wp_cloud_sync_exchange).
	 */
	public function handle_token_exchange() {
		check_admin_referer( 'wp_cloud_sync_exchange' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'wp-cloud-sync' ) );
		}

		$code  = isset( $_POST['wpcs_code'] ) ? sanitize_text_field( wp_unslash( $_POST['wpcs_code'] ) ) : '';
		$token = $this->exchange_code_for_token( $code );

		if ( is_wp_error( $token ) ) {
			wp_safe_redirect(
				add_query_arg( 'wpcs_error', rawurlencode( $token->get_error_message() ), admin_url( 'options-general.php?page=wp-cloud-sync' ) )
			);
		} else {
			$settings              = $this->get_settings();
			$settings['yandex_access_token'] = $token;
			update_option( self::OPTION_KEY, $settings );
			wp_safe_redirect(
				add_query_arg( 'wpcs_ok', '1', admin_url( 'options-general.php?page=wp-cloud-sync' ) )
			);
		}
		exit;
	}

	/**
	 * Запуск следующего батча синхронизации (AJAX).
	 */
	public function ajax_run_batch() {
		check_ajax_referer( 'wp_cloud_sync_run', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Недостаточно прав.' ) );
		}

		$settings = $this->get_settings();
		if ( empty( $settings['yandex_access_token'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Нет токена доступа. Сначала получите токен.', 'wp-cloud-sync' ) ) );
		}

		// Новый запуск всегда сбрасывает флаг остановки.
		delete_option( self::STOP_OPTION );

		$run_id  = isset( $_REQUEST['run_id'] ) ? sanitize_key( $_REQUEST['run_id'] ) : '';
		$lock    = get_transient( self::LOCK_TRANSIENT );

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

		$result = $this->run_batch( $settings['yandex_access_token'], $run_id );
		wp_send_json_success( $result );
	}

	/**
	 * Остановка синхронизации (AJAX): выставляет флаг, который run_batch
	 * проверяет перед каждым батчем и сразу завершает работу.
	 */
	public function ajax_stop_sync() {
		check_ajax_referer( 'wp_cloud_sync_stop', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Недостаточно прав.' ) );
		}

		update_option( self::STOP_OPTION, 1 );

		wp_send_json_success( array( 'stopped' => true ) );
	}

	/**
	 * Одна итерация синхронизации (сканирование + обработка батча файлов).
	 *
	 * @param string $token OAuth-токен.
	 * @return array
	 */
	public function run_batch( $token, $run_id = '' ) {
		@set_time_limit( 0 );

		// Атомарный захват окна файлов (защита от параллельных AJAX).
		if ( ! $this->acquire_batch_lock() ) {
			return array(
				'total'          => 0,
				'current_index'  => 0,
				'scanned_count'  => 0,
				'uploaded_count' => 0,
				'failed_count'   => 0,
				'idx'            => 0,
				'done'           => false,
				'busy'           => true,
				'counters'       => $this->default_counters(),
				'log'            => '',
			);
		}

		// [5] Принудительный сброс хешей и состояния (разовый флаг в настройках).
		// Выполняется только под блокировкой, чтобы его обработал ровно один воркер.
		// Флаг читаем НАПРЯМУЮ из БД: get_settings() кэширует опцию в процессе,
		// и параллельные воркеры со своим кэшем продолжали бы видеть флаг = 1,
		// сбрасывая хеши и состояние на каждом вызове.
		$flag_settings = $this->option_fresh( self::OPTION_KEY, array() );
		if ( ! is_array( $flag_settings ) ) {
			$flag_settings = array();
		}
		$flag_settings = wp_parse_args( $flag_settings, $this->get_defaults() );
		if ( ! empty( $flag_settings['force_reset_hashes'] ) ) {
			delete_option( self::OPTION_HASHES );
			delete_option( self::OPTION_STATE );
			$flag_settings['force_reset_hashes'] = 0;
			update_option( self::OPTION_KEY, $flag_settings );
			$this->log( 'Принудительный сброс хешей и состояния выполнен (флаг force_reset_hashes).', 'info' );
		}

		// Пользователь нажал «Остановить»: завершаемся без обработки нового окна.
		if ( get_option( self::STOP_OPTION ) ) {
			delete_option( self::STOP_OPTION );
			$this->release_batch_lock();
			delete_transient( self::LOCK_TRANSIENT );
			$this->log( __( 'Синхронизация остановлена пользователем.', 'wp-cloud-sync' ) );
			$current_state = $this->get_state();
			return array(
				'total'          => (int) ( isset( $current_state['total'] ) ? $current_state['total'] : 0 ),
				'current_index'  => (int) ( isset( $current_state['idx'] ) ? $current_state['idx'] : 0 ),
				'scanned_count'  => 0,
				'uploaded_count' => 0,
				'failed_count'   => 0,
				'idx'            => (int) ( isset( $current_state['idx'] ) ? $current_state['idx'] : 0 ),
				'done'           => false,
				'stopped'        => true,
				'counters'       => $this->default_counters(),
				'log'            => $this->get_log_tail( 5 ),
			);
		}

		$state    = $this->get_state();
		$prev_idx = (int) ( isset( $state['idx'] ) ? $state['idx'] : 0 );

		// Синхронизация уже завершена этой же цепочкой воркеров (done=true):
		// возвращаем done, состояние в БД сохраняем — параллельные воркеры
		// увидят done и остановятся, а новый запуск (другой run_id) начнёт
		// свежий проход. Удалять state здесь нельзя: другие воркеры могут
		// быть в полёте, и пустое состояние заставило бы их пересобрать список.
		if ( ! empty( $state['done'] ) && isset( $state['run_id'] ) && $state['run_id'] === $run_id ) {
			$this->release_batch_lock();
			return array(
				'total'          => (int) ( isset( $state['total'] ) ? $state['total'] : 0 ),
				'current_index'  => (int) ( isset( $state['idx'] ) ? $state['idx'] : 0 ),
				'scanned_count'  => 0,
				'uploaded_count' => 0,
				'failed_count'   => 0,
				'idx'            => (int) ( isset( $state['idx'] ) ? $state['idx'] : 0 ),
				'done'           => true,
				'counters'       => $this->default_counters(),
				'log'            => $this->get_log_tail( 5 ),
			);
		}

		// Завершённый проход + новый запуск (другой run_id) = начинаем заново.
		$fresh_run = ! empty( $state['done'] ) && ( ! isset( $state['run_id'] ) || $state['run_id'] !== $run_id );
		if ( $fresh_run ) {
			$state['done'] = false;
			$prev_idx      = 0;
		}

		// Если настройки сканирования изменились с прошлого прогона — список устарел,
		// пересобираем его заново (иначе продолжат грузиться старые файлы).
		if ( ! empty( $state['files'] ) && ( ! isset( $state['scope_hash'] ) || $state['scope_hash'] !== $this->scope_hash() ) ) {
			$this->reset_state();
			$state    = $this->get_state();
			$prev_idx = 0;
			$this->log( __( 'Настройки сканирования изменились — список файлов пересобран.', 'wp-cloud-sync' ) );
		}

		// Валидация токена один раз в начале прогона (idx === 0).
		if ( 0 === $prev_idx ) {
			$check = $this->check_token( $token );
			if ( is_wp_error( $check ) ) {
				$this->release_batch_lock();
				$this->log( $check->get_error_message(), 'error' );
				$this->reset_state();
				return array(
					'total'          => 0,
					'current_index'  => 0,
					'scanned_count'  => 0,
					'uploaded_count' => 0,
					'failed_count'   => 0,
					'idx'            => 0,
					'done'           => false,
					'aborted'        => true,
					'error'          => $check->get_error_message(),
					'counters'       => $this->default_counters(),
					'log'            => $this->get_log_tail( 5 ),
				);
			}
		}

		// Начало работы: собираем список файлов, если он пуст либо это новый
		// запуск поверх завершённого прохода. Условие «idx >= count» здесь
		// НАМЕРЕННО отсутствует: после обработки последнего окна список нельзя
		// пересобирать с idx = 0, иначе воркеры вечно гоняют проходы заново.
		if ( empty( $state['files'] ) || ! is_array( $state['files'] ) || $fresh_run ) {
			$state = $this->build_file_list();
			$state['total'] = count( $state['files'] );

			// [4] Список пуст, но настройки не пустые — сбрасываем состояние
			// и пересобираем список ещё раз (защита от устаревшего scope_hash).
			$settings_now = $this->get_settings();
			if ( 0 === count( $state['files'] ) && ( '' !== trim( (string) $settings_now['custom_theme'] ) || '' !== trim( (string) $settings_now['custom_plugins'] ) ) ) {
				$this->log( 'build_file_list() вернул 0 файлов при непустых настройках — принудительный сброс состояния и повторная сборка.', 'warning' );
				$this->reset_state();
				$state = $this->build_file_list();
				$state['total'] = count( $state['files'] );
			}

			if ( 0 === $prev_idx ) {
				$this->log( sprintf( __( 'Сканирование завершено: %d файлов (корень: %s).', 'wp-cloud-sync' ), count( $state['files'] ), $this->local_root() ) );
			}
		}

		$files = $state['files'];
		$total = count( $files );

		// Резервируем окно [start, end) и фиксируем idx — теперь параллельные
		// запросы не смогут обработать те же файлы.
		$start = (int) $state['idx'];
		$end   = min( $start + self::FILES_PER_BATCH, $total );

		// Пустое окно: все файлы уже обработаны. Не резервируем окно
		// (не инкрементируем счётчик активных воркеров), а сразу
		// проверяем, завершена ли синхронизация.
		if ( $start >= $total ) {
			return $this->finalize_batch( $total, $run_id, false );
		}

		// Счётчик активных воркеров. Новый запуск (другой run_id) или
		// полностью зависший прогон (нет «сердцебиения» дольше
		// LOCK_TTL_STALE — все воркеры умерли) — сбрасываем счётчик, иначе
		// «Синхронизация завершена» может никогда не наступить. Пока хотя бы
		// один воркер жив и грузит файлы, heartbeat свежий, и счётчик не
		// трогаем: медленное окно — не повод считать воркеров мёртвыми.
		$now    = time();
		$stale  = (int) $this->option_fresh( self::OPTION_HEARTBEAT, 0 );
		$run_changed = isset( $state['run_id'] ) && $state['run_id'] !== $run_id;
		if ( $run_changed || ( $stale > 0 && ( $now - $stale ) >= self::LOCK_TTL_STALE ) ) {
			$state['active_workers'] = 0;
		}
		$state['active_workers'] = (int) ( isset( $state['active_workers'] ) ? $state['active_workers'] : 0 ) + 1;
		$state['run_id']         = $run_id;
		$state['idx']            = $end;
		$this->touch_heartbeat();
		$this->save_state( $state );
		$this->release_batch_lock();

		$hashes = $this->option_fresh( self::OPTION_HASHES, array() );
		if ( ! is_array( $hashes ) ) {
			$hashes = array();
		}

		$counters = $this->default_counters();
		$root     = $this->local_root();

		for ( $i = $start; $i < $end; $i++ ) {
			$rel   = $files[ $i ];
			$path  = $root . $rel;

			if ( ! is_file( $path ) ) {
				$counters['skipped']++;
				continue;
			}

			$size = filesize( $path );
			if ( $size > self::MAX_FILE_SIZE ) {
				$this->log( sprintf( __( 'Пропуск %s: файл больше 1 ГБ.', 'wp-cloud-sync' ), $rel ) );
				$counters['skipped']++;
				continue;
			}

			$md5 = md5_file( $path );
			$counters['scanned']++;

			if ( isset( $hashes[ $rel ] ) && hash_equals( $hashes[ $rel ], $md5 ) ) {
				$counters['up_to_date']++;
				continue;
			}

			$remote = $this->remote_path_for( $rel );
			if ( $this->upload_file( $path, $remote, $token ) ) {
				$this->set_hash( $rel, $md5 );
				$counters['uploaded']++;
				$this->log( sprintf( __( 'Загружен: %s (%d байт).', 'wp-cloud-sync' ), $rel, $size ) );
			} else {
				$counters['failed']++;
				$this->log( sprintf( __( 'Ошибка загрузки: %s.', 'wp-cloud-sync' ), $rel ), 'error' );
			}
			// Пульс во время работы: пока окно грузится (это может длиться
			// дольше LOCK_TTL_STALE), stale-проверка не должна считать
			// воркера зависшим и обнулять счётчик активных воркеров.
			$this->touch_heartbeat();
		}

		// Снимаем себя со счётчика активных воркеров и только после этого
		// решаем, что синхронизация завершена: «завершена» означает, что
		// последнее окно обработано И других воркеров в полёте нет.
		// Пометка done выполняется ПОД локом, чтобы счётчик и флаг
		// читались и писались атомарно (иначе два воркера могли бы
		// потерять декремент или пометить done раньше времени).
		$response = $this->finalize_batch( $total, $run_id, true );
		$response['scanned_count']  = (int) $counters['scanned'];
		$response['uploaded_count'] = (int) $counters['uploaded'];
		$response['failed_count']   = (int) $counters['failed'];
		$response['counters']       = $counters;

		return $response;
	}

	/**
	 * Финальная проверка завершения синхронизации (под локом).
	 *
	 * Снимает воркера со счётчика активных (если $decrement) и, если
	 * последнее окно обработано и других воркеров в полёте нет, помечает
	 * синхронизацию завершённой. Вызывается как после обработки реального
	 * окна, так и для пустых окон (когда все файлы уже обработаны) — в
	 * последнем случае воркер не инкрементировал счётчик, поэтому и не
	 * декрементирует его.
	 *
	 * @param int    $total     Общее число файлов.
	 * @param string $run_id    Идентификатор запуска.
	 * @param bool   $decrement Нужно ли снять воркера со счётчика.
	 * @return array
	 */
	private function finalize_batch( $total, $run_id, $decrement = true ) {
		$this->acquire_batch_lock();
		$state = $this->get_state();

		if ( $decrement ) {
			$active = (int) ( isset( $state['active_workers'] ) ? $state['active_workers'] : 1 );
			$state['active_workers'] = max( 0, $active - 1 );
		}

		$done = ( (int) $state['idx'] >= $total ) && 0 === (int) $state['active_workers'];
		if ( $done && empty( $state['done'] ) ) {
			$state['done']     = true;
			$state['finished'] = current_time( 'mysql' );
			$this->log( __( 'Синхронизация завершена.', 'wp-cloud-sync' ) );
			delete_transient( self::LOCK_TRANSIENT );
			delete_option( self::OPTION_HEARTBEAT );
		}
		$this->save_state( $state );
		$this->release_batch_lock();

		return array(
			'total'          => (int) $total,
			'current_index'  => (int) $state['idx'],
			'scanned_count'  => 0,
			'uploaded_count' => 0,
			'failed_count'   => 0,
			'idx'            => (int) $state['idx'],
			'done'           => $done,
			'counters'       => $this->default_counters(),
			'log'            => $this->get_log_tail( 5 ),
		);
	}

	/**
	 * Атомарный захват окна батча (только GET_LOCK).
	 *
	 * Смешанные локи (GET_LOCK + add_option-фолбэк) не давали взаимного
	 * исключения: второй воркер мог взять add_option-лок, пока первый
	 * держал GET_LOCK, и окна файлов пересекались. Теперь единственный
	 * механизм — GET_LOCK; при недоступности воркер отвечает busy.
	 *
	 * @return bool
	 */
	public function acquire_batch_lock() {
		global $wpdb;

		$ok = $wpdb->get_var( $wpdb->prepare( "SELECT GET_LOCK( %s, %d )", 'wp_cloud_sync_batch', 10 ) );

		return '1' === (string) $ok;
	}

	/**
	 * Освобождение захвата батча.
	 */
	public function release_batch_lock() {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK( %s )", 'wp_cloud_sync_batch' ) );
	}

	/**
	 * Запись md5 в хранилище хешей (слияние, чтобы параллельные батчи не теряли записи).
	 *
	 * @param string $rel Относительный путь.
	 * @param string $md5 Хеш.
	 */
	public function set_hash( $rel, $md5 ) {
		$this->acquire_batch_lock();
		$hashes = $this->option_fresh( self::OPTION_HASHES, array() );
		if ( ! is_array( $hashes ) ) {
			$hashes = array();
		}
		$hashes[ $rel ] = $md5;
		update_option( self::OPTION_HASHES, $hashes );
		$this->release_batch_lock();
	}

	/**
	 * Загрузка файла на Яндекс.Диск целиком (один PUT-запрос).
	 *
	 * @param string $path   Локальный путь.
	 * @param string $remote Путь на диске (/например/файл).
	 * @param string $token  OAuth-токен.
	 * @return bool
	 */
	public function upload_file( $path, $remote, $token ) {
		$dir = dirname( $remote );
		if ( '/' !== $dir && ! $this->make_dirs( $dir, $token ) ) {
			return false;
		}

		$href = $this->get_upload_href( $remote, $token );
		if ( ! $href ) {
			return false;
		}

		$size = @filesize( $path );
		if ( false === $size ) {
			$this->log( sprintf( __( 'Не удалось получить размер файла %s.', 'wp-cloud-sync' ), $path ), 'error' );
			return false;
		}

		$resp = $this->http_request(
			'PUT',
			$href,
			array(
				'headers' => array( 'Content-Length: ' . $size ),
				'file'    => $path,
			)
		);

		// 201 — сохранён, 202 — принят (загрузка в процессе).
		if ( ! in_array( $resp['code'], array( 201, 202 ), true ) ) {
			$this->log( sprintf( __( 'HTTP %d при загрузке %s.', 'wp-cloud-sync' ), $resp['code'], basename( $path ) ), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * Получение ссылки на загрузку файла.
	 *
	 * @param string $remote Удалённый путь.
	 * @param string $token  OAuth-токен.
	 * @return string|false
	 */
	public function get_upload_href( $remote, $token ) {
		$url = add_query_arg(
			array(
				'path'      => $remote,
				'overwrite' => 'true',
			),
			self::DISK_API_URL . '/resources/upload'
		);

		$resp = $this->http_request(
			'GET',
			$url,
			array( 'headers' => array( 'Authorization: OAuth ' . $token ) )
		);

		if ( 200 !== $resp['code'] || empty( $resp['body']['href'] ) ) {
			$this->log( sprintf( __( 'Не удалось получить ссылку загрузки для %s (HTTP %d).', 'wp-cloud-sync' ), $remote, $resp['code'] ) );
			return false;
		}

		return $resp['body']['href'];
	}

	/**
	 * Создание директории на Диске (рекурсивно, по одной).
	 *
	 * HTTP 423 («Ресурс заблокирован») возникает, когда параллельные воркеры
	 * одновременно создают одну и ту же папку — в этом случае ждём и повторяем.
	 *
	 * @param string $dir   Путь к папке.
	 * @param string $token OAuth-токен.
	 * @return bool
	 */
	public function make_dirs( $dir, $token ) {
		$parts = array_filter( explode( '/', $dir ), 'strlen' );
		$path  = '';

		foreach ( $parts as $part ) {
			$path .= '/' . $part;

			$code = 0;
			$body = '';
			for ( $attempt = 0; $attempt < 4; $attempt++ ) {
				if ( $attempt > 0 ) {
					usleep( 500000 * $attempt ); // 0.5с, 1с, 1.5с.
				}
				$resp = $this->http_request(
					'PUT',
					add_query_arg( array( 'path' => $path ), self::DISK_API_URL . '/resources' ),
					array( 'headers' => array( 'Authorization: OAuth ' . $token ) )
				);
				$code = $resp['code'];
				$body = $resp['body'];
				// 201 — создана, 409 — уже существует.
				if ( in_array( $code, array( 201, 409 ), true ) ) {
					break;
				}
				// 423 — папку создаёт другой воркер: повторяем.
				if ( 423 !== $code ) {
					break;
				}
			}

			if ( ! in_array( $code, array( 201, 409 ), true ) ) {
				$reason = is_array( $body ) && ! empty( $body['message'] ) ? $body['message'] : ( is_scalar( $body ) ? $body : '' );
				$this->log(
					sprintf(
						__( 'Не удалось создать папку %s: HTTP %d%s.', 'wp-cloud-sync' ),
						$path,
						$code,
						$reason ? ' — ' . $reason : ''
					),
					'error'
				);
				return false;
			}
		}

		return true;
	}

	/**
	 * Удалённый путь файла на Диске (корень из настроек remote_path).
	 *
	 * @param string $rel Относительный путь (с ABSPATH).
	 * @return string
	 */
	public function remote_path_for( $rel ) {
		$rel = ltrim( $rel, '/' );
		$root = trim( (string) $this->get_settings()['remote_path'] );
		if ( '' === $root ) {
			$root = '/wp-cloud-sync';
		}
		return '/' . trim( $root, '/' ) . '/' . $rel;
	}

	/**
	 * Корень синхронизации на Диске (из настройки remote_path).
	 *
	 * @return string
	 */
	public function remote_root() {
		$path = trim( (string) $this->get_settings()['remote_path'] );
		if ( '' === $path ) {
			$path = '/wp-cloud-sync';
		}
		return '/' . trim( $path, '/' );
	}

	/**
	 * Список дампов базы данных в облаке (db_dump_*.sql), свежие первыми.
	 *
	 * @param string $token OAuth-токен.
	 * @return array
	 */
	public function list_db_dumps( $token ) {
		$url = add_query_arg(
			array( 'path' => $this->remote_root(), 'limit' => 1000 ),
			self::DISK_API_URL . '/resources'
		);

		$resp = $this->http_request(
			'GET',
			$url,
			array( 'headers' => array( 'Authorization: OAuth ' . $token ) )
		);

		if ( 200 !== $resp['code'] || ! is_array( $resp['body'] ) || empty( $resp['body']['_embedded']['items'] ) ) {
			return array();
		}

		$dumps = array();
		foreach ( $resp['body']['_embedded']['items'] as $item ) {
			if ( empty( $item['type'] ) || 'file' !== $item['type'] || empty( $item['name'] ) ) {
				continue;
			}
			if ( 1 === preg_match( '/^db_dump_\d{8}_\d{6}\.sql$/', $item['name'] ) ) {
				$dumps[ $item['name'] ] = array(
					'md5'      => isset( $item['md5'] ) ? (string) $item['md5'] : '',
					'size'     => isset( $item['size'] ) ? (int) $item['size'] : 0,
					'modified' => isset( $item['modified'] ) ? (string) $item['modified'] : '',
				);
			}
		}

		krsort( $dumps );
		return $dumps;
	}

	/**
	 * Список дампов для интерфейса (action: wp_ajax_wp_cloud_sync_db_list).
	 */
	public function ajax_db_list() {
		check_ajax_referer( 'wp_cloud_sync_db', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'wp-cloud-sync' ) ) );
		}

		$settings = $this->get_settings();
		if ( empty( $settings['yandex_access_token'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Нет токена доступа. Сначала получите токен.', 'wp-cloud-sync' ) ) );
		}

		wp_send_json_success( array( 'dumps' => $this->list_db_dumps( $settings['yandex_access_token'] ) ) );
	}

	/**
	 * Экспорт БД в облако (action: wp_ajax_wp_cloud_sync_db_export).
	 */
	public function handle_db_export() {
		check_ajax_referer( 'wp_cloud_sync_db', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'wp-cloud-sync' ) ) );
		}

		$settings = $this->get_settings();
		if ( empty( $settings['yandex_access_token'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Нет токена доступа. Сначала получите токен.', 'wp-cloud-sync' ) ) );
		}

		$result = $this->db_export( $settings['yandex_access_token'] );
		wp_send_json_success( $result );
	}

	/**
	 * Создание дампа базы и загрузка его в облако.
	 *
	 * Дамп загружается в корень remote_path под именем db_dump_YYYYMMDD_HHMMSS.sql.
	 * Если свежайший дамп в облаке имеет тот же md5 — загрузка пропускается
	 * (данные не изменились).
	 *
	 * @param string $token OAuth-токен.
	 * @return array
	 */
	public function db_export( $token ) {
		@set_time_limit( 0 );

		$tmp = get_temp_dir() . 'wpcs-db-' . wp_generate_password( 8, false ) . '.sql';

		$dump = $this->create_sql_dump( $tmp );
		if ( is_wp_error( $dump ) ) {
			@unlink( $tmp );
			return array(
				'success' => false,
				'message' => $dump->get_error_message(),
				'log'     => $this->get_log_tail( 30 ),
			);
		}

		$size = (int) @filesize( $tmp );
		$md5  = md5_file( $tmp );
		$this->log( sprintf( __( 'Дамп БД создан: %d КБ, md5 %s.', 'wp-cloud-sync' ), (int) round( $size / 1024 ), $md5 ) );

		$dumps       = $this->list_db_dumps( $token );
		$newest_name = $dumps ? key( $dumps ) : '';
		$newest_md5  = ( '' !== $newest_name && ! empty( $dumps[ $newest_name ]['md5'] ) ) ? $dumps[ $newest_name ]['md5'] : '';

		if ( '' !== $newest_md5 && strcasecmp( $md5, $newest_md5 ) === 0 ) {
			@unlink( $tmp );
			$this->log( sprintf( __( 'Дамп не изменился (%s) — загрузка пропущена.', 'wp-cloud-sync' ), $newest_name ) );
			return array(
				'success' => true,
				'skipped' => true,
				'message' => __( 'Дамп не изменился — загрузка в облако пропущена.', 'wp-cloud-sync' ),
				'log'     => $this->get_log_tail( 30 ),
			);
		}

		$name   = 'db_dump_' . current_time( 'Ymd_His' ) . '.sql';
		$remote = $this->remote_root() . '/' . $name;

		if ( ! $this->upload_file( $tmp, $remote, $token ) ) {
			@unlink( $tmp );
			return array(
				'success' => false,
				'message' => sprintf( __( 'Не удалось загрузить дамп %s в облако.', 'wp-cloud-sync' ), $name ),
				'log'     => $this->get_log_tail( 30 ),
			);
		}

		@unlink( $tmp );
		$this->log( sprintf( __( 'Дамп БД загружен: %s (md5 %s).', 'wp-cloud-sync' ), $remote, $md5 ) );

		return array(
			'success' => true,
			'skipped' => false,
			'message' => sprintf( __( 'Дамп БД загружен в облако: %s.', 'wp-cloud-sync' ), $name ),
			'log'     => $this->get_log_tail( 30 ),
		);
	}

	/**
	 * Создание SQL-дампа (mysqldump; при недоступности — средствами PHP).
	 *
	 * @param string $path Куда писать дамп.
	 * @return true|\WP_Error
	 */
	private function create_sql_dump( $path ) {
		$bin = $this->find_db_binary( array( 'mysqldump', 'mysqldump.exe' ) );

		if ( $bin ) {
			list( $host, $port ) = $this->db_host_port();
			$cmd = escapeshellarg( $bin )
				. ' --no-tablespaces --single-transaction --quick --hex-blob --default-character-set=utf8mb4'
				. ' -h ' . escapeshellarg( $host )
				. ( '' !== $port ? ' -P ' . escapeshellarg( $port ) : '' )
				. ' -u ' . escapeshellarg( DB_USER )
				. ' -p' . escapeshellarg( DB_PASSWORD )
				. ' ' . escapeshellarg( DB_NAME )
				. ' > ' . escapeshellarg( $path ) . ' 2>&1';

			@exec( $cmd, $output, $code );
			if ( 0 === $code && is_file( $path ) && filesize( $path ) > 0 ) {
				return true;
			}
			$this->log( __( 'mysqldump не отработал — используем дамп средствами PHP.', 'wp-cloud-sync' ) );
		}

		$error = $this->php_create_sql_dump( $path );
		if ( is_wp_error( $error ) ) {
			@unlink( $path );
			return $error;
		}

		return true;
	}

	/**
	 * Дамп БД средствами PHP через $wpdb (чанками по 500 строк).
	 *
	 * @param string $path Куда писать дамп.
	 * @return true|\WP_Error
	 */
	private function php_create_sql_dump( $path ) {
		global $wpdb;

		$fp = @fopen( $path, 'wb' );
		if ( false === $fp ) {
			return new \WP_Error( 'wpcs_dump', sprintf( __( 'Не удалось открыть %s для записи дампа.', 'wp-cloud-sync' ), $path ) );
		}

		$tables = $wpdb->get_col(
			"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
		);

		if ( null === $tables ) {
			fclose( $fp );
			return new \WP_Error( 'wpcs_dump', __( 'Не удалось получить список таблиц базы.', 'wp-cloud-sync' ) );
		}

		fwrite( $fp, "-- WP Cloud Sync dump\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n" );

		foreach ( $tables as $table ) {
			$table_esc = str_replace( '`', '``', $table );

			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table_esc}`", ARRAY_N );
			if ( null === $create || empty( $create[1] ) ) {
				fclose( $fp );
				return new \WP_Error( 'wpcs_dump', sprintf( __( 'Не удалось получить структуру таблицы %s.', 'wp-cloud-sync' ), $table ) );
			}

			fwrite( $fp, "DROP TABLE IF EXISTS `{$table_esc}`;\n" . $create[1] . ";\n" );

			$offset = 0;
			$chunk  = 500;
			do {
				$rows = $wpdb->get_results( "SELECT * FROM `{$table_esc}` LIMIT {$offset}, {$chunk}", ARRAY_N );
				if ( null === $rows ) {
					fclose( $fp );
					return new \WP_Error( 'wpcs_dump', sprintf( __( 'Ошибка чтения таблицы %s.', 'wp-cloud-sync' ), $table ) );
				}

				$batch = array();
				foreach ( $rows as $row ) {
					$values = array();
					foreach ( $row as $value ) {
						if ( null === $value ) {
							$values[] = 'NULL';
						} else {
							$values[] = "'" . $wpdb->_real_escape( $value ) . "'";
						}
					}
					$batch[] = '(' . implode( ',', $values ) . ')';
					if ( count( $batch ) >= 100 ) {
						fwrite( $fp, "INSERT INTO `{$table_esc}` VALUES " . implode( ',', $batch ) . ";\n" );
						$batch = array();
					}
				}
				if ( $batch ) {
					fwrite( $fp, "INSERT INTO `{$table_esc}` VALUES " . implode( ',', $batch ) . ";\n" );
				}

				$offset += $chunk;
			} while ( count( $rows ) === $chunk );
		}

		fwrite( $fp, "SET FOREIGN_KEY_CHECKS=1;\n" );
		fclose( $fp );

		return true;
	}

	/**
	 * Применение SQL-дампа к текущей базе (mysql CLI; при недоступности — PHP).
	 *
	 * @param string $path Путь к файлу дампа.
	 * @return array
	 */
		/**
	 * Prepare a DB dump for import: replace source URLs with the target URL.
	 *
	 * Reads the SQL dump, auto-detects old site URLs (or uses manually
	 * configured ones), replaces every occurrence with the current
	 * site URL, and correctly fixes serialized PHP string lengths.
	 * The modified SQL is written back to the same file (in-place).
	 *
	 * @param string $path Path to the SQL dump file.
	 * @return void
	 */
	private function prepare_db_dump( $path ) {
		$settings = $this->get_settings();

		// A dump must be imported byte-for-byte unless URL replacement was
		// explicitly enabled for this receiving site.
		if ( empty( $settings['replace_db_urls'] ) ) {
			return;
		}

		$new_url = ! empty( $settings['target_url'] )
			? untrailingslashit( trim( $settings['target_url'] ) )
			: untrailingslashit( home_url() );

		if ( empty( $new_url ) ) {
			return;
		}

		$old_urls = array();

		if ( ! empty( $settings['source_urls'] ) ) {
			$configured = array_filter( array_map( 'trim', explode( "\n", $settings['source_urls'] ) ) );
			foreach ( $configured as $url ) {
				if ( '' !== $url ) {
					$old_urls[ $url ] = true;
				}
			}
		}

		if ( empty( $old_urls ) ) {
			$detected = $this->detect_old_urls( $path );
			foreach ( $detected as $url ) {
				$old_urls[ $url ] = true;
			}
		}

		// Never replace the target URL with itself.
		unset( $old_urls[ $new_url ] );

		if ( empty( $old_urls ) ) {
			return;
		}

		$old_urls = array_keys( $old_urls );

		// Sort by length descending so longer URLs are replaced first
		// (e.g. https://host.com/path before https://host.com).
		usort( $old_urls, function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );

		$sql = (string) @file_get_contents( $path );
		if ( '' === $sql ) {
			return;
		}

		$sql = $this->replace_urls_in_sql( $sql, $old_urls, $new_url );

		@file_put_contents( $path, $sql );

		$this->log( '[DB] ' . sprintf(
			'URL replacement: %s → %s',
			implode( ', ', $old_urls ),
			$new_url
		) );
	}

	/**
	 * Обнаружение старых адресов сайта в SQL-дампе.
	 *
	 * Сканирует siteurl/home в таблице опций текущего префикса и user_url
	 * в его таблице пользователей. Таблицы других установок внутри дампа
	 * (например, стейджинг WPvivid wpvividfresh01_*) не учитываются,
	 * чтобы не менять их адреса. Для URL с путём добавляются родительские
	 * домены (например, https://host.com/path → https://host.com).
	 *
	 * @param string $path Путь к файлу дампа.
	 * @return array Уникальные старые адреса.
	 */
	private function detect_old_urls( $path ) {
		global $wpdb;

		$sql = (string) @file_get_contents( $path );
		if ( '' === $sql ) {
			return array();
		}

		$urls = array();

		// Сканируем только INSERT текущего префикса (до следующего INSERT).
		foreach ( array( $wpdb->prefix . 'options', $wpdb->prefix . 'users' ) as $table ) {
			$start = strpos( $sql, 'INSERT INTO `' . $table . '`' );
			if ( false === $start ) {
				continue;
			}

			$rest  = substr( $sql, $start );
			$next  = strpos( $rest, 'INSERT INTO `', strlen( 'INSERT INTO `' . $table . '`' ) );
			$chunk = ( false === $next ) ? $rest : substr( $rest, 0, $next );

			if ( $wpdb->prefix . 'options' === $table ) {
				// Extract siteurl and home from the options table.
				foreach ( array( 'siteurl', 'home' ) as $option ) {
					if ( preg_match_all( "/'" . preg_quote( $option, '/' ) . "',\s*'([^']+)'/", $chunk, $m ) ) {
						foreach ( $m[1] as $url ) {
							if ( '' !== $url ) {
								$urls[ $url ] = true;
							}
						}
					}
				}
			} else {
				// Extract user_url from the users table:
				// INSERT INTO `prefix_users` VALUES (id, 'login', 'pass', 'nicename', 'email', 'url', ...)
				if ( preg_match_all( "/VALUES\s*\(\s*\d+\s*,\s*'[^']*'\s*,\s*'[^']*'\s*,\s*'[^']*'\s*,\s*'[^']*'\s*,\s*'([^']*)'/", $chunk, $m ) ) {
					foreach ( $m[1] as $url ) {
						if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
							$urls[ $url ] = true;
						}
					}
				}
			}
		}

		// Add parent domains for any URL that has a path.
		$to_add = array();
		foreach ( $urls as $url => $true ) {
			$parsed = parse_url( $url );
			if ( isset( $parsed['scheme'], $parsed['host'] ) ) {
				$base = $parsed['scheme'] . '://' . $parsed['host'];
				if ( $base !== $url && ! isset( $urls[ $base ] ) ) {
					$to_add[ $base ] = true;
				}
			}
		}
		foreach ( $to_add as $url => $true ) {
			$urls[ $url ] = true;
		}

		return array_keys( $urls );
	}

	/**
	 * Replace old URLs with the new URL in SQL dump content.
	 *
	 * Handles both plain SQL strings and PHP serialized data.
	 * For serialized strings (s:LENGTH:"CONTENT" or s:LENGTH:\"CONTENT\")
	 * the LENGTH is recalculated after replacement to keep the data valid.
	 *
	 * @param string $sql        SQL dump content.
	 * @param array  $old_urls   Array of old URLs to replace (longest first).
	 * @param string $new_url    The replacement URL.
	 * @return string
	 */
	private function replace_urls_in_sql( $sql, $old_urls, $new_url ) {
		if ( empty( $old_urls ) || empty( $new_url ) ) {
			return $sql;
		}

		$replacements = array_fill( 0, count( $old_urls ), $new_url );

		// --- Pass 1: fix serialized PHP string lengths ---
		// mysqldump escapes double-quotes inside single-quoted SQL strings
		// as \" so we match that escaped form.
		$sql = preg_replace_callback(
			'/s:(\d+):\\\\"(.+?)\\\\"/s',
			function ( $m ) use ( $old_urls, $replacements ) {
				$content   = $m[2];
				$replaced  = str_replace( $old_urls, $replacements, $content );
				if ( $replaced !== $content ) {
					return 's:' . strlen( $replaced ) . ':\\"' . $replaced . '\\"';
				}
				return $m[0];
			},
			$sql
		);

		// Also handle non-escaped serialized strings (less common).
		$sql = preg_replace_callback(
			'/s:(\d+):"(.+?)"/',
			function ( $m ) use ( $old_urls, $replacements ) {
				$content   = $m[2];
				$replaced  = str_replace( $old_urls, $replacements, $content );
				if ( $replaced !== $content ) {
					return 's:' . strlen( $replaced ) . ':"' . $replaced . '"';
				}
				return $m[0];
			},
			$sql
		);

		// --- Pass 2: simple replacement for non-serialized URLs ---
		foreach ( $old_urls as $old ) {
			if ( '' !== $old ) {
				$sql = str_replace( $old, $new_url, $sql );
			}
		}

		return $sql;
	}

	/**
	 * Применение SQL-дампа к текущей базе (mysql CLI; при недоступности — PHP).
	 *
	 * @param string $path Путь к файлу дампа.
	 * @return array
	 */
	public function apply_db_dump( $path ) {
		// URL replacement is opt-in; otherwise the downloaded dump is imported unchanged.
		$this->prepare_db_dump( $path );

		$bin = $this->find_db_binary( array( 'mysql', 'mysql.exe' ) );

		if ( $bin ) {
			list( $host, $port ) = $this->db_host_port();
			$cmd = escapeshellarg( $bin )
				. ' -h ' . escapeshellarg( $host )
				. ( '' !== $port ? ' -P ' . escapeshellarg( $port ) : '' )
				. ' -u ' . escapeshellarg( DB_USER )
				. ' -p' . escapeshellarg( DB_PASSWORD )
				. ' --default-character-set=utf8mb4'
				. ' ' . escapeshellarg( DB_NAME )
				. ' < ' . escapeshellarg( $path ) . ' 2>&1';

			@exec( $cmd, $output, $code );
			if ( 0 === $code ) {
				$this->repair_legacy_tokens();
				return array(
					'success'    => true,
					'statements' => 0,
					'errors'     => 0,
					'message'    => __( 'импорт через mysql выполнен успешно', 'wp-cloud-sync' ),
				);
			}
		}

		$result = $this->php_apply_db_dump( $path );
		if ( ! empty( $result['success'] ) ) {
			$this->repair_legacy_tokens();
		}
		return $result;
	}

	/**
	 * Восстановление '%' на месте устаревшего плейсхолдера.
	 *
	 * Старые версии плагина при импорте БД заменяли каждый символ '%'
	 * токеном self::LEGACY_TOKEN и не всегда возвращали '%' обратно.
	 * Метод безопасен и идемпотентен: на «чистой» базе ничего не меняет.
	 *
	 * @return array Карта «таблица.колонка => затронуто строк».
	 */
	public function repair_legacy_tokens() {
		global $wpdb;

		$token  = self::LEGACY_TOKEN;
		$tables = array(
			"{$wpdb->prefix}posts"   => array( 'post_name', 'guid' ),
			"{$wpdb->prefix}terms"   => array( 'slug' ),
			"{$wpdb->prefix}options" => array( 'option_value' ),
		);

		$affected = array();
		foreach ( $tables as $table => $cols ) {
			foreach ( $cols as $col ) {
				$query = $wpdb->prepare(
					"UPDATE `{$table}` SET `{$col}` = REPLACE( `{$col}`, %s, '%%' ) WHERE `{$col}` LIKE %s",
					$token,
					'%' . $wpdb->esc_like( $token ) . '%'
				);
				$wpdb->query( $query );
				if ( $wpdb->rows_affected > 0 ) {
					$affected[ $table . '.' . $col ] = (int) $wpdb->rows_affected;
				}
			}
		}

		if ( $affected ) {
			$parts = array();
			foreach ( $affected as $where => $count ) {
				$parts[] = $where . ' (' . $count . ')';
			}
			$this->log( '[DB] ' . __( 'Восстановлен "%" вместо устаревшего плейсхолдера: ', 'wp-cloud-sync' ) . implode( ', ', $parts ) );
		}

		return $affected;
	}

	/**
	 * Проверка и починка текущей базы данных (кнопка в консоли).
	 *
	 * 1. Восстанавливает '%' вместо устаревшего плейсхолдера (если есть).
	 * 2. Если задан target_url — приводит siteurl/home к нему.
	 * 3. Сбрасывает rewrite rules для перегенерации постоянных ссылок.
	 *
	 * @return array
	 */
	public function repair_db() {
		$affected = $this->repair_legacy_tokens();

		$settings = $this->get_settings();
		$target   = ! empty( $settings['target_url'] ) ? untrailingslashit( trim( $settings['target_url'] ) ) : '';

		$url_fixed = false;
		if ( '' !== $target && false !== filter_var( $target, FILTER_VALIDATE_URL ) ) {
			$current_siteurl = untrailingslashit( (string) $this->option_fresh( 'siteurl' ) );
			$current_home    = untrailingslashit( (string) $this->option_fresh( 'home' ) );
			if ( $target !== $current_siteurl || $target !== $current_home ) {
				update_option( 'siteurl', $target );
				update_option( 'home', $target );
				$url_fixed = true;
			}
		}

		delete_option( 'rewrite_rules' );

		$this->log( '[DB] ' . sprintf(
			__( 'Починка БД: плейсхолдеров исправлено %d, адреса приведены к target_url: %s.', 'wp-cloud-sync' ),
			array_sum( $affected ),
			$url_fixed ? __( 'да', 'wp-cloud-sync' ) : __( 'нет', 'wp-cloud-sync' )
		) );

		return array(
			'fixed_tokens' => $affected,
			'tokens_total' => array_sum( $affected ),
			'url_fixed'    => $url_fixed,
			'target_url'   => $target,
		);
	}

	/**
	 * Починка БД (action: wp_ajax_wp_cloud_sync_repair_db).
	 */
	public function ajax_repair_db() {
		check_ajax_referer( 'wp_cloud_sync_repair_db', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'wp-cloud-sync' ) ) );
		}

		$result = $this->repair_db();

		if ( $result['fixed_tokens'] ) {
			$parts = array();
			foreach ( $result['fixed_tokens'] as $where => $count ) {
				$parts[] = $where . ': ' . $count;
			}
			$message = __( 'Починка выполнена: ', 'wp-cloud-sync' ) . implode( ', ', $parts );
		} else {
			$message = __( 'Повреждений не найдено.', 'wp-cloud-sync' );
		}

		if ( $result['url_fixed'] ) {
			$message .= ' ' . sprintf( __( 'Адреса приведены к %s.', 'wp-cloud-sync' ), $result['target_url'] );
		}

		wp_send_json_success( array(
			'message' => $message,
			'result'  => $result,
			'log'     => $this->get_log_tail( 30 ),
		) );
	}

	/**
	 * Применение дампа средствами PHP (разбор операторов и выполнение через $wpdb).
	 *
	 * @param string $path Путь к файлу дампа.
	 * @return array
	 */
	private function php_apply_db_dump( $path ) {
		global $wpdb;

		$size = (int) @filesize( $path );
		$ini  = (int) ini_get( 'memory_limit' );

		if ( $size > 209715200 && -1 !== $ini && 0 !== $ini && $size > $ini * 1048576 ) {
			return array(
				'success'    => false,
				'statements' => 0,
				'errors'     => 1,
				'message'    => __( 'дамп слишком большой для импорта средствами PHP — требуется бинарник mysql', 'wp-cloud-sync' ),
			);
		}

		@ini_set( 'memory_limit', '512M' );

		$sql = (string) @file_get_contents( $path );
		if ( '' === $sql ) {
			return array(
				'success'    => false,
				'statements' => 0,
				'errors'     => 1,
				'message'    => __( 'дамп пуст или не читается', 'wp-cloud-sync' ),
			);
		}

		$statements = $this->split_sql_statements( $sql );
		$errors     = 0;
		$first      = '';

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );

		foreach ( $statements as $stmt ) {
			if ( false === $wpdb->query( $stmt ) ) {
				$errors++;
				if ( '' === $first ) {
					$first = trim( str_replace( "\n", ' ', $stmt ) );
				}
			}
		}

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );

		if ( $errors > 0 ) {
			return array(
				'success'    => false,
				'statements' => count( $statements ),
				'errors'     => $errors,
				'message'    => sprintf(
					__( 'ошибок при импорте: %d из %d (первая: %s...)', 'wp-cloud-sync' ),
					$errors,
					count( $statements ),
					mb_substr( $first, 0, 120 )
				),
			);
		}

		return array(
			'success'    => true,
			'statements' => count( $statements ),
			'errors'     => 0,
			'message'    => sprintf( __( 'импортировано операторов: %d', 'wp-cloud-sync' ), count( $statements ) ),
		);
	}

	/**
	 * Разбивка SQL-текста на операторы с учётом кавычек, комментариев,
	 * версионных комментариев /*!...*​/ и директив DELIMITER.
	 *
	 * @param string $sql Текст дампа.
	 * @return array
	 */
	private function split_sql_statements( $sql ) {
		$statements = array();
		$len        = strlen( $sql );
		$i          = 0;
		$delimiter  = ';';
		$dlen       = 1;
		$buf        = '';

		$sq = $dq = $bt = false;
		$line_comment = $block_comment = $exec_comment = false;
		$esc          = false;
		$line_start   = true;

		while ( $i < $len ) {
			$c  = $sql[ $i ];
			$n1 = ( $i + 1 < $len ) ? $sql[ $i + 1 ] : '';
			$n2 = ( $i + 2 < $len ) ? $sql[ $i + 2 ] : '';

			if ( $line_start && ! $sq && ! $dq && ! $bt && ! $block_comment && ! $exec_comment && ! $line_comment ) {
				if ( 1 === preg_match( '/^\s*DELIMITER\s+(\S+)/i', substr( $sql, $i ), $mm ) ) {
					$delimiter = $mm[1];
					$dlen      = strlen( $delimiter );
					$eol       = strpos( $sql, "\n", $i );
					$i         = ( false === $eol ) ? $len : $eol + 1;
					$buf       = '';
					$line_start = true;
					continue;
				}
			}

			if ( $line_comment ) {
				if ( "\n" === $c ) {
					$line_comment = false;
					$line_start   = true;
				}
				$i++;
				continue;
			}

			if ( $block_comment ) {
				if ( '*' === $c && '/' === $n1 ) {
					$block_comment = false;
					$i += 2;
				} else {
					$i++;
				}
				continue;
			}

			if ( $exec_comment ) {
				if ( '*' === $c && '/' === $n1 ) {
					$exec_comment = false;
					$buf .= ' ';
					$i += 2;
				} else {
					$buf .= $c;
					$i++;
				}
				continue;
			}

			if ( ! $sq && ! $dq && ! $bt ) {
				if ( '-' === $c && '-' === $n1 && ( '' === $n2 || ' ' === $n2 || "\t" === $n2 || "\n" === $n2 ) ) {
					$line_comment = true;
					$i += 2;
					continue;
				}
				if ( '#' === $c ) {
					$line_comment = true;
					$i++;
					continue;
				}
				if ( '/' === $c && '*' === $n1 && '!' === $n2 ) {
					$exec_comment = true;
					$buf .= ' ';
					$i += 3;
					while ( $i < $len && ctype_digit( $sql[ $i ] ) ) {
						$i++;
					}
					continue;
				}
				if ( '/' === $c && '*' === $n1 ) {
					$block_comment = true;
					$i += 2;
					continue;
				}
				if ( "'" === $c ) {
					$sq = true;
					$buf .= $c;
					$i++;
					continue;
				}
				if ( '"' === $c ) {
					$dq = true;
					$buf .= $c;
					$i++;
					continue;
				}
				if ( '`' === $c ) {
					$bt = true;
					$buf .= $c;
					$i++;
					continue;
				}
			} elseif ( $sq || $dq ) {
				if ( '\\' === $c ) {
					$esc = ! $esc;
					$buf .= $c;
					$i++;
					continue;
				}
				if ( $esc ) {
					$esc = false;
					$buf .= $c;
					$i++;
					continue;
				}
				if ( $sq && "'" === $c ) {
					$sq = false;
					$buf .= $c;
					$i++;
					continue;
				}
				if ( $dq && '"' === $c ) {
					$dq = false;
					$buf .= $c;
					$i++;
					continue;
				}
			} elseif ( $bt && '`' === $c ) {
				$bt = false;
				$buf .= $c;
				$i++;
				continue;
			}

			if ( ! $sq && ! $dq && ! $bt && 0 === substr_compare( $sql, $delimiter, $i, $dlen ) ) {
				$stmt = trim( $buf );
				if ( '' !== $stmt ) {
					$statements[] = $stmt;
				}
				$buf = '';
				$i += $dlen;
				$line_start = true;
				continue;
			}

			$line_start = ( "\n" === $c );
			$buf .= $c;
			$i++;
		}

		$stmt = trim( $buf );
		if ( '' !== $stmt ) {
			$statements[] = $stmt;
		}

		return $statements;
	}

	/**
	 * Поиск бинарника mysqldump/mysql в PATH.
	 *
	 * @param array $names Имена бинарников.
	 * @return string|false
	 */
	private function find_db_binary( $names ) {
		if ( ! function_exists( 'exec' ) || preg_match( '/(^|,)exec(,|$)/', (string) ini_get( 'disable_functions' ) ) ) {
			return false;
		}

		foreach ( (array) $names as $name ) {
			$out = array();
			@exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null', $out, $code );
			if ( 0 === $code && ! empty( $out ) && '' !== trim( $out[0] ) ) {
				return trim( $out[0] );
			}

			$out = array();
			@exec( 'where ' . $name . ' 2>nul', $out, $code );
			if ( 0 === $code && ! empty( $out ) && '' !== trim( $out[0] ) ) {
				return trim( $out[0] );
			}
		}

		return false;
	}

	/**
	 * Хост и порт MySQL из DB_HOST (host:port).
	 *
	 * @return array
	 */
	private function db_host_port() {
		$host = (string) DB_HOST;
		$port = '';

		if ( false !== strpos( $host, ':' ) ) {
			list( $host, $port ) = explode( ':', $host, 2 );
			if ( ! ctype_digit( $port ) ) {
				$port = '';
			}
		}

		if ( '' === $host ) {
			$host = 'localhost';
		}

		return array( $host, $port );
	}

	/**
	 * Сбор списка файлов сайта (без исключённых директорий).
	 *
	 * @return array
	 */
	public function build_file_list() {
		$files    = array();
		$root     = $this->local_root();
		$excluded = $this->get_excluded_dirs();

		$scopes = array();
		$theme  = $this->active_theme_dir();
		if ( $theme ) {
			$scopes[] = $theme;
		}
		foreach ( $this->custom_plugin_dirs() as $plugin ) {
			$scopes[] = $plugin;
		}

		$settings_now = $this->get_settings();

		// [2] Диагностика: реальные папки для настроенных scope.
		$theme_setting  = trim( (string) $settings_now['custom_theme'] );
		$plugin_setting = trim( (string) $settings_now['custom_plugins'] );

		if ( '' !== $theme_setting && ! is_dir( $root . 'wp-content/themes/' . sanitize_file_name( basename( $theme_setting ) ) ) ) {
			$this->log( 'Папка не найдена: wp-content/themes/' . sanitize_file_name( basename( $theme_setting ) ), 'warning' );
		}
		if ( '' !== $plugin_setting ) {
			foreach ( array_map( 'trim', explode( ',', $plugin_setting ) ) as $pname ) {
				$pname = sanitize_file_name( basename( $pname ) );
				if ( '' !== $pname && ! is_dir( $root . 'wp-content/plugins/' . $pname ) ) {
					$this->log( 'Папка не найдена: wp-content/plugins/' . $pname, 'warning' );
				}
			}
		}

		$theme_count   = 0;
		$plugin_count  = 0;

		foreach ( $scopes as $scope ) {
			$dir = $root . $scope;

			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
					function ( $current ) use ( $excluded, $dir, $scope ) {
						if ( $current->isDir() ) {
							$rel   = ltrim( str_replace( '\\', '/', substr( trailingslashit( $current->getPathname() ), strlen( $dir ) ) ), '/' );
							$full  = $scope . '/' . $rel;
							$parts = explode( '/', $full );

							// Жёсткое исключение: любая папка с таким именем на любом уровне.
							if ( array_intersect( $parts, self::HARD_EXCLUDED_DIR_NAMES ) ) {
								return false;
							}

							// Гибкая настройка «Исключённые папки» (полный путь или часть пути).
							foreach ( $excluded as $ex ) {
								if ( $full === $ex || 0 === strpos( $full, $ex . '/' ) || in_array( $ex, $parts, true ) ) {
									return false;
								}
							}
						}
						return true;
					}
				),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				if ( 'wp-cloud-sync-sync.log' === $file->getFilename() ) {
					continue;
				}
				// Статика (картинки) при локальном сканировании пропускается.
				$ext = $file->getExtension();
				if ( $this->is_local() && '' !== $ext && in_array( strtolower( '.' . $ext ), self::SKIP_EXTENSIONS, true ) ) {
					continue;
				}
				$rel     = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) ) ), '/' );
				$files[] = $scope . '/' . $rel;

				if ( 0 === strpos( $scope, 'wp-content/themes/' ) ) {
					$theme_count++;
				} elseif ( 0 === strpos( $scope, 'wp-content/plugins/' ) ) {
					$plugin_count++;
				}
			}
		}

		sort( $files );

		// [3] Диагностика: сколько файлов реально увидел сканер.
		$this->log( sprintf( 'Найдено %d файлов (тема: %d, плагин: %d).', count( $files ), $theme_count, $plugin_count ), 'info' );

		return array(
			'files'   => $files,
			'idx'     => 0,
			'done'    => false,
			'total'   => 0,
			'scope_hash' => $this->scope_hash(),
			'counters' => array(
				'scanned'     => 0,
				'up_to_date'  => 0,
				'uploaded'    => 0,
				'failed'      => 0,
				'skipped'     => 0,
			),
		);
	}

	/**
	 * Идёт ли локальное сканирование файловой системы
	 * (в отличие от удалённого обхода облака).
	 *
	 * build_file_list() всегда работает с локальным диском, поэтому
	 * фильтры «только для локального сканирования» (например, SKIP_EXTENSIONS)
	 * применяются через эту проверку.
	 *
	 * @return bool
	 */
	public function is_local() {
		return true;
	}

	/**
	 * Хеш настроек сканирования: при их изменении состояние считается устаревшим.
	 *
	 * @return string
	 */
	private function scope_hash() {
		$s = $this->get_settings();
		return md5( implode( "\n", array(
			(string) $s['custom_theme'],
			(string) $s['custom_plugins'],
			(string) $s['excluded_dirs'],
			(string) $s['local_root'],
			implode( ',', self::SKIP_EXTENSIONS ),
		) ) );
	}

	/**
	 * Папка темы (относительно корня сайта).
	 *
	 * Сканирование темы выполняется ТОЛЬКО если указано название в поле
	 * custom_theme. Пустое поле — тема не сканируется вовсе.
	 *
	 * @return string|null
	 */
	public function active_theme_dir() {
		$settings = $this->get_settings();
		$root     = $this->local_root();
		$choice   = trim( (string) $settings['custom_theme'] );

		if ( '' === $choice ) {
			return null;
		}

		$choice = sanitize_file_name( basename( $choice ) );

		if ( '' !== $choice && is_file( $root . 'wp-content/themes/' . $choice . '/style.css' ) ) {
			return 'wp-content/themes/' . $choice;
		}

		$this->log( sprintf( __( 'Тема %s не найдена (нет style.css) — тема не сканируется.', 'wp-cloud-sync' ), $choice ), 'error' );

		return null;
	}

	/**
	 * Папки кастомных плагинов.
	 *
	 * Сканирование плагинов выполняется ТОЛЬКО для папок, перечисленных
	 * в поле custom_plugins. Пустое поле — папка плагинов не сканируется вовсе.
	 *
	 * @return array
	 */
	public function custom_plugin_dirs() {
		$dirs        = array();
		$root        = $this->local_root();
		$plugins_dir = $root . 'wp-content/plugins';

		if ( ! is_dir( $plugins_dir ) ) {
			return $dirs;
		}

		$settings = $this->get_settings();
		$custom   = trim( (string) $settings['custom_plugins'] );

		// Пусто — плагины не трогаем.
		if ( '' === $custom ) {
			return $dirs;
		}

		foreach ( array_map( 'trim', explode( ',', $custom ) ) as $name ) {
			$name = sanitize_file_name( basename( $name ) );
			if ( '' === $name ) {
				continue;
			}
			$dir = $plugins_dir . '/' . $name;
			if ( is_dir( $dir ) ) {
				$dirs[] = 'wp-content/plugins/' . $name;
			}
		}

		return $dirs;
	}

	/**
	 * Чтение опции напрямую из БД (минуя кэш процесса).
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

	/**
	 * Отметка «воркер жив и работает».
	 *
	 * Пишем отдельную опцию атомарно, без блокировки: это единственный
	 * записываемый из параллельных воркеров ключ, который не участвует
	 * в гонках read-modify-write (в отличие от состояния, которое меняется
	 * только под локом).
	 */
	private function touch_heartbeat() {
		update_option( self::OPTION_HEARTBEAT, time(), false );
	}

	/**
	 * Текущее состояние синхронизации.
	 *
	 * Читаем напрямую из БД, минуя кэш опций: параллельные воркеры — это
	 * отдельные процессы, и их get_option() вернул бы устаревший кэш,
	 * из-за чего окна файлов пересекались бы между процессами.
	 *
	 * @return array
	 */
	public function get_state() {
		$state = $this->option_fresh( self::OPTION_STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Сохранение состояния синхронизации.
	 *
	 * @param array $state Состояние.
	 */
	public function save_state( $state ) {
		update_option( self::OPTION_STATE, $state, false );
	}

	/**
	 * Сброс состояния синхронизации.
	 */
	public function reset_state() {
		delete_option( self::OPTION_STATE );
		delete_option( self::OPTION_HEARTBEAT );
	}

	/**
	 * Стартовый массив счётчиков.
	 *
	 * @return array
	 */
	public function default_counters() {
		return array(
			'scanned'    => 0,
			'up_to_date' => 0,
			'uploaded'   => 0,
			'failed'     => 0,
			'skipped'    => 0,
		);
	}

	/**
	 * Проверка токена через запрос метаданных диска.
	 *
	 * @param string $token OAuth-токен.
	 * @return true|\WP_Error
	 */
	public function check_token( $token ) {
		$resp = $this->http_request(
			'GET',
			self::DISK_API_URL,
			array( 'headers' => array( 'Authorization: OAuth ' . $token ) )
		);

		if ( 200 === $resp['code'] ) {
			return true;
		}

		$reason = '';
		if ( is_array( $resp['body'] ) && ! empty( $resp['body']['message'] ) ) {
			$reason = ' — ' . $resp['body']['message'];
		} elseif ( 0 === $resp['code'] ) {
			$reason = ' — ' . ( is_scalar( $resp['body'] ) ? $resp['body'] : 'cURL' );
		}

		return new \WP_Error(
			'wpcs_token',
			sprintf( __( 'Стоп: токен недействителен (HTTP %d).%s Обновите токен на вкладке настроек.', 'wp-cloud-sync' ), $resp['code'], $reason )
		);
	}

	/**
	 * Запись строки в лог.
	 *
	 * @param string $message Сообщение.
	 * @param string $level   Уровень (info/error).
	 */
	public function log( $message, $level = 'info' ) {
		$line = sprintf( '[%s] [%s] %s', current_time( 'mysql' ), strtoupper( $level ), $message ) . PHP_EOL;
		$file = WP_CONTENT_DIR . '/uploads/wp-cloud-sync-sync.log';
		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Последние строки лога.
	 *
	 * @param int $lines Сколько строк.
	 * @return string
	 */
	public function get_log_tail( $lines = 20 ) {
		$file = WP_CONTENT_DIR . '/uploads/wp-cloud-sync-sync.log';
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
	 * Универсальный HTTP-запрос (cURL) для REST API Яндекс.Диска и OAuth.
	 *
	 * @param string $method HTTP-метод.
	 * @param string $url    URL.
	 * @param array  $opts   Доп. опции: body, headers.
	 * @return array{ code: int, body: array|string }
	 */
	public function http_request( $method, $url, $opts = array() ) {
		$ch = curl_init();
		curl_setopt_array( $ch, array(
			CURLOPT_URL            => $url,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION  => true,
			CURLOPT_TIMEOUT        => 120,
		) );

		$headers = isset( $opts['headers'] ) ? $opts['headers'] : array();
		$fp      = null;
		if ( isset( $opts['file'] ) ) {
			// Потоковая загрузка файла: не читаем его целиком в память.
			$fp = @fopen( $opts['file'], 'rb' );
			if ( false === $fp ) {
				return array( 'code' => 0, 'body' => 'Не удалось открыть файл: ' . $opts['file'] );
			}
			curl_setopt( $ch, CURLOPT_UPLOAD, true );
			curl_setopt( $ch, CURLOPT_INFILE, $fp );
			curl_setopt( $ch, CURLOPT_INFILESIZE, (int) @filesize( $opts['file'] ) );
		} elseif ( isset( $opts['body'] ) ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $opts['body'] );
		}
		if ( $headers ) {
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		}

		$raw     = curl_exec( $ch );
		$code    = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curl_er = curl_error( $ch );
		curl_close( $ch );
		if ( is_resource( $fp ) ) {
			fclose( $fp );
		}

		if ( false === $raw ) {
			return array( 'code' => 0, 'body' => $curl_er );
		}

		$decoded = json_decode( $raw, true );
		$body    = ( null === $decoded ) ? $raw : $decoded;

		return array( 'code' => $code, 'body' => $body );
	}

	/**
	 * Получение access token по OAuth-коду.
	 *
	 * @param string $code Код.
	 * @return string|\WP_Error
	 */
	public function exchange_code_for_token( $code ) {
		$settings = $this->get_settings();
		if ( ! $code || ! $settings['yandex_client_id'] || ! $settings['yandex_client_secret'] ) {
			return new \WP_Error( 'yandex_token', __( 'Нет Client ID, Secret или кода.', 'wp-cloud-sync' ) );
		}

		$resp = $this->http_request(
			'POST',
			self::YANDEX_TOKEN_URL,
			array(
				'headers' => array( 'Content-Type: application/x-www-form-urlencoded' ),
				'body'    => http_build_query( array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => $settings['yandex_client_id'],
					'client_secret' => $settings['yandex_client_secret'],
				) ),
			)
		);

		if ( 200 !== $resp['code'] ) {
			$msg = is_array( $resp['body'] ) && isset( $resp['body']['error_description'] ) ? $resp['body']['error_description'] : 'HTTP ' . $resp['code'];
			return new \WP_Error( 'yandex_token', $msg );
		}

		return isset( $resp['body']['access_token'] ) ? $resp['body']['access_token'] : new \WP_Error( 'yandex_token', __( 'Токен не получен.', 'wp-cloud-sync' ) );
	}

	/**
	 * Вывод страницы настроек.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings  = $this->get_settings();
		$auth_url  = $this->build_auth_url( $settings['yandex_client_id'] );
		$has_id    = (bool) $settings['yandex_client_id'];
		$has_token = (bool) $settings['yandex_access_token'];
		$error     = isset( $_GET['wpcs_error'] ) ? sanitize_text_field( wp_unslash( $_GET['wpcs_error'] ) ) : '';
		$ok        = isset( $_GET['wpcs_ok'] );
		$cleared   = isset( $_GET['cleared'] );
		$tab       = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'sync';
		$log_tail  = $this->get_log_tail( 20 );
		$db_log_tail = $this->get_log_tail( 30 );
		$opt_key   = self::OPTION_KEY;
		?>
		<style>
		.wpcs-wrap{max-width:1000px;margin:0 auto}
		.wpcs-wrap h1.wpcs-title{font-size:26px;font-weight:700;margin:8px 0 4px}
		.wpcs-tabs{display:flex;gap:4px;border-bottom:1px solid #e5e5e5;margin:14px 0 22px}
		.wpcs-tabs .wpcs-tab{padding:10px 18px;text-decoration:none;color:#555;font-weight:500;border-bottom:2px solid transparent;margin-bottom:-1px;transition:color .15s}
		.wpcs-tabs .wpcs-tab:hover{color:#2271b1}
		.wpcs-tabs .wpcs-tab.is-active{color:#2271b1;border-bottom-color:#2271b1;font-weight:600}
		.wpcs-card{background:#fff;border:1px solid #e8e8eb;border-radius:8px;box-shadow:0 2px 5px rgba(0,0,0,.05);padding:24px;margin-bottom:24px}
		.wpcs-card-title{font-size:18px;font-weight:600;margin:0 0 18px;padding-bottom:12px;border-bottom:1px solid #f0f0f1}
		.wpcs-card .description{color:#646970;font-size:12px;margin:6px 0 0}
		.wpcs-field{margin:0 0 16px}
		.wpcs-field .wpcs-label{display:block;font-weight:600;margin:0 0 6px;font-size:13px;color:#1d2327}
		.wpcs-field input[type="text"],.wpcs-field input[type="password"],.wpcs-field textarea{width:100%;padding:8px 12px;border:1px solid #dcdcde;border-radius:6px;font-size:13px;background:#fff;box-shadow:none;box-sizing:border-box;color:#1d2327}
		.wpcs-field input:focus,.wpcs-field textarea:focus{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1;outline:none}
		.wpcs-field textarea{font-family:Consolas,Monaco,"Courier New",monospace;resize:vertical}
		.wpcs-checkbox{display:flex;align-items:flex-start;gap:8px;cursor:pointer}
		.wpcs-checkbox input{margin-top:2px}
		.wpcs-btn-primary{display:inline-block;background:#2271b1;color:#fff;border:none;border-radius:8px;padding:12px 24px;font-size:15px;font-weight:600;cursor:pointer}
		.wpcs-btn-primary:hover{background:#1e5e8f}
		.wpcs-btn-primary:disabled{background:#a7c7d4;cursor:not-allowed}
		.wpcs-btn-danger{display:inline-block;background:#b32d2e;color:#fff;border:none;border-radius:8px;padding:12px 24px;font-size:15px;font-weight:600;cursor:pointer}
		.wpcs-btn-danger:hover{background:#8c2020}
		.wpcs-btn-danger:disabled{background:#d68a8b;cursor:not-allowed}
		.wpcs-card .button-primary{padding:12px 24px;border-radius:8px;font-size:15px;height:auto}
		.wpcs-card .button{border-radius:6px}
		.wpcs-auth-link{margin:0 0 12px;display:block}
		.wpcs-progress{margin:18px 0 6px}
		#wpcs-progress-bar{width:100%;height:12px;border-radius:6px;appearance:none;-webkit-appearance:none;border:none;background:#f0f0f1}
		#wpcs-progress-bar::-webkit-progress-bar{background:#f0f0f1;border-radius:6px}
		#wpcs-progress-bar::-webkit-progress-value{background:#2271b1;border-radius:6px;transition:width .2s}
		#wpcs-progress-bar::-moz-progress-bar{background:#2271b1;border-radius:6px}
		.wpcs-status{font-weight:600;color:#1d2327;margin:0 0 8px}
		.wpcs-log{background:#1e1e1e;color:#5ee07f;font-family:Consolas,Monaco,"Courier New",monospace;font-size:12px;line-height:1.5;padding:10px;border-radius:8px;max-height:400px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;margin-top:10px}
		.wpcs-log::-webkit-scrollbar{width:8px}
		.wpcs-log::-webkit-scrollbar-thumb{background:#444;border-radius:4px}
		.wpcs-log::-webkit-scrollbar-track{background:#2e2e2e;border-radius:4px}
		.wpcs-log-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:10px}
		.wpcs-log-actions .wpcs-inline-form{display:inline-flex;align-items:center;gap:6px;margin:0}
		.wpcs-log-actions .wpcs-inline-form .wpcs-format-select{margin:0}
		.wpcs-log-actions .wpcs-inline-form .button{margin:0;line-height:1.8;min-height:28px}
		.wpcs-dumps{width:100%;border-collapse:collapse;margin-top:8px}
		.wpcs-dumps th,.wpcs-dumps td{text-align:left;padding:6px 10px;border-bottom:1px solid #f0f0f1;font-size:13px}
		.wpcs-dumps th{color:#646970;font-weight:600}
		.wpcs-dumps .wpcs-dump-act{text-align:right;white-space:nowrap}
		.wpcs-dumps .button-link{color:#b32d2e}
		@media (max-width:768px){.wpcs-wrap{padding:0 8px}.wpcs-card{padding:16px}.wpcs-tabs{flex-wrap:wrap}}
		</style>

		<div class="wrap wpcs-wrap">
			<h1 class="wpcs-title"><?php esc_html_e( 'WP Cloud Sync', 'wp-cloud-sync' ); ?></h1>

			<?php if ( $error ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<?php if ( $ok ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Токен успешно получен и сохранён.', 'wp-cloud-sync' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $cleared ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Лог очищен.', 'wp-cloud-sync' ); ?></p></div>
			<?php endif; ?>

			<nav class="wpcs-tabs">
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=wp-cloud-sync' ) ); ?>" class="wpcs-tab <?php echo 'sync' === $tab ? 'is-active' : ''; ?>"><?php esc_html_e( 'Синхронизация', 'wp-cloud-sync' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=wp-cloud-sync&tab=logs' ) ); ?>" class="wpcs-tab <?php echo 'logs' === $tab ? 'is-active' : ''; ?>"><?php esc_html_e( 'Логи', 'wp-cloud-sync' ); ?></a>
			</nav>

			<?php if ( 'logs' === $tab ) : ?>
				<?php $this->render_logs_tab(); ?>
			<?php else : ?>

			<div class="wpcs-card">
				<h2 class="wpcs-card-title"><?php esc_html_e( 'Настройки приложения', 'wp-cloud-sync' ); ?></h2>
				<form method="post" action="options.php">
					<?php settings_fields( $opt_key ); ?>
					<div class="wpcs-field">
						<label class="wpcs-label" for="wpcs-yandex-client-id"><?php esc_html_e( 'Yandex Client ID', 'wp-cloud-sync' ); ?></label>
						<input type="text" id="wpcs-yandex-client-id" name="<?php echo esc_attr( $opt_key ); ?>[yandex_client_id]" value="<?php echo esc_attr( $settings['yandex_client_id'] ); ?>" />
					</div>
					<div class="wpcs-field">
						<label class="wpcs-label" for="wpcs-yandex-client-secret"><?php esc_html_e( 'Yandex Client Secret', 'wp-cloud-sync' ); ?></label>
						<input type="password" id="wpcs-yandex-client-secret" name="<?php echo esc_attr( $opt_key ); ?>[yandex_client_secret]" value="<?php echo esc_attr( $settings['yandex_client_secret'] ); ?>" />
					</div>
					<div class="wpcs-field">
						<label class="wpcs-label" for="wpcs-remote-path"><?php esc_html_e( 'Remote Path', 'wp-cloud-sync' ); ?></label>
						<input type="text" id="wpcs-remote-path" name="<?php echo esc_attr( $opt_key ); ?>[remote_path]" value="<?php echo esc_attr( $settings['remote_path'] ); ?>" placeholder="/path/to/site/root" />
					</div>
					<div class="wpcs-field">
						<label class="wpcs-label" for="wpcs-access-token"><?php esc_html_e( 'Токен доступа (access_token)', 'wp-cloud-sync' ); ?></label>
						<input type="password" id="wpcs-access-token" name="<?php echo esc_attr( $opt_key ); ?>[yandex_access_token]" value="<?php echo esc_attr( $settings['yandex_access_token'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Получите его автоматически ниже либо вставьте вручную.', 'wp-cloud-sync' ); ?></p>
					</div>
					<div class="wpcs-field">
						<span class="wpcs-label"><?php esc_html_e( 'Режим загрузчика', 'wp-cloud-sync' ); ?></span>
						<label class="wpcs-checkbox" for="wpcs-downloader-mode">
							<input type="checkbox" id="wpcs-downloader-mode" name="<?php echo esc_attr( $opt_key ); ?>[downloader_mode]" value="1" <?php checked( $settings['downloader_mode'] ); ?> />
							<span><?php esc_html_e( 'Включить загрузчик (удалённый сервер). Без этой галки скачивание недоступно.', 'wp-cloud-sync' ); ?></span>
						</label>
						<label class="wpcs-checkbox" for="wpcs-downloader-cron" style="margin-top:8px;">
							<input type="checkbox" id="wpcs-downloader-cron" name="<?php echo esc_attr( $opt_key ); ?>[downloader_cron]" value="1" <?php checked( $settings['downloader_cron'] ); ?> />
							<span><?php esc_html_e( 'Автоматически скачивать по крону каждые 5 минут (иначе — только вручную кнопками в консоли).', 'wp-cloud-sync' ); ?></span>
						</label>
					</div>
					<div class="wpcs-field">
						<span class="wpcs-label"><?php esc_html_e( 'Импорт базы данных', 'wp-cloud-sync' ); ?></span>
						<label class="wpcs-checkbox" for="wpcs-replace-db-urls">
							<input type="checkbox" id="wpcs-replace-db-urls" name="<?php echo esc_attr( $opt_key ); ?>[replace_db_urls]" value="1" <?php checked( $settings['replace_db_urls'] ); ?> />
							<span><?php esc_html_e( 'Заменять адреса сайта в дампе при импорте.', 'wp-cloud-sync' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'Включено по умолчанию: при импорте адреса дампа автоматически заменяются на адрес принимающего сайта. Отключайте только при необходимости импорта байт-в-байт.', 'wp-cloud-sync' ); ?></p>
					</div>
					<div class="wpcs-field">
						<label class="wpcs-label" for="wpcs-target-url"><?php esc_html_e( 'Адрес принимающего сайта (target_url)', 'wp-cloud-sync' ); ?></label>
						<input type="text" id="wpcs-target-url" name="<?php echo esc_attr( $opt_key ); ?>[target_url]" value="<?php echo esc_attr( $settings['target_url'] ); ?>" placeholder="https://example.com" />
						<p class="description"><?php esc_html_e( 'URL, на который заменяются адреса дампа при импорте. Пусто — используется home_url() текущего сайта. Также используется кнопкой «Проверить и починить БД».', 'wp-cloud-sync' ); ?></p>
					</div>
					<div class="wpcs-field">
						<label class="wpcs-label" for="wpcs-source-urls"><?php esc_html_e( 'Исходные адреса (source_urls)', 'wp-cloud-sync' ); ?></label>
						<textarea id="wpcs-source-urls" rows="3" name="<?php echo esc_attr( $opt_key ); ?>[source_urls]"><?php echo esc_textarea( $settings['source_urls'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Адреса, которые нужно заменять, по одному на строку. Пусто — определяются автоматически из siteurl/home дампа (рекомендуется).', 'wp-cloud-sync' ); ?></p>
					</div>
					<div class="wpcs-field">
						<span class="wpcs-label"><?php esc_html_e( 'Принудительный сброс', 'wp-cloud-sync' ); ?></span>
						<label class="wpcs-checkbox" for="wpcs-force-reset-hashes">
							<input type="checkbox" id="wpcs-force-reset-hashes" name="<?php echo esc_attr( $opt_key ); ?>[force_reset_hashes]" value="1" <?php checked( $settings['force_reset_hashes'] ); ?> />
							<span><?php esc_html_e( 'Сбросить хеши и состояние при следующем запуске синхронизации (начать с чистого листа).', 'wp-cloud-sync' ); ?></span>
						</label>
					</div>
					<div class="wpcs-field">
						<label class="wpcs-label" for="wpcs-custom-theme"><?php esc_html_e( 'Название кастомной темы', 'wp-cloud-sync' ); ?></label>
						<input type="text" id="wpcs-custom-theme" name="<?php echo esc_attr( $opt_key ); ?>[custom_theme]" value="<?php echo esc_attr( $settings['custom_theme'] ); ?>" placeholder="<?php echo esc_attr( get_stylesheet() ); ?>" />
						<p class="description"><?php esc_html_e( 'Папка внутри wp-content/themes (например: idpg_core). Обязательное поле: если пусто — тема не сканируется и не загружается вовсе.', 'wp-cloud-sync' ); ?></p>
					</div>
					<div class="wpcs-field">
						<label class="wpcs-label" for="wpcs-custom-plugins"><?php esc_html_e( 'Кастомные плагины (папки через запятую)', 'wp-cloud-sync' ); ?></label>
						<input type="text" id="wpcs-custom-plugins" name="<?php echo esc_attr( $opt_key ); ?>[custom_plugins]" value="<?php echo esc_attr( $settings['custom_plugins'] ); ?>" placeholder="<?php esc_attr_e( 'например: idpg-core, my-plugin', 'wp-cloud-sync' ); ?>" />
						<p class="description"><?php echo esc_html( __( 'Папки через запятую (например: idpg-core). Обязательное поле: если пусто — папка плагинов не сканируется и не загружается вовсе.', 'wp-cloud-sync' ) ); ?></p>
					</div>
					<div class="wpcs-field">
						<label class="wpcs-label" for="wpcs-excluded-dirs"><?php esc_html_e( 'Исключённые папки', 'wp-cloud-sync' ); ?></label>
						<textarea id="wpcs-excluded-dirs" rows="5" name="<?php echo esc_attr( $opt_key ); ?>[excluded_dirs]"><?php echo esc_textarea( $settings['excluded_dirs'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'По одной папке на строку, относительно корня сайта (например: wp-content/cache). Папки vendor, node_modules, tests, bin, build исключаются автоматически на любом уровне вложенности.', 'wp-cloud-sync' ); ?></p>
					</div>
					<div class="wpcs-field">
						<label class="wpcs-label" for="wpcs-local-root"><?php esc_html_e( 'Корневая папка WordPress', 'wp-cloud-sync' ); ?></label>
						<input type="text" id="wpcs-local-root" name="<?php echo esc_attr( $opt_key ); ?>[local_root]" value="<?php echo esc_attr( $settings['local_root'] ); ?>" placeholder="<?php echo esc_attr( ABSPATH ); ?>" />
						<p class="description"><?php esc_html_e( 'Оставьте пустым, если корень определяется автоматически (ABSPATH).', 'wp-cloud-sync' ); ?></p>
					</div>
					<?php submit_button( __( 'Сохранить изменения', 'wp-cloud-sync' ) ); ?>
				</form>
			</div>

			<div class="wpcs-card">
				<h2 class="wpcs-card-title"><?php esc_html_e( 'Авторизация (Яндекс OAuth)', 'wp-cloud-sync' ); ?></h2>
				<?php if ( $has_id ) : ?>
					<p class="wpcs-auth-link">
						<a class="button button-secondary" href="<?php echo esc_url( $auth_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Получить код авторизации', 'wp-cloud-sync' ); ?>
						</a>
					</p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Сначала сохраните Yandex Client ID.', 'wp-cloud-sync' ); ?></p>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wp_cloud_sync_exchange" />
					<?php wp_nonce_field( 'wp_cloud_sync_exchange' ); ?>
					<div class="wpcs-field">
						<label class="wpcs-label" for="wpcs-code"><?php esc_html_e( 'Код авторизации (code)', 'wp-cloud-sync' ); ?></label>
						<input type="text" id="wpcs-code" name="wpcs_code" />
						<p class="description"><?php esc_html_e( 'Откройте ссылку выше, получите код и вставьте его сюда, чтобы обменять на токен.', 'wp-cloud-sync' ); ?></p>
					</div>
					<?php submit_button( __( 'Обменять на токен', 'wp-cloud-sync' ), 'secondary' ); ?>
				</form>
			</div>

			<div class="wpcs-card">
				<h2 class="wpcs-card-title"><?php esc_html_e( 'Консоль синхронизации', 'wp-cloud-sync' ); ?></h2>
				<?php if ( ! $has_token ) : ?>
					<p class="description"><?php esc_html_e( 'Сначала получите токен доступа.', 'wp-cloud-sync' ); ?></p>
				<?php else : ?>
					<button type="button" class="wpcs-btn-primary" id="wpcs-sync-start">
						<?php esc_html_e( 'Запустить инкрементальную синхронизацию', 'wp-cloud-sync' ); ?>
					</button>
					<button type="button" class="button" id="wpcs-sync-stop" disabled style="margin-left:8px;">
						<?php esc_html_e( 'Остановить', 'wp-cloud-sync' ); ?>
					</button>

					<div class="wpcs-progress">
						<p class="wpcs-status" id="wpcs-progress-text"><?php esc_html_e( 'Готовность к запуску.', 'wp-cloud-sync' ); ?></p>
						<progress id="wpcs-progress-bar" max="100" value="0"></progress>
					</div>

					<?php if ( $settings['downloader_mode'] ) : ?>
					<div class="wpcs-dl-block">
						<h3 style="margin:22px 0 0;font-size:14px;font-weight:600;"><?php esc_html_e( 'Загрузка из облака (вручную)', 'wp-cloud-sync' ); ?></h3>
						<p class="description">
							<?php esc_html_e( '«Скачать всё» — полная загрузка всех файлов из облака без учёта состояния. «Скачать изменённые» — инкрементальный проход, как у крона.', 'wp-cloud-sync' ); ?>
						</p>

						<div class="wpcs-actions">
							<button type="button" class="wpcs-btn-primary" id="wpcs-dl-force-start">
								<?php esc_html_e( 'Скачать всё из облака', 'wp-cloud-sync' ); ?>
							</button>
							<button type="button" class="button" id="wpcs-dl-inc-start" style="margin-left:8px;">
								<?php esc_html_e( 'Скачать только изменённые', 'wp-cloud-sync' ); ?>
							</button>
						</div>

						<div class="wpcs-progress">
							<p class="wpcs-status" id="wpcs-dl-force-status"><?php esc_html_e( 'Готовность к запуску.', 'wp-cloud-sync' ); ?></p>
							<progress id="wpcs-dl-force-bar" max="100" value="0"></progress>
						</div>
						<div class="wpcs-progress">
							<p class="wpcs-status" id="wpcs-dl-inc-status"><?php esc_html_e( 'Готовность к запуску.', 'wp-cloud-sync' ); ?></p>
							<progress id="wpcs-dl-inc-bar" max="100" value="0"></progress>
						</div>

						<h3 style="margin:18px 0 0;font-size:14px;font-weight:600;"><?php esc_html_e( 'Лог загрузчика [DOWNLOAD]', 'wp-cloud-sync' ); ?></h3>
						<pre id="wpcs-dl-log" class="wpcs-log"><?php echo esc_html( wp_cloud_sync_downloader()->get_log_tail( 30 ) ); ?></pre>

						<script>
						(function ($) {
							var ajaxUrl = '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>';
							var nonce   = '<?php echo wp_create_nonce( 'wp_cloud_sync_download' ); ?>';
							var $forceBtn = $('#wpcs-dl-force-start');
							var $incBtn   = $('#wpcs-dl-inc-start');

							var dlRun = function (force, $btn, $status, $bar, $log) {
								var runId = (Math.random() * 1e9).toString(36) + '_' + Date.now();
								$btn.prop('disabled', true);
								$status.text('Запуск...');
								$bar.val(0);

								var step = function () {
									$.post(ajaxUrl, { action: 'wp_cloud_sync_run_download', nonce: nonce, run_id: runId, force: force ? 1 : 0 }).done(function (r) {
										if (r && r.success) {
											var d = r.data;
											if (d.total > 0) { $bar.val(Math.round((d.current || 0) / d.total * 100)); }
											$status.text('Скачивание: ' + (d.current || 0) + ' / ' + d.total);
											if (d.log) { $log.text(d.log); }
											if (d.done) {
												var c = d.counters || {};
												$status.text('Готово: скачано ' + (c.downloaded || 0) + ', ошибок ' + (c.failed || 0) + ', пропущено ' + (c.skipped || 0));
												$btn.prop('disabled', false);
											} else {
												setTimeout(step, 700);
											}
										} else {
											var msg = r && r.data && r.data.message ? r.data.message : 'Неизвестная ошибка сервера';
											$status.text('Ошибка: ' + msg);
											$btn.prop('disabled', false);
										}
									}).fail(function (x, s, e) {
										$status.text('Ошибка AJAX: ' + e);
										$btn.prop('disabled', false);
									});
								};
								step();
							};

							$forceBtn.on('click', function () {
								dlRun(true, $forceBtn, $('#wpcs-dl-force-status'), $('#wpcs-dl-force-bar'), $('#wpcs-dl-log'));
							});
							$incBtn.on('click', function () {
								dlRun(false, $incBtn, $('#wpcs-dl-inc-status'), $('#wpcs-dl-inc-bar'), $('#wpcs-dl-log'));
							});
						})(jQuery);
						</script>
					</div>
					<?php endif; ?>

					<h3 style="margin:18px 0 0;font-size:14px;font-weight:600;"><?php esc_html_e( 'Лог синхронизации', 'wp-cloud-sync' ); ?></h3>
					<pre id="wpcs-log" class="wpcs-log"><?php echo esc_html( $log_tail ); ?></pre>
				<?php endif; ?>

				<script>
				(function ($) {
					var $btn = $('#wpcs-sync-start'), $stop = $('#wpcs-sync-stop');
					var $text = $('#wpcs-progress-text'), $log = $('#wpcs-log'), $bar = $('#wpcs-progress-bar');
					var ajaxUrl = '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>';
					var runNonce = '<?php echo wp_create_nonce( 'wp_cloud_sync_run' ); ?>';
					var stopNonce = '<?php echo wp_create_nonce( 'wp_cloud_sync_stop' ); ?>';

					var runId, inflight = 0, finished = false, stopRequested = false, maxIdx = 0, total = 0, aborted = null;
					var completed = false;
					var totals = { uploaded: 0, failed: 0, skipped: 0, up_to_date: 0, scanned: 0 };

					var appendLog = function (s) { if (s) { $log.append(s + '\n'); $log.scrollTop($log[0].scrollHeight); } };

					var finish = function () {
						if (inflight > 0) { return; }
						$btn.prop('disabled', false);
						$stop.prop('disabled', true);
						if (stopRequested) { $bar.val(0); $text.text('⛔ Синхронизация остановлена пользователем.'); return; }
						if (aborted) { $bar.val(0); $text.text('Ошибка: ' + aborted); return; }
						if (!completed) {
							$bar.val(100);
							$text.text('✅ Синхронизация завершена!');
						}
					};

					var worker = function () {
						if (finished) { return; }
						$.post(ajaxUrl, {
							action: 'wp_cloud_sync_run',
							nonce: runNonce,
							run_id: runId
						}).done(function (r) {
							inflight--;
							if (finished) { finish(); return; }
							if (r && r.success) {
								var d = r.data;
								if (d.aborted) {
									finished = true;
									aborted = d.error;
									appendLog(d.error);
									finish();
									return;
								}
								if (d.busy) { inflight++; setTimeout(worker, 500); return; }
								if (d.total > 0) { total = d.total; }
								maxIdx = Math.max(maxIdx, d.current_index || 0);
								$text.text('Загрузка: ' + maxIdx + ' / ' + total);
								if (total === 0) { $text.text('Сканирование...'); }
								if (total > 0) { $bar.val(Math.round(maxIdx / total * 100)); }
								appendLog(d.log);
								if (d.counters) {
									totals.uploaded += d.counters.uploaded || 0;
									totals.failed += d.counters.failed || 0;
									totals.skipped += d.counters.skipped || 0;
								}
								if (d.stopped) {
									stopRequested = true;
									finished = true;
									appendLog('Синхронизация остановлена пользователем.');
									finish();
									return;
								}
								if (d.done) {
									finished = true;
									completed = true;
									$btn.prop('disabled', false);
									$stop.prop('disabled', true);
									$bar.val(100);
									$text.text('✅ Синхронизация завершена!');
									finish();
									return;
								}
								setTimeout(function () { inflight++; worker(); }, 400);
							} else {
								var msg = r && r.data && r.data.message ? r.data.message : 'Неизвестная ошибка сервера';
								appendLog(msg);
								setTimeout(function () { inflight++; worker(); }, 1000);
							}
						}).fail(function (x, s, e) {
							inflight--;
							if (finished) { finish(); return; }
							appendLog('Ошибка AJAX: ' + e);
							setTimeout(function () { inflight++; worker(); }, 1500);
						});
					};

					$btn.on('click', function () {
						runId = (Math.random() * 1e9).toString(36) + '_' + Date.now();
						inflight = 0; finished = false; stopRequested = false; maxIdx = 0; total = 0; aborted = null;
						completed = false;
						totals = { uploaded: 0, failed: 0, skipped: 0, up_to_date: 0, scanned: 0 };
						$btn.prop('disabled', true);
						$stop.prop('disabled', false);
						$text.text('Запуск (3 потока)...');
						for (var i = 0; i < 3; i++) { inflight++; worker(); }
					});

					$stop.on('click', function () {
						if (finished) { return; }
						stopRequested = true;
						finished = true;
						$stop.prop('disabled', true);
						$text.text('Остановка...');
						$.post(ajaxUrl, { action: 'wp_cloud_sync_stop', nonce: stopNonce })
							.always(function () {
								appendLog('Запрос остановки отправлен.');
								finish();
							});
					});
				})(jQuery);
				</script>
			</div>

			<div class="wpcs-card">
				<h2 class="wpcs-card-title"><?php esc_html_e( 'Управление базой данных', 'wp-cloud-sync' ); ?></h2>
				<?php if ( ! $has_token ) : ?>
					<p class="description"><?php esc_html_e( 'Сначала получите токен доступа.', 'wp-cloud-sync' ); ?></p>
				<?php else : ?>
					<p class="description">
						<?php esc_html_e( 'Дампы хранятся в корне папки синхронизации (Remote Path) под именами db_dump_*.sql. Экспорт пропускается, если дамп не изменился (сверка md5). Импорт заменяет текущую базу данных содержимым дампа из облака.', 'wp-cloud-sync' ); ?>
					</p>

					<div class="wpcs-actions">
						<button type="button" class="wpcs-btn-primary" id="wpcs-db-export">
							<?php esc_html_e( 'Экспорт БД', 'wp-cloud-sync' ); ?>
						</button>
						<button type="button" class="wpcs-btn-danger" id="wpcs-db-import" style="margin-left:8px;">
							<?php esc_html_e( 'Импорт свежайшего', 'wp-cloud-sync' ); ?>
						</button>
						<button type="button" class="button" id="wpcs-db-repair" style="margin-left:8px;">
							<?php esc_html_e( 'Проверить и починить БД', 'wp-cloud-sync' ); ?>
						</button>
					</div>
					<p class="description">
						<?php esc_html_e( '«Проверить и починить БД» восстанавливает "%" на месте устаревшего плейсхолдера (порча слагов старыми версиями плагина) и приводит siteurl/home к target_url, если он задан.', 'wp-cloud-sync' ); ?>
					</p>

					<div class="wpcs-progress">
						<p class="wpcs-status" id="wpcs-db-status"><?php esc_html_e( 'Готовность к запуску.', 'wp-cloud-sync' ); ?></p>
						<progress id="wpcs-db-bar" max="100" value="0"></progress>
					</div>

					<h3 style="margin:18px 0 0;font-size:14px;font-weight:600;"><?php esc_html_e( 'Дампы в облаке', 'wp-cloud-sync' ); ?></h3>
					<table class="wpcs-dumps" id="wpcs-db-dumps">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Файл', 'wp-cloud-sync' ); ?></th>
								<th><?php esc_html_e( 'Размер', 'wp-cloud-sync' ); ?></th>
								<th><?php esc_html_e( 'Создан', 'wp-cloud-sync' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>

					<h3 style="margin:18px 0 0;font-size:14px;font-weight:600;"><?php esc_html_e( 'Лог базы данных', 'wp-cloud-sync' ); ?></h3>
					<pre id="wpcs-db-log" class="wpcs-log"><?php echo esc_html( $db_log_tail ); ?></pre>

					<script>
					(function ($) {
						var ajaxUrl = '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>';
						var nonce   = '<?php echo wp_create_nonce( 'wp_cloud_sync_db' ); ?>';
						var $export = $('#wpcs-db-export');
						var $import = $('#wpcs-db-import');
						var $status = $('#wpcs-db-status');
						var $bar    = $('#wpcs-db-bar');
						var $log    = $('#wpcs-db-log');
						var $tbody  = $('#wpcs-db-dumps tbody');
						var importMsg = <?php echo wp_json_encode( __( 'ВНИМАНИЕ: текущая база данных будет ПОЛНОСТЬЮ ЗАМЕНЕНА содержимым этого дампа. Продолжить?', 'wp-cloud-sync' ) ); ?>;

						var fmtSize = function (bytes) {
							if (!bytes) { return '-'; }
							if (bytes >= 1048576) { return (bytes / 1048576).toFixed(1) + ' МБ'; }
							if (bytes >= 1024) { return (bytes / 1024).toFixed(0) + ' КБ'; }
							return bytes + ' Б';
						};

						var dumpDate = function (name) {
							var m = name.match(/^db_dump_(\d{8})_(\d{6})\.sql$/);
							if (!m) { return '-'; }
							return m[1].slice(6, 8) + '.' + m[1].slice(4, 6) + '.' + m[1].slice(0, 4) + ' ' + m[2].slice(0, 2) + ':' + m[2].slice(2, 4) + ':' + m[2].slice(4, 6);
						};

						var renderDumps = function (dumps) {
							$tbody.empty();
							var names = Object.keys(dumps || {}).sort().reverse();
							if (!names.length) {
								$tbody.append('<tr><td colspan="4">' + <?php echo wp_json_encode( __( 'Дампов в облаке нет.', 'wp-cloud-sync' ) ); ?> + '</td></tr>');
								return;
							}
							$.each(names, function (i, name) {
								var d = dumps[name] || {};
								var $btn = $('<button type="button" class="button-link"><?php echo esc_js( __( 'Импортировать', 'wp-cloud-sync' ) ); ?></button>');
								$btn.on('click', function () {
									run('wp_cloud_sync_db_import', $btn, importMsg, name);
								});
								var $row = $('<tr></tr>');
								$row.append($('<td></td>').text(name));
								$row.append($('<td></td>').text(fmtSize(d.size)));
								$row.append($('<td></td>').text(dumpDate(name)));
								$row.append($('<td class="wpcs-dump-act"></td>').append($btn));
								$tbody.append($row);
							});
						};

						var fetchList = function () {
							$.post(ajaxUrl, { action: 'wp_cloud_sync_db_list', nonce: nonce }).done(function (r) {
								if (r && r.success) { renderDumps(r.data.dumps); }
							});
						};

						var run = function (action, btn, confirmMsg, name) {
							if (!window.confirm(confirmMsg)) { return; }
							btn.prop('disabled', true);
							$bar.val(0);
							$status.text('Запуск...');
							var timer = setInterval(function () {
								var v = $bar.val() || 0;
								if (v < 90) { $bar.val(v + 5); }
							}, 200);
							$.post(ajaxUrl, { action: action, nonce: nonce, name: name || '' }).done(function (r) {
								clearInterval(timer);
								if (r && r.success) {
									$bar.val(100);
									$status.text(r.data.message || 'Готово.');
									if (action === 'wp_cloud_sync_db_export') { fetchList(); }
								} else {
									$bar.val(0);
									$status.text('Ошибка: ' + ((r && r.data && r.data.message) || 'Неизвестная ошибка сервера'));
								}
								if (r && r.data && r.data.log) { $log.text(r.data.log); }
							}).fail(function (x, s, e) {
								clearInterval(timer);
								$bar.val(0);
								$status.text('Ошибка AJAX: ' + e);
							}).always(function () {
								btn.prop('disabled', false);
							});
						};

						$export.on('click', function () {
							run('wp_cloud_sync_db_export', $export, <?php echo wp_json_encode( __( 'Создать дамп базы данных и загрузить его в облако?', 'wp-cloud-sync' ) ); ?>);
						});
						$import.on('click', function () {
							run('wp_cloud_sync_db_import', $import, importMsg);
						});

						var $repair = $('#wpcs-db-repair');
						var repairNonce = '<?php echo wp_create_nonce( 'wp_cloud_sync_repair_db' ); ?>';
						$repair.on('click', function () {
							if (!window.confirm(<?php echo wp_json_encode( __( 'Проверить базу данных и исправить найденные повреждения?', 'wp-cloud-sync' ) ); ?>)) { return; }
							$repair.prop('disabled', true);
							$status.text('Проверка...');
							$.post(ajaxUrl, { action: 'wp_cloud_sync_repair_db', nonce: repairNonce }).done(function (r) {
								if (r && r.success) {
									$status.text(r.data.message || 'Готово.');
									if (r.data.log) { $log.text(r.data.log); }
								} else {
									$status.text('Ошибка: ' + ((r && r.data && r.data.message) || 'Неизвестная ошибка сервера'));
								}
							}).fail(function () {
								$status.text('Ошибка AJAX.');
							}).always(function () {
								$repair.prop('disabled', false);
							});
						});
						fetchList();
					})(jQuery);
					</script>
				<?php endif; ?>
			</div>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Вкладка «Логи»: последние 100 строк обоих логов + кнопки очистки.
	 */
	public function render_logs_tab() {
		$files = array(
			'download' => array(
				'title' => __( 'Лог загрузчика (debug-cloud-sync.log)', 'wp-cloud-sync' ),
				'file'  => trailingslashit( WP_CONTENT_DIR ) . 'debug-cloud-sync.log',
			),
			'sync'     => array(
				'title' => __( 'Лог синхронизации (wp-content/uploads/wp-cloud-sync-sync.log)', 'wp-cloud-sync' ),
				'file'  => WP_CONTENT_DIR . '/uploads/wp-cloud-sync-sync.log',
			),
		);

		foreach ( $files as $key => $info ) {
			$content = '';
			if ( is_file( $info['file'] ) ) {
				$data    = (string) @file_get_contents( $info['file'] );
				$parts   = array_filter( explode( PHP_EOL, $data ), 'strlen' );
				$content = implode( PHP_EOL, array_slice( $parts, -100 ) );
			}
			?>
			<h2><?php echo esc_html( $info['title'] ); ?></h2>
			<div class="wpcs-log-actions">
				<button type="button" class="button wpcs-copy-log" data-target="wpcs-log-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Копировать лог', 'wp-cloud-sync' ); ?></button>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpcs-inline-form">
					<input type="hidden" name="action" value="wp_cloud_sync_download_log" />
					<input type="hidden" name="which" value="<?php echo esc_attr( $key ); ?>" />
					<?php wp_nonce_field( 'wp_cloud_sync_download_log' ); ?>
					<select name="format" class="wpcs-format-select">
						<option value="txt"><?php esc_html_e( 'TXT', 'wp-cloud-sync' ); ?></option>
						<option value="csv"><?php esc_html_e( 'CSV', 'wp-cloud-sync' ); ?></option>
						<option value="html"><?php esc_html_e( 'HTML', 'wp-cloud-sync' ); ?></option>
						<option value="json"><?php esc_html_e( 'JSON', 'wp-cloud-sync' ); ?></option>
					</select>
					<?php submit_button( __( 'Скачать лог', 'wp-cloud-sync' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
			<pre id="wpcs-log-<?php echo esc_attr( $key ); ?>" class="wpcs-log"><?php echo esc_html( '' === $content ? __( 'Лог пуст.', 'wp-cloud-sync' ) : $content ); ?></pre>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wp_cloud_sync_clear_log" />
				<input type="hidden" name="which" value="<?php echo esc_attr( $key ); ?>" />
				<?php wp_nonce_field( 'wp_cloud_sync_clear_log' ); ?>
				<?php submit_button( __( 'Очистить лог', 'wp-cloud-sync' ), 'secondary' ); ?>
			</form>
			<?php
		}
		?>
		<script>
		(function(){
			var btns = document.querySelectorAll('.wpcs-copy-log');
			Array.prototype.forEach.call(btns, function (btn) {
				btn.addEventListener('click', function () {
					var pre = document.getElementById(btn.getAttribute('data-target'));
					if (!pre) { return; }
					var text = pre.textContent;
					if (!navigator.clipboard || !navigator.clipboard.writeText) {
						var ta = document.createElement('textarea');
						ta.value = text;
						ta.style.position = 'fixed';
						ta.style.opacity = '0';
						document.body.appendChild(ta);
						ta.select();
						try { document.execCommand('copy'); } catch (e) {}
						document.body.removeChild(ta);
					} else {
						navigator.clipboard.writeText(text);
					}
					var original = btn.textContent;
					btn.textContent = <?php echo wp_json_encode( __( 'Скопировано', 'wp-cloud-sync' ) ); ?>;
					setTimeout(function () { btn.textContent = original; }, 1500);
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Очистка лог-файла (action: admin_post_wp_cloud_sync_clear_log).
	 */
	public function handle_clear_log() {
		check_admin_referer( 'wp_cloud_sync_clear_log' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'wp-cloud-sync' ) );
		}

		$which = isset( $_POST['which'] ) ? sanitize_key( $_POST['which'] ) : '';
		if ( 'download' === $which ) {
			$file = trailingslashit( WP_CONTENT_DIR ) . 'debug-cloud-sync.log';
		} elseif ( 'sync' === $which ) {
			$file = WP_CONTENT_DIR . '/uploads/wp-cloud-sync-sync.log';
		} else {
			$file = '';
		}

		if ( '' !== $file ) {
			@file_put_contents( $file, '' );
		}

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'wp-cloud-sync', 'tab' => 'logs', 'cleared' => '1' ),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Скачивание лог-файла в выбранном формате (action: admin_post_wp_cloud_sync_download_log).
	 */
	public function handle_download_log() {
		check_admin_referer( 'wp_cloud_sync_download_log' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'wp-cloud-sync' ) );
		}

		$which  = isset( $_POST['which'] ) ? sanitize_key( $_POST['which'] ) : '';
		$format = isset( $_POST['format'] ) ? sanitize_key( $_POST['format'] ) : 'txt';

		if ( 'download' === $which ) {
			$file = trailingslashit( WP_CONTENT_DIR ) . 'debug-cloud-sync.log';
		} elseif ( 'sync' === $which ) {
			$file = WP_CONTENT_DIR . '/uploads/wp-cloud-sync-sync.log';
		} else {
			$file = '';
		}

		if ( '' === $file || ! is_file( $file ) ) {
			wp_die( esc_html__( 'Лог не найден.', 'wp-cloud-sync' ) );
		}

		$data = (string) @file_get_contents( $file );
		$ext  = array(
			'txt'  => 'txt',
			'csv'  => 'csv',
			'html' => 'html',
			'json' => 'json',
		);
		$ext  = isset( $ext[ $format ] ) ? $ext[ $format ] : 'txt';

		$lines = array();
		foreach ( explode( PHP_EOL, $data ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		$filename = sprintf( 'wp-cloud-sync-%s-%s.%s', $which, gmdate( 'Y-m-d-H-i-s' ), $ext );

		switch ( $format ) {
			case 'csv':
				$out  = "datetime,level,message\n";
				foreach ( $this->parse_log_lines( $lines ) as $row ) {
					$out .= $this->csv_escape( $row['datetime'] ) . ',' . $this->csv_escape( $row['level'] ) . ',' . $this->csv_escape( $row['message'] ) . "\n";
				}
				$mime = 'text/csv';
				break;

			case 'html':
				$rows = '';
				foreach ( $this->parse_log_lines( $lines ) as $row ) {
					$level_class = strtolower( $row['level'] );
					$rows       .= '<tr><td>' . esc_html( $row['datetime'] ) . '</td><td class="wpcs-level wpcs-level-' . esc_attr( $level_class ) . '">' . esc_html( $row['level'] ) . '</td><td>' . esc_html( $row['message'] ) . '</td></tr>' . "\n";
				}
				$out = '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>' . esc_html( $filename ) . '</title><style>'
					. 'body{font-family:Consolas,Monaco,monospace;font-size:13px;margin:20px;color:#1d2327}'
					. 'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:4px 8px;text-align:left;vertical-align:top}'
					. 'th{background:#f0f0f1}.wpcs-level{font-weight:700}.wpcs-level-error{color:#b32d2e}.wpcs-level-warning{color:#b26a00}.wpcs-level-debug{color:#646970}'
					. '</style></head><body><h1>WP Cloud Sync — ' . esc_html( $which ) . ' log</h1>'
					. '<table><thead><tr><th>' . esc_html__( 'Время', 'wp-cloud-sync' ) . '</th><th>' . esc_html__( 'Уровень', 'wp-cloud-sync' ) . '</th><th>' . esc_html__( 'Сообщение', 'wp-cloud-sync' ) . '</th></tr></thead><tbody>'
					. ( '' === $rows ? '<tr><td colspan="3">' . esc_html__( 'Лог пуст.', 'wp-cloud-sync' ) . '</td></tr>' : $rows )
					. '</tbody></table></body></html>';
				$mime = 'text/html';
				break;

			case 'json':
				$rows = $this->parse_log_lines( $lines );
				$out  = wp_json_encode( array(
					'file'     => $filename,
					'exported' => gmdate( 'c' ),
					'count'    => count( $rows ),
					'entries'  => $rows,
				), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
				$mime = 'application/json';
				break;

			case 'txt':
			default:
				$out  = implode( "\n", $lines ) . "\n";
				$mime = 'text/plain';
				break;
		}

		status_header( 200 );
		header( 'Content-Type: ' . $mime . '; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $out ) );
		header( 'Cache-Control: no-store, no-cache' );
		echo $out;
		exit;
	}

	/**
	 * Разбор строк лога в структурированные записи.
	 *
	 * Формат строки: [2026-08-07 17:22:58] [DEBUG] Сообщение.
	 *
	 * @param array $lines Строки лога.
	 * @return array
	 */
	private function parse_log_lines( $lines ) {
		$rows = array();
		foreach ( $lines as $line ) {
			if ( 1 === preg_match( '/^\[([^\]]+)\]\s*\[([^\]]+)\]\s?(.*)$/s', $line, $m ) ) {
				$rows[] = array(
					'datetime' => $m[1],
					'level'    => strtoupper( $m[2] ),
					'message'  => $m[3],
				);
			} else {
				$rows[] = array(
					'datetime' => '',
					'level'    => '',
					'message'  => $line,
				);
			}
		}
		return $rows;
	}

	/**
	 * Экранирование значения для CSV.
	 *
	 * @param string $value Значение.
	 * @return string
	 */
	private function csv_escape( $value ) {
		$value = (string) $value;
		if ( false !== strpos( $value, ',' ) || false !== strpos( $value, '"' ) || false !== strpos( $value, "\n" ) || false !== strpos( $value, "\r" ) ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}
}
