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
	}

	/**
	 * Принудительная модерация при программной публикации события.
	 *
	 * Срабатывает на wp_insert_post_data: если событие пытается
	 * опубликовать пользователь без publish_events (организатор),
	 * статус понижается до pending. Исключение — правка уже
	 * опубликованного события: статус publish сохраняется.
	 *
	 * @param array $data    Данные поста после фильтров.
	 * @param array $postarr Исходные данные wp_insert_post/wp_update_post.
	 * @return array
	 */
	public function enforce_pending_for_organizer( $data, $postarr ) {
		if ( ! isset( $data['post_type'] ) || 'event' !== $data['post_type'] ) {
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