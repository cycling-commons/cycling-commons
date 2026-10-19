# SPDX-License-Identifier: AGPL-3.0-only
"""coverage_way_surface: each OSM way's recorded surface, by way id (coverage-provider.md §4).

The route planner (POST /map/plan/leg) asks Valhalla which ways a leg rides
and looks each `edge.way_id` up here. The rows are what the surface pass
already reads: the contract class of the `surface` tag, the smoothness and the
tracktype, each NULL when OSM records none (or a value the contract does not
name). A way with none of the three has no row.

One row in coverage_way_surface_country per loaded country says its ways are
in: a way missing from a loaded country is a way nobody has recorded, and a
country with no marker row is one the table cannot answer for.

Pipeline-owned like coverage_poi: created here, outside Doctrine migrations
(the app's schema_filter skips `coverage_`). A country loads in one
transaction, staging COPY then swap, so a reader never sees a half-loaded
country and a failed load keeps last week's rows serving.
"""

from __future__ import annotations

from collections.abc import Iterable
from pathlib import Path

import psycopg

_TABLE_DDL = """
CREATE TABLE IF NOT EXISTS coverage_way_surface (
  way_id       bigint PRIMARY KEY,
  country_code text   NOT NULL,
  surface      text   NULL,
  smoothness   text   NULL,
  tracktype    text   NULL
)
"""

# A country reload deletes by country; without this it would scan the table.
_INDEX_DDL = """
CREATE INDEX IF NOT EXISTS coverage_way_surface_country_idx
  ON coverage_way_surface (country_code)
"""

_MARKER_DDL = """
CREATE TABLE IF NOT EXISTS coverage_way_surface_country (
  country_code text PRIMARY KEY,
  inputs       text NOT NULL,
  ways         integer NOT NULL,
  loaded_at    timestamptz NOT NULL DEFAULT now()
)
"""

_STAGE_DDL = """
CREATE TEMP TABLE way_surface_stage (
  way_id     bigint NOT NULL,
  surface    text,
  smoothness text,
  tracktype  text
) ON COMMIT DROP
"""

# DISTINCT ON: a way can sit in two regions of one country (the US is
# california + colorado). ON CONFLICT: a way another country held until the
# owner rule moved it changes country here, before that country's own reload.
_SWAP_INSERT = """
INSERT INTO coverage_way_surface (way_id, country_code, surface, smoothness, tracktype)
SELECT DISTINCT ON (way_id) way_id, %s::text, surface, smoothness, tracktype
  FROM way_surface_stage
 ORDER BY way_id, surface, smoothness, tracktype
ON CONFLICT (way_id) DO UPDATE
   SET country_code = EXCLUDED.country_code,
       surface      = EXCLUDED.surface,
       smoothness   = EXCLUDED.smoothness,
       tracktype    = EXCLUDED.tracktype
"""

_MARKER_UPSERT = """
INSERT INTO coverage_way_surface_country (country_code, inputs, ways, loaded_at)
VALUES (%s, %s, %s, now())
ON CONFLICT (country_code) DO UPDATE
   SET inputs = EXCLUDED.inputs, ways = EXCLUDED.ways, loaded_at = EXCLUDED.loaded_at
"""


def ensure_schema(conn: psycopg.Connection) -> None:
    """Create both tables and the country index when absent."""
    conn.execute(_TABLE_DDL)
    conn.execute(_INDEX_DDL)
    conn.execute(_MARKER_DDL)


def load_country(conn: psycopg.Connection, country_code: str, files: Iterable[Path],
                 inputs: str) -> int | None:
    """Replace one country's rows with its share files; None when already current.

    Current means the marker row names these `inputs` (the surface build
    fingerprint, run._surface_inputs). The transaction holds the staging COPY
    and the swap only, so it never sits idle across anything slow on this side.
    """
    cc = country_code.upper()
    files = list(files)
    row = conn.execute("SELECT inputs FROM coverage_way_surface_country WHERE country_code = %s",
                       (cc,)).fetchone()
    if row is not None and row[0] == inputs:
        return None
    missing = [f.name for f in files if not f.exists()]
    if missing:
        raise FileNotFoundError(f"no way table file {', '.join(missing)}")
    with conn.transaction():
        conn.execute(_STAGE_DDL)
        with conn.cursor() as cur:
            with cur.copy("COPY way_surface_stage (way_id, surface, smoothness, tracktype) "
                          "FROM STDIN") as copy:
                for path in files:
                    with path.open("rb") as fh:
                        for chunk in iter(lambda: fh.read(1 << 20), b""):
                            copy.write(chunk)
        conn.execute("DELETE FROM coverage_way_surface WHERE country_code = %s", (cc,))
        ways = conn.execute(_SWAP_INSERT, (cc,)).rowcount
        conn.execute(_MARKER_UPSERT, (cc, inputs, ways))
    return ways


def retire_country(conn: psycopg.Connection, country_code: str) -> int:
    """Remove one country's rows and its marker (offboarding). Returns the rows removed."""
    cc = country_code.upper()
    with conn.transaction():
        ways = conn.execute("DELETE FROM coverage_way_surface WHERE country_code = %s", (cc,)).rowcount
        conn.execute("DELETE FROM coverage_way_surface_country WHERE country_code = %s", (cc,))
    return ways
