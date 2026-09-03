#!/usr/bin/env bash
#
# End-to-end test: does a high-value WooCommerce order become a HubSpot deal?
#
# Creates exactly one order for nvr-e2e@example.test. Run bin/e2e/cleanup.sh
# afterwards, or on failure, to remove everything.
#
# PHP goes in on stdin via `wp eval-file -`; see the note in cleanup.sh.
#
# Usage:  HS_PAK=... bin/e2e/test.sh

set -uo pipefail

# example.com, not example.test: HubSpot validates the domain and rejects a
# reserved .test TLD with INVALID_EMAIL, which is a property of the address
# rather than anything to do with the plugin.
EMAIL="nvr-e2e@example.com"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONTAINER="navira-wordpress-1"

export E2E_HERE="$HERE"

# Diagnostic output: stderr merged in, because we want to see warnings.
PHP() {
	docker exec -i -e NVR_E2E_EMAIL="$EMAIL" "$CONTAINER" \
		sh -c 'cd /var/www/html && wp --allow-root eval-file -' 2>&1
}

# Value capture: stderr dropped, and the value is fished out of a marker.
# The container has no MTA, so WooCommerce status changes emit
# "sh: 1: /usr/sbin/sendmail: not found" on stdout, which would otherwise be
# concatenated into whatever we are trying to read.
PHP_VAL() {
	docker exec -i -e NVR_E2E_EMAIL="$EMAIL" "$CONTAINER" \
		sh -c 'cd /var/www/html && wp --allow-root eval-file -' 2>/dev/null \
		| sed -n 's/.*<<<\(.*\)>>>.*/\1/p' | tr -d '[:space:]'
}

WP() { docker exec "$CONTAINER" sh -c "cd /var/www/html && wp --allow-root $*"; }

fail() { echo; echo "FAILED: $*" >&2; echo "Run bin/e2e/cleanup.sh to clean up." >&2; exit 1; }

deal_stage() {
	python3 - "$1" <<'PY'
import os, sys
sys.path.insert(0, os.environ["E2E_HERE"])
import hs
s, d = hs.get_deal(f"order-{sys.argv[1]}")
print((d.get("properties", {}) or {}).get("dealstage", "") if s == 200 else "")
PY
}

echo "=== 0. preconditions ==="
WP nvr-hs test || fail "no HubSpot connection"
if python3 "${HERE}/hs.py" show-contact "$EMAIL" >/dev/null 2>&1; then
	fail "contact $EMAIL already exists; run cleanup.sh first"
fi
echo "    contact $EMAIL does not exist yet, good"

SETTINGS=$(PHP_VAL <<'PHPEOF'
<?php
$s = new NvrHs\Settings();
printf( '<<<%s|%s|%s>>>', $s->float( 'high_value_threshold' ), $s->str( 'deal_stage_new' ), $s->str( 'deal_stage_won' ) );
PHPEOF
)
IFS='|' read -r THRESHOLD STAGE_NEW STAGE_WON <<< "$SETTINGS"
echo "    threshold=$THRESHOLD  new_stage=$STAGE_NEW  won_stage=$STAGE_WON"
[ -n "$STAGE_NEW" ] || fail "could not read settings"

echo
echo "=== 1. create a high-value order ==="
# Priced above the threshold on purpose so the pipeline rule fires, with two
# distinct products so line items and the category weighting have real input.
ORDER_ID=$(PHP_VAL <<'PHPEOF'
<?php
// No MTA in this container, so let wp_mail report success without shelling
// out to sendmail. Keeps the test from being about mail delivery.
add_filter( 'pre_wp_mail', '__return_true' );

$products = get_posts( array( 'post_type' => 'product', 'numberposts' => 2, 'fields' => 'ids' ) );
if ( count( $products ) < 2 ) {
	echo 'ERR:need-two-products';
	return;
}

$order = wc_create_order();
$order->set_billing_email( getenv( 'NVR_E2E_EMAIL' ) );
$order->set_billing_first_name( 'Nvr' );
$order->set_billing_last_name( 'E2E' );

foreach ( $products as $i => $pid ) {
	$product = wc_get_product( $pid );
	$item    = new WC_Order_Item_Product();
	$item->set_product( $product );
	$item->set_quantity( 3 );
	// Force the unit price so the total clears the threshold whatever the
	// catalogue happens to be priced at.
	$unit = 0 === $i ? 250.00 : 150.00;
	$item->set_subtotal( $unit * 3 );
	$item->set_total( $unit * 3 );
	$item->set_name( $product->get_name() );
	$order->add_item( $item );
}

$order->calculate_totals( false );
$order->set_status( 'processing' );
$order->save();
printf( '<<<%d>>>', $order->get_id() );
PHPEOF
)

case "$ORDER_ID" in
	''|ERR:*|*[!0-9]*) fail "could not create order (got: $ORDER_ID)" ;;
esac

TOTAL=$(PHP_VAL <<PHPEOF
<?php
printf( '<<<%s>>>', wc_get_order( ${ORDER_ID} )->get_total() );
PHPEOF
)
echo "    order $ORDER_ID created, total $TOTAL, status processing"

echo
echo "=== 2. did the order enqueue a deal sync? ==="
PHP <<'PHPEOF'
<?php
global $wpdb;
$rows = $wpdb->get_results( "SELECT id, job, status, attempts, dedupe_key, available_at FROM {$wpdb->prefix}nvr_hs_queue ORDER BY id" );
if ( ! $rows ) {
	echo "    queue is EMPTY - the order hook did not fire\n";
}
foreach ( $rows as $r ) {
	printf( "    #%d %-14s %-8s attempts=%d dedupe=%s available=%s\n", $r->id, $r->job, $r->status, $r->attempts, $r->dedupe_key, $r->available_at );
}
PHPEOF

echo
echo "=== 3. wait for the system cron to drain it (no manual queue run) ==="
DEAL_OK=0
for i in $(seq 1 14); do
	if python3 "${HERE}/hs.py" show-deal "order-${ORDER_ID}" >/dev/null 2>&1; then
		echo "    deal appeared within ~$(( i * 15 ))s, drained by cron"
		DEAL_OK=1
		break
	fi
	sleep 15
done

if [ "$DEAL_OK" -eq 0 ]; then
	echo "    not drained within 210s; draining by hand to isolate cron from the sync"
	WP nvr-hs queue
	if python3 "${HERE}/hs.py" show-deal "order-${ORDER_ID}" >/dev/null 2>&1; then
		fail "the sync works but the system cron is not firing"
	fi
	fail "the deal was never created"
fi

echo
echo "=== 4. the deal, as HubSpot has it ==="
python3 "${HERE}/hs.py" show-deal "order-${ORDER_ID}" || fail "deal lookup failed"

echo
echo "=== 5. assertions on the new deal ==="
python3 - "$ORDER_ID" "$TOTAL" "$STAGE_NEW" <<'PY' || fail "deal assertions failed"
import os, sys
sys.path.insert(0, os.environ["E2E_HERE"])
import hs  # noqa: E402

order_id, total, stage_new = sys.argv[1], float(sys.argv[2]), sys.argv[3]

status, data = hs.get_deal(f"order-{order_id}")
if status != 200:
    print(f"    deal lookup http {status}")
    sys.exit(1)

props = data.get("properties", {}) or {}
# HubSpot keys this "line items", with a space, not "line_items".
assoc = hs.associations(data)
ok = True


def check(label, actual, expected):
    global ok
    good = str(actual) == str(expected)
    print(f"    {'PASS' if good else 'FAIL'}  {label}: {actual!r}" + ("" if good else f" (expected {expected!r})"))
    ok = ok and good


check("nvr_wc_order_id", props.get("nvr_wc_order_id"), f"order-{order_id}")
check("dealstage", props.get("dealstage"), stage_new)

amount = float(props.get("amount") or 0)
good = abs(amount - total) < 0.01
print(f"    {'PASS' if good else 'FAIL'}  amount {amount} matches order total {total}")
ok = ok and good

contacts = (assoc.get("contacts") or {}).get("results") or []
print(f"    {'PASS' if contacts else 'FAIL'}  contact associated: {len(contacts)}")
ok = ok and bool(contacts)

items = (assoc.get("line_items") or {}).get("results") or []
good = len(items) == 2
print(f"    {'PASS' if good else 'FAIL'}  line items: {len(items)} (expected 2)")
ok = ok and good

sys.exit(0 if ok else 1)
PY

echo
echo "=== 6. complete the order, expect the stage to move ==="
PHP <<PHPEOF
<?php
add_filter( 'pre_wp_mail', '__return_true' );
\$o = wc_get_order( ${ORDER_ID} );
\$o->update_status( 'completed' );
printf( "    order status now %s\n", \$o->get_status() );
PHPEOF

STAGE=""
for i in $(seq 1 12); do
	STAGE=$(deal_stage "$ORDER_ID")
	if [ "$STAGE" = "$STAGE_WON" ]; then
		echo "    stage moved to $STAGE within ~$(( i * 15 ))s"
		break
	fi
	sleep 15
done
[ "$STAGE" = "$STAGE_WON" ] || fail "stage is '$STAGE', expected '$STAGE_WON'"

echo
echo "=== 7. segmentation wrote the customer's numbers ==="
for i in $(seq 1 12); do
	if python3 "${HERE}/hs.py" show-contact "$EMAIL" 2>/dev/null | grep -q nvr_customer_tier; then
		break
	fi
	sleep 15
done
python3 "${HERE}/hs.py" show-contact "$EMAIL" || fail "contact lookup failed"

echo
echo "=== 8. what the store computed locally, for comparison ==="
WP nvr-hs segment "$EMAIL"

echo
echo "=== 9. queue and log ended clean ==="
PHP <<'PHPEOF'
<?php
global $wpdb;
foreach ( $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$wpdb->prefix}nvr_hs_queue GROUP BY status" ) as $r ) {
	printf( "    queue %-8s %d\n", $r->status, $r->n );
}
$errors = $wpdb->get_results( "SELECT code, LEFT(message,80) m FROM {$wpdb->prefix}nvr_hs_log WHERE level='error' ORDER BY id DESC LIMIT 5" );
if ( ! $errors ) {
	echo "    errors: none\n";
}
foreach ( $errors as $r ) {
	printf( "    ERROR %-16s %s\n", $r->code, $r->m );
}
PHPEOF

echo
echo "=== PASSED ==="
echo "order_id=$ORDER_ID"
echo "Clean up with: bin/e2e/cleanup.sh $ORDER_ID"
