<?php
/**
 * Theme helper functions for idpg_core
 *
 * @package idpg_core
 */

/**
 * Метabox: скрытие заголовка страницы.
 */
function idpg_core_add_title_meta_box() {
	add_meta_box(
		'idpg_page_title_settings',
		'Настройки отображения',
		'idpg_page_title_meta_box_html',
		'page',
		'side',
		'low'
	);
}
add_action( 'add_meta_boxes', 'idpg_core_add_title_meta_box' );

/**
 * HTML метaboxа.
 */
function idpg_page_title_meta_box_html( $post ) {
	wp_nonce_field( 'idpg_page_title_meta', 'idpg_page_title_nonce' );
	$hide_title = get_post_meta( $post->ID, '_idpg_hide_title', true );
	?>
	<label>
		<input type="checkbox" name="idpg_hide_title" value="1" <?php checked( $hide_title, '1' ); ?>>
		Скрыть заголовок страницы на фронте
	</label>
	<?php
}

/**
 * Сохранение метaboxа.
 */
function idpg_core_save_title_meta( $post_id ) {
	if ( ! isset( $_POST['idpg_page_title_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( $_POST['idpg_page_title_nonce'], 'idpg_page_title_meta' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_page', $post_id ) ) {
		return;
	}

	$hide_title = isset( $_POST['idpg_hide_title'] ) ? '1' : '';
	update_post_meta( $post_id, '_idpg_hide_title', $hide_title );
}
add_action( 'save_post', 'idpg_core_save_title_meta' );

/**
 * Проверяет, скрыт ли заголовок страницы.
 *
 * @param int $post_id ID страницы. Если не указан — текущая.
 * @return bool
 */
function idpg_is_title_hidden( $post_id = 0 ) {
	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}

	return get_post_meta( $post_id, '_idpg_hide_title', true ) === '1';
}
