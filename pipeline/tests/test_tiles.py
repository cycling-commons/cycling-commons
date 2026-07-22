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
    sid = _src_id(db)
    for ref, letter, kind, name, lon, lat, tags in FIXTURE_ROWS:
        db.execute(
            "INSERT INTO coverage_poi (ref, letter, kind, name, geom, tags, src_region_id)"
            " VALUES (%s, %s, %s, %s, ST_SetSRID(ST_MakePoint(%s, %s), 4326), %s, %s)",
            (ref, letter, kind, name, lon, lat, Json(tags), sid))
    out = tiles.export_geojsonl(db, tmp_path)

    # Empty-letter omission contract: exactly the (letter, cc) combos with rows
    # appear. FIXTURE_ROWS stamp no country_code, so every row buckets to the
    # unstamped 'ZZ' pseudo-country (coverage-scope-rendering.md — per-country
    # tile split).
    assert set(out) == {("C", "ZZ"), ("D", "ZZ"), ("E", "ZZ")}
    assert not any(letter == "G" for letter, _ in out)

    shop = _features(out[("D", "ZZ")])["node/900000001"]
    assert shop["id"] == 900000001
    assert shop["geometry"]["coordinates"] == pytest.approx([4.35, 50.85])
    assert shop["properties"]["t"] == _label("D", "shop=bicycle")
    assert shop["properties"]["kind"] == "shop"
    assert shop["properties"]["n"] == "Vélodroom"

    water = _features(out[("C", "ZZ")])
    assert water["node/900000002"]["properties"]["potable"] is True
    assert "n" not in water["node/900000002"]["properties"]  # jsonb_strip_nulls
    assert water["node/900000003"]["properties"]["potable"] is False

    stay = _features(out[("E", "ZZ")])["node/900000004"]
    assert stay["properties"]["t"] == _label("E", "tourism=camp_site")
    assert stay["properties"]["acc"] == "Wheelchair-accessible"

    # ridtok/cctok are the region-scoping keys (region-scoping-design.md §6) as
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
    for letter, feats in (("D", _features(out[("D", "ZZ")])), ("C", _features(out[("C", "ZZ")])),
                          ("E", _features(out[("E", "ZZ")]))):
        allowed = set(contract.universal_tile_props) | set(contract.letters[letter].tile_props)
        for ref, feat in feats.items():
            extra = set(feat["properties"]) - allowed
            assert not extra, f"{ref}: undeclared tile prop(s) {extra} (contract drift)"


def test_export_carries_scope_tokens_when_stamped(db, tmp_path):
    """A region-stamped row emits ridtok="|<region_id>|" + cctok="|<cc>|"; a
    cc-only boundary row (region_id NULL) emits an EMPTY ridtok but a non-empty
    cctok — the shape the client hides under a region scope yet shows under its
    country scope (region-scoping-design.md §6, finding 5)."""
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
        ("node/1", "C", 4.35, 50.85, "BE"),
        ("node/2", "C", 4.40, 50.84, "BE"),
        ("node/3", "C", 5.10, 52.09, "NL"),
        ("node/4", "C", 6.00, 53.00, None),   # unstamped -> zz bucket
    ]
    for ref, letter, lon, lat, cc in rows:
        db.execute(
            "INSERT INTO coverage_poi (ref, letter, geom, tags, src_region_id, country_code)"
            " VALUES (%s, %s, ST_SetSRID(ST_MakePoint(%s,%s),4326), %s, %s, %s)",
            (ref, letter, lon, lat, Json({"amenity": "drinking_water"}), sid, cc))
    files = tiles.export_geojsonl(db, tmp_path)
    keys = set(files)
    assert ("C", "BE") in keys and ("C", "NL") in keys and ("C", "ZZ") in keys
    # each file holds only its country's rows
    assert set(_features(files[("C", "BE")])) == {"node/1", "node/2"}
    assert set(_features(files[("C", "NL")])) == {"node/3"}
    assert set(_features(files[("C", "ZZ")])) == {"node/4"}
    # cctok is single-country per file (BE file never carries |NL|)
    be = _features(files[("C", "BE")])["node/1"]["properties"]
    assert be["cctok"] == "|BE|"
    zz = _features(files[("C", "ZZ")])["node/4"]["properties"]
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
        ("C", "BE"): _geojsonl(tmp_path / "c_be.geojsonl", [
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
    tiles.verify_pmtiles(built, expected_layers={"c_be", "d_be"},
                         expected_bbox=(4.0, 50.0, 6.0, 51.5))  # must not raise


def test_verify_pmtiles_missing_layer_raises(built):
    with pytest.raises(RuntimeError, match="missing layer"):
        tiles.verify_pmtiles(built, expected_layers={"c_be", "d_be", "e_be"})


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
        tiles.verify_pmtiles(built, expected_layers={"c_be", "d_be"},
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
        tiles.verify_pmtiles(built, expected_layers={"c_be", "d_be"})


def test_verify_pmtiles_all_sample_tiles_empty_raises(built, monkeypatch):
    # A real archive whose header bbox is populated but whose min-zoom sample
    # area decodes entirely empty cannot be built (tippecanoe derives the
    # header bounds from the features themselves); stub the tile fetch to
    # test the decode-gate raise semantics.
    monkeypatch.setattr(tiles, "_tile_bytes", lambda *a: b"")
    with pytest.raises(RuntimeError, match="no decodable non-empty tile"):
        tiles.verify_pmtiles(built, expected_layers={"c_be", "d_be"})


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


# ---- Low-zoom clustering (region-scoping-design.md §7) ----
# The rail shows a coverage layer's full in-scope count ("D 2015/2015"), and the
# TILES must represent that same set at overview zooms — as point_count clusters,
# not tippecanoe's default point-thinning (which dropped ~99%, leaving the map
# empty under a 2015 label). These tests pin the flags AND the emergent
# behaviour: sum(point_count) == every input point, and the maxzoom cap.

def _tile_xy(lon: float, lat: float, z: int) -> tuple[int, int]:
    n = 2 ** z
    x = min(n - 1, max(0, int((lon + 180.0) / 360.0 * n)))
    return x, tiles._tile_y(lat, n)


def _decode_layer(path, z: int, x: int, y: int, layer: str) -> list:
    """tippecanoe-decode one tile → the features of one named vector layer.

    Output shape (tippecanoe-decode): a top FeatureCollection whose `features`
    are per-layer FeatureCollections, each tagged `properties.layer`."""
    doc = json.loads(tiles._run(
        ["tippecanoe-decode", str(path), str(z), str(x), str(y)], text=True))
    for lyr in doc.get("features", []):
        if lyr.get("properties", {}).get("layer") == layer:
            return lyr.get("features", [])
    return []


def test_build_command_carries_clustering_flags(tmp_path, monkeypatch):
    # The overview-clustering flags are load-bearing: -r1 keeps every point,
    # cluster-distance/maxzoom group at low zoom only, cluster-densest fits tile
    # size by MERGING not dropping. Pin them so a future edit can't silently fall
    # back to point-thinning (--drop-densest-as-needed), which is exactly the
    # regression this round fixed.
    captured = {}
    monkeypatch.setattr(tiles.subprocess, "run",
                        lambda cmd, check=True: captured.setdefault("cmd", cmd))
    tiles.build_pmtiles({("D", "BE"): tmp_path / "d.geojsonl"}, tmp_path / "o.pmtiles")
    cmd = captured["cmd"]
    assert "-r1" in cmd, "must keep every point (no rate-based dropping)"
    assert "--cluster-densest-as-needed" in cmd
    assert "--drop-densest-as-needed" not in cmd, "dropping must NOT return"
    for flag, val in [("--cluster-distance", "20"), ("--cluster-maxzoom", "11"),
                      ("--minimum-zoom", "6"), ("--maximum-zoom", "14")]:
        assert flag in cmd and cmd[cmd.index(flag) + 1] == val, f"{flag} {val}"
    # The scope tokens MUST be unioned across a cluster's members (finding 2):
    # without these a bubble would inherit one member's region, so a region scope
    # admits/hides the whole bubble on a lottery.
    assert "ridtok:concat" in cmd, "cluster bubbles must union member region tokens"
    assert "cctok:concat" in cmd, "cluster bubbles must union member country tokens"


def test_clustering_represents_every_point_within_one_tile_at_low_zoom(tmp_path):
    # 60 D-services packed into a ~0.02° box near Brussels, ALL inside one z6
    # tile. At z6 (below the cluster-maxzoom cap) tippecanoe MUST cluster them
    # into point_count features whose counts sum to EXACTLY 60 — proving nothing
    # is dropped (the -r1 + cluster-densest promise).
    #
    # SCOPE OF THE INVARIANT: conservation is PER TILE, not global. Tippecanoe's
    # default 5/256 feature buffer duplicates points within the seam strip into
    # both adjacent tiles' clusters, so summing point_count across tiles that
    # straddle a seam OVERSHOOTS the input (finding 3). The box here is chosen to
    # sit wholly inside a single tile so no seam duplication is in play; the rail
    # /map/coverage/counts (exact SQL, buffer-free) — not a cross-tile bubble sum
    # — is the authoritative total the client shows.
    n = 60
    rows = [(4.34 + (i % 10) * 0.002, 50.84 + (i // 10) * 0.002,
             {"ref": f"node/{i}", "t": "Bike shop", "kind": "shop"})
            for i in range(n)]
    out = tmp_path / "cl.pmtiles"
    tiles.build_pmtiles({("D", "BE"): _geojsonl(tmp_path / "d.geojsonl", rows)}, out)

    x, y = _tile_xy(4.35, 50.85, 6)
    feats = _decode_layer(out, 6, x, y, "d_be")
    assert feats, "z6 tile must contain the packed points"
    # unclustered feature = 1 point; clustered = point_count members.
    total = sum(f["properties"].get("point_count", 1) for f in feats)
    assert total == n, f"clustering must represent all {n} points at z6, got {total}"
    assert any("point_count" in f["properties"] for f in feats), \
        "such a dense box must yield at least one point_count cluster at z6"


def test_cluster_unions_member_region_tokens(tmp_path):
    # Finding 2: a bubble must carry the UNION of its members' region tokens, so
    # `'|<id>|' in ridtok` answers "does any member fall in this region?" instead
    # of trusting one lottery-chosen representative. Pack 40 points alternating
    # region 1 / 23 (all cc BE) into one z6 tile; the cluster's ridtok must
    # contain BOTH tokens and its cctok the country token.
    rows = []
    for i in range(40):
        rid = 1 if i % 2 == 0 else 23
        rows.append((4.34 + (i % 10) * 0.002, 50.84 + (i // 10) * 0.002,
                     {"ref": f"node/{i}", "t": "Bike shop",
                      "ridtok": f"|{rid}|", "cctok": "|BE|"}))
    out = tmp_path / "u.pmtiles"
    tiles.build_pmtiles({("D", "BE"): _geojsonl(tmp_path / "d.geojsonl", rows)}, out)

    x, y = _tile_xy(4.35, 50.85, 6)
    clusters = [f for f in _decode_layer(out, 6, x, y, "d_be")
                if "point_count" in f["properties"]]
    assert clusters, "dense box must cluster at z6"
    unioned = next(f for f in clusters if f["properties"]["point_count"] > 1)
    ridtok = unioned["properties"]["ridtok"]
    assert "|1|" in ridtok and "|23|" in ridtok, \
        f"bubble must union both member regions, got {ridtok!r}"
    assert "|BE|" in unioned["properties"]["cctok"]
    # No false positive: a region not present must NOT match.
    assert "|99|" not in ridtok


def test_cluster_tokens_survive_rid_less_members(tmp_path):
    # concat aborts tippecanoe on a MISSING attribute; _universal_props emits ''
    # (not NULL) for unstamped rows so a cluster mixing stamped + rid-less members
    # (the 206-boundary-row shape) still builds, and the empty tokens contribute
    # nothing to the union (finding 5 + the concat-abort guard).
    rows = []
    for i in range(30):
        stamped = i % 3 != 0
        rows.append((4.34 + (i % 6) * 0.002, 50.84 + (i // 6) * 0.002,
                     {"ref": f"node/{i}", "t": "Bike shop",
                      "ridtok": ("|7|" if stamped else ""), "cctok": "|BE|"}))
    out = tmp_path / "m.pmtiles"
    # Builds without tippecanoe's "can't happen" concat abort.
    tiles.build_pmtiles({("D", "BE"): _geojsonl(tmp_path / "d.geojsonl", rows)}, out)
    x, y = _tile_xy(4.35, 50.85, 6)
    clusters = [f for f in _decode_layer(out, 6, x, y, "d_be")
                if "point_count" in f["properties"]]
    assert clusters, "dense box must cluster at z6"
    ridtok = clusters[0]["properties"]["ridtok"]
    assert "|7|" in ridtok            # stamped members present
    assert "||" not in ridtok or ridtok.count("|") % 2 == 0  # empties add no stray delimiter


def test_z11_is_still_clustered_the_cap_boundary(tmp_path):
    # The cluster-maxzoom=11 boundary is inclusive: z11 still clusters, z12 does
    # not (finding 19 — the tests pinned z6 and z12 but never the z11 edge the
    # flag literal alone guards). 60 packed points, same box as the z6 test.
    n = 60
    rows = [(4.34 + (i % 10) * 0.0002, 50.84 + (i // 10) * 0.0002,
             {"ref": f"node/{i}", "t": "Bike shop"}) for i in range(n)]
    out = tmp_path / "z11.pmtiles"
    tiles.build_pmtiles({("D", "BE"): _geojsonl(tmp_path / "d.geojsonl", rows)}, out)
    x, y = _tile_xy(4.34, 50.84, 11)
    feats = _decode_layer(out, 11, x, y, "d_be")
    assert feats, "z11 tile must contain the packed points"
    assert any("point_count" in f["properties"] for f in feats), \
        "z11 is at (not above) --cluster-maxzoom=11 → must still cluster"


def test_cluster_maxzoom_leaves_high_zoom_individual(tmp_path):
    # Above --cluster-maxzoom=11, features render individually (no point_count),
    # so a rider zoomed into a town sees the actual POIs, not a bubble. Few,
    # tightly-grouped points so the z12 tile is nowhere near the size limit
    # (cluster-densest never fires) and the ONLY reason to cluster would be the
    # distance rule — which the maxzoom cap disables at z12.
    rows = [(4.350 + (i % 5) * 0.001, 50.850 + (i // 5) * 0.001,
             {"ref": f"node/{i}", "t": "Bike shop", "kind": "shop"})
            for i in range(15)]
    out = tmp_path / "hz.pmtiles"
    tiles.build_pmtiles({("D", "BE"): _geojsonl(tmp_path / "d.geojsonl", rows)}, out)

    x, y = _tile_xy(4.351, 50.851, 12)
    feats = _decode_layer(out, 12, x, y, "d_be")
    assert feats, "z12 tile must contain the grouped points"
    assert all("point_count" not in f["properties"] for f in feats), \
        "z12 is above --cluster-maxzoom=11 → every feature must be individual"


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
