<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Climb Elevation & Profiles

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

> **Partly built** (2026-08-05). The elevation service, the measurement and the
> catalogue sweep exist: `App\Elevation\ElevationClient` reads
> [§2a](#2a-the-source)'s source through Valhalla, `App\Elevation\ClimbProfiler`
> is the single implementation of everything in [§3](#3-sampling-and-binning)–[§5](#5-the-steepest-ramp-is-found-not-placed),
> and `app:climbs:recompute` re-measures every climb with a line (dry run by
> default). **Not yet run against the catalogue** — that is [§7](#7-migration),
> and it waits on the editorial calls in [§9](#9-owner-decisions-still-open).
> [§6](#6-the-chart)'s chart is built apart from provenance display.

A rider marks two points — the **foot** and the **summit**. Everything else
about the climb is measured: its length, its height gain, its average and
maximum gradient, the shape of its profile, and where its steepest ramp is.
Nobody types a gradient.

This document owns how that measurement is done and how the result is
displayed. The climb's *editing* flow (the three-point editor, the wizard steps,
the moderation path) is owned by
[edit-items/B-climbs.md](edit-items/B-climbs.md); the *catalog* rules for what an
item may store are owned by [catalog-data-model.md](catalog-data-model.md).

---

## 1. Why the current numbers cannot be trusted

Three separate faults, all found on 2026-08-03/04.

### 1a. The published figures were typed by hand

`Côte de la Redoute` displays `2.0 km · 8.4% avg` and `~20% (mid-climb ramp)`.
Both were written into a JavaScript literal in commit `af75f60` (17 June 2026),
when the map was a static HTML prototype, and later lifted verbatim into
`SeedManualCatalogCommand`. They carry `source: 'OSM + community edits'`, which
is a label rather than a provenance record: no dataset is named and nothing
computed them.

The same commit says, in its own comment: *"omit any attribute we cannot
verify."* These are exactly the attributes nobody could verify. They shipped
because **a plausible number is indistinguishable from a measured one** once it
is on the page.

Measured over the same 2.0 km, three independent sources agree with each other
and not with the seeded figure:

| source | gain | average |
|---|---|---|
| seeded value | — | **8.4%** |
| EU-DEM 25 m | 176 m | 8.9% |
| Shuttle Radar Topography Mission (SRTM) 30 m | 182 m | 9.2% |
| climbfinder.com | 180 m | 9.0% |

**And it is not one bad entry.** Every seeded headline was checked against the
route stored in the *same array*, 2026-08-04:

| climb | published | measured from its own line |
|---|---|---|
| Côte de la Redoute | 2.0 km · 8.4% | 1.96 km · 9.2% |
| Mur de Huy | 1.3 km · 9.3% | 1.39 km · 9.7% |
| Côte de Stockeu | ~1.0 km · 9%+ | 1.00 km · **14.0%** |
| Côte de la Roche-aux-Faucons | 1.5 km · 9% | 1.75 km · **3.2%** |

Read the published column on its own: **8.4%, 9.3%, 9%+, 9%**. Four climbs that
actually range from 3.2% to 14% were all published at about nine percent. That
clustering is the tell — these are not measurements that drifted, they are
plausible-looking numbers chosen to look like climb gradients.

Two details make the point sharper:

- **Even the lengths were typed.** Roche-aux-Faucons is published at 1.5 km while
  the route sitting beside it in the same seed entry is 1.75 km. Nothing derived
  the headline from the geometry it shipped with.
- **Where the line is also wrong, neither number is the truth.**
  Roche-aux-Faucons' stored line is not the climb at all: climbfinder puts that
  climb at 4.3 km averaging 5.4%, against our line's 1.75 km and 3.2%. So the
  published 9%, our own 3.2%, and the real 5.4% are three different numbers, and
  only the last one describes the road. Fixing the source without fixing the line
  ([§4a](#4a-the-line-must-end-at-the-summit)) just measures the wrong thing
  precisely.

### 1b. The elevation source is too coarse for the bins we want to draw

The profile comes from `profileFromRoute()` in
`web/assets/contribute/climb-elevation.js`. Until 2026-08-04 it sampled the drawn
line at 100 points and called open-meteo's elevation endpoint from the browser,
which serves Copernicus GLO-90 — a **D**igital **E**levation **M**odel (DEM) on a
roughly 90 m grid. It now posts to our own `/contribute/elevation`, which reads
[§2a](#2a-the-source)'s source through Valhalla; everything below is what the
90 m grid produced, and why the source had to change.

Sampling every 25 m into a 90 m grid produces a staircase. Measured on La
Redoute's stored route:

- **30 distinct elevation values across 99 samples**, with runs of up to **7
  identical samples** in a row;
- raw per-sample gradients spanning **−39% to +101%** (standard deviation 20.6);
- **8 samples reading downhill** on a climb that never descends.

At 100 m bins that is unpublishable. Same road, same request, three DEMs:

```
climbfinder    [6, 7, 10, 6,  8, 8,   7,  9, 10,  9, 13, 16, 10,  9, 13, 10, 4, 6, 6, 5]
EU-DEM 25 m    [5, 9, 13, 7, 11, 6,   3,  5, 10, 11, 13, 17, 11, 10, 13, 11, 4, 6, 7]
SRTM 30 m      [7, 6,  9, 7, 12, 6,   5, 11,  8, 13, 13, 15, 10, 11,  9, 18, -2, 6, 11]
GLO-90 (today) [6, 8, 12, 12, 25, 0, -10, 16, 12,  7, 11, 17,  8, 12, 17,  8, 7, 8, -2]
```

| source | distinct values (of 100) | longest flat run | mean error vs climbfinder | bins reading downhill |
|---|---|---|---|---|
| GLO-90 *(today)* | 28 | 6 | 4.7 pts | **2** |
| SRTM 30 m | 92 | 2 | 2.4 pts | 1 |
| **EU-DEM 25 m** | **100** | **1** | **1.4 pts** | **0** |

The existing 11-bin display (≈220 m per bar on a 2.4 km climb) is not accurate —
it is merely *wide enough to hide this*.

**And it stopped hiding it the moment anything measured narrower.** When
`maxGradient` began coming from a 100 m window ([§5](#5-the-steepest-ramp-is-found-not-placed)),
a redrawn Roche-aux-Faucons published a **32% ramp it does not have** (owner,
2026-08-04). That is [§3a](#3a-bin-width-follows-the-source) arriving on the
page: 100 m on a 90 m grid is barely one cell, so two adjacent samples on a
staircase read as a wall. The same noise inflated the ascent-only average from
5.7% to 6.3%, because ascent-only accumulates upward wobble and never subtracts
it. Neither figure was wrong about its own arithmetic; both were wrong about the
road, and no amount of clamping fixes a source that coarse.

The last column is decisive **here and not in general**: La Redoute never
descends, so a bin that reads downhill on it can only be an artifact. On a climb
that genuinely drops between two ramps the same count means nothing — see the
correction in [§2a](#2a-the-source).

### 1c. The bars mean different things on different climbs

Seeded climbs carry hand-authored `grad` arrays of 10, 11 or 12 values. Editor-
drawn climbs carry 11 values sampled from real elevation. Nothing in the payload
distinguishes an estimate from a measurement, and both render identically.

---

## 2. The elevation service

### 2a. The source

**One source, worldwide: Copernicus DEM GLO-30.** Owner decision, 2026-08-04.

| source | resolution | coverage |
|---|---|---|
| Copernicus DEM GLO-30 | 30 m | worldwide land |

There is no chain, no per-coordinate resolution order, and no regional
fallback. That is the whole point of the decision: every mechanism this
document previously needed — a priority list, a per-continent raster
inventory, a `demSource` that varies by where you are standing — existed only
to paper over a Europe-only first choice.

**What replaced the chain.** The earlier design put EU-DEM v1 first for Europe,
SRTM second, GLO-90 third as a worldwide floor. GLO-90 was genuinely too coarse
([§1b](#1b-the-elevation-source-is-too-coarse-for-the-bins-we-want-to-draw)),
which is what forced the fallback structure. But GLO-90 is a 3x downsample of
GLO-30, so that verdict never applied to GLO-30 itself — it was inherited by
association and left the better dataset untested for the entire design.

**The measurement that settled it**, 2026-08-04, on all seven seeded climbs:
the GLO-30 tile was converted to `.hgt` and **both sources read by identical
code**, so the comparison isolates the raster rather than two services'
interpolation. The reader was first checked against Valhalla `/height` on the
same EU-DEM tile — 179 m and 8.62% against the service's 179 m and 8.61% — and
only then used to judge anything.

| | EU-DEM v1 | GLO-30 |
|---|---|---|
| La Redoute, gain | 179 m | **181 m** (climbfinder: 180 m) |
| mean per-bin disagreement | — | 2.02 points (worst climb 3.04) |
| downhill bins on Mur de Huy, which never descends | 1 | **0** |

The gain match against an independent reference is the load-bearing result.
GLO-30 is **at least as good**, and the Mur de Huy row is a tiebreak rather than
an argument: one bin on one climb. The spread between the two is not one of them
being wrong — see the DSM note below.

**A correction worth keeping, owner 2026-08-04.** An earlier draft of this
section scored the sources on their total count of downhill bins, on the
reasoning that a climb descending in its middle is impossible. **That is false.**
Roche-aux-Faucons climbs to 228 m, descends to 185 m over more than a kilometre,
then climbs to 270 m; 27 of Hockai's bins read downhill and every one is real
rail-trail descent. The rule would have condemned the correct answer on both.

A downhill bin is only diagnostic on a road **known** to rise monotonically —
which is exactly why it was decisive against GLO-90 on La Redoute, a climb that
never descends. Everywhere else it is a question.

The measure that generalises is **disagreement about direction**: bins where the
two sources differ on whether the road rises. A descent both see is terrain; one
only a single source sees is an artifact — and it needs no prior knowledge of
the road, so it works on climbs nobody has profiled. Across all seven climbs the
sources dispute **33 of 266 bins**, and the split is clean: Hockai's 27 descents
are almost entirely agreed, while EU-DEM's lone downhill bin on Mur de Huy *and*
on Bohissau are both disputed, i.e. artifacts. `compare-sources.js` reports this
column.

**What adopting it retires.** Three things stop being problems rather than
getting solved:

- **EU-DEM's access terms.** Its readme places it under a GMES delegated
  regulation setting user registration conditions, not an open-data licence.
  GLO-30 Public is on the AWS Open Data registry and reads without an account.
  [§2c](#2c-licensing-is-a-gate-not-a-footnote) shrinks to one attribution.
- **Per-region raster inventory.** One dataset covers every country onboarded
  from here, so acquisition stops being a step in
  [country onboarding](catalog-data-model.md).
- **`demSource` as a variable.** It becomes a constant, and the bin floor in
  [§3a](#3a-bin-width-follows-the-source) becomes a single number rather than a
  table lookup.

**What it does not fix.** GLO-30 is a **Digital Surface Model** — the first
surface as illuminated by the sensors, so canopy and buildings, not bare ground.
On a wooded Ardennes climb a reading over a tree-lined stretch is partly the
trees. EU-DEM was a DSM too, so this is not a regression; it is a permanent
error term, and the most likely explanation whenever two good sources agree on a
total and disagree over a stretch. A **DTM** at useful resolution would be the
better input, and none is available worldwide.

**The Alps made that error term much larger, and it has a second cause.**
Seeding six Swiss passes on 2026-08-06 produced credible lengths and averages —
Nufenen measured 13.27 km at 8.5% against a published 13.4 km at 8.5%, Furka
10.31 km at 6.5% against ~10.1 km at 6.6% — beside **steepest-100 m figures of
35%** on Grimsel, Susten and Klausen. No pass road sustains that.

The cause is not canopy, it is **tunnels and avalanche galleries**. Where the
road runs under cover, a surface model reads the mountain on top of it. On the
Grimsel the profile climbs **768 m to 822 m in 140 m and then goes flat** — a
54 m step that is the hillside above a gallery, not tarmac. Alpine pass roads
are full of them, and roads cut into a cliff face give the same reading from the
wall beside the carriageway.

This is the Stockeu finding at a larger scale (GLO-30 27%, Wallonia LiDAR
16.7%): **an error that cancels over a whole climb but concentrates in its worst
hundred metres.**

**FIXED 2026-08-07, and the diagnosis was not the one expected.** The first
suspicion was sampling: `SAMPLES` is a fixed 200 regardless of length, so a
26 km climb samples every 130 m and a 100 m window spans less than one interval.
Measured, that turned out to be innocent — densifying Furka to 10 m, 20 m, 30 m
and 50 m spacing moved the published figure by less than half a point. Two other
things were guilty:

- **The window was below the source's resolution.**
  [§3a](#3a-bin-width-follows-the-source)'s own rule — never narrower than about
  four DEM cells — puts GLO-30's floor at 120 m, and the window was 100 m. It
  had always been asking the grid a question finer than its cells; short,
  unroofed Ardennes climbs simply hid it.
- **A maximum is an extreme-value statistic, and on a DSM the extreme is the
  artifact.** The tell is that the raw maximum got *worse* as sampling improved:
  denser sampling finds more spikes, not more road. Grimsel's raw window went
  from 35% to 77% between 50 m and 20 m spacing. Worse, 35% was not a
  measurement at all — Grimsel, Susten and Klausen all published exactly 35%
  because they were hitting a `min(35.0, …)` clamp, which turned three artifacts
  into three plausible-looking steep passes.

The published figure is now the **95th percentile of sliding 250 m windows**
(`MAX_WINDOW_M`, `STEEPEST_PERCENTILE`). Validated against the only two
independent truths available, and it hits both: Wallonia's 50 cm LiDAR puts
Stockeu's steepest at **16.7%** and we now read **16.7%**; the owner reports
Furka at **~10%** and we now read **10.3%**. Those are two points, in different
countries and different terrain, fitting one estimator — worth re-testing as
more ground truth appears, not treated as settled.

The clamp stays at 30% as a guard rather than a filter: above any real road's
sustained 250 m, so it fires only when the estimator itself is wrong, which is
when it should be visible.

`steepWindowM` travels with the figure and the caption is built from it, so the
copy can no longer say "100m" over a 250 m window — which is exactly what four
translation catalogues did until this landed.

**What this does not fix.** The tunnel readings are still in the data; the
percentile only stops one from becoming the headline. Grimsel still reads 14%
against a real ~11%, and it has the most galleries of the six. Carrying the
road's `tunnel`/`covered` OSM tags through from the route and refusing to measure
across them remains the real fix, and is not built.

Two further limits worth stating plainly. GLO-30 Public **withholds tiles over a
few countries**, so "worldwide" has holes and
[§2d](#2d-failure-is-honest) still has to hold. And the evidence is **one tile,
one massif, one latitude band** — Benelux is the widening that would confirm it,
via [tools/elevation](../../tools/elevation/README.md).

**EU-DEM's status.** The 37 GB of converted tiles already in place stay usable
and need not be deleted; they are simply no longer the plan. The EEA has
discontinued EU-DEM outright, marking it superseded and pointing at Copernicus
DEM, so this decision follows the publisher's own. For the record, v1.1 was also
measured and agreed with v1 to 0.54 of a point per bin — the finer grid bought
per-sample smoothness, not a better answer, so nothing was lost by never
acquiring it.

### 2a-i. What adopting GLO-30 actually costs

Checked 2026-08-04, because "register and rebuild" turns out to be the wrong
model of the work on both counts.

**No registration is required.** GLO-30 Public is on the AWS Open Data registry
at `s3://copernicus-dem-30m/` (eu-central-1) and reads without an account:
`aws s3 ls --no-sign-request s3://copernicus-dem-30m/`. Registering with the
Copernicus Data Space Ecosystem is what unlocks the *restricted* instances;
the public one does not need it. Note that GLO-30 Public withholds a small
subset of tiles over certain countries, so "worldwide" has holes and
[§2d](#2d-failure-is-honest) still has to hold.

**No Valhalla rebuild is required either — for profiles.** Elevation is read by
skadi from the directory named by `additional_data.elevation`, *separately from
the routing graph*. Adding or replacing elevation tiles is a file copy and a
restart, which is exactly what the existing EU-DEM procedure does. A **rebuild
is only needed if elevation should influence routing decisions** — hill-aware
bicycle costing — which is what `build_elevation` does at tile-build time. Those
are two different features and only the second is expensive.

**Conversion is required, and it resamples.** GLO-30 ships as Cloud Optimized
GeoTIFF; skadi expects SRTM-format `.hgt`. So it goes through the same GDAL step
EU-DEM does — the pipeline exists, the input changes.

The longitude decimation is **confirmed, not a caution**: the tile covering La
Redoute is **2400 × 3600**, i.e. 1.5″ in longitude against 1″ in latitude.
Copernicus does this deliberately, because meridians converge — at 50°N, 1.5″ of
longitude is ≈30 m of ground, the same as 1″ of latitude, so the cells stay
roughly square. `.hgt` cannot express that, since it is 1″ in both directions by
definition.

The conversion therefore **upsamples longitude 1.5″ → 1″**. That adds no
information but destroys none either, which is why the measured result holds up.
Two consequences worth carrying: the decimation factor **changes with latitude
band**, so a converter must not hard-code 2400 and must read each tile's actual
size; and a `.hgt` from GLO-30 is ~1.5× larger than its source information
warrants, so the storage table below is a floor rather than an estimate of
content.

**Storage is the real cost.** A 1 arc-second `.hgt` tile is 3601×3601×2 bytes =
**24.7 MB**, and the existing Europe set checks the arithmetic: 1517 tiles ×
24.7 MB = 36.6 GB against 37 GB measured. Global land is on the order of
14,000–26,000 tiles, so:

| scope | tiles | `.hgt` size |
|---|---|---|
| Europe (already converted) | 1,517 | 37 GB |
| global land | ~14,000–26,000 | **~340–630 GB** |

That is a different class of commitment from the current 37 GB, and it is
per-instance if the deployment stays regional. **Scope it to onboarded regions**
([country onboarding](catalog-data-model.md)) rather than the globe: the
Commons is worldwide in ambition, but coverage arrives country by country and
an unpopulated continent needs no raster.

### 2b. Start on the public API; self-host when something makes it necessary

Elevation is looked up when a climb's LINE changes — not per page view, not per
map render. Doing the arithmetic before reaching for infrastructure (owner,
2026-08-04):

- opentopodata's public endpoint allows **100 locations per call**, so a climb up
  to 2 km at 20 m sampling is **one call**. A 17 km climb is nine.
- The budget is **1000 calls/day**, which is roughly **65 climb contributions a
  day** at a generous 15 route-changes each — far beyond any volume this project
  will see before it has other reasons to run its own service.

So the public API is the starting point, and self-hosting is what the following
require rather than a precondition:

1. **A bulk recompute.** [§7](#7-migration) sweeps every climb with a route.
   That is seven items today and finishes in seconds; a catalogue of thousands,
   re-swept because the source or the binning changed, would take days at
   1000/day.
2. **Sustained contribution volume**, on the arithmetic above.
3. **Terms of use.** opentopodata asks heavy and production users to run their
   own instance. That is a courtesy this project extends to other people's
   infrastructure as a matter of course, and it is the most likely trigger of
   the three.

**One thing to get right on the public API:** the lookup fires on every resolved
route change, so a rider dragging a summit repeatedly can spend calls quickly and
meet the 1 call/second limit mid-edit. `recomputeProfile()` already aborts an
in-flight request when a newer one supersedes it; it also needs a settle delay so
a drag costs one lookup rather than one per intermediate position. Without that,
a throttled response shows a rider "profile unavailable" for a climb that is
perfectly fine.

### 2b-i. Self-hosting: Valhalla already does this

The stack **already runs Valhalla** — opt-in `routing` compose profile, today
with `build_elevation: "False"`. Valhalla serves `POST /height`, which takes a
shape and returns an elevation per point; with `range: true` it returns
cumulative distance alongside each height, which is precisely the input
[§3](#3-sampling-and-binning) bins. So the self-hosted option is not a new
service. It is a flag and a directory of tiles.

**This is proven, not theoretical.** The owner already runs a Valhalla instance
for another application, fed by a converter that reads the EU-DEM mosaic and
writes SRTM-format `.hgt` tiles into Valhalla's `additional_data.elevation`
directory. Measured against that instance, 2026-08-04, on the same road as
[§1b](#1b-the-elevation-source-is-too-coarse-for-the-bins-we-want-to-draw):

| | GLO-90 (public API) | Valhalla, EU-DEM `.hgt` | EU-DEM 25 m direct |
|---|---|---|---|
| distinct values | 30 / 99 | **83 / 99** | 100 / 100 |
| longest identical run | 7 | **3** | 1 |
| 100 m bins reading downhill | several, incl. −10% mid-climb | **0** | 0 |
| gain vs climbfinder (180 m) | — | **179 m** | 180 m |

Gain lands within a metre of the reference. The remaining coarseness is
explained and bounded, and it is **not** a resampling loss. EU-DEM v1 is already
published at 1 arc-second, which is exactly the `.hgt` grid, so the conversion is
grid-aligned and moves no cells. The single loss is that `.hgt` stores **integer
metres**. That costs per-sample fidelity — 83 distinct readings of 99 against
v1.1's 99 — and costs the aggregate figures nothing, and it is comfortably past
the bar [§3a](#3a-bin-width-follows-the-source) sets for 100 m bins, which GLO-90
fails. The read path still interpolates between cells, per
[§3d](#3d-where-the-coordinates-come-from-and-what-the-dem-returns); the rounding
is applied to the interpolated result.

**The single source removes this section's hardest constraint.** A Valhalla
instance has one elevation directory and cannot choose a source per coordinate,
which is why an earlier draft had to argue that per-continent instances happened
to match the source chain's partition. With [§2a](#2a-the-source) settled on one
worldwide dataset that argument is unnecessary: **every instance gets the same
tiles**, differing only in which part of the world they cover. `demSource` is a
constant, and a climb near a regional boundary cannot get a different answer
depending on which instance it reached.

**The trap: Valhalla fails by returning zeros.** An instance with no elevation
tiles loaded does not error — `/height` answers `0` for every point, which is a
valid-looking sea-level profile. The other application's client defends against
this by requiring at least half the samples to be non-zero before accepting a
result, and falling back otherwise. **Any client here must do the same**, and
it is why [§8](#8-testing) pins a known elevation rather than merely asserting
the call succeeded. A silent zero is worse than a failure, because
[§2d](#2d-failure-is-honest) cannot catch what does not report itself.

**Remaining alternatives**, if Valhalla ever stops fitting:

- **An opentopodata container** — speaks the API the public endpoint speaks, so
  adopting it is a base-URL change. Removes the daily budget and the per-call
  location cap without changing the client.
- **The `pipeline` tier** — [dev-environment.md §3](dev-environment.md) places
  "Rasters (rasterio/DEM)" there and the service's docstring already names *DEM
  sampling* as its job; `DEM_DIR` is mounted read-only with a `GET /dem` health
  check and nothing samples it yet. This is the only route that can select a
  source **per coordinate** rather than per instance, which matters only if
  regional partitioning proves too coarse.

Either alternative stays internal-only on an opt-in compose profile — an
unauthenticated elevation service has no business on a public port.

**The client must not care which it is talking to.** One setting names the base
URL, one names the source order; unset means the public endpoint. That is what
keeps this a deployment decision instead of a code change.

**What to prepare, in order:**

1. **Confirm licensing** ([§2c](#2c-licensing-is-a-gate-not-a-footnote)) — this
   gates acquiring the data at all, not just publishing it, and EU-DEM's credit
   is mandatory on every surface that shows a derived profile.
2. **Generate the tiles — or reuse the ones that exist.** The converter is GDAL
   over the GLO-30 tiles, scripted in
   [tools/elevation](../../tools/elevation/README.md). The existing 37 GB of
   EU-DEM `.hgt` does **not** carry over — it is a different dataset, so
   [§2a](#2a-the-source)'s decision means converting Benelux first and then
   whichever regions are onboarded, rather than topping up a Europe that is
   already done.
3. **Point `additional_data.elevation` at them** and set `build_elevation`, per
   instance.
4. **Then** the client, behind the base-URL setting, with the non-zero guard
   above and [§8](#8-testing)'s fixture test pinning the answer, so a source
   swap that silently changes La Redoute's gradient is caught.

**Step 4 is built** (2026-08-04). `App\Elevation\ElevationClient` posts to
Valhalla `/height` and `POST /contribute/elevation` exposes it to the editor,
login-gated. Both the base URL (`ELEVATION_URL`) and the attribution string
(`ELEVATION_DEM_SOURCE`) are environment settings, so changing dataset is a tile
swap and a restart rather than a deploy — and an unset URL disables profiles
rather than erroring, per [§2d](#2d-failure-is-honest).

The non-zero guard is the part with teeth, and it is tested directly: an
all-zero reply is refused, while a route that merely *touches* sea level is
kept, because only exact zeros count and a real climb is never mostly at exactly
sea level. A reply whose length does not match the request is refused too —
zipping mismatched arrays would attach elevations to the wrong coordinates,
which is a wrong profile rather than no profile.

**This also took the browser out of it.** The editor no longer calls a third
party directly, so the dataset is no longer whatever that API happened to serve,
the page's CSP lost an external host, and the sample count stopped being someone
else's cap — it is 200 now, which puts a 4 km climb at the ~20 m spacing
[§3c](#3c-sampling) asks for rather than the 43 m the old 100-point limit forced.

### 2b-ii. One Valhalla per continent, chosen by where the climb is

Elevation stopped being a single-endpoint question on **2026-08-06**, when the
rollout took the atlas to France, Switzerland, the United Kingdom, Italy,
Australia, Japan, California and Colorado.

The host runs **six Valhalla instances, one per continent**, and skadi reads
`additional_data.elevation` per instance. An instance therefore answers `/height`
for exactly the tiles in its own directory — and, per
[§2d](#2d-failure-is-honest), answers **zeros** rather than an error everywhere
else. Asking the Europe instance for a climb on Mount Buller is not a routing
inefficiency; it is a request that comes back looking like a flat climb at sea
level.

So the client picks the instance from the shape's coordinates
(`App\Elevation\ElevationEndpoints`, driven by `ELEVATION_URLS`;
`ELEVATION_URL` stays both the default and the master switch, so unsetting it
still disables profiles entirely). Three properties make this safe to get wrong:

- **The boxes are tile sets, not continents.** They are tested in a fixed
  priority order, and `europe` is first *because* the EUROPE tile set already
  covers Sicily and southern Spain, which a truthful Africa box would otherwise
  claim. The `europe` box is a copy of `fetch-glo30.sh`'s EUROPE bbox; the two
  must be widened together.
- **A continent with no configured instance falls back to the default** rather
  than resolving to nothing, and an unrecognised key in `ELEVATION_URLS` is
  dropped rather than trusted — a typo'd `occeania` must not look configured.
- **A misroute costs a missing profile, never a wrong one.** The wrong instance
  returns zeros and `MIN_NONZERO_SHARE` refuses them. This is the same guard that
  already protects against an un-tiled instance, now doing double duty.

The shape is routed by its **first point**. A climb is one road between a foot
and a summit, so it does not cross a continent; a shape that somehow did is still
answered by one dataset rather than stitched from two, which is the better of the
two failures.

### 2c. Licensing is a gate, not a footnote

Attribution requirements may not be assumed from memory. They must be read from
the distribution, recorded in
[osm-data-architecture.md](osm-data-architecture.md)'s licensing section, and
surfaced wherever a profile is displayed, **before this ships**. The project's
posture on data licences is deliberate and this is data.

**Copernicus DEM GLO-30**, the source [§2a](#2a-the-source) settles on:

- **Free for the general public** for the GLO-30 Public instance, under the
  Copernicus DEM Licence. Full terms live on the Copernicus Data Space COP-DEM
  collection page and **must be read before publishing** — the AWS registry
  entry links them rather than restating them.
- The AWS Open Data registry gives the citation form *"Copernicus Digital
  Elevation Model (DEM) was accessed on `DATE` from
  https://registry.opendata.aws/copernicus-dem."* That is the **registry's**
  citation for access, and it is not automatically the same thing as the
  licence's own attribution requirement. Both need checking; assuming the
  citation discharges the licence is exactly the shortcut this section exists to
  prevent.
- Where the credit belongs: on **every surface showing a derived profile** — the
  chart and any gradient figure computed from it — not on a licences page alone.

**Why this is now smaller than it was.** The previous source, EU-DEM, placed
access under a GMES delegated regulation setting *user registration conditions*
rather than an open-data licence — a materially heavier instrument. Adopting
GLO-30 retires that question rather than answering it. The EU-DEM notes are kept
below only because 37 GB of converted tiles still exist and could be used.

<details>
<summary>EU-DEM terms, retained for the tiles already converted</summary>

- Its credit is **mandatory and displayed**, in this exact wording:

  > Data funded under GMES preparatory action 2009 on Reference Data Access by
  > the European Commission, DG Enterprise and Industry.

- The requested citation is *European Commission - DG ENTR, 2012, EU-DEM Version
  1*. Both strings are v1-specific; v1.1 is an EEA product with different
  wording.
- Access is stated as "governed by Commission delegated regulation (EU) No
  12386/13 of 12.7.2013 supplementing Regulation (EU) No 911/2010 ... establishing
  registration and licensing conditions for GMES users". The instrument of that
  date supplementing 911/2010 appears to be Delegated Regulation (EU) **No
  1159/2013**, so the readme's number looks like an internal reference -
  **verify before relying on it**.

</details>

### 2d. Failure is honest

No elevation, no profile. A climb whose elevation lookup fails keeps its
geometry and displays no gradient figures at all — it never falls back to a
guess. The rider is told the profile could not be measured, and the submission
is still valid: the line is the contribution, the profile is derived from it.

---

## 3. Sampling and binning

### 3a. Bin width follows the source

**A bin is never narrower than four DEM cells.** Differencing two elevations one
or two cells apart measures the grid, not the road — which is precisely the
GLO-90 failure in [§1b](#1b-the-elevation-source-is-too-coarse-for-the-bins-we-want-to-draw).

| source | cell | minimum bin |
|---|---|---|
| EU-DEM 25 m | 25 m | 100 m |
| SRTM 30 m | 30 m | 120 m |
| GLO-90 | 90 m | 360 m |

This is an engineering guideline drawn from the measurements above, not a
theorem: at 100 m on GLO-90 the profile produced impossible descents, and at
≈220 m (2.5 cells) it was plausible but still noisy.

### 3b. A bar is a distance, not a fraction of the climb

**This is the rule, and it replaces a worse one** (owner, 2026-08-05). The
profile used to be **eleven equal slices of whatever the climb happened to be**,
so a bar meant ~220 m on a 2.4 km climb and 1.5 km on Hockai. Two charts looked
alike and were not comparable, and any short ramp was averaged flat on a long
climb.

Bars are now a real distance, from the ladder **{100, 150, 200, 250, 500, 1000,
2000} m**, taking the narrowest that keeps the chart to **25 bars or fewer**. So
a 2.4 km climb draws 24 bars of 100 m; past 2.5 km it steps to 150 m, and so on.
The last rung is a floor rather than a guarantee — an unusually long route draws
more bars instead of being silently truncated.

| climb | length | bin | bars |
|---|---|---|---|
| Mur de Huy | 1.39 km | 100 m | 14 |
| Côte de la Redoute | 2.08 km | 100 m | 21 |
| Roche-aux-Faucons | 4.24 km | 200 m | 22 |
| Hockai | 16.90 km | 1000 m | 17 |

**The average does NOT follow this ladder.** It stays at 100 m bins
([§4b](#4b-average-gradient-counts-only-the-climbing)), because a published
figure must not change merely because a climb grew long enough to be redrawn
with wider bars.

**The caption always states the bin width** ("per 100 m"). That was true before
and unenforced; with the width now varying by climb it is the difference between
a chart and a decoration, and it is the same fault as
[§1c](#1c-the-bars-mean-different-things-on-different-climbs).

**And the length beside the chart must be the measured one.** It stops at the
summit, while the drawn line may not — La Redoute displayed "2.4 km" above 21
bars of 100 m. `length` and `gain` are stored for exactly this reason
([§4](#4-what-is-measured-and-what-is-stored)).

**The caption always states the bin width** ("per 100 m"). A chart whose bars
silently mean different distances on different climbs is the same class of fault
as [§1c](#1c-the-bars-mean-different-things-on-different-climbs).

### 3c. Sampling

Sample the routed line at **one fifth of the bin width** (20 m for a 100 m bin),
so every bar averages five readings rather than differencing two. Long climbs
are batched against the self-hosted service; there is no 100-point ceiling.

### 3d. Where the coordinates come from, and what the DEM returns

Stated explicitly because it is the first thing a reader asks and nothing above
says it: **the elevation source never produces coordinates.** A DEM has no idea
a road exists. It is a lookup table — hand it a latitude and longitude, it
returns a height there. The trajectory comes entirely from routing.

The full chain for one climb:

1. The rider taps **foot** and **summit** on the map.
2. **The routing engine produces the line.** `climb-editor.js` calls OSRM with
   `overview=full`, which snaps those taps to the road network and returns the
   polyline actually ridden. This is the only step that decides *where* the
   climb goes. See [§3e](#3e-the-routing-call-is-external-and-client-side) — it
   is not a server-side call, and `ClimbGeometry` only validates what comes
   back.
3. **That polyline is resampled** to the interval in
   [§3c](#3c-sampling) — OSRM's vertices sit where the road bends, not at even
   spacing, so points are interpolated along it to get one every 20 m.
4. **Those points are sent to `/height`** as `shape`, with `range: true`.
   Valhalla returns one elevation per point *and* the cumulative distance to it,
   so no distance arithmetic is needed on our side.
5. **Bins are cut** from that distance/elevation series per
   [§3a](#3a-bin-width-follows-the-source)–[§3b](#3b-a-bar-is-a-distance-not-a-fraction-of-the-climb).

So step 2 owns the geometry and step 4 owns the heights, and they are
independent: a profile can be recomputed against a better DEM without re-routing,
and a re-drawn line gets a new profile without changing sources.

**What `/height` does with a `.hgt` file.** Tiles are named for their south-west
corner (`N50E005.hgt`) and hold a raw grid of 16-bit elevations — 3601×3601 for
one arc-second. A lookup takes the integer part of the coordinate to pick the
tile and the fractional part to index the grid, then **interpolates between the
four surrounding cells** rather than snapping to the nearest one. Measured
2026-08-04: walking 60 m in 2 m steps returned values changing every ~14 m on a
~6.7% slope — run length tracking the *gradient* rather than the 30 m cell size,
which is the signature of interpolation.

**But the reply is integer metres**, and that is a second, independent argument
for the [§3a](#3a-bin-width-follows-the-source) floor. Rounding to the metre is
±0.5 m on every reading regardless of how good the source raster is. Over a
100 m bin at 9% — 9 m of rise — that is ±0.5 of a percentage point, which is
tolerable. Over a 20 m bin it would be ±2.5 points, and the bar would be mostly
rounding error. Sampling at one fifth of the bin width
([§3c](#3c-sampling)) also helps here: averaging five readings dilutes the
quantisation that differencing two endpoints would keep at full strength.

### 3e. The routing call is external and client-side

Recorded because it is easy to assume otherwise, and because it constrains
everything above. As built today:

| | where it runs | endpoint |
|---|---|---|
| geometry | **the browser** (`climb-editor.js`) | `https://router.project-osrm.org` |
| elevation | **the browser** | `https://api.open-meteo.com` |
| validation | the server (`ClimbGeometry`) | — |

Three consequences.

**`router.project-osrm.org` is the OSRM project's public demo server.** It
carries no service guarantee and is explicitly not intended to back an
application. This is the same courtesy question [§2b](#2b-start-on-the-public-api-self-host-when-something-makes-it-necessary)
raises about opentopodata, and it has the sharper answer, because the stack
**already runs Valhalla** for [§2b-i](#2b-i-self-hosting-valhalla-already-does-this).
A Valhalla instance with routing tiles serves `/route` as well as `/height`, so
the same service can supply both and the demo-server dependency disappears.
Verified 2026-08-04: the existing European instance answers `/route` with
`"costing":"bicycle"` today, so this is a client change rather than an
infrastructure one.

**It requests the `driving` profile.** The URL is
`/route/v1/driving/…` — the only profile the demo server offers. A climb is
normally a road, so this is usually right; where it is wrong it is silently
wrong, routing around a surface a bike may use and a car may not. A cycling
profile is a reason to move to Valhalla independent of elevation.

**The server never sees the road.** `ClimbGeometry` decodes and validates the
posted payload — pairs of finite numbers, within `MAX_POINTS` — and by design
checks shape rather than truth. It cannot confirm the polyline follows a road,
because it never asked a router. So a route is a *contribution*, verified by
moderation like any other, not a computed fact. Any future server-side
recompute ([§7](#7-migration)) needs its own routing call rather than trusting
the stored line.

**Not used: `/route` with `elevation_interval`.** Valhalla can route and sample
elevation in a single call, returning both geometry and heights. That suits a
caller who has no geometry yet; here OSRM has already produced the line the
rider approved, and re-routing through a second engine could return a
*different* line than the one on screen. Worth knowing it exists — it is the
natural choice if climbs ever route through Valhalla too.

---

## 4. What is measured, and what is stored

Everything below is **derived**. None of it is a form field, and
`CatalogField::$derived` ([edit-items/B-climbs.md](edit-items/B-climbs.md))
is how the edit form is kept from offering a box for any of it.

| attribute | definition |
|---|---|
| `length` | great-circle length of the routed line |
| `gain` | summit elevation − foot elevation |
| `avgGradient` | **ascent only**: the sum of the climbing, over the length — see [§4b](#4b-average-gradient-counts-only-the-climbing) |
| `maxGradient` | the **steepest 100 m** — see [§5](#5-the-steepest-ramp-is-found-not-placed) |
| `grad` | per-bin gradients, bin width per [§3](#3-sampling-and-binning) |
| `elev` | elevation at each bin edge, for the silhouette |
| `binM` | the bin width in metres, so the chart can label itself |
| `demSource` | which source answered, for attribution and for the bin floor |

`elev` and `binM` do not exist today; `demSource` is the provenance flag whose
absence made [§1c](#1c-the-bars-mean-different-things-on-different-climbs)
undetectable. All three need adding to the letter-B vocabulary in
`AttributeVocabulary`.

**`headline` is retired.** It is a stored display string (`"2.0 km · 8.4% avg"`)
that nothing recomputes, so it drifts the moment a climb is redrawn. The drawer
composes that line from `length` and `avgGradient` at render time.

### 4a. The line must end at the summit

`gain` and `avgGradient` are only meaningful if the line stops climbing. La
Redoute's stored route does not: measured 2026-08-04, it runs **362 m past the
high point**, and those last metres descend — the final 100 m bin reads −7.4%.

The cost is not cosmetic. Over the stored line the climb averages **6.80%**;
trimmed at its summit it averages **8.61%** over 2080 m, against climbfinder's
9.0% over 2000 m. **Overshooting the top understates the climb by 1.8
percentage points** — an error several times larger than the difference between
the DEM sources [§2a](#2a-the-source) agonises over. Getting the source right
and the endpoint wrong still publishes a wrong number.

This is a rider-input problem, not a data problem: marking a summit a few
hundred metres late is easy and the map gives no feedback that it happened. So:

- **`length` and `gain` are measured to the highest point on the line**, not to
  its last point. The tail beyond the summit is excluded from every derived
  figure.
- **A trailing descent is a warning, not a silent trim.** If the line continues
  materially past its high point, the editor says so and offers to cut it there
  — the rider may have meant to include a dip, and a spec that quietly discards
  part of a contribution is the kind of thing [§1](#1-why-the-current-numbers-cannot-be-trusted)
  is written against.
- **"Materially" means metres LOST, not metres travelled** (owner, 2026-08-05).
  An earlier draft flagged on tail *distance*, and it was wrong: many climbs
  finish on a plateau, and **on flat ground the highest point is decided by DEM
  noise rather than by the road**, so its position wanders by whatever the
  quantisation happens to do. Roche-aux-Faucons is flat for its last 73 m; its
  tail is 130 m long and loses **1 m**, and the route is correct. La Redoute's
  tail is 361 m and loses **10 m**, and that is a descent. The threshold is on
  the drop — 8 m in `ClimbProfiler` — and for the same reason the summit is the
  **last** point at the maximum, not the first: a climb does not end where its
  plateau begins.
- **[§7](#7-migration)'s sweep must re-derive endpoints, not just elevations.**
  Every existing climb was drawn without this check.

**And the line must run uphill, which is a separate check.** Measured across
every climb with a route, 2026-08-04, and the two defects found have **different
origins** — which matters more than the count:

| climb | high point sits at | origin |
|---|---|---|
| Mur de Huy, Ereffe, Bohissau, Hockai, Stockeu | at or within 5 m of the end | correct |
| **Côte de la Roche-aux-Faucons** | **0%** | **seed data, stored backwards** — fixed |
| Côte de la Redoute | 90%, 361 m of descent | **a route drawn in the editor**, during testing |

An earlier draft of this section said "three of seven", counting Stockeu. Its
tail is **5 m**, which is noise rather than a defect, and the real figure was
never a count of seed errors at all:

- **Roche-aux-Faucons was a seed error** — hand-authored summit-to-foot in
  `SeedManualCatalogCommand`, starting at 242 m and ending at 181 m. One entry,
  now reversed at source.
- **La Redoute's overshoot is not in the seed at all.** The seeded route is
  1959 m and ends at its summit. The 361 m of trailing descent belongs to a
  route **drawn through the contribute wizard** while testing the moderation
  flow — which makes it the more important of the two, because it is what the
  live editor accepts from a real rider today, with no warning of any kind.

So this is a validation rule rather than a footnote, and the validation belongs
in the **editor**, not only in a migration sweep over seeded rows.

The reversed case is the dangerous one, because the trim rule above **fails
silently on it**. "Measure to the highest point" on a descending line puts the
summit at index 0, giving a length of 0 m, a gain of 0 m, and an average
gradient of 0% — numbers that are not obviously broken in a database column.
Guard it explicitly: if the high point is at or near the *start*, the line is
reversed, and the answer is to say so — offer to flip it — never to publish a
zero. A climb whose foot and summit are the same height is not a climb, and a
zero-length one is a bug report, not a measurement.

### 4b. Average gradient counts only the climbing

Owner decision, 2026-08-04. A climb with a dip in it has two defensible
averages and they are far apart:

| definition | Roche-aux-Faucons, redrawn |
|---|---|
| net gain ÷ length | 4.4% |
| **ascent only ÷ length** | **5.7%** |
| climbfinder, same road | 5.4% |

Net gain lets a descent cancel out the climbing either side of it, which is not
what the rider did — they climbed both ramps. Ascent-only is what climb sites
publish and what the legs remember, and it lands within 0.3 of a point of the
reference here. **We publish ascent-only.**

**Measured over ~100 m bins, not raw samples.** Ascent-only is noise-sensitive
by construction: every upward wobble in the DEM adds to the total and nothing
ever subtracts, so summing raw sample deltas inflates the figure on exactly the
wooded climbs whose readings are least trustworthy. Binning first is the same
defence as [§3a](#3a-bin-width-follows-the-source)'s floor, and it is stable —
the same climb reads 5.8% at 50 m bins, 5.7% at 100 m, 5.6% at 200 m, against
6.0% unbinned.

**One decimal place.** The average is the headline figure and whole percent
throws away a distinction riders care about; 8.6% and 9.4% are not the same
climb. The maximum stays whole, because it is one window's reading and that
precision is not real.

---

## 5. The steepest ramp is found, not placed

`steepestWindow()` slides a **100 m** window along the profile and returns the
maximum sustained gradient with its coordinate. **That is the default and only
behaviour**: the rider marks foot and summit, and the steepest ramp appears
where the measurement puts it.

### 5a. Two markers: one measured, one remembered

Proposed by the owner, 2026-08-05, and it resolves the Mur de Huy problem
properly rather than by wording.

The measurement above and a rider's knowledge answer **different questions**, and
the section on the max ramp shows the DEM cannot answer the second one at all:
Mur de Huy's Chapelle hairpin is smaller than a GLO-30 cell, so no window width
recovers its 26%. That is not a precision we can reach by computing harder. It is
information the dataset does not contain and a rider does.

So there are two markers:

| | placed by | means |
|---|---|---|
| **steepest 100 m** | us, automatically | the steepest sustained 100 m the DEM can see — comparable across every climb, and comparable with climb databases |
| **steepest point** | a rider, by hand | where the wall actually is, on a road they have ridden |

Ours stays ours: derived on every redraw, never edited, and it is the figure the
catalogue publishes and sorts on, because it is the only one measured the same
way everywhere. Theirs is a contribution — a distinct icon at a place they
choose, going through the same moderation as any other, and it can carry a
gradient the DEM cannot see.

**Why not just let riders correct our number.** Because then the published field
means something different on every climb, depending on whether anyone happened
to edit it — which is exactly how the catalogue ended up publishing four
different definitions of "max gradient" and calling them one field
([§1a](#1a-the-published-figures-were-typed-by-hand)). Two fields with two
honest definitions beat one field with a negotiable one.

**Built 2026-08-05.** Stored as `steepPoint` — `{at, pct, note}` — beside
`steep`, never merged with it:

- **The percentage is optional.** A rider may know *where* the wall is without
  knowing how steep, and demanding a number invites an invented one. `note`
  gives them somewhere to say what they do know ("the hairpin after the
  chapel"), bounded to 120 characters because it is a landmark, not a paragraph.
- **A distinct icon**, amber and filled against the derived marker's purple
  triangle, in the editor and on the map. Two markers that meant different
  things and looked alike would be worse than one.
- **It never moves on its own.** Every other derived value is recomputed on
  redraw; this one is only ever placed, dragged or cleared by a rider, and it
  survives an edit to anything else on the climb.
- **It travels as geometry**, through the same submission and moderation path as
  the line itself — no new review mechanic
  ([moderation-and-contribution.md](moderation-and-contribution.md)).

**Still open:** whether a climb may carry more than one, and whether the rider's
figure should ever appear in listings or sorting. It does not today, and that is
the safe default — a field that means the same thing everywhere is what can be
sorted on.

The third tap survives as an **override**, for the case the rider is on the road
and the model is not: a marker they move is flagged `manual: true` and keeps its
position, with its percentage re-read from the profile at that point (already
the behaviour in `climb-editor.js`). An automatic marker is re-derived whenever
the line changes.

**And it is labelled "steepest 100m", not "max gradient"** (owner, 2026-08-05).
The name was doing damage the number could not fix: "max gradient" invites
comparison with a **point** maximum, so Mur de Huy's famous ~26% — its steepest
hairpin — read as a contradiction of our 19%, when the two simply measure
different distances. Naming the window settles it, and it is why the figure
ships without a `~`: a tilde on a value whose measurement distance is stated is
hedging about something that is not uncertain. The average ships plain for the
same reason, which closes the accidental split
[§9](#9-owner-decisions-still-open) flagged.

**Could we publish a shorter "max ramp" as well?** Measured on the live GLO-30
service, 2026-08-05, at four window widths:

| climb | 25 m | 50 m | 100 m | 150 m |
|---|---|---|---|---|
| Mur de Huy | 20% | 20% | **20%** | 18% |
| Côte de Stockeu | **41%** | 38% | 27% | 23% |
| Côte de la Redoute | 21% | 18% | 17% | 16% |
| Côte d'Ereffe | 23% | 21% | 19% | 16% |

**No — not from this data.** GLO-30's cells are ~30 m, so a 25 m window is *less
than one cell*, and the table shows exactly the two failures that predicts.
Shortening the window does **not** recover Mur de Huy's famous 26% — the DEM
cannot see that hairpin at any width, because the hairpin is smaller than a
cell. And it invents **41%** on Stockeu, a gradient no paved road has. A shorter
window yields a bigger number, not a truer one, which is
[§1b](#1b-the-elevation-source-is-too-coarse-for-the-bins-we-want-to-draw)'s
failure one scale down.

What would actually measure a ramp is a finer source — Wallonia publishes 1 m
LiDAR terrain data — or a rider with a known-good device. Both are real options
and neither is this dataset. Until then, publishing a 25 m figure would be
inventing precision.

**Why 100 m** (owner, 2026-08-04). It was 150 m, chosen only as a
noise-averaging distance. But the window is not a free parameter: climb
databases publish the steepest **100 m**, so any longer window reads
systematically gentler than every other source describing the same road, and a
rider comparing us against climbfinder sees us understate a climb they know.
Matching the convention is worth more than the marginal smoothing.

It still is not the same number as the worst display bar. The bars are eleven
equal slices of the *whole* climb, so on a 4 km climb each spans ~360 m and
averages a short ramp flat; the marker is a fixed 100 m wherever it falls. Both
are true, they measure different distances, and the marker is the one that
answers "how steep does this get".

---

## 6. The chart

### 6a. A descent must look like a descent

The bars carried no zero. Each was `10 + (p/max)*30` pixels with a 6-pixel
floor, so a **−15% bin rendered as a short bar pointing the same way as every
climbing one** — a chart that says "gentle rise" where the road drops. On a
climb that genuinely descends between two ramps, which is what prompted this
([§2a](#2a-the-source)'s correction), the profile told the opposite of the truth.

Bars now hang below a baseline where the gradient is negative, and the dashed
zero line is drawn only when something actually descends — an ordinary climb is
not decorated with a rule that explains nothing.

**The line and the bars share one series** (owner, 2026-08-05): the map takes
its colour from the bars, so the same stretch of road is the same colour in both
places. They were separate — bars at fixed bin boundaries, line from a window
sliding every ~25 m — and disagreed about the colour band on up to **11 of 21
bars**, which is indefensible when they are two pictures of one profile.

**A residual worth knowing about, not yet decided.** The steepest-ramp marker is
still the true steepest 100 m at *any* offset, and a ramp that straddles a bin
boundary is split between two bars. On Côte de Stockeu the marker reads 27% while
sitting on a 14% bar, because its window spans two of them; the neighbouring bar
reads 23%. Two ways out, and they trade against each other:

- **Publish the sliding maximum** (today). Truer to the road — 27% really is
  there over 100 m — but not verifiable from the chart a reader is looking at.
- **Publish the steepest bar.** Verifiable by eye and always consistent with the
  marker's position, at the cost of understating a ramp that happens to straddle
  a boundary: Stockeu would read 23% rather than 27%.

This is an editorial call about which kind of wrongness is worse, and the
published figures have moved enough already. Recorded rather than decided.

*(Previously, and kept because the reasoning still holds:)* The
map line used to take its colour from the chart's bins, and the steepest-ramp
marker then landed *off* the darkest stretch — on La Redoute the marker read 17%
at 970 m while the darkest band sat at 1100 m reading 15%, two different pieces
of road (owner-reported 2026-08-05). Neither number was wrong: the marker slides
its window to any offset, and a fixed bin cannot.

So `lineGrad` colours the line — the sustained gradient measured at each band's
own centre — while `grad` keeps its fixed bins for the chart, because columns
have to be comparable between climbs. The darkest part of the line is now the
steepest part of the road by construction, which is where the marker is: across
all seven climbs the marker and the darkest band land in the **same colour
band**, and on La Redoute at the same point.

Three details mattered, and all three were found by looking at the map rather
than at the numbers:

- **The band gradient must be measured at its centre *distance***, not by
  resolving that centre to the nearest sampled coordinate. That quantisation
  alone under-read La Redoute's peak by a whole colour step.
- **`lineGrad` and the drawn geometry must cover the same extent.**
  `line-progress` runs 0..1 over whatever is rendered, so bands measured over a
  different length get stretched — measuring to the summit while drawing the
  whole route pushed the darkest band **145 m past** the marker. The fix is to
  make both the *climb*: the map draws foot-to-summit, which is what every
  published figure already describes. Drawing the whole stored line instead put
  a 2.44 km line with a descending blue tail beside a chart and a length that
  both said 2.1 km (owner-reported 2026-08-05). The overshoot stays in `route` —
  it is a contribution, and [§4a](#4a-the-line-must-end-at-the-summit) warns
  rather than discarding — but it is not part of the climb, so it is not drawn
  as one.
- **The steepest window must slide by a fixed step**, not from route vertex to
  route vertex. A routing engine puts vertices where the road bends, so a
  straight has almost none: on Côte d'Ereffe no vertex fell near 660 m, the
  17.7% window there was never evaluated, and the marker landed **634 m away**
  on a 17% stretch. Stepping by distance also makes the marker and the line
  agree by construction, because both now measure the same way.

Verified across all seven climbs: every marker now sits within one colour band
of the darkest stretch, and within 20 m of it on six of them. The seventh is
Hockai, where 167 m is a little over one band on a 17 km climb whose top
windows are all within 0.1 of a point of each other.

**The chart carries a distance axis.** With bars at a real width
([§3b](#3b-a-bar-is-a-distance-not-a-fraction-of-the-climb)) a profile can be
read as a *position* along the climb, not only as a silhouette — which is what
lets the steepest-ramp marker on the map correspond to something on the chart.
Ticks are one per kilometre, or per 500 m under 1.5 km, and an interior tick
that would crowd the end label is dropped rather than printed: a 2.1 km climb
showing "2" beside "2.1 km" is noise. Each bar's tooltip names its stretch
("1.2–1.3 km · 12%"), because on a 22-bar chart "12%" alone leaves the reader
counting bars.

**Both directions share one scale.** A −12% bar is exactly as long as a +12%
one. Scaling each side to its own extreme would make a shallow dip look as
dramatic as the steepest ramp on the climb, which is the same class of dishonesty
as the missing zero.

**A climbing bar rests ON the baseline, not on the floor of the strip.** Worth
stating because the first implementation got it wrong in a way that looked
plausible: bottom-aligning each bar to its column put every climbing bar below
the zero line, so the second half of a climb that dips and rises again read as
though it were still descending (owner-reported 2026-08-04). Verified in a
browser rather than by inspection — every climbing bar's lower edge and every
descending bar's upper edge measure 0 px from the baseline.

### 6b. Descents are blue

Colour carried no direction. `gradColor()` was a single purple ramp keyed on
gradient, and since every negative falls in its first band, **a −12% drop wore
the same pale purple as a 3% rise** — on the strip *and* on the map line. The
dip was invisible unless you read the bar heights, which before
[§6a](#6a-a-descent-must-look-like-a-descent) it did not have either.

Descending gradients now use a blue ramp: `#A6CFF2` → `#5BA0E8` → `#2B76D0` →
`#184F9F` → `#0A2A66`. It mirrors the purple exactly — **the same five
|gradient| thresholds and the same light-to-dark progression** — so steepness
reads identically in either direction and only the hue says which way the road
goes. A rider who has learnt that dark means steep does not have to learn a
second scale.

This is in `gradColor()` itself rather than in the drawer, so **the map line and
the profile strip agree**: the same stretch of road is the same colour in both,
and a climb that descends shows blue on the map where it descends.

The reference is the industry-standard climb profile (climbfinder, and the
same shape used by every climbing site):

- **Bars at fixed distance**, one per bin, coloured on a gradient scale from
  yellow through orange to dark red.
- **The gradient printed on each bar**, in whole per cent.
- **The elevation silhouette** drawn over the bars from `elev`.
- **A distance axis** in kilometres, and the **foot and summit elevations**
  labelled at the ends.
- **The steepest ramp marked** on the bar containing it, distinctly, with its
  own percentage — it is not the bar's value.
- **A caption stating the bin width and the source**, e.g.
  `per 100 m · EU-DEM 25 m`.

This replaces `gradStrip()` in `web/assets/map/drawer.js`, which draws
unlabelled bars with no axis and no silhouette.

---

## 7. Migration

1. Stand up the elevation service ([§2](#2-the-elevation-service)); confirm
   licensing first.
2. Recompute every letter-B item that has a `route`, writing the derived set
   from [§4](#4-what-is-measured-and-what-is-stored).
3. **Delete the hand-authored values** — `grad`, `avgGradient`, `maxGradient`
   and `headline` — from `SeedManualCatalogCommand` and from any row a recompute
   did not reach. A climb with no route cannot be measured and must show no
   gradient figures rather than the old ones.
4. Only then build the chart ([§6](#6-the-chart)). Drawing 100 m bins on today's
   data would publish a 10% descent in the middle of La Redoute.

Order matters: steps 2 and 3 are what *"only use the measured data"* means, and
the chart is only honest once they are done.

---

## 8. Testing

- **Unit** — binning: bin width chosen per [§3a](#3a-bin-width-follows-the-source)
  and [§3b](#3b-a-bar-is-a-distance-not-a-fraction-of-the-climb) for a 500 m, a 2 km and a 17 km
  climb; a bin never narrower than four cells of the answering source.
- **Unit** — `steepestWindow` finds a planted ramp; a `manual` marker survives a
  re-profile and an automatic one moves.
- **Unit** — no elevation yields no figures, never zeros or a fallback.
- **Regression** — La Redoute's first 2.0 km measures 9.0% ± 0.2 and 180 m ± 5 m
  against a recorded EU-DEM fixture. That is the number three sources agree on,
  and it is what the seeded 8.4% failed.
- **Browser** — the climb editor must be exercised in a real browser. A headless
  probe reported green against a broken geometry path for hours
  ([edit-items/B-climbs.md](edit-items/B-climbs.md) records why).

---

## 9. Owner decisions still open

- **Load the GLO-30 tiles on the Valhalla host.** Benelux is downloaded and
  converted — 26 `.hgt` tiles, 644 MB — but the running
  instance still answers from EU-DEM, verified 2026-08-04 by comparing its
  replies against both local tile sets (60/60 samples matched EU-DEM). Until the
  tiles are in the directory `additional_data.elevation` names, the source
  decided in [§2a](#2a-the-source) is not the one riders' numbers come from.
  Note both datasets use identical `N50E005.hgt` filenames and identical file
  sizes, so **only the timestamp distinguishes them** — copying into the wrong
  directory fails silently rather than erroring.
- **Widen the GLO-30 evidence geographically.** Benelux tiles exist and were
  compared against EU-DEM, but **every seeded climb sits in one tile**
  (`N50E005`), so that run re-tested the same ground rather than widening it.
  The Netherlands is the interesting test — the decimation factor changes with
  latitude — and it needs climbs there to test *with*.
- **`headline` is still stored and still wrong.** [§4](#4-what-is-measured-and-what-is-stored)
  retires it, and nothing recomputes it: Roche-aux-Faucons reads
  `1.5 km · 9% avg` on a line now measured at 4.4 km and 5.6%. The drawer
  composes its own length so riders do not see it, which is exactly what makes
  it easy to leave — it is wrong data sitting in the attribute, waiting for
  something to read it.
- **Recompute cadence** — on submission only, or a periodic sweep as DEM sources
  are updated.
- **Whether the changed numbers need saying out loud.** The sweep ran on
  2026-08-05 and moved figures riders recognise — Stockeu from `9%+` to 14.0%,
  Mur de Huy's maximum from `~26%` to 19% under its new name. The naming carries
  most of the explanation, but whether a rider who knew the old numbers gets
  told *why* they changed is still an editorial call rather than a technical one.

*(Closed: the `~` question — measured values ship plain, and the maximum states
the distance it is measured over, so there is nothing left to hedge. See
[§5](#5-the-steepest-ramp-is-found-not-placed).)*
