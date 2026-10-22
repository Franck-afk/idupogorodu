<?php
/**
 * Нативные метабоксы полей события и вкладка «События» на странице IDPG.
 *
 * Заменяет Carbon Fields: все поля события (даты, сеансы, место,
 * билеты, медиа) выводятся нативными метабоксами на экране
 * редактирования события и формой на вкладке «События» страницы
 * «IDPG» (для администраторов).
 *
 * Данные хранятся в тех же мета-ключах `_idpg_*`, что и раньше,
 * — шаблоны и REST не меняются.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Event_Fields
 */
class IDPG_Event_Fields {

	/**
	 * Слаг вкладки на странице «IDPG».
	 *
	 * @var string
	 */
	const TAB_SLUG = 'events';

	/**
	 * Действие nonce для метабокса.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'idpg_save_event_fields';

	/**
	 * Имя поля nonce для метабокса.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'idpg_event_fields_nonce';

	/**
	 * Действие nonce для вкладки.
	 *
	 * @var string
	 */
	const TAB_NONCE_ACTION = 'idpg_save_event_tab';

	/**
	 * Имя поля nonce для вкладки.
	 *
	 * @var string
	 */
	const TAB_NONCE_NAME = 'idpg_event_tab_nonce';

	/**
	 * Синглтон.
	 *
	 * @var IDPG_Event_Fields|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Event_Fields
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор. Привязывает методы к хукам WordPress.
	 */
	private function __construct() {
		// Метабоксы на экране редактирования события.
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		// Сохранение полей при сохранении поста.
		add_action( 'save_post_event', array( $this, 'save_post_fields' ), 20, 3 );
		// Вкладка «События» на странице «IDPG».
		add_filter( 'idpg_widget_areas_admin_tabs', array( $this, 'register_admin_tab' ) );
		add_action( 'admin_init', array( $this, 'handle_tab_save' ) );
		// Скрипты и стили для метабоксов и вкладки.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	// =========================================================================
	// Определения полей
	// =========================================================================

	/**
	 * Группы полей события.
	 *
	 * Каждая группа соответствует бывшему контейнеру Carbon Fields и
	 * выводится отдельным метабоксом. Поля хранятся в мета-ключах
	 * `_idpg_*` (те же, что использовал Carbon Fields).
	 *
	 * @return array Массив групп: id, title, context, priority, fields.
	 */
	public static function get_field_groups() {
		return array(
			array(
				'id'       => 'main',
				'title'    => __( 'Основная информация', 'idpg-core' ),
				'context'  => 'normal',
				'priority' => 'high',
				'fields'   => array(
					array(
						'id'       => 'idpg_start_date',
						'key'      => '_idpg_start_date',
						'type'     => 'date',
						'label'    => __( 'Дата начала', 'idpg-core' ),
						'help'     => __( 'Укажите дату начала. Поле обязательно для публикации.', 'idpg-core' ),
						'sanitize' => 'text',
					),
					array(
						'id'       => 'idpg_end_date',
						'key'      => '_idpg_end_date',
						'type'     => 'date',
						'label'    => __( 'Дата окончания', 'idpg-core' ),
						'help'     => __( 'Оставьте пустым для события одного дня.', 'idpg-core' ),
						'sanitize' => 'text',
					),
					array(
						'id'       => 'idpg_all_day',
						'key'      => '_idpg_all_day',
						'type'     => 'checkbox',
						'label'    => __( 'Весь день', 'idpg-core' ),
						'help'     => __( 'Событие идёт весь день — время сеансов не указывается.', 'idpg-core' ),
						'sanitize' => 'checkbox',
					),
					array(
						'id'    => 'idpg_sessions',
						'key'   => '_idpg_sessions',
						'type'  => 'sessions',
						'label' => __( 'Сеансы', 'idpg-core' ),
						'help'  => __( 'Время начала, цена и ссылка на билеты для каждого сеанса.', 'idpg-core' ),
					),
				),
			),
			array(
				'id'       => 'place',
				'title'    => __( 'Событие: место и продвижение', 'idpg-core' ),
				'context'  => 'side',
				'priority' => 'high',
				'fields'   => array(
					array(
						'id'       => 'idpg_venue',
						'key'      => '_idpg_venue',
						'type'     => 'select',
						'label'    => __( 'Площадка', 'idpg-core' ),
						'help'     => __( 'Выберите площадку. Если её нет — укажите адрес вручную.', 'idpg-core' ),
						'sanitize' => 'int',
						'options'  => array( __CLASS__, 'get_venue_options' ),
					),
					array(
						'id'       => 'idpg_address',
						'key'      => '_idpg_address',
						'type'     => 'text',
						'label'    => __( 'Адрес', 'idpg-core' ),
						'help'     => __( 'Заполняется, если адрес отличается от адреса площадки.', 'idpg-core' ),
						'sanitize' => 'text',
					),
					array(
						'id'       => 'idpg_is_online',
						'key'      => '_idpg_is_online',
						'type'     => 'checkbox',
						'label'    => __( 'Онлайн-событие', 'idpg-core' ),
						'help'     => __( 'Отметьте, если событие проходит онлайн (трансляция в интернете).', 'idpg-core' ),
						'sanitize' => 'checkbox',
					),
					array(
						'id'       => 'idpg_stream_url',
						'key'      => '_idpg_stream_url',
						'type'     => 'url',
						'label'    => __( 'Ссылка на трансляцию', 'idpg-core' ),
						'help'     => __( 'Ссылка на страницу/трансляцию, куда перейдут зрители.', 'idpg-core' ),
						'sanitize' => 'url',
					),
					array(
						'id'       => 'idpg_banner_request',
						'key'      => '_idpg_banner_request',
						'type'     => 'checkbox',
						'label'    => __( 'Подать заявку на показ в баннере на главной', 'idpg-core' ),
						'help'     => __( 'Показ платный. После отправки заявки администратор свяжется с вами для оплаты; событие попадёт в баннер после подтверждения.', 'idpg-core' ),
						'sanitize' => 'checkbox',
					),
					// Системные поля — только администраторы.
					array(
						'id'         => 'idpg_source',
						'key'        => '_idpg_source',
						'type'       => 'text',
						'label'      => __( 'Источник импорта', 'idpg-core' ),
						'help'       => __( 'Название сервиса, из которого импортировано событие.', 'idpg-core' ),
						'sanitize'   => 'text',
						'admin_only' => true,
					),
					array(
						'id'         => 'idpg_promoted',
						'key'        => '_idpg_promoted',
						'type'       => 'checkbox',
						'label'      => __( 'Закрепить в топе', 'idpg-core' ),
						'help'       => __( 'Событие поднимается наверх в списках и подборках.', 'idpg-core' ),
						'sanitize'   => 'checkbox',
						'admin_only' => true,
					),
					array(
						'id'         => 'idpg_pin_expires',
						'key'        => '_idpg_pin_expires',
						'type'       => 'date',
						'label'      => __( 'Закрепить до', 'idpg-core' ),
						'help'       => __( 'Дата автоматического снятия закрепления. Пусто — закрепление бессрочное.', 'idpg-core' ),
						'sanitize'   => 'text',
						'admin_only' => true,
					),
					array(
						'id'         => 'idpg_hide_from_banner',
						'key'        => '_idpg_hide_from_banner',
						'type'       => 'checkbox',
						'label'      => __( 'Скрыть из баннера на главной', 'idpg-core' ),
						'help'       => __( 'Событие не будет показываться в hero-баннере на главной странице.', 'idpg-core' ),
						'sanitize'   => 'checkbox',
						'admin_only' => true,
					),
					array(
						'id'         => 'idpg_hide_full_banner',
						'key'        => '_idpg_hide_full_banner',
						'type'       => 'checkbox',
						'label'      => __( 'Скрыть баннер полностью', 'idpg-core' ),
						'help'       => __( 'Событие не будет показано в баннере и не будет заменено следующим — баннер на главной скроется полностью.', 'idpg-core' ),
						'sanitize'   => 'checkbox',
						'admin_only' => true,
					),
					array(
						'id'         => 'idpg_views',
						'key'        => '_idpg_views',
						'type'       => 'number',
						'label'      => __( 'Просмотры', 'idpg-core' ),
						'help'       => __( 'Счётчик заполняется автоматически.', 'idpg-core' ),
						'sanitize'   => 'int',
						'readonly'   => true,
						'admin_only' => true,
					),
					array(
						'id'         => 'idpg_clicks',
						'key'        => '_idpg_clicks',
						'type'       => 'number',
						'label'      => __( 'Клики', 'idpg-core' ),
						'help'       => __( 'Счётчик заполняется автоматически.', 'idpg-core' ),
						'sanitize'   => 'int',
						'readonly'   => true,
						'admin_only' => true,
					),
					array(
						'id'         => 'idpg_banner_approved',
						'key'        => '_idpg_banner_approved',
						'type'       => 'checkbox',
						'label'      => __( 'Показ в баннере оплачен и подтверждён', 'idpg-core' ),
						'help'       => __( 'Отметьте после получения оплаты за показ. Событие появится в баннере на главной (приоритет перед автоматическим показом).', 'idpg-core' ),
						'sanitize'   => 'checkbox',
						'admin_only' => true,
					),
				),
			),
			array(
				'id'       => 'tickets',
				'title'    => __( 'Билеты и цены', 'idpg-core' ),
				'context'  => 'normal',
				'priority' => 'high',
				'fields'   => array(
					array(
						'id'       => 'idpg_ticket_status',
						'key'      => '_idpg_ticket_status',
						'type'     => 'select',
						'label'    => __( 'Статус билетов', 'idpg-core' ),
						'help'     => __( 'Укажите состояние продажи билетов — например «Распроданы», если мест нет.', 'idpg-core' ),
						'sanitize' => 'text',
						'default'  => 'in_sale',
						'options'  => array(
							'in_sale'   => __( 'В продаже', 'idpg-core' ),
							'sold_out'  => __( 'Распроданы', 'idpg-core' ),
							'cancelled' => __( 'Отменено', 'idpg-core' ),
							'postponed' => __( 'Перенесено', 'idpg-core' ),
						),
					),
					array(
						'id'       => 'idpg_is_free',
						'key'      => '_idpg_is_free',
						'type'     => 'checkbox',
						'label'    => __( 'Бесплатное событие', 'idpg-core' ),
						'help'     => __( 'Цены ниже игнорируются — на фронте выводится «Вход свободный».', 'idpg-core' ),
						'sanitize' => 'checkbox',
					),
					array(
						'id'       => 'idpg_currency',
						'key'      => '_idpg_currency',
						'type'     => 'select',
						'label'    => __( 'Валюта', 'idpg-core' ),
						'help'     => __( 'Валюта, в которой указаны цены билетов.', 'idpg-core' ),
						'sanitize' => 'text',
						'default'  => 'RUB',
						'options'  => array(
							'RUB' => 'RUB',
							'USD' => 'USD',
							'EUR' => 'EUR',
						),
					),
					array(
						'id'       => 'idpg_vip_price',
						'key'      => '_idpg_vip_price',
						'type'     => 'number',
						'label'    => __( 'VIP-цена', 'idpg-core' ),
						'help'     => __( 'Цена VIP-билета. Оставьте пустым, если VIP-билетов нет.', 'idpg-core' ),
						'sanitize' => 'text',
						'step'     => '0.01',
					),
					array(
						'id'       => 'idpg_price_from',
						'key'      => '_idpg_price_from',
						'type'     => 'number',
						'label'    => __( 'Цена от', 'idpg-core' ),
						'help'     => __( 'Минимальная цена билета.', 'idpg-core' ),
						'sanitize' => 'text',
						'step'     => '0.01',
					),
					array(
						'id'       => 'idpg_price_to',
						'key'      => '_idpg_price_to',
						'type'     => 'number',
						'label'    => __( 'Цена до', 'idpg-core' ),
						'help'     => __( 'Максимальная цена билета.', 'idpg-core' ),
						'sanitize' => 'text',
						'step'     => '0.01',
					),
					array(
						'id'       => 'idpg_ticket_type',
						'key'      => '_idpg_ticket_type',
						'type'     => 'select',
						'label'    => __( 'Тип продажи билетов', 'idpg-core' ),
						'help'     => __( '«Внешняя ссылка» — кнопка ведёт на ваш сайт продаж; «Виджет Яндекс.Билетов» — вставьте код виджета ниже.', 'idpg-core' ),
						'sanitize' => 'text',
						'default'  => 'external_link',
						'options'  => array(
							'external_link' => __( 'Внешняя ссылка', 'idpg-core' ),
							'widget_yandex' => __( 'Виджет Яндекс.Билетов', 'idpg-core' ),
						),
					),
					array(
						'id'       => 'idpg_external_id',
						'key'      => '_idpg_external_id',
						'type'     => 'text',
						'label'    => __( 'ID во внешней системе', 'idpg-core' ),
						'help'     => __( 'Используется cron-синхронизацией статусов билетов.', 'idpg-core' ),
						'sanitize' => 'text',
					),
					array(
						'id'          => 'idpg_ticket_url',
						'key'         => '_idpg_ticket_url',
						'type'        => 'url',
						'label'       => __( 'URL страницы продажи', 'idpg-core' ),
						'help'        => __( 'Ссылка, по которой посетители купят билет. Открывается в новой вкладке.', 'idpg-core' ),
						'sanitize'    => 'url',
						'placeholder' => 'https://',
						'conditional' => array(
							'field' => 'idpg_ticket_type',
							'value' => 'external_link',
						),
					),
					array(
						'id'          => 'idpg_yandex_form_url',
						'key'         => '_idpg_yandex_form_url',
						'type'        => 'url',
						'label'       => __( 'Ссылка на Яндекс.Форму', 'idpg-core' ),
						'help'        => __( 'Организатор указывает ссылку на Яндекс.Форму — на странице события появится кнопка «Зарегистрироваться на событие».', 'idpg-core' ),
						'sanitize'    => 'url',
						'placeholder' => 'https://forms.yandex.ru/',
					),
					array(
						'id'          => 'idpg_widget_code',
						'key'         => '_idpg_widget_code',
						'type'        => 'textarea',
						'label'       => __( 'Код виджета', 'idpg-core' ),
						'help'        => __( 'HTML/JS код виджета продажи билетов. Вставляется на страницу события как есть.', 'idpg-core' ),
						'sanitize'    => 'widget_code',
						'rows'        => 6,
						'conditional' => array(
							'field' => 'idpg_ticket_type',
							'value' => 'widget_yandex',
						),
					),
				),
			),
			array(
				'id'       => 'media',
				'title'    => __( 'Медиа', 'idpg-core' ),
				'context'  => 'normal',
				'priority' => 'default',
				'fields'   => array(
					array(
						'id'          => 'idpg_video_trailer',
						'key'         => '_idpg_video_trailer',
						'type'        => 'url',
						'label'       => __( 'Видео-трейлер', 'idpg-core' ),
						'help'        => __( 'Ссылка на трейлер: YouTube, VK Видео, Rutube.', 'idpg-core' ),
						'sanitize'    => 'url',
						'placeholder' => 'https://',
					),
					array(
						'id'    => 'idpg_gallery',
						'key'   => '_idpg_gallery',
						'type'  => 'gallery',
						'label' => __( 'Галерея', 'idpg-core' ),
						'help'  => __( 'Несколько изображений; порядок задаётся перетаскиванием.', 'idpg-core' ),
					),
					array(
						'id'       => 'idpg_stream_embed',
						'key'      => '_idpg_stream_embed',
						'type'     => 'textarea',
						'label'    => __( 'Код встраивания трансляции', 'idpg-core' ),
						'help'     => __( 'Вставьте HTML-код плеера для онлайн-трансляции. Доступно также в «Настройки организатора».', 'idpg-core' ),
						'sanitize' => 'embed',
						'rows'     => 5,
					),
				),
			),
		);
	}

	/**
	 * Список площадок для селекта.
	 *
	 * @return array Массив ID => Название.
	 */
	public static function get_venue_options() {
		$options = array( '0' => __( '— Не выбрана —', 'idpg-core' ) );

		$venues = get_posts(
			array(
				'post_type'   => 'venue',
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		foreach ( $venues as $venue ) {
			$options[ strval( $venue->ID ) ] = $venue->post_title;
		}

		return $options;
	}

	// =========================================================================
	// Метабоксы на экране редактирования события
	// =========================================================================

	/**
	 * Регистрация метабоксов полей события.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'event' !== $screen->post_type ) {
			return;
		}

		foreach ( self::get_field_groups() as $group ) {
			add_meta_box(
				'idpg_event_' . $group['id'],
				$group['title'],
				array( $this, 'render_meta_box' ),
				'event',
				$group['context'],
				$group['priority'],
				array( 'group_id' => $group['id'] )
			);
		}
	}

	/**
	 * Вывод содержимого метабокса.
	 *
	 * @param WP_Post $post    Объект поста.
	 * @param array   $box     Данные метабокса (с group_id).
	 * @return void
	 */
	public function render_meta_box( $post, $box ) {
		$group_id = isset( $box['args']['group_id'] ) ? $box['args']['group_id'] : '';
		$groups   = self::get_field_groups();

		foreach ( $groups as $group ) {
			if ( $group['id'] !== $group_id ) {
				continue;
			}

			wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

			echo '<div class="idpg-event-fields" data-group="' . esc_attr( $group['id'] ) . '">';
			foreach ( $group['fields'] as $field ) {
				// Системные поля — только для администраторов.
				if ( ! empty( $field['admin_only'] ) && ! current_user_can( 'manage_options' ) ) {
					continue;
				}

				$value = get_post_meta( $post->ID, $field['key'], true );
				self::render_field( $field, $value );
			}
			echo '</div>';

			break;
		}
	}

	// =========================================================================
	// Вкладка «События» на странице IDPG
	// =========================================================================

	/**
	 * Регистрация вкладки «События» на странице «IDPG».
	 *
	 * @param array $tabs Карта вкладок `slug => ['label','callback']`.
	 * @return array
	 */
	public function register_admin_tab( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			$tabs = array();
		}

		$tabs[ self::TAB_SLUG ] = array(
			'label'    => __( 'События', 'idpg-core' ),
			'callback' => array( $this, 'render_admin_tab' ),
		);

		return $tabs;
	}

	/**
	 * Рендер вкладки: выбор события и форма его полей.
	 */
	public function render_admin_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;

		// Сообщение после сохранения.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['idpg_msg'] ) ? sanitize_key( wp_unslash( $_GET['idpg_msg'] ) ) : '';

		if ( 'saved' === $msg ) {
			?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Поля события сохранены.', 'idpg-core' ); ?></p></div>
			<?php
		}

		$event = $event_id ? get_post( $event_id ) : null;

		if ( $event && 'event' !== $event->post_type ) {
			$event = null;
		}

		if ( ! $event ) {
			$this->render_event_selector();
			return;
		}

		$this->render_event_form( $event );
	}

	/**
	 * Селектор события для вкладки.
	 *
	 * Показывает список последних событий с возможностью выбора.
	 */
	protected function render_event_selector() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['event_search'] ) ? sanitize_text_field( wp_unslash( $_GET['event_search'] ) ) : '';

		$args = array(
			'post_type'      => 'event',
			'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
			'posts_per_page' => 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$events = get_posts( $args );
		$base   = admin_url( 'admin.php?page=idpg-widget-areas&tab=' . self::TAB_SLUG );
		?>
		<h2 class="idpg-privacy-section-title"><?php esc_html_e( 'Редактирование полей события', 'idpg-core' ); ?></h2>

		<p class="idpg-privacy-tools__text">
			<?php esc_html_e( 'Выберите событие, чтобы отредактировать его поля: даты, сеансы, место, билеты и медиа.', 'idpg-core' ); ?>
		</p>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-bottom:16px;">
			<input type="hidden" name="page" value="idpg-widget-areas" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_SLUG ); ?>" />
			<label for="idpg-event-search"><?php esc_html_e( 'Поиск:', 'idpg-core' ); ?></label>
			<input type="search" id="idpg-event-search" name="event_search" value="<?php echo esc_attr( $search ); ?>" class="regular-text" />
			<button type="submit" class="button"><?php esc_html_e( 'Найти', 'idpg-core' ); ?></button>
		</form>

		<table class="widefat striped" style="max-width:800px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Название', 'idpg-core' ); ?></th>
					<th><?php esc_html_e( 'Дата', 'idpg-core' ); ?></th>
					<th><?php esc_html_e( 'Статус', 'idpg-core' ); ?></th>
					<th><?php esc_html_e( 'Действие', 'idpg-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $events ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'События не найдены.', 'idpg-core' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $events as $ev ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $ev->post_title ); ?></strong></td>
							<td><?php echo esc_html( get_post_meta( $ev->ID, '_idpg_start_date', true ) ); ?></td>
							<td><?php echo esc_html( get_post_status_object( $ev->post_status )->label ); ?></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'event_id', $ev->ID, $base ) ); ?>">
									<?php esc_html_e( 'Редактировать', 'idpg-core' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<p>
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=event' ) ); ?>">
				<?php esc_html_e( 'Все события в списке WP', 'idpg-core' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Форма полей выбранного события на вкладке.
	 *
	 * @param WP_Post $event Объект события.
	 */
	protected function render_event_form( $event ) {
		$base = admin_url( 'admin.php?page=idpg-widget-areas&tab=' . self::TAB_SLUG );
		?>
		<p>
			<a href="<?php echo esc_url( $base ); ?>">&laquo; <?php esc_html_e( 'К списку событий', 'idpg-core' ); ?></a>
		</p>

		<h2 class="idpg-privacy-section-title">
			<?php echo esc_html( $event->post_title ); ?>
			<span style="font-weight:400;font-size:13px;color:#666;">
				(<?php echo esc_html( get_post_status_object( $event->post_status )->label ); ?>)
			</span>
		</h2>

		<p>
			<a class="button" href="<?php echo esc_url( get_edit_post_link( $event->ID ) ); ?>">
				<?php esc_html_e( 'Открыть в редакторе WP', 'idpg-core' ); ?>
			</a>
		</p>

		<form method="post" action="<?php echo esc_url( $base . '&event_id=' . $event->ID ); ?>" class="idpg-event-fields-form">
			<?php wp_nonce_field( self::TAB_NONCE_ACTION, self::TAB_NONCE_NAME ); ?>
			<input type="hidden" name="idpg_event_id" value="<?php echo esc_attr( $event->ID ); ?>" />

			<?php foreach ( self::get_field_groups() as $group ) : ?>
				<h2 class="idpg-privacy-section-title"><?php echo esc_html( $group['title'] ); ?></h2>
				<table class="form-table" role="presentation" style="max-width:800px;">
					<?php foreach ( $group['fields'] as $field ) : ?>
						<?php if ( ! empty( $field['admin_only'] ) ) : ?>
							<?php continue; ?>
						<?php endif; ?>
						<tr>
							<?php self::render_tab_field( $field, get_post_meta( $event->ID, $field['key'], true ) ); ?>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endforeach; ?>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Сохранить поля', 'idpg-core' ); ?></button>
			</p>
		</form>
		<?php
	}

	// =========================================================================
	// Сохранение
	// =========================================================================

	/**
	 * Обработчик сохранения формы на вкладке (admin_init).
	 *
	 * @return void
	 */
	public function handle_tab_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( $_POST[ self::TAB_NONCE_NAME ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::TAB_NONCE_NAME ] ) ), self::TAB_NONCE_ACTION ) ) {
			return;
		}

		$event_id = isset( $_POST['idpg_event_id'] ) ? absint( $_POST['idpg_event_id'] ) : 0;

		if ( ! $event_id || 'event' !== get_post_type( $event_id ) ) {
			return;
		}

		// Флаг: обработчик вкладки (чтобы save_post_fields не сработал повторно).
		$GLOBALS['idpg_event_tab_saved'] = $event_id;

		$this->save_fields( $event_id, 'tab' );

		$base = admin_url( 'admin.php?page=idpg-widget-areas&tab=' . self::TAB_SLUG . '&event_id=' . $event_id );
		wp_safe_redirect( add_query_arg( 'idpg_msg', 'saved', $base ) );
		exit;
	}

	/**
	 * Обработчик сохранения поста (save_post_event).
	 *
	 * @param int     $post_id ID поста.
	 * @param WP_Post $post    Объект поста.
	 * @param bool    $update  Обновление ли это.
	 * @return void
	 */
	public function save_post_fields( $post_id, $post, $update ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof WP_Post || 'event' !== $post->post_type ) {
			return;
		}

		// Вкладка уже сохранила — не дублируем.
		if ( isset( $GLOBALS['idpg_event_tab_saved'] ) && $GLOBALS['idpg_event_tab_saved'] === (int) $post_id ) {
			return;
		}

		// Проверка nonce (из метабокса).
		if ( empty( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$this->save_fields( $post_id, 'post' );
	}

	/**
	 * Сохранение полей события.
	 *
	 * Единый метод для метабокса и вкладки. Читает $_POST, санитизирует
	 * и записывает мета-поля, пересобирает зеркало сеансов.
	 *
	 * @param int    $post_id ID события.
	 * @param string $context Контекст: 'post' (метабокс) или 'tab' (вкладка).
	 * @return void
	 */
	protected function save_fields( $post_id, $context ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверяется в save_post_fields() и handle_tab_save().
		$is_admin = current_user_can( 'manage_options' );

		foreach ( self::get_field_groups() as $group ) {
			foreach ( $group['fields'] as $field ) {
				// Системные поля доступны только администраторам.
				if ( ! empty( $field['admin_only'] ) && ! $is_admin ) {
					continue;
				}

				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce проверяется выше; санитизация в sanitize_field().
				$raw = isset( $_POST[ $field['id'] ] ) ? wp_unslash( $_POST[ $field['id'] ] ) : '';

				switch ( $field['type'] ) {
					case 'checkbox':
						// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверяется выше.
						$value = isset( $_POST[ $field['id'] ] ) ? 'yes' : '';
						break;

					case 'sessions':
						// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce проверяется выше; санитизация в save_sessions().
						$value = isset( $_POST['idpg_sessions_rows'] ) ? wp_unslash( $_POST['idpg_sessions_rows'] ) : array();
						break;

					case 'gallery':
						// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверяется выше.
						$value = isset( $_POST['idpg_gallery_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['idpg_gallery_ids'] ) ) : '';
						break;

					default:
						$value = self::sanitize_field( $field, $raw );
						break;
				}

				// Запись.
				if ( 'sessions' === $field['type'] ) {
					$this->save_sessions( $post_id, $value );
				} elseif ( 'gallery' === $field['type'] ) {
					$this->save_gallery( $post_id, $value );
				} elseif ( '' === $value ) {
					delete_post_meta( $post_id, $field['key'] );
				} else {
					update_post_meta( $post_id, $field['key'], $value );
				}
			}
		}

		// Сброс кэша хелперов шаблонов.
		if ( class_exists( 'IDPG_Meta' ) ) {
			IDPG_Meta::purge_helper_cache( $post_id );
		}
	}

	/**
	 * Санитизация значения поля.
	 *
	 * @param array $field Описание поля.
	 * @param mixed $value Сырое значение.
	 * @return mixed Санитизированное значение.
	 */
	public static function sanitize_field( $field, $value ) {
		$value = is_string( $value ) ? trim( $value ) : $value;

		switch ( $field['sanitize'] ) {
			case 'int':
				return absint( $value );

			case 'url':
				return esc_url_raw( $value );

			case 'widget_code':
				// Код виджета содержит <script>, поэтому сохраняется как есть,
				// но только для пользователей с правом unfiltered_html.
				if ( ! current_user_can( 'unfiltered_html' ) ) {
					return wp_kses_post( $value );
				}
				return $value;

			case 'embed':
				// Код встраивания плеера — HTML-фрагмент.
				if ( ! current_user_can( 'unfiltered_html' ) ) {
					return wp_kses_post( $value );
				}
				return $value;

			case 'checkbox':
				return empty( $value ) ? '' : 'yes';

			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Сохранение сеансов (complex-поле) + пересборка зеркала.
	 *
	 * @param int   $post_id ID события.
	 * @param array $rows    Массив строк {time, price, url}.
	 * @return void
	 */
	protected function save_sessions( $post_id, $rows ) {
		$sessions = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$time = isset( $row['time'] ) ? trim( sanitize_text_field( (string) $row['time'] ) ) : '';

				if ( '' === $time ) {
					continue;
				}

				$sessions[] = array(
					'time'  => $time,
					'price' => isset( $row['price'] ) ? trim( sanitize_text_field( (string) $row['price'] ) ) : '',
					'url'   => isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '',
				);
			}
		}

		// Основной ключ — сериализованный PHP-массив.
		if ( empty( $sessions ) ) {
			delete_post_meta( $post_id, '_idpg_sessions' );
		} else {
			update_post_meta( $post_id, '_idpg_sessions', $sessions );
		}

		// JSON-зеркало — читают шаблоны.
		if ( empty( $sessions ) ) {
			delete_post_meta( $post_id, IDPG_Meta::SESSIONS_MIRROR );
		} else {
			update_post_meta( $post_id, IDPG_Meta::SESSIONS_MIRROR, wp_json_encode( $sessions ) );
		}
	}

	/**
	 * Сохранение галереи (media_gallery).
	 *
	 * @param int    $post_id ID события.
	 * @param string $ids_csv Строка ID через запятую.
	 * @return void
	 */
	protected function save_gallery( $post_id, $ids_csv ) {
		$ids = array();

		if ( '' !== $ids_csv ) {
			foreach ( explode( ',', $ids_csv ) as $id ) {
				$id = absint( trim( $id ) );
				if ( $id ) {
					$ids[] = $id;
				}
			}
		}

		if ( empty( $ids ) ) {
			delete_post_meta( $post_id, '_idpg_gallery' );
		} else {
			update_post_meta( $post_id, '_idpg_gallery', $ids );
		}
	}

	// =========================================================================
	// Вывод полей
	// =========================================================================

	/**
	 * Вывод поля в метабоксе (на экране редактирования события).
	 *
	 * @param array $field Описание поля.
	 * @param mixed $value Текущее значение.
	 * @return void
	 */
	public static function render_field( $field, $value ) {
		$type        = $field['type'];
		$id          = $field['id'];
		$label       = $field['label'];
		$help        = isset( $field['help'] ) ? $field['help'] : '';
		$conditional = isset( $field['conditional'] ) ? $field['conditional'] : null;

		$wrap_attrs = '';
		if ( $conditional ) {
			$wrap_attrs = sprintf(
				' data-conditional-field="%s" data-conditional-value="%s"',
				esc_attr( $conditional['field'] ),
				esc_attr( $conditional['value'] )
			);
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $wrap_attrs построен через esc_attr() выше.
		echo '<div class="idpg-field idpg-field--' . esc_attr( $type ) . '" data-field-id="' . esc_attr( $id ) . '"' . $wrap_attrs . '>';
		echo '<p><strong>' . esc_html( $label ) . '</strong></p>';

		switch ( $type ) {
			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
					esc_attr( $id ),
					checked( 'yes' === (string) $value, true, false ),
					esc_html( $label )
				);
				break;

			case 'select':
				$options = isset( $field['options'] ) ? $field['options'] : array();
				if ( is_callable( $options ) ) {
					$options = call_user_func( $options );
				}
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '">';
				foreach ( (array) $options as $opt_value => $opt_label ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $opt_value ),
						selected( (string) $value, (string) $opt_value, false ),
						esc_html( $opt_label )
					);
				}
				echo '</select>';
				break;

			case 'textarea':
				$rows = isset( $field['rows'] ) ? (int) $field['rows'] : 4;
				printf(
					'<textarea id="%1$s" name="%1$s" rows="%2$d" class="large-text code">%3$s</textarea>',
					esc_attr( $id ),
					$rows, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows int.
					esc_textarea( (string) $value )
				);
				break;

			case 'date':
				printf(
					'<input type="date" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( (string) $value )
				);
				break;

			case 'number':
				$step     = isset( $field['step'] ) ? $field['step'] : '1';
				$readonly = ! empty( $field['readonly'] ) ? ' readonly' : '';
				printf(
					'<input type="number" id="%1$s" name="%1$s" value="%2$s" min="0" step="%3$s" class="regular-text"%4$s />',
					esc_attr( $id ),
					esc_attr( (string) $value ),
					esc_attr( $step ),
					$readonly // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal HTML.
				);
				break;

			case 'url':
				$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : 'https://';
				printf(
					'<input type="url" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( (string) $value ),
					esc_attr( $placeholder )
				);
				break;

			case 'sessions':
				self::render_sessions_field( $value );
				break;

			case 'gallery':
				self::render_gallery_field( $value );
				break;

			default:
				printf(
					'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( (string) $value )
				);
				break;
		}

		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Вывод поля в таблице формы на вкладке.
	 *
	 * @param array $field Описание поля.
	 * @param mixed $value Текущее значение.
	 * @return void
	 */
	public static function render_tab_field( $field, $value ) {
		$type = $field['type'];

		echo '<th scope="row"><label for="' . esc_attr( $field['id'] ) . '">' . esc_html( $field['label'] ) . '</label></th>';
		echo '<td>';

		if ( 'checkbox' === $type ) {
			printf(
				'<label><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
				esc_attr( $field['id'] ),
				checked( 'yes' === (string) $value, true, false ),
				esc_html( $field['label'] )
			);
		} elseif ( 'sessions' === $type ) {
			self::render_sessions_field( $value );
		} elseif ( 'gallery' === $type ) {
			self::render_gallery_field( $value );
		} else {
			// Обёртка, чтобы render_field не выводил <p><strong>.
			echo '<div class="idpg-field-plain" data-field-id="' . esc_attr( $field['id'] ) . '"';
			if ( ! empty( $field['conditional'] ) ) {
				printf(
					' data-conditional-field="%s" data-conditional-value="%s"',
					esc_attr( $field['conditional']['field'] ),
					esc_attr( $field['conditional']['value'] )
				);
			}
			echo '>';
			// Временно подменяем label на пустую строку, чтобы не дублировать.
			$saved_label    = $field['label'];
			$field['label'] = '';
			// Убираем обёртку <div class="idpg-field"> — выводим только контрол.
			self::render_field_control( $field, $value );
			$field['label'] = $saved_label;
			echo '</div>';
		}

		if ( ! empty( $field['help'] ) ) {
			echo '<p class="description">' . esc_html( $field['help'] ) . '</p>';
		}

		echo '</td>';
	}

	/**
	 * Вывод только управляющего элемента (без обёртки).
	 *
	 * @param array $field Описание поля.
	 * @param mixed $value Текущее значение.
	 * @return void
	 */
	protected static function render_field_control( $field, $value ) {
		$type = $field['type'];
		$id   = $field['id'];

		switch ( $type ) {
			case 'select':
				$options = isset( $field['options'] ) ? $field['options'] : array();
				if ( is_callable( $options ) ) {
					$options = call_user_func( $options );
				}
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '">';
				foreach ( (array) $options as $opt_value => $opt_label ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $opt_value ),
						selected( (string) $value, (string) $opt_value, false ),
						esc_html( $opt_label )
					);
				}
				echo '</select>';
				break;

			case 'textarea':
				$rows = isset( $field['rows'] ) ? (int) $field['rows'] : 4;
				printf(
					'<textarea id="%1$s" name="%1$s" rows="%2$d" class="large-text code">%3$s</textarea>',
					esc_attr( $id ),
					$rows, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows int.
					esc_textarea( (string) $value )
				);
				break;

			case 'date':
				printf(
					'<input type="date" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( (string) $value )
				);
				break;

			case 'number':
				$step     = isset( $field['step'] ) ? $field['step'] : '1';
				$readonly = ! empty( $field['readonly'] ) ? ' readonly' : '';
				printf(
					'<input type="number" id="%1$s" name="%1$s" value="%2$s" min="0" step="%3$s" class="regular-text"%4$s />',
					esc_attr( $id ),
					esc_attr( (string) $value ),
					esc_attr( $step ),
					$readonly // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal HTML.
				);
				break;

			case 'url':
				$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : 'https://';
				printf(
					'<input type="url" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( (string) $value ),
					esc_attr( $placeholder )
				);
				break;

			default:
				printf(
					'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( (string) $value )
				);
				break;
		}
	}

	/**
	 * Вывод complex-поля «Сеансы».
	 *
	 * Повторяемые строки: время, цена, ссылка. Каждая строка — набор
	 * инпутов с именем `idpg_sessions_rows[N][field]`.
	 *
	 * @param mixed $value Текущее значение (массив строк или JSON-строка).
	 * @return void
	 */
	public static function render_sessions_field( $value ) {
		$rows = self::normalize_sessions_value( $value );
		?>
		<div class="idpg-sessions" id="idpg-sessions-field">
			<div class="idpg-sessions__rows">
				<?php if ( empty( $rows ) ) : ?>
					<div class="idpg-sessions__row" data-index="0">
						<input type="text" name="idpg_sessions_rows[0][time]" placeholder="<?php esc_attr_e( '19:00', 'idpg-core' ); ?>" class="small-text" />
						<input type="number" name="idpg_sessions_rows[0][price]" placeholder="<?php esc_attr_e( 'Цена', 'idpg-core' ); ?>" min="0" step="0.01" class="small-text" />
						<input type="url" name="idpg_sessions_rows[0][url]" placeholder="<?php esc_attr_e( 'https://', 'idpg-core' ); ?>" class="regular-text" />
						<button type="button" class="button idpg-sessions__remove" title="<?php esc_attr_e( 'Удалить', 'idpg-core' ); ?>">&times;</button>
					</div>
				<?php else : ?>
					<?php foreach ( $rows as $i => $row ) : ?>
						<div class="idpg-sessions__row" data-index="<?php echo esc_attr( $i ); ?>">
							<input type="text" name="idpg_sessions_rows[<?php echo esc_attr( $i ); ?>][time]" value="<?php echo esc_attr( $row['time'] ); ?>" placeholder="<?php esc_attr_e( '19:00', 'idpg-core' ); ?>" class="small-text" />
							<input type="number" name="idpg_sessions_rows[<?php echo esc_attr( $i ); ?>][price]" value="<?php echo esc_attr( $row['price'] ); ?>" placeholder="<?php esc_attr_e( 'Цена', 'idpg-core' ); ?>" min="0" step="0.01" class="small-text" />
							<input type="url" name="idpg_sessions_rows[<?php echo esc_attr( $i ); ?>][url]" value="<?php echo esc_attr( $row['url'] ); ?>" placeholder="<?php esc_attr_e( 'https://', 'idpg-core' ); ?>" class="regular-text" />
							<button type="button" class="button idpg-sessions__remove" title="<?php esc_attr_e( 'Удалить', 'idpg-core' ); ?>">&times;</button>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<p>
				<button type="button" class="button idpg-sessions__add"><?php esc_html_e( '+ Добавить сеанс', 'idpg-core' ); ?></button>
			</p>
		</div>
		<?php
	}

	/**
	 * Вывод media_gallery-поля «Галерея».
	 *
	 * @param mixed $value Текущее значение (массив ID или JSON-строка).
	 * @return void
	 */
	public static function render_gallery_field( $value ) {
		$ids = self::normalize_gallery_value( $value );
		?>
		<div class="idpg-gallery" id="idpg-gallery-field">
			<input type="hidden" name="idpg_gallery_ids" id="idpg_gallery_ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>" />
			<div class="idpg-gallery__thumbnails">
				<?php foreach ( $ids as $att_id ) : ?>
					<?php $thumb = wp_get_attachment_image_url( $att_id, 'thumbnail' ); ?>
					<?php if ( $thumb ) : ?>
						<span class="idpg-gallery__item" data-id="<?php echo esc_attr( $att_id ); ?>">
							<img src="<?php echo esc_url( $thumb ); ?>" alt="" />
							<button type="button" class="idpg-gallery__remove" title="<?php esc_attr_e( 'Удалить', 'idpg-core' ); ?>">&times;</button>
						</span>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
			<p>
				<button type="button" class="button idpg-gallery__add"><?php esc_html_e( '+ Добавить изображения', 'idpg-core' ); ?></button>
			</p>
		</div>
		<?php
	}

	// =========================================================================
	// Нормализация значений
	// =========================================================================

	/**
	 * Нормализация значения complex-поля «Сеансы».
	 *
	 * Принимает массив строк (новый формат) или JSON-строку (старый
	 * формат зеркала) и возвращает массив {time, price, url}.
	 *
	 * @param mixed $value Сырое значение.
	 * @return array
	 */
	public static function normalize_sessions_value( $value ) {
		if ( is_array( $value ) ) {
			$rows = $value;
		} elseif ( is_string( $value ) && '' !== $value ) {
			$decoded = json_decode( $value, true );
			$rows    = is_array( $decoded ) ? $decoded : array();
		} else {
			$rows = array();
		}

		$normalized = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$normalized[] = array(
				'time'  => isset( $row['time'] ) ? (string) $row['time'] : '',
				'price' => isset( $row['price'] ) ? (string) $row['price'] : '',
				'url'   => isset( $row['url'] ) ? (string) $row['url'] : '',
			);
		}

		return $normalized;
	}

	/**
	 * Нормализация значения media_gallery.
	 *
	 * Принимает массив ID (новый формат), JSON-строку (старый формат
	 * Carbon Fields) или маркер пустого значения, и возвращает
	 * массив ID.
	 *
	 * @param mixed $value Сырое значение.
	 * @return int[]
	 */
	public static function normalize_gallery_value( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'absint', $value );
		}

		if ( is_string( $value ) && '' !== $value && '|||0|_empty' !== $value ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				return array_map( 'absint', $decoded );
			}
		}

		return array();
	}

	// =========================================================================
	// Скрипты
	// =========================================================================

	/**
	 * Подключение скриптов и стилей для метабоксов и вкладки.
	 *
	 * @param string $hook_suffix Текущий хук страницы.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		$is_event_edit = ( 'post.php' === $hook_suffix || 'post-new.php' === $hook_suffix )
			&& $screen
			&& 'event' === $screen->post_type;

		$is_idpg_tab = ( 'toplevel_page_idpg-widget-areas' === $hook_suffix );

		if ( ! $is_event_edit && ! $is_idpg_tab ) {
			return;
		}

		// Медиа-библиотека для галереи.
		wp_enqueue_media();

		$css_version = file_exists( IDPG_CORE_DIR . 'assets/css/editor.css' )
			? (string) filemtime( IDPG_CORE_DIR . 'assets/css/editor.css' )
			: IDPG_CORE_VERSION;

		wp_enqueue_style( 'idpg-editor', IDPG_CORE_URL . 'assets/css/editor.css', array(), $css_version );

		// JS: добавление/удаление сеансов, галерея, условная логика.
		wp_enqueue_script(
			'idpg-event-fields',
			IDPG_CORE_URL . 'assets/js/event-fields.js',
			array( 'jquery' ),
			IDPG_CORE_VERSION,
			true
		);
	}
}
