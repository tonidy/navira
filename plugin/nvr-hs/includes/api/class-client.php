<?php
/**
 * HubSpot REST client.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

namespace NvrHs\Api;

use NvrHs\Logger;
use NvrHs\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over the HubSpot v3 API.
 *
 * Deliberately short timeouts. The CRM Perks plugin sharing this site uses a
 * 30 second timeout (and 1000 seconds on its bulk path) with php-fpm running
 * six workers, which is how a single slow HubSpot call takes the admin down.
 * Nothing here is allowed to hold a worker for long: requests that matter run
 * through the queue, and requests that block a page render get one short shot.
 */
final class Client {

	private const BASE = 'https://api.hubapi.com';

	/** Foreground requests must not hold a php-fpm worker. */
	private const TIMEOUT_FOREGROUND = 5;

	/** Queue workers run on cron and can afford to wait a little longer. */
	private const TIMEOUT_QUEUE = 15;

	/** Give up after this many attempts on a retryable failure. */
	private const MAX_ATTEMPTS = 3;

	private Settings $settings;
	private Logger $logger;
	private bool $in_queue;

	public function __construct( Settings $settings, Logger $logger, bool $in_queue = false ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->in_queue = $in_queue;
	}

	/**
	 * Whether a usable token is configured. Cheap, no network.
	 */
	public function is_configured(): bool {
		return '' !== $this->settings->token();
	}

	/**
	 * @param array<int,int> $tolerate Statuses that are expected control flow
	 *                                 rather than faults. Still returned as a
	 *                                 WP_Error, but logged at debug level so
	 *                                 they stay out of the dashboard's error
	 *                                 panel. A 404 driving an upsert-then-create
	 *                                 is not something anyone needs paging over.
	 */
	public function get( string $path, array $query = array(), array $tolerate = array() ): array|WP_Error {
		if ( $query ) {
			$path .= ( str_contains( $path, '?' ) ? '&' : '?' ) . http_build_query( $query );
		}
		return $this->request( 'GET', $path, null, $tolerate );
	}

	/**
	 * @param array<int,int> $tolerate Expected statuses, see get().
	 */
	public function post( string $path, array $body, array $tolerate = array() ): array|WP_Error {
		return $this->request( 'POST', $path, $body, $tolerate );
	}

	/**
	 * @param array<int,int> $tolerate Expected statuses, see get().
	 */
	public function patch( string $path, array $body, array $tolerate = array() ): array|WP_Error {
		return $this->request( 'PATCH', $path, $body, $tolerate );
	}

	/**
	 * PUT, used by the v4 association endpoints. Their body is a JSON list
	 * rather than an object, which wp_json_encode handles either way.
	 *
	 * @param array<int|string,mixed> $body Request body.
	 */
	public function put( string $path, array $body ): array|WP_Error {
		return $this->request( 'PUT', $path, $body );
	}

	/**
	 * Perform a request, retrying on rate limits and transient server errors.
	 *
	 * @param string         $method   HTTP verb.
	 * @param string         $path     Path beginning with a slash.
	 * @param array|null     $body     Optional JSON body.
	 * @param array<int,int> $tolerate Statuses to log at debug, not error.
	 * @return array|WP_Error Decoded response, or an error.
	 */
	private function request( string $method, string $path, ?array $body = null, array $tolerate = array() ): array|WP_Error {
		$token = $this->settings->token();
		if ( '' === $token ) {
			return new WP_Error( 'nvr_hs_no_token', __( 'No HubSpot private app token is configured.', 'nvr-hs' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => $this->in_queue ? self::TIMEOUT_QUEUE : self::TIMEOUT_FOREGROUND,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$url     = self::BASE . $path;
		$attempt = 0;

		while ( true ) {
			++$attempt;
			$started  = microtime( true );
			$response = wp_remote_request( $url, $args );
			$elapsed  = microtime( true ) - $started;

			if ( is_wp_error( $response ) ) {
				// Transport failure: DNS, TLS, timeout. Retryable.
				if ( $attempt < self::MAX_ATTEMPTS && $this->in_queue ) {
					$this->backoff( $attempt );
					continue;
				}
				$this->logger->error(
					'http_transport',
					sprintf( '%s %s failed: %s', $method, $path, $response->get_error_message() ),
					array( 'attempt' => $attempt )
				);
				return $response;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$raw    = wp_remote_retrieve_body( $response );
			$data   = '' === $raw ? array() : json_decode( $raw, true );
			if ( ! is_array( $data ) ) {
				$data = array( 'raw' => $raw );
			}

			// 429 carries a Retry-After we should honour rather than guess at.
			if ( 429 === $status && $attempt < self::MAX_ATTEMPTS && $this->in_queue ) {
				$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				$this->backoff( $attempt, $retry_after );
				continue;
			}

			if ( $status >= 500 && $attempt < self::MAX_ATTEMPTS && $this->in_queue ) {
				$this->backoff( $attempt );
				continue;
			}

			if ( $status >= 200 && $status < 300 ) {
				$this->logger->debug(
					'http_ok',
					sprintf( '%s %s -> %d', $method, $path, $status ),
					array(
						'ms'      => (int) round( $elapsed * 1000 ),
						'attempt' => $attempt,
					)
				);
				return $data;
			}

			$message = (string) ( $data['message'] ?? sprintf( 'HTTP %d', $status ) );
			$context = array(
				'ms'       => (int) round( $elapsed * 1000 ),
				'attempt'  => $attempt,
				'category' => $data['category'] ?? null,
			);
			$line    = sprintf( '%s %s -> %d: %s', $method, $path, $status, $message );

			if ( in_array( $status, $tolerate, true ) ) {
				$this->logger->debug( 'http_' . $status, $line, $context );
			} else {
				$this->logger->error( 'http_' . $status, $line, $context );
			}

			return new WP_Error(
				'nvr_hs_http_' . $status,
				$message,
				array(
					'status' => $status,
					'body'   => $data,
				)
			);
		}
	}

	/**
	 * Sleep between attempts. Only ever called from queue context, so this
	 * never delays a page render.
	 *
	 * @param int $attempt     1-based attempt number just completed.
	 * @param int $retry_after Seconds requested by the server, if any.
	 */
	private function backoff( int $attempt, int $retry_after = 0 ): void {
		$seconds = $retry_after > 0 ? min( $retry_after, 30 ) : min( 2 ** $attempt, 8 );
		// Jitter avoids a thundering herd when several queue jobs retry together.
		usleep( (int) ( ( $seconds + ( wp_rand( 0, 500 ) / 1000 ) ) * 1_000_000 ) );
	}

	/**
	 * Verify the token and report what the portal is. Used by the settings
	 * page connection test, so it runs in the foreground on purpose.
	 *
	 * @return array{ok:bool,message:string,portal:?array}
	 */
	public function connection_test(): array {
		$result = $this->get( '/account-info/v3/details' );

		if ( is_wp_error( $result ) ) {
			return array(
				'ok'      => false,
				'message' => $result->get_error_message(),
				'portal'  => null,
			);
		}

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: 1: portal id, 2: data hosting region */
				__( 'Connected to portal %1$s (%2$s).', 'nvr-hs' ),
				$result['portalId'] ?? '?',
				$result['dataHostingLocation'] ?? '?'
			),
			'portal'  => $result,
		);
	}
}
