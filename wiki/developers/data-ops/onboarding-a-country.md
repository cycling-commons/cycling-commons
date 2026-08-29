<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Onboarding a new country or region

Adding a country to the map is a fixed sequence: seed its **regions** (the scope-selector areas and
moderation jurisdictions), wire them up, then let the [harvest](harvesting.md) fill them with POIs.
This page is the practical walk-through; the tool reference is
[`tools/divisions/README.md`](https://github.com/cycling-commons/cycling-commons/blob/main/tools/divisions/README.md),
and the coverage side of what onboarding feeds is
[coverage-provider.md](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/coverage-provider.md).

The regions come from the **Overture Maps `divisions` theme** (ODbL — it conflates OSM and
geoBoundaries and carries ISO 3166 codes), not from the OSM harvest. Two different data sources for
two different jobs: Overture draws the boundaries, Geofabrik fills them with points.

## The playbook

One sequence for every country. ⚑ marks a human judgment call.

| # | Step | What happens |
|---|------|--------------|
| 1 | ⚑ **Choose the operating level** | `make region-probe c="XX"` lists the country's official subdivisions and their sizes. You decide which level to seed at (see below). |
| 2 | **Scaffold** | `make region-scaffold c="XX"` emits a config block + label stubs. It *emits, never applies*. |
| 3 | ⚑ **Review & merge** | Freeze slugs (permanent identity), fix exonyms in every locale, add the `all_<cc>` rung, add the timezone. |
| 4 | **Export** | `make divisions-data c="XX"` queries Overture and writes `region-<slug>.geojson` artifacts. |
| 5 | **Seed** | Import the artifacts as `Region` rows. |
| 6 | **Coverage** | Add the Geofabrik region to the harvest, then run it. |
| 6b | **Elevation** | Check the country's box already has DEM tiles; install them if not. |
| 6c | **The other tile sets** | Coverage is not the only per-country artifact: build the road-surface and cycle-route tiles too. |
| 7 | ⚑ **Moderators** | Assign region atoms to moderators. |
| 8 | **Specs** | Record the rollout. |

## Step 1 — the operating-level decision

The rule: seed at the **official administrative level whose subdivisions are a reasonable riding size**,
preferring a stable ISO 3166-2 identity. Small official regions are fine — moderation composes
upward, one moderator can hold several. Only invent synthetic macro-regions when *no* official level
fits.

But an official level can also be *too fine*. **Luxembourg** is the worked example: its only official
subdivision level is 12 cantons, all 78–343 km² — an order of magnitude below every other onboarded
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

## Step 3 — what you must freeze by hand

The scaffolder emits stubs; you make the permanent decisions:

- **Slugs are identity.** A region row is upserted by slug, so a slug must never change afterwards.
  Use an established English exonym where one truly exists (`wallonia`, `flanders`), otherwise the
  native form (`noord-holland`). **Act on every collision warning** — the Netherlands has a Limburg
  and so does Belgium, so the Dutch one is `limburg-nl`.
- **Exonyms in every locale.** Region display labels come from the `messages` translation domain,
  not from Overture. Add the label and the country rung to each of `en`/`fr`/`nl`/`de`/`es` — the
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

## Steps 4–5 — export and seed

<!-- CODE-ILLUSTRATIVE export then import the region artifacts -->
```bash
make divisions-data c="LU"                      # -> tools/divisions/out/region-*.geojson
mkdir -p web/var/catalog-lu
cp tools/divisions/out/region-luxembourg.geojson web/var/catalog-lu/
docker exec cycling-commons-dev-app-1 php bin/console app:catalog:import /app/var/catalog-lu
```

!!! tip "Import an isolated directory"
    `app:catalog:import` processes *every* file in the directory you give it. Point it at a folder
    holding only the new region's geojson, not the shared `catalog-out` — otherwise it reprocesses
    every stale artifact sitting there.

The import upserts the `Region` row(s) and re-derives region memberships. Confirm before moving on:

<!-- CODE-ILLUSTRATIVE verify the region seeded -->
```sql
SELECT slug, country_code, area_km2, admin_level FROM region WHERE country_code = 'LU';
```

## Step 6 — coverage

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
    A missing `COUNTRY_BY_REGION` entry now **hard-fails** that region's harvest rather than silently
    disabling ownership, and harvesting a country with **no seeded regions** fails loudly too (the
    ownership filter would otherwise drop every row). Both are deliberate — seed the regions first.

Then run the harvest with the new country folded into the full list (never one country alone — see
[Harvesting](harvesting.md#running-it)):

<!-- CODE-ILLUSTRATIVE harvest including the new country -->
```bash
make coverage-refresh regions=europe/belgium,europe/netherlands,europe/germany,europe/luxembourg,europe/france
```

Once it completes, the new country's regions appear in the scope selector automatically — the client
region registry is served straight from the `region` table — and its POIs render from the rebuilt
tiles.

## Step 6c — the other tile sets

Step 6 builds the POI coverage tiles. **Two more tile sets are built per country**, from
the same Geofabrik extract and taking the same `regions=` list:

<!-- CODE-ILLUSTRATIVE the two builds a new country also needs -->
```bash
make surface-tiles regions=south-america/chile   # road surface: classified, to-do, gap grid
make routes-tiles  regions=south-america/chile   # cycle-route network + junction numbers
```

!!! danger "Pass every region, not just the new one"
    Coverage accumulates in a database, so refreshing one country and rebuilding its
    tiles is correct. **Surface and routes use no database**: they read the extracts you
    name and publish an artifact containing exactly those. Building them one country at a
    time *replaces* the previous artifact instead of adding to it, and the manifest ends
    up listing only the country you built last. Always rebuild these two with the whole
    `COVERAGE_REGIONS` list, then check `country_codes` in each manifest.

A country with coverage but without these looks *broken* rather than incomplete: the
Surfaces skin is blank over it while every neighbouring country is coloured, and node
networks simply are not there. Because every country onboarded before 2026-08-14 has all
three artifacts, the missing ones read as a bug in the layer rather than as work nobody
did.

Both manifests publish a `country_codes` list, so whether a country is covered is a
question with a factual answer rather than an assumption. Check it before and after.

## Step 6b — elevation

Coverage fills the country with points. **Elevation is what lets a climb in it have a gradient
profile**, and it is a separate dataset on a separate host, so it is a separate step.

This step exists because it was missed. Seven countries were onboarded on 2026-08-14 and only one of
them had elevation — the procedure lived as scripts on the routing host and lines in a shell history,
so each rollout rediscovered it and that one didn't.

**Why it fails quietly.** Elevation is served by one Valhalla instance per continent, each holding
only the tiles in its own directory. `ElevationEndpoints` picks the instance from the shape's first
point; a continent with no configured instance falls back to the **default**, which answers `null`
for ground it does not hold, and the client refuses any reply with a non-numeric sample. So a climb
in an uncovered country gets **no profile rather than a wrong one** — the right failure, and an
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

If it comes back `null`, run the installer **on the Valhalla host**:

<!-- CODE-ILLUSTRATIVE the installer, run on the routing host -->
```bash
tools/elevation/dem-install.sh africa RWANDA SOUTHAFRICA
```

It fetches the Copernicus GLO-30 GeoTIFFs, converts them to the `.hgt` format Valhalla's elevation
service reads, and moves the result into that instance's `elevation_data`. All three stages resume,
so an interrupted run picks up where it stopped.

**Then restart that continent's instance, or nothing changes.** Valhalla builds its elevation index
at startup, so a running instance answers `null` for a tile sitting readable in its own mount. Restart
only the continent you touched — `docker restart valhalla-<continent>`.

!!! tip "If restarting one continent means restarting all six, fix the unit"
    That instruction is a workaround for a service definition that runs every
    continent from a single unit. It is worth removing rather than working
    around: a systemd **template** unit — `valhalla@europe`, `valhalla@asia`,
    one instance per continent, with port and memory in
    `/etc/valhalla/<continent>.env` — makes each independent, so an elevation
    install *or* a tile rebuild costs one continent's downtime instead of the
    whole box.

    Measured on a six-continent host after that change: **101 s** for a cold
    start of all six (about 100 GB of tiles, 22,611 elevation tiles), and one
    continent could be stopped and started with the other five still answering.

Two details worth knowing rather than rediscovering:

- **The conversion runs in a container.** The routing host has no GDAL on purpose — it is a routing
  box, not a GIS box — so `osgeo/gdal` supplies the tools for the length of the job and leaves
  nothing behind. It is CPU-capped, because that host is serving live routing while the conversion
  runs.
- **The whole continent is converted at once**, never per country. `.hgt` tiles overlap by one row
  and one column, so a tile cut in isolation gets nodata along its north and east edges. Cutting
  every tile from one mosaic of the continent's GeoTIFFs is what gives those edges real values.

!!! warning "Tiles the app never asks for are the same as no tiles"
    The instance must also be named in `ELEVATION_URLS`, or the box falls through to the default and
    the tiles you just installed are never queried. The `africa` and `south-america` instances
    existed, were empty, *and* were unlisted for months — three ways of being absent at once.

## Where to go deeper

- [`tools/divisions/README.md`](https://github.com/cycling-commons/cycling-commons/blob/main/tools/divisions/README.md) — the exporter, the scaffolder,
  Overture provenance, and the full command reference.
- This page — the operating-level rule, the tessellation invariant, and the
  BE/NL/DE/LU rollout notes.
