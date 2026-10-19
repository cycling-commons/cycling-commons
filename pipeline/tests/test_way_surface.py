# SPDX-License-Identifier: AGPL-3.0-only
"""coverage_way_surface: each OSM way's recorded surface, for the planner (coverage-provider.md §4)."""

from __future__ import annotations

import psycopg
import pytest

from coverage import way_surface


@pytest.fixture()
def conn(db):
    """The test schema on an autocommit connection, as the surface run holds it."""
    db.commit()
    db.autocommit = True
    way_surface.ensure_schema(db)
    return db


def _rows(conn, cc=None):
    q = "SELECT way_id, country_code, surface, smoothness, tracktype FROM coverage_way_surface"
    if cc:
        return conn.execute(q + " WHERE country_code = %s ORDER BY way_id", (cc,)).fetchall()
    return conn.execute(q + " ORDER BY way_id").fetchall()


def _marker(conn, cc):
    return conn.execute("SELECT inputs, ways FROM coverage_way_surface_country WHERE country_code = %s",
                        (cc,)).fetchone()


def _tsv(path, *lines):
    path.write_text("".join(line + "\n" for line in lines))
    return path


def test_a_country_loads_with_nulls_and_its_marker(conn, tmp_path):
    f = _tsv(tmp_path / "be.tsv", "1\tgravel\tbad\tgrade2", "2\t\\N\t\\N\tgrade4")
    assert way_surface.load_country(conn, "BE", [f], "in-1") == 2
    assert _rows(conn) == [(1, "BE", "gravel", "bad", "grade2"), (2, "BE", None, None, "grade4")]
    assert _marker(conn, "BE") == ("in-1", 2)


def test_a_way_in_two_regions_of_one_country_is_one_row(conn, tmp_path):
    # The US is california + colorado: a way on the state line can sit in both.
    ca = _tsv(tmp_path / "ca.tsv", "7\tpaved\t\\N\t\\N", "8\tdirt\t\\N\t\\N")
    co = _tsv(tmp_path / "co.tsv", "7\tpaved\t\\N\t\\N", "9\trock\t\\N\t\\N")
    assert way_surface.load_country(conn, "US", [ca, co], "in-1") == 3
    assert [r[0] for r in _rows(conn, "US")] == [7, 8, 9]
    assert _marker(conn, "US") == ("in-1", 3)


def test_unchanged_inputs_skip_the_load(conn, tmp_path):
    f = _tsv(tmp_path / "be.tsv", "1\tgravel\t\\N\t\\N")
    way_surface.load_country(conn, "BE", [f], "in-1")
    _tsv(f, "1\tpaved\t\\N\t\\N")
    assert way_surface.load_country(conn, "BE", [f], "in-1") is None
    assert _rows(conn) == [(1, "BE", "gravel", None, None)]


def test_a_reload_replaces_only_that_countrys_rows(conn, tmp_path):
    way_surface.load_country(conn, "BE", [_tsv(tmp_path / "be.tsv", "1\tgravel\t\\N\t\\N",
                                               "2\tpaved\t\\N\t\\N")], "be-1")
    way_surface.load_country(conn, "NL", [_tsv(tmp_path / "nl.tsv", "5\tpaved\t\\N\t\\N")], "nl-1")
    # Way 2 was retagged out of the layer; way 3 is new.
    assert way_surface.load_country(conn, "BE", [_tsv(tmp_path / "be.tsv", "1\tdirt\t\\N\t\\N",
                                                      "3\tpave\t\\N\t\\N")], "be-2") == 2
    assert _rows(conn) == [(1, "BE", "dirt", None, None), (3, "BE", "pave", None, None),
                           (5, "NL", "paved", None, None)]
    assert _marker(conn, "BE") == ("be-2", 2)
    assert _marker(conn, "NL") == ("nl-1", 1)


def test_a_way_the_owner_rule_moves_changes_country(conn, tmp_path):
    # Moved outlines: NL now owns way 1, while BE's rows are last week's.
    way_surface.load_country(conn, "BE", [_tsv(tmp_path / "be.tsv", "1\tgravel\t\\N\t\\N")], "be-1")
    assert way_surface.load_country(conn, "NL", [_tsv(tmp_path / "nl.tsv", "1\tpaved\tgood\t\\N")],
                                    "nl-1") == 1
    assert _rows(conn) == [(1, "NL", "paved", "good", None)]


def test_a_failed_load_keeps_last_weeks_rows(conn, tmp_path):
    way_surface.load_country(conn, "BE", [_tsv(tmp_path / "be.tsv", "1\tgravel\t\\N\t\\N")], "be-1")
    broken = _tsv(tmp_path / "broken.tsv", "2\tpaved\t\\N\t\\N", "not-a-way-id\tpaved\t\\N\t\\N")
    with pytest.raises(psycopg.Error):
        way_surface.load_country(conn, "BE", [broken], "be-2")
    assert _rows(conn) == [(1, "BE", "gravel", None, None)]
    assert _marker(conn, "BE") == ("be-1", 1)


def test_a_missing_file_loads_nothing(conn, tmp_path):
    way_surface.load_country(conn, "BE", [_tsv(tmp_path / "be.tsv", "1\tgravel\t\\N\t\\N")], "be-1")
    with pytest.raises(FileNotFoundError):
        way_surface.load_country(conn, "BE", [tmp_path / "be.tsv", tmp_path / "gone.tsv"], "be-2")
    assert _marker(conn, "BE") == ("be-1", 1)


def test_retiring_a_country_removes_its_rows_and_marker(conn, tmp_path):
    way_surface.load_country(conn, "BE", [_tsv(tmp_path / "be.tsv", "1\tgravel\t\\N\t\\N")], "be-1")
    way_surface.load_country(conn, "NL", [_tsv(tmp_path / "nl.tsv", "5\tpaved\t\\N\t\\N")], "nl-1")
    assert way_surface.retire_country(conn, "BE") == 1
    assert _rows(conn) == [(5, "NL", "paved", None, None)]
    assert _marker(conn, "BE") is None


def test_ensure_schema_is_idempotent(conn):
    way_surface.ensure_schema(conn)
    cols = conn.execute(
        "SELECT table_name, column_name, data_type, is_nullable FROM information_schema.columns "
        "WHERE table_schema = 'coverage_pytest' AND table_name LIKE 'coverage_way_surface%%' "
        "ORDER BY table_name, ordinal_position").fetchall()
    assert cols == [
        ("coverage_way_surface", "way_id", "bigint", "NO"),
        ("coverage_way_surface", "country_code", "text", "NO"),
        ("coverage_way_surface", "surface", "text", "YES"),
        ("coverage_way_surface", "smoothness", "text", "YES"),
        ("coverage_way_surface", "tracktype", "text", "YES"),
        ("coverage_way_surface_country", "country_code", "text", "NO"),
        ("coverage_way_surface_country", "inputs", "text", "NO"),
        ("coverage_way_surface_country", "ways", "integer", "NO"),
        ("coverage_way_surface_country", "loaded_at", "timestamp with time zone", "NO"),
    ]
