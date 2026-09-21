# SPDX-License-Identifier: AGPL-3.0-only
"""Harvest timing tracker (worker build plan §2.11): one coverage_run row per
batch and one coverage_run_step row per step, written by run.py as it goes.
Queryable after the container is gone, and what the dispatcher reads to order
regions by staleness (coverage_source_freshness) and to estimate whether the
next region fits tonight's budget. Never commits: run.main is autocommit."""
import contextlib
import time
from collections.abc import Iterator

# Column `trigger` and `rows` are non-reserved words in PostgreSQL; unquoted is fine.
_DDL = (
    """
CREATE TABLE IF NOT EXISTS coverage_run (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    started_at        timestamptz NOT NULL DEFAULT now(),
    finished_at       timestamptz,
    trigger           text NOT NULL,          -- dispatcher | bootstrap | manual
    status            text NOT NULL,          -- running | ok | partial | failed
    regions_requested int,
    regions_loaded    int,
    published_url     text
)
""",
    """
CREATE TABLE IF NOT EXISTS coverage_run_step (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    run_id     bigint NOT NULL REFERENCES coverage_run(id) ON DELETE CASCADE,
    region     text,                          -- NULL for the per-run steps (export, tippecanoe, upload)
    step       text NOT NULL,
    started_at timestamptz NOT NULL,
    seconds    double precision NOT NULL,
    bytes      bigint,
    rows       bigint,
    status     text NOT NULL,                 -- ok | failed
    detail     text
)
""",
    "CREATE INDEX IF NOT EXISTS coverage_run_step_region_step_idx "
    "ON coverage_run_step (region, step, started_at)",
    # One source of truth for "when was this slug last loaded": a never-loaded
    # slug shows NULLs, a slug whose latest load failed keeps its older ok time.
    """
CREATE OR REPLACE VIEW coverage_source_freshness AS
SELECT s.slug,
       l.last_loaded_at,
       l.last_status
FROM coverage_source s
LEFT JOIN LATERAL (
    SELECT max(st.started_at) FILTER (WHERE st.status = 'ok') AS last_loaded_at,
           (array_agg(st.status ORDER BY st.started_at DESC))[1]  AS last_status
    FROM coverage_run_step st
    WHERE st.region = s.slug AND st.step = 'load'
) l ON true
""",
)

_STEP_INSERT = (
    "INSERT INTO coverage_run_step "
    "(run_id, region, step, started_at, seconds, bytes, rows, status, detail) "
    "VALUES (%s, %s, %s, now() - make_interval(secs => %s), %s, %s, %s, %s, %s)"
)


def ensure_tracker_schema(conn) -> None:
    """Idempotent; runs inside load.ensure_schema's bootstrap transaction."""
    for stmt in _DDL:
        conn.execute(stmt)


class StepStats:
    """What a step reports about itself, filled in by the caller before exit."""

    __slots__ = ("bytes", "rows", "detail")

    def __init__(self) -> None:
        self.bytes: int | None = None
        self.rows: int | None = None
        self.detail: str | None = None


class RunTracker:
    """Writes one run row and its step rows through a single connection."""

    def __init__(self, conn) -> None:
        self.conn = conn
        self.run_id = None
        self._attached = False

    def start(self, trigger: str, regions_requested: int) -> int:
        self.run_id = self.conn.execute(
            "INSERT INTO coverage_run (started_at, trigger, status, regions_requested) "
            "VALUES (now(), %s, 'running', %s) RETURNING id",
            (trigger, regions_requested),
        ).fetchone()[0]
        self._attached = False
        return self.run_id

    def attach(self, run_id: int) -> None:
        """Append steps to a run somebody else (the dispatcher) owns and will finish."""
        self.run_id = run_id
        self._attached = True

    @contextlib.contextmanager
    def step(self, region: str | None, name: str) -> Iterator[StepStats]:
        stats = StepStats()
        started = time.monotonic()
        try:
            yield stats
        except Exception as exc:
            self.record(region, name, time.monotonic() - started, bytes=stats.bytes,
                        rows=stats.rows, status="failed",
                        detail=f"{type(exc).__name__}: {exc}"[:500])
            raise
        self.record(region, name, time.monotonic() - started, bytes=stats.bytes,
                    rows=stats.rows, detail=stats.detail)

    def record(self, region: str | None, name: str, seconds: float, *,
               bytes: int | None = None, rows: int | None = None,
               status: str = "ok", detail: str | None = None) -> None:
        """A step timed elsewhere (parse, which runs inside load's COPY stream)."""
        self.conn.execute(_STEP_INSERT, (self.run_id, region, name, seconds, seconds,
                                         bytes, rows, status, detail))

    def finish(self, status: str, regions_loaded: int, published_url: str | None = None) -> None:
        if self._attached or self.run_id is None:
            return
        self.conn.execute(
            "UPDATE coverage_run SET finished_at = now(), status = %s, regions_loaded = %s, "
            "published_url = %s WHERE id = %s",
            (status, regions_loaded, published_url, self.run_id),
        )
