# SPDX-License-Identifier: AGPL-3.0-only
"""run.py - the surface and routes runners' orchestration.

These walk the FRESH-extract path deliberately: the cached path was the only
one the suite exercised, which is how a rebind bug ("'SurfaceCounts' object is
not subscriptable") could sit in _run_surface marking every cold-cache region
failed while 140 tests stayed green and a warm-cache publish worked."""

from __future__ import annotations

import contextlib
import os
import json
import pathlib

import pytest
import shapely

from coverage import run
from coverage.contract import load_contract
from coverage.regions import ONBOARDED_REGIONS
from coverage.routes import RouteCounts
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
    monkeypatch.setattr(run, "_ownership", lambda workdir: None)
    monkeypatch.setattr(run, "_line_run_lock", lambda family: contextlib.nullcontext(True))
    monkeypatch.delenv("COVERAGE_FIRST_PUBLISH_PARTIAL", raising=False)
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


def test_a_failed_reextract_leaves_no_stale_surface_stamp(offline, contract, monkeypatch, capsys):
    """extract_region truncates its outputs first; a re-extract that raises
    mid-way must not leave the OLD stamp beside the now-truncated files, or
    the next run's _extract_is_current would call them current."""
    calls = []

    def good_extract(filtered, contract, *, classified_out, todo_out, gaps_out, ridtok="",
                     cctok="", route_way_ids=frozenset(), keep=None):
        calls.append("good")
        classified_out.write_text('{"f":1}\n')
        todo_out.write_text('{"f":2}\n')
        gaps_out.write_text("1\t1\t1.0\t0.0\t1\n")
        return SurfaceCounts(classified=1, todo=1, cells=1)

    monkeypatch.setattr(run, "extract_region", good_extract)
    assert _run_surface(["europe/netherlands"], offline, contract,
                        extract_only=True, publish=False) == 0
    stamp = offline / "surface_europe-netherlands.stamp"
    assert stamp.exists()

    def raising_extract(filtered, contract, *, classified_out, todo_out, gaps_out, ridtok="",
                        cctok="", route_way_ids=frozenset(), keep=None):
        calls.append("raise")
        # A killed osmium/tippecanoe leaves the outputs it had already
        # truncated and started rewriting, not untouched originals.
        classified_out.write_text('{"partial":1}\n')
        todo_out.write_text('{"partial":1}\n')
        gaps_out.write_text("1\t1\t1.0\t0.0\t1\n")
        raise RuntimeError("osmium killed")

    monkeypatch.setattr(run, "extract_region", raising_extract)
    monkeypatch.setenv("COVERAGE_FORCE_EXTRACT", "1")
    rc = _run_surface(["europe/netherlands"], offline, contract,
                      extract_only=True, publish=False)
    assert rc == 1
    assert not stamp.exists(), "a raise mid-extract must not leave the old stamp"
    assert "europe/netherlands FAILED" in capsys.readouterr().err

    monkeypatch.delenv("COVERAGE_FORCE_EXTRACT", raising=False)
    monkeypatch.setattr(run, "extract_region", good_extract)
    assert _run_surface(["europe/netherlands"], offline, contract,
                        extract_only=True, publish=False) == 0
    assert calls == ["good", "raise", "good"], "the next run must re-extract, not reuse the truncated files"


def test_a_failed_reextract_leaves_no_stale_routes_stamp(offline, contract, monkeypatch, capsys):
    from coverage.routes import RouteCounts
    calls = []

    def good_extract(filtered, contract, *, ways_out, nodes_out, wayids_out, ridtok="", cctok="",
                     keep=None):
        calls.append("good")
        ways_out.write_text('{"f":1}\n')
        nodes_out.write_text("")
        wayids_out.write_text("41\n")
        return RouteCounts(ways=1, nodes=0, relations=1)

    monkeypatch.setattr(run, "routes_extract_region", good_extract)
    assert _run_routes(["europe/netherlands"], offline, contract,
                       extract_only=True, publish=False) == 0
    stamp = offline / "routes_europe-netherlands.stamp"
    assert stamp.exists()

    def raising_extract(filtered, contract, *, ways_out, nodes_out, wayids_out, ridtok="", cctok="",
                        keep=None):
        calls.append("raise")
        ways_out.write_text('{"partial":1}\n')
        nodes_out.write_text("")
        wayids_out.write_text("41\n")
        raise RuntimeError("osmium killed")

    monkeypatch.setattr(run, "routes_extract_region", raising_extract)
    monkeypatch.setenv("COVERAGE_FORCE_EXTRACT", "1")
    rc = _run_routes(["europe/netherlands"], offline, contract,
                     extract_only=True, publish=False)
    assert rc == 1
    assert not stamp.exists(), "a raise mid-extract must not leave the old stamp"

    monkeypatch.delenv("COVERAGE_FORCE_EXTRACT", raising=False)
    monkeypatch.setattr(run, "routes_extract_region", good_extract)
    assert _run_routes(["europe/netherlands"], offline, contract,
                       extract_only=True, publish=False) == 0
    assert calls == ["good", "raise", "good"], "the next run must re-extract, not reuse the truncated files"


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
    calls = {"built": [], "published": None, "builds": {}, "live": {"version": 2, "countries": {}},
             "live_v2": True}
    def fake_build(layer_files, out, contract, **kw):
        calls["built"].append(out.name)
        out.write_bytes(b"pm")
    monkeypatch.setattr(run, "build_surface_pmtiles", fake_build)
    monkeypatch.setattr(run, "build_gaps_pmtiles", lambda files, out, c: out.write_bytes(b"pm"))
    monkeypatch.setattr(run, "artifact_bounds", lambda p: [0.0, 0.0, 1.0, 1.0])
    monkeypatch.setattr(run, "read_live_manifest", lambda family: (calls["live"], calls["live_v2"]))
    monkeypatch.setattr(run, "ensure_bucket", lambda: None)
    def fake_publish(family, built, *, gaps=None, retire=()):
        calls["published"] = (family, sorted(built), gaps is not None, list(retire))
        calls["builds"] = dict(built)
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
                     "countries": {"nl": {"inputs": run._surface_inputs(offline, ["europe/netherlands"])}}}
    assert _run_surface(["europe/netherlands"], offline, contract) == 0
    assert tiled["built"] == []
    assert tiled["published"] is None


def test_country_with_a_missing_region_is_not_built(tiled, offline, contract, capsys):
    (offline / "north-america-us-california-latest.osm.pbf").write_bytes(b"pbf")
    assert _run_surface(["north-america/us/california"], offline, contract) == 0
    assert tiled["built"] == []
    assert tiled["published"] is None
    out = capsys.readouterr().out
    assert "[tiles] US: not tiled, this run lacks north-america/us/colorado" in out
    assert "[surface] gaps: not rebuilt, no current cells for " in out


def _current_cells(workdir, skip=()):
    """A current gap-cell file, one distinct cell each, for every onboarded region but `skip`."""
    for i, region in enumerate(ONBOARDED_REGIONS):
        if region in skip:
            continue
        slug = region.replace("/", "-")
        (workdir / f"surface_{slug}_gapcells.tsv").write_text(f"{100 + i}\t{100 + i}\t2.0\t0.0\t1\n")
        (workdir / f"surface_{slug}.stamp").write_text(
            run._region_stamp(workdir, workdir / f"{slug}-latest.osm.pbf"))


def _world_cells(workdir):
    return [json.loads(line)["properties"]
            for line in (workdir / "surface-gaps.geojsonl").read_text().splitlines()]


def test_a_subset_run_publishes_gaps_from_every_current_region(tiled, offline, contract):
    (offline / "north-america-us-california-latest.osm.pbf").write_bytes(b"pbf")
    _current_cells(offline, skip=("north-america/us/california",))
    assert _run_surface(["north-america/us/california"], offline, contract) == 0
    assert tiled["published"] == ("surface", [], True, [])
    cells = _world_cells(offline)
    # California's cell from this run (1 km), and one 2 km cell per other onboarded region.
    assert len(cells) == len(ONBOARDED_REGIONS)
    us = sorted(c["km"] for c in cells if c["cctok"] == "|US|")
    assert us == [1.0, 2.0]                      # California's, and Colorado's from its cell file
    assert {c["cctok"] for c in cells} >= {"|BE|", "|NL|", "|JP|", "|US|"}


def test_a_subset_run_without_a_regions_cells_publishes_no_gaps(tiled, offline, contract, capsys):
    (offline / "north-america-us-california-latest.osm.pbf").write_bytes(b"pbf")
    _current_cells(offline, skip=("north-america/us/california", "north-america/us/colorado"))
    assert _run_surface(["north-america/us/california"], offline, contract) == 0
    assert tiled["published"] is None
    assert "[surface] gaps: not rebuilt, no current cells for north-america/us/colorado\n" in capsys.readouterr().out


def test_a_stale_cell_file_does_not_feed_the_world_grid(tiled, offline, contract, capsys):
    (offline / "north-america-us-california-latest.osm.pbf").write_bytes(b"pbf")
    _current_cells(offline, skip=("north-america/us/california",))
    (offline / "surface_north-america-us-colorado.stamp").write_text("an-older-contract:outlines")
    assert _run_surface(["north-america/us/california"], offline, contract) == 0
    assert tiled["published"] is None
    assert "no current cells for north-america/us/colorado" in capsys.readouterr().out


def test_one_country_failing_to_build_still_publishes_the_others(tiled, offline, contract,
                                                                  monkeypatch, capsys):
    (offline / "europe-belgium-latest.osm.pbf").write_bytes(b"pbf")

    def flaky_build(layer_files, out, contract, **kw):
        if out.name == "surface-be.pmtiles":
            raise RuntimeError("tippecanoe died")
        out.write_bytes(b"pm")
    monkeypatch.setattr(run, "build_surface_pmtiles", flaky_build)
    assert _run_surface(["europe/belgium", "europe/netherlands"], offline, contract) == 1
    assert tiled["published"] == ("surface", ["NL"], False, [])
    assert "[surface] BE: build FAILED: tippecanoe died" in capsys.readouterr().err


def test_a_failed_gaps_build_still_publishes_the_countries(tiled, offline, contract, monkeypatch, capsys):
    _current_cells(offline, skip=("europe/netherlands",))

    def broken(files, out, c):
        raise RuntimeError("no disk")
    monkeypatch.setattr(run, "build_gaps_pmtiles", broken)
    assert _run_surface(["europe/netherlands"], offline, contract) == 1
    assert tiled["published"] == ("surface", ["NL"], False, [])
    assert "[surface] gaps: build FAILED: no disk" in capsys.readouterr().err


def test_a_failed_publish_exits_1_and_says_so(tiled, offline, contract, monkeypatch, capsys):
    def refused(family, built, *, gaps=None, retire=()):
        raise RuntimeError("S3 503")
    monkeypatch.setattr(run, "publish_countries", refused)
    assert _run_surface(["europe/netherlands"], offline, contract) == 1
    assert "[surface] publish FAILED: S3 503" in capsys.readouterr().err


def test_a_failed_manifest_read_publishes_nothing(tiled, offline, contract, monkeypatch, capsys):
    def unreadable(family):
        raise RuntimeError("S3 500")
    monkeypatch.setattr(run, "read_live_manifest", unreadable)
    assert _run_surface(["europe/netherlands"], offline, contract) == 1
    assert tiled["built"] == [] and tiled["published"] is None
    assert "[surface] manifest read FAILED: S3 500" in capsys.readouterr().err


def test_a_countrys_bounds_cover_both_arms(tiled, offline, contract, monkeypatch):
    monkeypatch.setattr(run, "artifact_bounds",
                        lambda p: [-1.0, 0.5, 0.5, 2.0] if "todo" in p.name else [0.0, 0.0, 1.0, 1.0])
    assert _run_surface(["europe/netherlands"], offline, contract) == 0
    assert tiled["builds"]["NL"].bounds == [-1.0, 0.0, 1.0, 2.0]


def test_first_v2_publish_must_cover_every_onboarded_country(tiled, offline, contract, monkeypatch, capsys):
    tiled["live_v2"] = False
    assert _run_surface(["europe/netherlands"], offline, contract) == 1
    assert tiled["built"] == [] and tiled["published"] is None
    assert "first per-country publish" in capsys.readouterr().err
    monkeypatch.setenv("COVERAGE_FIRST_PUBLISH_PARTIAL", "1")
    assert _run_surface(["europe/netherlands"], offline, contract) == 0
    assert tiled["published"][1] == ["NL"]


def test_first_v2_publish_with_retire_is_allowed(tiled, offline, contract):
    tiled["live_v2"] = False
    assert _run_surface(["europe/netherlands"], offline, contract, retire=("xx",)) == 0
    assert tiled["published"] == ("surface", ["NL"], False, ["xx"])


def test_first_v2_publish_refuses_when_a_countrys_build_failed(tiled, offline, contract,
                                                                monkeypatch, capsys):
    """A country present in this run but whose TILING failed must block a
    first v2 publish exactly like one missing from the run: publishing around
    it would still take that country's v1 archive off the map."""
    tiled["live_v2"] = False
    (offline / "europe-belgium-latest.osm.pbf").write_bytes(b"pbf")
    monkeypatch.setattr(run, "ONBOARDED_REGIONS", ("europe/belgium", "europe/netherlands"))
    monkeypatch.setattr(run, "COUNTRY_BY_REGION",
                        {"europe/belgium": "BE", "europe/netherlands": "NL"})

    def flaky_build(layer_files, out, contract, **kw):
        if out.name == "surface-be.pmtiles":
            raise RuntimeError("tippecanoe died")
        out.write_bytes(b"pm")
    monkeypatch.setattr(run, "build_surface_pmtiles", flaky_build)

    assert _run_surface(["europe/belgium", "europe/netherlands"], offline, contract) == 1
    assert tiled["published"] is None
    err = capsys.readouterr().err
    assert "[surface] BE: build FAILED: tippecanoe died" in err
    assert "first per-country publish refused" in err and "lacks BE" in err


def test_first_v2_publish_with_retire_is_allowed_despite_a_failed_build(tiled, offline, contract,
                                                                        monkeypatch):
    tiled["live_v2"] = False
    (offline / "europe-belgium-latest.osm.pbf").write_bytes(b"pbf")
    monkeypatch.setattr(run, "ONBOARDED_REGIONS", ("europe/belgium", "europe/netherlands"))
    monkeypatch.setattr(run, "COUNTRY_BY_REGION",
                        {"europe/belgium": "BE", "europe/netherlands": "NL"})

    def flaky_build(layer_files, out, contract, **kw):
        if out.name == "surface-be.pmtiles":
            raise RuntimeError("tippecanoe died")
        out.write_bytes(b"pm")
    monkeypatch.setattr(run, "build_surface_pmtiles", flaky_build)

    assert _run_surface(["europe/belgium", "europe/netherlands"], offline, contract,
                        retire=("xx",)) == 1
    assert tiled["published"] == ("surface", ["NL"], True, ["xx"])


def test_a_surface_run_already_running_exits_2(offline, contract, monkeypatch, capsys):
    monkeypatch.setattr(run, "_line_run_lock", lambda family: contextlib.nullcontext(False))
    monkeypatch.setattr(run, "extract_region", lambda *a, **k: pytest.fail("extracted under a held lock"))
    assert _run_surface(["europe/netherlands"], offline, contract) == 2
    assert "another surface run holds the run lock" in capsys.readouterr().err


def test_a_dev_fixture_surface_run_extracts_and_tiles_nothing(offline, contract, monkeypatch, capsys):
    fixture = offline / "fixture.osm.pbf"
    fixture.write_bytes(b"pbf")
    monkeypatch.setenv("COVERAGE_PBF_PATH", str(fixture))
    seen = {}

    def fake_extract(filtered, contract, *, classified_out, todo_out, gaps_out, cctok="", keep=None, **kw):
        seen["cctok"] = cctok
        classified_out.write_text('{"f":1}\n')
        todo_out.write_text('{"f":2}\n')
        gaps_out.write_text("1\t1\t1.0\t0.0\t1\n")
        return SurfaceCounts(classified=1, todo=1, cells=1)
    monkeypatch.setattr(run, "extract_region", fake_extract)
    monkeypatch.setattr(run, "read_live_manifest", lambda family: pytest.fail("a dev run reads no manifest"))
    assert _run_surface(["dev/fixture"], offline, contract) == 0
    assert seen, "the dev region is still extracted"
    assert "[tiles] dev/fixture: a dev/ region, extracted but not tiled per country" in capsys.readouterr().out


def test_a_region_that_is_not_onboarded_says_so(capsys):
    assert run._complete_countries(["europe/germany/bayern"]) == {}
    assert ("[tiles] europe/germany/bayern: not an onboarded region, not tiled per country"
            in capsys.readouterr().out)


def _boxed_pbf(path, box):
    import osmium
    header = osmium.io.Header()
    header.add_box(osmium.osm.Box(osmium.osm.Location(box[0], box[1]), osmium.osm.Location(box[2], box[3])))
    path.unlink(missing_ok=True)
    writer = osmium.SimpleWriter(str(path), 4096, header)
    writer.add_node(osmium.osm.mutable.Node(id=1, location=((box[0] + box[2]) / 2, (box[1] + box[3]) / 2)))
    writer.close()


def _outline(id_, cc, box):
    return {"id": id_, "cc": cc, "area": 1.0, "wkb": shapely.to_wkb(shapely.box(*box), hex=True)}


def test_only_outlines_near_a_region_invalidate_its_extract(offline, contract, monkeypatch):
    calls = []

    def fake_extract(filtered, contract, *, classified_out, todo_out, gaps_out, keep=None, **kw):
        calls.append(1)
        classified_out.write_text('{"f":1}\n')
        todo_out.write_text('{"f":2}\n')
        gaps_out.write_text("1\t1\t1.0\t0.0\t1\n")
        return SurfaceCounts(classified=1, todo=1, cells=1)
    monkeypatch.setattr(run, "extract_region", fake_extract)
    _boxed_pbf(offline / "europe-netherlands-latest.osm.pbf", (3.3, 50.7, 7.3, 53.6))
    outlines = offline / "ownership-regions.json"

    def write(rows):
        outlines.write_text(json.dumps(rows, separators=(",", ":"), sort_keys=True))
    nl, be = _outline(1, "NL", (3.4, 50.8, 7.2, 53.5)), _outline(2, "BE", (2.5, 49.5, 6.4, 51.5))
    write([nl, be, _outline(3, "JP", (139.0, 35.0, 140.0, 36.0))])
    _run_surface(["europe/netherlands"], offline, contract, extract_only=True)
    write([nl, be, _outline(3, "JP", (139.0, 35.0, 141.0, 37.0))])     # Japan re-seeded
    _run_surface(["europe/netherlands"], offline, contract, extract_only=True)
    assert calls == [1]
    write([nl, _outline(2, "BE", (2.5, 49.5, 6.4, 51.6)), _outline(3, "JP", (139.0, 35.0, 141.0, 37.0))])
    _run_surface(["europe/netherlands"], offline, contract, extract_only=True)
    assert calls == [1, 1]


@pytest.fixture()
def routed(offline, contract, monkeypatch):
    """Stubs for everything after the routes extract."""
    calls = {"built": [], "published": None}

    def fake_build(ways, knoop, out, contract):
        calls["built"].append(out.name)
        out.write_bytes(b"pm")
    monkeypatch.setattr(run, "build_routes_pmtiles", fake_build)
    monkeypatch.setattr(run, "artifact_bounds", lambda p: [0.0, 0.0, 1.0, 1.0])
    monkeypatch.setattr(run, "read_live_manifest", lambda family: ({"version": 2, "countries": {}}, True))
    monkeypatch.setattr(run, "ensure_bucket", lambda: None)

    def fake_publish(family, built, *, gaps=None, retire=()):
        calls["published"] = (family, sorted(built), list(retire))
        return {"countries": {}}
    monkeypatch.setattr(run, "publish_countries", fake_publish)
    monkeypatch.setattr(run, "prune_family", lambda family, manifest: [])

    def fake_extract(filtered, contract, *, ways_out, nodes_out, wayids_out, cctok="", keep=None, **kw):
        ways_out.write_text('{"f":1}\n')
        nodes_out.write_text("")
        wayids_out.write_text("41\n")
        return RouteCounts(ways=1, nodes=0, relations=1)
    monkeypatch.setattr(run, "routes_extract_region", fake_extract)
    (offline / "europe-belgium-latest.osm.pbf").write_bytes(b"pbf")
    return calls


def test_one_country_failing_its_routes_build_still_publishes_the_others(routed, offline, contract,
                                                                         monkeypatch, capsys):
    def flaky(ways, knoop, out, contract):
        if out.name == "routes-be.pmtiles":
            raise RuntimeError("tippecanoe died")
        out.write_bytes(b"pm")
    monkeypatch.setattr(run, "build_routes_pmtiles", flaky)
    assert _run_routes(["europe/belgium", "europe/netherlands"], offline, contract) == 1
    assert routed["published"] == ("routes", ["NL"], [])
    assert "[routes] BE: build FAILED: tippecanoe died" in capsys.readouterr().err


def test_a_failed_routes_publish_exits_1_and_says_so(routed, offline, contract, monkeypatch, capsys):
    def refused(family, built, *, gaps=None, retire=()):
        raise RuntimeError("S3 503")
    monkeypatch.setattr(run, "publish_countries", refused)
    assert _run_routes(["europe/netherlands"], offline, contract) == 1
    assert "[routes] publish FAILED: S3 503" in capsys.readouterr().err


def test_first_v2_routes_publish_must_cover_every_onboarded_country(routed, offline, contract,
                                                                   monkeypatch, capsys):
    monkeypatch.setattr(run, "read_live_manifest", lambda family: ({"version": 2, "countries": {}}, False))
    assert _run_routes(["europe/netherlands"], offline, contract) == 1
    assert routed["published"] is None
    assert "first per-country publish" in capsys.readouterr().err


def test_first_v2_routes_publish_refuses_when_a_countrys_build_failed(routed, offline, contract,
                                                                      monkeypatch, capsys):
    """A country present this run but whose TILING failed must block a first
    v2 routes publish, the same as one missing from the run."""
    monkeypatch.setattr(run, "read_live_manifest", lambda family: ({"version": 2, "countries": {}}, False))
    monkeypatch.setattr(run, "ONBOARDED_REGIONS", ("europe/belgium", "europe/netherlands"))
    monkeypatch.setattr(run, "COUNTRY_BY_REGION",
                        {"europe/belgium": "BE", "europe/netherlands": "NL"})

    def flaky(ways, knoop, out, contract):
        if out.name == "routes-be.pmtiles":
            raise RuntimeError("tippecanoe died")
        out.write_bytes(b"pm")
    monkeypatch.setattr(run, "build_routes_pmtiles", flaky)

    assert _run_routes(["europe/belgium", "europe/netherlands"], offline, contract) == 1
    assert routed["published"] is None
    err = capsys.readouterr().err
    assert "[routes] BE: build FAILED: tippecanoe died" in err
    assert "first per-country publish refused" in err and "lacks BE" in err


def test_a_routes_run_already_running_exits_2(offline, contract, monkeypatch, capsys):
    monkeypatch.setattr(run, "_line_run_lock", lambda family: contextlib.nullcontext(False))
    assert _run_routes(["europe/netherlands"], offline, contract) == 2
    assert "another routes run holds the run lock" in capsys.readouterr().err


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


def test_a_published_surface_run_reports_the_countries_it_rebuilt(tiled, offline, contract):
    rebuilt: list[str] = []
    assert _run_surface(["europe/netherlands"], offline, contract, rebuilt=rebuilt) == 0
    assert rebuilt == ["nl"]


def test_a_published_world_gap_grid_is_reported_as_gaps(tiled, offline, contract):
    (offline / "north-america-us-california-latest.osm.pbf").write_bytes(b"pbf")
    _current_cells(offline, skip=("north-america/us/california",))
    rebuilt: list[str] = []
    assert _run_surface(["north-america/us/california"], offline, contract, rebuilt=rebuilt) == 0
    assert rebuilt == ["gaps"]


def test_a_failed_surface_publish_reports_nothing_rebuilt(tiled, offline, contract, monkeypatch):
    def refused(family, built, *, gaps=None, retire=()):
        raise RuntimeError("S3 503")
    monkeypatch.setattr(run, "publish_countries", refused)
    rebuilt: list[str] = []
    assert _run_surface(["europe/netherlands"], offline, contract, rebuilt=rebuilt) == 1
    assert rebuilt == []


def test_an_extract_only_surface_run_reports_nothing_rebuilt(tiled, offline, contract):
    rebuilt: list[str] = []
    assert _run_surface(["europe/netherlands"], offline, contract, extract_only=True,
                        rebuilt=rebuilt) == 0
    assert rebuilt == []


def test_a_published_routes_run_reports_the_countries_it_rebuilt(routed, offline, contract):
    rebuilt: list[str] = []
    assert _run_routes(["europe/belgium", "europe/netherlands"], offline, contract,
                       rebuilt=rebuilt) == 0
    assert rebuilt == ["be", "nl"]


def test_a_failed_routes_publish_reports_nothing_rebuilt(routed, offline, contract, monkeypatch):
    def refused(family, built, *, gaps=None, retire=()):
        raise RuntimeError("S3 503")
    monkeypatch.setattr(run, "publish_countries", refused)
    rebuilt: list[str] = []
    assert _run_routes(["europe/netherlands"], offline, contract, rebuilt=rebuilt) == 1
    assert rebuilt == []


def _swap_pbf_under(offline):
    """What the other environment does: rename a fresh PBF onto the shared path
    while this run is still extracting. Its mtime is not newer than the extract."""
    pbf = offline / "europe-netherlands-latest.osm.pbf"
    fresh = offline / "incoming.pbf"
    fresh.write_bytes(b"a newer geofabrik build")
    old = pbf.stat().st_mtime
    os.utime(fresh, (old, old))
    fresh.replace(pbf)


def test_a_pbf_replaced_mid_surface_extract_is_re_extracted(offline, contract, monkeypatch):
    calls = []

    def extract(filtered, contract, *, classified_out, todo_out, gaps_out, ridtok="",
                cctok="", route_way_ids=frozenset(), keep=None):
        calls.append("extract")
        if len(calls) == 1:
            _swap_pbf_under(offline)
        classified_out.write_text('{"f":1}\n')
        todo_out.write_text('{"f":2}\n')
        gaps_out.write_text("1\t1\t1.0\t0.0\t1\n")
        return SurfaceCounts(classified=1, todo=1, cells=1)

    monkeypatch.setattr(run, "extract_region", extract)
    for _ in range(3):
        assert _run_surface(["europe/netherlands"], offline, contract,
                            extract_only=True, publish=False) == 0
    # Stale once (built from the replaced file), then current again.
    assert calls == ["extract", "extract"]


def test_a_pbf_replaced_mid_routes_extract_is_re_extracted(offline, contract, monkeypatch):
    from coverage.routes import RouteCounts
    calls = []

    def extract(filtered, contract, *, ways_out, nodes_out, wayids_out, ridtok="", cctok="",
                keep=None):
        calls.append("extract")
        if len(calls) == 1:
            _swap_pbf_under(offline)
        ways_out.write_text('{"f":1}\n')
        nodes_out.write_text("")
        wayids_out.write_text("41\n")
        return RouteCounts(ways=1, nodes=0, relations=1)

    monkeypatch.setattr(run, "routes_extract_region", extract)
    for _ in range(3):
        assert _run_routes(["europe/netherlands"], offline, contract,
                           extract_only=True, publish=False) == 0
    assert calls == ["extract", "extract"]
