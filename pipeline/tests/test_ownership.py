# SPDX-License-Identifier: AGPL-3.0-only
"""One owner per line feature at a border: the point rule, restated for lines."""

from __future__ import annotations

import json

import shapely

from coverage.ownership import Owners, anchor_point, outlines_fingerprint, snapshot_outlines


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
