# SPDX-License-Identifier: AGPL-3.0-only
"""coverage.regions: the onboarded extracts come from country_extract, and
resolve_country keeps its longest-prefix rule over them."""
import contextlib

import pytest

from catalog_rows import add_country
from coverage import regions, run
from coverage.load import resolve_country


def _seed(conn):
    add_country(conn, "BE", "live", ["europe/belgium"])
    add_country(conn, "US", "seeded", ["north-america/us/california", "north-america/us/colorado"])
    add_country(conn, "DK", "planned", ["europe/denmark"])


def test_onboarded_reads_seeded_and_live_countries_only(catalog):
    _seed(catalog)
    assert regions.onboarded(catalog) == [
        "europe/belgium", "north-america/us/california", "north-america/us/colorado"]
    assert regions.onboarded_map(catalog)["north-america/us/colorado"] == "US"


def test_default_regions_prefers_the_env(monkeypatch):
    monkeypatch.setenv("COVERAGE_REGIONS", " europe/belgium, asia/japan ,")
    assert regions.default_regions({"europe/netherlands": "NL"}) == ["europe/belgium", "asia/japan"]
    monkeypatch.setenv("COVERAGE_REGIONS", "")
    assert regions.default_regions({"europe/netherlands": "NL"}) == ["europe/netherlands"]
    monkeypatch.delenv("COVERAGE_REGIONS")
    monkeypatch.setattr(regions, "load_onboarded", lambda: {"asia/japan": "JP"})
    assert regions.default_regions() == ["asia/japan"]


def test_module_main_prints_the_onboarded_csv(catalog, monkeypatch, capsys):
    _seed(catalog)
    monkeypatch.setenv("COVERAGE_REGIONS", "europe/ignored")
    monkeypatch.setattr(regions.psycopg, "connect", lambda dsn: contextlib.nullcontext(catalog))
    assert regions.main() == 0
    assert capsys.readouterr().out.strip() == (
        "europe/belgium,north-america/us/california,north-america/us/colorado")


def test_resolve_country_uses_the_longest_onboarded_prefix():
    onboarded = {"europe/germany": "DE", "north-america/us/california": "US"}
    assert resolve_country("europe/germany", onboarded) == "DE"
    assert resolve_country("europe/germany/bayern", onboarded) == "DE"
    assert resolve_country("dev/fixture", onboarded) is None
    with pytest.raises(RuntimeError, match="country_extract"):
        resolve_country("north-america/us/texas", onboarded)


def test_main_without_regions_or_env_requests_every_onboarded_extract(monkeypatch, tmp_path):
    """An env-less --tiles-only run's coverage_run row names every onboarded extract."""
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
    monkeypatch.setattr(run, "_onboarded", lambda: {"europe/belgium": "BE", "asia/japan": "JP", "europe/spain": "ES"})
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
    assert writes == [("manual", 3, "points")]
