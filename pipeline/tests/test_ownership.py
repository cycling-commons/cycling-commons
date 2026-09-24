# SPDX-License-Identifier: AGPL-3.0-only
"""One owner per line feature at a border: the point rule, restated for lines."""

from __future__ import annotations

import json

import shapely

from coverage.ownership import (Owners, anchor_point, outlines_fingerprint, pbf_header_box,
                                region_fingerprint, snapshot_outlines)


def _row(id_, cc, area, box):
    return {"id": id_, "cc": cc, "area": area, "wkb": shapely.to_wkb(shapely.box(*box), hex=True)}


# BE and NL share the edge x=1. LU sits inside BE and is smaller.
ROWS = [
    _row(1, "BE", 10.0, (0.0, 0.0, 1.0, 1.0)),
    _row(2, "NL", 10.0, (1.0, 0.0, 2.0, 1.0)),
    _row(3, "LU", 1.0, (0.2, 0.2, 0.4, 0.4)),
]


def test_anchor_is_the_middle_vertex():
    assert anchor_point([(0, 0), (1, 1), (2, 2)]) == (1, 1)
    assert anchor_point([(0, 0), (1, 1)]) == (1, 1)


def test_inside_one_region():
    assert Owners(ROWS).owner(0.7, 0.5) == "BE"
    assert Owners(ROWS).owner(1.5, 0.5) == "NL"


def test_overlap_goes_to_the_smaller_region():
    assert Owners(ROWS).owner(0.3, 0.3) == "LU"


def test_shared_edge_ties_on_area_then_id():
    # Distance 0 to both, equal area, so the lower id wins.
    assert Owners(ROWS).owner(1.0, 0.5) == "BE"


def test_snap_band_and_foreign():
    owners = Owners(ROWS)
    assert owners.owner(2.005, 0.5) == "NL"     # 0.005 degrees outside NL: inside the snap band
    assert owners.owner(2.5, 0.5) is None       # far outside every region: foreign, dropped


def test_row_order_does_not_change_the_answer():
    assert Owners(list(reversed(ROWS))).owner(1.0, 0.5) == "BE"


def test_keeper_keeps_only_own_features():
    keep = Owners(ROWS).keeper("NL")
    assert keep([(1.2, 0.5), (1.5, 0.5), (1.8, 0.5)]) is True
    assert keep([(0.2, 0.5), (0.5, 0.5), (0.8, 0.5)]) is False
    assert Owners(ROWS).keeper(None) is None     # dev/ regions: no filter


def test_snapshot_reads_operational_regions_and_is_stable(db, tmp_path):
    db.execute("INSERT INTO region VALUES "
               "(1, 10, 'BE', 4, ST_Multi(ST_MakeEnvelope(0,0,1,1,4326))),"
               "(2, 99, 'BE', 2, ST_Multi(ST_MakeEnvelope(0,0,1,1,4326))),"   # outline: not operational
               "(3, 10, 'NL', 4, ST_Multi(ST_MakeEnvelope(1,0,2,1,4326)))")
    path = tmp_path / "ownership-regions.json"
    fp = snapshot_outlines(db, path)
    rows = json.loads(path.read_text())
    assert [(r["id"], r["cc"]) for r in rows] == [(1, "BE"), (3, "NL")]
    mtime = path.stat().st_mtime_ns
    assert snapshot_outlines(db, path) == fp == outlines_fingerprint(path)
    assert path.stat().st_mtime_ns == mtime   # unchanged content is not rewritten


def _outlines(tmp_path, rows):
    path = tmp_path / "ownership-regions.json"
    path.write_text(json.dumps(rows, separators=(",", ":"), sort_keys=True))
    return path


# A region extract over (0.2..0.8, 0.2..0.8): BE's outline meets it, NL's does
# not, and JP is on the other side of the world.
REGION_BOX = (0.2, 0.2, 0.8, 0.8)
FAR = _row(7, "JP", 5.0, (139.0, 35.0, 140.0, 36.0))


def test_a_change_to_a_far_outline_leaves_the_region_fingerprint_alone(tmp_path):
    before = region_fingerprint(_outlines(tmp_path, [ROWS[0], FAR]), REGION_BOX)
    moved = _row(7, "JP", 5.0, (139.5, 35.0, 140.5, 36.0))
    assert region_fingerprint(_outlines(tmp_path, [ROWS[0], moved]), REGION_BOX) == before


def test_a_change_to_a_neighbouring_outline_changes_the_region_fingerprint(tmp_path):
    # NL's edge moves to within the snap distance of the region's box: it can
    # now own an anchor there, so the region's extract must be redone.
    far_nl = _row(2, "NL", 10.0, (1.0, 0.0, 2.0, 1.0))
    near_nl = _row(2, "NL", 10.0, (0.805, 0.0, 2.0, 1.0))
    before = region_fingerprint(_outlines(tmp_path, [ROWS[0], far_nl]), REGION_BOX)
    assert region_fingerprint(_outlines(tmp_path, [ROWS[0], near_nl]), REGION_BOX) != before


def test_no_header_box_falls_back_to_the_global_fingerprint(tmp_path):
    path = _outlines(tmp_path, [ROWS[0], FAR])
    assert region_fingerprint(path, None) == outlines_fingerprint(path)
    assert region_fingerprint(tmp_path / "absent.json", REGION_BOX) == ""


def test_pbf_header_box_reads_the_header_or_says_none(tmp_path):
    import pathlib

    import osmium

    header = osmium.io.Header()
    header.add_box(osmium.osm.Box(osmium.osm.Location(4.0, 50.0), osmium.osm.Location(6.0, 52.0)))
    boxed = tmp_path / "boxed.osm.pbf"
    writer = osmium.SimpleWriter(str(boxed), 4096, header)
    writer.add_node(osmium.osm.mutable.Node(id=1, location=(5.0, 51.0)))
    writer.close()
    assert pbf_header_box(boxed) == (4.0, 50.0, 6.0, 52.0)
    # The fixture PBF carries no header box; a file that is no PBF has none either.
    assert pbf_header_box(pathlib.Path(__file__).parent / "fixtures" / "mini.osm.pbf") is None
    junk = tmp_path / "junk.osm.pbf"
    junk.write_bytes(b"pbf")
    assert pbf_header_box(junk) is None
    assert pbf_header_box(tmp_path / "absent.osm.pbf") is None
