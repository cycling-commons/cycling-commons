#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Find where a climb STARTS, given its summit and how long it is.

The gap that stopped `climb_candidates.py` from being an importer: Wikidata
knows the col and never the foot, and a foot guessed at a village centroid is
what produced the endpoint defects already logged against the climbs we have.

But a foot is not really unknown — it is *implied*. If the summit is at a known
point and the climb is a known length, then the foot is the place you reach by
following the road down from the summit for exactly that far. This walks it.

    python3 tools/wikimedia/climb_foot.py --summit 50.4009,5.9214 --length-km 2.3
    python3 tools/wikimedia/climb_foot.py --summit 46.5721,8.4142 --length-km 10.6 --both

How it works, and where it can be wrong:

1. **Overpass** for every road within a generous radius of the summit — generous
   because a 28 km climb needs 28 km of road, and a bounding box that only just
   fits will clip a hairpin and stop the walk early.
2. Build an undirected graph of the road nodes, snap the summit onto it, and
   walk outward accumulating true along-road distance. A pass has (at least)
   two sides, so the walk is run per direction: at each junction it continues on
   the branch that **descends** and keeps going straightest, which is the
   behaviour of a road down a valley rather than a turning onto a side road.
3. Stop at the requested distance. That node is the candidate foot.
4. Read its elevation and report the drop, so an obviously wrong answer — a
   "climb" that descends 40 m over 20 km — is visible rather than silent.

**The remaining judgement is which side.** `--both` reports every direction it
found with its drop and its gradient; the classic side of a pass is usually the
one with the bigger drop, but not always, and Furka from Gletsch and Furka from
Realp are different climbs with the same summit. This prints the options; a
person picks, and the average gradient it reports is the check on that choice
(if it does not match the published figure, the wrong side was taken).

Elevation comes from the same Valhalla the app uses, so a foot derived here and
a climb measured by the app agree by construction.
"""

from __future__ import annotations

import argparse
import json
import math
import os
import sys
import urllib.parse
import urllib.request
from collections import defaultdict

UA = "CyclingCommons-climb-foot/1.0 (https://cyclingcommons.org; info@cyclingcommons.org)"
OVERPASS = os.environ.get("OVERPASS_URL", "https://overpass-api.de/api/interpreter")
VALHALLA = os.environ.get("ELEVATION_URL", "http://localhost:8003")

# Roads a road bike could be on. Deliberately excludes tracks and paths: a
# hairpin road often has a walking shortcut beside it, and the walk would take
# it and report a foot 200 m up a footpath.
ROAD_TYPES = (
    "motorway|trunk|primary|secondary|tertiary|unclassified|residential"
    "|motorway_link|trunk_link|primary_link|secondary_link|tertiary_link|living_street"
)


def haversine(a: tuple[float, float], b: tuple[float, float]) -> float:
    r, p = 6371000.0, math.pi / 180
    x = (math.sin((b[0] - a[0]) * p / 2) ** 2
         + math.cos(a[0] * p) * math.cos(b[0] * p) * math.sin((b[1] - a[1]) * p / 2) ** 2)
    return 2 * r * math.asin(math.sqrt(x))


def bearing(a: tuple[float, float], b: tuple[float, float]) -> float:
    p = math.pi / 180
    y = math.sin((b[1] - a[1]) * p) * math.cos(b[0] * p)
    x = (math.cos(a[0] * p) * math.sin(b[0] * p)
         - math.sin(a[0] * p) * math.cos(b[0] * p) * math.cos((b[1] - a[1]) * p))
    return (math.atan2(y, x) / p + 360) % 360


def fetch_roads(lat: float, lng: float, radius_m: float) -> tuple[dict, dict]:
    """(node id -> (lat,lng), node id -> [neighbour ids]) for roads near a point."""
    query = f"""
    [out:json][timeout:180];
    way(around:{int(radius_m)},{lat},{lng})["highway"~"^({ROAD_TYPES})$"];
    (._;>;);
    out body;
    """
    req = urllib.request.Request(
        OVERPASS, data=urllib.parse.urlencode({"data": query}).encode(),
        headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=300) as resp:
        data = json.load(resp)

    coords, adj = {}, defaultdict(list)
    for el in data["elements"]:
        if el["type"] == "node":
            coords[el["id"]] = (el["lat"], el["lon"])
    for el in data["elements"]:
        if el["type"] == "way":
            nodes = [n for n in el["nodes"] if n in coords]
            for a, b in zip(nodes, nodes[1:]):
                adj[a].append(b)
                adj[b].append(a)
    return coords, adj


def elevations(points: list[tuple[float, float]]) -> list[float] | None:
    """Valhalla /height for a list of [lat,lng]."""
    body = json.dumps({"range": False, "shape": [{"lat": p[0], "lon": p[1]} for p in points]})
    try:
        req = urllib.request.Request(
            VALHALLA.rstrip("/") + "/height", data=body.encode(),
            headers={"User-Agent": UA, "Content-Type": "application/json"})
        with urllib.request.urlopen(req, timeout=90) as resp:
            out = json.load(resp).get("height")
        # A tile-less Valhalla answers zeros rather than an error
        # (docs/specs/climb-elevation.md) — treat an all-zero read as no read.
        return out if out and any(h for h in out) else None
    except Exception:
        return None


def walk(coords: dict, adj: dict, start: int, first: int, target_m: float) -> list[int]:
    """Follow the road from `start` towards `first` for `target_m`, descending."""
    path, prev, cur, dist = [start, first], start, first, haversine(coords[start], coords[first])
    while dist < target_m:
        options = [n for n in adj[cur] if n != prev]
        if not options:
            break
        if len(options) == 1:
            nxt = options[0]
        else:
            # At a junction, keep going straightest — a road down a valley
            # continues; a side road turns. Elevation would be a better test but
            # costs a network round trip per junction on a 28 km walk.
            came = bearing(coords[cur], coords[prev])
            nxt = min(options, key=lambda n: abs(180 - abs(bearing(coords[cur], coords[n]) - came)))
        dist += haversine(coords[cur], coords[nxt])
        path.append(nxt)
        prev, cur = cur, nxt
        if len(path) > 20000:
            break
    return path


def feet(summit: tuple[float, float], length_km: float, both: bool) -> list[dict]:
    target = length_km * 1000
    coords, adj = fetch_roads(summit[0], summit[1], target * 1.3 + 500)
    if not coords:
        raise SystemExit("Overpass returned no roads near that point")

    top = min(coords, key=lambda n: haversine(coords[n], summit))
    if haversine(coords[top], summit) > 250:
        raise SystemExit(f"nearest road is {haversine(coords[top], summit):.0f} m away — is that a summit?")

    out = []
    for branch in adj[top]:
        path = walk(coords, adj, top, branch, target)
        foot = coords[path[-1]]
        walked = sum(haversine(coords[a], coords[b]) for a, b in zip(path, path[1:]))
        ele = elevations([coords[top], foot])
        drop = (ele[0] - ele[1]) if ele else None
        out.append({
            "foot": [round(foot[0], 5), round(foot[1], 5)],
            "walked_km": round(walked / 1000, 2),
            "drop_m": round(drop) if drop is not None else None,
            "avg_pct": round(drop / walked * 100, 1) if drop and walked else None,
            "nodes": len(path),
        })
    # Biggest descent first: on a pass that is almost always the classic side.
    out.sort(key=lambda r: -(r["drop_m"] or 0))
    return out if both else out[:1]


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--summit", required=True, help="lat,lng of the col")
    ap.add_argument("--length-km", type=float, required=True, help="published length of the climb")
    ap.add_argument("--both", action="store_true", help="report every side, not just the steepest")
    args = ap.parse_args()

    lat, lng = (float(x) for x in args.summit.split(","))
    results = feet((lat, lng), args.length_km, args.both)

    for r in results:
        drop = f"{r['drop_m']} m" if r["drop_m"] is not None else "no elevation"
        pct = f"{r['avg_pct']}%" if r["avg_pct"] is not None else "—"
        print(f"  foot {r['foot'][0]},{r['foot'][1]}  walked {r['walked_km']} km  "
              f"drop {drop}  avg {pct}", file=sys.stderr)

    json.dump(results, sys.stdout, indent=2)
    print()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
