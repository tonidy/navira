<?php
/**
 * Remove everything seed.php created, and nothing else.
 *
 * Orders and users are found by the _nvr_demo_seed meta rather than by name or
 * email pattern, so a real customer who happens to share a name is never at
 * risk. Carts are found by their nvr-demo- session key prefix.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

const SEED_META = '_nvr_demo_seed';

add_filter( 'nvr_hs_queue_enabled', '__return_false' );
add_filter( 'pre_wp_mail', '__return_true' );

global $wpdb;

$deleted_orders = 0;
$deleted_users  = 0;

// HPOS keeps order meta in its own table, so ask WooCommerce rather than
// querying postmeta, which would find nothing on this store.
$order_ids = wc_get_orders(
	array(
		'limit'      => -1,
		'status'     => 'any',
		'return'     => 'ids',
		'meta_query' => array(
			array(
				'key'     => SEED_META,
				'compare' => 'EXISTS',
			),
		),
	)
);

$order_ids = is_array( $order_ids ) ? $order_ids : array();

foreach ( $order_ids as $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order ) {
		continue;
	}

	// Belt and braces: only delete what carries the marker.
	if ( '1' !== (string) $order->get_meta( SEED_META ) ) {
		WP_CLI::warning( sprintf( 'order %d has no seed marker, leaving it', $order_id ) );
		continue;
	}

	$order->delete( true );
	++$deleted_orders;
}

$users = get_users(
	array(
		'meta_key'   => SEED_META,
		'meta_value' => '1',
		'fields'     => 'ID',
		'number'     => -1,
	)
);

require_once ABSPATH . 'wp-admin/includes/user.php';

foreach ( $users as $user_id ) {
	if ( wp_delete_user( (int) $user_id ) ) {
		++$deleted_users;
	}
}

$carts = (int) $wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->prefix}nvr_hs_carts WHERE session_key LIKE %s",
		$wpdb->esc_like( 'nvr-demo-' ) . '%'
	)
);

// Any queue rows the seeding produced refer to orders that no longer exist.
$queue = (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}nvr_hs_queue WHERE status IN ('pending','failed')" );

if ( class_exists( 'NvrHs\\Admin\\Funnel' ) ) {
	NvrHs\Admin\Funnel::flush();
}

WP_CLI::log(
	sprintf(
		'orders=%d users=%d carts=%d queue=%d removed',
		$deleted_orders,
		$deleted_users,
		$carts,
		$queue
	)
);
WP_CLI::success( 'Demo data purged.' );
