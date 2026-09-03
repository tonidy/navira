<?php
/**
 * Custom HubSpot property definitions.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

namespace NvrHs\Api;

use NvrHs\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the nvr_* custom properties this plugin writes.
 *
 * Portal 247211579 already has 405 contact properties and none prefixed
 * nvr_, so the namespace is ours. Creating them up front matters for more
 * than tidiness: a HubSpot workflow can only be triggered by a property that
 * exists, so the store owner cannot build the abandoned cart automation until
 * nvr_cart_abandoned_at is present in the portal.
 */
final class Properties {

	public const GROUP = 'nvr_hs';

	private Client $client;
	private Logger $logger;

	public function __construct( Client $client, Logger $logger ) {
		$this->client = $client;
		$this->logger = $logger;
	}

	/**
	 * Contact properties, keyed by internal name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function contact_properties(): array {
		return array(
			'nvr_wp_user_id'          => array(
				'label'     => 'WP user ID',
				'type'      => 'number',
				'fieldType' => 'number',
			),
			'nvr_ltv'                 => array(
				'label'     => 'Lifetime value',
				'type'      => 'number',
				'fieldType' => 'number',
			),
			'nvr_order_count'         => array(
				'label'     => 'Paid order count',
				'type'      => 'number',
				'fieldType' => 'number',
			),
			'nvr_aov'                 => array(
				'label'     => 'Average order value',
				'type'      => 'number',
				'fieldType' => 'number',
			),
			'nvr_first_order_at'      => array(
				'label'     => 'First order date',
				'type'      => 'datetime',
				'fieldType' => 'date',
			),
			'nvr_last_order_at'       => array(
				'label'     => 'Last order date',
				'type'      => 'datetime',
				'fieldType' => 'date',
			),
			'nvr_last_order_number'   => array(
				'label'     => 'Last order number',
				'type'      => 'string',
				'fieldType' => 'text',
			),
			'nvr_customer_tier'       => array(
				'label'     => 'Customer tier',
				'type'      => 'enumeration',
				'fieldType' => 'select',
				'options'   => array(
					array(
						'label'        => 'Prospect',
						'value'        => 'prospect',
						'displayOrder' => 0,
					),
					array(
						'label'        => 'New customer',
						'value'        => 'new',
						'displayOrder' => 1,
					),
					array(
						'label'        => 'Repeat customer',
						'value'        => 'repeat',
						'displayOrder' => 2,
					),
					array(
						'label'        => 'VIP reseller',
						'value'        => 'vip',
						'displayOrder' => 3,
					),
					array(
						'label'        => 'At risk',
						'value'        => 'at_risk',
						'displayOrder' => 4,
					),
					array(
						'label'        => 'Churned',
						'value'        => 'churned',
						'displayOrder' => 5,
					),
				),
			),
			'nvr_favorite_category'   => array(
				'label'     => 'Most purchased category',
				'type'      => 'string',
				'fieldType' => 'text',
			),
			// Abandoned cart state. A workflow keyed on nvr_cart_abandoned_at
			// being known is what actually sends the recovery email.
			'nvr_cart_value'          => array(
				'label'     => 'Open cart value',
				'type'      => 'number',
				'fieldType' => 'number',
			),
			'nvr_cart_item_count'     => array(
				'label'     => 'Open cart items',
				'type'      => 'number',
				'fieldType' => 'number',
			),
			'nvr_cart_abandoned_at'   => array(
				'label'     => 'Cart abandoned at',
				'type'      => 'datetime',
				'fieldType' => 'date',
			),
			'nvr_cart_recovery_url'   => array(
				'label'     => 'Cart recovery URL',
				'type'      => 'string',
				'fieldType' => 'text',
			),
		);
	}

	/**
	 * Deal properties. Only one, but it is the important one: it makes order
	 * to deal sync idempotent, so a retried queue job updates the existing
	 * deal instead of creating a second one.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function deal_properties(): array {
		return array(
			'nvr_wc_order_id' => array(
				'label'              => 'WooCommerce order ID',
				'type'               => 'string',
				'fieldType'          => 'text',
				'hasUniqueValue'     => true,
			),
		);
	}

	/**
	 * Create the property group and any missing properties.
	 *
	 * Idempotent, and safe to run repeatedly: existing properties are left
	 * exactly as they are, because the portal owner may well have edited a
	 * label or added a tier option by hand.
	 *
	 * @return array{created:array<int,string>,existing:array<int,string>,errors:array<int,string>}
	 */
	public function ensure(): array {
		$out = array(
			'created'  => array(),
			'existing' => array(),
			'errors'   => array(),
		);

		$this->ensure_group( 'contacts', 'Navira store' );
		$this->ensure_group( 'deals', 'Navira store' );

		foreach ( array( 'contacts' => self::contact_properties(), 'deals' => self::deal_properties() ) as $object => $defs ) {
			$existing = $this->existing_names( $object );

			if ( null === $existing ) {
				$out['errors'][] = sprintf( 'Could not read %s properties', $object );
				continue;
			}

			foreach ( $defs as $name => $def ) {
				if ( in_array( $name, $existing, true ) ) {
					$out['existing'][] = $object . '.' . $name;
					continue;
				}

				$body = array_merge(
					$def,
					array(
						'name'      => $name,
						'groupName' => self::GROUP,
					)
				);

				$result = $this->client->post( '/crm/v3/properties/' . $object, $body );

				if ( is_wp_error( $result ) ) {
					$out['errors'][] = $object . '.' . $name . ': ' . $result->get_error_message();
					continue;
				}

				$out['created'][] = $object . '.' . $name;
			}
		}

		$this->logger->debug(
			'properties_ensured',
			sprintf( '%d created, %d already present', count( $out['created'] ), count( $out['existing'] ) ),
			$out
		);

		return $out;
	}

	/**
	 * @return array<int,string>|null Null when the read failed.
	 */
	private function existing_names( string $object ): ?array {
		$result = $this->client->get( '/crm/v3/properties/' . $object );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		return array_column( $result['results'] ?? array(), 'name' );
	}

	/**
	 * A 409 here means the group already exists, which is the normal case
	 * after the first run and is not worth logging as a failure.
	 */
	private function ensure_group( string $object, string $label ): void {
		$result = $this->client->post(
			'/crm/v3/properties/' . $object . '/groups',
			array(
				'name'  => self::GROUP,
				'label' => $label,
			),
			array( 409 )
		);

		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 0 );
			if ( 409 !== $status ) {
				$this->logger->debug( 'property_group', $result->get_error_message(), array( 'object' => $object ) );
			}
		}
	}

	/**
	 * HubSpot datetime properties want epoch milliseconds.
	 */
	public static function timestamp( int $unix ): string {
		return (string) ( $unix * 1000 );
	}
}
