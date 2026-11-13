<?php
/**
 * Legal document functions for idpg_core theme
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

 *
 * @param string $fallback_html Дефолтный текст, если контент страницы пуст.
 * @return array { toc: array[], html: string }
 */
function idpg_core_legal_document( $fallback_html = '' ) {
	ob_start();
	the_content();
	$html = trim( ob_get_clean() );

	if ( '' === $html ) {
		$html = $fallback_html;
	}

	$toc     = array();
	$counter = 0;

	$html = preg_replace_callback(
		'/<h2([^>]*)>(.*?)<\/h2>/is',
		function ( $m ) use ( &$toc, &$counter ) {
			$counter++;
			$id    = 'section-' . $counter;
			$toc[] = array(
				'id'    => $id,
				'title' => wp_strip_all_tags( $m[2] ),
			);
			return '<h2 id="' . esc_attr( $id ) . '"' . $m[1] . '>' . $m[2] . '</h2>';
		},
		$html
	);

	return array(
		'toc'  => $toc,
		'html' => $html,
	);
}

/**
 * Печать блока юридического документа: оглавление + текст.
 *
 * @param array $doc Результат idpg_core_legal_document().
 */
function idpg_core_legal_render( $doc ) {
	$has_toc = count( $doc['toc'] ) > 1;
	?>
	<div class="idpg-legal-layout<?php echo $has_toc ? ' has-toc' : ''; ?>">
		<?php if ( $has_toc ) : ?>
			<nav class="idpg-legal-toc" aria-label="<?php esc_attr_e( 'Содержание документа', 'idpg_core' ); ?>">
				<span class="idpg-legal-toc__caption"><?php esc_html_e( 'Содержание', 'idpg_core' ); ?></span>
				<ul class="idpg-legal-toc__list">
					<?php foreach ( $doc['toc'] as $item ) : ?>
						<li><a href="#<?php echo esc_attr( $item['id'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>
		<?php endif; ?>
		<div class="idpg-legal-body">
			<?php echo wp_kses_post( $doc['html'] ); ?>
		</div>
	</div>
	<?php
}
