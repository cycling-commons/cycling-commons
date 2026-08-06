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
import time
import urllib.error
import urllib.request

import psycopg

from .contract import load_contract
from .extract import run_extract
from .load import apply_session_budget, ensure_schema, load_region, resolve_country
from .parse import parse_pois
from .publish import ensure_bucket, prune, upload
from .tiles import build_pmtiles, export_geojsonl, verify_pmtiles

GEOFABRIK_BASE = "https://download.geofabrik.de"

# Fixed advisory-lock key for the whole coverage run (design §3.2). Any stable
# non-zero bigint that no other advisory-lock user on the CC cluster shares; CC
# is the only advisory-lock user there today. 0xC07E7A6E = "coverage" mnemonic.
COVERAGE_ADVISORY_LOCK_KEY = 0xC07E7A6E


def _acquire_run_lock(conn) -> bool:
    """Session-level pg_try_advisory_lock for the whole run (design §3.2).
    Returns False when another coverage run already holds it. Held until the
    connection closes; the commit closes the implicit txn while the session
    keeps the lock."""
    got = conn.execute(
        "SELECT pg_try_advisory_lock(%s)", (COVERAGE_ADVISORY_LOCK_KEY,)
    ).fetchone()[0]
    conn.commit()
    return got


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
    # Race-tolerant skip: a stale mirror .md5 here at worst forces a needless
    # re-download, never a crash — so a flaky .md5 must not block a needed load.
    try:
        with urllib.request.urlopen(url + ".md5", timeout=60) as r:
            if dest.exists() and _md5(dest) == r.read().decode().split()[0]:
                print(f"[coverage] {region}: PBF unchanged, skipping download")
                return dest
    except urllib.error.URLError:
        pass
    # Geofabrik 302-round-robins across mirrors and rebuilds -latest daily, so a
    # -latest.md5 fetched from one mirror can disagree with the -latest.pbf a
    # different mirror serves — a VALID multi-GB download then fails the check
    # (observed for europe/germany; small cached extracts never hit the window).
    # Pin the mirror: verify the bytes we actually received against the .md5 from
    # the SAME resolved URL that served them (r.geturl() after redirects), which
    # is consistent by construction. The retry then re-reads -latest.md5
    # (round-robin) as a fallback so a mirror missing its sibling .md5 still
    # converges on the current hash rather than crashing a good download.
    tmp = dest.with_suffix(".part")
    with urllib.request.urlopen(url, timeout=600) as r, open(tmp, "wb") as out:
        resolved = r.geturl()
        while chunk := r.read(1 << 20):
            out.write(chunk)
    got = _md5(tmp)
    md5_sources = [resolved + ".md5", *([url + ".md5"] * 4)]
    want = None
    for i, src in enumerate(md5_sources):
        try:
            with urllib.request.urlopen(src, timeout=60) as r:
                want = r.read().decode().split()[0]
        except urllib.error.URLError:
            want = None
        if want == got:
            tmp.replace(dest)
            return dest
        if i < len(md5_sources) - 1:
            time.sleep(3)
    tmp.unlink()
    raise RuntimeError(
        f"{region}: md5 mismatch after download (got {got}; last want {want}) — "
        "no mirror .md5 matched across retries, the download may be corrupt"
    )


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description="Weekly coverage batch (PostGIS index + PMTiles)")
    ap.add_argument("--regions",
                    help="csv of Geofabrik regions (default: $COVERAGE_REGIONS or europe/belgium,europe/netherlands,europe/germany,europe/luxembourg,europe/france,europe/switzerland,europe/great-britain,europe/ireland-and-northern-ireland,europe/italy,australia-oceania/australia,asia/japan,north-america/us/california,north-america/us/colorado)")
    args = ap.parse_args(argv)
    # Code-level fallback mirrors the shipped .env.example / compose default so
    # an env-less invocation still covers every onboarded region, not just BE.
    regions = [r.strip() for r in
               (args.regions or os.environ.get("COVERAGE_REGIONS", "europe/belgium,europe/netherlands,europe/germany,europe/luxembourg,europe/france,europe/switzerland,europe/great-britain,europe/ireland-and-northern-ireland,europe/italy,australia-oceania/australia,asia/japan,north-america/us/california,north-america/us/colorado")).split(",")
               if r.strip()]
    workdir = pathlib.Path(os.environ.get("COVERAGE_WORKDIR", "/data/work"))
    workdir.mkdir(parents=True, exist_ok=True)
    contract = load_contract()
    failed = []
    dsn = os.environ.get("DATABASE_DSN", "postgresql://cc:cc@db:5432/cyclingcommons")
    with psycopg.connect(dsn) as conn:
        # Autocommit so the read-only phases (export COPYs, the manifest counts
        # query) never leave a transaction open across the long, non-DB tile
        # build/verify/upload phases — that would sit idle-in-transaction and be
        # killed by COVERAGE_IDLE_TXN_TIMEOUT (design §3.1). The ONLY transactions
        # then are load_region's explicit `with conn.transaction()` swaps, which
        # is exactly what the idle/statement timeouts should be guarding.
        conn.autocommit = True
        apply_session_budget(conn)
        if not _acquire_run_lock(conn):
            print("[coverage] another coverage run holds the advisory lock — "
                  "exiting", file=sys.stderr)
            return 2
        ensure_schema(conn)
        for region in regions:
            try:
                # Resolved ONCE per region and reused for both calls below (I1): an
                # unresolvable country is now a hard failure (raises), caught by the
                # try/except like any other per-region failure, rather than the two
                # call sites independently `.get()`-missing into a silent None.
                country_code = resolve_country(region)
                pbf = fetch_pbf(region, workdir)
                filtered = workdir / (region.replace("/", "-") + "-filtered.osm.pbf")
                run_extract(pbf, filtered, contract)
                rows = parse_pois(filtered, contract, region, country_code)
                result = load_region(conn, rows, region, country_code)
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
