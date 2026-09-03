<?php
/**
 * Contact reads and writes.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

namespace NvrHs\Api;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Contact operations, keyed on email.
 *
 * The CRM Perks plugin owns baseline contact creation from orders and forms.
 * This class exists to update the nvr_* properties on top of whatever record
 * already exists, so it upserts rather than creates: if CRM Perks made the
 * contact a second earlier, we want to patch that one, not make a duplicate.
 */
final class Contacts {

	private Client $client;

	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Create or update a contact by email.
	 *
	 * @param string              $email      Contact email.
	 * @param array<string,mixed> $properties Property name => value.
	 * @return string|WP_Error HubSpot contact ID.
	 */
	public function upsert( string $email, array $properties ): string|WP_Error {
		$email = strtolower( trim( $email ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'nvr_hs_bad_email', __( 'A valid email is required to sync a contact.', 'nvr-hs' ) );
		}

		$properties['email'] = $email;

		// The idempotent path: PATCH by email as the id, which HubSpot accepts
		// via idProperty. Avoids a search call on the common case.
		$result = $this->client->patch(
			'/crm/v3/objects/contacts/' . rawurlencode( $email ) . '?idProperty=email',
			array( 'properties' => $properties ),
			array( 404 )
		);

		if ( ! is_wp_error( $result ) ) {
			return (string) ( $result['id'] ?? '' );
		}

		$status = (int) ( $result->get_error_data()['status'] ?? 0 );

		// 404 means no such contact yet, so create it. Anything else is a real
		// failure and should surface to the queue for retry.
		if ( 404 !== $status ) {
			return $result;
		}

		$created = $this->client->post( '/crm/v3/objects/contacts', array( 'properties' => $properties ), array( 409 ) );

		if ( is_wp_error( $created ) ) {
			// A 409 is the race we cannot avoid: something created the contact
			// between our PATCH and our POST. Patch it now and move on.
			if ( 409 === (int) ( $created->get_error_data()['status'] ?? 0 ) ) {
				$retry = $this->client->patch(
					'/crm/v3/objects/contacts/' . rawurlencode( $email ) . '?idProperty=email',
					array( 'properties' => $properties )
				);
				return is_wp_error( $retry ) ? $retry : (string) ( $retry['id'] ?? '' );
			}
			return $created;
		}

		return (string) ( $created['id'] ?? '' );
	}

	/**
	 * Look up a contact ID by email without writing anything.
	 *
	 * @return string|null Null when not found.
	 */
	public function find_id( string $email ): ?string {
		$email = strtolower( trim( $email ) );

		if ( '' === $email ) {
			return null;
		}

		$result = $this->client->get(
			'/crm/v3/objects/contacts/' . rawurlencode( $email ),
			array(
				'idProperty' => 'email',
				'properties' => 'email',
			),
			array( 404 )
		);

		if ( is_wp_error( $result ) ) {
			return null;
		}

		$id = (string) ( $result['id'] ?? '' );

		return '' === $id ? null : $id;
	}

	/**
	 * Clear the open cart properties. Called when a cart converts or empties,
	 * so a stale nvr_cart_abandoned_at cannot keep a recovery workflow live
	 * after the customer has already bought.
	 *
	 * @return true|WP_Error
	 */
	public function clear_cart_state( string $email ): bool|WP_Error {
		$result = $this->upsert(
			$email,
			array(
				'nvr_cart_value'        => '',
				'nvr_cart_item_count'   => '',
				'nvr_cart_abandoned_at' => '',
				'nvr_cart_recovery_url' => '',
			)
		);

		return is_wp_error( $result ) ? $result : true;
	}
}
