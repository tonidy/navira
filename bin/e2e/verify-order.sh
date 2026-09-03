#!/usr/bin/env bash
#
# Verify one order's HubSpot sync, without creating anything.
#
# Useful on its own for checking a real order, and used by the e2e run to
# continue from an order that already exists.
#
# Usage:  HS_PAK=... bin/e2e/verify-order.sh <order_id> [expected_stage]

set -uo pipefail

ORDER_ID="${1:?usage: verify-order.sh <order_id> [expected_stage]}"
EXPECT_STAGE="${2:-}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONTAINER="navira-wordpress-1"

export E2E_HERE="$HERE"

PHP() {
	docker exec -i "$CONTAINER" sh -c 'cd /var/www/html && wp --allow-root eval-file -' 2>&1
}
PHP_VAL() {
	docker exec -i "$CONTAINER" sh -c 'cd /var/www/html && wp --allow-root eval-file -' 2>/dev/null \
		| sed -n 's/.*<<<\(.*\)>>>.*/\1/p' | tr -d '[:space:]'
}
WP() { docker exec "$CONTAINER" sh -c "cd /var/www/html && wp --allow-root $*"; }

fail() { echo; echo "FAILED: $*" >&2; exit 1; }

INFO=$(PHP_VAL <<PHPEOF
<?php
\$o = wc_get_order( ${ORDER_ID} );
if ( ! \$o ) { printf( '<<<missing|||>>>' ); return; }
printf( '<<<%s|%s|%s|%s>>>', \$o->get_status(), \$o->get_total(), \$o->get_billing_email(), \$o->get_meta( '_nvr_hs_deal_id' ) );
PHPEOF
)
IFS='|' read -r STATUS TOTAL EMAIL DEAL_META <<< "$INFO"
[ "$STATUS" = "missing" ] && fail "order ${ORDER_ID} does not exist"

echo "=== order ${ORDER_ID} ==="
echo "    status=$STATUS total=$TOTAL email=$EMAIL"
echo "    _nvr_hs_deal_id meta: ${DEAL_META:-(not set)}"

echo
echo "=== deal in HubSpot ==="
python3 "${HERE}/hs.py" show-deal "order-${ORDER_ID}" || fail "no deal for order-${ORDER_ID}"

echo
echo "=== assertions ==="
python3 - "$ORDER_ID" "$TOTAL" "$EXPECT_STAGE" "$DEAL_META" <<'PY' || fail "assertions failed"
import os, sys
sys.path.insert(0, os.environ["E2E_HERE"])
import hs  # noqa: E402

order_id, total, expect_stage, deal_meta = sys.argv[1], float(sys.argv[2]), sys.argv[3], sys.argv[4]

status, data = hs.get_deal(f"order-{order_id}")
if status != 200:
    print(f"    deal lookup http {status}")
    sys.exit(1)

props = data.get("properties", {}) or {}
assoc = hs.associations(data)
ok = True


def check(label, actual, expected):
    global ok
    good = str(actual) == str(expected)
    print(f"    {'PASS' if good else 'FAIL'}  {label}: {actual!r}" + ("" if good else f" (expected {expected!r})"))
    ok = ok and good


check("nvr_wc_order_id", props.get("nvr_wc_order_id"), f"order-{order_id}")

amount = float(props.get("amount") or 0)
good = abs(amount - total) < 0.01
print(f"    {'PASS' if good else 'FAIL'}  amount {amount} matches order total {total}")
ok = ok and good

if expect_stage:
    check("dealstage", props.get("dealstage"), expect_stage)
else:
    print(f"    INFO  dealstage: {props.get('dealstage')!r}")

if deal_meta:
    check("deal id recorded on the order", deal_meta, data.get("id"))
else:
    print("    FAIL  _nvr_hs_deal_id was never written to the order")
    ok = False

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
echo "=== line item detail ==="
python3 - "$ORDER_ID" <<'PY'
import os, sys
sys.path.insert(0, os.environ["E2E_HERE"])
import hs

s, d = hs.get_deal(f"order-{sys.argv[1]}")
for r in ((hs.associations(d).get("line_items") or {}).get("results") or []):
    ls, li = hs.call("GET", f"/crm/v3/objects/line_items/{r['id']}?properties=name,quantity,price,amount")
    p = (li.get("properties") or {}) if ls == 200 else {}
    print(f"    {r['id']}  {p.get('name','?')[:38]:<38} qty={p.get('quantity')} price={p.get('price')}")
PY

echo
echo "=== contact in HubSpot ==="
python3 "${HERE}/hs.py" show-contact "$EMAIL" || echo "    (no contact)"

echo
echo "=== what the store computed ==="
WP nvr-hs segment "$EMAIL"

echo
echo "=== queue and log ==="
PHP <<'PHPEOF'
<?php
global $wpdb;
$rows = $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$wpdb->prefix}nvr_hs_queue GROUP BY status" );
if ( ! $rows ) { echo "    queue empty\n"; }
foreach ( $rows as $r ) {
	printf( "    queue %-8s %d\n", $r->status, $r->n );
}
$errors = $wpdb->get_results( "SELECT created, code, LEFT(message,90) m FROM {$wpdb->prefix}nvr_hs_log WHERE level='error' ORDER BY id DESC LIMIT 8" );
if ( ! $errors ) { echo "    errors: none\n"; }
foreach ( $errors as $r ) {
	printf( "    ERROR %s %-22s %s\n", $r->created, $r->code, $r->m );
}
PHPEOF

echo
echo "=== OK ==="
