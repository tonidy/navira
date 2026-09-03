<?php
/**
 * Funnel and sales figures.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

namespace NvrHs\Admin;

use NvrHs\Sync\Cart_Recovery;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Computes the numbers the dashboard shows.
 *
 * Read straight from the store, not from HubSpot. The store is the system of
 * record for what was actually sold, and asking HubSpot would mean the report
 * silently disagrees with WooCommerce whenever a sync is behind.
 *
 * Results are cached in a transient because this runs several aggregate
 * queries and the dashboard is a page someone leaves open.
 */
final class Funnel {

	private const CACHE_KEY = 'nvr_hs_funnel_';
	private const CACHE_TTL = 300;

	/** Statuses that represent money actually taken. */
	private const PAID_STATUSES = array( 'processing', 'completed' );

	/**
	 * Everything the dashboard needs, for a window in days.
	 *
	 * @return array<string,mixed>
	 */
	public function report( int $days = 30 ): array {
		$days  = max( 1, min( 365, $days ) );
		$key   = self::CACHE_KEY . $days;
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$since = time() - ( $days * DAY_IN_SECONDS );

		$report = array(
			'days'   => $days,
			'carts'  => $this->cart_stats( $since ),
			'orders' => $this->order_stats( $since ),
		);

		$report['funnel'] = $this->funnel_steps( $report['carts'], $report['orders'] );

		set_transient( $key, $report, self::CACHE_TTL );

		return $report;
	}

	/**
	 * @return array<string,int|float>
	 */
	private function cart_stats( int $since ): array {
		global $wpdb;

		$table  = Cart_Recovery::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', $since );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT
					COUNT(*) AS created,
					SUM(CASE WHEN abandoned_at IS NOT NULL THEN 1 ELSE 0 END) AS abandoned,
					SUM(CASE WHEN notified_at IS NOT NULL THEN 1 ELSE 0 END) AS notified,
					SUM(CASE WHEN recovered_order_id IS NOT NULL THEN 1 ELSE 0 END) AS converted,
					SUM(CASE WHEN notified_at IS NOT NULL AND recovered_order_id IS NOT NULL THEN 1 ELSE 0 END) AS recovered,
					COALESCE(AVG(cart_total), 0) AS avg_value,
					COALESCE(SUM(CASE WHEN abandoned_at IS NOT NULL AND recovered_order_id IS NULL THEN cart_total ELSE 0 END), 0) AS lost_value
				 FROM ' . $table . '
				 WHERE created >= %s',
				$cutoff
			),
			ARRAY_A
		);

		$row = is_array( $row ) ? $row : array();

		return array(
			'created'    => (int) ( $row['created'] ?? 0 ),
			'abandoned'  => (int) ( $row['abandoned'] ?? 0 ),
			'notified'   => (int) ( $row['notified'] ?? 0 ),
			'converted'  => (int) ( $row['converted'] ?? 0 ),
			'recovered'  => (int) ( $row['recovered'] ?? 0 ),
			'avg_value'  => (float) ( $row['avg_value'] ?? 0 ),
			'lost_value' => (float) ( $row['lost_value'] ?? 0 ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function order_stats( int $since ): array {
		$orders = wc_get_orders(
			array(
				'limit'        => -1,
				'status'       => array_merge( self::PAID_STATUSES, array( 'pending', 'on-hold', 'cancelled', 'refunded', 'failed' ) ),
				'date_created' => '>' . $since,
				'return'       => 'objects',
			)
		);

		$orders = is_array( $orders ) ? $orders : array();

		$placed    = 0;
		$paid      = 0;
		$completed = 0;
		$revenue   = 0.0;
		$by_day    = array();
		$products  = array();
		$high_value = 0;

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			++$placed;
			$status = $order->get_status();

			if ( in_array( $status, self::PAID_STATUSES, true ) ) {
				++$paid;
				$total    = (float) $order->get_total();
				$revenue += $total;

				$created = $order->get_date_created();
				$day     = $created ? $created->date( 'Y-m-d' ) : gmdate( 'Y-m-d' );

				$by_day[ $day ] = ( $by_day[ $day ] ?? 0.0 ) + $total;

				foreach ( $order->get_items() as $item ) {
					$name              = $item->get_name();
					$products[ $name ] = ( $products[ $name ] ?? 0.0 ) + (float) $item->get_total();
				}
			}

			if ( 'completed' === $status ) {
				++$completed;
			}

			if ( '' !== (string) $order->get_meta( '_nvr_hs_deal_id' ) ) {
				++$high_value;
			}
		}

		ksort( $by_day );
		arsort( $products );

		return array(
			'placed'      => $placed,
			'paid'        => $paid,
			'completed'   => $completed,
			'revenue'     => $revenue,
			'aov'         => $paid > 0 ? $revenue / $paid : 0.0,
			'by_day'      => $by_day,
			'top_products' => array_slice( $products, 0, 10, true ),
			'with_deals'  => $high_value,
		);
	}

	/**
	 * The funnel proper: each step with its count and drop-off from the last.
	 *
	 * @param array<string,int|float> $carts  Cart stats.
	 * @param array<string,mixed>     $orders Order stats.
	 * @return array<int,array{label:string,count:int,rate:?float}>
	 */
	private function funnel_steps( array $carts, array $orders ): array {
		$steps = array(
			array(
				'label' => __( 'Carts started', 'nvr-hs' ),
				'count' => (int) $carts['created'],
			),
			array(
				'label' => __( 'Reached checkout', 'nvr-hs' ),
				'count' => (int) $orders['placed'],
			),
			array(
				'label' => __( 'Paid', 'nvr-hs' ),
				'count' => (int) $orders['paid'],
			),
			array(
				'label' => __( 'Completed', 'nvr-hs' ),
				'count' => (int) $orders['completed'],
			),
		);

		$first = $steps[0]['count'];
		$out   = array();

		foreach ( $steps as $index => $step ) {
			$previous = 0 === $index ? $step['count'] : $steps[ $index - 1 ]['count'];

			$out[] = array(
				'label' => $step['label'],
				'count' => $step['count'],
				// Share of the top of the funnel, which is the number people
				// actually mean by "conversion rate".
				'rate'  => $first > 0 ? ( $step['count'] / $first ) * 100 : null,
				'step_rate' => $previous > 0 ? ( $step['count'] / $previous ) * 100 : null,
			);
		}

		return $out;
	}

	/**
	 * Drop the cached report. Called after a manual queue run so the numbers
	 * on screen match what just happened.
	 */
	public static function flush(): void {
		foreach ( array( 7, 30, 90, 365 ) as $days ) {
			delete_transient( self::CACHE_KEY . $days );
		}
	}
}
