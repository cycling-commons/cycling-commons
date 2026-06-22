# Seasonal ride-heatmap + "Plan from Spa" — prototype slice

**Date:** 2026-06-17
**Surface:** `atlas/demo/map.html` + `atlas/demo/routes-data.js` (generated). No backend.
**Status:** Design approved (GPX uploaded) → implementing.

## Goal
A demoable, **illustrative** slice of the routes vision on the map: a seasonal **ride-heatmap**
(glows where rides overlap, with a season slider) and a **"Plan from Spa"** planner that suggests a
popular loop for a chosen distance. Same honest-preview spirit as the rest of the map.

## Data — `routes-data.js` (build-time, scripted)
Generated from 6 real GPX loops in `atlas/demo/media/` (Spa/Ardennes), **GPS points only**, downsampled.
Raw `.gpx` stays gitignored; only the derived dataset ships.
- `window.CC_ROUTES.heat` — ~3040 `[lat, lon, season]` points (~1 per 110 m across all rides).
- `window.CC_ROUTES.routes` — 6 loops: `{name, season, km, start:[lat,lon], loop:[[lat,lon]…]}`.
- Loops & distances are **real** (17–126 km). **Seasons are illustrative** (assigned, not from the
  files): spring 1 · summer 2 · autumn 2 · winter 1.

## Heatmap layer
- MapLibre `heatmap` layer from the `heat` points (one GeoJSON source, each point tagged `season`).
- Warm ramp (transparent → ochre → trail → paper), weight tuned for the point density.
- It's the **L · Ride heatmap (derived/aggregate)** output — labelled illustrative; default **off**, toggled in the rail. *(Was "K" under the old A–K scheme; K is now Quality rides.)*

## Season slider
- Chips: **All · Spring · Summer · Autumn · Winter** in a new rail group.
- Changing it sets the heatmap layer `filter` (`['==',['get','season'],sel]`, or no filter for All),
  so the popular lines visibly shift by season. Seasons are illustrative (noted).

## "Plan from Spa" planner
- Fixed start = **Spa** (all loops are Spa-area; keeps the fake simple — no geolocation).
- Distance buttons (~20 / ~50 / ~80 / ~125 km) → pick the loop whose `km` is nearest → draw its
  `loop` as a highlighted cased line + a start pin at Spa, and open the drawer with the route record
  (name, distance, season, "popular this season").
- Drawer notes this is **faked**; the **real** planner stitches a route from the heatmap (deferred).

## Framing
Inspirational-preview badge stays. Heatmap + planner clearly marked illustrative. A line notes the
real system **anonymizes ride data at ingest** (per the `data-catalog.md` K design).

## Scope
- **In:** `atlas/demo/routes-data.js` (generated), `atlas/demo/map.html` (include it; heatmap source/layer; season
  chips; planner panel + draw/drawer; rail "Routes & heatmap" group).
- **Out (deferred):** real heatmap from anonymized uploads, real stitch-from-heatmap planner,
  geolocation, GPX upload UI, vote integration.

## Verification
Headless + Playwright: heatmap toggles on and renders; season chips change the visible heat; each
distance button draws the right loop + opens its drawer; 0 console errors; SRI/region/base-map intact.
