<?php
/**
 * Партиал: поле даты настроек баннера (ручной ввод, без JavaScript).
 *
 * Вариант A решения DEV-317 (помечен как «Без JS — дата не вводится»).
 * Убрали `readonly` на видимом поле. Поле само несёт `name` и `value`
 * (формат ГГГГ-ММ-ДД, нужный санитизации `sanitize_output_field_value()`),
 * поэтому без JavaScript форму можно отправить и дату ввести. JS-обработчик
 * валидирует ввод: допустимы только ГГГГ-ММ-ДД, а при вводе старого вида
 * ДД.ММ.ГГГД поле конвертирует в ГГГГ-ММ-ДД; на пустое поле очищает.
 *
 * Ожидает: $field (array: id, label, description), $value (string Y-m-d).
 *
 * @package idpg-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="idpg-option-field">
	<label class="idpg-option-field-label" for="<?php echo esc_attr( $field['id'] ); ?>">
		<span><?php echo esc_html( $field['label'] ); ?></span>
	</label>
	<div class="idpg-option-field-body">
		<span class="idpg-datepicker-wrap">
			<input type="text"
					class="idpg-datepicker"
					id="<?php echo esc_attr( $field['id'] ); ?>"
					name="<?php echo esc_attr( $field['id'] ); ?>"
					value="<?php echo esc_attr( (string) $value ); ?>"
					placeholder="<?php esc_attr_e( 'ГГГГ-ММ-ДД', 'idpg-core' ); ?>"
					autocomplete="off">
			<button type="button" class="idpg-datepicker-clear"
					aria-label="<?php esc_attr_e( 'Очистить дату', 'idpg-core' ); ?>"
					title="<?php esc_attr_e( 'Очистить', 'idpg-core' ); ?>">&times;</button>
		</span>
		<?php if ( ! empty( $field['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
		<?php endif; ?>
	</div>
</div>
