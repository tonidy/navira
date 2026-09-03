<?php
/**
 * Abandoned cart tracking and recovery.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

namespace NvrHs\Sync;

use NvrHs\Api\Contacts;
use NvrHs\Api\Properties;
use NvrHs\Logger;
use NvrHs\Queue;
use NvrHs\Settings;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Records live carts, decides when one is abandoned, and hands off recovery.
 *
 * Two things are deliberate here. First, carts are stored in our own table
 * rather than inferred from WooCommerce sessions, because sessions expire and
 * take the evidence with them. Second, the abandoned decision is made by cron
 * and not by a page view, so a customer who simply closed the tab is still
 * caught.
 *
 * How the email actually gets sent depends on the email_mode setting. In
 * 'workflow' mode we only write contact properties and a HubSpot workflow
 * triggered on nvr_cart_abandoned_at does the sending, which is the right
 * design when the portal has Marketing Hub Professional. In 'woocommerce'
 * mode we send it ourselves, so the feature still works on a free portal.
 */
final class Cart_Recovery {

	public const TABLE      = 'nvr_hs_carts';
	public const JOB_NOTIFY = 'cart_notify';

	/** Do not rewrite the cart row more than once every this many seconds. */
	private const THROTTLE = 30;

	private Settings $settings;
	private Queue $queue;
	private Logger $logger;
	private Contacts $contacts;

	public function __construct( Settings $settings, Queue $queue, Logger $logger, Contacts $contacts ) {
		$this->settings = $settings;
		$this->queue    = $queue;
		$this->logger   = $logger;
		$this->contacts = $contacts;
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public function register_hooks(): void {
		add_action( 'woocommerce_cart_updated', array( $this, 'capture' ) );

		// Checkout is where we usually first learn the email of a guest.
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'capture_checkout_email' ), 10, 1 );

		// A placed order means this cart converted.
		add_action( 'woocommerce_checkout_order_created', array( $this, 'on_order_created' ) );

		add_action( 'template_redirect', array( $this, 'maybe_restore' ) );

		add_action( \NvrHs\Installer::CRON_CARTS, array( $this, 'sweep' ) );

		$this->queue->register( self::JOB_NOTIFY, array( $this, 'handle_notify' ) );
	}

	/**
	 * Write the current cart to the table.
	 */
	public function capture(): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
			return;
		}

		$session_key = (string) WC()->session->get_customer_id();

		if ( '' === $session_key ) {
			return;
		}

		$cart = WC()->cart;

		// An emptied cart is not an abandoned cart. Drop the row so the sweep
		// cannot chase someone who deliberately cleared it.
		if ( $cart->is_empty() ) {
			$this->forget( $session_key );
			return;
		}

		// woocommerce_cart_updated fires several times per request. Throttle
		// on our own row rather than doing four writes per page view.
		$existing = $this->find_by_session( $session_key );

		if ( $existing && strtotime( (string) $existing->updated ) > time() - self::THROTTLE ) {
			return;
		}

		global $wpdb;

		$now   = gmdate( 'Y-m-d H:i:s' );
		$email = $this->current_email();

		$contents = array();
		foreach ( $cart->get_cart() as $line ) {
			$product = $line['data'] ?? null;
			$contents[] = array(
				'id'    => (int) ( $line['product_id'] ?? 0 ),
				'qty'   => (int) ( $line['quantity'] ?? 0 ),
				'name'  => $product ? $product->get_name() : '',
				'total' => (float) ( $line['line_total'] ?? 0 ),
			);
		}

		// Written as one prepared upsert rather than via $wpdb->insert and
		// $wpdb->update. Those two run every value through prepare() as a
		// string, which turns a PHP null into '', and '' is not a legal
		// DATETIME under MySQL 8's STRICT_TRANS_TABLES. It would also make
		// email = '' rather than NULL, quietly breaking the sweep's
		// "email IS NOT NULL" guard so we would chase carts with no address.
		//
		// session_key is UNIQUE, so ON DUPLICATE KEY UPDATE gives us
		// create-or-update in a single statement and no race between the two.
		// recovery_token is only set on insert; it must stay stable because it
		// is already out in an email.
		$sql = 'INSERT INTO ' . self::table() . '
			(session_key, recovery_token, user_id, email, cart_total, currency, item_count, contents, created, updated, abandoned_at)
			VALUES (%s, %s, %d, NULLIF(%s, \'\'), %f, %s, %d, %s, %s, %s, NULL)
			ON DUPLICATE KEY UPDATE
				user_id      = VALUES(user_id),
				-- never overwrite a known address with an empty one
				email        = COALESCE(VALUES(email), email),
				cart_total   = VALUES(cart_total),
				currency     = VALUES(currency),
				item_count   = VALUES(item_count),
				contents     = VALUES(contents),
				updated      = VALUES(updated),
				-- a change means the cart is live again
				abandoned_at = NULL';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->query(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL
				$session_key,
				$existing ? (string) $existing->recovery_token : bin2hex( random_bytes( 16 ) ),
				get_current_user_id(),
				$email,
				(float) $cart->get_total( 'edit' ),
				get_woocommerce_currency(),
				(int) $cart->get_cart_contents_count(),
				(string) wp_json_encode( $contents ),
				$now,
				$now
			)
		);
	}

	/**
	 * Capture the email a guest typed at checkout, even if the order then
	 * fails validation. That is exactly the cart most worth recovering.
	 *
	 * @param array<string,mixed> $fields Posted checkout fields.
	 */
	public function capture_checkout_email( $fields ): void {
		$email = '';

		if ( is_array( $fields ) && isset( $fields['billing_email'] ) ) {
			$email = sanitize_email( (string) $fields['billing_email'] );
		}

		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'email'   => $email,
				'updated' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'session_key' => (string) WC()->session->get_customer_id() ),
			array( '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Mark the cart as converted and clear the HubSpot cart state.
	 *
	 * @param WC_Order $order Created order.
	 */
	public function on_order_created( $order ): void {
		if ( ! $order instanceof WC_Order || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$session_key = (string) WC()->session->get_customer_id();
		$row         = $this->find_by_session( $session_key );

		if ( ! $row ) {
			return;
		}

		global $wpdb;

		$wpdb->update(
			self::table(),
			array( 'recovered_order_id' => $order->get_id() ),
			array( 'id' => (int) $row->id ),
			array( '%d' ),
			array( '%d' )
		);

		// If we had already told HubSpot this cart was abandoned, retract it,
		// otherwise a recovery workflow emails a customer who just bought.
		if ( null !== $row->abandoned_at && ! empty( $row->email ) ) {
			$this->queue->push(
				self::JOB_NOTIFY,
				array(
					'cart_id' => (int) $row->id,
					'action'  => 'clear',
				),
				'clear-' . $row->id
			);
		}
	}

	/**
	 * Find carts that have gone quiet and start recovery for them.
	 */
	public function sweep(): void {
		global $wpdb;

		$minutes = max( 5, $this->settings->int( 'cart_abandon_minutes' ) );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $minutes * 60 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . '
				 WHERE abandoned_at IS NULL
				   AND notified_at IS NULL
				   AND recovered_order_id IS NULL
				   AND item_count > 0
				   AND email IS NOT NULL
				   AND email <> \'\'
				   AND updated < %s
				 LIMIT 50',
				$cutoff
			)
		);

		foreach ( $rows as $row ) {
			$wpdb->update(
				self::table(),
				array( 'abandoned_at' => gmdate( 'Y-m-d H:i:s' ) ),
				array( 'id' => (int) $row->id ),
				array( '%s' ),
				array( '%d' )
			);

			$this->queue->push(
				self::JOB_NOTIFY,
				array(
					'cart_id' => (int) $row->id,
					'action'  => 'abandon',
				),
				'abandon-' . $row->id
			);
		}

		if ( $rows ) {
			$this->logger->debug( 'cart_sweep', sprintf( '%d carts marked abandoned', count( $rows ) ) );
		}
	}

	/**
	 * Queue handler for both abandoning and retracting.
	 *
	 * @param array<string,mixed> $payload Job payload.
	 * @throws \RuntimeException When the HubSpot write fails.
	 */
	public function handle_notify( array $payload ): void {
		$cart_id = (int) ( $payload['cart_id'] ?? 0 );
		$action  = (string) ( $payload['action'] ?? 'abandon' );
		$row     = $this->find_by_id( $cart_id );

		if ( ! $row || empty( $row->email ) ) {
			return;
		}

		if ( 'clear' === $action ) {
			$result = $this->contacts->clear_cart_state( (string) $row->email );

			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( 'Clearing cart state failed: ' . $result->get_error_message() );
			}

			return;
		}

		$recovery_url = add_query_arg( 'nvr_recover', (string) $row->recovery_token, home_url( '/' ) );

		$result = $this->contacts->upsert(
			(string) $row->email,
			array(
				'nvr_cart_value'        => (string) round( (float) $row->cart_total, 2 ),
				'nvr_cart_item_count'   => (string) (int) $row->item_count,
				'nvr_cart_abandoned_at' => Properties::timestamp( (int) strtotime( (string) $row->abandoned_at ) ),
				'nvr_cart_recovery_url' => $recovery_url,
			)
		);

		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( 'Cart state write failed: ' . $result->get_error_message() );
		}

		// In workflow mode HubSpot sends the email off the property change we
		// just made. Otherwise send it from here.
		if ( ! $this->settings->workflow_email_mode() ) {
			$this->send_recovery_email( $row, $recovery_url );
		}

		global $wpdb;
		$wpdb->update(
			self::table(),
			array( 'notified_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => $cart_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Fallback sender for portals without workflow access.
	 *
	 * @param object $row          Cart row.
	 * @param string $recovery_url Link that rebuilds the cart.
	 */
	private function send_recovery_email( object $row, string $recovery_url ): void {
		$items = json_decode( (string) $row->contents, true );
		$items = is_array( $items ) ? $items : array();

		$lines = array();
		foreach ( $items as $item ) {
			$lines[] = sprintf( '- %s x%d', (string) ( $item['name'] ?? '' ), (int) ( $item['qty'] ?? 0 ) );
		}

		$body = sprintf(
			/* translators: 1: item list, 2: recovery URL */
			__(
				"You left something behind:\n\n%1\$s\n\nPick up where you left off:\n%2\$s",
				'nvr-hs'
			),
			implode( "\n", $lines ),
			$recovery_url
		);

		$sent = wp_mail(
			(string) $row->email,
			__( 'Your Navira cart is waiting', 'nvr-hs' ),
			$body
		);

		if ( ! $sent ) {
			$this->logger->error( 'cart_email_failed', 'wp_mail returned false', array( 'cart_id' => (int) $row->id ) );
		}
	}

	/**
	 * Rebuild a cart from a recovery link and send the visitor to checkout.
	 */
	public function maybe_restore(): void {
		$token = isset( $_GET['nvr_recover'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['nvr_recover'] ) ) : '';

		if ( '' === $token || ! function_exists( 'WC' ) ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE recovery_token = %s LIMIT 1', $token )
		);

		if ( ! $row ) {
			return;
		}

		if ( ! WC()->cart ) {
			wc_load_cart();
		}

		$items = json_decode( (string) $row->contents, true );
		$items = is_array( $items ) ? $items : array();

		WC()->cart->empty_cart();

		foreach ( $items as $item ) {
			$product_id = (int) ( $item['id'] ?? 0 );
			$qty        = max( 1, (int) ( $item['qty'] ?? 1 ) );

			if ( $product_id > 0 ) {
				WC()->cart->add_to_cart( $product_id, $qty );
			}
		}

		$this->logger->debug( 'cart_restored', 'Recovery link used', array( 'cart_id' => (int) $row->id ) );

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	private function current_email(): string {
		$user = wp_get_current_user();

		if ( $user && $user->exists() && is_email( $user->user_email ) ) {
			return strtolower( $user->user_email );
		}

		if ( function_exists( 'WC' ) && WC()->customer ) {
			$email = (string) WC()->customer->get_billing_email();
			if ( is_email( $email ) ) {
				return strtolower( $email );
			}
		}

		return '';
	}

	private function find_by_session( string $session_key ): ?object {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE session_key = %s LIMIT 1', $session_key )
		);

		return $row ?: null;
	}

	private function find_by_id( int $id ): ?object {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', $id )
		);

		return $row ?: null;
	}

	private function forget( string $session_key ): void {
		global $wpdb;

		// Keep rows we have already acted on, so reporting stays honest about
		// how many carts were abandoned and how many came back.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE session_key = %s AND notified_at IS NULL',
				$session_key
			)
		);
	}
}
