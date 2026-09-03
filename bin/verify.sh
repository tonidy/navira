#!/usr/bin/env bash
set -uo pipefail

# NVR_HS_TOKEN is exported by the caller from fnox; `docker exec -e` with no
# value makes docker inherit it, so the token never appears in a command line
# or in ps output.
WP()   { docker exec -e NVR_HS_TOKEN navira-wordpress-1 sh -c "cd /var/www/html && wp --allow-root $*"; }
EVAL() { docker exec navira-wordpress-1 sh -c "cd /var/www/html && wp --allow-root eval '$1'"; }

echo "=== 1. connection ==="
WP nvr-hs test

echo
echo "=== 2. custom properties (idempotent, should be all existing now) ==="
WP nvr-hs properties

echo
echo "=== 3. tables ==="
# No mysql client in the container, so go through \$wpdb rather than wp db query.
EVAL 'global $wpdb; foreach ($wpdb->get_col("SHOW TABLES LIKE \"{$wpdb->prefix}nvr_hs%\"") as $t) { printf("    %-24s %d rows\n", $t, (int) $wpdb->get_var("SELECT COUNT(*) FROM $t")); }'

echo
echo "=== 4. carts schema, nullability of the columns that matter ==="
EVAL 'global $wpdb; foreach ($wpdb->get_results("DESCRIBE {$wpdb->prefix}nvr_hs_carts") as $c) { if (in_array($c->Field, ["email","abandoned_at","notified_at","recovered_order_id","session_key","recovery_token"], true)) { printf("    %-20s %-14s null=%s key=%s\n", $c->Field, $c->Type, $c->Null, $c->Key); } }'

echo
echo "=== 5. cron events (all four expected) ==="
docker exec navira-wordpress-1 sh -c 'cd /var/www/html && wp --allow-root cron event list --fields=hook,next_run_relative,recurrence --format=table' | grep -E 'nvr_hs|hook'

echo
echo "=== 6. NULL handling: the bug that would have bitten ==="
# Prove an empty email lands as SQL NULL, not '', so the sweep guard holds.
EVAL 'global $wpdb; $t = $wpdb->prefix . "nvr_hs_carts";
$wpdb->query($wpdb->prepare("INSERT INTO $t (session_key, recovery_token, user_id, email, cart_total, currency, item_count, contents, created, updated, abandoned_at) VALUES (%s, %s, 0, NULLIF(%s, \"\"), %f, %s, 1, %s, %s, %s, NULL)", "nvr-probe", "nvr-probe-token", "", 12.5, "USD", "[]", gmdate("Y-m-d H:i:s"), gmdate("Y-m-d H:i:s")));
$row = $wpdb->get_row($wpdb->prepare("SELECT email, abandoned_at FROM $t WHERE session_key = %s", "nvr-probe"));
printf("    email is %s, abandoned_at is %s\n", null === $row->email ? "NULL (correct)" : "\"" . $row->email . "\" (WRONG)", null === $row->abandoned_at ? "NULL (correct)" : "set (WRONG)");
$wpdb->query($wpdb->prepare("DELETE FROM $t WHERE session_key = %s", "nvr-probe"));
printf("    probe row removed: %d remaining\n", (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE session_key = %s", "nvr-probe")));'

echo
echo "=== 7. queue drains, sweep runs ==="
WP nvr-hs queue
WP nvr-hs sweep

echo
echo "=== 8. admin screens render without fatals ==="
for slug in nvr-hs-settings nvr-hs-dashboard; do
  out=$(docker exec navira-wordpress-1 sh -c "cd /var/www/html && wp --allow-root eval '
    \$_GET[\"page\"] = \"$slug\";
    wp_set_current_user(1);
    require_once ABSPATH . \"wp-admin/includes/plugin.php\";
    ob_start();
    do_action(\"admin_menu\");
    ob_end_clean();
    echo \"rendered\";
  '" 2>&1)
  case "$out" in
    *rendered*) echo "    $slug: menu registered, no fatal" ;;
    *)          echo "    $slug: PROBLEM -> $out" ;;
  esac
done

echo
echo "=== 9. site health ==="
for u in / /wp-login.php "/?post_type=product"; do
  curl -s -o /dev/null -w "    %{http_code}  ttfb=%{time_starttransfer}s  $u\n" --max-time 30 "https://navira.run.biz.id$u"
done

echo
echo "=== 10. errors logged during all of the above ==="
EVAL 'global $wpdb; $rows = $wpdb->get_results("SELECT created, code, LEFT(message,80) AS m FROM {$wpdb->prefix}nvr_hs_log WHERE level = \"error\" ORDER BY id DESC LIMIT 10");
if (!$rows) { echo "    none\n"; } else { foreach ($rows as $r) { printf("    %s  %-18s %s\n", $r->created, $r->code, $r->m); } }'
