# SPDX-License-Identifier: AGPL-3.0-only
"""run.py — the surface and routes runners' orchestration.

These walk the FRESH-extract path deliberately: the cached path was the only
one the suite exercised, which is how a rebind bug ("'SurfaceCounts' object is
not subscriptable") could sit in _run_surface marking every cold-cache region
failed while 140 tests stayed green and a warm-cache publish worked."""

from __future__ import annotations

import json
import pathlib

import pytest

from coverage import run
from coverage.contract import load_contract
from coverage.run import _extract_is_current, _run_routes, _run_surface
from coverage.surface import SurfaceCounts


@pytest.fixture
def contract():
    return load_contract()


@pytest.fixture
def offline(monkeypatch, tmp_path):
    """A workdir with a fake PBF already in place, and no network anywhere."""
    monkeypatch.delenv("COVERAGE_PBF_PATH", raising=False)
    monkeypatch.delenv("COVERAGE_FORCE_EXTRACT", raising=False)
    monkeypatch.setenv("COVERAGE_PBF_OFFLINE", "1")
    (tmp_path / "europe-netherlands-latest.osm.pbf").write_bytes(b"pbf")
    monkeypatch.setattr(run, "run_filter", lambda pbf, out, exprs: out)
    monkeypatch.setattr(run, "_ownership", lambda workdir: (None, "test"))
    return tmp_path


def test_run_surface_fresh_extract_counts_and_succeeds(offline, contract, monkeypatch, capsys):
    """The rebind fix: a FRESH extract must reach the tally, not the except."""
    def fake_extract(filtered, contract, *, classified_out, todo_out, gaps_out,
                     ridtok="", cctok="", route_way_ids=frozenset(), keep=None):
        classified_out.write_text('{"f":1}\n{"f":2}\n')
        todo_out.write_text('{"f":3}\n')
        gaps_out.write_text("2100\t1360\t1.0\t0.0\t1\n")
        return SurfaceCounts(classified=2, todo=1, cells=1)

    monkeypatch.setattr(run, "extract_region", fake_extract)
    rc = _run_surface(["europe/netherlands"], offline, contract,
                      extract_only=True, publish=False)
    out = capsys.readouterr()
    assert "FAILED" not in out.err
    assert rc == 0
    assert "2 classified, 1 to record, 1 grid cells" in out.out


def test_run_surface_feeds_the_routes_way_ids_to_the_extract(offline, contract, monkeypatch):
    """Route-awareness is wired through the runner, not just available."""
    seen = {}

    def fake_extract(filtered, contract, *, classified_out, todo_out, gaps_out,
                     ridtok="", cctok="", route_way_ids=frozenset(), keep=None):
        seen["way_ids"] = route_way_ids
        for p in (classified_out, todo_out, gaps_out):
            p.write_text("")
        return SurfaceCounts(0, 0, 0)

    (offline / "routes_europe-netherlands_wayids.txt").write_text("41\n42\n")
    monkeypatch.setattr(run, "extract_region", fake_extract)
    rc = _run_surface(["europe/netherlands"], offline, contract,
                      extract_only=True, publish=False)
    assert rc == 0
    assert seen["way_ids"] == frozenset({41, 42})


def test_run_surface_says_so_when_route_awareness_is_missing(offline, contract,
                                                             monkeypatch, capsys):
    # The arm still builds, but the log must say why the Zuiderdijk would show
    # class-gated homework only.
    def fake_extract(filtered, contract, *, keep=None, **kw):
        for key in ("classified_out", "todo_out", "gaps_out"):
            kw[key].write_text("")
        return SurfaceCounts(0, 0, 0)

    monkeypatch.setattr(run, "extract_region", fake_extract)
    _run_surface(["europe/netherlands"], offline, contract,
                 extract_only=True, publish=False)
    assert "no routes extract in the workdir" in capsys.readouterr().out


def test_run_routes_extract_only_writes_the_three_outputs(offline, contract,
                                                          monkeypatch, capsys):
    from coverage.routes import RouteCounts

    def fake_extract(filtered, contract, *, ways_out, nodes_out, wayids_out, ridtok="", cctok="",
                     keep=None):
        assert cctok == "|NL|"
        ways_out.write_text('{"f":1}\n')
        nodes_out.write_text("")            # a country with no knooppunten
        wayids_out.write_text("41\n")
        return RouteCounts(ways=1, nodes=0, relations=1)

    monkeypatch.setattr(run, "routes_extract_region", fake_extract)
    rc = _run_routes(["europe/netherlands"], offline, contract,
                     extract_only=True, publish=False)
    out = capsys.readouterr()
    assert rc == 0
    assert "1 member ways on 1 routes, 0 knooppunten" in out.out
    assert (offline / "routes_europe-netherlands_wayids.txt").read_text() == "41\n"


def test_run_routes_reuses_an_extract_even_when_a_country_has_no_knooppunten(
        offline, contract, monkeypatch, capsys):
    # An empty knoop file is an ANSWER (no node network there), not a cache
    # miss — without allow_empty the region would re-extract weekly forever.
    from coverage.routes import RouteCounts

    calls = []

    def fake_extract(filtered, contract, *, ways_out, nodes_out, wayids_out, ridtok="", cctok="",
                     keep=None):
        calls.append(1)
        ways_out.write_text('{"f":1}\n')
        nodes_out.write_text("")
        wayids_out.write_text("41\n")
        return RouteCounts(1, 0, 1)

    monkeypatch.setattr(run, "routes_extract_region", fake_extract)
    _run_routes(["europe/netherlands"], offline, contract, extract_only=True, publish=False)
    _run_routes(["europe/netherlands"], offline, contract, extract_only=True, publish=False)
    assert calls == [1], "the second run must reuse the cached extract"
    assert "extract unchanged" in capsys.readouterr().out


def test_extract_is_stale_when_an_extra_input_is_newer(tmp_path):
    # The surface extract names the routes way-id file as an input: a fresh
    # --routes run must invalidate the surface extracts it would change.
    import os
    import time

    contract_file = tmp_path / "contract.json"
    contract_file.write_text("{}")
    extract = tmp_path / "surface.geojsonl"
    extract.write_text("data")
    wayids = tmp_path / "wayids.txt"
    assert _extract_is_current(extract, tmp_path / "no.pbf", contract_file,
                               extra_inputs=(wayids,))
    wayids.write_text("41\n")
    os.utime(wayids, (time.time() + 5, time.time() + 5))
    assert not _extract_is_current(extract, tmp_path / "no.pbf", contract_file,
                                   extra_inputs=(wayids,))


def test_a_changed_outline_snapshot_invalidates_the_extract(tmp_path):
    from coverage.run import _extract_is_current, extract_stamp
    extract = tmp_path / "x.geojsonl"
    extract.write_text("{}\n")
    stamp = tmp_path / "x.stamp"
    stamp.write_text(extract_stamp("aaaa"))
    pbf = tmp_path / "p.pbf"
    assert _extract_is_current(extract, pbf, tmp_path / "none.json", stamp, expected=extract_stamp("aaaa"))
    assert not _extract_is_current(extract, pbf, tmp_path / "none.json", stamp, expected=extract_stamp("bbbb"))


@pytest.fixture()
def tiled(offline, contract, monkeypatch):
    """Stubs for everything after the extract: tippecanoe, bounds, manifest, publish."""
    calls = {"built": [], "published": None, "live": {"version": 2, "countries": {}}}
    def fake_build(layer_files, out, contract, **kw):
        calls["built"].append(out.name)
        out.write_bytes(b"pm")
    monkeypatch.setattr(run, "build_surface_pmtiles", fake_build)
    monkeypatch.setattr(run, "build_gaps_pmtiles", lambda files, out, c: out.write_bytes(b"pm"))
    monkeypatch.setattr(run, "artifact_bounds", lambda p: [0.0, 0.0, 1.0, 1.0])
    monkeypatch.setattr(run, "read_manifest", lambda family: calls["live"])
    monkeypatch.setattr(run, "ensure_bucket", lambda: None)
    def fake_publish(family, built, *, gaps=None, retire=()):
        calls["published"] = (family, sorted(built), gaps is not None, list(retire))
        return {"countries": {}}
    monkeypatch.setattr(run, "publish_countries", fake_publish)
    monkeypatch.setattr(run, "prune_family", lambda family, manifest: [])
    def fake_extract(filtered, contract, *, classified_out, todo_out, gaps_out, ridtok="", cctok="",
                     route_way_ids=frozenset(), keep=None):
        classified_out.write_text('{"f":1}\n')
        todo_out.write_text('{"f":2}\n')
        gaps_out.write_text("1\t1\t1.0\t0.0\t1\n")
        return SurfaceCounts(classified=1, todo=1, cells=1)
    monkeypatch.setattr(run, "extract_region", fake_extract)
    return calls


def test_only_changed_countries_are_rebuilt(tiled, offline, contract):
    assert _run_surface(["europe/netherlands"], offline, contract) == 0
    assert tiled["published"][1] == ["NL"]
    # The live manifest now names exactly these inputs: the same run again builds nothing.
    tiled["built"].clear()
    tiled["published"] = None
    tiled["live"] = {"version": 2,
                     "countries": {"nl": {"inputs": run._surface_inputs(offline, "NL", ["europe/netherlands"])}},
                     "gaps": {"inputs": run._gaps_inputs(offline)}}
    assert _run_surface(["europe/netherlands"], offline, contract) == 0
    assert tiled["built"] == []
    assert tiled["published"] is None


def test_country_with_a_missing_region_is_not_built(tiled, offline, contract, capsys):
    (offline / "north-america-us-california-latest.osm.pbf").write_bytes(b"pbf")
    assert _run_surface(["north-america/us/california"], offline, contract) == 0
    assert tiled["published"] is None or "US" not in tiled["published"][1]
    assert "north-america/us/colorado" in capsys.readouterr().out


def test_complete_countries_groups_regions_by_country():
    got = run._complete_countries(["europe/belgium", "north-america/us/california", "north-america/us/colorado"])
    assert got == {"BE": ["europe/belgium"],
                   "US": ["north-america/us/california", "north-america/us/colorado"]}
    assert run._complete_countries(["north-america/us/california"]) == {}
    assert run._complete_countries(["dev/fixture"]) == {}


def test_an_empty_todo_arm_is_published_without_that_arm(tiled, offline, contract, monkeypatch):
    def only_classified(filtered, contract, *, classified_out, todo_out, gaps_out, **kw):
        classified_out.write_text('{"f":1}\n')
        todo_out.write_text("")
        gaps_out.write_text("")
        return SurfaceCounts(classified=1, todo=0, cells=0)
    monkeypatch.setattr(run, "extract_region", only_classified)
    assert _run_surface(["europe/netherlands"], offline, contract) == 0
    assert "surface-todo-nl.pmtiles" not in tiled["built"]
