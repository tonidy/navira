#!/usr/bin/env bash
#
# Remove everything the nvr-hs end-to-end test creates.
#
# Written before the test and safe to run at any point, including when the test
# died half way. Every target is addressed by a value the test owns: the email
# is a reserved @example.test address, and the deal is found by its
# nvr_wc_order_id, so nothing here can match real store or portal data.
#
# PHP is passed on stdin to `wp eval-file -` and values arrive through the
# environment. Interpolating them into a quoted `wp eval '...'` string breaks
# the moment a value contains @ or %, which an email and a LIKE pattern both do.
#
# Usage:  HS_PAK=... bin/e2e/cleanup.sh [order_id]

set -uo pipefail

EMAIL="nvr-e2e@example.com"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONTAINER="navira-wordpress-1"
ORDER_ID="${1:-}"

# PHP arrives on stdin, so nothing needs escaping.
PHP() {
	docker exec -i -e NVR_E2E_EMAIL="$EMAIL" "$CONTAINER" \
		sh -c 'cd /var/www/html && wp --allow-root eval-file -' 2>&1
}

echo "=== cleanup: HubSpot ==="

if [ -z "$ORDER_ID" ]; then
	ORDER_ID=$(PHP <<'PHPEOF' | tr -d '[:space:]'
<?php
$ids = wc_get_orders( array(
	'billing_email' => getenv( 'NVR_E2E_EMAIL' ),
	'limit'         => -1,
	'status'        => 'any',
	'return'        => 'ids',
) );
echo implode( ',', is_array( $ids ) ? $ids : array() );
PHPEOF
)
fi

if [ -n "$ORDER_ID" ]; then
	IFS=',' read -ra IDS <<< "$ORDER_ID"
	for id in "${IDS[@]}"; do
		[ -n "$id" ] || continue
		python3 "${HERE}/hs.py" delete-deal "order-${id}"
	done
else
	echo "    no test orders found, no deals to look up"
fi

python3 "${HERE}/hs.py" delete-contact "$EMAIL"

echo
echo "=== cleanup: WooCommerce ==="
PHP <<'PHPEOF'
<?php
$email = strtolower( (string) getenv( 'NVR_E2E_EMAIL' ) );
$ids   = wc_get_orders( array(
	'billing_email' => $email,
	'limit'         => -1,
	'status'        => 'any',
	'return'        => 'ids',
) );
$ids = is_array( $ids ) ? $ids : array();

if ( ! $ids ) {
	echo "    no test orders\n";
}

foreach ( $ids as $id ) {
	$order = wc_get_order( $id );
	if ( ! $order ) {
		continue;
	}
	// Guard: never delete an order that is not the test one.
	if ( strtolower( $order->get_billing_email() ) !== $email ) {
		printf( "    REFUSING order %d, email is %s\n", $id, $order->get_billing_email() );
		continue;
	}
	$order->delete( true );
	printf( "    deleted order %d\n", $id );
}
PHPEOF

echo
echo "=== cleanup: plugin state ==="
docker exec -i \
	-e NVR_E2E_EMAIL="$EMAIL" -e NVR_E2E_ORDERS="$ORDER_ID" "$CONTAINER" \
	sh -c 'cd /var/www/html && wp --allow-root eval-file -' 2>&1 <<'PHPEOF'
<?php
global $wpdb;
$email  = (string) getenv( 'NVR_E2E_EMAIL' );
$orders = array_filter( array_map( 'intval', explode( ',', (string) getenv( 'NVR_E2E_ORDERS' ) ) ) );

// Two shapes of job to clear. customer_sync and post_purchase carry the email
// in the payload, but deal_sync carries only {"order_id":N}, so matching on
// the email alone leaves those rows behind to retry against a deleted order.
$removed = (int) $wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->prefix}nvr_hs_queue WHERE payload LIKE %s",
		'%' . $wpdb->esc_like( $email ) . '%'
	)
);

foreach ( $orders as $id ) {
	$removed += (int) $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}nvr_hs_queue WHERE dedupe_key IN (%s, %s) OR payload = %s",
			'deal_sync:' . $id,
			'post_purchase:purchase-' . $id,
			wp_json_encode( array( 'order_id' => $id ) )
		)
	);
}

printf( "    queue rows removed: %d\n", $removed );

$c = $wpdb->query(
	$wpdb->prepare( "DELETE FROM {$wpdb->prefix}nvr_hs_carts WHERE email = %s", $email )
);
printf( "    cart rows removed: %d\n", (int) $c );
PHPEOF

echo
echo "=== verify gone ==="
python3 "${HERE}/hs.py" show-contact "$EMAIL" || true
PHP <<'PHPEOF'
<?php
$ids = wc_get_orders( array(
	'billing_email' => getenv( 'NVR_E2E_EMAIL' ),
	'limit'         => -1,
	'status'        => 'any',
	'return'        => 'ids',
) );
printf( "    remaining test orders: %d\n", count( is_array( $ids ) ? $ids : array() ) );
PHPEOF
