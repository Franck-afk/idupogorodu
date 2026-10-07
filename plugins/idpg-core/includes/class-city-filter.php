<?php
/**
 * Глобальная фильтрация по городам.
 *
 * Определяет текущий город из cookie и автоматически
 * фильтрует запросы архивов по таксономии city.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_City_Filter
 *
 * Читает cookie с выбором города и навешивает tax_query на основные запросы.
 */
class IDPG_City_Filter {

	/**
	 * Синглтон.
	 *
	 * @var IDPG_City_Filter|null
	 */
	private static $instance = null;

	/**
	 * Название cookie.
	 *
	 * @var string
	 */
	const COOKIE_NAME = 'idpg_selected_city';

	/**
	 * Cookie-подтверждение: «город выбран самим посетителем» (DEV-19).
	 *
	 * Раньше cookie COOKIE_NAME ставилась и автоопределением по IP, и ручным
	 * выбором — различия не было, поэтому определённый по IP город молча
	 * становился рабочим и выглядел как выбранный. Теперь факт выбора
	 * хранится отдельной меткой: пока её нет, автоопределение может
	 * предлагать город, но не «закреплять» его.
	 *
	 * @var string
	 */
	const COOKIE_CONFIRMED = 'idpg_city_confirmed';

	/**
	 * Значение cookie-подтверждения.
	 *
	 * @var string
	 */
	const CONFIRMED_VALUE = '1';

	/**
	 * Nonce-действие для подтверждения города ссылкой (DEV-19).
	 *
	 * @var string
	 */
	const CONFIRM_ACTION = 'idpg_confirm_city';

	/**
	 * ID города по умолчанию (переопределяется константой IDPG_DEFAULT_CITY_ID).
	 *
	 * @var int
	 */
	const DEFAULT_CITY_ID = 15;

	/**
	 * Был ли текущий город определён автоматически по IP
	 * (для подсказки в виджете/шапке).
	 *
	 * @var bool
	 */
	private static $detected_from_ip = false;

	/**
	 * Время жизни кэша успешного определения (сек).
	 *
	 * @var int
	 */
	const IP_CACHE_TTL_OK = DAY_IN_SECONDS;

	/**
	 * Время жизни кэша «сервис ответил, но город не найден» (сек).
	 *
	 * @var int
	 */
	const IP_CACHE_TTL_MISS = 12 * HOUR_IN_SECONDS;

	/**
	 * Время жизни кэша «сервисы недоступны» — короткий, чтобы скоро повторить (сек).
	 *
	 * @var int
	 */
	const IP_CACHE_TTL_FAIL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_City_Filter
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
		add_action( 'pre_get_posts', array( $this, 'filter_by_city' ), 99 );

		// AJAX: смена города через JS (установка cookie).
		add_action( 'wp_ajax_idpg_set_city', array( $this, 'ajax_set_city' ) );
		add_action( 'wp_ajax_nopriv_idpg_set_city', array( $this, 'ajax_set_city' ) );

		// Автоопределение города по IP (если cookie ещё не установлен).
		add_action( 'init', array( $this, 'auto_detect_city' ), 5 );

		// DEV-19: подтверждение определённого по IP города без JS (ссылка с nonce).
		add_action( 'template_redirect', array( $this, 'maybe_confirm_city_from_link' ), 1 );
	}

	/**
	 * Подтверждение города обычной ссылкой (без JavaScript).
	 *
	 * Запасной путь для посетителей с отключённым JS: раз виджет и шапка
	 * рендерят блок подтверждения сервером, кнопка «Да, мой город» должна
	 * работать без fetch. Действие через GET изменяет cookie — поэтому
	 * подпись nonce обязательна (AGENTS.md, п.12).
	 */
	public function maybe_confirm_city_from_link() {
		if ( ! isset( $_GET['idpg_confirm_city'] ) ) {
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::CONFIRM_ACTION ) ) {
			return;
		}

		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			// Подтверждать нечего: город не определён и не выбран.
			return;
		}

		self::mark_city_confirmed();
	}

	/**
	 * Подтверждён ли город посетителем.
	 *
	 * @return bool
	 */
	public static function is_city_confirmed() {
		return isset( $_COOKIE[ self::COOKIE_CONFIRMED ] )
			&& self::CONFIRMED_VALUE === (string) wp_unslash( $_COOKIE[ self::COOKIE_CONFIRMED ] );
	}

	/**
	 * Пометить текущий город как подтверждённый посетителем.
	 *
	 * Метка ставится на то же время жизни, что и cookie города (30 дней),
	 * и синхронизируется в $_COOKIE — как это делает set_city_cookie(),
	 * чтобы текущий запрос тоже видел новое состояние.
	 */
	public static function mark_city_confirmed() {
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE_CONFIRMED,
				self::CONFIRMED_VALUE,
				array(
					'expires'  => time() + ( 30 * DAY_IN_SECONDS ),
					'path'     => '/',
					'samesite' => 'Lax',
					'httponly' => true,
				)
			);
		}

		$_COOKIE[ self::COOKIE_CONFIRMED ] = self::CONFIRMED_VALUE;
	}

	/**
	 * Выбрать город и сразу пометить его подтверждённым.
	 *
	 * Ручной выбор посетителя — это и есть подтверждение, поэтому и AJAX,
	 * и REST-эндпоинт вызывают именно этот метод.
	 *
	 * @param int $city_id Term ID.
	 */
	public static function set_chosen_city( $city_id ) {
		self::set_city_cookie( $city_id );

		self::mark_city_confirmed();
	}

	/**
	 * Нужно ли предлагать подтвердить определённый по IP город.
	 *
	 * Показываем предложение, только когда город действительно пришёл из
	 * IP-определения и посетитель его ещё не подтвердил. Для всех, кто
	 * выбрал город вручную, диалога нет.
	 *
	 * @return bool
	 */
	public static function needs_city_confirmation() {
		if ( ! self::$detected_from_ip ) {
			return false;
		}

		return ! self::is_city_confirmed();
	}

	/**
	 * Автоопределение города по IP посетителя.
	 *
	 * Бесплатный сервис ip-api.com (основной) с фолбэком ipapi.co.
	 * Результат кэшируется по IP (успех — сутки, промах — 12 часов,
	 * недоступность сервиса — 10 минут). Cookie ставится только один раз —
	 * при первом визите, пока пользователь не выбрал город вручную.
	 *
	 * DEV-19: подтверждённый посетителем город автоопределение не трогает
	 * никогда — выбранный город не должен молча меняться на IP-угадайку.
	 */
	public function auto_detect_city() {
		if ( self::is_city_confirmed() ) {
			return;
		}

		if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		// Настройка виджета «Выбор города» может отключить автоопределение
		// ('none') или сузить список сервисов; по умолчанию — оба сервиса.
		$services = self::allowed_ip_services();

		if ( empty( $services ) ) {
			return;
		}

		// Не тратим запросы внешнего сервиса на ботов и пустые UA.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === trim( $ua ) || preg_match( '/bot|crawl|spider|slurp|curl|wget|python|monitor|headless/i', $ua ) ) {
			return;
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( ! $ip || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return;
		}

		$cached = self::get_ip_detection( $ip );

		if ( is_array( $cached ) ) {
			if ( 'ok' === $cached['status'] && ! empty( $cached['term_id'] ) ) {
				self::$detected_from_ip = true;
				self::set_city_cookie( (int) $cached['term_id'] );
			}
			return;
		}

		// В кэше ничего нет — выполняем поиск (под замком от параллельных запросов).
		$term_id = self::lookup_city_id_by_ip( $ip, $services );

		if ( $term_id ) {
			self::$detected_from_ip = true;
			self::set_city_cookie( $term_id );
		}
	}

	/**
	 * Чтение кэша определения города по IP.
	 *
	 * Поддерживает и старый формат (число/false) — такой кэш удаляется,
	 * чтобы отравленные нули не блокировали определение на сутки.
	 *
	 * @param string $ip IP-адрес.
	 * @return array|null Массив ['status'=>'ok|miss|fail','term_id'=>int] или null.
	 */
	public static function get_ip_detection( $ip ) {
		$key    = 'idpg_city_ip_' . md5( (string) $ip );
		$cached = get_transient( $key );

		if ( false === $cached ) {
			return null;
		}

		// Старый формат (до рефакторинга): число или false.
		if ( ! is_array( $cached ) ) {
			delete_transient( $key );
			return null;
		}

		return $cached;
	}

	/**
	 * Поиск города по IP через внешние сервисы с кэшированием результата.
	 *
	 * Публичный метод — используется также виджетом селектора города,
	 * чтобы не дублировать HTTP-запросы (общий transient-кэш).
	 *
	 * @param string $ip       IP-адрес.
	 * @param array  $services Список сервисов (ip_api, ipapi_co). Пустой список = оба сервиса.
	 * @return int Term ID или 0.
	 */
	public static function lookup_city_id_by_ip( $ip, $services = array() ) {
		$ip = sanitize_text_field( (string) $ip );

		if ( ! $ip || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return 0;
		}

		// Нормализация списка сервисов: пустой список = оба сервиса.
		$services = self::normalize_services( $services );

		if ( empty( $services ) ) {
			return 0;
		}

		$key  = 'idpg_city_ip_' . md5( $ip );
		$lock = $key . '_lock';

		// Параллельный запрос уже выполняет поиск — не дёргаем API повторно.
		if ( false !== get_transient( $lock ) ) {
			return 0;
		}

		set_transient( $lock, 1, 20 );

		$term_id     = 0;
		$had_response = false;

		foreach ( $services as $service ) {
			$data = self::request_ip_data( $service, $ip );

			if ( empty( $data ) ) {
				continue;
			}

			$had_response = true;

			$region = isset( $data['region'] ) ? mb_strtolower( trim( (string) $data['region'] ), 'UTF-8' ) : '';
			$city   = isset( $data['city'] ) ? mb_strtolower( trim( (string) $data['city'] ), 'UTF-8' ) : '';

			$term_id = self::match_city_term( $city, $region );

			if ( $term_id ) {
				break;
			}
		}

		delete_transient( $lock );

		if ( $term_id ) {
			set_transient( $key, array(
				'status'  => 'ok',
				'term_id' => (int) $term_id,
			), self::IP_CACHE_TTL_OK );
		} elseif ( $had_response ) {
			set_transient( $key, array(
				'status'  => 'miss',
				'term_id' => 0,
			), self::IP_CACHE_TTL_MISS );
		} else {
			// Оба сервиса недоступны — короткий кэш, чтобы скоро повторить попытку.
			set_transient( $key, array(
				'status'  => 'fail',
				'term_id' => 0,
			), self::IP_CACHE_TTL_FAIL );
		}

		return (int) $term_id;
	}

	/**
	 * Был ли текущий город определён автоматически по IP.
	 *
	 * @return bool
	 */
	public static function was_detected_from_ip() {
		return self::$detected_from_ip;
	}

	/**
	 * Список сервисов, разрешённых для глобального автоопределения.
	 *
	 * Учитывает настройку `ip_service` виджета «Выбор города»:
	 * - виджет не размещён или настройка невалидна — оба сервиса;
	 * - 'none'  — автоопределение отключено;
	 * - 'ip_api' / 'ipapi_co' — используется только выбранный сервис.
	 *
	 * @return array Ключи сервисов.
	 */
	public static function allowed_ip_services() {
		static $allowed = null;

		if ( null !== $allowed ) {
			return $allowed;
		}

		$allowed = array( 'ip_api', 'ipapi_co' );

		// Виджет не размещён в активной области — используем оба сервиса.
		if ( ! is_active_widget( false, false, IDPG_City_Selector_Widget::ID_BASE ) ) {
			return $allowed;
		}

		$option = get_option( 'widget_' . IDPG_City_Selector_Widget::ID_BASE, array() );
		$option = is_array( $option ) ? $option : array();

		// Служебный ключ мультивиджета — не является инстансом.
		unset( $option['_multiwidget'] );

		// Последний сохранённый инстанс — актуальная настройка.
		$instance = end( $option );

		if ( ! is_array( $instance ) ) {
			return $allowed;
		}

		$ip_service = isset( $instance['ip_service'] ) ? $instance['ip_service'] : 'none';

		if ( 'none' === $ip_service ) {
			$allowed = array();
		} elseif ( in_array( $ip_service, array( 'ip_api', 'ipapi_co' ), true ) ) {
			$allowed = array( $ip_service );
		}

		return $allowed;
	}

	/**
	 * Нормализация списка сервисов автоопределения.
	 *
	 * Пустой или невалидный список означает «оба сервиса» (безопасный фолбэк).
	 *
	 * @param string|array $services Ключ сервиса или массив ключей.
	 * @return array
	 */
	private static function normalize_services( $services ) {
		$allowed = array( 'ip_api', 'ipapi_co' );

		if ( is_string( $services ) && '' !== $services ) {
			$services = array( $services );
		}

		if ( ! is_array( $services ) || empty( $services ) ) {
			return $allowed;
		}

		$services = array_values( array_intersect( array_map( 'strval', $services ), $allowed ) );

		return $services ? $services : $allowed;
	}

	/**
	 * Запрос к внешнему сервису определения города по IP.
	 *
	 * @param string $service Ключ сервиса: ip_api | ipapi_co.
	 * @param string $ip      IP-адрес.
	 * @return array Поля city/region или пустой массив.
	 */
	private static function request_ip_data( $service, $ip ) {
		$urls = array();

		if ( 'ip_api' === $service ) {
			// Бесплатный план ip-api.com не поддерживает HTTPS;
			// сначала пробуем HTTPS (для платных/прокси), при неудаче — HTTP.
			$urls = array(
				'https://ip-api.com/json/' . $ip . '?lang=ru&fields=status,regionName,city',
				'http://ip-api.com/json/' . $ip . '?lang=ru&fields=status,regionName,city',
			);
		} elseif ( 'ipapi_co' === $service ) {
			$urls = array( 'https://ipapi.co/' . $ip . '/json/' );
		} else {
			return array();
		}

		foreach ( $urls as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 3,
					'headers' => array(
						'User-Agent' => 'IDPG City Filter',
						'Accept'     => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $body ) ) {
				continue;
			}

			$data = array();

			if ( 'ip_api' === $service ) {
				if ( isset( $body['status'] ) && 'success' === $body['status'] ) {
					$data['region'] = isset( $body['regionName'] ) ? $body['regionName'] : '';
					$data['city']   = isset( $body['city'] ) ? $body['city'] : '';

					return $data;
				}
			} elseif ( 'ipapi_co' === $service ) {
				$data['region'] = isset( $body['region'] ) ? $body['region'] : '';
				$data['city']   = isset( $body['city'] ) ? $body['city'] : '';

				return $data;
			}
		}

		return array();
	}

	/**
	 * Поиск термина города по названию города или региона.
	 *
	 * Сначала ищется город (по имени), затем — регион (возвращается
	 * его первый город, при наличии — дефолтный). Сравнение регионов
	 * устойчиво к коротким/полным формам («Удмуртия» ↔ «Удмуртская
	 * Республика», «Татарстан» ↔ «Республика Татарстан»).
	 *
	 * @param string $city   Название города (нижний регистр).
	 * @param string $region Название региона (нижний регистр).
	 * @return int Term ID или 0.
	 */
	private static function match_city_term( $city, $region ) {
		$tree = self::get_city_tree();

		if ( '' !== $city ) {
			foreach ( $tree as $node ) {
				if ( ! empty( $node['children'] ) ) {
					foreach ( $node['children'] as $child ) {
						if ( mb_strtolower( $child['name'], 'UTF-8' ) === $city ) {
							return (int) $child['id'];
						}
					}
				} elseif ( mb_strtolower( $node['name'], 'UTF-8' ) === $city ) {
					return (int) $node['id'];
				}
			}
		}

		if ( '' !== $region ) {
			foreach ( $tree as $node ) {
				if ( ! empty( $node['children'] ) && self::region_names_match( $region, $node['name'] ) ) {
					// Если дефолтный город принадлежит этому региону — отдаём его.
					$default = defined( 'IDPG_DEFAULT_CITY_ID' ) ? (int) IDPG_DEFAULT_CITY_ID : 0;
					if ( $default ) {
						foreach ( $node['children'] as $child ) {
							if ( (int) $child['id'] === $default ) {
								return $default;
							}
						}
					}
					// Иначе — первый город региона.
					foreach ( $node['children'] as $child ) {
						return (int) $child['id'];
					}
				}
			}
		}

		return 0;
	}

	/**
	 * Сопоставление названия региона из IP-сервиса с названием термина.
	 *
	 * @param string $detected Регион от сервиса (нижний регистр).
	 * @param string $term     Название термина.
	 * @return bool
	 */
	public static function region_names_match( $detected, $term ) {
		$a = self::normalize_region_name( $detected );
		$b = self::normalize_region_name( $term );

		if ( '' === $a || '' === $b ) {
			return false;
		}

		if ( $a === $b ) {
			return true;
		}

		// Короткая и полная формы: «удмуртия» / «удмуртская республика» → общий корень.
		$len = min( mb_strlen( $a, 'UTF-8' ), mb_strlen( $b, 'UTF-8' ) );

		return $len >= 5 && mb_substr( $a, 0, 5, 'UTF-8' ) === mb_substr( $b, 0, 5, 'UTF-8' );
	}

	/**
	 * Нормализация названия региона: нижний регистр, ё→е,
	 * без служебных слов (республика/область/край/округ и т.п.).
	 *
	 * @param string $name Исходное название.
	 * @return string
	 */
	public static function normalize_region_name( $name ) {
		$name = mb_strtolower( trim( (string) $name ), 'UTF-8' );
		$name = str_replace( 'ё', 'е', $name );
		$name = preg_replace( '/[^\p{L}\p{Nd}\s]+/u', ' ', $name );

		$stop = array(
			'республика', 'республики', 'область', 'области', 'край', 'края',
			'округ', 'округа', 'автономный', 'автономная', 'автономного',
			'автономной', 'город', 'г', 'рф',
		);

		$parts = preg_split( '/\s+/u', trim( (string) $name ) );

		if ( is_array( $parts ) ) {
			$parts = array_diff( $parts, $stop );
			$name  = implode( ' ', $parts );
		}

		return trim( (string) $name );
	}

	/**
	 * Установка cookie с городом (30 дней).
	 *
	 * @param int $city_id Term ID.
	 */
	public static function set_city_cookie( $city_id ) {
		$city_id = absint( $city_id );

		if ( ! $city_id || ! term_exists( $city_id, 'city' ) ) {
			return;
		}

		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::COOKIE_NAME,
			(string) $city_id,
			array(
				'expires'  => time() + ( 30 * DAY_IN_SECONDS ),
				'path'     => '/',
				'samesite' => 'Lax',
				'httponly' => true,
			)
		);

		$_COOKIE[ self::COOKIE_NAME ] = (string) $city_id;
	}

	/**
	 * Определение текущего города.
	 *
	 * Порядок:
	 * 1. Cookie `idpg_selected_city` (ID термина таксономии city).
	 * 2. Константа IDPG_DEFAULT_CITY_ID (или значение по умолчанию).
	 *
	 * @return int Term ID текущего города.
	 */
	public static function get_current_city_id() {
		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return defined( 'IDPG_DEFAULT_CITY_ID' )
				? (int) IDPG_DEFAULT_CITY_ID
				: self::DEFAULT_CITY_ID;
		}

		$city_id = absint( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );

		if ( ! $city_id || ! term_exists( $city_id, 'city' ) ) {
			return defined( 'IDPG_DEFAULT_CITY_ID' )
				? (int) IDPG_DEFAULT_CITY_ID
				: self::DEFAULT_CITY_ID;
		}

		return $city_id;
	}

	/**
	 * Получение названия текущего города.
	 *
	 * @return string Название термина или пустая строка.
	 */
	public static function get_current_city_name() {
		$city_id = self::get_current_city_id();
		$term    = get_term( $city_id, 'city' );

		return ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
	}

	/**
	 * Дерево городов: регионы → города → районы.
	 *
	 * DEV-22: реализация вынесена в IDPG_Helpers::get_city_tree(), раньше
	 * она дублировалась здесь и в IDPG_City_Selector_Widget. Этот метод без
	 * аргументов и без сортировки сохранён как публичный — вызывается
	 * виджетом и шаблоном.
	 *
	 * @return array Массив регионов с вложенными children.
	 */
	public static function get_city_tree() {
		return IDPG_Helpers::get_city_tree( 'alpha' );
	}

	/**
	 * Универсальный фильтр основного запроса по городу.
	 *
	 * Добавляет tax_query для таксономии city на:
	 * - Архив CPT event
	 * - Главную страницу (is_front_page)
	 * - Архив CPT venue (площадки)
	 *
	 * Исключение: если в URL уже передан параметр таксономии city
	 * (в т.ч. URL вида /gorod/<slug>/), фильтр cookie не применяется
	 * (приоритет пользовательского выбора).
	 *
	 * @param WP_Query $query Экземпляр WP_Query (не глобальный, а переданный по ссылке).
	 */
	public function filter_by_city( $query ) {
		if ( is_admin() ) {
			return;
		}

		if ( ! $query->is_main_query() ) {
			return;
		}

		if ( $this->has_city_in_url() ) {
			return;
		}

		if ( $query->is_post_type_archive( 'event' ) || $query->is_home() ) {
			$this->apply_city_tax_query( $query );
		}

		$this->filter_front_page( $query );
		$this->filter_venues( $query );
	}

	/**
	 * Фильтрация главной страницы по городу.
	 *
	 * Применяется к главной (is_front_page), если она выводит записи
	 * в основном цикле. Статическая главная (show_on_front = page)
	 * выводит страницу без термина city — tax_query убрал бы её саму,
	 * поэтому не фильтруется. Лента постов (is_home) уже отфильтрована
	 * в filter_by_city().
	 *
	 * @param WP_Query $query Экземпляр WP_Query.
	 */
	public function filter_front_page( $query ) {
		if ( ! $query->is_front_page() ) {
			return;
		}

		// Лента постов (главная = лента) уже отфильтрована в filter_by_city().
		if ( $query->is_home() ) {
			return;
		}

		// Статическая главная: страница не привязана к городу — не фильтруем.
		if ( 'page' === get_option( 'show_on_front' ) ) {
			return;
		}

		$this->apply_city_tax_query( $query );
	}

	/**
	 * Фильтрация архива площадок (CPT venue) по городу.
	 *
	 * @param WP_Query $query Экземпляр WP_Query.
	 */
	public function filter_venues( $query ) {
		if ( ! $query->is_post_type_archive( 'venue' ) ) {
			return;
		}

		$this->apply_city_tax_query( $query );
	}

	/**
	 * Slug текущего города.
	 *
	 * Берёт ID города из cookie, конвертирует в slug термина таксономии city.
	 * Если cookie нет или термин не существует — возвращает дефолтный 'izhevsk'.
	 *
	 * @return string Slug термина city.
	 */
	public static function get_current_city_slug() {
		$city_id = self::get_current_city_id();
		$term    = get_term( $city_id, 'city' );

		if ( $term && ! is_wp_error( $term ) ) {
			return $term->slug;
		}

		return 'izhevsk';
	}

	/**
	 * Применение tax_query по городу к запросу.
	 *
	 * @param WP_Query $query Экземпляр WP_Query.
	 */
	private function apply_city_tax_query( $query ) {
		$city_slug = self::get_current_city_slug();

		if ( '' === $city_slug ) {
			return;
		}

		$city_tax_query = $query->get( 'tax_query' );

		if ( ! is_array( $city_tax_query ) ) {
			$city_tax_query = array();
		}

		$city_tax_query[] = array(
			'taxonomy' => 'city',
			'field'    => 'slug',
			'terms'    => $city_slug,
		);

		$query->set( 'tax_query', $city_tax_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
	}

	/**
	 * Проверка, есть ли в текущем URL параметр таксономии city.
	 *
	 * WordPress при фильтрации по таксономии добавляет в query_vars
	 * ключ `city` (имя таксономии). Он задаётся и для URL вида
	 * /gorod/<slug>/ (rewrite slug таксономии), и для ?city=<slug>.
	 * Если он уже задан — значит, пользователь явно выбрал город.
	 *
	 * @return bool True, если город уже задан в URL.
	 */
	public function has_city_in_url() {
		global $wp_query;

		// Новый механизм: город в пути (/afisha/{city}/, /venues/{city}/).
		if ( get_query_var( IDPG_City_URL::QVAR ) ) {
			return true;
		}

		if ( ! $wp_query instanceof WP_Query ) {
			return false;
		}

		$city_var = $wp_query->get( 'city' );

		return ! empty( $city_var );
	}

	/**
	 * AJAX-обработчик: установка cookie с городом.
	 *
	 * Принимает POST-параметр `city_id` (int). Валидирует,
	 * что термин существует в таксономии city, и ставит cookie.
	 *
	 * DEV-19: выбор из UI — это подтверждение, поэтому дополнительно
	 * ставится метка COOKIE_CONFIRMED.
	 */
	public function ajax_set_city() {
		check_ajax_referer( 'idpg_set_city', 'nonce' );

		$city_id = isset( $_POST['city_id'] ) ? absint( wp_unslash( $_POST['city_id'] ) ) : 0;

		if ( ! $city_id || ! term_exists( $city_id, 'city' ) ) {
			wp_send_json_error( array( 'message' => __( 'Неверный город.', 'idpg-core' ) ) );
		}

		self::set_chosen_city( $city_id );

		wp_send_json_success( array(
			'city_id'    => $city_id,
			'city_name'  => self::get_current_city_name(),
			'confirmed'  => self::is_city_confirmed(),
		) );
	}
}
