#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Scenic viewpoints and historical places per country, with verified photos.

Harvests from Wikidata, checks every photo's licence against the Commons API,
and writes a **reviewable JSON artifact** per country. It does not touch the
database: `app:catalog:seed-wikidata` imports the artifact, so what ships is a
file a human read and committed, not whatever an API returned the day the seed
ran.

    python3 tools/wikimedia/country_places.py --country ES --country IT
    python3 tools/wikimedia/country_places.py --all

Two layers, matching the catalogue's own letters:

- **I · scenic-views** — mountains, lakes, waterfalls, national parks and
  viewpoints. Things you stop the bike for.
- **J · history-culture** — UNESCO World Heritage sites, castles, monasteries,
  monuments. Things worth riding past slowly.

Selection is by Wikidata sitelink count, which is the only machine-readable
proxy for "worth the detour" available. It is a proxy for **fame**, not for
quality and certainly not for whether a road goes there — which is why the
output is a draft for review rather than an import.

Every entry must clear the same bar `commons_photo.py` sets: a free licence on
the accepted list, and an author Commons actually states. A place whose photo
fails that is dropped rather than shipped photo-less, because the photo is most
of the point of a scenic pin.
"""

from __future__ import annotations

import argparse
import json
import pathlib
import sys
import time
import re
import urllib.parse
import urllib.request

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))
from commons_photo import FREE_LICENCES, credit_from, licence_of  # noqa: E402
from divisions.config import COUNTRY_CONFIG  # noqa: E402

# Wikidata returns the Q-id as the label when it can find no name at all. That
# is not a name, and "Q130018" on a map pin is worse than no pin.
#
# The label service is asked for "en,mul", NOT just "en". `mul` is Wikidata's
# "default for all languages" — the value an item carries when its name is the
# same everywhere, which is exactly the case for most mountains. Asking only for
# English made Denali and Mount St. Helens come back as Q130018 and Q4675 and
# get thrown away, when both have a perfectly good name sitting in `mul` and
# their English row on Wikidata simply inherits it.
QID_AS_LABEL = re.compile(r"^Q\d+$")

# Reviewed exclusions: inside the country's box, correctly typed, correctly
# licensed — and still wrong for this atlas. A bbox cannot judge these, and the
# judgement should survive a re-harvest rather than be made again every time,
# so it lives here with its reason attached.
EXCLUDE = {
    # It IS land — a 562 m volcanic stack, and the tallest in the world. It is
    # also 20 km of open ocean from the nearest road, uninhabited, and landing
    # on it needs a permit. Excluded for being unreachable by bike, not for
    # being wet.
    "Q152872": "Ball's Pyramid — 20 km of open ocean from the nearest road; landing needs a permit.",
}

UA = "CyclingCommons-places/1.0 (https://cyclingcommons.org; info@cyclingcommons.org)"
SPARQL = "https://query.wikidata.org/sparql"

COUNTRY_QID = {
    "BE": "Q31", "NL": "Q55", "DE": "Q183", "LU": "Q32", "FR": "Q142",
    "CH": "Q39", "GB": "Q145", "IT": "Q38", "AU": "Q408", "JP": "Q17",
    "US": "Q30", "ES": "Q29",
}

# Wikidata classes per layer, with the catalogue `type` value each maps to.
# The `type` strings are the registry's own choices — they must stay inside the
# scenic-views / history-culture vocabularies or the seed refuses the row.
SCENIC = {
    "Q8502": "Viewpoint / high point",    # mountain
    "Q23397": "Viewpoint / high point",   # lake
    "Q34038": "Viewpoint / high point",   # waterfall
    "Q46169": "Nature reserve",           # national park
    "Q179049": "Viewpoint / high point",  # gorge
}
HISTORY = {
    "Q23413": "Heritage site",     # castle
    "Q44613": "Religious site",    # monastery
    "Q16970": "Religious site",    # church building
    "Q4989906": "Monument",        # monument
    "Q33506": "Museum",            # museum
}

QUERY = """
SELECT ?item ?itemLabel ?itemDescription ?coord ?image ?links WHERE {
  ?item wdt:P31/wdt:P279* wd:%(cls)s ;
        wdt:P17 wd:%(country)s ;
        wdt:P625 ?coord ;
        wdt:P18 ?image ;
        wikibase:sitelinks ?links .
  FILTER(?links >= 8)
  SERVICE wikibase:label { bd:serviceParam wikibase:language "en,mul". }
}
ORDER BY DESC(?links) LIMIT %(limit)d
"""


def in_country_box(cc: str, lat: float, lng: float) -> bool:
    """Inside the area this country was actually onboarded for.

    Wikidata's `country` property is sovereignty, not geography, and the
    difference is not academic: it puts Île Amsterdam and Île Saint-Paul (the
    southern Indian Ocean) under France, Inaccessible Island and the Soufrière
    Hills under the UK, and Saba, Sint Eustatius and Bonaire under the
    Netherlands — whose divisions config deliberately excludes the Caribbean
    municipalities. Seeding those puts scenic pins thousands of kilometres from
    any road anybody here rides, in regions that do not exist.

    The bbox is the SAME one the region onboarding used
    (tools/divisions/config.py), so "somewhere we have regions for" means one
    thing across both tools. A country with no config falls through as allowed —
    it has no onboarded area to be outside of.
    """
    box = COUNTRY_CONFIG.get(cc.upper(), {}).get("bbox")
    if not box:
        return True
    west, south, east, north = box
    return south <= lat <= north and west <= lng <= east


def _get(url: str, attempts: int = 4) -> dict:
    """GET with backoff.

    The SPARQL endpoint rate-limits, and a swallowed failure here is worse than
    a crash: the layer simply fills with whatever the NEXT class returned, so a
    throttled mountain query turns Australia's scenic pins from Uluru and
    Kosciuszko into four waterfalls — and the artifact looks perfectly fine.
    That happened once; hence retries, and hence a failed class being reported
    at the end rather than only warned about mid-run.
    """
    last: Exception | None = None
    for attempt in range(attempts):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "application/json"})
            with urllib.request.urlopen(req, timeout=120) as resp:
                return json.load(resp)
        except Exception as exc:  # noqa: BLE001 — retried below
            last = exc
            if attempt < attempts - 1:
                time.sleep(5 * (attempt + 1))
    raise RuntimeError(f"gave up after {attempts} attempts: {last}")


def query(cls: str, country_qid: str, limit: int) -> list[dict]:
    url = SPARQL + "?" + urllib.parse.urlencode({
        "query": QUERY % {"cls": cls, "country": country_qid, "limit": limit},
        "format": "json",
    })
    return _get(url)["results"]["bindings"]


def harvest(cc: str, per_layer: int = 6) -> dict:
    """Everything for one country, photo-verified, deduplicated by Q-id."""
    qid = COUNTRY_QID[cc.upper()]
    out: dict = {"country": cc.upper(), "scenic": [], "history": []}
    seen: set[str] = set()

    for layer, classes in (("scenic", SCENIC), ("history", HISTORY)):
        for cls, type_label in classes.items():
            try:
                rows = query(cls, qid, per_layer * 2)
            except Exception as exc:  # noqa: BLE001 — recorded, not swallowed
                print(f"  !! {cc} {layer}/{cls}: query failed ({exc})", file=sys.stderr)
                out.setdefault("failed", []).append(f"{layer}/{cls}")
                continue
            for b in rows:
                if len(out[layer]) >= per_layer:
                    break
                q = b["item"]["value"].rsplit("/", 1)[-1]
                if q in seen or q in EXCLUDE:
                    continue
                filename = urllib.parse.unquote(
                    b["image"]["value"].rsplit("/", 1)[-1]).replace("_", " ")
                meta = licence_of(filename)
                if not meta["exists"] or meta["non_free"] or meta["restrictions"]:
                    continue
                canonical = FREE_LICENCES.get(meta["licence_short"].strip().lower())
                if canonical is None:
                    continue
                credit, _ = credit_from(meta)
                if credit is None:
                    continue     # unattributable share-alike is unusable
                label = b["itemLabel"]["value"]
                lng, lat = b["coord"]["value"].removeprefix("Point(").removesuffix(")").split()
                if QID_AS_LABEL.match(label):
                    # Reaching here now means the item has NO name in English
                    # and none in `mul` either — genuinely unnamed, not merely
                    # un-Englished. Recorded rather than silently skipped: the
                    # remedy is to add a label on Wikidata, which fixes it for
                    # everyone, and you cannot do that for a drop you never saw.
                    out.setdefault("dropped", []).append(
                        {"qid": q, "why": "no label in English or mul", "at": [float(lat), float(lng)]})
                    continue
                if not in_country_box(cc, float(lat), float(lng)):
                    out.setdefault("dropped", []).append(
                        {"qid": q, "name": label, "why": "outside the onboarded bbox", "at": [float(lat), float(lng)]})
                    continue
                seen.add(q)
                out[layer].append({
                    "qid": q,
                    "name": label,
                    "type": type_label,
                    "note": b.get("itemDescription", {}).get("value", ""),
                    "lat": round(float(lat), 5),
                    "lng": round(float(lng), 5),
                    "sitelinks": int(b["links"]["value"]),
                    "photo": {
                        "file": filename,
                        "credit": credit,
                        "user": meta["user"],
                        "license": canonical,
                    },
                })
                time.sleep(0.25)
            time.sleep(0.5)
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--country", action="append", default=[])
    ap.add_argument("--all", action="store_true", help="every country in COUNTRY_QID")
    ap.add_argument("--per-layer", type=int, default=6, help="entries per layer per country")
    ap.add_argument("--out", default="wikimedia/out", help="artifact directory")
    args = ap.parse_args()

    countries = sorted(COUNTRY_QID) if args.all else [c.upper() for c in args.country]
    if not countries:
        ap.error("give --country CC (repeatable) or --all")

    outdir = pathlib.Path(args.out)
    outdir.mkdir(parents=True, exist_ok=True)

    for cc in countries:
        if cc not in COUNTRY_QID:
            print(f"{cc}: no Wikidata item known — skipped", file=sys.stderr)
            continue
        data = harvest(cc, args.per_layer)
        path = outdir / f"places-{cc.lower()}.json"
        path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        warn = f"  !! {len(data['failed'])} class(es) FAILED — rerun {cc}" if data.get("failed") else ""
        if data.get("dropped"):
            warn += f"  ({len(data['dropped'])} candidate(s) dropped — see \"dropped\" in the artifact)"
        print(f"{cc}: {len(data['scenic'])} scenic, {len(data['history'])} historical → {path}{warn}",
              file=sys.stderr)
        for entry in data["scenic"] + data["history"]:
            print(f"    {entry['name']} — {entry['type']} — {entry['photo']['license']}",
                  file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
