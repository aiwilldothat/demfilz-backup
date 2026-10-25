<?php
/**
 * Admin screens, settings, OAuth flow and AJAX controllers.
 *
 * @package Demfilz_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires up the admin experience.
 */
class DBGD_Admin {

	const CAP = 'manage_options';

	/**
	 * Hook everything.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_oauth' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'update_option_' . DBGD_OPT_SETTINGS, array( 'DBGD_Cron', 'reschedule' ), 10, 0 );

		// AJAX — privileged.
		add_action( 'wp_ajax_dbgd_backup_start', array( $this, 'ajax_backup_start' ) );
		add_action( 'wp_ajax_dbgd_backup_step', array( $this, 'ajax_backup_step' ) );
		add_action( 'wp_ajax_dbgd_backup_status', array( $this, 'ajax_backup_status' ) );
		add_action( 'wp_ajax_dbgd_list_drive', array( $this, 'ajax_list_drive' ) );
		add_action( 'wp_ajax_dbgd_delete_backup', array( $this, 'ajax_delete_backup' ) );
		add_action( 'wp_ajax_dbgd_restore_start', array( $this, 'ajax_restore_start' ) );
		add_action( 'wp_ajax_dbgd_upload_restore', array( $this, 'ajax_upload_restore' ) );

		// AJAX — restore step/status also allowed without a session (DB swap can
		// log the admin out mid-restore); these are guarded by a file token.
		add_action( 'wp_ajax_dbgd_restore_step', array( $this, 'ajax_restore_step' ) );
		add_action( 'wp_ajax_nopriv_dbgd_restore_step', array( $this, 'ajax_restore_step' ) );
		add_action( 'wp_ajax_dbgd_restore_status', array( $this, 'ajax_restore_status' ) );
		add_action( 'wp_ajax_nopriv_dbgd_restore_status', array( $this, 'ajax_restore_status' ) );

		// Streamed download of a local backup.
		add_action( 'admin_post_dbgd_download', array( $this, 'handle_download' ) );
	}

	// === Menu & assets ===

	/**
	 * Register the admin menu.
	 */
	public function menu() {
		add_menu_page(
			__( 'Backups', 'demfilz-backup' ),
			__( 'Backups', 'demfilz-backup' ),
			self::CAP,
			'dbgd-backup',
			array( $this, 'render_backup_page' ),
			'dashicons-backup',
			81
		);
		add_submenu_page( 'dbgd-backup', __( 'Backup & Restore', 'demfilz-backup' ), __( 'Backup & Restore', 'demfilz-backup' ), self::CAP, 'dbgd-backup', array( $this, 'render_backup_page' ) );
		add_submenu_page( 'dbgd-backup', __( 'Settings', 'demfilz-backup' ), __( 'Settings', 'demfilz-backup' ), self::CAP, 'dbgd-settings', array( $this, 'render_settings_page' ) );
	}

	/**
	 * Enqueue admin assets on our pages only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		if ( false === strpos( $hook, 'dbgd-backup' ) && false === strpos( $hook, 'dbgd-settings' ) ) {
			return;
		}
		wp_enqueue_style( 'dbgd-admin', DBGD_URL . 'assets/css/dbgd-admin.css', array(), DBGD_VERSION );
		wp_enqueue_script( 'dbgd-admin', DBGD_URL . 'assets/js/dbgd-admin.js', array(), DBGD_VERSION, true );
		wp_localize_script(
			'dbgd-admin',
			'DBGD',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'adminPost' => admin_url( 'admin-post.php' ),
				'nonce'     => wp_create_nonce( 'dbgd_ajax' ),
				'dlNonce'   => wp_create_nonce( 'dbgd_download' ),
				'home'      => untrailingslashit( home_url() ),
				'i18n'      => array(
					'confirmRestore' => __( 'Restoring will OVERWRITE this site\'s database and files with the contents of the backup. This cannot be undone. Continue?', 'demfilz-backup' ),
					'confirmDelete'  => __( 'Delete this backup permanently?', 'demfilz-backup' ),
					'failed'         => __( 'Something went wrong. Check the log below.', 'demfilz-backup' ),
					'done'           => __( 'Done.', 'demfilz-backup' ),
				),
			)
		);
	}

	// === Settings API ===

	/**
	 * Register the settings + sanitiser.
	 */
	public function register_settings() {
		register_setting(
			'dbgd_settings_group',
			DBGD_OPT_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);
	}

	/**
	 * Sanitise the settings array.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$defaults = dbgd_default_settings();
		$out      = dbgd_get_option();
		$input    = is_array( $input ) ? $input : array();

		foreach ( array( 'include_database', 'include_uploads', 'include_themes', 'include_plugins', 'include_content', 'include_core', 'compression' ) as $flag ) {
			$out[ $flag ] = isset( $input[ $flag ] ) ? (int) $input[ $flag ] : 0;
		}
		$out['auth_mode'] = ( isset( $input['auth_mode'] ) && 'own' === $input['auth_mode'] ) ? 'own' : 'broker';
		if ( isset( $input['client_id'] ) ) {
			$out['client_id'] = sanitize_text_field( $input['client_id'] );
		}
		if ( isset( $input['client_secret'] ) ) {
			$out['client_secret'] = sanitize_text_field( $input['client_secret'] );
		}
		if ( isset( $input['drive_folder'] ) ) {
			$out['drive_folder'] = sanitize_text_field( $input['drive_folder'] );
			if ( '' === $out['drive_folder'] ) {
				$out['drive_folder'] = $defaults['drive_folder'];
			}
		}
		$schedules           = array( 'disabled', 'daily', 'dbgd_weekly', 'dbgd_monthly' );
		$out['schedule']     = ( isset( $input['schedule'] ) && in_array( $input['schedule'], $schedules, true ) ) ? $input['schedule'] : 'disabled';
		$out['retention']    = isset( $input['retention'] ) ? max( 0, (int) $input['retention'] ) : $defaults['retention'];
		$out['notify_email'] = isset( $input['notify_email'] ) ? sanitize_email( $input['notify_email'] ) : '';
		$out['chunk_files']  = isset( $input['chunk_files'] ) ? min( 5000, max( 25, (int) $input['chunk_files'] ) ) : $defaults['chunk_files'];
		$out['chunk_rows']   = isset( $input['chunk_rows'] ) ? min( 50000, max( 200, (int) $input['chunk_rows'] ) ) : $defaults['chunk_rows'];

		return $out;
	}

	// === OAuth flow ===

	/**
	 * Handle "connect", the Google callback, and "disconnect".
	 */
	public function handle_oauth() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// Start the connect flow (one-click broker, or the site's own Google app).
		if ( isset( $_GET['dbgd_oauth'] ) && 'connect' === $_GET['dbgd_oauth'] ) {
			check_admin_referer( 'dbgd_connect' );
			$mode  = DBGD_GDrive::mode();
			$state = wp_create_nonce( 'dbgd_oauth_state' );

			if ( 'broker' === $mode ) {
				$verifier = wp_generate_password( 64, false, false );
				set_transient( 'dbgd_oauth_verifier_' . get_current_user_id(), $verifier, 15 * MINUTE_IN_SECONDS );
				$url = DBGD_GDrive::broker_auth_url( $state, DBGD_GDrive::pkce_challenge( $verifier ) );
			} else {
				$url = DBGD_GDrive::auth_url( $state );
				if ( is_wp_error( $url ) ) {
					$this->redirect_with_notice( 'settings', 'error', $url->get_error_message() );
				}
			}
			wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External redirect to Google's consent screen (or the OAuth broker).
			exit;
		}

		// Callback from Google (own mode) or the broker (broker mode).
		if ( isset( $_GET['dbgd_oauth'] ) && 'callback' === $_GET['dbgd_oauth'] ) {
			$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
			if ( ! wp_verify_nonce( $state, 'dbgd_oauth_state' ) ) {
				$this->redirect_with_notice( 'settings', 'error', __( 'Security check failed during Google sign-in. Please try again.', 'demfilz-backup' ) );
			}
			if ( isset( $_GET['error'] ) ) {
				$this->redirect_with_notice( 'settings', 'error', __( 'Google sign-in was cancelled.', 'demfilz-backup' ) );
			}

			if ( isset( $_GET['dbgd_broker'] ) || isset( $_GET['claim'] ) ) {
				// Broker mode: swap the one-time claim code (+ our verifier) for tokens.
				$claim    = isset( $_GET['claim'] ) ? sanitize_text_field( wp_unslash( $_GET['claim'] ) ) : '';
				$verifier = get_transient( 'dbgd_oauth_verifier_' . get_current_user_id() );
				delete_transient( 'dbgd_oauth_verifier_' . get_current_user_id() );
				if ( '' === $claim || ! $verifier ) {
					$this->redirect_with_notice( 'settings', 'error', __( 'The Google connection did not complete. Please try again.', 'demfilz-backup' ) );
				}
				$result = DBGD_GDrive::claim_from_broker( $claim, $verifier );
			} else {
				// Own-app mode: exchange the Google authorization code directly.
				$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
				if ( '' === $code ) {
					$this->redirect_with_notice( 'settings', 'error', __( 'No authorization code was returned by Google.', 'demfilz-backup' ) );
				}
				$result = DBGD_GDrive::exchange_code( $code );
			}

			if ( is_wp_error( $result ) ) {
				$this->redirect_with_notice( 'settings', 'error', $result->get_error_message() );
			}
			DBGD_GDrive::ensure_folder();
			$this->redirect_with_notice( 'settings', 'success', __( 'Google Drive connected.', 'demfilz-backup' ) );
		}

		// Disconnect.
		if ( isset( $_GET['dbgd_oauth'] ) && 'disconnect' === $_GET['dbgd_oauth'] ) {
			check_admin_referer( 'dbgd_disconnect' );
			DBGD_GDrive::disconnect();
			$this->redirect_with_notice( 'settings', 'success', __( 'Google Drive disconnected.', 'demfilz-backup' ) );
		}
	}

	/**
	 * Redirect back to one of our pages with a transient notice.
	 *
	 * @param string $page    'backup'|'settings'.
	 * @param string $type    'success'|'error'.
	 * @param string $message Message.
	 */
	private function redirect_with_notice( $page, $type, $message ) {
		set_transient(
			'dbgd_notice',
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
		$slug = 'settings' === $page ? 'dbgd-settings' : 'dbgd-backup';
		wp_safe_redirect( admin_url( 'admin.php?page=' . $slug ) );
		exit;
	}

	/**
	 * Print and clear any pending notice.
	 */
	private function print_notice() {
		$notice = get_transient( 'dbgd_notice' );
		if ( ! $notice || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( 'dbgd_notice' );
		$class = ( 'error' === $notice['type'] ) ? 'notice-error' : 'notice-success';
		printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $notice['message'] ) );
	}

	// === Pages ===

	/**
	 * Main Backup & Restore page.
	 */
	public function render_backup_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$connected = DBGD_GDrive::is_connected();
		$state     = DBGD_GDrive::state();
		$email     = isset( $state['email'] ) ? $state['email'] : '';
		?>
		<div class="wrap dbgd-wrap">
			<h1><?php esc_html_e( 'Backup &amp; Restore', 'demfilz-backup' ); ?></h1>
			<?php $this->print_notice(); ?>

			<div class="dbgd-status-bar">
				<?php if ( $connected ) : ?>
					<span class="dbgd-dot dbgd-dot--on"></span>
					<?php
					/* translators: %s: connected Google account email. */
					printf( esc_html__( 'Connected to Google Drive%s', 'demfilz-backup' ), $email ? ' (' . esc_html( $email ) . ')' : '' );
					?>
				<?php else : ?>
					<span class="dbgd-dot dbgd-dot--off"></span>
					<?php esc_html_e( 'Google Drive is not connected.', 'demfilz-backup' ); ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=dbgd-settings' ) ); ?>"><?php esc_html_e( 'Set up', 'demfilz-backup' ); ?></a>
				<?php endif; ?>
			</div>

			<div class="dbgd-card">
				<h2><?php esc_html_e( 'Create a backup', 'demfilz-backup' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Bundles your database and files into one zip. Choose where to store it.', 'demfilz-backup' ); ?></p>
				<p>
					<button class="button button-primary" id="dbgd-backup-drive" <?php disabled( ! $connected ); ?>><?php esc_html_e( 'Back up to Google Drive', 'demfilz-backup' ); ?></button>
					<button class="button" id="dbgd-backup-local"><?php esc_html_e( 'Back up to this server only', 'demfilz-backup' ); ?></button>
				</p>
				<div class="dbgd-progress" id="dbgd-backup-progress" hidden>
					<div class="dbgd-progress__bar"><span></span></div>
					<p class="dbgd-progress__msg"></p>
				</div>
			</div>

			<div class="dbgd-card">
				<h2><?php esc_html_e( 'Your backups', 'demfilz-backup' ); ?></h2>
				<p>
					<button class="button" id="dbgd-refresh-list"><?php esc_html_e( 'Refresh list', 'demfilz-backup' ); ?></button>
				</p>
				<table class="widefat striped dbgd-table" id="dbgd-backup-list">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Backup', 'demfilz-backup' ); ?></th>
							<th><?php esc_html_e( 'Where', 'demfilz-backup' ); ?></th>
							<th><?php esc_html_e( 'Size', 'demfilz-backup' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'demfilz-backup' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr><td colspan="4"><?php esc_html_e( 'Loading…', 'demfilz-backup' ); ?></td></tr>
					</tbody>
				</table>
			</div>

			<div class="dbgd-card">
				<h2><?php esc_html_e( 'Restore from a zip file', 'demfilz-backup' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Upload a backup zip created by this plugin (for example, to move another site here).', 'demfilz-backup' ); ?></p>
				<p>
					<input type="file" id="dbgd-upload-file" accept=".zip" />
					<button class="button" id="dbgd-upload-restore"><?php esc_html_e( 'Upload &amp; restore', 'demfilz-backup' ); ?></button>
				</p>
			</div>

			<div class="dbgd-card dbgd-restore-box" id="dbgd-restore-box" hidden>
				<h2><?php esc_html_e( 'Restore', 'demfilz-backup' ); ?></h2>
				<p>
					<label for="dbgd-new-url"><strong><?php esc_html_e( 'New site URL (this site):', 'demfilz-backup' ); ?></strong></label><br />
					<input type="url" id="dbgd-new-url" class="regular-text" value="<?php echo esc_attr( untrailingslashit( home_url() ) ); ?>" />
				</p>
				<p class="description"><?php esc_html_e( 'Restore a backup onto this site. To restore onto a different URL (move or clone a site to a new domain), the Demfilz Backup Pro add-on rewrites every occurrence of the original URL for you.', 'demfilz-backup' ); ?></p>
				<?php if ( ! dbgd_can_migrate() ) : ?>
					<p class="dbgd-pro-note">
						<?php esc_html_e( 'Restoring onto a different URL (site migration) is provided by the separate Demfilz Backup Pro add-on.', 'demfilz-backup' ); ?>
						<a href="https://demfilz.com/demfilz-backup" target="_blank" rel="noopener"><?php esc_html_e( 'Get the add-on', 'demfilz-backup' ); ?></a>
					</p>
				<?php endif; ?>
				<p>
					<button class="button button-primary" id="dbgd-do-restore"><?php esc_html_e( 'Start restore', 'demfilz-backup' ); ?></button>
					<button class="button" id="dbgd-cancel-restore"><?php esc_html_e( 'Cancel', 'demfilz-backup' ); ?></button>
				</p>
				<div class="dbgd-progress" id="dbgd-restore-progress" hidden>
					<div class="dbgd-progress__bar"><span></span></div>
					<p class="dbgd-progress__msg"></p>
				</div>
			</div>

			<div class="dbgd-card">
				<h2><?php esc_html_e( 'Activity log', 'demfilz-backup' ); ?></h2>
				<div class="dbgd-log" id="dbgd-log"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$o         = dbgd_get_option();
		$connected = DBGD_GDrive::is_connected();
		$redirect  = DBGD_GDrive::redirect_uri();
		?>
		<div class="wrap dbgd-wrap">
			<h1><?php esc_html_e( 'Backup Settings', 'demfilz-backup' ); ?></h1>
			<?php $this->print_notice(); ?>

			<?php
			$mode        = DBGD_GDrive::mode();
			$connect_url = wp_nonce_url( admin_url( 'admin.php?page=dbgd-settings&dbgd_oauth=connect' ), 'dbgd_connect' );
			$can_connect = ( 'broker' === $mode ) || ( '' !== $o['client_id'] && '' !== $o['client_secret'] );
			$state       = DBGD_GDrive::state();
			$acct_email  = isset( $state['email'] ) ? $state['email'] : '';
			?>
			<div class="dbgd-card">
				<h2><?php esc_html_e( 'Google Drive connection', 'demfilz-backup' ); ?></h2>
				<?php if ( $connected ) : ?>
					<p>
						<span class="dbgd-dot dbgd-dot--on"></span>
						<?php
						/* translators: %s: connected Google account email. */
						printf( esc_html__( 'Connected%s.', 'demfilz-backup' ), $acct_email ? ' — ' . esc_html( $acct_email ) : '' );
						?>
					</p>
					<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=dbgd-settings&dbgd_oauth=disconnect' ), 'dbgd_disconnect' ) ); ?>"><?php esc_html_e( 'Disconnect', 'demfilz-backup' ); ?></a></p>
				<?php else : ?>
					<?php if ( 'broker' === $mode ) : ?>
						<p class="description"><?php esc_html_e( 'Click below and approve access in your Google account — no Google Cloud setup required.', 'demfilz-backup' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Using your own Google app. Once your Client ID and Secret are saved below, connect here.', 'demfilz-backup' ); ?></p>
					<?php endif; ?>
					<p>
						<a class="button button-primary button-hero dbgd-connect-btn <?php echo $can_connect ? '' : 'disabled'; ?>" href="<?php echo $can_connect ? esc_url( $connect_url ) : '#'; ?>"<?php echo $can_connect ? '' : ' aria-disabled="true"'; ?>>
							<span class="dashicons dashicons-google"></span> <?php esc_html_e( 'Connect with Google', 'demfilz-backup' ); ?>
						</a>
					</p>
					<?php if ( ! $can_connect ) : ?>
						<p class="description"><?php esc_html_e( 'Enter your Google Client ID and Secret under “Advanced” below, save, then click Connect.', 'demfilz-backup' ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'dbgd_settings_group' ); ?>

				<div class="dbgd-card">
					<h2><?php esc_html_e( 'Drive folder', 'demfilz-backup' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="dbgd-folder"><?php esc_html_e( 'Drive folder name', 'demfilz-backup' ); ?></label></th>
							<td><input name="<?php echo esc_attr( DBGD_OPT_SETTINGS ); ?>[drive_folder]" id="dbgd-folder" type="text" class="regular-text" value="<?php echo esc_attr( $o['drive_folder'] ); ?>" /></td>
						</tr>
					</table>

					<details class="dbgd-advanced"<?php echo ( 'own' === $o['auth_mode'] ) ? ' open' : ''; ?>>
						<summary><?php esc_html_e( 'Advanced: connection method', 'demfilz-backup' ); ?></summary>

						<fieldset class="dbgd-authmode">
							<label class="dbgd-check">
								<input type="radio" name="<?php echo esc_attr( DBGD_OPT_SETTINGS ); ?>[auth_mode]" value="broker" <?php checked( 'broker', $o['auth_mode'] ); ?> />
								<strong><?php esc_html_e( 'Automatic (recommended)', 'demfilz-backup' ); ?></strong>
								— <?php esc_html_e( 'one-click connect through Demfilz. No Google Cloud setup.', 'demfilz-backup' ); ?>
							</label>
							<label class="dbgd-check">
								<input type="radio" name="<?php echo esc_attr( DBGD_OPT_SETTINGS ); ?>[auth_mode]" value="own" <?php checked( 'own', $o['auth_mode'] ); ?> />
								<strong><?php esc_html_e( 'Use my own Google app', 'demfilz-backup' ); ?></strong>
								— <?php esc_html_e( 'nothing passes through Demfilz; you create your own OAuth client.', 'demfilz-backup' ); ?>
							</label>
						</fieldset>

						<div class="dbgd-ownkeys">
							<p class="description">
								<?php esc_html_e( 'For “my own Google app”: create an OAuth client (type: Web application) with the Google Drive API enabled, and register this exact redirect URI:', 'demfilz-backup' ); ?>
							</p>
							<p><code class="dbgd-redirect"><?php echo esc_html( $redirect ); ?></code></p>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><label for="dbgd-client-id"><?php esc_html_e( 'Client ID', 'demfilz-backup' ); ?></label></th>
									<td><input name="<?php echo esc_attr( DBGD_OPT_SETTINGS ); ?>[client_id]" id="dbgd-client-id" type="text" class="regular-text" value="<?php echo esc_attr( $o['client_id'] ); ?>" autocomplete="off" /></td>
								</tr>
								<tr>
									<th scope="row"><label for="dbgd-client-secret"><?php esc_html_e( 'Client Secret', 'demfilz-backup' ); ?></label></th>
									<td><input name="<?php echo esc_attr( DBGD_OPT_SETTINGS ); ?>[client_secret]" id="dbgd-client-secret" type="password" class="regular-text" value="<?php echo esc_attr( $o['client_secret'] ); ?>" autocomplete="off" /></td>
								</tr>
							</table>
						</div>
					</details>
				</div>

				<div class="dbgd-card">
					<h2><?php esc_html_e( 'What to back up', 'demfilz-backup' ); ?></h2>
					<?php
					$boxes = array(
						'include_database' => __( 'Database', 'demfilz-backup' ),
						'include_uploads'  => __( 'Uploads (media library)', 'demfilz-backup' ),
						'include_themes'   => __( 'Themes', 'demfilz-backup' ),
						'include_plugins'  => __( 'Plugins', 'demfilz-backup' ),
						'include_content'  => __( 'Other wp-content (mu-plugins, languages…)', 'demfilz-backup' ),
						'include_core'     => __( 'WordPress core files (wp-admin, wp-includes)', 'demfilz-backup' ),
					);
					foreach ( $boxes as $key => $label ) :
						?>
						<label class="dbgd-check">
							<input type="checkbox" name="<?php echo esc_attr( DBGD_OPT_SETTINGS ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( 1, (int) $o[ $key ] ); ?> />
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'wp-config.php and the active backup folder are always excluded. Core files are usually not needed when restoring onto a fresh WordPress install.', 'demfilz-backup' ); ?></p>
				</div>

				<div class="dbgd-card">
					<h2><?php esc_html_e( 'Automatic backups', 'demfilz-backup' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="dbgd-schedule"><?php esc_html_e( 'Schedule', 'demfilz-backup' ); ?></label></th>
							<td>
								<select name="<?php echo esc_attr( DBGD_OPT_SETTINGS ); ?>[schedule]" id="dbgd-schedule">
									<?php
									$opts = array(
										'disabled'     => __( 'Disabled', 'demfilz-backup' ),
										'daily'        => __( 'Daily', 'demfilz-backup' ),
										'dbgd_weekly'  => __( 'Weekly', 'demfilz-backup' ),
										'dbgd_monthly' => __( 'Monthly', 'demfilz-backup' ),
									);
									foreach ( $opts as $val => $label ) {
										printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $o['schedule'], $val, false ), esc_html( $label ) );
									}
									?>
								</select>
								<p class="description"><?php esc_html_e( 'Scheduled backups upload to Google Drive (must be connected).', 'demfilz-backup' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="dbgd-retention"><?php esc_html_e( 'Keep last N backups', 'demfilz-backup' ); ?></label></th>
							<td><input name="<?php echo esc_attr( DBGD_OPT_SETTINGS ); ?>[retention]" id="dbgd-retention" type="number" min="0" class="small-text" value="<?php echo esc_attr( $o['retention'] ); ?>" /> <span class="description"><?php esc_html_e( '0 = keep them all', 'demfilz-backup' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><label for="dbgd-notify"><?php esc_html_e( 'Email report to', 'demfilz-backup' ); ?></label></th>
							<td><input name="<?php echo esc_attr( DBGD_OPT_SETTINGS ); ?>[notify_email]" id="dbgd-notify" type="email" class="regular-text" value="<?php echo esc_attr( $o['notify_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" /></td>
						</tr>
					</table>
				</div>

				<div class="dbgd-card">
					<h2><?php esc_html_e( 'Performance', 'demfilz-backup' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="dbgd-chunk-files"><?php esc_html_e( 'Files per step', 'demfilz-backup' ); ?></label></th>
							<td><input name="<?php echo esc_attr( DBGD_OPT_SETTINGS ); ?>[chunk_files]" id="dbgd-chunk-files" type="number" min="25" max="5000" class="small-text" value="<?php echo esc_attr( $o['chunk_files'] ); ?>" /> <span class="description"><?php esc_html_e( 'Lower this if backups time out.', 'demfilz-backup' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><label for="dbgd-chunk-rows"><?php esc_html_e( 'Database rows per step', 'demfilz-backup' ); ?></label></th>
							<td><input name="<?php echo esc_attr( DBGD_OPT_SETTINGS ); ?>[chunk_rows]" id="dbgd-chunk-rows" type="number" min="200" max="50000" class="small-text" value="<?php echo esc_attr( $o['chunk_rows'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Compression', 'demfilz-backup' ); ?></th>
							<td><label class="dbgd-check"><input type="checkbox" name="<?php echo esc_attr( DBGD_OPT_SETTINGS ); ?>[compression]" value="1" <?php checked( 1, (int) $o['compression'] ); ?> /> <?php esc_html_e( 'Compress files (uncheck for faster backups on already-compressed media)', 'demfilz-backup' ); ?></label></td>
						</tr>
					</table>
				</div>

				<?php submit_button(); ?>
			</form>
			<p class="description"><?php esc_html_e( 'After saving, use “Connect with Google” at the top to authorize Drive.', 'demfilz-backup' ); ?></p>

			<?php if ( ! dbgd_can_migrate() ) : ?>
			<div class="dbgd-card dbgd-pro-card">
				<h2><?php esc_html_e( 'Move a site to a new URL', 'demfilz-backup' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'This plugin backs up your site and restores it onto the same site. To restore a backup onto a different URL — migrating or cloning a site to a new domain, with automatic serialization-safe URL rewriting — install the separate Demfilz Backup Pro add-on.', 'demfilz-backup' ); ?>
				</p>
				<p><a class="button" href="https://demfilz.com/demfilz-backup" target="_blank" rel="noopener"><?php esc_html_e( 'Get Demfilz Backup Pro', 'demfilz-backup' ); ?></a></p>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// === AJAX — backup ===

	/**
	 * Guard: verify nonce + capability for privileged AJAX.
	 */
	private function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'demfilz-backup' ) ), 403 );
		}
		check_ajax_referer( 'dbgd_ajax', 'nonce' );
	}

	/**
	 * AJAX: start a backup.
	 */
	public function ajax_backup_start() {
		$this->guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Nonce/capability verified in guard() or token verified in restore_token_guard().
		$dest = isset( $_POST['destination'] ) && 'local' === $_POST['destination'] ? 'local' : 'drive';
		$job  = DBGD_Backup::start( $dest );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message' => $job->get_error_message() ) );
		}
		wp_send_json_success( $this->job_public( $job ) );
	}

	/**
	 * AJAX: run one backup step.
	 */
	public function ajax_backup_step() {
		$this->guard();
		$job = DBGD_Backup::run_step();
		wp_send_json_success( $this->job_public( $job ) );
	}

	/**
	 * AJAX: current backup status + log.
	 */
	public function ajax_backup_status() {
		$this->guard();
		$job = get_option( DBGD_OPT_JOB, array() );
		wp_send_json_success( $this->job_public( is_array( $job ) ? $job : array() ) );
	}

	/**
	 * Shape a backup job for the browser (drop heavy/internal fields).
	 *
	 * @param array $job Job.
	 * @return array
	 */
	private function job_public( $job ) {
		return array(
			'status'   => isset( $job['status'] ) ? $job['status'] : 'idle',
			'stage'    => isset( $job['stage'] ) ? $job['stage'] : '',
			'progress' => isset( $job['progress'] ) ? (int) $job['progress'] : 0,
			'message'  => isset( $job['message'] ) ? $job['message'] : '',
			'error'    => isset( $job['error'] ) ? $job['error'] : '',
			'log'      => $this->format_log( DBGD_Logger::all() ),
		);
	}

	/**
	 * Format log entries for display.
	 *
	 * @param array $log Raw log.
	 * @return array
	 */
	private function format_log( $log ) {
		$out = array();
		foreach ( $log as $entry ) {
			$out[] = array(
				'time'  => isset( $entry['time'] ) ? gmdate( 'H:i:s', (int) $entry['time'] ) : '',
				'level' => isset( $entry['level'] ) ? $entry['level'] : 'info',
				'msg'   => isset( $entry['msg'] ) ? $entry['msg'] : '',
			);
		}
		return $out;
	}

	// === AJAX — Drive list / delete / upload ===

	/**
	 * AJAX: list backups (Drive + local).
	 */
	public function ajax_list_drive() {
		$this->guard();
		$items = array();

		// Local registry.
		$local = get_option( DBGD_OPT_BACKUPS, array() );
		if ( is_array( $local ) ) {
			foreach ( $local as $entry ) {
				$has_local = ! empty( $entry['path'] ) && is_file( $entry['path'] );
				$items[]   = array(
					'key'      => 'local:' . $entry['id'],
					'name'     => $entry['name'],
					'size'     => isset( $entry['size'] ) ? size_format( (int) $entry['size'] ) : '',
					'where'    => $has_local ? __( 'This server', 'demfilz-backup' ) : ( ! empty( $entry['drive_id'] ) ? __( 'Google Drive', 'demfilz-backup' ) : __( 'Removed', 'demfilz-backup' ) ),
					'source'   => $has_local ? 'local' : 'drive',
					'path'     => $has_local ? $entry['path'] : '',
					'drive_id' => isset( $entry['drive_id'] ) ? $entry['drive_id'] : '',
					'can_dl'   => $has_local,
				);
			}
		}

		// Drive listing (authoritative for what's stored remotely).
		if ( DBGD_GDrive::is_connected() ) {
			$files = DBGD_GDrive::list_files();
			if ( is_wp_error( $files ) ) {
				wp_send_json_error( array( 'message' => $files->get_error_message() ) );
			}
			$seen_names = wp_list_pluck( $items, 'name' );
			foreach ( $files as $f ) {
				if ( in_array( $f['name'], $seen_names, true ) ) {
					continue;
				}
				$items[] = array(
					'key'      => 'drive:' . $f['id'],
					'name'     => $f['name'],
					'size'     => isset( $f['size'] ) ? size_format( (int) $f['size'] ) : '',
					'where'    => __( 'Google Drive', 'demfilz-backup' ),
					'source'   => 'drive',
					'path'     => '',
					'drive_id' => $f['id'],
					'can_dl'   => false,
				);
			}
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

	/**
	 * AJAX: delete a backup (Drive + local registry/file).
	 */
	public function ajax_delete_backup() {
		$this->guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Nonce/capability verified in guard() or token verified in restore_token_guard().
		$drive_id = isset( $_POST['drive_id'] ) ? sanitize_text_field( wp_unslash( $_POST['drive_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Nonce/capability verified in guard() or token verified in restore_token_guard().
		$path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';

		if ( '' !== $drive_id && DBGD_GDrive::is_connected() ) {
			DBGD_GDrive::delete_file( $drive_id );
		}
		// Only allow deleting files inside our backup dir.
		if ( '' !== $path ) {
			$path = wp_normalize_path( $path );
			$base = wp_normalize_path( dbgd_backup_dir() );
			if ( 0 === strpos( $path, $base ) && is_file( $path ) ) {
				dbgd_delete_path( $path );
			}
		}

		// Tidy the registry.
		$local = get_option( DBGD_OPT_BACKUPS, array() );
		if ( is_array( $local ) ) {
			foreach ( $local as $id => $entry ) {
				if ( ( '' !== $drive_id && isset( $entry['drive_id'] ) && $entry['drive_id'] === $drive_id ) || ( '' !== $path && isset( $entry['path'] ) && wp_normalize_path( $entry['path'] ) === $path ) ) {
					unset( $local[ $id ] );
				}
			}
			update_option( DBGD_OPT_BACKUPS, $local, false );
		}

		wp_send_json_success( array( 'message' => __( 'Backup deleted.', 'demfilz-backup' ) ) );
	}

	/**
	 * AJAX: receive an uploaded zip to restore.
	 */
	public function ajax_upload_restore() {
		$this->guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Nonce/capability verified in guard() or token verified in restore_token_guard().
		if ( empty( $_FILES['file'] ) || ! isset( $_FILES['file']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'demfilz-backup' ) ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Nonce/capability verified in guard() or token verified in restore_token_guard().
		$name = isset( $_FILES['file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) ) : 'upload.zip';
		if ( '.zip' !== strtolower( substr( $name, -4 ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Please upload a .zip backup file.', 'demfilz-backup' ) ) );
		}
		$dir = dbgd_prepare_backup_dir();
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Route WordPress's own upload handler into our protected backup directory.
		$backup_dir = untrailingslashit( $dir );
		$to_backups = function ( $dirs ) use ( $backup_dir ) {
			$dirs['path']   = $backup_dir;
			$dirs['url']    = '';
			$dirs['subdir'] = '';
			return $dirs;
		};
		add_filter( 'upload_dir', $to_backups );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing -- Nonce/cap verified in guard(); wp_handle_upload validates and sanitises the uploaded file.
		$uploaded = $_FILES['file'];
		$result   = wp_handle_upload(
			$uploaded,
			array(
				'test_form' => false,
				'mimes'     => array( 'zip' => 'application/zip' ),
			)
		);
		remove_filter( 'upload_dir', $to_backups );

		if ( ! is_array( $result ) || isset( $result['error'] ) ) {
			$msg = ( is_array( $result ) && isset( $result['error'] ) ) ? $result['error'] : __( 'Could not save the uploaded file.', 'demfilz-backup' );
			wp_send_json_error( array( 'message' => $msg ) );
		}
		wp_send_json_success( array( 'path' => wp_normalize_path( $result['file'] ) ) );
	}

	// === AJAX — restore ===

	/**
	 * AJAX: start a restore (privileged).
	 */
	public function ajax_restore_start() {
		$this->guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Nonce/capability verified in guard() or token verified in restore_token_guard().
		$source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : 'local';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Nonce/capability verified in guard() or token verified in restore_token_guard().
		$new_url = isset( $_POST['new_url'] ) ? esc_url_raw( wp_unslash( $_POST['new_url'] ) ) : home_url();
		$args    = array(
			'source'  => $source,
			'new_url' => $new_url,
		);
		if ( 'drive' === $source ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Nonce/capability verified in guard() or token verified in restore_token_guard().
			$args['drive_id'] = isset( $_POST['drive_id'] ) ? sanitize_text_field( wp_unslash( $_POST['drive_id'] ) ) : '';
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Nonce/capability verified in guard() or token verified in restore_token_guard().
			$path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
			$path = wp_normalize_path( $path );
			$base = wp_normalize_path( dbgd_backup_dir() );
			if ( '' === $path || 0 !== strpos( $path, $base ) || ! is_file( $path ) ) {
				wp_send_json_error( array( 'message' => __( 'Backup file not found.', 'demfilz-backup' ) ) );
			}
			$args['archive'] = $path;

			// For a local/uploaded backup, read the manifest now and decline a
			// cross-URL migration before anything starts (unless the add-on adds it).
			$manifest = DBGD_Restore::peek_manifest( $path );
			if ( $manifest && dbgd_is_url_migration( $manifest, $new_url ) && ! dbgd_can_migrate() ) {
				wp_send_json_error(
					array(
						'message'   => sprintf(
							/* translators: 1: source URL, 2: target URL. */
							__( 'This backup is from %1$s. Restoring onto a different URL (%2$s) is provided by the Demfilz Backup Pro add-on.', 'demfilz-backup' ),
							untrailingslashit( isset( $manifest['site_url'] ) ? $manifest['site_url'] : '' ),
							untrailingslashit( $new_url )
						),
						'addon_url' => 'https://demfilz.com/demfilz-backup',
						'need_pro'  => true,
					)
				);
			}
		}

		$state = DBGD_Restore::start( $args );
		if ( is_wp_error( $state ) ) {
			wp_send_json_error( array( 'message' => $state->get_error_message() ) );
		}
		wp_send_json_success( $this->restore_public( $state ) );
	}

	/**
	 * AJAX: run one restore step (token-guarded; survives DB swap).
	 */
	public function ajax_restore_step() {
		$this->restore_token_guard();
		$state = DBGD_Restore::run_step();
		wp_send_json_success( $this->restore_public( $state ) );
	}

	/**
	 * AJAX: restore status (token-guarded).
	 */
	public function ajax_restore_status() {
		$this->restore_token_guard();
		$state = DBGD_Restore::get_state();
		wp_send_json_success( $this->restore_public( $state ) );
	}

	/**
	 * Validate the restore token against the file-based state.
	 */
	private function restore_token_guard() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Nonce/capability verified in guard() or token verified in restore_token_guard().
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$state = DBGD_Restore::get_state();
		$valid = ! empty( $state['token'] ) && '' !== $token && hash_equals( (string) $state['token'], $token );
		// A logged-in admin is also fine (before the DB is swapped).
		if ( ! $valid && ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'Restore session is invalid.', 'demfilz-backup' ) ), 403 );
		}
	}

	/**
	 * Shape restore state for the browser.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private function restore_public( $state ) {
		return array(
			'status'   => isset( $state['status'] ) ? $state['status'] : 'idle',
			'stage'    => isset( $state['stage'] ) ? $state['stage'] : '',
			'progress' => isset( $state['progress'] ) ? (int) $state['progress'] : 0,
			'message'  => isset( $state['message'] ) ? $state['message'] : '',
			'error'    => isset( $state['error'] ) ? $state['error'] : '',
			'token'    => isset( $state['token'] ) ? $state['token'] : '',
			'log'      => isset( $state['log'] ) ? $this->format_log( $state['log'] ) : array(),
		);
	}

	// === Download ===

	/**
	 * Stream a local backup file to the browser.
	 */
	public function handle_download() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'demfilz-backup' ) );
		}
		check_admin_referer( 'dbgd_download' );
		$path = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';
		$path = wp_normalize_path( $path );
		$base = wp_normalize_path( dbgd_backup_dir() );
		if ( '' === $path || 0 !== strpos( $path, $base ) || ! is_file( $path ) ) {
			wp_die( esc_html__( 'File not found.', 'demfilz-backup' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming the backup file to the browser.
		readfile( $path );
		exit;
	}
}
