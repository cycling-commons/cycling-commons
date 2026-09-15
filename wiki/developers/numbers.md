<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# The numbers these courses quote

Every course on this site argues from measurements. That is deliberate: a page that says "we keep
only the tags we need" teaches nothing, and a page that says "4,220 keys reach the parser and 33
survive it" teaches the whole idea in one line.

The cost is that those figures are typed into prose, often into several pages at once, and prose is
the one thing this wiki's gates could not read. `check_date` joined the stored keys on 2026-09-10
and chapter 6 went on saying 32 in five places and a figure. So the numbers live here now, once, and
the pages point at this table rather than repeating it.

## Derived from the repository

Everything in this table is extracted from a checkout by `tools/wiki-numbers.py`, which regenerates
the block below and fails if it has drifted. Nothing here is typed by hand, so nothing here can be
stale without the build saying so.

<!-- BEGIN GENERATED NUMBERS: tools/wiki-numbers.py -->

| id | value | what it is | derived from |
|---|---|---|---|
| `catalog-letters` | **12** | editable catalog types, each with one letter | `web/src/Catalog/ItemType.php` |
| `selector-rules` | **42** | OSM tag rules that decide whether an object is worth keeping | `pipeline/contract/coverage-contract.json` |
| `stored-tag-keys` | **34** | tag keys the harvest keeps on a coverage row; every other key is dropped at parse time | `pipeline/contract/coverage-contract.json` |
| `drawer-tag-whitelist` | **23** | of those keys the POI drawer is allowed to render | `web/src/Coverage/CoverageRepository.php` |
| `map-modules` | **46** | JavaScript modules the map is split into | `web/assets/map/` |
| `map-js-lines` | **312** | lines in `map.js` itself, which is imports plus the boot sequence | `web/assets/map/map.js` |
| `geofabrik-regions` | **22** | Geofabrik extracts the harvest runs by default | `developers/docker/compose.yaml` |
| `seeded-pins` | **30** | hand-authored pins `make course-data` seeds | `web/src/Catalog/Command/SeedManualCatalogCommand.php` |
| `api-rate-limit-per-minute` | **120** | requests a minute per client address on the public API | `web/config/packages/rate_limiter.yaml` |
| `api-bbox-max-degrees` | **10.0** | widest bbox `/v1/search` accepts, on either axis | `web/src/Controller/Api/V1/PublicApiController.php` |
| `api-limit-default` | **100** | features `/v1/search` returns when `limit` is not given | `web/src/Controller/Api/V1/PublicApiController.php` |
| `api-limit-max` | **500** | features `/v1/search` will return at most | `web/src/Controller/Api/V1/PublicApiController.php` |
| `climb-window-m` | **250** | metres in the sliding window the steepest-stretch figure is measured over | `web/src/Elevation/ClimbProfiler.php` |
| `climb-percentile` | **0.95** | percentile of those windows that gets published, rather than the maximum | `web/src/Elevation/ClimbProfiler.php` |
| `climb-samples` | **200** | elevation samples per climb, a fixed count, so spacing grows with length | `web/src/Elevation/ClimbProfiler.php` |
| `route-surface-buffer-m` | **25** | metres either side of a route line that count as on it, for surface attribution | `web/src/Catalog/SurfaceProfiler.php` |
| `dem-tile-bytes` | **25,934,402** | bytes in one 1 arc-second `.hgt` tile: 3601 x 3601 samples, 2 bytes each | arithmetic on the SRTM/GLO-30 tile shape |

<!-- END GENERATED NUMBERS -->

## Measured, not derived

These cannot come out of a checkout. They are counts in a live database, wall-clock timings, or the
size of a real harvest, and every one of them carries the date it was taken and the command that
takes it again. Treat the date as part of the number: quoting one of these without it is how a page
goes quietly wrong.

| what it is | value | taken | how to take it again |
|---|---|---|---|
| Rows in `coverage_poi`, all onboarded countries | 2,063,788 | 2026-09-10 | `SELECT count(*) FROM coverage_poi;` |
| Countries onboarded | 19 | 2026-09-10 | `SELECT count(DISTINCT country_code) FROM region;` |
| Region rows | 271 | 2026-09-10 | `SELECT count(*) FROM region;` |
| `item` rows on a fresh clone after `make course-data` | 736 | 2026-09-10 | build an empty database, run the target, `SELECT count(*) FROM item;` |
| Of which seeded by hand | 30 | 2026-09-10 | `make course-data` prints it; `tools/check-course-data.py` verifies it |
| Import rows the duplicate guard holds out | 84 | 2026-09-10 | the count `app:catalog:import` reports as skipped |
| `coverage_poi` rows the course fixture builds | 10 | 2026-09-10 | `make course-data`, then count the table |
| Ride-check corridor query, before and after the rewrite | 62 s to under 1 s | 2026-07 | `docs/specs/` design note for the rewrite |
| Radius query, sequential scan against functional index | 716 ms to 2 ms | 2026-07 | `pipeline/coverage/load.py` records both |
| Tag payload dropped by narrowing the stored keys | 73 MB to 35 MB | 2026-07 | one Belgian extract, parsed both ways |
| One Geofabrik extract, downloaded against stored | 4.8 GB to 28 MB | 2026-08 | `make coverage-refresh regions=europe/belgium` |
| Surface grid, whole-Benelux at two resolutions | 0.6 MB and 34.8 MB | 2026-08 | build the surface arm at each zoom floor |
| Valhalla cold start, six continents of DEM | 101 s | 2026-08 | restart the routing container and time it |
| Gzipped DEM tiles, read cost against raw | 2.6x slower | 2026-08 | `docs/specs/climb-elevation.md` |
| GLO-30 Europe tile set | 1137 tiles, 28 GB | 2026-08 | `tools/elevation/README.md` |

## Using this page

Cite a number, do not copy it. A sentence like "the harvest keeps 33 of the tags an object carries"
should link here, so that when the contract gains a key there is one page to change and one gate
that notices. If you find yourself typing a figure that already appears above, link instead.

If a number belongs in the first table but is not there yet, add an extractor to
`tools/wiki-numbers.py` rather than a row to the page. The page is generated; a row typed into it by
hand will be deleted the next time the gate runs.
