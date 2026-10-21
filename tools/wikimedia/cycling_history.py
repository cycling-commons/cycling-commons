#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-only
"""Cycling history places: hand-written stories, located on OSM and Wikidata.

    python3 tools/wikimedia/cycling_history.py
    python3 tools/wikimedia/cycling_history.py --only "Tom Simpson memorial"   # just that row
    python3 tools/wikimedia/cycling_history.py --refresh   # texts and links only

Reads `cycling_history.json` (next to this file): one entry per place, with a
name, a kind (App\\Catalog\\PlaceKind, letter Q) and a story we wrote. Writes
`out/cycling-history.json`, the reviewable artifact that
`app:catalog:seed-cycling-history` imports. The database is never touched.

Where a place is, in this order:

- `osm`: the exact OSM object (`node/1334315594`). Its point wins, and a node
  or way becomes the row's `osmRef`, so our pin stands in for the OSM pin
  rather than beside it (catalog-data-model.md §5b).
- `qid`: the Wikidata item. Its point (P625) is used when there is no `osm`.
- `wiki`: an English Wikipedia title, for a place whose Q-id is not known yet.

Every id is one a person looked up and checked; nothing here searches by name,
because the first search hit for a memorial is as often the village around it.
For a place without `osm`, Overpass is asked for the one node or way tagged
with its Q-id near the point; when Overpass is busy the place is written
without an `osmRef` and says so. A place that cannot be located is listed at
the end and left out, never guessed.

A `photo` is a Commons file name a person looked at before writing it down:
it shows the place, and nobody is in it close up (photo-uploads.md §6). The
script asks Commons for its licence and author every run and leaves a photo
off, with the reason, when it no longer passes `usable_photo()`.

Links (catalog-data-model.md §7): the place's own Wikipedia article becomes a
`links` entry labelled "Wikipedia", and each `about` title (the people or the
event the story tells of) an entry labelled with its name, each with its
language variants. The place's official website (P856) goes to `web`, never
into `links`. `--refresh` rewrites the story, kind, year, photo and links of the
rows already written, without locating anything again.
"""

from __future__ import annotations

import argparse
import json
import math
import pathlib
import re
import sys
import time
import unicodedata
import urllib.parse
import urllib.request

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from commons_photo import licence_of, usable_photo  # noqa: E402

HERE = pathlib.Path(__file__).resolve().parent
SOURCE = HERE / "cycling_history.json"
OUT = HERE / "out" / "cycling-history.json"

UA = "CyclingCommons-places/1.0 (https://cyclingcommons.org; info@cyclingcommons.org)"
WIKIDATA = "https://www.wikidata.org/w/api.php"
NOMINATIM = "https://nominatim.openstreetmap.org"
OVERPASS = ("https://overpass-api.de/api/interpreter", "https://overpass.private.coffee/api/interpreter")

# The kinds a Q place can have (App\Catalog\PlaceKind::KINDS['Q']).
KINDS = {
    "castle", "fort", "ruins", "monument", "memorial", "archaeological",
    "manor", "monastery", "museum", "worship", "heritage", "architecture",
}

OSM_REF = re.compile(r"^(node|way|relation)/(\d+)$")

# The languages the site serves (App\Catalog\Import\OutboundLinks::LOCALES).
LOCALES = ("en", "fr", "nl", "de", "es")

# Every Wikipedia entry has an en.wikipedia.org url, and OutboundLinks allows
# one host in at most two entries.
MAX_WIKIPEDIA_ENTRIES = 2

# How far from the Wikidata point Overpass looks for the object with that Q-id.
NEAR_M = 2000

_last_nominatim = 0.0


def _get(url: str, data: bytes | None = None, attempts: int = 3, timeout: int = 60) -> dict | list:
    last: Exception | None = None
    for attempt in range(attempts):
        try:
            req = urllib.request.Request(url, data=data, headers={"User-Agent": UA, "Accept": "application/json"})
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                return json.load(resp)
        except Exception as exc:  # noqa: BLE001 - retried below
            last = exc
            if attempt < attempts - 1:
                time.sleep(5 * (attempt + 1))
    raise RuntimeError(f"gave up after {attempts} attempts: {last}")


def _nominatim(path: str, params: dict) -> dict | list:
    """Nominatim allows one request a second (operations.osmfoundation.org/policies/nominatim)."""
    global _last_nominatim
    wait = 1.1 - (time.monotonic() - _last_nominatim)
    if wait > 0:
        time.sleep(wait)
    _last_nominatim = time.monotonic()

    return _get(f"{NOMINATIM}/{path}?" + urllib.parse.urlencode(params | {"format": "jsonv2"}))


def slug(name: str) -> str:
    ascii_ = unicodedata.normalize("NFKD", name).encode("ascii", "ignore").decode()
    return re.sub(r"[^a-z0-9]+", "-", ascii_.lower()).strip("-")


def wikidata_point(qid: str | None = None, title: str | None = None) -> tuple[str, tuple[float, float] | None] | None:
    """Q-id and point (P625) of a Wikidata item, by id or by English Wikipedia title."""
    params = {"action": "wbgetentities", "props": "claims", "format": "json"}
    params |= {"ids": qid} if qid else {"sites": "enwiki", "titles": title, "redirects": "yes"}
    data = _get(WIKIDATA + "?" + urllib.parse.urlencode(params))
    for found, entity in data.get("entities", {}).items():
        if found.startswith("-") or "missing" in entity:
            return None
        for claim in entity.get("claims", {}).get("P625", []):
            value = claim["mainsnak"].get("datavalue", {}).get("value")
            if value:
                return found, (round(value["latitude"], 6), round(value["longitude"], 6))

        return found, None

    return None


def wikidata_entity(qid: str | None = None, title: str | None = None) -> dict | None:
    """Sitelinks and claims of one item, by Q-id or by English Wikipedia title."""
    params = {"action": "wbgetentities", "props": "sitelinks|claims", "format": "json"}
    params |= {"ids": qid} if qid else {"sites": "enwiki", "titles": title, "redirects": "yes"}
    data = _get(WIKIDATA + "?" + urllib.parse.urlencode(params))
    for found, entity in data.get("entities", {}).items():
        if found.startswith("-") or "missing" in entity:
            return None

        return entity

    return None


def wikipedia_urls(entity: dict) -> list[dict]:
    urls = []
    for lc in LOCALES:
        link = (entity.get("sitelinks") or {}).get(f"{lc}wiki")
        if link and link.get("title"):
            title = urllib.parse.quote(str(link["title"]).replace(" ", "_"), safe="")
            urls.append({"locale": lc, "url": f"https://{lc}.wikipedia.org/wiki/{title}"})

    return urls


def links_for(qid: str | None, about: list[str]) -> tuple[dict, list[str]]:
    """{web?, links?} for one place, and what had to be left off."""
    out: dict = {}
    entries: list[dict] = []
    notes: list[str] = []
    seen: set[str] = set()
    if qid:
        place = wikidata_entity(qid=qid)
        if place is not None:
            seen.add(place["id"])
            for claim in (place.get("claims") or {}).get("P856", []):
                value = ((claim.get("mainsnak") or {}).get("datavalue") or {}).get("value")
                if isinstance(value, str) and value.startswith("https://"):
                    out["web"] = value
                    break
            urls = wikipedia_urls(place)
            if urls:
                entries.append({"label": "Wikipedia", "urls": urls})
    for title in about:
        subject = wikidata_entity(title=title)
        if subject is None:
            raise LookupError(f"no Wikipedia article {title!r}")
        if subject["id"] in seen:
            continue
        seen.add(subject["id"])
        urls = wikipedia_urls(subject)
        if not urls:
            continue
        if len(entries) >= MAX_WIKIPEDIA_ENTRIES:
            notes.append(f"left off {title!r}: two Wikipedia links already")
            continue
        entries.append({"label": re.sub(r"\s*\(.*\)$", "", title), "urls": urls})
    if entries:
        out["links"] = entries

    return out, notes


def photo_for(entry: dict) -> tuple[dict | None, str]:
    """The checked photo record of an entry's `photo`, or None and why it was left off."""
    name = entry.get("photo")
    if not name:
        return None, ""
    record = usable_photo(name, licence_of(name))
    if record is None:
        return None, f"photo {name!r} is not free to use, or has no stated author"

    return record, ""


def osm_object(ref: str) -> dict | None:
    """Point, Q-id tag and display name of one OSM object."""
    kind, number = OSM_REF.match(ref).groups()
    hits = _nominatim("lookup", {"osm_ids": kind[0].upper() + number, "extratags": 1})
    if not hits:
        return None
    hit = hits[0]

    return {
        "point": (round(float(hit["lat"]), 6), round(float(hit["lon"]), 6)),
        "qid": (hit.get("extratags") or {}).get("wikidata"),
        "found": hit.get("display_name"),
    }


def osm_ref_for(qid: str, point: tuple[float, float]) -> str | None:
    """The one node or way tagged with this Q-id near the point; None when there is not exactly one."""
    query = f'[out:json][timeout:25];nw["wikidata"="{qid}"](around:{NEAR_M},{point[0]},{point[1]});out ids;'
    for endpoint in OVERPASS:
        try:
            data = _get(endpoint, data=urllib.parse.urlencode({"data": query}).encode(), attempts=2, timeout=40)
        except RuntimeError:
            continue
        elements = data.get("elements", [])

        return f"{elements[0]['type']}/{elements[0]['id']}" if len(elements) == 1 else None
    raise RuntimeError("Overpass is busy")


def country_of(point: tuple[float, float]) -> str | None:
    hit = _nominatim("reverse", {"lat": point[0], "lon": point[1], "zoom": 3})
    code = (hit.get("address") or {}).get("country_code") if isinstance(hit, dict) else None

    return code.upper() if code else None


def resolve(entry: dict) -> tuple[dict | None, str]:
    """The artifact row for one entry, or None and the reason it was left out."""
    name = entry.get("name")
    if not name:
        return None, "no name"
    if entry.get("kind") not in KINDS:
        return None, f"kind {entry.get('kind')!r} is not a Q kind"
    if not entry.get("story"):
        return None, "no story"
    if "year" in entry and not (isinstance(entry["year"], int) and 100 <= entry["year"] <= 9999):
        return None, f"year {entry['year']!r} is not a year"
    if entry.get("osm") and not OSM_REF.match(entry["osm"]):
        return None, f"osm {entry['osm']!r} is not node/<id>, way/<id> or relation/<id>"

    qid = entry.get("qid")
    point = None
    osm_ref = None
    found = None
    if entry.get("osm"):
        obj = osm_object(entry["osm"])
        if obj is None:
            return None, f"OSM has no {entry['osm']}"
        point, found = obj["point"], obj["found"]
        qid = qid or obj["qid"]
        if not entry["osm"].startswith("relation/"):
            osm_ref = entry["osm"]
    elif qid or entry.get("wiki"):
        item = wikidata_point(qid=qid, title=None if qid else entry["wiki"])
        if item is None:
            return None, f"no Wikidata item for {qid or entry['wiki']!r}"
        qid, point = item
        if point is None:
            return None, f"Wikidata {qid} has no point; give the place an `osm` object"
        try:
            osm_ref = osm_ref_for(qid, point)
        except RuntimeError as exc:
            print(f"  ?? {name}: no osmRef ({exc})", file=sys.stderr)
    else:
        return None, "no osm, qid or wiki"

    country = country_of(point)
    if country is None:
        return None, "no country at that point"

    row = {
        "ref": "manual:history-" + slug(name),
        "name": name,
        "kind": entry["kind"],
        "lat": point[0],
        "lng": point[1],
        "cc": country,
        "story": entry["story"],
    }
    if "year" in entry:
        row["year"] = str(entry["year"])
    if qid:
        row["qid"] = qid
    if osm_ref:
        row["osmRef"] = osm_ref
    if found:
        row["found"] = found
    try:
        row |= links_for(qid, entry.get("about", []))[0]
    except LookupError as exc:
        return None, str(exc)
    photo, why = photo_for(entry)
    if photo is not None:
        row["photo"] = photo
    elif why:
        print(f"  ?? {name}: {why}", file=sys.stderr)

    return row, ""


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    parser.add_argument("--only", action="append", default=[], help="Resolve only the place with this name, and update just its row in the artifact")
    parser.add_argument("--refresh", action="store_true", help="Rewrite story, kind, year, photo and links of the rows already written, without locating them again")
    args = parser.parse_args()

    entries = json.loads(SOURCE.read_text(encoding="utf-8"))["places"]
    if args.refresh:
        return refresh(entries)
    if args.only:
        entries = [e for e in entries if e.get("name") in args.only]

    rows: list[dict] = []
    missing: list[str] = []
    for entry in entries:
        row, why = resolve(entry)
        if row is None:
            missing.append(f"{entry.get('name', '?')}: {why}")
            print(f"  -- {entry.get('name', '?')}: {why}", file=sys.stderr)
            continue
        print(f"  ok {row['cc']} {row['name']} ({row['lat']}, {row['lng']}) {row.get('osmRef', '')}", file=sys.stderr)
        rows.append(row)

    refs = [r["ref"] for r in rows]
    twice = sorted({r for r in refs if refs.count(r) > 1})
    if twice:
        print(f"Two places share a ref, rename one: {', '.join(twice)}", file=sys.stderr)

        return 1

    if args.only and OUT.exists():
        # Re-resolve a few places into the artifact; every other row stays as written.
        # A row whose place left the list goes too.
        kept = json.loads(OUT.read_text(encoding="utf-8"))
        names = set(args.only)
        listed = {e.get("name") for e in json.loads(SOURCE.read_text(encoding="utf-8"))["places"]}
        rows = [r for r in kept["places"] if r["name"] not in names and r["name"] in listed] + rows
        missing = [m for m in kept.get("missing", []) if m.split(":")[0] not in names] + missing

    rows.sort(key=lambda r: (r["cc"], r["name"]))
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps({"places": rows, "missing": missing}, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    print(f"{len(rows)} places written to {OUT}, {len(missing)} left out", file=sys.stderr)

    return 0


def refresh(entries: list[dict]) -> int:
    by_name = {e["name"]: e for e in entries}
    artifact = json.loads(OUT.read_text(encoding="utf-8"))
    failed = 0
    for row in artifact["places"]:
        entry = by_name.get(row["name"])
        if entry is None:
            continue
        try:
            found, notes = links_for(row.get("qid"), entry.get("about", []))
        except LookupError as exc:
            print(f"  -- {row['name']}: {exc}", file=sys.stderr)
            failed += 1
            continue
        for key in ("web", "links", "year", "photo"):
            row.pop(key, None)
        row["kind"], row["story"] = entry["kind"], entry["story"]
        if "year" in entry:
            row["year"] = str(entry["year"])
        row |= found
        photo, why = photo_for(entry)
        if photo is not None:
            row["photo"] = photo
        elif why:
            print(f"  ?? {row['name']}: {why}", file=sys.stderr)
        labels = ", ".join(e["label"] for e in found.get("links", []))
        print(f"  ok {row['name']}: {labels or 'no links'}{'  web' if 'web' in found else ''}", file=sys.stderr)
        for note in notes:
            print(f"  ?? {row['name']}: {note}", file=sys.stderr)
    OUT.write_text(json.dumps(artifact, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")

    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
