#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-only
"""Each region's population and the year it was counted, from Wikidata, as a reviewable artifact.

The top of a region page shows the land's figures: area, population, ride
character (docs/specs/moderation-and-contribution.md §3.1g). People set them
on the region editor; this fills the population where nobody has, from the
one source that covers every province and state worldwide with the year of
the count: Wikidata's population (P1082) with its point in time (P585).
Wikidata is CC0, so the figure needs no credit line.

- **BUILD time, never runtime**, as the Wikipedia leads (region_context.py):
  this script writes `tools/wikimedia/out/region-population.json`, a human
  reviews and commits it, and `app:regions:import-population` loads it into
  `region.facts_harvest`, a column of its own, so a figure a person set is
  never touched.
- **Which statement.** Only the best-ranked statements (preferred where one
  is, else normal; never deprecated), and of those the latest count. A
  statement without a point in time is skipped: the page always says which
  year a count is from. Some counts are old (Irish counties: 2011); the year
  shows on the page, and a person can set a newer one.
- **Which item.** Regions are matched by their ISO 3166-2 code (P300, or
  P297 for a country-level region), with region_context.py's reviewed
  overrides for codes whose item is an empty duplicate.

    python3 tools/wikimedia/region_population.py --dsn postgresql://cc:cc@localhost:5433/cyclingcommons
    python3 tools/wikimedia/region_population.py --dsn ... --iso NL-DR --iso BE-WLX   # a subset, for review
"""

from __future__ import annotations

import argparse
import json
import pathlib
import sys
import time
import urllib.parse

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from region_context import QID_OVERRIDES, SPARQL, _get, regions_from_db  # noqa: E402

OUT = pathlib.Path(__file__).resolve().parent / "out" / "region-population.json"

# One query per 100 codes. `wikibase:BestRank` is the preferred statements
# where an item has any, else its normal ones; deprecated never. Wikidata's
# editors mark the count to use as preferred; taking any later count instead
# brought in rounded milestones (Canada "40,000,000") for six regions.
QUERY = """
SELECT ?key ?item ?pop ?time WHERE {
  VALUES ?key { %(keys)s }
  %(match)s
  ?item p:P1082 ?st.
  ?st a wikibase:BestRank; ps:P1082 ?pop.
  OPTIONAL { ?st pq:P585 ?time. }
}
"""
BY_ISO = "?item wdt:P300|wdt:P297 ?key."
BY_ITEM = "BIND(?key AS ?item)"


def pick(rows: list[dict]) -> dict | None:
    """The latest dated count among one item's best-ranked statements.

    Each row is `{qid, population, time}` with `time` an ISO date string or
    None. Undated counts, and counts that are not whole non-negative
    numbers, are skipped. None when nothing is left.
    """
    best = None
    for row in rows:
        stamp = row.get("time") or ""
        if len(stamp) < 4 or not stamp[:4].isdigit():
            continue
        try:
            value = float(row["population"])
        except (TypeError, ValueError):
            continue
        if value < 0 or value != int(value):
            continue
        candidate = {"qid": row["qid"], "population": int(value), "year": int(stamp[:4]), "_at": stamp}
        if best is None or candidate["_at"] > best["_at"]:
            best = candidate
    if best is None:
        return None
    best.pop("_at")
    return best


def counts(keys: list[str], by_item: bool) -> dict[str, list[dict]]:
    """{key: [{qid, population, time}]} for ISO codes, or for Wikidata ids."""
    out: dict[str, list[dict]] = {}
    for i in range(0, len(keys), 100):
        chunk = keys[i : i + 100]
        values = " ".join(f"wd:{k}" for k in chunk) if by_item else " ".join(f'"{k}"' for k in chunk)
        q = QUERY % {"keys": values, "match": BY_ITEM if by_item else BY_ISO}
        url = SPARQL + "?" + urllib.parse.urlencode({"query": q, "format": "json"})
        for row in _get(url)["results"]["bindings"]:
            qid = row["item"]["value"].rsplit("/", 1)[-1]
            key = qid if by_item else row["key"]["value"]
            out.setdefault(key, []).append({
                "qid": qid,
                "population": row["pop"]["value"],
                "time": (row.get("time") or {}).get("value"),
            })
        time.sleep(1)
    return out


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--dsn", help="postgres DSN to read (slug, iso_code) from")
    ap.add_argument("--iso", action="append", default=[], help="harvest only these ISO 3166-2 codes")
    args = ap.parse_args()
    if not args.dsn:
        ap.error("need --dsn (slugs come from the region table; --iso only filters)")

    pairs = regions_from_db(args.dsn)
    if args.iso:
        wanted = {i.upper() for i in args.iso}
        pairs = [(slug, iso) for slug, iso in pairs if iso.upper() in wanted]

    by_iso = counts([iso for _, iso in pairs if iso not in QID_OVERRIDES], by_item=False)
    overrides = [QID_OVERRIDES[iso] for _, iso in pairs if iso in QID_OVERRIDES]
    by_item = counts(overrides, by_item=True) if overrides else {}

    artifact: dict[str, dict] = {}
    misses: list[str] = []
    for slug, iso in pairs:
        rows = by_item.get(QID_OVERRIDES[iso], []) if iso in QID_OVERRIDES else by_iso.get(iso, [])
        found = pick(rows)
        if found is None:
            misses.append(iso)
            continue
        artifact[slug] = found
        print(f"{slug} ({iso}): {found['population']:,} in {found['year']} ({found['qid']})", file=sys.stderr)

    OUT.parent.mkdir(parents=True, exist_ok=True)
    # A partial run (--iso) merges into the committed artifact; a full run replaces it.
    if args.iso and OUT.exists():
        existing = json.loads(OUT.read_text(encoding="utf-8"))
        existing.update(artifact)
        artifact = existing
    OUT.write_text(json.dumps(artifact, ensure_ascii=False, indent=1, sort_keys=True) + "\n", encoding="utf-8")
    print(f"wrote {OUT} - {len(artifact)} regions, {len(misses)} without a dated count"
          + (f" ({', '.join(misses[:10])}{'…' if len(misses) > 10 else ''})" if misses else ""), file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
