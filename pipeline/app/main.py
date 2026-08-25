# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Cycling Commons — Python pipeline (dev scaffold).

This is the geospatial/raster/routing tier (OSM import, DEM sampling, Valhalla
routing, tile/PMTiles generation, ODbL exports). For now it only proves the
wiring: it can reach PostGIS and see the mounted DEM. Real jobs build on top.
"""

import logging
import os

import psycopg
from fastapi import FastAPI

app = FastAPI(title="Cycling Commons pipeline", version="0.0.1-dev")
log = logging.getLogger("cc.pipeline")

DATABASE_DSN = os.environ.get(
    "DATABASE_DSN", "postgresql://cc:cc@db:5432/cyclingcommons"
)
DEM_DIR = os.environ.get("DEM_DIR", "/data/dem")


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "service": "pipeline"}


@app.get("/db")
def db() -> dict:
    """Prove the pipeline can read PostGIS."""
    try:
        with psycopg.connect(DATABASE_DSN, connect_timeout=5) as conn:
            postgres = conn.execute("SELECT version()").fetchone()[0]
            postgis = conn.execute("SELECT postgis_full_version()").fetchone()[0]
    except Exception as exc:  # noqa: BLE001 - any failure is the same answer here
        # The exception text is LOGGED, never returned. The old `str(exc)` in
        # the response body handed a caller whatever psycopg had to say, and a
        # failed connection says plenty: an auth failure reports as
        # `connection to server at "172.26.0.8", port 5432 failed: FATAL:
        # password authentication failed for user "cc"`, which is the internal
        # address, the port and the database user (verified against psycopg 3,
        # 2026-08-25; the password itself does NOT appear, which is the one
        # part of the original report that did not hold up). Nothing about this
        # being a dev scaffold makes it fine: the service published its port to
        # the whole network until the same scan, and scaffolds get copied.
        log.exception("PostGIS connectivity check failed")
        return {
            "status": "error",
            "message": "Cannot reach PostGIS. See the pipeline container logs for the reason.",
        }
    return {"status": "ok", "postgres": postgres, "postgis": postgis}


@app.get("/dem")
def dem() -> dict:
    """Report whether the downloaded DEM directory is mounted."""
    mounted = os.path.isdir(DEM_DIR)
    sample = sorted(os.listdir(DEM_DIR))[:20] if mounted else []
    return {"dem_dir": DEM_DIR, "mounted": mounted, "sample": sample}
