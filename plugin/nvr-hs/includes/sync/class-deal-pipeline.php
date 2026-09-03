<?php
/**
 * Deal pipeline automation for high-value leads.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

namespace NvrHs\Sync;

use NvrHs\Api\Client;
use NvrHs\Api\Contacts;
use NvrHs\Api\Deals;
use NvrHs\Logger;
use NvrHs\Queue;
use NvrHs\Settings;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and advances HubSpot deals for orders worth chasing.
 *
 * This is the piece the CRM Perks free tier cannot do: its own upgrade notice
 * lists "Set Ticket Status, Deals stage and Status" and "Add Order Items as
 * Products to Deals" as premium. So we own deal stage and line items, and let
 * it own plain contact and order sync.
 *
 * Nothing here talks to HubSpot inline. Order hooks fire during checkout, and
 * a blocking API call there costs a customer.
 */
final class Deal_Pipeline {

	public const JOB = 'deal_sync';

	private Settings $settings;
	private Queue $queue;
	private Logger $logger;
	private Deals $deals;
	private Contacts $contacts;

	public function __construct(
		Settings $settings,
		Queue $queue,
		Logger $logger,
		Deals $deals,
		Contacts $contacts
	) {
		$this->settings = $settings;
		$this->queue    = $queue;
		$this->logger   = $logger;
		$this->deals    = $deals;
		$this->contacts = $contacts;
	}

	public function register_hooks(): void {
		// Enqueue on the transitions that matter. All three dedupe to the same
		// key, so an order that goes pending -> processing -> completed in one
		// request queues one job, not three.
		add_action( 'woocommerce_checkout_order_created', array( $this, 'on_order_created' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 4 );

		$this->queue->register( self::JOB, array( $this, 'handle' ) );
	}

	/**
	 * @param WC_Order $order Newly created order.
	 */
	public function on_order_created( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$this->maybe_enqueue( $order );
	}

	/**
	 * @param int      $order_id Order ID.
	 * @param string   $from     Previous status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order object.
	 */
	public function on_status_changed( $order_id, $from, $to, $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$this->maybe_enqueue( $order );
	}

	/**
	 * Queue a sync if the order clears the high-value bar.
	 *
	 * Orders below the threshold are deliberately skipped: this pipeline is
	 * for leads a salesperson should chase, and filling it with every small
	 * retail order would make it useless.
	 */
	private function maybe_enqueue( WC_Order $order ): void {
		$total     = (float) $order->get_total();
		$threshold = $this->settings->float( 'high_value_threshold' );

		// Once a deal exists we keep it in step regardless of the threshold,
		// otherwise a partial refund below the bar would freeze the stage.
		$already = '' !== (string) $order->get_meta( '_nvr_hs_deal_id' );

		if ( ! $already && $total < $threshold ) {
			return;
		}

		$this->queue->push(
			self::JOB,
			array( 'order_id' => $order->get_id() ),
			(string) $order->get_id()
		);
	}

	/**
	 * Queue handler: push the order to HubSpot as a deal.
	 *
	 * @param array<string,mixed> $payload Job payload.
	 * @throws \RuntimeException When the sync fails and should be retried.
	 */
	public function handle( array $payload ): void {
		$order_id = (int) ( $payload['order_id'] ?? 0 );
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof WC_Order ) {
			// The order is gone. Nothing to retry.
			$this->logger->error( 'deal_no_order', 'Order not found', array( 'order_id' => $order_id ) );
			return;
		}

		$email = $order->get_billing_email();

		if ( '' === $email ) {
			$this->logger->error( 'deal_no_email', 'Order has no billing email', array( 'order_id' => $order_id ) );
			return;
		}

		$reference = 'order-' . $order_id;
		$stage     = $this->stage_for_status( $order->get_status() );

		$properties = array(
			'dealname'  => sprintf(
				/* translators: 1: order number, 2: customer name */
				__( 'Order %1$s - %2$s', 'nvr-hs' ),
				$order->get_order_number(),
				trim( $order->get_formatted_billing_full_name() )
			),
			'amount'    => (string) round( (float) $order->get_total(), 2 ),
			'pipeline'  => $this->settings->str( 'deal_pipeline' ),
			'dealstage' => $stage,
			'closedate' => (string) ( $order->get_date_created()
				? $order->get_date_created()->getTimestamp() * 1000
				: time() * 1000 ),
		);

		$owner = $this->settings->str( 'deal_owner_id' );
		if ( '' !== $owner ) {
			$properties['hubspot_owner_id'] = $owner;
		}

		// Left unset by default: when the store and the portal share a currency,
		// sending nothing is both correct and safer, because HubSpot rejects a
		// code that is not enabled on the portal and would take the whole deal
		// down with it.
		$currency = $this->settings->str( 'deal_currency_code' );
		if ( '' !== $currency ) {
			$properties['deal_currency_code'] = $currency;
		}

		$deal_id = $this->deals->upsert( $reference, $properties );

		if ( is_wp_error( $deal_id ) ) {
			throw new \RuntimeException( 'Deal upsert failed: ' . $deal_id->get_error_message() );
		}

		// Link the contact. CRM Perks may well have created it already; upsert
		// finds that record rather than making a second one.
		//
		// A contact failure does not abandon the deal. HubSpot rejects some
		// addresses outright (a reserved TLD, a typo'd domain), and when that
		// happens the order is still real and the sales team still wants the
		// deal with its line items. So a permanent rejection is logged and
		// stepped over; only a transient one is worth retrying the job for.
		$contact_id = $this->contacts->upsert(
			$email,
			array(
				'firstname' => $order->get_billing_first_name(),
				'lastname'  => $order->get_billing_last_name(),
			)
		);

		if ( is_wp_error( $contact_id ) ) {
			if ( ! Client::is_permanent( $contact_id ) ) {
				throw new \RuntimeException( 'Contact upsert failed: ' . $contact_id->get_error_message() );
			}

			$this->logger->error(
				'deal_contact_rejected',
				sprintf( 'Order %d: HubSpot will not accept the contact, deal left unlinked. %s', $order_id, $contact_id->get_error_message() ),
				array(
					'order_id' => $order_id,
					'email'    => $email,
					'deal_id'  => $deal_id,
				)
			);
			$contact_id = '';
		}

		if ( '' !== $contact_id ) {
			$linked = $this->deals->link_contact( $deal_id, $contact_id );

			if ( is_wp_error( $linked ) ) {
				throw new \RuntimeException( 'Deal to contact link failed: ' . $linked->get_error_message() );
			}
		}

		// Line items are added once. Re-adding on every status change would
		// duplicate them, and HubSpot has no upsert for line items keyed on
		// anything we control.
		if ( '' === (string) $order->get_meta( '_nvr_hs_line_items_synced' ) ) {
			$result = $this->deals->set_line_items( $deal_id, $this->line_items( $order ) );

			if ( $result['errors'] ) {
				$this->logger->error(
					'deal_line_items',
					sprintf( '%d of %d line items failed', count( $result['errors'] ), count( $this->line_items( $order ) ) ),
					array(
						'order_id' => $order_id,
						'errors'   => array_slice( $result['errors'], 0, 5 ),
					)
				);
			} else {
				$order->update_meta_data( '_nvr_hs_line_items_synced', '1' );
			}
		}

		$order->update_meta_data( '_nvr_hs_deal_id', $deal_id );
		$order->save();

		$this->logger->debug(
			'deal_synced',
			sprintf( 'Order %d -> deal %s at stage %s', $order_id, $deal_id, $stage ),
			array(
				'order_id' => $order_id,
				'deal_id'  => $deal_id,
			)
		);
	}

	/**
	 * Map a WooCommerce order status onto a pipeline stage.
	 *
	 * Portal 247211579's Sales Pipeline is Prospecting, Initial Contact,
	 * Proposal, Closed Won, Closed Lost. Anything unrecognised lands on the
	 * configured entry stage rather than guessing.
	 */
	private function stage_for_status( string $status ): string {
		return match ( $status ) {
			'completed'                            => $this->settings->str( 'deal_stage_won' ),
			'cancelled', 'refunded', 'failed'      => $this->settings->str( 'deal_stage_lost' ),
			default                                => $this->settings->str( 'deal_stage_new' ),
		};
	}

	/**
	 * Build HubSpot line item payloads from the order's items.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function line_items( WC_Order $order ): array {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			$quantity = (int) $item->get_quantity();
			$subtotal = (float) $item->get_total();

			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => (string) $quantity,
				// HubSpot wants unit price, Woo gives us the line total.
				'price'    => (string) round( $quantity > 0 ? $subtotal / $quantity : $subtotal, 2 ),
			);
		}

		return $items;
	}
}
