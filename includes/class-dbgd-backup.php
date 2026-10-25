<?php
/**
 * Backup engine: builds a single .zip (database + files + manifest) in small,
 * resumable steps, then uploads it to Google Drive.
 *
 * @package Demfilz_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateful, step-driven backup builder.
 */
class DBGD_Backup {

	/**
	 * Directory/file fragments never included in a backup.
	 *
	 * @return string[]
	 */
	public static function hard_excludes() {
		return array(
			'/dbgd-backups/',          // Our own backups.
			'/wp-content/cache/',
			'/wp-content/upgrade/',
			'/wp-content/uploads/cache/',
			'/.git/',
			'/node_modules/',
			'/.DS_Store',
		);
	}

	/**
	 * Create a fresh backup job and store it.
	 *
	 * @param string $destination 'drive' or 'local'.
	 * @return array|WP_Error The job array.
	 */
	public static function start( $destination = 'drive' ) {
		if ( 'drive' === $destination && ! DBGD_GDrive::is_connected() ) {
			return new WP_Error( 'dbgd_not_connected', __( 'Connect Google Drive before backing up to Drive.', 'demfilz-backup' ) );
		}

		$dir = dbgd_prepare_backup_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		DBGD_Logger::clear();
		DBGD_Logger::add( __( 'Backup started.', 'demfilz-backup' ), 'info' );

		$stamp    = gmdate( 'Ymd-His' );
		$host     = preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$host     = '' === $host ? 'site' : $host;
		$job_id   = $stamp . '-' . wp_generate_password( 6, false, false );
		$work_dir = $dir . 'work-' . $job_id . '/';
		wp_mkdir_p( $work_dir );

		$job = array(
			'type'         => 'backup',
			'id'           => $job_id,
			'status'       => 'running',
			'stage'        => 'init',
			'destination'  => ( 'local' === $destination ? 'local' : 'drive' ),
			'created'      => time(),
			'updated'      => time(),
			'work_dir'     => $work_dir,
			'archive'      => $dir . $host . '-' . $stamp . '.zip',
			'archive_name' => $host . '-' . $stamp . '.zip',
			'sql_file'     => $work_dir . 'database.sql',
			'list_file'    => $work_dir . 'files.list',
			'includes'     => array(
				'database' => (int) dbgd_get_option( 'include_database' ),
				'uploads'  => (int) dbgd_get_option( 'include_uploads' ),
				'themes'   => (int) dbgd_get_option( 'include_themes' ),
				'plugins'  => (int) dbgd_get_option( 'include_plugins' ),
				'content'  => (int) dbgd_get_option( 'include_content' ),
				'core'     => (int) dbgd_get_option( 'include_core' ),
			),
			'db'           => array(
				'tables' => array(),
				'index'  => 0,
				'offset' => 0,
			),
			'files'        => array(
				'count' => 0,
				'index' => 0,
			),
			'upload'       => array(
				'session' => '',
				'offset'  => 0,
				'total'   => 0,
				'file_id' => '',
			),
			'progress'     => 0,
			'message'      => __( 'Preparing…', 'demfilz-backup' ),
			'error'        => '',
		);

		update_option( DBGD_OPT_JOB, $job, false );
		return $job;
	}

	/**
	 * Advance the current job by one step.
	 *
	 * @return array The (possibly updated) job.
	 */
	public static function run_step() {
		$job = get_option( DBGD_OPT_JOB, array() );
		if ( ! is_array( $job ) || 'backup' !== ( isset( $job['type'] ) ? $job['type'] : '' ) ) {
			return array(
				'status' => 'error',
				'error'  => __( 'No backup job is running.', 'demfilz-backup' ),
			);
		}
		if ( in_array( $job['status'], array( 'done', 'error' ), true ) ) {
			return $job;
		}

		try {
			switch ( $job['stage'] ) {
				case 'init':
					$job = self::step_init( $job );
					break;
				case 'database':
					$job = self::step_database( $job );
					break;
				case 'files_list':
					$job = self::step_files_list( $job );
					break;
				case 'files_zip':
					$job = self::step_files_zip( $job );
					break;
				case 'finalize':
					$job = self::step_finalize( $job );
					break;
				case 'upload':
					$job = self::step_upload( $job );
					break;
				case 'prune':
					$job = self::step_prune( $job );
					break;
				case 'cleanup':
					$job = self::step_cleanup( $job );
					break;
				default:
					$job['status'] = 'done';
			}
		} catch ( Exception $e ) {
			$job['status'] = 'error';
			$job['error']  = $e->getMessage();
			DBGD_Logger::add( 'Backup error: ' . $e->getMessage(), 'error' );
		}

		$job['updated'] = time();
		update_option( DBGD_OPT_JOB, $job, false );
		return $job;
	}

	/**
	 * Step: initialise manifest, table list and the empty archive.
	 *
	 * @param array $job Job.
	 * @return array
	 * @throws Exception When the archive cannot be created.
	 */
	private static function step_init( $job ) {
		$tables              = $job['includes']['database'] ? DBGD_Database::all_tables() : array();
		$job['db']['tables'] = $tables;

		global $wpdb;
		$manifest        = array(
			'generator'    => 'demfilz-backup',
			'version'      => DBGD_VERSION,
			'format'       => 1,
			'created'      => gmdate( 'c' ),
			'site_url'     => site_url(),
			'home_url'     => home_url(),
			'abspath'      => wp_normalize_path( ABSPATH ),
			'content_dir'  => wp_normalize_path( WP_CONTENT_DIR ),
			'wp_version'   => get_bloginfo( 'version' ),
			'php_version'  => PHP_VERSION,
			'table_prefix' => $wpdb->prefix,
			'charset'      => $wpdb->charset ? $wpdb->charset : 'utf8',
			'multisite'    => is_multisite() ? 1 : 0,
			'includes'     => $job['includes'],
			'tables'       => $tables,
		);
		$job['manifest'] = $manifest;

		// Create an empty archive up front.
		$zip = new ZipArchive();
		if ( true !== $zip->open( $job['archive'], ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new Exception( esc_html__( 'Could not create the backup archive.', 'demfilz-backup' ) );
		}
		$zip->addFromString( 'dbgd-readme.txt', "Demfilz Backup\nRestore this archive from the plugin's Restore screen.\n" );
		$zip->close();

		// Reset the SQL file.
		dbgd_write_file( $job['sql_file'], "-- Demfilz Backup database export\n" );

		$job['stage']    = $job['includes']['database'] ? 'database' : 'files_list';
		$job['progress'] = 3;
		$job['message']  = __( 'Exporting database…', 'demfilz-backup' );
		DBGD_Logger::add( sprintf( /* translators: %d: number of tables. */ __( 'Found %d database tables.', 'demfilz-backup' ), count( $tables ) ), 'info' );
		return $job;
	}

	/**
	 * Step: dump one batch of database rows.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	private static function step_database( $job ) {
		$tables = $job['db']['tables'];
		$index  = (int) $job['db']['index'];
		$offset = (int) $job['db']['offset'];
		$limit  = max( 200, (int) dbgd_get_option( 'chunk_rows' ) );

		if ( $index >= count( $tables ) ) {
			$job['stage']    = 'files_list';
			$job['progress'] = 35;
			$job['message']  = __( 'Building the file list…', 'demfilz-backup' );
			return $job;
		}

		$table = $tables[ $index ];
		$sql   = '';
		if ( 0 === $offset ) {
			$sql .= "\n" . DBGD_Database::dump_structure( $table );
		}
		$rows = DBGD_Database::dump_rows( $table, $offset, $limit );
		$sql .= $rows['sql'];

		if ( '' !== $sql ) {
			self::append_file( $job['sql_file'], $sql );
		}

		if ( $rows['count'] < $limit ) {
			// Table finished — move on.
			$job['db']['index']  = $index + 1;
			$job['db']['offset'] = 0;
			DBGD_Logger::add( sprintf( /* translators: %s: table name. */ __( 'Exported table %s.', 'demfilz-backup' ), $table ), 'info' );
		} else {
			$job['db']['offset'] = $offset + $rows['count'];
		}

		$done            = ( count( $tables ) > 0 ) ? ( $job['db']['index'] / count( $tables ) ) : 1;
		$job['progress'] = (int) min( 34, 5 + round( $done * 29 ) );
		$job['message']  = sprintf( /* translators: 1: current table number, 2: total tables. */ __( 'Exporting database (table %1$d of %2$d)…', 'demfilz-backup' ), $job['db']['index'] + 1, count( $tables ) );
		return $job;
	}

	/**
	 * Step: walk the included file roots and write a relative-path list.
	 *
	 * @param array $job Job.
	 * @return array
	 * @throws Exception When the file list cannot be written.
	 */
	private static function step_files_list( $job ) {
		$roots    = self::gather_roots( $job['includes'] );
		$excludes = self::hard_excludes();
		$abspath  = wp_normalize_path( trailingslashit( ABSPATH ) );
		$count    = 0;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming a potentially huge path list to disk.
		$handle = fopen( $job['list_file'], 'wb' );
		if ( false === $handle ) {
			throw new Exception( esc_html__( 'Could not write the file list.', 'demfilz-backup' ) );
		}

		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) && ! is_file( $root ) ) {
				continue;
			}
			if ( is_file( $root ) ) {
				$rel = self::relative_path( $root, $abspath );
				if ( false !== $rel ) {
					fwrite( $handle, $rel . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming path list.
					++$count;
				}
				continue;
			}
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $it as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$path = wp_normalize_path( $file->getPathname() );
				if ( self::is_excluded( $path, $excludes ) ) {
					continue;
				}
				$rel = self::relative_path( $path, $abspath );
				if ( false === $rel ) {
					continue;
				}
				fwrite( $handle, $rel . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming path list.
				++$count;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming path list.

		$job['files']['count'] = $count;
		$job['files']['index'] = 0;
		$job['stage']          = $count > 0 ? 'files_zip' : 'finalize';
		$job['progress']       = 38;
		$job['message']        = sprintf( /* translators: %d: number of files. */ __( 'Compressing %d files…', 'demfilz-backup' ), $count );
		DBGD_Logger::add( sprintf( /* translators: %d: number of files. */ __( 'Queued %d files for the archive.', 'demfilz-backup' ), $count ), 'info' );
		return $job;
	}

	/**
	 * Step: add the next batch of files to the archive.
	 *
	 * @param array $job Job.
	 * @return array
	 * @throws Exception When the archive cannot be opened.
	 */
	private static function step_files_zip( $job ) {
		$index   = (int) $job['files']['index'];
		$count   = (int) $job['files']['count'];
		$batch   = max( 25, (int) dbgd_get_option( 'chunk_files' ) );
		$abspath = wp_normalize_path( trailingslashit( ABSPATH ) );

		if ( $index >= $count ) {
			$job['stage']    = 'finalize';
			$job['progress'] = 80;
			return $job;
		}

		$lines = self::read_list_slice( $job['list_file'], $index, $batch );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $job['archive'] ) ) {
			throw new Exception( esc_html__( 'Could not open the backup archive.', 'demfilz-backup' ) );
		}
		$store_compress = (int) dbgd_get_option( 'compression' );
		foreach ( $lines as $rel ) {
			$rel = trim( $rel );
			if ( '' === $rel ) {
				continue;
			}
			$source = $abspath . $rel;
			if ( ! is_file( $source ) || ! is_readable( $source ) ) {
				continue;
			}
			$entry = 'files/' . $rel;
			if ( $zip->addFile( $source, $entry ) && method_exists( $zip, 'setCompressionName' ) ) {
				$method = self::should_store( $rel ) ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE;
				if ( 0 === $store_compress ) {
					$method = ZipArchive::CM_STORE;
				}
				$zip->setCompressionName( $entry, $method );
			}
		}
		$zip->close();

		$job['files']['index'] = $index + count( $lines );
		$done                  = $count > 0 ? ( $job['files']['index'] / $count ) : 1;
		$job['progress']       = (int) min( 79, 38 + round( $done * 41 ) );
		$job['message']        = sprintf( /* translators: 1: files done, 2: total files. */ __( 'Compressing files (%1$d of %2$d)…', 'demfilz-backup' ), min( $job['files']['index'], $count ), $count );
		return $job;
	}

	/**
	 * Step: add the database dump and manifest to the archive.
	 *
	 * @param array $job Job.
	 * @return array
	 * @throws Exception When the archive cannot be finalised.
	 */
	private static function step_finalize( $job ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $job['archive'] ) ) {
			throw new Exception( esc_html__( 'Could not finalise the backup archive.', 'demfilz-backup' ) );
		}
		if ( $job['includes']['database'] && is_file( $job['sql_file'] ) ) {
			$zip->addFile( $job['sql_file'], 'database.sql' );
			if ( method_exists( $zip, 'setCompressionName' ) ) {
				$zip->setCompressionName( 'database.sql', ZipArchive::CM_DEFLATE );
			}
		}
		$zip->addFromString( 'dbgd-manifest.json', wp_json_encode( $job['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$zip->close();

		// SQL file is now inside the archive — remove the loose copy early.
		if ( is_file( $job['sql_file'] ) ) {
			dbgd_delete_path( $job['sql_file'] );
		}

		$size                   = is_file( $job['archive'] ) ? (int) filesize( $job['archive'] ) : 0;
		$job['archive_size']    = $size;
		$job['upload']['total'] = $size;

		self::record_local_backup( $job, $size );

		if ( 'drive' === $job['destination'] ) {
			$job['stage']    = 'upload';
			$job['progress'] = 82;
			$job['message']  = __( 'Uploading to Google Drive…', 'demfilz-backup' );
		} else {
			$job['stage']    = 'prune';
			$job['progress'] = 95;
			$job['message']  = __( 'Tidying up…', 'demfilz-backup' );
		}
		DBGD_Logger::add( sprintf( /* translators: %s: human-readable file size. */ __( 'Archive built (%s).', 'demfilz-backup' ), size_format( $size ) ), 'success' );
		return $job;
	}

	/**
	 * Step: upload one chunk to Google Drive (resumable).
	 *
	 * @param array $job Job.
	 * @return array
	 * @throws Exception When the upload fails.
	 */
	private static function step_upload( $job ) {
		$total = (int) $job['upload']['total'];
		if ( $total <= 0 ) {
			throw new Exception( esc_html__( 'The backup archive is empty.', 'demfilz-backup' ) );
		}

		if ( '' === $job['upload']['session'] ) {
			$folder = DBGD_GDrive::ensure_folder();
			if ( is_wp_error( $folder ) ) {
				throw new Exception( esc_html( $folder->get_error_message() ) );
			}
			$session = DBGD_GDrive::start_resumable( $job['archive_name'], $folder, $total );
			if ( is_wp_error( $session ) ) {
				throw new Exception( esc_html( $session->get_error_message() ) );
			}
			$job['upload']['session'] = $session;
			$job['upload']['offset']  = 0;
		}

		$offset = (int) $job['upload']['offset'];
		$chunk  = self::read_archive_chunk( $job['archive'], $offset, DBGD_GDrive::CHUNK );
		if ( false === $chunk || '' === $chunk ) {
			throw new Exception( esc_html__( 'Could not read the archive for upload.', 'demfilz-backup' ) );
		}

		$result = DBGD_GDrive::upload_chunk( $job['upload']['session'], $chunk, $offset, $total );
		if ( is_wp_error( $result ) ) {
			throw new Exception( esc_html( $result->get_error_message() ) );
		}

		if ( 'done' === $result['status'] ) {
			$job['upload']['file_id'] = $result['file_id'];
			$job['upload']['offset']  = $total;
			$job['stage']             = 'prune';
			$job['progress']          = 95;
			$job['message']           = __( 'Upload complete.', 'demfilz-backup' );
			self::mark_local_uploaded( $job, $result['file_id'] );
			DBGD_Logger::add( __( 'Uploaded to Google Drive.', 'demfilz-backup' ), 'success' );
		} else {
			$job['upload']['offset'] = (int) $result['next_offset'];
			$pct                     = $total > 0 ? ( $job['upload']['offset'] / $total ) : 1;
			$job['progress']         = (int) min( 94, 82 + round( $pct * 12 ) );
			$job['message']          = sprintf( /* translators: 1: uploaded size, 2: total size. */ __( 'Uploading to Google Drive (%1$s of %2$s)…', 'demfilz-backup' ), size_format( $job['upload']['offset'] ), size_format( $total ) );
		}
		return $job;
	}

	/**
	 * Step: enforce retention on Drive and locally.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	private static function step_prune( $job ) {
		$keep = (int) dbgd_get_option( 'retention' );
		if ( 'drive' === $job['destination'] && $keep > 0 && DBGD_GDrive::is_connected() ) {
			$files = DBGD_GDrive::list_files();
			if ( ! is_wp_error( $files ) && count( $files ) > $keep ) {
				// list_files() is newest-first; delete the overflow.
				$old = array_slice( $files, $keep );
				foreach ( $old as $f ) {
					DBGD_GDrive::delete_file( $f['id'] );
					DBGD_Logger::add( sprintf( /* translators: %s: file name. */ __( 'Removed old backup %s from Drive.', 'demfilz-backup' ), $f['name'] ), 'info' );
				}
			}
		}
		self::prune_local( $keep > 0 ? $keep : 3 );

		$job['stage']    = 'cleanup';
		$job['progress'] = 98;
		return $job;
	}

	/**
	 * Step: remove working files; keep or drop the local archive.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	private static function step_cleanup( $job ) {
		// Remove the working directory.
		if ( ! empty( $job['work_dir'] ) && is_dir( $job['work_dir'] ) ) {
			dbgd_delete_path( $job['work_dir'] );
		}
		// If it went to Drive, we no longer need the local copy.
		if ( 'drive' === $job['destination'] && ! empty( $job['upload']['file_id'] ) && is_file( $job['archive'] ) ) {
			dbgd_delete_path( $job['archive'] );
			self::forget_local_path( $job['archive'] );
		}

		$job['status']   = 'done';
		$job['stage']    = 'done';
		$job['progress'] = 100;
		$job['message']  = __( 'Backup complete.', 'demfilz-backup' );
		DBGD_Logger::add( __( 'Backup complete.', 'demfilz-backup' ), 'success' );

		self::maybe_notify( $job, true );
		return $job;
	}

	// === Helpers ===

	/**
	 * Build the list of filesystem roots to include.
	 *
	 * @param array $includes Include flags.
	 * @return string[]
	 */
	private static function gather_roots( $includes ) {
		$roots   = array();
		$content = wp_normalize_path( WP_CONTENT_DIR );
		$uploads = wp_upload_dir();
		$upbase  = isset( $uploads['basedir'] ) ? wp_normalize_path( $uploads['basedir'] ) : $content . '/uploads';

		if ( ! empty( $includes['plugins'] ) ) {
			$roots[] = $content . '/plugins';
		}
		if ( ! empty( $includes['themes'] ) ) {
			$roots[] = $content . '/themes';
		}
		if ( ! empty( $includes['uploads'] ) ) {
			$roots[] = $upbase;
		}
		if ( ! empty( $includes['content'] ) ) {
			// Other wp-content items not already covered.
			$skip = array( 'plugins', 'themes', 'uploads', 'cache', 'upgrade', 'dbgd-backups' );
			$dh   = is_dir( $content ) ? opendir( $content ) : false;
			if ( $dh ) {
				// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard readdir loop.
				while ( false !== ( $item = readdir( $dh ) ) ) {
					if ( '.' === $item || '..' === $item ) {
						continue;
					}
					if ( in_array( $item, $skip, true ) ) {
						continue;
					}
					$roots[] = $content . '/' . $item;
				}
				closedir( $dh );
			}
		}
		if ( ! empty( $includes['core'] ) ) {
			$abspath = wp_normalize_path( untrailingslashit( ABSPATH ) );
			$roots[] = $abspath . '/wp-admin';
			$roots[] = $abspath . '/wp-includes';
			// Root-level core files (skip wp-config.php and wp-content).
			$dh = is_dir( $abspath ) ? opendir( $abspath ) : false;
			if ( $dh ) {
				// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard readdir loop.
				while ( false !== ( $item = readdir( $dh ) ) ) {
					$full = $abspath . '/' . $item;
					if ( ! is_file( $full ) ) {
						continue;
					}
					if ( 'wp-config.php' === $item ) {
						continue;
					}
					$roots[] = $full;
				}
				closedir( $dh );
			}
		}

		return array_values( array_unique( $roots ) );
	}

	/**
	 * Whether a path matches any exclude fragment.
	 *
	 * @param string $path     Normalised path.
	 * @param array  $excludes Fragments.
	 * @return bool
	 */
	private static function is_excluded( $path, $excludes ) {
		foreach ( $excludes as $frag ) {
			if ( false !== strpos( $path, $frag ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Path relative to ABSPATH, or false if outside it.
	 *
	 * @param string $path    Normalised absolute path.
	 * @param string $abspath Normalised ABSPATH (trailing slash).
	 * @return string|false
	 */
	private static function relative_path( $path, $abspath ) {
		$path = wp_normalize_path( $path );
		if ( 0 === strpos( $path, $abspath ) ) {
			return substr( $path, strlen( $abspath ) );
		}
		return false;
	}

	/**
	 * Whether a file extension is already compressed (store, don't deflate).
	 *
	 * @param string $rel Relative path.
	 * @return bool
	 */
	private static function should_store( $rel ) {
		$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'mp3', 'zip', 'gz', 'pdf', 'woff', 'woff2' ), true );
	}

	/**
	 * Append a string to a file.
	 *
	 * @param string $path     File.
	 * @param string $contents Data.
	 */
	private static function append_file( $path, $contents ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Appending to the working SQL dump.
		file_put_contents( $path, $contents, FILE_APPEND );
	}

	/**
	 * Read a slice of lines from the file list.
	 *
	 * @param string $path  List file.
	 * @param int    $start Start line index (0-based).
	 * @param int    $count How many lines.
	 * @return string[]
	 */
	private static function read_list_slice( $path, $start, $count ) {
		$lines = array();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming a large list file.
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return $lines;
		}
		$i = 0;
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard fgets loop.
		while ( false !== ( $line = fgets( $handle ) ) ) {
			if ( $i >= $start && $i < ( $start + $count ) ) {
				$lines[] = rtrim( $line, "\r\n" );
			}
			++$i;
			if ( $i >= ( $start + $count ) ) {
				break;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming list file.
		return $lines;
	}

	/**
	 * Read a byte range from the archive.
	 *
	 * @param string $path   Archive.
	 * @param int    $offset Start byte.
	 * @param int    $length Length.
	 * @return string|false
	 */
	private static function read_archive_chunk( $path, $offset, $length ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming the archive for chunked upload.
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		fseek( $handle, $offset );
		$data = fread( $handle, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming the archive for chunked upload.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming archive.
		return $data;
	}

	/**
	 * Record a backup in the local registry option.
	 *
	 * @param array $job  Job.
	 * @param int   $size Size in bytes.
	 */
	private static function record_local_backup( $job, $size ) {
		$list = get_option( DBGD_OPT_BACKUPS, array() );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		$list[ $job['id'] ] = array(
			'id'          => $job['id'],
			'name'        => $job['archive_name'],
			'path'        => $job['archive'],
			'size'        => $size,
			'created'     => time(),
			'destination' => $job['destination'],
			'drive_id'    => '',
			'site_url'    => isset( $job['manifest']['site_url'] ) ? $job['manifest']['site_url'] : '',
		);
		update_option( DBGD_OPT_BACKUPS, $list, false );
	}

	/**
	 * Flag a local registry entry as uploaded to Drive.
	 *
	 * @param array  $job      Job.
	 * @param string $drive_id Drive file ID.
	 */
	private static function mark_local_uploaded( $job, $drive_id ) {
		$list = get_option( DBGD_OPT_BACKUPS, array() );
		if ( is_array( $list ) && isset( $list[ $job['id'] ] ) ) {
			$list[ $job['id'] ]['drive_id'] = $drive_id;
			update_option( DBGD_OPT_BACKUPS, $list, false );
		}
	}

	/**
	 * Drop a path from the local registry once deleted from disk.
	 *
	 * @param string $path Path.
	 */
	private static function forget_local_path( $path ) {
		$list = get_option( DBGD_OPT_BACKUPS, array() );
		if ( ! is_array( $list ) ) {
			return;
		}
		foreach ( $list as $id => $entry ) {
			if ( isset( $entry['path'] ) && $entry['path'] === $path ) {
				$list[ $id ]['path'] = '';
			}
		}
		update_option( DBGD_OPT_BACKUPS, $list, false );
	}

	/**
	 * Delete local archive files beyond the keep count.
	 *
	 * @param int $keep        How many local archives to keep.
	 */
	private static function prune_local( $keep ) {
		$dir  = dbgd_backup_dir();
		$zips = glob( $dir . '*.zip' );
		if ( ! is_array( $zips ) || count( $zips ) <= $keep ) {
			return;
		}
		// Oldest first.
		usort(
			$zips,
			function ( $a, $b ) {
				return filemtime( $a ) <=> filemtime( $b );
			}
		);
		$remove = array_slice( $zips, 0, count( $zips ) - $keep );
		foreach ( $remove as $zip ) {
			dbgd_delete_path( $zip );
			self::forget_local_path( $zip );
		}
	}

	/**
	 * Email a short backup report when configured.
	 *
	 * @param array $job     Job.
	 * @param bool  $success Whether it succeeded.
	 */
	public static function maybe_notify( $job, $success ) {
		$email = trim( (string) dbgd_get_option( 'notify_email' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}
		$site    = wp_parse_url( home_url(), PHP_URL_HOST );
		$subject = sprintf(
			/* translators: 1: success/failure word, 2: site host. */
			__( 'Backup %1$s — %2$s', 'demfilz-backup' ),
			$success ? __( 'succeeded', 'demfilz-backup' ) : __( 'failed', 'demfilz-backup' ),
			$site
		);
		$lines   = array();
		$lines[] = $success ? __( 'Your scheduled backup completed successfully.', 'demfilz-backup' ) : __( 'Your scheduled backup did not complete.', 'demfilz-backup' );
		$lines[] = '';
		$lines[] = sprintf( /* translators: %s: file name. */ __( 'File: %s', 'demfilz-backup' ), isset( $job['archive_name'] ) ? $job['archive_name'] : '' );
		if ( ! empty( $job['archive_size'] ) ) {
			$lines[] = sprintf( /* translators: %s: size. */ __( 'Size: %s', 'demfilz-backup' ), size_format( (int) $job['archive_size'] ) );
		}
		$lines[] = sprintf( /* translators: %s: destination. */ __( 'Destination: %s', 'demfilz-backup' ), 'drive' === $job['destination'] ? 'Google Drive' : __( 'Local only', 'demfilz-backup' ) );
		if ( ! $success && ! empty( $job['error'] ) ) {
			$lines[] = '';
			$lines[] = sprintf( /* translators: %s: error message. */ __( 'Error: %s', 'demfilz-backup' ), $job['error'] );
		}
		wp_mail( $email, $subject, implode( "\n", $lines ) );
	}
}
