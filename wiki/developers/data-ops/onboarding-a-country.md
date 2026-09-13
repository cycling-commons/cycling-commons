<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Onboarding a new country or region

Adding a country to the map is a fixed sequence: seed its **regions** (the scope-selector areas and
moderation jurisdictions), wire them up, then let the [harvest](harvesting.md) fill them with POIs.
This page is the practical walk-through; the tool reference is
[`tools/divisions/README.md`](https://github.com/cycling-commons/cycling-commons/blob/main/tools/divisions/README.md),
and the coverage side of what onboarding feeds is
[coverage-provider.md](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/coverage-provider.md).

The regions come from the **Overture Maps `divisions` theme** (ODbL, it conflates OSM and
geoBoundaries and carries ISO 3166 codes), not from the OSM harvest. Two different data sources for
two different jobs: Overture draws the boundaries, Geofabrik fills them with points.

## The playbook

One sequence for every country. ⚑ marks a human judgment call.

| # | Step | What happens |
|---|------|--------------|
| 1 | ⚑ **Choose the operating level** | `make region-probe c="XX"` lists the country's official subdivisions and their sizes. You decide which level to seed at (see below). |
| 2 | **Scaffold** | `make region-scaffold c="XX"` emits a config block + label stubs. It *emits, never applies*. |
| 3 | ⚑ **Review & merge** | Freeze slugs (permanent identity), fix exonyms in every locale, add the `all_<cc>` rung, add the timezone. |
| 4 | **Export** | `make divisions-data c="XX"` queries Overture and writes one `region-<slug>.geojson` per subdivision **plus** one for the level-2 country outline, in the same run. |
| 5 | **Seed** | Stage the artifacts, the country outline included, and import them as `Region` rows. |
| 6 | **Coverage** | Add the Geofabrik region to the harvest, then run it. |
| 6b | **The other tile sets** | Coverage is not the only per-country artifact: build the road-surface and cycle-route tiles too. Same extracts as step 6, so they follow it directly. |
| 6c | **Elevation** | Check the country's box already has DEM tiles; install them if not. Runs on the routing host, not this one, which is why it comes last. |
| 7 | ⚑ **Moderators** | Assign region atoms to moderators. |
| 8 | **Specs** | Record the rollout. |

## Step 1: the operating-level decision

The rule: seed at the **official administrative level whose subdivisions are a reasonable riding size**,
preferring a stable ISO 3166-2 identity. Small official regions are fine, moderation composes
upward, one moderator can hold several. Only invent synthetic macro-regions when *no* official level
fits.

But an official level can also be *too fine*. **Luxembourg** is the worked example: its only official
subdivision level is 12 cantons, all 78–343 km², an order of magnitude below every other onboarded
region, and finer scope granularity than a country crossable in an hour needs. So Luxembourg is
seeded as **one whole-country region** at Overture's `country` level, not its cantons.

That case needed a small extension to the exporter, which is now a reusable path for any micro-state
(Andorra, Malta, Liechtenstein): Overture's country-level polygon has a null `region` column, so the
exporter keys it on the ISO 3166-1 code. The config block that produced it:

<!-- CODE-FROM tools/divisions/config.py -->
```python
"LU": {
    "subtype": "country",
    "slugs": {"LU": "luxembourg"},
    "names": {"LU": "Luxembourg"},
    "bbox": [5.7, 49.4, 6.6, 50.2],
},
```

A normal country instead uses `"subtype": "region"` with an ISO-3166-2 → slug map (one entry per
subdivision), exactly like Belgium's three regions or Germany's sixteen.

## Step 2: scaffold, which emits and never applies

<!-- CODE-ILLUSTRATIVE step 2, emit the config block and label stubs for review -->
```bash
make region-scaffold c="NL"            # add flags="--probe-areas" to include measured areas
```

One property is worth stating plainly, because it is what makes the next step safe: the scaffolder
**emits, never applies**. It prints a config block and a set of label stubs to standard output and
writes nothing anywhere. Nothing is committed, no row is created, and running it twice costs
nothing. That is deliberate, because everything it emits is about to be reviewed by a person, and
the things step 3 asks you to freeze are permanent.

`tools/divisions/README.md` is the full reference for the emitted shape and every flag.

## Step 3: what you must freeze by hand

The scaffolder emits stubs; you make the permanent decisions:

- **Slugs are identity.** A region row is upserted by slug, so a slug must never change afterwards.
  Use an established English exonym where one truly exists (`wallonia`, `flanders`), otherwise the
  native form (`noord-holland`). **Act on every collision warning**: the Netherlands has a Limburg
  and so does Belgium, so the Dutch one is `limburg-nl`.
- **Exonyms in every locale.** Region display labels come from the `messages` translation domain,
  not from Overture. Add the label and the country rung to each of `en`/`fr`/`nl`/`de`/`es`, the
  parity gate fails the build if one catalogue is missing a key, so a half-done country cannot ship:

<!-- CODE-FROM web/translations/messages.en.yaml -->
```yaml
  luxembourg:
    label: 'Luxembourg'
  all_lu:
    label: 'All Luxembourg'
```

- **The timezone.** Add the country's IANA zone(s) to `TZ_COUNTRY` in the map's scope model, or the
  cold-start "guess the visitor's home country" resolves to nothing for that country's riders (and no
  test catches the gap):

<!-- CODE-FROM web/assets/map/scope.js -->
```javascript
    'Europe/Luxembourg': 'LU',
```

## Steps 4–5: export and seed

<!-- CODE-ILLUSTRATIVE export then import the region artifacts -->
```bash
make divisions-data c="NL"                      # -> tools/divisions/out/region-*.geojson
mkdir -p web/var/catalog-nl
cp tools/divisions/out/region-<each-new-slug>.geojson web/var/catalog-nl/   # the subdivisions
cp tools/divisions/out/region-netherlands.geojson web/var/catalog-nl/       # the L2 country outline
docker compose -f developers/docker/compose.yaml exec -T app php -d memory_limit=2G \
  bin/console app:catalog:import /app/var/catalog-nl
```

Every export emits the **level-2 country outline** alongside the operating-level subdivisions (a
country operating at level 2, like Luxembourg, emits it as its only artifact). Stage the outline too.
It is infrastructure, not an operating region: it never appears in the scope selector or on
`/regions` while finer rows exist, but evidence in a country without subdivisions anchors to it and
it provides the country polygon. Import it only as part of onboarding the country, never on its own
ahead of the rest of the playbook, because a country whose only row is its outline is public by the
same rule.

`-d memory_limit=2G` is not optional past a handful of countries: in the dev environment Doctrine's
SQL logger retains every region geometry sent as a query parameter for the profiler, and the default
128 MB runs out part-way through. The import is one transaction, so a crash rolls back cleanly and
re-running is safe.

!!! tip "Import an isolated directory"
    `app:catalog:import` processes *every* file in the directory you give it, and `make divisions-data`
    adds to `tools/divisions/out/` without clearing it. Point the import at a folder holding only the
    new country's artifacts, not the shared `catalog-out` and not a `region-*` glob over `out/`,
    otherwise it reprocesses every stale artifact sitting there.

The import upserts the `Region` row(s) and re-derives region memberships. Confirm before moving on;
expect the operating-level rows plus one row at `admin_level = 2`, the outline (for a country
operating at level 2, that one row is all there is):

<!-- CODE-ILLUSTRATIVE verify the region seeded -->
```sql
SELECT slug, country_code, area_km2, admin_level FROM region WHERE country_code = 'NL';
```

## Step 6: coverage

Add the country to **both** the coverage country map and the harvest region list. The country map
tells the pipeline which country an extract owns:

<!-- CODE-FROM pipeline/coverage/load.py -->
```python
COUNTRY_BY_REGION = {
    "europe/belgium": "BE", "europe/netherlands": "NL", "europe/germany": "DE",
    "europe/luxembourg": "LU",
    ...
    # Northern Ireland has no extract of its own — Geofabrik ships it inside the
    # all-Ireland one, which also covers the Republic. Ireland is NOT onboarded,
    # so nearest-region-wins deletes Republic rows for having no onboarded region
    # within BOUNDARY_SNAP_DEG, EXCEPT in the ~1 km band along the border, where
    # the snap hands them to northern-ireland. That band is mis-stamped GB until
    # Ireland is onboarded, which re-harvests it with correct region stamps.
    "europe/ireland-and-northern-ireland": "GB",
    ...
    # State-level onboarding: only the two seeded states, not a north-america/us
    # ancestor, so an unonboarded state's extract still hard-fails resolve_country
    # instead of silently harvesting as US.
    "north-america/us/california": "US", "north-america/us/colorado": "US",
    ...
}
```

The elisions are load-bearing: the dict grows with every rollout, and a quote
that listed every entry would read as complete and be wrong within a month.
Open the file for the current list.

A country whose regions live inside a **shared** extract (Northern Ireland above) is the case to
watch: ownership is decided by nearest onboarded region, so the un-onboarded half of the extract is
discarded except within `BOUNDARY_SNAP_DEG` of the border. That is correct behaviour, not a bug, but
it does mean a thin band of the neighbour's POIs carries your country code until the neighbour is
onboarded too.

!!! warning "The guard will stop you if you skip step 5"
    A missing `COUNTRY_BY_REGION` entry **hard-fails** that region's harvest rather than silently
    disabling ownership, and harvesting a country with **no seeded regions** fails loudly too (the
    ownership filter would otherwise drop every row). Both are deliberate: seed the regions first.

Then run the harvest with the new country folded into the full list (never one country alone; see
[Harvesting](harvesting.md#running-it)):

<!-- CODE-ILLUSTRATIVE harvest including the new country: every onboarded region (the COVERAGE_REGIONS default in developers/docker/compose.yaml) plus the new extract -->
```bash
make coverage-refresh regions=$(make -s coverage-regions),<new-region>
```

Then add the new extract to the `COVERAGE_REGIONS` default in `developers/docker/compose.yaml`, or
nothing that follows the committed configuration refreshes it again.

Once it completes, the new country's regions appear in the scope selector automatically, the client
region registry is served straight from the `region` table, and its POIs render from the rebuilt
tiles.

## Step 6b: the other tile sets

Step 6 builds the POI coverage tiles. **Two more tile sets are built per country**, from
the same Geofabrik extract and taking the same `regions=` list:

<!-- CODE-ILLUSTRATIVE the two builds a new country also needs, over every onboarded region -->
```bash
make routes-tiles  regions=$(make -s coverage-regions),<new-region>   # cycle-route network + junction numbers, first
make surface-tiles regions=$(make -s coverage-regions),<new-region>   # road surface: classified, to-do, gap grid, second
```

Routes first: the routes run writes the per-region way-id sets the surface build reads to make its
to-do arm route-aware (see [Building road-surface tiles](surface-tiles.md)).

!!! danger "Pass every region, not just the new one"
    Coverage accumulates in a database, so refreshing one country and rebuilding its
    tiles is correct. **Surface and routes use no database**: they read the extracts you
    name and publish an artifact containing exactly those. Building them one country at a
    time *replaces* the previous artifact instead of adding to it, and the manifest ends
    up listing only the country you built last. Always rebuild these two with the whole
    `COVERAGE_REGIONS` list, then check `country_codes` in each manifest.

A country with coverage but without these looks *broken* rather than incomplete: the
Surfaces skin is blank over it while every neighbouring country is coloured, and node
networks simply are not there. Every onboarded country has all three artifacts, so a
missing one reads as a bug in the layer rather than as work nobody did.

Both manifests publish a `country_codes` list, so whether a country is covered is a
question with a factual answer rather than an assumption. Check it before and after.

## Step 6c: elevation

Coverage fills the country with points. **Elevation is what lets a climb in it have a gradient
profile**, and it is a separate dataset on a separate host, so it is a separate step.

This step is easy to skip: the procedure runs on the routing host rather than in this repository's
containers, and nothing in the harvest fails when it is missed.

**Why it fails quietly.** Elevation is served by one Valhalla instance per continent, each holding
only the tiles in its own directory. `ElevationEndpoints` picks the instance from the shape's first
point; a continent with no configured instance falls back to the **default**, which answers `null`
for ground it does not hold, and the client refuses any reply with a non-numeric sample. So a climb
in an uncovered country gets **no profile rather than a wrong one**, the right failure, and an
invisible one.

**Check before you fetch.** The box may already cover the country: Slovenia needed nothing, because
the Europe tile set already reaches it. Ask for a summit whose height you know.

<!-- CODE-ILLUSTRATIVE one /height call against a known point -->
```bash
curl -sG --data-urlencode 'json={"shape":[{"lat":46.06,"lon":14.51}]}' \
  http://127.0.0.1:8002/height
# {"height":[299]}  -> Ljubljana, so Slovenia is covered
# {"height":[null]} -> no tile; install below
```

**Ask more than one point, and insist on a non-zero.** `null` is only one of the
two ways this goes wrong. A Valhalla with no elevation loaded answers **`0`**,
which is numeric, plausible, and passes any check that only rejects `null`.

The reverse trap is just as easy: three points inside `N70E025` once came back
`[0, 0, 0]` from a perfectly healthy tile, because all three landed in a fjord.
A single sample cannot tell "no data" from "sea level".

<!-- CODE-ILLUSTRATIVE a spread rather than a single sample -->
```bash
curl -sG --data-urlencode 'json={"shape":[
  {"lat":46.06,"lon":14.51},{"lat":46.21,"lon":14.66},
  {"lat":46.21,"lon":14.36},{"lat":45.91,"lon":14.66}]}' \
  http://127.0.0.1:8002/height
# some values above zero -> covered
# all zero, or all null    -> not covered, whatever the client thinks
```

If it comes back `null` or all zeros, the country needs a DEM install, and that procedure is not
repeated here: [Building elevation tiles](elevation-tiles.md) owns it, steps 4 and 5, along with the
reasons behind each one. In outline it is `dem-install.sh <continent> <COUNTRY>` on the routing
host, then a restart of that continent's instance, then the same spread again from outside. Three
details from that page are worth knowing before you start, because each one has caught somebody:

- **The restart is unavoidable**, and it is a real outage for that continent. Valhalla builds its
  elevation index at startup, so a running instance answers `null` for a tile sitting readable in
  its own mount.
- **The whole continent is converted at once**, never per country. Tiles overlap by one row and one
  column, so a tile cut in isolation gets nodata along two edges.
- **The conversion runs in a container**, because the routing host has no GDAL on purpose.

!!! tip "If restarting one continent means restarting all six, fix the unit"
    That is a workaround for a service definition that runs every continent from a single unit,
    and it is removed rather than worked around: a systemd **template** unit
    (`valhalla@europe`, `valhalla@asia`, one per continent) makes each independent. Measured on a
    six-continent host running template units: **101 s** for a cold start of all six, and one
    continent could be stopped and started with the other five still answering.

!!! warning "Tiles the app never asks for are the same as no tiles"
    The instance must also be named in `ELEVATION_URLS`, or the box falls through to the default and
    the tiles you just installed are never queried. An instance can exist, hold no tiles, *and* be
    unlisted all at once: three ways of being absent, each of them silent.

## Step 7: moderators

Region rows exist; nobody is looking after them. Moderator scope in this project is assigned per
region atom rather than per country, so a new country's regions start unassigned and its
submissions queue with no one scoped to see them. Assigning them is a judgement call about people
rather than a command, which is why it carries the ⚑ in the playbook, and it happens in the curator
desk rather than here. `docs/specs/moderation-and-contribution.md` is the contract.

Until somebody is scoped, the country is live on the map and invisible in moderation, which is the
wrong half to leave running.

## Step 8: record the rollout

The last step is writing down what you just decided, because the decisions in steps 1 and 3 are the
ones a future onboarder will otherwise have to re-derive: which administrative level you chose and
why, any slug that had to be disambiguated, any exonym that needed a locale-specific fix, and
anything about the country that did not fit the playbook. Luxembourg's single-region treatment is
in the specs for exactly this reason, and it is the reason the next micro-state is an hour's work
rather than a day's.

`tools/divisions/README.md` carries the per-country rollout notes.

## Try it

!!! tip "Hands-on: confirm a country is really onboarded, in two questions"
    A country is onboarded when its region rows exist and the elevation host answers for its ground.
    Those are two different machines and two different failure modes, so check them separately. The
    Netherlands is used here because it is already seeded on any stack that has run the rollout;
    swap the code for the country you just added.

    First, the rows. One level-2 row for the country outline, and one level-4 row per subdivision:

    <!-- CODE-ILLUSTRATIVE verify the region seeded -->
    ```sql
    SELECT slug, country_code, round(area_km2::numeric) AS area_km2, admin_level
    FROM region WHERE country_code = 'NL' ORDER BY admin_level, slug;
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM author-install; sample output, first five of thirteen rows, 2026-09-10 -->
    ```text
        slug     | country_code | area_km2 | admin_level
    -------------+--------------+----------+-------------
     netherlands | NL           |    37746 |           2
     drenthe     | NL           |     2680 |           4
     flevoland   | NL           |     2413 |           4
     friesland   | NL           |     3995 |           4
     gelderland  | NL           |     5137 |           4
    ```

    Read the `admin_level` column, not the count. Exactly one row must be level 2: that is the
    country outline every scoped query falls back to, and a country with two of them, or none, is
    the failure this step exists to catch. The areas are a second, cheaper check: a subdivision
    coming back at a few square kilometres usually means an outline that failed to close.

    Second, the ground. Ask the elevation instance for a point whose height you already know, and
    read the answer sceptically:

    <!-- CODE-ILLUSTRATIVE ask the elevation host for one known summit; Signal de Botrange is about 694 m -->
    ```bash
    curl -s -X POST "$ELEVATION_URL/height" -H 'Content-Type: application/json' \
      -d '{"range":false,"shape":[{"lat":50.5010,"lon":6.0940}]}'
    ```

    A plausible height means tiles are installed and the box is wired. **A `0` is the answer to
    fear**, and it is why this check names a summit rather than a random point: the endpoint does
    not error when a tile is missing, it answers zero, and zero is a number that flows all the way
    through to a published climb reading flat. If you get one, work back through the three silent
    absences the warning above lists: no tiles, no restart, or the instance not named in
    `ELEVATION_URLS`.

## Where to go deeper

- [`tools/divisions/README.md`](https://github.com/cycling-commons/cycling-commons/blob/main/tools/divisions/README.md): the exporter, the scaffolder,
  Overture provenance, the operational-versus-infrastructure rule for level-2 rows, the per-country
  rollout notes, and the full command reference.
