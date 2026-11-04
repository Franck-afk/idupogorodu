<?php
/**
 * Партиал: многострочное поле настроек баннера.
 *
 * Ожидает переменные:
 * - $field (array): id, label, description, placeholder, rows.
 * - $value (string): текущее значение.
 *
 * @package idpg-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$idpg_rows = ! empty( $field['rows'] ) ? (int) $field['rows'] : 3;
?>
<div class="idpg-option-field">
	<label class="idpg-option-field-label" for="<?php echo esc_attr( $field['id'] ); ?>">
		<span><?php echo esc_html( $field['label'] ); ?></span>
	</label>
	<div class="idpg-option-field-body">
		<textarea
			id="<?php echo esc_attr( $field['id'] ); ?>"
			name="<?php echo esc_attr( $field['id'] ); ?>"
			class="large-text"
			rows="<?php echo esc_attr( (string) $idpg_rows ); ?>"
			<?php if ( ! empty( $field['placeholder'] ) ) : ?>placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"<?php endif; ?>><?php echo esc_textarea( (string) $value ); ?></textarea>
		<?php if ( ! empty( $field['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
		<?php endif; ?>
	</div>
</div>
