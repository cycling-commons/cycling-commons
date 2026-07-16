-- Enable pg_trgm in the Cycling Commons dev database: coverage_poi name
-- search uses a GIN(name gin_trgm_ops) index
-- (docs/specs/coverage-provider.md §2).
-- Like 01-postgis.sql this runs once, on first cluster init (empty data
-- volume only). Existing dev clusters need it applied by hand (see the
-- verification step in the plan / dev docs); the test DB gets it from
-- `make test-db-reset`.
CREATE EXTENSION IF NOT EXISTS pg_trgm;
