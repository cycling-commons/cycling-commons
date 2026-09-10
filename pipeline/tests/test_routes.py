# SPDX-License-Identifier: AGPL-3.0-only
"""Cycle-route network layer (docs/plans/handoffs/2026-08-12-routes-layer-and-
surface-quality.md). The acceptance case throughout is the Zuiderdijk: a way on
a national LF route AND two rcn node-network segments, with no surface tag —
the extractor has to see all three memberships, pick the strongest for the
line's colour, and hand the way id to the surface pass as homework."""

from __future__ import annotations

import json
import pathlib
import sys

import pytest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))

from coverage.contract import load_contract  # noqa: E402
from coverage.routes import (Membership, RouteNode, RouteWay,  # noqa: E402
                             extract_region, feature_json_node, feature_json_way,
                             load_way_ids, membership_from_tags, selector_expressions)


@pytest.fixture(scope="module")
def contract():
    return load_contract()


# ── Relation classification ─────────────────────────────────────────────────

def test_a_bicycle_route_takes_its_network(contract):
    m = membership_from_tags({"route": "bicycle", "network": "ncn",
                              "ref": "LF-ZZ", "name": "LF Zuiderzeeroute"})
    assert m == Membership(net="ncn", ref="LF-ZZ", name="LF Zuiderzeeroute", rk="route")
    assert m.net in contract.routes["networks"]


def test_an_mtb_route_is_mtb_whatever_its_network_says():
    # The layer splits by riding kind first: a signed MTB loop tagged with a
    # road-network value would otherwise vanish into the road corridors.
    assert membership_from_tags({"route": "mtb", "network": "rcn"}).net == "mtb"
    assert membership_from_tags({"route": "mtb"}).net == "mtb"


def test_an_unrecognised_network_is_other_not_invented():
    # Still a signed route somebody laid out — the honest bucket, not a guessed
    # rank and not a dropped relation.
    assert membership_from_tags({"route": "bicycle", "network": "regional"}).net == "other"
    assert membership_from_tags({"route": "bicycle"}).net == "other"


def test_non_cycle_routes_are_not_ours():
    for route in ("hiking", "bus", "foot", None):
        tags = {"route": route} if route else {}
        assert membership_from_tags(tags) is None, f"route={route} must not be selected"


def test_node_network_segments_are_recognised_both_ways():
    # The mandated tag, and the NN-NN ref shape for segments mapped before the
    # tag existed — the Zuiderdijk's rcn segments (09-80, 62-80) are the case.
    by_tag = membership_from_tags({"route": "bicycle", "network": "rcn",
                                   "network:type": "node_network", "ref": "09-80"})
    assert by_tag.rk == "node"
    by_shape = membership_from_tags({"route": "bicycle", "network": "rcn", "ref": "62-80"})
    assert by_shape.rk == "node"
    named_route = membership_from_tags({"route": "bicycle", "network": "ncn", "ref": "LF-ZZ"})
    assert named_route.rk == "route"


# ── Feature shape ────────────────────────────────────────────────────────────

def _zuiderdijk_way():
    lf = Membership("ncn", "LF-ZZ", "LF Zuiderzeeroute", "route")
    rcn_a = Membership("rcn", "09-80", "", "node")
    rcn_b = Membership("rcn", "62-80", "", "node")
    return RouteWay("way/41", lf, (rcn_a, rcn_b), [(5.1234567, 52.62), (5.13, 52.63)])


def test_a_way_feature_carries_the_tile_contract(contract):
    feature = json.loads(feature_json_way(_zuiderdijk_way(), load_contract(), ridtok="|7|", cctok="|NL|"))
    props = feature["properties"]
    # The strongest membership colours the line; every membership reaches the
    # drawer via refs. `ref` is the ELEMENT ref — same key, same meaning as the
    # surface arms, because the click hands it to /improve — and the route's
    # code travels as `rr`.
    assert props["ref"] == "way/41"
    assert props["net"] == "ncn"
    assert props["rr"] == "LF-ZZ"
    assert props["rk"] == "route"
    assert props["refs"] == "ncn LF-ZZ|rcn 09-80|rcn 62-80"
    assert props["name"] == "LF Zuiderzeeroute"
    assert props["ridtok"] == "|7|" and props["cctok"] == "|NL|"
    assert set(contract.routes["tileProps"]) <= {"net", "rr", "rk", "refs", "ref"}
    # 6 dp rounding, same rationale as the surface arms.
    assert feature["geometry"]["coordinates"][0] == [5.123457, 52.62]


def test_a_single_route_way_omits_refs():
    # Most ways carry one route; repeating it as refs would be dead weight in
    # every tile a rider downloads.
    way = RouteWay("way/42", Membership("rcn", "09-80", "", "node"), (), [(5.1, 52.6), (5.2, 52.7)])
    props = json.loads(feature_json_way(way, load_contract()))["properties"]
    assert "refs" not in props and "name" not in props
    assert props["ref"] == "way/42"
    assert props["rk"] == "node"


def test_planning_routes_reach_the_low_zooms_and_local_ones_do_not(contract):
    """Two floors on one archive (contract routes `_zoomComment`).

    An international route is what a rider plans with at the zoom where a
    country fits on the screen. Below zoom 8 the whole layer used to be absent,
    which reads as broken rather than as out of range (owner 2026-08-17). The
    low floor is for the planning networks ONLY: shipping millions of local
    connectors from z5 would put an unreadable smear in a handful of tiles.
    """
    planning = contract.routes["planningMinZoom"]
    local = contract.routes["localMinZoom"]
    assert planning < local, "two floors, or there is nothing to stamp"

    def floor_of(net):
        way = RouteWay("way/1", Membership(net, "X", "", "route"), (), [(5.1, 52.6), (5.2, 52.7)])
        return json.loads(feature_json_way(way, contract))["tippecanoe"]["minzoom"]

    for net in contract.routes["planningNetworks"]:
        assert floor_of(net) == planning, f"{net} is a planning network"
    for net in set(contract.routes["networks"]) - set(contract.routes["planningNetworks"]):
        assert floor_of(net) == local, f"{net} must stay at the local floor"


def test_the_archive_reaches_as_low_as_its_lowest_feature(contract):
    """A per-feature floor below the ARCHIVE floor is a feature in no tile.

    tippecanoe builds `routes.minZoom`..`maxZoom`; a way stamped z5 inside an
    archive that starts at z8 is simply never written, and the symptom is the
    exact bug this replaced - an empty map at planning zoom.
    """
    routes = contract.routes
    assert routes["minZoom"] <= routes["planningMinZoom"]
    assert routes["minZoom"] <= routes["localMinZoom"]
    assert routes["nodes"]["minZoom"] <= routes["maxZoom"]


def test_a_way_carrying_a_planning_route_keeps_the_low_floor(contract):
    """Judged on the BEST membership, which is the one it draws in.

    A lane carrying both EuroVelo 12 and a village loop is part of EuroVelo 12
    at planning zoom. Dropping it because of its weaker membership would break
    the very line the low floor exists to show.
    """
    way = RouteWay("way/41",
                   Membership("icn", "EV12", "North Sea Cycle Route", "route"),
                   (Membership("lcn", "DuiZee", "", "route"),),
                   [(5.1, 52.6), (5.2, 52.7)])
    assert json.loads(feature_json_way(way, contract))["tippecanoe"]["minzoom"] \
        == contract.routes["planningMinZoom"]


def test_a_node_feature_carries_its_number_and_its_floor(contract):
    feature = json.loads(feature_json_node(
        RouteNode("node/9", "rcn", "9", 5.1234567, 52.6), contract, cctok="|NL|"))
    assert feature["properties"] == {"net": "rcn", "nr": "9", "ref": "node/9",
                                     "ridtok": "", "cctok": "|NL|"}
    # The per-feature floor is what keeps a country's numbers out of the
    # planning-zoom tiles without a second artifact.
    assert feature["tippecanoe"] == {"minzoom": contract.routes["nodes"]["minZoom"]}
    assert feature["geometry"] == {"type": "Point", "coordinates": [5.123457, 52.6]}


def test_the_selector_asks_for_relations_and_knooppunt_nodes():
    exprs = selector_expressions()
    assert "r/route=bicycle,mtb" in exprs
    # Knooppunt nodes are not referenced by the route relations that pass the
    # first expression — the node NETWORK relations are a different type — so
    # they need their own selectors or the badges layer is silently empty.
    assert "n/rcn_ref" in exprs and "n/lcn_ref" in exprs


# ── End to end over a real PBF ───────────────────────────────────────────────
# The two-pass structure (relations before ways, locations on the second walk)
# is exactly what the monkeypatch-style tests above cannot exercise, and it is
# where a silent ordering mistake would empty the layer.

def _write_fixture_pbf(path: pathlib.Path) -> None:
    import osmium
    from osmium.osm import mutable

    writer = osmium.SimpleWriter(str(path))
    try:
        # A knooppunt and the dijk's shared corner nodes.
        writer.add_node(mutable.Node(id=1, location=(5.10, 52.62)))
        writer.add_node(mutable.Node(id=2, location=(5.12, 52.63)))
        writer.add_node(mutable.Node(id=3, location=(5.14, 52.64)))
        writer.add_node(mutable.Node(id=9, location=(5.12, 52.63),
                                     tags={"rcn_ref": "9"}))
        # The Zuiderdijk stand-ins: tertiary, no surface tag.
        writer.add_way(mutable.Way(id=101, nodes=[1, 2], tags={"highway": "tertiary"}))
        writer.add_way(mutable.Way(id=102, nodes=[2, 3], tags={"highway": "tertiary"}))
        # An mtb loop way, and a way on no route at all.
        writer.add_way(mutable.Way(id=103, nodes=[3, 1], tags={"highway": "track"}))
        writer.add_way(mutable.Way(id=104, nodes=[1, 3], tags={"highway": "tertiary"}))
        writer.add_relation(mutable.Relation(
            id=201, members=[("w", 101, ""), ("w", 102, "")],
            tags={"type": "route", "route": "bicycle", "network": "ncn",
                  "ref": "LF-ZZ", "name": "LF Zuiderzeeroute"}))
        writer.add_relation(mutable.Relation(
            # The way listed twice (forward/backward roles) must stay ONE
            # membership, or the drawer reads a duplicate route.
            id=202, members=[("w", 101, "forward"), ("w", 101, "backward")],
            tags={"type": "route", "route": "bicycle", "network": "rcn",
                  "network:type": "node_network", "ref": "09-80"}))
        writer.add_relation(mutable.Relation(
            id=203, members=[("w", 101, "")],
            tags={"type": "route", "route": "bicycle", "network": "rcn",
                  "network:type": "node_network", "ref": "62-80"}))
        writer.add_relation(mutable.Relation(
            id=204, members=[("w", 103, "")],
            tags={"type": "route", "route": "mtb", "ref": "M1"}))
        # A hiking route that must select nothing.
        writer.add_relation(mutable.Relation(
            id=205, members=[("w", 104, "")],
            tags={"type": "route", "route": "hiking", "ref": "GR12"}))
    finally:
        writer.close()


def test_extract_region_end_to_end(tmp_path, contract):
    pbf = tmp_path / "zuiderdijk.osm.pbf"
    _write_fixture_pbf(pbf)
    ways_out = tmp_path / "ways.geojsonl"
    nodes_out = tmp_path / "nodes.geojsonl"
    wayids_out = tmp_path / "wayids.txt"

    counts = extract_region(pbf, contract, ways_out=ways_out, nodes_out=nodes_out,
                            wayids_out=wayids_out, cctok="|NL|")

    ways = {json.loads(l)["properties"]["ref"]: json.loads(l)
            for l in ways_out.read_text().splitlines()}
    assert counts.ways == len(ways) == 3
    assert set(ways) == {"way/101", "way/102", "way/103"}, \
        "member ways only — way/104 carries no cycle route"

    # The acceptance way: national colour, all three memberships, once each.
    w101 = ways["way/101"]["properties"]
    assert w101["net"] == "ncn" and w101["rr"] == "LF-ZZ"
    assert w101["refs"] == "ncn LF-ZZ|rcn 09-80|rcn 62-80"
    assert w101["cctok"] == "|NL|"
    assert ways["way/101"]["geometry"]["type"] == "LineString"

    # A single-route way stays thin; the mtb way is net mtb.
    assert "refs" not in ways["way/102"]["properties"]
    assert ways["way/103"]["properties"]["net"] == "mtb"

    # The knooppunt came out as a point with its number and its zoom floor.
    nodes = [json.loads(l) for l in nodes_out.read_text().splitlines()]
    assert counts.nodes == len(nodes) == 1
    assert nodes[0]["properties"]["nr"] == "9"
    assert nodes[0]["properties"]["net"] == "rcn"
    assert nodes[0]["tippecanoe"]["minzoom"] == contract.routes["nodes"]["minZoom"]

    # The way-id set the surface pass eats: every member way, sorted, and
    # nothing else.
    assert wayids_out.read_text().split() == ["101", "102", "103"]
    assert load_way_ids(wayids_out) == frozenset({101, 102, 103})


def test_load_way_ids_missing_file_is_empty_not_fatal(tmp_path):
    # A region whose routes were never extracted still gets its surface build —
    # just without route-awareness. The caller prints the fact.
    assert load_way_ids(tmp_path / "absent.txt") == frozenset()
