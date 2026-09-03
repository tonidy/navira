#!/usr/bin/env bash
#
# Seed or purge the demo purchase history on the navira store.
#
#   bin/seed-demo.sh                    seed, WooCommerce only, no HubSpot writes
#   bin/seed-demo.sh --hubspot          seed and queue the HubSpot syncs too
#   bin/seed-demo.sh --purge            remove the WooCommerce side
#   bin/seed-demo.sh --purge-hubspot    show what would go from the portal (dry run)
#   bin/seed-demo.sh --purge-hubspot --confirm
#                                       actually remove it from the portal
#   bin/seed-demo.sh --purge-hubspot --confirm --company-id=123,456
#                                       and remove those companies by id
#   bin/seed-demo.sh --report           show what the data looks like now
#
# --purge and --purge-hubspot are deliberately separate. Deleting CRM records
# is not something a local purge should do as a side effect, and the two sides
# can legitimately be reset independently.
#
# HubSpot writes are off by default. Seeding queues one job per order and per
# customer, and that is a lot of traffic to push into a live portal unless you
# actually want the CRM populated.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONTAINER="navira-wordpress-1"
MODE="${1:-seed}"

run_php() {
	docker exec -i -e NVR_SEED_HUBSPOT="${NVR_SEED_HUBSPOT:-0}" "$CONTAINER" \
		sh -c 'cd /var/www/html && wp --allow-root eval-file -'
}

report() {
	docker exec -i "$CONTAINER" sh -c 'cd /var/www/html && wp --allow-root eval-file -' <<'PHPEOF'
<?php
global $wpdb;

$ids = wc_get_orders( array( 'limit' => -1, 'status' => 'any', 'return' => 'ids', 'meta_query' => array( array( 'key' => '_nvr_demo_seed', 'compare' => 'EXISTS' ) ) ) );
$ids = is_array( $ids ) ? $ids : array();

$by_status = array();
$revenue   = 0.0;
$oldest    = PHP_INT_MAX;
$newest    = 0;

foreach ( $ids as $id ) {
	$o = wc_get_order( $id );
	if ( ! $o ) { continue; }
	$s = $o->get_status();
	$by_status[ $s ] = ( $by_status[ $s ] ?? 0 ) + 1;
	if ( in_array( $s, array( 'processing', 'completed' ), true ) ) {
		$revenue += (float) $o->get_total();
	}
	$d = $o->get_date_created();
	if ( $d ) {
		$oldest = min( $oldest, $d->getTimestamp() );
		$newest = max( $newest, $d->getTimestamp() );
	}
}

printf( "seeded orders: %d\n", count( $ids ) );
ksort( $by_status );
foreach ( $by_status as $s => $n ) {
	printf( "  %-12s %d\n", $s, $n );
}
printf( "paid revenue:  %.2f %s\n", $revenue, get_woocommerce_currency() );
if ( $newest > 0 ) {
	printf( "date range:    %s .. %s\n", gmdate( 'Y-m-d', $oldest ), gmdate( 'Y-m-d', $newest ) );
}

printf( "seeded users:  %d\n", count( get_users( array( 'meta_key' => '_nvr_demo_seed', 'meta_value' => '1', 'fields' => 'ID', 'number' => -1 ) ) ) );
printf( "demo carts:    %d\n", (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}nvr_hs_carts WHERE session_key LIKE 'nvr-demo-%'" ) );

echo "\ntiers the plugin computes from this history:\n";
$seg   = new NvrHs\Sync\Segmentation( new NvrHs\Settings(), new NvrHs\Queue( new NvrHs\Logger( new NvrHs\Settings() ) ), new NvrHs\Logger( new NvrHs\Settings() ), new NvrHs\Api\Contacts( new NvrHs\Api\Client( new NvrHs\Settings(), new NvrHs\Logger( new NvrHs\Settings() ) ) ) );
$emails = $wpdb->get_col( "SELECT DISTINCT billing_email FROM {$wpdb->prefix}wc_orders WHERE billing_email <> '' ORDER BY billing_email" );
$tiers  = array();

foreach ( $emails as $email ) {
	$m = $seg->metrics_for( $email );
	$tiers[ $m['tier'] ] = ( $tiers[ $m['tier'] ] ?? 0 ) + 1;
	printf( "  %-30s %-9s orders=%-2d ltv=%9.2f  %s\n", $email, $m['tier'], $m['orders'], $m['ltv'], $m['category'] );
}

echo "\ntier totals:\n";
foreach ( $tiers as $t => $n ) {
	printf( "  %-9s %d\n", $t, $n );
}
PHPEOF
}

case "$MODE" in
	--purge)
		echo "==> purging demo data (WooCommerce side only)"
		run_php < "${HERE}/seed/purge.php"
		echo
		echo "    HubSpot still holds the synced contacts and deals."
		echo "    Run: bin/seed-demo.sh --purge-hubspot"
		;;
	--purge-hubspot)
		if [ -z "${HS_PAK:-}" ]; then
			echo "error: HS_PAK is not set. Run under: fnox exec -- bin/seed-demo.sh --purge-hubspot" >&2
			exit 1
		fi
		# Pass through --confirm and --companies untouched; the script defaults
		# to a dry run when neither is present.
		shift
		python3 "${HERE}/seed/purge-hubspot.py" "$@"
		exit $?
		;;
	--report)
		report
		;;
	--hubspot)
		echo "==> seeding, HubSpot syncs WILL be queued"
		NVR_SEED_HUBSPOT=1 run_php < "${HERE}/seed/seed.php"
		echo
		report
		;;
	seed|"")
		echo "==> seeding, WooCommerce only (no HubSpot writes)"
		NVR_SEED_HUBSPOT=0 run_php < "${HERE}/seed/seed.php"
		echo
		report
		;;
	*)
		echo "usage: $0 [--hubspot|--purge|--report]" >&2
		exit 2
		;;
esac

echo
curl -s -o /dev/null -w "site: home http=%{http_code} ttfb=%{time_starttransfer}s\n" --max-time 30 https://navira.run.biz.id/
