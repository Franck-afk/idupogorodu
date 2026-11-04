<?php
/**
 * Партиал: заголовок секции настроек баннера.
 *
 * Ожидает переменные:
 * - $section_title (string).
 * - $section_description (string, опционально).
 *
 * @package idpg-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h2 class="idpg-privacy-section-title"><?php echo esc_html( $section_title ); ?></h2>
<?php if ( ! empty( $section_description ) ) : ?>
	<p class="idpg-privacy-tools__text"><?php echo esc_html( $section_description ); ?></p>
<?php endif; ?>
