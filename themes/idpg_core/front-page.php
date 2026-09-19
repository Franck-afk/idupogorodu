<?php
/**
 * Front Page template.
 *
 * Отображается когда Настройки → Чтение → "Ваша главная страница: Статическая страница".
 *
 * @package idpg_core
 */

get_header();
?>
<div class="container" style="padding-top: 106px;">
	<?php do_action( 'idpg_banner_position', 'hero' ); ?>
</div>

<main id="primary" class="site-main">
	<div class="container">

		<?php idpg_widget_areas_render( 'above-content' ); ?>

		<div class="idpg-home">
			<div class="idpg-home__inner">

			<?php if ( idpg_home_section_enabled( 'today' ) ) : ?>
		<?php
		/*
		 * Секционный блок «Сегодня».
		 *
		 * Показываем события, идущие сегодня (включая многодневные,
		 * у которых start <= сегодня <= end) — та же семантика дат,
		 * что и у фильтра афиши /afisha/?date=today (functions.php).
		 * Город фильтруется автоматически через idpg_get_home_section_events().
		 */
		$idpg_today_ymd = gmdate( 'Y-m-d', current_time( 'timestamp' ) );

		$idpg_today_query = idpg_get_home_section_events(
			array(
				'posts_per_page' => 6,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => '_idpg_start_date',
						'value'   => $idpg_today_ymd,
						'compare' => '<=',
						'type'    => 'DATE',
					),
					array(
						'relation' => 'OR',
						// Многодневное событие: конец ещё не наступил.
						array(
							'key'     => '_idpg_end_date',
							'value'   => $idpg_today_ymd,
							'compare' => '>=',
							'type'    => 'DATE',
						),
						// Однодневное: end пуст, старт равен сегодняшнему дню.
						array(
							'relation' => 'AND',
							array(
								'key'   => '_idpg_end_date',
								'value' => '',
							),
							array(
								'key'     => '_idpg_start_date',
								'value'   => $idpg_today_ymd,
								'compare' => '=',
								'type'    => 'DATE',
							),
						),
					),
				),
			)
		);

		if ( $idpg_today_query->have_posts() ) :
			?>
			<section class="idpg-home-section" aria-labelledby="idpg-home-today-title">
				<header class="idpg-home-section__header">
					<h2 class="idpg-home-section__title" id="idpg-home-today-title">
						<?php esc_html_e( 'Сегодня', 'idpg_core' ); ?>
					</h2>
				</header>

				<div class="idpg-home-section__grid idpg-events-grid idpg-events-grid--today">
					<?php
					while ( $idpg_today_query->have_posts() ) :
						$idpg_today_query->the_post();

						get_template_part( 'template-parts/content', 'event-today' );

					endwhile;
					wp_reset_postdata();
					?>
 				</div>
 			</section>
 			<?php
		endif;
		endif;
		?>

		<?php
		if ( idpg_home_section_enabled( 'weekend' ) ) :
		?>
		<?php
		/*
		 * Секционный блок «В ближайшие выходные».
		 *
		 * Диапазон пт–вс: текущие выходные, если мы уже внутри них
		 * (пт/сб/вс), иначе ближайшие наступающие (пн–чт) — общий хелпер
		 * idpg_core_get_weekend_range() с фильтром афиши ?date=weekend.
		 * Событие совпадает с диапазоном [S,E], если start <= E и
		 * (end >= S или end пуст при S <= start <= E).
		 */
		list( $idpg_we_start, $idpg_we_end ) = idpg_core_get_weekend_range();

		$idpg_we_dow = (int) gmdate( 'N', current_time( 'timestamp' ) );
		$idpg_we_in  = ( $idpg_we_dow >= 5 ); // Мы уже в выходных?

		$idpg_we_query = idpg_get_home_section_events(
			array(
				'posts_per_page' => 6,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => '_idpg_start_date',
						'value'   => $idpg_we_end,
						'compare' => '<=',
						'type'    => 'DATE',
					),
					array(
						'relation' => 'OR',
						// Многодневное событие: конец после начала диапазона.
						array(
							'key'     => '_idpg_end_date',
							'value'   => $idpg_we_start,
							'compare' => '>=',
							'type'    => 'DATE',
						),
						// Однодневное: end пуст, старт попадает в диапазон.
						array(
							'relation' => 'AND',
							array(
								'key'   => '_idpg_end_date',
								'value' => '',
							),
							array(
								'key'     => '_idpg_start_date',
								'value'   => $idpg_we_start,
								'compare' => '>=',
								'type'    => 'DATE',
							),
							array(
								'key'     => '_idpg_start_date',
								'value'   => $idpg_we_end,
								'compare' => '<=',
								'type'    => 'DATE',
							),
						),
					),
				),
			)
		);

		if ( $idpg_we_query->have_posts() ) :
			?>
			<section class="idpg-home-section" aria-labelledby="idpg-home-weekend-title">
				<header class="idpg-home-section__header">
					<h2 class="idpg-home-section__title" id="idpg-home-weekend-title">
						<?php
						// Внутри выходных — «В эти выходные», до них — «В ближайшие выходные».
						echo esc_html(
							$idpg_we_in
								? __( 'В эти выходные', 'idpg_core' )
								: __( 'В ближайшие выходные', 'idpg_core' )
						);
						?>
					</h2>
				</header>

				<div class="idpg-home-section__grid idpg-events-grid idpg-events-grid--3 idpg-events-grid--v">
					<?php
					while ( $idpg_we_query->have_posts() ) :
						$idpg_we_query->the_post();

						get_template_part( 'template-parts/content', 'event' );

					endwhile;
					wp_reset_postdata();
					?>
				</div>
			</section>
			<?php
		endif;
		endif;
		?>

		<?php
		if ( idpg_home_section_enabled( 'free' ) ) :
		?>
		<?php
		/*
		 * Секционный блок «Бесплатные события».
		 *
		 * Флаг бесплатности в проекте хранится как _idpg_is_free = 'yes'
		 * (та же конвенция у фильтра афиши /afisha/?is_free=1 и форматтера
		 * цены). Город фильтруется автоматически через хелпер.
		 */
		$idpg_free_query = idpg_get_home_section_events(
			array(
				'posts_per_page' => 6,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_idpg_is_free',
						'value' => 'yes',
					),
				),
			)
		);

		if ( $idpg_free_query->have_posts() ) :
			?>
			<section class="idpg-home-section" aria-labelledby="idpg-home-free-title">
				<header class="idpg-home-section__header">
					<h2 class="idpg-home-section__title" id="idpg-home-free-title">
						<?php esc_html_e( 'Бесплатные события', 'idpg_core' ); ?>
					</h2>
				</header>

				<div class="idpg-home-section__grid idpg-events-grid idpg-events-grid--3 idpg-events-grid--v">
					<?php
					while ( $idpg_free_query->have_posts() ) :
						$idpg_free_query->the_post();

						get_template_part( 'template-parts/content', 'event' );

					endwhile;
 					wp_reset_postdata();
 					?>
 				</div>
 			</section>
 			<?php
		endif;
		endif;
		?>

			<?php
			if ( idpg_home_section_enabled( 'popular' ) ) :
			/*
			 * ─── Секция «Популярные события» ───
			 *
			 * Сортировка по счётчику просмотров _idpg_views.
			 * Fallback: если ни у одного события нет просмотров —
			 * показываем последние по дате. Порог бейджа «Популярное»
			 * = среднее число просмотров по выборке (см. content-event.php).
			 */
			$idpg_pop_query = idpg_get_home_section_events(
				array(
					'posts_per_page' => 6,
					'orderby'        => 'meta_value',
					'meta_key'       => '_idpg_views', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'order'          => 'DESC',
				)
			);

			// Fallback: просмотров нет → лента по дате.
			$idpg_pop_top = $idpg_pop_query->posts[0] ?? null;
			if ( ! $idpg_pop_top || (int) get_post_meta( $idpg_pop_top->ID, '_idpg_views', true ) < 1 ) {
				$idpg_pop_query = idpg_get_home_section_events(
					array(
						'posts_per_page' => 6,
						'orderby'        => 'date',
						'order'          => 'DESC',
					)
				);
			}

			// Порог «выше среднего» для бейджа в карточке.
			$idpg_pop_views = array_map(
				static fn( $p ) => (int) get_post_meta( $p->ID, '_idpg_views', true ),
				$idpg_pop_query->posts
			);
			$GLOBALS['idpg_popular_min_views'] = max(
				1,
				(int) ceil( array_sum( $idpg_pop_views ) / max( 1, count( $idpg_pop_views ) ) )
			);

			get_template_part(
				'template-parts/home-section',
				null,
				array(
					'heading_id' => 'idpg-home-popular-title',
					'title'      => __( 'Популярные события', 'idpg_core' ),
					'link_url'   => add_query_arg( 'sort', 'popular', idpg_core_city_afisha_url() ),
					'query'      => $idpg_pop_query,
					'grid_class' => 'idpg-events-grid--3 idpg-events-grid--v',
				)
			);

			unset( $GLOBALS['idpg_popular_min_views'] );
			endif;
			?>

 			<?php
 			if ( idpg_home_section_enabled( 'feed' ) ) :
			/*
			 * Основная лента: последние события города.
			 *
			 * show_on_front=page — дефолтный цикл вывел бы статическую
			 * страницу «Главная» (пустую), поэтому лента берётся явным
			 * запросом. Город фильтруется автоматически.
			 */
			$idpg_feed_query = idpg_get_home_section_events(
				array(
					'posts_per_page' => 6,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);

			if ( $idpg_feed_query->have_posts() ) :
				?>
				<section class="idpg-home-section" aria-labelledby="idpg-home-feed-title">
					<header class="idpg-home-section__header">
					<h2 class="idpg-home-section__title" id="idpg-home-feed-title">
						<?php esc_html_e( 'Все события', 'idpg_core' ); ?>
					</h2>
				</header>

				<div class="idpg-home-section__grid idpg-events-grid idpg-events-grid--3 idpg-events-grid--v">
						<?php
						while ( $idpg_feed_query->have_posts() ) :
							$idpg_feed_query->the_post();

							get_template_part( 'template-parts/content', 'event' );

						endwhile;
						wp_reset_postdata();
						?>
				</div>
				</section>
				<?php
			endif;
			endif;
			?>

			</div>

			<?php if ( is_active_sidebar( 'home-sidebar' ) ) : ?>
				<aside id="secondary" class="idpg-home__sidebar site-sidebar">
					<?php dynamic_sidebar( 'home-sidebar' ); ?>
				</aside>
			<?php endif; ?>
		</div>

		<?php do_action( 'idpg_banner_position', 'middle' ); idpg_widget_areas_render( 'below-content' ); ?>

	</div>
</main>

<div class="container">
	<?php do_action( 'idpg_banner_position', 'bottom' ); ?>
</div>

<?php
get_footer();
