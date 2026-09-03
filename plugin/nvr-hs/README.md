# nvr-hs (Navira HubSpot)

Deal pipeline automation, abandoned cart recovery, transaction segmentation and
a sales funnel report for the Navira reseller store, on top of HubSpot.

## What it does, and what it deliberately does not

This plugin is a companion to CRM Perks' "Integration for HubSpot and
WooCommerce", not a replacement. That plugin owns baseline contact and order
sync. nvr-hs covers the things its free tier cannot do, which its own upgrade
notice lists as premium:

| Feature | Owner |
| --- | --- |
| Contact sync from orders and forms | CRM Perks |
| Order to HubSpot sync | CRM Perks |
| Deal stage automation for high-value leads | nvr-hs |
| Order line items on deals | nvr-hs |
| Abandoned cart tracking and recovery | nvr-hs |
| Post-purchase follow-up | nvr-hs |
| Transaction-based segmentation | nvr-hs |
| Sales and conversion funnel report | nvr-hs |
| Registered-but-never-ordered accounts | nvr-hs |

The one overlap is contact writes, and it is safe: everything here upserts by
email through `PATCH /crm/v3/objects/contacts/{email}?idProperty=email`, so it
patches whatever record already exists rather than creating a second one.

## Requirements

- WordPress 6.6+, PHP 8.1+, WooCommerce 9.0+ (tested against WP 7.1, PHP 8.5, Woo 11.0)
- A HubSpot **private app** token (`pat-na2-...`). A developer API key will not
  work: it authenticates but carries no CRM scopes.

Scopes needed: contacts, deals, companies, line items and products (read and
write), lists read/write, contact and deal schemas, owners read. The
`automation` scope is **not** required, see below.

## Setup

1. Deploy (see `bin/deploy-plugin.sh` in the stack repo) and activate:
   ```bash
   wp plugin activate nvr-hs
   ```
2. Give it the token. Either paste it at WooCommerce → Navira HubSpot, where it
   is stored AES-256 encrypted under a key derived from the site's `AUTH_KEY`
   and `AUTH_SALT`, or keep it out of the database entirely:
   ```php
   // wp-config.php
   define( 'NVR_HS_TOKEN', 'pat-na2-...' );
   ```
   An `NVR_HS_TOKEN` environment variable works too, which pairs with the fnox
   secret already in the stack. Constant beats environment beats database.
3. Verify and create the custom properties:
   ```bash
   wp nvr-hs test
   wp nvr-hs properties
   ```

Creating the properties is not cosmetic. A HubSpot workflow can only be
triggered by a property that exists, so the abandoned cart automation cannot be
built in the portal until `nvr_cart_abandoned_at` is there.

## How the email automation works

nvr-hs never sends marketing email through HubSpot's API. It writes contact
properties and lets the portal decide, which is why no `automation` scope is
needed. Two modes, set on the settings screen:

- **workflow** (default). The plugin writes `nvr_cart_abandoned_at`,
  `nvr_cart_value` and `nvr_cart_recovery_url`, and a workflow you build in
  HubSpot, enrolled on "`nvr_cart_abandoned_at` is known", sends the email.
  Needs Marketing Hub Professional or above.
- **woocommerce**. The plugin sends the email itself via `wp_mail`. Use this on
  a free or Starter portal. The same properties are still written, so switching
  to workflow mode later needs no data backfill.

If a customer buys after being marked abandoned, the cart state is cleared in
HubSpot so a live workflow cannot email someone who already paid.

## Custom properties

Contacts, all under the "Navira store" group:

| Property | Purpose |
| --- | --- |
| `nvr_wp_user_id` | Links back to the WordPress account |
| `nvr_ltv` | Lifetime value across paid orders |
| `nvr_order_count`, `nvr_aov` | Order count and average order value |
| `nvr_first_order_at`, `nvr_last_order_at` | Recency and tenure |
| `nvr_last_order_number` | Most recent order, for support lookups |
| `nvr_customer_tier` | prospect / new / repeat / vip / at_risk / churned |
| `nvr_favorite_category` | Most purchased category, weighted by revenue |
| `nvr_cart_value`, `nvr_cart_item_count` | Open cart size |
| `nvr_cart_abandoned_at` | Workflow trigger |
| `nvr_cart_recovery_url` | One-click cart rebuild link |

Deals get one, `nvr_wc_order_id`, declared with `hasUniqueValue`. That is what
makes order-to-deal sync idempotent: a retried job upserts the same deal
instead of creating a duplicate.

## Tiers

Recency is evaluated before value, on purpose. A reseller who spent a fortune
and then went quiet for four months is a retention problem, and calling them
VIP hides the thing someone should act on.

```
no paid orders                        -> prospect
idle > 2x churn_risk_days             -> churned
idle > churn_risk_days                -> at_risk
ltv >= tier_vip_ltv                   -> vip
orders >= tier_repeat_orders           -> repeat
otherwise                              -> new
```

Only `processing` and `completed` count as revenue, so a pending or cancelled
order never inflates lifetime value. A refund requeues the customer.

## Nothing blocks a page render

Every HubSpot write goes through a durable queue table drained by cron. This
store runs six php-fpm workers behind nginx, so one slow outbound call on the
thank-you page is enough to queue real customers behind it. Foreground requests
(only the settings-page connection test) get a 5 second timeout; queue workers
get 15 seconds plus retry with backoff and `Retry-After` handling.

Jobs are deduplicated, so an order that moves pending → processing → completed
in one request queues one sync, not three. A job that fails five times is
parked as `failed` and shown on the report rather than retried forever.

## WP-CLI

```bash
wp nvr-hs test                  # verify the token, print the portal
wp nvr-hs properties            # create the nvr_* properties (idempotent)
wp nvr-hs queue                 # drain the queue once, in the foreground
wp nvr-hs sweep                 # mark quiet carts abandoned now
wp nvr-hs segment a@b.com       # show computed metrics without writing
```

## Screens

- WooCommerce → **Navira HubSpot**: connection, thresholds, stage mapping, tiers.
- WooCommerce → **Navira report**: revenue, funnel with per-step drop-off, cart
  recovery rate, top products, and sync health with recent errors.

The report reads from WooCommerce, not HubSpot, because the store is the system
of record for what was actually sold. Asking HubSpot would make the numbers
disagree with Woo whenever a sync is behind.

## Uninstall

Deactivating changes nothing. Deleting drops the three tables and the options.
Order meta recording which deal an order became is left in place, because it
stays true regardless and removing it would make a reinstall create duplicates.
