<?php
/**
 * Lightweight ring-buffer logger for the current/last job.
 *
 * @package Demfilz_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores recent log lines in an option so the admin UI can show progress.
 */
class DBGD_Logger {

	const OPTION = 'dbgd_log';
	const MAX    = 300;

	/**
	 * Append a log line.
	 *
	 * @param string $message Human-readable message.
	 * @param string $level   info|warning|error|success.
	 */
	public static function add( $message, $level = 'info' ) {
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			'time'  => time(),
			'level' => $level,
			'msg'   => (string) $message,
		);
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -self::MAX );
		}
		update_option( self::OPTION, $log, false );
	}

	/**
	 * Get all log entries.
	 *
	 * @return array
	 */
	public static function all() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Clear the log (typically at the start of a new job).
	 */
	public static function clear() {
		update_option( self::OPTION, array(), false );
	}
}
