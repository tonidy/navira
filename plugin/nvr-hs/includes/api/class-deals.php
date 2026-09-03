<?php
/**
 * Deal reads and writes.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

namespace NvrHs\Api;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Deal operations, keyed on the WooCommerce order or cart that produced them.
 *
 * Every deal this plugin creates carries nvr_wc_order_id, declared with
 * hasUniqueValue, which lets us upsert by idProperty. Without that a retried
 * queue job would create a duplicate deal every time, and duplicate deals in
 * a sales pipeline are worse than no deals at all.
 */
final class Deals {

	/** HubSpot's built-in deal to contact association. */
	private const ASSOC_DEAL_TO_CONTACT = 3;

	/** HubSpot's built-in deal to line item association. */
	private const ASSOC_DEAL_TO_LINE_ITEM = 19;

	private Client $client;

	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Create or update a deal for a WooCommerce order reference.
	 *
	 * @param string              $reference  Stable key, e.g. "order-1234" or "cart-abc".
	 * @param array<string,mixed> $properties Deal properties.
	 * @return string|WP_Error HubSpot deal ID.
	 */
	public function upsert( string $reference, array $properties ): string|WP_Error {
		$properties['nvr_wc_order_id'] = $reference;

		$result = $this->client->patch(
			'/crm/v3/objects/deals/' . rawurlencode( $reference ) . '?idProperty=nvr_wc_order_id',
			array( 'properties' => $properties ),
			array( 404 )
		);

		if ( ! is_wp_error( $result ) ) {
			return (string) ( $result['id'] ?? '' );
		}

		if ( 404 !== (int) ( $result->get_error_data()['status'] ?? 0 ) ) {
			return $result;
		}

		$created = $this->client->post( '/crm/v3/objects/deals', array( 'properties' => $properties ) );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return (string) ( $created['id'] ?? '' );
	}

	/**
	 * Associate a deal with a contact. Idempotent on HubSpot's side, so it is
	 * safe to call on every sync rather than tracking whether we did it.
	 *
	 * @return true|WP_Error
	 */
	public function link_contact( string $deal_id, string $contact_id ): bool|WP_Error {
		if ( '' === $deal_id || '' === $contact_id ) {
			return new WP_Error( 'nvr_hs_bad_assoc', __( 'Both a deal and a contact are required.', 'nvr-hs' ) );
		}

		$result = $this->client->put(
			sprintf( '/crm/v4/objects/deals/%s/associations/contacts/%s', $deal_id, $contact_id ),
			array(
				array(
					'associationCategory' => 'HUBSPOT_DEFINED',
					'associationTypeId'   => self::ASSOC_DEAL_TO_CONTACT,
				),
			)
		);

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Move a deal to a stage.
	 *
	 * @return true|WP_Error
	 */
	public function set_stage( string $reference, string $stage_id ): bool|WP_Error {
		$result = $this->upsert( $reference, array( 'dealstage' => $stage_id ) );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Replace a deal's line items with the given set.
	 *
	 * @param string                          $deal_id HubSpot deal ID.
	 * @param array<int,array<string,mixed>>  $items   Line item property sets.
	 * @return array{created:int,errors:array<int,string>}
	 */
	public function set_line_items( string $deal_id, array $items ): array {
		$out = array(
			'created' => 0,
			'errors'  => array(),
		);

		foreach ( $items as $item ) {
			$created = $this->client->post( '/crm/v3/objects/line_items', array( 'properties' => $item ) );

			if ( is_wp_error( $created ) ) {
				$out['errors'][] = $created->get_error_message();
				continue;
			}

			$line_id = (string) ( $created['id'] ?? '' );

			if ( '' === $line_id ) {
				continue;
			}

			$linked = $this->client->put(
				sprintf( '/crm/v4/objects/deals/%s/associations/line_items/%s', $deal_id, $line_id ),
				array(
					array(
						'associationCategory' => 'HUBSPOT_DEFINED',
						'associationTypeId'   => self::ASSOC_DEAL_TO_LINE_ITEM,
					),
				)
			);

			if ( is_wp_error( $linked ) ) {
				$out['errors'][] = $linked->get_error_message();
				continue;
			}

			++$out['created'];
		}

		return $out;
	}

	/**
	 * Whether a deal already exists for a reference.
	 */
	public function exists( string $reference ): bool {
		$result = $this->client->get(
			'/crm/v3/objects/deals/' . rawurlencode( $reference ),
			array( 'idProperty' => 'nvr_wc_order_id' ),
			array( 404 )
		);

		return ! is_wp_error( $result );
	}
}
