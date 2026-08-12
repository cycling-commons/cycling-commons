# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Road-surface line layer (Dated/2026-08-09-surface-line-tiles-design.md)."""

from __future__ import annotations

import json

import pytest

from coverage.contract import load_contract
from coverage.surface import canonical_class, selector_expressions, write_geojsonl, SurfaceWay, _wanted


@pytest.fixture(scope="module")
def contract():
    return load_contract()


def test_road_type_is_not_a_surface_class(contract):
    """The colour answers ONE question: what is under the tyres.

    `highway=cycleway` used to override the surface value, so a purple line said
    "cycleway" while the line beside it said "asphalt" and neither could be read
    as a surface (owner decision 2026-08-12). Most Dutch cycleways are asphalt —
    not all — and the scale had no way to say which. Road type rides on as `hw`
    and the client draws it as its own channel.
    """
    assert canonical_class("asphalt", "cycleway", contract) == "paved"
    assert canonical_class("gravel", "cycleway", contract) == "gravel"
    # And a cycleway nobody has tagged is unrecorded, like any other road: it is
    # the to-do arm's business, not a colour claiming knowledge we lack.
    assert canonical_class(None, "cycleway", contract) == contract.surface["untaggedClass"]


@pytest.mark.parametrize("surface,expected", [
    ("asphalt", "paved"), ("concrete", "paved"), ("paving_stones", "paved"),
    ("gravel", "gravel"), ("fine_gravel", "gravel"), ("compacted", "gravel"),
    ("sett", "pave"), ("cobblestone", "pave"),
    ("ground", "dirt"), ("sand", "dirt"), ("grass", "dirt"),
    ("rock", "rock"),
])
def test_surface_values_map_to_their_class(surface, expected, contract):
    assert canonical_class(surface, "residential", contract) == expected


def test_an_unknown_surface_value_is_not_invented_into_a_class(contract):
    # Falling through to `unverified` is honest; guessing is not, and a made-up
    # class would render as a confident colour for something nobody has said.
    assert canonical_class("moon_dust", "residential", contract) == "unverified"
    assert canonical_class(None, "residential", contract) == "unverified"
    assert canonical_class("", "residential", contract) == "unverified"


def test_paths_are_gated_on_being_cycleable(contract):
    # Ungated, `path` drags in the whole hiking network — which carries surface
    # tags exactly like anything else, so the tags cannot be the filter.
    assert _wanted({"highway": "path", "bicycle": "yes"}, contract) == "path"
    assert _wanted({"highway": "path", "bicycle": "designated"}, contract) == "path"
    assert _wanted({"highway": "path"}, contract) is None
    assert _wanted({"highway": "path", "bicycle": "no"}, contract) is None
    # An ungated highway needs no such tag.
    assert _wanted({"highway": "track"}, contract) == "track"


def test_unrideable_and_noisy_highways_are_excluded(contract):
    for hw in ("motorway", "trunk", "motorway_link", "service"):
        assert _wanted({"highway": hw}, contract) is None, f"{hw} must not be selected"


def test_the_selector_filters_on_highway_not_surface(contract):
    # The untagged arm is defined by the ABSENCE of a surface tag, so a
    # `w/surface` filter could never see it — the classification happens after
    # the extract, not in it.
    exprs = selector_expressions(contract)
    assert all(e.startswith("w/highway=") for e in exprs)
    assert not any("surface" in e for e in exprs)
    assert len(exprs) == len(contract.surface["highways"])


def test_geojsonl_features_carry_the_tile_contract(tmp_path, contract):
    out = tmp_path / "s.geojsonl"
    n = write_geojsonl(
        [SurfaceWay("way/42", "gravel", "track", "", [(4.1234567, 50.7654321), (4.2, 50.8)])],
        out, ridtok="|7|", cctok="|BE|",
    )
    assert n == 1
    feature = json.loads(out.read_text().strip())
    assert feature["geometry"]["type"] == "LineString"
    assert feature["properties"] == {"cls": "gravel", "hw": "track", "ref": "way/42",
                                     "ridtok": "|7|", "cctok": "|BE|"}
    # Coordinates are rounded: 6 dp is ~11 cm, and the raw doubles would inflate
    # a country-scale file for precision no renderer can show.
    assert feature["geometry"]["coordinates"][0] == [4.123457, 50.765432]


def test_a_surface_value_may_not_sit_in_two_classes(contract):
    # Two classes claiming one value means the first match wins silently, and
    # the same road changes colour when somebody reorders the JSON.
    seen: dict[str, str] = {}
    for cls, values in contract.surface["classes"].items():
        for v in values:
            assert v not in seen, f"{v!r} is in both {seen.get(v)!r} and {cls!r}"
            seen[v] = cls


def test_a_named_way_carries_its_name(tmp_path):
    # The drawer headlines with it and the wizard prefills it, so a rider
    # correcting a street's surface never retypes a name we already have.
    out = tmp_path / "named.geojsonl"
    write_geojsonl(
        [SurfaceWay("way/7", "paved", "unclassified", "Rue du Puits Saint-Martin",
                    [(4.1, 50.7), (4.2, 50.8)])],
        out,
    )
    props = json.loads(out.read_text().strip())["properties"]
    assert props["name"] == "Rue du Puits Saint-Martin"


def test_an_unnamed_way_omits_the_key_entirely(tmp_path):
    # Most ways outside towns are unnamed. An empty string on every one of them
    # is weight in every tile a rider downloads, for nothing.
    out = tmp_path / "unnamed.geojsonl"
    write_geojsonl([SurfaceWay("way/8", "gravel", "track", "", [(4.1, 50.7), (4.2, 50.8)])], out)
    assert "name" not in json.loads(out.read_text().strip())["properties"]


# ── The to-do arm and the gap grid ─────────────────────────────────────────

def test_the_todo_arm_is_a_subset_of_the_extracted_network(contract):
    # It is filtered out of the classified pass, not selected separately, so a
    # highway named here but not extracted would promise an always-empty layer.
    todo = contract.surface["todo"]["highways"]
    assert todo, "the to-do arm must name at least one highway class"
    assert set(todo) <= set(contract.surface["highways"])


def test_the_todo_arm_carries_the_classes_where_nobody_can_predict(contract):
    """What counts as "nobody has said", by class.

    The measurement stands — on Belgium's tagged ways, primary is unpaved 0.1%
    of the time, secondary 0.5%, tertiary 2.5%, cycleway 0.0%, residential 7.2%
    — but it answered the wrong question for two of them. A statistically
    predictable road still draws as a HOLE when it has no tag and no arm, and a
    hole reads as a broken layer rather than as an editorial decision: the
    Zuiderdijk (7 untagged tertiary ways on a dijk) is the case that surfaced
    it. Tertiary and cycleway are back; the big through-roads and the
    residential grid stay out, where the hole is not where a rider looks.
    """
    todo = set(contract.surface["todo"]["highways"])
    # The genuinely uncertain ones: track is 88% unpaved when tagged, path is a
    # coin flip, and a rural unclassified lane is 6.8%.
    assert {"track", "path", "unclassified"} <= todo
    # Still out, by CLASS: confirming a primary road is asphalt is not work worth
    # asking for, and the residential grid is most of the bytes for the least
    # doubt. Tertiary and cycleway were briefly added and withdrawn — the gap
    # they addressed is real (an untagged tertiary on a signed route) but the
    # key is route membership, not class. See the handoff plan.
    for hw in ("primary", "secondary", "tertiary", "cycleway", "residential"):
        assert hw not in todo, f"{hw} is not homework by CLASS — route-awareness is the rule"


def test_the_grid_hands_over_to_the_lines_at_exactly_one_zoom(contract):
    # One legend row, two resolutions. A gap between them is a zoom where a
    # rider who asked "what needs recording?" sees nothing; an overlap draws
    # squares on top of the roads they summarise.
    assert contract.surface["gaps"]["maxZoom"] == contract.surface["todo"]["minZoom"]


def _way(ref, cls, hw, coords=((4.1, 50.7), (4.11, 50.7))):
    return SurfaceWay(ref, cls, hw, "", list(coords))


def test_one_pass_splits_the_arms_and_never_writes_a_way_twice(tmp_path, contract, monkeypatch):
    from coverage import surface as mod

    ways = [
        _way("way/1", "gravel", "track"),          # recorded, and a to-do class
        _way("way/2", "unverified", "track"),      # the homework
        _way("way/3", "unverified", "residential"),  # untagged, but predictable
        _way("way/4", "paved", "primary"),         # recorded
    ]
    monkeypatch.setattr(mod, "stream_surface_ways",
                        lambda pbf, contract, emit: [emit(w) for w in ways])
    counts = mod.extract_region(
        tmp_path / "ignored.pbf", contract,
        classified_out=tmp_path / "c.geojsonl", todo_out=tmp_path / "t.geojsonl",
        gaps_out=tmp_path / "g.geojsonl", cctok="|BE|")

    classified = [json.loads(l) for l in (tmp_path / "c.geojsonl").read_text().splitlines()]
    todo = [json.loads(l) for l in (tmp_path / "t.geojsonl").read_text().splitlines()]
    assert counts.classified == len(classified) == 2
    assert [f["properties"]["ref"] for f in classified] == ["way/1", "way/4"]
    # An untagged residential street is not homework, and a recorded track is
    # not either — the to-do arm is the intersection of "no answer" and "the
    # answer is unpredictable".
    assert counts.todo == len(todo) == 1
    assert todo[0]["properties"]["ref"] == "way/2"
    # No way may appear in both arms: the client draws them as two layers, and a
    # duplicated road would be drawn twice, at two weights, saying two things.
    assert not ({f["properties"]["ref"] for f in classified}
                & {f["properties"]["ref"] for f in todo})


def test_the_grid_counts_kilometres_of_todo_network_only(tmp_path, contract, monkeypatch):
    from coverage import surface as mod

    # Two unrecorded tracks and one recorded track in the same cell, plus an
    # untagged residential street that must not count as work.
    ways = [
        _way("way/1", "unverified", "track", [(4.10, 50.70), (4.20, 50.70)]),
        _way("way/2", "unverified", "track", [(4.10, 50.71), (4.20, 50.71)]),
        _way("way/3", "gravel", "track", [(4.10, 50.72), (4.20, 50.72)]),
        _way("way/4", "unverified", "residential", [(4.10, 50.73), (4.20, 50.73)]),
    ]
    monkeypatch.setattr(mod, "stream_surface_ways",
                        lambda pbf, contract, emit: [emit(w) for w in ways])
    counts = mod.extract_region(
        tmp_path / "ignored.pbf", contract,
        classified_out=tmp_path / "c.geojsonl", todo_out=tmp_path / "t.geojsonl",
        gaps_out=tmp_path / "g.geojsonl", cctok="|BE|")

    cells = [json.loads(l) for l in (tmp_path / "g.geojsonl").read_text().splitlines()]
    assert counts.cells == len(cells) == 1
    props = cells[0]["properties"]
    # ~7.1 km per way at this latitude, so two unrecorded of three tracks.
    assert props["n"] == 2
    assert 13.0 < props["km"] < 15.0
    assert props["pct"] == 67, "two unrecorded km-thirds of the to-do network"
    assert props["cctok"] == "|BE|"
    assert cells[0]["geometry"]["type"] == "Polygon"


def test_a_fully_recorded_cell_is_not_shipped(tmp_path, contract, monkeypatch):
    # A square drawn over finished work reads as "there is something to do here"
    # and is the one thing this layer must never say.
    from coverage import surface as mod

    monkeypatch.setattr(mod, "stream_surface_ways",
                        lambda pbf, contract, emit: emit(_way("way/1", "gravel", "track")))
    counts = mod.extract_region(
        tmp_path / "ignored.pbf", contract,
        classified_out=tmp_path / "c.geojsonl", todo_out=tmp_path / "t.geojsonl",
        gaps_out=tmp_path / "g.geojsonl")
    assert counts.cells == 0
    assert (tmp_path / "g.geojsonl").read_text() == ""


def test_the_grid_charges_a_way_to_the_cell_holding_its_midpoint(contract):
    # Documented behaviour, not an accident: at ~6 km cells, splitting a way
    # across the cells it crosses would cost a clipping pass per way to move a
    # rounding error between neighbouring squares.
    from coverage.surface import GapGrid

    grid = GapGrid(contract.surface["gaps"]["cellZoom"])
    grid.add(_way("way/1", "unverified", "track", [(4.10, 50.70), (4.11, 50.70)]), recorded=False)
    grid.add(_way("way/2", "unverified", "track", [(9.10, 45.70), (9.11, 45.70)]), recorded=False)
    assert len(list(grid.features())) == 2, "far-apart ways land in different cells"
