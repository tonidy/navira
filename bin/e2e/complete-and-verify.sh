#!/usr/bin/env bash
#
# Move an order to completed and verify the stage change and segmentation
# land in HubSpot, driven by the real system cron.
#
# Usage:  HS_PAK=... bin/e2e/complete-and-verify.sh <order_id>

set -uo pipefail

ORDER_ID="${1:?usage: complete-and-verify.sh <order_id>}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONTAINER="navira-wordpress-1"
export E2E_HERE="$HERE"

PHP() { docker exec -i "$CONTAINER" sh -c 'cd /var/www/html && wp --allow-root eval-file -' 2>&1; }
PHP_VAL() {
	docker exec -i "$CONTAINER" sh -c 'cd /var/www/html && wp --allow-root eval-file -' 2>/dev/null \
		| sed -n 's/.*<<<\(.*\)>>>.*/\1/p' | tr -d '[:space:]'
}
WP() { docker exec "$CONTAINER" sh -c "cd /var/www/html && wp --allow-root $*"; }
fail() { echo; echo "FAILED: $*" >&2; exit 1; }

deal_stage() {
	python3 - "$1" <<'PY'
import os, sys
sys.path.insert(0, os.environ["E2E_HERE"])
import hs
s, d = hs.get_deal(f"order-{sys.argv[1]}")
print((d.get("properties", {}) or {}).get("dealstage", "") if s == 200 else "")
PY
}

EMAIL=$(PHP_VAL <<PHPEOF
<?php
printf( '<<<%s>>>', wc_get_order( ${ORDER_ID} )->get_billing_email() );
PHPEOF
)
echo "=== order ${ORDER_ID} (${EMAIL}) ==="

echo
echo "=== 1. mark completed ==="
PHP <<PHPEOF
<?php
add_filter( 'pre_wp_mail', '__return_true' );
\$o = wc_get_order( ${ORDER_ID} );
\$o->update_status( 'completed' );
printf( "    status now %s\n", \$o->get_status() );
PHPEOF

echo
echo "=== 2. jobs queued by the transition ==="
PHP <<'PHPEOF'
<?php
global $wpdb;
foreach ( $wpdb->get_results( "SELECT id, job, status, dedupe_key, available_at FROM {$wpdb->prefix}nvr_hs_queue ORDER BY id" ) as $r ) {
	printf( "    #%d %-14s %-8s dedupe=%-28s available=%s\n", $r->id, $r->job, $r->status, $r->dedupe_key, $r->available_at );
}
PHPEOF

echo
echo "=== 3. wait for cron to move the stage to closedwon ==="
STAGE=""
for i in $(seq 1 12); do
	STAGE=$(deal_stage "$ORDER_ID")
	if [ "$STAGE" = "closedwon" ]; then
		echo "    stage is closedwon after ~$(( i * 15 ))s"
		break
	fi
	sleep 15
done
[ "$STAGE" = "closedwon" ] || fail "stage is '$STAGE', expected 'closedwon'"

echo
echo "=== 4. wait for segmentation to write the contact ==="
for i in $(seq 1 12); do
	if python3 "${HERE}/hs.py" show-contact "$EMAIL" 2>/dev/null | grep -q nvr_customer_tier; then
		echo "    segmentation landed after ~$(( i * 15 ))s"
		break
	fi
	sleep 15
done

echo
echo "=== 5. the contact, as HubSpot has it ==="
python3 "${HERE}/hs.py" show-contact "$EMAIL" || fail "contact lookup failed"

echo
echo "=== 6. store-side numbers, for comparison ==="
WP nvr-hs segment "$EMAIL"

echo
echo "=== 7. queue and errors ==="
PHP <<'PHPEOF'
<?php
global $wpdb;
foreach ( $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$wpdb->prefix}nvr_hs_queue GROUP BY status" ) as $r ) {
	printf( "    queue %-8s %d\n", $r->status, $r->n );
}
$errors = $wpdb->get_results( "SELECT created, code, LEFT(message,90) m FROM {$wpdb->prefix}nvr_hs_log WHERE level='error' ORDER BY id DESC LIMIT 8" );
if ( ! $errors ) { echo "    errors: none\n"; }
foreach ( $errors as $r ) {
	printf( "    ERROR %s %-22s %s\n", $r->created, $r->code, $r->m );
}
PHPEOF

echo
echo "=== 8. line items were not duplicated by the second sync ==="
python3 - "$ORDER_ID" <<'PY'
import os, sys
sys.path.insert(0, os.environ["E2E_HERE"])
import hs
s, d = hs.get_deal(f"order-{sys.argv[1]}")
items = (hs.associations(d).get("line_items") or {}).get("results") or []
print(f"    line items: {len(items)} " + ("(PASS, still 2)" if len(items) == 2 else "(FAIL, expected 2)"))
sys.exit(0 if len(items) == 2 else 1)
PY

echo
echo "=== PASSED ==="
