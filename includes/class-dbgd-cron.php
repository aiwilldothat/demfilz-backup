<?php
/**
 * Scheduled (automatic) backups via WP-Cron.
 *
 * @package Demfilz_Backup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers schedules and drives the staged backup engine to completion.
 */
class DBGD_Cron {

	const CONTINUE_HOOK = 'dbgd_backup_continue';

	/**
	 * Wire up cron hooks and custom intervals.
	 */
	public function __construct() {
		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );
		add_action( DBGD_CRON_HOOK, array( $this, 'run_scheduled' ) );
		add_action( self::CONTINUE_HOOK, array( $this, 'run_loop' ) );
	}

	/**
	 * Add weekly/monthly intervals (daily exists in core).
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_schedules( $schedules ) {
		$schedules['dbgd_weekly']  = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly (Demfilz Backup)', 'demfilz-backup' ),
		);
		$schedules['dbgd_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once Monthly (Demfilz Backup)', 'demfilz-backup' ),
		);
		return $schedules;
	}

	/**
	 * Apply the saved schedule setting: (re)schedule or clear the event.
	 */
	public static function reschedule() {
		$schedule = dbgd_get_option( 'schedule' );
		$existing = wp_next_scheduled( DBGD_CRON_HOOK );

		if ( 'disabled' === $schedule || '' === $schedule ) {
			if ( $existing ) {
				wp_unschedule_event( $existing, DBGD_CRON_HOOK );
			}
			return;
		}

		$interval = 'daily';
		if ( 'dbgd_weekly' === $schedule ) {
			$interval = 'dbgd_weekly';
		} elseif ( 'dbgd_monthly' === $schedule ) {
			$interval = 'dbgd_monthly';
		}

		// Reschedule cleanly if the interval changed.
		if ( $existing ) {
			$event = wp_get_scheduled_event( DBGD_CRON_HOOK );
			if ( $event && isset( $event->schedule ) && $event->schedule === $interval ) {
				return; // Already correct.
			}
			wp_unschedule_event( $existing, DBGD_CRON_HOOK );
		}

		// First run ~10 minutes out, then on the interval.
		wp_schedule_event( time() + 600, $interval, DBGD_CRON_HOOK );
	}

	/**
	 * Cron entry point: ensure a backup is running, then drive it.
	 */
	public function run_scheduled() {
		if ( ! DBGD_GDrive::is_connected() ) {
			DBGD_Logger::add( __( 'Scheduled backup skipped: Google Drive is not connected.', 'demfilz-backup' ), 'warning' );
			return;
		}
		$job     = get_option( DBGD_OPT_JOB, array() );
		$running = is_array( $job ) && isset( $job['status'] ) && 'running' === $job['status'];
		if ( ! $running ) {
			$started = DBGD_Backup::start( 'drive' );
			if ( is_wp_error( $started ) ) {
				DBGD_Logger::add( 'Scheduled backup could not start: ' . $started->get_error_message(), 'error' );
				return;
			}
		}
		$this->run_loop();
	}

	/**
	 * Run backup steps in a loop within a wall-clock budget; continue later if
	 * the job is not finished.
	 */
	public function run_loop() {
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions
			@set_time_limit( 0 );
		}
		$budget = 25; // seconds.
		$start  = time();

		do {
			$job = DBGD_Backup::run_step();
			if ( ! is_array( $job ) ) {
				break;
			}
			if ( in_array( isset( $job['status'] ) ? $job['status'] : '', array( 'done', 'error' ), true ) ) {
				if ( 'error' === $job['status'] ) {
					DBGD_Backup::maybe_notify( $job, false );
				}
				return;
			}
		} while ( ( time() - $start ) < $budget );

		// Not finished within the budget — schedule an immediate continuation.
		if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::CONTINUE_HOOK );
		}
	}
}
