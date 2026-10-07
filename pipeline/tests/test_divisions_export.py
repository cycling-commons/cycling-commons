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
from divisions.export_divisions import build_feature, build_where, export_country, l2_spec, spec_from_db

BE = {"subtype": "region", "slugs": {"BE-WAL": "wallonia", "BE-VLG": "flanders", "BE-BRU": "brussels"},
      "names": {"BE-WAL": "Wallonia", "BE-VLG": "Flanders", "BE-BRU": "Brussels"}, "bbox": [2.5, 49.4, 6.5, 51.6]}
LU = {"subtype": "country", "slugs": {"LU": "luxembourg"}, "names": {"LU": "Luxembourg"}, "bbox": [5.7, 49.4, 6.6, 50.2]}
NL = {"subtype": "region", "slugs": {"NL-NH": "noord-holland"}, "names": {"NL-NH": "North Holland"}, "bbox": [3.2, 50.7, 7.3, 53.6]}

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
        export_country("BE", tmp_path, BE, con=object())


@pytest.mark.skipif(os.environ.get("RUN_LIVE_OVERTURE") != "1", reason="hits Overture S3")
def test_live_overture_be(tmp_path):
    written = export_country("BE", tmp_path, BE, l2=("belgium", "Belgium"))
    slugs = {p.name for p in written}
    assert slugs == {"region-wallonia.geojson", "region-flanders.geojson", "region-brussels.geojson"}
    wal = json.loads((tmp_path / "region-wallonia.geojson").read_text())
    # Correctness gate: true geographic area ~16,901 km² (not a reprojection-inflated ~26k).
    assert 16000 <= wal["properties"]["area_km2"] <= 17500
    assert wal["properties"]["iso_code"] == "BE-WAL"
    assert wal["geometry"]["type"] == "MultiPolygon"


def test_nl_feature_carries_admin_level_4_and_frozen_slug():
    f = build_feature("NL-NH", "NL", MULTI, 2670.0, NL)
    assert f["properties"]["slug"] == "noord-holland"
    assert f["properties"]["admin_level"] == 4
    assert f["properties"]["country_code"] == "NL"


@pytest.mark.skipif(os.environ.get("RUN_LIVE_OVERTURE") != "1",
                    reason="live Overture smoke (network) — set RUN_LIVE_OVERTURE=1")
def test_live_overture_nl(tmp_path):
    written = export_country("NL", tmp_path, NL)
    assert len(written) == 1
    assert "region-noord-holland.geojson" in {p.name for p in written}


def test_l2_spec_builds_a_country_subtype_config():
    cfg = l2_spec("BE", "belgium", "Belgium", BE["bbox"])
    assert cfg["subtype"] == "country"
    assert cfg["slugs"] == {"BE": "belgium"}
    assert cfg["names"] == {"BE": "Belgium"}
    # bbox carries over so the Overture scan predicate stays cheap.
    assert cfg["bbox"] == BE["bbox"]


def test_l2_feature_shape_via_existing_builder():
    # The L2 outline flows through the SAME build_feature as every region:
    # provenance-identical rows (design §9).
    f = build_feature("BE", "BE", MULTI, 30528.0, l2_spec("BE", "belgium", "Belgium", BE["bbox"]))
    assert f["properties"] == {
        "slug": "belgium",
        "name": "Belgium",
        "area_km2": 30528,
        "country_code": "BE",
        "iso_code": "BE",
        "admin_level": 2,
        "source": "overture",
    }


def test_spec_from_db_reads_the_live_rows(catalog):
    from catalog_rows import add_country, add_region
    add_country(catalog, "BE", "live", ["europe/belgium"])
    catalog.execute("UPDATE country SET bbox = '[2.5, 49.4, 6.5, 51.6]' WHERE code = 'BE'")
    add_region(catalog, 1, "BE", 4, "POLYGON((4 50,5 50,5 51,4 51,4 50))", slug="wallonia")
    add_region(catalog, 2, "BE", 2, "POLYGON((3 49,6 49,6 52,3 52,3 49))", slug="belgium")
    catalog.execute("UPDATE region SET iso_code = 'BE-WAL', name = 'Wallonia' WHERE id = 1")
    catalog.execute("UPDATE region SET iso_code = 'BE', name = 'Belgium' WHERE id = 2")
    spec, l2 = spec_from_db(catalog, "BE")
    assert spec == {"subtype": "region", "slugs": {"BE-WAL": "wallonia"}, "names": {"BE-WAL": "Wallonia"},
                    "bbox": [2.5, 49.4, 6.5, 51.6]}
    assert l2 == ("belgium", "Belgium")


def test_spec_from_db_refuses_an_unknown_country(catalog):
    with pytest.raises(SystemExit, match="no country row for ZZ"):
        spec_from_db(catalog, "ZZ")
