<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Climb Elevation & Profiles

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

> **Planned** (2026-08-04). Nothing in this document is built yet. The
> measurements in [§1](#1-why-the-current-numbers-cannot-be-trusted) were taken
> against the live dev stack and are the evidence the design rests on; the rest
> is the contract to build against.

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

### 1b. The elevation source is too coarse for the bins we want to draw

The profile comes from `profileFromRoute()` in
`web/assets/contribute/climb-elevation.js`, which samples the drawn line at 100
points and calls open-meteo's elevation endpoint. That serves Copernicus
GLO-90 — a **D**igital **E**levation **M**odel (DEM) on a roughly 90 m grid.

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

### 1c. The bars mean different things on different climbs

Seeded climbs carry hand-authored `grad` arrays of 10, 11 or 12 values. Editor-
drawn climbs carry 11 values sampled from real elevation. Nothing in the payload
distinguishes an estimate from a measurement, and both render identically.

---

## 2. The elevation service

### 2a. Source chain

One service, resolved per coordinate, best available first:

| order | source | resolution | coverage |
|---|---|---|---|
| 1 | EU-DEM v1 | 1 arc-second (~30 m) | Europe (EEA members + cooperating states) |
| 2 | SRTM | 30 m | 60°N to 56°S |
| 3 | Copernicus GLO-90 | 90 m | worldwide |

The Commons is worldwide from day one
([osm-data-architecture.md](osm-data-architecture.md)), so a Europe-only source
cannot be the only source. GLO-90 stays as the floor because a coarse profile
beats none — but see [§3a](#3a-bin-width-follows-the-source) for what it is
allowed to draw.

**Row 1 is v1, at 1 arc-second, and that is deliberate.** An earlier draft of
this table said "EU-DEM v1.1, 25 m", which is a real dataset but not the one
measured anywhere in this document. Both were then compared on La Redoute,
2026-08-04:

| | EU-DEM v1 (1 arc-sec) | EU-DEM v1.1 (25 m) |
|---|---|---|
| gain to summit | 179.0 m | 178.8 m |
| average | 8.61% | 8.59% |
| 100 m bins reading downhill | 0 | 0 |
| distinct readings | 83 / 99 | 99 / 99 |

They agree to **0.54 of a percentage point per 100 m bin**, and both land within
1.2 m of climbfinder's 180 m of gain. v1.1's finer grid shows up as per-sample
smoothness, not as a better answer — and v1's coarser count is the integer-metre
reply of [§3d](#3d-where-the-coordinates-come-from-and-what-the-dem-returns),
not the raster. So v1.1 is not worth acquiring for the accuracy, and the EEA
has **discontinued EU-DEM entirely**, marking it superseded and directing users
to Copernicus DEM. Adopting v1.1 would mean paying a migration to land on a
dataset that is equally dead.

**Both are Digital Surface Models.** EU-DEM's own readme describes it as "the
first surface as illuminated by the sensors" — canopy and buildings included,
not bare ground. On a wooded Ardennes climb the reading over a tree-lined
stretch is not the road. This is not a defect to fix by changing EU-DEM version,
because Copernicus DEM is a DSM too; it is a known error term, and it is the
most likely explanation for any residual disagreement with a surveyed profile.

**Row 3's successor is measured, and it works.** Copernicus DEM GLO-30 is the
dataset EEA points to: 30 m, worldwide, and the same TanDEM-X source GLO-90 is a
3× downsample of — so
[§1b](#1b-the-elevation-source-is-too-coarse-for-the-bins-we-want-to-draw)'s
verdict on GLO-90 never applied to it. Tested 2026-08-04 on all seven seeded
climbs, by converting the GLO-30 tile to `.hgt` and reading **both sources with
identical code**, so the comparison isolates the raster rather than two services'
interpolation. (The reader was checked against Valhalla `/height` on the same
EU-DEM tile: 179 m and 8.62% against the service's 179 m and 8.61%.)

| | EU-DEM v1 | GLO-30 |
|---|---|---|
| La Redoute, gain | 179 m | **181 m** (climbfinder: 180 m) |
| bins reading downhill, short climbs | 2 | **0** |
| mean per-bin disagreement | — | 1.96 points (worst climb 3.04) |

Over the six climbs with a measurable length; the seventh is the reversed line
below, which has no bins to compare.

GLO-30 is **at least as good**, and marginally cleaner: it produced no impossible
descent on any short climb, while EU-DEM produced one each on Mur de Huy and
Bohissau. The spread is not one of them being wrong — both are DSMs disagreeing
over tree cover, per the note above.

**So the chain in this table can collapse to a single worldwide source.** That
removes the per-region raster management of
[§2b-i](#2b-i-self-hosting-valhalla-already-does-this), removes EU-DEM's
regulated-access question from [§2c](#2c-licensing-is-a-gate-not-a-footnote), and
means one dataset covers every country onboarded from here. The remaining reason
to keep EU-DEM is that its tiles already exist. What is *not* yet established is
whether this holds outside the Ardennes — one tile, one massif, one latitude
band. [§2a-i](#2a-i-what-adopting-glo-30-actually-costs) is the acquisition path,
and Benelux is the first widening.

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

**It satisfies [§2a](#2a-source-chain) by deployment rather than by dispatch.**
A single Valhalla instance has one elevation directory and cannot choose a
source per coordinate — but the existing deployment is already **one instance
per continent**, which is the same partition the source chain describes. EU-DEM
tiles in the European instance, SRTM tiles elsewhere, GLO-90 as the floor:
the chain becomes a question of which tiles each instance is given, and
`demSource` is then a property of the instance that answered rather than of
the reply.

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
   over the EU-DEM mosaic. **Europe is already done**: the owner's existing
   conversion produced **1517 `.hgt` tiles, 37 GB**, covering the full EU-DEM
   extent. So this step is already paid for in Europe, and the outstanding work
   is the rest of the world, where the source is row 2 or 3 of
   [§2a](#2a-source-chain) rather than EU-DEM.
3. **Point `additional_data.elevation` at them** and set `build_elevation`, per
   instance.
4. **Then** the client, behind the base-URL setting, with the non-zero guard
   above and [§8](#8-testing)'s fixture test pinning the answer, so a source
   swap that silently changes La Redoute's gradient is caught.

### 2c. Licensing is a gate, not a footnote

EU-DEM is Copernicus data and SRTM is United States government data. Neither
attribution requirement may be assumed from memory: both must be read and
recorded in [osm-data-architecture.md](osm-data-architecture.md)'s licensing
section, and surfaced wherever the profile is displayed, **before this ships**.
The project's posture on data licences is deliberate and this is data.

Two specifics worth carrying, from the EU-DEM distribution's own readme:

- Its credit is **mandatory and displayed** — it must appear on every surface
  showing EU-DEM-derived data, which here means the profile chart and any
  gradient figure derived from it, not a licences page alone. A compact form
  linking to the full credit satisfies it; omitting it does not.
- **Version matters for the citation**, and [§2a](#2a-source-chain) settles it
  as **v1**. The exact strings the distribution asks for:

  > Data funded under GMES preparatory action 2009 on Reference Data Access by
  > the European Commission, DG Enterprise and Industry.

  is the mandatory displayed credit, and the requested citation is *European
  Commission – DG ENTR, 2012, EU-DEM Version 1*. Both are v1-specific; v1.1 is
  an EEA product and carries different wording, so the credit must be re-checked
  if the source ever changes.

- **Access is regulated, not merely licensed.** The readme states access is
  "governed by Commission delegated regulation (EU) No 12386/13 of 12.7.2013
  supplementing Regulation (EU) No 911/2010 … establishing registration and
  licensing conditions for GMES users". The instrument of that date supplementing
  911/2010 appears to be Delegated Regulation (EU) **No 1159/2013**, so the
  readme's number looks like an internal reference rather than the citation —
  **verify before relying on it**. Either way this is a regulation setting user
  conditions, not an open-data licence, and it must be read before derived
  profiles are published rather than after.

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

### 3b. Long climbs get wider bins

100 m bins on a 17 km climb — and Wallonia's longest seeded climb is 17 km —
would be 170 bars, which is a texture, not a chart. Pick the **smallest bin from
{100, 200, 250, 500, 1000} m that yields at most 40 bars**, subject to the floor
in [§3a](#3a-bin-width-follows-the-source).

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
   [§3a](#3a-bin-width-follows-the-source)–[§3b](#3b-long-climbs-get-wider-bins).

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
| `avgGradient` | `gain / length` |
| `maxGradient` | the steepest sustained window — see [§5](#5-the-steepest-ramp-is-found-not-placed) |
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
the DEM sources [§2a](#2a-source-chain) agonises over. Getting the source right
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
- **[§7](#7-migration)'s sweep must re-derive endpoints, not just elevations.**
  Every existing climb was drawn without this check.

**And the line must run uphill, which is a separate check.** Measured across all
seven seeded climbs, 2026-08-04:

| climb | high point sits at | |
|---|---|---|
| Mur de Huy, Ereffe, Bohissau, Hockai | 100% along | correct |
| Côte de Stockeu | 97% | mild overshoot |
| Côte de la Redoute | 90% | 362 m overshoot |
| **Côte de la Roche-aux-Faucons** | **0%** | **stored backwards** |

Roche-aux-Faucons runs summit to foot: it starts at 242 m and ends at 181 m. So
**three of seven** stored climbs have an endpoint defect, which makes this a
validation rule rather than a footnote.

The reversed case is the dangerous one, because the trim rule above **fails
silently on it**. "Measure to the highest point" on a descending line puts the
summit at index 0, giving a length of 0 m, a gain of 0 m, and an average
gradient of 0% — numbers that are not obviously broken in a database column.
Guard it explicitly: if the high point is at or near the *start*, the line is
reversed, and the answer is to say so — offer to flip it — never to publish a
zero. A climb whose foot and summit are the same height is not a climb, and a
zero-length one is a bug report, not a measurement.

---

## 5. The steepest ramp is found, not placed

`steepestWindow()` already slides a 150 m window along the profile and returns
the maximum sustained gradient with its coordinate. **That becomes the default
and only behaviour**: the rider marks foot and summit, and the steepest ramp
appears where the measurement puts it.

The third tap survives as an **override**, for the case the rider is on the road
and the model is not: a marker they move is flagged `manual: true` and keeps its
position, with its percentage re-read from the profile at that point (already
the behaviour in `climb-editor.js`). An automatic marker is re-derived whenever
the line changes.

The 150 m window is why `maxGradient` (≈20%) legitimately exceeds the worst
100 m bin (16%): a bar is an average over its bin, the marker is the worst
sustained stretch. Both are true and they are not the same measurement.

---

## 6. The chart

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
  and [§3b](#3b-long-climbs-get-wider-bins) for a 500 m, a 2 km and a 17 km
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

- **Whether to skip the public API entirely.**
  [§2b-i](#2b-i-self-hosting-valhalla-already-does-this) settled *which* self-hosted
  route — Valhalla `/height`, already in the stack and already proven with EU-DEM
  tiles elsewhere. That makes [§2b](#2b-start-on-the-public-api-self-host-when-something-makes-it-necessary)'s
  staging debatable: the public endpoint was the starting point because
  self-hosting looked like new infrastructure, and it is not. Starting on
  Valhalla costs a tile generation run and skips both the daily budget and the
  throttle-mid-drag problem. Still open: whether the tiles cover every onboarded
  region or only Europe at first, everything else falling to the public API.
- **Adopt GLO-30 as the single source, or keep the chain?** GLO-30 is now
  measured and is at least as good as EU-DEM on all seven seeded climbs
  ([§2a](#2a-source-chain)), which makes a one-source worldwide model possible.
  The evidence is one tile, one massif, one latitude band — Benelux is the
  widening that would confirm it, via
  [tools/elevation](../../tools/elevation/README.md). Deciding *yes* retires
  EU-DEM's regulated-access question and the per-region raster management;
  deciding *no* keeps 37 GB of tiles that already exist.
  *(The EU-DEM version question is closed: v1, measured equivalent to v1.1 —
  see [§2a](#2a-source-chain).)*
- **Fix the three defective stored climbs**
  ([§4a](#4a-the-line-must-end-at-the-summit)) — Roche-aux-Faucons is stored
  backwards and two others overshoot their summit. Whether that is a data
  repair now or falls out of [§7](#7-migration)'s sweep is a sequencing call.
- **Recompute cadence** — on submission only, or a periodic sweep as DEM sources
  are updated.
- **`~` in published gradients** — measured values are numbers; the catalog's
  editorial strings (`~20% (mid-climb ramp)`) carry an approximation marker and
  a parenthetical. Once figures are measured, is the tilde still wanted, and
  does the parenthetical survive as separate prose?
