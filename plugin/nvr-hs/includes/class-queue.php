<?php
/**
 * Asynchronous job queue.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

namespace NvrHs;

defined( 'ABSPATH' ) || exit;

/**
 * A small durable queue backed by its own table.
 *
 * Every HubSpot write goes through here. Checkout must never wait on an API
 * call: this store runs six php-fpm workers, so one slow outbound request on
 * the thank-you page is enough to start queueing real customers behind it.
 * Handlers are registered by name, and a job that keeps failing is parked as
 * 'failed' rather than retried forever.
 */
final class Queue {

	public const TABLE       = 'nvr_hs_queue';
	public const MAX_ATTEMPTS = 5;

	/** Keep a cron tick well inside php-fpm's patience. */
	private const BATCH   = 20;
	private const BUDGET_SECONDS = 20;

	private Logger $logger;

	/** @var array<string,callable> */
	private array $handlers = array();

	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * @param callable $handler Receives the decoded payload array. Throwing
	 *                          marks the job for retry.
	 */
	public function register( string $job, callable $handler ): void {
		$this->handlers[ $job ] = $handler;
	}

	/**
	 * Add a job.
	 *
	 * @param string              $job     Handler name.
	 * @param array<string,mixed> $payload Job data.
	 * @param string              $dedupe  Optional key; a pending job with the
	 *                                     same key is not queued twice. Use it
	 *                                     for "sync order 1234", which can fire
	 *                                     from several Woo hooks at once.
	 * @param int                 $delay   Seconds to wait before running.
	 */
	public function push( string $job, array $payload = array(), string $dedupe = '', int $delay = 0 ): bool {
		global $wpdb;

		$now  = time();
		$data = array(
			'created'      => gmdate( 'Y-m-d H:i:s', $now ),
			'available_at' => gmdate( 'Y-m-d H:i:s', $now + max( 0, $delay ) ),
			'job'          => substr( $job, 0, 64 ),
			'payload'      => (string) wp_json_encode( $payload ),
			'status'       => 'pending',
			'dedupe_key'   => '' === $dedupe ? null : substr( $job . ':' . $dedupe, 0, 191 ),
		);

		// A duplicate dedupe_key trips the unique index. That is the intended
		// outcome, so suppress the error rather than logging a scary row.
		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert( self::table(), $data, array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
		$wpdb->suppress_errors( $suppress );

		return (bool) $inserted;
	}

	/**
	 * Drain up to BATCH jobs, stopping early if the time budget runs out.
	 *
	 * @return array{done:int,failed:int,retried:int}
	 */
	public function run(): array {
		global $wpdb;

		$deadline = microtime( true ) + self::BUDGET_SECONDS;
		$stats    = array(
			'done'    => 0,
			'failed'  => 0,
			'retried' => 0,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$jobs = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE status = 'pending' AND available_at <= %s ORDER BY id ASC LIMIT %d",
				gmdate( 'Y-m-d H:i:s' ),
				self::BATCH
			)
		);

		foreach ( $jobs as $job ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}

			// Claim the row first so a second overlapping cron run cannot pick
			// up the same job. The affected-rows check is the lock.
			$claimed = $wpdb->update(
				self::table(),
				array(
					'status'   => 'running',
					'attempts' => (int) $job->attempts + 1,
				),
				array(
					'id'     => (int) $job->id,
					'status' => 'pending',
				),
				array( '%s', '%d' ),
				array( '%d', '%s' )
			);

			if ( ! $claimed ) {
				continue;
			}

			$handler = $this->handlers[ $job->job ] ?? null;

			if ( null === $handler ) {
				$this->fail( (int) $job->id, sprintf( 'No handler registered for "%s"', $job->job ) );
				++$stats['failed'];
				continue;
			}

			$payload = json_decode( (string) $job->payload, true );
			$payload = is_array( $payload ) ? $payload : array();

			try {
				$handler( $payload );
				$wpdb->update(
					self::table(),
					array( 'status' => 'done' ),
					array( 'id' => (int) $job->id ),
					array( '%s' ),
					array( '%d' )
				);
				++$stats['done'];
			} catch ( \Throwable $e ) {
				$attempts = (int) $job->attempts + 1;

				if ( $attempts >= self::MAX_ATTEMPTS ) {
					$this->fail( (int) $job->id, $e->getMessage() );
					++$stats['failed'];
					continue;
				}

				// Exponential backoff, computed from the attempt count so a
				// restart does not reset the delay.
				$wpdb->update(
					self::table(),
					array(
						'status'       => 'pending',
						'available_at' => gmdate( 'Y-m-d H:i:s', time() + ( 60 * ( 2 ** ( $attempts - 1 ) ) ) ),
						'last_error'   => substr( $e->getMessage(), 0, 500 ),
					),
					array( 'id' => (int) $job->id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
				++$stats['retried'];
			}
		}

		$this->cleanup();

		return $stats;
	}

	private function fail( int $id, string $message ): void {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'status'     => 'failed',
				'last_error' => substr( $message, 0, 500 ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$this->logger->error( 'queue_failed', $message, array( 'job_id' => $id ) );
	}

	/**
	 * Drop completed jobs, and unstick anything left 'running' by a worker
	 * that was killed mid-job.
	 */
	private function cleanup(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->query(
			"DELETE FROM " . self::table() . " WHERE status = 'done' AND created < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY)"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->query(
			"UPDATE " . self::table() . " SET status = 'pending' WHERE status = 'running' AND created < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)"
		);
	}

	/**
	 * Counts by status, for the dashboard health panel.
	 *
	 * @return array<string,int>
	 */
	public function stats(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = (array) $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY status' );

		$out = array(
			'pending' => 0,
			'running' => 0,
			'done'    => 0,
			'failed'  => 0,
		);

		foreach ( $rows as $row ) {
			$out[ $row->status ] = (int) $row->n;
		}

		return $out;
	}
}
