#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-only
"""Draw or repair a climb's line by routing foot -> summit through OUR Valhalla.

The climb editor draws this line in a browser, two clicks and a snap. That is
the right tool for one climb and the wrong one for twenty: a hand-drawn line is
also how the two logged defects got in (Gotthard stopping short of the col,
Roche-aux-Faucons moving after its numbers were taken). Routing the same two
points through the same Valhalla the editor snaps to gives the identical line
without the hand, and makes "redraw it to the real col" a rerunnable act rather
than a memory of where somebody clicked.

    # what a redraw would change, printed, nothing written
    python3 tools/wikimedia/climb_line.py --id 22084 --to 46.5592,8.5617

    # draw a missing line from a researched foot, then write it
    python3 tools/wikimedia/climb_line.py --id 3124 --from 50.5218,5.8676 --write

`--to` alone keeps the stored foot and moves only the summit end, which is the
"line stops short of the col" repair. `--from` alone keeps the stored summit.
Either may be given; with neither there is nothing to do.

**Costing is `bicycle`, matching RouteSnapper** - the app's own editor snap. A
`driving` profile refuses the cycleways and greenways some climbs ride, and a
line that disagrees with the editor's would be a second answer to one question.

The written line is the same shape the editor stores: a JSON array of
[lat, lng] pairs in `attributes.route`. Nothing here computes gradients; run
`app:climbs:recompute --id <id> --write` afterwards, which is also the step
that re-reads elevation from the DEM.
"""

from __future__ import annotations

import argparse
import json
import math
import os
import subprocess
import sys
import urllib.request

# Europe by default: every climb in the catalogue today is Alpine or Ardennes.
# Another continent's climbs need that continent's endpoint (web/.env
# ELEVATION_URLS lists them), passed with --valhalla.
VALHALLA = os.environ.get("VALHALLA_URL", "http://localhost:8002")
PSQL = ["docker", "exec", "cycling-commons-dev-db-1", "psql", "-U", "cc", "-d", "cyclingcommons", "-tAc"]
# The same psql, reading its statement on STDIN so that `-v name=value`
# bindings can be interpolated as `:'name'` (psql quotes and escapes those
# itself, so no caller builds a literal by hand). It has to be `-f -` and not
# `-c`: psql hands a `-c` string straight to the server without running its own
# parser over it, so `:'name'` would arrive verbatim and the server would
# answer `syntax error at or near ":"`. Note `docker exec -i`, without which
# the container gets no stdin and psql reads an empty script.
PSQL_STDIN = ["docker", "exec", "-i", "cycling-commons-dev-db-1", "psql", "-U", "cc", "-d", "cyclingcommons", "-tA"]


def _hav(a: tuple[float, float], b: tuple[float, float]) -> float:
    """Metres between two (lat, lng) points."""
    r = 6371000.0
    la1, lo1, la2, lo2 = map(math.radians, [a[0], a[1], b[0], b[1]])
    h = math.sin((la2 - la1) / 2) ** 2 + math.cos(la1) * math.cos(la2) * math.sin((lo2 - lo1) / 2) ** 2
    return 2 * r * math.asin(math.sqrt(h))


def _decode(encoded: str, precision: int = 6) -> list[list[float]]:
    """Valhalla's encoded polyline -> [[lat, lng], ...].

    Precision 6, not Google's 5: Valhalla encodes at 1e-6 and decoding at 1e-5
    puts the line in the wrong country, which is loud enough to catch but only
    if you are looking.
    """
    factor = float(10**precision)
    out: list[list[float]] = []
    index = lat = lng = 0
    while index < len(encoded):
        for target in ("lat", "lng"):
            shift = result = 0
            while True:
                byte = ord(encoded[index]) - 63
                index += 1
                result |= (byte & 0x1F) << shift
                shift += 5
                if byte < 0x20:
                    break
            delta = ~(result >> 1) if result & 1 else (result >> 1)
            if target == "lat":
                lat += delta
            else:
                lng += delta
        out.append([round(lat / factor, 6), round(lng / factor, 6)])
    return out


def route(a: tuple[float, float], b: tuple[float, float], base: str) -> list[list[float]]:
    """The bicycle-costed road line from a to b, as [[lat, lng], ...]."""
    body = json.dumps({
        "locations": [{"lat": a[0], "lon": a[1]}, {"lat": b[0], "lon": b[1]}],
        "costing": "bicycle",
        "directions_options": {"units": "kilometers"},
    }).encode()
    req = urllib.request.Request(base.rstrip("/") + "/route", data=body,
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=60) as fh:
        data = json.load(fh)
    shape: list[list[float]] = []
    for leg in data["trip"]["legs"]:
        part = _decode(leg["shape"])
        # Legs share their junction point; drop the repeat rather than storing
        # a zero-length segment the profiler would divide by.
        shape.extend(part[1:] if shape and part and part[0] == shape[-1] else part)
    return shape


def heights(points: list[list[float]], base: str) -> list[float | None]:
    body = json.dumps({"shape": [{"lat": p[0], "lon": p[1]} for p in points], "range": False}).encode()
    req = urllib.request.Request(base.rstrip("/") + "/height", data=body,
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=60) as fh:
        return json.load(fh)["height"]


def find_foot(summit: tuple[float, float], target_m: float, base: str,
              bearings: int = 24) -> list[dict]:
    """Candidate feet for a summit, found with the ROUTER rather than Overpass.

    `climb_foot.py` does this better - it walks a real road graph and knows a
    junction from a driveway - but it needs Overpass, which is a shared public
    service that answers 504 as often as it answers. This is the fallback that
    keeps the work moving: fire a route at a ring of points around the summit,
    keep the ones that come back on a road, and cut each returned line at the
    requested distance. The cut point is a real point on a real road because
    the router put it there.

    What it canNOT do is tell a valley road from a driveway, so every candidate
    is reported with its drop and gradient and a human picks. A candidate whose
    gradient is nothing like the published figure took the wrong road.
    """
    out: list[dict] = []
    seen: set[tuple[float, float]] = set()
    ring = target_m * 1.25
    for i in range(bearings):
        theta = 2 * math.pi * i / bearings
        # Degrees per metre, latitude-corrected so the ring is round on the
        # ground rather than an ellipse squashed by the longitude convergence.
        dlat = (ring * math.cos(theta)) / 111320.0
        dlng = (ring * math.sin(theta)) / (111320.0 * max(0.2, math.cos(math.radians(summit[0]))))
        try:
            line = route(summit, (summit[0] + dlat, summit[1] + dlng), base)
        except Exception:
            continue
        if len(line) < 2:
            continue
        cum = 0.0
        cut = None
        for j in range(len(line) - 1):
            cum += _hav((line[j][0], line[j][1]), (line[j + 1][0], line[j + 1][1]))
            if cum >= target_m:
                cut = j + 1
                break
        if cut is None:
            continue
        key = (round(line[cut][0], 4), round(line[cut][1], 4))
        if key in seen:
            continue
        seen.add(key)
        out.append({"foot": line[cut], "walked_m": cum, "line": line[:cut + 1]})

    ele = heights([c["foot"] for c in out] + [list(summit)], base)
    top = ele[-1]
    for c, e in zip(out, ele):
        c["foot_ele"] = e
        c["gain"] = None if e is None or top is None else top - e
        c["pct"] = None if c["gain"] is None or not c["walked_m"] else c["gain"] / c["walked_m"] * 100
    # Steepest first: a climb's own side is almost always the steeper of the
    # roads leaving a col, and a flat candidate is a valley road, not an ascent.
    out.sort(key=lambda c: -(c["pct"] or -99))
    return out


def stored(item_id: int) -> dict:
    sql = ("SELECT json_build_object('name', name, 'cc', country_code, "
           "'route', attributes->'route', 'length', attributes->>'length', "
           "'gain', attributes->>'gain', 'avg', attributes->>'avgGradient')::text "
           f"FROM item WHERE id = {item_id} AND letter = 'N'")
    out = subprocess.run(PSQL + [sql], capture_output=True, text=True, check=True).stdout.strip()
    if not out:
        sys.exit(f"No climb with id {item_id}.")
    return json.loads(out)


def length_of(line: list[list[float]]) -> float:
    return sum(_hav(tuple(line[i]), tuple(line[i + 1])) for i in range(len(line) - 1))


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--id", type=int, required=True, help="item id of the climb")
    ap.add_argument("--from", dest="foot", help="lat,lng of the foot (default: the stored line's first point)")
    ap.add_argument("--to", dest="summit", help="lat,lng of the summit (default: the stored line's last point)")
    ap.add_argument("--extend", action="store_true",
                    help="keep the stored line and route only from its last point to --to")
    ap.add_argument("--trim-crest-within-km", type=float, default=None, metavar="KM",
                    help="cut the stored line at its highest point inside the first KM "
                         "(the 'the line carries on past the top' defect)")
    ap.add_argument("--reverse", action="store_true",
                    help="route summit->foot and store the line flipped (for roads the "
                         "router only takes one way round)")
    ap.add_argument("--find-foot", type=float, default=None, metavar="KM",
                    help="report candidate feet KM down every road from the summit, and stop")
    ap.add_argument("--valhalla", default=VALHALLA, help=f"routing base url (default {VALHALLA})")
    ap.add_argument("--write", action="store_true", help="persist the line (default is a dry run)")
    args = ap.parse_args()

    item = stored(args.id)
    old = item["route"] or []

    if args.find_foot is not None:
        raw = args.summit or (f"{old[-1][0]},{old[-1][1]}" if old else None)
        if raw is None:
            sys.exit(f"{item['name']}: give --to (the summit) to search from.")
        lat, lng = (float(x) for x in raw.split(","))
        print(f"{item['name']} ({item['cc']}, id {args.id}) - candidate feet "
              f"{args.find_foot} km from {lat:.5f},{lng:.5f}:")
        for c in find_foot((lat, lng), args.find_foot * 1000, args.valhalla):
            pct = "  n/a" if c["pct"] is None else f"{c['pct']:5.1f}%"
            print(f"  {c['foot'][0]:.5f},{c['foot'][1]:.5f}  {c['foot_ele']} m  "
                  f"gain {c['gain']} m over {c['walked_m'] / 1000:.2f} km  {pct}")
        return 0

    def pick(raw: str | None, fallback: list[float] | None, what: str) -> tuple[float, float]:
        if raw:
            lat, lng = (float(x) for x in raw.split(","))
            return (lat, lng)
        if fallback is None:
            sys.exit(f"{item['name']}: no stored line, so --{what} is required.")
        return (float(fallback[0]), float(fallback[1]))

    foot = pick(args.foot, old[0] if old else None, "from")
    summit = pick(args.summit, old[-1] if old else None, "to")

    if args.trim_crest_within_km is not None:
        # A different defect from a line that stops short, and the opposite
        # repair: the line ran ON past the top, over a descent and up a second
        # rise, so the published figures for the climb match nothing in it.
        # Cutting at the highest point inside the window is deterministic - no
        # eyeballed index - and the printed gradient is the check on the window.
        if not old:
            sys.exit(f"{item['name']}: nothing stored to trim.")
        pts = [(float(p[0]), float(p[1])) for p in old]
        cum = [0.0]
        for i in range(len(pts) - 1):
            cum.append(cum[-1] + _hav(pts[i], pts[i + 1]))
        window = args.trim_crest_within_km * 1000
        inside = [i for i in range(len(pts)) if cum[i] <= window]
        if len(inside) < 2:
            sys.exit(f"{item['name']}: the window holds fewer than two points.")
        ele_all = heights([list(p) for p in pts], args.valhalla)
        crest = max(inside, key=lambda i: (ele_all[i] if ele_all[i] is not None else -9999))
        line = [list(p) for p in pts[:crest + 1]]
        print(f"  trimmed at the crest: point {crest} of {len(pts)}, {cum[crest]:.0f} m in, "
              f"{ele_all[crest]} m")
    elif args.extend:
        # Surgical repair, not a redraw. A whole-line reroute can legitimately
        # come back on a DIFFERENT road (Gotthard has the cobbled Tremola and
        # the modern road, both valid, 2 km apart in length), which silently
        # replaces a curator's choice of route with the router's. Extending
        # touches only the end that was wrong.
        if not old:
            sys.exit(f"{item['name']}: --extend needs a stored line to extend.")
        if not args.summit:
            sys.exit("--extend needs --to: it is the new end point.")
        tail = route((float(old[-1][0]), float(old[-1][1])), summit, args.valhalla)
        line = [[float(p[0]), float(p[1])] for p in old]
        line.extend(p for p in tail if p != line[-1])
    elif args.reverse:
        # Routing is not symmetric: one-way streets and turn restrictions can
        # make foot->summit 2.0 km of detour where summit->foot is the 1.5 km
        # road the climb actually is (Côte des Forges, 2026-08-16). The climb
        # is the road either way, and storage order is ours to choose, so ask
        # downhill and flip. Reported lengths make the difference visible.
        line = list(reversed(route(summit, foot, args.valhalla)))
    else:
        line = route(foot, summit, args.valhalla)
    if len(line) < 2:
        sys.exit(f"{item['name']}: the router returned no usable line.")

    ele = heights([line[0], line[-1]], args.valhalla)
    new_len = length_of(line)
    print(f"{item['name']} ({item['cc']}, id {args.id})")
    print(f"  foot   {line[0][0]:.5f},{line[0][1]:.5f}  {ele[0]} m")
    print(f"  summit {line[-1][0]:.5f},{line[-1][1]:.5f}  {ele[1]} m")
    if old:
        print(f"  points {len(old)} -> {len(line)}   length {length_of(old):.0f} m -> {new_len:.0f} m"
              f"   (stored says {item['length']})")
    else:
        print(f"  points {len(line)}   length {new_len:.0f} m   (stored says {item['length']})")
    if ele[0] is not None and ele[1] is not None:
        gain = ele[1] - ele[0]
        print(f"  drop   {gain} m over {new_len / 1000:.2f} km = {gain / new_len * 100:.1f}% average")
        if gain <= 0:
            print("  WARNING: this line descends. The foot and summit are probably the wrong way round.")

    if not args.write:
        print("  (dry run - pass --write to persist, then run app:climbs:recompute --id "
              f"{args.id} --write)")
        return 0

    # Bound, not interpolated. The old version doubled quotes into an f-string
    # by hand; it happened to be safe because the only input is a router
    # response, but hand-rolled escaping in a write path is one refactor away
    # from an injection and it was flagged as such (security scan 2026-08-25).
    # `:'route'` makes psql produce the literal, and the id is an int by
    # argparse so it needs no quoting.
    subprocess.run(
        PSQL_STDIN + ["-v", "route=" + json.dumps(line), "-f", "-"],
        input="UPDATE item SET attributes = jsonb_set(attributes, '{route}', :'route'::jsonb), "
              f"updated_at = NOW() WHERE id = {int(args.id)};\n",
        capture_output=True, text=True, check=True,
    )
    print(f"  written. Now: app:climbs:recompute --id {args.id} --write")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
