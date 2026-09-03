<?php
/**
 * WordPress user to HubSpot contact sync.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

namespace NvrHs\Sync;

use NvrHs\Api\Client;
use NvrHs\Api\Contacts;
use NvrHs\Logger;
use NvrHs\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps registered accounts in HubSpot.
 *
 * Narrow on purpose. CRM Perks already creates contacts from orders and form
 * submissions, so duplicating that would mean two plugins racing to write the
 * same record. What it does not cover is someone who registers a reseller
 * account and has not ordered yet, which for a supplier is precisely the lead
 * worth having in the CRM.
 */
final class Contact_Sync {

	public const JOB = 'user_sync';

	private Queue $queue;
	private Logger $logger;
	private Contacts $contacts;

	public function __construct( Queue $queue, Logger $logger, Contacts $contacts ) {
		$this->queue    = $queue;
		$this->logger   = $logger;
		$this->contacts = $contacts;
	}

	public function register_hooks(): void {
		add_action( 'user_register', array( $this, 'on_user_register' ) );
		add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 2 );

		$this->queue->register( self::JOB, array( $this, 'handle' ) );
	}

	/**
	 * @param int $user_id New user ID.
	 */
	public function on_user_register( $user_id ): void {
		$this->enqueue( (int) $user_id );
	}

	/**
	 * Only sync on a change we actually mirror, so a password reset or a
	 * last-login update does not cost an API call.
	 *
	 * @param int    $user_id New user ID.
	 * @param object $old     User object before the update.
	 */
	public function on_profile_update( $user_id, $old ): void {
		$user = get_userdata( (int) $user_id );

		if ( ! $user || ! is_object( $old ) ) {
			return;
		}

		$changed = $user->user_email !== ( $old->user_email ?? '' )
			|| $user->first_name !== get_user_meta( (int) $user_id, 'first_name', true )
			|| $user->last_name !== get_user_meta( (int) $user_id, 'last_name', true );

		if ( $changed ) {
			$this->enqueue( (int) $user_id );
		}
	}

	private function enqueue( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$this->queue->push( self::JOB, array( 'user_id' => $user_id ), (string) $user_id );
	}

	/**
	 * Queue handler.
	 *
	 * @param array<string,mixed> $payload Job payload.
	 * @throws \RuntimeException When the HubSpot write fails.
	 */
	public function handle( array $payload ): void {
		$user_id = (int) ( $payload['user_id'] ?? 0 );
		$user    = $user_id ? get_userdata( $user_id ) : null;

		if ( ! $user ) {
			return;
		}

		$email = strtolower( (string) $user->user_email );

		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$properties = array(
			'nvr_wp_user_id' => (string) $user_id,
		);

		// Only send names we actually have. Sending an empty string would
		// overwrite a better value that HubSpot already holds.
		$first = (string) get_user_meta( $user_id, 'first_name', true );
		$last  = (string) get_user_meta( $user_id, 'last_name', true );

		if ( '' !== $first ) {
			$properties['firstname'] = $first;
		}

		if ( '' !== $last ) {
			$properties['lastname'] = $last;
		}

		$result = $this->contacts->upsert( $email, $properties );

		if ( is_wp_error( $result ) ) {
			if ( Client::is_permanent( $result ) ) {
				$this->logger->error(
					'user_rejected',
					sprintf( 'HubSpot will not accept user %d (%s), not retrying. %s', $user_id, $email, $result->get_error_message() ),
					array( 'user_id' => $user_id )
				);
				return;
			}

			throw new \RuntimeException( 'User sync failed: ' . $result->get_error_message() );
		}

		$this->logger->debug( 'user_synced', sprintf( 'User %d -> contact %s', $user_id, $result ) );
	}
}
