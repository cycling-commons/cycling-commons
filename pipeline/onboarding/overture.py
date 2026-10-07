# SPDX-License-Identifier: AGPL-3.0-only
"""The planner's Overture reads: a country's box (no geometry), then its divisions inside it."""
from __future__ import annotations

import json
from dataclasses import dataclass

from divisions import config
from divisions.export_divisions import _connect, geodesic_area_km2


class OvertureError(LookupError):
    """The release or the country's rows are not there; the message is the one-line reason."""


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
        try:
            self.path = config.division_area_path(release)
        except ValueError as exc:
            raise OvertureError(str(exc)) from exc
        self._con = con

    @property
    def con(self):
        if self._con is None:
            self._con = _connect()
            # DuckDB draws its progress bar on stdout, which would corrupt --json.
            self._con.execute("SET enable_progress_bar = false;")
        return self._con

    def _query(self, sql: str, params: list):
        import duckdb

        try:
            return self.con.execute(sql, params)
        except (duckdb.IOException, duckdb.HTTPException) as exc:
            # A missing release or an unreachable bucket; any other DuckDB error is a bug and keeps its traceback.
            first = str(exc).strip().splitlines()[0] if str(exc).strip() else type(exc).__name__
            raise OvertureError(f"Overture {self.release}: {first}") from exc

    def country_box(self, cc: str) -> tuple[float, float, float, float]:
        """Box over the country's land divisions, read from their bbox columns only."""
        row = self._query(
            f"""SELECT min(bbox.xmin), min(bbox.ymin), max(bbox.xmax), max(bbox.ymax)
                FROM read_parquet('{self.path}', hive_partitioning=1)
                WHERE country = ? AND "class" = 'land'""",
            [cc],
        ).fetchone()
        if row is None or row[0] is None:
            raise OvertureError(f"Overture {self.release} has no land divisions for {cc}")
        return tuple(float(v) for v in row)

    def divisions(self, cc: str, subtype: str, box: tuple[float, float, float, float]) -> list[Division]:
        x0, y0, x1, y1 = box
        rows = self._query(
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
