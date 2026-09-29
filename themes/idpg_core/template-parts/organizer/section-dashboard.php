<?php
/**
 * Раздел «Дашборд» кабинета организатора (G6).
 *
 * Данные приходят из GET /idpg/v1/organizer/stats. Запрос делает
 * `organizer.js`: счётчики живут в REST-классе плагина, и дублировать
 * запрос здесь означало бы со временем развести две реализации одного
 * подсчёта. Поэтому сервер отдаёт разметку-каркас (подписи, плейсхолдеры и
 * рабочую ссылку «Создать событие»), а скрипт подставляет значения.
 *
 * Без JavaScript раздел остаётся читаемым: подписи на месте, цифры пустые,
 * кнопка создания события работает — это обычная ссылка.
 *
 * @package idpg_core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$idpg_dash_new_url = function_exists( 'idpg_organizer_event_form_url' )
	? idpg_organizer_event_form_url()
	: home_url( '/organizer/event/new/' );
?>

<div
	class="dashboard"
	data-idpg-stats
	data-idpg-stats-url="<?php echo esc_url( rest_url( 'idpg/v1/organizer/stats' ) ); ?>"
	data-idpg-stats-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
>
	<div class="dashboard__stats" data-idpg-stats-list>

		<?php
		/*
		 * Плейсхолдеры `—` намеренные: сетка не прыгает после загрузки,
		 * а подписи сразу видны и без скрипта. `data-idpg-stats-*` на
		 * значениях — контракт с organizer.js.
		 */
		$idpg_dash_cards = array(
			'primary' => array(
				'label' => __( 'Всего событий', 'idpg_core' ),
				'slot'  => 'total',
			),
			'success' => array(
				'label' => __( 'Опубликовано', 'idpg_core' ),
				'slot'  => 'publish',
			),
			'warning' => array(
				'label' => __( 'На модерации', 'idpg_core' ),
				'slot'  => 'pending',
			),
			'muted'   => array(
				'label' => __( 'Черновики', 'idpg_core' ),
				'slot'  => 'draft',
			),
		);
		?>

		<?php foreach ( $idpg_dash_cards as $idpg_dash_modifier => $idpg_dash_card ) : ?>
			<div class="dashboard__stat-card dashboard__stat-card--<?php echo esc_attr( $idpg_dash_modifier ); ?>">
				<div class="dashboard__stat-number" data-idpg-stats-value="<?php echo esc_attr( $idpg_dash_card['slot'] ); ?>">
					&mdash;
				</div>
				<div class="dashboard__stat-label">
					<?php echo esc_html( $idpg_dash_card['label'] ); ?>
				</div>
			</div>
		<?php endforeach; ?>

	</div>

	<p class="dashboard__actions">
		<a class="idpg-btn idpg-btn--primary" href="<?php echo esc_url( $idpg_dash_new_url ); ?>">
			<?php esc_html_e( '+ Создать событие', 'idpg_core' ); ?>
		</a>
	</p>

	<noscript>
		<div class="idpg-notice idpg-notice--info">
			<?php esc_html_e( 'Счётчики загружаются скриптом. Создать событие и перейти к списку можно и без JavaScript.', 'idpg_core' ); ?>
		</div>
	</noscript>

	<div
		class="idpg-notice idpg-notice--error"
		role="alert"
		hidden
		data-idpg-stats-error
	></div>
</div>
