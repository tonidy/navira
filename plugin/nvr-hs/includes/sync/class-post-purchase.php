<?php
/**
 * Post-purchase follow-up.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

namespace NvrHs\Sync;

use NvrHs\Logger;
use NvrHs\Queue;
use NvrHs\Settings;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Reacts to a completed purchase.
 *
 * The actual property writing lives in Segmentation, because a purchase is
 * just the event that makes a customer's numbers stale. This class owns the
 * moment itself: it delays the follow-up so the email does not land while the
 * customer is still on the thank-you page, and it sends the message itself
 * when HubSpot workflows are not available.
 */
final class Post_Purchase {

	public const JOB = 'post_purchase';

	/**
	 * Wait before following up. Long enough that the transactional Woo receipt
	 * arrives first, short enough to still feel connected to the purchase.
	 */
	private const DELAY = 900;

	private Settings $settings;
	private Queue $queue;
	private Logger $logger;
	private Segmentation $segmentation;

	public function __construct(
		Settings $settings,
		Queue $queue,
		Logger $logger,
		Segmentation $segmentation
	) {
		$this->settings     = $settings;
		$this->queue        = $queue;
		$this->logger       = $logger;
		$this->segmentation = $segmentation;
	}

	public function register_hooks(): void {
		// Completed is the honest signal for a supplier: processing can still
		// be cancelled before anything ships.
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_completed' ), 10, 2 );

		// A refund changes lifetime value, so the segment has to be redone.
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), 10, 2 );

		$this->queue->register( self::JOB, array( $this, 'handle' ) );
	}

	/**
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order object.
	 */
	public function on_completed( $order_id, $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( (int) $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$email = strtolower( $order->get_billing_email() );

		if ( '' === $email ) {
			return;
		}

		// Numbers first, so the follow-up sees an up-to-date tier.
		$this->segmentation->enqueue( $email );

		$this->queue->push(
			self::JOB,
			array(
				'order_id' => $order->get_id(),
				'email'    => $email,
			),
			'purchase-' . $order->get_id(),
			self::DELAY
		);
	}

	/**
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function on_refunded( $order_id, $refund_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$email = strtolower( $order->get_billing_email() );

		if ( '' !== $email ) {
			$this->segmentation->enqueue( $email );
		}
	}

	/**
	 * Queue handler.
	 *
	 * In workflow mode there is nothing to send: Segmentation has already
	 * written nvr_last_order_at and nvr_customer_tier, and a HubSpot workflow
	 * enrolled on those does the rest. This exists so the same feature still
	 * works on a portal without Marketing Hub Professional.
	 *
	 * @param array<string,mixed> $payload Job payload.
	 */
	public function handle( array $payload ): void {
		$order_id = (int) ( $payload['order_id'] ?? 0 );
		$email    = (string) ( $payload['email'] ?? '' );
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof WC_Order || '' === $email ) {
			return;
		}

		// A refund or cancellation between queueing and running means the
		// follow-up is no longer appropriate.
		if ( 'completed' !== $order->get_status() ) {
			$this->logger->debug(
				'post_purchase_skipped',
				sprintf( 'Order %d is now %s', $order_id, $order->get_status() ),
				array( 'order_id' => $order_id )
			);
			return;
		}

		if ( $this->settings->workflow_email_mode() ) {
			return;
		}

		$this->send_followup( $order );
	}

	private function send_followup( WC_Order $order ): void {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			$items[] = sprintf( '- %s x%d', $item->get_name(), (int) $item->get_quantity() );
		}

		$body = sprintf(
			/* translators: 1: customer first name, 2: order number, 3: item list */
			__(
				"Hi %1\$s,\n\nThanks for order %2\$s:\n\n%3\$s\n\nIf you resell these, tell us how they moved and we will keep your next restock ready.",
				'nvr-hs'
			),
			$order->get_billing_first_name(),
			$order->get_order_number(),
			implode( "\n", $items )
		);

		$sent = wp_mail(
			$order->get_billing_email(),
			sprintf(
				/* translators: %s: order number */
				__( 'How did order %s work out?', 'nvr-hs' ),
				$order->get_order_number()
			),
			$body
		);

		if ( ! $sent ) {
			$this->logger->error(
				'post_purchase_email_failed',
				'wp_mail returned false',
				array( 'order_id' => $order->get_id() )
			);
		}
	}
}
