# SPDX-License-Identifier: AGPL-3.0-only
"""run.py — Geofabrik download step (override, md5 skip, mismatch abort) and
main() orchestration (stage order, per-region failure isolation, exit code)."""
import hashlib
import io
import os

import psycopg
import pytest

from coverage import run
from coverage.load import DriftAbort, LoadResult
from coverage.run import COVERAGE_ADVISORY_LOCK_KEY, _acquire_run_lock, _dur, _print_timings


class _Resp(io.BytesIO):
    """urlopen response double that also carries geturl() (the resolved,
    post-redirect URL fetch_pbf pins the .md5 verify to)."""

    def __init__(self, data: bytes, url: str = "") -> None:
        super().__init__(data)
        self._url = url

    def geturl(self) -> str:
        return self._url


def test_fetch_pbf_honours_override(monkeypatch, tmp_path):
    override = tmp_path / "mini.osm.pbf"
    override.write_bytes(b"local fixture bytes")
    monkeypatch.setenv("COVERAGE_PBF_PATH", str(override))

    def boom(*args, **kwargs):
        raise AssertionError("network touched despite COVERAGE_PBF_PATH")

    monkeypatch.setattr(run.urllib.request, "urlopen", boom)
    assert run.fetch_pbf("europe/belgium", tmp_path) == override


def test_fetch_pbf_override_missing_file_aborts(monkeypatch, tmp_path):
    missing = tmp_path / "nope.osm.pbf"
    monkeypatch.setenv("COVERAGE_PBF_PATH", str(missing))

    def boom(*args, **kwargs):
        raise AssertionError("network touched despite COVERAGE_PBF_PATH")

    monkeypatch.setattr(run.urllib.request, "urlopen", boom)
    with pytest.raises(RuntimeError, match="override PBF not found") as exc:
        run.fetch_pbf("europe/belgium", tmp_path)
    assert str(missing) in str(exc.value)


def test_fetch_pbf_offline_uses_the_local_file_without_asking(monkeypatch, tmp_path):
    # The surface build tiles every country again whenever one is added, and
    # that pass walks the whole region list. Without an offline mode it would
    # re-verify ~20 GB of PBFs to rebuild artifacts from extracts it already has.
    monkeypatch.delenv("COVERAGE_PBF_PATH", raising=False)
    monkeypatch.setenv("COVERAGE_PBF_OFFLINE", "1")
    dest = tmp_path / "europe-belgium-latest.osm.pbf"
    dest.write_bytes(b"last week's bytes")

    def boom(*args, **kwargs):
        raise AssertionError("network touched despite COVERAGE_PBF_OFFLINE")

    monkeypatch.setattr(run.urllib.request, "urlopen", boom)
    assert run.fetch_pbf("europe/belgium", tmp_path) == dest


def test_fetch_pbf_offline_without_a_local_file_aborts(monkeypatch, tmp_path):
    # Failing loudly beats falling through to a download the operator asked us
    # not to make, and beats an opaque osmium error on a file that is not there.
    monkeypatch.delenv("COVERAGE_PBF_PATH", raising=False)
    monkeypatch.setenv("COVERAGE_PBF_OFFLINE", "1")
    monkeypatch.setattr(run.urllib.request, "urlopen",
                        lambda *a, **k: (_ for _ in ()).throw(AssertionError("network touched")))
    with pytest.raises(RuntimeError, match="COVERAGE_PBF_OFFLINE"):
        run.fetch_pbf("europe/belgium", tmp_path)


def test_fetch_pbf_skips_unchanged_download(monkeypatch, tmp_path):
    monkeypatch.delenv("COVERAGE_PBF_OFFLINE", raising=False)
    monkeypatch.delenv("COVERAGE_PBF_PATH", raising=False)
    dest = tmp_path / "europe-belgium-latest.osm.pbf"
    dest.write_bytes(b"same bytes as last week")
    md5 = hashlib.md5(dest.read_bytes()).hexdigest()
    calls = []

    def fake_urlopen(url, timeout=None):
        calls.append(url)
        if url.endswith(".md5"):
            return io.BytesIO(f"{md5}  europe-belgium-latest.osm.pbf\n".encode())
        raise AssertionError("full PBF re-downloaded although md5 matched")

    monkeypatch.setattr(run.urllib.request, "urlopen", fake_urlopen)
    assert run.fetch_pbf("europe/belgium", tmp_path) == dest
    assert calls == ["https://download.geofabrik.de/europe/belgium-latest.osm.pbf.md5"]


def test_fetch_pbf_md5_mismatch_aborts(monkeypatch, tmp_path):
    """A genuinely corrupt download: NO mirror .md5 ever matches, so after the
    retry set the swap aborts and the .part is cleaned up."""
    monkeypatch.delenv("COVERAGE_PBF_PATH", raising=False)
    monkeypatch.setattr(run.time, "sleep", lambda *a, **k: None)  # no backoff in tests

    def fake_urlopen(url, timeout=None):
        if url.endswith(".md5"):
            return io.BytesIO(b"0" * 32 + b"  europe-belgium-latest.osm.pbf\n")
        return _Resp(b"corrupted download", url)

    monkeypatch.setattr(run.urllib.request, "urlopen", fake_urlopen)
    with pytest.raises(RuntimeError, match="md5 mismatch"):
        run.fetch_pbf("europe/belgium", tmp_path)
    assert not (tmp_path / "europe-belgium-latest.osm.pbf").exists()
    assert not (tmp_path / "europe-belgium-latest.osm.part").exists()


def test_fetch_pbf_recovers_when_md5_mirror_lags(monkeypatch, tmp_path):
    """The download is VALID but the first .md5 reads (a lagging Geofabrik
    mirror) disagree; a later retry (another mirror) matches the bytes we got —
    the load must succeed, not crash. This is the europe/germany failure mode."""
    monkeypatch.delenv("COVERAGE_PBF_PATH", raising=False)
    monkeypatch.setattr(run.time, "sleep", lambda *a, **k: None)
    pbf = b"valid germany extract bytes"
    good = hashlib.md5(pbf).hexdigest()
    stale = "0" * 32
    n = {"md5": 0}

    def fake_urlopen(url, timeout=None):
        if url.endswith(".md5"):
            n["md5"] += 1
            # skip-check (1) + first verify (2) lag; the second verify converges
            h = good if n["md5"] >= 3 else stale
            return io.BytesIO(f"{h}  europe-germany-latest.osm.pbf\n".encode())
        return _Resp(pbf, url)

    monkeypatch.setattr(run.urllib.request, "urlopen", fake_urlopen)
    dest = run.fetch_pbf("europe/germany", tmp_path)
    assert dest.read_bytes() == pbf
    assert not (tmp_path / "europe-germany-latest.osm.part").exists()


def test_main_stage_order_and_region_failure_isolation(monkeypatch, tmp_path, capsys):
    """Two regions, the first drift-aborts: the loop continues, the artifact
    still ships (verify BEFORE upload, prune AFTER), and main() exits non-zero
    — coverage-provider.md §3 failure mode. Pure python: every stage seam and
    the DB connection are monkeypatched."""
    calls = []
    manifests = []

    class FakeResult:
        def fetchall(self):
            return [("B", 3), ("D", 2)]

        def fetchone(self):
            return (True,)   # pg_try_advisory_lock → acquired (design §3.2)

    class FakeConn:
        def __enter__(self):
            return self

        def __exit__(self, *exc):
            return False

        def execute(self, sql, params=None):
            # Only the real GROUP BY counts query is recorded; the session-budget
            # SET statements (sql.Composed) and the advisory-lock SELECT are the
            # new run.main() preamble (design §3.1/§3.2) and are no-ops here.
            if isinstance(sql, str) and "count(*)" in sql:
                calls.append("counts-query")
            return FakeResult()

        def commit(self):
            pass

    def fake_load_region(conn, rows, region, country_code, near_ways=None, name_or_tags=None,
                         exclude_tag_values=None):
        calls.append(f"load:{region}")
        # Scenic points must sit along a bike way: the run hands the rule over.
        assert near_ways is not None and near_ways[0] == {"P": 250.0}
        assert name_or_tags == {"P": ["image", "wikidata", "wikimedia_commons"],
                                "Q": ["image", "wikidata", "wikimedia_commons"]}
        assert exclude_tag_values == {"F": {"bicycle": ["no"], "cc:bicycle_from_route": ["no"],
                                            "usage": ["leisure", "tourism"]},
                                      "Q": {"memorial": [
            "bench", "blue_plaque", "ghost_bike", "grave", "plaque", "stolperstein", "tomb"]}}
        if region == "dev/bad":
            raise DriftAbort("simulated drift: 1 row vs 100 previously")
        return LoadResult(inserted=7, previous=5)

    def fake_upload(artifact, manifest):
        calls.append("upload")
        manifests.append(manifest)
        return "http://bucket/coverage/20260716-0400.pmtiles"

    monkeypatch.setenv("COVERAGE_WORKDIR", str(tmp_path))
    monkeypatch.setattr(run.psycopg, "connect", lambda dsn: FakeConn())
    monkeypatch.setattr(run, "ensure_schema", lambda conn: calls.append("schema"))
    # dev/bad and dev/ok are placeholder slugs for this orchestration test, not
    # real countries — resolve_country only special-cases the literal
    # "dev/fixture" (I1), so any other unconfigured slug now hard-fails. Stub it
    # out here so this test keeps exercising failure-isolation/exit-code
    # mechanics, not country resolution.
    monkeypatch.setattr(run, "resolve_country", lambda region: None)
    monkeypatch.setattr(
        run, "fetch_pbf", lambda region, workdir: calls.append(f"fetch:{region}") or tmp_path / "in.pbf")
    monkeypatch.setattr(
        run, "run_extract", lambda pbf, out, contract: calls.append("extract") or out)
    monkeypatch.setattr(run, "parse_pois", lambda pbf, contract, region, cc: iter(()))
    monkeypatch.setattr(run, "run_way_filter", lambda pbf, out, rule: calls.append("ways") or out)
    monkeypatch.setattr(run, "export_lines", lambda path: iter(()))
    monkeypatch.setattr(run, "load_region", fake_load_region)
    monkeypatch.setattr(
        run, "export_geojsonl",
        lambda conn, wd: calls.append("export") or {("B", "BE"): tmp_path / "b.geojsonl"})
    monkeypatch.setattr(run, "build_pmtiles", lambda lf, out: calls.append("build"))
    monkeypatch.setattr(
        run, "verify_pmtiles",
        lambda path, expected_layers=None: calls.append("verify"))
    monkeypatch.setattr(run, "ensure_bucket", lambda: calls.append("bucket"))
    monkeypatch.setattr(run, "upload", fake_upload)
    monkeypatch.setattr(run, "prune", lambda keep=4: calls.append("prune") or [])

    rc = run.main(["--regions", "dev/bad,dev/ok"])

    assert rc == 1  # the drift-aborted region surfaces as a non-zero exit
    # Failure isolation: dev/bad aborts, dev/ok still loads afterwards.
    assert calls.index("load:dev/ok") > calls.index("load:dev/bad")
    # The artifact still ships, gated in order: verify → upload → prune.
    assert calls.index("verify") < calls.index("upload") < calls.index("prune")
    assert calls.index("build") < calls.index("verify")
    # Manifest: table-wide counts, this run's regions (shape locked).
    assert manifests == [{"counts": {"B": 3, "D": 2}, "regions": ["dev/bad", "dev/ok"],
                         "country_codes": ["BE"]}]
    err = capsys.readouterr().err
    assert "dev/bad: FAILED" in err and "simulated drift" in err


def test_country_by_region_stamps_netherlands():
    """europe/netherlands is a first-class coverage region — its POIs must be
    stamped country_code NL (tools/divisions/README.md). Resolved via
    resolve_country (I1's longest-prefix lookup), not a bare dict read — that
    lookup is what run.py now calls at the two country_code call sites."""
    from coverage.load import resolve_country
    assert resolve_country("europe/netherlands") == "NL"


def test_run_lock_is_exclusive_across_sessions(db):
    """A second session cannot take the run lock while the first holds it —
    a staggered timer + a manual refresh can no longer overlap (design §3.2)."""
    assert COVERAGE_ADVISORY_LOCK_KEY  # a fixed non-zero bigint
    other = psycopg.connect(os.environ["DATABASE_DSN"])
    try:
        assert _acquire_run_lock(db) is True
        assert _acquire_run_lock(other) is False
    finally:
        other.close()  # db releases its lock at fixture teardown (conn.close)


# ---- run timings -----------------------------------------------------------
# A full refresh is 22 country extracts and hours of wall clock, so the log has
# to answer "which region is eating the run?" without the reader doing sums.


@pytest.mark.parametrize("seconds,expected", [
    (0.42, "0.4s"),
    (41.23, "41.2s"),
    (59.94, "59.9s"),        # still seconds; the minute boundary is exact
    (60, "1m00s"),
    (137, "2m17s"),
    (3599, "59m59s"),
    (3600, "1h00m"),
    (45296, "12h34m"),       # a planet-scale run must not read as "755m"
])
def test_dur_reads_at_a_glance(seconds, expected):
    assert _dur(seconds) == expected


def test_timings_are_listed_slowest_first_and_mark_failures(capsys):
    _print_timings(
        [("europe/belgium", 41.2, True), ("asia/japan", 3.1, False), ("europe/france", 258.0, True)],
        302.3,
    )
    lines = [ln for ln in capsys.readouterr().out.splitlines() if ln]

    assert "3 regions, 2 ok, 1 failed" in lines[0]
    assert [ln.split()[1] for ln in lines[1:4]] == ["europe/france", "europe/belgium", "asia/japan"]
    assert lines[3].endswith("FAILED")
    assert lines[-1] == "[coverage] total 5m02s"


def test_timings_stay_quiet_when_no_region_ran(capsys):
    _print_timings([], 0.0)
    assert capsys.readouterr().out == ""


def test_main_tiles_only_skips_the_harvest_and_still_publishes(monkeypatch, tmp_path):
    """--tiles-only: no fetch/extract/load for any region, but the artifact is
    still exported, built, verified, uploaded and pruned in that order, with
    the manifest carrying the region list given. Added for the 2026-08-25
    letter renumbering: the rows changed, the OSM data did not."""
    calls = []
    manifests = []

    class FakeResult:
        def fetchall(self):
            return [("B", 3)]

        def fetchone(self):
            return (True,)

    class FakeConn:
        def __enter__(self):
            return self

        def __exit__(self, *exc):
            return False

        def execute(self, sql, params=None):
            return FakeResult()

        def commit(self):
            pass

    monkeypatch.setenv("COVERAGE_WORKDIR", str(tmp_path))
    monkeypatch.setattr(run.psycopg, "connect", lambda dsn: FakeConn())
    monkeypatch.setattr(run, "ensure_schema", lambda conn: calls.append("schema"))
    monkeypatch.setattr(run, "resolve_country", lambda region: calls.append("resolve"))
    monkeypatch.setattr(run, "fetch_pbf", lambda region, workdir: calls.append("fetch"))
    monkeypatch.setattr(run, "run_extract", lambda pbf, out, contract: calls.append("extract"))
    monkeypatch.setattr(run, "parse_pois", lambda pbf, contract, region, cc: calls.append("parse"))
    monkeypatch.setattr(run, "load_region", lambda conn, rows, region, cc: calls.append("load"))
    monkeypatch.setattr(
        run, "export_geojsonl",
        lambda conn, wd: calls.append("export") or {("B", "BE"): tmp_path / "b.geojsonl"})
    monkeypatch.setattr(run, "build_pmtiles", lambda lf, out: calls.append("build"))
    monkeypatch.setattr(run, "verify_pmtiles", lambda path, expected_layers=None: calls.append("verify"))
    monkeypatch.setattr(run, "ensure_bucket", lambda: calls.append("bucket"))
    monkeypatch.setattr(run, "upload", lambda artifact, manifest: manifests.append(manifest) or calls.append("upload") or "u")
    monkeypatch.setattr(run, "prune", lambda keep=4: calls.append("prune") or [])

    rc = run.main(["--tiles-only", "--regions", "europe/belgium"])

    assert rc == 0
    assert not {"resolve", "fetch", "extract", "parse", "load"} & set(calls)
    assert calls == ["schema", "export", "build", "verify", "bucket", "upload", "prune"]
    assert manifests == [{"counts": {"B": 3}, "regions": ["europe/belgium"], "country_codes": ["BE"]}]


def test_main_load_only_loads_and_builds_no_tiles(monkeypatch, tmp_path):
    """--load-only: the regions load, and nothing is exported, built or
    published. For loading countries one at a time on a machine short of
    memory, with a single --tiles-only pass at the end."""
    calls = []

    class FakeResult:
        def fetchall(self):
            return [("B", 3)]

        def fetchone(self):
            return (True,)

    class FakeConn:
        def __enter__(self):
            return self

        def __exit__(self, *exc):
            return False

        def execute(self, sql, params=None):
            return FakeResult()

        def commit(self):
            pass

    monkeypatch.setenv("COVERAGE_WORKDIR", str(tmp_path))
    monkeypatch.setattr(run.psycopg, "connect", lambda dsn: FakeConn())
    monkeypatch.setattr(run, "ensure_schema", lambda conn: calls.append("schema"))
    monkeypatch.setattr(run, "resolve_country", lambda region: None)
    monkeypatch.setattr(run, "fetch_pbf", lambda region, workdir: tmp_path / "in.pbf")
    monkeypatch.setattr(run, "run_extract", lambda pbf, out, contract: out)
    monkeypatch.setattr(run, "parse_pois", lambda pbf, contract, region, cc: iter(()))
    monkeypatch.setattr(run, "run_way_filter", lambda pbf, out, rule: out)
    monkeypatch.setattr(run, "export_lines", lambda path: iter(()))
    monkeypatch.setattr(run, "load_region",
                        lambda conn, rows, region, cc, near_ways=None, name_or_tags=None, exclude_tag_values=None: calls.append("load") or LoadResult(1, 1))
    for name in ("export_geojsonl", "build_pmtiles", "verify_pmtiles", "ensure_bucket", "upload", "prune"):
        monkeypatch.setattr(run, name, lambda *a, _n=name, **k: calls.append(_n))

    rc = run.main(["--load-only", "--regions", "europe/belgium"])

    assert rc == 0
    assert calls == ["schema", "load"]


def test_main_records_a_step_row_per_stage(monkeypatch, tmp_path):
    """The timing tracker (worker build plan §2.11): every stage of a region
    lands as one coverage_run_step row, a failing stage as status 'failed' with
    no later rows for that region, and the run row closes as 'partial'."""
    writes = []

    class FakeResult:
        def fetchall(self):
            return [("B", 3)]

        def fetchone(self):
            return (True,)

    class FakeConn:
        def __enter__(self):
            return self

        def __exit__(self, *exc):
            return False

        def execute(self, sql, params=None):
            if isinstance(sql, str) and "coverage_run" in sql:
                writes.append((sql, params))
            return FakeResult()

        def commit(self):
            pass

    def fake_load_region(conn, rows, region, cc, near_ways=None, name_or_tags=None,
                         exclude_tag_values=None):
        list(rows)   # consume the parse stream, as the COPY does
        if region == "dev/bad":
            raise DriftAbort("shrank")
        return LoadResult(inserted=7, previous=5, dropped={"name:P": 3, "near_way": 1})

    pbf = tmp_path / "in.pbf"
    pbf.write_bytes(b"x" * 10)
    monkeypatch.setenv("COVERAGE_WORKDIR", str(tmp_path))
    monkeypatch.setattr(run.psycopg, "connect", lambda dsn: FakeConn())
    monkeypatch.setattr(run, "ensure_schema", lambda conn: None)
    monkeypatch.setattr(run, "resolve_country", lambda region: None)
    monkeypatch.setattr(run, "fetch_pbf", lambda region, workdir: pbf)
    monkeypatch.setattr(run, "run_extract", lambda pbf, out, contract: out)
    monkeypatch.setattr(run, "parse_pois", lambda pbf, contract, region, cc: iter(()))
    monkeypatch.setattr(run, "run_way_filter", lambda pbf, out, rule: out)
    monkeypatch.setattr(run, "export_lines", lambda path: iter(()))
    monkeypatch.setattr(run, "load_region", fake_load_region)
    monkeypatch.setattr(run, "export_geojsonl", lambda conn, wd: {("B", "BE"): tmp_path / "b.geojsonl"})
    monkeypatch.setattr(run, "build_pmtiles", lambda lf, out: out.write_bytes(b"pm" * 4))
    monkeypatch.setattr(run, "verify_pmtiles", lambda path, expected_layers=None: None)
    monkeypatch.setattr(run, "ensure_bucket", lambda: None)
    monkeypatch.setattr(run, "upload", lambda artifact, manifest: "http://bucket/c.pmtiles")
    monkeypatch.setattr(run, "prune", lambda keep=4: [])

    assert run.main(["--regions", "dev/bad,dev/ok", "--trigger", "bootstrap"]) == 1

    start = [p for (sql, p) in writes if "INSERT INTO coverage_run " in sql]
    assert start == [("bootstrap", 2)]
    steps = [p for (sql, p) in writes if "coverage_run_step" in sql]
    # (run_id, region, step, seconds, seconds, bytes, rows, status, detail)
    assert [(p[1], p[2], p[7]) for p in steps] == [
        ("dev/bad", "download", "ok"), ("dev/bad", "filter", "ok"),
        ("dev/bad", "near_way", "ok"), ("dev/bad", "load", "failed"),
        ("dev/ok", "download", "ok"), ("dev/ok", "filter", "ok"),
        ("dev/ok", "near_way", "ok"), ("dev/ok", "load", "ok"), ("dev/ok", "parse", "ok"),
        (None, "export", "ok"), (None, "tippecanoe", "ok"), (None, "upload", "ok"),
    ]
    by_key = {(p[1], p[2]): p for p in steps}
    assert by_key[("dev/bad", "download")][5] == 10 and by_key[("dev/bad", "download")][8] == "cached"
    assert by_key[("dev/bad", "load")][8] == "DriftAbort: shrank"
    # The load step carries its numbers as JSON so /admin/coverage-runs can read
    # them back per rule (coverage-runs-admin.md §3).
    assert by_key[("dev/ok", "load")][6:] == (
        7, "ok", '{"previous": 5, "dropped": {"name:P": 3, "near_way": 1}}')
    assert by_key[(None, "tippecanoe")][5] == 8
    assert by_key[(None, "upload")][8] == "http://bucket/c.pmtiles"
    finish = [p for (sql, p) in writes if "UPDATE coverage_run" in sql]
    assert finish == [("partial", 1, "http://bucket/c.pmtiles", True)]
