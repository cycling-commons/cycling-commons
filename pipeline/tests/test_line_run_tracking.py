# SPDX-License-Identifier: AGPL-3.0-only
"""run.main for --routes / --surface: one coverage_run row per run, or steps on
the dispatcher's run with --run-id. Written over two short connections, so no
Postgres session is held through an hours-long line build. The runners are a
seam here; their own behaviour is test_run_surface's."""
import contextlib

import psycopg
import pytest

from coverage import run
from coverage.load import ensure_schema
from coverage.tracker import RunTracker


@pytest.fixture()
def line_run(db, monkeypatch, tmp_path):
    """Script the line runners and log every DB connect and build, in order."""
    monkeypatch.setenv("COVERAGE_WORKDIR", str(tmp_path))
    script = {"rc": 0, "rebuild": [], "raise": None, "events": []}

    def connect(dsn):
        script["events"].append("connect")
        return contextlib.nullcontext(db)
    monkeypatch.setattr(run.psycopg, "connect", connect)

    def runner(regions, workdir, contract, *, extract_only=False, publish=True, retire=(),
               rebuilt=None):
        script["events"].append("build")
        if script["raise"]:
            raise script["raise"]
        rebuilt.extend(script["rebuild"])
        return script["rc"]
    monkeypatch.setattr(run, "_run_routes", runner)
    monkeypatch.setattr(run, "_run_surface", runner)
    return script


def _runs(db):
    return db.execute(
        "SELECT id, family, trigger, status, regions_requested, regions_loaded, published_url "
        "FROM coverage_run ORDER BY id").fetchall()


def _steps(db, run_id):
    return db.execute(
        "SELECT region, step, status, detail FROM coverage_run_step WHERE run_id = %s ORDER BY id",
        (run_id,)).fetchall()


def test_a_routes_extract_run_opens_its_own_row_with_one_extract_step(db, line_run):
    assert run.main(["--routes", "--extract-only", "--regions", "europe/belgium"]) == 0
    ((run_id, *row),) = _runs(db)
    assert row == ["routes", "manual", "ok", 1, None, None]
    assert _steps(db, run_id) == [("europe/belgium", "routes_extract", "ok", None)]


def test_a_surface_publish_run_records_the_countries_it_rebuilt(db, line_run):
    line_run["rebuild"] = ["be", "nl"]
    assert run.main(["--surface", "--regions", "europe/belgium,europe/netherlands",
                     "--trigger", "bootstrap"]) == 0
    ((run_id, *row),) = _runs(db)
    assert row == ["surface", "bootstrap", "ok", 2, None, "be,nl"]
    assert _steps(db, run_id) == [(None, "surface_publish", "ok", "be,nl")]


def test_a_failing_line_run_is_recorded_as_failed(db, line_run):
    line_run["rc"] = 1
    assert run.main(["--routes", "--regions", "europe/belgium"]) == 1
    ((run_id, *row),) = _runs(db)
    assert row[2] == "failed"
    assert _steps(db, run_id) == [("europe/belgium", "routes_publish", "failed", "rc 1")]


def test_a_raising_line_run_is_recorded_failed_and_the_error_propagates(db, line_run):
    line_run["raise"] = RuntimeError("osmium died")
    with pytest.raises(RuntimeError, match="osmium died"):
        run.main(["--surface", "--regions", "europe/belgium"])
    ((run_id, *row),) = _runs(db)
    assert row[2] == "failed"
    assert _steps(db, run_id) == [("europe/belgium", "surface_publish", "failed",
                                   "RuntimeError: osmium died")]


def test_with_run_id_the_step_joins_the_dispatchers_run_and_no_row_is_opened(db, line_run):
    ensure_schema(db)
    night = RunTracker(db).start("dispatcher", 22)
    db.commit()
    line_run["rebuild"] = ["nl"]
    assert run.main(["--routes", "--regions", "europe/belgium,europe/netherlands",
                     "--run-id", str(night)]) == 0
    assert [r[0] for r in _runs(db)] == [night]
    assert _runs(db)[0][3] == "running"          # the dispatcher finishes its own run
    assert _steps(db, night) == [(None, "routes_publish", "ok", "nl")]


def test_the_build_runs_between_two_short_connections(db, line_run):
    assert run.main(["--surface", "--regions", "europe/belgium"]) == 0
    assert line_run["events"] == ["connect", "build", "connect"]


def test_an_unreachable_database_does_not_stop_the_tile_run(db, line_run, monkeypatch, capsys):
    def down(dsn):
        line_run["events"].append("connect")
        raise psycopg.OperationalError("connection refused")
    monkeypatch.setattr(run.psycopg, "connect", down)
    assert run.main(["--routes", "--regions", "europe/belgium"]) == 0
    assert "build" in line_run["events"]
    assert "run not tracked" in capsys.readouterr().err
