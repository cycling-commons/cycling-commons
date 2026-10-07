# pipeline/divisions: Overture divisions access

The Overture Maps `divisions` theme (`division_area`) code the onboarding
planner uses (`python -m onboarding.plan`, pipeline/onboarding/): DuckDB reads
of the public release, geodesic areas, the area probe, and the candidates
exporter that fills `world_division`. The pipeline image installs DuckDB's
httpfs and spatial extensions at build time.

How a country is onboarded, end to end:
[wiki: Onboarding a new country](../../wiki/developers/data-ops/onboarding-a-country.md).

Every onboarded country carries its level-2 outline beside its operating level;
see "Operational vs infrastructure rows" below for why it exists and where it
must never appear.

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

Spain also needed three timezones (the country's `timezones`) rather than one:
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

The rule is **derived**, never stored: no flag, no onboarding step, nothing to
go stale. When a country later onboards a finer level (see below), its
previous operating level demotes to infrastructure automatically the moment
the finer rows land.

A country whose **only** `region` row is its L2 outline is thereby
operational by the same rule (the LU precedent) and will appear on
`/regions` — so importing the L2 outline alone for a not-yet-onboarded
country makes that country public. Import the L2 outline only as part of a
full onboarding run (`app:country:apply` seeds them together), or together with its subdivisions —
never on its own ahead of the rest of the onboarding.

**Finer levels (6+) stay a per-country decision, on evidence.** Levels 2 and 4
are the default for every country; going deeper (e.g. ~3,000 US counties) is a
separate, per-country decision (`--level` on the planner) made only when a country's
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
  `region.labels` column (5 locales; edit with `app:country:label`).
- `slug` is **identity** (upsert-by-slug). Never change a slug — it orphans the
  region row. The planner assigns slugs and stores them in `country_plan_region`.
- Geometry is stored as `MultiPolygon` (single polygons are promoted).

## Provenance / licence

Overture `divisions` is **ODbL** (conflates OSM + geoBoundaries, carries
ISO 3166-1/-2). Regeneration hits the **public, anonymous** Overture S3 bucket
for the release pinned in `config.OVERTURE_RELEASE`. Bump that constant
deliberately — a newer release may shift boundaries, which is a versioned
re-import event (slugs/ISO codes stay identity; region rows are never deleted).

## Re-export a live country (an Overture release bump)

```bash
make divisions-data c="NL"     # pipeline container -> pipeline/divisions/out/region-*.geojson
```

The spec (subtype, ISO code to slug, names, box) comes from the country's live
`country` and `region` rows, so a re-export keeps every slug. Import the files
with `app:catalog:import` (pass `-d memory_limit=2G` past a handful of countries:
the dev SQL logger keeps every geometry parameter).

## Tests

```bash
make pipeline-test                                              # offline
docker compose -f developers/docker/compose.yaml exec -T -e RUN_LIVE_OVERTURE=1 pipeline python -m pytest tests -q -k live
```
