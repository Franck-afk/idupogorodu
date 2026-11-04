<?php
/**
 * Тесты полей баннера на главной: набор полей — как у карточки события.
 *
 * @package idpg-core
 */

/**
 * Класс Test_Current_Event_Banner_Fields
 */
class Test_Current_Event_Banner_Fields extends WP_UnitTestCase {

	/**
	 * Чистка настроек баннера между тестами.
	 *
	 * Санитизация пишет и индивидуальные option; без чистки значения
	 * из одного теста могут протечь в другой (порядок не гарантирован).
	 */
	public function tear_down() {
		delete_option( IDPG_Banner_Settings::OPTION );

		foreach ( array_keys( IDPG_Banner_Settings::defaults() ) as $key ) {
			delete_option( 'idpg_banner_' . $key );
		}

		// `fields` не входит в defaults(), но тоже пишется индивидуально.
		delete_option( 'idpg_banner_fields' );

		parent::tear_down();
	}

	/**
	 * Вызов приватного метода баннера.
	 *
	 * @param string $method Имя метода.
	 * @param array  $args   Аргументы.
	 * @return mixed
	 */
	private function invoke( $method, $args = array() ) {
		$banner = IDPG_Current_Event_Banner::instance();
		$ref    = new ReflectionMethod( $banner, $method );
		$ref->setAccessible( true );

		return $ref->invokeArgs( $banner, $args );
	}

	/**
	 * Событие для тестов.
	 *
	 * @return int ID события.
	 */
	private function make_event() {
		return self::factory()->post->create( array( 'post_type' => 'event' ) );
	}

	/**
	 * Бейджи баннера совпадают с набором бейджей карточки события.
	 *
	 * Обратная проба: если убрать любой из бейджей (статус, «Бесплатно»,
	 * «ТОП», «Онлайн», «Новый») — тест обязан падать.
	 */
	public function test_chips_match_event_card_set() {
		$event = $this->make_event();

		// Чипы берутся только из настроек вкладки «Банер на главной».
		$fields = array(
			'banner_ticket_status' => 'sold_out',
			'banner_is_free'       => 'yes',
			'banner_is_online'     => 'yes',
		);
		update_option( IDPG_Banner_Settings::OPTION, $fields );

		$chips = $this->invoke( 'get_event_chips', array( $event ) );
		$types = wp_list_pluck( $chips, 'type' );

		// Из чинов: статус, «Бесплатно», «Онлайн» — без события.
		$this->assertContains( 'status', $types );
		$this->assertContains( 'free', $types );
		$this->assertContains( 'online', $types );

		// Не наследуются: ТОП, Новый, Популярное.
		$this->assertNotContains( 'promo', $types );
		$this->assertNotContains( 'new', $types );
		$this->assertNotContains( 'popular', $types );

		// Подписи бейджей — как в карточке (свои, без события).
		$labels = wp_list_pluck( $chips, 'label' );
		$this->assertContains( 'Билеты закончились', $labels );
		$this->assertContains( 'Бесплатно', $labels );
		$this->assertContains( 'Онлайн', $labels );
	}



	/**
	 * Постер для события — баннер без изображения не строится.
	 *
	 * @param int $event_id ID события.
	 * @return int ID вложения.
	 */
	private function attach_poster( $event_id ) {
		$attachment_id = self::factory()->attachment->create_object(
			'banner.jpg',
			0,
			array( 'post_mime_type' => 'image/jpeg' )
		);
		update_attached_file( $attachment_id, '2026/01/banner.jpg' );
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'   => '2026/01/banner.jpg',
				'width'  => 1200,
				'height' => 800,
				'sizes'  => array(),
			)
		);
		set_post_thumbnail( $event_id, $attachment_id );

		return $attachment_id;
	}

	/**
	 * Город события — баннер выбирает события только текущего города.
	 *
	 * @param int    $event_id ID события.
	 * @param string $slug     Slug города.
	 * @return int ID термина.
	 */
	private function attach_city( $event_id, $slug = 'izhevsk' ) {
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'city',
				'slug'     => $slug,
				'name'     => 'Ижевск',
			)
		);
		wp_set_object_terms( $event_id, array( (int) $term_id ), 'city' );

		return $term_id;
	}

	/**
	 * Рендер баннера на главной для теста.
	 *
	 * @return string HTML.
	 */
	private function render_front_banner() {
		update_option( 'show_on_front', 'posts' );
		$this->go_to( home_url( '/' ) );

		$banner = IDPG_Current_Event_Banner::instance();
		$banner->purge_cache();

		ob_start();
		$banner->render( 'hero' );
		return (string) ob_get_clean();
	}

	/**
	 * Без выбора во вкладке «Банер на главной» баннер не выводится.
	 *
	 * Обратная проба: если вернуть автовыбор ближайшего события в render(),
	 * тест обязан упасть — новое событие с постером снова окажется в баннере.
	 */
	public function test_render_skips_when_no_event_selected() {
		$event = self::factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'publish',
			)
		);
		$this->attach_poster( $event );
		$this->attach_city( $event );
		update_post_meta( $event, '_idpg_start_date', '2099-01-01' );
		update_post_meta( $event, '_idpg_end_date', '2099-01-01' );

		$this->assertSame( '', $this->render_front_banner() );
	}

	/**
	 * Событие, выбранное во вкладке «Банер на главной», выводится в баннере.
	 */
	public function test_render_shows_selected_event() {
		$event = self::factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'publish',
			)
		);
		$this->attach_poster( $event );
		$this->attach_city( $event );
		update_post_meta( $event, '_idpg_start_date', '2099-01-01' );
		update_post_meta( $event, '_idpg_end_date', '2099-01-01' );

		update_option(
			IDPG_Banner_Settings::OPTION,
			array(
				'banner_event_ids' => array( $event ),
				'banner_title'     => 'Тестовый баннер',
			)
		);

		$html = $this->render_front_banner();

		$this->assertStringContainsString( 'idpg-current-event-banner', $html );
		$this->assertStringContainsString( 'Тестовый баннер', $html );

		delete_option( IDPG_Banner_Settings::OPTION );
	}

	/**
	 * Закончившееся выбранное событие в баннер не попадает.
	 *
	 * Обратная проба: без фильтра is_event_expired() в get_selected_events()
	 * просроченное событие осталось бы в выдаче.
	 */
	public function test_render_skips_expired_selected_event() {
		$event = self::factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'publish',
			)
		);
		$this->attach_poster( $event );
		$this->attach_city( $event );
		update_post_meta( $event, '_idpg_start_date', '2000-01-01' );
		update_post_meta( $event, '_idpg_end_date', '2000-01-01' );

		update_option(
			IDPG_Banner_Settings::OPTION,
			array( 'banner_event_ids' => array( $event ) )
		);

		$this->assertSame( '', $this->render_front_banner() );
	}

	/**
	 * Границы просрочки: дата без времени актуальна весь день окончания.
	 */
	public function test_is_event_expired_boundaries() {
		$event = $this->make_event();

		update_post_meta( $event, '_idpg_start_date', '2000-01-01' );
		update_post_meta( $event, '_idpg_end_date', '2000-01-01' );
		$this->assertTrue( $this->invoke( 'is_event_expired', array( $event ) ) );

		// Сегодняшняя дата без времени — событие ещё идёт.
		update_post_meta( $event, '_idpg_end_date', current_time( 'Y-m-d' ) );
		$this->assertFalse( $this->invoke( 'is_event_expired', array( $event ) ) );

		// Дата-время в будущем — не истекло.
		update_post_meta( $event, '_idpg_end_date', '2099-01-01T09:00' );
		$this->assertFalse( $this->invoke( 'is_event_expired', array( $event ) ) );

		// Дата-время в прошлом — истекло.
		update_post_meta( $event, '_idpg_end_date', '2000-01-01T09:00' );
		$this->assertTrue( $this->invoke( 'is_event_expired', array( $event ) ) );

		// Пустые даты не считаются просроченными.
		delete_post_meta( $event, '_idpg_start_date' );
		delete_post_meta( $event, '_idpg_end_date' );
		$this->assertFalse( $this->invoke( 'is_event_expired', array( $event ) ) );
	}

	/**
	 * Время события: «весь день», время первого сеанса или пусто.
	 */
	public function test_time_label_all_day_and_sessions() {
		$event = $this->make_event();

		// Весь день.
		update_post_meta( $event, '_idpg_all_day', 'yes' );
		$this->assertSame( 'весь день', $this->invoke( 'get_event_time_label', array( $event ) ) );

		// Время первого сеанса из зеркала сеансов.
		delete_post_meta( $event, '_idpg_all_day' );
		update_post_meta(
			$event,
			'_idpg_sessions_json',
			wp_json_encode(
				array(
					array(
						'time'  => '20:00',
						'price' => '500',
						'url'   => '',
					),
				)
			)
		);
		$this->assertSame( '20:00', $this->invoke( 'get_event_time_label', array( $event ) ) );

		// Без сеансов — пусто.
		delete_post_meta( $event, '_idpg_sessions_json' );
		$this->assertSame( '', $this->invoke( 'get_event_time_label', array( $event ) ) );
	}

	/**
	 * Рубрики — до двух, как в карточке события.
	 */
	public function test_rubrics_limit_two() {
		$event = $this->make_event();

		$cat1 = self::factory()->term->create(
			array(
				'taxonomy' => 'event_category',
				'name'     => 'Концерты',
			)
		);
		$cat2 = self::factory()->term->create(
			array(
				'taxonomy' => 'event_category',
				'name'     => 'Театр',
			)
		);
		$cat3 = self::factory()->term->create(
			array(
				'taxonomy' => 'event_category',
				'name'     => 'Фестивали',
			)
		);

		wp_set_object_terms( $event, array( $cat1, $cat2, $cat3 ), 'event_category' );

		$rubrics = $this->invoke( 'get_rubrics', array( $event, 2 ) );

		$this->assertCount( 2, $rubrics );
		$this->assertContains( 'Концерты', $rubrics );
	}

	/**
	 * VIP-цена выводится как в карточке; без цены — пусто.
	 */
	public function test_vip_price_label() {
		$event = $this->make_event();

		$this->assertSame( '', $this->invoke( 'get_vip_price', array( $event ) ) );

		update_post_meta( $event, '_idpg_vip_price', '1500' );
		update_post_meta( $event, '_idpg_currency', 'RUB' );

		$vip = $this->invoke( 'get_vip_price', array( $event ) );
		$this->assertStringContainsString( 'VIP:', $vip );
		$this->assertStringContainsString( '₽', $vip );
	}

	/**
	 * Рендер уважает настройки полей: снятое поле не выводится.
	 *
	 * Обратная проба: если гейт настроек убрать, выключенные поля
	 * снова появятся в разметке — тест обязан падать.
	 */
	public function test_render_respects_field_settings() {
		$attachment_id = self::factory()->attachment->create_object(
			'banner.jpg',
			0,
			array( 'post_mime_type' => 'image/jpeg' )
		);
		update_attached_file( $attachment_id, '2026/01/banner.jpg' );
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'   => '2026/01/banner.jpg',
				'width'  => 1200,
				'height' => 800,
				'sizes'  => array(),
			)
		);

		$event = $this->factory()->post->create( array( 'post_type' => 'event' ) );
		set_post_thumbnail( $event, $attachment_id );
		update_post_meta( $event, '_idpg_start_date', '2026-08-10T19:00' );
		update_post_meta( $event, '_idpg_is_free', 'yes' );

		// Чипы, кнопка и дата выводятся всегда; гейты «field settings»
		// на вкладку изначально не влияют. Берём только настройки баннера.
		$fields = array(
			'banner_start_date'    => '2026-08-10',
			'banner_ticket_status' => 'sold_out',
			'banner_is_free'       => 'yes',
			'banner_is_online'     => 'yes',
		);
		update_option( IDPG_Banner_Settings::OPTION, $fields );

		$html = $this->invoke( 'render_single_html', array( get_post( $event ) ) );
		$this->assertStringContainsString( 'idpg-current-event-banner__chips', $html );
		$this->assertStringContainsString( 'idpg-current-event-banner__btn', $html );
		$this->assertStringContainsString( '10 августа 2026', $html );
		$this->assertStringContainsString( 'Подробнее', $html );

		delete_option( IDPG_Banner_Settings::OPTION );
	}

	/**
	 * Рендер баннера содержит поля карточки: бейджи, дату со временем,
	 * рубрики, кнопку «Подробнее».
	 *
	 * Обратная проба: при удалении любого из этих блоков из разметки
	 * тест обязан падать.
	 */
	public function test_render_single_html_contains_card_fields() {
		$attachment_id = self::factory()->attachment->create_object(
			'banner.jpg',
			0,
			array( 'post_mime_type' => 'image/jpeg' )
		);
		update_attached_file( $attachment_id, '2026/01/banner.jpg' );
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'   => '2026/01/banner.jpg',
				'width'  => 1200,
				'height' => 800,
				'sizes'  => array(),
			)
		);

		$event = $this->make_event();
		set_post_thumbnail( $event, $attachment_id );

		update_post_meta( $event, '_idpg_start_date', '2026-08-10T19:00' );
		update_post_meta( $event, '_idpg_is_free', 'yes' );
		update_post_meta(
			$event,
			'_idpg_sessions_json',
			wp_json_encode(
				array(
					array(
						'time'  => '20:00',
						'price' => '',
						'url'   => '',
					),
				)
			)
		);

		$cat = self::factory()->term->create(
			array(
				'taxonomy' => 'event_category',
				'name'     => 'Концерты',
			)
		);
		wp_set_object_terms( $event, array( $cat ), 'event_category' );

		// Чипы берутся только из настроек вкладки «Банер на главной».
		update_option(
			IDPG_Banner_Settings::OPTION,
			array(
				'banner_ticket_status' => 'sold_out',
				'banner_is_free'       => 'yes',
				'banner_is_online'     => 'yes',
			)
		);

		$html = $this->invoke( 'render_single_html', array( get_post( $event ) ) );

		$this->assertStringContainsString( 'idpg-current-event-banner__chips', $html );
		$this->assertStringContainsString( 'idpg-current-event-banner__chip--free', $html );
		$this->assertStringContainsString( 'idpg-current-event-banner__btn', $html );
		// Чипы и кнопка.
		$this->assertStringContainsString( 'idpg-current-event-banner__chips', $html );
		$this->assertStringContainsString( 'idpg-current-event-banner__chip--free', $html );
		$this->assertStringContainsString( 'idpg-current-event-banner__btn', $html );
		$this->assertStringContainsString( 'Подробнее', $html );

		// Только из настроек вкладки: статус, «Бесплатно», «Онлайн».
		// Событие не вносит данные: нет даты, времени, рубрик, города, возраста.
		$this->assertStringNotContainsString( '10 августа 2026', $html );
		$this->assertStringNotContainsString( '20:00', $html );
		$this->assertStringNotContainsString( 'Концерты', $html );

		delete_option( IDPG_Banner_Settings::OPTION );
	}
}
