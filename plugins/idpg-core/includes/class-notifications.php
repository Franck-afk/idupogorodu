<?php
/**
 * Уведомления при изменении статуса событий.
 *
 * Отправляет email-уведомления автору события при изменении
 * статуса билетов (active → sold_out, active → cancelled и т.д.).
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Notifications
 *
 * Слушает хук idpg_ticket_status_changed и отправляет письма.
 */
class IDPG_Notifications {

	/**
	 * Синглтон.
	 *
	 * @var IDPG_Notifications|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Notifications
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
		add_action( 'idpg_ticket_status_changed', array( $this, 'on_status_changed' ), 10, 4 );
	}

	/**
	 * Обработчик изменения статуса билетов.
	 *
	 * @param int    $event_id    ID события.
	 * @param string $old_status  Предыдущий статус.
	 * @param string $new_status  Новый статус.
	 * @param array  $api_response Ответ API.
	 */
	public function on_status_changed( $event_id, $old_status, $new_status, $api_response ) {
		$post = get_post( $event_id );

		if ( ! $post ) {
			return;
		}

		$author = get_userdata( $post->post_author );

		if ( ! $author ) {
			return;
		}

		$this->send_status_notification( $author, $post, $old_status, $new_status );
	}

	/**
	 * Отправка email-уведомления автору события.
	 *
	 * @param WP_User $author     Объект автора.
	 * @param WP_Post $event      Объект события.
	 * @param string  $old_status Предыдущий статус.
	 * @param string  $new_status Новый статус.
	 */
	private function send_status_notification( $author, $event, $old_status, $new_status ) {
		$to = $author->user_email;

		$subject = sprintf(
			/* translators: 1: название события, 2: новый статус */
			__( 'Статус билетов изменён: "%1$s" — %2$s', 'idpg-core' ),
			$event->post_title,
			$this->get_status_label( $new_status )
		);

		$edit_link = get_edit_post_link( $event->ID, 'raw' );
		$view_link = get_permalink( $event->ID );

		$message = sprintf(
			__( "Здравствуйте, %s!\n\n", 'idpg-core' ),
			$author->display_name
		);

		$message .= sprintf(
			/* translators: 1: название события, 2: старый статус, 3: новый статус */
			__( "Статус билетов для события «%1$s» изменён: %2$s → %3$s.\n\n", 'idpg-core' ),
			$event->post_title,
			$this->get_status_label( $old_status ),
			$this->get_status_label( $new_status )
		);

		$message .= __( "Ссылки:\n", 'idpg-core' );

		if ( $edit_link ) {
			$message .= sprintf( "- Редактирование: %s\n", $edit_link );
		}
		if ( $view_link ) {
			$message .= sprintf( "- Просмотр: %s\n", $view_link );
		}

		$message .= __( "\nЭто автоматическое уведомление.", 'idpg-core' );

		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
		);

		wp_mail( $to, $subject, $message, $headers );
	}

	/**
	 * Получение читаемого названия статуса.
	 *
	 * @param string $status Код статуса.
	 * @return string Название на русском.
	 */
	private function get_status_label( $status ) {
		$labels = array(
			'active'   => __( 'Активны', 'idpg-core' ),
			'sold_out' => __( 'Распроданы', 'idpg-core' ),
			'cancelled' => __( 'Отменено', 'idpg-core' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}
}
