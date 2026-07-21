# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""tiles.py — GeoJSONL export shape, tippecanoe build, go-pmtiles verify.

The export test rides the shared `db` conftest fixture (isolated
coverage_pytest schema on the dev PostGIS, dropped at teardown) — it never
writes the shared public table; the build/verify tests need no DB at all —
just the container's tippecanoe and pmtiles binaries.
"""
import json
import re

import pytest
from psycopg.types.json import Json

from coverage import tiles
from coverage.contract import load_contract
from coverage.load import ensure_schema

SRC = "test/tiles"  # synthetic src_region label, never a real Geofabrik name

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


def test_export_geojsonl_shapes(db, tmp_path):
    ensure_schema(db)
    for ref, letter, kind, name, lon, lat, tags in FIXTURE_ROWS:
        db.execute(
            "INSERT INTO coverage_poi (ref, letter, kind, name, geom, tags, src_region)"
            " VALUES (%s, %s, %s, %s, ST_SetSRID(ST_MakePoint(%s, %s), 4326), %s, %s)",
            (ref, letter, kind, name, lon, lat, Json(tags), SRC))
    out = tiles.export_geojsonl(db, tmp_path)

    # Empty-letter omission contract: exactly the letters with rows appear.
    assert set(out) == {"C", "D", "E"}
    assert "G" not in out

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

    # rid/cc are the region-scoping keys (region-scoping-design.md §6). The
    # fixture rows carry neither region_id nor country_code, so jsonb_strip_nulls
    # drops them — a prop-less feature the client renders unfiltered (the §8
    # risk-2 fallback until the weekly rebuild stamps them).
    assert "rid" not in shop["properties"]
    assert "cc" not in shop["properties"]


def test_export_carries_rid_cc_when_stamped(db, tmp_path):
    """A region-stamped coverage_poi row emits rid (region_id) + cc
    (country_code) as flat tile props for the client scope filter."""
    ensure_schema(db)
    db.execute(
        "INSERT INTO coverage_poi (ref, letter, kind, name, geom, tags, src_region, region_id, country_code)"
        " VALUES (%s, %s, %s, %s, ST_SetSRID(ST_MakePoint(%s, %s), 4326), %s, %s, %s, %s)",
        ("node/900001001", "D", "shop", "Scoped shop", 4.35, 50.85,
         Json({"shop": "bicycle", "name": "Scoped shop"}), SRC, 42, "BE"))
    out = tiles.export_geojsonl(db, tmp_path)
    props = _features(out["D"])["node/900001001"]["properties"]
    assert props["rid"] == 42          # flat bigint, MVT-legal
    assert props["cc"] == "BE"


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
    # the sample-tile decode (coverage-provider.md §3 step 7: bounds, tile
    # count, decode).
    tiles.verify_pmtiles(built, expected_layers={"c", "d"},
                         expected_bbox=(4.0, 50.0, 6.0, 51.5))  # must not raise


def test_verify_pmtiles_missing_layer_raises(built):
    with pytest.raises(RuntimeError, match="missing layer"):
        tiles.verify_pmtiles(built, expected_layers={"c", "d", "e"})


def test_verify_pmtiles_default_layers_expect_all_contract_letters(built):
    # expected_layers=None defaults to every contract letter lowercased; the
    # two-layer fixture must fail, naming exactly the absent letters.
    with pytest.raises(RuntimeError, match="missing layer") as exc:
        tiles.verify_pmtiles(built)
    absent = sorted({letter.lower() for letter in load_contract().letters} - {"c", "d"})
    assert str(absent) in str(exc.value)


def test_verify_pmtiles_disjoint_bounds_raise(built):
    with pytest.raises(RuntimeError, match="bounds"):
        tiles.verify_pmtiles(built, expected_layers={"c", "d"},
                             expected_bbox=(120.0, 10.0, 121.0, 11.0))


def test_verify_pmtiles_zero_addressed_tiles_raise(built, monkeypatch):
    # tippecanoe refuses to build from zero features (exit 110, no valid
    # archive written), so a real zero-addressed-tiles artifact cannot exist
    # on disk; doctor the parsed `show` header instead — this tests the raise
    # semantics of the count gate only.
    doctored = re.sub(r"(addressed tiles(?: count)?:)\s*\d+", r"\1 0",
                      tiles._show(built))
    monkeypatch.setattr(tiles, "_show", lambda path, *flags: doctored)
    with pytest.raises(RuntimeError, match="no addressed tiles"):
        tiles.verify_pmtiles(built, expected_layers={"c", "d"})


def test_verify_pmtiles_all_sample_tiles_empty_raises(built, monkeypatch):
    # A real archive whose header bbox is populated but whose min-zoom sample
    # area decodes entirely empty cannot be built (tippecanoe derives the
    # header bounds from the features themselves); stub the tile fetch to
    # test the decode-gate raise semantics.
    monkeypatch.setattr(tiles, "_tile_bytes", lambda *a: b"")
    with pytest.raises(RuntimeError, match="no decodable non-empty tile"):
        tiles.verify_pmtiles(built, expected_layers={"c", "d"})


def test_verify_pmtiles_subprocess_failure_surfaces_diagnostics(tmp_path):
    # A failing go-pmtiles invocation must raise RuntimeError carrying the
    # command and its stderr diagnostics, not a bare CalledProcessError.
    bogus = tmp_path / "bogus.pmtiles"
    bogus.write_bytes(b"not a pmtiles archive")
    with pytest.raises(RuntimeError) as exc:
        tiles.verify_pmtiles(bogus)
    msg = str(exc.value)
    assert "pmtiles show" in msg      # the exact failing command
    assert "magic number" in msg      # go-pmtiles' captured stderr
