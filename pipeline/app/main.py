# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Cycling Commons — Python pipeline (dev scaffold).

This is the geospatial/raster/routing tier (OSM import, DEM sampling, Valhalla
routing, tile/PMTiles generation, ODbL exports). For now it only proves the
wiring: it can reach PostGIS and see the mounted DEM. Real jobs build on top.
"""

import os

import psycopg
from fastapi import FastAPI

app = FastAPI(title="Cycling Commons pipeline", version="0.0.1-dev")

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
    except Exception as exc:  # noqa: BLE001 - surface the reason in dev
        return {"status": "error", "message": str(exc)}
    return {"status": "ok", "postgres": postgres, "postgis": postgis}


@app.get("/dem")
def dem() -> dict:
    """Report whether the downloaded DEM directory is mounted."""
    mounted = os.path.isdir(DEM_DIR)
    sample = sorted(os.listdir(DEM_DIR))[:20] if mounted else []
    return {"dem_dir": DEM_DIR, "mounted": mounted, "sample": sample}
