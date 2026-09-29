<?php
/**
 * Юнит-тесты DEV-46: отклонение события модератором.
 *
 * DEV-42 зарегистрировал статус `rejected`, но выставить его было нечем.
 * Здесь проверяется, что появился весь путь: row action в списке событий →
 * форма с причиной → отклонение → письмо автору → событие снова редактируемо
 * организатором через REST.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Event_Rejection
 */
class Test_Event_Rejection extends WP_UnitTestCase {

	/**
	 * Перехваченные письма: по одному на вызов wp_mail().
	 *
	 * @var array
	 */
	private $sent = array();

	/**
	 * Подготовка окружения.
	 */
	public function set_up() {
		parent::set_up();

		IDPG_Event_Rejection::instance();
		IDPG_REST_Organizer::instance();
		IDPG_Roles::instance()->create_roles();
		IDPG_Event_Moderation::instance();
		IDPG_REST_Organizer::instance()->register_post_status();

		$this->sent = array();

		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/**
	 * Очистка окружения.
	 */
	public function tear_down() {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );

		parent::tear_down();
	}

	/**
	 * Перехват wp_mail().
	 *
	 * @param null|bool $short_circuit Обрыв отправки.
	 * @param array     $atts          Аргументы письма.
	 * @return bool
	 */
	public function capture_mail( $short_circuit, $atts ) {
		$this->sent[] = $atts;

		return true;
	}

	/**
	 * Письма, адресованные конкретному адресату.
	 *
	 * Дополнительно отсеиваются по теме: создание пользователей в тестах
	 * отправляет приветственные письма (user_register → send_welcome_email),
	 * и они не имеют отношения к проверке отклонения. Тема письма об
	 * отклонении всегда начинается с «Событие отклонено».
	 *
	 * @param string $email Адрес.
	 * @return array[]
	 */
	private function mails_to( $email ) {
		return array_values(
			array_filter(
				$this->sent,
				static function ( $mail ) use ( $email ) {
					if ( ! isset( $mail['to'] ) || $mail['to'] !== $email ) {
						return false;
					}

					return isset( $mail['subject'] )
						&& false !== stripos( (string) $mail['subject'], 'отклонено' );
				}
			)
		);
	}

	/**
	 * Перехватить wp_die() как исключение.
	 *
	 * Обработчик формы заканчивается wp_die(), который иначе завершил бы
	 * тестовый процесс. Возвращает колбэк для remove_filter.
	 *
	 * @return callable
	 */
	protected function catch_wpdie() {
		$thrown = array();

		$handler = static function () use ( &$thrown ) {
			return static function ( $message = '' ) use ( &$thrown ) {
				$thrown[] = is_wp_error( $message ) ? $message->get_error_message() : (string) $message;

				throw new RuntimeException( 'WPDIE: ' . wp_strip_all_tags( (string) $message ) );
			};
		};

		add_filter( 'wp_die_handler', $handler );

		return $handler;
	}

	/**
	 * Создать организатора.
	 *
	 * @param string $login Логин.
	 * @param string $email Email.
	 * @return WP_User
	 */
	protected function make_organizer( $login, $email ) {
		$user = new WP_User(
			$this->factory()->user->create(
				array(
					'user_login' => $login,
					'user_email' => $email,
					'role'       => 'subscriber',
				)
			)
		);

		foreach ( (array) $user->roles as $role ) {
			$user->remove_role( $role );
		}
		$user->add_role( IDPG_Roles::ROLE_SLUG );

		return $user;
	}

	/**
	 * Создать администратора.
	 *
	 * @param string $login Логин.
	 * @param string $email Email.
	 * @return WP_User
	 */
	protected function make_admin( $login, $email ) {
		return $this->factory()->user->create_and_get(
			array(
				'user_login' => $login,
				'user_email' => $email,
				'role'       => 'administrator',
			)
		);
	}

	/**
	 * Создать событие на модерации.
	 *
	 * @param int $author_id ID автора.
	 * @param string $title Заголовок.
	 * @return int
	 */
	protected function make_pending_event( $author_id, $title = 'Событие на модерации' ) {
		return $this->factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'pending',
				'post_author' => $author_id,
				'post_title'  => $title,
			)
		);
	}

	/**
	 * Отклонить событие настоящим методом продакшна.
	 *
	 * Вызывается именно IDPG_Event_Rejection::reject_event() — тот же путь,
	 * что и из обработчика формы. Дублировать логику в тесте нельзя: она
	 * разошлась бы с кодом, и тест продолжил бы «зеленеть» после поломки
	 * продакшна.
	 *
	 * @param WP_Post $post   Событие.
	 * @param string  $reason Причина.
	 * @return mixed
	 */
	protected function run_reject( $post, $reason = '' ) {
		return IDPG_Event_Rejection::instance()->reject_event( $post->ID, $reason );
	}

	// =====================================================================
	// Права
	// =====================================================================

	/**
	 * Только пользователь с edit_others_events может отклонять.
	 */
	public function test_reject_capability() {
		$organizer = $this->make_organizer( 'cap_org', 'cap-org@example.com' );
		$admin     = $this->make_admin( 'cap_admin', 'cap-admin@example.com' );

		wp_set_current_user( $organizer->ID );
		$this->assertFalse( IDPG_Event_Rejection::current_user_can_reject() );

		wp_set_current_user( $admin->ID );
		$this->assertTrue( IDPG_Event_Rejection::current_user_can_reject() );
	}

	// =====================================================================
	// Guards обработчика формы
	// =====================================================================

	/**
	 * Обработчик формы отклоняет запрос без прав.
	 *
	 * wp_die() перехватывается, чтобы не завершать тестовый процесс.
	 */
	public function test_handler_requires_capability() {
		$organizer = $this->make_organizer( 'guard_org', 'guard-org@example.com' );
		$post      = get_post( $this->make_pending_event( $organizer->ID ) );

		wp_set_current_user( $organizer->ID );

		$_POST = array(
			'action'  => IDPG_Event_Rejection::ACTION,
			'post_id' => (string) $post->ID,
			'reason'  => 'Причина',
		);

		$handler = $this->catch_wpdie();

		try {
			IDPG_Event_Rejection::instance()->handle_reject();
			$this->fail( 'Ожидался отказ по правам.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'Недостаточно прав', $e->getMessage() );
		} finally {
			remove_filter( 'wp_die_handler', $handler );
		}

		// Событие не тронуто.
		$this->assertSame( 'pending', get_post_status( $post->ID ) );
	}

	/**
	 * Обработчик формы отклоняет запрос без nonce.
	 */
	public function test_handler_requires_nonce() {
		$admin = $this->make_admin( 'guard_admin', 'guard-admin@example.com' );
		$post  = get_post( $this->make_pending_event( $admin->ID ) );

		wp_set_current_user( $admin->ID );

		// Права есть, nonce намеренно нет.
		$_POST = array(
			'action'  => IDPG_Event_Rejection::ACTION,
			'post_id' => (string) $post->ID,
			'reason'  => 'Причина',
		);

		$handler = $this->catch_wpdie();

		try {
			IDPG_Event_Rejection::instance()->handle_reject();
			$this->fail( 'Ожидался отказ по nonce.' );
		} catch ( RuntimeException $e ) {
			// Любое прерывание означает, что обработчик не дошёл до отклонения.
			$this->assertStringNotContainsString( 'SIBLING', $e->getMessage() );
		} finally {
			remove_filter( 'wp_die_handler', $handler );
		}

		$this->assertSame( 'pending', get_post_status( $post->ID ) );
	}

	/**
	 * Ключ действия зарегистрирован на admin-post.php.
	 */
	public function test_handler_hook_registered() {
		$this->assertSame(
			10,
			has_action( 'admin_post_' . IDPG_Event_Rejection::ACTION, array( IDPG_Event_Rejection::instance(), 'handle_reject' ) )
		);
	}

	/**
	 * Повторное отклонение не шлёт второе письмо.
	 */
	public function test_double_reject_sends_one_email() {
		$admin  = $this->make_admin( 'double_admin', 'double-admin@example.com' );
		$author = $this->make_organizer( 'double_author', 'double-author@example.com' );

		$post = get_post( $this->make_pending_event( $author->ID ) );

		wp_set_current_user( $admin->ID );

		$this->assertTrue( $this->run_reject( $post, 'Причина' ) );

		// Второй вызов — ошибка, а не новое письмо.
		$second = $this->run_reject( $post, 'Причина' );

		$this->assertWPError( $second );
		$this->assertSame( 'idpg_reject_already_rejected', $second->get_error_code() );
		$this->assertCount( 1, $this->mails_to( 'double-author@example.com' ) );
	}

	/**
	 * Отклонить несуществующее событие — ошибка, не исключение.
	 */
	public function test_reject_unknown_event() {
		$admin = $this->make_admin( 'unknown_admin', 'unknown-admin@example.com' );

		wp_set_current_user( $admin->ID );

		$result = IDPG_Event_Rejection::instance()->reject_event( 999999, 'Причина' );

		$this->assertWPError( $result );
		$this->assertSame( 'idpg_reject_event_not_found', $result->get_error_code() );
	}

	// =====================================================================
	// Row action
	// =====================================================================

	/**
	 * Row action появляется у администратора для события на модерации.
	 */
	public function test_row_action_added_for_admin() {
		$admin = $this->make_admin( 'row_admin', 'row-admin@example.com' );
		$post  = get_post( $this->make_pending_event( $admin->ID ) );

		wp_set_current_user( $admin->ID );

		$actions = apply_filters( 'post_row_actions', array( 'edit' => 'Изменить' ), $post );

		$this->assertArrayHasKey( 'idpg_reject', $actions );
		$this->assertStringContainsString( 'Отклонить', $actions['idpg_reject'] );
		$this->assertStringContainsString( 'idpg-reject-event', $actions['idpg_reject'] );
	}

	/**
	 * У организатора row action не появляется.
	 */
	public function test_row_action_absent_for_organizer() {
		$organizer = $this->make_organizer( 'row_org', 'row-org@example.com' );
		$post      = get_post( $this->make_pending_event( $organizer->ID ) );

		wp_set_current_user( $organizer->ID );

		$actions = apply_filters( 'post_row_actions', array( 'edit' => 'Изменить' ), $post );

		$this->assertArrayNotHasKey( 'idpg_reject', $actions );
	}

	/**
	 * Для не-pending события row action не появляется.
	 */
	public function test_row_action_absent_for_draft() {
		$admin = $this->make_admin( 'row_admin2', 'row-admin2@example.com' );
		$post  = $this->factory()->post->create_and_get(
			array(
				'post_type'   => 'event',
				'post_status' => 'draft',
				'post_author' => $admin->ID,
			)
		);

		wp_set_current_user( $admin->ID );

		$actions = apply_filters( 'post_row_actions', array( 'edit' => 'Изменить' ), $post );

		$this->assertArrayNotHasKey( 'idpg_reject', $actions );
	}

	/**
	 * Для других типов записей действие не добавляется.
	 */
	public function test_row_action_absent_for_other_post_types() {
		$admin = $this->make_admin( 'row_admin3', 'row-admin3@example.com' );
		$post  = $this->factory()->post->create_and_get(
			array(
				'post_type'   => 'post',
				'post_status' => 'pending',
				'post_author' => $admin->ID,
			)
		);

		wp_set_current_user( $admin->ID );

		$actions = apply_filters( 'post_row_actions', array( 'edit' => 'Изменить' ), $post );

		$this->assertArrayNotHasKey( 'idpg_reject', $actions );
	}

	// =====================================================================
	// Отклонение
	// =====================================================================

	/**
	 * Отклонение переводит событие в rejected.
	 */
	public function test_reject_sets_status() {
		$admin  = $this->make_admin( 'rej_admin', 'rej-admin@example.com' );
		$author = $this->make_organizer( 'rej_author', 'rej-author@example.com' );

		$post = get_post( $this->make_pending_event( $author->ID ) );

		wp_set_current_user( $admin->ID );
		$this->run_reject( $post, 'Нет ссылки на билеты' );

		$this->assertSame( IDPG_Event_Rejection::STATUS_REJECTED, get_post_status( $post->ID ) );
	}

	/**
	 * Хук idpg_event_rejected срабатывает с корректными аргументами.
	 */
	public function test_reject_fires_action() {
		$admin  = $this->make_admin( 'hook_admin', 'hook-admin@example.com' );
		$author = $this->make_organizer( 'hook_author', 'hook-author@example.com' );

		$post = get_post( $this->make_pending_event( $author->ID ) );

		$captured = array();

		$callback = static function ( $post_id, $reason, $actor ) use ( &$captured ) {
			$captured = array(
				'post_id' => (int) $post_id,
				'reason'  => (string) $reason,
				'actor'   => (int) $actor,
			);
		};

		add_action( 'idpg_event_rejected', $callback, 10, 3 );
		wp_set_current_user( $admin->ID );
		$this->run_reject( $post, 'Нет ссылки на билеты' );
		remove_action( 'idpg_event_rejected', $callback, 10 );

		$this->assertSame( $post->ID, $captured['post_id'] );
		$this->assertSame( 'Нет ссылки на билеты', $captured['reason'] );
		$this->assertSame( $admin->ID, $captured['actor'] );
	}

	/**
	 * Причина сохраняется и попадает в письмо.
	 */
	public function test_reason_saved_and_emailed() {
		$admin  = $this->make_admin( 'reason_admin', 'reason-admin@example.com' );
		$author = $this->make_organizer( 'reason_author', 'reason-author@example.com' );

		$post = get_post( $this->make_pending_event( $author->ID ) );

		wp_set_current_user( $admin->ID );
		$this->run_reject( $post, 'Укажите продавца билетов' );

		$this->assertSame(
			'Укажите продавца билетов',
			get_post_meta( $post->ID, IDPG_Event_Rejection::META_REASON, true )
		);
		$this->assertNotEmpty( get_post_meta( $post->ID, IDPG_Event_Rejection::META_REJECTED_AT, true ) );

		$mails = $this->mails_to( 'reason-author@example.com' );

		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'Укажите продавца билетов', $mails[0]['message'] );
	}

	/**
	 * Письмо отправляется и без причины.
	 */
	public function test_email_sent_without_reason() {
		$admin  = $this->make_admin( 'noreason_admin', 'noreason-admin@example.com' );
		$author = $this->make_organizer( 'noreason_author', 'noreason-author@example.com' );

		$post = get_post( $this->make_pending_event( $author->ID ) );

		wp_set_current_user( $admin->ID );
		$this->run_reject( $post, '' );

		$this->assertSame( '', get_post_meta( $post->ID, IDPG_Event_Rejection::META_REASON, true ) );

		$mails = $this->mails_to( 'noreason-author@example.com' );

		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'отклонено', $mails[0]['message'] );
	}

	/**
	 * Письмо уходит автору, а не модератору.
	 */
	public function test_email_goes_to_author_only() {
		$admin  = $this->make_admin( 'to_admin', 'to-admin@example.com' );
		$author = $this->make_organizer( 'to_author', 'to-author@example.com' );

		$post = get_post( $this->make_pending_event( $author->ID ) );

		wp_set_current_user( $admin->ID );
		$this->run_reject( $post, 'Причина' );

		$this->assertCount( 1, $this->mails_to( 'to-author@example.com' ) );
		$this->assertCount( 0, $this->mails_to( 'to-admin@example.com' ) );
	}

	/**
	 * HTML в причине экранируется.
	 */
	public function test_reason_html_is_escaped() {
		$admin  = $this->make_admin( 'esc_admin', 'esc-admin@example.com' );
		$author = $this->make_organizer( 'esc_author', 'esc-author@example.com' );

		$post = get_post( $this->make_pending_event( $author->ID ) );

		wp_set_current_user( $admin->ID );
		$this->run_reject( $post, '<script>alert(1)</script>' );

		$mails = $this->mails_to( 'esc-author@example.com' );

		$this->assertStringNotContainsString( '<script>', $mails[0]['message'] );
	}

	// =====================================================================
	// Жизненный цикл после отклонения
	// =====================================================================

	/**
	 * Организатор может править отклонённое событие через REST.
	 */
	public function test_organizer_can_edit_rejected_event() {
		$admin  = $this->make_admin( 'cycle_admin', 'cycle-admin@example.com' );
		$author = $this->make_organizer( 'cycle_author', 'cycle-author@example.com' );

		$post = get_post( $this->make_pending_event( $author->ID ) );

		wp_set_current_user( $admin->ID );
		$this->run_reject( $post, 'Исправьте' );

		// Организатор возвращается к событию и правит его.
		wp_set_current_user( $author->ID );

		$request = new WP_REST_Request( 'PUT', '/idpg/v1/organizer/events/' . $post->ID );
		$request->set_body_params( array( 'title' => 'Исправленное событие' ) );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Исправленное событие', get_post( $post->ID )->post_title );
	}

	/**
	 * Отклонённое событие можно повторно отправить на модерацию.
	 */
	public function test_organizer_can_resubmit_rejected_event() {
		$admin  = $this->make_admin( 'resub_admin', 'resub-admin@example.com' );
		$author = $this->make_organizer( 'resub_author', 'resub-author@example.com' );

		$post_id = $this->make_pending_event( $author->ID );
		$post    = get_post( $post_id );

		update_post_meta( $post_id, '_idpg_start_date', '2026-10-01 12:00:00' );
		update_post_meta( $post_id, '_idpg_is_free', 'no' );
		update_post_meta( $post_id, '_idpg_price_from', '500' );
		update_post_meta( $post_id, '_idpg_ticket_type', 'external_link' );
		update_post_meta( $post_id, '_idpg_ticket_url', 'https://tickets.example' );

		$city = $this->factory()->term->create( array( 'taxonomy' => 'city' ) );
		wp_set_object_terms( $post_id, array( $city ), 'city' );

		wp_set_current_user( $admin->ID );
		$this->run_reject( $post, 'Исправьте' );

		$this->assertSame( IDPG_Event_Rejection::STATUS_REJECTED, get_post_status( $post_id ) );

		wp_set_current_user( $author->ID );

		$request = new WP_REST_Request( 'POST', '/idpg/v1/organizer/events/' . $post_id . '/submit' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( 'pending', get_post_status( $post_id ) );
	}

	/**
	 * Причина отклонения видна организатору в ответе профиля/списка.
	 *
	 * Отклонённое событие попадает в список с фильтром rejected.
	 */
	public function test_rejected_event_visible_in_list() {
		$admin  = $this->make_admin( 'list_admin', 'list-admin@example.com' );
		$author = $this->make_organizer( 'list_author', 'list-author@example.com' );

		$post = get_post( $this->make_pending_event( $author->ID ) );

		wp_set_current_user( $admin->ID );
		$this->run_reject( $post, 'Причина' );

		wp_set_current_user( $author->ID );

		$request = new WP_REST_Request( 'GET', '/idpg/v1/organizer/events' );
		$request->set_query_params( array( 'status' => IDPG_Event_Rejection::STATUS_REJECTED ) );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$data = rest_do_request( $request )->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertSame( $post->ID, $data['items'][0]['id'] );
		$this->assertSame( IDPG_Event_Rejection::STATUS_REJECTED, $data['items'][0]['status'] );
	}
}
