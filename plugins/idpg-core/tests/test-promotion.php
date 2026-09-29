<?php
/**
 * Тесты DEV-10: срок закрепления события и его автоснятие.
 *
 * До DEV-10 галочка «Закрепить в топе» ставила `_idpg_promoted = 'yes'`
 * навсегда — забытое закрепление держало событие в подборках неограниченно
 * долго. Теперь рядом живёт дата «Закрепить до», а ежедневная задача
 * `idpg_cron_unpin_expired` снимает закрепление после этой даты.
 *
 * Приёмка по карточке: событие с прошедшей датой закрепления теряет
 * `_idpg_promoted` после запуска cron; повторный запуск идемпотентен.
 *
 * @package idpg-core
 */

/**
 * Class Test_Promotion
 */
class Test_Promotion extends WP_UnitTestCase {

	/**
	 * Синглтон доступен.
	 */
	public function test_class_is_loaded() {
		$this->assertTrue( class_exists( 'IDPG_Promotion' ) );
		$this->assertSame( 'idpg_cron_unpin_expired', IDPG_Promotion::CRON_HOOK );
	}

	/**
	 * Создаёт событие с заданным состоянием закрепления.
	 *
	 * @param string $promoted 'yes' — закреплено, иначе нет.
	 * @param string $expires  Дата Y-m-d или ''.
	 * @return int ID события.
	 */
	private function make_event( $promoted = 'yes', $expires = '' ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'publish',
			)
		);

		if ( 'yes' === $promoted ) {
			update_post_meta( $post_id, IDPG_Promotion::META_PROMOTED, 'yes' );
		}

		if ( '' !== $expires ) {
			update_post_meta( $post_id, IDPG_Promotion::META_EXPIRES, $expires );
		}

		return $post_id;
	}

	/**
	 * Дата в прошлом.
	 *
	 * @return string
	 */
	private function yesterday() {
		return gmdate( 'Y-m-d', strtotime( '-1 day' ) );
	}

	/**
	 * Дата в будущем.
	 *
	 * @return string
	 */
	private function tomorrow() {
		return gmdate( 'Y-m-d', strtotime( '+1 day' ) );
	}

	/* ---------------------------------------------------------------------
	 * Состояние
	 * ------------------------------------------------------------------ */

	/**
	 * Без закрепления событие не активно.
	 */
	public function test_not_promoted_is_not_active() {
		$post_id = $this->make_event( 'no', $this->tomorrow() );

		$this->assertFalse( IDPG_Promotion::is_promoted( $post_id ) );
		$this->assertFalse( IDPG_Promotion::is_active( $post_id ) );
	}

	/**
	 * Закрепление без даты — бессрочное и активно.
	 */
	public function test_promoted_without_expiry_is_active() {
		$post_id = $this->make_event( 'yes', '' );

		$this->assertTrue( IDPG_Promotion::is_promoted( $post_id ) );
		$this->assertFalse( IDPG_Promotion::is_expired( $post_id ) );
		$this->assertTrue( IDPG_Promotion::is_active( $post_id ) );
	}

	/**
	 * Срок в будущем — закрепление активно.
	 */
	public function test_future_expiry_keeps_promotion() {
		$post_id = $this->make_event( 'yes', $this->tomorrow() );

		$this->assertFalse( IDPG_Promotion::is_expired( $post_id ) );
		$this->assertTrue( IDPG_Promotion::is_active( $post_id ) );
	}

	/**
	 * Срок в прошлом — закрепление истекло.
	 */
	public function test_past_expiry_expires_promotion() {
		$post_id = $this->make_event( 'yes', $this->yesterday() );

		$this->assertTrue( IDPG_Promotion::is_expired( $post_id ) );
		$this->assertFalse(
			IDPG_Promotion::is_active( $post_id ),
			'Событие с прошедшим сроком не должно считаться закреплённым.'
		);
	}

	/**
	 * Дата окончания включительна: закрепление до сегодня действует сегодня.
	 */
	public function test_expiry_today_is_still_active() {
		$post_id = $this->make_event( 'yes', IDPG_Promotion::today() );

		$this->assertFalse( IDPG_Promotion::is_expired( $post_id ) );
		$this->assertTrue( IDPG_Promotion::is_active( $post_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Cron
	 * ------------------------------------------------------------------ */

	/**
	 * Приёмка карточки: событие с прошедшей датой теряет закрепление.
	 */
	public function test_cron_unpins_expired() {
		$post_id = $this->make_event( 'yes', $this->yesterday() );

		$removed = IDPG_Promotion::instance()->unpin_expired();

		$this->assertSame( 1, $removed, 'Cron не снял закрепление с истёкшего события.' );
		$this->assertSame( '', get_post_meta( $post_id, IDPG_Promotion::META_PROMOTED, true ) );
		$this->assertFalse( IDPG_Promotion::is_active( $post_id ) );
	}

	/**
	 * Приёмка карточки: повторный запуск ничего не делает.
	 */
	public function test_cron_is_idempotent() {
		$this->make_event( 'yes', $this->yesterday() );

		$first = IDPG_Promotion::instance()->unpin_expired();
		$this->assertSame( 1, $first );

		$second = IDPG_Promotion::instance()->unpin_expired();
		$this->assertSame( 0, $second, 'Повторный запуск cron не идемпотентен.' );
		$third = IDPG_Promotion::instance()->unpin_expired();
		$this->assertSame( 0, $third );
	}

	/**
	 * Будущее и бессрочное закрепление cron не трогает.
	 */
	public function test_cron_keeps_valid_promotions() {
		$future    = $this->make_event( 'yes', $this->tomorrow() );
		$forever   = $this->make_event( 'yes', '' );
		$today     = $this->make_event( 'yes', IDPG_Promotion::today() );
		$unpinned  = $this->make_event( 'no', $this->yesterday() );

		$removed = IDPG_Promotion::instance()->unpin_expired();

		$this->assertSame( 0, $removed, 'Cron снял действующее закрепление.' );
		$this->assertTrue( IDPG_Promotion::is_active( $future ) );
		$this->assertTrue( IDPG_Promotion::is_active( $forever ) );
		$this->assertTrue( IDPG_Promotion::is_active( $today ) );
		$this->assertFalse( IDPG_Promotion::is_promoted( $unpinned ) );
	}

	/**
	 * Срок сохраняется после снятия: повторное закрепление без новой даты
	 * честно снимется на следующем же запуске, а не будет висеть вечно.
	 */
	public function test_expiry_date_survives_unpin() {
		$post_id = $this->make_event( 'yes', $this->yesterday() );

		IDPG_Promotion::instance()->unpin_expired();

		$this->assertSame(
			$this->yesterday(),
			IDPG_Promotion::get_pin_expires( $post_id ),
			'Дата окончания стёрта вместе с закреплением.'
		);
	}

	/**
	 * Событие снимается независимо от статуса: черновик и на модерации тоже.
	 */
	public function test_cron_covers_all_post_statuses() {
		$draft = self::factory()->post->create( array( 'post_type' => 'event', 'post_status' => 'draft' ) );
		update_post_meta( $draft, IDPG_Promotion::META_PROMOTED, 'yes' );
		update_post_meta( $draft, IDPG_Promotion::META_EXPIRES, $this->yesterday() );

		$pending = self::factory()->post->create( array( 'post_type' => 'event', 'post_status' => 'pending' ) );
		update_post_meta( $pending, IDPG_Promotion::META_PROMOTED, 'yes' );
		update_post_meta( $pending, IDPG_Promotion::META_EXPIRES, $this->yesterday() );

		$removed = IDPG_Promotion::instance()->unpin_expired();

		$this->assertSame( 2, $removed );
		$this->assertSame( '', get_post_meta( $draft, IDPG_Promotion::META_PROMOTED, true ) );
		$this->assertSame( '', get_post_meta( $pending, IDPG_Promotion::META_PROMOTED, true ) );
	}

	/**
	 * События других типов записей не затрагиваются.
	 */
	public function test_cron_ignores_other_post_types() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $page_id, IDPG_Promotion::META_PROMOTED, 'yes' );
		update_post_meta( $page_id, IDPG_Promotion::META_EXPIRES, $this->yesterday() );

		$removed = IDPG_Promotion::instance()->unpin_expired();

		$this->assertSame( 0, $removed );
		$this->assertSame( 'yes', get_post_meta( $page_id, IDPG_Promotion::META_PROMOTED, true ) );
	}

	/**
	 * Хук `idpg_event_unpinned` срабатывает один раз на событие.
	 */
	public function test_unpinned_action_fires() {
		$this->make_event( 'yes', $this->yesterday() );

		$fired = array();
		add_action(
			'idpg_event_unpinned',
			static function ( $post_id ) use ( &$fired ) {
				$fired[] = $post_id;
			}
		);

		IDPG_Promotion::instance()->unpin_expired();
		IDPG_Promotion::instance()->unpin_expired();

		$this->assertCount( 1, $fired, 'Хук должен сработать ровно один раз.' );
	}

	/**
	 * Хук не срабатывает, если снимать нечего.
	 */
	public function test_unpinned_action_not_fired_without_expiry() {
		$this->make_event( 'yes', '' );

		$fired = 0;
		add_action(
			'idpg_event_unpinned',
			static function () use ( &$fired ) {
				$fired++;
			}
		);

		IDPG_Promotion::instance()->unpin_expired();

		$this->assertSame( 0, $fired );
	}

	/* ---------------------------------------------------------------------
	 * Расписание
	 * ------------------------------------------------------------------ */

	/**
	 * Задача ставится в расписание ровно один раз.
	 */
	public function test_schedule_is_not_duplicated() {
		$promotion = IDPG_Promotion::instance();

		$promotion->unschedule_unpin();
		$promotion->schedule_unpin();
		$promotion->schedule_unpin();

		$timestamp = wp_next_scheduled( IDPG_Promotion::CRON_HOOK );
		$this->assertNotFalse( $timestamp, 'Задача не запланирована.' );

		// Считаем все вхождения события в расписании.
		$crons = _get_cron_array();
		$count = 0;
		foreach ( (array) $crons as $time => $hooks ) {
			if ( isset( $hooks[ IDPG_Promotion::CRON_HOOK ] ) ) {
				$count += count( $hooks[ IDPG_Promotion::CRON_HOOK ] );
			}
		}

		$this->assertSame( 1, $count, 'Задача задвоилась в расписании.' );

		$promotion->unschedule_unpin();
		$this->assertFalse( wp_next_scheduled( IDPG_Promotion::CRON_HOOK ) );
	}

	/**
	 * Исполнитель задачи подключён: запуск хука снимает закрепление.
	 */
	public function test_cron_hook_is_wired() {
		$post_id = $this->make_event( 'yes', $this->yesterday() );

		// Не вызываем метод напрямую — имитируем реальный запуск WordPress.
		do_action( IDPG_Promotion::CRON_HOOK );

		$this->assertSame( '', get_post_meta( $post_id, IDPG_Promotion::META_PROMOTED, true ) );
	}

	/* ---------------------------------------------------------------------
	 * Поле в админке
	 * ------------------------------------------------------------------ */

	/**
	 * Поле «Закрепить до» зарегистрировано и в карте мет, и в Carbon Fields.
	 *
	 * Carbon Fields в тестах не поднимает редактор, поэтому проверяем
	 * объявление поля по исходнику class-meta.php. Отсутствие поля означало бы,
	 * что срок некуда вводить: cron есть, а задать дату нечем.
	 */
	public function test_pin_expires_field_is_registered() {
		$source = file_get_contents( IDPG_CORE_DIR . 'includes/class-meta.php' );

		$this->assertStringContainsString(
			"'_idpg_pin_expires'",
			$source,
			'Метка срока закрепления не зарегистрирована в карте мет.'
		);
		$this->assertStringContainsString(
			"'date', 'idpg_pin_expires'",
			$source,
			'Поле «Закрепить до» не объявлено как дата в Carbon Fields.'
		);
		$this->assertStringContainsString(
			"set_storage_format( 'Y-m-d' )",
			$source,
			'Поле «Закрепить до» должно храниться в формате Y-m-d.'
		);
	}
}
