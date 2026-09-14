# SPDX-License-Identifier: AGPL-3.0-only
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
from .load import apply_session_budget, ensure_schema, load_region, resolve_country
from .parse import parse_pois
from .publish import (ROUTES_MANIFEST_KEY, ensure_bucket, prune, prune_routes,
                      prune_surface, published_countries, upload, upload_routes,
                      upload_surface)
from .routes import extract_region as routes_extract_region
from .routes import load_way_ids
from .routes import selector_expressions as routes_selectors
from .surface import extract_region
from .surface import selector_expressions as surface_selectors
from .tiles import (build_gaps_pmtiles, build_pmtiles, build_routes_pmtiles,
                    build_surface_pmtiles, export_geojsonl, verify_pmtiles)

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


def _extract_is_current(extract: pathlib.Path, pbf: pathlib.Path, contract_file: pathlib.Path,
                        stamp: pathlib.Path | None = None, *,
                        extra_inputs: tuple[pathlib.Path, ...] = (),
                        allow_empty: bool = False) -> bool:
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
    return stamp.exists() and stamp.read_text().strip() == contract_fingerprint(contract_file)


def _run_surface(regions, workdir, contract, *, extract_only: bool = False,
                 publish: bool = True) -> int:
    """The line path: PBF -> osmium -> GeoJSONL -> tippecanoe. No database at all.

    Deliberately not folded into the per-region loop above: that loop exists to
    keep coverage_poi in step, and lines never touch it. Sharing it would mean
    holding the advisory lock and a Postgres session through a build that needs
    neither (Dated/2026-08-09-surface-line-tiles-design.md §4).

    One pass per region produces all three artifacts — the classified skin, the
    to-do arm and the gap grid — because they are three readings of the same
    walk over the same ways. The previous shape ran the whole filter-and-parse
    twice, once per arm, which on a continental build is hours spent deriving
    data the first pass had already seen.
    """
    classified: dict[str, list[pathlib.Path]] = {}
    todo: dict[str, list[pathlib.Path]] = {}
    gaps: list[pathlib.Path] = []
    # Feature counts for the manifest, filled as regions are processed — so it
    # has to exist BEFORE the loop that increments it. It did not, and every
    # region raised UnboundLocalError into the per-region handler and reported
    # itself as failed; the unit tests never walked that path, and a real
    # publish found it in one run.
    counts = {"classified": 0, "todo": 0, "cells": 0}
    allow_shrink = os.environ.get("COVERAGE_ALLOW_SHRINK") == "1"
    failed = []
    for region in regions:
        try:
            country_code = resolve_country(region)
            pbf = fetch_pbf(region, workdir)
            # Keyed by REGION, not by country: the US is onboarded as two
            # Geofabrik extracts (california + colorado) and Great Britain can
            # be joined by the all-Ireland one. A per-country filename made the
            # second region overwrite the first, and the cache check then
            # declared the survivor current — one state silently standing in
            # for a country, with nothing in the log to say so.
            slug = region.replace("/", "-")
            out = workdir / f"surface_{slug}_classified.geojsonl"
            todo_out = workdir / f"surface_{slug}_todo.geojsonl"
            gaps_out = workdir / f"surface_{slug}_gaps.geojsonl"

            # A PMTiles archive cannot be appended to — adding a country means
            # tiling the whole set again — so the per-country EXTRACT is the
            # only part worth caching, and it is by far the expensive one:
            # osmium plus a full node-location pass over a national PBF, versus
            # a tiling run that reads GeoJSONL already on disk.
            #
            # Reused only when the extract is newer than both the PBF it came
            # from AND the contract that shaped it. The contract matters as much
            # as the data: adding a highway type or a surface class changes what
            # SHOULD be in the file while leaving the PBF untouched, and a stale
            # extract would then be silently tiled as if it were current.
            # All three outputs are checked, not just the classified one: a run
            # that added the to-do arm to a workdir holding last week's extracts
            # would otherwise reuse them and tile an arm that does not exist.
            stamp = workdir / f"surface_{slug}.stamp"
            # The routes extract's way-id set, when a `--routes` run has left
            # one in the workdir: it is what makes the to-do arm route-aware
            # (an untagged way on a signed route is homework whatever its
            # class). Named as an extract INPUT too, so a fresh routes run
            # invalidates the surface extracts it would change.
            wayids_path = workdir / f"routes_{slug}_wayids.txt"
            if all(_extract_is_current(f, pbf, contract_path(), stamp,
                                       extra_inputs=(wayids_path,))
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
                    print(f"[surface] {region}: no routes extract in the workdir — "
                          "the to-do arm is class-gated only (run --routes first "
                          "for route-awareness)")
                # NOT the manifest `counts` dict: rebinding that name here made
                # every fresh extract fail at the line-count loop below
                # ("'SurfaceCounts' object is not subscriptable") while cached
                # regions sailed through — the first cold build after a cache
                # wipe would have reported every region failed.
                fresh = extract_region(
                    filtered, contract, classified_out=out, todo_out=todo_out,
                    gaps_out=gaps_out, cctok=f"|{country_code}|",
                    route_way_ids=route_way_ids)
                # Written only after all three files are complete, so a run
                # killed mid-extract leaves no stamp and the next one redoes it.
                stamp.write_text(contract_fingerprint(), encoding="utf-8")
                print(f"[surface] {region}: {fresh.classified} classified, "
                      f"{fresh.todo} to record, {fresh.cells} gap cells")
            for key, path in (("classified", out), ("todo", todo_out), ("cells", gaps_out)):
                with path.open("rb") as fh:
                    counts[key] += sum(1 for _ in fh)
            classified.setdefault(country_code, []).append(out)
            todo.setdefault(country_code, []).append(todo_out)
            gaps.append(gaps_out)
        except Exception as exc:  # noqa: BLE001 — one region must not stop the rest
            print(f"[surface] {region} FAILED: {exc}", file=sys.stderr)
            failed.append(region)
    def _peak() -> str:
        """Peak RSS of this process and of the tools it waited on, in MB.

        Reported because this job's whole design rests on a memory claim — ways
        stream to disk instead of accumulating, and tippecanoe sorts on disk —
        and a claim that is never printed is a claim nobody can check. Measuring
        it OUTSIDE the container (`/usr/bin/time docker compose run`) measures
        the docker client, which is how a build can look like it used 12 MB.
        ru_maxrss is kilobytes on Linux; CHILDREN covers osmium and tippecanoe.
        """
        me = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024
        kids = resource.getrusage(resource.RUSAGE_CHILDREN).ru_maxrss / 1024
        return f"peak RSS {me:.0f} MB (this process), {kids:.0f} MB (largest tool)"

    spec = contract.surface
    if extract_only:
        # Continental runs go region-by-region so a failure costs one country
        # rather than the queue — and every one of those passes would otherwise
        # end in a full tippecanoe build of the countries done so far, tiling
        # Germany a dozen times to throw each result away. Extract now, tile
        # once at the end.
        print(f"[surface] extract-only: {len(classified)} country layer(s) ready, not tiling")
        print(f"[surface] {_peak()}")
        return 1 if failed else 0
    if classified:
        artifact = workdir / "surface.pmtiles"
        build_surface_pmtiles(classified, artifact, contract)
        print(f"[surface] {artifact.name}: {artifact.stat().st_size / 1e6:.1f} MB")
    if todo:
        artifact = workdir / "surface-todo.pmtiles"
        build_surface_pmtiles(todo, artifact, contract,
                              min_zoom=spec["todo"]["minZoom"], max_zoom=spec["todo"]["maxZoom"])
        print(f"[surface] {artifact.name}: {artifact.stat().st_size / 1e6:.1f} MB")
    if gaps:
        artifact = workdir / "surface-gaps.pmtiles"
        build_gaps_pmtiles(gaps, artifact, contract)
        print(f"[surface] {artifact.name}: {artifact.stat().st_size / 1e6:.1f} MB")
    if classified and publish:
        # Publishing is part of the run, exactly as it is for coverage. It used
        # to be a hand-run `mc cp` plus an edited env var plus a cache clear —
        # three manual steps between "the data is built" and "riders can see
        # it", each of which can be forgotten, and one of which (a pinned URL
        # left pointing at a pruned artifact) yields a map with no surfaces and
        # no error anywhere.
        try:
            ensure_bucket()
            # Never let a narrow run silently replace a wide one.
            #
            # A PMTiles archive cannot be appended to, so the tiling step builds
            # from exactly the regions it was given — and `make surface-tiles
            # regions=europe/luxembourg` therefore produces a Luxembourg-only
            # build. Publishing it would take eleven countries off the map with
            # a zero exit code and nothing in the log. (Observed, 2026-08-12,
            # on the first end-to-end publish.) The point job documents the same
            # hazard as a warning in the runbook; this enforces it.
            live = published_countries()
            built = set(classified)
            if live is not None and built < live and not allow_shrink:
                raise RuntimeError(
                    f"refusing to publish {len(built)} countries over the live "
                    f"{len(live)}: {', '.join(sorted(live - built))} would vanish "
                    "from the map. Pass every onboarded region, or set "
                    "COVERAGE_ALLOW_SHRINK=1 if the removal is intended.")
            urls = upload_surface(
                {"classified": workdir / "surface.pmtiles",
                 "todo": workdir / "surface-todo.pmtiles",
                 "gaps": workdir / "surface-gaps.pmtiles"},
                {"counts": counts, "country_codes": sorted(classified)})
            for arm, url in urls.items():
                print(f"[surface] published {arm}: {url}")
            for key in prune_surface():
                print(f"[surface] pruned {key}")
        except Exception as exc:  # noqa: BLE001
            # A build that cannot publish is still a build worth keeping: the
            # artifacts are on disk and the last manifest keeps serving.
            print(f"[surface] publish FAILED: {exc}", file=sys.stderr)
            failed.append("publish")
    print(f"[surface] {_peak()}")
    return 1 if failed else 0


def _run_routes(regions, workdir, contract, *, extract_only: bool = False,
                publish: bool = True) -> int:
    """The route-network path: PBF -> osmium -> two-pass extract -> tippecanoe.

    No database, same as the surface path and for the same reason. One run
    produces the routes artifact AND the per-region way-id sets the surface
    pass reads for route-awareness — which is why `--routes` is the pass to run
    FIRST when both are being rebuilt: the way-id files it drops in the workdir
    are newer than the surface extracts, so the surface run re-extracts with
    them (see _extract_is_current's extra_inputs).
    """
    way_files: dict[str, list[pathlib.Path]] = {}
    node_files: dict[str, list[pathlib.Path]] = {}
    counts = {"ways": 0, "nodes": 0}
    allow_shrink = os.environ.get("COVERAGE_ALLOW_SHRINK") == "1"
    failed = []
    for region in regions:
        try:
            country_code = resolve_country(region)
            pbf = fetch_pbf(region, workdir)
            # Keyed by REGION for the same multi-extract-country reason the
            # surface files are (california + colorado).
            slug = region.replace("/", "-")
            ways_out = workdir / f"routes_{slug}_ways.geojsonl"
            nodes_out = workdir / f"routes_{slug}_knoop.geojsonl"
            wayids_out = workdir / f"routes_{slug}_wayids.txt"
            stamp = workdir / f"routes_{slug}.stamp"
            # allow_empty: a country with no node network has an empty knoop
            # file, and a country with no signed routes at all (rare, but a
            # partial extract like a single US state can be) has empty ways —
            # both are answers, not failures to cache.
            if all(_extract_is_current(f, pbf, contract_path(), stamp, allow_empty=True)
                   for f in (ways_out, nodes_out, wayids_out)):
                print(f"[routes] {region}: extract unchanged, reusing {ways_out.name}")
            else:
                filtered = workdir / (slug + "-routes.osm.pbf")
                run_filter(pbf, filtered, routes_selectors())
                fresh = routes_extract_region(
                    filtered, contract, ways_out=ways_out, nodes_out=nodes_out,
                    wayids_out=wayids_out, cctok=f"|{country_code}|")
                stamp.write_text(contract_fingerprint(), encoding="utf-8")
                print(f"[routes] {region}: {fresh.ways} member ways on "
                      f"{fresh.relations} routes, {fresh.nodes} knooppunten")
            for key, path in (("ways", ways_out), ("nodes", nodes_out)):
                with path.open("rb") as fh:
                    counts[key] += sum(1 for _ in fh)
            way_files.setdefault(country_code, []).append(ways_out)
            node_files.setdefault(country_code, []).append(nodes_out)
        except Exception as exc:  # noqa: BLE001 — one region must not stop the rest
            print(f"[routes] {region} FAILED: {exc}", file=sys.stderr)
            failed.append(region)

    if extract_only:
        print(f"[routes] extract-only: {len(way_files)} country layer(s) ready, not tiling")
        return 1 if failed else 0
    if way_files:
        artifact = workdir / "routes.pmtiles"
        build_routes_pmtiles(way_files, node_files, artifact, contract)
        print(f"[routes] {artifact.name}: {artifact.stat().st_size / 1e6:.1f} MB")
        if publish:
            try:
                ensure_bucket()
                # Same shrink guard as the surface publish, against its own
                # manifest: `make routes-tiles regions=europe/luxembourg` must
                # not take eleven countries' corridors off the map silently.
                live = published_countries(manifest_key=ROUTES_MANIFEST_KEY)
                built = set(way_files)
                if live is not None and built < live and not allow_shrink:
                    raise RuntimeError(
                        f"refusing to publish {len(built)} countries over the live "
                        f"{len(live)}: {', '.join(sorted(live - built))} would vanish "
                        "from the map. Pass every onboarded region, or set "
                        "COVERAGE_ALLOW_SHRINK=1 if the removal is intended.")
                url = upload_routes(artifact, {"counts": counts,
                                               "country_codes": sorted(way_files)})
                print(f"[routes] published {url}")
                for key in prune_routes():
                    print(f"[routes] pruned {key}")
            except Exception as exc:  # noqa: BLE001
                # A build that cannot publish is still a build worth keeping.
                print(f"[routes] publish FAILED: {exc}", file=sys.stderr)
                failed.append("publish")
    return 1 if failed else 0


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
                         "instead of the point index (zero DB rows; their own pmtiles). "
                         "One pass produces all three: surface.pmtiles (the classified "
                         "skin), surface-todo.pmtiles (roads whose surface nobody has "
                         "recorded, in the classes where the answer is genuinely "
                         "unknown) and surface-gaps.pmtiles (the same question as a "
                         "grid, for planning zoom). Three artifacts because a vector "
                         "tile is fetched whole, so a layer that is off by default must "
                         "not cost its bytes to every rider.")
    ap.add_argument("--routes", action="store_true",
                    help="build the cycle-route NETWORK artifact for the given regions "
                         "(route=bicycle/mtb relations as corridors, knooppunt numbers "
                         "as points; zero DB rows, its own pmtiles + manifest). Also "
                         "drops the per-region member way-id sets the --surface pass "
                         "reads to make its to-do arm route-aware — run --routes "
                         "BEFORE --surface when rebuilding both.")
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
                    help="csv of Geofabrik regions (default: $COVERAGE_REGIONS or europe/belgium,europe/netherlands,europe/germany,europe/luxembourg,europe/france,europe/switzerland,europe/great-britain,europe/ireland-and-northern-ireland,europe/italy,australia-oceania/australia,asia/japan,north-america/us/california,north-america/us/colorado,europe/spain)")
    args = ap.parse_args(argv)
    # Code-level fallback mirrors the shipped .env.example / compose default so
    # an env-less invocation still covers every onboarded region, not just BE.
    regions = [r.strip() for r in
               (args.regions or os.environ.get("COVERAGE_REGIONS", "europe/belgium,europe/netherlands,europe/germany,europe/luxembourg,europe/france,europe/switzerland,europe/great-britain,europe/ireland-and-northern-ireland,europe/italy,australia-oceania/australia,asia/japan,north-america/us/california,north-america/us/colorado,europe/spain")).split(",")
               if r.strip()]
    workdir = pathlib.Path(os.environ.get("COVERAGE_WORKDIR", "/data/work"))
    workdir.mkdir(parents=True, exist_ok=True)
    contract = load_contract()
    if args.routes:
        return _run_routes(regions, workdir, contract, extract_only=args.extract_only,
                           publish=not args.no_publish)
    if args.surface:
        return _run_surface(regions, workdir, contract, extract_only=args.extract_only,
                            publish=not args.no_publish)
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
        # Letters whose points must sit along a bike way (docs/specs/scenic-views.md).
        near_rules = {letter: spec.near_way for letter, spec in contract.letters.items() if spec.near_way}
        if len({(r.within_m, tuple(r.highways), tuple(r.bicycle_tags)) for r in near_rules.values()}) > 1:
            raise ValueError("near-way rules differ between letters; one way pass serves them all today")
        # Letters whose points need a name or a photo link (docs/specs/scenic-views.md §2).
        name_or_tags = {letter: spec.name_or_tags for letter, spec in contract.letters.items() if spec.name_or_tags}
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
                pbf = fetch_pbf(region, workdir)
                filtered = workdir / (region.replace("/", "-") + "-filtered.osm.pbf")
                run_extract(pbf, filtered, contract)
                rows = parse_pois(filtered, contract, region, country_code)
                near_ways = None
                if near_rules:
                    rule = next(iter(near_rules.values()))
                    ways = workdir / (region.replace("/", "-") + "-bikeways.osm.pbf")
                    run_way_filter(pbf, ways, rule)
                    near_ways = ({letter: r.within_m for letter, r in near_rules.items()},
                                 rideable_lines(export_lines(ways), rule))
                result = load_region(conn, rows, region, country_code, near_ways=near_ways,
                                     name_or_tags=name_or_tags or None)
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

        if args.load_only:
            _print_timings(timings, time.monotonic() - run_started)
            return 1 if failed else 0

        tiles_started = time.monotonic()
        layer_files = export_geojsonl(conn, workdir)
        if not layer_files:
            print("[coverage] index empty — nothing to publish", file=sys.stderr)
            # Still report what the attempt cost: a run that harvested for an
            # hour and then found nothing to publish is a different problem
            # from one that fell over immediately.
            _print_timings(timings, time.monotonic() - run_started)
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
        print(f"[coverage] published {url} (pruned {len(stale)}) — "
              f"tiles {_dur(time.monotonic() - tiles_started)}")
        _print_timings(timings, time.monotonic() - run_started)
    return 1 if failed else 0


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
