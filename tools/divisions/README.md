# tools/divisions — Overture administrative-region exporter

Emits `region-<slug>.geojson` artifacts for the Symfony catalog importer
(`app:catalog:import`) from the **Overture Maps `divisions` theme**
(`division_area`). This is the worldwide-ready region source described in
`docs/specs/2026-07-19-region-scoping-design.md` §3 / §7 Phase 2; it replaces the
Wallonia-only OSM harvest that lived in `tools/wallonia/export.py` (a test
scaffold).

## What it produces

One `region-<slug>.geojson` per seeded subdivision, each a single GeoJSON
`Feature` whose `properties` carry the provenance the importer requires:

```json
{ "slug": "flanders", "name": "Flanders", "area_km2": 13522,
  "country_code": "BE", "iso_code": "BE-VLG", "admin_level": 4,
  "source": "overture" }
```

- `country_code` is **required** by the importer — an unstamped region is a
  silent moderation-jurisdiction hole (region-scoping-design.md §3, §8 risk 1).
- `name` is an English placeholder; the rider-facing label comes from the
  `messages` translations domain (`region.<slug>.label`, 4 locales), because
  Overture's `names.primary` is localized/bilingual.
- `slug` is **identity** (upsert-by-slug). Never change a slug — it orphans the
  region row. The ISO→slug map lives in `config.py`.
- Geometry is stored as `MultiPolygon` (single polygons, e.g. Brussels, are
  promoted).

## Provenance / licence

Overture `divisions` is **ODbL** (conflates OSM + geoBoundaries, carries
ISO 3166-1/-2). Same open licence as the OSM boundaries it supersedes.

## Run

```bash
cd tools
python3 -m pip install -r divisions/requirements.txt      # duckdb
python3 -m divisions.export_divisions --country BE --out divisions/out
```

Regeneration hits the **public, anonymous** Overture S3 bucket for the release
pinned in `config.OVERTURE_RELEASE` (currently `2026-06-17.0`). Bump that
constant deliberately — a newer release may shift boundaries, which is a
versioned re-import event (slugs/ISO codes stay identity; region rows are never
deleted).

Then stage into the catalog import dir and import (see
`docs/specs/catalog-data-model.md`):

```bash
cp divisions/out/region-*.geojson ../web/var/catalog-out/
docker exec cycling-commons-dev-app-1 php bin/console app:catalog:import /app/var/catalog-out
```

## Adding a country (worldwide rollout, region-scoping-design.md §5a)

Add a block to `COUNTRY_CONFIG` in `config.py`: its operating-level Overture
`subtype` (one level per country — the tessellation invariant), the explicit
ISO 3166-2 → slug map for the regions to seed, English placeholder names, and an
optional bbox for fast reads. Seeding is demand-driven (a curator appears);
`admin_level` derives from the subtype.

## Tests

```bash
cd tools && python3 -m pytest divisions/tests -q          # offline
RUN_LIVE_OVERTURE=1 python3 -m pytest divisions/tests -q  # + live Overture smoke
```
