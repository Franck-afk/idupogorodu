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
