import sys
import pathlib
import json
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2]))
from wallonia import harvest_poi as h

SELS = [("shop", "bicycle", "Bike shop"), ("amenity", "bicycle_repair_station", "Repair station")]


def test_build_query_unions_selectors_and_uses_center():
    q = h.build_query(SELS, 3600001532)
    assert 'node["shop"="bicycle"](area.a)' in q
    assert 'way["amenity"="bicycle_repair_station"](area.a)' in q
    assert "out center" in q


def test_dedupe_keeps_first_by_id():
    f = [{"_id": "node/1", "properties": {}}, {"_id": "node/1", "properties": {}},
         {"_id": "node/2", "properties": {}}]
    assert [x["_id"] for x in h.dedupe(f)] == ["node/1", "node/2"]


def test_rank_puts_named_first_then_caps():
    f = [{"_id": "n/1", "properties": {"t": "x"}},
         {"_id": "n/2", "properties": {"t": "x", "n": "Named"}},
         {"_id": "n/3", "properties": {"t": "x"}}]
    out = h.rank_and_cap(f, cap=2)
    assert len(out) == 2
    assert out[0]["properties"].get("n") == "Named"


def test_to_fixture_js_strips_private_id_and_wraps_var():
    f = [{"type": "Feature", "_id": "node/1",
          "properties": {"t": "Bike shop", "prov": "Namur"},
          "geometry": {"type": "Point", "coordinates": [4.2, 50.1]}}]
    js = h.to_fixture_js(f, "CC_SERVICES_OSM", "// hdr\n")
    assert js.startswith("// hdr\nwindow.CC_SERVICES_OSM=")
    assert js.rstrip().endswith(";")
    payload = json.loads(js.split("=", 1)[1].rsplit(";", 1)[0])
    assert payload["type"] == "FeatureCollection"
    assert "_id" not in payload["features"][0]
