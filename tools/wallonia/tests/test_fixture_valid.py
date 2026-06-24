import json
import pathlib

ROOT = pathlib.Path(__file__).resolve().parents[3]
FIX = ROOT / "atlas/demo/services-osm.js"
BBOX = (2.84, 49.45, 6.41, 50.85)


def test_services_fixture_is_valid_and_in_bbox():
    txt = FIX.read_text(encoding="utf-8")
    assert "SPDX-License-Identifier: ODbL-1.0" in txt
    assert "OpenStreetMap contributors" in txt
    payload = json.loads(txt.split("window.CC_SERVICES_OSM=", 1)[1].rsplit(";", 1)[0])
    assert payload["type"] == "FeatureCollection"
    feats = payload["features"]
    assert len(feats) >= 50  # region-balanced, materially richer than the 3 demo POIs
    for f in feats:
        lon, lat = f["geometry"]["coordinates"]
        assert BBOX[0] <= lon <= BBOX[2] and BBOX[1] <= lat <= BBOX[3]
        assert f["properties"]["t"] and f["properties"]["prov"]
        assert "_id" not in f["properties"]
