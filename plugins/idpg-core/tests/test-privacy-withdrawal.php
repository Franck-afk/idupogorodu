<?php
/**
 * Тесты отзыва согласия на обработку персональных данных (152-ФЗ).
 *
 * Раньше текст на /privacy/ обещал субъекту «отозвать согласие на обработку»,
 * но способа не было — обещание на публичной странице без механизма.
 * Теперь отзыв оформлен отдельной записью и не затирает запись о согласии:
 * факт «было дано, потом отозвано» сам по себе подлежит хранению.
 *
 * @package idpg-core
 */

/**
 * Class Test_Privacy_Consent_Withdrawal
 */
class Test_Privacy_Consent_Withdrawal extends WP_UnitTestCase {

	/**
	 * ID пользователя для теста.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Создаёт пользователя с зафиксированным согласием.
	 */
	public function set_up() {
		parent::set_up();

		$this->user_id = self::factory()->user->create();
		IDPG_Privacy::record_consent( $this->user_id, 'organizer' );
	}

	/**
	 * Убирает запись об отзыве.
	 */
	public function tear_down() {
		delete_user_meta( $this->user_id, IDPG_Privacy::META_WITHDRAWAL );

		parent::tear_down();
	}

	/* ---------------------------------------------------------------------
	 * Бэкенд
	 * ------------------------------------------------------------------ */

	/**
	 * Изначально согласие действует.
	 */
	public function test_consent_active_before_withdrawal() {
		$this->assertTrue( IDPG_Privacy::has_consent( $this->user_id ) );
		$this->assertFalse( IDPG_Privacy::is_withdrawn( $this->user_id ) );
	}

	/**
	 * Отзыв фиксируется.
	 */
	public function test_withdrawal_is_recorded() {
		$this->assertTrue( IDPG_Privacy::withdraw_consent( $this->user_id ) );

		$this->assertTrue( IDPG_Privacy::is_withdrawn( $this->user_id ) );

		$record = IDPG_Privacy::get_withdrawal( $this->user_id );

		$this->assertNotEmpty( $record['at'], 'В записи об отзыве нет даты.' );
		$this->assertArrayHasKey( 'version', $record );
		$this->assertArrayHasKey( 'ip_hash', $record );
	}

	/**
	 * После отзыва согласие больше не считается действующим.
	 */
	public function test_withdrawn_consent_is_not_active() {
		IDPG_Privacy::withdraw_consent( $this->user_id );

		$this->assertFalse(
			IDPG_Privacy::has_consent( $this->user_id ),
			'Отозванное согласие продолжает считаться действующим.'
		);
	}

	/**
	 * Запись о самом согласии не затирается: нужен след для доказательства.
	 */
	public function test_consent_record_survives_withdrawal() {
		$before = IDPG_Privacy::get_consent( $this->user_id );

		IDPG_Privacy::withdraw_consent( $this->user_id );

		$after = IDPG_Privacy::get_consent( $this->user_id );

		$this->assertSame( $before, $after, 'Отзыв стёр запись о согласии.' );
		$this->assertNotEmpty( $after['given'] );
		$this->assertSame( 'organizer', $after['source'] );
	}

	/**
	 * Повторный отзыв не плодит дубликаты и не падает.
	 */
	public function test_repeat_withdrawal_is_safe() {
		IDPG_Privacy::withdraw_consent( $this->user_id );
		$first = IDPG_Privacy::get_withdrawal( $this->user_id );

		IDPG_Privacy::withdraw_consent( $this->user_id );
		$second = IDPG_Privacy::get_withdrawal( $this->user_id );

		$this->assertTrue( IDPG_Privacy::is_withdrawn( $this->user_id ) );
		$this->assertArrayNotHasKey( 0, $second, 'Запись об отзыве стала списком.' );
		$this->assertNotEmpty( $second['at'] );
		$this->assertNotEmpty( $first['at'] );
	}

	/**
	 * Новое согласие снимает прежний отзыв.
	 */
	public function test_new_consent_clears_withdrawal() {
		IDPG_Privacy::withdraw_consent( $this->user_id );
		$this->assertFalse( IDPG_Privacy::has_consent( $this->user_id ) );

		IDPG_Privacy::record_consent( $this->user_id, 'manual' );

		$this->assertFalse(
			IDPG_Privacy::is_withdrawn( $this->user_id ),
			'Повторное согласие не сняло отзыв.'
		);
		$this->assertTrue( IDPG_Privacy::has_consent( $this->user_id ) );
	}

	/**
	 * Хук отзыва срабатывает с ID пользователя и записью.
	 */
	public function test_withdrawn_action_fires() {
		$seen = array();

		add_action(
			'idpg_privacy_consent_withdrawn',
			static function ( $user_id, $record ) use ( &$seen ) {
				$seen = array( $user_id, $record );
			},
			10,
			2
		);

		IDPG_Privacy::withdraw_consent( $this->user_id );

		$this->assertCount( 2, $seen, 'Хук отзыва не сработал.' );
		$this->assertSame( $this->user_id, $seen[0] );
		$this->assertNotEmpty( $seen[1]['at'] );
	}

	/**
	 * Отзыв для несуществующего пользователя безопасен.
	 */
	public function test_withdrawal_rejects_invalid_user() {
		$this->assertFalse( IDPG_Privacy::withdraw_consent( 0 ) );
		$this->assertFalse( IDPG_Privacy::withdraw_consent( -1 ) );
	}

	/* ---------------------------------------------------------------------
	 * Форма на /privacy/
	 * ------------------------------------------------------------------ */

	/**
	 * Авторизованному субъекту выводится форма отзыва.
	 */
	public function test_form_is_rendered_for_logged_in() {
		wp_set_current_user( $this->user_id );

		ob_start();
		IDPG_Privacy::instance()->render_subject_tools();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'idpg_privacy_withdraw_submit', $html );
		$this->assertStringContainsString( 'idpg_privacy_withdraw_confirm', $html );
		$this->assertStringContainsString( 'idpg_privacy_withdraw_nonce', $html );
		$this->assertStringContainsString( 'idpg-privacy-tools__block', $html );
	}

	/**
	 * Гостю форма не выводится — только предложение войти.
	 */
	public function test_form_hidden_for_guests() {
		wp_set_current_user( 0 );

		ob_start();
		IDPG_Privacy::instance()->render_subject_tools();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'idpg_privacy_withdraw_submit', $html );
		$this->assertStringContainsString( 'Войти в аккаунт', $html );
	}

	/**
	 * После отзыва форма исчезает и показывается дата.
	 */
	public function test_form_disappears_after_withdrawal() {
		IDPG_Privacy::withdraw_consent( $this->user_id );
		wp_set_current_user( $this->user_id );

		ob_start();
		IDPG_Privacy::instance()->render_subject_tools();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'idpg_privacy_withdraw_submit', $html );
		$this->assertStringContainsString( 'отозвано', $html );
	}

	/**
	 * Пользователю без записи о согласии форма не выводится.
	 */
	public function test_form_hidden_without_consent() {
		$fresh = self::factory()->user->create();
		wp_set_current_user( $fresh );

		ob_start();
		IDPG_Privacy::instance()->render_subject_tools();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'idpg_privacy_withdraw_submit', $html );
		$this->assertStringContainsString( 'не зафиксировано', $html );
	}

	/**
	 * Поле согласия в разметке формы отзыва.
	 */
	public function test_confirm_checkbox_is_required() {
		wp_set_current_user( $this->user_id );

		ob_start();
		IDPG_Privacy::instance()->render_subject_tools();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/name="idpg_privacy_withdraw_confirm"[^>]*required/',
			$html,
			'У чекбокса подтверждения нет атрибута required.'
		);
	}

	/**
	 * Поле согласия не выводится вместе с формой удаления данных.
	 */
	public function test_withdraw_and_delete_blocks_are_separate() {
		wp_set_current_user( $this->user_id );

		ob_start();
		IDPG_Privacy::instance()->render_subject_tools();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'idpg_privacy_request', $html );
		$this->assertStringContainsString( 'idpg_privacy_withdraw_submit', $html );
	}
}
