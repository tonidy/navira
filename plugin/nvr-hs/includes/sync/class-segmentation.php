<?php
/**
 * Transaction-based customer segmentation.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

namespace NvrHs\Sync;

use NvrHs\Api\Client;
use NvrHs\Api\Contacts;
use NvrHs\Api\Properties;
use NvrHs\Logger;
use NvrHs\Queue;
use NvrHs\Settings;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Works out what kind of customer someone is from their order history, and
 * writes that to HubSpot as contact properties.
 *
 * Properties rather than HubSpot lists on purpose. A property-based tier can
 * drive an active list, a workflow, a report and a personalisation token all
 * at once, whereas writing list memberships directly means the portal owner
 * cannot change the definition without a code change. The store computes the
 * facts; HubSpot decides what to do with them.
 *
 * Statuses that count as revenue are the ones Woo itself treats as paid, so
 * a pending or cancelled order never inflates lifetime value.
 */
final class Segmentation {

	public const JOB = 'customer_sync';

	/** Statuses that represent money actually taken. */
	private const PAID_STATUSES = array( 'processing', 'completed' );

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

	public function register_hooks(): void {
		add_action( \NvrHs\Installer::CRON_SEGMENT, array( $this, 'resegment_stale' ) );
		$this->queue->register( self::JOB, array( $this, 'handle' ) );
	}

	/**
	 * Queue a recalculation for one customer.
	 */
	public function enqueue( string $email, int $delay = 0 ): void {
		$email = strtolower( trim( $email ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$this->queue->push( self::JOB, array( 'email' => $email ), $email, $delay );
	}

	/**
	 * Queue handler: compute and push one customer's segment.
	 *
	 * @param array<string,mixed> $payload Job payload.
	 * @throws \RuntimeException When the HubSpot write fails.
	 */
	public function handle( array $payload ): void {
		$email = strtolower( (string) ( $payload['email'] ?? '' ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$metrics = $this->metrics_for( $email );

		$properties = array(
			'nvr_ltv'               => (string) round( $metrics['ltv'], 2 ),
			'nvr_order_count'       => (string) $metrics['orders'],
			'nvr_aov'               => (string) round( $metrics['aov'], 2 ),
			'nvr_customer_tier'     => $metrics['tier'],
			'nvr_favorite_category' => $metrics['category'],
		);

		if ( $metrics['first_order'] > 0 ) {
			$properties['nvr_first_order_at'] = Properties::timestamp( $metrics['first_order'] );
		}

		if ( $metrics['last_order'] > 0 ) {
			$properties['nvr_last_order_at'] = Properties::timestamp( $metrics['last_order'] );
		}

		if ( '' !== $metrics['last_number'] ) {
			$properties['nvr_last_order_number'] = $metrics['last_number'];
		}

		if ( $metrics['user_id'] > 0 ) {
			$properties['nvr_wp_user_id'] = (string) $metrics['user_id'];
		}

		$result = $this->contacts->upsert( $email, $properties );

		if ( is_wp_error( $result ) ) {
			// An address HubSpot refuses will be refused again on every retry.
			if ( Client::is_permanent( $result ) ) {
				$this->logger->error(
					'segment_rejected',
					sprintf( 'HubSpot will not accept %s, not retrying. %s', $email, $result->get_error_message() ),
					array( 'email' => $email )
				);
				return;
			}

			throw new \RuntimeException( 'Segment write failed: ' . $result->get_error_message() );
		}

		$this->logger->debug(
			'segment_synced',
			sprintf( '%s -> %s (LTV %.2f over %d orders)', $email, $metrics['tier'], $metrics['ltv'], $metrics['orders'] ),
			$metrics
		);
	}

	/**
	 * Compute a customer's transaction metrics.
	 *
	 * Uses wc_get_orders rather than a direct query so it works the same on
	 * HPOS, which this store runs, and on legacy post storage.
	 *
	 * @return array{
	 *   orders:int, ltv:float, aov:float, first_order:int, last_order:int,
	 *   last_number:string, category:string, tier:string, user_id:int
	 * }
	 */
	public function metrics_for( string $email ): array {
		$orders = wc_get_orders(
			array(
				'billing_email' => $email,
				'status'        => self::PAID_STATUSES,
				'limit'         => -1,
				'orderby'       => 'date',
				'order'         => 'ASC',
				'return'        => 'objects',
			)
		);

		$orders = is_array( $orders ) ? $orders : array();

		$ltv         = 0.0;
		$first       = 0;
		$last        = 0;
		$last_number = '';
		$user_id     = 0;
		$categories  = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$ltv += (float) $order->get_total();

			$created = $order->get_date_created();
			$stamp   = $created ? $created->getTimestamp() : 0;

			if ( $stamp > 0 ) {
				if ( 0 === $first || $stamp < $first ) {
					$first = $stamp;
				}
				if ( $stamp > $last ) {
					$last        = $stamp;
					$last_number = (string) $order->get_order_number();
				}
			}

			if ( 0 === $user_id ) {
				$user_id = (int) $order->get_customer_id();
			}

			// Weight categories by line revenue, not item count: for a
			// skincare supplier, one case of serum matters more than five
			// sachets of sample cream.
			foreach ( $order->get_items() as $item ) {
				$product_id = (int) $item->get_product_id();
				$terms      = get_the_terms( $product_id, 'product_cat' );

				if ( ! is_array( $terms ) ) {
					continue;
				}

				foreach ( $terms as $term ) {
					$categories[ $term->name ] = ( $categories[ $term->name ] ?? 0.0 ) + (float) $item->get_total();
				}
			}
		}

		$count = count( $orders );
		$aov   = $count > 0 ? $ltv / $count : 0.0;

		arsort( $categories );
		$category = (string) ( array_key_first( $categories ) ?? '' );

		return array(
			'orders'      => $count,
			'ltv'         => $ltv,
			'aov'         => $aov,
			'first_order' => $first,
			'last_order'  => $last,
			'last_number' => $last_number,
			'category'    => $category,
			'tier'        => $this->tier_for( $count, $ltv, $last ),
			'user_id'     => $user_id,
		);
	}

	/**
	 * Decide a tier.
	 *
	 * Recency is checked before value, deliberately. A reseller who spent a
	 * fortune and then vanished for four months is a retention problem, and
	 * labelling them VIP hides exactly the thing someone should act on.
	 */
	private function tier_for( int $orders, float $ltv, int $last_order ): string {
		if ( 0 === $orders ) {
			return 'prospect';
		}

		$risk_days = max( 1, $this->settings->int( 'churn_risk_days' ) );
		$idle_days = $last_order > 0 ? (int) floor( ( time() - $last_order ) / DAY_IN_SECONDS ) : PHP_INT_MAX;

		if ( $idle_days > $risk_days * 2 ) {
			return 'churned';
		}

		if ( $idle_days > $risk_days ) {
			return 'at_risk';
		}

		if ( $ltv >= $this->settings->float( 'tier_vip_ltv' ) ) {
			return 'vip';
		}

		if ( $orders >= max( 2, $this->settings->int( 'tier_repeat_orders' ) ) ) {
			return 'repeat';
		}

		return 'new';
	}

	/**
	 * Nightly catch-up: re-evaluate customers whose tier can change without
	 * them doing anything, which is everyone whose recency crosses a
	 * threshold. Bounded per run so a large customer base does not turn one
	 * cron tick into a long job.
	 */
	public function resegment_stale(): void {
		$risk_days = max( 1, $this->settings->int( 'churn_risk_days' ) );

		$orders = wc_get_orders(
			array(
				'status'       => self::PAID_STATUSES,
				'limit'        => 100,
				'orderby'      => 'date',
				'order'        => 'ASC',
				'date_created' => '<' . ( time() - ( $risk_days * DAY_IN_SECONDS ) ),
				'return'       => 'objects',
			)
		);

		$orders = is_array( $orders ) ? $orders : array();
		$seen   = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$email = strtolower( $order->get_billing_email() );

			if ( '' === $email || isset( $seen[ $email ] ) ) {
				continue;
			}

			$seen[ $email ] = true;
			$this->enqueue( $email );
		}

		if ( $seen ) {
			$this->logger->debug( 'resegment', sprintf( '%d customers queued for re-evaluation', count( $seen ) ) );
		}
	}
}
