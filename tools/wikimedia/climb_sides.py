#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Every rideable side of a pass, found by descending each road to its valley.

`climb_candidates.py` ends at the col and says so: "Wikidata knows the col, not
where the climb begins." `climb_foot.py` closes that gap but needs a PUBLISHED
LENGTH to walk down, which is a second thing nobody has for 190 passes, and it
needs Overpass, which answers 504 about half the time.

This asks the question the other way round and needs neither. A pass climb is
not "N km of road" - it is **the road from the valley to the col**. So: leave
the col in every direction, follow each road down, and stop where the descending
stops. That point is the foot, its distance is the length, and because a col
has two or more roads leaving it, **you get every side without choosing one**.

    python3 tools/wikimedia/climb_sides.py --summit 46.5721,8.4142 --name "Furka"
    python3 tools/wikimedia/climb_sides.py --country CH --out out/climb-sides-ch.json

## Where the descent stops

Not at a fixed gradient - a col road often has a flat kilometre partway down and
then drops again, and cutting there would publish half a climb. The rule is:
keep going while the road is still LOSING height overall, and cut at the last
point before a sustained window (`--flat-km`, default 2 km) that loses less than
`--flat-pct`. A valley floor satisfies that and a mid-descent terrace does not.

**`--flat-pct` is a real trade and 2.5% is a chosen compromise, not a constant
of nature.** Measured on the Tourmalet, whose two sides are as well published as
any climb in the world: at 1% the east side runs 36.5 km down the Adour valley
(4.6%) because a valley road that still loses 1.5% never trips the test; at 3%
it stops at 14.1 km (8.3%) against a published 17.2 km at 7.4%, because a
genuinely climbing but flatter lower section trips it early. There is no
threshold that is right for every col, which is the honest reason this writes a
review file instead of importing.

## What it cannot do, and why every row still needs an eye

The router will happily leave a col down a farm track or a dead end, and this
has no way to know that a road is not the road. Each side is reported with its
length, drop and average gradient, and the artifact is a **review file, not an
import**: a 40 km "side" at 1.2% is a valley road, and a 900 m one at 14% is a
driveway. Both look like climbs to arithmetic.

Continent matters: pass `--valhalla` for anything outside Europe (web/.env
ELEVATION_URLS lists one endpoint per continent).
"""

from __future__ import annotations

import argparse
import io
import json
import math
import os
import pathlib
import sys
import time
import urllib.request

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from climb_line import _decode, _hav, heights  # noqa: E402

VALHALLA = os.environ.get("VALHALLA_URL", "http://localhost:8002")

# One endpoint per continent, mirroring web/.env ELEVATION_URLS. Kept here so a
# whole-country run picks its own router instead of asking the operator to
# remember which port holds which continent.
BY_COUNTRY = {
    "US": "http://localhost:8003", "CA": "http://localhost:8003",
    "JP": "http://localhost:8004", "AU": "http://localhost:8007",
    "NZ": "http://localhost:8007", "ZA": "http://localhost:8006",
    "RW": "http://localhost:8006", "CL": "http://localhost:8005",
    "CO": "http://localhost:8005",
}


def route(a, b, base):
    body = json.dumps({
        "locations": [{"lat": a[0], "lon": a[1]}, {"lat": b[0], "lon": b[1]}],
        "costing": "bicycle",
    }).encode()
    req = urllib.request.Request(base.rstrip("/") + "/route", data=body,
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=120) as fh:
        data = json.load(fh)
    shape = []
    for leg in data["trip"]["legs"]:
        part = _decode(leg["shape"])
        shape.extend(part[1:] if shape and part and part[0] == shape[-1] else part)
    return shape


def _cum(line):
    out = [0.0]
    for i in range(len(line) - 1):
        out.append(out[-1] + _hav(tuple(line[i]), tuple(line[i + 1])))
    return out


def descend(line, ele, cum, flat_km, flat_pct):
    """Index of the foot: the last point before the descending stops.

    Walks forward while the road is still losing height, and cuts at the start
    of the first sustained window that is effectively flat. Returns None when
    the road never descends at all, which is how an uphill or level direction
    out of a col reports itself rather than being trimmed to nothing.
    """
    window = flat_km * 1000
    best = None
    i = 0
    while i < len(line):
        if ele[i] is None:
            i += 1
            continue
        # Look ahead one window and ask whether it still goes down.
        j = i
        while j + 1 < len(line) and cum[j] - cum[i] < window:
            j += 1
        if j == i:
            break
        if ele[j] is None:
            i += 1
            continue
        span = cum[j] - cum[i]
        drop = ele[i] - ele[j]
        if span > 0 and (drop / span * 100) < flat_pct:
            break
        best = j
        i = j
    if best is None:
        return None
    # REFINE, or every foot lands on a multiple of the window. The coarse loop
    # advances a whole window at a time, so without this the artifact reports
    # climbs of 2.01, 4.00, 6.03, 8.04 km - a grid, not a measurement, and one
    # that is obvious in a column of numbers precisely because roads are not
    # like that. The real foot is the LOWEST point in the window the loop
    # stopped in: the valley floor it was heading for.
    end = best
    while end + 1 < len(line) and cum[end] - cum[best] < window:
        end += 1
    lowest = min(
        (k for k in range(best, end + 1) if ele[k] is not None),
        key=lambda k: ele[k],
        default=best,
    )
    return lowest


def sides(summit, base, bearings=16, radius_km=28.0, flat_km=2.0, flat_pct=2.5,
          min_km=1.0, min_pct=4.0):
    """Every descending road out of `summit`, as candidate climbs."""
    found = []
    fingerprints = set()
    for k in range(bearings):
        theta = 2 * math.pi * k / bearings
        m = radius_km * 1000
        dlat = (m * math.cos(theta)) / 111320.0
        dlng = (m * math.sin(theta)) / (111320.0 * max(0.2, math.cos(math.radians(summit[0]))))
        try:
            line = route(summit, (summit[0] + dlat, summit[1] + dlng), base)
        except Exception:
            continue
        if len(line) < 3:
            continue
        cum = _cum(line)
        # Two bearings often leave the col on the same road; fingerprint on the
        # point 400 m out so the same side is not reported four times.
        near = next((i for i in range(len(line)) if cum[i] >= 400), len(line) - 1)
        fp = (round(line[near][0], 3), round(line[near][1], 3))
        if fp in fingerprints:
            continue
        fingerprints.add(fp)
        try:
            ele = heights(line, base)
        except Exception:
            continue
        foot = descend(line, ele, cum, flat_km, flat_pct)
        if foot is None or foot < 2:
            continue
        length = cum[foot]
        top, bottom = ele[0], ele[foot]
        if top is None or bottom is None:
            continue
        gain = top - bottom
        if length < min_km * 1000 or gain <= 0:
            continue
        pct = gain / length * 100
        if pct < min_pct:
            continue
        found.append({
            "foot": [round(line[foot][0], 6), round(line[foot][1], 6)],
            "foot_ele": bottom,
            "summit_ele": top,
            "length_m": round(length),
            "gain_m": round(gain),
            "avg_pct": round(pct, 1),
            # Stored summit-first; the seed reverses it, and keeping the
            # router's own order here makes a side easy to re-check by hand.
            "line": [[round(p[0], 6), round(p[1], 6)] for p in line[:foot + 1]],
        })
    found.sort(key=lambda s: -s["length_m"])
    return found


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--summit", help="lat,lng of one col")
    ap.add_argument("--name", default="(unnamed)")
    ap.add_argument("--country", help="run every candidate from climb_candidates.py for this country")
    ap.add_argument("--limit", type=int, default=12, help="candidates per country (default 12)")
    ap.add_argument("--out", help="write the artifact here")
    ap.add_argument("--bearings", type=int, default=16)
    ap.add_argument("--radius-km", type=float, default=28.0)
    ap.add_argument("--flat-km", type=float, default=2.0)
    ap.add_argument("--flat-pct", type=float, default=2.5)
    ap.add_argument("--valhalla", default=None)
    args = ap.parse_args()

    if args.summit:
        base = args.valhalla or VALHALLA
        lat, lng = (float(x) for x in args.summit.split(","))
        for s in sides((lat, lng), base, args.bearings, args.radius_km, args.flat_km, args.flat_pct):
            print(f"  {args.name}: {s['length_m'] / 1000:5.2f} km  {s['gain_m']:5} m  "
                  f"{s['avg_pct']:5.1f}%   foot {s['foot'][0]:.5f},{s['foot'][1]:.5f} "
                  f"({s['foot_ele']} m -> {s['summit_ele']} m)")
        return 0

    if not args.country:
        ap.error("give --summit or --country")

    import climb_candidates
    cc = args.country.upper()
    base = args.valhalla or BY_COUNTRY.get(cc, VALHALLA)
    cands = climb_candidates.candidates(cc, args.limit)
    print(f"{cc}: {len(cands)} candidate col(s), routing through {base}", file=sys.stderr)

    out = []
    for c in cands:
        t0 = time.time()
        found = sides(tuple(c["summit"]), base, args.bearings, args.radius_km,
                      args.flat_km, args.flat_pct)
        print(f"  {c['name']}: {len(found)} side(s)  [{time.time() - t0:.0f}s]", file=sys.stderr)
        for s in found:
            print(f"     {s['length_m'] / 1000:5.2f} km {s['gain_m']:5} m {s['avg_pct']:5.1f}%"
                  f"  foot {s['foot'][0]:.5f},{s['foot'][1]:.5f}", file=sys.stderr)
        out.append({**{k: v for k, v in c.items()}, "sides": found})

    payload = {cc: out}
    if args.out:
        path = pathlib.Path(args.out)
        path.parent.mkdir(parents=True, exist_ok=True)
        with io.open(path, "w", encoding="utf-8") as fh:
            json.dump(payload, fh, ensure_ascii=False, indent=2)
        print(f"wrote {path}", file=sys.stderr)
    else:
        json.dump(payload, sys.stdout, ensure_ascii=False, indent=2)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
