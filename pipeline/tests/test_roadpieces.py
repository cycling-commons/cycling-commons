# SPDX-License-Identifier: AGPL-3.0-only
"""Road-piece tiles for traffic matching (docs/specs/traffic-measurements.md §2).

A piece is one OSM way a bike may ride, labelled by where the rider is
relative to the cars: a separate cycle path, a painted lane, or the road."""

from __future__ import annotations

import json
import pathlib
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))

from coverage.roadpieces import advisory_lane, feature_json, label_way, stream_roadpieces  # noqa: E402


def test_ways_a_bike_may_not_ride_are_left_out():
    assert label_way({"highway": "motorway"}) is None
    assert label_way({"highway": "motorway_link"}) is None
    assert label_way({"highway": "primary", "bicycle": "use_sidepath"}) is None
    assert label_way({"highway": "tertiary", "bicycle": "no"}) is None
    assert label_way({"highway": "footway"}) is None
    assert label_way({"highway": "steps"}) is None
    assert label_way({"highway": "pedestrian", "area": "yes", "bicycle": "yes"}) is None
    assert label_way({"building": "yes"}) is None


def test_separate_cycle_paths_are_paths():
    assert label_way({"highway": "cycleway"}) == ("p", False)
    assert label_way({"highway": "path"}) == ("p", False)
    assert label_way({"highway": "footway", "bicycle": "designated"}) == ("p", False)
    assert label_way({"highway": "residential", "motor_vehicle": "no"}) == ("p", False)


def test_a_track_drawn_on_the_road_counts_as_a_path():
    assert label_way({"highway": "tertiary", "cycleway:right": "track"}) == ("p", False)
    assert label_way({"highway": "secondary", "cycleway": "track"}) == ("p", False)


def test_painted_lanes_and_shared_roads():
    assert label_way({"highway": "secondary", "cycleway": "lane"}) == ("l", False)
    assert label_way({"highway": "residential", "cycleway:left": "opposite_lane"}) == ("l", False)
    assert label_way({"highway": "unclassified"}) == ("r", False)
    assert label_way({"highway": "track"}) == ("r", False)


def test_a_road_with_a_separately_drawn_path_is_flagged():
    assert label_way({"highway": "secondary", "cycleway:both": "separate"}) == ("r", True)
    assert label_way({"highway": "secondary", "cycleway:left": "lane", "cycleway:right": "separate"}) == ("l", True)


def test_an_advisory_lane_is_told_apart():
    # The suggestiestrook: a dashed line on red asphalt, cars may drive on it.
    assert advisory_lane({"highway": "tertiary", "cycleway": "lane", "cycleway:lane": "advisory"})
    assert advisory_lane({"highway": "tertiary", "cycleway:both": "lane", "cycleway:both:lane": "advisory"})
    assert not advisory_lane({"highway": "tertiary", "cycleway": "lane", "cycleway:lane": "exclusive"})
    assert not advisory_lane({"highway": "tertiary", "cycleway": "lane"}), "a lane that does not say is no advisory lane"
    assert not advisory_lane({"highway": "tertiary", "cycleway:left:lane": "advisory", "cycleway:right:lane": "exclusive"})


MINI = """<?xml version='1.0' encoding='UTF-8'?>
<osm version="0.6">
  <node id="1" lat="52.0000" lon="5.0000"/>
  <node id="2" lat="52.0010" lon="5.0000"/>
  <node id="3" lat="52.0020" lon="5.0000"/>
  <node id="4" lat="52.0000" lon="5.0001"/>
  <node id="5" lat="52.0010" lon="5.0001"/>
  <way id="100"><nd ref="1"/><nd ref="2"/><nd ref="3"/>
    <tag k="highway" v="secondary"/><tag k="cycleway:right" v="separate"/></way>
  <way id="200"><nd ref="4"/><nd ref="5"/><tag k="highway" v="cycleway"/></way>
  <way id="300"><nd ref="1"/><nd ref="4"/><tag k="highway" v="motorway"/></way>
  <way id="400"><nd ref="2"/><nd ref="5"/><tag k="highway" v="tertiary"/>
    <tag k="cycleway" v="lane"/><tag k="cycleway:lane" v="advisory"/></way>
</osm>
"""


def test_the_stream_emits_labelled_pieces_with_their_way_id(tmp_path):
    osm = tmp_path / "mini.osm"
    osm.write_text(MINI, encoding="utf-8")
    got = []
    stream_roadpieces(osm, got.append)

    by_id = {p.way_id: p for p in got}
    assert sorted(by_id) == [100, 200, 400]
    assert by_id[100].label == "r" and by_id[100].sidepath
    assert by_id[200].label == "p" and not by_id[200].sidepath
    assert by_id[100].coords[0] == (5.0, 52.0)


def test_a_feature_carries_the_way_id_and_only_set_flags(tmp_path):
    osm = tmp_path / "mini.osm"
    osm.write_text(MINI, encoding="utf-8")
    got = []
    stream_roadpieces(osm, got.append)
    feats = {p.way_id: json.loads(feature_json(p)) for p in got}

    assert feats[100]["id"] == 100
    assert feats[100]["properties"] == {"l": "r", "h": "secondary", "s": 1}
    assert feats[200]["properties"] == {"l": "p", "h": "cycleway"}
    assert feats[200]["geometry"]["type"] == "LineString"
    assert feats[400]["properties"] == {"l": "l", "h": "tertiary", "a": 1}, "an advisory lane stays a lane"


def test_the_build_command_keeps_every_piece_at_z14_with_its_id(tmp_path, monkeypatch):
    # The browser matches against full geometry at z14: no dropped line, no
    # simplified one at z14, and the way id as the feature id. The Road safety
    # overlay draws them zoomed out (owner 2026-10-26: "Zoom a bit out and the
    # colors are gone"), so z12 and z13 hold every way too, simplified.
    from coverage import tiles
    captured = {}
    monkeypatch.setattr(tiles, "_run", lambda cmd: captured.setdefault("cmd", cmd))
    tiles.build_roadpieces_pmtiles({"NL": [tmp_path / "a.geojsonl", tmp_path / "b.geojsonl"]},
                                   tmp_path / "out.pmtiles")
    cmd = captured["cmd"]
    assert cmd[cmd.index("--minimum-zoom") + 1] == "12"
    assert cmd[cmd.index("--maximum-zoom") + 1] == "14"
    for flag in ("--no-feature-limit", "--no-tile-size-limit", "--simplify-only-low-zooms",
                 "--no-simplification-of-shared-nodes"):
        assert flag in cmd
    assert "--drop-densest-as-needed" not in cmd
    assert "--no-line-simplification" not in cmd, "the lower zooms are simplified"
    assert tiles.TILE_PROFILE["roadpieces"] == "2", "a flag change rebuilds every country"
    layers = [cmd[i + 1] for i, a in enumerate(cmd) if a == "-L"]
    assert [l.split(":")[0] for l in layers] == ["roadpieces_nl", "roadpieces_nl"]


def test_a_real_build_holds_the_pieces_from_z12_to_z14(tmp_path):
    import shutil
    import subprocess
    if shutil.which("tippecanoe") is None:
        import pytest
        pytest.skip("tippecanoe is only in the pipeline image")
    from coverage import tiles
    from coverage.roadpieces import extract_region
    osm = tmp_path / "mini.osm"
    osm.write_text(MINI, encoding="utf-8")
    lines = tmp_path / "nl.geojsonl"
    assert extract_region(osm, lines) == 3
    out = tmp_path / "roadpieces-nl.pmtiles"
    tiles.build_roadpieces_pmtiles({"NL": [lines]}, out)
    show = subprocess.run(["pmtiles", "show", str(out)], capture_output=True, text=True, check=True).stdout
    assert "min zoom: 12" in show and "max zoom: 14" in show
    assert "roadpieces_nl" in show


def test_a_cycle_street_is_bike_only():
    # A fietsstraat: cars are guests, the street is laid out for bikes.
    assert label_way({"highway": "residential", "cyclestreet": "yes"}) == ("p", False)
    assert label_way({"highway": "unclassified", "bicycle_road": "yes"}) == ("p", False)


def test_a_piece_carries_its_region_when_one_is_known():
    from coverage.roadpieces import RoadPiece
    piece = RoadPiece(way_id=7, label="r", highway="residential", sidepath=False, coords=[(5.0, 52.0), (5.1, 52.0)])
    assert json.loads(feature_json(piece, region=12))["properties"]["g"] == 12
    assert "g" not in json.loads(feature_json(piece))["properties"]
