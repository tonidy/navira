<?php
/**
 * Logging to a dedicated table.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

namespace NvrHs;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to the plugin's own log table.
 *
 * A dedicated table rather than error_log because the interesting failures
 * here are per-order and per-contact, and support questions are always "what
 * happened to order 1234", which wants a queryable row.
 */
final class Logger {

	public const TABLE = 'nvr_hs_log';

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * @param array<string,mixed> $context Extra detail, stored as JSON.
	 */
	public function error( string $code, string $message, array $context = array() ): void {
		$this->write( 'error', $code, $message, $context );
	}

	/**
	 * Only persisted when log_level is 'debug', so the table does not fill up
	 * with one row per HTTP call on a busy store.
	 *
	 * @param array<string,mixed> $context Extra detail, stored as JSON.
	 */
	public function debug( string $code, string $message, array $context = array() ): void {
		if ( 'debug' !== $this->settings->str( 'log_level' ) ) {
			return;
		}
		$this->write( 'debug', $code, $message, $context );
	}

	/**
	 * @param array<string,mixed> $context Extra detail, stored as JSON.
	 */
	private function write( string $level, string $code, string $message, array $context ): void {
		global $wpdb;

		// Never let logging break the request that was being logged.
		$wpdb->insert(
			self::table(),
			array(
				'created' => current_time( 'mysql', true ),
				'level'   => $level,
				'code'    => substr( $code, 0, 64 ),
				'message' => substr( $message, 0, 1000 ),
				'context' => $context ? (string) wp_json_encode( $context ) : null,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Drop rows older than the configured retention. Called from cron.
	 *
	 * @return int Rows removed.
	 */
	public function prune(): int {
		global $wpdb;

		$days = max( 1, $this->settings->int( 'log_retention_days' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table, no cache.
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE created < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)', // phpcs:ignore WordPress.DB.PreparedSQL
				$days
			)
		);
	}

	/**
	 * Most recent entries, for the admin log screen.
	 *
	 * @return array<int,object>
	 */
	public function recent( int $limit = 100, ?string $level = null ): array {
		global $wpdb;

		$limit = max( 1, min( 500, $limit ) );

		if ( null !== $level ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			return (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . self::table() . ' WHERE level = %s ORDER BY id DESC LIMIT %d',
					$level,
					$limit
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit )
		);
	}
}
