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

		update_post_meta( $event, '_idpg_ticket_status', 'sold_out' );
		update_post_meta( $event, '_idpg_is_free', 'yes' );
		update_post_meta( $event, '_idpg_is_online', 'yes' );
		update_post_meta( $event, '_idpg_promoted', 'yes' );

		$chips = $this->invoke( 'get_event_chips', array( $event ) );
		$types = wp_list_pluck( $chips, 'type' );

		$this->assertContains( 'status', $types );
		$this->assertContains( 'free', $types );
		$this->assertContains( 'promo', $types );
		$this->assertContains( 'online', $types );
		$this->assertContains( 'new', $types );

		// Порог «популярного» задаёт тема для секций главной — без него чипа нет.
		$this->assertNotContains( 'popular', $types );

		// Подписи бейджей — как в карточке.
		$labels = wp_list_pluck( $chips, 'label' );
		$this->assertContains( 'Билеты закончились', $labels );
		$this->assertContains( 'Бесплатно', $labels );
		$this->assertContains( 'ТОП', $labels );
		$this->assertContains( 'Онлайн', $labels );
		$this->assertContains( 'Новый', $labels );
	}

	/**
	 * Бейдж «Популярное» появляется при пороге темы ($idpg_popular_min_views).
	 */
	public function test_popular_chip_uses_theme_threshold() {
		$event = $this->make_event();
		update_post_meta( $event, '_idpg_views', 100 );

		$GLOBALS['idpg_popular_min_views'] = 50;

		$types = wp_list_pluck( $this->invoke( 'get_event_chips', array( $event ) ), 'type' );
		$this->assertContains( 'popular', $types );

		unset( $GLOBALS['idpg_popular_min_views'] );
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

		// По умолчанию все поля включены.
		$html = $this->invoke( 'render_single_html', array( get_post( $event ) ) );
		$this->assertStringContainsString( 'idpg-current-event-banner__chips', $html );
		$this->assertStringContainsString( 'idpg-current-event-banner__btn', $html );
		$this->assertStringContainsString( '10 августа 2026', $html );

		// Выключаем бейджи, кнопку и дату.
		$fields = array();
		foreach ( IDPG_Banner_Settings::FIELDS as $field ) {
			$fields[ $field ] = 'yes';
		}
		$fields['chips']  = 'no';
		$fields['button'] = 'no';
		$fields['date']   = 'no';
		update_option( IDPG_Banner_Settings::OPTION, array( 'fields' => $fields ) );

		$html = $this->invoke( 'render_single_html', array( get_post( $event ) ) );
		$this->assertStringNotContainsString( 'idpg-current-event-banner__chips', $html );
		$this->assertStringNotContainsString( 'idpg-current-event-banner__btn', $html );
		$this->assertStringNotContainsString( '10 августа 2026', $html );
		$this->assertStringNotContainsString( 'Подробнее', $html );

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
		$this->assertStringContainsString( 'Подробнее', $html );
		$this->assertStringContainsString( '10 августа 2026', $html );
		$this->assertStringContainsString( '20:00', $html );
		$this->assertStringContainsString( 'Концерты', $html );
	}
}
