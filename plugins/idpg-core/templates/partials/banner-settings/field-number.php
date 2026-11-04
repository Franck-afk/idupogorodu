<?php
/**
 * Партиал: числовое поле настроек баннера.
 *
 * Ожидает переменные:
 * - $field (array): id, label, description, min, max, step.
 * - $value (int): текущее значение.
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
		<input type="number"
			id="<?php echo esc_attr( $field['id'] ); ?>"
			name="<?php echo esc_attr( $field['id'] ); ?>"
			class="small-text"
			value="<?php echo esc_attr( (string) (int) $value ); ?>"
			<?php if ( isset( $field['min'] ) ) : ?>min="<?php echo esc_attr( (string) $field['min'] ); ?>"<?php endif; ?>
			<?php if ( isset( $field['max'] ) ) : ?>max="<?php echo esc_attr( (string) $field['max'] ); ?>"<?php endif; ?>
			<?php if ( ! empty( $field['step'] ) ) : ?>step="<?php echo esc_attr( (string) $field['step'] ); ?>"<?php endif; ?>>
		<?php if ( ! empty( $field['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
		<?php endif; ?>
	</div>
</div>
