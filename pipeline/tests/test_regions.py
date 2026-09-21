# SPDX-License-Identifier: AGPL-3.0-only
"""coverage.regions — the code-level region list mirrors the compose default,
and an env-less run.main covers every onboarded region."""
import re
from pathlib import Path

import pytest

from coverage import run
from coverage.regions import ONBOARDED_REGIONS, default_regions

COMPOSE = Path(__file__).resolve().parents[2] / "developers/docker/compose.yaml"


def test_onboarded_regions_match_the_compose_default():
    # Only pipeline/ is mounted into the dev container; on the host the file is there.
    if not COMPOSE.is_file():
        pytest.skip(f"{COMPOSE} not reachable (run on the host to check compose parity)")
    m = re.search(r"COVERAGE_REGIONS: \$\{COVERAGE_REGIONS:-([^}]+)\}", COMPOSE.read_text())
    assert m, "COVERAGE_REGIONS default not found in compose.yaml"
    assert tuple(m.group(1).split(",")) == ONBOARDED_REGIONS


def test_onboarded_regions_is_the_22_region_list():
    assert len(ONBOARDED_REGIONS) == 22
    assert len(set(ONBOARDED_REGIONS)) == 22
    assert "europe/spain" in ONBOARDED_REGIONS and "africa/rwanda" in ONBOARDED_REGIONS


def test_default_regions_prefers_the_env(monkeypatch):
    monkeypatch.setenv("COVERAGE_REGIONS", " europe/belgium, asia/japan ,")
    assert default_regions() == ["europe/belgium", "asia/japan"]
    monkeypatch.setenv("COVERAGE_REGIONS", "")
    assert default_regions() == list(ONBOARDED_REGIONS)
    monkeypatch.delenv("COVERAGE_REGIONS")
    assert default_regions() == list(ONBOARDED_REGIONS)


def test_main_without_regions_or_env_publishes_every_onboarded_region(monkeypatch, tmp_path):
    """The manifest of an env-less --tiles-only run lists all 22 regions — the
    14-region fallback this replaced silently dropped eight countries."""
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

    monkeypatch.delenv("COVERAGE_REGIONS", raising=False)
    monkeypatch.setenv("COVERAGE_WORKDIR", str(tmp_path))
    monkeypatch.setattr(run.psycopg, "connect", lambda dsn: FakeConn())
    monkeypatch.setattr(run, "ensure_schema", lambda conn: None)
    monkeypatch.setattr(run, "export_geojsonl", lambda conn, wd: {("B", "BE"): tmp_path / "b.geojsonl"})
    monkeypatch.setattr(run, "build_pmtiles", lambda lf, out: None)
    monkeypatch.setattr(run, "verify_pmtiles", lambda path, expected_layers=None: None)
    monkeypatch.setattr(run, "ensure_bucket", lambda: None)
    monkeypatch.setattr(run, "upload", lambda artifact, manifest: manifests.append(manifest) or "u")
    monkeypatch.setattr(run, "prune", lambda keep=4: [])

    assert run.main(["--tiles-only"]) == 0
    assert len(manifests) == 1
    assert manifests[0]["regions"] == list(ONBOARDED_REGIONS)
    assert len(manifests[0]["regions"]) == 22
