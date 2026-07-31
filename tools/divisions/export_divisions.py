#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
r"""Export official administrative subdivisions from Overture Maps `division_area`
as region-<slug>.geojson artifacts for App\Catalog\Command\ImportCatalogCommand.

Worldwide-ready region export (map-and-search.md §4.5 Phase 2). A country
is seeded at ONE operating level (Belgium: subtype=region -> ISO 3166-2
BE-WAL/BE-VLG/BE-BRU). Provenance: source="overture" (Overture divisions theme,
ODbL — conflates OSM + geoBoundaries, carries ISO 3166-1/-2 and a normalised
per-country admin level).

Each artifact carries the props the importer REQUIRES (country_code) plus
iso_code / admin_level / source — an unstamped region is a silent
moderation-jurisdiction hole (map-and-search.md §4.5 risk 1).

Requires duckdb with httpfs + spatial (see requirements.txt). Regeneration hits
the public Overture S3 bucket anonymously — a network step, like the OSM harvest.

Usage:
    cd tools && python3 -m divisions.export_divisions --country BE --out divisions/out
"""
import argparse
import json
import pathlib

from pyproj import Geod

from . import config

OUT_DEFAULT = pathlib.Path(__file__).resolve().parent / "out"

# Geodesic area on the WGS84 ellipsoid — worldwide-exact and axis-safe. DuckDB's
# ST_Area_Spheroid over-estimated by ~1/cos(lat) here (Wallonia read 26,161 km²
# vs the true 16,901), so area is computed in Python from the lon/lat rings.
_GEOD = Geod(ellps="WGS84")


def _ring_area_m2(ring):
    lons = [c[0] for c in ring]
    lats = [c[1] for c in ring]
    area, _perim = _GEOD.polygon_area_perimeter(lons, lats)
    return abs(area)


def geodesic_area_km2(geom):
    """True geographic area (km²) of a GeoJSON Polygon/MultiPolygon, holes subtracted."""
    polys = geom["coordinates"] if geom["type"] == "MultiPolygon" else [geom["coordinates"]]
    total = 0.0
    for poly in polys:
        if not poly:
            continue
        total += _ring_area_m2(poly[0])          # outer ring
        for hole in poly[1:]:
            total -= _ring_area_m2(hole)          # interior rings
    return total / 1e6


def build_feature(iso, cc, geom_geojson, area_km2, cfg):
    """Assemble one region Feature with the importer's required provenance props.

    geom_geojson : a parsed GeoJSON geometry dict (Polygon or MultiPolygon).
                   Brussels arrives as a Polygon; the geometry column stores
                   MultiPolygon, so promote it (matches the OSM path's
                   normalisation in tools/wallonia/export.py).
    """
    geom = geom_geojson
    if geom.get("type") == "Polygon":
        geom = {"type": "MultiPolygon", "coordinates": [geom["coordinates"]]}
    return {
        "type": "Feature",
        "properties": {
            "slug": cfg["slugs"][iso],
            "name": cfg["names"][iso],
            "area_km2": round(float(area_km2)),
            "country_code": cc,
            "iso_code": iso,
            "admin_level": config.SUBTYPE_ADMIN_LEVEL[cfg["subtype"]],
            "source": "overture",
        },
        "geometry": geom,
    }


def _connect():
    import duckdb

    con = duckdb.connect()
    con.execute("INSTALL httpfs; LOAD httpfs; INSTALL spatial; LOAD spatial;")
    con.execute("SET s3_region='us-west-2';")
    # public bucket -> anonymous / unsigned access
    con.execute("SET s3_access_key_id=''; SET s3_secret_access_key='';")
    return con


def build_where(cc, cfg):
    """WHERE clauses + params selecting a country's operating-level LAND areas.

    class='land' (07-20 review finding 4): division_area carries a maritime
    twin row (territorial waters) for coastal divisions; without the filter a
    coastal country's export could silently ship a sea polygon as the region
    and corrupt membership stamping. Belgium happened to be single-row; the
    first NL/FR/DK seeding would not be.

    bbox: a true OVERLAP test — the old min-corner containment
    (bbox.xmin BETWEEN …) dropped any region whose min corner fell outside the
    configured box. Same pushdown benefit; the `missing` guard in
    export_country still makes a too-small config box fail loud.
    """
    where = ["country = ?", "subtype = ?", '"class" = ?']
    params = [cc, cfg["subtype"], "land"]
    if cfg.get("bbox"):  # predicate pushdown for fast reads
        xmin, ymin, xmax, ymax = cfg["bbox"]
        where += ["bbox.xmin <= ?", "bbox.xmax >= ?", "bbox.ymin <= ?", "bbox.ymax >= ?"]
        params += [xmax, xmin, ymax, ymin]
    return where, params


def query_country(con, cc, cfg, release):
    """Return [(iso, geojson_str), ...] for a country's operating-level regions.

    Geometry only — area is computed geodesically in Python (see geodesic_area_km2).
    """
    path = config.OVERTURE_DIVISION_AREA.format(release=release)
    where, params = build_where(cc, cfg)
    # COALESCE(region, country): a subtype='region' land row carries its ISO 3166-2
    # code in `region`; a subtype='country' row (a whole-country operating level,
    # e.g. Luxembourg) has region=NULL, so we key it on the ISO 3166-1 `country`
    # code instead. Only the country path is affected — `region` is never NULL for
    # a subtype='region' land row.
    sql = f"""
        SELECT COALESCE(region, country) AS iso,
               ST_AsGeoJSON(geometry) AS geojson
        FROM read_parquet('{path}', hive_partitioning=1)
        WHERE {' AND '.join(where)}
    """
    return con.execute(sql, params).fetchall()


def l2_cfg(cc):
    """A synthetic COUNTRY_CONFIG block selecting a country's level-2 outline.

    Reuses query_country/build_feature wholesale: subtype='country' rows carry
    region=NULL, so the slug map is keyed on the ISO 3166-1 code (the LU
    precedent), and SUBTYPE_ADMIN_LEVEL stamps admin_level=2.
    """
    if cc not in config.COUNTRY_L2:
        raise SystemExit(
            f"No COUNTRY_L2 entry for {cc} — every onboardable country needs "
            "a level-2 slug/name."
        )
    slug, name = config.COUNTRY_L2[cc]
    return {
        "subtype": "country",
        "slugs": {cc: slug},
        "names": {cc: name},
        "bbox": config.COUNTRY_CONFIG[cc].get("bbox"),
    }


def export_country(cc, out_dir, release=None, con=None):
    """Query Overture for `cc`'s operating-level regions and write region-<slug>.geojson."""
    release = release or config.OVERTURE_RELEASE
    cfg = config.COUNTRY_CONFIG.get(cc)
    if cfg is None:
        raise SystemExit(
            f"No COUNTRY_CONFIG for {cc} — add its operating level + ISO->slug map "
            "(map-and-search.md §4.5a)."
        )
    con = con or _connect()
    out_dir = pathlib.Path(out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    # Always emit the level-2 country outline alongside the operating level
    # (2+4 default). A country
    # operating AT level 2 (LU) already emits it as its operating level.
    configs = [cfg]
    if cfg["subtype"] != "country":
        configs.append(l2_cfg(cc))
    written = []
    for c in configs:
        seen = set()
        for iso, geojson in query_country(con, cc, c, release):
            if iso not in c["slugs"]:
                # A subtype='region' code Overture carries but we have not chosen to
                # seed (worldwide guard: seed only configured regions).
                print(f"  skip {iso}: in Overture but not in {cc} config")
                continue
            if iso in seen:
                # Never last-wins-overwrite a written artifact (07-20 review
                # finding 4): >1 land row per ISO means release/schema drift or a
                # config mistake — a human decides which geometry is the region.
                raise SystemExit(
                    f"Overture returned multiple land rows for {iso} in {cc} "
                    f"(subtype={c['subtype']}, release={release}) — refusing to "
                    f"overwrite region-{c['slugs'][iso]}.geojson; inspect the rows."
                )
            seen.add(iso)
            geom = json.loads(geojson)
            feat = build_feature(iso, cc, geom, geodesic_area_km2(geom), c)
            path = out_dir / f"region-{feat['properties']['slug']}.geojson"
            path.write_text(json.dumps(feat, ensure_ascii=False), encoding="utf-8")
            p = feat["properties"]
            print(f"  {path.name}: {p['iso_code']} {p['area_km2']} km² ({feat['geometry']['type']})")
            written.append(path)
        missing = set(c["slugs"]) - seen
        if missing:
            raise SystemExit(
                f"Overture returned no rows for {sorted(missing)} in {cc} "
                f"(subtype={c['subtype']}, release={release}) — check the config."
            )
    return written


def main(argv=None):
    ap = argparse.ArgumentParser(description="Overture divisions -> region-<slug>.geojson")
    ap.add_argument("--country", required=True, help="ISO 3166-1 alpha-2 (e.g. BE)")
    ap.add_argument("--out", default=str(OUT_DEFAULT), help="output dir (default tools/divisions/out)")
    ap.add_argument("--release", default=None, help="Overture release (default config.OVERTURE_RELEASE)")
    args = ap.parse_args(argv)
    written = export_country(args.country.upper(), args.out, args.release)
    print(f"Wrote {len(written)} region artifact(s) to {args.out}")


if __name__ == "__main__":
    main()
