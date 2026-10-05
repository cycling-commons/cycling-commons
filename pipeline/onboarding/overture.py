# SPDX-License-Identifier: AGPL-3.0-only
"""The planner's Overture reads: a country's box (no geometry), then its divisions inside it."""
from __future__ import annotations

import json
from dataclasses import dataclass

from divisions import config
from divisions.export_divisions import _connect, geodesic_area_km2


@dataclass(frozen=True)
class Division:
    iso: str | None
    primary: str
    common: dict[str, str]
    geometry: dict
    area_km2: float


class Overture:
    def __init__(self, release: str, con=None):
        self.release = release
        self.path = config.division_area_path(release)
        self._con = con

    @property
    def con(self):
        if self._con is None:
            self._con = _connect()
            # DuckDB draws its progress bar on stdout, which would corrupt --json.
            self._con.execute("SET enable_progress_bar = false;")
        return self._con

    def country_box(self, cc: str) -> tuple[float, float, float, float]:
        """Box over the country's land divisions, read from their bbox columns only."""
        row = self.con.execute(
            f"""SELECT min(bbox.xmin), min(bbox.ymin), max(bbox.xmax), max(bbox.ymax)
                FROM read_parquet('{self.path}', hive_partitioning=1)
                WHERE country = ? AND "class" = 'land'""",
            [cc],
        ).fetchone()
        if row is None or row[0] is None:
            raise LookupError(f"Overture {self.release} has no land divisions for {cc}")
        return tuple(float(v) for v in row)

    def divisions(self, cc: str, subtype: str, box: tuple[float, float, float, float]) -> list[Division]:
        x0, y0, x1, y1 = box
        rows = self.con.execute(
            f"""SELECT region, names.primary, CAST(to_json(names.common) AS VARCHAR), ST_AsGeoJSON(geometry)
                FROM read_parquet('{self.path}', hive_partitioning=1)
                WHERE country = ? AND subtype = ? AND "class" = 'land'
                  AND bbox.xmax >= ? AND bbox.ymax >= ? AND bbox.xmin <= ? AND bbox.ymin <= ?""",
            [cc, subtype, x0, y0, x1, y1],
        ).fetchall()
        out = []
        for iso, primary, common, geojson in rows:
            geometry = json.loads(geojson)
            names = json.loads(common) if common else {}
            out.append(Division(iso=iso, primary=primary or "", common=names if isinstance(names, dict) else {},
                                geometry=geometry, area_km2=geodesic_area_km2(geometry)))
        return out
