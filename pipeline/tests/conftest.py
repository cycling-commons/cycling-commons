# SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0
"""Shared pytest fixtures for the coverage pipeline suite.

Runs INSIDE the pipeline container (osmium-tool + pyosmium come from the image):
  docker compose -f developers/docker/compose.yaml exec -T pipeline python -m pytest tests -q
CI never touches the network: mini.osm.pbf is a COMMITTED fixture, built once
from the hand-written mini.osm via `osmium cat` (regenerate + re-commit on
fixture changes — see the plan), per coverage-provider.md §3.
"""

import os
import pathlib

import psycopg
import pytest

from coverage.contract import load_contract

FIXTURES = pathlib.Path(__file__).resolve().parent / "fixtures"


@pytest.fixture(scope="session")
def contract():
    return load_contract()


@pytest.fixture(scope="session")
def mini_pbf():
    """The committed PBF twin of the hand-written mini.osm fixture."""
    return FIXTURES / "mini.osm.pbf"


@pytest.fixture()
def db():
    """Isolated schema on the dev PostGIS (compose DATABASE_DSN).

    coverage_poi and a private `region` land in coverage_pytest (shadowing
    public.region via search_path) and the schema is dropped afterwards — the
    real public schema is never written. pg_trgm is ensured in public first so
    ensure_schema's guard no-ops instead of installing into the test schema.
    """
    conn = psycopg.connect(os.environ["DATABASE_DSN"])
    conn.execute("CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public")
    conn.execute("DROP SCHEMA IF EXISTS coverage_pytest CASCADE")
    conn.execute("CREATE SCHEMA coverage_pytest")
    conn.execute("SET search_path TO coverage_pytest, public")
    # area_km2 mirrors public.region: load_region's smallest-area-wins tie-break
    # (region-scoping-design.md §3) orders overlapping matches by it.
    conn.execute(
        "CREATE TABLE region (id bigint PRIMARY KEY, area_km2 double precision, "
        "geom geometry(MultiPolygon, 4326))"
    )
    conn.commit()
    yield conn
    conn.rollback()
    conn.execute("DROP SCHEMA IF EXISTS coverage_pytest CASCADE")
    conn.commit()
    conn.close()
