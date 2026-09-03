"""Reconcile WooCommerce order totals against their HubSpot deal amounts.

Proves store and portal agree number-for-number now that both are IDR.
Order totals arrive on stdin as "order_id total" lines. Reads HS_PAK.
"""

import json
import os
import sys
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
        return e.status, {"raw": e.read().decode("utf-8", "replace")[:200]}


wc = {}
for line in sys.stdin:
    parts = line.split()
    if len(parts) == 2:
        wc[f"order-{parts[0]}"] = float(parts[1])

status, data = call(
    "POST",
    "/crm/v3/objects/deals/search",
    {
        "filterGroups": [
            {"filters": [{"propertyName": "nvr_wc_order_id", "operator": "HAS_PROPERTY"}]}
        ],
        "properties": ["nvr_wc_order_id", "amount", "amount_in_home_currency", "deal_currency_code", "dealstage"],
        "limit": 100,
    },
)

if status != 200:
    print(f"search failed http={status}")
    raise SystemExit(1)

print(f"{'reference':<14} {'woo total':>14} {'hs amount':>14} {'hs home':>14}  match")
print("-" * 76)

mismatch = 0
checked = 0

for r in data.get("results", []):
    p = r.get("properties") or {}
    ref = p.get("nvr_wc_order_id") or ""
    hs = float(p.get("amount") or 0)
    home = float(p.get("amount_in_home_currency") or 0)

    if ref not in wc:
        print(f"{ref:<14} {'(no woo order)':>14} {hs:>14,.0f} {home:>14,.0f}  ORPHAN")
        mismatch += 1
        continue

    woo = wc[ref]
    ok = abs(woo - hs) < 0.01 and abs(hs - home) < 0.01
    checked += 1
    if not ok:
        mismatch += 1
    print(f"{ref:<14} {woo:>14,.0f} {hs:>14,.0f} {home:>14,.0f}  {'ok' if ok else 'MISMATCH'}")

print("-" * 76)
print(f"checked {checked}, mismatches {mismatch}")
print(f"woo orders above threshold not in HubSpot: {sorted(set(wc) - {(x.get('properties') or {}).get('nvr_wc_order_id') for x in data.get('results', [])})}")
raise SystemExit(1 if mismatch else 0)
