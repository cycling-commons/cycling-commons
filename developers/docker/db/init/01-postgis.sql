-- Enable PostGIS in the Cycling Commons dev database.
-- Runs once, on first cluster init (only when the data volume is empty).
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS postgis_topology;

-- The real schema and migrations are owned by the API (Doctrine) and the
-- Python pipeline — not seeded here. This file only guarantees the spatial
-- extension is present so `GET /api/db-check` and `GET /db` work on a fresh clone.
