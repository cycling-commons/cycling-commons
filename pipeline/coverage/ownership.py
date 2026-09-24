# SPDX-License-Identifier: AGPL-3.0-only
"""One owner per line feature at a national border (coverage-provider.md §3).

Geofabrik extracts overlap along shared borders, and a way that crosses the
border is complete in both. Without an owner, each country's file carries its
own copy, and two copies refreshed on different nights disagree on the map.

The owner is the rule load_region applies to points: the country of the
nearest operational region within BOUNDARY_SNAP_DEG of the anchor, ties broken
by smaller area and then lower id. Both extracts hold the same geometry, so
both compute the same owner, and every feature lands in exactly one file. No
region within the snap distance means the feature is foreign and is dropped.
"""

from __future__ import annotations

import hashlib
import json
from collections.abc import Callable
from pathlib import Path

import osmium
import shapely
from shapely import STRtree

from coverage.load import BOUNDARY_SNAP_DEG

# The operational regions load._materialize_operational_regions selects,
# restricted to those that can own a row (country_code <> ''), exactly the
# candidate set load_region's nearest-region query reads. The rules change
# together.
OUTLINES_SQL = """
SELECT r.id, r.country_code, r.area_km2, encode(ST_AsBinary(r.geom), 'hex')
FROM region r
WHERE r.geom IS NOT NULL AND r.country_code <> ''
  AND r.admin_level IS NOT DISTINCT FROM (
      SELECT MAX(r2.admin_level) FROM region r2 WHERE r2.country_code = r.country_code)
ORDER BY r.id
"""


def anchor_point(coords: list[tuple[float, float]]) -> tuple[float, float]:
    """The vertex that decides a line's owner and its gap-grid cell."""
    return coords[len(coords) // 2]


def snapshot_outlines(conn, path: Path) -> str:
    """Write the operational region outlines to `path`, return their fingerprint.

    The file is rewritten only when its content changes, so its mtime and hash
    move together and an unchanged region table invalidates no extract.
    """
    rows = [{"id": int(i), "cc": cc.strip(), "area": None if a is None else float(a), "wkb": w}
            for i, cc, a, w in conn.execute(OUTLINES_SQL)]
    body = json.dumps(rows, separators=(",", ":"), sort_keys=True).encode()
    if not path.exists() or path.read_bytes() != body:
        tmp = path.with_suffix(".part")
        tmp.write_bytes(body)
        tmp.replace(path)
    return hashlib.sha256(body).hexdigest()[:16]


def outlines_fingerprint(path: Path) -> str:
    """The fingerprint snapshot_outlines returned for this file, '' when absent."""
    return hashlib.sha256(path.read_bytes()).hexdigest()[:16] if path.exists() else ""


def pbf_header_box(pbf: Path) -> tuple[float, float, float, float] | None:
    """min_lon, min_lat, max_lon, max_lat from a PBF's header, None when it has none.

    Geofabrik extracts declare their box in the header, so reading it costs one
    block, not a pass over the file. A missing or unreadable file has no box.
    """
    try:
        reader = osmium.io.Reader(str(pbf), osmium.osm.osm_entity_bits.NOTHING)
        try:
            box = reader.header().box()
        finally:
            reader.close()
    except RuntimeError:
        return None
    if not box.valid():
        return None
    return (box.bottom_left.lon, box.bottom_left.lat, box.top_right.lon, box.top_right.lat)


# One outline file per run: its rows' bounding boxes, keyed by the file's content hash.
_OUTLINE_BOXES: dict[str, list[tuple[tuple[float, ...], str]]] = {}


def _outline_boxes(path: Path) -> list[tuple[tuple[float, ...], str]]:
    """Each outline row's bounding box with its canonical JSON."""
    body = path.read_bytes()
    key = hashlib.sha256(body).hexdigest()
    if key not in _OUTLINE_BOXES:
        rows = json.loads(body)
        boxes = shapely.bounds(shapely.from_wkb([bytes.fromhex(r["wkb"]) for r in rows])) if rows else []
        _OUTLINE_BOXES.clear()
        _OUTLINE_BOXES[key] = [(tuple(float(v) for v in b), json.dumps(r, separators=(",", ":"), sort_keys=True))
                               for b, r in zip(boxes, rows)]
    return _OUTLINE_BOXES[key]


def region_fingerprint(path: Path, box: tuple[float, float, float, float] | None) -> str:
    """Fingerprint of the outlines that can own a feature inside `box`.

    A feature's owner is the nearest region within BOUNDARY_SNAP_DEG of its
    anchor, so only the outlines whose bounding box meets `box` grown by that
    distance can change what a region's extract keeps. Onboarding a country on
    another continent leaves the fingerprint, and so the extract, unchanged.
    No box (a PBF without a header box) falls back to the fingerprint of every
    outline; an absent file reads as ''.
    """
    if not path.exists():
        return ""
    if box is None:
        return outlines_fingerprint(path)
    s = BOUNDARY_SNAP_DEG
    x0, y0, x1, y1 = box[0] - s, box[1] - s, box[2] + s, box[3] + s
    near = sorted(row for (bx0, by0, bx1, by1), row in _outline_boxes(path)
                  if bx0 <= x1 and bx1 >= x0 and by0 <= y1 and by1 >= y0)
    return hashlib.sha256("\n".join(near).encode()).hexdigest()[:16]


class Owners:
    """Answers "which country owns this anchor?" for one run."""

    def __init__(self, rows: list[dict]):
        rows = sorted(rows, key=lambda r: r["id"])
        self._cc = [r["cc"] for r in rows]
        self._rank = [(float("inf") if r["area"] is None else r["area"], r["id"]) for r in rows]
        self._geoms = [shapely.from_wkb(bytes.fromhex(r["wkb"])) for r in rows]
        for g in self._geoms:
            shapely.prepare(g)
        self._tree = STRtree(self._geoms)

    @classmethod
    def load(cls, path: Path) -> Owners:
        return cls(json.loads(path.read_text()))

    def owner(self, lon: float, lat: float) -> str | None:
        s = BOUNDARY_SNAP_DEG
        near = self._tree.query(shapely.box(lon - s, lat - s, lon + s, lat + s))
        if len(near) == 0:
            return None
        # Fast path: almost every anchor is inside a region, and a prepared
        # contains test is cheap. Distances are computed only near a border.
        inside = [int(i) for i in near if shapely.contains_xy(self._geoms[i], lon, lat)]
        if inside:
            return self._cc[min(inside, key=lambda i: self._rank[i])]
        p = shapely.Point(lon, lat)
        best = None
        for i in (int(i) for i in near):
            d = shapely.distance(self._geoms[i], p)
            if d <= s and (best is None or (d, self._rank[i]) < best[0]):
                best = ((d, self._rank[i]), i)
        return None if best is None else self._cc[best[1]]

    def keeper(self, country_code: str | None) -> Callable[[list[tuple[float, float]]], bool] | None:
        """A filter that keeps the features `country_code` owns, or None for dev/ regions."""
        if country_code is None:
            return None
        return lambda coords: self.owner(*anchor_point(coords)) == country_code
