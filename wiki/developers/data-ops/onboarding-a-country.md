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
    # 2026-08-06 rollout.
    "europe/france": "FR", "europe/switzerland": "CH", "europe/italy": "IT",
    "europe/great-britain": "GB",
    # Northern Ireland has no extract of its own — Geofabrik ships it inside the
    # all-Ireland one, which also covers the Republic. Ireland is NOT onboarded,
    # so nearest-region-wins deletes Republic rows for having no onboarded region
    # within BOUNDARY_SNAP_DEG, EXCEPT in the ~1 km band along the border, where
    # the snap hands them to northern-ireland. That band is mis-stamped GB until
    # Ireland is onboarded, which re-harvests it with correct region stamps.
    "europe/ireland-and-northern-ireland": "GB",
    "australia-oceania/australia": "AU",
    "asia/japan": "JP",
    # State-level onboarding: only the two seeded states, not a north-america/us
    # ancestor, so an unonboarded state's extract still hard-fails resolve_country
    # instead of silently harvesting as US.
    "north-america/us/california": "US", "north-america/us/colorado": "US",
}
```

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

## Where to go deeper

- [`tools/divisions/README.md`](https://github.com/cycling-commons/cycling-commons/blob/main/tools/divisions/README.md) — the exporter, the scaffolder,
  Overture provenance, and the full command reference.
- This page — the operating-level rule, the tessellation invariant, and the
  BE/NL/DE/LU rollout notes.
