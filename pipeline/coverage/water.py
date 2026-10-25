# SPDX-License-Identifier: AGPL-3.0-only
"""coverage_water: the big lakes of each extract (map-and-search.md §4.6).

A region page draws its country with the region filled and its neighbours
around it. Administrative areas count inland water as land (the Dutch
provinces share the IJsselmeer between them), so the drawing showed the
IJsselmeer and the Markermeer as land. This keeps every lake, reservoir and
lagoon of an extract from MIN_KM2 up, and the app's
`region_silhouettes_refresh()` leaves them out of the drawn shapes. Nothing
else reads the table: region geometry, membership and areas stay as they are.

The lakes come from the extract the harvest already downloaded:
`osmium tags-filter` keeps the water areas, `osmium export` assembles them
(a lake is often a multipolygon relation of many ways), and only those of
MIN_KM2 or more are kept. Rows are stored simplified to about 50 m, far
finer than a 300 px drawing of a country. An extract whose file and rules
are the ones its lakes were last read from is skipped (about 80 s for the
Netherlands): lakes rarely change, and the harvest runs every week.

Pipeline-owned like coverage_poi: created here, outside Doctrine migrations
(the app's schema_filter skips `coverage_`). An extract loads in one
transaction, so a reader never sees half of it and a failed load keeps last
week's lakes.
"""

from __future__ import annotations

import json
import math
import subprocess
import tempfile
from collections.abc import Iterable, Iterator
from pathlib import Path

import psycopg

from .extract import run_filter
from .pbfs import pbf_identity

# The smallest lake kept, in km². At the Netherlands' scale one drawing unit is
# about 2 km, and a lake of 20 km² is about two units across: the smallest
# the drawing shows (RegionDerivations' min_part is 1.5 units).
MIN_KM2 = 20.0

# What counts as a lake: OSM's `water=*` values for standing water. A river's
# area, a canal, a moat or a pond is left out; an area tagged natural=water
# with no `water=*` is a lake by OSM's own default.
LAKE_KINDS = frozenset({"lake", "reservoir", "lagoon"})

# Stored geometry step, in degrees (about 50 m).
SIMPLIFY_DEG = 0.0005

FILTER = ["a/natural=water", "a/landuse=reservoir"]

_TABLE_DDL = """
CREATE TABLE IF NOT EXISTS coverage_water (
  id        bigserial PRIMARY KEY,
  extract   text NOT NULL,
  osm_ref   text NOT NULL,
  name      text NULL,
  area_km2  double precision NOT NULL,
  geom      geometry(MultiPolygon, 4326) NOT NULL
)
"""

_INDEX_DDL = (
    "CREATE INDEX IF NOT EXISTS coverage_water_extract_idx ON coverage_water (extract)",
    "CREATE INDEX IF NOT EXISTS coverage_water_geom_idx ON coverage_water USING gist (geom)",
)

# One row per extract whose lakes are in: what they were read from.
_MARKER_DDL = """
CREATE TABLE IF NOT EXISTS coverage_water_extract (
  extract    text PRIMARY KEY,
  inputs     text NOT NULL,
  lakes      integer NOT NULL,
  loaded_at  timestamptz NOT NULL DEFAULT now()
)
"""

_INSERT = """
INSERT INTO coverage_water (extract, osm_ref, name, area_km2, geom)
SELECT %(extract)s, %(ref)s, %(name)s, ST_Area(g::geography) / 1e6, g
  FROM (SELECT ST_Multi(ST_CollectionExtract(ST_MakeValid(
               ST_SimplifyPreserveTopology(ST_SetSRID(ST_GeomFromGeoJSON(%(geom)s), 4326), %(step)s)), 3)) AS g) s
 WHERE NOT ST_IsEmpty(g)
"""


def ensure_schema(conn: psycopg.Connection) -> None:
    conn.execute(_TABLE_DDL)
    for ddl in _INDEX_DDL:
        conn.execute(ddl)
    conn.execute(_MARKER_DDL)


def inputs(pbf: Path) -> str:
    """What an extract's lakes are read from: the file and the rules that keep them."""
    return "|".join([pbf_identity(pbf), str(MIN_KM2), str(SIMPLIFY_DEG), ",".join(sorted(LAKE_KINDS)), ",".join(FILTER)])


def is_lake(tags: dict) -> bool:
    """A standing water body: natural=water with a lake kind or none, or a reservoir."""
    if tags.get("landuse") == "reservoir":
        return True
    if tags.get("natural") != "water":
        return False
    kind = tags.get("water")
    return kind is None or kind in LAKE_KINDS


def approx_km2(geometry: dict) -> float:
    """The area of a GeoJSON Polygon or MultiPolygon, in km², near enough to sort lakes by size.

    Each ring by the shoelace formula on longitude scaled by the cosine of
    its own mean latitude; holes subtract. Exact to a few percent for a lake.
    """
    polygons = geometry.get("coordinates") or []
    if geometry.get("type") == "Polygon":
        polygons = [polygons]
    elif geometry.get("type") != "MultiPolygon":
        return 0.0
    km_per_deg = 111.32
    total = 0.0
    for polygon in polygons:
        for i, ring in enumerate(polygon):
            if len(ring) < 4:
                continue
            k = math.cos(math.radians(sum(p[1] for p in ring) / len(ring)))
            twice = sum(ring[j][0] * ring[j + 1][1] - ring[j + 1][0] * ring[j][1] for j in range(len(ring) - 1))
            area = abs(twice) / 2 * km_per_deg * km_per_deg * k
            total += area if i == 0 else -area
    return max(total, 0.0)


def export_areas(pbf_path: Path) -> Iterator[str]:
    """`osmium export` of a water PBF as GeoJSON text sequence: assembled areas only."""
    with tempfile.TemporaryDirectory(prefix="coverage-water-") as tmp:
        proc = subprocess.Popen(
            ["osmium", "export", str(pbf_path), "-f", "geojsonseq", "--geometry-types=polygon",
             "-a", "type,id", "-i", f"sparse_file_array,{tmp}/locations.idx", "-o", "-"],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
        )
        assert proc.stdout is not None
        yield from proc.stdout
        if proc.wait() != 0:
            raise RuntimeError(f"osmium export failed ({proc.returncode}): {proc.stderr.read().strip()}")


def lakes(text_lines: Iterable[str], min_km2: float = MIN_KM2) -> Iterator[dict]:
    """`{ref, name, geometry}` for every lake of `min_km2` or more, from GeoJSON text sequence lines."""
    for line in text_lines:
        line = line.lstrip("\x1e").strip()
        if not line:
            continue
        try:
            feature = json.loads(line)
        except json.JSONDecodeError:
            continue
        props = feature.get("properties") or {}
        geometry = feature.get("geometry") or {}
        if not is_lake(props) or approx_km2(geometry) < min_km2:
            continue
        kind = {"way": "way", "relation": "relation", "w": "way", "r": "relation"}.get(str(props.get("@type")), "way")
        yield {
            "ref": f"{kind}/{props.get('@id')}",
            "name": props.get("name"),
            "geometry": geometry,
        }


def load_water(conn: psycopg.Connection, pbf: Path, workdir: Path, extract: str) -> int | None:
    """Replace one extract's lakes; the number kept, or None when they are read from these inputs already."""
    wants = inputs(pbf)
    row = conn.execute("SELECT inputs FROM coverage_water_extract WHERE extract = %s", (extract,)).fetchone()
    if row is not None and row[0] == wants:
        return None
    filtered = workdir / (extract.replace("/", "-") + "-water.osm.pbf")
    run_filter(pbf, filtered, FILTER)
    found = list(lakes(export_areas(filtered)))
    with conn.transaction():
        conn.execute("DELETE FROM coverage_water WHERE extract = %s", (extract,))
        for lake in found:
            conn.execute(_INSERT, {
                "extract": extract, "ref": lake["ref"], "name": lake["name"],
                "geom": json.dumps(lake["geometry"]), "step": SIMPLIFY_DEG,
            })
        conn.execute(
            "INSERT INTO coverage_water_extract (extract, inputs, lakes) VALUES (%s, %s, %s) "
            "ON CONFLICT (extract) DO UPDATE SET inputs = EXCLUDED.inputs, lakes = EXCLUDED.lakes, loaded_at = now()",
            (extract, wants, len(found)),
        )
    return len(found)


def refresh_silhouettes(conn: psycopg.Connection) -> None:
    """Draw the region shapes again without the lakes: the app's function, once the app has it."""
    conn.execute(
        "DO $$ BEGIN IF to_regproc('region_silhouettes_refresh') IS NOT NULL THEN "
        "PERFORM region_silhouettes_refresh(); END IF; END $$"
    )
