# SPDX-License-Identifier: Apache-2.0
"""Unit tests for export.py pure helpers (no network, no cache)."""
from wallonia import export


def test_slug_is_stable_and_ascii():
    assert export.slug("Côte de la Ferme Libert") == "cote-de-la-ferme-libert"
    assert export.slug("La Cabane des Avelines") == "la-cabane-des-avelines"


def test_parse_fixture_payload_extracts_json():
    js = '// header\nwindow.CC_X={"type":"FeatureCollection","features":[]};\n'
    data = export.parse_fixture(js, "CC_X")
    assert data == {"type": "FeatureCollection", "features": []}


def test_water_ref_is_coordinate_based():
    feat = {"properties": {"t": "Spring"}, "geometry": {"type": "Point", "coordinates": [5.58935, 50.65259]}}
    assert export.water_ref(feat) == "fx:water:50.65259,5.58935"


def test_surface_feature_converts_path_and_ref():
    seg = {"name": "Test seg", "surface": "Asphalt", "smoothness": "Good", "width": "3.0 m",
           "traffic": "Quiet", "cls": "paved", "path": [[50.1, 4.2], [50.2, 4.3]], "wayId": 123}
    f = export.surface_feature(seg)
    assert f["properties"]["ref"] == "way/123"
    assert f["properties"]["source"] == "osm"
    assert f["geometry"]["type"] == "LineString"
    assert f["geometry"]["coordinates"][0] == [4.2, 50.1]  # [lat,lng] -> [lng,lat]
    seg2 = dict(seg); del seg2["wayId"]
    assert export.surface_feature(seg2)["properties"]["ref"] == "fx:surface:test-seg"
    assert export.surface_feature(seg2)["properties"]["source"] == "auto"


def test_climb_features_preserves_fixture_source_as_attribution(monkeypatch):
    """Deviation (approved): fixture climb 'source' citation preserved as 'attribution'
    instead of being overwritten by the provenance key."""
    fake_climb = {"name": "Test Climb", "source": "Wikidata (P625) · OpenStreetMap",
                  "geom": {"ll": [50.1, 5.2]}}
    monkeypatch.setattr(export, "load_fixture", lambda filename, js_var: [fake_climb])
    monkeypatch.setattr(export.climbs_mod, "_search_qid", lambda name: "Q12345")
    feats = export.climb_features()
    props = feats[0]["properties"]
    assert props["attribution"] == "Wikidata (P625) · OpenStreetMap"
    assert props["source"] == "wikidata"
    assert props["ref"] == "Q12345"
