# SPDX-License-Identifier: AGPL-3.0-only
"""coverage_water: which water areas count as a big lake (coverage/water.py)."""

import json

from coverage import water


def _square(west: float, south: float, side: float) -> dict:
    ring = [[west, south], [west + side, south], [west + side, south + side], [west, south + side], [west, south]]
    return {"type": "Polygon", "coordinates": [ring]}


def _line(tags: dict, geometry: dict, kind: str = "relation", osm_id: int = 7) -> str:
    return "\x1e" + json.dumps({"type": "Feature", "geometry": geometry,
                                "properties": {**tags, "@type": kind, "@id": osm_id}}) + "\n"


def test_a_lake_is_standing_water():
    assert water.is_lake({"natural": "water", "water": "lake"})
    assert water.is_lake({"natural": "water"}), "natural=water with no water=* is a lake by OSM's default"
    assert water.is_lake({"natural": "water", "water": "reservoir"})
    assert water.is_lake({"landuse": "reservoir"})
    assert not water.is_lake({"natural": "water", "water": "river"})
    assert not water.is_lake({"natural": "water", "water": "canal"})
    assert not water.is_lake({"natural": "wood"})


def test_the_area_is_near_enough_and_a_hole_counts_against_it():
    # A tenth of a degree at 52 N: 11.1 km by 6.9 km.
    assert abs(water.approx_km2(_square(5.0, 52.0, 0.1)) - 76.0) < 2.0
    holed = {"type": "Polygon", "coordinates": [_square(5.0, 52.0, 0.1)["coordinates"][0],
                                                 _square(5.02, 52.02, 0.05)["coordinates"][0]]}
    assert water.approx_km2(holed) < 60.0
    assert water.approx_km2({"type": "Point", "coordinates": [5.0, 52.0]}) == 0.0


def test_only_big_lakes_are_kept_with_their_osm_reference():
    lines = [
        _line({"natural": "water", "water": "lake", "name": "IJsselmeer"}, _square(5.0, 52.6, 0.3)),
        _line({"natural": "water", "water": "lake"}, _square(5.0, 52.0, 0.01), osm_id=8),
        _line({"natural": "water", "water": "river"}, _square(6.0, 52.0, 0.3), osm_id=9),
        "not json\n",
    ]
    kept = list(water.lakes(lines))
    assert [(k["ref"], k["name"]) for k in kept] == [("relation/7", "IJsselmeer")]
