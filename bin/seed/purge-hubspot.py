"""Remove the demo data this repo seeded from HubSpot portal 247211579.

Dry run by default. Nothing is deleted without --confirm.

Contacts are matched against the email list parsed out of seed.php, so there is
one source of truth and this targets exactly what the seeder created. A
reserved-domain check (RFC 2606 example.com/net/org) is applied on top as a
safety assertion, not as the identifier.

Matching on the domain alone was the first attempt and it was wrong twice over:
it picked up a pre-existing contact on example.com from before the seeding, and
it matched a real company record whose domain happened to be example.com. Both
would have been deleted. Hence the narrower rule, and hence companies get no
rule at all: they are listed as candidates and removed only when their id is
named explicitly.

Deals are removed only when they both belong to a seeded contact and carry
nvr_wc_order_id, so a deal created by hand or by another integration is never
touched. Line items go first, because deleting a deal orphans them.

Env:
    HS_PAK   private app token (never printed)

Usage:
    purge-hubspot.py                       dry run
    purge-hubspot.py --confirm             delete contacts, deals, line items
    purge-hubspot.py --company-id=343769403098[,...]   also delete those companies
"""

import json
import os
import re
import sys
import urllib.error
import urllib.request

TOKEN = os.environ["HS_PAK"]
BASE = "https://api.hubapi.com"
HERE = os.path.dirname(os.path.abspath(__file__))
SEED_FILE = os.path.join(HERE, "seed.php")

# RFC 2606 reserved. Used only to assert that a target cannot be a real address.
RESERVED = ("example.com", "example.net", "example.org")

CONFIRM = "--confirm" in sys.argv
COMPANY_IDS = []
for arg in sys.argv[1:]:
    if arg.startswith("--company-id="):
        COMPANY_IDS.extend(x.strip() for x in arg.split("=", 1)[1].split(",") if x.strip())


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


def seeded_emails():
    """The seeder's own customer list, so the two cannot drift apart."""
    try:
        src = open(SEED_FILE, encoding="utf-8").read()
    except OSError as exc:
        print(f"cannot read {SEED_FILE}: {exc}")
        raise SystemExit(2)

    found = re.findall(r"'email'\s*=>\s*'([^']+)'", src)
    emails = {e.lower() for e in found}

    unsafe = {e for e in emails if not any(e.endswith("@" + d) for d in RESERVED)}
    if unsafe:
        # The seeder is supposed to use reserved domains only. If that ever
        # changes, refuse rather than delete something deliverable.
        print("refusing to run: seed.php contains non-reserved addresses:")
        for e in sorted(unsafe):
            print(f"    {e}")
        raise SystemExit(2)

    return emails


def find_contact(email):
    s, d = call(
        "POST",
        "/crm/v3/objects/contacts/search",
        {
            "filterGroups": [{"filters": [{"propertyName": "email", "operator": "EQ", "value": email}]}],
            "properties": ["email", "nvr_customer_tier"],
            "limit": 10,
        },
    )
    if s != 200:
        print(f"    search failed for {email}: http={s}")
        return []
    return [(r["id"], ((r.get("properties") or {}).get("email") or "").lower()) for r in d.get("results", [])]


EMAILS = seeded_emails()

print("=" * 76)
print("HubSpot demo purge " + ("(CONFIRMED, will delete)" if CONFIRM else "(DRY RUN, deletes nothing)"))
print(f"seeded addresses in seed.php: {len(EMAILS)}")
print("=" * 76)

# --- contacts ---------------------------------------------------------------
targets = []
for email in sorted(EMAILS):
    for cid, actual in find_contact(email):
        # Belt and braces: the search matched, but re-assert both conditions.
        if actual in EMAILS and any(actual.endswith("@" + d) for d in RESERVED):
            targets.append((cid, actual))
        else:
            print(f"  SKIP contact {cid} ({actual}): not a seeded address")

targets = sorted(set(targets), key=lambda t: t[1])

print(f"\ncontacts created by the seeder: {len(targets)}")
for cid, email in targets:
    print(f"    {cid:<16} {email}")

missing = EMAILS - {e for _, e in targets}
if missing:
    print(f"\n  no contact in the portal for {len(missing)} seeded address(es):")
    for e in sorted(missing):
        print(f"    {e}")

# --- their nvr-hs deals, and the line items under them ----------------------
deals = {}
line_items = {}

for cid, email in targets:
    s, d = call("GET", f"/crm/v4/objects/contacts/{cid}/associations/deals?limit=100")
    if s != 200:
        continue
    for row in d.get("results", []):
        deal_id = row.get("toObjectId")
        if not deal_id or deal_id in deals:
            continue

        ds, dd = call("GET", f"/crm/v3/objects/deals/{deal_id}?properties=dealname,amount,nvr_wc_order_id")
        if ds != 200:
            continue
        props = dd.get("properties") or {}
        ref = props.get("nvr_wc_order_id")

        if not ref:
            print(f"  SKIP deal {deal_id} ({props.get('dealname')}): no nvr_wc_order_id, not ours")
            continue

        deals[deal_id] = (ref, props.get("dealname") or "", props.get("amount") or "0")

        ls, ld = call("GET", f"/crm/v4/objects/deals/{deal_id}/associations/line_items?limit=100")
        if ls == 200:
            for lrow in ld.get("results", []):
                if lrow.get("toObjectId"):
                    line_items[lrow["toObjectId"]] = deal_id

print(f"\ndeals carrying nvr_wc_order_id on those contacts: {len(deals)}")
for did, (ref, name, amount) in sorted(deals.items(), key=lambda kv: kv[1][0]):
    print(f"    {did:<16} {ref:<14} {float(amount):>14,.0f}  {name[:34]}")

print(f"\nline items under those deals: {len(line_items)}")

# --- companies: candidates only, never matched automatically ----------------
print("\ncompanies associated with the seeded contacts:")
candidates = {}
for cid, email in targets:
    s, d = call("GET", f"/crm/v4/objects/contacts/{cid}/associations/companies?limit=50")
    if s != 200:
        continue
    for row in d.get("results", []):
        coid = row.get("toObjectId")
        if not coid or coid in candidates:
            continue
        cs, cd = call("GET", f"/crm/v3/objects/companies/{coid}?properties=name,domain,num_associated_contacts")
        if cs == 200:
            p = cd.get("properties") or {}
            candidates[str(coid)] = (p.get("name") or "(unnamed)", p.get("domain") or "", p.get("num_associated_contacts") or "?")

if not candidates:
    print("    none")
for coid, (name, domain, n) in candidates.items():
    mark = "WILL DELETE" if coid in COMPANY_IDS else "candidate"
    print(f"    {coid:<16} {domain:<14} contacts={n:<4} {name[:34]:<34} {mark}")

print(
    "\n    Companies are never matched by rule: HubSpot auto-creates them from a\n"
    "    contact's email domain, and a real company can legitimately carry the\n"
    "    same domain. Name the ones you want gone with --company-id=<id>[,<id>]."
)

unknown = [c for c in COMPANY_IDS if c not in candidates]
if unknown:
    print(f"\n  refusing: --company-id given for companies not associated with any seeded contact: {unknown}")
    raise SystemExit(2)

to_delete_companies = [c for c in COMPANY_IDS if c in candidates]

total = len(line_items) + len(deals) + len(targets) + len(to_delete_companies)
print("\n" + "-" * 76)
print(
    f"would delete: {len(line_items)} line items, {len(deals)} deals, "
    f"{len(targets)} contacts, {len(to_delete_companies)} companies  ({total} records)"
)

if not CONFIRM:
    print("\nDry run. Re-run with --confirm to delete.")
    raise SystemExit(0)

if total == 0:
    print("\nNothing to delete.")
    raise SystemExit(0)

# --- delete, children first -------------------------------------------------
print("\ndeleting...")
failed = 0


def delete(obj, oid, label):
    global failed
    s, d = call("DELETE", f"/crm/v3/objects/{obj}/{oid}")
    if s in (200, 202, 204):
        print(f"    {obj:<11} {oid:<16} deleted   {label}")
    else:
        failed += 1
        print(f"    {obj:<11} {oid:<16} FAILED {s} {str(d)[:80]}")


for lid in line_items:
    delete("line_items", lid, "")
for did, (ref, _name, _amount) in deals.items():
    delete("deals", did, ref)
for cid, email in targets:
    delete("contacts", cid, email)
for coid in to_delete_companies:
    delete("companies", coid, candidates[coid][1])

print("\n" + "-" * 76)
print(f"done, {failed} failure(s)")
print("HubSpot deletes are soft for 90 days; records are recoverable from the recycle bin.")
raise SystemExit(1 if failed else 0)
