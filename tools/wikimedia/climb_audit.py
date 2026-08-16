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
import urllib.request

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from climb_sides import BY_COUNTRY, VALHALLA  # noqa: E402

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


def existing_climbs():
    """Climbs already in the catalogue, as (name, lat, lng) - never re-seed one."""
    sql = ("SELECT json_agg(json_build_object('name', name, 'lat', ST_Y(geom), 'lng', ST_X(geom)))::text "
           "FROM item WHERE letter = 'B'")
    try:
        out = subprocess.run(PSQL + [sql], capture_output=True, text=True, check=True).stdout.strip()
        return json.loads(out) or []
    except Exception:
        return []


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--dir", default="tools/wikimedia/out", help="where the climb-sides-*.json live")
    ap.add_argument("--out", help="write the review table here (markdown)")
    ap.add_argument("--json-out", help="write the judged data here")
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
                ele = col.get("ele")
                if ele:
                    gap = abs(side["summit_ele"] - ele)
                    if gap > SUMMIT_TOLERANCE_M:
                        verdict = "CHECK"
                        why.append(f"tops out {gap:.0f} m from the published col ({side['summit_ele']} vs {ele:.0f} m)")
                if t["not_road_pct"] >= 8:
                    verdict = "CHECK"
                    why.append(f"{t['not_road_pct']}% off-road")
        r["verdict"], r["why"] = verdict, why

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
                  "| cc | pass | km | gain | avg | road | surface | note |",
                  "|---|---|---:|---:|---:|---|---|---|"]
        for r in picked:
            s, t = r["side"], r["trace"]
            lines.append(
                f"| {r['cc']} | {r['col']['name']} | {s['length_m'] / 1000:.1f} | {s['gain_m']} m "
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
                    "verdict": r["verdict"], "why": r["why"], "trace": r["trace"],
                    **{k: v for k, v in r["side"].items()}}
                   for r in rows]
        p = pathlib.Path(args.json_out)
        io.open(p, "w", encoding="utf-8").write(json.dumps(payload, ensure_ascii=False, indent=2))
        print(f"wrote {p}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
