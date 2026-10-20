<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Имя файла дано по стилю проекта (class-<название>.php), а не по стандарту: остальные классы плагина названы так же.
/**
 * Локальная база IP → регион (242-ФЗ).
 *
 * Определение региона по IP без обращения к зарубежным сервисам: данные
 * лежат в таблице `{prefix}idpg_geo_ranges` и наполняются владельцем
 * импортом CSV-выгрузки DB-IP Lite (набор по РФ). IP посетителя не
 * покидает сервер — трансграничной передачи нет.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Geo_Ranges
 *
 * Таблица диапазонов IP → регион, поиск региона и импорт CSV.
 */
class IDPG_Geo_Ranges {

	/**
	 * Синглтон.
	 *
	 * @var IDPG_Geo_Ranges|null
	 */
	private static $instance = null;

	/**
	 * Имя таблицы без префикса.
	 *
	 * @var string
	 */
	const TABLE = 'idpg_geo_ranges';

	/**
	 * Опция с датой последнего импорта.
	 *
	 * @var string
	 */
	const OPT_UPDATED = 'idpg_geo_ranges_updated';

	/**
	 * Опция с числом строк последнего импорта.
	 *
	 * @var string
	 */
	const OPT_ROWS = 'idpg_geo_ranges_rows';

	/**
	 * Действие nonce формы импорта.
	 *
	 * @var string
	 */
	const IMPORT_ACTION = 'idpg_geo_import';

	/**
	 * Слаг вкладки на странице «IDPG».
	 *
	 * @var string
	 */
	const TAB_SLUG = 'geo';

	/**
	 * Кэш результата проверки существования таблицы.
	 *
	 * @var bool|null
	 */
	private static $table_exists_cache = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Geo_Ranges
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
		// Вкладка «Гео (IP→регион)» на странице «IDPG» (точка расширения DEV-288).
		add_filter( 'idpg_widget_areas_admin_tabs', array( $this, 'register_admin_tab' ) );

		// Обработка формы импорта CSV (только manage_options + nonce).
		add_action( 'admin_init', array( $this, 'handle_import' ) );
	}

	/**
	 * Полное имя таблицы с префиксом БД.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Существует ли таблица диапазонов.
	 *
	 * Проверка через `DESCRIBE` (а не `SHOW TABLES`/`LIKE`): работает и на
	 * некоторых конфигурациях, где `SHOW TABLES` для этой таблицы пуст.
	 * Результат кэшируется на время запроса; `$fresh = true` сбрасывает кэш
	 * (используется после создания таблицы и в тестах).
	 *
	 * @param bool $fresh Принудительно перечитать.
	 * @return bool
	 */
	public static function table_exists( $fresh = false ) {
		global $wpdb;

		if ( ! $fresh && null !== self::$table_exists_cache ) {
			return self::$table_exists_cache;
		}

		$table = self::table_name();

		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column = $wpdb->get_var( "DESCRIBE {$table}" );

		self::$table_exists_cache = ( null !== $column && '' === $wpdb->last_error );

		return self::$table_exists_cache;
	}

	/**
	 * Преобразование IPv4-адреса в целое число.
	 *
	 * IPv6 и невалидные значения не поддерживаются (128 бит не влезают
	 * в BIGINT) — возвращается -1, определение города пропускается.
	 *
	 * @param string $ip IP-адрес.
	 * @return int Значение 32-битного адреса или -1.
	 */
	public static function ip_to_long( $ip ) {
		$ip = trim( (string) $ip );

		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return -1;
		}

		return (int) sprintf( '%u', ip2long( $ip ) );
	}

	/**
	 * Поиск региона по IP в локальной базе.
	 *
	 * @param string $ip IP-адрес.
	 * @return string Название региона или пустая строка.
	 */
	public static function lookup_region( $ip ) {
		global $wpdb;

		$value = self::ip_to_long( $ip );

		if ( $value < 0 || ! self::table_exists() ) {
			return '';
		}

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$region = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Имя таблицы не пользовательский ввод, значения — через %d.
				"SELECT region FROM {$table} WHERE ip_start <= %d AND ip_end >= %d LIMIT 1",
				$value,
				$value
			)
		);

		return is_string( $region ) ? $region : '';
	}

	/**
	 * Карта соответствий «регион DB-IP (англ.) → регион РФ (рус.)».
	 *
	 * DB-IP Lite хранит названия регионов на английском, а термины таксономии
	 * `city` — на русском, поэтому при импорте регион переводится. Список
	 * засеян крупными субъектами и расширяется фильтром
	 * `idpg_geo_region_aliases` (владелец может добавить свои).
	 *
	 * @return array Карта `english_lower => russian`.
	 */
	public static function region_aliases() {
		$aliases = array(
			'moscow'                    => 'Москва',
			'moscow oblast'             => 'Московская область',
			'saint petersburg'          => 'Санкт-Петербург',
			'leningrad oblast'          => 'Ленинградская область',
			'udmurt republic'           => 'Удмуртская Республика',
			'udmurtia'                  => 'Удмуртская Республика',
			'tatarstan'                 => 'Республика Татарстан',
			'republic of tatarstan'     => 'Республика Татарстан',
			'bashkortostan'             => 'Республика Башкортостан',
			'republic of bashkortostan' => 'Республика Башкортостан',
			'novosibirsk oblast'        => 'Новосибирская область',
			'sverdlovsk oblast'         => 'Свердловская область',
			'chelyabinsk oblast'        => 'Челябинская область',
			'nizhny novgorod oblast'    => 'Нижегородская область',
			'samara oblast'             => 'Самарская область',
			'rostov oblast'             => 'Ростовская область',
			'krasnodar krai'            => 'Краснодарский край',
			'perm krai'                 => 'Пермский край',
			'primorsky krai'            => 'Приморский край',
			'krasnoyarsk krai'          => 'Красноярский край',
			'voronezh oblast'           => 'Воронежская область',
			'volgograd oblast'          => 'Волгоградская область',
			'saratov oblast'            => 'Саратовская область',
			'tyumen oblast'             => 'Тюменская область',
			'irkutsk oblast'            => 'Иркутская область',
			'orenburg oblast'           => 'Оренбургская область',
			'kirov oblast'              => 'Кировская область',
			'yaroslavl oblast'          => 'Ярославская область',
			'tula oblast'               => 'Тульская область',
			'kaluga oblast'             => 'Калужская область',
			'ryazan oblast'             => 'Рязанская область',
			'penza oblast'              => 'Пензенская область',
			'ulyanovsk oblast'          => 'Ульяновская область',
			'tver oblast'               => 'Тверская область',
			'vladimir oblast'           => 'Владимирская область',
			'arkhangelsk oblast'        => 'Архангельская область',
			'vologda oblast'            => 'Вологодская область',
			'crimea'                    => 'Республика Крым',
			'republic of crimea'        => 'Республика Крым',
			'chuvash republic'          => 'Чувашская Республика',
			'mari el republic'          => 'Республика Марий Эл',
			'republic of mordovia'      => 'Республика Мордовия',
			'komi republic'             => 'Республика Коми',
			'sakha republic'            => 'Республика Саха (Якутия)',
		);

		/**
		 * Фильтр карты регионов для расширения списка владельцем.
		 *
		 * @param array $aliases Карта `english_lower => russian`.
		 */
		return apply_filters( 'idpg_geo_region_aliases', $aliases );
	}

	/**
	 * Перевод названия региона из выгрузки DB-IP в русское название.
	 *
	 * Если соответствия нет — возвращается исходное название (импорт не
	 * теряет данные; позднее владелец дополнит карту фильтром).
	 *
	 * @param string $region Название региона из выгрузки.
	 * @return string Русское название региона или исходное.
	 */
	public static function localize_region( $region ) {
		$region = trim( (string) $region );

		if ( '' === $region ) {
			return '';
		}

		$aliases = self::region_aliases();
		$key     = mb_strtolower( $region, 'UTF-8' );

		if ( isset( $aliases[ $key ] ) ) {
			return $aliases[ $key ];
		}

		return $region;
	}

	/**
	 * Число диапазонов в таблице.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Импорт CSV-выгрузки DB-IP Lite (набор по РФ) в таблицу.
	 *
	 * Поддерживаются два вида границ: готовое числовое представление
	 * (начало, конец) и CIDR (только для IPv4). Строки другой страны,
	 * пустые и битые пропускаются. Перед импортом таблица очищается —
	 * база приходит целиком, а не дозаполняется.
	 *
	 * Формат DB-IP (участки города): start,end,country_code,country_name,region_name,city_name.
	 *
	 * @param string $path Путь к CSV-файлу.
	 * @return array ['rows'=>int,'skipped'=>int] или ['error'=>string].
	 */
	public static function import_csv( $path ) {
		global $wpdb;

		if ( ! is_readable( $path ) ) {
			return array( 'error' => __( 'Файл не найден или недоступен для чтения.', 'idpg-core' ) );
		}

		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $handle ) {
			return array( 'error' => __( 'Не удалось открыть файл.', 'idpg-core' ) );
		}

		$table = self::table_name();

		// База приходит целиком — очищаем прежние диапазоны.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		$rows    = 0;
		$skipped = 0;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
		while ( false !== ( $line = fgets( $handle ) ) ) {
			$parsed = self::parse_csv_line( $line );

			if ( null === $parsed ) {
				++$skipped;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->insert(
				$table,
				array(
					'ip_start' => $parsed['start'],
					'ip_end'   => $parsed['end'],
					'region'   => self::localize_region( $parsed['region'] ),
				),
				array( '%d', '%d', '%s' )
			);

			if ( false === $ok ) {
				++$skipped;
				continue;
			}

			++$rows;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		update_option( self::OPT_UPDATED, current_time( 'mysql' ), false );
		update_option( self::OPT_ROWS, $rows, false );

		return array(
			'rows'    => $rows,
			'skipped' => $skipped,
		);
	}

	/**
	 * Разбор одной строки CSV-выгрузки DB-IP.
	 *
	 * @param string $line Строка файла.
	 * @return array|null ['start'=>int,'end'=>int,'region'=>string] или null, если строку надо пропустить.
	 */
	public static function parse_csv_line( $line ) {
		$line = trim( (string) $line );

		if ( '' === $line ) {
			return null;
		}

		$cols = str_getcsv( $line );

		if ( ! is_array( $cols ) || count( $cols ) < 2 ) {
			return null;
		}

		$start = self::boundary_to_long( $cols[0] );
		$end   = self::boundary_to_long( $cols[1] );

		if ( $start < 0 || $end < 0 || $start > $end ) {
			return null;
		}

		// Определяем страну и регион по раскладке DB-IP:
		// 0:start, 1:end, 2:country_code, 3:country_name, 4:region_name, 5:city_name.
		// Если колонок меньше — регион берём из последнего непустого текстового поля.
		$country_code = isset( $cols[2] ) ? trim( (string) $cols[2] ) : '';

		if ( '' !== $country_code && 2 === strlen( $country_code ) && preg_match( '/^[A-Za-z]{2}$/', $country_code ) ) {
			$country_code = strtoupper( $country_code );
		} else {
			$country_code = '';
		}

		if ( isset( $cols[4] ) && '' !== trim( (string) $cols[4] ) ) {
			$region = trim( (string) $cols[4] );
		} else {
			// Нормализованный ручной формат: последнее непустое поле — регион.
			$tail = array_slice( $cols, 3 );
			$tail = array_filter( array_map( 'trim', array_map( 'strval', $tail ) ), 'strlen' );

			if ( ! empty( $tail ) ) {
				$region = (string) end( $tail );
			}
		}

		// Оставляем только диапазоны РФ (код RU). Если кода страны в строке
		// нет — принимаем как есть (нормализованный ручной формат).
		if ( '' !== $country_code && 'RU' !== $country_code ) {
			return null;
		}

		if ( '' === $region ) {
			return null;
		}

		return array(
			'start'  => $start,
			'end'    => $end,
			'region' => sanitize_text_field( $region ),
		);
	}

	/**
	 * Преобразование границы диапазона в целое число.
	 *
	 * Поддерживает числовое представление и CIDR (IPv4).
	 *
	 * @param string $value Значение границы.
	 * @return int Значение или -1.
	 */
	private static function boundary_to_long( $value ) {
		$value = trim( (string) $value, " \t\n\r\0\x0B\"" );

		if ( '' === $value ) {
			return -1;
		}

		// CIDR: a.b.c.d/len — вычисляем границы сети.
		if ( false !== strpos( $value, '/' ) ) {
			list( $network, $bits ) = array_pad( explode( '/', $value, 2 ), 2, '' );

			$bits = (int) $bits;

			if ( $bits < 0 || $bits > 32 ) {
				return -1;
			}

			$long = self::ip_to_long( $network );

			if ( $long < 0 ) {
				return -1;
			}

			// Размер маски: для /0 длина нулевая.
			$host_bits = 32 - $bits;
			$mask      = ( 0 === $host_bits ) ? 0 : ( ( 1 << $host_bits ) - 1 );

			// Для CIDR возвращаем адрес сети (начало). Конец строки задаётся
			// отдельным полем end, поэтому здесь достаточно начала.
			return ( $long & ~$mask ) & 0xFFFFFFFF;
		}

		// Чисто числовое представление.
		if ( ctype_digit( $value ) ) {
			$num = (float) $value;

			if ( $num < 0 || $num > 4294967295 ) {
				return -1;
			}

			return (int) $num;
		}

		// IP-адрес.
		return self::ip_to_long( $value );
	}

	/**
	 * Регистрация вкладки «Гео (IP→регион)» на странице «IDPG».
	 *
	 * @param array $tabs Карта вкладок `slug => ['label','callback']`.
	 * @return array
	 */
	public function register_admin_tab( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			$tabs = array();
		}

		$tabs[ self::TAB_SLUG ] = array(
			'label'    => __( 'Гео (IP→регион)', 'idpg-core' ),
			'callback' => array( $this, 'render_admin_tab' ),
		);

		return $tabs;
	}

	/**
	 * Рендер вкладки: сводка базы и форма импорта CSV.
	 */
	public function render_admin_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$rows    = self::count();
		$updated = get_option( self::OPT_UPDATED, '' );

		// Сообщение после импорта (idpg_msg проставляется редиректом).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['idpg_msg'] ) ? sanitize_key( wp_unslash( $_GET['idpg_msg'] ) ) : '';

		if ( 'imported' === $msg ) {
			$imported = absint( get_transient( 'idpg_geo_last_import_rows' ) );
			?>
			<div class="idpg-notice idpg-notice--success">
				<?php
				printf(
					/* translators: %d: число импортированных диапазонов. */
					esc_html__( 'Импорт завершён: добавлено %d диапазонов.', 'idpg-core' ),
					(int) $imported
				);
				?>
			</div>
			<?php
			delete_transient( 'idpg_geo_last_import_rows' );
		} elseif ( 'empty' === $msg ) {
			?>
			<div class="idpg-notice idpg-notice--error"><?php esc_html_e( 'Файл пуст или не распознан.', 'idpg-core' ); ?></div>
			<?php
		} elseif ( 'error' === $msg ) {
			?>
			<div class="idpg-notice idpg-notice--error"><?php esc_html_e( 'Не удалось импортировать файл.', 'idpg-core' ); ?></div>
			<?php
		}
		?>
		<h2 class="idpg-privacy-section-title"><?php esc_html_e( 'Локальная база IP → регион (242-ФЗ)', 'idpg-core' ); ?></h2>

		<p class="idpg-privacy-tools__text">
			<?php esc_html_e( 'Город определяется по локальной базе диапазонов IP — данные не передаются за рубеж. Импортируйте CSV-выгрузку DB-IP Lite (набор по РФ), база заменит прежние данные целиком.', 'idpg-core' ); ?>
		</p>

		<ul class="idpg-geo-summary">
			<li>
				<?php
				printf(
					/* translators: %d: число диапазонов. */
					esc_html__( 'Диапазонов в базе: %d', 'idpg-core' ),
					(int) $rows
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: дата последнего импорта. */
					esc_html__( 'Обновлено: %s', 'idpg-core' ),
					$updated ? esc_html( $updated ) : esc_html__( 'ещё не импортировалось', 'idpg-core' )
				);
				?>
			</li>
		</ul>

		<form method="post" action="" enctype="multipart/form-data" class="idpg-privacy-tools__form">
			<?php wp_nonce_field( self::IMPORT_ACTION, 'idpg_geo_import_nonce' ); ?>
			<input type="hidden" name="idpg_geo_action" value="import">
			<p>
				<input type="file" name="idpg_geo_csv" accept=".csv,text/csv" required>
			</p>
			<button type="submit" class="idpg-privacy-tools__submit"><?php esc_html_e( 'Импортировать в локальную базу', 'idpg-core' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Обработка формы импорта CSV.
	 *
	 * Доступ — только администраторы (manage_options), запрос подписан nonce.
	 */
	public function handle_import() {
		if ( ! isset( $_POST['idpg_geo_action'] ) || 'import' !== $_POST['idpg_geo_action'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Недостаточно прав для импорта.', 'idpg-core' ), 403 );
		}

		check_admin_referer( self::IMPORT_ACTION, 'idpg_geo_import_nonce' );

		$redirect = admin_url( 'admin.php?page=idpg-widget-areas&tab=' . self::TAB_SLUG );

		if ( empty( $_FILES['idpg_geo_csv']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'idpg_msg', 'error', $redirect ) );
			exit;
		}

		$file = $_FILES['idpg_geo_csv']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// Проверка типа файла до чтения.
		$check = wp_check_filetype( isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '' );

		if ( ! in_array( strtolower( (string) $check['ext'] ), array( 'csv', 'txt' ), true ) ) {
			wp_safe_redirect( add_query_arg( 'idpg_msg', 'error', $redirect ) );
			exit;
		}

		$tmp = isset( $file['tmp_name'] ) ? $file['tmp_name'] : '';

		// Перемещаем во временный файл загрузки (без публикации в uploads).
		if ( ! is_uploaded_file( $tmp ) && ! is_readable( $tmp ) ) {
			wp_safe_redirect( add_query_arg( 'idpg_msg', 'error', $redirect ) );
			exit;
		}

		$result = self::import_csv( $tmp );

		if ( isset( $result['error'] ) ) {
			wp_safe_redirect( add_query_arg( 'idpg_msg', 'error', $redirect ) );
			exit;
		}

		if ( 0 === (int) $result['rows'] ) {
			wp_safe_redirect( add_query_arg( 'idpg_msg', 'empty', $redirect ) );
			exit;
		}

		set_transient( 'idpg_geo_last_import_rows', (int) $result['rows'], 60 );

		wp_safe_redirect( add_query_arg( 'idpg_msg', 'imported', $redirect ) );
		exit;
	}
}
