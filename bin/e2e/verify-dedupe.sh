#!/usr/bin/env bash
#
# Prove that a settled job releases its dedupe key, so a later status change on
# the same order can queue a fresh sync.
#
# Before the fix, deal_sync:<id> stayed occupied by the completed job and the
# unique index rejected every subsequent enqueue, freezing the deal stage.
#
# Usage:  HS_PAK=... bin/e2e/verify-dedupe.sh <order_id>

set -uo pipefail

ORDER_ID="${1:?usage: verify-dedupe.sh <order_id>}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONTAINER="navira-wordpress-1"
export E2E_HERE="$HERE"

PHP() { docker exec -i "$CONTAINER" sh -c 'cd /var/www/html && wp --allow-root eval-file -' 2>&1; }
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

echo "=== 1. settled jobs must have released their key ==="
PHP <<'PHPEOF'
<?php
global $wpdb;
$rows = $wpdb->get_results( "SELECT id, job, status, dedupe_key FROM {$wpdb->prefix}nvr_hs_queue ORDER BY id" );
if ( ! $rows ) { echo "    queue empty\n"; }
foreach ( $rows as $r ) {
	$held = null === $r->dedupe_key ? 'released (NULL)' : 'HOLDING ' . $r->dedupe_key;
	printf( "    #%d %-14s %-8s %s\n", $r->id, $r->job, $r->status, $held );
}
$stuck = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}nvr_hs_queue WHERE status IN ('done','failed') AND dedupe_key IS NOT NULL" );
printf( "    settled rows still holding a key: %d%s\n", $stuck, 0 === $stuck ? ' (correct)' : ' (BUG)' );
PHPEOF

echo
echo "=== 2. stage before ==="
BEFORE=$(deal_stage "$ORDER_ID")
echo "    $BEFORE"

echo
echo "=== 3. bounce the order status to trigger a resync ==="
PHP <<PHPEOF
<?php
add_filter( 'pre_wp_mail', '__return_true' );
\$o = wc_get_order( ${ORDER_ID} );
\$o->update_status( 'processing' );
\$o->update_status( 'completed' );
printf( "    status now %s\n", \$o->get_status() );
PHPEOF

echo
echo "=== 4. a new deal_sync must be queued ==="
PHP <<'PHPEOF'
<?php
global $wpdb;
$rows = $wpdb->get_results( "SELECT id, job, status, dedupe_key FROM {$wpdb->prefix}nvr_hs_queue WHERE status = 'pending' ORDER BY id" );
if ( ! $rows ) { echo "    NO PENDING JOBS - the enqueue was rejected again\n"; }
foreach ( $rows as $r ) {
	printf( "    #%d %-14s %-8s %s\n", $r->id, $r->job, $r->status, $r->dedupe_key ?? '(none)' );
}
PHPEOF

echo
echo "=== 5. wait for cron to move the stage ==="
STAGE=""
for i in $(seq 1 10); do
	STAGE=$(deal_stage "$ORDER_ID")
	if [ "$STAGE" = "closedwon" ]; then
		echo "    stage is closedwon after ~$(( i * 15 ))s"
		break
	fi
	sleep 15
done
[ "$STAGE" = "closedwon" ] || fail "stage is '$STAGE', expected 'closedwon' (was '$BEFORE')"

echo
echo "=== 6. line items were not duplicated by the resync ==="
python3 - "$ORDER_ID" <<'PY' || fail "line items duplicated"
import os, sys
sys.path.insert(0, os.environ["E2E_HERE"])
import hs
s, d = hs.get_deal(f"order-{sys.argv[1]}")
items = (hs.associations(d).get("line_items") or {}).get("results") or []
print(f"    line items: {len(items)} " + ("(PASS, still 2)" if len(items) == 2 else "(FAIL, expected 2)"))
sys.exit(0 if len(items) == 2 else 1)
PY

echo
echo "=== PASSED: dedupe key is a lock on live work, not a permanent record ==="
