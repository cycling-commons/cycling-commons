#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-only
"""Well-known climbs per country from Wikidata — and a check on the ones we have.

Two modes, and the second is the one that has already earned its keep.

`--country ES` lists named mountain passes in a country, ordered by Wikidata
sitelink count, which is the closest machine-readable proxy for "well known"
there is. Each carries a summit coordinate and usually a published elevation.

`--verify` compares OUR stored `summitEle` for every measured climb against
Wikidata's published elevation for the same pass. That is an INDEPENDENT check
on the whole elevation stack — our DEM, our sampling, and above all where the
drawn line stops. A climb whose line ends short of the real col reads low here
and nowhere else.

    python3 tools/wikimedia/climb_candidates.py --country ES
    python3 tools/wikimedia/climb_candidates.py --verify   # needs psql access

## What the 2026-08-08 probe found

Availability is very uneven, and it tracks whether a country's famous climbs
are *mountain passes* in Wikidata's sense:

| Country | Usable candidates | Notes |
|---|---|---|
| CH, IT, FR | 10+ each, easily | Gotthard, Stelvio, Galibier, Tourmalet, Mortirolo… |
| ES | 10+ | Angliru, Somport, Bonaigua, Larrau, Roncevaux |
| BE | 10, and correctly | the Flemish bergs — Koppenberg, Paterberg, Taaienberg |
| GB | ~8 | Hardknott, Wrynose, Bealach na Bà |
| DE, JP, AU | thin | their famous cycling climbs are not classified as passes |
| NL | **zero** | there are no mountain passes in the Netherlands |

Two things this canNOT give you, and both matter more than the list:

1. **The foot.** Wikidata knows the col, not where the climb begins. That is a
   judgement — the junction where the pass road leaves the valley — and getting
   it wrong is exactly the defect already logged against the existing climbs
   (docs/TODO.md, "Lock climb start/end"). Bulk-importing 120 climbs with
   guessed feet would multiply one known defect by seventeen.
2. **Whether it is worth riding.** Sitelink count measures fame, not quality,
   and several high-ranking passes are motorway crossings.

So this is a research aid for a human building a seed list, not an importer.

## Verify results, 2026-08-08

    Furka      +1 m      Grimsel   +1 m      Susten   +3 m
    Nufenen    -2 m      Klausen  +14 m      Gotthard -11 m

Six independent agreements inside 14 m is a strong result for GLO-30 — and it
retires the old Susten defect, which used to read 2260 m against a published
2224 and now reads 2227. **Gotthard is the one to look at**: 11 m low means the
drawn line probably stops short of the col.
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
import time
import urllib.parse
import urllib.request

UA = "CyclingCommons-climb-probe/1.0 (https://cyclingcommons.org; info@cyclingcommons.org)"
SPARQL = "https://query.wikidata.org/sparql"
API = "https://www.wikidata.org/w/api.php"
OVERPASS = "https://overpass-api.de/api/interpreter"

# OpenStreetMap as a second source (added 2026-09-06). Wikidata knows one
# mountain pass in Colombia; OpenStreetMap names 106. The candidate carries
# `osm:node:<id>` where a Wikidata row carries a Q-id, so the seed can file
# it under its own provenance. No fame proxy exists here, so the list is
# ordered by the pass's tagged elevation, highest first, and a pass without
# an `ele` tag sorts last rather than being dropped.
OSM_QUERY = """
[out:json][timeout:120];
area["ISO3166-1"="%s"][admin_level=2]->.a;
node["mountain_pass"="yes"]["name"](area.a);
out body;
"""

# Q133056 = mountain pass, plus anything that subclasses it. Q54050 = hill:
# the class the Flemish bergs and the Dutch and Luxembourg climbs live under,
# because a country without a col still has hills a race goes over
# (Côte de Desnié and Haute-Levée are "hill", not "pass"). Chosen per run
# with --class; the summit is a hilltop then, and climb_sides.py walks down
# from it the same way.
# Q5762701 "hillclimbing" is the class the Flemish walls and many raced
# climbs sit in (Muur van Geraardsbergen, Oude Kwaremont); Q5409910 "steep
# road" its neighbour; Q8502 "mountain" holds the big road climbs that are a
# summit and not a col (Cauberg, Mont Ventoux, Alto de Letras). A mountain
# without a road to its top simply yields no side, so asking costs time, not
# correctness.
CLASSES = {"pass": "Q133056", "hill": "Q54050", "climb": "Q5762701", "steep": "Q5409910", "mountain": "Q8502"}
CANDIDATES_QUERY = """
SELECT ?item ?itemLabel ?coord ?ele ?links WHERE {
  ?item wdt:P31/wdt:P279* wd:%s ;
        wdt:P17 wd:%s ;
        wdt:P625 ?coord ;
        wikibase:sitelinks ?links .
  OPTIONAL { ?item wdt:P2044 ?ele }
  SERVICE wikibase:label { bd:serviceParam wikibase:language "en,nl,fr,de,es,it,ja". }
}
ORDER BY DESC(?links) LIMIT %d
"""

COUNTRY_QID = {
    "BE": "Q31", "NL": "Q55", "DE": "Q183", "LU": "Q32", "FR": "Q142",
    "CH": "Q39", "GB": "Q145", "IT": "Q38", "AU": "Q408", "JP": "Q17",
    "US": "Q30", "ES": "Q29",
    # The 2026-08-14 rollout's seven, added 2026-08-16 so the probe covers
    # every onboarded country rather than the twelve that existed when it was
    # written. Availability is not promised - see the table above; ZA, CL, CO
    # and NZ have real pass roads, RW and SI fewer, and a country with none
    # returns an empty list rather than an error.
    "CA": "Q16", "CL": "Q298", "CO": "Q739", "NZ": "Q664",
    "RW": "Q1037", "SI": "Q215", "ZA": "Q258",
}

# Anything further apart than this is worth a human look at where the line ends.
SUMMIT_TOLERANCE_M = 25.0


def _get(url: str) -> dict:
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "application/json"})
    with urllib.request.urlopen(req, timeout=60) as resp:
        return json.load(resp)


def _post(url: str, data: dict) -> dict:
    req = urllib.request.Request(url, data=urllib.parse.urlencode(data).encode(),
                                 headers={"User-Agent": UA, "Accept": "application/json"})
    with urllib.request.urlopen(req, timeout=150) as resp:
        return json.load(resp)


def osm_candidates(cc: str, limit: int = 15) -> list[dict]:
    """Named mountain passes from OpenStreetMap, via Overpass (retries on 504/429)."""
    last = None
    for attempt in range(4):
        try:
            rows = _post(OVERPASS, {"data": OSM_QUERY % cc.upper()}).get("elements", [])
            break
        except urllib.error.HTTPError as exc:
            last = exc
            if exc.code not in (429, 502, 503, 504):
                raise
            time.sleep(15 * (attempt + 1))
    else:
        raise SystemExit(f"Overpass kept failing for {cc}: {last}")

    out, seen = [], set()
    for n in rows:
        tags = n.get("tags", {})
        name = tags.get("name", "").strip()
        if not name or name in seen or "lat" not in n:
            continue
        seen.add(name)
        ele = None
        raw = tags.get("ele", "").replace("m", "").replace(",", ".").strip()
        try:
            ele = float(raw) if raw else None
        except ValueError:
            ele = None
        out.append({
            "name": name,
            "qid": f"osm:node:{n['id']}",
            "summit": [round(float(n["lat"]), 5), round(float(n["lon"]), 5)],
            "ele": ele,
            "sitelinks": 0,
        })
    out.sort(key=lambda c: -(c["ele"] or -1.0))
    return out[:limit]


def candidates(cc: str, limit: int = 15, cls: str = "pass", source: str = "wikidata") -> list[dict]:
    if source == "osm":
        return osm_candidates(cc, limit)
    if source != "wikidata":
        raise SystemExit(f"unknown source {source!r}; wikidata or osm")
    qid = COUNTRY_QID.get(cc.upper())
    if qid is None:
        raise SystemExit(f"no Wikidata item known for country {cc!r}")
    class_qid = CLASSES.get(cls)
    if class_qid is None:
        raise SystemExit(f"unknown class {cls!r}; one of {sorted(CLASSES)}")
    url = SPARQL + "?" + urllib.parse.urlencode(
        {"query": CANDIDATES_QUERY % (class_qid, qid, limit), "format": "json"})
    rows = _get(url)["results"]["bindings"]

    seen, out = set(), []
    for b in rows:
        label = b["itemLabel"]["value"]
        if label in seen:
            continue  # Wikidata carries genuine duplicates for several passes
        seen.add(label)
        # POINT(lng lat)
        lng, lat = b["coord"]["value"].removeprefix("Point(").removesuffix(")").split()
        out.append({
            "name": label,
            "qid": b["item"]["value"].rsplit("/", 1)[-1],
            "summit": [round(float(lat), 5), round(float(lng), 5)],
            "ele": float(b["ele"]["value"]) if "ele" in b else None,
            "sitelinks": int(b["links"]["value"]),
        })
    return out


def published_elevation(name: str) -> float | None:
    """Wikidata's P2044 for the best match on this name, or None."""
    hits = _get(API + "?" + urllib.parse.urlencode({
        "action": "wbsearchentities", "format": "json", "language": "en",
        "limit": 1, "search": name,
    })).get("search", [])
    if not hits:
        return None
    claims = _get(API + "?" + urllib.parse.urlencode({
        "action": "wbgetclaims", "format": "json", "property": "P2044",
        "entity": hits[0]["id"],
    })).get("claims", {}).get("P2044", [])
    for claim in claims:
        value = claim.get("mainsnak", {}).get("datavalue", {}).get("value")
        if isinstance(value, dict) and value.get("amount"):
            return float(value["amount"])
    return None


def ours() -> list[tuple[str, float]]:
    """Every climb we have measured, straight out of the dev database."""
    sql = ("SELECT name || '|' || (attributes->>'summitEle') FROM item "
           "WHERE letter='N' AND attributes ? 'summitEle' ORDER BY 1")
    out = subprocess.run(
        ["docker", "compose", "-f", "developers/docker/compose.yaml", "exec", "-T", "db",
         "psql", "-U", "cc", "-d", "cyclingcommons", "-tAc", sql],
        capture_output=True, text=True, check=True).stdout
    rows = []
    for line in out.splitlines():
        line = line.strip()
        if "|" in line:
            name, ele = line.rsplit("|", 1)
            rows.append((name, float(ele)))
    return rows


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--country", action="append", default=[], help="ISO 3166-1 alpha-2 (repeatable)")
    ap.add_argument("--limit", type=int, default=15)
    ap.add_argument("--class", dest="cls", default="pass", choices=sorted(CLASSES),
                    help="Wikidata class to harvest: pass (default) or hill")
    ap.add_argument("--source", default="wikidata", choices=("wikidata", "osm"),
                    help="where candidates come from: wikidata (default) or osm (Overpass, mountain_pass=yes)")
    ap.add_argument("--verify", action="store_true",
                    help="compare our stored summitEle against Wikidata's published elevation")
    args = ap.parse_args()

    if args.verify:
        flagged = 0
        for name, mine in ours():
            published = published_elevation(name)
            if published is None:
                print(f"{name:32} ours {mine:6.0f} m   wikidata: none")
            else:
                diff = mine - published
                mark = "   <-- CHECK where the line ends" if abs(diff) > SUMMIT_TOLERANCE_M else ""
                flagged += 1 if mark else 0
                print(f"{name:32} ours {mine:6.0f} m   wikidata {published:6.0f} m   {diff:+5.0f} m{mark}")
            time.sleep(1)
        print(f"\n{flagged} climb(s) outside ±{SUMMIT_TOLERANCE_M:.0f} m.")
        return 0

    if not args.country:
        ap.error("give --country CC (repeatable), or --verify")

    result = {}
    for cc in args.country:
        found = candidates(cc, args.limit, args.cls, args.source)
        result[cc.upper()] = found
        print(f"{cc.upper()}: {len(found)} distinct pass(es)", file=sys.stderr)
        for c in found[:10]:
            ele = f"{c['ele']:.0f} m" if c["ele"] else "no elevation"
            print(f"    {c['name']} — {ele} — {c['sitelinks']} sitelinks", file=sys.stderr)
        time.sleep(1.5)

    json.dump(result, sys.stdout, indent=2, ensure_ascii=False)
    print()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
