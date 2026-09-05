<?php
/**
 * Компактная горизонтальная карточка события для блока «Сегодня».
 *
 * Фото слева (квадрат), чип времени, заголовок, площадка.
 * Отличается от основной карточки афиши, чтобы блок читался
 * как быстрая сводка дня.
 *
 * @package idpg_core
 */

$idpg_post_id   = get_the_ID();
$idpg_meta      = idpg_core_event_meta( $idpg_post_id );
$idpg_sessions  = idpg_core_event_sessions( $idpg_post_id );
$idpg_perm      = get_permalink( $idpg_post_id );
$idpg_today_ymd = gmdate( 'Y-m-d', current_time( 'timestamp' ) );

/*
 * Чип времени:
 * - «Весь день»        — флаг all_day;
 * - «Идёт»             — многодневное, началось раньше сегодня;
 * - время первой сессии — обычный случай;
 * - «Сегодня»          — без данных.
 */
if ( ! empty( $idpg_meta['all_day'] ) ) {
	$idpg_time_label = __( 'Весь день', 'idpg_core' );
	$idpg_time_live  = false;
} elseif ( ! empty( $idpg_meta['date_start'] ) && $idpg_meta['date_start'] < $idpg_today_ymd ) {
	$idpg_time_label = __( 'Идёт', 'idpg_core' );
	$idpg_time_live  = true;
} elseif ( $idpg_sessions ) {
	$idpg_time_label = $idpg_sessions[0]['time'];
	$idpg_time_live  = false;
} else {
	$idpg_time_label = __( 'Сегодня', 'idpg_core' );
	$idpg_time_live  = false;
}

$idpg_thumb_id = get_post_thumbnail_id( $idpg_post_id );

$idpg_venue      = idpg_core_get_venue( $idpg_post_id );
$idpg_venue_name = $idpg_venue ? $idpg_venue->post_title : '';

$idpg_placeholder_letter = mb_strtoupper( mb_substr( get_the_title(), 0, 1 ) );
?>

<a class="idpg-today-card" href="<?php echo esc_url( $idpg_perm ); ?>">
	<span class="idpg-today-card__thumb" aria-hidden="true">
		<?php if ( $idpg_thumb_id ) : ?>
			<?php echo wp_get_attachment_image( $idpg_thumb_id, 'medium', false, array( 'class' => 'idpg-today-card__img', 'loading' => 'lazy' ) ); ?>
		<?php else : ?>
			<?php echo esc_html( $idpg_placeholder_letter ); ?>
		<?php endif; ?>
	</span>

	<span class="idpg-today-card__body">
		<span class="idpg-today-card__time<?php echo $idpg_time_live ? ' idpg-today-card__time--live' : ''; ?>">
			<?php echo esc_html( $idpg_time_label ); ?>
		</span>

		<h3 class="idpg-today-card__title"><?php the_title(); ?></h3>

		<?php if ( $idpg_venue_name ) : ?>
			<span class="idpg-today-card__venue"><?php echo esc_html( $idpg_venue_name ); ?></span>
		<?php endif; ?>
	</span>

	<?php
	// Сердце «в избранное» (авторизованные).
	if ( class_exists( 'IDPG_Favorites' ) ) {
		IDPG_Favorites::button( $idpg_post_id );
	}
	?>
</a>
