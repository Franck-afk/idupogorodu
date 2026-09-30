<?php
/**
 * Сторож версии темы: `IDPG_VERSION` и заголовок `Version:` в style.css совпадают.
 *
 * Контекст. Версия темы — единственный источник кэш-бастинга: значение
 * подставляется в `?ver=` у style.css и скриптов темы (см. functions.php,
 * `wp_enqueue_style( 'idpg_core-style', ..., IDPG_VERSION )`). Если версия
 * не растёт, браузеры отдают ассеты из кэша, и правки стилей/скриптов не
 * доезжают до пользователя.
 *
 * После сбоя git (DEV-102) в дереве остались рассинхронизированные значения:
 * `IDPG_VERSION` = 1.0.75 при заголовке style.css = 1.0.73, хотя DEV-84
 * заявлял подъём до 1.0.77. DEV-138 привёл обе точки к 1.0.78.
 *
 * Тест ловит возврат к расхождению: сравнивает значение константы в
 * functions.php с заголовком style.css и с рантайм-значением (если тема
 * активна). Файлы читаются как текст, поэтому тема не обязана быть активной.
 *
 * @package idpg-core
 */

/**
 * Class Test_Theme_Version
 */
class Test_Theme_Version extends WP_UnitTestCase {

	/**
	 * Подключение functions.php темы, чтобы работала рантайм-проверка константы.
	 *
	 * Порядок как в `test-theme.php`: сначала регистрируем каталог тем, затем
	 * переключаемся на idpg_core и подключаем файл. Если тема уже загружена
	 * другим тестом набора — `require_once` и защита `defined()` не дадут
	 * определить константу повторно.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'IDPG_VERSION' ) && file_exists( $this->functions_php() ) ) {
			$parent = dirname( $this->theme_dir() );

			add_filter( 'theme_root', function () use ( $parent ) {
				return $parent;
			} );
			add_filter( 'stylesheet_root', function () use ( $parent ) {
				return $parent;
			} );
			add_filter( 'template_root', function () use ( $parent ) {
				return $parent;
			} );
			register_theme_directory( $parent );

			switch_theme( 'idpg_core' );

			if ( ! defined( 'IDPG_VERSION' ) ) {
				require_once $this->functions_php();
			}
		}
	}

	/**
	 * Каталог родительской темы idpg_core.
	 *
	 * @return string
	 */
	private function theme_dir() {
		return dirname( dirname( dirname( __DIR__ ) ) ) . '/themes/idpg_core';
	}

	/**
	 * Путь к style.css темы.
	 *
	 * @return string
	 */
	private function style_css() {
		return $this->theme_dir() . '/style.css';
	}

	/**
	 * Путь к functions.php темы.
	 *
	 * @return string
	 */
	private function functions_php() {
		return $this->theme_dir() . '/functions.php';
	}

	/**
	 * Заголовок `Version:` из style.css (через штатный `get_file_data()`).
	 *
	 * @return string Пустая строка, если заголовок не найден.
	 */
	private function header_version() {
		if ( ! file_exists( $this->style_css() ) ) {
			return '';
		}

		$data = get_file_data( $this->style_css(), array( 'Version' => 'Version' ) );

		return isset( $data['Version'] ) ? trim( (string) $data['Version'] ) : '';
	}

	/**
	 * Значение `IDPG_VERSION` из functions.php, прочитанное как текст.
	 *
	 * Чтение текстом намеренно: тест не зависит от того, активна ли тема
	 * в среде прогона.
	 *
	 * @return string Пустая строка, если объявление не найдено.
	 */
	private function constant_version() {
		if ( ! file_exists( $this->functions_php() ) ) {
			return '';
		}

		$source = (string) file_get_contents( $this->functions_php() );

		if ( ! preg_match( "/define\(\s*'IDPG_VERSION'\s*,\s*'([^']+)'\s*\)/", $source, $matches ) ) {
			return '';
		}

		return trim( $matches[1] );
	}

	/**
	 * Все объявления `IDPG_VERSION` в functions.php.
	 *
	 * @return array Список найденных значений.
	 */
	private function all_constant_versions() {
		if ( ! file_exists( $this->functions_php() ) ) {
			return array();
		}

		$source = (string) file_get_contents( $this->functions_php() );

		preg_match_all( "/define\(\s*'IDPG_VERSION'\s*,\s*'([^']+)'\s*\)/", $source, $matches );

		return isset( $matches[1] ) ? $matches[1] : array();
	}

	/**
	 * Файлы темы на месте.
	 */
	public function test_theme_files_exist() {
		$this->assertFileExists( $this->style_css(), 'style.css темы idpg_core не найден.' );
		$this->assertFileExists( $this->functions_php(), 'functions.php темы idpg_core не найден.' );
	}

	/**
	 * Заголовок `Version:` в style.css присутствует и непустой.
	 */
	public function test_header_version_present() {
		$header = $this->header_version();

		$this->assertNotSame( '', $header, 'В style.css нет заголовка Version:.' );
	}

	/**
	 * Константа `IDPG_VERSION` объявлена в functions.php.
	 */
	public function test_constant_version_present() {
		$constant = $this->constant_version();

		$this->assertNotSame( '', $constant, 'В functions.php нет define( IDPG_VERSION ).' );
	}

	/**
	 * Версия объявлена ровно один раз — иначе значение зависит от порядка.
	 */
	public function test_constant_declared_once() {
		$all = $this->all_constant_versions();

		$this->assertCount( 1, $all, 'IDPG_VERSION объявлена более одного раза.' );
	}

	/**
	 * Главная проверка: версия в functions.php совпадает с заголовком style.css.
	 */
	public function test_versions_match() {
		$header   = $this->header_version();
		$constant = $this->constant_version();

		$this->assertSame(
			$header,
			$constant,
			'IDPG_VERSION в functions.php не совпадает с Version: в style.css.'
		);
	}

	/**
	 * Формат версии: только цифры и точки (semver-подобный).
	 */
	public function test_version_format() {
		$constant = $this->constant_version();

		$this->assertMatchesRegularExpression(
			'/^\d+(\.\d+)*$/',
			$constant,
			'Версия темы должна состоять из цифр и точек.'
		);
	}

	/**
	 * Рантайм-значение константы совпадает с заголовком (если тема загружена).
	 *
	 * Ловит третий источник версии, перекрывающий functions.php.
	 */
	public function test_runtime_constant_matches_header() {
		if ( ! defined( 'IDPG_VERSION' ) ) {
			$this->markTestSkipped( 'Тема не загружена в этой среде — проверка рантайма пропущена.' );
		}

		$this->assertSame(
			$this->header_version(),
			(string) IDPG_VERSION,
			'Рантайм IDPG_VERSION не совпадает с заголовком style.css.'
		);
	}
}
