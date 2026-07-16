# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""tiles.py — GeoJSONL export shape, tippecanoe build, go-pmtiles verify.

The export test writes its own synthetic rows under src_region 'test/tiles'
(never a real Geofabrik region name) into the dev DB and removes them again;
the build/verify tests need no DB at all — just the container's tippecanoe
and pmtiles binaries.
"""
import json
import os

import psycopg
import pytest
from psycopg.types.json import Json

from coverage import tiles
from coverage.contract import load_contract
from coverage.load import ensure_schema

DSN = os.environ.get("DATABASE_DSN", "postgresql://cc:cc@db:5432/cyclingcommons")
SRC = "test/tiles"

FIXTURE_ROWS = [
    # (ref, letter, kind, name, lon, lat, tags)
    ("node/900000001", "D", "shop", "Vélodroom", 4.35, 50.85,
     {"shop": "bicycle", "name": "Vélodroom"}),
    ("node/900000002", "C", None, None, 4.40, 50.84,
     {"amenity": "drinking_water"}),
    ("node/900000003", "C", None, "Oude pomp", 4.41, 50.83,
     {"amenity": "drinking_water", "drinking_water": "no"}),
    ("node/900000004", "E", None, "Camping Dijle", 4.70, 50.88,
     {"tourism": "camp_site", "wheelchair": "yes", "name": "Camping Dijle"}),
]


def _label(letter, tag):
    """The tile 't' value is the contract selector's label — read it from there."""
    return next(s.label for s in load_contract().letters[letter].selectors if s.tag == tag)


def _features(path):
    with open(path, encoding="utf-8") as fh:
        return {f["properties"]["ref"]: f for f in map(json.loads, fh)}


def test_export_geojsonl_shapes(tmp_path):
    with psycopg.connect(DSN) as conn:
        ensure_schema(conn)
        conn.execute("DELETE FROM coverage_poi WHERE src_region = %s", (SRC,))
        for ref, letter, kind, name, lon, lat, tags in FIXTURE_ROWS:
            conn.execute(
                "INSERT INTO coverage_poi (ref, letter, kind, name, geom, tags, src_region)"
                " VALUES (%s, %s, %s, %s, ST_SetSRID(ST_MakePoint(%s, %s), 4326), %s, %s)",
                (ref, letter, kind, name, lon, lat, Json(tags), SRC))
        conn.commit()
        try:
            out = tiles.export_geojsonl(conn, tmp_path)

            shop = _features(out["D"])["node/900000001"]
            assert shop["id"] == 900000001
            assert shop["geometry"]["coordinates"] == pytest.approx([4.35, 50.85])
            assert shop["properties"]["t"] == _label("D", "shop=bicycle")
            assert shop["properties"]["kind"] == "shop"
            assert shop["properties"]["n"] == "Vélodroom"

            water = _features(out["C"])
            assert water["node/900000002"]["properties"]["potable"] is True
            assert "n" not in water["node/900000002"]["properties"]  # jsonb_strip_nulls
            assert water["node/900000003"]["properties"]["potable"] is False

            stay = _features(out["E"])["node/900000004"]
            assert stay["properties"]["t"] == _label("E", "tourism=camp_site")
            assert stay["properties"]["acc"] == "Wheelchair-accessible"
        finally:
            conn.execute("DELETE FROM coverage_poi WHERE src_region = %s", (SRC,))
            conn.commit()


def _geojsonl(path, rows):
    path.write_text("".join(
        json.dumps({"type": "Feature", "id": i,
                    "geometry": {"type": "Point", "coordinates": [lon, lat]},
                    "properties": props}) + "\n"
        for i, (lon, lat, props) in enumerate(rows, start=1)), encoding="utf-8")
    return path


@pytest.fixture()
def built(tmp_path):
    files = {
        "C": _geojsonl(tmp_path / "c.geojsonl", [
            (4.35, 50.85, {"ref": "node/1", "t": "Drinking water", "potable": True}),
            (5.57, 50.63, {"ref": "node/2", "t": "Drinking water", "potable": False}),
        ]),
        "D": _geojsonl(tmp_path / "d.geojsonl", [
            (4.40, 50.84, {"ref": "node/3", "n": "Bike shop BXL", "t": "Bike shop", "kind": "shop"}),
        ]),
    }
    out = tmp_path / "coverage.pmtiles"
    tiles.build_pmtiles(files, out)
    return out


def test_build_and_verify_pmtiles(built):
    # Fixture bounds ≈ (4.35, 50.63, 5.57, 50.85) — must intersect the expected
    # Belgium box; passing expected_bbox also exercises the bounds check and
    # the sample-tile decode (design §5 step 7: bounds, tile count, decode).
    tiles.verify_pmtiles(built, expected_layers={"c", "d"},
                         expected_bbox=(4.0, 50.0, 6.0, 51.5))  # must not raise


def test_verify_pmtiles_missing_layer_raises(built):
    with pytest.raises(RuntimeError, match="missing layer"):
        tiles.verify_pmtiles(built, expected_layers={"c", "d", "e"})


def test_verify_pmtiles_disjoint_bounds_raise(built):
    with pytest.raises(RuntimeError, match="bounds"):
        tiles.verify_pmtiles(built, expected_layers={"c", "d"},
                             expected_bbox=(120.0, 10.0, 121.0, 11.0))
