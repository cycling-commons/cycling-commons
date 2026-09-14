# SPDX-License-Identifier: AGPL-3.0-only
"""Every country's division boundaries, for the table that holds what we HAVE.

Not the onboarding exporter. `export_divisions.py` reads one country's
configured operating level and produces the geometry for `region` rows the
Commons will run. This walks the whole release and produces every candidate
boundary, onboarded or not, for `world_division`: the list a rider can ask
from, and the list onboarding promotes out of.

The split is the point. A boundary we hold can be acted on, because seeding it
is this pipeline and not somebody drawing a shape; an area we hold nothing for
is a different kind of request and must not appear in a picker that implies we
can go and add it.

**Memory is bounded, and it took three tries.** A query over the whole release
makes DuckDB read enormous geometry blocks before its first match, however the
rows are fetched: loading everything at once filled RAM and swap on a 21 GB
machine, and both `fetchmany()` and the streaming Arrow reader were killed by
the run's memory guard at 2 GB with nothing written (2026-09-14).

What works is never asking about the whole world with geometry in the query:

  1. one pass reads only each division's bounding box, no geometry, and folds
     them into a box per country (about 80 s, a few hundred MB);
  2. each country is then queried alone, inside its box. Overture stores a box
     per row, so DuckDB skips every block that cannot overlap: the Netherlands
     reads in 4 s and the United States in 12 s, at about 220 MB.

The box comes from the country's own divisions, not from its `country` row.
Thirty-one territories (Puerto Rico, Greenland, Réunion and others) have
divisions and no country row, and a box taken from country rows would skip
them without a word. "Overlaps the box" rather than "inside it", because a
division's own box can poke past a box built from rounded extents, and the
Netherlands lost a province that way in testing.

Each country is written to a temporary file and renamed only when complete, and
a country whose file already exists is skipped, so a killed run resumes where it
stopped instead of starting over or leaving half a province behind.

One line per feature (NDJSON) rather than a FeatureCollection, so the importer
can read a file line by line too: a country like Russia or Canada is tens of
megabytes of coordinates, and decoding that as one JSON document is the same
mistake again on the PHP side.

Usage:
    python -m tools.divisions.export_candidates --out web/var/divisions
"""

import argparse
import json
import pathlib
import re
import sys

from . import config
from .export_divisions import _connect, geodesic_area_km2

# The tier a person could curate. `county` is a level down and only matters for
# countries whose `region` tier is unusably fine; not fetched by default.
DEFAULT_SUBTYPES = ("region",)

# Degrees. About 55 m of latitude: invisible at the zooms a boundary is looked
# at, and it takes Wallonia from 47,000 points to a few thousand. This table
# answers "do we hold a boundary here, and how big is it"; the geometry a
# `region` row actually runs on still comes from export_divisions.py at full
# resolution when a place is onboarded, so nothing live is drawn from this.
SIMPLIFY_DEGREES = 0.0005

# Rows held in Python at once. Small, because one row can be a whole province's
# coastline.
BATCH = 50


def _boxes(con, path, subtypes, only):
    """{country: (xmin, ymin, xmax, ymax)} from the divisions' own boxes, no geometry read."""
    marks = ",".join("?" * len(subtypes))
    sql = f"""SELECT country, min(bbox.xmin), min(bbox.ymin), max(bbox.xmax), max(bbox.ymax)
              FROM read_parquet('{path}', hive_partitioning=1)
              WHERE subtype IN ({marks}) AND "class" = 'land'
                AND country IS NOT NULL AND names.primary IS NOT NULL"""
    params = list(subtypes)
    if only:
        sql += " AND country IN (" + ",".join("?" * len(only)) + ")"
        params += only
    sql += " GROUP BY country"
    return {cc: box for cc, *box in con.execute(sql, params).fetchall()}


def _export_country(con, path, cc, box, subtypes, release, target):
    """Write one country to <target>.part, then rename. Returns the feature count."""
    x0, y0, x1, y1 = box
    marks = ",".join("?" * len(subtypes))
    cur = con.execute(
        f"""SELECT region AS iso, names.primary AS name, subtype,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(geometry, {SIMPLIFY_DEGREES})) AS geojson
            FROM read_parquet('{path}', hive_partitioning=1)
            WHERE country = ? AND subtype IN ({marks}) AND "class" = 'land'
              AND names.primary IS NOT NULL
              AND bbox.xmax >= ? AND bbox.ymax >= ? AND bbox.xmin <= ? AND bbox.ymin <= ?""",
        [cc, *subtypes, x0, y0, x1, y1],
    )
    part = target.with_name(target.name + ".part")
    count = 0
    with part.open("w", encoding="utf-8") as fh:
        for batch in cur.to_arrow_reader(BATCH):
            cols = batch.to_pydict()
            for iso, name, subtype, geojson in zip(cols["iso"], cols["name"], cols["subtype"], cols["geojson"]):
                geom = json.loads(geojson)
                feature = {
                    "type": "Feature",
                    "properties": {
                        "iso": iso,
                        "name": name,
                        "subtype": subtype,
                        "release": release,
                        # Geodesic, not DuckDB's ST_Area_Spheroid, which mis-scales
                        # by about 1/cos(lat). From the simplified shape: at 55 m
                        # with topology preserved it moves well under one percent,
                        # against a calibration band thousands of km² wide.
                        "area_km2": round(geodesic_area_km2(geom), 1),
                    },
                    "geometry": geom,
                }
                fh.write(json.dumps(feature, ensure_ascii=False, separators=(",", ":")))
                fh.write("\n")
                count += 1
            del cols, batch
    part.replace(target)
    return count


def export(out_dir, subtypes=DEFAULT_SUBTYPES, release=None, con=None,
           memory_limit="1GB", threads=2, countries=None, force=False):
    """Write <out_dir>/<cc>.ndjson per country, one feature per line; return {cc: count}."""
    release = release or config.OVERTURE_RELEASE
    # Pasted into a SQL literal, so shaped before it gets there, the same
    # reasoning as config.division_area_path().
    if not re.fullmatch(r"\d{1,5}(MB|GB)", str(memory_limit)):
        raise ValueError(f"Not a memory limit: {memory_limit!r}. Expected e.g. 1GB or 512MB.")
    con = con or _connect()
    con.execute(f"SET memory_limit='{memory_limit}'; SET threads={int(threads)};")
    path = config.division_area_path(release)
    subtypes = list(subtypes)
    only = sorted({c.upper() for c in countries}) if countries else None

    out = pathlib.Path(out_dir)
    out.mkdir(parents=True, exist_ok=True)
    for leftover in out.glob("*.part"):
        leftover.unlink()

    boxes = _boxes(con, path, subtypes, only)
    print(f"{len(boxes)} countries with divisions", flush=True)

    counts = {}
    for i, cc in enumerate(sorted(boxes), 1):
        target = out / f"{cc.lower()}.ndjson"
        if target.exists() and not force:
            continue
        counts[cc] = _export_country(con, path, cc, boxes[cc], subtypes, release, target)
        print(f"[{i}/{len(boxes)}] {cc} {counts[cc]}", flush=True)
    return counts


def main(argv=None):
    ap = argparse.ArgumentParser(description="Export every country's division boundaries")
    ap.add_argument("--out", default="web/var/divisions", help="output dir (default web/var/divisions)")
    ap.add_argument("--subtypes", default=",".join(DEFAULT_SUBTYPES))
    ap.add_argument("--release", default=None, help=f"Overture release (default {config.OVERTURE_RELEASE})")
    ap.add_argument("--memory-limit", default="1GB", help="DuckDB memory ceiling (default 1GB)")
    ap.add_argument("--country", default=None, help="csv of ISO 3166-1 alpha-2 codes; default every country")
    ap.add_argument("--force", action="store_true", help="re-export countries whose file already exists")
    args = ap.parse_args(argv)

    counts = export(
        args.out,
        tuple(s.strip() for s in args.subtypes.split(",") if s.strip()),
        args.release,
        memory_limit=args.memory_limit,
        countries=[c.strip() for c in args.country.split(",") if c.strip()] if args.country else None,
        force=args.force,
    )
    print(f"Wrote {len(counts)} countries, {sum(counts.values())} boundaries to {args.out} (existing files skipped)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
