"""Find out what portal 247211579 will let us do about currency.

Reads HS_PAK. Never prints it.
"""

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


print("=== 1. what the account reports ===")
s, d = call("GET", "/account-info/v3/details")
for k in ("portalId", "companyCurrency", "dataHostingLocation", "accountType", "timeZone"):
    print(f"    {k:<22} {d.get(k)}")

print()
print("=== 2. candidate currency endpoints ===")
for path in (
    "/settings/v3/currencies",
    "/settings/v3/currencies/exchange-rates",
    "/crm/v3/properties/deals/deal_currency_code",
):
    s, d = call("GET", path)
    msg = d.get("message", "")
    print(f"    {path:<46} http={s}")
    if s == 200 and "options" in d:
        opts = [o.get("value") for o in (d.get("options") or [])]
        print(f"        currencies enabled in the portal: {opts or '(none listed)'}")
    elif s == 200:
        body = json.dumps(d)
        print(f"        {body[:220]}")
    elif msg:
        print(f"        {msg[:150]}")

print()
print("=== 3. the deals that just landed ===")
s, d = call(
    "POST",
    "/crm/v3/objects/deals/search",
    {
        "filterGroups": [
            {"filters": [{"propertyName": "nvr_wc_order_id", "operator": "HAS_PROPERTY"}]}
        ],
        "properties": ["dealname", "amount", "amount_in_home_currency", "deal_currency_code", "dealstage", "nvr_wc_order_id"],
        "limit": 6,
        "sorts": [{"propertyName": "amount", "direction": "DESCENDING"}],
    },
)
if s == 200:
    print(f"    total deals with nvr_wc_order_id: {d.get('total')}")
    for r in d.get("results", []):
        p = r.get("properties", {}) or {}
        print(
            "      {:<14} amount={:<14} home={:<14} ccy={:<5} {}".format(
                p.get("nvr_wc_order_id", ""),
                p.get("amount") or "-",
                p.get("amount_in_home_currency") or "-",
                p.get("deal_currency_code") or "-",
                (p.get("dealname") or "")[:34],
            )
        )
else:
    print(f"    search http={s} {str(d)[:200]}")

print()
print("=== 4. will HubSpot accept deal_currency_code=IDR? ===")
# Probe on one real deal, then put it back exactly as found.
s, d = call(
    "POST",
    "/crm/v3/objects/deals/search",
    {
        "filterGroups": [
            {"filters": [{"propertyName": "nvr_wc_order_id", "operator": "HAS_PROPERTY"}]}
        ],
        "properties": ["deal_currency_code", "nvr_wc_order_id"],
        "limit": 1,
    },
)

if s != 200 or not d.get("results"):
    print("    no deal to probe with")
else:
    deal = d["results"][0]
    deal_id = deal["id"]
    before = (deal.get("properties") or {}).get("deal_currency_code")
    print(f"    probing deal {deal_id} (currently {before or 'unset'})")

    ps, pd = call(
        "PATCH",
        f"/crm/v3/objects/deals/{deal_id}",
        {"properties": {"deal_currency_code": "IDR"}},
    )

    if ps == 200:
        print("    ACCEPTED: IDR is an enabled portal currency")
        # Put it back so the probe leaves nothing behind.
        rs, _ = call(
            "PATCH",
            f"/crm/v3/objects/deals/{deal_id}",
            {"properties": {"deal_currency_code": before or ""}},
        )
        print(f"    reverted: http={rs}")
    else:
        print(f"    REJECTED http={ps}")
        print(f"      {str(pd.get('message', pd))[:260]}")
