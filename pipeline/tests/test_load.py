# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""coverage.load — schema bootstrap + per-region atomic swap, against the dev PostGIS."""

import pytest

from coverage.load import DriftAbort, LoadResult, ensure_schema, load_region
from coverage.parse import PoiRow


def _row(ref, letter, lon=4.86, lat=50.46, **over):
    base = dict(
        ref=ref, letter=letter, kind=None, name=f"POI {ref}", lon=lon, lat=lat,
        tags={"amenity": "drinking_water"}, osm_version=1, osm_ts=None,
        src_region="europe/belgium", country_code="BE",
    )
    base.update(over)
    return PoiRow(**base)


def test_ensure_schema_is_idempotent(db):
    ensure_schema(db)
    ensure_schema(db)  # second run must be a no-op, not an error
    cols = {c[0] for c in db.execute(
        "SELECT column_name FROM information_schema.columns "
        "WHERE table_schema = 'coverage_pytest' AND table_name = 'coverage_poi'"
    ).fetchall()}
    assert cols >= {
        "id", "ref", "letter", "kind", "name", "geom", "tags",
        "osm_version", "osm_ts", "src_region", "country_code", "region_id",
    }
    idx = {r[0] for r in db.execute(
        "SELECT indexname FROM pg_indexes WHERE schemaname = 'coverage_pytest'"
    ).fetchall()}
    assert {"coverage_poi_geom_idx", "coverage_poi_letter_idx",
            "coverage_poi_region_id_idx", "coverage_poi_name_trgm_idx"} <= idx


def test_load_region_inserts_and_backfills_region_id(db):
    ensure_schema(db)
    db.execute(
        "INSERT INTO region (id, geom) VALUES (7, "
        "ST_GeomFromText('MULTIPOLYGON(((4 50, 6 50, 6 51, 4 51, 4 50)))', 4326))"
    )
    db.commit()
    res = load_region(db, [
        _row("node/1", "C"),                        # inside the region polygon
        _row("node/2", "D", lon=10.0, lat=45.0, kind="shop",
             tags={"shop": "bicycle"}),             # outside every polygon
    ], "europe/belgium")
    assert res == LoadResult(inserted=2, previous=0)
    got = dict(db.execute("SELECT ref, region_id FROM coverage_poi").fetchall())
    assert got["node/1"] == 7
    assert got["node/2"] is None
    lon, lat = db.execute(
        "SELECT ST_X(geom), ST_Y(geom) FROM coverage_poi WHERE ref = 'node/1'"
    ).fetchone()
    assert lon == pytest.approx(4.86)
    assert lat == pytest.approx(50.46)


def test_load_region_swaps_only_its_region_slice(db):
    ensure_schema(db)
    load_region(db, [_row("node/10", "C")], "europe/belgium")
    load_region(db, [_row("node/20", "C", src_region="europe/netherlands",
                          country_code="NL")], "europe/netherlands")
    res = load_region(db, [_row("node/11", "C")], "europe/belgium")
    assert res == LoadResult(inserted=1, previous=1)
    refs = {r[0] for r in db.execute("SELECT ref FROM coverage_poi").fetchall()}
    assert refs == {"node/11", "node/20"}           # node/10 swapped out, NL untouched


def test_load_region_drift_abort_keeps_last_slice(db):
    ensure_schema(db)
    load_region(db, [_row(f"node/{i}", "C") for i in range(10)], "europe/belgium")
    with pytest.raises(DriftAbort):
        # 5 < 10 * (1 - DRIFT_ABORT_RATIO) = 6 -> abort
        load_region(db, [_row(f"node/{i}", "C") for i in range(5)], "europe/belgium")
    n = db.execute("SELECT count(*) FROM coverage_poi").fetchone()[0]
    assert n == 10                                   # last good slice kept


def test_same_ref_may_carry_two_letters(db):
    ensure_schema(db)
    load_region(db, [
        _row("node/109", "E", tags={"tourism": "hotel", "historic": "castle"}),
        _row("node/109", "J", tags={"tourism": "hotel", "historic": "castle"}),
    ], "europe/belgium")
    letters = {r[0] for r in db.execute(
        "SELECT letter FROM coverage_poi WHERE ref = 'node/109'"
    ).fetchall()}
    assert letters == {"E", "J"}
