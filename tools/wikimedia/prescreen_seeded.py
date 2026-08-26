#!/usr/bin/env python3
# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Mechanical pre-screen of the seeded curated rows (B climbs, I scenic, J history).

The owner reads all 199 before launch (docs/TODO.md, "Content review"). Two of
the four questions per row are human calls and stay that way — *would a rider
actually go there*, and *is the photo the place*. The other two are checkable,
and checking them by hand 199 times is how a real error gets skimmed past:

  1. **Is the point in the right place?**  Every row is cross-checked against
     Wikidata's own P625 coordinate for the same QID. We seeded FROM Wikidata,
     so agreement is expected — a disagreement is a seeding bug, and a MISSING
     P625 means the pin came from somewhere else entirely and nobody has
     checked it.

  2. **Is it in the country we filed it under?**  The point is tested against
     our own region polygons. Outside every region of its `country_code` means
     either the wrong country or an offshore pin; both are worth a look, and
     the distance to the nearest region says which.

  3. **Is it a place at all, or an area we flattened to a dot?**  Wikidata's
     instance-of classes are read for each row: a national park, a lake, a
     mountain range or a protected area stored as one point is the "Yosemite
     National Park" case the owner already flagged.

  4. **Is the name ambiguous?**  Names carried by more than one row, or by more
     than one Wikidata item, are the St Mary's/St Paul's wrong-match risk.

Everything it reports is a *candidate for a human look*, never a verdict. It
prints a short list; silence about a row means the mechanical checks passed,
not that the row is good.

    python3 tools/wikimedia/prescreen_seeded.py            # all letters
    python3 tools/wikimedia/prescreen_seeded.py --letter P
    python3 tools/wikimedia/prescreen_seeded.py --json out.json
"""

from __future__ import annotations

import argparse
import json
import math
import re
import subprocess
import sys
import time
import urllib.parse
import unicodedata
import urllib.parse  # noqa: F811
import urllib.request

WIKIDATA_API = "https://www.wikidata.org/w/api.php"
USER_AGENT = "CyclingCommons-prescreen/1.0 (https://cyclingcommons.org; info@cyclingcommons.org)"

# Wikidata classes that describe an AREA. A point is a poor stand-in for any of
# them, which is the Yosemite case: the pin lands wherever Wikidata happened to
# put the label, not where a rider would stop.
AREA_CLASSES = {
    "Q46169": "national park (US)",
    "Q46169820": "national park",
    "Q9259": "UNESCO World Heritage Site",
    "Q473972": "protected area",
    "Q23397": "lake",
    "Q46831": "mountain range",
    "Q82794": "geographic region",
    "Q4022": "river",
    "Q40080": "beach",
    "Q22698": "park",
    "Q1437459": "cultural landscape",
    "Q15640612": "reservoir",
    "Q131681": "reservoir",
}

# How far a pin may sit from Wikidata's own coordinate before it is worth a
# look. Wikidata rounds, and a big building has more than one defensible
# centre, so a few hundred metres is noise. A kilometre is not.
COORD_TOLERANCE_M = 1000


# Characters that SEPARATE two words and must become a space, not vanish.
# Deleting them was a real gap (found 2026-08-24): "Cote de Saint-Roch" reduced
# to "cotedesaintroch" while "Cote de Saint Roch" reduced to
# "cote de saint roch", so the one pair of spellings this function exists to
# catch was the one pair it could never catch.
#
# Written as explicit escapes, and the unicode dashes are listed on purpose:
# they must be replaced BEFORE the ascii fold below, which deletes them outright
# and would close the gap up again. `App\Catalog\Import\NameKey` is the PHP
# twin of this function and the two are pinned together by
# tools/wikimedia/name_key_cases.json — keep the character set identical.
_SEPARATORS = re.compile(
    "["
    "\\-"          # hyphen-minus
    "/"            # slash
    "_"            # underscore
    "­"       # soft hyphen
    "‐-―"  # hyphen .. horizontal bar (en dash, em dash, figure dash)
    "−"       # minus sign
    "]+"
)


def normalise_name(name: str) -> str:
    """A name reduced to what makes two rows the SAME dedication.

    "St Mary's Cathedral", "St. Mary's Cathedral" and "St Mary's Cathedral,
    Perth" are three strings and one wrong-match risk, so the abbreviation
    point, the possessive apostrophe and any trailing place qualifier all come
    off before comparing. Exact matching found none of them.

    Separators (hyphens, slashes, dashes of every width) become a SPACE;
    joiners inside a word (apostrophes, abbreviation points) come off with no
    replacement. That is the difference between "Saint-Roch" meaning
    "Saint Roch" and "Mary's" meaning "Marys".
    """
    n = _SEPARATORS.sub(" ", name)
    n = unicodedata.normalize("NFKD", n).encode("ascii", "ignore").decode()
    n = n.split(",")[0]                       # drop ", Perth" / ", Brisbane"
    n = re.sub(r"\bSt\.", "St", n)
    n = re.sub(r"[^\w\s]", "", n)              # apostrophes, periods, the rest
    return re.sub(r"\s+", " ", n).strip().casefold()


def db(sql: str, **bindings: str) -> list[list[str]]:
    """One psql round trip against the dev database, tab-separated.

    Values go in as `-v name=value` and are read in SQL as `:'name'`, which
    psql quotes and escapes itself. Nothing here builds a literal by hand: one
    of the queries below keys on `country_code` values read back OUT of the
    database, which is second-order injection waiting for the first row whose
    text carries a quote (security scan 2026-08-25).

    The statement arrives on STDIN rather than through `-c`, because psql hands
    a `-c` string straight to the server without running its own parser over
    it, so `:'name'` would reach PostgreSQL verbatim and come back as a syntax
    error.
    """
    argv = [
        "docker", "compose", "-f", "developers/docker/compose.yaml",
        "exec", "-T", "db", "psql", "-U", "cc", "-d", "cyclingcommons",
        "-At", "-F", "\t",
    ]
    for name, value in bindings.items():
        argv += ["-v", f"{name}={value}"]
    argv += ["-f", "-"]

    out = subprocess.run(
        argv, input=sql, capture_output=True, text=True, check=True,
    ).stdout
    return [line.split("\t") for line in out.splitlines() if line]


def haversine_m(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    r = 6371000.0
    p1, p2 = math.radians(lat1), math.radians(lat2)
    dp = math.radians(lat2 - lat1)
    dl = math.radians(lon2 - lon1)
    a = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
    return 2 * r * math.asin(math.sqrt(a))


def wikidata_entities(qids: list[str]) -> dict[str, dict]:
    """Coordinates (P625) and instance-of (P31) for up to 50 QIDs per call."""
    found: dict[str, dict] = {}
    for i in range(0, len(qids), 50):
        batch = qids[i : i + 50]
        params = urllib.parse.urlencode({
            "action": "wbgetentities",
            "ids": "|".join(batch),
            "props": "claims|labels",
            "languages": "en",
            "format": "json",
        })
        req = urllib.request.Request(f"{WIKIDATA_API}?{params}", headers={"User-Agent": USER_AGENT})
        with urllib.request.urlopen(req, timeout=60) as fh:
            payload = json.load(fh)
        for qid, entity in (payload.get("entities") or {}).items():
            claims = entity.get("claims") or {}
            coord = None
            for claim in claims.get("P625", []):
                value = (claim.get("mainsnak") or {}).get("datavalue", {}).get("value")
                if value:
                    coord = (float(value["latitude"]), float(value["longitude"]))
                    break
            classes = []
            for claim in claims.get("P31", []):
                value = (claim.get("mainsnak") or {}).get("datavalue", {}).get("value")
                if value and "id" in value:
                    classes.append(value["id"])
            found[qid] = {
                "coord": coord,
                "classes": classes,
                "label": ((entity.get("labels") or {}).get("en") or {}).get("value"),
            }
        # Wikidata asks for politeness rather than a hard rate; one pause per
        # batch of fifty is well inside it.
        time.sleep(1)
    return found


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--letter", choices=["N", "P", "Q"], help="only this letter")
    ap.add_argument("--json", metavar="PATH", help="also write the findings as JSON")
    args = ap.parse_args()

    letters = [args.letter] if args.letter else ["N", "P", "Q"]

    rows = db("""
        SELECT i.letter, i.country_code, i.name, i.source_ref,
               ST_Y(ST_Centroid(i.geom))::text, ST_X(ST_Centroid(i.geom))::text,
               ST_GeometryType(i.geom),
               ((i.attributes -> 'photo') IS NOT NULL)::text,
               COALESCE(NULLIF(i.attributes ->> 'description', ''), '')
          FROM item i
         WHERE i.letter = ANY (string_to_array(:'letters', ','))
           AND i.source IN ('manual','wikidata')
         ORDER BY i.letter, i.country_code, i.name
    """, letters=",".join(letters))

    items = [{
        "letter": r[0], "cc": r[1], "name": r[2], "ref": r[3],
        "lat": float(r[4]), "lon": float(r[5]), "geom": r[6],
        "photo": r[7] == "true", "description": r[8],
    } for r in rows]
    print(f"→ {len(items)} seeded rows across {', '.join(letters)}\n", file=sys.stderr)

    # ── Check 2: inside a region of its own country? ────────────────────────
    # One query for all of them, using our own polygons. `nearest_km` says
    # whether an outside point is a metre over a border or a country away.
    # One bound JSON document, expanded server-side. These rows come back OUT
    # of the database, so pasting `cc` into a SQL literal made the query only as
    # safe as the tidiest row in `item` (security scan 2026-08-25).
    points = json.dumps([
        {"n": n, "lon": it["lon"], "lat": it["lat"], "cc": it["cc"]}
        for n, it in enumerate(items)
    ])
    placement = {int(r[0]): (r[1] == "true", float(r[2]) if r[2] else None, r[3] or "")
                 for r in db("""
        WITH pt AS (
            SELECT (e ->> 'n')::int      AS n,
                   (e ->> 'lon')::float8 AS lon,
                   (e ->> 'lat')::float8 AS lat,
                   e ->> 'cc'            AS cc
              FROM jsonb_array_elements(:'points'::jsonb) AS e
        ),
             p AS (SELECT n, cc, ST_SetSRID(ST_MakePoint(lon, lat), 4326) AS g FROM pt)
        SELECT p.n,
               EXISTS (SELECT 1 FROM region r
                        WHERE r.country_code = p.cc AND r.geom IS NOT NULL
                          AND ST_Contains(r.geom, p.g))::text,
               (SELECT ROUND((ST_Distance(r.geom::geography, p.g::geography) / 1000)::numeric, 1)
                  FROM region r WHERE r.country_code = p.cc AND r.geom IS NOT NULL
                 ORDER BY r.geom <-> p.g LIMIT 1)::text,
               COALESCE((SELECT r.country_code FROM region r
                          WHERE r.geom IS NOT NULL AND ST_Contains(r.geom, p.g)
                          LIMIT 1), '')
          FROM p
    """, points=points)}

    # ── Checks 1 and 3: Wikidata's own coordinate and classes ───────────────
    qids = [it["ref"].split(":", 1)[1] for it in items
            if it["ref"].startswith("wikidata:")]
    print(f"→ asking Wikidata about {len(qids)} items…", file=sys.stderr)
    wd = wikidata_entities(qids) if qids else {}

    # ── Check 4: names carried by more than one row ─────────────────────────
    by_name: dict[str, list[dict]] = {}
    for it in items:
        by_name.setdefault(normalise_name(it["name"]), []).append(it)

    findings: list[dict] = []
    for n, it in enumerate(items):
        inside, nearest_km, actual_cc = placement.get(n, (True, None, ""))
        qid = it["ref"].split(":", 1)[1] if it["ref"].startswith("wikidata:") else None
        entity = wd.get(qid or "")

        def flag(kind: str, detail: str) -> None:
            findings.append({
                "kind": kind, "letter": it["letter"], "cc": it["cc"],
                "name": it["name"], "ref": it["ref"],
                "lat": it["lat"], "lon": it["lon"], "detail": detail,
            })

        if not inside:
            where = f"nearest {it['cc']} region {nearest_km} km away" if nearest_km else "no region polygon to test against"
            if actual_cc and actual_cc != it["cc"]:
                flag("wrong-country", f"filed under {it['cc']} but the point is inside {actual_cc}; {where}")
            else:
                flag("outside-country", f"outside every {it['cc']} region — {where}")

        if qid and entity is None:
            flag("wikidata-gone", "the QID we seeded from returns nothing today")
        elif entity:
            if entity["coord"] is None:
                flag("no-wikidata-coord", "Wikidata has no P625 for this item — the pin came from somewhere unchecked")
            else:
                d = haversine_m(it["lat"], it["lon"], *entity["coord"])
                if d > COORD_TOLERANCE_M:
                    flag("coord-drift", f"{round(d)} m from Wikidata's own coordinate ({entity['coord'][0]:.5f}, {entity['coord'][1]:.5f})")
            areas = [AREA_CLASSES[c] for c in entity["classes"] if c in AREA_CLASSES]
            if areas and it["geom"] == "ST_Point":
                flag("area-as-point", f"Wikidata calls this a {', '.join(sorted(set(areas)))} — one pin for a whole area")
            if entity["label"] and entity["label"].strip().casefold() != it["name"].strip().casefold():
                flag("name-drift", f"Wikidata's English label is “{entity['label']}”")

        twins = by_name[normalise_name(it["name"])]
        if len(twins) > 1 and twins[0] is it:
            flag("ambiguous-name", "the same dedication is seeded in " + ", ".join(
                sorted({t["cc"] for t in twins if t is not it})) + " — check each row is the right building, not a namesake")

        if not it["photo"]:
            flag("no-photo", "no photo")
        if not it["description"]:
            flag("no-description", "no description")

    # ── Climbs: the LINE and the numbers that come off it ───────────────────
    # No Valhalla needed. Every one of these compares a climb against ITSELF —
    # the stored length against the drawn line's actual length, the stated gain
    # against summit-minus-foot, the average gradient against gain over length.
    # A climb that disagrees with itself was measured at a different time from
    # when its line was last moved, and `app:climbs:recompute --write` is the
    # fix. (Verifying the summit against the real world is a separate job that
    # DOES need elevation: `climb_candidates.py --verify`.)
    if "N" in letters:
        for r in db("""
            SELECT i.name, i.country_code,
                   (i.attributes ? 'route')::text,
                   (i.attributes ? 'lineGrad')::text,
                   COALESCE((i.attributes->>'length'), ''),
                   COALESCE(CASE WHEN i.attributes ? 'route' THEN
                     ROUND(ST_Length(ST_MakeLine(ARRAY(
                       SELECT ST_MakePoint((e->>1)::float, (e->>0)::float)
                         FROM jsonb_array_elements(i.attributes->'route') e))::geography))::text
                   END, ''),
                   COALESCE((i.attributes->>'gain'), ''),
                   COALESCE(((i.attributes->>'summitEle')::float - (i.attributes->>'footEle')::float)::text, ''),
                   COALESCE((SELECT MIN(v::int)::text FROM jsonb_array_elements_text(i.attributes->'lineGrad') v), '')
              FROM item i
             WHERE i.letter = 'N' AND i.source IN ('manual','wikidata')
             ORDER BY i.country_code, i.name
        """):
            name, cc, has_line, has_grad, stored, measured, gain, delta, min_grad = r

            def cflag(kind: str, detail: str) -> None:
                findings.append({"kind": kind, "letter": "N", "cc": cc, "name": name,
                                 "ref": "", "lat": 0.0, "lon": 0.0, "detail": detail})

            if has_line != "true":
                cflag("climb-no-line",
                      "no drawn line — the gradients cannot be derived and the map has nothing to draw"
                      + ("" if stored else "; not even a stored length"))
                continue
            if has_grad != "true":
                cflag("climb-not-measured",
                      "a line is drawn but nothing was measured from it — `app:climbs:recompute --write` "
                      "has not run since it was drawn (needs Valhalla up, or it writes zeros)")
                continue

            if stored and measured:
                drift = abs(float(measured) - float(stored))
                # 30 m is the polyline-vs-sampled-path rounding the recompute
                # itself leaves behind. Ten times that is a moved line.
                if drift > 100:
                    cflag("climb-length-drift",
                          f"stored length {float(stored):.0f} m, the drawn line measures {float(measured):.0f} m "
                          f"({drift:.0f} m apart) — the line moved after the numbers were taken")
            if gain and delta and abs(float(gain) - float(delta)) > 1:
                cflag("climb-gain-mismatch",
                      f"gain says {float(gain):.0f} m, summit minus foot is {float(delta):.0f} m")
            if min_grad and int(min_grad) <= -8:
                cflag("climb-descends",
                      f"a {min_grad}% segment inside the climb — a real dip on a long pass, "
                      "or a line drawn over the top and down the far side")

    # ── Report ──────────────────────────────────────────────────────────────
    order = ["wrong-country", "outside-country", "wikidata-gone", "coord-drift",
             "no-wikidata-coord", "climb-no-line", "climb-not-measured",
             "climb-length-drift", "climb-gain-mismatch", "climb-descends",
             "area-as-point", "ambiguous-name", "name-drift",
             "no-photo", "no-description"]
    by_kind: dict[str, list[dict]] = {}
    for f in findings:
        by_kind.setdefault(f["kind"], []).append(f)

    print(f"\n{len(items)} rows checked. Findings by kind:\n")
    for kind in order:
        hits = by_kind.get(kind, [])
        if not hits:
            continue
        # The two tallies are context, not work — print them as one line each.
        if kind in ("no-photo", "no-description"):
            print(f"  {kind:18} {len(hits):3}  (tally only)")
            continue
        print(f"\n── {kind} ({len(hits)}) " + "─" * max(0, 50 - len(kind)))
        for f in hits:
            print(f"  [{f['letter']}] {f['cc']} {f['name']}")
            print(f"      {f['detail']}")
            if f["lat"] or f["lon"]:
                print(f"      {f['lat']:.5f},{f['lon']:.5f}  ·  https://www.openstreetmap.org/?mlat={f['lat']}&mlon={f['lon']}#map=15/{f['lat']}/{f['lon']}")

    real = [f for f in findings if f["kind"] not in ("no-photo", "no-description")]
    print(f"\n{len(real)} rows want a human look, out of {len(items)}.")

    if args.json:
        with open(args.json, "w", encoding="utf-8") as fh:
            json.dump({"checked": len(items), "findings": findings}, fh, indent=2, ensure_ascii=False)
        print(f"→ {args.json}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
