<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Building road-surface tiles

The map's road-surface layer answers "what is under my tyres?" for every road OpenStreetMap knows
about, and just as usefully "which roads has nobody answered for yet?". This is how that layer is
built.

It shares its source with [Harvesting OSM coverage](harvesting.md), the same Geofabrik extracts, the
same `osmium tags-filter` step, and diverges completely after that. Coverage produces **points** and
loads them into PostGIS. Surface produces **lines**, and touches **no database at all**: points go
through `coverage_poi` because the serve endpoints and the dedupe need SQL, and lines need neither,
so ways stream from the PBF straight to GeoJSONL and on into tippecanoe. That is what makes
country-scale line data affordable.

## Running it

<!-- CODE-ILLUSTRATIVE build the three surface artifacts for every onboarded region (the COVERAGE_REGIONS default in developers/docker/compose.yaml) -->
```bash
make surface-tiles regions=$(make -s coverage-regions)
```

One pass over each region's PBF produces **three artifacts**, because they answer three different
questions and a vector tile is fetched *whole*, a layer that is off by default still costs its
bytes to every rider who fetches the tile it is folded into:

| Artifact | What it holds | Zooms |
|---|---|---|
| `surface.pmtiles` | every way with a `surface` tag, canonicalised into the contract's surface classes | z10-13 |
| `surface-todo.pmtiles` | ways with **no** surface tag, in the classes where the answer is genuinely unknown | z11-13 |
| `surface-gaps.pmtiles` | one square per ~6 km with the km of unrecorded network inside it | z4-11 |

The classified floor is z10 because the build runs with no tile-size limit, so a low-zoom tile
carries every classified way under it: measured over Wallonia, one tile was about 1.1 MB at z8
against roughly 90 KB at z10, and a screen is about sixteen tiles at any zoom. Below z10 the gaps
grid answers the planning question instead. The client carries the same floor
(`CLASSIFIED_MIN_ZOOM`), and a cross-language test pins the two together.

### Why the "needs recording" arm is not simply every untagged road

An arm holding every untagged road costs about as much as the entire classified layer: measured on
Belgium, the two came out within a few percent of each other. What decides the set instead is what
those roads actually turn out to be. Of Belgian ways somebody **has** tagged, these are the shares
that came back unpaved:

| highway | unpaved when tagged | on the to-do list? |
|---|---|---|
| `track` | 88.2 % | **yes** |
| `path` | 50.1 % | **yes** |
| `unclassified` | 6.8 % | **yes** |
| `living_street` | 20.0 % | no |
| `residential` | 7.2 % | no |
| `tertiary` | 2.5 % | no |
| `secondary` | 0.5 % | no |
| `primary` | 0.1 % | no |
| `cycleway` | 0.0 % | no |

<figure class="gis-fig"><svg viewBox="0 0 640 432" role="img" aria-labelledby="sf1-t sf1-d" xmlns="http://www.w3.org/2000/svg"><title id="sf1-t">How often each kind of road turns out to be unpaved, once somebody has tagged it</title><desc id="sf1-d">A horizontal bar chart of nine OpenStreetMap highway classes in Belgium, each bar showing the share of ways of that class carrying a surface tag whose value is unpaved. Track is by far the longest bar at 88.2 percent, then path at 50.1 percent, then living_street at 20.0 percent. The remaining six are very short: residential 7.2, unclassified 6.8, tertiary 2.5, secondary 0.5, primary 0.1 and cycleway 0.0 percent. Three bars are drawn in the accent colour to mark the classes kept on the to-do list, track, path and unclassified, and the other six are muted. The chart shows that the kept set is not a simple threshold: unclassified is kept at 6.8 percent while living_street at 20.0 percent and residential at 7.2 percent are left off.</desc>
<line class="gis-ink" x1="186" y1="30" x2="186" y2="358"/><line class="gis-muted" x1="290" y1="30" x2="290" y2="358" stroke-dasharray="3 5"/><line class="gis-muted" x1="393" y1="30" x2="393" y2="358" stroke-dasharray="3 5"/><line class="gis-muted" x1="496" y1="30" x2="496" y2="358" stroke-dasharray="3 5"/><line class="gis-muted" x1="600" y1="30" x2="600" y2="358" stroke-dasharray="3 5"/>
<rect class="gis-fill-accent" x="186" y="40" width="365" height="21"/><rect class="gis-fill-accent" x="186" y="76" width="207" height="21"/><rect class="gis-fill-glacier" x="186" y="112" width="83" height="21"/><rect class="gis-fill-glacier" x="186" y="148" width="30" height="21"/><rect class="gis-fill-accent" x="186" y="184" width="28" height="21"/><rect class="gis-fill-glacier" x="186" y="220" width="10" height="21"/><rect class="gis-fill-glacier" x="186" y="256" width="2" height="21"/><rect class="gis-fill-glacier" x="186" y="292" width="2" height="21"/>
<text class="gis-label-mono" x="176" y="56" text-anchor="end">track</text><text class="gis-label-sm" x="560" y="56">88.2%</text><text class="gis-label-mono" x="176" y="92" text-anchor="end">path</text><text class="gis-label-sm" x="402" y="92">50.1%</text><text class="gis-label-mono" x="176" y="128" text-anchor="end">living_street</text><text class="gis-label-sm" x="278" y="128">20.0%</text><text class="gis-label-mono" x="176" y="164" text-anchor="end">residential</text><text class="gis-label-sm" x="225" y="164">7.2%</text><text class="gis-label-mono" x="176" y="200" text-anchor="end">unclassified</text><text class="gis-label-sm" x="223" y="200">6.8%</text><text class="gis-label-mono" x="176" y="236" text-anchor="end">tertiary</text><text class="gis-label-sm" x="205" y="236">2.5%</text><text class="gis-label-mono" x="176" y="272" text-anchor="end">secondary</text><text class="gis-label-sm" x="197" y="272">0.5%</text><text class="gis-label-mono" x="176" y="308" text-anchor="end">primary</text><text class="gis-label-sm" x="197" y="308">0.1%</text><text class="gis-label-mono" x="176" y="344" text-anchor="end">cycleway</text><text class="gis-label-sm" x="195" y="344">0.0%</text><text class="gis-label-sm" x="290" y="380" text-anchor="middle">25%</text><text class="gis-label-sm" x="393" y="380" text-anchor="middle">50%</text><text class="gis-label-sm" x="496" y="380" text-anchor="middle">75%</text><text class="gis-label-sm" x="600" y="380" text-anchor="middle">100%</text>
<rect class="gis-fill-accent" x="186" y="399" width="20" height="14"/><text class="gis-label-sm" x="214" y="412">kept: nobody can predict it</text>
<rect class="gis-fill-glacier" x="430" y="399" width="20" height="14"/><text class="gis-label-sm" x="458" y="412">left off</text></svg>
<figcaption>Measured on our own Belgian extract: of the ways somebody <strong>has</strong> tagged, this is the share that came back unpaved. A rider sent to record an untagged <code>primary</code> is being sent to confirm asphalt, one tagged primary in a thousand is anything else, and because mappers tag the <em>surprising</em> road first, an untagged one is safer still than its bar suggests. Only <code>track</code>, <code>path</code> and <code>unclassified</code> are kept, which holds the arm to under a third of the bytes while making every line in it a road where riding actually settles something. Note it is <strong>not a threshold</strong>: <code>unclassified</code> is kept at 6.8&nbsp;% while <code>living_street</code> is dropped at 20&nbsp;%. An unclassified road is a rural lane where the answer genuinely varies; a living street is in a town, and its 20&nbsp;% is mostly setts nobody rides for the surface.</figcaption></figure>

The set is contract data (`surface.todo.highways`), not code, so widening it is a rebuild rather than
a release.

### The grid: "where should I scan?" without shipping roads

Below z11 the same question is answered by squares instead of geometry. Each cell carries the
kilometres of unrecorded to-do network inside it, that as a share of the cell's network, and a road
count, and a cell where everything is already recorded is **omitted**, because a square drawn over
finished work reads as "there is something to do here", which is the one thing the layer must never
say.

Kilometres, not way counts: a way is an arbitrary unit. A rural track runs unbroken for 3 km while a
village lane is split at every junction, so counting ways would make dense villages look like more
work than the gravel network around them.

<figure class="gis-fig"><svg viewBox="0 0 640 400" role="img" aria-labelledby="sf3-t sf3-d" xmlns="http://www.w3.org/2000/svg">
<title id="sf3-t">One walk over each country's ways produces all three artifacts</title>
<desc id="sf3-d">A flowchart. At the top, a box labelled region PBF leads by an arrow to a box labelled osmium tags-filter, which leads to a box labelled one streaming pass. From that single box three arrows fan down to three boxes: classified, to record, and gap counts. Each of those leads down in turn to its own artifact: surface dot pmtiles, surface-todo dot pmtiles and surface-gaps dot pmtiles. A note at the side reads no database. The diagram makes the point that the expensive step, walking the ways, happens once and feeds all three outputs, rather than once per artifact.</desc>
<defs><marker id="gis-arrow-fS3" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path d="M 0 0 L 10 5 L 0 10 Z"/></marker></defs>
<rect class="gis-box gis-ink gis-fill-paper" x="20" y="28" width="170" height="56" rx="8"/><text class="gis-label-sm" x="105" y="62" text-anchor="middle">region PBF</text>
<line class="gis-ink" x1="194" y1="56" x2="228" y2="56" marker-end="url(#gis-arrow-fS3)"/>
<rect class="gis-box gis-ink gis-fill-paper" x="232" y="28" width="196" height="56" rx="8"/><text class="gis-label-sm" x="330" y="62" text-anchor="middle">osmium tags-filter</text>
<line class="gis-ink" x1="432" y1="56" x2="466" y2="56" marker-end="url(#gis-arrow-fS3)"/>
<rect class="gis-box gis-ink gis-fill-accent" x="470" y="28" width="150" height="56" rx="8"/><text class="gis-label-sm gis-halo" x="545" y="62" text-anchor="middle">one pass</text>
<path class="gis-ink" d="M 545 88 L 545 120 L 115 120 L 115 152" fill="none"/>
<path class="gis-ink" d="M 545 120 L 320 120 L 320 152" fill="none"/>
<path class="gis-ink" d="M 545 120 L 525 120 L 525 152" fill="none"/>
<line class="gis-ink" x1="115" y1="156" x2="115" y2="190" marker-end="url(#gis-arrow-fS3)"/><line class="gis-ink" x1="115" y1="252" x2="115" y2="306" marker-end="url(#gis-arrow-fS3)"/><line class="gis-ink" x1="320" y1="156" x2="320" y2="190" marker-end="url(#gis-arrow-fS3)"/><line class="gis-ink" x1="320" y1="252" x2="320" y2="306" marker-end="url(#gis-arrow-fS3)"/><line class="gis-ink" x1="525" y1="156" x2="525" y2="190" marker-end="url(#gis-arrow-fS3)"/><line class="gis-ink" x1="525" y1="252" x2="525" y2="306" marker-end="url(#gis-arrow-fS3)"/><rect class="gis-box gis-ink gis-fill-paper" x="20" y="196" width="190" height="56" rx="8"/><text class="gis-label-sm" x="115" y="230" text-anchor="middle">classified</text><rect class="gis-box gis-ink gis-fill-paper" x="225" y="196" width="190" height="56" rx="8"/><text class="gis-label-sm" x="320" y="230" text-anchor="middle">to record</text><rect class="gis-box gis-ink gis-fill-paper" x="430" y="196" width="190" height="56" rx="8"/><text class="gis-label-sm" x="525" y="230" text-anchor="middle">gap counts</text><rect class="gis-box gis-ink gis-fill-paper" x="20" y="312" width="190" height="56" rx="8"/><text class="gis-label-mono" x="115" y="346" text-anchor="middle">surface</text><rect class="gis-box gis-ink gis-fill-paper" x="225" y="312" width="190" height="56" rx="8"/><text class="gis-label-mono" x="320" y="346" text-anchor="middle">surface-todo</text><rect class="gis-box gis-ink gis-fill-paper" x="430" y="312" width="190" height="56" rx="8"/><text class="gis-label-mono" x="525" y="346" text-anchor="middle">surface-gaps</text>
<text class="gis-label-sm" x="20" y="392">no PostGIS anywhere in this path</text>
</svg>
<figcaption>The expensive half of a continental build is walking the ways, and it happens <strong>once</strong>. Each way is written out and dropped as it arrives, so a country never accumulates in memory, the reason a 5&nbsp;GB France extract builds on a laptop-sized machine at a peak of about 2&nbsp;GB, most of which is osmium's node-location index rather than our ways. Only the grid keeps state, and it is bounded by area rather than by roads: about a thousand cells per country, three numbers each.</figcaption></figure>

The grid stops at exactly the zoom the lines start, and both sides are pinned to that number by a
cross-language test, a mismatch would leave a band of zoom showing neither.

### Running it at continental scale

Two knobs exist for exactly that:

<!-- CODE-ILLUSTRATIVE continental surface build: extract per region, tile once -->
```bash
# One region at a time: a failure costs one country, not the queue.
make surface-tiles regions=europe/germany ARGS=--extract-only

# Then tile every extract on disk, once, from the PBFs already in the workdir.
make surface-tiles offline=1 regions=$(make -s coverage-regions)   # every onboarded region, as above
```

- **`ARGS=--extract-only`** stops after the GeoJSONL. Without it, every per-region pass ends in a full
  tippecanoe build of the countries done so far: tiling Germany a dozen times to discard each result.
- **`offline=1`** (`COVERAGE_PBF_OFFLINE=1` inside the container) uses the PBFs already in the
  workdir, and a region whose PBF is not there fails the run instead of fetching it. A PMTiles archive
  cannot be appended to, so adding one country means tiling *all* of them again, and that pass walks
  the whole region list. It is explicit rather than inferred from a warm cache: a run that skips the
  refresh has to say so, or "the data is current" quietly becomes "the data is whatever was here last
  time".

The per-region extract is cached and reused only while it is newer than **both** the PBF it came from
and the contract that shaped it, adding a highway class changes what *should* be in the file while
leaving the PBF untouched, and a stale extract would then be tiled as if it were current.

Each run reports its own peak memory. That matters here: ways stream to disk rather than
accumulating, and measuring from outside the container (`/usr/bin/time docker compose run`) measures
the docker client, which is how a continental build can appear to use 12 MB.

## Publishing: the manifest, not an edited config file

A finished build is uploaded by the same run that made it, under **one versioned
prefix per build**:

<!-- CODE-ILLUSTRATIVE the bucket layout of one published build -->
```
surface/20260812-2015/classified.pmtiles
surface/20260812-2015/todo.pmtiles
surface/20260812-2015/gaps.pmtiles
surface/manifest.json          <- stable key, repointed last
```

Symfony reads that manifest server-side (`App\Coverage\SurfaceManifest`, cached
one hour, 30-second negative TTL, 5-second timeout) and injects the three URLs
into the map page. **So a rebuild goes live within the hour with no config
change, no cache clear and no deploy**, the same contract as the coverage
artifact.

Three properties are load-bearing:

- **The artifacts are immutable, the manifest is not.** Versioned keys are
  `Cache-Control: immutable` for a year; the manifest carries `max-age=300`. A
  rebuild must never overwrite a live artifact, a rider mid-session holds an
  offset into the PMTiles directory, and changing the bytes underneath them
  reads as corruption, not as an update.
- **The three arms move together.** One stamp, one manifest, one publish. They
  are three readings of a single walk over the same ways, and serving one
  build's classified skin beside another's to-do arm would tell riders that
  roads they have just recorded still need recording.
- **The env vars pin, they do not configure.** `ROAD_SURFACE_TILES_URL`,
  `ROAD_SURFACE_TODO_URL` and `ROAD_SURFACE_GAPS_URL` override the manifest
  when set: the hatch for bisecting a rendering problem or serving an artifact
  that was never published. Empty (the default) means "follow the manifest",
  and `ROAD_SURFACE_MANIFEST_URL` names the manifest itself. Leaving a pin set
  by accident is how a map ends up serving last month's tiles.

Old builds are pruned to the newest three, **whole prefixes at a time**, never
one arm of a build, which would leave a manifest pointing at a layer that is no
longer there. Three rather than coverage's four because a surface build runs
to several gigabytes against coverage's well under one, and the reason to keep
any is a fast rollback, not history.

### The narrow-run trap, and the guard for it

A PMTiles archive cannot be appended to, so the tiling step builds from exactly
the regions it was given. That makes this dangerous:

<!-- CODE-ILLUSTRATIVE the narrow run the guard exists for -->
```bash
make surface-tiles regions=europe/luxembourg   # builds a LUXEMBOURG-ONLY archive
```

Published, that would replace the live build of every onboarded country with a
one-country build, with a zero exit code and nothing in the log. So the run
**refuses to publish a country set that is a strict subset of the live one**:

<!-- CODE-ILLUSTRATIVE SAMPLE-FROM author-install; sample output, what the guard prints when it refuses (the live set on 2026-09-10) -->
```
refusing to publish 1 countries over the live 19: AU, BE, CA, CH, CL, CO, DE,
ES, FR, GB, IT, JP, NL, NZ, RW, SI, US, ZA would vanish from the map. Pass
every onboarded region, or set COVERAGE_ALLOW_SHRINK=1 if the removal is
intended.
```

Use `ARGS=--no-publish` for size experiments on one country, and
`COVERAGE_ALLOW_SHRINK=1` only when dropping a country is what you actually
mean.

## The routes build, and why it runs first

The cycle-route network layer (signed `route=bicycle`/`route=mtb` corridors
plus knooppunt numbers) is a sibling build with the same shape, same
extracts, no database, its own artifact and manifest:

<!-- CODE-ILLUSTRATIVE build and publish the route-network artifact for every onboarded region -->
```bash
make routes-tiles regions=$(make -s coverage-regions)   # the same full list as the surface build
```

It differs from the surface build in one structural way: routes are OSM
**relations**, and a way cannot know its relations, so the extractor walks the
filtered PBF twice, relations first for membership, then ways with locations
(`pipeline/coverage/routes.py`). It publishes
`routes/<stamp>/routes.pmtiles` + `routes/manifest.json` (read by
`App\Coverage\RoutesManifest`; pin: `ROUTES_TILES_URL`), with the same
immutable-artifact, shrink-guard and whole-prefix-prune rules as above.

**Order matters when rebuilding both.** The routes run drops a
`routes_<region>_wayids.txt` per region into the workdir, the set of ways
that carry a signed route, and the surface build reads it to make its to-do
arm route-aware (an untagged way on a signed route is homework whatever its
highway class). The way-id file is a declared input of the surface extract, so
a fresh routes run automatically invalidates the surface extracts it would
change; run `make routes-tiles` first, `make surface-tiles` second, and the
cache does the rest. A surface run with no way-id files still builds; it says
so in the log, and the to-do arm is class-gated only.

## Try it

!!! tip "Hands-on: read the manifest before you rebuild anything, and know what the guard protects"
    Every claim on this page is visible in one file. Read it first, because it is also the file a
    careless publish overwrites.

    <!-- CODE-ILLUSTRATIVE read the published surface manifest -->
    ```bash
    curl -s http://localhost:9100/cc-maps/surface/manifest.json \
      | jq '{stamp, tiles: (.tiles | keys), counts, countries: (.country_codes | length)}'
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM author-install; sample output on a machine holding all nineteen countries, 2026-09-10 -->
    ```json
    {
      "stamp": "20260814-1512",
      "tiles": ["classified", "gaps", "todo"],
      "counts": { "classified": 16470329, "todo": 13924252, "cells": 150767 },
      "countries": 19
    }
    ```

    Three artifacts under `tiles`, which is the design this page opens with: one for the roads whose
    surface is recorded, one for the roads worth recording, one grid for the emptiness between them.
    The `counts` are kilometres and cells, not way counts, for the reason the page gives.

    Now look at `countries`. That number is what the shrink guard defends. Build one country and
    publish it over this, and nineteen become one:

    <!-- CODE-ILLUSTRATIVE build one country without publishing, to see the cost before paying it -->
    ```bash
    make surface-tiles regions=europe/luxembourg ARGS=--no-publish
    ```

    `--no-publish` is honoured on this arm, unlike the coverage point arm, so the build runs to
    completion and the manifest is untouched. Read the peak-memory line it prints against the
    numbers in the running section above, then compare the country list it *would* have published
    with the one you just read. If they differ, publishing is the narrow-run trap, and the guard
    will say so by name rather than letting the map quietly empty.

## Where to go deeper

- [A-road-surface.md](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/edit-items/A-road-surface.md): the rider-facing item:
  what a surface submission is, how a stretch is drawn, what moderators see.
- [map-and-search.md](https://github.com/cycling-commons/cycling-commons/blob/main/docs/specs/map-and-search.md): how the three artifacts render,
  the legend row that governs all of them, and the zoom handover.
- [Harvesting OSM coverage](harvesting.md): the point pipeline this shares its
  extracts with, and the md5 mirror-lag gotcha that bites both.
