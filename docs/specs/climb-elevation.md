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
| 1 | EU-DEM v1.1 | 25 m | Europe (EEA members + cooperating states) |
| 2 | SRTM | 30 m | 60°N to 56°S |
| 3 | Copernicus GLO-90 | 90 m | worldwide |

The Commons is worldwide from day one
([osm-data-architecture.md](osm-data-architecture.md)), so a Europe-only source
cannot be the only source. GLO-90 stays as the floor because a coarse profile
beats none — but see [§3a](#3a-bin-width-follows-the-source) for what it is
allowed to draw.

### 2b. Self-hosted, not a public API

opentopodata's public endpoint allows 1 call/second, 1000 calls/day and 100
locations per call. That is a research tool, not a dependency for a contribution
flow. The service is **self-hosted** on the existing cluster
([dev-environment.md](dev-environment.md) for the local stack), which also
removes the per-call location cap that would otherwise force a long climb into
many round trips.

### 2c. Licensing is a gate, not a footnote

EU-DEM is Copernicus data and SRTM is United States government data. Neither
attribution requirement may be assumed from memory: both must be read and
recorded in [osm-data-architecture.md](osm-data-architecture.md)'s licensing
section, and surfaced wherever the profile is displayed, **before this ships**.
The project's posture on data licences is deliberate and this is data.

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

- **Self-hosting scope** — full Europe EU-DEM (tens of gigabytes) against only
  the regions currently onboarded, and whether the tiles live beside the
  existing PMTiles infrastructure.
- **Recompute cadence** — on submission only, or a periodic sweep as DEM sources
  are updated.
- **`~` in published gradients** — measured values are numbers; the catalog's
  editorial strings (`~20% (mid-climb ramp)`) carry an approximation marker and
  a parenthetical. Once figures are measured, is the tilde still wanted, and
  does the parenthetical survive as separate prose?
