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

SRC = "test/tiles"  # synthetic src_region slug, never a real Geofabrik name


def _src_id(db, slug=SRC):
    """Resolve (get-or-create) a coverage_source id for the seeders' NOT NULL FK."""
    db.execute("INSERT INTO coverage_source (slug) VALUES (%s) ON CONFLICT (slug) DO NOTHING", (slug,))
    return db.execute("SELECT id FROM coverage_source WHERE slug = %s", (slug,)).fetchone()[0]

FIXTURE_ROWS = [
    # (ref, letter, kind, name, lon, lat, tags)
    ("node/900000001", "D", "shop", "Vélodroom", 4.35, 50.85,
     {"shop": "bicycle", "name": "Vélodroom"}),
    ("node/900000002", "B", None, None, 4.40, 50.84,
     {"amenity": "drinking_water"}),
    ("node/900000003", "B", None, "Oude pomp", 4.41, 50.83,
     {"amenity": "drinking_water", "drinking_water": "no"}),
    ("node/900000004", "O", None, "Camping Dijle", 4.70, 50.88,
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
    sid = _src_id(db)
    for ref, letter, kind, name, lon, lat, tags in FIXTURE_ROWS:
        db.execute(
            "INSERT INTO coverage_poi (ref, letter, kind, name, geom, tags, src_region_id)"
            " VALUES (%s, %s, %s, %s, ST_SetSRID(ST_MakePoint(%s, %s), 4326), %s, %s)",
            (ref, letter, kind, name, lon, lat, Json(tags), sid))
    out = tiles.export_geojsonl(db, tmp_path)

    # Empty-letter omission contract: exactly the (letter, cc) combos with rows
    # appear. FIXTURE_ROWS stamp no country_code, so every row buckets to the
    # unstamped 'ZZ' pseudo-country (coverage-provider.md §4 — per-country
    # tile split).
    assert set(out) == {("B", "ZZ"), ("D", "ZZ"), ("O", "ZZ")}
    assert not any(letter == "F" for letter, _ in out)

    shop = _features(out[("D", "ZZ")])["node/900000001"]
    assert shop["id"] == 900000001
    assert shop["geometry"]["coordinates"] == pytest.approx([4.35, 50.85])
    assert shop["properties"]["t"] == _label("D", "shop=bicycle")
    assert shop["properties"]["kind"] == "shop"
    assert shop["properties"]["n"] == "Vélodroom"

    water = _features(out[("B", "ZZ")])
    assert water["node/900000002"]["properties"]["potable"] is True
    assert "n" not in water["node/900000002"]["properties"]  # jsonb_strip_nulls
    assert water["node/900000003"]["properties"]["potable"] is False

    stay = _features(out[("O", "ZZ")])["node/900000004"]
    assert stay["properties"]["t"] == _label("O", "tourism=camp_site")
    assert stay["properties"]["acc"] == "Wheelchair-accessible"

    # ridtok/cctok are the region-scoping keys (map-and-search.md §4.5) as
    # pipe-delimited membership tokens. They are ALWAYS emitted (never
    # NULL-stripped): an unstamped row gets the empty string, which the client
    # reads as prop-less and renders unfiltered (the §8 risk-2 fallback until the
    # weekly rebuild stamps them). Empty is what lets --accumulate-attribute=concat
    # union tokens across a cluster without tippecanoe's missing-attribute abort.
    assert shop["properties"]["ridtok"] == ""
    assert shop["properties"]["cctok"] == ""

    # Contract enforcement (universalTileProps consumed, not just declared): every
    # exported feature's key set is exactly the contract's universal props plus
    # this letter's declared extras — an undeclared column can't ship silently.
    contract = load_contract()
    for letter, feats in (("D", _features(out[("D", "ZZ")])), ("B", _features(out[("B", "ZZ")])),
                          ("O", _features(out[("O", "ZZ")]))):
        allowed = set(contract.universal_tile_props) | set(contract.letters[letter].tile_props)
        for ref, feat in feats.items():
            extra = set(feat["properties"]) - allowed
            assert not extra, f"{ref}: undeclared tile prop(s) {extra} (contract drift)"


def test_export_carries_scope_tokens_when_stamped(db, tmp_path):
    """A region-stamped row emits ridtok="|<region_id>|" + cctok="|<cc>|"; a
    cc-only boundary row (region_id NULL) emits an EMPTY ridtok but a non-empty
    cctok — the shape the client hides under a region scope yet shows under its
    country scope (map-and-search.md §4.5, finding 5)."""
    ensure_schema(db)
    sid = _src_id(db)
    db.execute(
        "INSERT INTO coverage_poi (ref, letter, kind, name, geom, tags, src_region_id, region_id, country_code)"
        " VALUES (%s, %s, %s, %s, ST_SetSRID(ST_MakePoint(%s, %s), 4326), %s, %s, %s, %s)",
        ("node/900001001", "D", "shop", "Scoped shop", 4.35, 50.85,
         Json({"shop": "bicycle", "name": "Scoped shop"}), sid, 42, "BE"))
    db.execute(
        "INSERT INTO coverage_poi (ref, letter, kind, name, geom, tags, src_region_id, region_id, country_code)"
        " VALUES (%s, %s, %s, %s, ST_SetSRID(ST_MakePoint(%s, %s), 4326), %s, %s, %s, %s)",
        ("node/900001002", "D", "shop", "Border shop", 4.36, 50.86,
         Json({"shop": "bicycle", "name": "Border shop"}), sid, None, "BE"))
    out = tiles.export_geojsonl(db, tmp_path)
    stamped = _features(out[("D", "BE")])["node/900001001"]["properties"]
    assert stamped["ridtok"] == "|42|"
    assert stamped["cctok"] == "|BE|"
    ccOnly = _features(out[("D", "BE")])["node/900001002"]["properties"]
    assert ccOnly["ridtok"] == ""       # region-scope hides it (matches /counts)
    assert ccOnly["cctok"] == "|BE|"    # country-scope shows it


def test_export_geojsonl_splits_by_country(db, tmp_path):
    ensure_schema(db)
    sid = _src_id(db)
    rows = [
        ("node/1", "B", 4.35, 50.85, "BE"),
        ("node/2", "B", 4.40, 50.84, "BE"),
        ("node/3", "B", 5.10, 52.09, "NL"),
        ("node/4", "B", 6.00, 53.00, None),   # unstamped -> zz bucket
    ]
    for ref, letter, lon, lat, cc in rows:
        db.execute(
            "INSERT INTO coverage_poi (ref, letter, geom, tags, src_region_id, country_code)"
            " VALUES (%s, %s, ST_SetSRID(ST_MakePoint(%s,%s),4326), %s, %s, %s)",
            (ref, letter, lon, lat, Json({"amenity": "drinking_water"}), sid, cc))
    files = tiles.export_geojsonl(db, tmp_path)
    keys = set(files)
    assert ("B", "BE") in keys and ("B", "NL") in keys and ("B", "ZZ") in keys
    # each file holds only its country's rows
    assert set(_features(files[("B", "BE")])) == {"node/1", "node/2"}
    assert set(_features(files[("B", "NL")])) == {"node/3"}
    assert set(_features(files[("B", "ZZ")])) == {"node/4"}
    # cctok is single-country per file (BE file never carries |NL|)
    be = _features(files[("B", "BE")])["node/1"]["properties"]
    assert be["cctok"] == "|BE|"
    zz = _features(files[("B", "ZZ")])["node/4"]["properties"]
    assert zz["cctok"] == ""   # unstamped stays prop-less


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
        ("B", "BE"): _geojsonl(tmp_path / "b_be.geojsonl", [
            (4.35, 50.85, {"ref": "node/1", "t": "Drinking water", "potable": True}),
            (5.57, 50.63, {"ref": "node/2", "t": "Drinking water", "potable": False}),
        ]),
        ("D", "BE"): _geojsonl(tmp_path / "d_be.geojsonl", [
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
    tiles.verify_pmtiles(built, expected_layers={"b_be", "d_be"},
                         expected_bbox=(4.0, 50.0, 6.0, 51.5))  # must not raise


def test_verify_pmtiles_missing_layer_raises(built):
    with pytest.raises(RuntimeError, match="missing layer"):
        tiles.verify_pmtiles(built, expected_layers={"b_be", "d_be", "o_be"})


def test_verify_pmtiles_default_layers_expect_all_contract_letters(built):
    # expected_layers=None defaults to every contract letter lowercased (no cc
    # suffix); the built fixture's actual layers are the letter_cc names, so
    # NONE of the default single-letter names match — every contract letter is
    # reported absent.
    with pytest.raises(RuntimeError, match="missing layer") as exc:
        tiles.verify_pmtiles(built)
    absent = sorted(letter.lower() for letter in load_contract().letters)
    assert str(absent) in str(exc.value)


def test_verify_pmtiles_disjoint_bounds_raise(built):
    with pytest.raises(RuntimeError, match="bounds"):
        tiles.verify_pmtiles(built, expected_layers={"b_be", "d_be"},
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
        tiles.verify_pmtiles(built, expected_layers={"b_be", "d_be"})


def test_verify_pmtiles_all_sample_tiles_empty_raises(built, monkeypatch):
    # A real archive whose header bbox is populated but whose min-zoom sample
    # area decodes entirely empty cannot be built (tippecanoe derives the
    # header bounds from the features themselves); stub the tile fetch to
    # test the decode-gate raise semantics.
    monkeypatch.setattr(tiles, "_tile_bytes", lambda *a: b"")
    with pytest.raises(RuntimeError, match="no decodable non-empty tile"):
        tiles.verify_pmtiles(built, expected_layers={"b_be", "d_be"})


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


# ---- No clustering: individual points from z11 ----

def _tile_xy(lon: float, lat: float, z: int) -> tuple[int, int]:
    n = 2 ** z
    x = min(n - 1, max(0, int((lon + 180.0) / 360.0 * n)))
    return x, tiles._tile_y(lat, n)


def _decode_layer(path, z: int, x: int, y: int, layer: str) -> list:
    """tippecanoe-decode one tile → the features of one named vector layer.

    Output shape (tippecanoe-decode): a top FeatureCollection whose `features`
    are per-layer FeatureCollections, each tagged `properties.layer`. A tile
    outside the archive's zoom range (e.g. z6 below the z11 coverage floor)
    decodes as an empty stdout, not JSON — treat that the same as "no such
    tile", not a parse error."""
    raw = tiles._run(["tippecanoe-decode", str(path), str(z), str(x), str(y)], text=True)
    if not raw.strip():
        return []
    doc = json.loads(raw)
    for lyr in doc.get("features", []):
        if lyr.get("properties", {}).get("layer") == layer:
            return lyr.get("features", [])
    return []


def test_build_command_has_no_clustering_and_minzoom_6(tmp_path, monkeypatch):
    # Coverage is rendered as INDIVIDUAL points from z6 up, never clustered:
    # a cluster's rendered
    # centroid can sit outside a scoped region (the phantom-bubble class), and
    # individual points are scope-filtered exactly. So the build must carry NO
    # cluster/accumulate flags and start at zoom 6.
    captured = {}
    monkeypatch.setattr(tiles.subprocess, "run",
                        lambda cmd, check=True: captured.setdefault("cmd", cmd))
    tiles.build_pmtiles({("D", "BE"): tmp_path / "d.geojsonl"}, tmp_path / "o.pmtiles")
    cmd = captured["cmd"]
    for flag, val in [("--minimum-zoom", "6"), ("--maximum-zoom", "14")]:
        assert flag in cmd and cmd[cmd.index(flag) + 1] == val, f"{flag} {val}"
    for absent in ("--cluster-distance", "--cluster-maxzoom",
                   "--cluster-densest-as-needed", "ridtok:concat", "cctok:concat"):
        assert absent not in cmd, f"clustering must be gone: {absent}"
    assert "-r1" in cmd, "keep every point at the built zooms"
    # z6-10 tiles are thinned to fit (the density sample for the overview
    # heatmap); z11-14 tiles fit within budget and keep every point (complete
    # icons) — never rate-based thinning across all zooms.
    assert "--drop-densest-as-needed" in cmd


def test_no_clusters_individual_points_z6_to_14(tmp_path):
    # Coverage is rendered individual (no clusters) across the whole zoom range:
    # z6-10 thinned to fit for the overview heatmap, z11-14 complete for icons.
    # Crucially: NO feature
    # ever carries point_count (no clustering, at any zoom).
    n = 60
    rows = [(4.34 + (i % 10) * 0.002, 50.84 + (i // 10) * 0.002,
             {"ref": f"node/{i}", "t": "Bike shop", "kind": "shop"})
            for i in range(n)]
    out = tmp_path / "nc.pmtiles"
    tiles.build_pmtiles({("D", "BE"): _geojsonl(tmp_path / "d.geojsonl", rows)}, out)

    # z6 tiles now EXIST (minzoom 6, for the overview heatmap) and carry
    # individual points, none clustered.
    x6, y6 = _tile_xy(4.35, 50.85, 6)
    feats6 = _decode_layer(out, 6, x6, y6, "d_be")
    assert feats6, "z6 tile must carry coverage points (overview heatmap source)"
    assert not any("point_count" in f["properties"] for f in feats6), \
        "no clusters at z6 — individual (thinned) points only"

    # z11: individual points, none clustered.
    x11, y11 = _tile_xy(4.35, 50.85, 11)
    feats11 = _decode_layer(out, 11, x11, y11, "d_be")
    assert feats11, "z11 tile must carry the individual points"
    assert not any("point_count" in f["properties"] for f in feats11), \
        "no clusters at z11 — individual points only"


def test_extra_sql_tag_keys_are_pinned_to_the_contract_constant():
    """TILE_DERIVED_TAG_KEYS must equal what _EXTRA_SQL actually reads from tags.

    load_contract() validates that every key in the constant survives the
    storedTagKeys trim (coverage-provider.md §2). That guarantee is only as good
    as the constant tracking this SQL, so pin the two together: adding a
    `tags->>'foo'` to a tile property without listing `foo` fails here, before it
    can ship a tile column that is silently always NULL.
    """
    from coverage.contract import TILE_DERIVED_TAG_KEYS

    read_by_sql = {
        key
        for fragment in tiles._EXTRA_SQL.values()
        for key in re.findall(r"tags\s*(?:->>|\?)\s*'([^']+)'", fragment)
    }
    assert read_by_sql == TILE_DERIVED_TAG_KEYS


def test_routes_build_command_layers_lines_and_knooppunten(tmp_path, monkeypatch):
    # One artifact, two layer families: routes_<cc> corridors and knoop_<cc>
    # numbers, zoomed by the contract. No thinning (a dropped line is a route
    # that vanishes; a dropped point is a knooppunt a rider cannot find), and
    # -r1 keeps every number at the built zooms — the badges are a navigation
    # aid, not a density sample.
    captured = {}
    monkeypatch.setattr(tiles, "_run", lambda cmd: captured.setdefault("cmd", cmd))
    contract = load_contract()
    tiles.build_routes_pmtiles(
        {"NL": [tmp_path / "nl_ways.geojsonl"], "BE": [tmp_path / "be_ways.geojsonl"]},
        {"NL": [tmp_path / "nl_knoop.geojsonl"]},
        tmp_path / "routes.pmtiles", contract)
    cmd = captured["cmd"]
    spec = contract.routes
    for flag, val in [("--minimum-zoom", str(spec["minZoom"])),
                      ("--maximum-zoom", str(spec["maxZoom"]))]:
        assert flag in cmd and cmd[cmd.index(flag) + 1] == val, f"{flag} {val}"
    layers = [cmd[i + 1] for i, a in enumerate(cmd) if a == "-L"]
    assert [l.split(":")[0] for l in layers] == ["routes_be", "routes_nl", "knoop_nl"]
    assert "--no-feature-limit" in cmd and "--no-tile-size-limit" in cmd
    assert "-r1" in cmd
    assert "--drop-densest-as-needed" not in cmd
