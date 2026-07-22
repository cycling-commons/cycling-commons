# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Coverage batch runner — the weekly per-region job (coverage-provider.md §3).

Per region: download (md5-checked, skipped when unchanged) → osmium tags-filter
→ pyosmium parse → atomic per-region swap into coverage_poi. Then once per run:
per-letter GeoJSONL export → tippecanoe PMTiles → go-pmtiles verify → upload
versioned artifact + manifest → prune. A region failure keeps last week's
slice serving and turns into a non-zero exit for the timer's failure mail.

Entrypoint: python -m coverage.run  (dev: `make coverage-refresh`).
"""
import argparse
import hashlib
import os
import pathlib
import sys
import urllib.request

import psycopg

from .contract import load_contract
from .extract import run_extract
from .load import ensure_schema, load_region
from .parse import parse_pois
from .publish import ensure_bucket, prune, upload
from .tiles import build_pmtiles, export_geojsonl, verify_pmtiles

GEOFABRIK_BASE = "https://download.geofabrik.de"
# country_code stamped per extract (coverage-provider.md §2 country_code column);
# extend per region.
COUNTRY_BY_REGION = {"europe/belgium": "BE", "europe/netherlands": "NL", "europe/germany": "DE"}


def _md5(path: pathlib.Path) -> str:
    h = hashlib.md5()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def fetch_pbf(region: str, workdir: pathlib.Path) -> pathlib.Path:
    """Download <region>-latest.osm.pbf, md5-verified; skip when unchanged.

    COVERAGE_PBF_PATH short-circuits everything — dev/fixture runs and CI
    never touch the network."""
    override = os.environ.get("COVERAGE_PBF_PATH")
    if override:
        path = pathlib.Path(override)
        if not path.is_file():
            # Fail here with the real cause instead of an opaque osmium error
            # two stages later (typo'd fixture path, forgotten volume mount).
            raise RuntimeError(f"override PBF not found: {path}")
        return path
    url = f"{GEOFABRIK_BASE}/{region}-latest.osm.pbf"
    dest = workdir / (region.replace("/", "-") + "-latest.osm.pbf")
    with urllib.request.urlopen(url + ".md5", timeout=60) as r:
        want = r.read().decode().split()[0]
    if dest.exists() and _md5(dest) == want:
        print(f"[coverage] {region}: PBF unchanged (md5 {want}), skipping download")
        return dest
    tmp = dest.with_suffix(".part")
    with urllib.request.urlopen(url, timeout=600) as r, open(tmp, "wb") as out:
        while chunk := r.read(1 << 20):
            out.write(chunk)
    got = _md5(tmp)
    if got != want:
        tmp.unlink()
        raise RuntimeError(f"{region}: md5 mismatch after download (want {want}, got {got})")
    tmp.replace(dest)
    return dest


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description="Weekly coverage batch (PostGIS index + PMTiles)")
    ap.add_argument("--regions",
                    help="csv of Geofabrik regions (default: $COVERAGE_REGIONS or europe/belgium,europe/netherlands,europe/germany)")
    args = ap.parse_args(argv)
    # Code-level fallback mirrors the shipped .env.example / compose default so
    # an env-less invocation still covers every onboarded region, not just BE.
    regions = [r.strip() for r in
               (args.regions or os.environ.get("COVERAGE_REGIONS", "europe/belgium,europe/netherlands,europe/germany")).split(",")
               if r.strip()]
    workdir = pathlib.Path(os.environ.get("COVERAGE_WORKDIR", "/data/work"))
    workdir.mkdir(parents=True, exist_ok=True)
    contract = load_contract()
    failed = []
    dsn = os.environ.get("DATABASE_DSN", "postgresql://cc:cc@db:5432/cyclingcommons")
    with psycopg.connect(dsn) as conn:
        ensure_schema(conn)
        for region in regions:
            try:
                pbf = fetch_pbf(region, workdir)
                filtered = workdir / (region.replace("/", "-") + "-filtered.osm.pbf")
                run_extract(pbf, filtered, contract)
                rows = parse_pois(filtered, contract, region, COUNTRY_BY_REGION.get(region))
                result = load_region(conn, rows, region)
                print(f"[coverage] {region}: loaded/updated {result.inserted} rows "
                      f"(previous {result.previous})")
            except Exception as exc:  # noqa: BLE001 — one region must not stop the rest (coverage-provider.md §3 failure mode)
                failed.append(region)
                print(f"[coverage] {region}: FAILED — {exc}", file=sys.stderr)

        layer_files = export_geojsonl(conn, workdir)
        if not layer_files:
            print("[coverage] index empty — nothing to publish", file=sys.stderr)
            return 1
        artifact = workdir / "coverage.pmtiles"
        build_pmtiles(layer_files, artifact)
        # expect exactly the (letter, cc) layer pairs we exported; pairs absent
        # from the index (possible on partial fixtures) don't fail the gate
        verify_pmtiles(artifact, expected_layers={
            f"{letter.lower()}_{cc.lower()}" for (letter, cc) in layer_files})
        # Manifest semantics (shape locked, coverage-provider.md §4): `counts`
        # spans the WHOLE coverage_poi table — every region's current slice,
        # matching the artifact, which is always built from the full index —
        # while `regions` lists only THIS run's regions. Staggered per-region
        # prod timers make the two legitimately diverge.
        counts = dict(conn.execute(
            "SELECT letter, count(*) FROM coverage_poi GROUP BY letter").fetchall())
        ensure_bucket()
        country_codes = sorted({cc for (_letter, cc) in layer_files if cc != "ZZ"})
        url = upload(artifact, {"counts": counts, "regions": regions,
                                "country_codes": country_codes})
        stale = prune(keep=4)
        print(f"[coverage] published {url} (pruned {len(stale)})")
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
