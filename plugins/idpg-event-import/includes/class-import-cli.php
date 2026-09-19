<?php
/**
 * WP-CLI команды модуля импорта событий.
 *
 * Примеры:
 *   wp idpgei import <source-id>   — импорт одного источника
 *   wp idpgei import --all         — все источники, кому пора
 *   wp idpgei sources              — список источников
 *   wp idpgei test <source-id>     — проверка endpoint без записи в БД
 *
 * @package Idpg_Event_Import
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPGEI_CLI_Commands
 */
class IDPGEI_CLI_Commands {

	/**
	 * Список источников.
	 *
	 * @subcommand sources
	 *
	 * @return void
	 */
	public function sources() {
		$sources = IDPG_Event_Import::instance()->get_sources();

		if ( empty( $sources ) ) {
			WP_CLI::line( 'Источников нет.' );
			return;
		}

		WP_CLI::line( sprintf( '%-20s %-6s %-8s %-10s %s', 'ID', 'Тип', 'Статус', 'Последний', 'Название' ) );

		foreach ( $sources as $sid => $source ) {
			WP_CLI::line( sprintf(
				'%-20s %-6s %-8s %-10s %s',
				$sid,
				$source['provider'],
				isset( $source['last_status'] ) ? $source['last_status'] : '-',
				! empty( $source['last_run'] ) ? gmdate( 'd.m H:i', (int) $source['last_run'] ) : '-',
				$source['name']
			) );
		}
	}

	/**
	 * Импорт источника (или всех, кому пора).
	 *
	 * ## OPTIONS
	 *
	 * [<source-id>]
	 * : ID источника. Пропустите и укажите --all для обработки всех «дозревших».
	 *
	 * [--all]
	 * : Обработать все источники независимо от расписания.
	 *
	 * [--force]
	 * : Игнорировать расписание (только с --all или source-id).
	 *
	 * ## EXAMPLES
	 *
	 *     wp idpgei import kassir
	 *     wp idpgei import --all --force
	 *
	 * @subcommand import
	 *
	 * @param array $args       Позиционные аргументы.
	 * @param array $assoc_args Именованные флаги.
	 * @return void
	 */
	public function import( $args, $assoc_args ) {
		$importer = IDPG_Event_Import::instance();
		$all    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );
		$force  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

		if ( ! empty( $args[0] ) ) {
			$this->import_one( $importer, $args[0], $force );
			return;
		}

		if ( ! $all ) {
			WP_CLI::error( 'Укажите <source-id> или --all.' );
		}

		foreach ( $importer->get_sources() as $sid => $source ) {
			if ( ! $force ) {
				$last_run = isset( $source['last_run'] ) ? (int) $source['last_run'] : 0;

				if ( time() < $last_run + (int) $source['schedule'] ) {
					WP_CLI::log( "Пропуск $sid: не время (расписание)." );
					continue;
				}
			}

			$this->import_one( $importer, $sid, $force );
		}
	}

	/**
	 * Импорт одного источника с выводом статистики.
	 *
	 * @param IDPG_Event_Import $importer   Импортёр.
	 * @param string             $source_id ID источника.
	 * @param bool               $force     Игнорировать расписание.
	 * @return void
	 */
	protected function import_one( $importer, $source_id, $force = false ) {
		WP_CLI::log( "Импорт источника: $source_id" );

		$result = $importer->process_source( $source_id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::warning( 'Ошибка: ' . $result->get_error_message() );
			return;
		}

		WP_CLI::success( sprintf(
			'новых: %d, обновлено: %d, пропущено: %d, ошибок: %d',
			$result['created'],
			$result['updated'],
			$result['skipped'],
			$result['errors']
		) );
	}

	/**
	 * Проверка источника без записи событий (только fetch + маппинг).
	 *
	 * ## OPTIONS
	 *
	 * <source-id>
	 * : ID источника.
	 *
	 * ## EXAMPLES
	 *
	 *     wp idpgei test kassir
	 *
	 * @param array $args Позиционные аргументы.
	 * @return void
	 */
	public function test( $args ) {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Укажите <source-id>.' );
		}

		$importer = IDPG_Event_Import::instance();
		$source   = $importer->get_source( $args[0] );

		if ( ! $source ) {
			WP_CLI::error( 'Источник не найден.' );
		}

		$provider = $importer->get_provider( $source );

		if ( is_wp_error( $provider ) ) {
			WP_CLI::error( $provider->get_error_message() );
		}

		$items = $provider->fetch_page( 1 );

		if ( is_wp_error( $items ) ) {
			WP_CLI::error( 'Fetch: ' . $items->get_error_message() );
		}

		WP_CLI::line( 'Получено элементов: ' . count( $items ) );

		$valid = 0;

		foreach ( array_slice( $items, 0, 3 ) as $raw ) {
			$mapped = IDPG_Import_Mapper::map_event( $raw, $source );

			if ( null === $mapped ) {
				WP_CLI::line( '- пропущен (нет title/date/external_id)' );
				continue;
			}

			$valid++;
			WP_CLI::line( sprintf(
				'- «%s» | %s | ext:%s | %s',
				mb_substr( $mapped['title'], 0, 40 ),
				$mapped['starts_at'],
				$mapped['external_id'],
				$mapped['buy_url'] ? 'билеты есть' : 'без билетов'
			) );
		}

		WP_CLI::success( "Из первых трёх валидны: $valid. Записи в БД не создавались." );
	}
}
