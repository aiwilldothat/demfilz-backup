<?php
/**
 * Uninstall: remove options, scheduled events and the backup directory.
 *
 * @package Demfilz_Backup
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Clear scheduled events.
wp_clear_scheduled_hook( 'dbgd_scheduled_backup' );
wp_clear_scheduled_hook( 'dbgd_backup_continue' );

// Delete options.
$dbgd_options = array(
	'dbgd_settings',
	'dbgd_gdrive',
	'dbgd_job',
	'dbgd_local_backups',
	'dbgd_db_version',
	'dbgd_log',
	'dbgd_dir_secret',
);
foreach ( $dbgd_options as $dbgd_option ) {
	delete_option( $dbgd_option );
}

// Remove the backup directory and everything in it.
if ( defined( 'WP_CONTENT_DIR' ) ) {
	// The backup directory carries a random suffix, so match dbgd-backups*.
	$dbgd_roots = glob( WP_CONTENT_DIR . '/dbgd-backups*', GLOB_ONLYDIR );
	if ( ! is_array( $dbgd_roots ) ) {
		$dbgd_roots = array();
	}
	foreach ( $dbgd_roots as $dbgd_dir ) {
		$dbgd_stack = array( $dbgd_dir );
		$dbgd_dirs  = array();
		while ( $dbgd_stack ) {
			$dbgd_current = array_pop( $dbgd_stack );
			$dbgd_dirs[]  = $dbgd_current;
			$dbgd_items   = scandir( $dbgd_current );
			if ( is_array( $dbgd_items ) ) {
				foreach ( $dbgd_items as $dbgd_item ) {
					if ( '.' === $dbgd_item || '..' === $dbgd_item ) {
						continue;
					}
					$dbgd_path = $dbgd_current . '/' . $dbgd_item;
					if ( is_dir( $dbgd_path ) ) {
						$dbgd_stack[] = $dbgd_path;
					} else {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing the plugin's own backup files on uninstall.
						unlink( $dbgd_path );
					}
				}
			}
		}
		// Remove directories deepest-first.
		$dbgd_dirs = array_reverse( $dbgd_dirs );
		foreach ( $dbgd_dirs as $dbgd_d ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing the plugin's own backup directory on uninstall.
			rmdir( $dbgd_d );
		}
	}
}
