# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""coverage.load — schema bootstrap + per-region atomic swap, against the dev PostGIS."""

import psycopg
import pytest

from coverage.load import (
    DriftAbort,
    LoadResult,
    apply_session_budget,
    ensure_schema,
    load_region,
)
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
        "osm_version", "osm_ts", "src_region_id", "country_code", "region_id",
    }
    assert "src_region" not in cols   # normalized to the coverage_source FK
    src_cols = {c[0] for c in db.execute(
        "SELECT column_name FROM information_schema.columns "
        "WHERE table_schema = 'coverage_pytest' AND table_name = 'coverage_source'"
    ).fetchall()}
    assert src_cols >= {"id", "slug"}
    idx = {r[0] for r in db.execute(
        "SELECT indexname FROM pg_indexes WHERE schemaname = 'coverage_pytest'"
    ).fetchall()}
    assert {"coverage_poi_geom_idx", "coverage_poi_letter_idx",
            "coverage_poi_region_id_idx", "coverage_poi_country_code_idx",
            "coverage_poi_name_trgm_idx", "coverage_poi_src_region_id_idx"} <= idx


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


def test_load_region_upserts_shared_border_entity_across_regions(db):
    """Geofabrik regional extracts overlap at borders, so the SAME OSM entity
    (same ref) appears in two extracts (country-onboarding-design.md §2 plan
    refinement; 203 such refs shared BE↔NL). The global UNIQUE(ref, letter) plus
    the per-src_region swap must UPSERT a shared entity, never crash on the
    second region's load — and a region-stamped row's country_code must equal its
    region's, even when the neighbouring extract stamped the other country
    (region ⇒ cc invariant, region-scoping-design.md §8 risk 10)."""
    ensure_schema(db)
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES "
        "(1, 30000, 'BE', ST_GeomFromText('MULTIPOLYGON(((3 50, 5 50, 5 51, 3 51, 3 50)))', 4326)), "
        "(2, 20000, 'NL', ST_GeomFromText('MULTIPOLYGON(((5 50, 7 50, 7 51, 5 51, 5 50)))', 4326))"
    )
    db.commit()
    # BE extract loads two border entities that ALSO fall in NL's extract buffer:
    #   node/100 sits in NL territory (lon 6), node/200 in BE territory (lon 4).
    load_region(db, [
        _row("node/100", "C", lon=6.0, lat=50.5, src_region="europe/belgium", country_code="BE"),
        _row("node/200", "C", lon=4.0, lat=50.5, src_region="europe/belgium", country_code="BE"),
    ], "europe/belgium")
    # NL extract re-loads the SAME shared entities — must not raise a UNIQUE
    # violation (the pre-fix bug that rolled the whole NL slice back).
    res = load_region(db, [
        _row("node/100", "C", lon=6.0, lat=50.5, src_region="europe/netherlands", country_code="NL"),
        _row("node/200", "C", lon=4.0, lat=50.5, src_region="europe/netherlands", country_code="NL"),
    ], "europe/netherlands")
    assert res.inserted == 2
    rows = {ref: (rid, cc) for ref, rid, cc in db.execute(
        "SELECT ref, region_id, country_code FROM coverage_poi ORDER BY ref"
    ).fetchall()}
    assert len(rows) == 2, "shared border entities upsert in place, never duplicate"
    assert rows["node/100"] == (2, "NL"), "an entity in NL territory resolves to the NL region + cc"
    assert rows["node/200"] == (1, "BE"), (
        "an entity in BE territory keeps BE cc even though the NL extract loaded "
        "it last — region ⇒ cc, not extract ⇒ cc"
    )


def test_load_region_reclaimed_boundary_miss_reevaluates_region(db):
    """A boundary-miss shared entity, reclaimed cross-region, must re-derive its
    region — not keep the previous owner's.

    A POI in the gap between two regions (outside every polygon) boundary-snaps
    to the nearest region of its OWN country. When a neighbouring extract later
    reclaims the same (ref, letter), the upsert must reset region_id so the snap
    re-runs under the new owner; otherwise the stale region_id (a) skips the snap
    (gated on region_id IS NULL) and (b) makes cc-authority re-stamp the wrong
    country. region ⇒ cc stays *self*-consistent while both are wrong, so only a
    geometry-aware test catches it."""
    ensure_schema(db)
    # BE region ends at x=1.0; NL region starts at x=1.012 — a 0.012° gap.
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES "
        "(1, 100, 'BE', ST_GeomFromText('MULTIPOLYGON(((0 50, 1 50, 1 51, 0 51, 0 50)))', 4326)), "
        "(2, 100, 'NL', ST_GeomFromText('MULTIPOLYGON(((1.012 50, 2 50, 2 51, 1.012 51, 1.012 50)))', 4326))"
    )
    db.commit()
    # Shared boundary-miss POI at x=1.008: outside both polygons, but within the
    # 0.01° snap of BOTH edges (0.008° to BE, 0.004° to NL).
    load_region(db, [_row("node/b", "C", lon=1.008, lat=50.5,
                          src_region="europe/belgium", country_code="BE")], "europe/belgium")
    assert db.execute(
        "SELECT region_id, country_code FROM coverage_poi WHERE ref = 'node/b'"
    ).fetchone() == (1, "BE"), "BE first snaps the boundary-miss to its own region"
    # NL reclaims the same entity (border overlap): it must snap to the NL region,
    # not stay stuck on BE.
    load_region(db, [_row("node/b", "C", lon=1.008, lat=50.5,
                          src_region="europe/netherlands", country_code="NL")], "europe/netherlands")
    assert db.execute(
        "SELECT region_id, country_code FROM coverage_poi WHERE ref = 'node/b'"
    ).fetchone() == (2, "NL"), "the reclaimed boundary-miss re-snaps to the NL region + cc"


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

    Two brand-new rows sharing (ref, letter) make the diff-merge upsert try to
    affect the same just-inserted target row a second time → CardinalityViolation,
    proving the rollback covers the whole swap, not only the drift guard. The dup
    is a NEW ref on purpose: under the diff-merge's IS DISTINCT FROM guard a dup of
    an ALREADY-EXISTING ref is order-dependent (the identical first touch is
    skipped, so the "second affect" may not trigger), while two brand-new
    same-key rows raise deterministically. (Real parsing never emits intra-batch
    dups; this is a synthetic generic-error trigger.)
    """
    ensure_schema(db)
    load_region(db, [_row(f"node/{i}", "C") for i in range(10)], "europe/belgium")
    with pytest.raises(psycopg.errors.CardinalityViolation):
        load_region(db, [
            _row(f"node/{i}", "C") for i in range(9)
        ] + [_row("node/dup", "C"), _row("node/dup", "C")], "europe/belgium")  # new ref, twice
    n = db.execute("SELECT count(*) FROM coverage_poi").fetchone()[0]
    assert n == 10                                     # last good slice intact
    assert db.execute(
        "SELECT count(*) FROM coverage_poi WHERE ref = 'node/7'"
    ).fetchone()[0] == 1                               # sampled prior row survived


def test_delta_membership_noop_reload_rewrites_nothing(db):
    """A stamped, unchanged row is not rewritten under delta membership — the
    ctid is stable across an identical reload (Task 3 skipped the data write;
    Task 4 stops the whole-slice region_id rewrite that would otherwise churn it)."""
    ensure_schema(db)
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES (7, 100, 'BE', "
        "ST_GeomFromText('MULTIPOLYGON(((4 50, 6 50, 6 51, 4 51, 4 50)))', 4326))")
    db.commit()
    rows = [_row("node/1", "C")]                          # inside region 7, BE
    load_region(db, rows, "europe/belgium", "BE")
    before = db.execute("SELECT ctid::text FROM coverage_poi WHERE ref='node/1'").fetchone()[0]
    load_region(db, rows, "europe/belgium", "BE")         # identical
    after = db.execute("SELECT ctid::text FROM coverage_poi WHERE ref='node/1'").fetchone()[0]
    assert before == after, "a stamped unchanged row must not be rewritten by membership"


def test_full_membership_recomputes_whole_slice(db, monkeypatch):
    """The invariant + escape hatch: after a region change, a delta reload does
    NOT restamp an unchanged row, but COVERAGE_FULL_MEMBERSHIP=1 does (design §3.4)."""
    ensure_schema(db)
    rows = [_row("node/1", "C", lon=5.0, lat=50.5, src_region="dev/fixture", country_code=None)]
    load_region(db, rows, "dev/fixture", None)            # no region yet → region_id NULL
    assert db.execute("SELECT region_id FROM coverage_poi WHERE ref='node/1'").fetchone()[0] is None
    db.execute(                                            # a region is onboarded that now contains node/1
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES (7, 100, 'BE', "
        "ST_GeomFromText('MULTIPOLYGON(((4 50, 6 50, 6 51, 4 51, 4 50)))', 4326))")
    db.commit()
    load_region(db, rows, "dev/fixture", None)            # delta reload of identical rows
    assert db.execute("SELECT region_id FROM coverage_poi WHERE ref='node/1'").fetchone()[0] is None, \
        "delta reload must not restamp an unchanged row after a region change"
    monkeypatch.setenv("COVERAGE_FULL_MEMBERSHIP", "1")
    load_region(db, rows, "dev/fixture", None)            # full recompute
    assert db.execute("SELECT region_id FROM coverage_poi WHERE ref='node/1'").fetchone()[0] == 7, \
        "COVERAGE_FULL_MEMBERSHIP=1 restamps the whole slice"


def test_ensure_schema_gated_extension_still_builds_schema(db, monkeypatch):
    """With COVERAGE_ENSURE_EXTENSION=0 the privileged CREATE EXTENSION step is
    skipped (devops installs it at cluster init) yet the table + all indexes are
    still built (design §3.6). pg_trgm already exists in public via the fixture."""
    monkeypatch.setenv("COVERAGE_ENSURE_EXTENSION", "0")
    ensure_schema(db)
    idx = {r[0] for r in db.execute(
        "SELECT indexname FROM pg_indexes WHERE schemaname = 'coverage_pytest'").fetchall()}
    assert {"coverage_poi_geom_idx", "coverage_poi_name_trgm_idx",
            "coverage_poi_src_region_id_idx"} <= idx


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


def test_ownership_is_independent_of_load_order(db):
    """The core guarantee. Two overlapping extracts both carry one entity; whoever
    runs last must NOT win. Ownership is decided by which country's region contains
    it (2026-07-23-border-overlap-ownership-design.md §3)."""
    ensure_schema(db)
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES (1, 100, 'BE', "
        "ST_GeomFromText('POLYGON((3 50, 4 50, 4 51, 3 51, 3 50))', 4326))")
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES (2, 100, 'NL', "
        "ST_GeomFromText('POLYGON((4 50, 5 50, 5 51, 4 51, 4 50))', 4326))")
    db.commit()
    shared = _row("node/shared", "C", lon=3.5, lat=50.5)   # geometrically inside BE

    # BE first, then NL
    load_region(db, [shared], "europe/belgium", "BE")
    load_region(db, [shared], "europe/netherlands", "NL")
    be_first = db.execute(
        "SELECT s.slug FROM coverage_poi p JOIN coverage_source s ON s.id = p.src_region_id"
    ).fetchone()

    db.execute("DELETE FROM coverage_poi")
    db.commit()

    # NL first, then BE
    load_region(db, [shared], "europe/netherlands", "NL")
    load_region(db, [shared], "europe/belgium", "BE")
    nl_first = db.execute(
        "SELECT s.slug FROM coverage_poi p JOIN coverage_source s ON s.id = p.src_region_id"
    ).fetchone()

    assert be_first == nl_first == ("europe/belgium",), (
        "a shared border entity must be owned by the extract whose country contains it, "
        "regardless of load order")


def test_ownership_is_independent_of_load_order_in_the_border_band(db):
    """C1 regression test. Same shape as test_ownership_is_independent_of_load_order,
    but the shared entity sits at lon 3.995 — 0.005 deg from the BE/NL edge, INSIDE
    BOUNDARY_SNAP_DEG (0.01) of BOTH regions, not the 0.5 deg the other tests use.
    Geofabrik's real overlap buffer is 0.1026 deg, ten times the snap, so the whole
    snap allowance sits inside the zone where both extracts' filters accept the row —
    the DWithin-of-own-country predicate is not mutually exclusive there and falls
    back to ON CONFLICT / last-writer-wins, which this design exists to remove."""
    ensure_schema(db)
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES (1, 100, 'BE', "
        "ST_GeomFromText('POLYGON((3 50, 4 50, 4 51, 3 51, 3 50))', 4326))")
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES (2, 100, 'NL', "
        "ST_GeomFromText('POLYGON((4 50, 5 50, 5 51, 4 51, 4 50))', 4326))")
    db.commit()
    shared = _row("node/shared", "C", lon=3.995, lat=50.5)   # geometrically inside BE

    # BE first, then NL
    load_region(db, [shared], "europe/belgium", "BE")
    load_region(db, [shared], "europe/netherlands", "NL")
    be_first = db.execute(
        "SELECT s.slug FROM coverage_poi p JOIN coverage_source s ON s.id = p.src_region_id"
    ).fetchone()

    db.execute("DELETE FROM coverage_poi")
    db.commit()

    # NL first, then BE
    load_region(db, [shared], "europe/netherlands", "NL")
    load_region(db, [shared], "europe/belgium", "BE")
    nl_first = db.execute(
        "SELECT s.slug FROM coverage_poi p JOIN coverage_source s ON s.id = p.src_region_id"
    ).fetchone()

    assert be_first == nl_first == ("europe/belgium",), (
        "a shared border-band entity must be owned by the extract whose country "
        "contains it, regardless of load order — even 0.005 deg from the edge")


def test_ownership_tie_break_is_stable_regardless_of_load_order(db):
    """A row roughly equidistant from two countries' regions (here: exactly ON
    the shared BE/NL edge, distance 0 to both) must still resolve to exactly
    ONE stable owner, using the same area/id tie-break the nearest-wins
    ORDER BY shares with the existing smallest-area-wins membership step — not
    an accident of which extract's load happened to run last."""
    ensure_schema(db)
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES (1, 100, 'BE', "
        "ST_GeomFromText('POLYGON((3 50, 4 50, 4 51, 3 51, 3 50))', 4326))")
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES (2, 100, 'NL', "
        "ST_GeomFromText('POLYGON((4 50, 5 50, 5 51, 4 51, 4 50))', 4326))")
    db.commit()
    tied = _row("node/tie", "C", lon=4.0, lat=50.5)   # exactly on the shared edge

    load_region(db, [tied], "europe/belgium", "BE")
    load_region(db, [tied], "europe/netherlands", "NL")
    be_first = db.execute(
        "SELECT s.slug FROM coverage_poi p JOIN coverage_source s ON s.id = p.src_region_id"
    ).fetchone()

    db.execute("DELETE FROM coverage_poi")
    db.commit()

    load_region(db, [tied], "europe/netherlands", "NL")
    load_region(db, [tied], "europe/belgium", "BE")
    nl_first = db.execute(
        "SELECT s.slug FROM coverage_poi p JOIN coverage_source s ON s.id = p.src_region_id"
    ).fetchone()

    assert be_first == nl_first == ("europe/belgium",), (
        "an equidistant tie must resolve to the same owner regardless of load order "
        "(equal area, so the id-ascending tie-break picks BE's region id=1)")


def test_load_region_country_code_none_skips_filter_and_warns(db, capsys):
    """dev/fixture (country_code=None) explicitly disables the ownership filter
    and prints a warning naming the skip. Exercised incidentally by other
    tests, but nothing pins the behaviour directly (I6 item 4)."""
    ensure_schema(db)
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES (1, 100, 'BE', "
        "ST_GeomFromText('POLYGON((3 50, 4 50, 4 51, 3 51, 3 50))', 4326))")
    db.commit()
    # Far outside every region and every country — the filter would drop this
    # if it ran; country_code=None must skip it entirely and keep it.
    load_region(db, [_row("node/anywhere", "C", lon=20.0, lat=60.0, country_code=None)],
                "dev/fixture", None)
    assert db.execute("SELECT count(*) FROM coverage_poi").fetchone()[0] == 1
    err = capsys.readouterr().err
    assert "no configured country" in err and "ownership filter skipped" in err


def test_load_region_raises_when_country_has_no_regions(db):
    """I2 guard: an unseeded/mid-reseed `region` table for the extract's
    country would otherwise let the ownership filter silently delete every
    staged row. With previous=0 (a brand-new extract — exactly the onboarding
    case) nothing else catches it, so this must raise rather than exit 0."""
    ensure_schema(db)
    with pytest.raises(RuntimeError, match="onboarding step 5"):
        load_region(db, [_row("node/1", "C")], "europe/belgium", "BE")
    assert db.execute("SELECT count(*) FROM coverage_poi").fetchone()[0] == 0


def test_a_non_owning_extract_does_not_create_the_row(db):
    """The NL extract carries a Belgian entity. It must not appear at all."""
    ensure_schema(db)
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES (1, 100, 'BE', "
        "ST_GeomFromText('POLYGON((3 50, 4 50, 4 51, 3 51, 3 50))', 4326))")
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES (2, 100, 'NL', "
        "ST_GeomFromText('POLYGON((4 50, 5 50, 5 51, 4 51, 4 50))', 4326))")
    db.commit()

    load_region(db, [_row("node/be", "C", lon=3.5, lat=50.5)], "europe/netherlands", "NL")

    assert db.execute("SELECT count(*) FROM coverage_poi").fetchone()[0] == 0


def test_owner_dropping_the_entity_removes_it(db):
    """The week-long-disappearance case, inverted: once ownership is deterministic,
    only the OWNER's next run can delete the row — and a non-owning extract that
    still carries the SAME entity (Geofabrik's cuts overlap) can never resurrect
    it, even when that non-owner runs again after the owner has dropped it
    (2026-07-23-border-overlap-ownership-design.md §4)."""
    ensure_schema(db)
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES (1, 100, 'BE', "
        "ST_GeomFromText('POLYGON((3 50, 4 50, 4 51, 3 51, 3 50))', 4326))")
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES (2, 100, 'NL', "
        "ST_GeomFromText('POLYGON((4 50, 5 50, 5 51, 4 51, 4 50))', 4326))")
    db.commit()
    row = _row("node/gone", "C", lon=3.5, lat=50.5)   # geometrically inside BE, not NL

    # Two stable placeholders (also inside BE) keep the BE load within
    # DRIFT_ABORT_RATIO once node/gone is trimmed below — dropping 1 of 3 rows
    # stays under the 40% guard, unrelated to ownership, so the assertions below
    # isolate the ownership behaviour under test.
    placeholder1 = _row("node/stays1", "C", lon=3.5, lat=50.5)
    placeholder2 = _row("node/stays2", "C", lon=3.5, lat=50.5)

    load_region(db, [row, placeholder1, placeholder2], "europe/belgium", "BE")
    assert db.execute(
        "SELECT count(*) FROM coverage_poi WHERE ref = 'node/gone'"
    ).fetchone()[0] == 1
    db.commit()   # close out the SELECT's implicit tx so the next load_region starts fresh,
                  # not nested as a savepoint (its TEMP staging table needs a real COMMIT to drop)

    # Geofabrik's cut overlap means the NL extract ALSO carries this entity, even
    # though it geometrically belongs to BE. A non-owning extract's run must not
    # steal ownership or duplicate the row.
    load_region(db, [row], "europe/netherlands", "NL")
    owner = db.execute(
        "SELECT s.slug FROM coverage_poi p JOIN coverage_source s ON s.id = p.src_region_id "
        "WHERE p.ref = 'node/gone'"
    ).fetchone()
    assert owner == ("europe/belgium",), "a non-owning extract must not take ownership"
    db.commit()

    # The OWNER trims node/gone from its extract...
    load_region(db, [placeholder1, placeholder2], "europe/belgium", "BE")
    assert db.execute(
        "SELECT count(*) FROM coverage_poi WHERE ref = 'node/gone'"
    ).fetchone()[0] == 0, "the owner's next run must delete the row it no longer carries"
    db.commit()

    # ...and the non-owner, which still carries the entity, must not resurrect it
    # on a later run — the old last-writer-wins bug this design fixes.
    load_region(db, [row], "europe/netherlands", "NL")
    assert db.execute(
        "SELECT count(*) FROM coverage_poi WHERE ref = 'node/gone'"
    ).fetchone()[0] == 0, "a non-owning extract must never resurrect a dropped row"


def test_rows_in_no_onboarded_region_are_dropped(db):
    """Design decision 1: all 380 such rows were measured as foreign or offshore,
    not province-less, so they are not staged at all."""
    ensure_schema(db)
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES (1, 100, 'BE', "
        "ST_GeomFromText('POLYGON((3 50, 4 50, 4 51, 3 51, 3 50))', 4326))")
    db.commit()

    load_region(db, [
        _row("node/inside", "C", lon=3.5, lat=50.5),
        _row("node/far", "C", lon=20.0, lat=60.0),      # far outside every region
    ], "europe/belgium", "BE")

    refs = {r[0] for r in db.execute("SELECT ref FROM coverage_poi").fetchall()}
    assert refs == {"node/inside"}, "a row in no onboarded region must not be staged"


def test_boundary_snap_rows_keep_their_owner(db):
    """Design decision 2: the snap is UNCHANGED, so a row just outside every polygon
    but within BOUNDARY_SNAP_DEG of its own country's region is still owned and still
    region-stamped. Decisions 1 and 2 meet at this boundary and must not be conflated."""
    ensure_schema(db)
    db.execute(
        "INSERT INTO region (id, area_km2, country_code, geom) VALUES (1, 100, 'BE', "
        "ST_GeomFromText('POLYGON((3 50, 4 50, 4 51, 3 51, 3 50))', 4326))")
    db.commit()

    load_region(db, [_row("node/near", "C", lon=4.005, lat=50.5)], "europe/belgium", "BE")

    got = db.execute("SELECT ref, region_id FROM coverage_poi").fetchall()
    assert got == [("node/near", 1)], (
        "a row within BOUNDARY_SNAP_DEG of its own country's region is owned and stamped")


def test_src_region_normalized_and_self_filled(db):
    """Provenance is a coverage_source FK, self-filled get-or-create: each slug
    gets exactly one row, every POI links to it, and reloading a slug reuses its
    id rather than duplicating it — so the lookup scales worldwide with no
    pre-seeding or enum DDL."""
    ensure_schema(db)
    load_region(db, [_row("node/1", "C"), _row("node/2", "C")], "europe/belgium")
    load_region(
        db,
        [_row("node/3", "C", src_region="europe/netherlands", country_code="NL")],
        "europe/netherlands",
    )
    slugs = {r[0]: r[1] for r in db.execute("SELECT slug, id FROM coverage_source").fetchall()}
    assert set(slugs) == {"europe/belgium", "europe/netherlands"}
    linked = db.execute(
        "SELECT c.ref, s.slug FROM coverage_poi c "
        "JOIN coverage_source s ON s.id = c.src_region_id ORDER BY c.ref"
    ).fetchall()
    assert linked == [
        ("node/1", "europe/belgium"),
        ("node/2", "europe/belgium"),
        ("node/3", "europe/netherlands"),
    ]
    # reloading the same slug reuses the same id, never a second source row
    be_id = slugs["europe/belgium"]
    load_region(db, [_row("node/1", "C"), _row("node/2", "C")], "europe/belgium")
    assert db.execute("SELECT count(*) FROM coverage_source").fetchone()[0] == 2
    assert db.execute(
        "SELECT id FROM coverage_source WHERE slug = 'europe/belgium'"
    ).fetchone()[0] == be_id
    # the FK column is NOT NULL — provenance is mandatory for every harvested row
    nullable = db.execute(
        "SELECT is_nullable FROM information_schema.columns "
        "WHERE table_schema = 'coverage_pytest' AND table_name = 'coverage_poi' "
        "AND column_name = 'src_region_id'"
    ).fetchone()[0]
    assert nullable == "NO"


def test_apply_session_budget_applies_defaults(db):
    apply_session_budget(db)
    assert db.execute("SHOW work_mem").fetchone()[0] == "32MB"
    assert db.execute("SHOW synchronous_commit").fetchone()[0] == "off"
    assert db.execute("SHOW maintenance_work_mem").fetchone()[0] == "256MB"
    assert db.execute("SHOW max_parallel_workers_per_gather").fetchone()[0] == "0"


def test_apply_session_budget_honours_env_override(db, monkeypatch):
    monkeypatch.setenv("COVERAGE_WORK_MEM", "64MB")
    apply_session_budget(db)
    assert db.execute("SHOW work_mem").fetchone()[0] == "64MB"


def test_diff_merge_skips_unchanged_row(db):
    """An identical reload rewrites nothing: a region-less row's ctid is stable,
    proving the upsert's IS DISTINCT FROM guard skipped it (no heap write, no WAL)."""
    ensure_schema(db)
    rows = [_row("node/1", "C", lon=20.0, lat=60.0,
                 src_region="dev/fixture", country_code=None)]
    load_region(db, rows, "dev/fixture", None)
    before = db.execute("SELECT ctid::text FROM coverage_poi WHERE ref='node/1'").fetchone()[0]
    load_region(db, rows, "dev/fixture", None)          # byte-identical reload
    after = db.execute("SELECT ctid::text FROM coverage_poi WHERE ref='node/1'").fetchone()[0]
    assert before == after, "an unchanged row must not be rewritten"


def test_diff_merge_rewrites_only_the_changed_row(db):
    ensure_schema(db)
    r1 = _row("node/1", "C", lon=20.0, lat=60.0, src_region="dev/fixture", country_code=None)
    r2 = _row("node/2", "C", lon=21.0, lat=61.0, src_region="dev/fixture", country_code=None)
    load_region(db, [r1, r2], "dev/fixture", None)
    ctid0 = dict(db.execute("SELECT ref, ctid::text FROM coverage_poi").fetchall())
    r1b = _row("node/1", "C", lon=20.0, lat=60.0, name="RENAMED",
               src_region="dev/fixture", country_code=None)
    load_region(db, [r1b, r2], "dev/fixture", None)
    ctid1 = dict(db.execute("SELECT ref, ctid::text FROM coverage_poi").fetchall())
    assert ctid1["node/2"] == ctid0["node/2"], "unchanged sibling not rewritten"
    assert ctid1["node/1"] != ctid0["node/1"], "changed row rewritten"
    assert db.execute("SELECT name FROM coverage_poi WHERE ref='node/1'").fetchone()[0] == "RENAMED"


def test_diff_merge_deletes_disappeared_row(db):
    ensure_schema(db)
    # Three rows so dropping one (node/3) stays under the 40% drift guard —
    # isolating the delete-disappeared behaviour from the drift abort.
    a = _row("node/1", "C", lon=20.0, lat=60.0, src_region="dev/fixture", country_code=None)
    b = _row("node/2", "C", lon=21.0, lat=61.0, src_region="dev/fixture", country_code=None)
    c = _row("node/3", "C", lon=22.0, lat=62.0, src_region="dev/fixture", country_code=None)
    load_region(db, [a, b, c], "dev/fixture", None)
    load_region(db, [a, b], "dev/fixture", None)        # node/3 disappears upstream
    refs = {r[0] for r in db.execute("SELECT ref FROM coverage_poi").fetchall()}
    assert refs == {"node/1", "node/2"}, "a row gone from the extract is deleted from the slice"
