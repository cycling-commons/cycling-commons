#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Judge the harvested climb sides, so a human reviews 20 rows instead of 143.

`climb_sides.py` writes candidates and says plainly that it cannot tell a road
from a driveway: "a 40 km side at 1.2% is a valley road, and a 900 m one at 14%
is a driveway. Both look like climbs to arithmetic."

Arithmetic is the wrong instrument, so this uses a different one. Valhalla's
`trace_attributes` snaps a stored line back onto the road graph and returns what
each edge actually IS - `road_class`, `surface`, `use`, and the road's name. A
driveway says `use: driveway`; a mule track says `track` and `gravel`; the
Stelvio says `tertiary` and `paved_smooth` for 26 km. That is a fact about the
road rather than an inference from two numbers.

    python3 tools/wikimedia/climb_audit.py --out out/climb-review.md

Four verdicts, and the point of the split is that only one of them is a
judgement call a person has to make:

  DROP    something the trace proves is not a road climb.
  DUPLICATE  the same pass already held, either by another country's file (a
          border col is in both) or by a row already in our catalogue.
  CHECK   plausible but with one thing worth a human eye - an unpaved pass (real
          and famous, or a mistake, and the trace cannot tell which), or a
          summit that does not reach the col Wikidata publishes.
  KEEP    the trace agrees it is a paved road climb of a sane shape.

Nothing here writes to the database. The output is a review file.
"""

from __future__ import annotations

import argparse
import collections
import io
import json
import math
import pathlib
import subprocess
import sys
import time
import urllib.parse
import urllib.request

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from climb_sides import BY_COUNTRY, ROAD_BIKE, VALHALLA  # noqa: E402

PSQL = ["docker", "exec", "cycling-commons-dev-db-1", "psql", "-U", "cc", "-d", "cyclingcommons", "-tAc"]

# `use` values that mean "this is not the road up the pass". `track` and `path`
# are the mule-track case; `driveway` is a farm entrance the router took because
# it was the straightest way to a point in a field.
NOT_A_ROAD = {"driveway", "track", "path", "footway", "steps", "pedestrian", "alley", "parking_aisle"}
# Anything not in here counts as unpaved for the share below. Valhalla reports
# `compacted` for well-kept gravel, which a road bike can ride and a review
# should still be told about.
PAVED = {"paved", "paved_smooth", "paved_rough", "path", "compacted"}

# How far a measured summit may sit from Wikidata's published col before it is
# worth a look. 60 m is generous against the ±14 m the six Swiss passes agreed
# within (climb_candidates.py) - past it the line probably stops below the col.
SUMMIT_TOLERANCE_M = 60.0
# A road climb steeper than this over a real distance is almost always a track.
IMPLAUSIBLE_PCT = 13.0
IMPLAUSIBLE_OVER_M = 3000.0
# Two harvested sides whose FEET are this close are the same road found twice.
SAME_FOOT_M = 600.0


def _hav(a, b):
    r = 6371000.0
    la1, lo1, la2, lo2 = map(math.radians, [a[0], a[1], b[0], b[1]])
    h = math.sin((la2 - la1) / 2) ** 2 + math.cos(la1) * math.cos(la2) * math.sin((lo2 - lo1) / 2) ** 2
    return 2 * r * math.asin(math.sqrt(h))


def trace(line, base, max_points=280):
    """What the road under this line actually is, length-weighted.

    The shape is thinned to `max_points` before tracing: map-matching cost grows
    with the point count, and a 2,000-point line snaps to the same edges as a
    280-point one because the points are metres apart either way.
    """
    step = max(1, len(line) // max_points)
    shape = line[::step]
    if shape[-1] != line[-1]:
        shape.append(line[-1])
    body = json.dumps({
        "shape": [{"lat": p[0], "lon": p[1]} for p in shape],
        "costing": "bicycle",
        # The SAME road-bike costing the harvest routed with. Map-matching is a
        # routing problem too, so a trace run under a laxer profile can snap a
        # perfectly good road line onto the footpath running beside it and then
        # report the line as off-road - the audit inventing the very defect it
        # exists to find.
        "costing_options": {"bicycle": ROAD_BIKE},
        "shape_match": "map_snap",
        "filters": {"attributes": ["edge.road_class", "edge.surface", "edge.use",
                                   "edge.names", "edge.length"], "action": "include"},
    }).encode()
    req = urllib.request.Request(base.rstrip("/") + "/trace_attributes", data=body,
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=180) as fh:
        data = json.load(fh)

    edges = data.get("edges", [])
    total = sum(e.get("length", 0.0) for e in edges) or 1.0
    bad = sum(e.get("length", 0.0) for e in edges if e.get("use") in NOT_A_ROAD)
    unpaved = sum(e.get("length", 0.0) for e in edges if e.get("surface") not in PAVED)
    classes = collections.Counter()
    names = collections.Counter()
    for e in edges:
        classes[e.get("road_class") or "?"] += e.get("length", 0.0)
        for n in (e.get("names") or []):
            names[n] += e.get("length", 0.0)
    return {
        "edges": len(edges),
        "not_road_pct": round(bad / total * 100, 1),
        "unpaved_pct": round(unpaved / total * 100, 1),
        "top_class": classes.most_common(1)[0][0] if classes else "?",
        "road": names.most_common(1)[0][0] if names else "",
    }


def published_metres(ele, measured):
    """Wikidata's elevation in METRES, or None when it cannot be trusted.

    Wikidata stores a quantity with a unit and `climb_candidates.py` keeps only
    the number, so US passes arrive in FEET: Independence Pass reads 12103
    against our measured 3687, and the summit check then reports it as 8,416 m
    off the col - a units bug wearing the costume of a data defect.

    The ratio is the tell. 3.28 is feet-per-metre and nothing else lands there,
    so a published/measured ratio near it is a unit, not a disagreement.
    """
    if not ele or not measured:
        return None
    ratio = ele / measured
    if 3.0 < ratio < 3.6:
        return ele / 3.28084
    # Anything else wildly out of scale is not a comparison worth making; say
    # nothing rather than raise a confident wrong alarm.
    return ele if 0.5 < ratio < 2.0 else None


NOMINATIM = "https://nominatim.openstreetmap.org/reverse"
UA = "CyclingCommons-climb-audit/1.0 (https://cyclingcommons.org; info@cyclingcommons.org)"


def foot_place(lat, lng):
    """The settlement a side STARTS from, which is how riders name a climb.

    "Stelvio Pass" is two different climbs and nobody calls them side 0 and side
    1 - they are Stelvio from Prato and Stelvio from Bormio. A pass with two
    sides therefore needs two names, and the only honest source for them is
    where the road actually begins.

    Village -> town -> city -> municipality, in that order: a foot in the
    valley is usually a hamlet, and falling back to the municipality gives the
    name a local would use when the hamlet is too small to be listed. Returns
    None rather than a guess, and the caller leaves the side unnamed.
    """
    url = NOMINATIM + "?" + urllib.parse.urlencode({
        "lat": f"{lat:.6f}", "lon": f"{lng:.6f}", "format": "json", "zoom": "13",
    })
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    try:
        with urllib.request.urlopen(req, timeout=30) as fh:
            addr = json.load(fh).get("address", {})
    except Exception:
        return None
    for key in ("village", "town", "city", "hamlet", "suburb", "municipality", "county"):
        if addr.get(key):
            return addr[key]
    return None


def existing_climbs():
    """Climbs already in the catalogue, as (name, lat, lng) - never re-seed one."""
    sql = ("SELECT json_agg(json_build_object('name', name, 'lat', ST_Y(geom), 'lng', ST_X(geom)))::text "
           "FROM item WHERE letter = 'B'")
    # Narrow, and loud. A blanket `except Exception: return []` here read as
    # "no climbs in the catalogue", which is the same answer as "the database
    # is down" and as "the query is broken" (security scan 2026-08-25). This
    # function's whole job is to stop the caller re-seeding a climb that
    # already exists, so silently answering "none exist" is the one wrong
    # answer it must never give.
    try:
        out = subprocess.run(PSQL + [sql], capture_output=True, text=True, check=True).stdout.strip()
    except (subprocess.CalledProcessError, OSError) as exc:
        detail = exc.stderr.strip() if isinstance(exc, subprocess.CalledProcessError) and exc.stderr else exc
        sys.exit(f"Cannot read the existing climbs, so re-seeding would be unsafe: {detail}")

    if not out:
        return []
    try:
        return json.loads(out) or []
    except json.JSONDecodeError as exc:
        sys.exit(f"The existing-climbs query returned something that is not JSON: {exc}")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--dir", default="tools/wikimedia/out", help="where the climb-sides-*.json live")
    ap.add_argument("--out", help="write the review table here (markdown)")
    ap.add_argument("--json-out", help="write the judged data here")
    ap.add_argument("--name-feet", action="store_true",
                    help="reverse-geocode each surviving foot, so a two-sided pass gets two names "
                         "(one Nominatim call per row at 1/s - opt in)")
    args = ap.parse_args()

    have = existing_climbs()
    rows = []
    seen_qid = {}

    for path in sorted(pathlib.Path(args.dir).glob("climb-sides-*.json")):
        cc = path.stem.split("-")[-1]
        base = BY_COUNTRY.get(cc, VALHALLA)
        for col in json.load(io.open(path, encoding="utf-8")).get(cc, []):
            for i, side in enumerate(col["sides"]):
                rows.append({"cc": cc, "col": col, "side": side, "idx": i, "base": base})

    print(f"{len(rows)} sides to judge", file=sys.stderr)
    for n, r in enumerate(rows, 1):
        side, col = r["side"], r["col"]
        try:
            r["trace"] = trace(side["line"], r["base"])
        except Exception as exc:
            r["trace"] = {"edges": 0, "not_road_pct": None, "unpaved_pct": None,
                          "top_class": "?", "road": "", "error": str(exc)[:60]}
        if n % 20 == 0:
            print(f"  {n}/{len(rows)}", file=sys.stderr)

    # ---- verdicts -------------------------------------------------------
    for r in rows:
        side, col, t = r["side"], r["col"], r["trace"]
        why = []
        verdict = "KEEP"

        # 1. A pass that sits on a border is in BOTH countries' files. Keep the
        #    first and say where the twin was, rather than seeding one climb
        #    twice under two flags.
        qid = col.get("qid")
        key = (qid, r["idx"])
        if qid and key in seen_qid:
            verdict, why = "DUPLICATE", [f"same Wikidata item as {seen_qid[key]}"]
        elif qid:
            seen_qid[key] = f"{r['cc']} {col['name']}"

        # 2. Already ours. Matched on POSITION, not on name: our Furka row and
        #    Wikidata's Furka spell it the same, but the Belgian rows do not
        #    match anything and a name test would be the only check either way.
        if verdict == "KEEP":
            for h in have:
                if _hav((col["summit"][0], col["summit"][1]), (h["lat"], h["lng"])) < 3000:
                    verdict, why = "DUPLICATE", [f"already in the catalogue as \"{h['name']}\""]
                    break

        # 3. Two harvested sides that are really one road.
        if verdict == "KEEP":
            for other in col["sides"][:r["idx"]]:
                if _hav(tuple(side["foot"]), tuple(other["foot"])) < SAME_FOOT_M:
                    verdict, why = "DUPLICATE", ["same foot as an earlier side of this col"]
                    break

        if verdict == "KEEP":
            # 4. The trace's own verdict. This is the one arithmetic cannot give.
            if t.get("not_road_pct") is None:
                verdict, why = "CHECK", ["the router could not match this line to any road"]
            elif t["not_road_pct"] >= 25:
                verdict = "DROP"
                why.append(f"{t['not_road_pct']}% of it is track/path/driveway, not a road")
            elif side["avg_pct"] > IMPLAUSIBLE_PCT and side["length_m"] > IMPLAUSIBLE_OVER_M:
                verdict = "DROP"
                why.append(f"{side['avg_pct']}% for {side['length_m'] / 1000:.1f} km is not a road gradient")
            else:
                if t["unpaved_pct"] >= 35:
                    verdict = "CHECK"
                    why.append(f"{t['unpaved_pct']}% unpaved - a famous gravel pass, or a mistake")
                ele = published_metres(col.get("ele"), side["summit_ele"])
                if ele:
                    gap = abs(side["summit_ele"] - ele)
                    if gap > SUMMIT_TOLERANCE_M:
                        verdict = "CHECK"
                        why.append(f"tops out {gap:.0f} m from the published col ({side['summit_ele']} vs {ele:.0f} m)")
                # A side that stops far short of a much longer one on the same
                # col is usually the descent-stop cutting at a terrace rather
                # than a genuinely short side. Bernina came back 4 km against a
                # real 30; the numbers are self-consistent, which is exactly why
                # nothing else catches it.
                longest = max((o["length_m"] for o in col["sides"]), default=0)
                if side["length_m"] < 5000 and longest >= side["length_m"] * 2.5:
                    verdict = "CHECK"
                    why.append(f"only {side['length_m'] / 1000:.1f} km against {longest / 1000:.1f} km "
                               "on another side - the cut may have landed on a terrace")
                if t["not_road_pct"] >= 8:
                    verdict = "CHECK"
                    why.append(f"{t['not_road_pct']}% off-road")
        r["verdict"], r["why"] = verdict, why

    if args.name_feet:
        # Only the survivors: a DROP is a footpath and a DUPLICATE is already
        # named, so geocoding either spends Nominatim's one-per-second budget
        # on rows nobody will seed.
        wanted = [r for r in rows if r["verdict"] in ("KEEP", "CHECK")]
        print(f"naming {len(wanted)} feet", file=sys.stderr)
        for n, r in enumerate(wanted, 1):
            r["foot_place"] = foot_place(*r["side"]["foot"])
            time.sleep(1.1)  # Nominatim's published limit is 1 request/second.
            if n % 20 == 0:
                print(f"  {n}/{len(wanted)}", file=sys.stderr)

    order = {"KEEP": 0, "CHECK": 1, "DUPLICATE": 2, "DROP": 3}
    rows.sort(key=lambda r: (order[r["verdict"]], r["cc"], r["col"]["name"]))

    counts = collections.Counter(r["verdict"] for r in rows)
    lines = ["# Harvested climb sides - review",
             "",
             f"{len(rows)} sides. "
             + ", ".join(f"**{counts[k]} {k}**" for k in ("KEEP", "CHECK", "DUPLICATE", "DROP") if counts[k]),
             "",
             "Verdicts come from Valhalla `trace_attributes`, which says what each",
             "edge of a stored line actually is, so \"this is a driveway\" is a fact",
             "about the road rather than a guess from two numbers.",
             ""]
    for v in ("KEEP", "CHECK", "DUPLICATE", "DROP"):
        picked = [r for r in rows if r["verdict"] == v]
        if not picked:
            continue
        lines += [f"## {v} ({len(picked)})", "",
                  "| cc | pass | from | km | gain | avg | road | surface | note |",
                  "|---|---|---|---:|---:|---:|---|---|---|"]
        for r in picked:
            s, t = r["side"], r["trace"]
            lines.append(
                f"| {r['cc']} | {r['col']['name']} | {r.get('foot_place') or '-'} "
                f"| {s['length_m'] / 1000:.1f} | {s['gain_m']} m "
                f"| {s['avg_pct']}% | {t['top_class']} {('· ' + t['road']) if t['road'] else ''} "
                f"| {t['unpaved_pct'] if t['unpaved_pct'] is not None else '?'}% unpaved "
                f"| {'; '.join(r['why'])} |")
        lines.append("")

    text = "\n".join(lines)
    if args.out:
        p = pathlib.Path(args.out)
        p.parent.mkdir(parents=True, exist_ok=True)
        io.open(p, "w", encoding="utf-8").write(text)
        print(f"wrote {p}", file=sys.stderr)
    else:
        print(text)

    if args.json_out:
        payload = [{"cc": r["cc"], "name": r["col"]["name"], "qid": r["col"].get("qid"),
                    "summit": r["col"]["summit"], "ele": r["col"].get("ele"),
                    "side_index": r["idx"], "foot_place": r.get("foot_place"),
                    "verdict": r["verdict"], "why": r["why"], "trace": r["trace"],
                    **{k: v for k, v in r["side"].items()}}
                   for r in rows]
        p = pathlib.Path(args.json_out)
        io.open(p, "w", encoding="utf-8").write(json.dumps(payload, ensure_ascii=False, indent=2))
        print(f"wrote {p}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
