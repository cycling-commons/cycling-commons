# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""run.py — Geofabrik download step (override, md5 skip, mismatch abort) and
main() orchestration (stage order, per-region failure isolation, exit code)."""
import hashlib
import io
import os

import psycopg
import pytest

from coverage import run
from coverage.load import DriftAbort, LoadResult
from coverage.run import COVERAGE_ADVISORY_LOCK_KEY, _acquire_run_lock


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


def test_fetch_pbf_skips_unchanged_download(monkeypatch, tmp_path):
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
            return [("C", 3), ("D", 2)]

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

    def fake_load_region(conn, rows, region, country_code):
        calls.append(f"load:{region}")
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
    monkeypatch.setattr(run, "load_region", fake_load_region)
    monkeypatch.setattr(
        run, "export_geojsonl",
        lambda conn, wd: calls.append("export") or {("C", "BE"): tmp_path / "c.geojsonl"})
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
    assert manifests == [{"counts": {"C": 3, "D": 2}, "regions": ["dev/bad", "dev/ok"],
                         "country_codes": ["BE"]}]
    err = capsys.readouterr().err
    assert "dev/bad: FAILED" in err and "simulated drift" in err


def test_country_by_region_stamps_netherlands():
    """europe/netherlands is a first-class coverage region — its POIs must be
    stamped country_code NL (country-onboarding-design.md §5). Resolved via
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
