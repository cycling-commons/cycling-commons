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

Three artifacts, from ONE pass over the PBF (2026-08-12):

- the **classified** arm — ways that carry a `surface` tag, canonicalised into
  the six non-`unverified` classes. The riding view: what is under your tyres.
- the **to-do** arm — ways with no `surface` tag, restricted to the classes
  where the answer is genuinely unknown (contract `surface.todo.highways`:
  track, path, unclassified). Its own artifact because a vector tile is fetched
  whole, so folding it in would cost every rider its bytes for a layer that is
  off by default — the mistake the heat layer made inside catalog.json.
- the **gaps grid** — one square per `gaps.cellZoom` tile carrying how many km
  of to-do network sit inside it. The same question as the to-do arm at
  planning scale, for ~1% of the bytes.

**What the to-do arm deliberately leaves out, and why it is the interesting
part.** It used to be every untagged way, all nine highway classes, and it cost
as much as the classified arm (Belgium: 42 MB against 43). Measured against our
own tagged data (2026-08-12), most of that was asking riders to confirm what is
already known: of Belgian ways somebody HAS tagged, 0.1% of primary, 0.5% of
secondary, 2.5% of tertiary, 0.0% of cycleway and 7.2% of residential are
unpaved — and mappers tag the surprising road first, so an untagged one of those
is safer still. Tracks (88% unpaved), paths (a 50/50 coin flip) and rural
unclassified lanes are where nobody can predict the answer. Keeping only those
took Belgium's arm from 43.3 MB to 16 MB and made the prompt sharper rather than
weaker: every line in it is a road where riding it actually settles something.

The pass is a STREAM, not a collection: each way is written as it arrives and
then dropped. A `list[SurfaceWay]` of a mid-size country is gigabytes of Python
objects, and the machine that builds these has less RAM than France needs.
"""

from __future__ import annotations

import json
import math
from collections import defaultdict
from collections.abc import Callable, Iterator
from dataclasses import dataclass
from pathlib import Path

import osmium

from coverage.contract import Contract


@dataclass(frozen=True)
class SurfaceWay:
    """One OSM way, ready to be written as a GeoJSON LineString feature."""

    ref: str                       # 'way/<osm-id>' — item.source_ref format
    cls: str                       # canonical class (SURFACE_STYLE key)
    highway: str                   # raw highway value, for the drawer
    name: str                      # OSM `name` tag, '' when the way has none
    coords: list[tuple[float, float]]   # [(lon, lat), …] as drawn


def canonical_class(surface: str | None, highway: str, contract: Contract) -> str | None:
    """Canonical class for a way, or None when it is not ours to draw.

    The class is the SURFACE, and only the surface (owner decision 2026-08-12).
    `highway=cycleway` used to win outright, which put a road-TYPE answer into a
    surface scale: a purple line said "cycleway" while the line beside it said
    "asphalt", and neither could be read as a fact about what is under the
    tyres. Most Dutch cycleways are asphalt — but not all, and the map had no
    way to say which. Road type still travels on every feature as `hw`, and the
    client draws it as its own channel.

    An unrecognised value falls through to the untagged arm rather than being
    invented into a class, because a made-up class is worse than an honest
    "nobody has said" — and so does a way with no surface tag at all, whatever
    kind of road it is.
    """
    spec = contract.surface
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

    Hands each way to `emit` and keeps NOTHING. The collecting version of this
    held every way of a country in a Python list, which is fine for Belgium and
    several gigabytes for France — on a build host with 3 GB free, the country
    that most needs the layer was the one that could not build it.
    """

    def __init__(self, contract: Contract, emit: Callable[[SurfaceWay], None]):
        super().__init__()
        self._contract = contract
        self._emit = emit

    def way(self, w) -> None:
        tags = {t.k: t.v for t in w.tags}
        highway = _wanted(tags, self._contract)
        if highway is None:
            return
        cls = canonical_class(tags.get("surface"), highway, self._contract)
        if cls is None:
            return
        try:
            coords = [(n.location.lon, n.location.lat) for n in w.nodes if n.location.valid()]
        except osmium.InvalidLocationError:
            return
        if len(coords) < 2:
            return
        # 'way/<id>', NOT the design sketch's 'w<id>'. This ref is what the
        # drawer hands to /improve, and materialize-on-edit then creates an A
        # item carrying it as source_ref — which every other path in the
        # codebase writes and reads as "way/NNN" (CatalogProvider::curatedRefs,
        # the coverage dedupe, the importer). A second spelling would create
        # items that look right and dedupe against nothing.
        # The `name` tag, because a stretch of road is "Rue du Puits
        # Saint-Martin", not "an unclassified paved way". The basemap has been
        # printing that name under our line all along while the drawer said
        # nothing (owner-reported 2026-08-12), and a rider correcting the
        # surface then had to type a name we already knew.
        #
        # The tag verbatim, never composed with a class label: the class is
        # rendered from a localised dictionary at draw time, so gluing the two
        # here would freeze one English word into a name field for good.
        self._emit(SurfaceWay(f"way/{w.id}", cls, highway, tags.get("name", ""), coords))


def stream_surface_ways(pbf_path: Path, contract: Contract,
                        emit: Callable[[SurfaceWay], None]) -> None:
    """Walk `pbf_path` once, handing every selected way to `emit`.

    One pass for all three artifacts. The two-pass version re-ran `osmium
    tags-filter` and the whole node-location walk per arm, which doubled the
    expensive half of a continental build to produce data it had already seen.
    """
    _Collector(contract, emit).apply_file(str(pbf_path), locations=True, idx="flex_mem")


def feature_json(way: SurfaceWay, *, ridtok: str = "", cctok: str = "") -> str:
    """One way as a GeoJSON Feature line for tippecanoe.

    Scope tokens ride every feature exactly as they do on the point layers
    (map-and-search.md §4.5): the client filters on them, and an empty pair is
    the explicit "prop-less, render unfiltered" state rather than an accident.
    """
    props = {"cls": way.cls, "hw": way.highway, "ref": way.ref,
             "ridtok": ridtok, "cctok": cctok}
    # Omitted rather than empty: most ways outside towns are unnamed, and an
    # empty string per feature is dead weight in every tile a rider downloads.
    if way.name:
        props["name"] = way.name
    return json.dumps({
        "type": "Feature",
        "properties": props,
        "geometry": {"type": "LineString",
                     "coordinates": [[round(x, 6), round(y, 6)] for x, y in way.coords]},
    }, separators=(",", ":"))


def write_geojsonl(ways: Iterator[SurfaceWay] | list[SurfaceWay], out_path: Path,
                   *, ridtok: str = "", cctok: str = "") -> int:
    """Write ways as GeoJSONL for tippecanoe. Returns the feature count."""
    n = 0
    with out_path.open("w", encoding="utf-8") as fh:
        for w in ways:
            fh.write(feature_json(w, ridtok=ridtok, cctok=cctok) + "\n")
            n += 1
    return n


def _length_km(coords: list[tuple[float, float]]) -> float:
    """Great-circle length of a way, in km.

    The grid counts KILOMETRES, not ways. A way is an arbitrary unit — a rural
    track runs unbroken for 3 km while a village lane is split at every junction
    — so counting ways would make dense villages look like more work than the
    gravel network around them, which is the opposite of the truth.
    """
    total = 0.0
    for (lon1, lat1), (lon2, lat2) in zip(coords, coords[1:]):
        p1, p2 = math.radians(lat1), math.radians(lat2)
        dp, dl = p2 - p1, math.radians(lon2 - lon1)
        a = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
        total += 6371.0 * 2 * math.asin(min(1.0, math.sqrt(a)))
    return total


class GapGrid:
    """Where the unrecorded network is, aggregated to one square per cell.

    The planning half of the "what still needs recording?" question. A rider
    deciding where to point a Scout ride does not need 400,000 line geometries —
    they need to see which part of the map is dark, which is ~900 squares per
    country instead (0.5 MB for Belgium against 43 MB of lines).

    Only the to-do classes are counted, so the grid and the lines answer the
    SAME question: a cell full of untagged residential streets is not work,
    because an untagged residential street is asphalt (see the module docstring).

    A way is charged to the cell holding its MIDPOINT rather than split across
    the cells it crosses. At ~6 km cells and a road network whose ways are a
    fraction of that, splitting would cost a clipping pass per way to move a
    rounding error between neighbouring squares.
    """

    def __init__(self, cell_zoom: int):
        self._z = cell_zoom
        # cell -> [unrecorded km, recorded km, unrecorded way count]
        self._cells: dict[tuple[int, int], list[float]] = defaultdict(lambda: [0.0, 0.0, 0])

    def _cell(self, lon: float, lat: float) -> tuple[int, int]:
        n = 2 ** self._z
        lat = max(-85.05, min(85.05, lat))   # web-mercator poles have no tile
        x = int((lon + 180.0) / 360.0 * n)
        rad = math.radians(lat)
        y = int((1.0 - math.log(math.tan(rad) + 1.0 / math.cos(rad)) / math.pi) / 2.0 * n)
        return max(0, min(n - 1, x)), max(0, min(n - 1, y))

    def _bounds(self, x: int, y: int) -> tuple[float, float, float, float]:
        n = 2 ** self._z
        def ll(xi: int, yi: int) -> tuple[float, float]:
            lon = xi / n * 360.0 - 180.0
            lat = math.degrees(math.atan(math.sinh(math.pi * (1.0 - 2.0 * yi / n))))
            return lon, lat
        lon0, lat0 = ll(x, y)
        lon1, lat1 = ll(x + 1, y + 1)
        return lon0, lat0, lon1, lat1

    def add(self, way: SurfaceWay, *, recorded: bool) -> None:
        """Charge a to-do-class way's length to its cell."""
        mid = way.coords[len(way.coords) // 2]
        bucket = self._cells[self._cell(*mid)]
        km = _length_km(way.coords)
        if recorded:
            bucket[1] += km
        else:
            bucket[0] += km
            bucket[2] += 1

    def features(self, *, ridtok: str = "", cctok: str = "") -> Iterator[str]:
        """One GeoJSON square per non-empty cell, as GeoJSONL lines."""
        for (x, y), (todo_km, known_km, count) in sorted(self._cells.items()):
            if todo_km <= 0:
                # A cell where everything is already recorded is not a gap, and
                # shipping it would draw a square over finished work.
                continue
            lon0, lat0, lon1, lat1 = self._bounds(x, y)
            total = todo_km + known_km
            yield json.dumps({
                "type": "Feature",
                "properties": {
                    "km": round(todo_km, 1),
                    "pct": round(100.0 * todo_km / total) if total else 100,
                    "n": count,
                    "ridtok": ridtok,
                    "cctok": cctok,
                },
                "geometry": {"type": "Polygon", "coordinates": [[
                    [round(lon0, 5), round(lat0, 5)], [round(lon1, 5), round(lat0, 5)],
                    [round(lon1, 5), round(lat1, 5)], [round(lon0, 5), round(lat1, 5)],
                    [round(lon0, 5), round(lat0, 5)],
                ]]},
            }, separators=(",", ":"))


@dataclass(frozen=True)
class SurfaceCounts:
    """What one region's single pass produced."""

    classified: int
    todo: int
    cells: int


def extract_region(pbf_path: Path, contract: Contract, *,
                   classified_out: Path, todo_out: Path, gaps_out: Path,
                   ridtok: str = "", cctok: str = "") -> SurfaceCounts:
    """One pass over a filtered PBF -> all three artifacts' GeoJSONL.

    Written straight through to disk: a country's ways never accumulate in
    memory, which is what lets a 5 GB France extract run on a host with a few
    spare gigabytes.
    """
    spec = contract.surface
    untagged_class = spec["untaggedClass"]
    todo_highways = set(spec["todo"]["highways"])
    grid = GapGrid(spec["gaps"]["cellZoom"])
    counts = {"classified": 0, "todo": 0}

    with classified_out.open("w", encoding="utf-8") as cf, \
            todo_out.open("w", encoding="utf-8") as tf:
        def emit(way: SurfaceWay) -> None:
            unrecorded = way.cls == untagged_class
            if not unrecorded:
                cf.write(feature_json(way, ridtok=ridtok, cctok=cctok) + "\n")
                counts["classified"] += 1
            if way.highway in todo_highways:
                # The grid measures the same population the to-do lines draw,
                # recorded and not, so its percentage means "of the roads worth
                # recording here, this share has no answer yet".
                grid.add(way, recorded=not unrecorded)
                if unrecorded:
                    tf.write(feature_json(way, ridtok=ridtok, cctok=cctok) + "\n")
                    counts["todo"] += 1

        stream_surface_ways(pbf_path, contract, emit)

    cells = 0
    with gaps_out.open("w", encoding="utf-8") as gf:
        for line in grid.features(ridtok=ridtok, cctok=cctok):
            gf.write(line + "\n")
            cells += 1
    return SurfaceCounts(classified=counts["classified"], todo=counts["todo"], cells=cells)


def selector_expressions(contract: Contract) -> list[str]:
    """`osmium tags-filter` expressions for the surface pass.

    Ways only, and filtered on `highway` rather than on `surface`: the untagged
    arm is defined by the ABSENCE of a surface tag, so a `w/surface` filter
    could never see it. The classification happens in canonical_class().
    """
    return [f"w/highway={h}" for h in contract.surface["highways"]]
