# SPDX-License-Identifier: Apache-2.0
"""Unit tests for export.py pure helpers (no network, no cache)."""
import json

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
    assert f["properties"]["ref"] == "way/123"  # unique OSM way ref stays clean
    assert f["properties"]["source"] == "osm"
    assert f["geometry"]["type"] == "LineString"
    assert f["geometry"]["coordinates"][0] == [4.2, 50.1]  # [lat,lng] -> [lng,lat]


def test_surface_feature_synthetic_ref_has_coordinate_discriminator():
    """No wayId -> synthetic ref carries the first path vertex so segments of
    the same route/surface class don't collapse onto one (source, ref) key."""
    seg = {"name": "Test seg", "surface": "Asphalt", "cls": "paved",
           "path": [[50.1, 4.2], [50.2, 4.3]]}
    f = export.surface_feature(seg)
    assert f["properties"]["ref"] == "fx:surface:test-seg:50.1,4.2"
    assert f["properties"]["source"] == "auto"
    seg2 = dict(seg, path=[[50.9, 4.8], [50.2, 4.3]])
    assert export.surface_feature(seg2)["properties"]["ref"] == "fx:surface:test-seg:50.9,4.8"


def test_surface_feature_ref_is_stable_under_surface_relabel():
    """Regression (2026-07-09 orphan incident): the synthetic ref must NOT embed
    the mutable surface label. Route-surface segments carry a stable `refBase`
    (the ride name, label-free); renaming the surface (e.g. Unpaved -> Dirt) must
    not change the upsert ref, or a re-import orphans the old rows instead of
    updating them. `refBase` is identity-only and must not leak into properties."""
    base = {"refBase": "Spa · Sankt Vith", "cls": "dirt", "path": [[50.1, 4.2], [50.2, 4.3]]}
    unpaved = export.surface_feature(dict(base, name="Spa · Sankt Vith · Unpaved", surface="Unpaved"))
    dirt = export.surface_feature(dict(base, name="Spa · Sankt Vith · Dirt", surface="Dirt"))
    assert unpaved["properties"]["ref"] == "fx:surface:spa-sankt-vith:50.1,4.2"
    assert dirt["properties"]["ref"] == unpaved["properties"]["ref"]  # label change => identical ref
    assert "refBase" not in dirt["properties"]  # identity key, not a served attribute


def test_water_features_dedupes_exact_duplicate_refs(monkeypatch, capsys):
    """Exact-duplicate harvest points (same coordinates) export once — keep first."""
    pt = {"type": "Point", "coordinates": [4.85758, 50.46576]}
    fc = {"features": [
        {"properties": {"t": "Drinking water"}, "geometry": pt},
        {"properties": {"t": "Drinking water"}, "geometry": pt},
        {"properties": {"t": "Spring"}, "geometry": {"type": "Point", "coordinates": [5.0, 50.5]}},
    ]}
    monkeypatch.setattr(export, "load_fixture", lambda filename, js_var: fc)
    feats = export.water_features()
    assert [f["properties"]["ref"] for f in feats] == [
        "fx:water:50.46576,4.85758", "fx:water:50.5,5.0"]
    assert "dropped 1 exact-duplicate ref(s)" in capsys.readouterr().out


def test_parse_surface_fixture_handles_hybrid_js():
    """Regression: surface-data.js is hand-authored JS (bare keys, single quotes,
    comments, escaped apostrophes) plus an RS_START/RS_END machine-injected JSON
    block — parse_surface_fixture must round-trip both halves exactly."""
    js = (
        "// SPDX-License-Identifier: ODbL-1.0\n"
        "/* A · Road surface — hand-picked demo segments.\n"
        " * cls drives colour + pattern\n"
        " */\n"
        "window.CC_SURFACE = {\n"
        "  segments: [\n"
        "    {\n"
        "      name: 'Rue de l\\'Église · Stavelot',\n"
        "      surface: 'Sett (pavé)', smoothness: 'Bad', width: '—',\n"
        "      traffic: 'Local street', cls: 'pave', wayId: 77930062,\n"
        "      // real geometry from OSM way/77930062\n"
        "      path: [\n"
        "        [50.39501, 5.93173], [50.39496, 5.93198]\n"
        "      ]\n"
        "    }\n"
        '  ,/*RS_START*/{"name": "Spa · Sankt Vith · Asphalt", "surface": "Asphalt",'
        ' "smoothness": "Good", "width": "—", "traffic": "Open road", "cls": "paved",'
        ' "edit": "road-surface", "path": [[50.48939, 5.87922], [50.48939, 5.87912]]}'
        "/*RS_END*/]\n"
        "};\n"
    )
    segs = export.parse_surface_fixture(js)
    assert len(segs) == 2
    hand, injected = segs
    # hand-authored half: bare keys quoted, '...' strings converted, \' unescaped,
    # comments stripped without eating data
    assert hand["name"] == "Rue de l'Église · Stavelot"
    assert hand["surface"] == "Sett (pavé)"
    assert hand["cls"] == "pave"
    assert hand["wayId"] == 77930062
    assert hand["path"] == [[50.39501, 5.93173], [50.39496, 5.93198]]
    # machine-injected half: passed through json.loads untouched
    assert injected["name"] == "Spa · Sankt Vith · Asphalt"
    assert injected["edit"] == "road-surface"
    assert injected["path"] == [[50.48939, 5.87922], [50.48939, 5.87912]]


def test_parse_surface_fixture_without_injected_block():
    """A fixture with no RS_START/RS_END marker (pre-injection state) still parses."""
    js = ("window.CC_SURFACE = {\n"
          "  segments: [\n"
          "    { name: 'Plain seg', cls: 'gravel', path: [[50.1, 4.2], [50.2, 4.3]] }\n"
          "  ]\n"
          "};\n")
    segs = export.parse_surface_fixture(js)
    assert [s["name"] for s in segs] == ["Plain seg"]
    assert segs[0]["path"] == [[50.1, 4.2], [50.2, 4.3]]


def test_poi_feature_moves_id_into_ref():
    raw = {"type": "Feature", "properties": {"t": "Bike shop", "n": "X", "prov": "Namur"},
           "geometry": {"type": "Point", "coordinates": [4.9, 50.3]},
           "_id": "node/42", "_tags": {"wikidata": "Q1"}}
    f = export.poi_feature(raw)
    assert f["properties"]["ref"] == "node/42"
    assert f["properties"]["source"] == "osm"
    assert "_id" not in f and "_tags" not in f
    assert f["properties"]["t"] == "Bike shop"


def test_write_reports_zero_for_genuinely_empty_layer(monkeypatch, tmp_path, capsys):
    """An empty features/routes/points list must print 0, not fall through to
    the region-file default of 1 (falsy-`or` chain bug)."""
    monkeypatch.setattr(export, "OUT", tmp_path)
    export.write("empty.json", {"layer": "services", "letter": "D", "features": []})
    assert "empty.json: 0" in capsys.readouterr().out


def test_write_reports_one_for_region_feature_file(monkeypatch, tmp_path, capsys):
    """The region export is a bare GeoJSON Feature (no features/routes/points
    key) and always counts as exactly one."""
    monkeypatch.setattr(export, "OUT", tmp_path)
    export.write("region-wallonia.geojson", {"type": "Feature", "properties": {}, "geometry": {}})
    assert "region-wallonia.geojson: 1" in capsys.readouterr().out


def test_routes_payload_preserves_uploader_and_photo(monkeypatch):
    fixture = {"routes": [
        {"name": "With extras", "km": 10.0, "gain": 100, "season": "summer",
         "start": [50.5, 4.5], "loop": [[50.5, 4.5], [50.6, 4.6]], "elev": [10, 20],
         "difficulty": {"score": 2, "label": "easy"},
         "uploader": {"name": "Hanne V.", "public": True},
         "photo": {"sm": "https://example.test/s.jpg", "lg": "https://example.test/l.jpg",
                   "credit": "X", "license": "CC0", "source": "https://example.test"}},
        {"name": "Bare", "km": 5.0, "gain": 50, "season": "winter",
         "start": [50.1, 4.1], "loop": [[50.1, 4.1], [50.2, 4.2]], "elev": [5],
         "difficulty": {"score": 1, "label": "easy"}},
    ], "heat": []}
    monkeypatch.setattr(export, "load_fixture", lambda *_: fixture)
    routes = export.routes_payload()["routes"]
    assert routes[0]["attributes"]["uploader"] == {"name": "Hanne V.", "public": True}
    assert routes[0]["attributes"]["photo"]["credit"] == "X"
    assert "uploader" not in routes[1]["attributes"]
    assert "photo" not in routes[1]["attributes"]


def test_run_harvest_stamps_service_kind_onto_services_json(monkeypatch, tmp_path):
    """CRITICAL regression: run_harvest() feeds the REAL DB-import pipeline
    (tools/wallonia/out/services.json -> ImportCatalogCommand), unlike
    build_all.run() which only feeds the retired atlas demo fixture. It must
    stamp properties.serviceKind the same way build_all.run() does, via the
    shared build_all.stamp_service_kind() helper — not duplicate the mapping."""
    monkeypatch.setattr(export, "OUT", tmp_path)
    services_feats = [
        {"_id": "node/1", "properties": {"t": "Bike shop", "prov": "Namur"},
         "geometry": {"type": "Point", "coordinates": [4.2, 50.1]}},
        {"_id": "node/2", "properties": {"t": "Repair station", "prov": "Namur"},
         "geometry": {"type": "Point", "coordinates": [4.3, 50.2]}},
        {"_id": "node/3", "properties": {"t": "Pump", "prov": "Namur"},
         "geometry": {"type": "Point", "coordinates": [4.4, 50.3]}},
    ]
    other_feats = [{"_id": "node/9", "properties": {"t": "Viewpoint", "prov": "Namur"},
                    "geometry": {"type": "Point", "coordinates": [4.5, 50.4]}}]

    def fake_harvest(cfg):
        if cfg is export.build_all.LAYERS["services"]:
            return {"features": services_feats, "by_prov": {"Namur": 3}}
        return {"features": other_feats, "by_prov": {"Namur": 1}}

    monkeypatch.setattr(export.harvest_poi, "harvest", fake_harvest)
    export.run_harvest()

    services_payload = json.loads((tmp_path / "services.json").read_text(encoding="utf-8"))
    kinds = [f["properties"]["serviceKind"] for f in services_payload["features"]]
    assert kinds == ["shop", "station", "pump"]

    scenic_payload = json.loads((tmp_path / "scenic.json").read_text(encoding="utf-8"))
    assert "serviceKind" not in scenic_payload["features"][0]["properties"]


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
