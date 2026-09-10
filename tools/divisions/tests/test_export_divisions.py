# SPDX-License-Identifier: AGPL-3.0-only
"""Offline unit tests for the Overture divisions exporter.

These drive the pure `build_feature` helper (no network). The live Overture
query is exercised by the `--country BE` run in the plan (Task 1 Step 6) and by
`test_live_overture_be` below, which is skipped unless RUN_LIVE_OVERTURE=1.
"""
import json
import os

import pytest

from divisions import config
from divisions.export_divisions import build_feature, build_where, export_country, l2_cfg

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


LU = config.COUNTRY_CONFIG["LU"]


def test_lu_whole_country_feature_shape():
    # Luxembourg is seeded as ONE region at subtype=country (admin_level 2). The
    # slug map is keyed on the ISO 3166-1 code because Overture's country-level
    # division_area has region=NULL.
    f = build_feature("LU", "LU", SQUARE, 2586.0, LU)
    assert f["properties"] == {
        "slug": "luxembourg",
        "name": "Luxembourg",
        "area_km2": 2586,
        "country_code": "LU",
        "iso_code": "LU",
        "admin_level": 2,
        "source": "overture",
    }
    assert f["geometry"]["type"] == "MultiPolygon"


def test_country_subtype_admin_level_is_2():
    assert config.SUBTYPE_ADMIN_LEVEL["country"] == 2


def test_country_where_clause_selects_country_subtype():
    where, params = build_where("LU", LU)
    assert "subtype = ?" in where
    assert "LU" in params and "country" in params and "land" in params


def test_where_filters_maritime_rows():
    # 07-20 review finding 4: coastal divisions carry a maritime twin row;
    # only class='land' geometries may become regions.
    where, params = build_where("BE", BE)
    assert '"class" = ?' in where
    assert params[where.index('"class" = ?')] == "land"


def test_where_bbox_is_an_overlap_test():
    # A region OVERLAPPING the config box must match even when its min corner
    # lies outside the box (the old BETWEEN-on-min-corner dropped those).
    where, params = build_where("BE", BE)
    xmin, ymin, xmax, ymax = BE["bbox"]
    clauses = dict(zip(where[3:], params[3:]))
    assert clauses == {
        "bbox.xmin <= ?": xmax,
        "bbox.xmax >= ?": xmin,
        "bbox.ymin <= ?": ymax,
        "bbox.ymax >= ?": ymin,
    }


def test_where_without_bbox_has_no_pushdown():
    cfg = dict(BE, bbox=None)
    where, params = build_where("BE", cfg)
    assert where == ["country = ?", "subtype = ?", '"class" = ?']
    assert params == ["BE", cfg["subtype"], "land"]


def test_duplicate_iso_rows_fail_loud(monkeypatch, tmp_path):
    # Two land rows for one ISO must abort, never last-wins-overwrite the
    # already-written artifact (07-20 review finding 4).
    square = json.dumps(SQUARE)
    monkeypatch.setattr(
        "divisions.export_divisions.query_country",
        lambda con, cc, cfg, release: [("BE-WAL", square), ("BE-WAL", square)],
    )
    with pytest.raises(SystemExit, match="multiple land rows for BE-WAL"):
        export_country("BE", tmp_path, con=object())


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


NL = config.COUNTRY_CONFIG["NL"]


def test_nl_config_seeds_all_12_provinces_at_region_level():
    assert NL["subtype"] == "region"
    assert len(NL["slugs"]) == 12
    assert set(NL["slugs"]) == set(NL["names"])
    assert all(iso.startswith("NL-") for iso in NL["slugs"])


def test_nl_limburg_slug_is_disambiguated():
    # BE also has a Limburg province; slug is GLOBAL identity
    # (tools/divisions/README.md).
    assert NL["slugs"]["NL-LI"] == "limburg-nl"


def test_nl_feature_carries_admin_level_4_and_frozen_slug():
    f = build_feature("NL-NH", "NL", MULTI, 2670.0, NL)
    assert f["properties"]["slug"] == "noord-holland"
    assert f["properties"]["admin_level"] == 4
    assert f["properties"]["country_code"] == "NL"


def test_slugs_are_globally_unique_across_countries():
    all_slugs = [s for cfg in config.COUNTRY_CONFIG.values() for s in cfg["slugs"].values()]
    # Every onboarded country also carries an L2 country-outline slug
    #, except when the
    # primary config already IS the L2 row (LU: subtype "country") —
    # export_country only appends l2_cfg() on top when the primary subtype
    # differs, so including LU's COUNTRY_L2 entry here would flag its own
    # slug as a false collision with itself.
    all_slugs += [
        slug
        for cc, (slug, _name) in config.COUNTRY_L2.items()
        if config.COUNTRY_CONFIG[cc]["subtype"] != "country"
    ]
    assert len(all_slugs) == len(set(all_slugs))


@pytest.mark.skipif(os.environ.get("RUN_LIVE_OVERTURE") != "1",
                    reason="live Overture smoke (network) — set RUN_LIVE_OVERTURE=1")
def test_live_overture_nl(tmp_path):
    written = export_country("NL", tmp_path)
    assert len(written) == 12
    names = {p.name for p in written}
    assert "region-limburg-nl.geojson" in names
    assert "region-noord-holland.geojson" in names


def test_country_l2_covers_every_configured_country():
    # Every onboardable country must have an L2 identity — a missing entry
    # would make the always-emit-L2 branch fail loud mid-export.
    for cc in config.COUNTRY_CONFIG:
        assert cc in config.COUNTRY_L2, f"COUNTRY_L2 missing {cc}"
        slug, name = config.COUNTRY_L2[cc]
        assert slug and slug == slug.lower()
        assert name


def test_l2_cfg_builds_a_country_subtype_config():
    cfg = l2_cfg("BE")
    assert cfg["subtype"] == "country"
    assert cfg["slugs"] == {"BE": "belgium"}
    assert cfg["names"] == {"BE": "Belgium"}
    # bbox carries over so the Overture scan predicate stays cheap.
    assert cfg["bbox"] == config.COUNTRY_CONFIG["BE"]["bbox"]


def test_l2_feature_shape_via_existing_builder():
    # The L2 outline flows through the SAME build_feature as every region:
    # provenance-identical rows (design §9).
    f = build_feature("BE", "BE", MULTI, 30528.0, l2_cfg("BE"))
    assert f["properties"] == {
        "slug": "belgium",
        "name": "Belgium",
        "area_km2": 30528,
        "country_code": "BE",
        "iso_code": "BE",
        "admin_level": 2,
        "source": "overture",
    }
