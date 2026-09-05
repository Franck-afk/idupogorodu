<?php
/**
 * Template part for displaying event search results
 *
 * @package idpg_core
 */

?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'event-card' ); ?>>
	<header class="entry-header">
		<?php the_title( sprintf( '<h2 class="entry-title"><a href="%s" rel="bookmark">', esc_url( get_permalink() ) ), '</a></h2>' ); ?>
	</header>

	<?php if ( has_post_thumbnail() ) : ?>
		<div class="event-thumbnail">
			<a href="<?php echo esc_url( get_permalink() ); ?>">
				<?php the_post_thumbnail( 'medium' ); ?>
			</a>
		</div>
	<?php endif; ?>

	<div class="entry-summary">
		<?php the_excerpt(); ?>
	</div>
</article>
