<?php
/**
 * Тест: без JavaScript поле даты в настройках баннера вводится.
 *
 * DEV-317 (P1, S). Без JS — дата не вводится: раньше поле было
 * `readonly` + Pikaday, поэтому без скриптов ввести/отправить дату нельзя.
 *
 * Решение (вариант A): убрано `readonly`; поле само несёт `name` и `value`
 * в формате ГГГГ-ММ-ДД (нужном санитизации); JS-обработчик валидирует
 * ввод. Приёмка — без JS поле редактируемо независимо от наличия Pikaday.
 *
 * @package idpg-core
 */

/**
 * Проверка поля даты формы настроек баннера без JavaScript.
 */
class Test_Banner_Form_NoJs extends WP_UnitTestCase {

	/**
	 * Поле даты блока «Баннер на главной»: без JS оно редактируемо.
	 *
	 * Обратная проба: возвращение `readonly` на видимое поле, уход `name`/`
	 * value` или превращение поля в `type="hidden"` ломает приёмку —
	 * тест падает.
	 */
	public function test_date_field_is_editable_without_js() {
		$field = array(
			'id'          => 'idpg_banner_banner_start_date',
			'key'         => 'banner_start_date',
			'type'        => 'date',
			'section'     => 'dates',
			'label'       => 'Дата начала',
			'description' => 'Пусто — дата начала события.',
			'default'     => '',
		);
		$value = '2026-10-15';

		ob_start();
		include dirname( __FILE__ ) . '/../templates/partials/banner-settings/field-date.php';
		$html = ob_get_clean();

		// Без JS видное поле не должно блокировать ввод.
		$this->assertStringNotContainsString(
			'readonly',
			$html,
			'Поле осталось readonly — без JS дату ввести нельзя.'
		);

		// Поле должно передаваться в форму и иметь значение.
		$this->assertStringContainsString(
			'name="idpg_banner_banner_start_date"',
			$html,
			'Поле не передаётся в форму (нет name).'
		);
		$this->assertStringContainsString(
			'value="2026-10-15"',
			$html,
			'Поле не заполнено значением.'
		);

		// Поле — текстовое, а не скрытое или датовое.
		$this->assertStringContainsString(
			'type="text"',
			$html,
			'Поле не тип text.'
		);

		// Видное поле должно быть единым: скрытого input больше не должно быть.
		$this->assertStringNotContainsString(
			'type="hidden"',
			$html,
			'Остался скрытый input (форма не соответствует варианту A).'
		);

		// Плейсхолдер указывает формат ввода.
		$this->assertStringContainsString(
			'placeholder="ГГГГ-ММ-ДД"',
			$html,
			'Плейсхолдер не совпадает с форматом ГГГГ-ММ-ДД.'
		);
	}
}
