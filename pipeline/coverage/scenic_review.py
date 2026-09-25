# SPDX-License-Identifier: AGPL-3.0-only
"""How far each catalog scenic item is from a bike way (docs/specs/scenic-views.md).

The coverage load applies the contract's P `nearWay` rule to OSM points. Catalog
items (Wikidata harvests, materialized OSM points, rider additions) never pass
through that load, so this measures them against the same rule and the same
extracts. It only measures: `app:scenic:bikeway-review --apply` decides what to
retire, because what an item is and who touched it are catalog facts.

    php bin/console app:scenic:bikeway-review --export /tmp/scenic-items.json
    python -m coverage.scenic_review --items /tmp/scenic-items.json --out /tmp/scenic-review.json
    php bin/console app:scenic:bikeway-review --apply /tmp/scenic-review.json

Reads the `<region>-latest.osm.pbf` extracts in `COVERAGE_PBF_DIR` (the work
directory unless set) and builds each region's `<region>-bikeways.osm.pbf`
with the coverage run's own filter when it is missing.
"""

from __future__ import annotations

import argparse
import json
import math
import os
import pathlib
import subprocess
import sys
import tempfile
from collections.abc import Callable, Iterable

from .contract import load_contract
from .extract import rideable_lines, run_way_filter
from .pbfs import pbf_dir

EARTH_R = 6371000.0


def nearest_m(point: tuple[float, float], lines: Iterable[list[tuple[float, float]]]) -> float | None:
    """Metres from a (lon, lat) point to the nearest segment of any line, or None without lines."""
    k = math.pi / 180 * EARTH_R
    c = math.cos(math.radians(point[1]))
    best: float | None = None
    for line in lines:
        for a, b in zip(line, line[1:]):
            ax, ay = (a[0] - point[0]) * k * c, (a[1] - point[1]) * k
            bx, by = (b[0] - point[0]) * k * c, (b[1] - point[1]) * k
            dx, dy = bx - ax, by - ay
            length2 = dx * dx + dy * dy
            t = 0.0 if length2 == 0 else max(0.0, min(1.0, -(ax * dx + ay * dy) / length2))
            d = math.hypot(ax + t * dx, ay + t * dy)
            if best is None or d < best:
                best = d
    return best


def search_box(point: tuple[float, float], within_m: float) -> tuple[float, float, float, float]:
    """(west, south, east, north) reaching 1.5x the rule's range from a (lon, lat) point."""
    reach = within_m * 1.5
    dlat = reach / 111320.0
    dlon = reach / (111320.0 * max(0.2, math.cos(math.radians(point[1]))))
    return (point[0] - dlon, point[1] - dlat, point[0] + dlon, point[1] + dlat)


def _overlaps(a, b) -> bool:
    return a[0] <= b[2] and b[0] <= a[2] and a[1] <= b[3] and b[1] <= a[3]


def measure(items: list[dict], extracts: list[tuple[str, tuple[float, float, float, float]]],
            lines_for: Callable[[str, tuple], list[list[tuple[float, float]]]], within_m: float) -> list[dict]:
    """One row per item: whether any extract covers it, and its nearest bike way in metres."""
    out = []
    for item in items:
        point = (float(item["lng"]), float(item["lat"]))
        box = search_box(point, within_m)
        names = [name for name, extent in extracts if _overlaps(extent, box)]
        lines: list[list[tuple[float, float]]] = []
        for name in names:
            lines += lines_for(name, box)
        d = nearest_m(point, lines) if names else None
        out.append({"id": item["id"], "covered": bool(names), "nearest_bikeway_m": None if d is None else round(d)})
    return out


def local_extracts(workdir: pathlib.Path) -> list[tuple[str, tuple[float, float, float, float]]]:
    out = []
    for pbf in sorted(workdir.glob("*-latest.osm.pbf")):
        box = subprocess.run(["osmium", "fileinfo", "-g", "header.boxes", str(pbf)],
                             capture_output=True, text=True, check=True).stdout.strip().strip("()")
        out.append((pbf.name.removesuffix("-latest.osm.pbf"), tuple(float(v) for v in box.split(","))))
    return out


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description="Measure catalog scenic items against the P nearWay rule")
    ap.add_argument("--items", required=True, help="JSON list of {id, lat, lng} from app:scenic:bikeway-review --export")
    ap.add_argument("--out", required=True)
    args = ap.parse_args(argv)

    rule = load_contract().letters["P"].near_way
    if rule is None:
        print("[scenic-review] the contract's P letter carries no nearWay rule", file=sys.stderr)
        return 2
    workdir = pathlib.Path(os.environ.get("COVERAGE_WORKDIR", "/data/work"))
    pbfs = pbf_dir(workdir)
    items = json.loads(pathlib.Path(args.items).read_text(encoding="utf-8"))

    def lines_for(name: str, box: tuple) -> list[list[tuple[float, float]]]:
        ways = workdir / f"{name}-bikeways.osm.pbf"
        if not ways.exists():
            print(f"[scenic-review] building {ways.name}", file=sys.stderr, flush=True)
            run_way_filter(pbfs / f"{name}-latest.osm.pbf", ways, rule)
        with tempfile.NamedTemporaryFile(suffix=".osm.pbf") as cut:
            subprocess.run(["osmium", "extract", "--overwrite", "-s", "smart", "-b",
                            ",".join(f"{v:.6f}" for v in box), str(ways), "-o", cut.name],
                           check=True, capture_output=True)
            text = subprocess.run(["osmium", "export", cut.name, "-f", "geojsonseq",
                                   "--geometry-types=linestring", "-o", "-"],
                                  check=True, capture_output=True, text=True).stdout
        return list(rideable_lines(text.splitlines(), rule))

    rows = measure(items, local_extracts(pbfs), lines_for, rule.within_m)
    pathlib.Path(args.out).write_text(json.dumps({"withinM": rule.within_m, "items": rows}, indent=1) + "\n",
                                      encoding="utf-8")
    near = sum(1 for r in rows if r["nearest_bikeway_m"] is not None and r["nearest_bikeway_m"] <= rule.within_m)
    print(f"[scenic-review] {len(rows)} items: {near} within {rule.within_m:.0f} m, "
          f"{sum(1 for r in rows if not r['covered'])} outside every extract -> {args.out}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
