<?php
/**
 * Activation, table creation and scheduling.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

namespace NvrHs;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the plugin's tables and cron schedule.
 */
final class Installer {

	public const DB_VERSION        = '2';
	public const OPTION_DB_VERSION = 'nvr_hs_db_version';

	public const CRON_QUEUE   = 'nvr_hs_run_queue';
	public const CRON_CARTS   = 'nvr_hs_sweep_carts';
	public const CRON_SEGMENT = 'nvr_hs_resegment';
	public const CRON_PRUNE   = 'nvr_hs_prune_logs';

	public static function activate(): void {
		self::create_tables();
		self::ensure_schedule();
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	public static function deactivate(): void {
		foreach ( array( self::CRON_QUEUE, self::CRON_CARTS, self::CRON_SEGMENT, self::CRON_PRUNE ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Run table creation when the stored version is behind. Called on every
	 * boot so an update via git or rsync, which never fires the activation
	 * hook, still gets its schema.
	 */
	public static function maybe_upgrade(): void {
		$stored = (string) get_option( self::OPTION_DB_VERSION, '0' );

		if ( $stored === self::DB_VERSION ) {
			return;
		}

		self::create_tables();
		self::ensure_schedule();

		// v2: release dedupe keys still held by jobs that had already settled.
		//
		// Queue::settle() now clears the key when a job finishes, but rows
		// written before that change still hold theirs, and a held key blocks
		// every future enqueue for the same order. Without this an existing
		// install would carry on silently dropping deal stage updates for any
		// order it had synced once already.
		if ( version_compare( $stored, '2', '<' ) ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$released = (int) $wpdb->query(
				'UPDATE ' . Queue::table() . " SET dedupe_key = NULL WHERE status IN ('done','failed') AND dedupe_key IS NOT NULL"
			);

			if ( $released > 0 ) {
				( new Logger( new Settings() ) )->error(
					'migrate_dedupe_release',
					sprintf( 'Released %d stale dedupe key(s) from settled jobs.', $released ),
					array( 'from_version' => $stored )
				);
			}
		}

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	/**
	 * Register any cron event that is not currently scheduled.
	 *
	 * Public and idempotent so it can run on every request. The two custom
	 * intervals only exist once the cron_schedules filter has run, which is
	 * why nvr-hs.php registers that filter at file scope.
	 */
	public static function ensure_schedule(): void {
		// Queue drains often; everything else is housekeeping.
		if ( ! wp_next_scheduled( self::CRON_QUEUE ) ) {
			wp_schedule_event( time() + 60, 'nvr_hs_minute', self::CRON_QUEUE );
		}
		if ( ! wp_next_scheduled( self::CRON_CARTS ) ) {
			wp_schedule_event( time() + 300, 'nvr_hs_five_minutes', self::CRON_CARTS );
		}
		if ( ! wp_next_scheduled( self::CRON_SEGMENT ) ) {
			wp_schedule_event( time() + 900, 'hourly', self::CRON_SEGMENT );
		}
		if ( ! wp_next_scheduled( self::CRON_PRUNE ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::CRON_PRUNE );
		}
	}

	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$log     = Logger::table();
		$queue   = Queue::table();
		$carts   = Sync\Cart_Recovery::table();

		// Note on index choices: the log is written far more than it is read,
		// so it carries only what the admin screen filters on. The queue is
		// polled every minute by exactly one query, which the status/available
		// index covers. The carts table is looked up by session and by token
		// on every recovery link click, so both are unique.
		$sql = "
		CREATE TABLE {$log} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created DATETIME NOT NULL,
			level VARCHAR(10) NOT NULL,
			code VARCHAR(64) NOT NULL,
			message VARCHAR(1000) NOT NULL,
			context LONGTEXT NULL,
			PRIMARY KEY (id),
			KEY level_created (level, created),
			KEY created (created)
		) {$charset};

		CREATE TABLE {$queue} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created DATETIME NOT NULL,
			available_at DATETIME NOT NULL,
			job VARCHAR(64) NOT NULL,
			payload LONGTEXT NULL,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(12) NOT NULL DEFAULT 'pending',
			dedupe_key VARCHAR(191) NULL,
			last_error VARCHAR(500) NULL,
			PRIMARY KEY (id),
			KEY status_available (status, available_at),
			UNIQUE KEY dedupe (dedupe_key)
		) {$charset};

		CREATE TABLE {$carts} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_key VARCHAR(64) NOT NULL,
			recovery_token VARCHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			email VARCHAR(191) NULL,
			cart_total DECIMAL(18,4) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT '',
			item_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			contents LONGTEXT NULL,
			created DATETIME NOT NULL,
			updated DATETIME NOT NULL,
			abandoned_at DATETIME NULL,
			notified_at DATETIME NULL,
			recovered_order_id BIGINT UNSIGNED NULL,
			PRIMARY KEY (id),
			UNIQUE KEY session_key (session_key),
			UNIQUE KEY recovery_token (recovery_token),
			KEY sweep (abandoned_at, notified_at, updated),
			KEY email (email)
		) {$charset};
		";

		dbDelta( $sql );
	}

	/**
	 * Custom cron intervals. WordPress ships nothing shorter than hourly.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules Existing schedules.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function cron_schedules( array $schedules ): array {
		$schedules['nvr_hs_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every minute (Navira HubSpot)', 'nvr-hs' ),
		);
		$schedules['nvr_hs_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every five minutes (Navira HubSpot)', 'nvr-hs' ),
		);
		return $schedules;
	}
}
