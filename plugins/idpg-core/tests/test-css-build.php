<?php
/**
 * Сторож сборки CSS: `npm run compile:css` не должен терять правила.
 *
 * Контекст. До DEV-87 SCSS-дерево отстало от style.css: в style.css была
 * система из 103 CSS-токенов (на момент c12921c, сейчас 101 — DEV-19) в
 * :root, которой в SCSS не было вовсе. Любая
 * полная пересборка откатывала дизайн — терялись токены и 13 компонентов.
 * Живой style.css тогда перенесли в партиалы (см. assets/scss/), и сборка
 * стала эквивалентной.
 *
 * Тест ловит возврат к расхождению: собирает SCSS во временный файл и
 * сравнивает набор правил с текущим style.css. Нулевая разница — сборка
 * безопасна; любое расхождение — регресс, который надо рассмотреть.
 *
 * Требует node с sass (`npx --yes sass`). Если node недоступен — пропуск.
 *
 * @package idpg-core
 */

/**
 * Class Test_Css_Build
 */
class Test_Css_Build extends WP_UnitTestCase {

	/**
	 * Собранный из SCSS CSS.
	 *
	 * @var string|null
	 */
	private static $compiled = null;

	/**
	 * Результат сравнения.
	 *
	 * @var array|null
	 */
	private static $diff = null;

	/**
	 * Путь к style.css родительской темы.
	 *
	 * @return string
	 */
	private function style_css() {
		return $this->real_theme_dir() . '/idpg_core/style.css';
	}

	/**
	 * Каталог рабочих тем: wp-content/themes.
	 *
	 * @return string
	 */
	private function real_theme_dir() {
		return dirname( dirname( dirname( __DIR__ ) ) ) . '/themes';
	}

	/**
	 * Собирает SCSS и сравнивает с style.css. Выполняется один раз.
	 */
	private function ensure_diff() {
		if ( null !== self::$diff ) {
			return;
		}

		if ( ! file_exists( $this->style_css() ) ) {
			self::$diff = array(
				'skipped' => 'style.css не найден.',
			);
			return;
		}

		$scss = $this->real_theme_dir() . '/idpg_core/assets/scss/style.scss';

		if ( ! file_exists( $scss ) ) {
			self::$diff = array( 'skipped' => 'style.scss не найден.' );
			return;
		}

		$node = trim( (string) shell_exec( 'node --version 2>&1' ) );

		if ( '' === $node ) {
			self::$diff = array( 'skipped' => 'node не установлен.' );
			return;
		}

		$tmp = wp_tempnam( 'idpg-css-build' );
		$cmd = 'npx --yes sass@1.69.5 ' . escapeshellarg( $scss ) . ' '
			. escapeshellarg( $tmp ) . ' --no-source-map --style=expanded 2>&1';

		$output = array();
		$status = 0;
		exec( $cmd, $output, $status );

		if ( 0 !== $status || ! file_exists( $tmp ) ) {
			self::$diff = array(
				'skipped' => 'sass не собрался (возможно, нет сети для npx): '
					. implode( "\n", array_slice( $output, 0, 3 ) ),
			);
			return;
		}

		$built = file_get_contents( $tmp );
		wp_delete_file( $tmp );

		self::$compiled = $built;
		self::$diff     = self::compare_rules(
			file_get_contents( $this->style_css() ),
			$built
		);
	}

	/**
	 * Сравнивает два CSS на уровне правил.
	 *
	 * Ключ правила — контекст at-rule + нормализованный список селекторов.
	 * Значение — нормализованный список деклараций без комментариев.
	 * Этого достаточно: CSS проекта плоский, вложенность только @media,
	 * а проверяется именно совпадение набора правил, а не форматирование.
	 *
	 * @param string $a Исходный CSS (style.css).
	 * @param string $b Собранный CSS.
	 * @return array {missing: array, changed: array, added: array, a: int, b: int}
	 */
	private static function compare_rules( $a, $b ) {
		$map_a = self::parse_rules( $a );
		$map_b = self::parse_rules( $b );

		$missing = array();
		$changed = array();
		$added   = array();

		foreach ( $map_a as $key => $rule ) {
			if ( ! isset( $map_b[ $key ] ) ) {
				$missing[] = $rule['label'];
			} elseif ( $map_b[ $key ]['decl'] !== $rule['decl'] ) {
				$changed[] = $rule['label'];
			}
		}
		foreach ( $map_b as $key => $rule ) {
			if ( ! isset( $map_a[ $key ] ) ) {
				$added[] = $rule['label'];
			}
		}

		return array(
			'missing' => $missing,
			'changed' => $changed,
			'added'   => $added,
			'a'       => count( $map_a ),
			'b'       => count( $map_b ),
		);
	}

	/**
	 * Разбирает CSS в карту правил.
	 *
	 * @param string $css CSS.
	 * @return array
	 */
	private static function parse_rules( $css ) {
		$map = array();
		self::walk( self::strip_comments( $css ), array(), $map );

		return $map;
	}

	/**
	 * Рекурсивный спуск по блокам.
	 *
	 * Заходит ВНУТРЬ @media/@supports: правила внутри них — полноценные
	 * правила, и именно там лежит половина проекта (нижняя панель, модалка
	 * города, мобильные тач-таргеты). Плоский разбор их не видел, и гард
	 * молчал бы при потере правила внутри @media.
	 *
	 * @param string $css      CSS-фрагмент.
	 * @param array  $at_stack Открытые at-rule (для ключа).
	 * @param array  $map      Накопитель правил (по ссылке).
	 */
	private static function walk( $css, $at_stack, &$map ) {
		$len = strlen( $css );
		$i   = 0;

		while ( $i < $len ) {
			if ( '{' !== $css[ $i ] ) {
				$i++;
				continue;
			}

			// Начало селектора: идём назад до ';' , '{' или '}'.
			$start = $i - 1;
			while ( $start >= 0 && '{' !== $css[ $start ] && ';' !== $css[ $start ] && '}' !== $css[ $start ] ) {
				$start--;
			}
			$selector = trim( substr( $css, $start + 1, $i - $start - 1 ) );

			// Закрывающая скобка с учётом вложенности.
			$depth = 1;
			$j     = $i + 1;
			while ( $j < $len && $depth > 0 ) {
				if ( '{' === $css[ $j ] ) {
					$depth++;
				} elseif ( '}' === $css[ $j ] ) {
					$depth--;
				}
				$j++;
			}
			$body = substr( $css, $i + 1, $j - $i - 2 );

			if ( '' === $selector ) {
				$i = $j;
				continue;
			}

			if ( 0 === strpos( $selector, '@' ) ) {
				// Контейнер: рекурсия внутрь с добавлением контекста.
				$at_stack[] = self::norm_sel( $selector );
				self::walk( $body, $at_stack, $map );
				array_pop( $at_stack );
			} else {
				$at = implode( ' ', $at_stack );
				$key = $at . '||' . self::norm_sel( $selector );

				if ( ! isset( $map[ $key ] ) ) {
					$map[ $key ] = array(
						'decl'  => self::norm_decl( $body ),
						'label' => ( '' === $at ? '' : $at . ' ' ) . $selector,
					);
				}
			}

			$i = $j;
		}
	}

	/**
	 * Убирает CSS-комментарии.
	 *
	 * @param string $css CSS.
	 * @return string
	 */
	private static function strip_comments( $css ) {
		return (string) preg_replace( '#/\*.*?\*/#s', '', $css );
	}

	/**
	 * Нормализация списка селекторов: убрать пробелы, отсортировать.
	 *
	 * @param string $s Список селекторов через запятую.
	 * @return string
	 */
	private static function norm_sel( $s ) {
		return self::norm_list( $s, ',' );
	}

	/**
	 * Нормализация блока деклараций: убрать пробелы, отсортировать.
	 *
	 * @param string $s Блок деклараций.
	 * @return string
	 */
	private static function norm_decl( $s ) {
		return self::norm_list( $s, ';' );
	}

	/**
	 * Разбивает по разделителю, чистит и сортирует.
	 *
	 * @param string $s   Строка.
	 * @param string $sep Разделитель.
	 * @return string
	 */
	private static function norm_list( $s, $sep ) {
		$parts = array_map( 'trim', explode( $sep, $s ) );
		$parts = array_filter(
			$parts,
			static function ( $x ) {
				return '' !== $x;
			}
		);
		$parts = array_map(
			static function ( $x ) {
				return trim( preg_replace( '/\s+/', ' ', $x ) );
			},
			$parts
		);
		sort( $parts );

		return implode( $sep, $parts );
	}

	/* ---------------------------------------------------------------------
	 * Тесты
	 * ------------------------------------------------------------------ */

	/**
	 * Сборка вообще выполняется (иначе все проверки ниже бессмысленны).
	 */
	public function test_scss_compiles() {
		$this->ensure_diff();

		$skip = $this->skip_reason();
		if ( null !== $skip ) {
			$this->markTestSkipped( $skip );
		}

		$this->assertNotEmpty(
			self::$compiled,
			'Сборка из SCSS вернула пустой файл.'
		);
	}

	/**
	 * Сборка не теряет ни одного правила.
	 */
	public function test_rebuild_keeps_every_rule() {
		$this->ensure_diff();

		$skip = $this->skip_reason();
		if ( null !== $skip ) {
			$this->markTestSkipped( $skip );
		}

		$this->assertSame(
			array(),
			self::$diff['missing'],
			'Пересборка потеряла правила: ' . implode( '; ', self::$diff['missing'] )
		);
	}

	/**
	 * Сборка не выкидывает и не добавляет правил.
	 */
	public function test_rebuild_does_not_invent_or_drop_rules() {
		$this->ensure_diff();

		$skip = $this->skip_reason();
		if ( null !== $skip ) {
			$this->markTestSkipped( $skip );
		}

		$this->assertSame(
			self::$diff['a'],
			self::$diff['b'],
			sprintf(
				'Число правил разошлось: в style.css %d, в сборке %d. Потеряно: %s. Лишнее: %s.',
				self::$diff['a'],
				self::$diff['b'],
				implode( '; ', self::$diff['missing'] ),
				implode( '; ', self::$diff['added'] )
			)
		);
	}

	/**
	 * Декларации совпадают, а не только селекторы.
	 */
	public function test_rebuild_keeps_declarations_identical() {
		$this->ensure_diff();

		$skip = $this->skip_reason();
		if ( null !== $skip ) {
			$this->markTestSkipped( $skip );
		}

		$this->assertSame(
			array(),
			self::$diff['changed'],
			'Декларации правил изменились при сборке: ' . implode( '; ', self::$diff['changed'] )
		);
	}

	/**
	 * Токены на месте: потеря :root означает потерю всей палитры.
	 */
	public function test_css_custom_properties_survive() {
		$css = file_get_contents( $this->style_css() );

		$this->assertStringContainsString( ':root', $css, 'В style.css нет :root с токенами.' );
		$this->assertGreaterThan(
			50,
			preg_match_all( '/--idpg-[\w-]+\s*:/', $css ),
			'Слишком мало CSS-переменных --idpg-*: токены выродились.'
		);
	}

	/**
	 * Причина пропуска, если сборка невозможна.
	 *
	 * @return string|null
	 */
	private function skip_reason() {
		return isset( self::$diff['skipped'] ) ? self::$diff['skipped'] : null;
	}
}
