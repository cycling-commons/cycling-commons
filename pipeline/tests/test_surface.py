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


def test_cycleway_wins_over_its_surface_tag(contract):
    # A rider wants to know it is a cycleway first and what it is paved with
    # second, so the highway value decides regardless of the surface tag.
    assert canonical_class("asphalt", "cycleway", contract) == "cycleway"
    assert canonical_class("gravel", "cycleway", contract) == "cycleway"
    assert canonical_class(None, "cycleway", contract) == "cycleway"


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
