<?php
/**
 * Партиал: URL-поле настроек баннера.
 *
 * Ожидает: $field (array: id, label, description, placeholder), $value (string).
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
		<input type="url"
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
