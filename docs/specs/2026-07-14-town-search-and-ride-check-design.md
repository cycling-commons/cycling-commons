# Town search completeness + GPX ride-check (2026-07-14)

- **Status:** Approved (design reviewed in-session 2026-07-14)
- **Surfaces:** `/map` (search UI, ride-check overlay), new `POST /map/ride-check`
- **Related:** [2026-07-08-route-domain-design.md](2026-07-08-route-domain-design.md) (GPX intake pipeline),
  [2026-07-02-map-based-moderation-design.md](2026-07-02-map-based-moderation-design.md) (map shell),
  `web/src/Catalog/SurfaceProfiler.php` (the existing items-near-a-line query this generalizes)

## 1. Problem

Two rider-facing gaps on `/map`:

1. **Town search is incomplete and duplicated.** Searching a town only lists climbs,
   routes and surface segments near it — water, services, stays, transit, shelter,
   scenic and history never appear, because `nearbyItems()` scans only
   `CATALOG[].features` while those seven types live exclusively in the `CC_*_OSM`
   / `CC_STAYS_PIVOT` pools. Meanwhile every Tourisme-Wallonie (PIVOT) stay shows
   **twice** (in the dropdown and as map dots) because `catalog-load.js` concats the
   pivot pool into the OSM stays pool and `map.js` then indexes both pools. There is
   no dedup anywhere, the dropdown is capped at 8 rows, and only ~15 hardcoded towns
   are searchable at all.

2. **No way to relate a planned ride to the Commons.** A rider with a GPX of
   tomorrow's ride cannot ask "which water points / stays / climbs are along my
   route?". Read-only indication only — no ride editing, no ride storage.

## 2. Goals / Non-goals

**Goals**
- One deduplicated client-side item index feeding both search and nearby lists;
  every served catalog item findable, each physical place listed once.
- Any town/village searchable (live geocoder autocomplete), not just the hardcoded list.
- Logged-in riders can upload a GPX and see all catalog items within a chosen
  corridor of the track, ordered by distance along the ride, plus Commons routes
  their ride follows. The GPX is parsed in memory and **never stored**; the UI says so.

**Non-goals (YAGNI)**
- No ride persistence, ride library, or ride editing of any kind.
- No surface-quality summary of the uploaded ride (SurfaceProfiler stays route-intake-only for now).
- No anonymous ride-check (revisit if demand appears).
- No server-side town/item search endpoint — the catalog already ships whole to the client.

## 3. Part 1 — Town search: any town, all items, no doubles

### 3.1 Unified item index (root fix)
After `catalog.json` fan-out, build a single `ITEM_INDEX` —
`{id, letter, name, lngLat, feature, source}` — from **all** pools:
`CATALOG[].features` plus every `CC_*_OSM`/`CC_STAYS_PIVOT` global. The search
dropdown and `nearbyItems()` both consume this index; the four blind `push` loops
in `map.js` (~line 1986–2008) are removed.

Dedup, two passes:
- **Structural:** remove the pivot→OSM concat in `catalog-load.js:35-42`; render the
  two stay pools separately. Kills the guaranteed PIVOT double-draw + double-index at the root.
- **Cross-source:** same letter + normalized name (case/diacritics-folded, trimmed)
  + within 100 m → one entry, preferring non-`osm` source rows (curated/manual)
  over OSM imports.

### 3.2 Place autocomplete (any town)
- **Photon** (komoot) autocomplete, debounced ≥300 ms, biased to the map bbox,
  filtered to place types (city/town/village/hamlet/municipality). Photon is chosen
  over Nominatim because Nominatim's usage policy forbids type-ahead; the project
  already used Photon in the improve-wizard prototype.
- Hardcoded `CITIES` remain as instant quick-picks (matched first, no network).
- CSP: add the Photon host to `connect-src` (map page).
- Failure mode: if Photon errors/times out, search degrades to index + quick-picks
  silently (no error toast; a muted "place search unavailable" row).

### 3.3 Result list & town card
- Dropdown becomes grouped + scrollable: towns first, then items grouped by layer
  (existing colour chips), total cap ~30 (was 8).
- `openCity(name)` generalizes to `openPlace(name, lngLat)` — works for any geocoded
  place; keeps the 5 km radius; the nearby list now draws from `ITEM_INDEX`, grouped
  by layer, so all ten types appear.

## 4. Part 2 — GPX ride-check

### 4.1 Endpoint
`POST /map/ride-check` — `ROLE_USER`, CSRF-protected, JSON response. New sliding-window
rate limiter `ride_check` (20/day, own cache pool with `when@test` array adapter,
matching `rate_limiter.yaml` conventions). Request: `gpx` file (same `File`
constraints as `ProposeRouteType`: 15 M, gpx extensions/mimes) + `radius` from the
allowed set {100, 250, 500, 1000} m (default 250). Nothing is persisted — no entity,
no migration, no filesystem write.

### 4.2 Service (`RideCheckService`)
1. Reuse `GpxParser` (15 MiB / 50 k-point / ≥2-point guards) → `GpxTrack`.
2. Reuse `TrackProcessor::simplify()` for the query+display geometry. **No privacy
   trim** — the track is shown only back to its uploader and never stored.
   Raw-length guard: ≥ 500 m (shorter is a click, not a ride), ≤ 400 km (route-domain cap).
3. Items query (one raw-DBAL statement, the `SurfaceProfiler` idiom):
   `ST_DWithin(i.geom::geography, :track::geography, :radius)` over `item`, letters
   B–J (A surface segments excluded — corridor noise), states gated by
   `ItemState::servedSqlTuple()`. Per match also select
   `ST_Distance(i.geom::geography, :track::geography)` (metres off-track) and
   `ST_LineLocatePoint(:track, ST_ClosestPoint(i.geom, :track))` (fraction → km along
   ride, ordering key). Cap 200 matches/letter, flag truncation in the response.
4. Routes overlap: `recommended_route` rows (served states) where
   `ST_Length(ST_Intersection(r.geom, ST_Buffer(:track::geography, :radius)::geometry)::geography) ≥ 300`
   (Upstream's penetration-filter idea: corner-crossings don't count as "follows").
5. Response: simplified track coords, distance km, ascent m (null-safe), matches
   grouped by letter ordered by km-along, overlapping routes with shared-length km,
   the radius used, truncation flags.

### 4.3 Map UI
- "Check my ride" control on `/map`, rendered only for authenticated users
  (server-side Twig conditional, like curator affordances). File picker + radius
  select + **a persistent muted notice: "Your GPX is processed in memory and never
  stored."** (i18n key, 4 locales).
- On response: draw the track via `drawLine` as a distinct dashed overlay,
  `fitBounds` to it, halo matched items, open a side panel modelled on the
  corrections panel: one group per layer (colour chip + count), rows
  "name — km 23.4 · 80 m off"; row click centres/highlights the item; radius change
  re-posts; Clear removes overlay + panel. Uploading a new file replaces the previous overlay.
- Errors (bad GPX, too short/long, rate-limited) surface as translated inline
  messages in the panel, reusing `propose_route.error.*` keys where they exist.

## 5. Testing

- `RideCheckServiceTest` against PostGIS fixtures (SurfaceProfiler-test pattern):
  corridor hit/miss per radius, letter A exclusion, state gating, km-along ordering,
  route-overlap threshold, truncation cap.
- WebTests: auth required, CSRF, rate limit, oversize/invalid GPX, happy path JSON shape.
- JS: index/dedup logic covered by the repo's existing lightweight node checks if
  present; otherwise manual verification per project convention.

## 6. Decisions log

- 2026-07-14 — Any-town search via geocoder approved; Photon over Nominatim (policy).
- 2026-07-14 — Ride-check gated to logged-in users; in-memory only, never stored.
- 2026-07-14 — User request: UI must carry an explicit "GPX not stored" notice.
- 2026-07-14 — Client-side-only matching rejected (weaker point-to-segment maths,
  stops scaling beyond one region); storing uploads rejected (indication only).
