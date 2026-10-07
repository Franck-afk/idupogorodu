<?php
/**
 * REST-эндпоинты личного кабинета организатора для работы с событиями (DEV-42).
 *
 * До задачи кабинет организатора был «читалкой»: раздел «Мои события» и
 * «Дашборд» показывали заглушку, а REST-маршрутов для событий не было
 * вообще. Здесь они появились:
 *
 *   GET    /organizer/events                 — список своих событий с фильтрами
 *   GET    /organizer/events/{id}            — одно событие
 *   POST   /organizer/events                 — создать черновик
 *   PUT    /organizer/events/{id}            — обновить черновик или отклонённое
 *   POST   /organizer/events/{id}/submit     — отправить на модерацию
 *   DELETE /organizer/events/{id}            — удалить черновик или отклонённое
 *   GET    /organizer/stats                  — счётчики по своим событиям
 *
 * Ключевые решения:
 *
 *   1. **Скоуп — только свои события.** Автор определяется `post_author`, и
 *      чужое событие недоступно даже по прямому ID: 403, а не 404, чтобы не
 *      выдавать факт существования чужой записи.
 *   2. **Жизненный цикл.** `draft` и `rejected` можно править и удалять.
 *      `pending` и `publish` — нельзя: правка `pending` возвращает 409
 *      (событие на модерации), а `publish` — 403. Иначе организатор мог бы
 *      обойти модерацию, изменив событие прямо во время проверки.
 *   3. **Модерация не дублируется.** Правила «бесплатное публикуем сразу /
 *      платное требует билетные данные» уже реализованы в
 *      `IDPG_Event_Moderation` и применяются сами, когда им сказать
 *      обработать конкретный пост.
 *
 * @package IDPG_Core
 */

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_REST_Organizer
 */
class IDPG_REST_Organizer {

	/**
	 * Пространство имён маршрутов.
	 *
	 * @var string
	 */
	const NAMESPACE = 'idpg/v1';

	/**
	 * Статусы, которые организатор может видеть в списке.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'draft', 'pending', 'publish', 'rejected' );

	/**
	 * Статусы, которые организатор вправе править и удалять.
	 *
	 * @var string[]
	 */
	const EDITABLE_STATUSES = array( 'draft', 'rejected' );

	/**
	 * Статусы, из которых разрешена отправка на модерацию.
	 *
	 * @var string[]
	 */
	const SUBMITTABLE_STATUSES = array( 'draft', 'rejected' );

	/**
	 * Поля, обязательные для отправки на модерацию.
	 *
	 * @var string[]
	 */
	const REQUIRED_ON_SUBMIT = array( 'title', 'start_date', 'city' );

	/**
	 * Максимальный размер страницы в списке.
	 *
	 * @var int
	 */
	const MAX_PER_PAGE = 50;

	/**
	 * Имя поля галереи в Carbon Fields (как в `IDPG_Meta`).
	 *
	 * В базе поле лежит под ключом `_idpg_gallery` — с подчёркиванием и в
	 * собственном формате Carbon Fields. Плагин не дублирует константу из
	 * `IDPG_Meta`, чтобы не тянуть зависимость от модуля мета: имя здесь
	 * зафиксировано тестом `test-gallery_roundtrip_uses_carbon_storage`.
	 *
	 * @var string
	 */
	const GALLERY_FIELD = 'idpg_gallery';

	/**
	 * Имя complex-поля Carbon Fields с сеансами.
	 *
	 * Устройство хранения то же, что у галереи: в базе `_idpg_sessions` в
	 * формате CF, а не JSON. Организатор пишет сюда через
	 * `carbon_set_post_meta()`, обратное направление (CF → зеркало
	 * `_idpg_sessions_json`) выполняет `IDPG_Meta::mirror_sessions()`.
	 *
	 * @var string
	 */
	const SESSIONS_FIELD = 'idpg_sessions';

	/**
	 * Публичный статус «Отклонено модератором».
	 *
	 * Регистрируется здесь, потому что события организатора — единственное
	 * место, где состояние нужно. В коде до DEV-42 такого статуса не было
	 * вовсе: модерация уводила событие в `pending` и на этом останавливалась.
	 * Пока статус не выставляется из админки (см. карточку DEV-42 в
	 * `TZ_BACKLOG.md`), но переходы `rejected → draft/pending` уже работают.
	 */
	const STATUS_REJECTED = 'rejected';

	/**
	 * Метка публичного статуса.
	 */
	const LABEL_REJECTED = 'Отклонено модератором';

	/**
	 * Синглтон.
	 *
	 * @var IDPG_REST_Organizer|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_REST_Organizer
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Конструктор. Регистрирует маршруты и статус.
	 */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'init', array( $this, 'register_post_status' ) );
	}

	/**
	 * Регистрация публичного статуса «Отклонено модератором».
	 *
	 * @return void
	 */
	public function register_post_status() {
		register_post_status(
			self::STATUS_REJECTED,
			array(
				'label'                     => self::LABEL_REJECTED,
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'private'                   => false,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
			)
		);
	}

	/**
	 * Регистрация маршрутов.
	 *
	 * @return void
	 */
	public function register_routes() {
		$id_arg = array(
			'id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/organizer/events',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_events' ),
					'permission_callback' => array( $this, 'can_organizer' ),
					'args'                => $this->get_list_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_event' ),
					'permission_callback' => array( $this, 'can_organizer' ),
					'args'                => $this->get_write_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/organizer/events/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_event' ),
					'permission_callback' => array( $this, 'can_access_event' ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_event' ),
					'permission_callback' => array( $this, 'can_access_event' ),
					'args'                => array_merge( $id_arg, $this->get_write_args() ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_event' ),
					'permission_callback' => array( $this, 'can_access_event' ),
					'args'                => $id_arg,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/organizer/events/(?P<id>\d+)/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit_event' ),
				'permission_callback' => array( $this, 'can_access_event' ),
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/organizer/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'can_organizer' ),
			)
		);

		// Площадки организатора (DEV-50). Форма события предлагает создать
		// площадку, не выходя из кабинета, поэтому нужен собственный
		// маршрут: без него кнопка была бы мёртвой.
		register_rest_route(
			self::NAMESPACE,
			'/organizer/venues',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_venues' ),
					'permission_callback' => array( $this, 'can_organizer' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_venue' ),
					'permission_callback' => array( $this, 'can_organizer' ),
					'args'                => $this->get_venue_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/organizer/venues/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_venue' ),
					'permission_callback' => array( $this, 'can_access_venue' ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_venue' ),
					'permission_callback' => array( $this, 'can_access_venue' ),
					'args'                => array_merge( $id_arg, $this->get_venue_args() ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_venue' ),
					'permission_callback' => array( $this, 'can_access_venue' ),
					'args'                => $id_arg,
				),
			)
		);

		// Профиль организатора. GET/PUT работают с текущим пользователем:
		// чужие профили недоступны по построению.
		register_rest_route(
			self::NAMESPACE,
			'/organizer/profile',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_profile' ),
					'permission_callback' => array( $this, 'can_profile' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_profile' ),
					'permission_callback' => array( $this, 'can_profile' ),
					'args'                => array(
						// DEV-197: значения legal_type и inn проверяет
						// IDPG_Organizer_URL::sanitize_profile_input() — тот же
						// код, что и у no-JS формы в page-organizer.php.
						// Здесь стояли enum и pattern: схема отсекала запрос
						// до колбэка и отдавала rest_invalid_param, поэтому
						// REST и форма расходились в поведении — форма пустой
						// ИНН принимала, а REST на нём же отвечал 400.
						'legal_type' => array(
							'type' => 'string',
						),
						'inn'        => array(
							'type' => 'string',
						),
						'documents'  => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
					),
				),
			)
		);
	}

	// =========================================================================
	// Permission callbacks
	// =========================================================================

	/**
	 * Доступ к кабинету организатора: валидный nonce + авторизация + роль.
	 *
	 * Проверка nonce сделана так же, как в `IDPG_REST_Api::can_manage_events()`:
	 * REST сам по себе проверяет nonce только при cookie-аутентификации, а
	 * для Bearer-токена запрос с чужим заголовком прошёл бы. Явная проверка
	 * закрывает оба случая.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return true|WP_Error
	 */
	public function can_organizer( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'idpg_org_nonce_failed',
				__( 'Неверный или отсутствующий nonce-токен.', 'idpg-core' ),
				array( 'status' => 403 )
			);
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'idpg_org_not_logged_in',
				__( 'Необходима авторизация.', 'idpg-core' ),
				array( 'status' => 401 )
			);
		}

		if ( ! IDPG_Organizer_URL::user_is_organizer() ) {
			return new WP_Error(
				'idpg_org_forbidden',
				__( 'Раздел доступен только организаторам.', 'idpg-core' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Доступ к конкретному событию: права организатора плюс владение записью.
	 *
	 * Администратор и редактор (есть `edit_others_events`) получают доступ к
	 * любому событию — им это нужно для модерации.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return true|WP_Error
	 */
	public function can_access_event( $request ) {
		$base = $this->can_organizer( $request );

		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$id  = (int) $request->get_param( 'id' );
		$post = $id ? get_post( $id ) : null;

		if ( ! $post instanceof WP_Post || 'event' !== $post->post_type ) {
			return new WP_Error(
				'idpg_org_event_not_found',
				__( 'Событие не найдено.', 'idpg-core' ),
				array( 'status' => 404 )
			);
		}

		$user_id = get_current_user_id();

		if ( (int) $post->post_author === $user_id ) {
			return true;
		}

		if ( current_user_can( 'edit_others_events' ) ) {
			return true;
		}

		return new WP_Error(
			'idpg_org_forbidden_owner',
			__( 'Можно работать только со своими событиями.', 'idpg-core' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Доступ к конкретной площадке: права организатора плюс владение записью.
	 *
	 * Зеркалит `can_access_event()`. Отличие в одном: у площадки в проекте
	 * общий список (в базе их семь, ими заполняют несколько событий), но
	 * правило владения остаётся тем же — иначе организатор правил бы чужую
	 * площадку, а это бьёт по расписанию чужого события.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return true|WP_Error
	 */
	public function can_access_venue( $request ) {
		$base = $this->can_organizer( $request );

		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$id   = (int) $request->get_param( 'id' );
		$post = $id ? get_post( $id ) : null;

		if ( ! $post instanceof WP_Post || 'venue' !== $post->post_type ) {
			return new WP_Error(
				'idpg_org_venue_not_found',
				__( 'Площадка не найдена.', 'idpg-core' ),
				array( 'status' => 404 )
			);
		}

		$user_id = get_current_user_id();

		if ( (int) $post->post_author === $user_id ) {
			return true;
		}

		if ( current_user_can( 'edit_others_venues' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'idpg_org_forbidden_owner',
			__( 'Можно работать только со своими площадками.', 'idpg-core' ),
			array( 'status' => 403 )
		);
	}

	// =========================================================================
	// Профиль организатора
	// =========================================================================

	/**
	 * Права на профиль организатора.
	 *
	 * В отличие от `can_organizer()` заголовок X-WP-Nonce здесь не
	 * проверяется явно: маршрут работает с данными текущего пользователя,
	 * а cookie-аутентификацию WordPress проверяет сам — при отсутствии
	 * действительного nonce запрос не доходит до колбэка вовсе. Свой
	 * профиль по построению недоступен извне, поэтому дополнительной
	 * защиты от подделки запроса здесь не требуется.
	 *
	 * @return true|WP_Error
	 */
	public function can_profile() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'idpg_org_not_logged_in',
				__( 'Необходима авторизация.', 'idpg-core' ),
				array( 'status' => 401 )
			);
		}

		if ( current_user_can( 'manage_options' ) || IDPG_Organizer_URL::user_is_organizer() ) {
			return true;
		}

		return new WP_Error(
			'idpg_org_forbidden',
			__( 'Доступно только организаторам.', 'idpg-core' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * GET /organizer/profile — данные текущего организатора.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_profile( $request ) {
		$data = IDPG_Organizer_URL::get_profile_data();

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * PUT /organizer/profile — обновление профиля текущего организатора.
	 *
	 * Email здесь не принимается: смена адреса идёт отдельным
	 * подтверждённым письмом и не должна зависеть от этого маршрута.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_profile( $request ) {
		// Берём объединённый набор параметров: клиент присылает тело
		// запроса как JSON, но и PHP-fallback, и тесты передают его через
		// set_body_params(), поэтому get_json_params() здесь пуст.
		$json = $request->get_params();

		unset( $json['_locale'], $json['_wpnonce'] );

		if ( ! is_array( $json ) || ! $json ) {
			return new WP_Error(
				'idpg_org_profile_empty',
				__( 'Нет данных для сохранения профиля.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

		// Санитайзер принимает белый список полей, поэтому email и любые
		// неизвестные ключи отбрасываются здесь же.
		$clean = IDPG_Organizer_URL::sanitize_profile_input( $json );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$result = IDPG_Organizer_URL::update_profile( null, $clean );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Объект текущего пользователя в кэше WP_User остался со старым
		// display_name, поэтому профиль читаем заново из БД.
		$user_id = get_current_user_id();
		clean_user_cache( $user_id );

		// Ответ — полный профиль: клиенту не нужно делать второй GET,
		// а форма сразу видит фактически сохранённые значения.
		return new WP_REST_Response( IDPG_Organizer_URL::get_profile_data( $user_id ), 200 );
	}


	// =========================================================================
	// Схема аргументов
	// =========================================================================

	/**
	 * Разрешённая разметка кода вставки трансляции.
	 *
	 * Список один и тот же для записи (санитайзер) и для вывода
	 * (`wp_kses` в `single-event.php`). Если бы они расходились, то либо
	 * поле молчало бы пустым при сохранении, либо на выводе появилось бы
	 * лишнее.
	 *
	 * @return array Список тегов и атрибутов для wp_kses.
	 */
	public static function stream_embed_allowed_html() {
		return array(
			'iframe' => array(
				'src'            => true,
				'width'          => true,
				'height'         => true,
				'frameborder'    => true,
				'allow'          => true,
				'allowfullscreen' => true,
				'title'          => true,
			),
			'video' => array(
				'src'      => true,
				'width'    => true,
				'height'   => true,
				'controls' => true,
				'poster'   => true,
			),
			'a'      => array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
			),
			'div'    => array(
				'class' => true,
				'id'    => true,
				'style' => true,
			),
			'span'   => array( 'class' => true ),
			'p'      => array( 'class' => true ),
		);
	}

	/**
	 * Санитайзер кода вставки трансляции.
	 *
	 * @param string $value Значение.
	 * @return string
	 */
	public function sanitize_stream_embed( $value ) {
		return wp_kses( (string) $value, self::stream_embed_allowed_html() );
	}

	/**
	 * Схема аргументов площадки (DEV-50).
	 *
	 * Имена и наборы значений взяты из контейнера «Данные площадки» в
	 * `docs/carbon-fields-export.json`. Пустая строка в `venue_type`
	 * разрешена: в Carbon это первый вариант «— Не выбран —».
	 *
	 * Координаты и вместимость хранятся строками, а не числами: в Carbon
	 * это поля типа text, и приведение «6» к float потеряло бы исходное
	 * значение при пересохранении через админку.
	 *
	 * @return array
	 */
	private function get_venue_args() {
		return array(
			'title'         => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'content'       => array(
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			),
			'city'          => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'venue_type'    => array(
				'type'              => 'string',
				'enum'              => array( '', 'theater', 'cinema', 'museum', 'concert' ),
				'sanitize_callback' => 'sanitize_key',
				/*
				 * Без validate_callback перечисление не проверяется: ядро
				 * вызывает rest_validate_request_arg() только для аргументов,
				 * у которых он явно задан, а sanitize_key() из 'hack' сделает
				 * 'hack' — и значение уехало бы в базу как есть.
				 */
				'validate_callback' => 'rest_validate_request_arg',
			),
			'venue_address' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'venue_phone'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'venue_email'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
			),
			'venue_website' => array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			),
			'venue_capacity' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'venue_lat'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'venue_lng'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			// Постер площадки — нативное поле WP, в отличие от остальных
			// полей контейнера. Тип совпадает с одноимённым параметром
			// события, поэтому набор проверок тоже общий.
			'poster_id'     => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Аргументы списка событий.
	 *
	 * @return array
	 */
	private function get_list_args() {
		return array(
			'status'   => array(
				'type'              => 'string',
				'enum'              => self::STATUSES,
				'sanitize_callback' => 'sanitize_key',
			),
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => self::MAX_PER_PAGE,
				'sanitize_callback' => 'absint',
			),
			'search'   => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'orderby'  => array(
				'type'              => 'string',
				'enum'              => array( 'date', 'title', 'views' ),
				'default'           => 'date',
				'sanitize_callback' => 'sanitize_key',
				/* Значение уходит в WP_Query: без проверки приходит в запрос как есть. */
				'validate_callback' => 'rest_validate_request_arg',
			),
			'order'    => array(
				'type'              => 'string',
				'enum'              => array( 'asc', 'desc' ),
				'default'           => 'desc',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Аргументы создания и обновления события.
	 *
	 * Термы (city, category, age_rating) и площадка приходят ID — их
	 * существование проверяет save_event(), уже в обработчике: на момент
	 * санитайза ещё не известно, что запись создаётся.
	 *
	 * @return array
	 */
	private function get_write_args() {
		return array(
			'title'         => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'content'       => array(
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			),
			'excerpt'       => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'city'          => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'category'      => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'age_rating'    => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'venue'         => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'start_date'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'end_date'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'all_day'       => array(
				'type'              => 'string',
				'enum'              => array( 'yes', 'no' ),
				'sanitize_callback' => 'sanitize_key',
				/*
				 * Перечисление без validate_callback было декорацией:
				 * `all_day=hack` проходил с 201 и ложился в базу как есть.
				 * Набор значений совпадает с тем, что отправляет форма, —
				 * это радио с двумя позициями, скрипт подставляет 'no',
				 * когда ничего не отмечено. Пустой строки здесь нет
				 * намеренно: поле не отправляется, а не приходит пустым.
				 */
				'validate_callback' => 'rest_validate_request_arg',
			),
			'sessions_json' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'is_free'       => array(
				'type'              => 'string',
				'enum'              => array( 'yes', 'no' ),
				'sanitize_callback' => 'sanitize_key',
				/* См. комментарий у `all_day`: без проверки `is_free=hack` проходил. */
				'validate_callback' => 'rest_validate_request_arg',
			),
			'price_from'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'price_to'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'ticket_type'   => array(
				'type'              => 'string',
				/*
				 * Пустая строка — часть контракта, а не побочка: это опция
				 * «— Не нужны —», она же дефолт новой формы, и
				 * `organizer-event-form.js` шлёт значение select'а как есть.
				 * Без неё в перечислении сохранение события без билетов
				 * отваливалось бы с 400.
				 */
				'enum'              => array( '', 'external_link', 'widget_yandex' ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'ticket_url'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			),
			'widget_code'   => array(
				'type'              => 'string',
				/*
				 * Не sanitize_textarea_field(): он вызывает
				 * wp_strip_all_tags(), и код виджета сохранялся бы пустым.
				 * Но и хранить «как пришло» нельзя: код виджета выводится на
				 * публичной странице события без экранирования
				 * (IDPG_Template_Helper::get_ticket_button()), а писать его
				 * теперь может организатор, а не только администратор.
				 * wp_kses_post() оставляет безопасную подмножество разметки
				 * и вырезает <script> и обработчики событий.
				 *
				 * Следствие: настоящий embed Яндекс.Афиш начинается со
				 * <script> и будет вырезан. Осознанная плата за то, что поле
				 * доступно организатору.
				 */
				'sanitize_callback' => 'wp_kses_post',
			),
			'poster_id'     => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'gallery'       => array(
				'type'              => 'array',
				'items'             => array( 'type' => 'integer' ),
				'sanitize_callback' => array( $this, 'sanitize_gallery' ),
			),

			/*
			 * Поля, добавленные DEV-97 по схеме
			 * `docs/carbon-fields-export.json`. Имена параметров — без
			 * ведущего подчёркивания мета-ключа, как и у остальных полей
			 * выше. Опции `ticket_status` и `ticket_type` взяты из экспорта
			 * буквально, а не из ТЗ: `ticket_type` там имеет ровно два
			 * значения (`external_link`, `widget_yandex`).
			 *
			 * Перечисления ниже — из экспорта, плюс пустая строка там, где
			 * форма шлёт её по умолчанию.
			 */
			'ticket_status'  => array(
				'type'              => 'string',
				/*
				 * Пустая строка обязательна. Это не теория: без неё
				 * создание события было сломано. «— Не выбран —» — первая
				 * опция select'а и состояние новой формы, а
				 * `organizer-event-form.js` отправляет значение как есть,
				 * поэтому организатор получал 400 `rest_invalid_param` на
				 * обычном сохранении. Проверено на живом REST: с пустым
				 * `ticket_status` запрос отвергался.
				 */
				'enum'              => array( '', 'in_sale', 'sold_out', 'cancelled', 'postponed' ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'vip_price'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'yandex_form_url' => array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			),
			'address'        => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'banner_request' => array(
				'type'              => 'string',
				'enum'              => array( 'yes', 'no' ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'is_online'      => array(
				'type'              => 'string',
				'enum'              => array( 'yes', 'no' ),
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'stream_url'     => array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			),
			'video_trailer'  => array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			),
			'stream_embed'   => array(
				'type'              => 'string',
				/*
				 * HTML-вставка трансляции: sanitize_textarea_field() её бы
				 * уничтожил, а wp_kses_post() вырезал бы <iframe> — то есть
				 * именно то, ради чего поле и нужно. Поэтому используется
				 * тот же список тегов, что и при выводе на странице
				 * события (см. IDPG_REST_Organizer::stream_embed_allowed_html()).
				 */
				'sanitize_callback' => array( $this, 'sanitize_stream_embed' ),
			),
		);
	}

	/**
	 * Приведение галереи к массиву целых ID вложений.
	 *
	 * @param mixed $value Значение параметра.
	 * @return int[]
	 */
	public function sanitize_gallery( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();

		foreach ( $value as $item ) {
			$id = absint( $item );

			if ( $id ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	// =========================================================================
	// Чтение
	// =========================================================================

	/**
	 * Список событий текущего организатора.
	 *
	 * Запрос всегда ограничен `author`: чужие события не попадают в выдачу
	 * ни при каких фильтрах.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response
	 */
	public function get_events( $request ) {
		$args = array(
			'post_type'           => 'event',
			'post_status'         => $this->get_list_post_status( $request->get_param( 'status' ) ),
			'author'              => get_current_user_id(),
			'posts_per_page'      => min( self::MAX_PER_PAGE, max( 1, (int) $request->get_param( 'per_page' ) ) ),
			'paged'               => max( 1, (int) $request->get_param( 'page' ) ),
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
		);

		$search = (string) $request->get_param( 'search' );

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$orderby = (string) $request->get_param( 'orderby' );
		$order   = 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC';

		if ( 'title' === $orderby ) {
			$args['orderby'] = 'title';
			$args['order']   = $order;
		} elseif ( 'views' === $orderby ) {
			// Сортировка по просмотрам идёт по мета: orderby => meta_value_num.
			$args['meta_key'] = IDPG_Views::META_KEY; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['orderby']  = 'meta_value_num';
			$args['order']    = $order;
		} else {
			$args['order'] = $order;
		}

		$query = new WP_Query( $args );

		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->format_event( $post );
		}

		return new WP_REST_Response(
			array(
				'items' => $items,
				'total' => (int) $query->found_posts,
				'pages' => (int) $query->max_num_pages,
			),
			200
		);
	}

	/**
	 * Статусы для WP_Query.
	 *
	 * Без фильтра показываем все рабочие статусы, а не только
	 * опубликованные: организатор должен видеть и черновики.
	 *
	 * @param string $status Запрошенный статус.
	 * @return string[]
	 */
	private function get_list_post_status( $status ) {
		$status = (string) $status;

		if ( in_array( $status, self::STATUSES, true ) ) {
			return array( $status );
		}

		return self::STATUSES;
	}

	/**
	 * Одно событие.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response
	 */
	public function get_event( $request ) {
		$post = get_post( (int) $request->get_param( 'id' ) );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $this->format_event( $post ),
			),
			200
		);
	}

	/**
	 * Счётчики по событиям организатора.
	 *
	 * @return WP_REST_Response
	 */
	public function get_stats() {
		$user_id = get_current_user_id();
		$counts  = array();

		foreach ( self::STATUSES as $status ) {
			$counts[ $status ] = 0;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'event',
				'post_status'    => self::STATUSES,
				'author'         => $user_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $query->posts as $id ) {
			$status = (string) get_post_status( $id );

			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			}
		}

		$views  = 0;
		$clicks = 0;

		foreach ( $query->posts as $id ) {
			$views  += (int) get_post_meta( $id, IDPG_Views::META_KEY, true );
			$clicks += (int) get_post_meta( $id, IDPG_Views::META_CLICKS, true );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'counts'  => $counts,
					'total'   => (int) $query->post_count,
					'views'   => $views,
					'clicks'  => $clicks,
					'days'    => 30,
				),
				'message' => '',
			),
			200
		);
	}

	// =========================================================================
	// Запись
	// =========================================================================

	/**
	 * Создание черновика события.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_event( $request ) {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'event',
				'post_status'  => 'draft',
				'post_author'  => get_current_user_id(),
				'post_title'   => '',
				'post_content' => '',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$saved = $this->save_event( $post_id, $request );

		if ( is_wp_error( $saved ) ) {
			// Не оставляем пустой черновик, если данные не прошли проверку.
			wp_delete_post( $post_id, true );

			return $saved;
		}

		return $this->respond(
			$this->format_event( get_post( $post_id ) ),
			__( 'Черновик создан.', 'idpg-core' ),
			201
		);
	}

	/**
	 * Обновление черновика или отклонённого события.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_event( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		$gate = $this->guard_status( $post, self::EDITABLE_STATUSES );

		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$saved = $this->save_event( $id, $request );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return $this->respond(
			$this->format_event( get_post( $id ) ),
			__( 'Событие обновлено.', 'idpg-core' ),
			200
		);
	}

	/**
	 * Удаление черновика или отклонённого события.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_event( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		$gate = $this->guard_status( $post, self::EDITABLE_STATUSES );

		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$deleted = wp_delete_post( $id, true );

		if ( ! $deleted ) {
			return new WP_Error(
				'idpg_org_delete_failed',
				__( 'Не удалось удалить событие.', 'idpg-core' ),
				array( 'status' => 500 )
			);
		}

		return $this->respond(
			array( 'id' => $id ),
			__( 'Событие удалено.', 'idpg-core' ),
			200
		);
	}

	/**
	 * Отправка события на модерацию.
	 *
	 * Статус переводится в `pending`, а дальше срабатывает уже готовая
	 * логика `IDPG_Event_Moderation`: бесплатное событие публикуется сразу,
	 * платное с полными билетными данными остаётся на модерации, платное без
	 * данных возвращается в черновик с подсказкой организатору.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit_event( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		$gate = $this->guard_status( $post, self::SUBMITTABLE_STATUSES );

		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$missing = $this->get_missing_submit_fields( $id );

		if ( $missing ) {
			return new WP_Error(
				'idpg_org_submit_incomplete',
				sprintf(
					/* translators: %s: список недостающих полей через запятую. */
					__( 'Заполните обязательные поля: %s.', 'idpg-core' ),
					implode( ', ', $missing )
				),
				array( 'status' => 400 )
			);
		}

		// Ставим pending напрямую, а не publish: фильтр
		// enforce_pending_for_organizer() всё равно перевёл бы publish в
		// pending, но явный статус предсказуем.
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'pending',
			)
		);

		// Плагин moderation сам применяет правила бесплатного/платного.
		// Штатный триггер — Carbon Fields, которого на этом пути нет,
		// поэтому говорим ему обработать пост явно.
		if ( class_exists( 'IDPG_Event_Moderation' ) ) {
			IDPG_Event_Moderation::instance()->schedule_price_moderation( $id );
		}

		/**
		 * Действие после отправки события организатором на модерацию.
		 *
		 * @param int $post_id ID события.
		 */
		do_action( 'idpg_organizer_event_submitted', $id );

		return $this->respond(
			$this->format_event( get_post( $id ) ),
			__( 'Событие отправлено на модерацию.', 'idpg-core' ),
			200
		);
	}

	/**
	 * Проверка допустимого статуса для операции.
	 *
	 * `pending` отличается от `publish` намеренно: событие на модерации ещё
	 * можно отозвать обратно в черновик (409 — конфликт состояний), а
	 * опубликованное уже нельзя трогать из кабинета вовсе (403).
	 *
	 * @param WP_Post|null $post    Событие.
	 * @param string[]      $allowed Разрешённые статусы.
	 * @return true|WP_Error
	 */
	private function guard_status( $post, $allowed ) {
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'idpg_org_event_not_found',
				__( 'Событие не найдено.', 'idpg-core' ),
				array( 'status' => 404 )
			);
		}

		$status = (string) $post->post_status;

		if ( in_array( $status, $allowed, true ) ) {
			return true;
		}

		if ( 'pending' === $status ) {
			return new WP_Error(
				'idpg_org_event_locked',
				__( 'Событие на модерации — дождитесь решения редактора.', 'idpg-core' ),
				array( 'status' => 409 )
			);
		}

		return new WP_Error(
			'idpg_org_event_not_editable',
			__( 'Это событие уже нельзя изменить из кабинета.', 'idpg-core' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Незаполненные обязательные поля для отправки на модерацию.
	 *
	 * @param int $post_id ID события.
	 * @return string[]
	 */
	private function get_missing_submit_fields( $post_id ) {
		$post = get_post( $post_id );
		$missing = array();

		if ( ! $post instanceof WP_Post || '' === trim( (string) $post->post_title ) ) {
			$missing[] = __( 'название', 'idpg-core' );
		}

		$start = (string) get_post_meta( $post_id, '_idpg_start_date', true );

		if ( '' === trim( $start ) ) {
			$missing[] = __( 'дата начала', 'idpg-core' );
		}

		$city = wp_get_post_terms( $post_id, 'city', array( 'fields' => 'ids' ) );

		if ( is_wp_error( $city ) || ! $city ) {
			$missing[] = __( 'город', 'idpg-core' );
		}

		return $missing;
	}

	/**
	 * Сохранение полей события.
	 *
	 * @param int             $post_id ID события.
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_Post|WP_Error
	 */
	private function save_event( $post_id, $request ) {
		$params = $request->get_params();

		$postarr = array( 'ID' => $post_id );

		if ( array_key_exists( 'title', $params ) ) {
			$postarr['post_title'] = $params['title'];
		}

		if ( array_key_exists( 'content', $params ) ) {
			$postarr['post_content'] = $params['content'];
		}

		if ( array_key_exists( 'excerpt', $params ) ) {
			$postarr['post_excerpt'] = $params['excerpt'];
		}

		if ( count( $postarr ) > 1 ) {
			$updated = wp_update_post( $postarr, true );

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		$meta = $this->save_meta( $post_id, $params );

		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		$terms = $this->save_terms( $post_id, $params );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$media = $this->save_media( $post_id, $params );

		if ( is_wp_error( $media ) ) {
			return $media;
		}

		return get_post( $post_id );
	}

	/**
	 * Сохранение мета-полей события.
	 *
	 * @param int   $post_id ID события.
	 * @param array $params  Параметры запроса.
	 * @return true|WP_Error
	 */
	private function save_meta( $post_id, $params ) {
		$map = array(
			'_idpg_start_date'    => 'start_date',
			'_idpg_end_date'      => 'end_date',
			'_idpg_all_day'       => 'all_day',
			'_idpg_is_free'       => 'is_free',
			'_idpg_price_from'    => 'price_from',
			'_idpg_price_to'      => 'price_to',
			'_idpg_vip_price'     => 'vip_price',
			'_idpg_ticket_status' => 'ticket_status',
			'_idpg_ticket_url'    => 'ticket_url',
			'_idpg_yandex_form_url' => 'yandex_form_url',
			'_idpg_widget_code'   => 'widget_code',
			'_idpg_ticket_type'   => 'ticket_type',
			'_idpg_venue'         => 'venue',
			'_idpg_address'       => 'address',
			'_idpg_banner_request' => 'banner_request',
			'_idpg_is_online'     => 'is_online',
			'_idpg_stream_url'    => 'stream_url',
			'_idpg_video_trailer' => 'video_trailer',
			'_idpg_stream_embed'  => 'stream_embed',
		);

		foreach ( $map as $meta_key => $param ) {
			if ( ! array_key_exists( $param, $params ) ) {
				continue;
			}

			$value = $params[ $param ];

			// Площадка должна существовать и быть площадкой.
			if ( '_idpg_venue' === $meta_key && (int) $value ) {
				$venue = get_post( (int) $value );

				if ( ! $venue instanceof WP_Post || 'venue' !== $venue->post_type ) {
					return new WP_Error(
						'idpg_org_invalid_venue',
						__( 'Площадка не найдена.', 'idpg-core' ),
						array( 'status' => 400 )
					);
				}
			}

			update_post_meta( $post_id, $meta_key, $value );
		}

		/*
		 * Валюта организатора — всегда рубль (DEV-95).
		 *
		 * Поле `_idpg_currency` остаётся в модели Carbon для администратора,
		 * но организатор выбирать её не вправе: портал российский, оплата
		 * ведётся внешними ссылками, курс конвертировать нечем. В схеме
		 * аргументов `currency` поэтому нет — переданный клиентом параметр
		 * игнорируется, а не применяется.
		 *
		 * Пишем RUB только когда поле ещё пустое: событие, которому админ
		 * осознанно проставил USD, не должно терять валюту от того, что
		 * организатор отредактировал описание. Новое событие организатора
		 * при этом сразу получает явное значение, а не полагается на дефолт
		 * отрисовки карточки.
		 */
		$currency = (string) get_post_meta( $post_id, '_idpg_currency', true );

		if ( '' === trim( $currency ) ) {
			update_post_meta( $post_id, '_idpg_currency', 'RUB' );
		}

		// Сеансы принимаем только как валидный JSON: строка произвольного
		// содержания сломала бы разбор на фронтенде молча.
		if ( array_key_exists( 'sessions_json', $params ) ) {
			$raw  = trim( (string) $params['sessions_json'] );
			$rows = array();

			if ( '' !== $raw ) {
				$decoded = json_decode( $raw, true );

				if ( null === $decoded || ! is_array( $decoded ) ) {
					return new WP_Error(
						'idpg_org_invalid_sessions',
						__( 'Сеансы должны быть корректным JSON.', 'idpg-core' ),
						array( 'status' => 400 )
					);
				}

				$rows = $this->normalize_session_rows( $decoded );
			}

			/*
			 * Сеансы — complex-поле Carbon Fields (`idpg_sessions`), и в базе
			 * оно лежит в собственном формате CF. Поэтому пишем его через
			 * carbon_set_post_meta(), а не update_post_meta() в зеркало:
			 * раньше здесь сохранялось только зеркало `_idpg_sessions_json`,
			 * поэтому администратор в wp-admin не видел сеансов,
			 * заведённых организатором, — ровно тот же баг, что DEV-42
			 * закрыл для галереи.
			 *
			 * Зеркало после записи в CF пересобирает IDPG_Meta::mirror_sessions():
			 * так обе стороны читаются из одного источника и не могут
			 * разойтись в правилах нормализации. Плюс он снимает кэш
			 * карточки, который иначе показал бы старые сеансы.
			 */
			if ( function_exists( 'carbon_set_post_meta' ) ) {
				carbon_set_post_meta( $post_id, self::SESSIONS_FIELD, $rows );

				if ( class_exists( 'IDPG_Meta' ) ) {
					IDPG_Meta::mirror_sessions( $post_id );
				}

				return true;
			}

			// Carbon Fields недоступен — редактор всё равно не откроется,
			// поэтому пишем только зеркало, из которого читают шаблоны.
			if ( empty( $rows ) ) {
				delete_post_meta( $post_id, '_idpg_sessions_json' );
			} else {
				update_post_meta( $post_id, '_idpg_sessions_json', wp_json_encode( $rows ) );
			}
		}

		return true;
	}

	/**
	 * Приведение сеансов к виду complex-поля Carbon Fields.
	 *
	 * Правила повторяют `IDPG_Meta::mirror_sessions()`: строка без времени
	 * не является сеансом, значения обрезаются, ссылка проходит через
	 * esc_url_raw(). Дублировать их здесь приходится только для ветки без
	 * Carbon Fields — при его наличии зеркало собирает сам mirror_sessions().
	 *
	 * @param array $rows Декодированный JSON сеансов.
	 * @return array Список строк {time, price, url}.
	 */
	private function normalize_session_rows( $rows ) {
		$normalized = array();

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$time = isset( $row['time'] ) ? trim( (string) $row['time'] ) : '';

			if ( '' === $time ) {
				continue;
			}

			$normalized[] = array(
				'time'  => $time,
				'price' => isset( $row['price'] ) ? trim( (string) $row['price'] ) : '',
				'url'   => isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '',
			);
		}

		return $normalized;
	}

	/**
	 * Сохранение термов (город, категория, возрастной рейтинг).
	 *
	 * @param int   $post_id ID события.
	 * @param array $params  Параметры запроса.
	 * @return true|WP_Error
	 */
	private function save_terms( $post_id, $params ) {
		$map = array(
			'city'        => 'city',
			'category'    => 'event_category',
			'age_rating'  => 'age_rating',
		);

		foreach ( $map as $param => $taxonomy ) {
			if ( ! array_key_exists( $param, $params ) ) {
				continue;
			}

			$term_id = (int) $params[ $param ];

			if ( ! $term_id ) {
				wp_set_object_terms( $post_id, array(), $taxonomy, false );
				continue;
			}

			$term = get_term( $term_id, $taxonomy );

			if ( ! $term instanceof WP_Term ) {
				return new WP_Error(
					'idpg_org_invalid_term',
					__( 'Указан несуществующий терм.', 'idpg-core' ),
					array( 'status' => 400 )
				);
			}

			wp_set_object_terms( $post_id, array( (int) $term->term_id ), $taxonomy, false );
		}

		return true;
	}

	/**
	 * Сохранение медиа: постер и галерея.
	 *
	 * @param int   $post_id ID события.
	 * @param array $params  Параметры запроса.
	 * @return true|WP_Error
	 */
	private function save_media( $post_id, $params ) {
		if ( array_key_exists( 'poster_id', $params ) ) {
			$poster_id = (int) $params['poster_id'];

			if ( $poster_id ) {
				$attachment = get_post( $poster_id );

				if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
					return new WP_Error(
						'idpg_org_invalid_poster',
						__( 'Постер должен быть файлом из медиатеки.', 'idpg-core' ),
						array( 'status' => 400 )
					);
				}
			}

			// Постер хранится штатной featured image, а не кастомной метой.
			if ( $poster_id ) {
				set_post_thumbnail( $post_id, $poster_id );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}

		if ( array_key_exists( 'gallery', $params ) ) {
			$ids = array();

			foreach ( (array) $params['gallery'] as $id ) {
				$attachment = get_post( (int) $id );

				if ( $attachment instanceof WP_Post && 'attachment' === $attachment->post_type ) {
					$ids[] = (int) $id;
				}
			}

			// Галерея — поле Carbon Fields (`idpg_gallery`), и хранится оно
			// в базе под ключом `_idpg_gallery` в собственном формате CF
			// (для media_gallery — JSON-массив ID, для пустого значения с
			// суффиксом `|||0|_empty`). Поэтому пишем и читаем через
			// carbon_get_post_meta(), а не через get_post_meta() с ключом
			// без подчёркивания: иначе данные из кабинета были бы не видны
			// в редакторе и наоборот.
			if ( function_exists( 'carbon_set_post_meta' ) ) {
				carbon_set_post_meta( $post_id, self::GALLERY_FIELD, $ids );
			} else {
				// Плагин CF выключен — пишем напрямую, редактор в этом
				// состоянии всё равно недоступен.
				update_post_meta( $post_id, '_' . self::GALLERY_FIELD, $ids );
			}
		}

		return true;
	}

	// =========================================================================
	// Площадки организатора (DEV-50)
	// =========================================================================

	/**
	 * Карта «параметр → мета-ключ» для площадки.
	 *
	 * Мета-ключи — строго те, что заведены в контейнере «Данные площадки»
	 * Carbon Fields, иначе правильная площадка в админке выглядела бы
	 * пустой.
	 *
	 * @var array
	 */
	const VENUE_META_MAP = array(
		'venue_type'    => '_idpg_venue_type',
		'venue_address' => '_idpg_venue_address',
		'venue_phone'   => '_idpg_venue_phone',
		'venue_email'   => '_idpg_venue_email',
		'venue_website' => '_idpg_venue_website',
		'venue_capacity' => '_idpg_venue_capacity',
		'venue_lat'     => '_idpg_venue_lat',
		'venue_lng'     => '_idpg_venue_lng',
	);

	/**
	 * Список площадок организатора.
	 *
	 * Отдаёт собственные площадки плюс те, что уже есть в базе: общий
	 * список нужен, чтобы организатор не плодил дубли существующих.
	 *
	 * @return WP_REST_Response
	 */
	public function get_venues() {
		$user_id = get_current_user_id();

		$mine = get_posts(
			array(
				'post_type'      => 'venue',
				'post_status'    => 'any',
				'author'         => $user_id,
				'posts_per_page' => self::MAX_PER_PAGE,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$items = array();

		foreach ( $mine as $venue ) {
			$data                = $this->format_venue( $venue );
			$data['is_own']      = true;
			$items[ $venue->ID ] = $data;
		}

		$shared = get_posts(
			array(
				'post_type'      => 'venue',
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_PER_PAGE,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post__not_in'   => array_keys( $items ),
			)
		);

		foreach ( $shared as $venue ) {
			$data                = $this->format_venue( $venue );
			$data['is_own']      = false;
			$items[ $venue->ID ] = $data;
		}

		ksort( $items );

		return new WP_REST_Response(
			array(
				'items' => array_values( $items ),
				'total' => count( $items ),
			),
			200
		);
	}

	/**
	 * Одна площадка.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response
	 */
	public function get_venue( $request ) {
		$venue_id = (int) $request->get_param( 'id' );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $this->format_venue( get_post( $venue_id ) ),
				'message' => '',
			),
			200
		);
	}

	/**
	 * Создание площадки.
	 *
	 * Черновик разрешён: площадка может быть не заполнена полностью, но
	 * без названия её невозможно выбрать из списка событий.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_venue( $request ) {
		$invalid = $this->validate_venue_input( $request );

		if ( is_wp_error( $invalid ) ) {
			return $invalid;
		}

		if ( ! current_user_can( 'edit_venues' ) ) {
			return new WP_Error(
				'idpg_org_forbidden',
				__( 'Недостаточно прав для создания площадки.', 'idpg-core' ),
				array( 'status' => 403 )
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'venue',
				'post_title'   => trim( (string) $request->get_param( 'title' ) ),
				'post_content' => (string) $request->get_param( 'content' ),
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->save_venue_meta( (int) $post_id, $request );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $this->format_venue( get_post( (int) $post_id ) ),
				'message' => __( 'Площадка создана.', 'idpg-core' ),
			),
			201
		);
	}

	/**
	 * Правка площадки.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_venue( $request ) {
		$venue_id = (int) $request->get_param( 'id' );

		$invalid = $this->validate_venue_input( $request );

		if ( is_wp_error( $invalid ) ) {
			return $invalid;
		}

		$updated = wp_update_post(
			array(
				'ID'           => $venue_id,
				'post_title'   => trim( (string) $request->get_param( 'title' ) ),
				'post_content' => (string) $request->get_param( 'content' ),
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$this->save_venue_meta( $venue_id, $request );

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $this->format_venue( get_post( $venue_id ) ),
				'message' => __( 'Площадка сохранена.', 'idpg-core' ),
			),
			200
		);
	}

	/**
	 * Проверка обязательных полей площадки.
	 *
	 * Общая для создания и правки, иначе набор требований разошёлся бы
	 * между двумя путями: создать можно было бы без адреса, а правка —
	 * нет.
	 *
	 * Обязательны название, город и адрес. Город добавлен сверх формулировки
	 * ТЗ потому, что площадка без города не попадает ни в одну подборку
	 * (все выборки фильтруются по таксономии `city`), то есть такая запись
	 * была бы невидима на сайте. Адрес обязателен по ТЗ.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return true|WP_Error
	 */
	private function validate_venue_input( $request ) {
		if ( '' === trim( (string) $request->get_param( 'title' ) ) ) {
			return new WP_Error(
				'idpg_org_venue_title_required',
				__( 'Укажите название площадки.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

		if ( ! (int) $request->get_param( 'city' ) ) {
			return new WP_Error(
				'idpg_org_venue_city_required',
				__( 'Укажите город площадки.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === trim( (string) $request->get_param( 'venue_address' ) ) ) {
			return new WP_Error(
				'idpg_org_venue_address_required',
				__( 'Укажите адрес площадки.', 'idpg-core' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Сохранение мета-полей площадки, её города и постера.
	 *
	 * @param int             $venue_id ID площадки.
	 * @param WP_REST_Request $request  Запрос.
	 * @return void
	 */
	private function save_venue_meta( $venue_id, $request ) {
		foreach ( self::VENUE_META_MAP as $param => $meta_key ) {
			if ( ! $request->get_param( $param ) && ! $request->has_param( $param ) ) {
				continue;
			}

			update_post_meta( $venue_id, $meta_key, (string) $request->get_param( $param ) );
		}

		if ( $request->has_param( 'city' ) ) {
			$city = (int) $request->get_param( 'city' );

			wp_set_object_terms( $venue_id, $city ? array( $city ) : array(), 'city', false );
		}

		if ( $request->has_param( 'poster_id' ) ) {
			$poster_id = (int) $request->get_param( 'poster_id' );
			$poster    = $poster_id ? get_post( $poster_id ) : null;

			// Принимаем только настоящее вложение: иначе в качестве постера
			// можно было бы подставить произвольную запись.
			if ( $poster && 'attachment' === $poster->post_type ) {
				set_post_thumbnail( $venue_id, $poster_id );
			} elseif ( ! $poster_id ) {
				delete_post_thumbnail( $venue_id );
			}
		}
	}

	/**
	 * Удаление площадки.
	 *
	 * Отказ с кодом 409, если на площадку ссылается хотя бы одно событие:
	 * удаление обнулило бы `_idpg_venue` у чужого события, и оно молча
	 * потеряло бы место проведения. 409 выбран потому, что конфликт здесь
	 * не в запросе, а в состоянии данных.
	 *
	 * @param WP_REST_Request $request Запрос.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_venue( $request ) {
		$venue_id = (int) $request->get_param( 'id' );

		$linked = get_posts(
			array(
				'post_type'      => 'event',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_idpg_venue',
						'value' => $venue_id,
					),
				),
			)
		);

		if ( ! empty( $linked ) ) {
			return new WP_Error(
				'idpg_org_venue_in_use',
				__( 'Площадка используется в событиях — сначала уберите её оттуда.', 'idpg-core' ),
				array( 'status' => 409 )
			);
		}

		$deleted = wp_delete_post( $venue_id, true );

		if ( ! $deleted ) {
			return new WP_Error(
				'idpg_org_venue_delete_failed',
				__( 'Не удалось удалить площадку.', 'idpg-core' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array( 'id' => $venue_id ),
				'message' => __( 'Площадка удалена.', 'idpg-core' ),
			),
			200
		);
	}

	/**
	 * Формат площадки для ответа API.
	 *
	 * @param WP_Post|null $post Площадка.
	 * @return array
	 */
	private function format_venue( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		$data = array(
			'id'       => (int) $post->ID,
			'title'    => (string) $post->post_title,
			'content'  => (string) $post->post_content,
			'city'     => $this->term_ids( $post->ID, 'city' ),
			'poster_id' => (int) get_post_thumbnail_id( $post->ID ),
			'edit_url' => (string) get_edit_post_link( $post->ID, 'raw' ),
		);

		foreach ( self::VENUE_META_MAP as $param => $meta_key ) {
			$data[ $param ] = (string) get_post_meta( $post->ID, $meta_key, true );
		}

		return $data;
	}

	// =========================================================================
	// Форматирование ответа
	// =========================================================================

	/**
	 * Единый формат события для всех маршрутов.
	 *
	 * @param WP_Post|null $post Событие.
	 * @return array
	 */
	private function format_event( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		$gallery = $this->get_gallery( $post->ID );

		return array(
			'id'           => (int) $post->ID,
			'title'        => (string) $post->post_title,
			'content'      => (string) $post->post_content,
			'excerpt'      => (string) $post->post_excerpt,
			'status'       => (string) $post->post_status,
			'permalink'    => (string) get_permalink( $post->ID ),
			'date'         => (string) $post->post_date,
			'author'       => (int) $post->post_author,
			'start_date'   => (string) get_post_meta( $post->ID, '_idpg_start_date', true ),
			'end_date'     => (string) get_post_meta( $post->ID, '_idpg_end_date', true ),
			'all_day'      => (string) get_post_meta( $post->ID, '_idpg_all_day', true ),
			'sessions_json' => (string) get_post_meta( $post->ID, '_idpg_sessions_json', true ),
			'venue'        => (int) get_post_meta( $post->ID, '_idpg_venue', true ),
			'is_free'      => (string) get_post_meta( $post->ID, '_idpg_is_free', true ),
			'price_from'   => (string) get_post_meta( $post->ID, '_idpg_price_from', true ),
			'price_to'     => (string) get_post_meta( $post->ID, '_idpg_price_to', true ),
			'vip_price'    => (string) get_post_meta( $post->ID, '_idpg_vip_price', true ),
		'ticket_status' => IDPG_Helpers::get_ticket_status( $post->ID ),
		'ticket_type'  => IDPG_Helpers::get_ticket_type( $post->ID ),
		'ticket_url'   => IDPG_Helpers::get_ticket_url( $post->ID ),
			'yandex_form_url' => (string) get_post_meta( $post->ID, '_idpg_yandex_form_url', true ),
			'widget_code'  => (string) get_post_meta( $post->ID, '_idpg_widget_code', true ),
			'address'      => (string) get_post_meta( $post->ID, '_idpg_address', true ),
			'banner_request' => (string) get_post_meta( $post->ID, '_idpg_banner_request', true ),
			'is_online'    => (string) get_post_meta( $post->ID, '_idpg_is_online', true ),
			'stream_url'   => (string) get_post_meta( $post->ID, '_idpg_stream_url', true ),
			'video_trailer' => (string) get_post_meta( $post->ID, '_idpg_video_trailer', true ),
			'stream_embed' => (string) get_post_meta( $post->ID, '_idpg_stream_embed', true ),
			'poster_id'    => (int) get_post_thumbnail_id( $post->ID ),
			'gallery'      => is_array( $gallery ) ? array_map( 'absint', $gallery ) : array(),
			'city'         => $this->term_ids( $post->ID, 'city' ),
			'category'     => $this->term_ids( $post->ID, 'event_category' ),
			'age_rating'   => $this->term_ids( $post->ID, 'age_rating' ),
			'views'        => (int) get_post_meta( $post->ID, IDPG_Views::META_KEY, true ),
			'clicks'       => (int) get_post_meta( $post->ID, IDPG_Views::META_CLICKS, true ),
		);
	}

	/**
	 * ID вложений галереи события.
	 *
	 * Читается через Carbon Fields: поле `idpg_gallery` — media_gallery, и
	 * CF хранит его в собственном формате. Обычный get_post_meta() с ключом
	 * `_idpg_gallery` вернул бы строку служебного вида для пустой галереи
	 * (`...|||0|_empty`) или не нашёл бы значение вовсе.
	 *
	 * @param int $post_id ID события.
	 * @return int[]
	 */
	private function get_gallery( $post_id ) {
		$raw = function_exists( 'carbon_get_post_meta' )
			? carbon_get_post_meta( $post_id, self::GALLERY_FIELD )
			: get_post_meta( $post_id, '_' . self::GALLERY_FIELD, true );

		// Пустое значение CF приходит строкой-заглушкой, а не массивом.
		if ( is_string( $raw ) ) {
			return array();
		}

		return is_array( $raw ) ? array_map( 'absint', $raw ) : array();
	}

	/**
	 * ID термов таксономии для события.
	 *
	 * @param int    $post_id  ID события.
	 * @param string $taxonomy Таксономия.
	 * @return int[]
	 */
	private function term_ids( $post_id, $taxonomy ) {
		$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );

		return is_wp_error( $terms ) ? array() : array_map( 'absint', $terms );
	}

	/**
	 * Формирование стандартного ответа.
	 *
	 * @param array  $data    Данные.
	 * @param string $message Сообщение.
	 * @param int    $status  HTTP-код.
	 * @return WP_REST_Response
	 */
	private function respond( $data, $message, $status ) {
		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
				'message' => $message,
			),
			$status
		);
	}
}
