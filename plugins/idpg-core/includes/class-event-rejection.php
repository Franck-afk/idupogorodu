<?php
/**
 * Отклонение события модератором (DEV-46).
 *
 * DEV-42 зарегистрировал публичный статус «Отклонено модератором»
 * (`rejected`) и переходы `rejected → draft / pending` из кабинета
 * организатора, но выставить этот статус было нечем: в админке не было
 * ни кнопки, ни уведомления автору. Статус существовал только как
 * перечисление в коде.
 *
 * Здесь появляется недостающее:
 *   - row action «Отклонить» в списке событий (`edit.php?post_type=event`);
 *   - форма с необязательным указанием причины отклонения;
 *   - письмо автору с этой причиной;
 *   - хук `idpg_event_rejected` для сторонней логики.
 *
 * Отдельный класс, а не дописывание `IDPG_Event_Moderation`: модерация
 * по платности и отклонение редактором — разные решения с разными
 * последствиями для события, и смешивать их в одном модуле не стоит.
 *
 * Одобрение не трогаем: его роль уже выполняет обычная публикация записи,
 * а письмо об одобрении отправляет `IDPG_Event_Moderation`.
 *
 * @package IDPG_Core
 */

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Event_Rejection
 */
class IDPG_Event_Rejection {

	/**
	 * Slug подстраницы админки с формой отклонения.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'idpg-reject-event';

	/**
	 * Родительское меню — единая страница плагина «Иду по городу».
	 *
	 * @var string
	 */
	const PARENT_SLUG = 'idpg-settings';

	/**
	 * Действие обработчика формы.
	 *
	 * @var string
	 */
	const ACTION = 'idpg_reject_event';

	/**
	 * Nonce-действие.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'idpg_reject_event';

	/**
	 * Статус отклонённого события.
	 *
	 * Совпадает с константой DEV-42; класс намеренно не зависит от
	 * `IDPG_REST_Organizer`, чтобы работал и при отключённом REST-модуле.
	 *
	 * @var string
	 */
	const STATUS_REJECTED = 'rejected';

	/**
	 * Ключ user meta с причиной последнего отклонения.
	 *
	 * @var string
	 */
	const META_REASON = '_idpg_rejection_reason';

	/**
	 * Ключ user meta с датой отклонения.
	 *
	 * @var string
	 */
	const META_REJECTED_AT = '_idpg_rejected_at';

	/**
	 * Синглтон.
	 *
	 * @var IDPG_Event_Rejection|null
	 */
	private static $instance = null;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Event_Rejection
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Конструктор. Регистрирует хуки.
	 */
	private function __construct() {
		add_filter( 'post_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'register_page' ), 20 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_reject' ) );
	}

	/**
	 * Права, достаточные для отклонения события.
	 *
	 * `edit_others_events` есть у администратора и редактора — ровно те, кому
	 * модерация и положена. Проверяется и в row action, и в обработчике:
	 * прямая отправка в admin-post.php не должна обходить UI.
	 *
	 * @return bool
	 */
	public static function current_user_can_reject() {
		return current_user_can( 'edit_others_events' );
	}

	/**
	 * Row action «Отклонить» в списке событий.
	 *
	 * @param array    $actions Список действий строки.
	 * @param WP_Post  $post    Запись.
	 * @return array
	 */
	public function add_row_action( $actions, $post ) {
		if ( ! $post instanceof WP_Post || 'event' !== $post->post_type ) {
			return $actions;
		}

		// Отклонять имеет смысл только то, что сейчас на модерации.
		if ( 'pending' !== $post->post_status ) {
			return $actions;
		}

		if ( ! self::current_user_can_reject() ) {
			return $actions;
		}

		$actions['idpg_reject'] = sprintf(
			'<a href="%1$s" class="idpg-reject-link">%2$s</a>',
			esc_url( $this->page_url( $post->ID ) ),
			esc_html__( 'Отклонить', 'idpg-core' )
		);

		return $actions;
	}

	/**
	 * Регистрация подстраницы с формой отклонения.
	 *
	 * @return void
	 */
	public function register_page() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Отклонить событие', 'idpg-core' ),
			__( 'Отклонить событие', 'idpg-core' ),
			'edit_others_events',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * URL страницы отклонения для конкретного события.
	 *
	 * @param int $post_id ID события.
	 * @return string
	 */
	public function page_url( $post_id ) {
		return add_query_arg(
			array(
				'page'    => self::PAGE_SLUG,
				'post_id' => (int) $post_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Страница с формой: предпросмотр события и поле причины.
	 *
	 * @return void
	 */
	public function render_page() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- форма ниже с nonce; здесь только предпросмотр.
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! self::current_user_can_reject() ) {
			wp_die( esc_html__( 'Недостаточно прав для отклонения событий.', 'idpg-core' ), 403 );
		}

		if ( ! $post instanceof WP_Post || 'event' !== $post->post_type ) {
			wp_die( esc_html__( 'Событие не найдено.', 'idpg-core' ), 404 );
		}

		$done = isset( $_GET['idpg_rejected'] ) ? absint( $_GET['idpg_rejected'] ) : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Отклонить событие', 'idpg-core' ); ?></h1>

			<?php if ( $done === $post->ID ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Событие отклонено. Автор получил письмо с причиной.', 'idpg-core' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( 'pending' !== $post->post_status ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: текущий статус события. */
							esc_html__( 'Отклонить можно только событие, ожидающее модерации. Текущий статус: %s.', 'idpg-core' ),
							esc_html( $post->post_status )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<h2><?php echo esc_html( $post->post_title ); ?></h2>
			<p>
				<a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID, 'raw' ) ); ?>">
					<?php esc_html_e( 'Открыть событие в редакторе', 'idpg-core' ); ?>
				</a>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, '_idpg_nonce' ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="idpg_reason"><?php esc_html_e( 'Причина отклонения', 'idpg-core' ); ?></label>
						</th>
						<td>
							<textarea
								name="reason"
								id="idpg_reason"
								rows="4"
								class="large-text"
								placeholder="<?php esc_attr_e( 'Например: не указана ссылка на покупку билетов, событие прошло в прошлом.', 'idpg-core' ); ?>"
							></textarea>
							<p class="description">
								<?php esc_html_e( 'Необязательно. Если указать, текст попадёт в письмо автору. Письмо отправляется в любом случае.', 'idpg-core' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Отклонить событие', 'idpg-core' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Обработчик отклонения.
	 *
	 * Только проверки прав и nonce плюс редиректы. Само отклонение — в
	 * reject_event(), чтобы его можно было вызвать и из теста: обработчик
	 * заканчивается `exit`, и такой путь иначе не покрыть.
	 *
	 * @return void
	 */
	public function handle_reject() {
		if ( ! self::current_user_can_reject() ) {
			wp_die( esc_html__( 'Недостаточно прав для отклонения событий.', 'idpg-core' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION, '_idpg_nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post instanceof WP_Post || 'event' !== $post->post_type ) {
			wp_die( esc_html__( 'Событие не найдено.', 'idpg-core' ), 404 );
		}

		$reason = isset( $_POST['reason'] ) ? wp_unslash( $_POST['reason'] ) : '';

		$result = $this->reject_event( $post_id, (string) $reason );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'idpg_error', rawurlencode( $result->get_error_code() ), $this->page_url( $post_id ) ) );
			exit;
		}

		wp_safe_redirect( $this->done_url( $post_id ) );
		exit;
	}

	/**
	 * Отклонение события: смена статуса, хук и письмо автору.
	 *
	 * Права и nonce проверяются в handle_reject() до вызова; здесь
	 * предполагается, что вызов идёт из админки.
	 *
	 * @param int    $post_id ID события.
	 * @param string $reason  Причина отклонения ('' — если не указана).
	 * @return true|WP_Error True либо ошибка.
	 */
	public function reject_event( $post_id, $reason = '' ) {
		$post_id = (int) $post_id;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post instanceof WP_Post || 'event' !== $post->post_type ) {
			return new WP_Error(
				'idpg_reject_event_not_found',
				__( 'Событие не найдено.', 'idpg-core' )
			);
		}

		// Повторное отклонение уже отклонённого события — no-op, чтобы
		// двойной клик не слал второе письмо.
		if ( self::STATUS_REJECTED === $post->post_status ) {
			return new WP_Error(
				'idpg_reject_already_rejected',
				__( 'Событие уже отклонено.', 'idpg-core' )
			);
		}

		$reason = sanitize_textarea_field( (string) $reason );

		// Причина пишется до смены статуса: обновление поста не затрагивает
		// meta, и так её можно показать автору.
		if ( '' !== $reason ) {
			update_post_meta( $post_id, self::META_REASON, $reason );
			update_post_meta( $post_id, self::META_REJECTED_AT, current_time( 'mysql' ) );
		}

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => self::STATUS_REJECTED,
			)
		);

		/**
		 * Действие после отклонения события модератором.
		 *
		 * @param int    $post_id ID события.
		 * @param string $reason  Причина отклонения ('' — если не указана).
		 * @param int    $actor   ID модератора.
		 */
		do_action( 'idpg_event_rejected', $post_id, $reason, get_current_user_id() );

		$this->send_rejection_email( $post, $reason );

		return true;
	}

	/**
	 * Адрес возврата после отклонения.
	 *
	 * @param int $post_id ID события.
	 * @return string
	 */
	private function done_url( $post_id ) {
		return add_query_arg(
			'idpg_rejected',
			(int) $post_id,
			$this->page_url( $post_id )
		);
	}

	/**
	 * Письмо автору события.
	 *
	 * @param WP_Post $post   Событие (со статусом до смены — заголовок тот же).
	 * @param string  $reason Причина отклонения.
	 * @return void
	 */
	private function send_rejection_email( $post, $reason ) {
		$user = get_userdata( (int) $post->post_author );

		if ( ! $user instanceof WP_User || ! $user->exists() || ! is_email( $user->user_email ) ) {
			return;
		}

		$edit_url = (string) get_edit_post_link( $post->ID, 'raw' );

		$content = sprintf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: название события. */
					__( 'Событие «%s» отклонено модератором.', 'idpg-core' ),
					$post->post_title
				)
			)
		);

		if ( '' !== $reason ) {
			$content .= sprintf(
				'<p><strong>%1$s</strong></p><p>%2$s</p>',
				esc_html__( 'Причина отклонения:', 'idpg-core' ),
				nl2br( esc_html( $reason ) )
			);
		}

		$content .= sprintf(
			'<p>%s</p>',
			esc_html__( 'Вы можете исправить событие и отправить его на модерацию повторно.', 'idpg-core' )
		);

		if ( $edit_url ) {
			$content .= sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( $edit_url ),
				esc_html__( 'Вернуться к редактированию события', 'idpg-core' )
			);
		}

		if ( class_exists( 'IDPG_Auth_Emails' ) ) {
			$content = IDPG_Auth_Emails::instance()->render_email_template(
				$content,
				__( 'Событие отклонено модератором', 'idpg-core' )
			);
		}

		/**
		 * Фильтр письма об отклонении события.
		 *
		 * @param array   $atts   Аргументы wp_mail().
		 * @param WP_Post $post   Событие.
		 * @param string  $reason Причина отклонения.
		 */
		$atts = apply_filters(
			'idpg_event_rejection_email_args',
			array(
				'to'      => $user->user_email,
				'subject' => sprintf(
					/* translators: %s: название сайта. */
					__( 'Событие отклонено — %s', 'idpg-core' ),
					get_bloginfo( 'name' )
				),
				'message' => $content,
				'headers' => array( 'Content-Type: text/html; charset=UTF-8' ),
			),
			$post,
			$reason
		);

		wp_mail( $atts['to'], $atts['subject'], $atts['message'], $atts['headers'] );
	}
}
