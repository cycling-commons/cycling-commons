#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Print a stored climb's elevation profile, to see WHERE a bad segment is.

`app:climbs:recompute` reports that a climb has a -12% segment. It does not say
whether that dip is at 200 m or at 4 km, and the two mean opposite things: a dip
in the middle is a real feature of the road, while a long descent at the END is
the signature of a line drawn over the top and down the far side.

    python3 tools/wikimedia/climb_profile_probe.py --id 11003
"""

from __future__ import annotations

import argparse
import json
import math
import os
import subprocess
import sys
import urllib.request

VALHALLA = os.environ.get("VALHALLA_URL", "http://localhost:8002")
PSQL = ["docker", "exec", "cycling-commons-dev-db-1", "psql", "-U", "cc", "-d", "cyclingcommons", "-tAc"]


def _hav(a, b):
    r = 6371000.0
    la1, lo1, la2, lo2 = map(math.radians, [a[0], a[1], b[0], b[1]])
    h = math.sin((la2 - la1) / 2) ** 2 + math.cos(la1) * math.cos(la2) * math.sin((lo2 - lo1) / 2) ** 2
    return 2 * r * math.asin(math.sqrt(h))


def heights(points, base):
    """Valhalla caps a /height shape, so ask in chunks and stitch."""
    out = []
    for i in range(0, len(points), 400):
        chunk = points[i:i + 400]
        body = json.dumps({"shape": [{"lat": p[0], "lon": p[1]} for p in chunk], "range": False}).encode()
        req = urllib.request.Request(base.rstrip("/") + "/height", data=body,
                                     headers={"Content-Type": "application/json"})
        with urllib.request.urlopen(req, timeout=60) as fh:
            out.extend(json.load(fh)["height"])
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--id", type=int, required=True)
    ap.add_argument("--bins", type=int, default=12, help="how many segments to report (default 12)")
    ap.add_argument("--valhalla", default=VALHALLA)
    args = ap.parse_args()

    sql = (f"SELECT json_build_object('name', name, 'route', attributes->'route')::text "
           f"FROM item WHERE id = {args.id} AND letter = 'B'")
    out = subprocess.run(PSQL + [sql], capture_output=True, text=True, check=True).stdout.strip()
    item = json.loads(out)
    line = item["route"] or []
    if len(line) < 2:
        sys.exit(f"{item['name']}: no line to profile.")

    ele = heights(line, args.valhalla)
    cum = [0.0]
    for i in range(len(line) - 1):
        cum.append(cum[-1] + _hav(line[i], line[i + 1]))
    total = cum[-1]

    print(f"{item['name']} (id {args.id})  {len(line)} points  {total:.0f} m")
    print(f"  start {ele[0]} m   end {ele[-1]} m   net {(ele[-1] or 0) - (ele[0] or 0):+.0f} m")
    step = total / args.bins
    print(f"  {args.bins} bins of {step:.0f} m:")
    hi_at, hi = 0.0, ele[0] or -9999
    for b in range(args.bins):
        lo_d, hi_d = b * step, (b + 1) * step
        i0 = min(range(len(cum)), key=lambda i: abs(cum[i] - lo_d))
        i1 = min(range(len(cum)), key=lambda i: abs(cum[i] - hi_d))
        if ele[i0] is None or ele[i1] is None or i1 == i0:
            continue
        d = cum[i1] - cum[i0]
        g = ele[i1] - ele[i0]
        bar = "#" * max(0, min(20, int(round(abs(g / d * 100) * 2))))
        print(f"   {lo_d / 1000:5.2f}-{hi_d / 1000:5.2f} km  {ele[i0]:5.0f}->{ele[i1]:5.0f} m"
              f"  {g / d * 100:+6.1f}%  {bar}")
    for i, e in enumerate(ele):
        if e is not None and e > hi:
            hi, hi_at = e, cum[i]
    print(f"  highest point {hi} m at {hi_at / 1000:.2f} km"
          + ("  <- BEFORE the end: the line goes over the top and down" if total - hi_at > 150 else ""))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
