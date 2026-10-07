# SPDX-License-Identifier: AGPL-3.0-only
"""Road pieces for traffic matching (docs/specs/traffic-measurements.md §2).

Every OSM way a bike may ride, labelled by where the rider sits relative to the
cars, so a radar count can be read as safety (on the road) or as noise (on a
separate path beside it):

- ``p`` cycle path: no cars share it;
- ``l`` cycle lane: a painted lane on a road with cars;
- ``r`` shared road: the rider rides where the cars drive.

The browser snaps a ride to these pieces, so a way a bike may not use is left
out entirely: a rider can never be placed on it. ``s`` marks a road that says a
separately mapped cycle path runs beside it, which lets the matcher prefer that
path when GPS cannot tell the two apart.

Same streaming shape as the surface pass: each way is written as it arrives
and dropped, so a large country never sits in memory.
"""

from __future__ import annotations

import json
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import Path

import osmium

# Highways a bike may never use, or that are not a riding surface at all.
_EXCLUDED_HIGHWAYS = frozenset({
    "motorway", "motorway_link", "construction", "proposed", "steps", "platform",
    "raceway", "elevator", "corridor", "bus_stop", "rest_area", "services",
})
# `bicycle` values that forbid riding the way itself.
_FORBIDDEN_BICYCLE = frozenset({"no", "use_sidepath"})
# Pedestrian-first ways, ridden only where bikes are explicitly allowed.
_GATED_HIGHWAYS = frozenset({"footway", "pedestrian", "bridleway"})
_ALLOWED_BICYCLE = frozenset({"yes", "designated", "permissive"})
# Highways a car may use, so a rider on them shares the road.
_ROAD_HIGHWAYS = frozenset({
    "trunk", "trunk_link", "primary", "primary_link", "secondary", "secondary_link",
    "tertiary", "tertiary_link", "unclassified", "residential", "living_street",
    "service", "track", "road", "busway",
})
_CYCLEWAY_KEYS = ("cycleway", "cycleway:left", "cycleway:right", "cycleway:both")
# The selector for `osmium tags-filter`: every way with a highway tag. The
# label rules then decide; a narrower filter would have to repeat them.
SELECTOR = "w/highway"


@dataclass(frozen=True)
class RoadPiece:
    """One OSM way, ready to be written as a GeoJSON LineString feature."""

    way_id: int
    label: str                       # 'p', 'l' or 'r'
    sidepath: bool                   # the road says a separate path runs beside it
    highway: str
    coords: list[tuple[float, float]]   # [(lon, lat), …] as drawn


def label_way(tags: dict[str, str]) -> tuple[str, bool] | None:
    """The piece label and the sidepath flag, or None when a bike may not ride it."""
    highway = tags.get("highway")
    if highway is None or highway in _EXCLUDED_HIGHWAYS:
        return None
    if tags.get("area") == "yes":
        return None
    bicycle = tags.get("bicycle", "")
    if bicycle in _FORBIDDEN_BICYCLE:
        return None
    if highway in _GATED_HIGHWAYS and bicycle not in _ALLOWED_BICYCLE:
        return None

    if highway in ("cycleway", "path") or highway in _GATED_HIGHWAYS:
        return ("p", False)
    if highway not in _ROAD_HIGHWAYS:
        return None
    if tags.get("motor_vehicle") == "no" or tags.get("motorcar") == "no":
        return ("p", False)
    # A cycle street (fietsstraat, Fahrradstraße): laid out for bikes, cars are guests.
    if tags.get("cyclestreet") == "yes" or tags.get("bicycle_road") == "yes":
        return ("p", False)
    cycleways = {tags.get(k, "") for k in _CYCLEWAY_KEYS}
    if "track" in cycleways:
        return ("p", False)
    if cycleways & {"lane", "opposite_lane"}:
        return ("l", "separate" in cycleways)
    return ("r", "separate" in cycleways)


class _Collector(osmium.SimpleHandler):
    """Streams labelled ways, geometry included (needs locations on the reader)."""

    def __init__(self, emit: Callable[[RoadPiece], None]):
        super().__init__()
        self._emit = emit

    def way(self, w) -> None:
        tags = {t.k: t.v for t in w.tags}
        labelled = label_way(tags)
        if labelled is None:
            return
        try:
            coords = [(n.location.lon, n.location.lat) for n in w.nodes if n.location.valid()]
        except osmium.InvalidLocationError:
            return
        if len(coords) < 2:
            return
        label, sidepath = labelled
        self._emit(RoadPiece(w.id, label, sidepath, tags["highway"], coords))


def stream_roadpieces(osm_path: Path, emit: Callable[[RoadPiece], None]) -> None:
    """Walk `osm_path` once, handing every rideable way to `emit`."""
    _Collector(emit).apply_file(str(osm_path), locations=True, idx="flex_mem")


def feature_json(piece: RoadPiece, region: int | None = None) -> str:
    """One piece as a GeoJSON Feature line for tippecanoe; the feature id is the way id.

    `g` is the operational region that owns the piece's middle vertex: the
    browser sends it with each traffic line, so the curator page can count
    roads per region without a server-side table of every road.
    """
    props: dict[str, object] = {"l": piece.label, "h": piece.highway}
    if piece.sidepath:
        props["s"] = 1
    if region is not None:
        props["g"] = region
    return json.dumps({
        "type": "Feature",
        "id": piece.way_id,
        "properties": props,
        "geometry": {"type": "LineString", "coordinates": [list(c) for c in piece.coords]},
    }, separators=(",", ":"))


def extract_region(osm_path: Path, out_path: Path,
                   region_of: Callable[[list[tuple[float, float]]], int | None] | None = None) -> int:
    """Write every piece of a filtered region PBF to GeoJSONL; returns the piece count.

    `region_of` gives a piece's region from its coordinates (coverage.ownership).
    """
    count = 0
    with out_path.open("w", encoding="utf-8") as out:
        def write(piece: RoadPiece) -> None:
            nonlocal count
            out.write(feature_json(piece, None if region_of is None else region_of(piece.coords)))
            out.write("\n")
            count += 1
        stream_roadpieces(osm_path, write)
    return count

