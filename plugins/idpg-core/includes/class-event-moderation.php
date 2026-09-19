<?php
/**
 * Модерация публикации событий организаторов.
 *
 * Организатор (роль event_organizer) лишён capability publish_events:
 * он создаёт и редактирует свои события, но публикует их только
 * администратор/редактор. Этот класс добавляет защиту в глубину —
 * программное понижение статуса в pending — и email-уведомления:
 * админам о новом событии на модерации, организатору об одобрении.
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Event_Moderation
 */
class IDPG_Event_Moderation {

	/**
	 * Мета-ключи блока «Билеты и цены», влияющие на модерацию.
	 */
	const META_IS_FREE     = '_idpg_is_free';
	const META_PRICE_FROM  = '_idpg_price_from';
	const META_TICKET_TYPE = '_idpg_ticket_type';
	const META_TICKET_URL  = '_idpg_ticket_url';
	const META_WIDGET_CODE = '_idpg_widget_code';

	/**
	 * Допустимые типы продажи билетов.
	 *
	 * @var string[]
	 */
	const TICKET_TYPES = array( 'external_link', 'widget_yandex' );

	/**
	 * Флаг обхода принудительной модерации.
	 *
	 * Включается на время автопубликации бесплатного события,
	 * чтобы wp_update_post() не понижал статус обратно в pending
	 * фильтром enforce_pending_for_organizer().
	 *
	 * @var bool
	 */
	private static $bypass_enforcement = false;

	/**
	 * Флаг: отложенная фаза обработки уже запланирована в запросе.
	 *
	 * @var bool
	 */
	private static $phase_scheduled = false;

	/**
	 * Защита от повторного входа в обработчик shutdown-фазы.
	 *
	 * @var bool
	 */
	private static $phase_running = false;

	/**
	 * ID события, сохранённого Carbon Fields в текущем запросе.
	 *
	 * @var int
	 */
	private static $phase_post_id = 0;

	/**
	 * Синглтон.
	 *
	 * @var IDPG_Event_Moderation|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Event_Moderation
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор.
	 *
	 * Регистрирует хуки принудительной модерации и уведомлений.
	 */
	private function __construct() {
		add_filter( 'wp_insert_post_data', array( $this, 'enforce_pending_for_organizer' ), 20, 2 );
		add_action( 'transition_post_status', array( $this, 'on_status_transition' ), 10, 3 );

		// Вторая фаза — после сохранения мета Carbon Fields: мета события
		// уже в БД, поэтому правила «бесплатное/платное» применяются
		// к актуальным данным. Выполняется один раз в конце запроса.
		add_action( 'carbon_fields_post_meta_container_saved', array( $this, 'schedule_price_moderation' ), 10, 2 );

		// Подсказка организатору о результате ценовой модерации.
		add_action( 'admin_notices', array( $this, 'render_event_notice' ) );
	}

	/**
	 * Принудительная модерация при программной публикации события.
	 *
	 * Срабатывает на wp_insert_post_data: если событие пытается
	 * опубликовать пользователь без publish_events (организатор),
	 * статус понижается до pending. Исключения:
	 *  - правка уже опубликованного события: статус publish сохраняется;
	 *  - внутренняя автопубликация бесплатного события (guard-флаг,
	 *    см. publish_free_event()) — полный обход модерации.
	 *
	 * Финальное решение по платному/бесплатному событию принимает
	 * вторая фаза (schedule_price_moderation()) — после сохранения
	 * мета Carbon Fields, т.к. в момент этого фильтра свежие значения
	 * полей «Билеты и цены» ещё недоступны.
	 *
	 * @param array $data    Данные поста после фильтров.
	 * @param array $postarr Исходные данные wp_insert_post/wp_update_post.
	 * @return array
	 */
	public function enforce_pending_for_organizer( $data, $postarr ) {
		if ( ! isset( $data['post_type'] ) || 'event' !== $data['post_type'] ) {
			return $data;
		}

		// Внутренняя автопубликация бесплатного события — не трогаем.
		if ( self::$bypass_enforcement ) {
			return $data;
		}

		// Только для залогиненных пользователей. Системные вызовы (cron,
		// CLI, импорты) под uid=0 не относятся к модерации организаторов.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $data;
		}

		// Админ/редактор (есть publish_events) — не трогаем.
		if ( current_user_can( 'publish_events' ) ) {
			return $data;
		}

		// Только попытки публикации/планирования (не draft/pending).
		if ( ! in_array( (string) $data['post_status'], array( 'publish', 'future' ), true ) ) {
			return $data;
		}

		// Правка уже опубликованного события — статус publish сохраняем.
		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( $post_id && 'publish' === (string) get_post_status( $post_id ) ) {
			return $data;
		}

		// Новое событие / ранее не опубликованное — на модерацию.
		$data['post_status'] = 'pending';

		return $data;
	}

	/**
	 * Фаза 2. Планирование ценовой модерации после сохранения мета.
	 *
	 * Carbon Fields сохраняет поля «Билеты и цены» на хуке
	 * carbon_fields_post_meta_container_saved — к этому моменту
	 * актуальные значения уже в БД, поэтому правила «бесплатное
	 * публикуем сразу / платное требует данные билетов» применяются
	 * здесь. Сама обработка отложена на shutdown: в Gutenberg
	 * статус поста приходит первым REST-запросом, а мета — вторым
	 * metabox-запросом, и решение должно учитывать оба.
	 *
	 * @param int $post_id ID сохранённой записи.
	 * @return void
	 */
	public function schedule_price_moderation( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! $post_id || 'event' !== get_post_type( $post_id ) ) {
			return;
		}

		// Автопубликация бесплатного события уже выполнялась в этом запросе.
		if ( self::$phase_running ) {
			return;
		}

		self::$phase_post_id = $post_id;

		if ( self::$phase_scheduled ) {
			return;
		}

		self::$phase_scheduled = true;
		add_action( 'shutdown', array( $this, 'run_price_moderation' ) );
	}

	/**
	 * Фаза 2. Применение правил платной/бесплатной модерации.
	 *
	 * Вызывается один раз в конце запроса сохранения события
	 * организатором (без publish_events). Логика по актуальным мета:
	 *  - бесплатное (is_free=yes) → публикация сразу, без модерации;
	 *  - платное с полными данными билетов → остаётся на модерации
	 *    (pending, статус уже выставлен фазой 1);
	 *  - платное без данных билетов → понижение до draft с подсказкой
	 *    организатору — на модерацию такое событие отправить нельзя.
	 *
	 * @return void
	 */
	public function run_price_moderation() {
		if ( self::$phase_running ) {
			return;
		}
		self::$phase_running = true;

		$post_id = self::$phase_post_id;

		if ( ! $post_id || 'event' !== get_post_type( $post_id ) ) {
			return;
		}

		// Правила применяются только к событиям организаторов.
		if ( ! $this->is_organizer_submission( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		// Только события на модерации: организатор нажал «Отправить
		// на рассмотрение» (или «Опубликовать» — фаза 1 перевела
		// их в pending). Черновик — осознанный выбор, не трогаем.
		if ( 'pending' !== $post->post_status ) {
			return;
		}

		// Бесплатное событие — публикация сразу, без модерации.
		if ( $this->is_event_free( $post_id ) ) {
			$this->publish_free_event( $post_id );
			return;
		}

		// Платное событие без данных билетов — с модерации снимается:
		// остаётся черновиком, организатор получает подсказку.
		if ( ! $this->is_ticket_data_complete( $post_id ) ) {
			$this->downgrade_incomplete_paid_event( $post_id );
		}
	}

	/**
	 * Проверяет, что событие в текущем запросе сохранено организатором.
	 *
	 * @param int $post_id ID события.
	 * @return bool
	 */
	private function is_organizer_submission( $post_id ) {
		// Системные вызовы (cron, CLI, импорты) не участвуют в модерации.
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		// Админ/редактор публикует сам — правила организатора не действуют.
		if ( current_user_can( 'publish_events' ) ) {
			return false;
		}

		// Уточняем права именно на эту запись: автор события без
		// publish_events считается организатором-автором.
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		return (int) $post->post_author === $user_id;
	}

	/**
	 * Бесплатно ли событие по актуальным мета-данным.
	 *
	 * @param int $post_id ID события.
	 * @return bool
	 */
	private function is_event_free( $post_id ) {
		return 'yes' === (string) get_post_meta( $post_id, self::META_IS_FREE, true );
	}

	/**
	 * Заполнены ли обязательные данные билетов платного события.
	 *
	 * Обязательны: минимальная цена (price_from), тип продажи
	 * (ticket_type) и источник покупки — внешняя ссылка
	 * (ticket_url) либо код виджета (widget_code) в зависимости
	 * от типа. Ссылка на Яндекс.Форму — дополнительный канал
	 * регистрации, обязательной не является.
	 *
	 * @param int $post_id ID события.
	 * @return bool
	 */
	private function is_ticket_data_complete( $post_id ) {
		$price = (float) get_post_meta( $post_id, self::META_PRICE_FROM, true );

		if ( $price <= 0 ) {
			return false;
		}

		$type = (string) get_post_meta( $post_id, self::META_TICKET_TYPE, true );

		if ( ! in_array( $type, self::TICKET_TYPES, true ) ) {
			return false;
		}

		if ( 'external_link' === $type ) {
			$url = trim( (string) get_post_meta( $post_id, self::META_TICKET_URL, true ) );

			// Факт заполнения важнее строгой валидации формата:
			// поле в редакторе имеет type=url и проверяется на клиенте.
			return '' !== $url;
		}

		// widget_yandex — требуется код виджета.
		$widget = trim( (string) get_post_meta( $post_id, self::META_WIDGET_CODE, true ) );

		return '' !== $widget;
	}

	/**
	 * Автопубликация бесплатного события организатора.
	 *
	 * wp_update_post() с guard-флагом $bypass_enforcement: фильтр
	 * enforce_pending_for_organizer() видит флаг и не понижает
	 * статус обратно в pending. Штатные хуки сохранения
	 * (пр. IDPG_Meta::ensure_city_and_date_on_publish) выполняются,
	 * уведомление автору отправляет on_status_transition().
	 *
	 * @param int $post_id ID события.
	 * @return void
	 */
	private function publish_free_event( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'publish' === $post->post_status ) {
			return;
		}

		self::$bypass_enforcement = true;

		wp_update_post( array(
			'ID'          => (int) $post_id,
			'post_status' => 'publish',
		), true );

		self::$bypass_enforcement = false;

		// Уведомление автору: on_status_transition() в REST-контексте
		// уже отработал на вставке с pending, поэтому вызываем явно.
		$this->notify_author_published( $post );
	}

	/**
	 * Снятие платного события с модерации из-за незаполненных билетов.
	 *
	 * Событие понижается с pending до draft, организатору показывается
	 * подсказка о недостающих полях блока «Билеты и цены».
	 *
	 * @param int $post_id ID события.
	 * @return void
	 */
	private function downgrade_incomplete_paid_event( $post_id ) {
		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->posts,
			array( 'post_status' => 'draft' ),
			array( 'ID' => (int) $post_id, 'post_status' => 'pending' ),
			array( '%s' ),
			array( '%d', '%s' )
		);

		if ( false === $updated ) {
			return;
		}

		clean_post_cache( $post_id );

		set_transient(
			'idpg_moderation_ticket_notice_' . get_current_user_id(),
			array( 'post_id' => (int) $post_id ),
			60
		);
	}

	/**
	 * Подсказка организатору о результате ценовой модерации.
	 *
	 * Отображается в админке после сохранения события, если
	 * платное событие снято с модерации из-за незаполненных
	 * данных билетов.
	 *
	 * @return void
	 */
	public function render_event_notice() {
		if ( ! function_exists( 'get_current_screen' ) || ! get_current_screen() ) {
			return;
		}

		$key     = 'idpg_moderation_ticket_notice_' . get_current_user_id();
		$payload = get_transient( $key );

		if ( empty( $payload['post_id'] ) ) {
			return;
		}

		delete_transient( $key );

		$post_id = (int) $payload['post_id'];

		if ( 'event' !== get_post_type( $post_id ) ) {
			return;
		}

		$edit_url = get_edit_post_link( $post_id, 'raw' );

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%1$s</p>%2$s</div>',
			esc_html__( 'Платное событие не отправлено на модерацию: заполните блок «Билеты и цены» — цену от, тип продажи и ссылку либо код виджета билетов. После заполнения отправьте событие на модерацию повторно.', 'idpg-core' ),
			$edit_url ? sprintf( '<p><a href="%1$s">%2$s</a></p>', esc_url( $edit_url ), esc_html__( 'Вернуться к редактированию события', 'idpg-core' ) ) : ''
		);
	}

	/**
	 * Обработчик смены статуса события.
	 *
	 * - Переход в pending (первичная отправка на модерацию) уведомляет админов.
	 * - Переход в publish уведомляет автора события об одобрении.
	 *
	 * @param string  $new_status Новый статус.
	 * @param string  $old_status Старый статус.
	 * @param WP_Post $post       Объект записи.
	 * @return void
	 */
	public function on_status_transition( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post || 'event' !== $post->post_type ) {
			return;
		}

		if ( 'pending' === $new_status && 'pending' !== $old_status ) {
			// Первичная отправка: draft/auto-draft/новое → pending.
			if ( in_array( (string) $old_status, array( 'draft', 'auto-draft', 'new', '' ), true ) ) {
				$this->notify_admins_new_pending( $post );
			}
		}

		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			$this->notify_author_published( $post );
		}
	}

	/**
	 * Уведомляет администраторов и редакторов о новом событии на модерации.
	 *
	 * @param WP_Post $post Событие.
	 * @return void
	 */
	private function notify_admins_new_pending( $post ) {
		$moderators = $this->get_moderator_emails();

		if ( empty( $moderators ) ) {
			return;
		}

		$edit_url  = get_edit_post_link( $post->ID, 'raw' );
		$subtitle  = __( 'Событие ожидает проверки', 'idpg-core' );
		$subject   = sprintf(
			/* translators: 1: название события. */
			__( 'Новое событие на модерации: «%1$s»', 'idpg-core' ),
			$post->post_title
		);

		$content  = '<p style="margin: 0 0 20px; font-size: 16px; line-height: 1.6; color: #4a4a4a;">';
		$content .= sprintf(
			/* translators: 1: название события. */
			esc_html__( 'Организатор отправил на модерацию событие «%1$s». Оно ещё не видно посетителям.', 'idpg-core' ),
			$post->post_title
		);
		$content .= '</p>';

		if ( $edit_url ) {
			$content .= $this->render_button( __( 'Проверить событие', 'idpg-core' ), $edit_url );
		}

		$body = IDPG_Auth_Emails::instance()->render_email_template( $content, $subtitle );

		foreach ( $moderators as $email ) {
			wp_mail( $email, $subject, $body );
		}
	}

	/**
	 * Уведомляет автора события об одобрении и публикации.
	 *
	 * @param WP_Post $post Событие.
	 * @return void
	 */
	private function notify_author_published( $post ) {
		$author = get_userdata( (int) $post->post_author );

		if ( ! $author || ! is_email( $author->user_email ) ) {
			return;
		}

		$view_url = get_permalink( $post->ID );

		if ( ! $view_url ) {
			return;
		}

		$subtitle = __( 'Событие опубликовано', 'idpg-core' );
		$subject  = sprintf(
			/* translators: 1: название события. */
			__( 'Ваше событие опубликовано: «%1$s»', 'idpg-core' ),
			$post->post_title
		);

		$content  = '<p style="margin: 0 0 20px; font-size: 16px; line-height: 1.6; color: #4a4a4a;">';
		$content .= sprintf(
			/* translators: 1: название события. %s name replaced below */
			esc_html__( 'Здравствуйте, %1$s! Ваше событие «%2$s» прошло модерацию и уже доступно посетителям.', 'idpg-core' ),
			$author->display_name ? $author->display_name : $author->user_login,
			$post->post_title
		);
		$content .= '</p>';

		$content .= $this->render_button( __( 'Посмотреть событие', 'idpg-core' ), $view_url );

		$body = IDPG_Auth_Emails::instance()->render_email_template( $content, $subtitle );

		wp_mail( $author->user_email, $subject, $body );
	}

	/**
	 * Список email-адресов модераторов (администраторы и редакторы).
	 *
	 * Извлекаются только уникальные адреса.
	 *
	 * @return string[]
	 */
	private function get_moderator_emails() {
		$emails = array();

		$users = get_users( array(
			'role__in' => array( 'administrator', 'editor' ),
			'fields'   => 'user_email',
		) );

		foreach ( $users as $email ) {
			if ( is_email( $email ) ) {
				$emails[ $email ] = $email;
			}
		}

		// Админ сайта как страховка, если роли не найдены.
		$admin_email = get_option( 'admin_email' );
		if ( is_email( $admin_email ) ) {
			$emails[ $admin_email ] = $admin_email;
		}

		return array_values( $emails );
	}

	/**
	 * HTML-кнопка письма (в стиле писем IDPG_Auth_Emails).
	 *
	 * @param string $text Текст кнопки.
	 * @param string $url  Ссылка.
	 * @return string
	 */
	private function render_button( $text, $url ) {
		$html  = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin: 0 auto;">';
		$html .= '<tr>';
		$html .= '<td style="background-color: #007cba; border-radius: 6px; text-align: center;">';
		$html .= '<a href="' . esc_url( $url ) . '" ';
		$html .= 'style="display: inline-block; padding: 14px 36px; font-size: 16px; font-weight: 600; ';
		$html .= 'color: #ffffff; text-decoration: none; line-height: 1; border-radius: 6px;">';
		$html .= esc_html( $text );
		$html .= '</a>';
		$html .= '</td>';
		$html .= '</tr>';
		$html .= '</table>';

		return $html;
	}
}