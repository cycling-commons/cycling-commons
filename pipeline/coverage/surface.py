# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Road-surface LINE layer for the coverage plane.

Design: docs/specs/Dated/2026-08-09-surface-line-tiles-design.md, owner-approved
2026-08-10. The A layer's curated corridors stay `item` rows; this is the
reference skin underneath them — every surfaced way OSM knows about, rendered
from tiles, with **zero database rows**.

That is the whole point of keeping it separate from the point pipeline: points
go through `coverage_poi` because the serve endpoints and the dedupe need SQL,
and lines need neither. So ways stream from the PBF straight to per-country
GeoJSONL and on into tippecanoe — no PostGIS table, no index, no diff-merge,
which is what makes country-scale line data cheap.

Two arms, deliberately in two artifacts (design D3):

- the **classified** arm — ways that carry a `surface` tag, canonicalised into
  the six non-`unverified` classes;
- the **untagged** arm — tagged highways with no `surface` at all, which is
  most of the network in many areas. It is the "needs a tag" contribution view,
  off by default, and it gets its own file because a vector tile is fetched
  whole: folding it in would make every surface tile several times larger for
  every rider, to carry a layer almost nobody switches on. That is exactly the
  mistake the heat layer made inside catalog.json.
"""

from __future__ import annotations

import json
from collections.abc import Iterator
from dataclasses import dataclass
from pathlib import Path

import osmium

from coverage.contract import Contract


@dataclass(frozen=True)
class SurfaceWay:
    """One OSM way, ready to be written as a GeoJSON LineString feature."""

    ref: str                       # 'w<osm-id>' — the design's tile `ref`
    cls: str                       # canonical class (SURFACE_STYLE key)
    highway: str                   # raw highway value, for the drawer
    coords: list[tuple[float, float]]   # [(lon, lat), …] as drawn


def canonical_class(surface: str | None, highway: str, contract: Contract) -> str | None:
    """Canonical class for a way, or None when it is not ours to draw.

    `highway=cycleway` wins regardless of its surface tag: a rider wants to know
    it is a cycleway first, and what it is paved with second. Everything else
    keys off the surface value; an unrecognised value falls through to the
    untagged arm rather than being invented into a class, because a made-up
    class is worse than an honest "nobody has said".
    """
    spec = contract.surface
    if highway == spec["cyclewayClass"]:
        return spec["cyclewayClass"]
    if not surface:
        return spec["untaggedClass"]
    for cls, values in spec["classes"].items():
        if surface in values:
            return cls
    return spec["untaggedClass"]


def _wanted(tags, contract: Contract) -> str | None:
    """The highway value if this way belongs in the layer, else None."""
    spec = contract.surface
    highway = tags.get("highway")
    if highway is None or highway not in spec["highways"]:
        return None
    gate = spec.get("gatedHighways", {}).get(highway)
    if gate is not None and tags.get(gate["key"]) not in gate["values"]:
        # e.g. a footpath that nobody has marked cycleable. It carries surface
        # tags like anything else, which is exactly why it has to be gated
        # rather than trusted.
        return None
    return highway


class _Collector(osmium.SimpleHandler):
    """Streams matching ways out of a filtered PBF, geometry included.

    Requires `locations_on_ways` on the reader so `w.nodes` carry coordinates;
    without it every way arrives geometry-less and the layer is silently empty.
    """

    def __init__(self, contract: Contract, untagged: bool):
        super().__init__()
        self._contract = contract
        self._untagged_class = contract.surface["untaggedClass"]
        self._want_untagged = untagged
        self.ways: list[SurfaceWay] = []

    def way(self, w) -> None:
        tags = {t.k: t.v for t in w.tags}
        highway = _wanted(tags, self._contract)
        if highway is None:
            return
        cls = canonical_class(tags.get("surface"), highway, self._contract)
        if cls is None:
            return
        # One arm per artifact — never both in one file.
        if (cls == self._untagged_class) != self._want_untagged:
            return
        try:
            coords = [(n.location.lon, n.location.lat) for n in w.nodes if n.location.valid()]
        except osmium.InvalidLocationError:
            return
        if len(coords) < 2:
            return
        self.ways.append(SurfaceWay(f"w{w.id}", cls, highway, coords))


def parse_surface_ways(pbf_path: Path, contract: Contract, *, untagged: bool = False) -> list[SurfaceWay]:
    """Every way in `pbf_path` belonging to the classified (or untagged) arm."""
    handler = _Collector(contract, untagged)
    handler.apply_file(str(pbf_path), locations=True, idx="flex_mem")
    return handler.ways


def write_geojsonl(ways: Iterator[SurfaceWay] | list[SurfaceWay], out_path: Path,
                   *, ridtok: str = "", cctok: str = "") -> int:
    """Write ways as GeoJSONL for tippecanoe. Returns the feature count.

    Scope tokens ride every feature exactly as they do on the point layers
    (map-and-search.md §4.5): the client filters on them, and an empty pair is
    the explicit "prop-less, render unfiltered" state rather than an accident.
    """
    n = 0
    with out_path.open("w", encoding="utf-8") as fh:
        for w in ways:
            fh.write(json.dumps({
                "type": "Feature",
                "properties": {"cls": w.cls, "hw": w.highway, "ref": w.ref,
                               "ridtok": ridtok, "cctok": cctok},
                "geometry": {"type": "LineString", "coordinates": [[round(x, 6), round(y, 6)] for x, y in w.coords]},
            }, separators=(",", ":")) + "\n")
            n += 1
    return n


def selector_expressions(contract: Contract) -> list[str]:
    """`osmium tags-filter` expressions for the surface pass.

    Ways only, and filtered on `highway` rather than on `surface`: the untagged
    arm is defined by the ABSENCE of a surface tag, so a `w/surface` filter
    could never see it. The classification happens in canonical_class().
    """
    return [f"w/highway={h}" for h in contract.surface["highways"]]
