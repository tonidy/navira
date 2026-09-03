"""HubSpot helper for the nvr-hs end-to-end test.

Reads the token from HS_PAK. Never prints it. Used by both test.sh and
cleanup.sh so the two agree on how objects are located.

Usage:
    hs.py show-deal <reference>
    hs.py show-contact <email>
    hs.py delete-deal <reference>
    hs.py delete-contact <email>
"""

import json
import os
import sys
import urllib.error
import urllib.request

TOKEN = os.environ["HS_PAK"]
BASE = "https://api.hubapi.com"

DEAL_PROPS = "dealname,amount,pipeline,dealstage,closedate,nvr_wc_order_id,hubspot_owner_id"
CONTACT_PROPS = (
    "email,firstname,lastname,nvr_ltv,nvr_order_count,nvr_aov,nvr_customer_tier,"
    "nvr_favorite_category,nvr_last_order_number,nvr_last_order_at,nvr_wp_user_id,"
    "nvr_cart_value,nvr_cart_abandoned_at"
)


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


def get_deal(ref):
    return call("GET", f"/crm/v3/objects/deals/{ref}?idProperty=nvr_wc_order_id&properties={DEAL_PROPS}&associations=contacts,line_items")


def get_contact(email):
    return call("GET", f"/crm/v3/objects/contacts/{email}?idProperty=email&properties={CONTACT_PROPS}")


def associations(data):
    """Association groups, keyed with underscores.

    HubSpot labels these by object type with a space in it: the line item
    collection comes back as "line items", not "line_items". Reading the
    underscored name silently finds nothing, which looks exactly like a
    missing association.
    """
    raw = data.get("associations") or {}
    return {k.replace(" ", "_"): v for k, v in raw.items()}


def show(label, status, data, keys):
    if status == 404:
        print(f"    {label}: NOT FOUND")
        return None
    if status != 200:
        print(f"    {label}: http {status} {str(data)[:120]}")
        return None

    props = data.get("properties", {}) or {}
    print(f"    {label}: id={data.get('id')}")
    for k in keys:
        v = props.get(k)
        if v not in (None, ""):
            print(f"      {k:<24} {v}")

    assoc = data.get("associations", {}) or {}
    for name, block in assoc.items():
        ids = [r.get("id") for r in (block.get("results") or [])]
        print(f"      assoc:{name:<17} {len(ids)} -> {', '.join(map(str, ids[:5]))}")
    return data


def main():
    if len(sys.argv) < 3:
        print(__doc__)
        return 2

    cmd, arg = sys.argv[1], sys.argv[2]

    if cmd == "show-deal":
        status, data = get_deal(arg)
        show("deal", status, data, ["nvr_wc_order_id", "dealname", "amount", "pipeline", "dealstage", "hubspot_owner_id"])
        return 0 if status == 200 else 1

    if cmd == "show-contact":
        status, data = get_contact(arg)
        show("contact", status, data, [
            "email", "firstname", "lastname", "nvr_customer_tier", "nvr_ltv",
            "nvr_order_count", "nvr_aov", "nvr_last_order_number", "nvr_favorite_category",
            "nvr_wp_user_id", "nvr_cart_value",
        ])
        return 0 if status == 200 else 1

    if cmd == "delete-deal":
        status, data = get_deal(arg)
        if status == 404:
            print(f"    deal {arg}: already gone")
            return 0
        if status != 200:
            print(f"    deal {arg}: lookup http {status}")
            return 1

        deal_id = data["id"]

        # Line items are separate objects; deleting the deal orphans them.
        for r in ((associations(data).get("line_items") or {}).get("results") or []):
            ls, _ = call("DELETE", f"/crm/v3/objects/line_items/{r['id']}")
            print(f"    line_item {r['id']}: {'deleted' if ls in (204, 200) else 'http ' + str(ls)}")

        ds, _ = call("DELETE", f"/crm/v3/objects/deals/{deal_id}")
        print(f"    deal {deal_id} ({arg}): {'deleted' if ds in (204, 200) else 'http ' + str(ds)}")
        return 0

    if cmd == "delete-contact":
        status, data = get_contact(arg)
        if status == 404:
            print(f"    contact {arg}: already gone")
            return 0
        if status != 200:
            print(f"    contact {arg}: lookup http {status}")
            return 1

        cs, _ = call("DELETE", f"/crm/v3/objects/contacts/{data['id']}")
        print(f"    contact {data['id']} ({arg}): {'deleted' if cs in (204, 200) else 'http ' + str(cs)}")
        return 0

    print(f"unknown command: {cmd}")
    return 2


if __name__ == "__main__":
    sys.exit(main())
