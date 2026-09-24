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

import shapely
from shapely import STRtree

from coverage.load import BOUNDARY_SNAP_DEG

# The same set load._materialize_operational_regions builds. The two are one
# rule in two places and change together.
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
