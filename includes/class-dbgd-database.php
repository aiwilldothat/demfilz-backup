<?php
/**
 * Database export, import and in-place search/replace.
 *
 * Everything here is designed to be called in small, resumable batches so a
 * large database never exhausts the PHP time or memory limit in one request.
 *
 * @package Demfilz_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure-PHP MySQL dump/restore helpers built on $wpdb.
 */
class DBGD_Database {

	/**
	 * Maximum bytes of INSERT data to buffer into one statement.
	 */
	const MAX_INSERT_BYTES = 800000;

	/**
	 * Safely quote a table or column identifier.
	 *
	 * @param string $name Identifier.
	 * @return string Backtick-quoted identifier.
	 */
	public static function quote_ident( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}

	/**
	 * Whether an identifier is a plain, safe table name.
	 *
	 * @param string $name Table name.
	 * @return bool
	 */
	public static function is_safe_table( $name ) {
		return (bool) preg_match( '/^[A-Za-z0-9_]+$/', (string) $name );
	}

	/**
	 * List every table in the current database.
	 *
	 * @return string[]
	 */
	public static function all_tables() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup needs the live table list.
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		return is_array( $tables ) ? $tables : array();
	}

	/**
	 * Number of rows in a table.
	 *
	 * @param string $table Table name.
	 * @return int
	 */
	public static function row_count( $table ) {
		global $wpdb;
		if ( ! self::is_safe_table( $table ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live row count; table name passed as a %i identifier placeholder.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	}

	/**
	 * Structure dump for a table (DROP + CREATE).
	 *
	 * @param string $table Table name.
	 * @return string SQL ending with newlines.
	 */
	public static function dump_structure( $table ) {
		global $wpdb;
		if ( ! self::is_safe_table( $table ) ) {
			return '';
		}
		$ident = self::quote_ident( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Reading schema; table name via %i placeholder.
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
		if ( ! is_array( $row ) || ! isset( $row[1] ) ) {
			return '';
		}
		$create = $row[1];
		// Drop AUTO_INCREMENT start value so restores are deterministic.
		$create = preg_replace( '/ AUTO_INCREMENT=\d+/i', '', $create );

		return 'DROP TABLE IF EXISTS ' . $ident . ";\n" . $create . ";\n";
	}

	/**
	 * Dump up to $limit rows of a table as INSERT statements.
	 *
	 * @param string $table  Table name.
	 * @param int    $offset Row offset.
	 * @param int    $limit  Max rows.
	 * @return array { string $sql, int $count }
	 */
	public static function dump_rows( $table, $offset, $limit ) {
		global $wpdb;
		if ( ! self::is_safe_table( $table ) ) {
			return array(
				'sql'   => '',
				'count' => 0,
			);
		}
		$ident  = self::quote_ident( $table );
		$offset = max( 0, (int) $offset );
		$limit  = max( 1, (int) $limit );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dumping rows; table via %i, limits via %d.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d, %d', $table, $offset, $limit ), ARRAY_A );
		if ( empty( $rows ) ) {
			return array(
				'sql'   => '',
				'count' => 0,
			);
		}

		$sql        = '';
		$buffer     = '';
		$buffer_len = 0;
		$prefix     = 'INSERT INTO ' . $ident . ' VALUES ';

		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $row as $value ) {
				if ( null === $value ) {
					$values[] = 'NULL';
				} else {
					$values[] = "'" . esc_sql( $value ) . "'";
				}
			}
			$tuple = '(' . implode( ',', $values ) . ')';

			if ( $buffer_len > 0 && ( $buffer_len + strlen( $tuple ) ) > self::MAX_INSERT_BYTES ) {
				$sql       .= $prefix . $buffer . ";\n";
				$buffer     = '';
				$buffer_len = 0;
			}
			$buffer     .= ( '' === $buffer ? '' : ',' ) . $tuple;
			$buffer_len += strlen( $tuple ) + 1;
		}
		if ( '' !== $buffer ) {
			$sql .= $prefix . $buffer . ";\n";
		}

		return array(
			'sql'   => $sql,
			'count' => count( $rows ),
		);
	}

	/**
	 * Run the preamble queries that make an import reliable.
	 */
	public static function import_preamble() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL -- Static session-setup statements.
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
		$wpdb->query( "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'" );
		// phpcs:enable
	}

	/**
	 * Run the cleanup queries after an import.
	 */
	public static function import_postamble() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL -- Static session-cleanup statement.
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
	}

	/**
	 * Split a chunk of SQL into complete statements, respecting quoting.
	 *
	 * Returns the complete statements found plus any trailing partial text that
	 * the caller should prepend to the next chunk. This lets a huge .sql file be
	 * imported a block at a time.
	 *
	 * @param string $sql   SQL text (may begin mid-nowhere but not mid-statement
	 *                       if the caller passes back $remainder each time).
	 * @param string $state By-ref parser state carried between calls.
	 * @return array { string[] $statements, string $remainder }
	 */
	public static function split_statements( $sql, &$state = null ) {
		if ( ! is_array( $state ) ) {
			$state = array(
				'in_string'   => false,
				'in_ident'    => false,
				'escape_next' => false,
			);
		}

		$statements = array();
		$current    = '';
		$len        = strlen( $sql );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $sql[ $i ];

			if ( $state['escape_next'] ) {
				$current             .= $ch;
				$state['escape_next'] = false;
				continue;
			}
			if ( $state['in_string'] && '\\' === $ch ) {
				$current             .= $ch;
				$state['escape_next'] = true;
				continue;
			}
			if ( "'" === $ch && ! $state['in_ident'] ) {
				$state['in_string'] = ! $state['in_string'];
				$current           .= $ch;
				continue;
			}
			if ( '`' === $ch && ! $state['in_string'] ) {
				$state['in_ident'] = ! $state['in_ident'];
				$current          .= $ch;
				continue;
			}
			if ( ';' === $ch && ! $state['in_string'] && ! $state['in_ident'] ) {
				$statements[] = $current;
				$current      = '';
				continue;
			}
			$current .= $ch;
		}

		return array(
			'statements' => $statements,
			'remainder'  => $current,
		);
	}

	/**
	 * Remap the leading table identifier of a statement to a new prefix.
	 *
	 * @param string $statement   One SQL statement.
	 * @param string $from_prefix Source table prefix.
	 * @param string $to_prefix   Target table prefix.
	 * @return string
	 */
	public static function swap_statement_prefix( $statement, $from_prefix, $to_prefix ) {
		if ( $from_prefix === $to_prefix || '' === $from_prefix ) {
			return $statement;
		}
		$pattern = '/^(\s*(?:CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|INSERT\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE|LOCK\s+TABLES)\s+`)' . preg_quote( $from_prefix, '/' ) . '/i';
		return preg_replace( $pattern, '${1}' . $to_prefix, $statement, 1 );
	}

	/**
	 * Execute one SQL statement, skipping comments/empties.
	 *
	 * @param string $statement Statement (no trailing semicolon needed).
	 * @return bool True if executed (or safely skipped).
	 */
	public static function run_statement( $statement ) {
		global $wpdb;
		$trimmed = trim( $statement );
		if ( '' === $trimmed ) {
			return true;
		}
		if ( 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '/*' ) || 0 === strpos( $trimmed, '#' ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Executing a statement from a SQL dump this plugin created; it cannot be parameterised.
		$result = $wpdb->query( $trimmed );
		if ( false === $result && '' !== $wpdb->last_error ) {
			DBGD_Logger::add( 'DB import error: ' . $wpdb->last_error, 'warning' );
			return false;
		}
		return true;
	}
}
