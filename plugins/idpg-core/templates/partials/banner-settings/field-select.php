<?php
/**
 * Партиал: выпадающий список настроек баннера.
 *
 * Ожидает: $field (array: id, label, description, options), $value (string).
 *
 * @package idpg-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$idpg_options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
?>
<div class="idpg-option-field">
	<label class="idpg-option-field-label" for="<?php echo esc_attr( $field['id'] ); ?>">
		<span><?php echo esc_html( $field['label'] ); ?></span>
	</label>
	<div class="idpg-option-field-body">
		<select id="<?php echo esc_attr( $field['id'] ); ?>" name="<?php echo esc_attr( $field['id'] ); ?>">
			<?php foreach ( $idpg_options as $idpg_opt_value => $idpg_opt_label ) : ?>
				<option value="<?php echo esc_attr( (string) $idpg_opt_value ); ?>" <?php selected( (string) $value, (string) $idpg_opt_value ); ?>>
					<?php echo esc_html( $idpg_opt_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php if ( ! empty( $field['description'] ) ) : ?>
			<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
		<?php endif; ?>
	</div>
</div>
