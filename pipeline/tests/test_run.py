# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""run.py — Geofabrik download step: override, md5 skip-if-unchanged, mismatch abort."""
import hashlib
import io
import pathlib

import pytest

from coverage import run


def test_fetch_pbf_honours_override(monkeypatch, tmp_path):
    monkeypatch.setenv("COVERAGE_PBF_PATH", "/data/fixtures/mini.osm.pbf")

    def boom(*args, **kwargs):
        raise AssertionError("network touched despite COVERAGE_PBF_PATH")

    monkeypatch.setattr(run.urllib.request, "urlopen", boom)
    assert run.fetch_pbf("europe/belgium", tmp_path) == pathlib.Path("/data/fixtures/mini.osm.pbf")


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
