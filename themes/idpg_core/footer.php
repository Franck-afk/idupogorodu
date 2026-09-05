<?php
/**
 * The template for displaying the footer
 *
 * Contains the closing of the #content div and all content after.
 *
 * @link https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package idpg_core
 */

?>

<?php idpg_widget_areas_render( 'before-footer' ); ?>

	<footer id="colophon" class="site-footer">
		<div class="site-footer__container">

		<div class="site-footer__top">

			<div class="site-footer__column">
				<h3 class="site-footer__title"><?php esc_html_e( 'Информация', 'idpg_core' ); ?></h3>
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'footer',
						'container'      => false,
						'menu_class'     => 'site-footer__list',
						'depth'          => 1,
						'fallback_cb'    => false,
					)
				);
				?>
			</div>

			<div class="site-footer__column site-footer__column--subscribe">
				<h3 class="site-footer__title"><?php esc_html_e( 'Мы в соцсетях', 'idpg_core' ); ?></h3>

				<ul class="site-footer__social">
					<?php foreach ( array( 'vk', 'telegram', 'dzen' ) as $social_icon ) : ?>
						<?php
						$social_icon_path = get_template_directory() . '/assets/icons/' . $social_icon . '.svg';

						if ( ! file_exists( $social_icon_path ) ) {
							continue;
						}

						$social_icon_svg = file_get_contents( $social_icon_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

						$social_labels = array(
							'vk'       => __( 'ВКонтакте', 'idpg_core' ),
							'telegram' => __( 'Telegram', 'idpg_core' ),
							'dzen'     => __( 'Дзен', 'idpg_core' ),
						);
						?>
						<li>
							<a class="site-footer__social-link" href="#" aria-label="<?php echo esc_attr( $social_labels[ $social_icon ] ); ?>" title="<?php echo esc_attr( $social_labels[ $social_icon ] ); ?>">
								<?php echo $social_icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>

		</div>

			<div class="site-footer__bottom">
				&copy; <?php echo esc_html( date( 'Y' ) ); ?> <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
			</div>

		</div>
	</footer>
</div><!-- #page -->

<?php wp_footer(); ?>

</body>
</html>
