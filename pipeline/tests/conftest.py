# SPDX-License-Identifier: AGPL-3.0-only
"""Shared pytest fixtures for the coverage pipeline suite.

Runs INSIDE the pipeline container (osmium-tool + pyosmium come from the image):
  docker compose -f developers/docker/compose.yaml exec -T pipeline python -m pytest tests -q
CI never touches the network: mini.osm.pbf is a COMMITTED fixture, built once
from the hand-written mini.osm via `osmium cat` (regenerate + re-commit on
fixture changes), per coverage-provider.md §3.
"""

import os
import pathlib

import psycopg
import pytest

from coverage.contract import load_contract

FIXTURES = pathlib.Path(__file__).resolve().parent / "fixtures"


@pytest.fixture(autouse=True)
def _no_shared_pbf_dir(monkeypatch):
    """A COVERAGE_PBF_DIR in the container must not move every test's PBFs."""
    monkeypatch.delenv("COVERAGE_PBF_DIR", raising=False)


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
    # (map-and-search.md §4.5) orders overlapping matches by it. country_code
    # mirrors public.region too — load_region's boundary-snap constrains to a
    # POI's own country and backfills cc from the region.
    # admin_level mirrors public.region as well: every spatial step reads the
    # operational-region set, which is derived from it (load.py's
    # _materialize_operational_regions). It defaults to NULL here, and a country
    # whose rows are ALL NULL is operational by `IS NOT DISTINCT FROM MAX(...)`
    # — so existing fixtures keep behaving exactly as they did, and a test that
    # wants an infrastructure row states a level explicitly.
    conn.execute(
        "CREATE TABLE region (id bigint PRIMARY KEY, area_km2 double precision, "
        "country_code char(2), admin_level int, "
        "geom geometry(MultiPolygon, 4326))"
    )
    conn.commit()
    yield conn
    conn.rollback()
    conn.execute("DROP SCHEMA IF EXISTS coverage_pytest CASCADE")
    conn.commit()
    conn.close()


@pytest.fixture()
def catalog(db):
    """The onboarding tables (web/migrations/Version20261005120000.php) in the test schema."""
    db.execute(
        "ALTER TABLE region ADD COLUMN slug varchar(80), ADD COLUMN name varchar(160), "
        "ADD COLUMN iso_code varchar(10), ADD COLUMN source varchar(32), "
        "ADD COLUMN labels jsonb NOT NULL DEFAULT '{}'"
    )
    db.execute(
        "CREATE TABLE country (code char(2) PRIMARY KEY, name varchar(100) NOT NULL, "
        "subtype varchar(20) NOT NULL, bbox jsonb, labels jsonb NOT NULL DEFAULT '{}', "
        "timezones text[] NOT NULL DEFAULT '{}', "
        "status varchar(10) NOT NULL CHECK (status IN ('planned', 'seeded', 'live')), "
        "overture_release varchar(40), planned_at timestamptz, seeded_at timestamptz, live_at timestamptz)"
    )
    db.execute(
        "CREATE TABLE country_extract (slug varchar(100) PRIMARY KEY, "
        "country_code char(2) NOT NULL REFERENCES country (code) ON DELETE CASCADE)"
    )
    db.execute(
        "CREATE TABLE country_plan_region (country_code char(2) NOT NULL REFERENCES country (code) ON DELETE CASCADE, "
        "slug varchar(80) NOT NULL, iso_code varchar(10), name varchar(160) NOT NULL, "
        "labels jsonb NOT NULL DEFAULT '{}', fallback_locales text[] NOT NULL DEFAULT '{}', "
        "admin_level smallint NOT NULL, area_km2 double precision NOT NULL, "
        "geom geometry(Geometry, 4326) NOT NULL, PRIMARY KEY (country_code, slug))"
    )
    db.commit()
    return db
