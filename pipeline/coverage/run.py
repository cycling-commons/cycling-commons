# SPDX-License-Identifier: AGPL-3.0-only
"""Coverage batch runner — the weekly per-region job (coverage-provider.md §3).

Per region: download (md5-checked, skipped when unchanged) → osmium tags-filter
→ pyosmium parse → atomic per-region swap into coverage_poi. Then once per run:
per-letter GeoJSONL export → tippecanoe PMTiles, per country → go-pmtiles
verify → publish + prune, per country (a country whose export fingerprint
matches the live manifest is skipped rather than rebuilt). A region failure
keeps last week's slice serving and turns into a non-zero exit for the timer's
failure mail.

Entrypoint: python -m coverage.run  (dev: `make coverage-refresh`).
"""
import argparse
import contextlib
import hashlib
import json
import resource
import os
import pathlib
import sys
import time
import urllib.error
import urllib.request

import psycopg

from .contract import load_contract
from .extract import export_lines, rideable_lines, run_extract, run_filter, run_way_filter
from .load import COUNTRY_BY_REGION, apply_session_budget, ensure_schema, load_region, resolve_country
from .ownership import Owners, pbf_header_box, region_fingerprint, snapshot_outlines
from .parse import parse_pois
from .publish import (CountryBuild, GapsBuild, ensure_bucket, inputs_fingerprint,
                      prune_family, publish_countries, read_live_manifest, read_manifest)
from .regions import ONBOARDED_REGIONS, default_regions
from .routes import extract_region as routes_extract_region
from .routes import load_way_ids
from .routes import selector_expressions as routes_selectors
from .surface import extract_region, merge_gap_cells
from .surface import selector_expressions as surface_selectors
from .tiles import (TILE_PROFILE, artifact_bounds, build_gaps_pmtiles, build_pmtiles,
                    build_routes_pmtiles, build_surface_pmtiles, export_geojsonl,
                    verify_pmtiles)
from .tracker import RunTracker

GEOFABRIK_BASE = "https://download.geofabrik.de"

# Fixed advisory-lock key for the whole coverage run (design §3.2). Any stable
# non-zero bigint that no other advisory-lock user on the CC cluster shares; CC
# is the only advisory-lock user there today. 0xC07E7A6E = "coverage" mnemonic.
COVERAGE_ADVISORY_LOCK_KEY = 0xC07E7A6E
# One run lock per line family (surface, routes), distinct from the coverage
# run lock above and from the manifest locks (publish._MANIFEST_LOCK_KEYS,
# 0xC07E7A70-72).
LINE_RUN_LOCK_KEYS = {"surface": 0xC07E7A73, "routes": 0xC07E7A74}

# The operational region outlines the owner rule reads, snapshotted per run.
OUTLINES_FILE = "ownership-regions.json"


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
    # Offline: use what is on disk and do not ask Geofabrik anything.
    #
    # For the surface build the expensive step is the per-region EXTRACT, which
    # is cached — but a PMTiles archive cannot be appended to, so adding a
    # country means a TILING pass over every country again, and that pass walks
    # the same region list. Without this it would re-verify (and on a daily
    # rebuild, re-download) ~20 GB of PBFs to produce files it already has.
    # Deliberately explicit rather than inferred from the cache being warm: a
    # run that skips the download must say so, or "the data is current" quietly
    # becomes "the data is whatever was here last time".
    if os.environ.get("COVERAGE_PBF_OFFLINE") == "1":
        if not dest.is_file():
            raise RuntimeError(
                f"{region}: COVERAGE_PBF_OFFLINE=1 but {dest.name} is not in the "
                "workdir — run the region once online first")
        print(f"[coverage] {region}: offline, using {dest.name} as it stands")
        return dest
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


def contract_path() -> pathlib.Path:
    """Where the tile contract lives, for freshness checks."""
    return pathlib.Path(__file__).resolve().parents[1] / "contract" / "coverage-contract.json"


def contract_fingerprint(contract_file: pathlib.Path | None = None) -> str:
    """Short content hash of the contract — what an extract was shaped by.

    CONTENT, not mtime. The contract decides what belongs in an extract, so a
    changed contract must invalidate it; but a *touched* contract must not. Two
    ways that distinction stops being academic:

      * a `git checkout` or a deploy sets mtimes to now, so an mtime rule would
        re-extract every onboarded country on every release — hours of work to
        rebuild files that are byte-identical;
      * it happened here. On 2026-08-12 the contract was touched without being
        changed between the extraction and the tiling pass, and three countries
        were silently re-extracted for nothing.

    The stale-data risk the mtime rule was protecting against is unchanged: a
    real edit changes the bytes and so changes this hash.
    """
    path = contract_file or contract_path()
    if not path.exists():
        return ""
    return hashlib.sha256(path.read_bytes()).hexdigest()[:16]


def extract_stamp(outlines_fp: str) -> str:
    """What a line extract was shaped by: the contract and the border outlines."""
    return f"{contract_fingerprint()}:{outlines_fp}"


def _region_stamp(workdir: pathlib.Path, pbf: pathlib.Path) -> str:
    """A region's extract stamp: the contract and the outlines that can own a
    feature inside its PBF's header box (coverage.ownership.region_fingerprint).
    Onboarding or reseeding a country elsewhere leaves it unchanged."""
    return extract_stamp(region_fingerprint(workdir / OUTLINES_FILE, pbf_header_box(pbf)))


def _default_pbf(workdir: pathlib.Path, region: str) -> pathlib.Path:
    """Where fetch_pbf keeps a region's PBF in the workdir."""
    return workdir / (region.replace("/", "-") + "-latest.osm.pbf")


def _ownership(workdir: pathlib.Path) -> Owners:
    """Snapshot the operational region outlines once per run (coverage.ownership).

    Needs the database even for an offline tiling pass. A run that cannot read
    the outlines fails: a line extract without the owner rule would put every
    border road back into two files.
    """
    path = workdir / OUTLINES_FILE
    dsn = os.environ.get("DATABASE_DSN", "postgresql://cc:cc@db:5432/cyclingcommons")
    with psycopg.connect(dsn) as conn:
        snapshot_outlines(conn, path)
    return Owners.load(path)


@contextlib.contextmanager
def _line_run_lock(family: str):
    """Session pg_try_advisory_lock for one whole surface or routes run.

    Yields False when another run of the same family holds it: both write the
    same per-region scratch files in the workdir, and one would tile what the
    other is rewriting. Distinct from the coverage run lock and the manifest
    locks, so a surface run never waits on a coverage run.
    """
    dsn = os.environ.get("DATABASE_DSN", "postgresql://cc:cc@db:5432/cyclingcommons")
    key = LINE_RUN_LOCK_KEYS[family]
    with psycopg.connect(dsn, autocommit=True) as conn:
        got = conn.execute("SELECT pg_try_advisory_lock(%s)", (key,)).fetchone()[0]
        try:
            yield got
        finally:
            if got:
                conn.execute("SELECT pg_advisory_unlock(%s)", (key,))


def _complete_countries(regions: list[str]) -> dict[str, list[str]]:
    """Countries whose every onboarded region is in this run, with those regions.

    A country built from part of its regions would publish over the whole one:
    `--regions north-america/us/california` must not take Colorado off the map.
    A region outside ONBOARDED_REGIONS (a dev/ region, a sub-country extract
    such as europe/germany/bayern) is extracted but never tiled per country.
    """
    wanted = set(regions)
    for region in regions:
        if region in ONBOARDED_REGIONS:
            continue
        if region.startswith("dev/"):
            print(f"[tiles] {region}: a dev/ region, extracted but not tiled per country")
        else:
            print(f"[tiles] {region}: not an onboarded region, not tiled per country")
    by_cc: dict[str, list[str]] = {}
    for region in ONBOARDED_REGIONS:
        cc = COUNTRY_BY_REGION.get(region)
        if cc:
            by_cc.setdefault(cc, []).append(region)
    out = {}
    for cc, members in sorted(by_cc.items()):
        present = [r for r in members if r in wanted]
        if present and len(present) == len(members):
            out[cc] = present
        elif present:
            missing = ", ".join(r for r in members if r not in wanted)
            print(f"[tiles] {cc}: not tiled, this run lacks {missing}")
    return out


def _first_publish_refused(family: str, complete: dict[str, list[str]], live_v2: bool,
                           retire: tuple[str, ...]) -> bool:
    """Is this the first v2 publish of `family` and does it lack a country?

    Until a v2 manifest is live, the app serves the v1 world archive; the first
    v2 manifest replaces it, so a first publish of fewer than every onboarded
    country takes the rest off the map. `--retire` or
    COVERAGE_FIRST_PUBLISH_PARTIAL=1 says the operator means it.
    """
    if live_v2 or retire or os.environ.get("COVERAGE_FIRST_PUBLISH_PARTIAL") == "1":
        return False
    onboarded = {COUNTRY_BY_REGION[r] for r in ONBOARDED_REGIONS if r in COUNTRY_BY_REGION}
    missing = sorted(onboarded - set(complete))
    if not missing:
        return False
    print(f"[{family}] first per-country publish refused: no v2 manifest is live yet and this run "
          f"lacks {', '.join(missing)}. Run every onboarded region, or set "
          "COVERAGE_FIRST_PUBLISH_PARTIAL=1 to publish this subset alone.", file=sys.stderr)
    return True


def _stamp_text(workdir: pathlib.Path, prefix: str, region: str) -> str:
    stamp = workdir / f"{prefix}_{region.replace('/', '-')}.stamp"
    return stamp.read_text(encoding="utf-8").strip() if stamp.exists() else ""


def _surface_inputs(workdir: pathlib.Path, regions: list[str]) -> str:
    """Fingerprint of a country's surface build inputs, for the rebuild rule.

    Each region's stamp names the contract and the outlines near that region
    its extract was shaped by."""
    slugs = [r.replace("/", "-") for r in regions]
    files = [workdir / f"surface_{s}_{arm}.geojsonl" for s in slugs for arm in ("classified", "todo")]
    return inputs_fingerprint(files, contract_fingerprint(),
                              *(_stamp_text(workdir, "surface", r) for r in sorted(regions)),
                              TILE_PROFILE["surface"])


def _gaps_inputs(workdir: pathlib.Path) -> str:
    """Fingerprint of the world gap-grid build inputs, for the rebuild rule."""
    return inputs_fingerprint([workdir / "surface-gaps.geojsonl"], contract_fingerprint(), TILE_PROFILE["gaps"])


def _routes_inputs(workdir: pathlib.Path, regions: list[str]) -> str:
    """Fingerprint of a country's routes build inputs, for the rebuild rule."""
    slugs = [r.replace("/", "-") for r in regions]
    files = [workdir / f"routes_{s}_{arm}.geojsonl" for s in slugs for arm in ("ways", "knoop")]
    return inputs_fingerprint(files, contract_fingerprint(),
                              *(_stamp_text(workdir, "routes", r) for r in sorted(regions)),
                              TILE_PROFILE["routes"])


def _world_gap_cells(workdir: pathlib.Path, wants: dict[str, str]) -> tuple[dict[str, list[pathlib.Path]], list[str]]:
    """Every onboarded region's current gap-cell file, keyed by its country, and
    the onboarded regions that have none.

    Current means the region's surface stamp equals the stamp this run wants
    for it: `wants` for the regions this run extracted, the stamp of the PBF in
    the workdir for the rest. dev/ regions never feed the world grid.
    """
    cells: dict[str, list[pathlib.Path]] = {}
    missing = []
    for region in ONBOARDED_REGIONS:
        slug = region.replace("/", "-")
        path = workdir / f"surface_{slug}_gapcells.tsv"
        want = wants.get(region) or _region_stamp(workdir, _default_pbf(workdir, region))
        if path.exists() and _stamp_text(workdir, "surface", region) == want:
            cells.setdefault(COUNTRY_BY_REGION[region], []).append(path)
        else:
            missing.append(region)
    return cells, missing


def _union_bounds(boxes: list[list[float]]) -> list[float]:
    return [min(b[0] for b in boxes), min(b[1] for b in boxes),
            max(b[2] for b in boxes), max(b[3] for b in boxes)]


def _extract_is_current(extract: pathlib.Path, pbf: pathlib.Path, contract_file: pathlib.Path,
                        stamp: pathlib.Path | None = None, *,
                        extra_inputs: tuple[pathlib.Path, ...] = (),
                        allow_empty: bool = False,
                        expected: str | None = None) -> bool:
    """Is a cached per-country GeoJSONL still good?

    Only when it exists, is not empty, is newer than the PBF it came from, and
    was produced by the contract we are holding now. Deliberately conservative:
    a false "current" silently ships last month's roads, while a false "stale"
    costs one extract.

    The PBF stays an mtime comparison — a re-downloaded country really is new
    data, and its bytes are gigabytes we are not going to hash. The contract is
    matched by content (see contract_fingerprint), recorded in a `.stamp` beside
    the extract. A missing stamp means "written before stamps existed", which is
    treated as stale: one extract is a cheap price for not guessing.

    `extra_inputs` are further files the extract was derived from, compared by
    mtime like the PBF — the surface pass names the routes way-id set here, so
    a fresh `--routes` run invalidates the surface extracts whose to-do arm it
    would change. `allow_empty` is for outputs that are legitimately empty (a
    country with no knooppunten has an empty nodes file, and re-extracting it
    weekly to rediscover that would be the cache defeating itself).

    `COVERAGE_FORCE_EXTRACT=1` skips the cache outright — the hatch for a
    pipeline change whose effect is visible in no timestamp and no hash.
    """
    if os.environ.get("COVERAGE_FORCE_EXTRACT") == "1":
        return False
    if not extract.exists() or (not allow_empty and extract.stat().st_size == 0):
        return False
    if pbf.exists() and extract.stat().st_mtime < pbf.stat().st_mtime:
        return False
    for extra in extra_inputs:
        if extra.exists() and extract.stat().st_mtime < extra.stat().st_mtime:
            return False
    if stamp is None:
        # Back-compat for callers that have no stamp to offer (the tests' own
        # temp contracts): fall back to the timestamp rule this replaced.
        return not contract_file.exists() or extract.stat().st_mtime >= contract_file.stat().st_mtime
    want = contract_fingerprint(contract_file) if expected is None else expected
    return stamp.exists() and stamp.read_text().strip() == want


def _run_surface(regions, workdir, contract, *, extract_only: bool = False,
                 publish: bool = True, retire: tuple[str, ...] = ()) -> int:
    """The line path, under the surface run lock; 2 when another surface run holds it."""
    with _line_run_lock("surface") as got:
        if not got:
            print("[surface] another surface run holds the run lock, exiting", file=sys.stderr)
            return 2
        return _surface_pass(regions, workdir, contract, extract_only=extract_only,
                             publish=publish, retire=retire)


def _surface_pass(regions, workdir, contract, *, extract_only: bool = False,
                  publish: bool = True, retire: tuple[str, ...] = ()) -> int:
    """The line path: PBF -> osmium -> GeoJSONL -> tippecanoe, per country. No
    database beyond the outline snapshot and the run lock.

    Deliberately not folded into the per-region loop above: that loop exists to
    keep coverage_poi in step, and lines never touch it. Sharing it would mean
    holding the coverage advisory lock and a Postgres session through a build
    that needs neither (Dated/2026-08-09-surface-line-tiles-design.md §4).

    One pass per region produces all three arms' extracts (the classified
    skin, the to-do arm and the gap cells) because they are three readings of
    the same walk over the same ways.

    Tiling is per country: a PMTiles archive cannot be appended to, so each
    complete country is tiled and published on its own, and skipped when its
    extract fingerprint matches what is already live. One country failing to
    build costs that country only. The world gap grid is merged from every
    onboarded region's current cell file, and is not rebuilt while any
    onboarded region has none.
    """
    failed = []
    wants: dict[str, str] = {}
    owners = _ownership(workdir)
    for region in regions:
        try:
            country_code = resolve_country(region)
            pbf = fetch_pbf(region, workdir)
            want = _region_stamp(workdir, pbf)
            # Keyed by REGION, not by country: the US is onboarded as two
            # Geofabrik extracts (california + colorado) and Great Britain can
            # be joined by the all-Ireland one; a per-country filename would
            # let the second region overwrite the first.
            slug = region.replace("/", "-")
            out = workdir / f"surface_{slug}_classified.geojsonl"
            todo_out = workdir / f"surface_{slug}_todo.geojsonl"
            gaps_out = workdir / f"surface_{slug}_gapcells.tsv"

            # The per-region EXTRACT is the expensive step (osmium plus a full
            # node-location pass over a national PBF), so it is cached.
            #
            # Reused only when the extract is newer than the PBF it came from
            # and its stamp names the contract that shaped it and the outlines
            # near the region. The contract matters as much as the data: adding
            # a highway type or a surface class changes what SHOULD be in the
            # file while leaving the PBF untouched. All three outputs are
            # checked, not just the classified one.
            stamp = workdir / f"surface_{slug}.stamp"
            # The routes extract's way-id set, when a `--routes` run has left
            # one in the workdir: it is what makes the to-do arm route-aware
            # (an untagged way on a signed route is homework whatever its
            # class). Named as an extract INPUT too, so a fresh routes run
            # invalidates the surface extracts it would change.
            wayids_path = workdir / f"routes_{slug}_wayids.txt"
            if all(_extract_is_current(f, pbf, contract_path(), stamp,
                                       extra_inputs=(wayids_path,), expected=want)
                   for f in (out, todo_out, gaps_out)):
                print(f"[surface] {region}: extract unchanged, reusing {out.name}")
            else:
                filtered = workdir / (slug + "-surface.osm.pbf")
                run_filter(pbf, filtered, surface_selectors(contract))
                route_way_ids = load_way_ids(wayids_path)
                if not route_way_ids:
                    # Loudly, not silently: the arm still builds, but a rider
                    # zooming the Zuiderdijk would see class-gated homework
                    # only, and nothing else in the log would say why.
                    print(f"[surface] {region}: no routes extract in the workdir; "
                          "the to-do arm is class-gated only (run --routes first "
                          "for route-awareness)")
                fresh = extract_region(
                    filtered, contract, classified_out=out, todo_out=todo_out,
                    gaps_out=gaps_out, cctok=f"|{country_code}|",
                    route_way_ids=route_way_ids,
                    keep=owners.keeper(country_code) if owners else None)
                # Written only after all three files are complete, so a run
                # killed mid-extract leaves no stamp and the next one redoes it.
                stamp.write_text(want, encoding="utf-8")
                print(f"[surface] {region}: {fresh.classified} classified, "
                      f"{fresh.todo} to record, {fresh.cells} grid cells, "
                      f"{fresh.foreign} owned by a neighbour")
            wants[region] = want
        except Exception as exc:  # noqa: BLE001 - one region must not stop the rest
            print(f"[surface] {region} FAILED: {exc}", file=sys.stderr)
            failed.append(region)

    spec = contract.surface
    if extract_only:
        # Continental runs go region-by-region so a failure costs one country
        # rather than the queue. Extract now, tile once at the end.
        print(f"[surface] extract-only: {len(wants)} region extract(s) ready, not tiling")
        print(f"[surface] {_peak()}")
        return 1 if failed else 0
    complete = _complete_countries(list(wants))
    if all(r.startswith("dev/") for r in regions) and not retire:
        print(f"[surface] {_peak()}")
        return 1 if failed else 0
    live = {"version": 2, "countries": {}}
    if publish:
        try:
            live, live_v2 = read_live_manifest("surface")
        except Exception as exc:  # noqa: BLE001 - the last manifest keeps serving
            print(f"[surface] manifest read FAILED: {exc}", file=sys.stderr)
            return 1
        if _first_publish_refused("surface", complete, live_v2, retire):
            return 1
    built: dict[str, CountryBuild] = {}
    for cc, members in complete.items():
        try:
            inputs = _surface_inputs(workdir, members)
            if live["countries"].get(cc.lower(), {}).get("inputs") == inputs:
                print(f"[surface] {cc}: unchanged, not rebuilt")
                continue
            slugs = [r.replace("/", "-") for r in members]
            paths, counts_cc = {}, {}
            for arm, lo, hi in (("classified", None, None),
                                ("todo", spec["todo"]["minZoom"], spec["todo"]["maxZoom"])):
                files = [workdir / f"surface_{s}_{arm}.geojsonl" for s in slugs]
                n = sum(sum(1 for _ in f.open("rb")) for f in files)
                counts_cc[arm] = n
                if n == 0:
                    continue      # a country with nothing in this arm publishes the other arm only
                out = workdir / f"surface-{'' if arm == 'classified' else 'todo-'}{cc.lower()}.pmtiles"
                build_surface_pmtiles({cc: files}, out, contract, min_zoom=lo, max_zoom=hi)
                paths[arm] = out
            if not paths:
                continue
            bounds = _union_bounds([artifact_bounds(p) for p in paths.values()])
            built[cc] = CountryBuild(paths=paths, inputs=inputs, bounds=bounds, counts=counts_cc)
            print(f"[surface] {cc}: rebuilt ({', '.join(f'{a} {counts_cc[a]}' for a in counts_cc)})")
        except Exception as exc:  # noqa: BLE001 - one country must not stop the rest
            print(f"[surface] {cc}: build FAILED: {exc}", file=sys.stderr)
            failed.append(cc)
    gaps_build = None
    cells, missing = _world_gap_cells(workdir, wants)
    if missing:
        print(f"[surface] gaps: not rebuilt, no current cells for {', '.join(missing)}")
    else:
        try:
            world_gaps = workdir / "surface-gaps.geojsonl"
            with world_gaps.open("w", encoding="utf-8") as fh:
                for line in merge_gap_cells(cells, spec["gaps"]["cellZoom"]):
                    fh.write(line + "\n")
            g_inputs = _gaps_inputs(workdir)
            if world_gaps.stat().st_size and live.get("gaps", {}).get("inputs") != g_inputs:
                out = workdir / "surface-gaps.pmtiles"
                build_gaps_pmtiles([world_gaps], out, contract)
                gaps_build = GapsBuild(path=out, inputs=g_inputs)
        except Exception as exc:  # noqa: BLE001 - the live gap grid keeps serving
            print(f"[surface] gaps: build FAILED: {exc}", file=sys.stderr)
            failed.append("gaps")
    if publish and (built or gaps_build or retire):
        try:
            ensure_bucket()
            doc = publish_countries("surface", built, gaps=gaps_build, retire=retire)
            for key in prune_family("surface", doc):
                print(f"[surface] pruned {key}")
        except Exception as exc:  # noqa: BLE001 - the last manifest keeps serving
            print(f"[surface] publish FAILED: {exc}", file=sys.stderr)
            failed.append("publish")
    print(f"[surface] {_peak()}")
    return 1 if failed else 0


def _peak() -> str:
    """Peak RSS of this process and of the tools it waited on, in MB.

    Reported because the line builds rest on a memory claim (ways stream to
    disk instead of accumulating, and tippecanoe sorts on disk), and a claim
    that is never printed is a claim nobody can check. Measuring it OUTSIDE the
    container (`/usr/bin/time docker compose run`) measures the docker client,
    which is how a build can look like it used 12 MB. ru_maxrss is kilobytes on
    Linux; CHILDREN covers osmium and tippecanoe.
    """
    me = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024
    kids = resource.getrusage(resource.RUSAGE_CHILDREN).ru_maxrss / 1024
    return f"peak RSS {me:.0f} MB (this process), {kids:.0f} MB (largest tool)"


def _run_routes(regions, workdir, contract, *, extract_only: bool = False,
                publish: bool = True, retire: tuple[str, ...] = ()) -> int:
    """The route-network path, under the routes run lock; 2 when another routes run holds it."""
    with _line_run_lock("routes") as got:
        if not got:
            print("[routes] another routes run holds the run lock, exiting", file=sys.stderr)
            return 2
        return _routes_pass(regions, workdir, contract, extract_only=extract_only,
                            publish=publish, retire=retire)


def _routes_pass(regions, workdir, contract, *, extract_only: bool = False,
                 publish: bool = True, retire: tuple[str, ...] = ()) -> int:
    """The route-network path: PBF -> osmium -> two-pass extract -> tippecanoe,
    per country.

    No database beyond the outline snapshot and the run lock, same as the
    surface path and for the same reason. One run produces the per-country
    routes artifacts AND the per-region way-id sets the surface pass reads for
    route-awareness, which is why `--routes` is the pass to run FIRST when both
    are being rebuilt: the way-id files it drops in the workdir are newer than
    the surface extracts, so the surface run re-extracts with them (see
    _extract_is_current's extra_inputs). One country failing to build costs
    that country only.
    """
    failed = []
    extracted: list[str] = []
    owners = _ownership(workdir)
    for region in regions:
        try:
            country_code = resolve_country(region)
            pbf = fetch_pbf(region, workdir)
            want = _region_stamp(workdir, pbf)
            # Keyed by REGION for the same multi-extract-country reason the
            # surface files are (california + colorado).
            slug = region.replace("/", "-")
            ways_out = workdir / f"routes_{slug}_ways.geojsonl"
            nodes_out = workdir / f"routes_{slug}_knoop.geojsonl"
            wayids_out = workdir / f"routes_{slug}_wayids.txt"
            stamp = workdir / f"routes_{slug}.stamp"
            # allow_empty: a country with no node network has an empty knoop
            # file, and a country with no signed routes at all (rare, but a
            # partial extract like a single US state can be) has empty ways;
            # both are answers, not failures to cache.
            if all(_extract_is_current(f, pbf, contract_path(), stamp, allow_empty=True,
                                       expected=want)
                   for f in (ways_out, nodes_out, wayids_out)):
                print(f"[routes] {region}: extract unchanged, reusing {ways_out.name}")
            else:
                filtered = workdir / (slug + "-routes.osm.pbf")
                run_filter(pbf, filtered, routes_selectors())
                fresh = routes_extract_region(
                    filtered, contract, ways_out=ways_out, nodes_out=nodes_out,
                    wayids_out=wayids_out, cctok=f"|{country_code}|",
                    keep=owners.keeper(country_code) if owners else None)
                stamp.write_text(want, encoding="utf-8")
                print(f"[routes] {region}: {fresh.ways} member ways on "
                      f"{fresh.relations} routes, {fresh.nodes} knooppunten, "
                      f"{fresh.foreign} owned by a neighbour")
            extracted.append(region)
        except Exception as exc:  # noqa: BLE001 - one region must not stop the rest
            print(f"[routes] {region} FAILED: {exc}", file=sys.stderr)
            failed.append(region)

    if extract_only:
        print(f"[routes] extract-only: {len(extracted)} region extract(s) ready, not tiling")
        return 1 if failed else 0
    complete = _complete_countries(extracted)
    if all(r.startswith("dev/") for r in regions) and not retire:
        return 1 if failed else 0
    live = {"version": 2, "countries": {}}
    if publish:
        try:
            live, live_v2 = read_live_manifest("routes")
        except Exception as exc:  # noqa: BLE001 - the last manifest keeps serving
            print(f"[routes] manifest read FAILED: {exc}", file=sys.stderr)
            return 1
        if _first_publish_refused("routes", complete, live_v2, retire):
            return 1
    built: dict[str, CountryBuild] = {}
    for cc, members in complete.items():
        try:
            inputs = _routes_inputs(workdir, members)
            if live["countries"].get(cc.lower(), {}).get("inputs") == inputs:
                print(f"[routes] {cc}: unchanged, not rebuilt")
                continue
            slugs = [r.replace("/", "-") for r in members]
            ways = [workdir / f"routes_{s}_ways.geojsonl" for s in slugs]
            knoop = [workdir / f"routes_{s}_knoop.geojsonl" for s in slugs]
            n_ways = sum(sum(1 for _ in f.open("rb")) for f in ways)
            n_nodes = sum(sum(1 for _ in f.open("rb")) for f in knoop)
            if n_ways + n_nodes == 0:
                continue
            out = workdir / f"routes-{cc.lower()}.pmtiles"
            build_routes_pmtiles({cc: ways}, {cc: knoop}, out, contract)
            built[cc] = CountryBuild(paths={"routes": out}, inputs=inputs, bounds=artifact_bounds(out),
                                     counts={"ways": n_ways, "nodes": n_nodes})
            print(f"[routes] {cc}: rebuilt ({n_ways} ways, {n_nodes} knooppunten)")
        except Exception as exc:  # noqa: BLE001 - one country must not stop the rest
            print(f"[routes] {cc}: build FAILED: {exc}", file=sys.stderr)
            failed.append(cc)
    if publish and (built or retire):
        try:
            ensure_bucket()
            doc = publish_countries("routes", built, retire=retire)
            for key in prune_family("routes", doc):
                print(f"[routes] pruned {key}")
        except Exception as exc:  # noqa: BLE001 - the last manifest keeps serving
            print(f"[routes] publish FAILED: {exc}", file=sys.stderr)
            failed.append("publish")
    return 1 if failed else 0


def _coverage_countries(layer_files: dict[tuple[str, str], pathlib.Path]) -> dict[str, dict]:
    """export_geojsonl's {(LETTER, CC): path} regrouped as {cc: {(LETTER, CC): path}},
    one entry per PMTiles archive the coverage points build must produce
    (lowercase cc, including the 'zz' bucket for unstamped rows)."""
    out: dict[str, dict] = {}
    for (letter, cc), path in layer_files.items():
        out.setdefault(cc.lower(), {})[(letter, cc)] = path
    return out


def _dur(seconds: float) -> str:
    """A duration read at a glance: "41.2s", "2m17s", "1h04m".

    A refresh is minutes per country and hours for the full list, so the log
    has to answer "which region is eating the run?" without arithmetic.
    """
    if seconds < 60:
        return f"{seconds:.1f}s"
    total = int(round(seconds))
    if total < 3600:
        return f"{total // 60}m{total % 60:02d}s"
    return f"{total // 3600}h{(total % 3600) // 60:02d}m"


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description="Weekly coverage batch (PostGIS index + PMTiles)")
    ap.add_argument("--surface", action="store_true",
                    help="build the road-surface LINE artifacts for the given regions "
                         "instead of the point index (zero DB rows; their own pmtiles), "
                         "tiled and published PER COUNTRY: a classified skin and a "
                         "to-do arm (roads whose surface nobody has recorded, in the "
                         "classes where the answer is genuinely unknown) under "
                         "surface/<cc>/<stamp>/<arm>.pmtiles, plus one world gap grid "
                         "under surface/gaps/<stamp>/gaps.pmtiles. A country rebuilds "
                         "only when its own extracts have changed, and only when every "
                         "one of its onboarded regions is in this run.")
    ap.add_argument("--routes", action="store_true",
                    help="build the cycle-route NETWORK artifact for the given regions "
                         "(route=bicycle/mtb relations as corridors, knooppunt numbers "
                         "as points; zero DB rows), tiled and published PER COUNTRY "
                         "under routes/<cc>/<stamp>/routes.pmtiles. Also drops the "
                         "per-region member way-id sets the --surface pass reads to "
                         "make its to-do arm route-aware: run --routes BEFORE "
                         "--surface when rebuilding both.")
    ap.add_argument("--no-publish", action="store_true",
                    help="with --surface/--routes: build the artifacts but do not upload "
                         "them or move the manifest. For experiments and size "
                         "measurements; a normal run publishes, so that a rebuild needs "
                         "no config change.")
    ap.add_argument("--extract-only", action="store_true",
                    help="with --surface/--routes: produce the per-region GeoJSONL and "
                         "stop, without building any PMTiles. For continental runs done "
                         "one region at a time (so a failure costs one country, not "
                         "the queue); the tiling pass follows once, over all of them.")
    ap.add_argument("--tiles-only", action="store_true",
                    help="skip the harvest and rebuild + publish the coverage artifact "
                         "from the coverage_poi rows already in the database. For a "
                         "change that rewrote the index without new OSM data (the "
                         "2026-08-25 letter renumbering: the tile layers are named "
                         "<letter>_<cc>, so the artifact had to follow the rows). The "
                         "manifest's `regions` is the region list given, as for a full run.")
    ap.add_argument("--load-only", action="store_true",
                    help="load the given regions into coverage_poi and stop: no export, no "
                         "PMTiles, no publish. For loading countries one at a time on a "
                         "machine short of memory; a single --tiles-only run then builds "
                         "and publishes the artifact from the whole index.")
    ap.add_argument("--regions",
                    help="csv of Geofabrik regions (default: $COVERAGE_REGIONS or every "
                         f"onboarded region: {','.join(ONBOARDED_REGIONS)})")
    ap.add_argument("--trigger", default="manual",
                    help="what started this run, recorded in coverage_run "
                         "(dispatcher | bootstrap | manual)")
    ap.add_argument("--run-id", type=int,
                    help="append this run's steps to an existing coverage_run row "
                         "(the dispatcher's) instead of opening a new one")
    ap.add_argument("--retire",
                    help="csv of country codes to remove from the published manifest of the family "
                         "this run builds (offboarding). Nothing else ever removes a country.")
    args = ap.parse_args(argv)
    regions = ([r.strip() for r in args.regions.split(",") if r.strip()]
               if args.regions else default_regions())
    retire = tuple(c.strip().lower() for c in (args.retire or "").split(",") if c.strip())
    workdir = pathlib.Path(os.environ.get("COVERAGE_WORKDIR", "/data/work"))
    workdir.mkdir(parents=True, exist_ok=True)
    contract = load_contract()
    if args.routes:
        return _run_routes(regions, workdir, contract, extract_only=args.extract_only,
                           publish=not args.no_publish, retire=retire)
    if args.surface:
        return _run_surface(regions, workdir, contract, extract_only=args.extract_only,
                            publish=not args.no_publish, retire=retire)
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
        tracker = RunTracker(conn)
        if args.run_id is not None:
            tracker.attach(args.run_id)
        else:
            tracker.start(args.trigger, len(regions))
        # Letters whose points must sit along a bike way (docs/specs/scenic-views.md).
        near_rules = {letter: spec.near_way for letter, spec in contract.letters.items() if spec.near_way}
        if len({(r.within_m, tuple(r.highways), tuple(r.bicycle_tags)) for r in near_rules.values()}) > 1:
            raise ValueError("near-way rules differ between letters; one way pass serves them all today")
        # Letters whose points need a name or a photo link (docs/specs/scenic-views.md §2).
        name_or_tags = {letter: spec.name_or_tags for letter, spec in contract.letters.items() if spec.name_or_tags}
        # Letters that leave out points of certain tag values (docs/specs/coverage-provider.md §3).
        exclude_tag_values = {letter: spec.exclude_tag_values
                              for letter, spec in contract.letters.items() if spec.exclude_tag_values}
        run_started = time.monotonic()
        timings: list[tuple[str, float, bool]] = []
        for region in ([] if args.tiles_only else regions):
            region_started = time.monotonic()
            try:
                # Resolved ONCE per region and reused for both calls below (I1): an
                # unresolvable country is now a hard failure (raises), caught by the
                # try/except like any other per-region failure, rather than the two
                # call sites independently `.get()`-missing into a silent None.
                country_code = resolve_country(region)
                with tracker.step(region, "download") as st:
                    download_started = time.time()
                    pbf = fetch_pbf(region, workdir)
                    if isinstance(pbf, pathlib.Path) and pbf.exists():
                        st.bytes = pbf.stat().st_size
                        # Nothing written since the step began: md5 skip, offline or override.
                        if pbf.stat().st_mtime < download_started:
                            st.detail = "cached"
                filtered = workdir / (region.replace("/", "-") + "-filtered.osm.pbf")
                with tracker.step(region, "filter"):
                    run_extract(pbf, filtered, contract)
                # parse_pois is a generator consumed by load_region's COPY; timing
                # it lazily keeps the rows streaming instead of held in memory.
                parse_seconds = [0.0]
                rows = _timed(parse_pois(filtered, contract, region, country_code), parse_seconds)
                near_ways = None
                if near_rules:
                    rule = next(iter(near_rules.values()))
                    ways = workdir / (region.replace("/", "-") + "-bikeways.osm.pbf")
                    with tracker.step(region, "near_way"):
                        run_way_filter(pbf, ways, rule)
                    near_ways = ({letter: r.within_m for letter, r in near_rules.items()},
                                 rideable_lines(export_lines(ways), rule))
                with tracker.step(region, "load") as st:
                    result = load_region(conn, rows, region, country_code, near_ways=near_ways,
                                         name_or_tags=name_or_tags or None,
                                         exclude_tag_values=exclude_tag_values or None)
                    st.rows = result.inserted
                    # JSON so the admin page can read the rule counts back;
                    # older plain-text rows stay readable as text (§3).
                    st.detail = json.dumps({"previous": result.previous,
                                            "dropped": result.dropped})
                tracker.record(region, "parse", parse_seconds[0])
                elapsed = time.monotonic() - region_started
                timings.append((region, elapsed, True))
                print(f"[coverage] {region}: loaded/updated {result.inserted} rows "
                      f"(previous {result.previous}) in {_dur(elapsed)}")
            except Exception as exc:  # noqa: BLE001 — one region must not stop the rest (coverage-provider.md §3 failure mode)
                elapsed = time.monotonic() - region_started
                timings.append((region, elapsed, False))
                failed.append(region)
                # The time a failure took is worth as much as a success's: a
                # region that dies after 40 minutes failed differently from one
                # that dies in two seconds.
                print(f"[coverage] {region}: FAILED after {_dur(elapsed)} — {exc}", file=sys.stderr)

        loaded = sum(1 for (_r, _e, good) in timings if good)
        run_status = "ok" if not failed else "partial" if loaded else "failed"
        if args.load_only:
            _print_timings(timings, time.monotonic() - run_started)
            tracker.finish(run_status, loaded)
            return 1 if failed else 0

        tiles_started = time.monotonic()
        with tracker.step(None, "export"):
            layer_files = export_geojsonl(conn, workdir)
        if not layer_files:
            print("[coverage] index empty — nothing to publish", file=sys.stderr)
            # Still report what the attempt cost: a run that harvested for an
            # hour and then found nothing to publish is a different problem
            # from one that fell over immediately.
            _print_timings(timings, time.monotonic() - run_started)
            tracker.finish("failed", loaded)
            return 1
        try:
            live = read_manifest("coverage")
        except Exception as exc:  # noqa: BLE001 - the last manifest keeps serving
            print(f"[coverage] manifest read FAILED: {exc}", file=sys.stderr)
            _print_timings(timings, time.monotonic() - run_started)
            tracker.finish("partial" if loaded else "failed", loaded)
            return 1
        built: dict[str, CountryBuild] = {}
        with tracker.step(None, "tippecanoe") as st:
            for cc, files in sorted(_coverage_countries(layer_files).items()):
                inputs = inputs_fingerprint(files.values(), contract_fingerprint(), TILE_PROFILE["coverage"])
                if live["countries"].get(cc, {}).get("inputs") == inputs:
                    continue
                out = workdir / f"coverage-{cc}.pmtiles"
                build_pmtiles(files, out)
                # expect exactly the (letter, cc) layer pairs this country exported;
                # pairs absent from the index (possible on partial fixtures) don't
                # fail the gate
                verify_pmtiles(out, expected_layers={f"{l.lower()}_{c.lower()}" for (l, c) in files})
                counts_cc = {l: sum(1 for _ in p.open("rb")) for (l, _c), p in files.items()}
                built[cc] = CountryBuild(paths={"points": out}, inputs=inputs,
                                         bounds=artifact_bounds(out), counts=counts_cc)
            st.bytes = sum(b.paths["points"].stat().st_size for b in built.values())
            st.detail = ",".join(sorted(built)) or "none changed"
        rebuilt = ",".join(sorted(built)) or None
        if built or retire:
            ensure_bucket()
            with tracker.step(None, "upload") as st:
                doc = publish_countries("coverage", built, retire=retire)
                st.detail = rebuilt
            for key in prune_family("coverage", doc, keep=4):
                print(f"[coverage] pruned {key}")
        print(f"[coverage] {len(built)} countr{'y' if len(built) == 1 else 'ies'} rebuilt: "
              f"{rebuilt or 'none'} - tiles {_dur(time.monotonic() - tiles_started)}")
        _print_timings(timings, time.monotonic() - run_started)
        tracker.finish(run_status, loaded, rebuilt)
    return 1 if failed else 0


def _timed(items, acc: list[float]):
    """Yield from `items`, adding the seconds spent inside each next() to acc[0]."""
    it = iter(items)
    while True:
        started = time.monotonic()
        try:
            item = next(it)
        except StopIteration:
            acc[0] += time.monotonic() - started
            return
        acc[0] += time.monotonic() - started
        yield item


def _print_timings(timings, total: float) -> None:
    """Per-region times, slowest first, then the wall clock for the whole run."""
    if not timings:
        return
    ok = sum(1 for (_r, _e, good) in timings if good)
    width = max(len(region) for (region, _e, _g) in timings)
    print(f"[coverage] --- timings ({len(timings)} regions, {ok} ok, "
          f"{len(timings) - ok} failed) ---")
    for region, elapsed, good in sorted(timings, key=lambda t: -t[1]):
        print(f"[coverage]   {region.ljust(width)}  {_dur(elapsed):>7}"
              f"{'' if good else '  FAILED'}")
    print(f"[coverage] total {_dur(total)}")


if __name__ == "__main__":
    raise SystemExit(main())
