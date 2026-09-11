<?php
/**
 * The template for displaying the events archive (Афиша).
 *
 * Поддерживает фильтры из меню:
 * - /afisha/?date=today | tomorrow | weekend | Y-m-d
 * - /afisha/?is_free=1
 *
 * Фильтрация выполняется в pre_get_posts (functions.php).
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
				 * Панель фильтров афиши (ЧПУ).
				 *
				 * Активное состояние определяется по query vars idpg_fd/idpg_ff,
				 * которые устанавливаются rewrite-правилами IDPG_City_URL.
				 */
				$idpg_f_fd = sanitize_title( (string) get_query_var( 'idpg_fd' ) );
				$idpg_f_ff = '1' === (string) get_query_var( 'idpg_ff' );
				$idpg_is_date_filter = $idpg_f_fd && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $idpg_f_fd );

				$idpg_pill_base = function ( string $label, string $url, bool $active ) {
					printf(
						'<a class="idpg-fpill%1$s" href="%2$s">%3$s</a>',
						$active ? ' is-active' : '',
						esc_url( $url ),
						esc_html( $label )
					);
				};
				?>
				<nav class="idpg-filter-bar" aria-label="<?php esc_attr_e( 'Фильтры афиши', 'idpg_core' ); ?>">
					<?php
					$idpg_pill_base(
						__( 'Все события', 'idpg_core' ),
						class_exists( 'IDPG_City_URL' ) ? IDPG_City_URL::afisha_url() : get_post_type_archive_link( 'event' ),
						! $idpg_f_fd && ! $idpg_f_ff
					);

					$idpg_pill_base( __( 'Сегодня', 'idpg_core' ), idpg_core_city_filter_url( 'today' ), 'today' === $idpg_f_fd );
					$idpg_pill_base( __( 'Завтра', 'idpg_core' ), idpg_core_city_filter_url( 'tomorrow' ), 'tomorrow' === $idpg_f_fd );
					$idpg_pill_base( __( 'Уикенд', 'idpg_core' ), idpg_core_city_filter_url( 'weekend' ), 'weekend' === $idpg_f_fd );

 					// Дата: кастомный выпадающий календарь.
 					$idpg_date_base = class_exists( 'IDPG_City_URL' ) ? IDPG_City_URL::afisha_url() : home_url( '/afisha/' );
					?>
					<div class="idpg-fpill-wrap">
						<button type="button" class="idpg-fpill idpg-fpill--date<?php echo $idpg_is_date_filter ? ' is-active' : ''; ?>"
							id="idpg-date-toggle" aria-haspopup="true" aria-expanded="false"
							data-idpg-date-base="<?php echo esc_url( $idpg_date_base ); ?>">
							📅 <span id="idpg-date-label"><?php echo esc_html( $idpg_is_date_filter ? idpg_core_format_date( $idpg_f_fd ) : __( 'Выбрать дату', 'idpg_core' ) ); ?></span>
						</button>
						<div class="idpg-datedrop" id="idpg-datedrop"></div>
					</div>
					<a class="idpg-fpill idpg-fpill--free<?php echo $idpg_f_ff ? ' is-active' : ''; ?>"
					   href="<?php echo esc_url( $idpg_f_ff ? idpg_core_city_afisha_url() : idpg_core_city_filter_url( 'free' ) ); ?>">
						<span class="idpg-fpill__box" aria-hidden="true"></span>
						<?php esc_html_e( 'Бесплатные', 'idpg_core' ); ?>
					</a>
				</nav>

				<?php if ( ! have_posts() && ( $idpg_f_fd || $idpg_f_ff ) ) : ?>
					<div class="idpg-archive-header__empty">
						<?php esc_html_e( 'По этому фильтру событий нет. Попробуйте другую дату или сбросьте фильтр.', 'idpg_core' ); ?>
					</div>
				<?php endif; ?>
 			</header>

			<?php if ( have_posts() ) : ?>

				<div class="idpg-events-grid idpg-events-grid--3">
					<?php
					while ( have_posts() ) :
						the_post();

						get_template_part( 'template-parts/content', 'event' );

					endwhile;
					?>
				</div>

				<?php
				the_posts_pagination(
					array(
						'prev_text' => '&larr;',
						'next_text' => '&rarr;',
						'mid_size'  => 2,
					)
				);
				?>

			<?php else : ?>

				<?php get_template_part( 'template-parts/content', 'none' ); ?>

			<?php endif; ?>

		<?php idpg_widget_areas_render( 'below-content' ); ?>

	</div>
</main>

		<script src="<?php echo esc_url( get_template_directory_uri() . '/assets/js/pikaday.js' ); ?>"></script>
<script src="<?php echo esc_url( get_template_directory_uri() ); ?>/assets/js/pikaday-locale-ru.js"></script>
<script>
/* Pikaday — календарь фильтра даты афиши. */
(function () {
	'use strict';

	if (typeof Pikaday === 'undefined') return;

	var btn  = document.getElementById('idpg-date-toggle');
	var drop = document.getElementById('idpg-datedrop');
	if (!btn || !drop) return;

	var base   = (btn.getAttribute('data-idpg-date-base') || '').replace(/\/+$/, '');
	var selYmd = <?php echo wp_json_encode( $idpg_is_date_filter ? $idpg_f_fd : '' ); ?>;
	var today  = new Date();
	today.setHours(0, 0, 0, 0);

	var eventDates = {};

	function pad(n) { return (n < 10 ? '0' : '') + n; }

	function fetchDates(y, m, cb) {
		var key = y + '-' + pad(m);
		if (eventDates[key] !== undefined) { if (cb) cb(); return; }
		eventDates[key] = null;

		fetch('/wp-json/idpg/v1/event-dates?year=' + y + '&month=' + m)
			.then(function (r) { return r.json(); })
			.then(function (data) {
				eventDates[key] = {};
				(data.dates || []).forEach(function (d) { eventDates[key][d] = true; });
				if (cb) cb();
			})
			.catch(function () {
				eventDates[key] = {};
				if (cb) cb();
			});
	}

	function addDot(el, dateStr) {
		var key = dateStr.substring(0, 7);
		if (eventDates[key] && eventDates[key][dateStr]) {
			var dot = document.createElement('span');
			dot.className = 'idpg-pika-dot';
			el.appendChild(dot);
		}
	}

	function renderDots(inst, pikaEl) {
		if (!pikaEl) return;
		var d = inst.getDate();
		var y, m;
		if (d) {
			y = d.getFullYear();
			m = d.getMonth() + 1;
		} else {
			/* Дата ещё не выбрана — берём открытый месяц вида */
			var cal = (inst.calendars && inst.calendars[0]) ? inst.calendars[0] : null;
			y = cal ? cal.year : today.getFullYear();
			m = (cal ? cal.month : today.getMonth()) + 1;
		}
		fetchDates(y, m, function () {
			var cells = pikaEl.querySelectorAll('.pika-button');
			cells.forEach(function (cell) {
				var ds = cell.getAttribute('data-pika-day');
				var dm = cell.getAttribute('data-pika-month');
				var dy = cell.getAttribute('data-pika-year');
				if (ds && dm !== undefined && dy) {
					var dateStr = dy + '-' + pad(parseInt(dm, 10) + 1) + '-' + pad(parseInt(ds, 10));
					addDot(cell, dateStr);
				}
			});
		});
	}

	/* Инициализация Pikaday — календарь создаёт pika-single внутри drop */
	var pickedDate = '';

	var pik = new Pikaday({
		field: btn,
		container: drop,
		bound: false,
		format: 'YYYY-MM-DD',
		firstDay: 1,
		minDate: today,
		showWeekNumbers: false,
		showDaysInNextAndPreviousMonths: false,
		numberOfMonths: 1,
		i18n: (typeof l10n !== 'undefined') ? l10n : {
			months: ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'],
			monthsShort: ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'],
			weekdays: ['Воскресенье','Понедельник','Вторник','Среда','Четверг','Пятница','Суббота'],
			weekdaysShort: ['Вс','Пн','Вт','Ср','Чт','Пт','Сб'],
			previousMonth: 'Предыдущий месяц',
			nextMonth: 'Следующий месяц',
			today: 'Сегодня',
			clear: 'Очистить',
			close: 'Закрыть'
		},
		onOpen: function () {
			btn.setAttribute('aria-expanded', 'true');
			pickedDate = '';
			if (submitBtn) submitBtn.disabled = true;
		},
		onClose: function () {
			btn.setAttribute('aria-expanded', 'false');
		},
		onDraw: function (inst) {
			var pikaEl = drop.querySelector('.pika-single');
			renderDots(inst, pikaEl);
			if (pickedDate && submitBtn) {
				submitBtn.disabled = false;
			}
		},
		onSelect: function () {
			var d = this.getDate();
			pickedDate = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
			if (submitBtn) submitBtn.disabled = false;
		}
	});

	/* Контролы: сброс + подтверждение — добавляем ПОСЛЕ pika-single */
	var controlsWrap = document.createElement('div');
	controlsWrap.className = 'pika-controls';
	controlsWrap.innerHTML =
		'<button type="button" class="pika-controls__reset" id="idpg-pika-reset">Сбросить</button>' +
		'<button type="button" class="pika-controls__submit" id="idpg-pika-submit" disabled>Показать события</button>';
	drop.appendChild(controlsWrap);

	var resetBtn  = document.getElementById('idpg-pika-reset');
	var submitBtn = document.getElementById('idpg-pika-submit');

	/* Если уже установлен фильтр даты — открываем на нужном месяце */
	if (selYmd) {
		var parts = selYmd.split('-');
		if (parts.length === 3) {
			pik.setDate(new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10)));
		}
	}

	/* Открытие/закрытие */
	btn.addEventListener('click', function (e) {
		e.preventDefault();
		if (drop.classList.contains('is-open')) {
			pik.hide();
			drop.classList.remove('is-open');
		} else {
			pik.show();
			drop.classList.add('is-open');
		}
	});

	document.addEventListener('click', function (e) {
		if (drop.classList.contains('is-open') && !e.target.closest('.idpg-fpill-wrap')) {
			pik.hide();
			drop.classList.remove('is-open');
		}
	});

	/* Подтвердить — переход */
	submitBtn.addEventListener('click', function () {
		if (!pickedDate) return;
		window.location.href = base.replace(/\/+$/, '') + '/date/' + pickedDate + '/';
	});

	/* Сброс — убрать фильтр */
	resetBtn.addEventListener('click', function () {
		window.location.href = base + '/';
	});
})();
</script>

<?php get_footer(); ?>
