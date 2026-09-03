"""What nvr-hs has actually put into the portal. Reads HS_PAK, never prints it."""

import json
import os
import urllib.error
import urllib.request

TOKEN = os.environ["HS_PAK"]
BASE = "https://api.hubapi.com"


def call(method, path, body=None):
    req = urllib.request.Request(
        BASE + path,
        method=method,
        data=json.dumps(body).encode() if body is not None else None,
        headers={"Authorization": "Bearer " + TOKEN, "Content-Type": "application/json"},
    )
    try:
        with urllib.request.urlopen(req, timeout=25) as r:
            raw = r.read()
            return r.status, (json.loads(raw) if raw else {})
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8", "replace")
        try:
            return e.status, json.loads(raw)
        except ValueError:
            return e.status, {"raw": raw[:200]}


print("=== contacts carrying a computed tier ===")
s, d = call(
    "POST",
    "/crm/v3/objects/contacts/search",
    {
        "filterGroups": [{"filters": [{"propertyName": "nvr_customer_tier", "operator": "HAS_PROPERTY"}]}],
        "properties": ["email", "nvr_customer_tier", "nvr_ltv", "nvr_order_count", "nvr_aov", "nvr_favorite_category", "nvr_last_order_number"],
        "limit": 100,
    },
)

tiers = {}
if s == 200:
    print(f"    total: {d.get('total')}")
    rows = sorted(
        d.get("results", []),
        key=lambda r: float((r.get("properties") or {}).get("nvr_ltv") or 0),
        reverse=True,
    )
    for r in rows:
        p = r.get("properties") or {}
        t = p.get("nvr_customer_tier") or "-"
        tiers[t] = tiers.get(t, 0) + 1
        ltv = float(p.get("nvr_ltv") or 0)
        print(
            "      {:<32} {:<9} orders={:<3} ltv={:>14,.0f}  {}".format(
                (p.get("email") or "")[:32],
                t,
                p.get("nvr_order_count") or 0,
                ltv,
                p.get("nvr_favorite_category") or "",
            )
        )
    print("\n    tier totals: " + ", ".join(f"{k}={v}" for k, v in sorted(tiers.items())))
else:
    print(f"    search http={s} {str(d)[:200]}")

print()
print("=== deals by stage ===")
s, d = call(
    "POST",
    "/crm/v3/objects/deals/search",
    {
        "filterGroups": [{"filters": [{"propertyName": "nvr_wc_order_id", "operator": "HAS_PROPERTY"}]}],
        "properties": ["dealname", "amount", "dealstage", "nvr_wc_order_id"],
        "limit": 100,
    },
)

if s == 200:
    stages = {}
    total = 0.0
    for r in d.get("results", []):
        p = r.get("properties") or {}
        st = p.get("dealstage") or "-"
        stages[st] = stages.get(st, 0) + 1
        total += float(p.get("amount") or 0)
    print(f"    total deals: {d.get('total')}   combined amount: {total:,.0f}")
    label = {
        "4224208578": "Prospecting",
        "4224208579": "Initial Contact",
        "4224208580": "Proposal",
        "closedwon": "Closed Won",
        "closedlost": "Closed Lost",
    }
    for st, n in sorted(stages.items(), key=lambda kv: -kv[1]):
        print(f"      {label.get(st, st):<18} {n}")
else:
    print(f"    search http={s} {str(d)[:200]}")

print()
print("=== contacts with open/abandoned cart state ===")
s, d = call(
    "POST",
    "/crm/v3/objects/contacts/search",
    {
        "filterGroups": [{"filters": [{"propertyName": "nvr_cart_abandoned_at", "operator": "HAS_PROPERTY"}]}],
        "properties": ["email", "nvr_cart_value", "nvr_cart_item_count", "nvr_cart_abandoned_at"],
        "limit": 50,
    },
)
if s == 200:
    print(f"    total: {d.get('total')}")
    for r in d.get("results", [])[:10]:
        p = r.get("properties") or {}
        print(
            "      {:<32} cart={:>12,.0f} items={}".format(
                (p.get("email") or "")[:32],
                float(p.get("nvr_cart_value") or 0),
                p.get("nvr_cart_item_count") or 0,
            )
        )
else:
    print(f"    search http={s} {str(d)[:200]}")
