<?php
/**
 * Юнит-тесты DEV-15: логотип в письмах не должен превращаться в битую картинку.
 *
 * Что было: `IDPG_Auth_Emails::get_logo_url()` отдавала значение фильтра
 * `idpg_email_logo_url` как есть, не проверяя, что файл вообще есть на диске.
 * Стоило удалить логотип из uploads — и в письмо уходил `<img>` с битым src.
 * Письма шли в статусе «доставлено», дефект был виден только получателю.
 *
 * Что стало: `validate_logo_url()` сверяет локальный URL с файловой системой
 * и возвращает '' если файла нет; оба шаблона писем на пустом URL рисуют
 * текстовую замену вместо картинки, поэтому вёрстка не разъезжается.
 *
 * Важно для тестов: класс — синглтон, а `logo_url` кэшируется в свойстве,
 * поэтому между сценариями кэш сбрасывается рефлексией, иначе второй тест
 * получил бы URL из первого.
 *
 * @package Idpg_Core
 */

/**
 * Класс Test_Email_Logo
 */
class Test_Email_Logo extends WP_UnitTestCase {

	/**
	 * Имя файла логотипа по умолчанию.
	 */
	const LOGO_NAME = 'email-logo.png';

	/**
	 * Путь к файлу, который тест создаёт в uploads.
	 *
	 * @var string
	 */
	private $created_file = '';

	/**
	 * Подготовка окружения.
	 */
	public function set_up() {
		parent::set_up();

		$this->reset_logo_cache();
		$this->created_file = '';
	}

	/**
	 * Очистка окружения: удаляем созданный файл и сбрасываем кэш.
	 */
	public function tear_down() {
		remove_all_filters( 'idpg_email_logo_url' );

		if ( '' !== $this->created_file && file_exists( $this->created_file ) ) {
			wp_delete_file( $this->created_file );
		}

		$this->created_file = '';
		$this->reset_logo_cache();

		parent::tear_down();
	}

	/**
	 * Сбрасывает кэш URL логотипа в синглтоне.
	 *
	 * `get_logo_url()` кэширует значение фильтра в приватном свойстве
	 * `logo_url`, а объект живёт весь запрос. Рефлексия — единственный
	 * способ добраться до приватного свойства из теста.
	 */
	private function reset_logo_cache() {
		$property = new ReflectionProperty( 'IDPG_Auth_Emails', 'logo_url' );
		$property->setAccessible( true );
		$property->setValue( IDPG_Auth_Emails::instance(), '' );
	}

	/**
	 * Создаёт настоящий файл логотипа в uploads.
	 *
	 * @param string $name Имя файла.
	 * @return string URL созданного файла.
	 */
	private function create_logo_file( $name = self::LOGO_NAME ) {
		$uploads = wp_get_upload_dir();
		$path    = $uploads['basedir'] . '/' . $name;

		// На случай прогона на Windows — каталог мог не создаться.
		if ( ! is_dir( $uploads['basedir'] ) ) {
			wp_mkdir_p( $uploads['basedir'] );
		}

		file_put_contents( $path, 'fake-png-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->created_file = $path;

		return $uploads['baseurl'] . '/' . $name;
	}

	/**
	 * Вызывает приватный get_logo_url() у синглтона.
	 *
	 * @return string
	 */
	private function call_get_logo_url() {
		$method = new ReflectionMethod( 'IDPG_Auth_Emails', 'get_logo_url' );
		$method->setAccessible( true );

		return $method->invoke( IDPG_Auth_Emails::instance() );
	}

	// =====================================================================
	// DEV-15: проверка существования файла
	// =====================================================================

	/**
	 * Логотип, который лежит на диске, отдаётся как URL.
	 *
	 * Базовый сценарий: наличие файла не должно ломать нормальную работу —
	 * раньше эта проверки не было вовсе, и важно убедиться, что добавленная
	 * не отсекает правильный логотип.
	 */
	public function test_existing_logo_returns_url() {
		$url = $this->create_logo_file();

		$this->assertTrue( file_exists( $this->created_file ) );
		$this->assertSame( $url, $this->call_get_logo_url() );
	}

	/**
	 * Отсутствующий файл даёт пустую строку, а не битый URL.
	 *
	 * Именно это и было дефектом: URL уходил в письмо независимо от того,
	 * есть файл или нет.
	 */
	public function test_missing_logo_returns_empty_string() {
		$uploads = wp_get_upload_dir();
		$missing = $uploads['baseurl'] . '/dev15-absent-logo.png';

		// Файла нет и создавать его не будем — это условие теста.
		$this->assertFileDoesNotExist( $uploads['basedir'] . '/dev15-absent-logo.png' );

		$this->assertSame( '', $this->call_get_logo_url() );
		$this->assertNotSame( $missing, $this->call_get_logo_url() );
	}

	/**
	 * Фильтр idpg_email_logo_url продолжает работать.
	 *
	 * Регрессия: проверка существования не должна отменять фильтр, иначе
	 * тема или другой плагин не смогут подменить логотип. Фильтр возвращает
	 * существующий файл — он обязан пройти проверку.
	 */
	public function test_filter_overrides_logo_url() {
		$url = $this->create_logo_file( 'dev15-filtered-logo.png' );

		add_filter(
			'idpg_email_logo_url',
			static function () use ( $url ) {
				return $url;
			}
		);

		$this->reset_logo_cache();

		$this->assertSame( $url, $this->call_get_logo_url() );
	}

	/**
	 * Фильтр может вернуть и внешний адрес — он не проверяется на диск.
	 *
	 * Отсекать такой URL нельзя: файл лежит на CDN, локально его нет,
	 * и картинка при этом работает. Проверка обязана быть opt-in по
	 * принадлежности нашему uploads, а не «всё, чего нет на диске».
	 */
	public function test_external_url_from_filter_is_not_discarded() {
		$external = 'https://cdn.example.com/logo.png';

		add_filter(
			'idpg_email_logo_url',
			static function () use ( $external ) {
				return $external;
			}
		);

		$this->reset_logo_cache();

		$this->assertSame( $external, $this->call_get_logo_url() );
	}

	/**
	 * Фильтр, вернувший URL удалённого файла, приводит к пустой строке.
	 *
	 * Второй путь к той же проблеме: картинку удалили, но URL продолжал
	 * приходить из фильтра.
	 */
	public function test_filter_url_with_deleted_file_yields_empty() {
		$url = $this->create_logo_file( 'dev15-to-delete.png' );

		add_filter(
			'idpg_email_logo_url',
			static function () use ( $url ) {
				return $url;
			}
		);

		$this->reset_logo_cache();

		// Пока файл есть — URL проходит.
		$this->assertSame( $url, $this->call_get_logo_url() );

		// Удаляем файл и сбрасываем кэш, как это было бы на живом сайте.
		wp_delete_file( $this->created_file );
		$this->created_file = '';
		$this->reset_logo_cache();

		$this->assertSame( '', $this->call_get_logo_url() );
	}

	// =====================================================================
	// Вёрстка: пустой URL не должен давать <img>
	// =====================================================================

	/**
	 * При пустом URL шаблон не рисует <img>.
	 *
	 * Проверяем именно отсутствие тега картинки: битая картинка в письме —
	 * это и есть симптом дефекта, даже если src формально выглядит корректно.
	 */
	public function test_template_has_no_img_when_logo_missing() {
		$html = IDPG_Auth_Emails::instance()->render_email_template( 'Тест', 'Проверка логотипа' );

		$this->assertStringNotContainsString( '<img', $html );
		// Текстовая замена остаётся, чтобы шапка письма не была пустой.
		$this->assertStringContainsString( 'Иду по городу', $html );
	}

	/**
	 * При существующем файле шаблон рисует <img>.
	 *
	 * Обратная сторона: проверка не должна убрать рабочий логотип.
	 */
	public function test_template_has_img_when_logo_exists() {
		$this->create_logo_file();

		$html = IDPG_Auth_Emails::instance()->render_email_template( 'Тест', 'Проверка логотипа' );

		$this->assertStringContainsString( '<img', $html );
		$this->assertStringContainsString( 'email-logo.png', $html );
	}

	/**
	 * Шаблон приветственного письма тоже не рисует <img> без файла.
	 *
	 * Шаблонов два, и дефект был бы только в одном из них, если бы правка
	 * попала не туда.
	 */
	public function test_organizer_template_has_no_img_when_logo_missing() {
		$method = new ReflectionMethod( 'IDPG_Auth_Emails', 'render_organizer_email_template' );
		$method->setAccessible( true );

		$html = $method->invoke(
			IDPG_Auth_Emails::instance(),
			'Иван Иванов',
			'dev15@example.com',
			'parol12345',
			'https://example.org/login'
		);

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( 'IDU POGORODU', $html );
	}

	/**
	 * Приветственное письмо рисует <img>, когда файл на месте.
	 */
	public function test_organizer_template_has_img_when_logo_exists() {
		$this->create_logo_file();

		$method = new ReflectionMethod( 'IDPG_Auth_Emails', 'render_organizer_email_template' );
		$method->setAccessible( true );

		$html = $method->invoke(
			IDPG_Auth_Emails::instance(),
			'Иван Иванов',
			'dev15@example.com',
			'parol12345',
			'https://example.org/login'
		);

		$this->assertStringContainsString( '<img', $html );
	}

	/**
	 * Вёрстка не разъезжается: пустой URL не оставляет пустой блок шапки.
	 *
	 * Приёмка DEV-15 — «письмо рендерится без разрыва вёрстки», поэтому
	 * проверяем, что вместо картинки выводится текстовый заменитель, а
	 * сама табличная шапка остаётся на месте.
	 */
	public function test_layout_survives_missing_logo() {
		$html = IDPG_Auth_Emails::instance()->render_email_template( 'Тест', 'Проверка логотипа' );

		// Контейнер письма и шапка логотипа остаются.
		$this->assertStringContainsString( 'role="presentation"', $html );
		$this->assertStringContainsString( '<span style="font-size: 22px', $html );
		// Нет ни пустого src, ни картинки без адреса.
		$this->assertStringNotContainsString( 'src=""', $html );
	}
}
