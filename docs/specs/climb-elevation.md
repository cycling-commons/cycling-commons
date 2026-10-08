<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Climb Elevation & Profiles

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

> **Built.** `App\Elevation\ElevationClient` reads [§2a](#2a-the-source)'s
> source through Valhalla, `App\Elevation\ClimbProfiler` is the single
> implementation of everything in [§3](#3-sampling-and-binning)-[§5](#5-the-steepest-ramp-is-found-not-placed),
> and `app:climbs:recompute` re-measures every climb with a line (dry run by
> default). No seeded climb types a measured value
> (`SeedManualCatalogCommandTest::testNoSeededClimbTypesAMeasuredValue`), so a
> freshly seeded climb shows no gradient until `app:climbs:recompute --write`
> runs. [§9](#9-owner-decisions-still-open) lists the calls left.

A rider marks two points, the **foot** and the **summit**. Everything else
about the climb is measured: its length, its height gain, its average and
steepest gradient, the shape of its profile, and where its steepest ramp is.
Nobody types a gradient.

This document owns how that measurement is done and how the result is
displayed. The climb's *editing* flow (the editor, the wizard steps, the
moderation path) is owned by [edit-items/N-climbs.md](edit-items/N-climbs.md);
the *catalog* rules for what an item may store are owned by
[catalog-data-model.md](catalog-data-model.md).

---

## 1. Why every figure is measured

The evidence behind the rule that nobody types a gradient, measured on the
Belgian seed climbs in August 2026.

### 1a. Hand-typed figures look like measured ones

The first catalogue carried climb figures typed into the seed by hand. Checked
against the route stored in the same seed entry:

| climb | published | measured from its own line |
|---|---|---|
| Côte de la Redoute | 2.0 km · 8.4% | 1.96 km · 9.2% |
| Mur de Huy | 1.3 km · 9.3% | 1.39 km · 9.7% |
| Côte de Stockeu | ~1.0 km · 9%+ | 1.00 km · **14.0%** |
| Côte de la Roche-aux-Faucons | 1.5 km · 9% | 1.75 km · **3.2%** |

Four climbs that range from 3.2% to 14% were all published at about nine
percent: plausible-looking numbers chosen to look like climb gradients. **A
plausible number is indistinguishable from a measured one** once it is on the
page. Even the lengths were typed: nothing derived the headline from the
geometry it shipped with. And where the line itself is wrong, neither number is
the truth (Roche-aux-Faucons' stored line was not the climb), so fixing the
source without fixing the line ([§4a](#4a-the-line-must-end-at-the-summit))
only measures the wrong thing precisely.

So seeds carry lines and no measured values, and `app:climbs:recompute` is the
only writer of the figures ([§7](#7-measuring-the-catalogue)).

### 1b. The elevation source is too coarse for the bins we want to draw

A 90 m elevation grid (Copernicus GLO-90) sampled every 25 m produces a
staircase. On La Redoute: 30 distinct values across 99 samples, runs of up to 7
identical samples, raw per-sample gradients from -39% to +101%, and 8 samples
reading downhill on a climb that never descends. The same road, the same
request, at 100 m bins:

```
climbfinder    [6, 7, 10, 6,  8, 8,   7,  9, 10,  9, 13, 16, 10,  9, 13, 10, 4, 6, 6, 5]
EU-DEM 25 m    [5, 9, 13, 7, 11, 6,   3,  5, 10, 11, 13, 17, 11, 10, 13, 11, 4, 6, 7]
SRTM 30 m      [7, 6,  9, 7, 12, 6,   5, 11,  8, 13, 13, 15, 10, 11,  9, 18, -2, 6, 11]
GLO-90         [6, 8, 12, 12, 25, 0, -10, 16, 12,  7, 11, 17,  8, 12, 17,  8, 7, 8, -2]
```

| source | distinct values (of 100) | longest flat run | mean error vs climbfinder | bins reading downhill |
|---|---|---|---|---|
| GLO-90 | 28 | 6 | 4.7 pts | **2** |
| SRTM 30 m | 92 | 2 | 2.4 pts | 1 |
| EU-DEM 25 m | 100 | 1 | 1.4 pts | 0 |

A wide display (11 bars on a 2.4 km climb) merely hides this. A narrow window on
a coarse grid publishes it: on GLO-90 a 100 m steepest window gave
Roche-aux-Faucons a 32% ramp it does not have, and the noise inflated its
ascent-only average from 5.7% to 6.3%. No clamping fixes a source that coarse.

The downhill column is decisive here only because La Redoute never descends;
on a climb that drops between two ramps the same count means nothing (see
[§2a](#2a-the-source)).

### 1c. A bar must mean the same thing on every climb

A profile whose bars are an estimate on one climb and a measurement on another,
or a different distance on each, cannot be compared and cannot be checked. So
every bar is measured, every chart states its bin width
([§3b](#3b-a-bar-is-a-distance-not-a-fraction-of-the-climb)), and `demSource`
travels with the figures ([§4](#4-what-is-measured-and-what-is-stored)).

---

## 2. The elevation service

### 2a. The source

**One source, worldwide: Copernicus DEM GLO-30.** Owner decision, 2026-08-04.

| source | resolution | coverage |
|---|---|---|
| Copernicus DEM GLO-30 | 30 m | worldwide land |

There is no chain, no per-coordinate resolution order and no regional fallback.
GLO-90's failure ([§1b](#1b-the-elevation-source-is-too-coarse-for-the-bins-we-want-to-draw))
does not apply to GLO-30, of which it is a 3x downsample.

**The measurement that settled it**, on all seven Belgian seed climbs: the
GLO-30 tile was converted to `.hgt` and both sources were read by identical
code, so the comparison isolates the raster. The reader was first checked
against Valhalla `/height` on the same EU-DEM tile (179 m and 8.62% against
the service's 179 m and 8.61%).

| | EU-DEM v1 | GLO-30 |
|---|---|---|
| La Redoute, gain | 179 m | **181 m** (climbfinder: 180 m) |
| mean per-bin disagreement | - | 2.02 points (worst climb 3.04) |
| downhill bins on Mur de Huy, which never descends | 1 | **0** |

The gain match against an independent reference is the load-bearing result.
The EEA has since discontinued EU-DEM and points at Copernicus DEM, and GLO-30
Public reads without an account, so the choice also follows the publisher.

**A downhill bin is not an artifact by itself** (owner, 2026-08-04).
Roche-aux-Faucons climbs to 228 m, descends to 185 m over more than a
kilometre, then climbs to 270 m; 27 of Hockai's bins read downhill and every
one is real rail-trail descent. A downhill bin is only diagnostic on a road
known to rise monotonically. The measure that generalises is **disagreement
about direction**: bins where two sources differ on whether the road rises. A
descent both see is terrain; one only a single source sees is an artifact.
Across the seven climbs the two sources dispute 33 of 266 bins: Hockai's
descents are agreed, EU-DEM's lone downhill bins on Mur de Huy and Bohissau are
disputed. `tools/elevation/compare-sources.js` reports this column.

**GLO-30 is a surface model.** It reads the first surface the sensor sees:
canopy and buildings, not bare ground. On a wooded climb a reading over a
tree-lined stretch is partly the trees. No bare-earth model at useful
resolution exists worldwide, so this is a permanent error term, and the most
likely explanation whenever two good sources agree on a total and disagree over
a stretch.

**Tunnels and galleries are the larger error.** Where the road runs under
cover, a surface model reads the mountain on top of it: on the Grimsel the
profile climbs 768 m to 822 m in 140 m and then goes flat, the hillside above a
gallery. The error cancels over a whole climb and concentrates in its worst
hundred metres, so it lands on the steepest figure. Two rules handle it:

- **The steepest figure is the 95th percentile of sliding 250 m windows**
  (`ClimbProfiler::MAX_WINDOW_M`, `STEEPEST_PERCENTILE`), not the raw maximum.
  250 m keeps the window above GLO-30's four-cell floor
  ([§3a](#3a-bin-width-follows-the-source)), and a maximum on a surface model
  picks the artifact: denser sampling found more spikes, not more road.
  Validated against two independent truths: Wallonia's 50 cm LiDAR puts
  Stockeu's steepest at 16.7% and we read 16.7%; the owner reports Furka at
  about 10% and we read 10.3%. Two points in different terrain fitting one
  estimator, to be re-tested as more ground truth appears. A 30% clamp on
  `maxGradient` stays as a guard, above any real sustained 250 m, so it fires
  only when the estimator is wrong.
- **Windows over cover are skipped.** `App\Elevation\CoveredSpans` asks
  Valhalla's `/trace_attributes` for OSM's `tunnel` flag per matched edge, and
  `steepestWindow()` drops any window overlapping a covered span. Spans are
  fractions of the line, not metres, because the map-matched geometry differs
  in length from the shape sent. The lookup runs on the profiler's own samples
  (at most 400 points, `CoveredSpans::MAX_POINTS`): matching follows the road
  between samples, so a short tunnel is still found. A failed lookup returns no
  spans and the climb measures as if uncovered; a climb where every window
  straddles cover is measured whole.

Measured with cover skipped: Grimsel 14% to 12% (truth about 11%), Susten 12%
to 11%, Klausen 15% to 14%; every climb without cover (Furka, Gotthard,
Nufenen, Stockeu, Redoute, Huy) is unchanged.

**What is still not corrected.** Cover is excluded from the steepest search
only. Gallery readings remain in `gain`, in the bars and in the line
colouring, where they are diluted enough not to have shown up as wrong.
GLO-30 Public withholds tiles over a few countries, so "worldwide" has holes
and [§2d](#2d-failure-is-honest) still has to hold.

### 2a-i. What GLO-30 costs

- **No registration.** GLO-30 Public is on the AWS Open Data registry at
  `s3://copernicus-dem-30m/` (eu-central-1) and reads over plain HTTPS without
  an account (`tools/elevation/fetch-glo30.sh`).
- **No Valhalla rebuild for profiles.** Valhalla's skadi reads elevation from
  the directory named by `additional_data.elevation`, separately from the
  routing graph, so adding tiles is a file copy and a restart. A rebuild is
  only needed for elevation-aware routing (`build_elevation`), which is a
  different feature.
- **Conversion resamples.** GLO-30 ships as Cloud Optimized GeoTIFF; skadi
  reads SRTM-format `.hgt` (1 arc-second in both directions). GLO-30 decimates
  longitude by latitude band (a tile at 50°N is 2400 × 3600, 1.5″ by 1″) so its
  cells stay roughly square, and `tools/elevation/to-hgt.sh` upsamples
  longitude onto the `.hgt` grid. That adds no information and destroys none.
  The factor changes with latitude band, so the converter reads each tile's
  actual size.
- **Storage is the real cost.** A 1 arc-second `.hgt` tile is 3601 × 3601 × 2
  bytes = 24.7 MB. Global land is about 14,000 to 26,000 tiles, 340 to 630 GB,
  so tiles are fetched for onboarded regions only (the presets and boxes in
  `fetch-glo30.sh`; [country onboarding](catalog-data-model.md)).

### 2b. Our own Valhalla, not a public API

Elevation is read from the project's own Valhalla, never from a third-party
elevation API, and nothing in the browser calls an elevation host. A lookup
happens when a climb's line changes (the editor's preview, a saved
contribution, `app:climbs:recompute`), never per page view or map render.

`POST /contribute/elevation` (`ElevationController`) serves the editor: login
required (401, not a redirect), the stateless `elevation` CSRF token
(`window.CC_ELEV_TOKEN`, sent as `X-CC-Token`), and the per-user `elevation`
limiter, 30 a minute, consumed after the cheap validation
(security-architecture.md §5.1, §7). A saved climb contribution spends the same
budget (`CatalogContributionService::deriveClimbProfile()`). The editor aborts
an in-flight profile request when a newer one replaces it (`climb-editor.js`).

### 2b-i. Valhalla answers the heights

Valhalla serves `POST /height`, which takes a `shape` and returns one elevation
per point. `App\Elevation\ElevationClient` posts the profiler's samples there
(at most `MAX_POINTS = 600`, timeout 8 s) and computes nothing itself.
`ELEVATION_URL` is the base URL and the master switch: unset, there are no
profiles at all, never a guess ([§2d](#2d-failure-is-honest)).
`ELEVATION_DEM_SOURCE` (`Copernicus DEM GLO-30`) is the attribution string
stored with each measurement as `demSource`. Changing dataset is a tile swap
and a restart, not a deploy.

**The trap: Valhalla fails by returning zeros.** An instance with no elevation
tiles for an area does not error: `/height` answers `0` for every point, a
valid-looking sea-level profile. So the client refuses a reply in which fewer
than half the samples are non-zero (`MIN_NONZERO_SHARE = 0.5`). Only exact
zeros count, so a route that merely touches sea level is kept. A reply whose
length does not match the request is refused too (it would attach elevations
to the wrong coordinates), as is a missing-sample sentinel below -1000 m.
Pinned by `ElevationClientTest`.

Valhalla serves the routing call as well ([§3e](#3e-the-routing-call)): the
same instances, the same master switch.

### 2b-ii. One Valhalla per continent, chosen by where the climb is

The host runs **six Valhalla instances, one per continent**, and each answers
`/height` only for the tiles in its own directory, with zeros elsewhere. Asking
the Europe instance for a climb in Australia returns a flat climb at sea level.

So the client picks the instance from the shape's coordinates
(`App\Elevation\ElevationEndpoints`, driven by `ELEVATION_URLS` as
`key=url,key=url`; `ELEVATION_URL` stays the default and the master switch).
Three properties make this safe to get wrong:

- **The boxes are tile sets, not continents.** They are tested in a fixed
  order and the first match wins. `europe` is first because the Europe tile set
  covers Sicily and southern Spain, which the Africa box would otherwise claim.
  The `europe` box is a copy of `fetch-glo30.sh`'s `EUROPE` box; the two are
  widened together.
- **A continent with no configured instance falls back to the default**, never
  to the next box, and an unrecognised key in `ELEVATION_URLS` is dropped
  rather than trusted.
- **A misroute costs a missing profile, never a wrong one.** The wrong instance
  returns zeros and `MIN_NONZERO_SHARE` refuses them.

The shape is routed by its **first point**. A climb is one road between a foot
and a summit and does not cross a continent; a shape that did would still be
answered by one dataset rather than stitched from two. Pinned by
`ElevationEndpointsTest`.

### 2c. Licensing is a gate, not a footnote

Attribution requirements are read from the distribution, never assumed from
memory, and recorded before data is used: the source's row in
[data-source-register.md](data-source-register.md) and its notice on
[credits-page.md](credits-page.md)'s `/credits` page.

**Copernicus DEM GLO-30** is free under the Copernicus DEM Licence, attribution
required. Its notice ("produced using Copernicus WorldDEM-30 © DLR e.V.
2010-2014 and © Airbus Defence and Space GmbH 2014-2018 provided under
COPERNICUS by the European Union and ESA; all rights reserved") is carried in
the upstream's own wording:

- in full on `/credits` (a required notice, never reworded);
- in short form in the map's attribution control (`map-init.js`), because the
  map is where the derived gradients are looked at;
- in each climb's provenance line in the drawer, where `demSource` links to the
  Copernicus DEM collection page (`srcLine()` in `drawer.js`).

The AWS registry's citation form is a citation for access and is not, by
itself, the licence's attribution requirement.

### 2d. Failure is honest

No elevation, no profile. A climb whose elevation lookup fails keeps its
geometry and displays no gradient figures at all; it never falls back to a
guess. `ClimbProfiler::profile()` returns null, `POST /contribute/elevation`
answers 503 (not 200 with nulls) and the editor shows the profile as
unavailable; a saved contribution keeps its line and stores no figures; the
recompute skips the climb with a warning. The submission is still valid: the
line is the contribution, the profile is derived from it.

### 2e. This pipeline serves the platform, not only Cycling Commons

**Decision (2026-08-27): Cycling Commons owns the elevation pipeline for every
application that reads the shared Valhalla. What gets installed is the union of
every consumer's requirement, and each consumer must be able to print its own.**

**Why.** The Valhalla host is shared: Cycling Commons asks it for climb
profiles, and a second application asks it for route planning, ride climb
metres and per-region priors. The installed coverage once matched exactly the
union of Cycling Commons' presets in `fetch-glo30.sh`, on all six continents,
while 143 of the other consumer's 175 countries had no elevation. Because
Valhalla answers `0` rather than failing, nothing surfaced it, and Cycling
Commons could not see it because its own requirement was fully met.

**The split.**

1. **Action lives here.** Fetch, convert, validate, install: one pipeline
   (`tools/elevation/`), one validator (`compare-sources.js`), one write-up
   (`wiki/developers/data-ops/elevation-tiles.md`). No other repository carries
   a copy of these scripts.
2. **Requirement lives with each consumer**, machine-readable. Cycling Commons
   declares through the presets in `fetch-glo30.sh`. The other consumer
   declares through a console command that derives its cell list from its own
   coverage tables:

   ```
   app:elevation:coverage --installed=<file>                # gap report
   app:elevation:coverage --installed=<file> --missing-only # bare cell names
   app:elevation:coverage --country=XX --plan               # bbox for one country
   ```

3. **The input to a fetch is the union**, never one consumer's list.
   `fetch-glo30.sh` takes a preset or a bounding box, so a box from another
   consumer's plan is fetched the same way as a preset.

**Consequences accepted.**

- **Onboarding a region is two-sided.** New elevation does not backfill
  anything computed before it. Each consumer re-runs its own recompute; ours is
  `app:climbs:recompute`.
- **Consumers guard themselves against the silent zero.** This pipeline cannot
  check what it does not own; our own guard is `MIN_NONZERO_SHARE`
  ([§2b-i](#2b-i-valhalla-answers-the-heights)).
- **Direction of travel.** As Cycling Commons becomes the data initiator for
  the catalogue, consumers move from reading the shared Valhalla directly to
  reading a Cycling Commons API. The requirement/action split holds either way.

---

## 3. Sampling and binning

### 3a. Bin width follows the source

**A window is never narrower than about four DEM cells.** Differencing two
elevations one or two cells apart measures the grid, not the road, which is
the GLO-90 failure in [§1b](#1b-the-elevation-source-is-too-coarse-for-the-bins-we-want-to-draw).

| source | cell | floor |
|---|---|---|
| EU-DEM 25 m | 25 m | 100 m |
| GLO-30 | 30 m | 120 m |
| GLO-90 | 90 m | 360 m |

This is an engineering guideline drawn from the measurements above, not a
theorem. The steepest window (250 m, [§5](#5-the-steepest-ramp-is-found-not-placed))
keeps to it. The display ladder ([§3b](#3b-a-bar-is-a-distance-not-a-fraction-of-the-climb))
and the average ([§4b](#4b-average-gradient-counts-only-the-climbing)) start
at 100 m, under GLO-30's floor ([§9](#9-owner-decisions-still-open)).

### 3b. A bar is a distance, not a fraction of the climb

Owner, 2026-08-05. Bars are a real distance, from the ladder **{100, 150, 200,
250, 500, 1000, 2000} m** (`ClimbProfiler::BIN_LADDER`), taking the narrowest
that keeps the chart to **25 bars or fewer** (`MAX_BARS`,
`ClimbProfiler::binWidthFor()`). A 2.4 km climb draws 24 bars of 100 m; past
2.5 km it steps to 150 m, and so on. The last rung is a floor rather than a
guarantee: an unusually long route draws more bars instead of being truncated.
Equal slices of each climb would make a bar mean 220 m on one climb and 1.5 km
on another, and would average any short ramp flat on a long climb.

| climb | length | bin | bars |
|---|---|---|---|
| Mur de Huy | 1.39 km | 100 m | 14 |
| Côte de la Redoute | 2.08 km | 100 m | 21 |
| Roche-aux-Faucons | 4.24 km | 200 m | 22 |
| Hockai | 16.90 km | 1000 m | 17 |

**The average does not follow this ladder.** It stays at 100 m bins
([§4b](#4b-average-gradient-counts-only-the-climbing)), so a published figure
never changes merely because a climb grew long enough to be redrawn with wider
bars.

**The caption always states the bin width** ("per 100 m", from `binM`). A
chart whose bars silently mean different distances on different climbs is the
fault [§1c](#1c-a-bar-must-mean-the-same-thing-on-every-climb) rules out.

**The length beside the chart is the measured one.** It stops at the summit,
while the drawn line may not, which is why `length` and `gain` are stored
([§4](#4-what-is-measured-and-what-is-stored)).

### 3c. Sampling

The profiler reads heights at up to **200 points** of the routed line
(`ClimbProfiler::SAMPLES`): every vertex when the line has 200 or fewer,
otherwise 200 vertices spread evenly by index. Distance along the climb comes
from the full-resolution line, not from the samples
(`sampleWithDistance()`), and a height between two samples is interpolated by
distance (`at()`). Bars, the average and the steepest window are all read off
that one distance/elevation series. 200 samples put a 4 km climb at about 20 m
spacing; a 26 km climb samples about every 130 m, which densifying showed moves
the published figures by less than half a point.

### 3d. Where the coordinates come from, and what the DEM returns

**The elevation source never produces coordinates.** A DEM is a lookup table:
hand it a latitude and longitude, it returns a height there. The trajectory
comes entirely from routing.

The full chain for one climb:

1. The rider taps **foot** and **summit** on the map.
2. **The routing engine produces the line.** `climb-editor.js` posts the taps
   to `POST /contribute/route`, which snaps them to the road network through
   our Valhalla (`RouteSnapper`, `bicycle` costing) and returns the polyline
   actually ridden ([§3e](#3e-the-routing-call)). This is the only step that
   decides *where* the climb goes.
3. **The line is sampled** per [§3c](#3c-sampling).
4. **The samples are sent to `/height`** as `shape`
   ([§2b-i](#2b-i-valhalla-answers-the-heights)); Valhalla returns one
   elevation per point.
5. **Bins are cut** from the distance/elevation series per
   [§3a](#3a-bin-width-follows-the-source)-[§3b](#3b-a-bar-is-a-distance-not-a-fraction-of-the-climb).

Step 2 owns the geometry and step 4 owns the heights, and they are
independent: a profile can be recomputed against a better DEM without
re-routing, and a redrawn line gets a new profile without changing sources.

**What `/height` does with a `.hgt` file.** Tiles are named for their
south-west corner (`N50E005.hgt`) and hold a raw grid of 16-bit elevations,
3601 × 3601 for one arc-second. A lookup interpolates between the four
surrounding cells rather than snapping to the nearest one: walking 60 m in 2 m
steps returned values changing every ~14 m on a ~6.7% slope, tracking the
gradient rather than the 30 m cell.

**The reply is integer metres**, a second argument for the
[§3a](#3a-bin-width-follows-the-source) floor. Rounding to the metre is ±0.5 m
on every reading. Over a 100 m bin at 9% (9 m of rise) that is ±0.5 of a
percentage point; over a 20 m bin it would be ±2.5 points, and the bar would be
mostly rounding error.

### 3e. The routing call

Both halves of the chain run on the server, against our own Valhalla:

| | where it runs | endpoint |
|---|---|---|
| geometry | the server (`RouteSnapper`, via `POST /contribute/route`) | our Valhalla `/route`, `bicycle` costing, per continent (`ElevationEndpoints`) |
| elevation | the server (`ElevationClient`) | our Valhalla `/height`, per continent (`ELEVATION_URLS`) |
| validation | the server (`ClimbGeometry`) | - |

No public routing or elevation host is called, and none is in the CSP.

`POST /contribute/route` follows the stateless-JSON pattern
([security-architecture.md](security-architecture.md) §5.1) exactly as its
sibling `/contribute/elevation` does, because both carry JSON from the same
editor pages to the same Valhalla: an anonymous caller gets a clean 401 (a
redirect would read as a successful response with an HTML body); it takes the
stateless `route-snap` token (`window.CC_ROUTE_TOKEN`), separate from the
unrelated `route-community`; and the per-user `route_snap` limiter, 90 a
minute, is consumed after validation, so a malformed body costs no budget.
`RouteControllerTest` mirrors `ElevationControllerTest` case for case so the
two endpoints do not drift. The reply keeps OSRM's response shape (`routes[0]
.geometry.coordinates`, `distance`; `code: NoRoute` with no route), which the
editor parses; with no route the editor keeps the straight line.

**The server never sees the road in the payload.** `ClimbGeometry` decodes and
validates a posted line (pairs of finite numbers, within `MAX_POINTS`) and by
design checks shape rather than truth. So a route is a *contribution*, verified
by moderation like any other, not a computed fact.

**Not used: `/route` with `elevation_interval`.** Valhalla can route and sample
elevation in one call, but re-routing at measurement time could return a
different line than the one on screen. The two calls stay separate so the
elevation is measured along the exact line the rider saw and accepted.

---

## 4. What is measured, and what is stored

Everything below is **derived**. None of it is a form field, and
`CatalogField::$derived` ([edit-items/N-climbs.md](edit-items/N-climbs.md))
keeps the edit form from offering a box for any of it. The letter-N keys are
allowed by `AttributeVocabulary` (`App\Catalog\Import`).

| attribute | definition |
|---|---|
| `length` | length along the routed line to the summit (haversine per segment), metres |
| `gain` | summit elevation minus foot elevation, metres |
| `footEle`, `summitEle` | the two altitudes, metres above sea level ([§4c](#4c-the-full-profile-and-the-two-altitudes)) |
| `avgGradient` | **ascent only**: the sum of the climbing over the length, one decimal ([§4b](#4b-average-gradient-counts-only-the-climbing)) |
| `maxGradient` | the **steepest 250 m**: the 95th-percentile window, whole percent ([§5](#5-the-steepest-ramp-is-found-not-placed)) |
| `steepWindowM` | the window `maxGradient` was measured over (250), so the caption names it |
| `steep` | the measured steepest marker, `{at, pct, manual}` ([§5](#5-the-steepest-ramp-is-found-not-placed)) |
| `grad` | per-bin gradients, whole percent, clamped to ±35, bin width per [§3](#3-sampling-and-binning) |
| `binM` | the bin width in metres, so the chart can label itself |
| `lineGrad` | sustained gradient per band of about 25 m (at most ~120 bands), for colouring a line that has no `grad` ([§6a](#6a-a-descent-must-look-like-a-descent)) |
| `demSource` | which source answered, for attribution |

`steepPoint` is stored beside them but is a rider's contribution, not a
measurement ([§5a](#5a-two-markers-one-measured-one-remembered)).

Two writers fill the set, `app:climbs:recompute --write` and a saved climb
contribution (`CatalogContributionService::deriveClimbProfile()`), and both
write all of it: the measured keys come from one mapping of the profile
(`ClimbProfiler::storedAttributes()`), and each writer sets `steep` itself so a
hand-placed marker keeps its place. Neither writes when the profile is null
([§2d](#2d-failure-is-honest)).

**There is no stored `headline`.** A stored display string (`"2.0 km · 8.4%
avg"`) drifts the moment a climb is redrawn, so both writers remove it and the
drawer composes that line from `length` and `avgGradient` at render time.

### 4a. The line must end at the summit

`gain` and `avgGradient` are only meaningful if the line stops climbing. A line
that runs on past the top understates the climb: La Redoute's line drawn 362 m
past its high point averaged 6.80%; trimmed at the summit it averages 8.61%
over 2080 m, against climbfinder's 9.0% over 2000 m. Marking a summit a few
hundred metres late is easy, so:

- **`length` and `gain` are measured to the highest point on the line**, not to
  its last point. The tail beyond the summit is excluded from every derived
  figure, and the map draws the climb foot to summit (`trimToClimb()` in
  `render.js`, cut at `length`). The tail stays in `route`: it is part of the
  contribution, not of the climb.
- **The summit is the last point at the maximum, not the first**, so a climb
  that finishes on a plateau does not end where its plateau begins.
- **"Past the summit" means metres lost, not metres travelled** (owner,
  2026-08-05). On flat ground the highest point is decided by DEM noise, so a
  tail's distance says nothing: Roche-aux-Faucons' 130 m tail loses 1 m and
  the route is correct; La Redoute's 361 m tail lost 10 m, a descent. The
  profiler reports `overshootM` only when the tail drops at least 8 m
  (`OVERSHOOT_DROP_M`), with the drop as `overshootDropM`.
  `app:climbs:recompute` prints such a climb as a warning and leaves the line
  alone. The editor receives `overshootM` and shows no warning and no cut.

**A reversed line is refused.** A line stored summit to foot puts the highest
point at its start; measured "to the highest point" it would give a length,
gain and average of zero, numbers that are not obviously broken in a database
column. So a line whose highest point is its first point gets no profile at all
(`ClimbProfiler::profile()` returns null), and the recompute reports it as "no
elevation, or the line runs downhill". A zero-length climb is a bug report,
not a measurement.

### 4b. Average gradient counts only the climbing

Owner decision, 2026-08-04. A climb with a dip in it has two defensible
averages and they are far apart:

| definition | Roche-aux-Faucons, redrawn |
|---|---|
| net gain ÷ length | 4.4% |
| **ascent only ÷ length** | **5.7%** |
| climbfinder, same road | 5.4% |

Net gain lets a descent cancel out the climbing either side of it, which is not
what the rider did. Ascent-only is what climb sites publish and what the legs
remember. **We publish ascent-only.**

**Measured over ~100 m bins, not raw samples** (`AVG_BIN_M`). Ascent-only is
noise-sensitive by construction: every upward wobble adds and nothing
subtracts. Binning first is stable: the same climb reads 5.8% at 50 m bins,
5.7% at 100 m, 5.6% at 200 m, against 6.0% unbinned.

**One decimal place.** The average is the headline figure, and 8.6% and 9.4%
are not the same climb. The steepest figure stays whole, because it is one
window's reading and that precision is not real.

### 4c. The full profile and the two altitudes

`profile()` returns **`footEle`** and **`summitEle`**, read from the same
elevation array every other figure is measured from. `gain` gives a chart its
height but not its position: it can say "+225 m" and not "277 m -> 502 m",
which is the pair every published climb profile leads with. A climb without
them shows no altitude labels rather than an invented sea level. Côte de
Stockeu measures 278 m -> 506 m here; myCols publishes 277 m -> 502 m.

The drawer's 52 px gradient strip is a **button** that opens the full profile
(`assets/map/climb-profile.js`) in its own popup: the road's silhouette, each
bin filled in its gradient colour with the figure written in a band under it,
both altitudes, and a distance ruler. Nothing is re-measured there: the
silhouette is the cumulative sum of the `grad` bins the strip already draws, so
the two charts cannot disagree about the same climb.

---

## 5. The steepest ramp is found, not placed

`steepestWindow()` slides a **250 m** window (`MAX_WINDOW_M`) along the profile
at a fixed step (a tenth of the window, at least 5 m), skips any window
overlapping a tunnel or gallery, and returns the 95th-percentile window
gradient (`STEEPEST_PERCENTILE`, [§2a](#2a-the-source)) with the coordinate of
that window's centre. A climb shorter than one window is its own steepest
stretch. The step is a fixed distance, not route vertex to vertex: a routing
engine puts vertices where the road bends, so a straight has almost none and a
vertex-stepped window can miss the steepest stretch entirely.

**It is labelled with its window ("steepest 250 m"), not "max gradient"**
(owner, 2026-08-05). "Max gradient" invites comparison with a **point**
maximum, so Mur de Huy's famous ~26% hairpin reads as a contradiction of our
sustained figure when the two measure different distances. Naming the window
settles it, which is why the figure ships without a `~`: a tilde on a value
whose measurement distance is stated hedges about nothing. `steepWindowM`
travels with the figure and the caption is built from it.

**A shorter "max ramp" cannot come from this data.** Measured on GLO-30 at four
window widths:

| climb | 25 m | 50 m | 100 m | 150 m |
|---|---|---|---|---|
| Mur de Huy | 20% | 20% | **20%** | 18% |
| Côte de Stockeu | **41%** | 38% | 27% | 23% |
| Côte de la Redoute | 21% | 18% | 17% | 16% |
| Côte d'Ereffe | 23% | 21% | 19% | 16% |

A 25 m window is less than one GLO-30 cell. Shortening the window does not
recover Mur de Huy's 26% (the hairpin is smaller than a cell) and invents 41%
on Stockeu, a gradient no paved road has. A finer source (Wallonia publishes
1 m LiDAR terrain data) or a rider would measure a ramp; this dataset cannot.

The 250 m window reads gentler than a climb database's steepest 100 m; the
caption names the window, so a reader can see the two measure different
distances. It is also not the same number as the worst display bar: the bars
sit at fixed boundaries, the window wherever it falls
([§6a](#6a-a-descent-must-look-like-a-descent)).

**The measured marker can be moved.** An automatic `steep` marker is re-derived
whenever the line changes. A rider who drags it sets `manual: true`: it keeps
its position through a redraw and only its percentage is re-read from the
profile at that point (`sustainedAtSteep`), and `app:climbs:recompute` never
overwrites it.

### 5a. Two markers: one measured, one remembered

Owner, 2026-08-05. The measurement above and a rider's knowledge answer
different questions, and the DEM cannot answer the second one: Mur de Huy's
hairpin is smaller than a GLO-30 cell, so no window recovers its 26%. That is
information the dataset does not contain and a rider does.

| | placed by | means |
|---|---|---|
| **steepest 250 m** (`steep`) | us, automatically | the steepest sustained 250 m the DEM can see, comparable across every climb |
| **steepest point** (`steepPoint`) | a rider | where the wall actually is, on a road they have ridden |

Ours is derived on every redraw and is the figure the catalogue publishes and
sorts on, because it is measured the same way everywhere. Theirs is a
contribution, a distinct icon at a place they choose, through the same
moderation as any other. Letting riders correct our number instead would make
the published field mean something different on every climb
([§1a](#1a-hand-typed-figures-look-like-measured-ones)); two fields with two
honest definitions beat one field with a negotiable one.

`steepPoint` is stored as `{at, pct, note}` beside `steep`, never merged with
it (`ClimbGeometry`):

- **The percentage is optional.** A rider may know *where* the wall is without
  knowing how steep. `note` holds what they do know ("the hairpin after the
  chapel"), cut at 120 characters.
- **A distinct icon**, amber and filled against the measured marker's purple
  triangle, in the editor and on the map.
- **It never moves on its own.** It is only placed, dragged or cleared by a
  rider, and survives an edit to anything else on the climb.
- **It travels as geometry**, through the same submission and moderation path
  as the line, with no new review mechanic
  ([moderation-and-contribution.md](moderation-and-contribution.md)).
- **The editor control** (`rider-steep.js`). Under the map, once foot and
  summit are set: **+ Steepest point** arms a mode, **Cancel** or Escape leaves
  it. A placed point shows two optional fields, gradient (`20` becomes `20%`;
  anything the server would refuse is not stored) and note, plus **Remove**.
- **The point is found for the rider.** "+ Steepest point" asks
  `POST /contribute/elevation` with `findPoint: true`
  (`ClimbProfiler::steepestPoint()`, owner 2026-10-02). The line is read at the
  profile's spacing and a 90 m window slides along it in 15 m steps, skipping
  tunnels and galleries; then 300 m around the steepest stretch is read again
  every 15 m and the steepest fitted slope places the point. While the server
  looks, the map says "Finding the steepest spot. Drag the point if it is
  wrong."; a tap on the road in that moment places the point by hand and wins.
  With no heights the tap is the only way.
- **The gradient fills itself.** Placing or dragging the point asks the same
  endpoint with `pointAt` for the gradient over **90 m** of road centred on it
  (`ClimbProfiler::pointGradient()`, `POINT_WINDOW_M`): 7 heights 15 m apart
  and the slope fitted through them, because one height is too noisy (owner
  2026-10-02). The figure lands in the gradient field and on the marker; a
  figure the rider types is theirs and is never overwritten.

The measured marker reads "steepest 250 m" beside it, so the two numbers say
what they measure. The rider's figure does not appear in listings or sorting
([§9](#9-owner-decisions-still-open)).

---

## 6. The chart

### 6a. A descent must look like a descent

**Bars hang below a baseline where the gradient is negative** (`gradStrip()` in
`drawer.js`), and the dashed zero line is drawn only when something descends.
A chart without a zero draws a -15% bin as a short bar pointing the same way as
every climbing one, telling the opposite of the truth on a climb that drops
between two ramps.

**Both directions share one scale.** A -12% bar is exactly as long as a +12%
one. Scaling each side to its own extreme would make a shallow dip look as
dramatic as the steepest ramp.

**A climbing bar rests on the baseline, not on the floor of the strip.**
Bottom-aligning each bar to its column put every climbing bar below the zero
line, so the second half of a climb that dips and rises again read as though it
were still descending (owner-reported 2026-08-04).

**The chart carries a distance axis** in the rider's distance unit: ticks every
0.5 up to a 1.5 total, every 1 up to 6, and otherwise a sixth of the total
rounded up; an interior tick that would crowd the end label is dropped. Each
bar's tooltip names its stretch ("1.2-1.3 km · 12%"). The caption reads
"Gradient profile · per {bin} · avg {avg}% · steepest {window} {max}%", from
`binM`, `avgGradient`, `steepWindowM` and `maxGradient`.

**The map line and the bars share one series** (owner, 2026-08-05): the map
colours the climb line from `grad`, in hard steps over the line's progress
(`drawClimbLine()` in `render.js`), so the same stretch of road is the same
colour on the map and in the strip. `lineGrad` colours the line only for a
climb that has no `grad`. The line and its colour bands both cover foot to
summit ([§4a](#4a-the-line-must-end-at-the-summit)); bands measured over a
different extent than the one drawn get stretched along it.

**Open: the steepest marker and the bars can disagree.** The steepest window
can sit at any offset, so a ramp that straddles a bin boundary is split between
two bars (on Côte de Stockeu, with a 100 m window, the marker read 27% while
sitting on a 14% bar beside a 23% one). Publishing the sliding window is truer
to the road but not verifiable from the chart; publishing the steepest bar is
verifiable by eye but understates a straddling ramp. Recorded rather than
decided ([§9](#9-owner-decisions-still-open)).

### 6b. Descents are blue

`gradColor()` (`assets/map/util.js`) colours climbing gradients on a purple
ramp and descending gradients on a blue one: `#A6CFF2` → `#5BA0E8` → `#2B76D0`
→ `#184F9F` → `#0A2A66`. The blue mirrors the purple exactly, with **the same
five |gradient| thresholds and the same light-to-dark progression**, so
steepness reads the same in either direction and only the hue says which way
the road goes. A single ramp would give a -12% drop the same pale colour as a
3% rise.

`gradColor()` serves the strip, the full profile and the map line alike, so a
climb that descends shows blue on the map where it descends. The map key's
gradient swatches use the same colours (`key-swatches.css`).

### 6c. The full profile keeps one slope scale

Owner, 2026-10-02. The full profile does not stretch every climb to the full
height of its chart, which made an 11 km climb at 6.5% look as steep as a short
wall. The vertical span is the largest of: the climb's own height gain, 60 m (a
riser is not an alp), and the height a **12% average** (`FULL_HEIGHT_GRADIENT`)
gains over the climb's length (`verticalSpan()` in
`assets/map/profile-scale.js`). So every climb up to a 12% average is drawn at
the same exaggeration and a gentler climb draws a lower silhouette: Furka
(about 650 m over 11 km) fills about half the chart. A climb steeper than 12%
on average fills the chart. On a phone the profile opens above the bottom sheet
(`.cc-cp` z-index 1600, like the lightbox). Pinned by
`web/tests/js/climb-profile-scale.test.mjs`.

### 6d. The full profile shows both steepest markers

Owner, 2026-10-02. The full profile draws the two markers of
[§5a](#5a-two-markers-one-measured-one-remembered) at their distance along the
climb, in their map colours:

- **Steepest 250 m** (`steep`, purple): the road is drawn heavy over the
  window, with a bracket above it and the label "▲ 11% over 250 m". The window
  is `steepWindowM` wide, centred on `steep.at`, and moved inside the climb when
  it would hang over the foot or the summit (`windowSpan()`).
- **Steepest point** (`steepPoint`, amber): a dashed line from the road to a
  label in the top row, "⬗ 18%", or "⬗ ramp" without a figure. Its tooltip
  carries the rider's note. The top row sits above the purple label, so the two
  labels never meet.

Both positions come from the stored coordinate, projected onto `route`
(`metresAlong()` in `assets/map/profile-marks.js`). They are metres from the
foot, not a share of the line: the chart's last bar is a full bin, so the
chart is a little longer than the road (Furka: 22 bars of 500 m against
10 597 m of road), and a share would land about 400 m past the spot. A climb
without a `route` or a marker draws no marker, and the chart keeps no empty
room for it. Pinned by `web/tests/js/climb-profile-marks.test.mjs`.

---

## 7. Measuring the catalogue

`app:climbs:recompute` (`RecomputeClimbProfilesCommand`) measures every letter-N
item that has a `route` and prints old and new average and steepest figures; it
is a dry run unless `--write` is given, and `--id N` limits it to one climb.
With `--write` it stores the full derived set of
[§4](#4-what-is-measured-and-what-is-stored), keeps a hand-moved `steep`
marker, and removes any `headline`. It is the only writer of measurements
besides the contribution path, so there is exactly one implementation the
published numbers come from (`ClimbProfiler`).

Seeds carry lines and no measured values (`SeedManualCatalogCommand`,
`app:catalog:seed-climbs`). A climb with no route cannot be measured and shows
no gradient figures.

### 7a. Drawing the line without the editor

A climb with no route has nothing to measure and nothing for the map to draw.
Drawing many lines in the browser editor is also how endpoint defects get in (a
line run past the summit, a line stored backwards,
[§4a](#4a-the-line-must-end-at-the-summit)).

`tools/wikimedia/climb_line.py` routes foot -> summit through **the same
Valhalla with the same `bicycle` costing** the editor's snap uses
(`RouteSnapper`), so it produces the line the editor would have produced, and
makes a redraw rerunnable. Four modes, each named for a defect:

- **`--extend`**: the line stops short of the top. Routes only from the stored
  last point to the new summit and appends. Not a redraw: a whole-line reroute
  can come back on a different road (Gotthard has the cobbled Tremola and the
  modern road), which would replace a curator's choice of road with the
  router's.
- **`--trim-crest-within-km KM`**: the line runs on past the top, over a
  descent and up a second rise, which the trailing-descent rule cannot catch.
  Cuts at the highest point inside the window.
- **`--reverse`**: routing is not symmetric. Côte des Forges came back as
  2.01 km of detour foot -> summit where summit -> foot is the 1.48 km road the
  climb is (one-ways and turn restrictions). It asks downhill and flips.
- **`--find-foot KM`**: fires routes at a ring of bearings and cuts each at the
  published length. It cannot tell a valley road from a driveway, so every
  candidate prints with its gradient and a person picks. `climb_foot.py` is the
  better finder (it walks a real road graph) but needs Overpass.

`tools/wikimedia/climb_profile_probe.py` prints where a bad segment sits,
because a -12% at 200 m and a -12% at 4 km mean opposite things: a dip in the
road versus a line drawn over the top.

Neither tool computes a gradient. `app:climbs:recompute --id N --write` writes
the measurements.

### 7b. Seeding passes at scale

`tools/wikimedia/climb_sides.py` finds climbs from a col without a published
length. **A pass climb is the road from the valley to the col**, so it leaves
the col in every direction, follows each road down, and stops where the
descending stops. That point is the foot and its distance is the length, and
because a col has two or more roads leaving it, every side comes back without
anyone choosing one: "Stelvio from Prato" and "Stelvio from Bormio" are two
rows. `climb_candidates.py` supplies the cols.

**Road-bike costing is load-bearing.** Plain `bicycle` costing returned the
Camino de Santiago footpath beside the N-135 as 47% of the climb to Roncevaux.
`climb_sides.py` routes with `bicycle_type: Road`, `avoid_bad_surfaces: 1.0`,
`use_roads: 1.0`. This is deliberately stricter than `climb_line.py`, which
must match the editor's own `RouteSnapper`: some stored climbs are greenways
(Hockai's whole line is a RAVeL), and the harvester may be stricter than the
editor while the editor may never be stricter than its riders.

**`climb_audit.py` is the second opinion.** Arithmetic cannot tell a driveway
from a col road. Valhalla's `trace_attributes` snaps each stored line back onto
the road graph and reports what every edge is (`road_class`, `surface`, `use`,
name). It traces with the same costing the harvest routed with, because a laxer
profile can snap a good road line onto the footpath beside it. Four verdicts:
DROP (the trace proves it is not a road), DUPLICATE (a border col in two
countries' files, or a row already in the catalogue), CHECK (a person decides)
and KEEP. Two traps it guards: Wikidata publishes US elevations in feet (a
summit-height ratio of 3.28 to the measured one is converted, since nothing else
lands there), and a side much shorter than another side of the same col is
usually the descent-stop cutting at a terrace. `--name-feet` reverse-geocodes
each surviving foot so a two-sided pass gets two names.

`app:catalog:seed-climbs` (`SeedClimbsCommand`) imports the reviewed artifact,
KEEP-only by default, and **refuses DROP outright**. It writes one item per
side, identified `wikidata:<qid>:<side>` so a re-run upserts each side onto
itself, geometry at the line's foot (a climb's point is its foot,
catalog-data-model.md §6a; the region and the duplicate check are asked there
too), and `state = unverified` like every seeded row. It writes no gradients.
"Stelvio Pass" names a col and "Stelvio Pass from Prato" names a climb, so the
foot is reverse-geocoded into the artifact and tidied at import: an
administrative area is dropped rather than shortened, a place already inside
the pass name is not repeated, and two sides that would still collide are told
apart by their road, or by their length when they share one. Pinned by
`SeedClimbsCommandTest`.

### 7c. Ten climbs in every country

Owner, 2026-09-06: every onboarded country has at least ten climbs. A flat
country has no mountain passes, so the candidate list widens beyond them, all
by flag in `climb_candidates.py` / `climb_sides.py` with the pass harvest as
the default:

- **`--limit`**: how many candidates per country (`climb_sides.py` default
  12, `climb_candidates.py` default 15; up to 150 where a country has the
  passes).
- **`--class hill | climb | steep | mountain`**: Wikidata classes beside
  mountain pass. Dutch and Luxembourg climbs are `hill` (Q54050), the Flemish
  walls `hillclimbing` (Q5762701), and the big summit roads (Cauberg, Ventoux,
  Alto de Letras) `mountain` (Q8502). A mountain with no road to its top yields
  no side.
- **`--source osm`**: named `mountain_pass=yes` nodes from OpenStreetMap via
  Overpass, ordered by tagged elevation. Such a col carries `osm:node:<id>`;
  `app:catalog:seed-climbs` files it with `source = osm` and ref
  `osm:node:<id>:<side>` (`testAnOpenStreetMapColKeepsItsOwnProvenance`).
- **`--min-km`, `--min-pct` and `--flat-km`**: the walker's floors default to
  Alpine values (1 km, 4%, a 2 km flat window). A Dutch berg is 600 m of 10%,
  so hills run with short windows.
- Candidate labels fall back through nl, fr, de, es, it, ja when English has
  none, so a Slovenian pass is "Razdrto", not its Q-id.

Hand rulings at review, as decisions: OpenStreetMap cols named by their
elevation are dropped; a walkers' col whose walk snapped onto a neighbouring
road climb is dropped; a real road with a short track section (9 to 16%
off-road) may be kept on CHECK. Rwanda has fewer than ten: it has no named pass
in either source, its volcano walks land on a park road far below the summit,
and its raced climbs (the Kigali walls) are streets, which need a line drawn
each in the editor.

### 7d. The three Belgian seed climbs, checked against references

The seeded Belgian climbs in `SeedManualCatalogCommand` are checked against
published profiles (2026-09-14). A foot or summit is right when its height
agrees within the few metres two elevation models differ by.

| climb | ours (GLO-30) | reference | verdict |
|---|---|---|---|
| Côte de la Redoute | 132 → 312 m, 180 m, 2.01 km | myCols 128 → 308 m, 180 m, 2.0 km | agrees |
| Côte de Stockeu | 278 → 504 m, 226 m, 2.30 km | myCols 277 → 502 m, 225 m, 2.3 km | agrees |
| Mur de Huy | 72 → 207 m, 135 m, 1.40 km, 9.7% | climbfinder 136 m, 1.4 km, 9.7% | agrees |

PJAMM (1.29 km, 123 m) and Wikipedia (1.3 km, 121 m) put Mur de Huy's foot
about 100 m higher up the road. Climbfinder is the reference kept, because it
agrees on all three figures.

**The stored line is drawn vertex for vertex.** `drawClimbLine()` sends the
stored `route` (cut at the summit) to MapLibre, so a line stored with too few
points cuts the corners of the road at high zoom. The seed carries each line as
the road shape Valhalla returns (`trace_route`, `bicycle` costing, `map_snap`).
A harvested line that differs from a fresh trace at the same length differs by
routing profile (`climb_sides.py` uses road-bike costing), not by missing
points, so it stays as it is.

---

## 8. Testing

- **Profiler** (`web/tests/Elevation/ClimbProfilerTest.php`): ascent-only
  ignores a descent in the middle; a line that only descends is refused rather
  than measured as zero; a flat summit is not reported as running past the top
  and a real trailing descent is; length and gain stop at the summit; the bin
  width climbs the ladder and never exceeds the bar cap; the profile is binned
  at the width it reports; provenance travels with the measurement; no
  elevation means no profile; a single bad sample does not become the steepest
  figure; the steepest figure carries its window; a step under a gallery is not
  the steepest stretch; length is measured along the road, not across the
  hairpins; the point gradient is the slope over 90 m and one odd height does
  not swing it; the steepest point is found on the wall.
- **Client and endpoints**: `ElevationClientTest` (the zero guard, a
  wrong-length reply, the missing-sample sentinel, an upstream failure, no
  configured service, too many points), `ElevationEndpointsTest` (instance
  choice, Europe winning its overlap with Africa, fallback, first point,
  unknown keys), `CoveredSpansTest` (tunnel spans, merged galleries, lookup
  failure).
- **Controllers**: `ElevationControllerTest` and `RouteControllerTest`, case
  for case ([§3e](#3e-the-routing-call)).
- **Seeds and contributions**: `SeedManualCatalogCommandTest`
  (`testNoSeededClimbTypesAMeasuredValue`, the road shape point for point),
  `SeedClimbsCommandTest`, `CatalogContributionServiceTest`
  (`testClimbSubmissionStoresRouteGradSteep`), `ClimbGeometryTest`.
- **Browser code**: `web/tests/js/climb-profile-scale.test.mjs`,
  `climb-profile-marks.test.mjs`, `climb-editor-rider.test.cjs`.
- **Source comparison**: `tools/elevation/compare-sources.js` reads climbs from
  the dev database and reports per-bin disagreement between two DEMs
  ([§2a](#2a-the-source)).

---

## 9. Owner decisions still open

- **Widen the GLO-30 evidence geographically.** The comparison against EU-DEM
  was run on Belgian climbs in one tile (`N50E005`). Climbs exist in every
  onboarded country, but the comparison has not been repeated on them. The
  Netherlands is the interesting test, because GLO-30's longitude decimation
  changes with latitude.
- **The 100 m rung under GLO-30's floor.** The display ladder and the average
  bins start at 100 m, below the 120 m four-cell floor of
  [§3a](#3a-bin-width-follows-the-source).
- **Recompute cadence**: on submission only, or a periodic sweep as the DEM is
  updated.
- **Sliding window or steepest bar** for the published steepest figure
  ([§6a](#6a-a-descent-must-look-like-a-descent)).
- **The rider's steepest point**: whether a climb may carry more than one, and
  whether the rider's figure should ever appear in listings or sorting. It does
  not, which is the safe default: a field that means the same thing everywhere
  is what can be sorted on.
- **Whether the changed numbers need saying out loud.** Measuring moved figures
  riders recognise (Stockeu from `9%+` to 14.0%, Mur de Huy's steepest from
  `~26%` to a sustained figure under its new name). Whether a rider who knew
  the old numbers is told why they changed is an editorial call.
