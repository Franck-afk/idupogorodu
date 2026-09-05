<?php
/**
 * The template for displaying venue archives.
 *
 * @package idpg_core
 */

get_header();
?>

	<main id="primary" class="site-main">
		<div class="container">

			<?php idpg_widget_areas_render( 'above-content' ); ?>

			<header class="idpg-archive-header">
				<h1 class="idpg-archive-header__title">
					<?php post_type_archive_title(); ?>
				</h1>
				<?php the_archive_description( '<div class="idpg-archive-header__desc">', '</div>' ); ?>

			<?php
			/*
			 * Панель фильтров площадок по типу.
			 *
			 * Активное состояние определяется по ?type=
			 * (theater|cinema|museum|concert). Фильтрация выполняется
			 * в pre_get_posts (functions.php).
			 *
			 * Важно: база ссылок — городской архив /venues/{city}/, а не голый
			 * /venues/, который делает 301 и теряет ?type=.
			 */
			$idpg_v_type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			$idpg_v_types = array(
				''        => __( 'Все площадки', 'idpg_core' ),
				'theater' => __( 'Театры', 'idpg_core' ),
				'cinema'  => __( 'Кинотеатры', 'idpg_core' ),
				'museum'  => __( 'Музеи', 'idpg_core' ),
				'concert' => __( 'Концертные залы', 'idpg_core' ),
			);

			// Неизвестное значение — считаем фильтр сброшенным (иначе не подсвечена ни одна пилюля).
			if ( '' !== $idpg_v_type && ! isset( $idpg_v_types[ $idpg_v_type ] ) ) {
				$idpg_v_type = '';
			}

			// Городская база: /venues/{текущий город}/ — редиректа нет, ?type= сохраняется.
			if ( class_exists( 'IDPG_City_Url' ) && method_exists( 'IDPG_City_Url', 'venues_url' ) ) {
				$idpg_v_base = IDPG_City_Url::venues_url();
			} else {
				$idpg_v_base = get_post_type_archive_link( 'venue' );
			}
			?>
			<nav class="idpg-filter-bar" aria-label="<?php esc_attr_e( 'Фильтры площадок', 'idpg_core' ); ?>">
				<?php
				foreach ( $idpg_v_types as $idpg_v_key => $idpg_v_label ) {
					$idpg_v_url = '' === $idpg_v_key ? $idpg_v_base : add_query_arg( 'type', $idpg_v_key, $idpg_v_base );
					printf(
						'<a class="idpg-fpill%1$s" href="%2$s">%3$s</a>',
						$idpg_v_key === $idpg_v_type ? ' is-active' : '',
						esc_url( $idpg_v_url ),
						esc_html( $idpg_v_label )
					);
				}
				?>
			</nav>

			<?php $idpg_v_is_filtered_empty = ( ! have_posts() && '' !== $idpg_v_type ); ?>
			<?php if ( $idpg_v_is_filtered_empty ) : ?>
				<div class="idpg-archive-header__empty">
					<?php esc_html_e( 'По этому фильтру площадок нет. Попробуйте другой тип или перейдите ко всем площадкам.', 'idpg_core' ); ?>
				</div>
			<?php endif; ?>

			<div class="idpg-archive-header__back">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php esc_html_e( '← На главную', 'idpg_core' ); ?>
				</a>
			</div>
		</header>

		<?php if ( have_posts() ) : ?>

			<div class="idpg-venue-grid">
				<?php
				while ( have_posts() ) :
					the_post();

					get_template_part( 'template-parts/content', 'venue' );

				endwhile;
				?>
			</div>

			<?php
			the_posts_navigation();
			?>

		<?php elseif ( ! $idpg_v_is_filtered_empty ) : ?>

			<?php get_template_part( 'template-parts/content', 'none' ); ?>

		<?php endif; ?>

			<?php idpg_widget_areas_render( 'below-content' ); ?>

		</div>
	</main>

<?php
get_footer();
