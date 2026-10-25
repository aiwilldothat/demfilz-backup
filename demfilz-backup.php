<?php
/**
 * Plugin Name:       Demfilz Backup
 * Plugin URI:        https://demfilz.com/demfilz-backup
 * Description:       Back up your whole WordPress site (database + files) into a single zip and store it safely in your own Google Drive. Restore any backup onto a new site — even on a different domain — with automatic, serialization-safe URL replacement. Schedule automatic backups and keep only the most recent copies.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Demfilz
 * Author URI:        https://demfilz.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       demfilz-backup
 *
 * @package Demfilz_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DBGD_VERSION', '1.0.0' );
define( 'DBGD_FILE', __FILE__ );
define( 'DBGD_BASENAME', plugin_basename( __FILE__ ) );
define( 'DBGD_PATH', plugin_dir_path( __FILE__ ) );
define( 'DBGD_URL', plugin_dir_url( __FILE__ ) );

// Option keys.
define( 'DBGD_OPT_SETTINGS', 'dbgd_settings' );
define( 'DBGD_OPT_GDRIVE', 'dbgd_gdrive' );
define( 'DBGD_OPT_JOB', 'dbgd_job' );
define( 'DBGD_OPT_BACKUPS', 'dbgd_local_backups' );
define( 'DBGD_OPT_DBVER', 'dbgd_db_version' );

// Cron hook.
define( 'DBGD_CRON_HOOK', 'dbgd_scheduled_backup' );

require_once DBGD_PATH . 'includes/class-dbgd-logger.php';
require_once DBGD_PATH . 'includes/class-dbgd-database.php';
require_once DBGD_PATH . 'includes/class-dbgd-gdrive.php';
require_once DBGD_PATH . 'includes/class-dbgd-backup.php';
require_once DBGD_PATH . 'includes/class-dbgd-restore.php';
require_once DBGD_PATH . 'includes/class-dbgd-cron.php';
require_once DBGD_PATH . 'includes/class-dbgd-admin.php';

/**
 * Default plugin settings.
 *
 * @return array Default settings.
 */
function dbgd_default_settings() {
	return array(
		// What to include in a backup.
		'include_database' => 1,
		'include_uploads'  => 1,
		'include_themes'   => 1,
		'include_plugins'  => 1,
		'include_content'  => 1, // Other wp-content (mu-plugins, languages, etc).
		'include_core'     => 0, // WordPress core (wp-admin, wp-includes, root files).
		// How to authorize Google Drive: 'broker' = one-click via the hosted
		// Demfilz broker (no setup); 'own' = the site supplies its own OAuth app.
		'auth_mode'        => 'broker',
		// Google OAuth application (only used in 'own' mode).
		'client_id'        => '',
		'client_secret'    => '',
		'drive_folder'     => 'Demfilz Backups',
		// Scheduling.
		'schedule'         => 'disabled', // disabled | dbgd_daily | dbgd_weekly | dbgd_monthly.
		'retention'        => 5,          // Number of backups to keep on Drive (0 = keep all).
		'notify_email'     => '',         // Where to email backup reports (blank = none).
		// Engine tuning.
		'chunk_files'      => 200,        // Files added per backup step.
		'chunk_rows'       => 2000,       // DB rows read per step.
		'compression'      => 1,          // 0 = store, 1..9 = deflate level (best-effort).
	);
}

/**
 * Get the whole settings array (merged over defaults) or one key.
 *
 * @param string|null $key      Setting key, or null for all.
 * @param mixed       $fallback Fallback when the key is missing.
 * @return mixed
 */
function dbgd_get_option( $key = null, $fallback = null ) {
	$defaults = dbgd_default_settings();
	$saved    = get_option( DBGD_OPT_SETTINGS, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	$options = array_merge( $defaults, $saved );

	if ( null === $key ) {
		return $options;
	}
	if ( array_key_exists( $key, $options ) ) {
		return $options[ $key ];
	}
	return $fallback;
}

/**
 * Absolute path to the protected directory backups are staged in.
 *
 * A random suffix (generated once) keeps the path unguessable — important on
 * servers like nginx that ignore the .htaccess deny rules, where the directory
 * name is the main thing standing between the public and your database dumps.
 *
 * @return string Path with a trailing slash.
 */
function dbgd_backup_dir() {
	$secret = get_option( 'dbgd_dir_secret', '' );
	if ( '' === $secret ) {
		$secret = wp_generate_password( 12, false, false );
		update_option( 'dbgd_dir_secret', $secret, false );
	}
	return WP_CONTENT_DIR . '/dbgd-backups-' . $secret . '/';
}

/**
 * Ensure the backup directory exists and is protected from public access.
 *
 * @return string|WP_Error Directory path, or error on failure.
 */
function dbgd_prepare_backup_dir() {
	$dir = dbgd_backup_dir();
	if ( ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'dbgd_mkdir', __( 'Could not create the backup directory.', 'demfilz-backup' ) );
	}

	// Block direct web access (Apache + IIS) and stop directory listing.
	$htaccess = $dir . '.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		dbgd_write_file( $htaccess, "Order Allow,Deny\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
	}
	$index = $dir . 'index.html';
	if ( ! file_exists( $index ) ) {
		dbgd_write_file( $index, '' );
	}
	$webconfig = $dir . 'web.config';
	if ( ! file_exists( $webconfig ) ) {
		dbgd_write_file( $webconfig, "<configuration>\n<system.webServer>\n<authorization>\n<deny users=\"*\" />\n</authorization>\n</system.webServer>\n</configuration>\n" );
	}

	return $dir;
}

/**
 * Small helper to write a string to a file (native, for early bootstrap use).
 *
 * @param string $path     File path.
 * @param string $contents Contents.
 * @return bool
 */
function dbgd_write_file( $path, $contents ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing protection files into the plugin's own backup dir.
	return false !== file_put_contents( $path, $contents );
}

/**
 * Recursively delete a file or directory.
 *
 * @param string $path Path to remove.
 * @return bool
 */
function dbgd_delete_path( $path ) {
	if ( '' === $path || ! file_exists( $path ) ) {
		return true;
	}
	if ( is_file( $path ) || is_link( $path ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing the plugin's own working files.
		return unlink( $path );
	}
	$items = scandir( $path );
	if ( is_array( $items ) ) {
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			dbgd_delete_path( rtrim( $path, '/\\' ) . '/' . $item );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing the plugin's own working directory.
	return rmdir( $path );
}

/**
 * Read and decode a JSON state file.
 *
 * @param string $path File path.
 * @return array Decoded data (empty array on failure).
 */
function dbgd_read_json_file( $path ) {
	if ( ! is_file( $path ) ) {
		return array();
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local state file in the plugin's backup dir.
	$raw  = file_get_contents( $path );
	$data = json_decode( (string) $raw, true );
	return is_array( $data ) ? $data : array();
}

/**
 * Encode and write a JSON state file.
 *
 * @param string $path File path.
 * @param array  $data Data.
 * @return bool
 */
function dbgd_write_json_file( $path, $data ) {
	return dbgd_write_file( $path, (string) wp_json_encode( $data ) );
}

/**
 * Whether restoring onto a different URL (site migration) is available.
 *
 * This core plugin restores a backup onto its own site. Restoring onto a
 * different URL is provided by the separate "Demfilz Backup Pro" add-on, which
 * hooks the dbgd_can_migrate filter to enable it. Without the add-on this is
 * always false and cross-URL restores are declined.
 *
 * @return bool
 */
function dbgd_can_migrate() {
	/**
	 * Filter whether cross-URL migration is available (the Pro add-on sets true).
	 *
	 * @param bool $can Whether migration is enabled.
	 */
	return (bool) apply_filters( 'dbgd_can_migrate', false );
}

/**
 * Whether restoring this backup onto this target URL is a cross-URL migration
 * (i.e. the backup's original site URL differs from the target URL).
 *
 * @param array  $manifest Backup manifest.
 * @param string $new_url  Target site URL.
 * @return bool
 */
function dbgd_is_url_migration( $manifest, $new_url ) {
	$old = isset( $manifest['site_url'] ) ? untrailingslashit( (string) $manifest['site_url'] ) : '';
	$new = untrailingslashit( (string) $new_url );
	return ( '' !== $old && $old !== $new );
}

/**
 * Boot the plugin.
 */
function dbgd_init() {
	new DBGD_Admin();
	new DBGD_Cron();
}
add_action( 'plugins_loaded', 'dbgd_init' );

/**
 * Activation: seed settings, create the protected backup directory.
 */
function dbgd_activate() {
	if ( false === get_option( DBGD_OPT_SETTINGS, false ) ) {
		add_option( DBGD_OPT_SETTINGS, dbgd_default_settings() );
	}
	dbgd_prepare_backup_dir();
	update_option( DBGD_OPT_DBVER, DBGD_VERSION );
}
register_activation_hook( __FILE__, 'dbgd_activate' );

/**
 * Deactivation: clear any scheduled backup event.
 */
function dbgd_deactivate() {
	$timestamp = wp_next_scheduled( DBGD_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, DBGD_CRON_HOOK );
	}
	wp_clear_scheduled_hook( DBGD_CRON_HOOK );
}
register_deactivation_hook( __FILE__, 'dbgd_deactivate' );

/**
 * "Backups" quick link on the Plugins screen.
 */
add_filter(
	'plugin_action_links_' . DBGD_BASENAME,
	function ( $links ) {
		$url = admin_url( 'admin.php?page=dbgd-backup' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Backups', 'demfilz-backup' ) . '</a>' );
		return $links;
	}
);
