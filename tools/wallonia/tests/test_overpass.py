import sys
import pathlib
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2]))
from wallonia import overpass


def test_node_becomes_point_feature():
    els = [{"type": "node", "id": 1, "lat": 50.5, "lon": 5.0,
            "tags": {"shop": "bicycle", "name": "Vélo Plus"}}]
    type_map = [("shop", "bicycle", "Bike shop")]
    feats = overpass.elements_to_features(els, type_map, "Liège")
    assert feats == [{
        "type": "Feature",
        "properties": {"t": "Bike shop", "n": "Vélo Plus", "prov": "Liège"},
        "geometry": {"type": "Point", "coordinates": [5.0, 50.5]},
        "_id": "node/1",
    }]


def test_way_uses_center_and_omits_missing_name():
    els = [{"type": "way", "id": 9, "center": {"lat": 50.1, "lon": 4.2},
            "tags": {"amenity": "bicycle_repair_station"}}]
    type_map = [("amenity", "bicycle_repair_station", "Repair station")]
    feats = overpass.elements_to_features(els, type_map, "Namur")
    assert feats[0]["geometry"]["coordinates"] == [4.2, 50.1]
    assert "n" not in feats[0]["properties"]
    assert feats[0]["_id"] == "way/9"
