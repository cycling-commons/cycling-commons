# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""coverage.load — schema bootstrap + per-region atomic swap, against the dev PostGIS."""

import psycopg
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
            "coverage_poi_region_id_idx", "coverage_poi_country_code_idx",
            "coverage_poi_name_trgm_idx"} <= idx


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


def test_load_region_snaps_boundary_miss_to_nearest_region(db):
    """A cc-bearing POI just OUTSIDE every region polygon (an ST_Contains gap)
    snaps to the nearest region of its own country (finding 5); one genuinely far
    away stays NULL."""
    ensure_schema(db)
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES (7, 100, 'BE', "
        "ST_GeomFromText('MULTIPOLYGON(((4 50, 6 50, 6 51, 4 51, 4 50)))', 4326))"
    )
    db.commit()
    load_region(db, [
        _row("node/near", "C", lon=3.995, lat=50.5),   # ~0.005° west of the 4.0 edge → snaps
        _row("node/far", "C", lon=10.0, lat=45.0),      # far outside → stays NULL
    ], "europe/belgium")
    got = dict(db.execute("SELECT ref, region_id FROM coverage_poi").fetchall())
    assert got["node/near"] == 7, "a boundary-miss row must snap to the nearest region"
    assert got["node/far"] is None, "a genuinely distant row must stay unstamped"


def test_load_region_never_snaps_across_country(db):
    """The snap is constrained to the POI's own country_code, so a border POI is
    never pulled into a neighbouring country's region (finding 5)."""
    ensure_schema(db)
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES (8, 100, 'FR', "
        "ST_GeomFromText('MULTIPOLYGON(((4 50, 6 50, 6 51, 4 51, 4 50)))', 4326))"
    )
    db.commit()
    load_region(db, [_row("node/be", "C", lon=3.995, lat=50.5, country_code="BE")],
                "europe/belgium")
    region_id = db.execute(
        "SELECT region_id FROM coverage_poi WHERE ref = 'node/be'"
    ).fetchone()[0]
    assert region_id is None, "a BE row must not snap into an FR region"


def test_load_region_backfills_cc_from_region_when_extract_left_it_null(db):
    """region ⇒ cc invariant (finding 8): a stamped row whose extract left
    country_code NULL gets cc backfilled from its region, so the controller's
    24-region cap always has a complete cc safety net."""
    ensure_schema(db)
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES (9, 100, 'BE', "
        "ST_GeomFromText('MULTIPOLYGON(((4 50, 6 50, 6 51, 4 51, 4 50)))', 4326))"
    )
    db.commit()
    load_region(db, [_row("node/1", "C", country_code=None)], "europe/belgium")
    rid, cc = db.execute(
        "SELECT region_id, country_code FROM coverage_poi WHERE ref = 'node/1'"
    ).fetchone()
    assert rid == 9
    assert cc == "BE", "a region-stamped row must never be left cc-less"


def test_load_region_smallest_area_wins_on_overlap(db):
    """Overlapping regions: the smaller-area one wins, not the lower id.

    The third membership writer must agree with RegionResolver /
    recomputeMembership (region-scoping-design.md §3). Region 101 (area 400)
    has the LOWER id but the BIGGER area; region 102 (area 4) must win.
    """
    ensure_schema(db)
    db.execute(
        "INSERT INTO region (id, area_km2, geom) VALUES "
        "(101, 400, ST_GeomFromText("
        "'MULTIPOLYGON(((3 49, 7 49, 7 53, 3 53, 3 49)))', 4326)),"
        "(102, 4, ST_GeomFromText("
        "'MULTIPOLYGON(((4 50, 6 50, 6 52, 4 52, 4 50)))', 4326))"
    )
    db.commit()
    load_region(db, [_row("node/1", "C", lon=5.0, lat=51.0)], "europe/belgium")
    region_id = db.execute(
        "SELECT region_id FROM coverage_poi WHERE ref = 'node/1'"
    ).fetchone()[0]
    assert region_id == 102


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


def test_load_region_generic_error_rolls_back_whole_swap(db):
    """Any mid-transaction failure — not just DriftAbort — keeps the last slice.

    A duplicate (ref, letter) pair passes the drift check and the DELETE, then
    fires coverage_poi's UNIQUE constraint on the INSERT — proving the rollback
    covers everything after the DELETE, not only the drift guard.
    """
    ensure_schema(db)
    load_region(db, [_row(f"node/{i}", "C") for i in range(10)], "europe/belgium")
    with pytest.raises(psycopg.errors.UniqueViolation):
        load_region(db, [
            _row(f"node/{i}", "C") for i in range(9)
        ] + [_row("node/0", "C")], "europe/belgium")   # 10 rows, node/0 twice
    n = db.execute("SELECT count(*) FROM coverage_poi").fetchone()[0]
    assert n == 10                                     # last good slice intact
    assert db.execute(
        "SELECT count(*) FROM coverage_poi WHERE ref = 'node/7'"
    ).fetchone()[0] == 1                               # sampled prior row survived


def test_load_region_exact_drift_boundary_does_not_abort(db):
    """A drop of exactly DRIFT_ABORT_RATIO is allowed: the guard is strict <."""
    ensure_schema(db)
    load_region(db, [_row(f"node/{i}", "C") for i in range(10)], "europe/belgium")
    res = load_region(
        db, [_row(f"node/{i}", "C") for i in range(6)], "europe/belgium"
    )                                                  # 6 == 10 * (1 - 0.4) -> no abort
    assert res == LoadResult(inserted=6, previous=10)
    n = db.execute("SELECT count(*) FROM coverage_poi").fetchone()[0]
    assert n == 6


def test_load_region_zero_rows_fresh_region_succeeds(db):
    """previous=0 disarms the drift guard: an empty first load is not an abort."""
    ensure_schema(db)
    res = load_region(db, [], "europe/luxembourg")
    assert res == LoadResult(inserted=0, previous=0)
    n = db.execute("SELECT count(*) FROM coverage_poi").fetchone()[0]
    assert n == 0


def test_load_region_zero_rows_over_populated_region_aborts(db):
    ensure_schema(db)
    load_region(db, [_row(f"node/{i}", "C") for i in range(10)], "europe/belgium")
    with pytest.raises(DriftAbort):
        load_region(db, [], "europe/belgium")
    n = db.execute("SELECT count(*) FROM coverage_poi").fetchone()[0]
    assert n == 10                                     # populated slice kept


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
