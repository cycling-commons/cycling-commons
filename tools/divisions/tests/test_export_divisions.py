# SPDX-License-Identifier: Apache-2.0
"""Offline unit tests for the Overture divisions exporter.

These drive the pure `build_feature` helper (no network). The live Overture
query is exercised by the `--country BE` run in the plan (Task 1 Step 6) and by
`test_live_overture_be` below, which is skipped unless RUN_LIVE_OVERTURE=1.
"""
import json
import os

import pytest

from divisions import config
from divisions.export_divisions import build_feature, export_country

BE = config.COUNTRY_CONFIG["BE"]

SQUARE = {
    "type": "Polygon",
    "coordinates": [[[4.0, 50.0], [5.0, 50.0], [5.0, 51.0], [4.0, 51.0], [4.0, 50.0]]],
}
MULTI = {"type": "MultiPolygon", "coordinates": [SQUARE["coordinates"]]}


def test_be_wal_feature_shape():
    f = build_feature("BE-WAL", "BE", MULTI, 16901.0, BE)
    assert f["type"] == "Feature"
    assert f["properties"] == {
        "slug": "wallonia",
        "name": "Wallonia",
        "area_km2": 16901,
        "country_code": "BE",
        "iso_code": "BE-WAL",
        "admin_level": 4,
        "source": "overture",
    }
    assert f["geometry"]["type"] == "MultiPolygon"


def test_polygon_promoted_to_multipolygon():
    # Brussels arrives from Overture as a Polygon; it must be stored as MultiPolygon.
    f = build_feature("BE-BRU", "BE", SQUARE, 254.0, BE)
    assert f["geometry"]["type"] == "MultiPolygon"
    assert f["geometry"]["coordinates"] == [SQUARE["coordinates"]]
    assert f["properties"]["slug"] == "brussels"
    assert f["properties"]["iso_code"] == "BE-BRU"


def test_flanders_slug_and_admin_level():
    f = build_feature("BE-VLG", "BE", MULTI, 13522.0, BE)
    assert f["properties"]["slug"] == "flanders"
    assert f["properties"]["admin_level"] == 4
    assert f["properties"]["source"] == "overture"


def test_area_km2_is_rounded_int():
    f = build_feature("BE-WAL", "BE", MULTI, 16901.37, BE)
    assert f["properties"]["area_km2"] == 16901
    assert isinstance(f["properties"]["area_km2"], int)


def test_unknown_country_raises():
    with pytest.raises(SystemExit):
        export_country("ZZ", "/tmp/does-not-matter")


@pytest.mark.skipif(os.environ.get("RUN_LIVE_OVERTURE") != "1", reason="hits Overture S3")
def test_live_overture_be(tmp_path):
    written = export_country("BE", tmp_path)
    slugs = {p.name for p in written}
    assert slugs == {"region-wallonia.geojson", "region-flanders.geojson", "region-brussels.geojson"}
    wal = json.loads((tmp_path / "region-wallonia.geojson").read_text())
    # Correctness gate: true geographic area ~16,901 km² (not a reprojection-inflated ~26k).
    assert 16000 <= wal["properties"]["area_km2"] <= 17500
    assert wal["properties"]["iso_code"] == "BE-WAL"
    assert wal["geometry"]["type"] == "MultiPolygon"
