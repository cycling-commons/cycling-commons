# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""run.py — Geofabrik download step (override, md5 skip, mismatch abort) and
main() orchestration (stage order, per-region failure isolation, exit code)."""
import hashlib
import io

import pytest

from coverage import run
from coverage.load import DriftAbort, LoadResult


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
    monkeypatch.delenv("COVERAGE_PBF_PATH", raising=False)

    def fake_urlopen(url, timeout=None):
        if url.endswith(".md5"):
            return io.BytesIO(b"0" * 32 + b"  europe-belgium-latest.osm.pbf\n")
        return io.BytesIO(b"corrupted download")

    monkeypatch.setattr(run.urllib.request, "urlopen", fake_urlopen)
    with pytest.raises(RuntimeError, match="md5 mismatch"):
        run.fetch_pbf("europe/belgium", tmp_path)
    assert not (tmp_path / "europe-belgium-latest.osm.pbf").exists()


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

    class FakeConn:
        def __enter__(self):
            return self

        def __exit__(self, *exc):
            return False

        def execute(self, sql, params=None):
            calls.append("counts-query")
            return FakeResult()

    def fake_load_region(conn, rows, region):
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
    monkeypatch.setattr(
        run, "fetch_pbf", lambda region, workdir: calls.append(f"fetch:{region}") or tmp_path / "in.pbf")
    monkeypatch.setattr(
        run, "run_extract", lambda pbf, out, contract: calls.append("extract") or out)
    monkeypatch.setattr(run, "parse_pois", lambda pbf, contract, region, cc: iter(()))
    monkeypatch.setattr(run, "load_region", fake_load_region)
    monkeypatch.setattr(
        run, "export_geojsonl",
        lambda conn, wd: calls.append("export") or {"C": tmp_path / "c.geojsonl"})
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
    assert manifests == [{"counts": {"C": 3, "D": 2}, "regions": ["dev/bad", "dev/ok"]}]
    err = capsys.readouterr().err
    assert "dev/bad: FAILED" in err and "simulated drift" in err
