# SPDX-License-Identifier: AGPL-3.0-only
"""Nightly coverage dispatcher (worker build plan §2.5 E3, §4.2): pick the
stalest onboarded regions, `--load-only` each in series inside a time budget,
then `--tiles-only` once if anything loaded. One timer per environment; adding
a region is a list entry; a failed region is first in line tomorrow, not next
week. Budget and cap come from COVERAGE_BUDGET_MIN / COVERAGE_MAX_REGIONS.
Each loaded region also refreshes its routes and surface extracts, and one
offline pass per family then republishes only the countries whose inputs
changed.

Entrypoint: python -m coverage.dispatch
"""
import contextlib
import os
import sys
import time
from datetime import datetime, timezone

import psycopg

from . import run as _run
from .load import apply_session_budget, ensure_schema
from .regions import default_regions
from .run import _dur
from .tracker import RunTracker

# Module-level seam so tests can record calls; run.main takes the advisory lock
# on its OWN connection per call, so this connection never touches the lock.
run_main = _run.main

_NEVER = datetime.min.replace(tzinfo=timezone.utc)


def order_by_staleness(conn, universe: list[str]) -> list[str]:
    """Never-loaded (absent from coverage_source or NULL) first, then oldest load, then slug."""
    fresh = dict(conn.execute(
        "SELECT slug, last_loaded_at FROM coverage_source_freshness").fetchall())
    return sorted(universe, key=lambda s: (fresh.get(s) is not None, fresh.get(s) or _NEVER, s))


def estimate_seconds(conn, region: str) -> float:
    """1.5 x the ok-step total of the most recent run that loaded this region; 0 when unknown."""
    row = conn.execute(
        "SELECT sum(seconds) FROM coverage_run_step "
        "WHERE region = %s AND status = 'ok' AND run_id = ("
        "  SELECT run_id FROM coverage_run_step "
        "  WHERE region = %s AND step = 'load' AND status = 'ok' "
        "  ORDER BY started_at DESC LIMIT 1)",
        (region, region),
    ).fetchone()
    return 1.5 * float(row[0]) if row and row[0] is not None else 0.0


def _call(label: str, argv: list[str]) -> int:
    """run_main(argv), with an exception logged and counted as rc 1.

    run.main runs in this process, so an exception it lets escape would
    otherwise skip every later pass and leave the run row unfinished.
    """
    try:
        return run_main(argv)
    except Exception as exc:  # noqa: BLE001 - one pass must not end the night
        print(f"[dispatch] {label} FAILED: {exc}", file=sys.stderr)
        return 1


@contextlib.contextmanager
def _offline():
    """COVERAGE_PBF_OFFLINE=1 for one call: the PBFs on disk are tonight's."""
    old = os.environ.get("COVERAGE_PBF_OFFLINE")
    os.environ["COVERAGE_PBF_OFFLINE"] = "1"
    try:
        yield
    finally:
        if old is None:
            os.environ.pop("COVERAGE_PBF_OFFLINE", None)
        else:
            os.environ["COVERAGE_PBF_OFFLINE"] = old


def main(argv=None) -> int:
    budget_s = 60 * float(os.environ.get("COVERAGE_BUDGET_MIN", "240"))
    max_regions = int(os.environ.get("COVERAGE_MAX_REGIONS", "6"))
    dsn = os.environ.get("DATABASE_DSN", "postgresql://cc:cc@db:5432/cyclingcommons")
    universe = default_regions()
    started = time.monotonic()
    loaded: list[str] = []
    failed: list[str] = []
    with psycopg.connect(dsn) as conn:
        conn.autocommit = True
        apply_session_budget(conn)
        ensure_schema(conn)
        tracker = RunTracker(conn)
        run_id = tracker.start("dispatcher", len(universe))
        order = order_by_staleness(conn, universe)
        print(f"[dispatch] run {run_id}: budget {_dur(budget_s)}, cap {max_regions}, "
              f"{len(order)} regions, stalest first: {', '.join(order[:max_regions])}")
        attempts = 0
        for region in order:
            if attempts >= max_regions:
                print(f"[dispatch] cap of {max_regions} regions reached, "
                      f"{len(order) - attempts} left for tomorrow")
                break
            estimate = estimate_seconds(conn, region)
            elapsed = time.monotonic() - started
            if attempts and elapsed + estimate > budget_s:
                print(f"[dispatch] {region}: estimate {_dur(estimate)} does not fit the "
                      f"{_dur(budget_s - elapsed)} left, stopping")
                break
            attempts += 1
            print(f"[dispatch] {region}: loading (estimate "
                  f"{_dur(estimate) if estimate else 'unknown'}, elapsed {_dur(elapsed)})")
            rc = _call(region, ["--load-only", "--regions", region,
                                "--run-id", str(run_id), "--trigger", "dispatcher"])
            if rc == 0:
                loaded.append(region)
                # The PBF this load just fetched feeds the line extracts too, so the
                # expensive pass runs once per region per night, and offline.
                for family in ("--routes", "--surface"):
                    t0 = time.monotonic()
                    with _offline():
                        erc = _call(f"{region} {family} extract", [family, "--extract-only", "--regions", region])
                    if erc == 0:
                        tracker.record(region, family.lstrip("-") + "_extract", time.monotonic() - t0)
                    else:
                        tracker.record(region, family.lstrip("-") + "_extract", time.monotonic() - t0,
                                        status="failed", detail=f"rc {erc}")
                        if region not in failed:
                            failed.append(region)
            elif rc == 2:
                print(f"[dispatch] {region}: another coverage run holds the lock, "
                      "aborting tonight", file=sys.stderr)
                tracker.finish("failed", len(loaded))
                return 2
            else:
                failed.append(region)
                print(f"[dispatch] {region}: FAILED (rc {rc}), continuing", file=sys.stderr)
        publish_failed = False
        if loaded:
            print(f"[dispatch] {len(loaded)} region(s) loaded, publishing the tiles")
            rc = _call("--tiles-only", ["--tiles-only", "--regions", ",".join(universe), "--run-id", str(run_id)])
            publish_failed = rc != 0
            if publish_failed:
                print(f"[dispatch] publish FAILED (rc {rc})", file=sys.stderr)
            with _offline():
                for family in ("--routes", "--surface"):
                    rc = _call(family, [family, "--regions", ",".join(universe)])
                    if rc == 2:
                        print(f"[dispatch] {family}: another {family} run holds its run lock, "
                              "not published tonight", file=sys.stderr)
                    elif rc != 0:
                        print(f"[dispatch] {family} publish FAILED (rc {rc})", file=sys.stderr)
                    if rc != 0:
                        publish_failed = True
        else:
            print("[dispatch] nothing loaded, not publishing")
        # What run.main's upload step left in its detail: the countries it
        # rebuilt this run (comma-separated), or none when nothing changed.
        rebuilt = conn.execute(
            "SELECT detail FROM coverage_run_step WHERE run_id = %s AND step = 'upload' "
            "AND status = 'ok' ORDER BY started_at DESC LIMIT 1",
            (run_id,),
        ).fetchone()
        if failed or publish_failed:
            status = "partial" if loaded else "failed"
        else:
            status = "ok"
        tracker.finish(status, len(loaded), rebuilt[0] if rebuilt else None)
        print(f"[dispatch] {status}: {len(loaded)} loaded, {len(failed)} failed, "
              f"total {_dur(time.monotonic() - started)}")
    return 1 if failed or publish_failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
