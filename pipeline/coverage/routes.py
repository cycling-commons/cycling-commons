# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Cycle-route NETWORK layer for the coverage plane.

Plan: docs/plans/handoffs/2026-08-12-routes-layer-and-surface-quality.md,
decided with the owner 2026-08-12. `route=bicycle` and `route=mtb` relations
out of OSM, rendered as corridors plus knooppunt numbers — the thing that makes
a map "feel like it knows where to ride" (OpenCycleMap is the benchmark,
readable-by-default the brief; CyclOSM was rejected as unreadable at scale).

Rendering networks is a solved problem we are borrowing. What the layer is FOR
is the loop with the surface skin: a signed route with no recorded surface is
exactly the road worth asking a rider about — the Zuiderdijk (LF-ZZ plus two
rcn segments, nine ways with no surface tag) is the worked example — so this
pass also produces the **member way-id set** that makes the surface to-do arm
route-aware (surface.extract_region's `route_way_ids`).

Same idiom as surface.py — stream, never collect — with one structural
difference: a way cannot know its relations, so membership needs a pass over
the RELATIONS before the ways stream. A PBF orders nodes < ways < relations,
which is the wrong way round for us, so the extractor walks the (already
osmium-filtered, few-MB) file twice:

  pass 1 — relations only: `way id -> [memberships]`, kept in memory. This is
           the one collection the design allows itself: a membership is a few
           small strings, and even the Netherlands' node network is tens of
           thousands of entries, not gigabytes.
  pass 2 — ways with locations (one feature per member way) and nodes
           (knooppunt numbers as points), streamed straight to GeoJSONL.

Two feature kinds, ONE artifact (unlike surface's three arms): the corridors
and the numbers are one question — "where do the signed routes run?" — toggled
by one control, so splitting them would cost a second fetch for no rider
choice. The numbers stay out of planning-zoom tiles via a per-feature
tippecanoe minzoom (contract routes.nodes.minZoom) instead of a second archive.
"""

from __future__ import annotations

import json
import re
from collections import defaultdict
from dataclasses import dataclass
from pathlib import Path
from typing import Callable

import osmium

from coverage.contract import Contract

# Strongest first. A way often carries several routes (the Zuiderdijk is on a
# national LF route AND two rcn node-network segments); the FEATURE takes the
# strongest membership for its colour, and the rest ride along in `refs` for
# the drawer. mtb outranks only `other`: a signed mtb loop sharing a way with
# any signed road network should read as the road network — the mtb layer is a
# filter view, not a stronger claim.
NETWORK_RANK = {"icn": 0, "ncn": 1, "rcn": 2, "lcn": 3, "mtb": 4, "other": 5}


@dataclass(frozen=True)
class Membership:
    """One relation's claim on a way."""

    net: str    # icn|ncn|rcn|lcn|mtb|other — contract routes.networks
    ref: str    # 'LF-ZZ', '09-80', '' when the relation has none
    name: str   # relation name, '' when it has none
    rk: str     # 'node' for a node-network segment, else 'route'

    def label(self) -> str:
        """`net ref` (or the name as fallback) — the drawer's list format."""
        return f"{self.net} {self.ref or self.name}".strip()


@dataclass(frozen=True)
class RouteWay:
    """One member way, ready to be written as a GeoJSON LineString feature."""

    ref: str                       # 'way/<osm-id>' — item.source_ref format
    best: Membership               # the strongest route this way carries
    others: tuple[Membership, ...]  # every other membership, strongest first
    coords: list[tuple[float, float]]


@dataclass(frozen=True)
class RouteNode:
    """One knooppunt: a node carrying rcn_ref/lcn_ref, as a Point feature."""

    ref: str    # 'node/<osm-id>'
    net: str    # rcn|lcn — which network numbered it
    nr: str     # the number on the sign, verbatim
    lon: float
    lat: float


def membership_from_tags(tags: dict) -> Membership | None:
    """A relation's Membership, or None when it is not a signed cycle route.

    `route=mtb` is always net `mtb`, whatever its network tag says — the layer
    splits by riding kind first. `route=bicycle` takes its network value when
    it is one the contract names, else `other`: an unrecognised network is
    still a signed route somebody laid out, and inventing a rank for it would
    be worse than the honest bucket.

    A node-network segment (rk 'node') is recognised by `network:type=
    node_network`, the tag the knooppunt scheme itself mandates — with the
    `NN-NN` ref shape as fallback for the (common) segments mapped before that
    tag existed.
    """
    route = tags.get("route")
    if route == "mtb":
        net = "mtb"
    elif route == "bicycle":
        network = tags.get("network", "")
        net = network if network in ("icn", "ncn", "rcn", "lcn") else "other"
    else:
        return None
    ref = tags.get("ref", "")
    is_node_segment = (tags.get("network:type") == "node_network"
                       or bool(re.fullmatch(r"\d{1,3}-\d{1,3}", ref)))
    return Membership(net=net, ref=ref, name=tags.get("name", ""),
                      rk="node" if is_node_segment else "route")


class _RelationPass(osmium.SimpleHandler):
    """Pass 1: which ways belong to which routes.

    Member RELATIONS are ignored on purpose: LF routes are structured as a
    superroute over stage relations, and each stage carries `route=bicycle`
    itself, so following the nesting would only double-count the stages the
    flat walk already sees.
    """

    def __init__(self):
        super().__init__()
        self.by_way: dict[int, list[Membership]] = defaultdict(list)

    def relation(self, r) -> None:
        m = membership_from_tags({t.k: t.v for t in r.tags})
        if m is None:
            return
        seen: set[int] = set()
        for member in r.members:
            # One membership per (relation, way), whatever the roles say: a
            # relation lists a way once per role (forward/backward), and the
            # drawer must not read that as two routes.
            if member.type == "w" and member.ref not in seen:
                seen.add(member.ref)
                self.by_way[member.ref].append(m)


class _MemberPass(osmium.SimpleHandler):
    """Pass 2: geometry. Member ways stream out as features; knooppunt nodes too.

    Requires `locations` on the reader, exactly as surface._Collector does;
    without it every way arrives geometry-less and the layer is silently empty.
    Hands each feature to its emit callback and keeps NOTHING.
    """

    def __init__(self, by_way: dict[int, list[Membership]],
                 emit_way: Callable[[RouteWay], None],
                 emit_node: Callable[[RouteNode], None]):
        super().__init__()
        self._by_way = by_way
        self._emit_way = emit_way
        self._emit_node = emit_node

    def node(self, n) -> None:
        tags = {t.k: t.v for t in n.tags}
        # rcn before lcn when a node carries both numbers: the regional network
        # is the one riders navigate by, and one badge per node is the brief.
        for key, net in (("rcn_ref", "rcn"), ("lcn_ref", "lcn")):
            nr = tags.get(key)
            if nr:
                self._emit_node(RouteNode(f"node/{n.id}", net, nr,
                                          n.location.lon, n.location.lat))
                return

    def way(self, w) -> None:
        memberships = self._by_way.get(w.id)
        if not memberships:
            return
        try:
            coords = [(n.location.lon, n.location.lat) for n in w.nodes if n.location.valid()]
        except osmium.InvalidLocationError:
            return
        if len(coords) < 2:
            return
        ranked = sorted(memberships, key=lambda m: NETWORK_RANK.get(m.net, 99))
        # 'way/<id>' — the same ref spelling every other path writes and reads
        # (see surface._Collector for why a second spelling would be poison).
        self._emit_way(RouteWay(f"way/{w.id}", ranked[0], tuple(ranked[1:]), coords))


def feature_json_way(way: RouteWay, *, ridtok: str = "", cctok: str = "") -> str:
    """One member way as a GeoJSON Feature line for tippecanoe.

    Scope tokens ride every feature exactly as they do on the surface arms
    (map-and-search.md §4.5). `ref` is the OSM ELEMENT ref ('way/NNN') — the
    same key with the same meaning as every other layer, because the click
    hands it to /improve and the drawer builds the OSM source link from it; the
    ROUTE's code (LF-ZZ, 09-80) travels as `rr`. `refs` is emitted only when a
    way carries more than one route — most ways carry one, and repeating it
    would be dead weight in every tile."""
    props = {"net": way.best.net, "rr": way.best.ref, "rk": way.best.rk,
             "ref": way.ref, "ridtok": ridtok, "cctok": cctok}
    if way.best.name:
        props["name"] = way.best.name
    if way.others:
        props["refs"] = "|".join(m.label() for m in (way.best, *way.others))
    return json.dumps({
        "type": "Feature",
        "properties": props,
        "geometry": {"type": "LineString",
                     "coordinates": [[round(x, 6), round(y, 6)] for x, y in way.coords]},
    }, separators=(",", ":"))


def feature_json_node(node: RouteNode, contract: Contract, *,
                      ridtok: str = "", cctok: str = "") -> str:
    """One knooppunt as a GeoJSON Point feature line.

    The per-feature `tippecanoe.minzoom` is what keeps a country's thousands of
    numbers out of the planning-zoom tiles while the corridors stay: one
    artifact, two floors, no second archive to version and prune."""
    return json.dumps({
        "type": "Feature",
        "tippecanoe": {"minzoom": contract.routes["nodes"]["minZoom"]},
        "properties": {"net": node.net, "nr": node.nr, "ref": node.ref,
                       "ridtok": ridtok, "cctok": cctok},
        "geometry": {"type": "Point",
                     "coordinates": [round(node.lon, 6), round(node.lat, 6)]},
    }, separators=(",", ":"))


@dataclass(frozen=True)
class RouteCounts:
    """What one region's extract produced."""

    ways: int
    nodes: int
    relations: int


def extract_region(pbf_path: Path, contract: Contract, *,
                   ways_out: Path, nodes_out: Path, wayids_out: Path,
                   ridtok: str = "", cctok: str = "") -> RouteCounts:
    """Two passes over a filtered PBF -> way lines, node points, and the way-id set.

    `wayids_out` is the surface pass's route-awareness input: every member way
    id, one per line, sorted — INCLUDING ways whose geometry could not be
    emitted here, because the surface pass walks its own (complete) extract and
    a way this file is missing would silently stay class-gated homework.
    """
    relations = _RelationPass()
    relations.apply_file(str(pbf_path))

    counts = {"ways": 0, "nodes": 0}
    with ways_out.open("w", encoding="utf-8") as wf, \
            nodes_out.open("w", encoding="utf-8") as nf:
        def emit_way(way: RouteWay) -> None:
            wf.write(feature_json_way(way, ridtok=ridtok, cctok=cctok) + "\n")
            counts["ways"] += 1

        def emit_node(node: RouteNode) -> None:
            nf.write(feature_json_node(node, contract, ridtok=ridtok, cctok=cctok) + "\n")
            counts["nodes"] += 1

        _MemberPass(relations.by_way, emit_way, emit_node).apply_file(
            str(pbf_path), locations=True, idx="flex_mem")

    with wayids_out.open("w", encoding="utf-8") as fh:
        for way_id in sorted(relations.by_way):
            fh.write(f"{way_id}\n")
    # Distinct memberships, not relations parsed: two relations with identical
    # tags collapse in by_way, and the count is a progress line, not a ledger.
    rels = {m for members in relations.by_way.values() for m in members}
    return RouteCounts(ways=counts["ways"], nodes=counts["nodes"], relations=len(rels))


def load_way_ids(path: Path) -> frozenset[int]:
    """The way-id set a routes extract wrote, for the surface pass.

    Missing file -> empty set, deliberately: a region whose routes have never
    been extracted still gets its surface build, just without route-awareness —
    the caller prints the fact and the class-gated arm stands alone.
    """
    if not path.exists():
        return frozenset()
    return frozenset(int(line) for line in path.read_text().split())


def selector_expressions() -> list[str]:
    """`osmium tags-filter` expressions for the routes pass.

    Relations by route kind — tags-filter keeps referenced objects by default,
    so the member ways and their nodes come with them — plus knooppunt nodes by
    their number tags, which no relation reference would otherwise select.
    """
    return ["r/route=bicycle,mtb", "n/rcn_ref", "n/lcn_ref"]
