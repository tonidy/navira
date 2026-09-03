#!/usr/bin/env bash
set -uo pipefail

docker exec -i navira-wordpress-1 sh -c 'cd /var/www/html && wp --allow-root eval-file -' <<'PHPEOF'
<?php
global $wpdb;

echo "=== queue ===\n";
foreach ( $wpdb->get_results( "SELECT id, job, status, attempts, dedupe_key, available_at, last_error FROM {$wpdb->prefix}nvr_hs_queue ORDER BY id" ) as $r ) {
	printf( "  #%d %-14s %-8s attempts=%d dedupe=%s\n", $r->id, $r->job, $r->status, $r->attempts, $r->dedupe_key );
	if ( ! empty( $r->last_error ) ) {
		printf( "      last_error: %s\n", $r->last_error );
	}
}

echo "\n=== log, newest first ===\n";
foreach ( $wpdb->get_results( "SELECT created, level, code, message, context FROM {$wpdb->prefix}nvr_hs_log ORDER BY id DESC LIMIT 20" ) as $r ) {
	printf( "  %s [%s] %s\n      %s\n", $r->created, $r->level, $r->code, $r->message );
	if ( ! empty( $r->context ) ) {
		printf( "      ctx: %s\n", substr( (string) $r->context, 0, 200 ) );
	}
}
PHPEOF
