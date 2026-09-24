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
    """An env-less --tiles-only run's coverage_run row requests all 22 onboarded
    regions — the 14-region fallback this replaced silently dropped eight
    countries. --tiles-only builds from the database, not from this list, but
    `regions_requested` is what an operator reads back to know what was asked for."""
    writes = []

    class FakeResult:
        def fetchone(self):
            return (True,)

    class FakeConn:
        def __enter__(self):
            return self

        def __exit__(self, *exc):
            return False

        def execute(self, sql, params=None):
            if isinstance(sql, str) and "INSERT INTO coverage_run " in sql:
                writes.append(params)
            return FakeResult()

        def commit(self):
            pass

    monkeypatch.delenv("COVERAGE_REGIONS", raising=False)
    monkeypatch.setenv("COVERAGE_WORKDIR", str(tmp_path))
    monkeypatch.setattr(run.psycopg, "connect", lambda dsn: FakeConn())
    monkeypatch.setattr(run, "ensure_schema", lambda conn: None)
    (tmp_path / "b.geojsonl").write_text('{"a":1}\n')
    monkeypatch.setattr(run, "export_geojsonl", lambda conn, wd: {("B", "BE"): tmp_path / "b.geojsonl"})
    monkeypatch.setattr(run, "build_pmtiles", lambda lf, out: out.write_bytes(b"pm"))
    monkeypatch.setattr(run, "verify_pmtiles", lambda path, expected_layers=None: None)
    monkeypatch.setattr(run, "artifact_bounds", lambda p: [0.0, 0.0, 1.0, 1.0])
    monkeypatch.setattr(run, "ensure_bucket", lambda: None)
    monkeypatch.setattr(run, "read_manifest", lambda family: {"version": 2, "countries": {}})
    monkeypatch.setattr(run, "publish_countries", lambda family, built, *, gaps=None, retire=(): {"countries": {}})
    monkeypatch.setattr(run, "prune_family", lambda family, manifest, keep=4: [])

    assert run.main(["--tiles-only"]) == 0
    assert writes == [("manual", 22)]
