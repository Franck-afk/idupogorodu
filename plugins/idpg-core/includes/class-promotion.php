<?php
/**
 * Продвижение события: закрепление в топе с ограничением по сроку.
 *
 * DEV-10. Раньше галочка «Закрепить в топе» ставила `_idpg_promoted = 'yes'`
 * навсегда: снять её мог только человек в админке, и забытое закрепление
 * держало событие в подборках неограниченно долго. Теперь рядом с галочкой
 * живёт дата «Закрепить до», а ежедневная задача снимает закрепление, когда
 * дата прошла.
 *
 * Пустая дата означает бессрочное закрепление — это осознанный режим для
 * платных размещений без срока, и cron его не трогает.
 *
 * @package idpg-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IDPG_Promotion
 */
class IDPG_Promotion {

	/**
	 * Хук ежедневной задачи.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'idpg_cron_unpin_expired';

	/**
	 * Метка факта закрепления.
	 *
	 * @var string
	 */
	const META_PROMOTED = '_idpg_promoted';

	/**
	 * Метка даты окончания закрепления (Y-m-d).
	 *
	 * @var string
	 */
	const META_EXPIRES = '_idpg_pin_expires';

	/**
	 * Сколько событий обрабатывать за одну итерацию.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 200;

	/**
	 * Предохранитель от бесконечного цикла при очень большом числе событий.
	 *
	 * @var int
	 */
	const MAX_PASSES = 50;

	/**
	 * Экземпляр-синглтон.
	 *
	 * @var IDPG_Promotion|null
	 */
	private static $instance = null;

	/**
	 * Сколько событий снято при последнем запуске задачи.
	 *
	 * @var int
	 */
	private $last_unpinned = 0;

	/**
	 * Получение экземпляра синглтона.
	 *
	 * @return IDPG_Promotion
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор.
	 */
	private function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'unpin_expired' ) );
	}

	/* ---------------------------------------------------------------------
	 * Состояние закрепления
	 * ------------------------------------------------------------------ */

	/**
	 * Текущая дата в зоне сайта.
	 *
	 * Сравнение идёт по дате сайта, а не по UTC: иначе ночью закрепление
	 * снималось бы на сутки позже, чем указал администратор.
	 *
	 * @return string Дата в формате Y-m-d.
	 */
	public static function today() {
		return current_time( 'Y-m-d' );
	}

	/**
	 * Закреплено ли событие (без учёта срока).
	 *
	 * @param int $post_id ID события.
	 * @return bool
	 */
	public static function is_promoted( $post_id ) {
		return 'yes' === get_post_meta( (int) $post_id, self::META_PROMOTED, true );
	}

	/**
	 * Дата окончания закрепления.
	 *
	 * @param int $post_id ID события.
	 * @return string Дата Y-m-d или пустая строка.
	 */
	public static function get_pin_expires( $post_id ) {
		return (string) get_post_meta( (int) $post_id, self::META_EXPIRES, true );
	}

	/**
	 * Истекло ли закрепление по сроку.
	 *
	 * Пустая дата — не истекло (бессрочное закрепление).
	 *
	 * @param int $post_id ID события.
	 * @return bool
	 */
	public static function is_expired( $post_id ) {
		$expires = self::get_pin_expires( $post_id );

		if ( '' === $expires ) {
			return false;
		}

		// Дата окончания включительно: закрепление, заданное на сегодня,
		// действует весь сегодняшний день и снимается завтра.
		return $expires < self::today();
	}

	/**
	 * Действует ли закрепление сейчас.
	 *
	 * Это то, что должны спрашивать выдачи событий. Проверять только
	 * `_idpg_promoted` недостаточно: между запусками cron событие может
	 * формально висеть с прошедшей датой.
	 *
	 * @param int $post_id ID события.
	 * @return bool
	 */
	public static function is_active( $post_id ) {
		if ( ! self::is_promoted( $post_id ) ) {
			return false;
		}

		return ! self::is_expired( $post_id );
	}

	/* ---------------------------------------------------------------------
	 * Cron
	 * ------------------------------------------------------------------ */

	/**
	 * Ставит ежедневную задачу в расписание.
	 *
	 * Повторный вызов не дублирует событие.
	 */
	public function schedule_unpin() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Убирает задачу из расписания.
	 */
	public function unschedule_unpin() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		// Событие могло задваироваться (например, после двух активаций),
		// поэтому снимаем все вхождения, а не только первое.
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Снимает закрепление у событий, срок которых прошёл.
	 *
	 * Идемпотентна: обрабатываются только события, у которых закрепление ещё
	 * стоит, а дата прошла. Повторный запуск ничего не делает и не падает.
	 *
	 * @return int Сколько событий снято.
	 */
	public function unpin_expired() {
		$this->last_unpinned = 0;
		$unpinned           = 0;
		$pass               = 0;

		do {
			$query = new WP_Query(
				array(
					'post_type'      => 'event',
					'post_status'    => 'any',
					'posts_per_page' => self::BATCH_SIZE,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					// Плановый обход всех статусов: снять закрепление нужно и
					// у черновика, и у события на модерации.
					'suppress_filters' => false,
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'key'   => self::META_PROMOTED,
							'value' => 'yes',
						),
						array(
							'key'     => self::META_EXPIRES,
							'value'   => self::today(),
							'compare' => '<',
							'type'    => 'DATE',
						),
					),
				)
			);

			$found = count( $query->posts );

			foreach ( $query->posts as $post_id ) {
				// Снимаем только факт закрепления. Дату окончания сохраняем:
				// она нужна для истории и для того, чтобы повторное закрепление
				// без новой даты честно снялось на следующем же запуске.
				delete_post_meta( (int) $post_id, self::META_PROMOTED );

				$unpinned++;

				/**
				 * Событие потеряло закрепление из-за истёкшего срока.
				 *
				 * @param int $post_id ID события.
				 */
				do_action( 'idpg_event_unpinned', (int) $post_id );
			}

			$pass++;
		} while ( self::BATCH_SIZE === $found && $pass < self::MAX_PASSES );

		$this->last_unpinned = $unpinned;

		return $unpinned;
	}

	/**
	 * Сколько событий снято при последнем запуске.
	 *
	 * @return int
	 */
	public function get_last_unpinned() {
		return $this->last_unpinned;
	}
}
