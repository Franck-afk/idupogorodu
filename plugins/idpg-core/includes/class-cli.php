<?php
/**
 * WP-CLI команды обслуживания (DEV-71).
 *
 * `wp idpg cleanup-subscribers` — удаление «мусорных» аккаунтов,
 * созданных регистрацией организатора, но так и не подтвердивших email.
 * Такие аккаунты живут с ролью `subscriber` (class-rest-auth.php:531),
 * потому что роль `event_organizer` выдаётся только после подтверждения.
 *
 * Безопасность:
 *   — по умолчанию команда НИЧЕГО НЕ УДАЛЯЕТ (режим dry-run);
 *   — реальное удаление включается только флагом `--force`;
 *   — удаляются ТОЛЬКО аккаунты, у которых роль `subscriber` — единственная.
 *     Любая другая роль (administrator, editor, event_organizer) исключает
 *     пользователя из выборки целиком;
 *   — аккаунты в pending-состоянии (зарегистрировался организатором, но
 *     не подтвердил email) НЕ трогаются: это живые заявки на регистрацию,
 *     а не мусор. Ключ отбора — IDPG_Organizer_Verify::META_PENDING.
 *
 * Отчёт содержит количество найденных, удалённых и пропущенных аккаунтов,
 * а также список ID — как того требует приёмка DEV-71.
 *
 * @package IDPG_Core
 */

// Запрет прямого доступа.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_CLI
 */
class IDPG_CLI {

	/**
	 * Роль «мусорного» аккаунта, ожидающего подтверждения.
	 */
	const TARGET_ROLE = 'subscriber';

	/**
	 * Собирает список аккаунтов, пригодных к удалению.
	 *
	 * Метод ничего не изменяет — пригоден для dry-run и для тестов.
	 *
	 * @return array[] Массив описаний: id, login, email, registered.
	 */
	public static function get_purge_candidates() {
		$candidates = array();

		$users = get_users(
			array(
				'role'   => self::TARGET_ROLE,
				'fields' => 'all',
				'number' => -1,
			)
		);

		foreach ( $users as $user ) {
			$user_id = (int) $user->ID;
			$roles   = (array) $user->roles;

			// Роль subscriber должна быть единственной. Мультиролевые
			// аккаунты (в т.ч. с event_organizer) не трогаем.
			if ( 1 !== count( $roles ) || self::TARGET_ROLE !== $roles[0] ) {
				continue;
			}

			// Pending-организатор — это живая заявка, а не мусор.
			if ( IDPG_Organizer_Verify::instance()->is_pending( $user_id ) ) {
				continue;
			}

			$candidates[] = array(
				'id'         => $user_id,
				'login'      => $user->user_login,
				'email'      => $user->user_email,
				'registered' => $user->user_registered,
			);
		}

		return $candidates;
	}

	/**
	 * Считает аккаунты в pending-состоянии среди носителей роли subscriber.
	 *
	 * Нужно для отчёта: их мы сохраняем осознанно, и владелец должен
	 * видеть это число, а не молчаливую потерю.
	 *
	 * @return int
	 */
	public static function count_pending_skipped() {
		$skipped = 0;

		$users = get_users(
			array(
				'role'   => self::TARGET_ROLE,
				'fields' => 'ID',
				'number' => -1,
			)
		);

		foreach ( $users as $user_id ) {
			if ( IDPG_Organizer_Verify::instance()->is_pending( (int) $user_id ) ) {
				++$skipped;
			}
		}

		return $skipped;
	}

	/**
	 * Удаляет найденные аккаунты либо только считает их (dry-run).
	 *
	 * @param bool $dry_run      true — ничего не удалять, только отчёт.
	 * @param int  $reassign_to  ID пользователя для переназначения контента;
	 *                          0 — не переназначать (контент удалится).
	 * @return array Отчёт: found, deleted, deleted_ids, reassign_to, dry_run.
	 */
	public static function cleanup( $dry_run = true, $reassign_to = 0 ) {
		$dry_run     = (bool) $dry_run;
		$reassign_to = (int) $reassign_to;

		$candidates = self::get_purge_candidates();

		$report = array(
			'dry_run'      => $dry_run,
			'reassign_to'  => $reassign_to,
			'found'        => count( $candidates ),
			'found_ids'    => wp_list_pluck( $candidates, 'id' ),
			'deleted'      => 0,
			'deleted_ids'  => array(),
			'pending_keep' => self::count_pending_skipped(),
		);

		// Dry-run: только отчёт, БД не трогаем.
		if ( $dry_run ) {
			return $report;
		}

		foreach ( $candidates as $candidate ) {
			$deleted = wp_delete_user( (int) $candidate['id'], $reassign_to );

			if ( $deleted ) {
				++$report['deleted'];
				$report['deleted_ids'][] = (int) $candidate['id'];
			}
		}

		return $report;
	}

	/**
	 * WP-CLI: `wp idpg cleanup-subscribers [--force] [--reassign-to=<id>]`
	 *
	 * Без `--force` команда работает в режиме dry-run и ничего не удаляет.
	 *
	 * @param array $args       Позиционные аргументы (не используются).
	 * @param array $assoc_args Именованные аргументы: force, reassign-to.
	 */
	public static function cli_cleanup_subscribers( $args, $assoc_args ) {
		$force       = ! empty( $assoc_args['force'] );
		$reassign_to = isset( $assoc_args['reassign-to'] ) ? (int) $assoc_args['reassign-to'] : 0;

		// Переназначать контент имеет смысл только на существующего пользователя.
		if ( $reassign_to && ! get_userdata( $reassign_to ) ) {
			WP_CLI::error(
				sprintf( 'Пользователь с ID %d не найден — переназначение невозможно.', $reassign_to )
			);
		}

		$report = self::cleanup( ! $force, $reassign_to );

		WP_CLI::log( '=== wp idpg cleanup-subscribers ===' );
		WP_CLI::log(
			sprintf(
				'Режим: %s',
				$report['dry_run']
					? 'DRY-RUN (ничего не удалено)'
					: 'РЕАЛЬНЫЙ ЗАПУСК (--force)'
			)
		);
		WP_CLI::log(
			sprintf(
				'Переназначение контента: %s',
				$report['reassign_to'] ? 'пользователь ID ' . $report['reassign_to'] : 'нет'
			)
		);
		WP_CLI::log( sprintf( 'Найдено аккаунтов: %d', $report['found'] ) );
		WP_CLI::log(
			sprintf(
				'Пропущено pending-организаторов: %d',
				$report['pending_keep']
			)
		);
		WP_CLI::log( sprintf( 'Удалено: %d', $report['deleted'] ) );
		WP_CLI::log(
			sprintf(
				'ID: %s',
				$report['found_ids'] ? implode( ', ', $report['found_ids'] ) : '—'
			)
		);

		if ( $report['dry_run'] ) {
			WP_CLI::success( 'Dry-run завершён. Повторите с --force для реального удаления.' );
			return;
		}

		WP_CLI::success( sprintf( 'Удалено аккаунтов: %d', $report['deleted'] ) );
	}
}

// Регистрация команды — только в контексте WP-CLI.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'idpg cleanup-subscribers',
		array( 'IDPG_CLI', 'cli_cleanup_subscribers' )
	);
}
