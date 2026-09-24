# SPDX-License-Identifier: AGPL-3.0-only
"""coverage.dispatch — staleness ordering, budget and cap, publish-once, exit
codes. The freshness view and the estimates are real (db fixture); run.main is
a recorded seam returning the exit code each test scripts."""
import contextlib
import os

import pytest

from coverage import dispatch
from coverage.load import ensure_schema
from coverage.tracker import RunTracker


@pytest.fixture()
def night(db, monkeypatch):
    """Wire the dispatcher to the test schema and script run.main's exit codes.

    Returns (calls, rcs): calls collects every argv the dispatcher hands to
    run.main; rcs maps region -> rc for --load-only calls ("tiles" for the
    publish), default 0."""
    ensure_schema(db)
    calls: list[list[str]] = []
    rcs: dict[str, int] = {}

    def fake_run_main(argv):
        calls.append(argv)
        if "--tiles-only" in argv:
            return rcs.get("tiles", 0)
        return rcs.get(argv[argv.index("--regions") + 1], 0)

    monkeypatch.setattr(dispatch, "run_main", fake_run_main)
    monkeypatch.setattr(dispatch.psycopg, "connect", lambda dsn: contextlib.nullcontext(db))
    monkeypatch.setenv("COVERAGE_BUDGET_MIN", "240")
    monkeypatch.setenv("COVERAGE_MAX_REGIONS", "6")
    return calls, rcs


def _loads(calls):
    return [c[c.index("--regions") + 1] for c in calls if "--load-only" in c]


def _tiles(calls):
    return [c for c in calls if "--tiles-only" in c]


def _seed_load(db, slug, when, status="ok", run_id=None, steps=()):
    """A past run in which `slug` had a load step at `when` (plus extra ok steps)."""
    db.execute("INSERT INTO coverage_source (slug) VALUES (%s) ON CONFLICT DO NOTHING", (slug,))
    if run_id is None:
        run_id = RunTracker(db).start("dispatcher", 1)
    db.execute(
        "INSERT INTO coverage_run_step (run_id, region, step, started_at, seconds, status) "
        "VALUES (%s, %s, 'load', %s, 10, %s)", (run_id, slug, when, status))
    for name, seconds in steps:
        db.execute(
            "INSERT INTO coverage_run_step (run_id, region, step, started_at, seconds, status) "
            "VALUES (%s, %s, %s, %s, %s, 'ok')", (run_id, slug, name, when, seconds))
    db.commit()
    return run_id


def _run_status(db):
    return db.execute(
        "SELECT status, regions_loaded, published_url FROM coverage_run "
        "WHERE trigger = 'dispatcher' ORDER BY id DESC LIMIT 1").fetchone()


def test_never_loaded_regions_come_first_then_oldest(db, night, monkeypatch):
    calls, _ = night
    monkeypatch.setenv("COVERAGE_REGIONS", "a,b,c")
    _seed_load(db, "b", "2026-09-20 01:00+00")
    _seed_load(db, "c", "2026-09-18 01:00+00")
    assert dispatch.main() == 0
    assert _loads(calls) == ["a", "c", "b"]


def test_a_region_that_failed_last_night_sorts_before_one_that_succeeded(db, night, monkeypatch):
    calls, _ = night
    monkeypatch.setenv("COVERAGE_REGIONS", "x,y,z")
    _seed_load(db, "x", "2026-09-17 01:00+00")
    _seed_load(db, "x", "2026-09-20 01:00+00", status="failed")   # last night failed
    _seed_load(db, "y", "2026-09-20 01:00+00")                    # last night ok
    _seed_load(db, "z", "2026-09-20 01:00+00", status="failed")   # only ever failed
    assert dispatch.main() == 0
    assert _loads(calls) == ["z", "x", "y"]


def test_max_regions_caps_attempts(db, night, monkeypatch):
    calls, _ = night
    monkeypatch.setenv("COVERAGE_REGIONS", "a,b,c,d")
    monkeypatch.setenv("COVERAGE_MAX_REGIONS", "2")
    assert dispatch.main() == 0
    assert _loads(calls) == ["a", "b"]
    assert len(_tiles(calls)) == 1


def test_budget_stops_before_a_region_whose_estimate_does_not_fit(db, night, monkeypatch):
    calls, _ = night
    monkeypatch.setenv("COVERAGE_REGIONS", "fresh,big,small")
    monkeypatch.setenv("COVERAGE_BUDGET_MIN", "1")
    # big: 10 (load) + 50 + 30 = 90 s of ok steps -> 1.5 x = 135 s, over a 60 s budget.
    # A failed step in the same run is not counted; an older run is not the one used.
    run_id = _seed_load(db, "big", "2026-09-18 01:00+00", steps=[("download", 50), ("filter", 30)])
    db.execute(
        "INSERT INTO coverage_run_step (run_id, region, step, started_at, seconds, status) "
        "VALUES (%s, 'big', 'near_way', '2026-09-18 01:00+00', 999, 'failed')", (run_id,))
    _seed_load(db, "big", "2026-09-10 01:00+00", steps=[("download", 1)])
    _seed_load(db, "small", "2026-09-19 01:00+00", steps=[("download", 1)])
    db.commit()
    assert dispatch.estimate_seconds(db, "big") == pytest.approx(135.0)
    assert dispatch.estimate_seconds(db, "fresh") == 0.0
    db.commit()   # the SELECTs opened a txn; main() needs an idle connection to set autocommit
    assert dispatch.main() == 0
    # fresh (never loaded) always runs first; big does not fit, and the loop
    # stops there rather than skipping ahead to small.
    assert _loads(calls) == ["fresh"]
    assert len(_tiles(calls)) == 1


def test_tiles_only_runs_once_after_the_loads_with_the_whole_universe(db, night, monkeypatch):
    calls, _ = night
    monkeypatch.setenv("COVERAGE_REGIONS", "a,b")
    assert dispatch.main() == 0
    tiles = _tiles(calls)
    assert len(tiles) == 1
    assert tiles[0][tiles[0].index("--regions") + 1] == "a,b"
    run_id = str(db.execute("SELECT max(id) FROM coverage_run").fetchone()[0])
    assert all(c[c.index("--run-id") + 1] == run_id for c in calls if "--run-id" in c)
    assert all("dispatcher" in c for c in calls if "--load-only" in c)
    assert _run_status(db) == ("ok", 2, None)


def test_nothing_loaded_means_no_publish_and_a_failed_run(db, night, monkeypatch):
    calls, rcs = night
    monkeypatch.setenv("COVERAGE_REGIONS", "a,b")
    rcs.update(a=1, b=1)
    assert dispatch.main() == 1
    assert _loads(calls) == ["a", "b"]
    assert _tiles(calls) == []
    assert _run_status(db) == ("failed", 0, None)


def test_lock_held_aborts_the_night_with_rc_2(db, night, monkeypatch):
    calls, rcs = night
    monkeypatch.setenv("COVERAGE_REGIONS", "a,b,c")
    rcs.update(b=2)
    assert dispatch.main() == 2
    assert _loads(calls) == ["a", "b"]
    assert _tiles(calls) == []
    assert _run_status(db)[0] == "failed"


def test_a_failed_region_exits_1_but_the_publish_still_happens(db, night, monkeypatch):
    calls, rcs = night
    monkeypatch.setenv("COVERAGE_REGIONS", "a,b")
    rcs.update(a=1)

    real = dispatch.run_main

    def publishing_run_main(argv):
        rc = real(argv)
        if "--tiles-only" in argv:
            # What run.main's upload step leaves behind for the dispatcher to read back.
            t = RunTracker(db)
            t.attach(int(argv[argv.index("--run-id") + 1]))
            t.record(None, "upload", 3.0, detail="http://bucket/coverage/20260921.pmtiles")
        return rc

    monkeypatch.setattr(dispatch, "run_main", publishing_run_main)
    assert dispatch.main() == 1
    assert _loads(calls) == ["a", "b"]
    assert len(_tiles(calls)) == 1
    assert _run_status(db) == ("partial", 1, "http://bucket/coverage/20260921.pmtiles")


def test_a_failed_publish_exits_1(db, night, monkeypatch):
    calls, rcs = night
    monkeypatch.setenv("COVERAGE_REGIONS", "a")
    rcs["tiles"] = 1
    assert dispatch.main() == 1
    assert _run_status(db) == ("partial", 1, None)


def test_each_loaded_region_gets_its_line_extracts_then_one_publish_pass_per_family(
        db, night, monkeypatch):
    calls, _ = night
    monkeypatch.setenv("COVERAGE_REGIONS", "europe/belgium,europe/netherlands")
    assert dispatch.main() == 0
    flags = [tuple(a for a in c if a.startswith("--")) for c in calls]
    assert flags[:3] == [("--load-only", "--regions", "--run-id", "--trigger"),
                         ("--routes", "--extract-only", "--regions"),
                         ("--surface", "--extract-only", "--regions")]
    assert flags[-3:] == [("--tiles-only", "--regions", "--run-id"),
                          ("--routes", "--regions"),
                          ("--surface", "--regions")]


def test_a_failed_line_extract_is_recorded_but_does_not_stop_the_night(db, night, monkeypatch):
    calls, rcs = night
    monkeypatch.setenv("COVERAGE_REGIONS", "a,b")
    real = dispatch.run_main

    def flaky_run_main(argv):
        if "--routes" in argv and "--extract-only" in argv and argv[argv.index("--regions") + 1] == "a":
            calls.append(argv)
            return 1
        return real(argv)

    monkeypatch.setattr(dispatch, "run_main", flaky_run_main)
    assert dispatch.main() == 1
    assert _loads(calls) == ["a", "b"]
    assert len(_tiles(calls)) == 1
    assert _run_status(db)[0] == "partial"


def test_nothing_loaded_means_no_line_extracts_and_no_publish_pass(db, night, monkeypatch):
    calls, rcs = night
    monkeypatch.setenv("COVERAGE_REGIONS", "a,b")
    rcs.update(a=1, b=1)
    assert dispatch.main() == 1
    assert not any("--extract-only" in c for c in calls)
    assert not any(("--routes" in c or "--surface" in c) and "--tiles-only" not in c
                   and "--extract-only" not in c for c in calls)


def test_offline_restores_previous_env_value_including_on_exception(monkeypatch):
    monkeypatch.setenv("COVERAGE_PBF_OFFLINE", "0")
    with pytest.raises(ValueError):
        with dispatch._offline():
            assert os.environ["COVERAGE_PBF_OFFLINE"] == "1"
            raise ValueError("boom")
    assert os.environ["COVERAGE_PBF_OFFLINE"] == "0"

    monkeypatch.delenv("COVERAGE_PBF_OFFLINE", raising=False)
    with dispatch._offline():
        assert os.environ["COVERAGE_PBF_OFFLINE"] == "1"
    assert "COVERAGE_PBF_OFFLINE" not in os.environ
