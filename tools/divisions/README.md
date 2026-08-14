# tools/divisions — region onboarding + Overture exporter

Region source for the Symfony catalog importer (`app:catalog:import`), from the
**Overture Maps `divisions` theme** (`division_area`), plus the tooling that
makes onboarding a new country or state a repeatable process.

Every onboarding run seeds **levels 2 + 4 by default**: the Overture
`admin_level=2` country outline alongside the configured operating level
(L4 unless a country's `COUNTRY_CONFIG` says otherwise). This is a deliberate owner decision — see
"Operational vs infrastructure rows" below for why the L2 row exists and
where it must never appear.

## Onboarding a country or state (the playbook)

One fixed sequence for every country/state. ⚑ marks a human judgment.

| # | Step | Command |
|---|------|---------|
| 1 | ⚑ Choose the operating level | `make region-probe c="NL"` → read `web/var/scaffold/nl/areas.md` |
| 2 | Scaffold config + label stubs | `make region-scaffold c="NL" flags="--probe-areas"` |
| 3 | ⚑ Review + merge | freeze slugs/exonyms; merge `config-block.py` into `config.py`, `translations.patch.yaml` into the 4 catalogs |
| 4 | Export Overture geojson (levels 2 + 4) | `make divisions-data c="NL"` — always emits the L2 country outline alongside the operating-level divisions, same run, same command |
| 5 | Seed `Region` rows | stage artifacts (including the L2 outline), `app:catalog:import` (see below) — upsert-by-slug, so re-running is safe |
| 6 | Coverage | add the Geofabrik region to `COVERAGE_REGIONS` **and** `COUNTRY_BY_REGION` (`pipeline/coverage/load.py`) — a missing entry now hard-fails that region's coverage run (`resolve_country`, the nearest-region-wins ownership rule) rather than silently disabling ownership — then `make coverage-refresh regions=europe/netherlands` |
| 6b | **Elevation** | check the country's box already has GLO-30 tiles, and install them if not: `tools/elevation/dem-install.sh <continent> <PRESET>` **on the Valhalla host**. See "Elevation is step 6b" below |
| 6c | **The other tile artifacts** | `make surface-tiles regions=<geofabrik>` and `make routes-tiles regions=<geofabrik>`. Coverage is not the only per-country tile set. See below |
| 7 | ⚑ Moderators | assign 2–4 region atoms per moderator (admin; `moderator_area` rows) |
| 8 | Specs | record the rollout in `docs/specs/` (region-scoping §7, coverage-provider) |

### Step 6c: coverage is not the only per-country tile set

**Added 2026-08-14, after the same question was asked about it.** Step 6 builds
`coverage.pmtiles`, the POI plane. Two more tile sets are built per country and
neither is in step 6:

| Artifact | Command | What a country without it loses |
|---|---|---|
| Road surface (3 files: classified, to-do, gaps) | `make surface-tiles regions=<geofabrik>` | the Surfaces skin is blank there: no road-surface colouring, no "needs recording" arm, no gap grid |
| Cycle-route network | `make routes-tiles regions=<geofabrik>` | no node networks, no numbered junctions, no named routes (RAVeL, knooppunten, EuroVelo) |

Both read the same Geofabrik extract as coverage and take the same `regions=`
list, so onboarding a country is three builds, not one.

**Check what the manifests already carry before assuming:** each publishes a
`country_codes` list, so the honest test is whether the new country is in it.

```bash
curl -s "$COVERAGE_PUBLIC_BASE_URL/surface/manifest.json" | python3 -m json.tool | grep -A20 country_codes
curl -s "$COVERAGE_PUBLIC_BASE_URL/routes/manifest.json"  | python3 -m json.tool | grep -A20 country_codes
```

On 2026-08-14 both listed exactly the twelve countries onboarded before that
day, which is what makes this a step rather than a note: every older country
has all three artifacts, so a new country missing two of them is invisible by
comparison, and the failure looks like "the surface layer is broken in Chile"
rather than "nobody built it".

### Elevation is step 6b, not an afterthought

**Added 2026-08-14 because it was missing.** Seven countries onboarded that day
and every one of them but Slovenia had no elevation data, which nobody noticed
until the owner asked — the procedure existed only as scripts on the host and
lines in a shell history, so each rollout rediscovered it.

**A country with no DEM gets no climb profiles.** Not wrong ones: an
`ElevationEndpoints` box with no configured instance falls back to the default,
which answers `null` for ground it does not hold, and `ElevationClient` refuses
any reply with a non-numeric sample. So the failure is silent and honest, which
is exactly why it survives a rollout unnoticed.

**Check before you fetch** — the box may already cover the country. Slovenia
needed nothing because the EUROPE tile set already reaches it. One request
against a summit whose height you know settles it:

```bash
curl -sG --data-urlencode 'json={"shape":[{"lat":46.06,"lon":14.51}]}' \
  http://127.0.0.1:8002/height        # Ljubljana -> 299, so Slovenia is covered
```

`null` means no tile. Then, **on the Valhalla host**:

```bash
tools/elevation/dem-install.sh africa RWANDA SOUTHAFRICA
```

which fetches the GeoTIFFs, converts them to `.hgt` in the `osgeo/gdal`
container (the host has no GDAL on purpose — it is a routing box, and the
container is capped with `--cpus` so live routing keeps its share), and moves
the result into that instance's `elevation_data`. Presets live in
`fetch-glo30.sh`; add one per country rather than passing raw bboxes, so the
next person inherits the box you worked out.

**Two traps this step carries.**
- **The preset's upper bounds must be one degree PAST the ground you want**, because
  a cell is named for its south-west corner and the fetch loop runs
  `seq LAT0 $((LAT1-1))`. Three presets were wrong on first write — Rwanda's
  northern strip, Colombia's Caribbean coast, Chile's Cape Horn — and the gap
  only ever shows up later as one climb with no profile.
- **A continent with tiles the app never asks for is the same as no tiles.**
  `ELEVATION_URLS` must name the instance, or the box falls through to the
  default. `africa` and `south-america` instances existed and were empty AND
  unlisted for months.

**Operating-level rule (tools/divisions/README.md):** seed at the
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
- add the country's IANA timezone(s) to `TZ_COUNTRY` in `web/assets/map/scope.js` — the cold-start home-country
  guess silently resolves to nothing for the new country's visitors otherwise,
  and no test catches the gap.

## Onboarded so far

| Date | Country | Level | Rows | Notes |
|---|---|---|---|---|
| — | BE | L4 régions | 3 | first |
| 2026-07-22 | NL | L4 provincies | 12 | `limburg-nl` — BE also has a Limburg |
| 2026-07-22 | DE | L4 Bundesländer | 16 | first continental-scale run |
| — | LU | L2 country | 1 | operating subtype is `country` |
| 2026-08-06 | FR / CH / GB / IT / AU / JP | régions / cantons / nations / regioni / states / prefectures | 13 / 26 / 4 / 20 / 8 / 47 | |
| 2026-08-06 | US | L4, `--only` | 2 | California + Colorado; first state-level run |
| 2026-08-08 | **ES** | L4 comunidades autónomas | **19** | ISO 3166-2:ES exactly |
| 2026-08-14 | **SI** | **L2 country** | **1** | second country to operate at L2 — see below |
| 2026-08-14 | RW | L4 provinces | 5 | native Kinyarwanda slugs — see below |
| 2026-08-14 | ZA | L4 provinces | 9 | extract bundles Lesotho + Eswatini |
| 2026-08-14 | CO | L4 departamentos + D.C. | 33 | three slugs suffixed `-co` |
| 2026-08-14 | CL | L4 regiones | 16 | three official long forms shortened |
| 2026-08-14 | NZ | L4 regions | 17 | straddles the antimeridian |
| 2026-08-14 | CA | L4, `--only` | 2 | British Columbia + Québec |

**The 2026-08-14 rollout (7 countries, 83 operating rows, three new
continents).** Five judgment calls worth not re-deriving:

**Slovenia is the second country to operate at L2**, and only the second after
Luxembourg. Its ISO 3166-2 level is 212 občine of 7–555 km² (probe:
`web/var/scaffold/si/areas.md`) with no intermediate level carrying ISO
identity — the 12 statistical regions are NUTS-3, not administrative. Slovenia
itself is **20,274 km², inside the advisory band**, so the country is a region
of exactly the right size. Synthetic macro-regions were the alternative and
this README reserves those for when no official level fits; here one does.

**Rwanda takes NATIVE Kinyarwanda slugs** (`iburasirazuba`, `amajyaruguru`,
`iburengerazuba`, `amajyepfo`) against the usual established-English-exonym
preference, because Rwanda's English names are *Eastern*, *Northern*,
*Western* and *Southern* — words belonging to no country in particular. A slug
is permanent GLOBAL identity, so `eastern` would need a suffix the first time
any other country onboards a compass-named division. `kigali` is the exception
in the other direction: the city's name is the same in every language.

**Colombia suffixes three slugs for collisions with countries nobody has
onboarded** — `amazonas-co` (Brazil, Peru and Venezuela all have one),
`cordoba-co` (Argentina) and `bolivar-co` (Venezuela). This follows
`la-rioja-es`, which was suffixed for Argentina's La Rioja while AR was, and
still is, unonboarded: a slug cannot change later without orphaning its row,
so the collision is answered when it is seen rather than when it bites.

**Chile and New Zealand needed the review step for exactly what it is for.**
The scaffolder emitted `aisen-del-general-carlos-ibanez-del-campo`,
`libertador-general-bernardo-o-higgins` and `region-metropolitana-de-santiago`
— official long forms nobody says — now `aysen`, `o-higgins` and
`region-metropolitana`. New Zealand's `hawke-s-bay` was an apostrophe
artifact, not a name, and `greater-wellington` is the regional COUNCIL;
ISO NZ-WGN is Wellington.

**New Zealand's bbox is 355° wide on purpose.** The Chatham Islands sit at
~176.5°W while the mainland ends at 178.7°E, so the country straddles the
antimeridian and no honest `[xmin…xmax]` box is narrow. A mainland-only box
reads faster and silently drops NZ-CIT, leaving a hole in the country's
tessellation. It is a read predicate for the Overture export only — it costs
one slower scan at onboarding time and never touches coverage ownership.

**Morocco was the first choice for Africa and was swapped for Rwanda.**
Overture returns 12 ISO-coded régions plus an **uncoded** Western Sahara
polygon (268,025 km²), and two of the 12 — Laâyoune-Sakia El Hamra and
Dakhla-Oued Ed-Dahab — lie inside that territory. The importer requires an ISO
code, so the uncoded row would have dropped automatically by the
`Plazas de Soberanía` precedent, which has the effect of publishing one side
of a territorial dispute on a public map without anyone deciding to. That is
an owner decision, not a default; it was asked and answered by choosing a
country with no such question (owner, 2026-08-14).

**Spain (2026-08-08).** The 17 comunidades autónomas plus Ceuta and Melilla.
`Plazas de Soberanía` is deliberately not seeded — 1 km², no ISO 3166-2 code, so
no stable identity to upsert on. Slugs are native forms (the German precedent),
with `la-rioja-es` carrying a suffix because Argentina's AR-F is also La Rioja:
AR is not onboarded, but a slug is permanent identity and the `limburg-nl`
precedent says act on the collision warning when it is raised, not later.

Eight of the scaffolder's slugs were rewritten by hand. It builds them from
Overture's dual-language labels and inverted-comma sort forms, which yields
`catalunya-cataluna`, `galicia-galicia`, `illes-balears-islas-baleares`,
`murcia-region-de`, `madrid-comunidad-de`, `navarra-comunidad-foral-de`,
`asturias-principado-de` and `valenciana-comunidad` — none of them a name
anyone uses. This is what review step 3 is for.

Spain also needed three timezones in `TZ_COUNTRY` rather than one:
`Europe/Madrid`, `Atlantic/Canary` (the Canaries run an hour behind) and
`Africa/Ceuta`.

## Operational vs infrastructure rows

Every onboarded country now carries at least two `region` rows for the same
country: the L2 country outline and the L4 (or configured) operating-level
divisions. Only one set is meant to be operational at a time.

**The rule: a region row is
*operational* iff its `admin_level` equals the deepest onboarded level for its
country.** For BE/NL/DE the L4 rows are operational; the L2 country outline is
**infrastructure only** — it anchors pre-onboarding evidence submissions and
provides the country polygon, but it must never appear on a public or
moderation listing surface (map scope selector, `/regions`, moderation area
pickers, curated-readiness reports, …). The rule is enforced centrally by
`web/src/Catalog/OperationalRegions.php`, which every region-listing consumer
must call or be an explicitly documented exemption from. Two readers are
exempt by design: the catalog importer's membership recompute (containment
across mixed levels — smallest-area-wins — is the point) and `RegionResolver`
(L2 must stay matchable so evidence in not-yet-subdivided countries anchors
somewhere).

The rule is **derived**, never stored: no flag, no playbook step, nothing to
go stale. When a country later onboards a finer level (see below), its
previous operating level demotes to infrastructure automatically the moment
the finer rows land.

A country whose **only** `region` row is its L2 outline is thereby
operational by the same rule (the LU precedent) and will appear on
`/regions` — so importing the L2 outline alone for a not-yet-onboarded
country makes that country public. Import the L2 outline only as part of a
full onboarding run (step 4 above), or together with its subdivisions —
never on its own ahead of the rest of the playbook.

**Finer levels (6+) stay a per-country decision, on evidence.** Levels 2 and 4
are the default for every country; going deeper (e.g. ~3,000 US counties) is a
separate, per-country `COUNTRY_CONFIG` setting made only when a country's
scale or shape demands it, not a default applied everywhere.

## What the exporter produces

One `region-<slug>.geojson` per seeded subdivision **plus** one for the L2
country outline, each a single GeoJSON `Feature` whose `properties` carry the
provenance the importer requires:

```json
{ "slug": "flanders", "name": "Flanders", "area_km2": 13522,
  "country_code": "BE", "iso_code": "BE-VLG", "admin_level": 4,
  "source": "overture" }
```

The L2 country-outline artifact looks the same, just at the country level and
with the plain-English country slug (`belgium`, matching LU's `luxembourg`):

```json
{ "slug": "belgium", "name": "Belgium", "area_km2": 30669,
  "country_code": "BE", "iso_code": "BE", "admin_level": 2,
  "source": "overture" }
```

- `country_code` is **required** by the importer — an unstamped region is a
  silent moderation-jurisdiction hole (map-and-search.md §4.5 risk 1).
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
docker exec cycling-commons-dev-app-1 php -d memory_limit=2G \
  bin/console app:catalog:import /app/var/catalog-out
```

**`-d memory_limit=2G` is not optional past a handful of countries.** The import
sends every region's geometry as a query parameter, and in the dev environment
Doctrine's SQL logger retains all of them for the profiler — 162 regions is
~101 MB of GeoJSON, which exhausts the default 128 MB limit part-way through
and dies with a fatal error. The whole import runs in one transaction, so a
crash rolls back cleanly and re-running is safe; it is a limit to raise, not
damage to repair.

## Tests

```bash
cd tools && python3 -m pytest divisions/tests -q          # offline
RUN_LIVE_OVERTURE=1 python3 -m pytest divisions/tests -q  # + live Overture smoke
cd web && php bin/phpunit --filter ScaffoldRegionsCommandTest
```
