<?php
/**
 * Тесты вкладки «Баннер на главной» (настройки баннера главной страницы).
 *
 * @package idpg-core
 */

/**
 * Класс Test_Banner_Settings_Tab
 */
class Test_Banner_Settings_Tab extends WP_UnitTestCase {

	/**
	 * Очистка option и флага «главной» между тестами.
	 */
	public function tear_down() {
		delete_option( IDPG_Banner_Settings::OPTION );

		// Санитизация пишет и индивидуальные option, которые читаются,
		// когда единого option нет. Чистим их, иначе значения из одного
		// теста протекают в другой (порядок тестов не гарантирован).
		foreach ( array_keys( IDPG_Banner_Settings::defaults() ) as $key ) {
			delete_option( 'idpg_banner_' . $key );
		}

		// `fields` не входит в defaults(), но тоже пишется индивидуально.
		delete_option( 'idpg_banner_fields' );

		unset( $_GET['hide_event'] );
		if ( isset( $GLOBALS['wp_query'] ) ) {
			$GLOBALS['wp_query']->is_home = false;
		}
		parent::tear_down();
	}

	/**
	 * Дефолтные настройки возвращают прежние значения из констант баннера.
	 */
	public function test_defaults_match_previous_hardcoded_values() {
		$defaults = IDPG_Banner_Settings::defaults();

		// До появления вкладки интервал, лимит и TTL были зашиты в коде.
		$this->assertSame( 6000, $defaults['carousel_interval'] );
		$this->assertSame( 20, $defaults['paid_limit'] );
		$this->assertSame( 10, $defaults['cache_ttl'] );

		// Дефолты новых настроек блока сохраняют прежнее поведение:
		// баннер показывается в hero, карусель крутится автоматически.
		$this->assertSame( 'yes', $defaults['enabled'] );
		$this->assertSame( 'yes', $defaults['auto_rotate'] );

		// Выбор событий баннера по умолчанию пуст — баннер не выводится.
		$this->assertSame( array(), $defaults['banner_event_ids'] );

		// Настройка позиции удалена по решению владельца — ключа нет.
		$this->assertArrayNotHasKey( 'position', $defaults );
	}

	/**
	 * Без сохранённого option геттеры возвращают дефолты.
	 */
	public function test_getters_fall_back_to_defaults_without_option() {
		delete_option( IDPG_Banner_Settings::OPTION );

		$this->assertSame( 6000, IDPG_Banner_Settings::get_carousel_interval() );
		$this->assertSame( 20, IDPG_Banner_Settings::get_paid_limit() );
		$this->assertSame( 10 * MINUTE_IN_SECONDS, IDPG_Banner_Settings::get_cache_ttl() );

		// Без option блок включён, авто-ротация включена — как и до
		// появления вкладки; геттера позиции больше нет.
		$this->assertTrue( IDPG_Banner_Settings::is_enabled() );
		$this->assertTrue( IDPG_Banner_Settings::is_auto_rotate() );
		$this->assertFalse( method_exists( 'IDPG_Banner_Settings', 'get_position' ) );
	}

	/**
	 * Настройка «Позиция на главной» удалена целиком.
	 *
	 * Обратная проба: при возврате любого из элементов настройки
	 * (ключ defaults, геттер, поле формы, ключ sanitize) — тест падает.
	 */
	public function test_position_setting_removed() {
		// Нет ключа в defaults и геттера.
		$this->assertArrayNotHasKey( 'position', IDPG_Banner_Settings::defaults() );
		$this->assertFalse( method_exists( 'IDPG_Banner_Settings', 'get_position' ) );

		// Санитизация не принимает присланную позицию.
		$clean = IDPG_Banner_Settings::sanitize_settings( array( 'idpg_banner_position' => 'middle' ) );
		$this->assertArrayNotHasKey( 'position', $clean );

		// Поле не рендерится в форме вкладки.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->assertStringNotContainsString( 'idpg_banner_position', $this->render_tab() );
	}

	/**
	 * Геттеры блока читают сохранённый option.
	 */
	public function test_display_getters_read_option() {
		update_option(
			IDPG_Banner_Settings::OPTION,
			array(
				'enabled'     => 'no',
				'auto_rotate' => 'no',
			)
		);

		$this->assertFalse( IDPG_Banner_Settings::is_enabled() );
		$this->assertFalse( IDPG_Banner_Settings::is_auto_rotate() );
	}

	/**
	 * Гейты рендера блока: включён/выключен, фиксированный hero,
	 * главная страница.
	 *
	 * Обратные пробы: (1) убран гейт enabled — «выключенный» блок всё
	 * равно рендерится в hero; (2) убран hero-гейт — блок рендерится
	 * в middle/bottom (настройки позиции больше нет). В обоих случаях
	 * тест обязан падать.
	 */
	public function test_render_gates_enabled_and_position() {
		$banner = IDPG_Current_Event_Banner::instance();
		$method = new ReflectionMethod( $banner, 'should_render' );
		$method->setAccessible( true );

		// Делаем запрос «главной страницей» (show_on_front=posts → is_home).
		$GLOBALS['wp_query']->is_home = true;

		// Дефолт: включён — рендер в hero, не в middle/bottom.
		$this->assertTrue( $method->invoke( $banner, 'hero' ) );
		$this->assertFalse( $method->invoke( $banner, 'middle' ) );
		$this->assertFalse( $method->invoke( $banner, 'bottom' ) );

		// Выключен — не рендерится ни в одной позиции.
		update_option( IDPG_Banner_Settings::OPTION, array( 'enabled' => 'no' ) );
		$this->assertFalse( $method->invoke( $banner, 'hero' ) );
		$this->assertFalse( $method->invoke( $banner, 'bottom' ) );

		// Не главная страница — не рендерится.
		delete_option( IDPG_Banner_Settings::OPTION );
		$GLOBALS['wp_query']->is_home = false;
		$this->assertFalse( $method->invoke( $banner, 'hero' ) );

		$GLOBALS['wp_query']->is_home = true;
	}

	/**
	 * Data-атрибуты карусели отражают настройки: интервал и авто-ротация.
	 *
	 * Обратная проба: без data-autorotate JS не может остановить
	 * ротацию — тест обязан падать при удалении атрибута.
	 */
	public function test_carousel_data_attrs_reflect_settings() {
		$banner = IDPG_Current_Event_Banner::instance();
		$method = new ReflectionMethod( $banner, 'get_carousel_data_attrs' );
		$method->setAccessible( true );

		$attrs = $method->invoke( $banner );
		$this->assertStringContainsString( 'data-interval="6000"', $attrs );
		$this->assertStringContainsString( 'data-autorotate="1"', $attrs );

		update_option(
			IDPG_Banner_Settings::OPTION,
			array(
				'auto_rotate'       => 'no',
				'carousel_interval' => 4500,
			)
		);

		$attrs = $method->invoke( $banner );
		$this->assertStringContainsString( 'data-interval="4500"', $attrs );
		$this->assertStringContainsString( 'data-autorotate="0"', $attrs );
	}

	/**
	 * Санитизация формы: чекбоксы по умолчанию выключены, числа зажаты.
	 */
	public function test_sanitize_settings_clamps_and_defaults() {
		// Мусор: чекбоксы не присланы, числа вне диапазона.
		$clean = IDPG_Banner_Settings::sanitize_settings(
			array(
				'idpg_banner_interval'   => 50,
				'idpg_banner_paid_limit' => 999,
			)
		);

		$this->assertSame( 'no', $clean['enabled'] );
		$this->assertSame( 'no', $clean['auto_rotate'] );
		$this->assertSame( 2000, $clean['carousel_interval'] );
		$this->assertSame( 50, $clean['paid_limit'] );
		$this->assertSame( 10, $clean['cache_ttl'] );

		// Валидные значения проходят без изменений.
		$clean = IDPG_Banner_Settings::sanitize_settings(
			array(
				'idpg_banner_enabled'    => 'yes',
				'idpg_banner_autorotate' => 'yes',
				'idpg_banner_interval'   => 4500,
				'idpg_banner_paid_limit' => 5,
				'idpg_banner_cache_ttl'  => 3,
			)
		);

		$this->assertSame( 'yes', $clean['enabled'] );
		$this->assertSame( 'yes', $clean['auto_rotate'] );
		$this->assertSame( 4500, $clean['carousel_interval'] );
		$this->assertSame( 5, $clean['paid_limit'] );
		$this->assertSame( 3, $clean['cache_ttl'] );
	}

	/**
	 * Поле «Скрыть баннер полностью» перенесено из вкладки «События».
	 *
	 * Обратная проба: при возврате поля в get_field_groups() тест
	 * обязан падать. Соседние баннерные поля вкладки «События»
	 * не тронуты — их наличие контролируется здесь же.
	 */
	public function test_hide_full_field_moved_out_of_events_tab() {
		$ids = array();

		foreach ( IDPG_Event_Fields::get_field_groups() as $group ) {
			foreach ( $group['fields'] as $field ) {
				$ids[] = $field['id'];
			}
		}

		$this->assertNotContains( 'idpg_hide_full_banner', $ids );
		$this->assertContains( 'idpg_hide_from_banner', $ids );
		$this->assertContains( 'idpg_banner_approved', $ids );
	}

	/**
	 * Настройки полей баннера: по умолчанию включены, неизвестные — нет.
	 *
	 * Обратная проба: если геттер всегда возвращает true, тест падает
	 * на неизвестном поле.
	 */
	public function test_field_settings_default_enabled() {
		delete_option( IDPG_Banner_Settings::OPTION );

		foreach ( IDPG_Banner_Settings::FIELDS as $field ) {
			$this->assertTrue(
				IDPG_Banner_Settings::is_field_enabled( $field ),
				'Поле должно быть включено по умолчанию: ' . $field
			);
		}

		$this->assertFalse( IDPG_Banner_Settings::is_field_enabled( 'unknown_field' ) );
	}

	/**
	 * Санитизация полей: отмеченные — включены, снятые — выключены,
	 * неизвестные ключи отбрасываются.
	 */
	public function test_sanitize_settings_reads_field_checkboxes() {
		$clean = IDPG_Banner_Settings::sanitize_settings(
			array(
				'idpg_banner_field_chips'   => 'yes',
				'idpg_banner_field_date'    => 'yes',
				'idpg_banner_field_unknown' => 'yes',
			)
		);

		$this->assertSame( 'yes', $clean['fields']['chips'] );
		$this->assertSame( 'yes', $clean['fields']['date'] );
		$this->assertSame( 'no', $clean['fields']['button'] );
		$this->assertArrayNotHasKey( 'unknown', $clean['fields'] );
		$this->assertCount( count( IDPG_Banner_Settings::FIELDS ), $clean['fields'] );
	}

	/**
	 * Без чекбоксов полей в форме сохраняется прежний набор полей.
	 *
	 * Обратная проба: старая логика «нет ключа → no» сбрасывала все поля
	 * при каждом сохранении вкладки — тест обязан падать на 'no' у chips.
	 */
	public function test_sanitize_settings_preserves_fields_without_checkboxes() {
		update_option(
			IDPG_Banner_Settings::OPTION,
			array(
				'fields' => array(
					'chips' => 'no',
					'date'  => 'yes',
				),
			)
		);

		$clean = IDPG_Banner_Settings::sanitize_settings( array( 'idpg_banner_enabled' => 'yes' ) );

		$this->assertSame( 'no', $clean['fields']['chips'] );
		$this->assertSame( 'yes', $clean['fields']['date'] );
		// Поля, которых не было в сохранённом наборе, остаются включёнными.
		$this->assertSame( 'yes', $clean['fields']['button'] );
	}

	/**
	 * Без сохранённого набора и без чекбоксов все поля включены (дефолт).
	 */
	public function test_sanitize_settings_defaults_fields_enabled() {
		$clean = IDPG_Banner_Settings::sanitize_settings( array( 'idpg_banner_enabled' => 'yes' ) );

		foreach ( IDPG_Banner_Settings::FIELDS as $field ) {
			$this->assertSame( 'yes', $clean['fields'][ $field ], 'Поле должно остаться включённым: ' . $field );
		}
	}

	/**
	 * Поле, снятое в настройках, выключается — геттер это учитывает.
	 */
	public function test_field_settings_saved_toggle() {
		update_option(
			IDPG_Banner_Settings::OPTION,
			array(
				'fields' => array(
					'chips'  => 'no',
					'button' => 'yes',
				),
			)
		);

		$this->assertFalse( IDPG_Banner_Settings::is_field_enabled( 'chips' ) );
		$this->assertTrue( IDPG_Banner_Settings::is_field_enabled( 'button' ) );
		// Поля, которых нет в option, остаются включёнными (дефолт).
		$this->assertTrue( IDPG_Banner_Settings::is_field_enabled( 'date' ) );
	}

	/**
	 * Чекбоксы полей баннера удалены из вкладки — вместо них поля
	 * ввода данных («Данные баннера»).
	 *
	 * Обратная проба: при возврате секции с чекбоксами тест падает.
	 */
	public function test_admin_tab_has_no_field_checkboxes() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$html = $this->render_tab();

		foreach ( IDPG_Banner_Settings::FIELDS as $field ) {
			$this->assertStringNotContainsString(
				'idpg_banner_field_' . $field,
				$html,
				'Вернулся чекбокс поля: ' . $field
			);
		}
	}

	/**
	 * Секция «Скрыть баннер полностью» на вкладке баннера: чекбокс —
	 * только для выбранного события (hide_event в URL) и по мета;
	 * выпадающий селектор события удалён по решению владельца.
	 *
	 * Обратные пробы: (1) без выбранного события чекбокса нет;
	 * (2) при установленной мете чекбокс отмечен; (3) при снятой мете —
	 * не отмечен; (4) при возврате селектора в разметку — тест падает.
	 */
	public function test_hide_full_checkbox_renders_for_selected_event() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$event = self::factory()->post->create( array( 'post_type' => 'event' ) );

		// Без выбранного события: чекбокса нет, селектора нет.
		unset( $_GET['hide_event'] );
		$html = $this->render_tab();
		$this->assertStringNotContainsString( 'idpg_hide_full_banner', $html );
		$this->assertStringNotContainsString( 'idpg_hide_event_select', $html );
		$this->assertStringNotContainsString( 'name="hide_event"', $html );

		// Выбранное событие без меты: чекбокс есть, но не отмечен
		// (checked() добавляет ведущий пробел — в разметке их два).
		$_GET['hide_event'] = $event;
		$html               = $this->render_tab();
		$this->assertStringContainsString( 'name="idpg_hide_event_id"', $html );
		$this->assertStringContainsString( 'idpg_hide_full_banner', $html );
		$this->assertStringNotContainsString(
			'name="idpg_hide_full_banner" value="yes"  checked=',
			$html
		);

		// Мета установлена: чекбокс отмечен (checked() печатает в
		// одинарных кавычках: checked='checked').
		update_post_meta( $event, IDPG_Current_Event_Banner::META_HIDE_FULL, 'yes' );
		$html = $this->render_tab();
		$this->assertStringContainsString(
			'name="idpg_hide_full_banner" value="yes"  checked=',
			$html
		);

		delete_post_meta( $event, IDPG_Current_Event_Banner::META_HIDE_FULL );
		unset( $_GET['hide_event'] );
	}

	/**
	 * Запись/снятие флага «Скрыть баннер полностью» — только для событий.
	 *
	 * Обратные пробы: (1) без проверки типа поста мета пишется на
	 * обычную запись — тест обязан падать; (2) без ветки снятия флаг
	 * остаётся 'yes' при снятом чекбоксе — тест обязан падать.
	 */
	public function test_save_hide_full_flag_writes_meta() {
		$event = self::factory()->post->create( array( 'post_type' => 'event' ) );

		// Чекбокс отмечен → флаг yes.
		$id = IDPG_Banner_Settings::save_hide_full_flag(
			array(
				'idpg_hide_event_id'    => $event,
				'idpg_hide_full_banner' => 'yes',
			)
		);
		$this->assertSame( $event, $id );
		$this->assertSame( 'yes', get_post_meta( $event, IDPG_Current_Event_Banner::META_HIDE_FULL, true ) );

		// Чекбокс снят → флаг снят (пустое значение, как во вкладке «События»).
		$id = IDPG_Banner_Settings::save_hide_full_flag(
			array( 'idpg_hide_event_id' => $event )
		);
		$this->assertSame( $event, $id );
		$this->assertSame( '', get_post_meta( $event, IDPG_Current_Event_Banner::META_HIDE_FULL, true ) );

		// Не событие → ничего не пишется, возвращается 0.
		$post = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$this->assertSame(
			0,
			IDPG_Banner_Settings::save_hide_full_flag(
				array(
					'idpg_hide_event_id'    => $post,
					'idpg_hide_full_banner' => 'yes',
				)
			)
		);
		$this->assertSame( '', get_post_meta( $post, IDPG_Current_Event_Banner::META_HIDE_FULL, true ) );

		// Нет события в данных → 0, без исключений.
		$this->assertSame( 0, IDPG_Banner_Settings::save_hide_full_flag( array() ) );
		$this->assertSame( 0, IDPG_Banner_Settings::save_hide_full_flag( 'not-an-array' ) );
	}

	/**
	 * Геттеры читают сохранённый option и применяют ограничения.
	 */
	public function test_getters_read_option_and_clamp_values() {
		update_option(
			IDPG_Banner_Settings::OPTION,
			array(
				'carousel_interval' => 4500,
				'paid_limit'        => 5,
				'cache_ttl'         => 3,
			)
		);

		$this->assertSame( 4500, IDPG_Banner_Settings::get_carousel_interval() );
		$this->assertSame( 5, IDPG_Banner_Settings::get_paid_limit() );
		$this->assertSame( 3 * MINUTE_IN_SECONDS, IDPG_Banner_Settings::get_cache_ttl() );
	}

	/**
	 * Вкладка «Баннер на главной» регистрируется фильтром.
	 */
	public function test_banner_tab_registered() {
		$tabs = apply_filters( 'idpg_widget_areas_admin_tabs', array() );

		$this->assertArrayHasKey( IDPG_Banner_Settings::TAB_SLUG, $tabs );
		$this->assertIsCallable( $tabs[ IDPG_Banner_Settings::TAB_SLUG ]['callback'] );
	}

	/**
	 * Обратная проба: вкладка НЕ регистрируется, если колбэк не добавлен.
	 *
	 * Если бы проверка выше только подтверждала текущее поведение,
	 * сломанный колбэк не был бы пойман: здесь вкладка исчезает
	 * при удалении колбэка — критерий действительно ловит дефект.
	 */
	public function test_banner_tab_not_registered_without_callback() {
		remove_filter( 'idpg_widget_areas_admin_tabs', array( IDPG_Banner_Settings::instance(), 'register_admin_tab' ) );

		$tabs = apply_filters( 'idpg_widget_areas_admin_tabs', array() );

		$this->assertArrayNotHasKey( IDPG_Banner_Settings::TAB_SLUG, $tabs );

		add_filter( 'idpg_widget_areas_admin_tabs', array( IDPG_Banner_Settings::instance(), 'register_admin_tab' ) );
	}

	/**
	 * Без manage_options рендер вкладки не выводит форму настроек.
	 */
	public function test_banner_tab_requires_manage_options() {
		wp_set_current_user( 0 );

		$html = $this->render_tab();

		$this->assertStringNotContainsString( 'idpg_banner_settings_nonce', $html );
	}

	/**
	 * С администратором рендерятся поля формы с nonce.
	 */
	public function test_banner_tab_renders_fields_for_admin() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$html = $this->render_tab();

		$this->assertStringContainsString( 'idpg_banner_settings_nonce', $html );
		$this->assertStringContainsString( 'idpg_banner_enabled', $html );
		$this->assertStringContainsString( 'idpg_banner_autorotate', $html );
		$this->assertStringContainsString( 'idpg_banner_interval', $html );
		$this->assertStringContainsString( 'idpg_banner_paid_limit', $html );
		$this->assertStringContainsString( 'idpg_banner_cache_ttl', $html );
	}

	/**
	 * Тосты админки подключаются на любой странице, без флага и вкладки.
	 *
	 * Обратная проба: если вернуть условную загрузку только для плашки
	 * баннера (как было до DEV-тостов), тест упадёт здесь.
	 */
	public function test_admin_toasts_enqueued_on_every_admin_page() {
		wp_dequeue_script( 'idpg-toastify' );
		wp_dequeue_script( IDPG_Admin_Toasts::HANDLE );
		wp_dequeue_style( 'idpg-toastify' );
		wp_dequeue_style( IDPG_Admin_Toasts::HANDLE );

		IDPG_Admin_Toasts::instance()->enqueue_assets();

		$this->assertTrue( wp_script_is( 'idpg-toastify', 'enqueued' ) );
		$this->assertTrue( wp_script_is( IDPG_Admin_Toasts::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'idpg-toastify', 'enqueued' ) );
		$this->assertTrue( wp_style_is( IDPG_Admin_Toasts::HANDLE, 'enqueued' ) );

		wp_dequeue_script( 'idpg-toastify' );
		wp_dequeue_script( IDPG_Admin_Toasts::HANDLE );
		wp_dequeue_style( 'idpg-toastify' );
		wp_dequeue_style( IDPG_Admin_Toasts::HANDLE );
	}

	/**
	 * Обратная проба: сохранение без nonce отклоняется (wp_die).
	 */
	public function test_save_requires_nonce() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$_POST['idpg_banner_action'] = 'save';

		$this->expectException( 'WPDieException' );

		IDPG_Banner_Settings::instance()->handle_save();
	}

	/**
	 * Без прав manage_options сохранение недоступно.
	 */
	public function test_save_requires_manage_options() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_POST['idpg_banner_action']               = 'save';
		$_POST[ IDPG_Banner_Settings::NONCE_NAME ] = wp_create_nonce( IDPG_Banner_Settings::NONCE_ACTION );

		$this->expectException( 'WPDieException' );

		IDPG_Banner_Settings::instance()->handle_save();
	}

	/**
	 * Без idpg_banner_action обработчик выходит молча (не трогает другие формы).
	 */
	public function test_save_ignores_unrelated_requests() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		unset( $_POST['idpg_banner_action'] );

		// Не должно бросить исключение и не должно записать option.
		IDPG_Banner_Settings::instance()->handle_save();

		$this->assertFalse( (bool) get_option( IDPG_Banner_Settings::OPTION ) );
	}

	/**
	 * Плашка «Настройки баннера сохранены.» — одноразовая (user meta)
	 * и показывается только на активной вкладке «Баннер на главной».
	 *
	 * Обратные пробы: (1) на чужой вкладке плашка не показывается и
	 * meta не «потребляется»; (2) повторный рендер на своей вкладке
	 * плашки не даёт — meta удалена после первого показа; (3) старый
	 * URL с idpg_msg=saved без meta не показывает ничего.
	 */
	public function test_success_notice_is_one_shot_and_gated_by_tab() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		update_user_meta( $admin, IDPG_Banner_Settings::USER_SAVED_META, 1 );

		// На чужой вкладке плашки нет, meta сохранена.
		$_GET['tab'] = 'events';
		$this->assertStringNotContainsString(
			'Настройки баннера сохранены.',
			$this->render_tab()
		);
		$this->assertSame(
			1,
			(int) get_user_meta( $admin, IDPG_Banner_Settings::USER_SAVED_META, true ),
			'Мета не должна потребляться на чужой вкладке.'
		);

		// Без tab плашки нет, meta сохранена.
		unset( $_GET['tab'] );
		$this->assertStringNotContainsString(
			'Настройки баннера сохранены.',
			$this->render_tab()
		);
		$this->assertSame(
			1,
			(int) get_user_meta( $admin, IDPG_Banner_Settings::USER_SAVED_META, true )
		);

		// На своей вкладке плашка показывается и meta потребляется.
		$_GET['tab'] = IDPG_Banner_Settings::TAB_SLUG;
		$html        = $this->render_tab();
		$this->assertStringContainsString( 'Настройки баннера сохранены.', $html );
		// Класс-маркер: JS забирает плашку и показывает её тостом.
		$this->assertStringContainsString( 'idpg-banner-saved-notice', $html );
		$this->assertFalse(
			(bool) get_user_meta( $admin, IDPG_Banner_Settings::USER_SAVED_META, true ),
			'Мета должна удалиться после показа (одноразовость).'
		);

		// Повторный рендер (F5/возврат) — плашки нет.
		$this->assertStringNotContainsString(
			'Настройки баннера сохранены.',
			$this->render_tab()
		);

		// Старый URL с idpg_msg=saved без meta ничего не показывает.
		update_user_meta( $admin, IDPG_Banner_Settings::USER_SAVED_META, 0 );
		delete_user_meta( $admin, IDPG_Banner_Settings::USER_SAVED_META );
		$_GET['idpg_msg'] = 'saved';
		$this->assertStringNotContainsString(
			'Настройки баннера сохранены.',
			$this->render_tab()
		);

		unset( $_GET['tab'], $_GET['idpg_msg'] );
		delete_user_meta( $admin, IDPG_Banner_Settings::USER_SAVED_META );
	}

	/**
	 * Параметры вывода: дефолты новых полей ввода.
	 */
	public function test_output_params_defaults() {
		$defaults = IDPG_Banner_Settings::defaults();

		$this->assertSame( 'Подробнее', $defaults['button_text'] );
		$this->assertSame( 0, $defaults['excerpt_length'] );
		$this->assertSame( 400, $defaults['banner_height'] );
	}

	/**
	 * Геттеры параметров вывода читают option и зажимают значения.
	 *
	 * Обратная проба: без зажимания высота 9999px сломает вёрстку,
	 * а текст кнопки без фолбэка на дефолт выведет пустую кнопку.
	 */
	public function test_output_params_getters_read_option_and_clamp() {
		delete_option( IDPG_Banner_Settings::OPTION );

		// Без option — дефолты.
		$this->assertSame( 'Подробнее', IDPG_Banner_Settings::get_button_text() );
		$this->assertSame( 0, IDPG_Banner_Settings::get_excerpt_length() );
		$this->assertSame( 400, IDPG_Banner_Settings::get_banner_height() );

		update_option(
			IDPG_Banner_Settings::OPTION,
			array(
				'button_text'    => 'Купить билет',
				'excerpt_length' => 120,
				'banner_height'  => 500,
			)
		);

		$this->assertSame( 'Купить билет', IDPG_Banner_Settings::get_button_text() );
		$this->assertSame( 120, IDPG_Banner_Settings::get_excerpt_length() );
		$this->assertSame( 500, IDPG_Banner_Settings::get_banner_height() );

		// Мусор зажимается.
		update_option(
			IDPG_Banner_Settings::OPTION,
			array(
				'button_text'    => '   ',
				'excerpt_length' => 9999,
				'banner_height'  => 9999,
			)
		);

		$this->assertSame( 'Подробнее', IDPG_Banner_Settings::get_button_text() );
		$this->assertSame( 500, IDPG_Banner_Settings::get_excerpt_length() );
		$this->assertSame( 800, IDPG_Banner_Settings::get_banner_height() );
	}

	/**
	 * Санитизация параметров вывода: текст чистится, пустой → дефолт,
	 * числа зажаты в диапазоны.
	 */
	public function test_sanitize_settings_reads_output_params() {
		$clean = IDPG_Banner_Settings::sanitize_settings(
			array(
				'idpg_banner_button_text'    => '<b>Смотреть</b>',
				'idpg_banner_excerpt_length' => 50,
				'idpg_banner_banner_height'  => 300,
			)
		);

		$this->assertSame( 'Смотреть', $clean['button_text'] );
		$this->assertSame( 50, $clean['excerpt_length'] );
		$this->assertSame( 300, $clean['banner_height'] );

		// Пустой текст → дефолт, числа вне диапазона зажаты.
		$clean = IDPG_Banner_Settings::sanitize_settings(
			array(
				'idpg_banner_button_text'    => '',
				'idpg_banner_excerpt_length' => 99999,
				'idpg_banner_banner_height'  => 10,
			)
		);

		$this->assertSame( 'Подробнее', $clean['button_text'] );
		$this->assertSame( 500, $clean['excerpt_length'] );
		$this->assertSame( 200, $clean['banner_height'] );
	}

	/**
	 * Хелпер sanitize_text_value: массив → пустая строка (без Array-to-string).
	 *
	 * Обратная проба: без is_array-гарда sanitize_text_field( array )
	 * даёт "Array to string conversion" в wp_check_invalid_utf8().
	 */
	public function test_sanitize_text_value_guards_arrays() {
		$this->assertSame( '', IDPG_Banner_Settings::sanitize_text_value( array( 'k' => array( 'x' ) ), 'k', 'text' ) );
		$this->assertSame( '', IDPG_Banner_Settings::sanitize_text_value( array( 'k' => array( 'x' ) ), 'k', 'textarea' ) );
		$this->assertSame( '', IDPG_Banner_Settings::sanitize_text_value( array(), 'k', 'text' ) );
		$this->assertSame( 'Купить', IDPG_Banner_Settings::sanitize_text_value( array( 'k' => '<b>Купить</b>' ), 'k', 'text' ) );
	}

	/**
	 * sanitize_settings переживает массивы в текстовых полях формы.
	 */
	public function test_sanitize_settings_survives_array_fields() {
		$clean = IDPG_Banner_Settings::sanitize_settings(
			array(
				'idpg_banner_button_text'  => array( 'x' ),
				'idpg_banner_banner_title' => array( 'x' ),
				'idpg_banner_banner_text'  => array( 'x' ),
			)
		);

		$this->assertSame( 'Подробнее', $clean['button_text'] );
		$this->assertSame( '', $clean['banner_title'] );
		$this->assertSame( '', $clean['banner_text'] );
	}

	/**
	 * У каждого типа поля есть PHP-партиал; id полей уникальны.
	 *
	 * Обратная проба: удаление партиала или дубль id ломает форму.
	 */
	public function test_output_field_definitions_have_partials() {
		$definitions = IDPG_Banner_Settings::get_output_field_definitions();
		$this->assertNotEmpty( $definitions );

		$ids = array();
		foreach ( $definitions as $key => $field ) {
			$this->assertArrayHasKey( 'id', $field, $key );
			$this->assertArrayHasKey( 'type', $field, $key );
			$this->assertContains( $field['type'], array( 'text', 'textarea', 'number', 'date', 'checkbox', 'select', 'url' ), $key );

			$partial = IDPG_CORE_DIR . 'templates/partials/banner-settings/field-' . $field['type'] . '.php';
			$this->assertFileExists( $partial, 'Нет партиала для типа ' . $field['type'] );

			$this->assertArrayNotHasKey( $field['id'], $ids, 'Дубль id: ' . $field['id'] );
			$ids[ $field['id'] ] = true;
		}
	}

	/**
	 * Санитизация берёт диапазоны из определений полей.
	 */
	public function test_sanitize_settings_clamps_by_definitions() {
		$clean = IDPG_Banner_Settings::sanitize_settings(
			array(
				'idpg_banner_excerpt_length' => 9999,
				'idpg_banner_banner_height'  => 10,
			)
		);

		$this->assertSame( 500, $clean['excerpt_length'] );
		$this->assertSame( 200, $clean['banner_height'] );
	}

	/**
	 * Поля баннера: санитизация по типам (date, select, url, number).
	 *
	 * Обратная проба: без safelist в select «hacked» попал бы в option;
	 * без проверки формата дата 01.12.2026 сохранилась бы как есть.
	 */
	public function test_sanitize_output_field_types() {
		$clean = IDPG_Banner_Settings::sanitize_settings(
			array(
				'idpg_banner_banner_start_date'    => '2026-12-01',
				'idpg_banner_banner_currency'      => 'GBP',
				'idpg_banner_banner_ticket_status' => 'hacked',
				'idpg_banner_banner_ticket_url'    => 'javascript:alert(1)',
				'idpg_banner_banner_price_from'    => '1200',
				'idpg_banner_banner_price_to'      => '99999999',
			)
		);

		$this->assertSame( '2026-12-01', $clean['banner_start_date'] );
		$this->assertSame( 'RUB', $clean['banner_currency'] );
		$this->assertSame( '', $clean['banner_ticket_status'] );
		$this->assertSame( '', $clean['banner_ticket_url'] );
		$this->assertSame( 1200, $clean['banner_price_from'] );
		$this->assertSame( 1000000, $clean['banner_price_to'] );

		// Невалидная дата отбрасывается.
		$bad = IDPG_Banner_Settings::sanitize_settings(
			array( 'idpg_banner_banner_start_date' => '01.12.2026' )
		);
		$this->assertSame( '', $bad['banner_start_date'] );
	}

	/**
	 * Все определения полей имеют уникальные id и известный тип.
	 */
	public function test_output_field_definitions_types_known() {
		$known = array( 'text', 'textarea', 'number', 'date', 'checkbox', 'select', 'url' );

		foreach ( IDPG_Banner_Settings::get_output_field_definitions() as $key => $field ) {
			$this->assertContains( $field['type'], $known, 'Неизвестный тип поля: ' . $key );
			$partial = IDPG_CORE_DIR . 'templates/partials/banner-settings/field-' . $field['type'] . '.php';
			$this->assertFileExists( $partial, 'Нет партиала для типа ' . $field['type'] );
		}
	}

	/**
	 * Выбор событий баннера: геттер по умолчанию пуст; санитизация
	 * оставляет только опубликованные события CPT `event`.
	 *
	 * Обратные пробы: (1) без проверки post_type черновик/запись попадут
	 * в баннер — тест обязан падать; (2) без проверки post_status
	 * неопубликованное событие тоже пройдёт; (3) без absint мусор
	 * сохранится как есть.
	 */
	public function test_banner_event_ids_sanitized_and_filtered() {
		delete_option( IDPG_Banner_Settings::OPTION );

		$this->assertSame( array(), IDPG_Banner_Settings::get_banner_event_ids() );

		$published = self::factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'publish',
			)
		);
		$draft     = self::factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'draft',
			)
		);
		$post      = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$clean = IDPG_Banner_Settings::sanitize_settings(
			array(
				'idpg_banner_event_ids' => array(
					(string) $published,
					$published,
					$draft,
					$post,
					'abc',
					0,
				),
			)
		);

		$this->assertSame( array( $published ), $clean['banner_event_ids'] );
		$this->assertSame( array( $published ), IDPG_Banner_Settings::get_banner_event_ids() );
	}

	/**
	 * Вкладка рендерит одиночный селект события баннера и отмечает выбранное.
	 *
	 * Обратная проба: без блока выбора владелец не может настроить
	 * баннер из этой вкладки; при возврате multiple-списка тест падает.
	 */
	public function test_admin_tab_renders_banner_event_selector() {
		// Событие создаём без текущего пользователя: иначе модерация
		// организаторов (enforce_pending_for_organizer) переводит publish
		// в pending, и селект остаётся пустым.
		wp_set_current_user( 0 );

		$event = self::factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'publish',
				'post_title'  => 'Событие для баннера',
			)
		);

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		update_option(
			IDPG_Banner_Settings::OPTION,
			array( 'banner_event_ids' => array( $event ) )
		);

		$html = $this->render_tab();

		$this->assertStringContainsString( 'name="idpg_banner_event_ids[]"', $html );
		$this->assertStringContainsString( 'value="' . $event . '"', $html );
		$this->assertStringContainsString( 'Событие для баннера', $html );
		$this->assertStringContainsString( 'value="' . $event . '"  selected', $html );
		$this->assertStringContainsString( '— Не выбрано —', $html );

		// Это выпадающий список одного значения, а не multiple-список.
		$this->assertStringNotContainsString( 'idpg_banner_event_ids[]" multiple', $html );
	}

	/**
	 * Вкладка рендерит поля ввода параметров вывода.
	 *
	 * Обратная проба: без полей в форме настройки сохраняются,
	 * но изменить их невозможно — тест обязан падать.
	 */
	public function test_admin_tab_renders_output_param_fields() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$html = $this->render_tab();

		foreach ( array(
			'name="idpg_banner_button_text"',
			'name="idpg_banner_excerpt_length"',
			'name="idpg_banner_banner_height"',
		) as $needle ) {
			$this->assertStringContainsString( $needle, $html );
		}
	}

	/**
	 * Свои заголовок и текст баннера: дефолт — пусто (данные события).
	 *
	 * Обратная проба: если геттер вернёт fallback-текст вместо пустой
	 * строки, баннер перестанет брать название события — тест обязан
	 * падать.
	 */
	public function test_banner_override_defaults_empty() {
		delete_option( IDPG_Banner_Settings::OPTION );

		$this->assertSame( '', IDPG_Banner_Settings::get_banner_title() );
		$this->assertSame( '', IDPG_Banner_Settings::get_banner_text() );

		update_option(
			IDPG_Banner_Settings::OPTION,
			array(
				'banner_title' => 'Афиша выходного дня',
				'banner_text'  => 'Выбирайте события в вашем городе',
			)
		);

		$this->assertSame( 'Афиша выходного дня', IDPG_Banner_Settings::get_banner_title() );
		$this->assertSame( 'Выбирайте события в вашем городе', IDPG_Banner_Settings::get_banner_text() );
	}

	/**
	 * Санитизация своих заголовка и текста: теги вырезаются,
	 * пробелы обрезаются.
	 */
	public function test_sanitize_settings_reads_banner_override() {
		$clean = IDPG_Banner_Settings::sanitize_settings(
			array(
				'idpg_banner_banner_title' => '<b>Афиша</b>',
				'idpg_banner_banner_text'  => "  Строка 1\nСтрока 2  ",
			)
		);

		$this->assertSame( 'Афиша', $clean['banner_title'] );
		$this->assertSame( "Строка 1\nСтрока 2", $clean['banner_text'] );

		// Пустые значения сохраняются как пустые строки (fallback на событие).
		$clean = IDPG_Banner_Settings::sanitize_settings( array() );
		$this->assertSame( '', $clean['banner_title'] );
		$this->assertSame( '', $clean['banner_text'] );
	}

	/**
	 * Вкладка рендерит поля ввода своих заголовка и текста.
	 */
	public function test_admin_tab_renders_banner_override_fields() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$html = $this->render_tab();

		$this->assertStringContainsString( 'name="idpg_banner_banner_title"', $html );
		$this->assertStringContainsString( 'name="idpg_banner_banner_text"', $html );
	}

	/**
	 * Вывод в баннере соответствует данным в полях формы.
	 *
	 * Обратные пробы: (1) поля формы не применились — в HTML останутся
	 * данные события, тест падает; (2) длина анонса не применяется —
	 * тест падает на обрезанном тексте; (3) высота не проброшена
	 * в CSS-переменную — тест падает на '--idpg-banner-h'.
	 */
	public function test_banner_output_matches_settings_fields() {
		// Событие с постером и данными «из БД».
		$attachment = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $attachment, '_wp_attached_file', '2026/10/test-banner.jpg' );

		wp_set_current_user( 0 );

		$event = self::factory()->post->create(
			array(
				'post_type'   => 'event',
				'post_status' => 'publish',
				'post_title'  => 'Название события из БД',
				'post_excerpt' => 'Анонс события из БД',
			)
		);
		set_post_thumbnail( $event, $attachment );

		$banner = IDPG_Current_Event_Banner::instance();
		$method = new ReflectionMethod( $banner, 'render_single_html' );
		$method->setAccessible( true );

		// 1. Поля формы заполнены — в баннере именно они, анонс без обрезки.
		update_option(
			IDPG_Banner_Settings::OPTION,
			array(
				'banner_title'   => 'Мой заголовок баннера',
				'banner_text'    => 'Мой текст анонса',
				'button_text'    => 'Купить билет',
				'banner_height'  => 520,
				'excerpt_length' => 0,
			)
		);

		$html = $method->invoke( $banner, get_post( $event ) );

		$this->assertStringContainsString( 'Мой заголовок баннера', $html, 'Заголовок из поля не выведен' );
		$this->assertStringContainsString( 'Мой текст анонса', $html, 'Текст из поля не выведен' );
		$this->assertStringContainsString( 'Купить билет', $html, 'Текст кнопки из поля не выведен' );
		$this->assertStringContainsString( '--idpg-banner-h:520px', $html, 'Высота из поля не проброшена' );
		$this->assertStringNotContainsString( 'Название события из БД', $html, 'Поля не перекрыли название события' );
		$this->assertStringNotContainsString( 'Анонс события из БД', $html, 'Поля не перекрыли анонс события' );

		// 2. Длина анонса из настроек обрезает текст поля.
		$long_text = 'Очень длинный текст анонса из настроек';

		update_option(
			IDPG_Banner_Settings::OPTION,
			array(
				'banner_title'   => '',
				'banner_text'    => $long_text,
				'button_text'    => 'Подробнее',
				'excerpt_length' => 12,
				'banner_height'  => 400,
			)
		);

		$html = $method->invoke( $banner, get_post( $event ) );

		$this->assertStringNotContainsString( $long_text, $html, 'Анонс не обрезан по длине из настроек' );
		$this->assertStringContainsString( mb_substr( $long_text, 0, 12 ) . '…', $html, 'Нет обрезанного анонса' );
		$this->assertStringContainsString( 'Подробнее', $html, 'Нет fallback текста кнопки' );
		$this->assertStringContainsString( '--idpg-banner-h:400px', $html, 'Нет дефолтной высоты' );
		$this->assertStringNotContainsString( 'Название события из БД', $html, 'Вернулся fallback на событие' );
		$this->assertStringNotContainsString( 'Анонс события из БД', $html, 'Вернулся fallback на анонс события' );
	}

	/**
	 * Рендер вкладки в буфер.
	 *
	 * @return string
	 */
	private function render_tab() {
		ob_start();
		IDPG_Banner_Settings::instance()->render_admin_tab();
		return ob_get_clean();
	}
}
