<?php
/**
 * Template part for displaying venue cards in lists.
 *
 * @package idpg_core
 */

$idpg_post_id = get_the_ID();
$idpg_meta    = idpg_core_venue_meta( $idpg_post_id );
$idpg_thumb   = idpg_core_event_poster( $idpg_post_id, 'event-card' );
$idpg_perm    = get_permalink( $idpg_post_id );
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'idpg-venue-card' ); ?>>
	<?php if ( $idpg_thumb ) : ?>
		<a class="idpg-venue-card__thumb" href="<?php echo esc_url( $idpg_perm ); ?>" tabindex="-1" aria-hidden="true">
			<?php echo $idpg_thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</a>
	<?php endif; ?>

	<div class="idpg-venue-card__body">
		<h2 class="idpg-venue-card__title">
			<a href="<?php echo esc_url( $idpg_perm ); ?>"><?php the_title(); ?></a>
		</h2>

		<?php if ( ! empty( $idpg_meta['address'] ) ) : ?>
			<p class="idpg-venue-card__address"><?php echo esc_html( $idpg_meta['address'] ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $idpg_meta['phone'] ) || ! empty( $idpg_meta['capacity'] ) ) : ?>
			<div class="idpg-venue-card__meta">
				<?php if ( ! empty( $idpg_meta['phone'] ) ) : ?>
					<span class="idpg-venue-card__phone">
						<a href="tel:<?php echo esc_attr( preg_replace( '/[^+0-9]/', '', $idpg_meta['phone'] ) ); ?>">
							<?php echo esc_html( $idpg_meta['phone'] ); ?>
						</a>
					</span>
				<?php endif; ?>

				<?php if ( ! empty( $idpg_meta['capacity'] ) ) : ?>
					<span class="idpg-venue-card__capacity">
						<?php
						printf(
							/* translators: %s: вместимость */
							esc_html__( 'до %s мест', 'idpg_core' ),
							esc_html( number_format_i18n( (int) $idpg_meta['capacity'] ) )
						);
						?>
					</span>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="idpg-venue-card__footer">
			<a class="idpg-btn idpg-btn--secondary idpg-venue-card__more" href="<?php echo esc_url( $idpg_perm ); ?>">
				<?php esc_html_e( 'О площадке', 'idpg_core' ); ?>
			</a>
		</div>
	</div>
</article>