<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Onboarding a new country

A country is rows in the database, not code. Onboarding one is a single operator command on the
deployment's side, one confirmation, and then the worker host does the rest: seed the regions,
harvest the country, rebuild its tiles, repair the border data of its onboarded neighbours, check
elevation, and go live. Nothing in this repository changes, nothing is committed and nothing is
deployed.

Two data sources do two different jobs: the **Overture Maps `divisions` theme** (ODbL) draws the
region boundaries, and Geofabrik's OpenStreetMap extracts fill them with points
([Harvesting](harvesting.md)).

## The command

The deployment's runner (a make target and a systemd template unit in the operator's own
infrastructure repository, not described further here) strings together the commands this
repository provides:

| # | Step | Command | Container |
|---|------|---------|-----------|
| 1 | Plan, print it, ask for one confirmation | `python -m onboarding.plan DK` | pipeline |
| 2 | Seed regions, derive, re-stamp | `php bin/console app:country:apply DK` | worker |
| 3 | Harvest, repair neighbours, rebuild tiles | `python -m onboarding.repair DK` | pipeline |
| 4 | Elevation check | `php bin/console app:country:check-elevation DK` | worker |
| 5 | Go live | `php bin/console app:country:mark-live DK` | worker |

The planner only writes the plan and prints it; the confirmation belongs to the runner. Steps 2-5 run
unattended, one after the other; the first failure stops the rest and alerts like any other
scheduled job. Every step checks the status it needs and is safe to rerun, so a failed run is fixed
by starting it again; a failed elevation check is the exception (see *Elevation check* below).
Staging always goes first; production then receives staging's exact rows (see
*Promoting to production*).

A country moves through three states, stored in `country.status`:

- **planned**: a plan exists (`country_plan_region`); nothing a rider sees has changed.
- **seeded**: its `region` rows exist, so the harvest may run and its extracts count as onboarded.
- **live**: every step passed; only now does the country show on the map.

The configuration lives in four tables: `country` (name, status, timezones, labels), `country_extract`
(its Geofabrik extracts), `country_plan_region` (the plan) and `region` (the seeded regions, with
their `labels`). No YAML or source file lists countries; `make country-lists-check` fails when one
creeps back in.

## What the planner decides

The planner never asks. It applies fixed rules, prints what it decided, and stops with a reason when
a rule cannot decide. A seeded or live country is refused: its slugs are permanent identity. Every
rule has an override flag for the case where it cannot decide.

- **Level.** It probes Overture's `region` and `county` subtypes and picks the first whose median
  subdivision is between 1,000 and 60,000 km². A country under 5,000 km² becomes one region at
  level 2, the Luxembourg rule. Otherwise it stops and prints the probe table. Override:
  `--level county`.
- **Slugs.** From the native name: generic words such as *Region* or *Provincia* dropped,
  transliterated (`Sjælland` becomes `sjaelland`), lower case, dashes. A slug already used by another
  country gets `-<cc>` (`limburg-nl`). The country outline is the exception: it takes the English
  name (`denmark`, like `belgium`). Override: `--slug DK-84=hovedstaden`, where the key is the ISO
  code, or the native name for subdivisions without one. An override cannot take a slug another
  region already holds.
- **Labels.** Overture's name in English, French, Dutch, German and Spanish; where Overture has none,
  the native name stands in and the table marks it with `*`. The country phrase ("All Denmark") uses
  fixed templates per language. Fix one label later with `app:country:label` (below).
- **Extract.** The Geofabrik extract whose ISO country list is exactly that country, the shallowest
  one first. None, or two at the same depth, stops the plan. Override: `--extract europe/denmark`.
- **Neighbours.** Seeded or live countries whose regions lie within this distance of the new
  country, just over the 0.1026° buffer Geofabrik cuts its extracts with:

<!-- CODE-FROM pipeline/onboarding/neighbours.py -->
```python
NEIGHBOUR_DEG = 0.11
```

`--json` prints the plan as JSON instead of the table. On the dev stack, `make country-plan c=DK`
runs the planner against your local database (it needs the network for Overture and Geofabrik); pass
flags with `args="--level county"`. It writes the plan and nothing else.

## What each step does

**Apply** turns the plan into `region` rows in one transaction, with the same upsert, provenance
check and tessellation check the catalog import uses (`RegionUpserter`), then recomputes adjacency
and outlines, and re-derives membership, rider base areas and route surfaces (`RegionDerivations`)
for the rows within 0.11° of the new country and its neighbours only, writing a row only when its
value really changes. It re-stamps rider data for the same countries, and fills the country's
timezones (the map's home-country hint for visitors who are not signed in) from PHP's zone table
when it has none yet. It refuses a plan whose slug already belongs to another country. It waits at
most 10 seconds for a lock another writer holds (a harvest, a catalog import), then stops without
writing anything and says so; start it again once that writer is done. The full catalog import
still re-derives every row.

**Re-stamp** (`app:regions:restamp --countries=DK,DE`, also on its own; `--dry-run` counts without
writing, `--recount` refreshes the coverage counts afterwards) gives a submission the region and
country it now lies in when it had none or belonged to a listed country, and gives an item the
country of the region it lies in. Smallest containing region wins, the same rule `SpatialResolver`
applies when a rider submits.

**Repair** harvests the new country's extracts, re-harvests every neighbour's extracts with full
membership (their border rows must be re-owned now that new regions sit next to them), rebuilds the
point tiles, then the route and surface tiles of the new country and its neighbours. Each is one
call of the documented `coverage.run` command. When the nightly harvest holds the coverage, routes or
surface lock, repair exits with code 2 and says to start it again later.

**Elevation check** samples 25 points inside the country and asks the elevation instance
`ElevationEndpoints` picks for each. It fails when more than 80 % of the answers are null or exactly
0, the two silent ways Valhalla says "no tiles here". An instance that does not answer at all (a
timeout, a refused connection, an HTTP error) is a different failure: the check stops at the first
one with `elevation instance unreachable: <endpoint>`, so fix or start that instance rather than
installing tiles. Missing tiles are fixed by a DEM install on the routing host, which
[Building elevation tiles](elevation-tiles.md) owns: install, restart that continent's instance and
check that the instance is listed in `ELEVATION_URLS`. Then finish by hand instead of starting the
whole run again, which would repeat the harvest:

<!-- CODE-ILLUSTRATIVE finish an onboarding after a DEM install -->
```bash
php bin/console app:country:check-elevation DK
php bin/console app:country:mark-live DK
```

**Mark live** records that every step passed, and only on a seeded country.

!!! note "A country is on the map once it is live"
    The region registry behind the scope selector and the timezone hint serve a country's regions
    only once its status is `live`, so a seeded country whose harvest or elevation check has not
    passed yet is not shown. Moderation and the harvest already see it from `seeded` on. Regions of a
    country that has no `country` row at all are always shown.

## Promoting to production

Production never queries Overture. `app:country:export DK` writes the live staging country (its
`country` row, extracts and regions, geometry as GeoJSON) to stdout, and `app:country:import-plan`
reads it on production (from stdin, or `--file`) into a plan. The same apply, repair, check and live
sequence then runs there, so production gets staging's exact slugs, labels and borders.

## Fixing a label

Region and country labels are rows (`region.labels`, `country.labels`), so the in-site translation
tool does not reach them. Translations it had approved before the labels moved were folded into these
rows by a migration. Edit a label with:

<!-- CODE-ILLUSTRATIVE fix one region label and one country phrase -->
```bash
php bin/console app:country:label hovedstaden nl 'Hoofdstedelijke Regio'
php bin/console app:country:label DK fr 'Danemark (tout le pays)'
```

A display label falls back to English, then to the region's name, so a missing one never shows a
raw key.

## Deploy order

A release that adds onboarding tables ships web migrations first, then the new pipeline image: the
planner and the coverage commands read the tables the migrations create.

## What stays manual

- **Curators.** A new country's regions start without curators; `/join/<cc>` switches to the curator
  form once regions exist. Until somebody is scoped, the country is live on the map and invisible in
  moderation.
- **A new UI language.** Onboarding Denmark does not add Danish.
- **Official data providers.** Separate, per-licence work.
- **A shared Geofabrik extract.** The planner takes an extract whose ISO list is exactly the one
  country, so a country Geofabrik ships only together with others cannot be onboarded by the
  command: `europe/ireland-and-northern-ireland` covers Ireland and is already GB's, and
  `africa/south-africa` bundles Lesotho and Eswatini. Such a country needs a hand-made plan and a
  decision on which country owns the shared extract.
- **Part of a country.** The planner plans a whole country at one level. The two US states and two
  Canadian provinces were seeded by hand before the planner existed; adding a third state is not
  something the planner does.

## Try it

!!! tip "Hands-on: read a country's state, then its rows"
    `php bin/console app:country:status` prints one line per country: status, when it was planned,
    seeded and made live, its region count and its extracts. Then look at the rows themselves:

    <!-- CODE-ILLUSTRATIVE verify a country's rows -->
    ```sql
    SELECT c.code, c.status, e.slug AS extract,
           (SELECT count(*) FROM region r WHERE r.country_code = c.code) AS regions
    FROM country c JOIN country_extract e ON e.country_code = c.code
    WHERE c.code = 'NL';
    ```

    Exactly one region of the country must be level 2: the country outline every scoped query falls
    back to. `make -s coverage-regions` lists every onboarded extract, straight from these tables.

## Where to go deeper

- [`pipeline/divisions/README.md`](https://github.com/cycling-commons/cycling-commons/blob/main/pipeline/divisions/README.md):
  Overture provenance, the operational-versus-infrastructure rule for level-2 rows, and the notes of
  every country onboarded so far.
- [Harvesting](harvesting.md): what the repair step's harvest does.
