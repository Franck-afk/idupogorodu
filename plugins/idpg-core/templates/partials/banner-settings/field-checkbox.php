<?php
/**
 * Партиал: чекбокс настроек баннера.
 *
 * Ожидает: $field (array: id, label, description), $value ('yes'|'no').
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
		<label for="<?php echo esc_attr( $field['id'] ); ?>">
			<input type="checkbox"
				id="<?php echo esc_attr( $field['id'] ); ?>"
				name="<?php echo esc_attr( $field['id'] ); ?>"
				value="yes"
				<?php checked( 'yes', (string) $value ); ?>>
			<?php esc_html_e( 'Включено', 'idpg-core' ); ?>
		</label>
		<?php if ( ! empty( $field['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
		<?php endif; ?>
	</div>
</div>
