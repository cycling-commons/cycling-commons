# tools/divisions — region onboarding + Overture exporter

Region source for the Symfony catalog importer (`app:catalog:import`), from the
**Overture Maps `divisions` theme** (`division_area`), plus the tooling that
makes onboarding a new country or state a repeatable process
(`docs/specs/2026-07-22-country-onboarding-design.md`).

## Onboarding a country or state (the playbook)

One fixed sequence for every country/state. ⚑ marks a human judgment.

| # | Step | Command |
|---|------|---------|
| 1 | ⚑ Choose the operating level | `make region-probe c="NL"` → read `web/var/scaffold/nl/areas.md` |
| 2 | Scaffold config + label stubs | `make region-scaffold c="NL" flags="--probe-areas"` |
| 3 | ⚑ Review + merge | freeze slugs/exonyms; merge `config-block.py` into `config.py`, `translations.patch.yaml` into the 4 catalogs |
| 4 | Export Overture geojson | `make divisions-data c="NL"` |
| 5 | Seed `Region` rows | stage artifacts, `app:catalog:import` (see below) |
| 6 | Coverage | add the Geofabrik region to `COVERAGE_REGIONS` **and** `COUNTRY_BY_REGION` (`pipeline/coverage/load.py`) — a missing entry now hard-fails that region's coverage run (`resolve_country`, 2026-07-23-border-overlap-ownership-design.md §3) rather than silently disabling ownership — then `make coverage-refresh regions=europe/netherlands` |
| 7 | ⚑ Moderators | assign 2–4 region atoms per moderator (admin; `moderator_area` rows) |
| 8 | Specs | record the rollout in `docs/specs/` (region-scoping §7, coverage-provider) |

**Operating-level rule (country-onboarding-design.md §3):** seed at the
official administrative level whose subdivisions are of reasonable riding
size, preferring legibility + stable ISO 3166-2 identity over an exact match
with the ~17k km² band (ADVISORY — Brussels sits far below it). Small official
regions are fine: moderation composes upward (one moderator holds 2–4 atoms).
Group into synthetic macro-regions ONLY when no official level fits.

**State-level onboarding:** pass `--only` to the scaffolder
(`php bin/console app:region:scaffold US --only=US-CA`) and a Geofabrik
sub-region to coverage (`north-america/us/california`) — the rest of the
country onboards later, demand-driven.

**Review step 3 in detail:** the scaffolder EMITS, NEVER APPLIES. You must
- freeze each **slug** (permanent identity — upsert-by-slug): established
  English exonym where one truly exists (`wallonia`, `flanders`), else the
  native form (`noord-holland`); act on every collision warning
  (NL example: `limburg-nl`, because BE also has a Limburg);
- fix **exonyms** in all four locales and delete the `# TODO exonym?` markers
  (sokil ships English msgids only, so no locale is pre-localized);
- add the `all_<cc>` country rung the map scope selector needs;
- add the country's IANA timezone(s) to `TZ_COUNTRY` in `web/assets/map/scope.js`
  (2026-07-22-scope-selector-scale-design.md §D) — the cold-start home-country
  guess silently resolves to nothing for the new country's visitors otherwise,
  and no test catches the gap.

## What the exporter produces

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
  `messages` translations domain (`region.<slug>.label`, 4 locales).
- `slug` is **identity** (upsert-by-slug). Never change a slug — it orphans the
  region row. The ISO→slug map lives in `config.py`.
- Geometry is stored as `MultiPolygon` (single polygons are promoted).

## Provenance / licence

Overture `divisions` is **ODbL** (conflates OSM + geoBoundaries, carries
ISO 3166-1/-2). Regeneration hits the **public, anonymous** Overture S3 bucket
for the release pinned in `config.OVERTURE_RELEASE`. Bump that constant
deliberately — a newer release may shift boundaries, which is a versioned
re-import event (slugs/ISO codes stay identity; region rows are never deleted).

## Run the export + import (playbook steps 4–5)

```bash
make divisions-data c="NL"        # → tools/divisions/out/region-*.geojson
mkdir -p web/var/catalog-out
cp tools/divisions/out/region-<each-new-slug>.geojson web/var/catalog-out/
docker exec cycling-commons-dev-app-1 php bin/console app:catalog:import /app/var/catalog-out
```

## Tests

```bash
cd tools && python3 -m pytest divisions/tests -q          # offline
RUN_LIVE_OVERTURE=1 python3 -m pytest divisions/tests -q  # + live Overture smoke
cd web && php bin/phpunit --filter ScaffoldRegionsCommandTest
```
