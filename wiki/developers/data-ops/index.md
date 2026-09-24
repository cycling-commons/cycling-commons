<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Data operations: start here

The other three courses on this site explain how the Commons works. This one is what you actually
run. Six pages, each for a job somebody does on a schedule or when a new country arrives, and
each written by somebody who has been bitten by the failure modes it lists. Elevation takes three
of them, in order, because it is the one job where the reasoning is longer than the commands.

They are runbooks rather than chapters, so read the one you need. There is no narrative running
between them, and no worked example carried through. What there is, and what this page exists for,
is a shared set of prerequisites, an order the jobs have to run in, and a vocabulary the four pages
use without stopping to define.

## The four jobs

| Runbook | What it produces | When you run it |
|---|---|---|
| [Harvesting OSM coverage](harvesting.md) | `coverage_poi` rows and the coverage tile archive | Weekly, per Geofabrik extract |
| [Onboarding a new country](onboarding-a-country.md) | Region rows, then everything below for that country | Once per country, then never again |
| [What a DEM gets wrong](elevation-dem-concepts.md) | Nothing. It is the reasoning the other two rest on | Read once, before either |
| [Building elevation tiles](elevation-tiles.md) | The DEM the routing host answers `/height` from | Once per continent, then when a country is added |
| [Measuring a climb](measuring-a-climb.md) | The published average and steepest gradient | After a DEM install, or a profiler change |
| [Building road-surface tiles](surface-tiles.md) | The surface quality and to-do tile archives | When the surface picture is stale |

## What all four assume

- **The dev stack is up**, and you can reach Postgres, MinIO on port 9100, and the pipeline
  container. [`building.md`](../../building.md#run-it-locally) covers bringing it up.
- **The country is onboarded already.** Region rows decide which extract owns which POI, so a
  harvest of a country with no regions fails loudly rather than loading rows nobody can scope. That
  is why onboarding is a prerequisite for the other three, not a peer of them.
- **Artifacts are versioned and a manifest points at the newest.** Nothing overwrites a published
  archive. A build writes a new stamped key and moves the manifest, so a reader holding an old URL
  keeps valid bytes until it is pruned.

## The order they run in

Onboarding first, for a new country. After that, the three build jobs are mostly independent, with
one coupling that is easy to miss and expensive to get wrong:

**Routes before surface.** The routes extract writes a per-region set of member way ids, and the
surface extract reads that set to decide which untagged roads are worth a rider's time. Build
surface first and it still succeeds, and says so, but its to-do arm is not route-aware. Rebuild both
and run routes first.

Coverage has no ordering constraint against either. Elevation runs on the routing host rather than
this one, which is why it comes last in the onboarding playbook: it is a different machine and a
different failure mode.

## Try it

!!! tip "Hands-on: ask all three artifacts whether they agree"
    Before running any of the four runbooks, find out what is currently published. Each build writes
    its own manifest, and the three should name the same set of countries. Where they do not, one
    build is behind, and the map is showing a country on one layer and not another.

    <!-- CODE-ILLUSTRATIVE read all three published manifests at once -->
    ```bash
    for a in coverage surface routes; do
      printf '%-9s ' "$a"
      curl -s "http://localhost:9100/cc-maps/$a/manifest.json" \
        | jq -r '"\(.country_codes | length) countries, built \(.built_at[0:10])"'
    done
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM author-install; sample output on a machine holding all nineteen countries, 2026-09-10 -->
    ```text
    coverage  19 countries, built 2026-09-10
    surface   19 countries, built 2026-08-14
    routes    19 countries, built 2026-08-16
    ```

    Read the counts across, not the dates down. Different build dates are normal, because the three
    jobs run on their own schedules and a rebuild with no new data changes nothing a rider sees. A
    different **country count** is the signal: it means a country was onboarded and harvested but
    one of the tile builds was never re-run, so it exists in the database and not on the map.

    A `404` on any of the three is also a real answer, not a broken command: that artifact has never
    been published on this stack. The runbook for it is the one to read first.

!!! tip "If an exercise does not work"
    [When an exercise does not work](../troubleshooting.md) collects the failures that
    actually happen: an empty result, a missing table, a map drawing nothing, a harvest
    exiting non-zero, an elevation call answering plausibly-shaped zeros.

## Vocabulary

A reader arriving straight at a runbook meets these without introduction.

**Diff-merge.** How a harvest lands a region's rows: stage them, decide by geometry which the
extract owns, then upsert on `(ref, letter)` with an `IS DISTINCT FROM` guard, so an unchanged row
is not rewritten at all, and delete the rows that have vanished from that extract's own slice. Not
delete-and-reload. Chapter 6 of the GIS course ([`osm-to-database.md`](../gis/osm-to-database.md))
walks the same mechanism from the row's point of view.

**Ownership, and nearest-region-wins.** Geofabrik's extracts overlap at borders, so one real object
arrives in several of them. Ownership is decided by geometry, not by which extract ran last: a row
belongs to the extract whose country holds the nearest region. Every other extract's run deletes it.
This is what stops a border POI flapping between countries week to week.

**Drift abort.** A guard, not a threshold to tune. If a region's new extract carries far fewer rows
than the last good one, the merge aborts and keeps what is already there, on the assumption that a
truncated download is more likely than a country losing a third of its fountains. An abort means
investigate.

**Silent zero.** The elevation failure mode worth fearing. A DEM with no tile for a place does not
error; it answers `0`. A climb measured against missing tiles comes back flat, and flat is a number,
so nothing downstream notices. Every elevation check in these pages is really a check against this.

**Per-country publish, never a narrow-run trap.** Each family publishes one archive per country under
its own key, merged into the family's manifest by replacing only the countries that run just built.
A run over one country can never drop the other eighteen off the map by omission: the only way a
country leaves the manifest is the explicit `--retire <cc>` flag, and the log names whatever it
retires.

**Manifest pinning.** The published manifest is what every client reads to find the current
per-country archives. Moving it is the publish. Rebuilding a country's tiles without moving the
manifest changes nothing a rider sees.

**Prune keep.** How many old builds survive a publish: four for coverage, three for surface and for
routes, whole prefixes at a time. Never one arm of a build, which would leave a manifest pointing at
a layer that is no longer there.

**bestOf.** A per-category flag in the API's category table, saying whether the Commons map's Best
of view shows that category. It travels through these pages only because the tile contract carries
it; [`curation-and-voting.md`](../../curation-and-voting.md) is where the idea is set out.

## Related reading

- [The numbers these courses quote](../numbers.md): the row counts, harvest sizes and timings these
  runbooks cite, each with its source or its date.
- [GIS course, chapter 6](../gis/osm-to-database.md) and [chapter 7](../gis/tiles.md): the harvest
  and the tile pyramid, explained rather than operated.
- [Country onboarding](../../country-onboarding.md): the same subject for a reader who is not going
  to run any of it.
