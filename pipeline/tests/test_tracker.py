# SPDX-License-Identifier: AGPL-3.0-only
"""coverage.tracker — run/step rows and the coverage_source_freshness view,
against the dev PostGIS."""
import pytest

from coverage.load import ensure_schema
from coverage.tracker import RunTracker


def _steps(db, run_id):
    return db.execute(
        "SELECT region, step, seconds, bytes, rows, status, detail FROM coverage_run_step "
        "WHERE run_id = %s ORDER BY id", (run_id,)).fetchall()


def _run_row(db, run_id):
    return db.execute(
        "SELECT status, finished_at, regions_requested, regions_loaded, published_url "
        "FROM coverage_run WHERE id = %s", (run_id,)).fetchone()


def test_start_opens_a_running_run(db):
    ensure_schema(db)
    run_id = RunTracker(db).start("manual", 3)
    assert isinstance(run_id, int)
    assert _run_row(db, run_id) == ("running", None, 3, None, None)


def test_step_writes_an_ok_row_with_its_stats(db):
    ensure_schema(db)
    t = RunTracker(db)
    run_id = t.start("manual", 1)
    with t.step("europe/belgium", "load") as st:
        st.bytes = 1234
        st.rows = 7
        st.detail = "previous 5"
    (row,) = _steps(db, run_id)
    assert row[:2] == ("europe/belgium", "load")
    assert row[2] >= 0.0
    assert row[3:] == (1234, 7, "ok", "previous 5")


def test_raising_step_writes_failed_with_the_exception_and_reraises(db):
    ensure_schema(db)
    t = RunTracker(db)
    run_id = t.start("manual", 1)
    with pytest.raises(RuntimeError, match="md5 mismatch"):
        with t.step("europe/germany", "download") as st:
            st.bytes = 99
            raise RuntimeError("md5 mismatch after download")
    (row,) = _steps(db, run_id)
    assert row[:2] == ("europe/germany", "download")
    assert row[3] == 99
    assert row[5:] == ("failed", "RuntimeError: md5 mismatch after download")


def test_failed_detail_is_truncated(db):
    ensure_schema(db)
    t = RunTracker(db)
    run_id = t.start("manual", 1)
    with pytest.raises(ValueError):
        with t.step(None, "export"):
            raise ValueError("x" * 2000)
    (row,) = _steps(db, run_id)
    assert row[1] == "export" and row[0] is None
    assert len(row[6]) == 500


def test_record_writes_a_step_timed_elsewhere(db):
    ensure_schema(db)
    t = RunTracker(db)
    run_id = t.start("manual", 1)
    t.record("europe/belgium", "parse", 12.5, rows=3)
    assert _steps(db, run_id) == [("europe/belgium", "parse", 12.5, None, 3, "ok", None)]


def test_finish_fills_the_run_row(db):
    ensure_schema(db)
    t = RunTracker(db)
    run_id = t.start("dispatcher", 4)
    t.finish("partial", 3, "http://bucket/coverage/x.pmtiles")
    status, finished_at, requested, loaded, url = _run_row(db, run_id)
    assert (status, requested, loaded, url) == ("partial", 4, 3, "http://bucket/coverage/x.pmtiles")
    assert finished_at is not None


def test_attached_tracker_appends_steps_but_never_finishes(db):
    ensure_schema(db)
    owner = RunTracker(db)
    run_id = owner.start("dispatcher", 2)
    child = RunTracker(db)
    child.attach(run_id)
    with child.step("europe/belgium", "load"):
        pass
    child.finish("ok", 1, "http://should-not-land")
    assert [r[1] for r in _steps(db, run_id)] == ["load"]
    assert _run_row(db, run_id) == ("running", None, 2, None, None)


def test_freshness_view_reports_loaded_failed_latest_and_never_loaded(db):
    ensure_schema(db)
    db.execute("INSERT INTO coverage_source (slug) VALUES ('loaded'), ('failed-latest'), ('never')")
    run_id = RunTracker(db).start("manual", 3)
    db.execute(
        "INSERT INTO coverage_run_step (run_id, region, step, started_at, seconds, status) VALUES "
        "(%(r)s, 'loaded',        'load',     '2026-09-20 01:00+00', 10, 'ok'), "
        "(%(r)s, 'loaded',        'download', '2026-09-20 02:00+00', 10, 'ok'), "   # not a load step
        "(%(r)s, 'failed-latest', 'load',     '2026-09-18 01:00+00', 10, 'ok'), "
        "(%(r)s, 'failed-latest', 'load',     '2026-09-19 01:00+00', 10, 'failed'), "
        "(%(r)s, 'never',         'download', '2026-09-19 01:00+00', 10, 'ok')",     # never a load
        {"r": run_id})
    rows = {slug: (ts.isoformat() if ts else None, status) for slug, ts, status in db.execute(
        "SELECT slug, last_loaded_at, last_status FROM coverage_source_freshness").fetchall()}
    assert rows == {
        "loaded": ("2026-09-20T01:00:00+00:00", "ok"),
        "failed-latest": ("2026-09-18T01:00:00+00:00", "failed"),
        "never": (None, None),
    }
