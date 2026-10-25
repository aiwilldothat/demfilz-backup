<?php
/**
 * Google Drive client (OAuth2 + resumable upload/download), pure WP HTTP API.
 *
 * The site owner supplies their own Google Cloud OAuth client (ID + secret).
 * We request only the "drive.file" scope, so this plugin can only ever see or
 * touch the files it created itself — never the rest of the user's Drive.
 *
 * @package Demfilz_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal Google Drive v3 wrapper.
 */
class DBGD_GDrive {

	const SCOPE      = 'https://www.googleapis.com/auth/drive.file';
	const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	const API_BASE   = 'https://www.googleapis.com/drive/v3';
	const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';

	/**
	 * Resumable upload chunk size (must be a multiple of 256 KiB).
	 *
	 * @var int
	 */
	const CHUNK = 8388608; // 8 MiB.

	/**
	 * Get the stored Drive token/state bag.
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( DBGD_OPT_GDRIVE, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persist the Drive token/state bag.
	 *
	 * @param array $state State.
	 */
	public static function save_state( $state ) {
		update_option( DBGD_OPT_GDRIVE, $state, false );
	}

	/**
	 * Whether we have a refresh token (i.e. an account is connected).
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$state = self::state();
		return ! empty( $state['refresh_token'] );
	}

	/**
	 * The OAuth redirect URI (this plugin's admin page). Google (own-app mode)
	 * or the Demfilz broker (broker mode) sends the browser back here.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin.php?page=dbgd-settings&dbgd_oauth=callback' );
	}

	/**
	 * Which authorization method is in use: 'broker' (one-click, default) or
	 * 'own' (the site owner supplied their own Google OAuth client).
	 *
	 * @return string
	 */
	public static function mode() {
		$mode = dbgd_get_option( 'auth_mode', 'broker' );
		return ( 'own' === $mode ) ? 'own' : 'broker';
	}

	/**
	 * Base URL of the hosted Demfilz Google OAuth broker (no trailing slash).
	 *
	 * The broker holds a single verified Google app so end users can connect in
	 * one click without creating their own Google Cloud project. Filterable so it
	 * can be pointed at a self-hosted broker.
	 *
	 * @return string
	 */
	public static function broker_base() {
		$url = defined( 'DBGD_BROKER_URL' ) ? DBGD_BROKER_URL : 'https://api.demfilz.com/gdrive';
		/**
		 * Filter the Google OAuth broker base URL.
		 *
		 * @param string $url Broker base URL, no trailing slash.
		 */
		return untrailingslashit( apply_filters( 'dbgd_broker_url', $url ) );
	}

	/**
	 * Compute base64url( sha256( verifier ) ) — the PKCE-style challenge sent to
	 * the broker so only the site that started the flow can claim the tokens.
	 *
	 * @param string $verifier Random verifier.
	 * @return string
	 */
	public static function pkce_challenge( $verifier ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url encoding of a hash for the OAuth PKCE challenge, not obfuscation.
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Build the one-click broker sign-in URL (PKCE-style, no client secret here).
	 *
	 * @param string $state     CSRF/state token.
	 * @param string $challenge base64url( sha256( verifier ) ).
	 * @return string
	 */
	public static function broker_auth_url( $state, $challenge ) {
		$args = array(
			'callback'  => self::redirect_uri(),
			'state'     => $state,
			'challenge' => $challenge,
			'site'      => home_url(),
			'v'         => DBGD_VERSION,
		);
		return self::broker_base() . '/start?' . http_build_query( $args );
	}

	/**
	 * Exchange a broker claim code (+ our verifier) for tokens, and store them.
	 *
	 * @param string $claim    One-time claim code from the broker callback.
	 * @param string $verifier The PKCE verifier we generated at connect time.
	 * @return true|WP_Error
	 */
	public static function claim_from_broker( $claim, $verifier ) {
		$response = wp_remote_post(
			self::broker_base() . '/claim',
			array(
				'timeout' => 30,
				'body'    => array(
					'claim'    => $claim,
					'verifier' => $verifier,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['refresh_token'] ) || empty( $body['access_token'] ) ) {
			$msg = isset( $body['error'] ) ? $body['error'] : __( 'The connection could not be completed. Please try again.', 'demfilz-backup' );
			return new WP_Error( 'dbgd_claim', $msg );
		}

		$state                  = self::state();
		$state['mode']          = 'broker';
		$state['access_token']  = $body['access_token'];
		$state['refresh_token'] = $body['refresh_token'];
		$state['expires_at']    = time() + ( isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3500 ) - 60;
		if ( ! empty( $body['email'] ) ) {
			$state['email'] = sanitize_email( $body['email'] );
		}
		self::save_state( $state );
		return true;
	}

	/**
	 * Build the Google consent screen URL.
	 *
	 * @param string $state CSRF state token.
	 * @return string|WP_Error
	 */
	public static function auth_url( $state ) {
		$client_id = trim( (string) dbgd_get_option( 'client_id' ) );
		if ( '' === $client_id ) {
			return new WP_Error( 'dbgd_no_client', __( 'Enter your Google OAuth Client ID and Secret first.', 'demfilz-backup' ) );
		}
		$args = array(
			'client_id'              => $client_id,
			'redirect_uri'           => self::redirect_uri(),
			'response_type'          => 'code',
			'scope'                  => self::SCOPE,
			'access_type'            => 'offline',
			'include_granted_scopes' => 'true',
			'prompt'                 => 'consent',
			'state'                  => $state,
		);
		return self::AUTH_URL . '?' . http_build_query( $args );
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @param string $code Authorization code from Google.
	 * @return true|WP_Error
	 */
	public static function exchange_code( $code ) {
		$client_id     = trim( (string) dbgd_get_option( 'client_id' ) );
		$client_secret = trim( (string) dbgd_get_option( 'client_secret' ) );

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$msg = isset( $body['error_description'] ) ? $body['error_description'] : __( 'Google did not return an access token.', 'demfilz-backup' );
			return new WP_Error( 'dbgd_token', $msg );
		}

		$state                 = self::state();
		$state['mode']         = 'own';
		$state['access_token'] = $body['access_token'];
		$state['expires_at']   = time() + ( isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3500 ) - 60;
		if ( ! empty( $body['refresh_token'] ) ) {
			$state['refresh_token'] = $body['refresh_token'];
		}
		self::save_state( $state );

		// Best-effort: record which account connected.
		$email = self::fetch_account_email();
		if ( $email ) {
			$state          = self::state();
			$state['email'] = $email;
			self::save_state( $state );
		}

		return true;
	}

	/**
	 * Get a valid access token, refreshing when expired.
	 *
	 * @return string|WP_Error
	 */
	public static function access_token() {
		$state = self::state();
		if ( ! empty( $state['access_token'] ) && ! empty( $state['expires_at'] ) && time() < (int) $state['expires_at'] ) {
			return $state['access_token'];
		}
		return self::refresh();
	}

	/**
	 * Use the refresh token to obtain a new access token.
	 *
	 * @return string|WP_Error
	 */
	public static function refresh() {
		$state = self::state();
		if ( empty( $state['refresh_token'] ) ) {
			return new WP_Error( 'dbgd_no_refresh', __( 'Google Drive is not connected.', 'demfilz-backup' ) );
		}

		// Connections made through the broker are refreshed through the broker
		// (the client secret lives there, never on the site).
		$via_broker = ( 'broker' === ( isset( $state['mode'] ) ? $state['mode'] : self::mode() ) );

		if ( $via_broker ) {
			$response = wp_remote_post(
				self::broker_base() . '/refresh',
				array(
					'timeout' => 30,
					'body'    => array( 'refresh_token' => $state['refresh_token'] ),
				)
			);
		} else {
			$response = wp_remote_post(
				self::TOKEN_URL,
				array(
					'timeout' => 30,
					'body'    => array(
						'client_id'     => trim( (string) dbgd_get_option( 'client_id' ) ),
						'client_secret' => trim( (string) dbgd_get_option( 'client_secret' ) ),
						'refresh_token' => $state['refresh_token'],
						'grant_type'    => 'refresh_token',
					),
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$msg = isset( $body['error_description'] ) ? $body['error_description'] : ( isset( $body['error'] ) ? $body['error'] : __( 'Could not refresh the Google access token. Try reconnecting.', 'demfilz-backup' ) );
			return new WP_Error( 'dbgd_refresh', $msg );
		}
		$state['access_token'] = $body['access_token'];
		$state['expires_at']   = time() + ( isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3500 ) - 60;
		self::save_state( $state );
		return $state['access_token'];
	}

	/**
	 * Disconnect: forget tokens (does not delete anything on Drive).
	 */
	public static function disconnect() {
		delete_option( DBGD_OPT_GDRIVE );
	}

	/**
	 * Fetch the connected account's email (for display only).
	 *
	 * @return string
	 */
	private static function fetch_account_email() {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return '';
		}
		$response = wp_remote_get(
			'https://www.googleapis.com/oauth2/v2/userinfo',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return isset( $body['email'] ) ? sanitize_email( $body['email'] ) : '';
	}

	/**
	 * Find or create the backups folder; returns its Drive file ID.
	 *
	 * @return string|WP_Error
	 */
	public static function ensure_folder() {
		$state = self::state();
		$name  = trim( (string) dbgd_get_option( 'drive_folder' ) );
		if ( '' === $name ) {
			$name = 'Demfilz Backups';
		}

		// Reuse the stored folder if it still exists.
		if ( ! empty( $state['folder_id'] ) ) {
			$check = self::api( 'GET', '/files/' . rawurlencode( $state['folder_id'] ) . '?fields=id,trashed' );
			if ( ! is_wp_error( $check ) && empty( $check['trashed'] ) && ! empty( $check['id'] ) ) {
				return $state['folder_id'];
			}
		}

		// Search for a folder we created with this name.
		$query  = "mimeType='application/vnd.google-apps.folder' and name='" . str_replace( "'", "\\'", $name ) . "' and trashed=false";
		$search = self::api( 'GET', '/files?q=' . rawurlencode( $query ) . '&fields=files(id,name)&pageSize=10' );
		if ( ! is_wp_error( $search ) && ! empty( $search['files'][0]['id'] ) ) {
			$id                 = $search['files'][0]['id'];
			$state              = self::state();
			$state['folder_id'] = $id;
			self::save_state( $state );
			return $id;
		}

		// Create it.
		$created = self::api(
			'POST',
			'/files?fields=id',
			array(
				'name'     => $name,
				'mimeType' => 'application/vnd.google-apps.folder',
			)
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		if ( empty( $created['id'] ) ) {
			return new WP_Error( 'dbgd_folder', __( 'Could not create the Google Drive folder.', 'demfilz-backup' ) );
		}
		$state              = self::state();
		$state['folder_id'] = $created['id'];
		self::save_state( $state );
		return $created['id'];
	}

	/**
	 * Make a JSON Drive API call.
	 *
	 * @param string $method   HTTP method.
	 * @param string $path     Path beginning with /, relative to API_BASE.
	 * @param mixed  $body     Optional JSON-encodable body.
	 * @param bool   $retried  Internal: whether this is a post-401 retry.
	 * @return array|WP_Error Decoded JSON (array) or error.
	 */
	private static function api( $method, $path, $body = null, $retried = false ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$response = wp_remote_request( self::API_BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code && ! $retried ) {
			$refresh = self::refresh();
			if ( is_wp_error( $refresh ) ) {
				return $refresh;
			}
			return self::api( $method, $path, $body, true );
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 ) {
			$msg = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : sprintf( /* translators: %d: HTTP status code. */ __( 'Google Drive API error (%d).', 'demfilz-backup' ), $code );
			return new WP_Error( 'dbgd_api', $msg );
		}
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * List backup files in the Drive folder.
	 *
	 * @return array|WP_Error Array of files (id,name,size,createdTime).
	 */
	public static function list_files() {
		$folder = self::ensure_folder();
		if ( is_wp_error( $folder ) ) {
			return $folder;
		}
		$query = "'" . $folder . "' in parents and trashed=false";
		$res   = self::api( 'GET', '/files?q=' . rawurlencode( $query ) . '&orderBy=createdTime desc&pageSize=200&fields=files(id,name,size,createdTime)' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return isset( $res['files'] ) ? $res['files'] : array();
	}

	/**
	 * Delete a Drive file by ID.
	 *
	 * @param string $file_id File ID.
	 * @return true|WP_Error
	 */
	public static function delete_file( $file_id ) {
		$res = self::api( 'DELETE', '/files/' . rawurlencode( $file_id ) );
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * Begin a resumable upload session.
	 *
	 * @param string $name      Destination file name.
	 * @param string $folder_id Parent folder ID.
	 * @param int    $size      Total file size in bytes.
	 * @return string|WP_Error Session URI.
	 */
	public static function start_resumable( $name, $folder_id, $size ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$response = wp_remote_post(
			self::UPLOAD_URL . '?uploadType=resumable',
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization'           => 'Bearer ' . $token,
					'Content-Type'            => 'application/json; charset=UTF-8',
					'X-Upload-Content-Type'   => 'application/zip',
					'X-Upload-Content-Length' => (string) $size,
				),
				'body'    => wp_json_encode(
					array(
						'name'    => $name,
						'parents' => array( $folder_id ),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code && 201 !== $code ) {
			return new WP_Error( 'dbgd_resumable', sprintf( /* translators: %d: HTTP status code. */ __( 'Could not start the upload (HTTP %d).', 'demfilz-backup' ), $code ) );
		}
		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( ! $location ) {
			return new WP_Error( 'dbgd_resumable', __( 'Google did not return an upload session URL.', 'demfilz-backup' ) );
		}
		return $location;
	}

	/**
	 * Upload one chunk to a resumable session.
	 *
	 * @param string $session_uri Session URI from start_resumable().
	 * @param string $chunk       Raw bytes for this chunk.
	 * @param int    $offset      Byte offset where this chunk starts.
	 * @param int    $total       Total file size.
	 * @return array|WP_Error { string status (incomplete|done), int next_offset, string file_id }
	 */
	public static function upload_chunk( $session_uri, $chunk, $offset, $total ) {
		$length   = strlen( $chunk );
		$end      = $offset + $length - 1;
		$response = wp_remote_request(
			$session_uri,
			array(
				'method'  => 'PUT',
				'timeout' => 120,
				'headers' => array(
					'Content-Length' => (string) $length,
					'Content-Range'  => 'bytes ' . $offset . '-' . $end . '/' . $total,
				),
				'body'    => $chunk,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 308 === $code ) {
			// Resume incomplete. Use the Range header to learn the confirmed offset.
			$range = wp_remote_retrieve_header( $response, 'range' );
			$next  = $offset + $length;
			if ( $range && preg_match( '/bytes=\d+-(\d+)/', $range, $m ) ) {
				$next = (int) $m[1] + 1;
			}
			return array(
				'status'      => 'incomplete',
				'next_offset' => $next,
				'file_id'     => '',
			);
		}
		if ( 200 === $code || 201 === $code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			return array(
				'status'      => 'done',
				'next_offset' => $total,
				'file_id'     => isset( $body['id'] ) ? $body['id'] : '',
			);
		}
		return new WP_Error( 'dbgd_chunk', sprintf( /* translators: %d: HTTP status code. */ __( 'Upload chunk failed (HTTP %d).', 'demfilz-backup' ), $code ) );
	}

	/**
	 * Get a Drive file's size in bytes.
	 *
	 * @param string $file_id File ID.
	 * @return int|WP_Error
	 */
	public static function file_size( $file_id ) {
		$res = self::api( 'GET', '/files/' . rawurlencode( $file_id ) . '?fields=size' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return isset( $res['size'] ) ? (int) $res['size'] : 0;
	}

	/**
	 * Download a byte range of a Drive file.
	 *
	 * @param string $file_id File ID.
	 * @param int    $offset  Start byte.
	 * @param int    $length  Number of bytes.
	 * @return string|WP_Error Raw bytes.
	 */
	public static function download_chunk( $file_id, $offset, $length ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$end      = $offset + $length - 1;
		$response = wp_remote_get(
			self::API_BASE . '/files/' . rawurlencode( $file_id ) . '?alt=media',
			array(
				'timeout' => 120,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Range'         => 'bytes=' . $offset . '-' . $end,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code && 206 !== $code ) {
			return new WP_Error( 'dbgd_download', sprintf( /* translators: %d: HTTP status code. */ __( 'Download failed (HTTP %d).', 'demfilz-backup' ), $code ) );
		}
		return wp_remote_retrieve_body( $response );
	}
}
