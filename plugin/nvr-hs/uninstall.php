<?php
/**
 * Uninstall cleanup.
 *
 * Runs only on delete, not on deactivate, so a temporary deactivation never
 * costs anyone their cart history or their settings.
 *
 * @package NvrHs
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'nvr_hs_log', 'nvr_hs_queue', 'nvr_hs_carts' ) as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $table );
}

delete_option( 'nvr_hs_settings' );
delete_option( 'nvr_hs_token' );
delete_option( 'nvr_hs_db_version' );

foreach ( array( 'nvr_hs_run_queue', 'nvr_hs_sweep_carts', 'nvr_hs_resegment', 'nvr_hs_prune_logs' ) as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

foreach ( array( 7, 30, 90, 365 ) as $days ) {
	delete_transient( 'nvr_hs_funnel_' . $days );
}

// Order meta is left in place on purpose. It records which HubSpot deal an
// order became, which stays true whether or not this plugin is installed, and
// deleting it would make a reinstall create duplicate deals.
