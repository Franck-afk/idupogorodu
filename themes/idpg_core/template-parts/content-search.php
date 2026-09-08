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

<?php if ( IDPG_Media::idpg_get_poster_id( get_the_ID() ) ) : ?>
	<div class="event-thumbnail">
		<a href="<?php echo esc_url( get_permalink() ); ?>">
			<?php echo IDPG_Media::idpg_get_poster( get_the_ID(), 'medium' ); ?>
		</a>
	</div>
<?php endif; ?>

	<div class="entry-summary">
		<?php the_excerpt(); ?>
	</div>
</article>
