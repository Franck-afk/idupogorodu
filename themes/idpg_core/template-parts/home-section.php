<?php
/**
 * Единый шаблон секционного блока главной страницы.
 *
 * Вызывается через get_template_part() с аргументами (WP 5.5+):
 *
 * get_template_part( 'template-parts/home-section', null, array(
 *     'heading_id' => 'idpg-home-today-title',
 *     'title'      => __( 'Сегодня', 'idpg_core' ),
 *     'link_url'   => 'https://…/afisha/?date=today',
 *     'query'      => $wp_query_object,
 *     'grid_class' => 'idpg-events-grid--3 idpg-events-grid--v',
 * ) );
 *
 * Пустая выборка → секция не выводится вообще.
 *
 * @package idpg_core
 */

$idpg_section_args = isset( $args ) && is_array( $args ) ? $args : array();

$idpg_section_query = isset( $idpg_section_args['query'] ) ? $idpg_section_args['query'] : null;

// Нечего показывать — выходим без разметки.
if ( ! $idpg_section_query instanceof WP_Query || ! $idpg_section_query->have_posts() ) {
	return;
}

$idpg_section_heading_id = isset( $idpg_section_args['heading_id'] ) ? $idpg_section_args['heading_id'] : '';
$idpg_section_title      = isset( $idpg_section_args['title'] ) ? $idpg_section_args['title'] : '';
$idpg_section_link       = isset( $idpg_section_args['link_url'] ) ? $idpg_section_args['link_url'] : '';
$idpg_section_grid       = isset( $idpg_section_args['grid_class'] ) ? $idpg_section_args['grid_class'] : 'idpg-events-grid--3';
?>

<section class="idpg-home-section"<?php echo $idpg_section_heading_id ? ' aria-labelledby="' . esc_attr( $idpg_section_heading_id ) . '"' : ''; ?>>
	<header class="idpg-home-section__header">
		<h2 class="idpg-home-section__title"<?php echo $idpg_section_heading_id ? ' id="' . esc_attr( $idpg_section_heading_id ) . '"' : ''; ?>>
			<?php echo esc_html( $idpg_section_title ); ?>
		</h2>
	</header>

	<div class="idpg-home-section__grid idpg-events-grid <?php echo esc_attr( $idpg_section_grid ); ?>">
		<?php
		while ( $idpg_section_query->have_posts() ) :
			$idpg_section_query->the_post();

			get_template_part( 'template-parts/content', 'event' );

		endwhile;
		wp_reset_postdata();
		?>
	</div>
</section>
