<?php
/**
 * Restore engine: takes a backup .zip (from Google Drive, an upload, or a local
 * copy), imports the database, rewrites every URL/path for the new site, and
 * extracts the files — all in resumable steps.
 *
 * Restore state is kept in a FILE, not the options table, because importing the
 * database replaces wp_options mid-run (and would otherwise wipe the job and log
 * the running admin out). A high-entropy token in that protected file authorises
 * each step after the database has been swapped.
 *
 * @package Demfilz_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateful, step-driven restorer.
 */
class DBGD_Restore {

	const READ_BLOCK = 2097152; // 2 MiB SQL read block.

	/**
	 * Path to the file-based restore state.
	 *
	 * @return string
	 */
	public static function state_file() {
		return dbgd_backup_dir() . 'restore-state.json';
	}

	/**
	 * Read the current restore state.
	 *
	 * @return array
	 */
	public static function get_state() {
		return dbgd_read_json_file( self::state_file() );
	}

	/**
	 * Persist the restore state.
	 *
	 * @param array $state State.
	 */
	public static function save_state( $state ) {
		$state['updated'] = time();
		dbgd_write_json_file( self::state_file(), $state );
	}

	/**
	 * Append a line to the restore log inside the state.
	 *
	 * @param array  $state By-ref state.
	 * @param string $msg   Message.
	 * @param string $level Level.
	 */
	private static function log( &$state, $msg, $level = 'info' ) {
		if ( ! isset( $state['log'] ) || ! is_array( $state['log'] ) ) {
			$state['log'] = array();
		}
		$state['log'][] = array(
			'time'  => time(),
			'level' => $level,
			'msg'   => $msg,
		);
		if ( count( $state['log'] ) > 300 ) {
			$state['log'] = array_slice( $state['log'], -300 );
		}
	}

	/**
	 * Begin a restore.
	 *
	 * @param array $args Restore arguments: source ('drive'|'local'|'upload'), drive_id, archive path, and new_url (target site URL).
	 * @return array|WP_Error State, or error.
	 */
	public static function start( $args ) {
		$dir = dbgd_prepare_backup_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$source  = isset( $args['source'] ) ? $args['source'] : 'local';
		$new_url = ! empty( $args['new_url'] ) ? untrailingslashit( esc_url_raw( $args['new_url'] ) ) : untrailingslashit( home_url() );

		$work_dir = $dir . 'restore-work/';
		if ( is_dir( $work_dir ) ) {
			dbgd_delete_path( $work_dir );
		}
		wp_mkdir_p( $work_dir );

		$archive = '';
		if ( 'drive' === $source ) {
			$archive = $work_dir . 'download.zip';
		} elseif ( ! empty( $args['archive'] ) && is_file( $args['archive'] ) ) {
			$archive = $args['archive'];
		}

		if ( 'drive' !== $source && '' === $archive ) {
			return new WP_Error( 'dbgd_no_archive', __( 'No backup file was provided to restore.', 'demfilz-backup' ) );
		}

		$state = array(
			'type'            => 'restore',
			'status'          => 'running',
			'stage'           => 'open',
			'token'           => wp_generate_password( 40, false, false ),
			'created'         => time(),
			'updated'         => time(),
			'source'          => $source,
			'drive_id'        => isset( $args['drive_id'] ) ? $args['drive_id'] : '',
			'drive_size'      => 0,
			'download_offset' => 0,
			'archive'         => $archive,
			'work_dir'        => $work_dir,
			'new_url'         => $new_url,
			'manifest'        => array(),
			'db'              => array(
				'sql_file'  => $work_dir . 'database.sql',
				'offset'    => 0,
				'remainder' => '',
				'parser'    => null,
				'done'      => 0,
			),
			'sr'              => array(
				'pairs'  => array(),
				'tables' => array(),
				'index'  => 0,
				'offset' => 0,
				'pk'     => array(),
				'cols'   => array(),
			),
			'files'           => array(
				'index' => 0,
				'count' => 0,
			),
			'progress'        => 0,
			'message'         => __( 'Preparing to restore…', 'demfilz-backup' ),
			'error'           => '',
			'log'             => array(),
		);

		if ( 'drive' === $source ) {
			$state['stage'] = 'download';
		}

		self::log( $state, __( 'Restore started.', 'demfilz-backup' ) );
		self::save_state( $state );
		return $state;
	}

	/**
	 * Read just the manifest out of a backup zip (no extraction), for cheap
	 * pre-flight checks like the Pro migration gate. Returns array (may be empty).
	 *
	 * @param string $archive Local zip path.
	 * @return array
	 */
	public static function peek_manifest( $archive ) {
		if ( '' === $archive || ! is_file( $archive ) ) {
			return array();
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive ) ) {
			return array();
		}
		$raw = $zip->getFromName( 'dbgd-manifest.json' );
		$zip->close();
		if ( false === $raw ) {
			return array();
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Advance the restore by one step.
	 *
	 * @return array State.
	 */
	public static function run_step() {
		$state = self::get_state();
		if ( empty( $state ) || 'restore' !== ( isset( $state['type'] ) ? $state['type'] : '' ) ) {
			return array(
				'status' => 'error',
				'error'  => __( 'No restore job is running.', 'demfilz-backup' ),
			);
		}
		if ( in_array( $state['status'], array( 'done', 'error' ), true ) ) {
			return $state;
		}

		try {
			switch ( $state['stage'] ) {
				case 'download':
					$state = self::step_download( $state );
					break;
				case 'open':
					$state = self::step_open( $state );
					break;
				case 'restore_db':
					$state = self::step_restore_db( $state );
					break;
				case 'search_replace':
					$state = self::step_search_replace( $state );
					break;
				case 'restore_files':
					$state = self::step_restore_files( $state );
					break;
				case 'finalize':
					$state = self::step_finalize( $state );
					break;
				case 'cleanup':
					$state = self::step_cleanup( $state );
					break;
				default:
					$state['status'] = 'done';
			}
		} catch ( Exception $e ) {
			$state['status'] = 'error';
			$state['error']  = $e->getMessage();
			self::log( $state, 'Restore error: ' . $e->getMessage(), 'error' );
		}

		self::save_state( $state );
		return $state;
	}

	/**
	 * Step: download the archive from Drive in chunks.
	 *
	 * @param array $state State.
	 * @return array
	 * @throws Exception When the download fails.
	 */
	private static function step_download( $state ) {
		if ( 0 === (int) $state['drive_size'] ) {
			$size = DBGD_GDrive::file_size( $state['drive_id'] );
			if ( is_wp_error( $size ) ) {
				throw new Exception( esc_html( $size->get_error_message() ) );
			}
			$state['drive_size'] = (int) $size;
			// Start a fresh file.
			dbgd_write_file( $state['archive'], '' );
		}

		$total  = (int) $state['drive_size'];
		$offset = (int) $state['download_offset'];
		if ( $offset >= $total ) {
			$state['stage']    = 'open';
			$state['progress'] = 20;
			return $state;
		}

		$length = min( DBGD_GDrive::CHUNK, $total - $offset );
		$data   = DBGD_GDrive::download_chunk( $state['drive_id'], $offset, $length );
		if ( is_wp_error( $data ) ) {
			throw new Exception( esc_html( $data->get_error_message() ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Appending a downloaded chunk to the local archive.
		file_put_contents( $state['archive'], $data, FILE_APPEND );
		$state['download_offset'] = $offset + strlen( $data );

		$pct               = $total > 0 ? ( $state['download_offset'] / $total ) : 1;
		$state['progress'] = (int) min( 19, round( $pct * 19 ) );
		$state['message']  = sprintf( /* translators: 1: downloaded, 2: total. */ __( 'Downloading backup (%1$s of %2$s)…', 'demfilz-backup' ), size_format( $state['download_offset'] ), size_format( $total ) );
		if ( $state['download_offset'] >= $total ) {
			$state['stage'] = 'open';
			self::log( $state, __( 'Download complete.', 'demfilz-backup' ), 'success' );
		}
		return $state;
	}

	/**
	 * Step: open the archive, read the manifest, extract database.sql.
	 *
	 * @param array $state State.
	 * @return array
	 * @throws Exception When the archive is invalid.
	 */
	private static function step_open( $state ) {
		if ( ! is_file( $state['archive'] ) ) {
			throw new Exception( esc_html__( 'Backup archive not found.', 'demfilz-backup' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $state['archive'] ) ) {
			throw new Exception( esc_html__( 'Could not open the backup archive. It may be incomplete or not a Demfilz backup.', 'demfilz-backup' ) );
		}

		$manifest_raw = $zip->getFromName( 'dbgd-manifest.json' );
		if ( false === $manifest_raw ) {
			$zip->close();
			throw new Exception( esc_html__( 'This zip is not a Demfilz backup (no manifest found).', 'demfilz-backup' ) );
		}
		$manifest          = json_decode( $manifest_raw, true );
		$state['manifest'] = is_array( $manifest ) ? $manifest : array();

		// Extract the SQL dump (if present) to disk for streaming import.
		if ( false !== $zip->locateName( 'database.sql' ) ) {
			$stream = $zip->getStream( 'database.sql' );
			if ( $stream ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming the SQL dump out of the archive.
				$out = fopen( $state['db']['sql_file'], 'wb' );
				if ( $out ) {
					// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard copy loop.
					while ( ! feof( $stream ) ) {
						fwrite( $out, fread( $stream, 1048576 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming copy.
					}
					fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming copy.
				}
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming copy.
			}
		} else {
			$state['db']['done'] = 1; // No database in this backup.
		}

		// Count file entries for progress.
		$file_entries = 0;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive core property.
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( 0 === strpos( $name, 'files/' ) && '/' !== substr( $name, -1 ) ) {
				++$file_entries;
			}
		}
		$state['files']['count'] = $file_entries;
		$zip->close();

		// Resolve source URL/path → target URL/path.
		$old_url              = isset( $state['manifest']['site_url'] ) ? untrailingslashit( $state['manifest']['site_url'] ) : '';
		$state['old_url']     = $old_url;
		$state['old_home']    = isset( $state['manifest']['home_url'] ) ? untrailingslashit( $state['manifest']['home_url'] ) : $old_url;
		$state['old_prefix']  = isset( $state['manifest']['table_prefix'] ) ? $state['manifest']['table_prefix'] : '';
		$state['old_abspath'] = isset( $state['manifest']['abspath'] ) ? untrailingslashit( $state['manifest']['abspath'] ) : '';

		self::log( $state, sprintf( /* translators: 1: source URL, 2: target URL. */ __( 'Restoring from %1$s to %2$s.', 'demfilz-backup' ), $old_url ? $old_url : '(unknown)', $state['new_url'] ) );

		// Restoring onto a DIFFERENT URL (site migration) is handled by the separate
		// "Demfilz Backup Pro" add-on, which registers the dbgd_can_migrate filter.
		// This core plugin restores a backup onto its own site. The check is here —
		// after the manifest is read but BEFORE any destructive database import — so
		// a backup from another URL changes nothing when the add-on isn't present.
		if ( dbgd_is_url_migration( $state['manifest'], $state['new_url'] ) && ! dbgd_can_migrate() ) {
			throw new Exception(
				esc_html(
					sprintf(
						/* translators: 1: source URL, 2: target URL. */
						__( 'This backup is from %1$s, but you are restoring to %2$s. Restoring onto a different URL (site migration) is provided by the Demfilz Backup Pro add-on. Restore this backup onto its original URL, or install the add-on to migrate.', 'demfilz-backup' ),
						$old_url,
						untrailingslashit( $state['new_url'] )
					)
				)
			);
		}

		$state['stage']    = ! empty( $state['db']['done'] ) ? 'restore_files' : 'restore_db';
		$state['progress'] = 22;
		$state['message']  = __( 'Importing database…', 'demfilz-backup' );
		return $state;
	}

	/**
	 * Step: import one block of the SQL dump.
	 *
	 * @param array $state State.
	 * @return array
	 * @throws Exception When the dump cannot be read.
	 */
	private static function step_restore_db( $state ) {
		global $wpdb;
		$sql_file = $state['db']['sql_file'];
		if ( ! is_file( $sql_file ) ) {
			$state['stage'] = 'search_replace';
			return $state;
		}

		// First block: run the preamble once.
		if ( 0 === (int) $state['db']['offset'] ) {
			DBGD_Database::import_preamble();
			if ( ! empty( $state['manifest']['charset'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Charset value sanitised below.
				$wpdb->query( $wpdb->prepare( 'SET NAMES %s', preg_replace( '/[^a-z0-9_]/i', '', $state['manifest']['charset'] ) ) );
			}
		}

		$size    = filesize( $sql_file );
		$offset  = (int) $state['db']['offset'];
		$to_pref = $wpdb->prefix;
		$from    = $state['old_prefix'];

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming the SQL dump.
		$handle = fopen( $sql_file, 'rb' );
		if ( false === $handle ) {
			throw new Exception( esc_html__( 'Could not read the database dump.', 'demfilz-backup' ) );
		}
		fseek( $handle, $offset );
		$block = fread( $handle, self::READ_BLOCK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming the SQL dump.
		$read  = strlen( $block );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming dump.

		// The stored remainder is always a clean partial statement (it begins at
		// a top-level boundary), so re-parsing remainder + new block from a fresh
		// state recomputes the quote state correctly. Do NOT also carry parser
		// state — that would double-count the remainder.
		$buffer = $state['db']['remainder'] . $block;
		$result = DBGD_Database::split_statements( $buffer );

		foreach ( $result['statements'] as $statement ) {
			$statement = DBGD_Database::swap_statement_prefix( $statement, $from, $to_pref );
			DBGD_Database::run_statement( $statement );
		}

		$state['db']['remainder'] = $result['remainder'];
		$state['db']['offset']    = $offset + $read;

		$eof = ( $offset + $read ) >= $size;
		if ( $eof ) {
			// Flush any trailing statement.
			$last = DBGD_Database::swap_statement_prefix( $result['remainder'], $from, $to_pref );
			DBGD_Database::run_statement( $last );
			DBGD_Database::import_postamble();
			$state['db']['remainder'] = '';
			$state['db']['done']      = 1;
			$state['stage']           = 'search_replace';
			$state['progress']        = 55;
			$state['message']         = __( 'Rewriting site URLs…', 'demfilz-backup' );
			self::log( $state, __( 'Database imported.', 'demfilz-backup' ), 'success' );
		} else {
			$pct               = $size > 0 ? ( $state['db']['offset'] / $size ) : 1;
			$state['progress'] = (int) min( 54, 22 + round( $pct * 32 ) );
			$state['message']  = sprintf( /* translators: %d: percent. */ __( 'Importing database (%d%%)…', 'demfilz-backup' ), (int) round( $pct * 100 ) );
		}
		return $state;
	}

	/**
	 * Step: URL/path rewriting for a site migration.
	 *
	 * This core plugin restores a backup onto its own site, where no URL rewriting
	 * is required. Rewriting the site URL for a restore onto a DIFFERENT URL is
	 * provided by the separate "Demfilz Backup Pro" add-on, which handles this
	 * stage through the dbgd_restore_migrate_step filter. When restoring onto the
	 * same URL, there is nothing to rewrite and we move straight on.
	 *
	 * @param array $state State.
	 * @return array
	 * @throws Exception When a cross-URL restore is reached without the add-on.
	 */
	private static function step_search_replace( $state ) {
		if ( ! dbgd_is_url_migration( $state['manifest'], $state['new_url'] ) ) {
			$state['stage']    = 'restore_files';
			$state['progress'] = 70;
			$state['message']  = __( 'Restoring files…', 'demfilz-backup' );
			return $state;
		}

		/**
		 * Let the Demfilz Backup Pro add-on perform one batch of URL rewriting and
		 * advance the restore. It returns the updated state (staying in this stage
		 * until done, then moving to restore_files).
		 *
		 * @param array|null $handled Null if unhandled; updated state array if handled.
		 * @param array      $state   Current restore state.
		 */
		$handled = apply_filters( 'dbgd_restore_migrate_step', null, $state );
		if ( is_array( $handled ) ) {
			return $handled;
		}

		// A cross-URL restore was reached without the add-on (it should have been
		// blocked at step_open). Fail safe rather than leave old URLs in place.
		throw new Exception( esc_html__( 'Restoring onto a different URL requires the Demfilz Backup Pro add-on.', 'demfilz-backup' ) );
	}

	/**
	 * Step: extract one batch of files from the archive into place.
	 *
	 * @param array $state State.
	 * @return array
	 * @throws Exception When the archive cannot be opened.
	 */
	private static function step_restore_files( $state ) {
		$count = (int) $state['files']['count'];
		if ( 0 === $count ) {
			$state['stage']    = 'finalize';
			$state['progress'] = 90;
			return $state;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $state['archive'] ) ) {
			throw new Exception( esc_html__( 'Could not open the backup archive.', 'demfilz-backup' ) );
		}

		$abspath = wp_normalize_path( trailingslashit( ABSPATH ) );
		$batch   = max( 25, (int) dbgd_get_option( 'chunk_files' ) );
		$self    = 'files/wp-content/plugins/' . dirname( DBGD_BASENAME ) . '/';
		$done    = 0;
		$seen    = 0;

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive core property.
		for ( $i = 0; $i < $zip->numFiles && $done < $batch; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( 0 !== strpos( $name, 'files/' ) || '/' === substr( $name, -1 ) ) {
				continue;
			}
			if ( $seen < (int) $state['files']['index'] ) {
				++$seen;
				continue;
			}
			++$seen;
			++$done;

			// Never overwrite the running plugin or wp-config.php.
			if ( 0 === strpos( $name, $self ) || 'files/wp-config.php' === $name ) {
				continue;
			}

			$rel    = substr( $name, strlen( 'files/' ) );
			$target = $abspath . $rel;
			$dir    = dirname( $target );
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			$stream = $zip->getStream( $name );
			if ( $stream ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- Restoring backed-up files into the WordPress install root is the whole purpose of a restore.
				$out = fopen( $target, 'wb' );
				if ( $out ) {
					// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard copy loop.
					while ( ! feof( $stream ) ) {
						fwrite( $out, fread( $stream, 262144 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fread, PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- Streaming restored file content into the WordPress root.
					}
					fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming copy.
				}
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming copy.
			}
		}
		$zip->close();

		$state['files']['index'] = $seen;
		if ( $seen >= $count ) {
			$state['stage']    = 'finalize';
			$state['progress'] = 90;
			$state['message']  = __( 'Finishing up…', 'demfilz-backup' );
			self::log( $state, sprintf( /* translators: %d: number of files. */ __( 'Restored %d files.', 'demfilz-backup' ), $count ) );
		} else {
			$pct               = $count > 0 ? ( $seen / $count ) : 1;
			$state['progress'] = (int) min( 89, 70 + round( $pct * 19 ) );
			$state['message']  = sprintf( /* translators: 1: files done, 2: total. */ __( 'Restoring files (%1$d of %2$d)…', 'demfilz-backup' ), min( $seen, $count ), $count );
		}
		return $state;
	}

	/**
	 * Step: fix core options, flush caches and rewrite rules.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function step_finalize( $state ) {
		global $wpdb;
		$new_url = $state['new_url'];

		// Force the canonical URLs regardless of what the import contained.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Options table was just replaced; bypass cache.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'siteurl'", $new_url ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'home'", $new_url ) );
		// Drop cached rewrite rules so permalinks regenerate on the new domain.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'rewrite_rules'" );
		// phpcs:enable

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		$state['stage']    = 'cleanup';
		$state['progress'] = 96;
		$state['message']  = __( 'Cleaning up…', 'demfilz-backup' );
		self::log( $state, sprintf( /* translators: %s: new site URL. */ __( 'Site URL set to %s.', 'demfilz-backup' ), $new_url ), 'success' );
		return $state;
	}

	/**
	 * Step: remove working files and finish.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function step_cleanup( $state ) {
		if ( ! empty( $state['work_dir'] ) && is_dir( $state['work_dir'] ) ) {
			dbgd_delete_path( $state['work_dir'] );
		}
		$state['status']   = 'done';
		$state['stage']    = 'done';
		$state['progress'] = 100;
		$state['message']  = __( 'Restore complete. Log in again on the new site if needed.', 'demfilz-backup' );
		self::log( $state, __( 'Restore complete.', 'demfilz-backup' ), 'success' );
		return $state;
	}
}
