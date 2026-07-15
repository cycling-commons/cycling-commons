# SPDX-License-Identifier: Apache-2.0
"""Unit tests for build_all.py's LAYERS config and run() wiring (no network)."""
import sys
import pathlib
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2]))
from wallonia import build_all


def test_services_selectors_have_full_service_kind_mapping():
    """Every D/services selector label maps to a canonical serviceKind, so
    future harvests carry it directly (no label-based backfill needed)."""
    cfg = build_all.LAYERS["services"]
    labels = {lbl for _, _, lbl in cfg["selectors"]}
    kind_map = cfg["service_kind_by_label"]
    assert set(kind_map) == labels
    assert kind_map["Bike shop"] == "shop"
    assert kind_map["Repair station"] == "station"
    assert kind_map["Pump"] == "pump"


def test_other_pools_have_no_service_kind_mapping():
    """The tag table is shared infra (selectors also feed scenic/history/stays/
    shelter/transit); only D/services may carry a serviceKind mapping."""
    for key, cfg in build_all.LAYERS.items():
        if key == "services":
            continue
        assert "service_kind_by_label" not in cfg


def test_run_stamps_service_kind_onto_harvested_services_features(monkeypatch, tmp_path):
    """run() must attach properties.serviceKind to each services feature, keyed by
    its existing `t` label, without touching harvest_poi/overpass internals."""
    monkeypatch.setattr(build_all, "DEMO", tmp_path)
    feats = [
        {"properties": {"t": "Bike shop", "prov": "Namur"},
         "geometry": {"type": "Point", "coordinates": [4.2, 50.1]}},
        {"properties": {"t": "Repair station", "prov": "Namur"},
         "geometry": {"type": "Point", "coordinates": [4.3, 50.2]}},
        {"properties": {"t": "Pump", "prov": "Namur"},
         "geometry": {"type": "Point", "coordinates": [4.4, 50.3]}},
    ]
    monkeypatch.setattr(
        build_all.harvest_poi, "harvest",
        lambda cfg: {"features": feats, "by_prov": {"Namur": len(feats)}})
    build_all.run(["services"])
    assert feats[0]["properties"]["serviceKind"] == "shop"
    assert feats[1]["properties"]["serviceKind"] == "station"
    assert feats[2]["properties"]["serviceKind"] == "pump"


def test_run_leaves_non_services_features_without_service_kind(monkeypatch, tmp_path):
    monkeypatch.setattr(build_all, "DEMO", tmp_path)
    feats = [{"properties": {"t": "Viewpoint", "prov": "Namur"},
              "geometry": {"type": "Point", "coordinates": [4.2, 50.1]}}]
    monkeypatch.setattr(
        build_all.harvest_poi, "harvest",
        lambda cfg: {"features": feats, "by_prov": {"Namur": 1}})
    build_all.run(["scenic"])
    assert "serviceKind" not in feats[0]["properties"]
