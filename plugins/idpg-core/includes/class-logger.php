<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Имя файла по конвенции плагина (class-<класс без префикса>.php), как у остальных классов.
/**
 * Минимальный журнал плагина: кольцевой буфер последних событий.
 *
 * Хранит последние LOG_LIMIT записей в отдельной опции (autoload = false),
 * чтобы журнал не грузился на каждом запросе. Файловый лог и error_log()
 * в боевом коде запрещены (AGENTS.md, п. 11).
 *
 * @package IDPG_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс IDPG_Logger
 *
 * Статический API: log() — запись, get_recent() — чтение, clear() — очистка.
 */
class IDPG_Logger {

	/**
	 * Имя опции журнала.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'idpg_log_recent';

	/**
	 * Максимум записей в буфере.
	 *
	 * @var int
	 */
	const LOG_LIMIT = 100;

	/**
	 * Максимальная длина сообщения (символов).
	 *
	 * @var int
	 */
	const MESSAGE_MAX_LENGTH = 500;

	/**
	 * Допустимые уровни записи.
	 *
	 * @var string[]
	 */
	const LEVELS = array( 'info', 'warning', 'error' );

	/**
	 * ЗАГЛУШКА: источник данных журнала задаётся только этой логикой,
	 * подключение полноценного мониторинга — вне задачи DEV-02.
	 *
	 * Запись события в журнал.
	 *
	 * @param string $source  Канал (например, `ticket-sync`).
	 * @param string $message Текст события.
	 * @param string $level   Уровень: info|warning|error.
	 */
	public static function log( $source, $message, $level = 'info' ) {
		if ( ! in_array( $level, self::LEVELS, true ) ) {
			$level = 'info';
		}

		$message = trim( wp_strip_all_tags( (string) $message ) );

		if ( '' === $message ) {
			return;
		}

		if ( function_exists( 'mb_substr' ) ) {
			$message = mb_substr( $message, 0, self::MESSAGE_MAX_LENGTH );
		} else {
			$message = substr( $message, 0, self::MESSAGE_MAX_LENGTH );
		}

		$entries   = self::get_recent( self::LOG_LIMIT );
		$entries[] = array(
			'time'    => current_time( 'mysql' ),
			'level'   => $level,
			'source'  => sanitize_key( $source ),
			'message' => $message,
		);

		if ( count( $entries ) > self::LOG_LIMIT ) {
			$entries = array_slice( $entries, - self::LOG_LIMIT );
		}

		update_option( self::OPTION_NAME, $entries, false );
	}

	/**
	 * Последние записи журнала.
	 *
	 * @param int $limit Сколько последних записей вернуть.
	 * @return array[] Записи: time, level, source, message.
	 */
	public static function get_recent( $limit = 10 ) {
		$entries = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $entries ) ) {
			return array();
		}

		$limit = absint( $limit );

		if ( $limit < 1 ) {
			return array();
		}

		if ( count( $entries ) > $limit ) {
			$entries = array_slice( $entries, - $limit );
		}

		return array_values( $entries );
	}

	/**
	 * Полная очистка журнала.
	 */
	public static function clear() {
		update_option( self::OPTION_NAME, array(), false );
	}
}
