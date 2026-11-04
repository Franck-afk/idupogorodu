<?php
/**
 * Партиал: текстовое поле настроек баннера.
 *
 * Ожидает переменные:
 * - $field (array): id, label, description, placeholder.
 * - $value (string): текущее значение.
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
		<input type="text"
			id="<?php echo esc_attr( $field['id'] ); ?>"
			name="<?php echo esc_attr( $field['id'] ); ?>"
			class="regular-text"
			value="<?php echo esc_attr( (string) $value ); ?>"
			<?php if ( ! empty( $field['placeholder'] ) ) : ?>placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"<?php endif; ?>>
		<?php if ( ! empty( $field['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
		<?php endif; ?>
	</div>
</div>
