# SPDX-License-Identifier: AGPL-3.0-only
"""Probe Overture division_area for a country's candidate operating levels.

Onboarding step 1 (tools/divisions/README.md): BEFORE seeding, report
each candidate subtype's subdivisions and geodesic areas against the ~17k km²
calibration band (ADVISORY — map-and-search.md §4.5; Brussels sits far
below it deliberately), and derive a bbox for COUNTRY_CONFIG predicate
pushdown. Emits, per country:

    <out>/<cc-lower>/areas.md    human report: per-subtype tables + level advice
    <out>/<cc-lower>/probe.json  machine output for `app:region:scaffold --probe-areas`

Network: hits the public Overture S3 bucket anonymously, like
export_divisions. The default --out lands inside web/var/scaffold/ so the
PHP scaffolder (app container, /app = web/) can read probe.json.

Usage:
    cd tools && python3 -m divisions.probe_areas --country NL
"""
import argparse
import json
import pathlib

from . import config
from .export_divisions import _connect, geodesic_area_km2

# KEEP 80-150 % of Wallonia's ~16.9k km² (map-and-search.md §4.5) — advisory.
BAND_KM2 = (13_520, 25_350)
BBOX_PAD_DEG = 0.1


def geom_bounds(geom):
    """(xmin, ymin, xmax, ymax) of a GeoJSON Polygon/MultiPolygon."""
    polys = geom["coordinates"] if geom["type"] == "MultiPolygon" else [geom["coordinates"]]
    xs, ys = [], []
    for poly in polys:
        for ring in poly:
            xs += [c[0] for c in ring]
            ys += [c[1] for c in ring]
    return min(xs), min(ys), max(xs), max(ys)


def bbox_union(bounds_list, pad=BBOX_PAD_DEG):
    """Padded [xmin, ymin, xmax, ymax] over per-geometry bounds, 2 decimals."""
    xmin = min(b[0] for b in bounds_list) - pad
    ymin = min(b[1] for b in bounds_list) - pad
    xmax = max(b[2] for b in bounds_list) + pad
    ymax = max(b[3] for b in bounds_list) + pad
    return [round(v, 2) for v in (xmin, ymin, xmax, ymax)]


def candidate_report(cc, per_subtype):
    """areas.md content: one table per probed subtype + the level-choice rule."""
    lines = [
        f"# {cc} — Overture subdivision area probe",
        "",
        f"Calibration band (ADVISORY): {BAND_KM2[0]:,}–{BAND_KM2[1]:,} km² "
        "(map-and-search.md §4.5; Brussels deliberately sits far below it).",
        "",
    ]
    for subtype, rows in per_subtype.items():
        lines += [f"## subtype={subtype} — {len(rows)} land subdivision(s)", "",
                  "| ISO | name | km² | vs band |", "|---|---|---:|---|"]
        for r in sorted(rows, key=lambda r: (r["iso"] or "", r["name"])):
            a = r["area_km2"]
            verdict = ("in band" if BAND_KM2[0] <= a <= BAND_KM2[1]
                       else "below" if a < BAND_KM2[0] else "above")
            lines.append(f"| {r['iso'] or '—'} | {r['name']} | {a:,} | {verdict} |")
        lines.append("")
    lines += [
        "Choose the OFFICIAL level whose subdivisions are of reasonable riding size,",
        "preferring legibility + stable ISO identity over an exact band match. Small",
        "official regions are fine — moderation composes upward, one moderator holds",
        "2–4 atoms. Group into synthetic macro-regions ONLY when no official level",
        "fits (tools/divisions/README.md).",
    ]
    return "\n".join(lines) + "\n"


def probe_country(cc, out_base, subtypes=("region",), release=None, con=None):
    """Query Overture per subtype; write areas.md + probe.json; return the out dir."""
    release = release or config.OVERTURE_RELEASE
    con = con or _connect()
    out_dir = pathlib.Path(out_base) / cc.lower()
    out_dir.mkdir(parents=True, exist_ok=True)
    path = config.division_area_path(release)
    per_subtype = {}
    probe = {"country": cc, "release": release, "subtypes": {}}
    for subtype in subtypes:
        rows = con.execute(
            f"""SELECT region AS iso, names.primary AS name,
                       ST_AsGeoJSON(geometry) AS geojson
                FROM read_parquet('{path}', hive_partitioning=1)
                WHERE country = ? AND subtype = ? AND "class" = ?""",
            [cc, subtype, "land"],
        ).fetchall()
        if not rows:
            print(f"  {subtype}: no land rows for {cc}")
            continue
        entries, bounds = [], []
        for iso, name, geojson in rows:
            geom = json.loads(geojson)
            entries.append({"iso": iso, "name": name,
                            "area_km2": round(geodesic_area_km2(geom))})
            bounds.append(geom_bounds(geom))
        entries.sort(key=lambda r: (r["iso"] or "", r["name"]))
        per_subtype[subtype] = entries
        probe["subtypes"][subtype] = {"bbox": bbox_union(bounds), "rows": entries}
    (out_dir / "areas.md").write_text(candidate_report(cc, per_subtype), encoding="utf-8")
    (out_dir / "probe.json").write_text(
        json.dumps(probe, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"Wrote {out_dir}/areas.md and {out_dir}/probe.json")
    return out_dir


def main(argv=None):
    ap = argparse.ArgumentParser(description="Probe Overture subdivision areas for onboarding")
    ap.add_argument("--country", required=True, help="ISO 3166-1 alpha-2 (e.g. NL)")
    ap.add_argument("--subtypes", default="region",
                    help="csv of Overture subtypes to probe (default: region)")
    ap.add_argument("--out", default="../web/var/scaffold",
                    help="base output dir (default ../web/var/scaffold, relative to tools/)")
    ap.add_argument("--release", default=None, help="Overture release (default config.OVERTURE_RELEASE)")
    args = ap.parse_args(argv)
    probe_country(args.country.upper(), args.out,
                  tuple(s.strip() for s in args.subtypes.split(",") if s.strip()),
                  args.release)


if __name__ == "__main__":
    main()
