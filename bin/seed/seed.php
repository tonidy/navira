<?php
/**
 * Seed the Navira store with believable reseller purchase history.
 *
 * Not a test fixture. This is demo data for a portfolio: the dashboard, the
 * funnel and the HubSpot segments only mean anything against a customer base
 * with shape to it, so the mix here is deliberate rather than random.
 *
 * Every tier the plugin can assign is represented, which means the dates matter
 * as much as the amounts: at_risk and churned are decided by recency, so those
 * customers need orders genuinely months old.
 *
 * Everything created is tagged with _nvr_demo_seed so purge.php can remove
 * exactly this and nothing else.
 *
 * Env:
 *   NVR_SEED_HUBSPOT=1  queue the HubSpot syncs (default: do not)
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

const SEED_META = '_nvr_demo_seed';

$push_hubspot = '1' === (string) getenv( 'NVR_SEED_HUBSPOT' );

// Unless we are deliberately populating the CRM, keep this entirely local.
// Seeding 60-odd orders would otherwise queue a HubSpot job per order and per
// customer, which is a lot of writes to someone's portal for demo data.
if ( ! $push_hubspot ) {
	add_filter( 'nvr_hs_queue_enabled', '__return_false' );
}

// No MTA in this container, and a seeding run should not try to email anyone.
add_filter( 'pre_wp_mail', '__return_true' );

/**
 * Customers, with the shape of history each should end up having.
 *
 * 'orders' is a list of [days_ago, status] pairs. Amounts come from real
 * catalogue products, so line items and category weighting are meaningful.
 *
 * Reserved example.com/net/org domains (RFC 2606) so nothing here can ever
 * reach a real inbox, even if a workflow is pointed at it later.
 */
$customers = array(
	// Two VIP resellers: high lifetime value, ordering recently.
	array(
		'first'    => 'Siti',
		'last'     => 'Rahayu',
		'email'    => 'siti.rahayu@example.com',
		'company'  => 'Rahayu Beauty Supply',
		'city'     => 'Bandung',
		'register' => true,
		'orders'   => array( array( 92, 'completed' ), array( 61, 'completed' ), array( 34, 'completed' ), array( 12, 'completed' ), array( 3, 'processing' ) ),
		'basket'   => 'large',
	),
	array(
		'first'    => 'Dewi',
		'last'     => 'Lestari',
		'email'    => 'dewi.lestari@example.com',
		'company'  => 'Glow Lestari Distribution',
		'city'     => 'Surabaya',
		'register' => true,
		'orders'   => array( array( 105, 'completed' ), array( 74, 'completed' ), array( 45, 'completed' ), array( 20, 'completed' ), array( 6, 'completed' ) ),
		'basket'   => 'large',
	),

	// Repeat customers: two or three orders, still active.
	array(
		'first'    => 'Putri',
		'last'     => 'Anggraini',
		'email'    => 'putri.anggraini@example.com',
		'company'  => 'Putri Skin Studio',
		'city'     => 'Jakarta',
		'register' => true,
		'orders'   => array( array( 48, 'completed' ), array( 15, 'completed' ) ),
		'basket'   => 'medium',
	),
	array(
		'first'    => 'Rina',
		'last'     => 'Wijaya',
		'email'    => 'rina.wijaya@example.net',
		'company'  => 'Wijaya Kosmetik',
		'city'     => 'Semarang',
		'register' => true,
		'orders'   => array( array( 52, 'completed' ), array( 27, 'completed' ), array( 8, 'completed' ) ),
		'basket'   => 'medium',
	),
	array(
		'first'    => 'Maya',
		'last'     => 'Sari',
		'email'    => 'maya.sari@example.com',
		'company'  => 'Maya Care',
		'city'     => 'Yogyakarta',
		'register' => false,
		'orders'   => array( array( 40, 'completed' ), array( 11, 'processing' ) ),
		'basket'   => 'small',
	),
	array(
		'first'    => 'Indah',
		'last'     => 'Permata',
		'email'    => 'indah.permata@example.org',
		'company'  => 'Permata Glow',
		'city'     => 'Medan',
		'register' => true,
		'orders'   => array( array( 66, 'completed' ), array( 29, 'completed' ) ),
		'basket'   => 'medium',
	),

	// New customers: one recent order each.
	array(
		'first'    => 'Ayu',
		'last'     => 'Kusuma',
		'email'    => 'ayu.kusuma@example.com',
		'company'  => '',
		'city'     => 'Denpasar',
		'register' => false,
		'orders'   => array( array( 9, 'completed' ) ),
		'basket'   => 'small',
	),
	array(
		'first'    => 'Fitri',
		'last'     => 'Handayani',
		'email'    => 'fitri.handayani@example.com',
		'company'  => '',
		'city'     => 'Malang',
		'register' => false,
		'orders'   => array( array( 5, 'completed' ) ),
		'basket'   => 'small',
	),
	array(
		'first'    => 'Nabila',
		'last'     => 'Azzahra',
		'email'    => 'nabila.azzahra@example.net',
		'company'  => 'Nabila Store',
		'city'     => 'Makassar',
		'register' => true,
		'orders'   => array( array( 2, 'processing' ) ),
		'basket'   => 'large',
	),
	array(
		'first'    => 'Citra',
		'last'     => 'Melati',
		'email'    => 'citra.melati@example.com',
		'company'  => '',
		'city'     => 'Palembang',
		'register' => false,
		'orders'   => array( array( 18, 'completed' ) ),
		'basket'   => 'small',
	),
	array(
		'first'    => 'Yuni',
		'last'     => 'Hartati',
		'email'    => 'yuni.hartati@example.org',
		'company'  => '',
		'city'     => 'Bogor',
		'register' => false,
		'orders'   => array( array( 7, 'on-hold' ) ),
		'basket'   => 'medium',
	),

	// At risk: bought well, then went quiet past the 90 day threshold.
	array(
		'first'    => 'Ratna',
		'last'     => 'Puspita',
		'email'    => 'ratna.puspita@example.com',
		'company'  => 'Puspita Beauty',
		'city'     => 'Solo',
		'register' => true,
		'orders'   => array( array( 160, 'completed' ), array( 121, 'completed' ) ),
		'basket'   => 'large',
	),
	array(
		'first'    => 'Lia',
		'last'     => 'Marlina',
		'email'    => 'lia.marlina@example.net',
		'company'  => 'Marlina Kosmetik',
		'city'     => 'Bekasi',
		'register' => false,
		'orders'   => array( array( 133, 'completed' ) ),
		'basket'   => 'medium',
	),

	// Churned: nothing for well over twice the risk window.
	array(
		'first'    => 'Hesti',
		'last'     => 'Nuraini',
		'email'    => 'hesti.nuraini@example.com',
		'company'  => '',
		'city'     => 'Tangerang',
		'register' => false,
		'orders'   => array( array( 265, 'completed' ), array( 240, 'completed' ) ),
		'basket'   => 'medium',
	),
	array(
		'first'    => 'Wulan',
		'last'     => 'Safitri',
		'email'    => 'wulan.safitri@example.org',
		'company'  => '',
		'city'     => 'Depok',
		'register' => false,
		'orders'   => array( array( 310, 'completed' ) ),
		'basket'   => 'small',
	),

	// Unhappy endings, so the funnel and the lost-deal stage are not empty.
	array(
		'first'    => 'Tari',
		'last'     => 'Oktaviani',
		'email'    => 'tari.oktaviani@example.com',
		'company'  => '',
		'city'     => 'Cimahi',
		'register' => false,
		'orders'   => array( array( 22, 'cancelled' ) ),
		'basket'   => 'large',
	),
	array(
		'first'    => 'Sinta',
		'last'     => 'Maharani',
		'email'    => 'sinta.maharani@example.net',
		'company'  => 'Maharani Supply',
		'city'     => 'Padang',
		'register' => true,
		'orders'   => array( array( 55, 'completed' ), array( 31, 'refunded' ) ),
		'basket'   => 'large',
	),
	array(
		'first'    => 'Vina',
		'last'     => 'Kartika',
		'email'    => 'vina.kartika@example.com',
		'company'  => '',
		'city'     => 'Manado',
		'register' => false,
		'orders'   => array( array( 13, 'failed' ) ),
		'basket'   => 'small',
	),
);

/**
 * How many distinct products and how much of each, per basket size. Resellers
 * buy in quantity, which is what makes a high-value deal plausible.
 */
$baskets = array(
	'small'  => array(
		'lines' => 2,
		'qty'   => array( 1, 3 ),
	),
	'medium' => array(
		'lines' => 3,
		'qty'   => array( 3, 6 ),
	),
	'large'  => array(
		'lines' => 4,
		'qty'   => array( 6, 14 ),
	),
);

$product_ids = get_posts(
	array(
		'post_type'      => 'product',
		'posts_per_page' => 60,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
	)
);

if ( count( $product_ids ) < 6 ) {
	WP_CLI::error( 'Need at least 6 products in the catalogue to seed believable baskets.' );
}

// Seeded, so a re-run after a purge produces the same story rather than a
// different one. Demo data that changes shape on every run is confusing.
mt_srand( 20260904 );

$created_orders = 0;
$created_users  = 0;
$revenue        = 0.0;
$summary        = array();

foreach ( $customers as $index => $customer ) {
	$user_id = 0;

	if ( $customer['register'] ) {
		$existing = get_user_by( 'email', $customer['email'] );

		if ( $existing ) {
			$user_id = (int) $existing->ID;
		} else {
			$user_id = wp_insert_user(
				array(
					'user_login'   => sanitize_user( strtolower( $customer['first'] . '.' . $customer['last'] ), true ),
					'user_email'   => $customer['email'],
					'user_pass'    => wp_generate_password( 24 ),
					'first_name'   => $customer['first'],
					'last_name'    => $customer['last'],
					'display_name' => $customer['first'] . ' ' . $customer['last'],
					'role'         => 'customer',
				)
			);

			if ( is_wp_error( $user_id ) ) {
				WP_CLI::warning( sprintf( 'user %s: %s', $customer['email'], $user_id->get_error_message() ) );
				$user_id = 0;
			} else {
				update_user_meta( $user_id, SEED_META, '1' );
				++$created_users;
			}
		}
	}

	$basket      = $baskets[ $customer['basket'] ];
	$order_count = 0;
	$ltv         = 0.0;

	foreach ( $customer['orders'] as $spec ) {
		list( $days_ago, $status ) = $spec;

		// Spread within the day so the revenue-by-day chart is not all midnight.
		$created_at = time() - ( $days_ago * DAY_IN_SECONDS ) + mt_rand( 8 * HOUR_IN_SECONDS, 20 * HOUR_IN_SECONDS );

		$order = wc_create_order( array( 'customer_id' => $user_id ) );

		$order->set_billing_first_name( $customer['first'] );
		$order->set_billing_last_name( $customer['last'] );
		$order->set_billing_email( $customer['email'] );
		$order->set_billing_city( $customer['city'] );
		$order->set_billing_country( 'ID' );

		if ( '' !== $customer['company'] ) {
			$order->set_billing_company( $customer['company'] );
		}

		// Rotate the starting point so customers do not all buy the same thing.
		$offset = ( $index * 3 ) % max( 1, count( $product_ids ) - $basket['lines'] );
		$picked = array_slice( $product_ids, $offset, $basket['lines'] );

		foreach ( $picked as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$qty = mt_rand( $basket['qty'][0], $basket['qty'][1] );

			// Fall back to a plausible wholesale price when the catalogue item
			// has none, so totals never come out as zero.
			$unit = (float) $product->get_price();
			if ( $unit <= 0 ) {
				$unit = (float) mt_rand( 45, 260 );
			}

			$item = new WC_Order_Item_Product();
			$item->set_product( $product );
			$item->set_name( $product->get_name() );
			$item->set_quantity( $qty );
			$item->set_subtotal( $unit * $qty );
			$item->set_total( $unit * $qty );
			$order->add_item( $item );
		}

		$order->set_date_created( gmdate( 'Y-m-d H:i:s', $created_at ) );
		$order->calculate_totals( false );
		$order->update_meta_data( SEED_META, '1' );
		$order->set_status( $status );
		$order->save();

		// set_date_created before save is not always respected once status
		// transitions run, so pin the dates afterwards too. Without this every
		// order looks like it happened today and recency-based tiers collapse.
		$order->set_date_created( gmdate( 'Y-m-d H:i:s', $created_at ) );
		if ( in_array( $status, array( 'completed', 'refunded' ), true ) ) {
			$order->set_date_paid( gmdate( 'Y-m-d H:i:s', $created_at + 1800 ) );
			$order->set_date_completed( gmdate( 'Y-m-d H:i:s', $created_at + 7200 ) );
		}
		$order->save();

		$total = (float) $order->get_total();
		++$created_orders;
		++$order_count;

		if ( in_array( $status, array( 'processing', 'completed' ), true ) ) {
			$ltv     += $total;
			$revenue += $total;
		}
	}

	$summary[] = sprintf(
		'  %-26s %-28s %d order(s), LTV %8.2f',
		$customer['email'],
		'' !== $customer['company'] ? $customer['company'] : '(individual)',
		$order_count,
		$ltv
	);
}

/**
 * Abandoned carts, so the funnel has a top and the recovery panel has rates.
 *
 * Written straight to the plugin's table because there is no way to fake a
 * live WooCommerce session from the CLI. Shapes covered: still open, notified
 * and never came back, notified and recovered.
 */
global $wpdb;
$carts_table = $wpdb->prefix . 'nvr_hs_carts';
$cart_specs  = array(
	// email, hours_ago, total, items, abandoned, notified, recovered
	array( 'ayu.kusuma@example.com', 3, 420.00, 3, true, true, false ),
	array( 'citra.melati@example.com', 9, 1180.00, 5, true, true, false ),
	array( 'yuni.hartati@example.org', 26, 260.00, 2, true, true, true ),
	array( 'lia.marlina@example.net', 50, 2340.00, 8, true, true, false ),
	array( 'fitri.handayani@example.com', 2, 890.00, 4, true, false, false ),
	array( 'tari.oktaviani@example.com', 74, 640.00, 3, true, true, true ),
	array( 'vina.kartika@example.com', 1, 150.00, 1, false, false, false ),
	array( 'maya.sari@example.com', 5, 3100.00, 11, true, true, false ),
	array( 'nabila.azzahra@example.net', 12, 720.00, 4, true, true, false ),
	array( 'hesti.nuraini@example.com', 40, 310.00, 2, true, true, false ),
);

$created_carts = 0;

foreach ( $cart_specs as $i => $spec ) {
	list( $email, $hours_ago, $total, $items, $abandoned, $notified, $recovered ) = $spec;

	$updated = time() - ( $hours_ago * HOUR_IN_SECONDS );

	$contents = array();
	for ( $n = 0; $n < min( 4, $items ); $n++ ) {
		$product = wc_get_product( $product_ids[ ( $i * 2 + $n ) % count( $product_ids ) ] );
		$contents[] = array(
			'id'    => $product ? $product->get_id() : 0,
			'qty'   => max( 1, (int) floor( $items / max( 1, min( 4, $items ) ) ) ),
			'name'  => $product ? $product->get_name() : 'Product',
			'total' => round( $total / max( 1, min( 4, $items ) ), 2 ),
		);
	}

	$inserted = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$carts_table}
			 (session_key, recovery_token, user_id, email, cart_total, currency, item_count, contents, created, updated, abandoned_at, notified_at, recovered_order_id)
			 VALUES (%s, %s, 0, %s, %f, %s, %d, %s, %s, %s, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE cart_total = VALUES(cart_total)",
			'nvr-demo-' . $i,
			'demo' . bin2hex( random_bytes( 12 ) ),
			$email,
			$total,
			get_woocommerce_currency(),
			$items,
			(string) wp_json_encode( $contents ),
			gmdate( 'Y-m-d H:i:s', $updated - 900 ),
			gmdate( 'Y-m-d H:i:s', $updated ),
			$abandoned ? gmdate( 'Y-m-d H:i:s', $updated + 3600 ) : null,
			$notified ? gmdate( 'Y-m-d H:i:s', $updated + 3900 ) : null,
			// A recovered cart points at a real order so the report can join.
			$recovered ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wc_orders WHERE billing_email = %s LIMIT 1", $email ) ) : null
		)
	);

	if ( $inserted ) {
		++$created_carts;
	}
}

// The report caches for five minutes; drop it so the new data shows at once.
if ( class_exists( 'NvrHs\\Admin\\Funnel' ) ) {
	NvrHs\Admin\Funnel::flush();
}

WP_CLI::log( '' );
WP_CLI::log( 'Customers seeded:' );
foreach ( $summary as $line ) {
	WP_CLI::log( $line );
}

WP_CLI::log( '' );
WP_CLI::log(
	sprintf(
		'orders=%d  users=%d  carts=%d  paid revenue=%.2f  hubspot=%s',
		$created_orders,
		$created_users,
		$created_carts,
		$revenue,
		$push_hubspot ? 'queued' : 'skipped'
	)
);
WP_CLI::success( 'Demo data seeded.' );
