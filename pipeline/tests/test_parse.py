# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""coverage.parse — filtered PBF to PoiRow records, over the mini.osm fixture."""

import datetime

import pytest

from coverage.parse import parse_pois


@pytest.fixture(scope="module")
def rows(mini_pbf, contract):
    return list(parse_pois(mini_pbf, contract, "europe/belgium", "BE"))


def _by_ref(rows):
    out = {}
    for r in rows:
        out.setdefault(r.ref, []).append(r)
    return out


def test_letter_per_selector_and_no_selector_drops(rows):
    letters = {ref: {r.letter for r in rs} for ref, rs in _by_ref(rows).items()}
    assert letters["node/101"] == {"D"}
    assert letters["node/102"] == {"D"}
    assert letters["node/103"] == {"C"}
    assert letters["node/104"] == {"G"}
    assert letters["node/105"] == {"J"}
    assert letters["node/106"] == {"H"}
    assert letters["node/107"] == {"I"}
    assert "node/108" not in letters          # amenity=bench matches no selector
    assert "node/110" not in letters          # untagged way corners never emit


def test_multi_letter_object_yields_one_row_per_letter(rows):
    hotel_castle = _by_ref(rows)["node/109"]
    assert {r.letter for r in hotel_castle} == {"E", "J"}
    assert all(
        r.tags == {"tourism": "hotel", "historic": "castle", "name": "Kasteelhotel"}
        for r in hotel_castle
    )


def test_way_reduces_to_centroid(rows):
    (way,) = _by_ref(rows)["way/201"]
    assert way.letter == "E"
    assert way.lon == pytest.approx(5.01, abs=1e-6)
    assert way.lat == pytest.approx(50.01, abs=1e-6)
    assert way.name == "Hôtel du Centre"


def test_service_kind_only_on_d(rows):
    by_ref = _by_ref(rows)
    assert by_ref["node/101"][0].kind == "shop"
    assert by_ref["node/102"][0].kind == "pump"
    assert by_ref["node/103"][0].kind is None


def test_name_tags_and_osm_metadata(rows):
    (shop,) = _by_ref(rows)["node/101"]
    assert shop.name == "Vélo Namur"
    assert shop.tags == {"shop": "bicycle", "name": "Vélo Namur"}
    assert shop.osm_version == 3
    assert shop.osm_ts == datetime.datetime(2026, 6, 1, 12, 0, tzinfo=datetime.timezone.utc)
    (pump,) = _by_ref(rows)["node/102"]
    assert pump.name is None


def test_region_stamp(rows):
    assert {(r.src_region, r.country_code) for r in rows} == {("europe/belgium", "BE")}
