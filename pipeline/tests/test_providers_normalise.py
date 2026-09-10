# SPDX-License-Identifier: AGPL-3.0-only
"""The generic harvester's normalise step (data-provider-hierarchy.md §5).

No network and no database: the rules that decide what a record IS are worth
testing against a fixture rather than against a publisher having a good day.
"""

import pytest

from providers.normalise import (NormaliseError, apply_field_map, make_ref,
                                 normalise, wgs84_point)

FIELD_MAP = {"name": "beschrijvi", "town": "plaats", "status": "type"}


def feature(lng: float, lat: float, **props) -> dict:
    return {
        "type": "Feature",
        "properties": props,
        "geometry": {"type": "Point", "coordinates": [lng, lat]},
    }


def test_a_wgs84_point_passes():
    assert wgs84_point({"type": "Point", "coordinates": [4.745706, 52.570650]}) == (
        4.745706, 52.570650)


def test_unreprojected_metres_are_refused():
    """EPSG:28992 metres read as degrees put a Dutch tap in the ocean."""
    with pytest.raises(NormaliseError, match="srsName"):
        wgs84_point({"type": "Point", "coordinates": [105000.0, 519000.0]})


def test_a_line_is_refused():
    with pytest.raises(NormaliseError, match="not a Point"):
        wgs84_point({"type": "LineString", "coordinates": [[0, 0], [1, 1]]})


def test_a_stable_id_makes_a_stable_ref():
    ref = make_ref("rivm-drinkwater", {"objectid": 4412}, "objectid", 4.7, 52.5)
    assert ref == "rivm-drinkwater:4412"


def test_without_a_stable_id_the_ref_is_the_rounded_coordinates():
    """RIVM feature ids are positional, and a republish renumbers them all.

    A moved feature is then a delete plus an insert, not an update, which is
    exactly why the choice is recorded per provider.
    """
    ref = make_ref("rivm-drinkwater", {}, None, 4.7457061111, 52.5706501111)
    assert ref == "rivm-drinkwater:52.57065,4.745706"


def test_an_id_field_that_is_empty_is_a_refusal_not_a_blank_ref():
    with pytest.raises(NormaliseError, match="no id"):
        make_ref("x", {"objectid": None}, "objectid", 4.7, 52.5)


def test_unmapped_upstream_fields_are_dropped():
    """An attribute nobody asked for is an attribute nobody renders, and a
    schema change upstream would then silently change what we store."""
    mapped = apply_field_map(
        {"beschrijvi": "Kraan", "plaats": "Alkmaar", "shape_len": 0.0001},
        FIELD_MAP,
    )
    assert mapped == {"name": "Kraan", "town": "Alkmaar"}


def test_a_value_map_lands_upstream_values_in_our_vocabulary():
    """RIVM's `type` is availability AND condition, in Dutch. Each of our
    fields names the values it accepts; anything else is dropped, because an
    attribute a rider cannot pick in the form is not ours to store."""
    field_map = {
        "town": "plaats",
        "availability": {"from": "type", "values": {
            "Regulier, 24-7 open": "Always",
            "Alleen overdag bereikbaar": "Daytime only"}},
        "condition": {"from": "type", "values": {"Storing": "Out of order"}},
    }
    assert apply_field_map({"type": "Regulier, 24-7 open"}, field_map) == {"availability": "Always"}
    assert apply_field_map({"type": "Alleen overdag bereikbaar"}, field_map) == {"availability": "Daytime only"}
    assert apply_field_map({"type": "Storing"}, field_map) == {"condition": "Out of order"}
    assert apply_field_map({"type": "Iets nieuws"}, field_map) == {}
    assert apply_field_map({"plaats": "Alkmaar"}, field_map) == {"town": "Alkmaar"}


def test_normalise_builds_the_shape_the_ingest_reads():
    out = normalise(
        feature(4.745706, 52.570650, beschrijvi="Kraan", plaats="Alkmaar",
                type="Regulier, 24-7 open"),
        key="rivm-drinkwater",
        letter="B",
        field_map=FIELD_MAP,
        id_field=None,
        country_code="NL",
    )

    assert out["geometry"] == {"type": "Point", "coordinates": [4.745706, 52.570650]}
    assert out["properties"] == {
        "ref": "rivm-drinkwater:52.57065,4.745706",
        "letter": "B",
        "name": "Kraan",
        "country_code": "NL",
        "town": "Alkmaar",
        "status": "Regulier, 24-7 open",
    }


def test_a_feature_with_no_name_still_normalises():
    """A register row without a description is a real tap, not a bad record."""
    out = normalise(
        feature(4.7, 52.5, plaats="Alkmaar"),
        key="k", letter="B", field_map=FIELD_MAP, id_field=None, country_code=None,
    )
    assert out["properties"]["name"] == ""
    assert "country_code" not in out["properties"]
