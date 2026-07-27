<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Map & Search

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

This is the map/search presentation contract that
[osm-data-architecture.md](osm-data-architecture.md) §8 delegates to: how the
full Commons is surfaced on `/map` — the rail, the tooltip/drawer selection
model, layer rendering, search, deep links, ride-check, street-level imagery —
and which of those contracts are approved but not yet built. It consumes the
data model unchanged; it never defines data semantics.

**Ownership boundaries.** Schema/identity/serving of catalog data:
[catalog-data-model.md](catalog-data-model.md). Per-type edit contracts and the
lifecycle/votability funnel: [edit-items/README.md](edit-items/README.md).
Submission/moderation/confirmation machinery:
[moderation-and-contribution.md](moderation-and-contribution.md). The K route
domain (states, votes, rides, corrections, GPX endpoint):
[route-domain.md](route-domain.md). CSP/CSRF/sanitizer/limiters:
[security-architecture.md](security-architecture.md). The coverage pipeline that
will replace the transitional client-side index:
[coverage-provider.md](coverage-provider.md).

Implementation surfaces: `web/assets/map/map.js` (all client behaviour),
`web/assets/map/catalog-load.js` (boot), `web/templates/map/index.html.twig`
(shell + injected globals), `App\Controller\MapController`,
`App\Controller\RideCheckController`, `App\Catalog\RideCheckService`,
`App\Catalog\CatalogSchemaProvider`.

---

## 1. Principles

1. **The map is the showcase; the rail is the catalog index.** The rail's
   controls expose the full lettered taxonomy as individually toggleable layers
   with live counts. Search, the Curated/Everything mode, and the filter chips
   *compose* to define the visible feature set — the rail is a navigable index
   of the Commons, not a settings panel.
2. **Display toggle ≠ discoverability.** The Curated/Everything mode governs
   **ambient map density only**. Search and the town card are intent-driven
   surfaces and always reach the **full Commons**; "too much" is solved by
   ranking and collapsing (curated first, community tagged and capped), never
   by hiding. (Approved 2026-07-15; presentation details in §12, shipped via
   [coverage-provider.md](coverage-provider.md).)
3. **Omit unknowns.** A drawer record row renders only when the attribute has a
   real value — unknown fields are never rendered blank and never invented.
   The registry-driven empty-prompt rows (§6.2) are the one deliberate
   exception: they render *as invitations to contribute*, visually distinct
   from data rows, never as values.
4. **Honesty labels.** Anything derived, simulated, or illustrative says so on
   the surface where it appears: the L heatmap and planner are labelled
   illustrative/faked (§11), route surface breakdowns carry an
   "estimate · N% of route mapped" method note, best-of is derived (never
   hand-set — funnel in
   [edit-items/README.md](edit-items/README.md#item-lifecycle-and-votability)).
5. **Never break on missing externals.** Every third-party dependency (basemap,
   Photon, Mapillary, Nominatim region boundary) degrades silently to a working
   map. Same for empty catalog pools: `catalog-load.js` boots `map.js` even
   when the catalog fetch fails.

## 2. Map shell and boot contract

- **Stack:** MapLibre GL JS (SRI-pinned from unpkg, see
  [security-architecture.md](security-architecture.md)), OpenFreeMap `liberty`
  basemap style, attribution control `© OpenStreetMap contributors · ODbL`.
  Optional Esri World Imagery satellite base (hidden by default, `#baseSeg`
  Map/Satellite toggle). A Nominatim-fetched region boundary renders as a
  spotlight mask + dashed outline; it is decorative — failure only logs.
- **Boot sequence** (`catalog-load.js`): fetch `window.CC_CATALOG_URL`
  (`GET /map/catalog.json`, `MapController::catalog()`, public, ETag,
  `max-age 3600`) → expose the layers as the `window.CC_*` globals → apply the
  stays merge (PIVOT features tagged `src='pivot'` and appended once to the OSM
  stays pool — a deliberate merge driving the Tourisme-Wallonie attribution
  branch and a single dot-render path) → inject `map.js`
  (`window.CC_MAP_SRC`). `map.js` **execution** waits on the catalog; its
  **download** does not — the template preloads it so both requests are in
  flight together. Catalog-fetch failure still boots the map (empty pools).
- **`_styleReady` rule:** `render()` no-ops until the map `load` event flips
  `_styleReady` — async responses (e.g. the initial best-of fetch) may resolve
  before the style loads, and `addSource`/`addLayer` throw on an unloaded
  style. The `load` handler runs `render()` itself and sees all state mutated
  so far.
- **Page-injected globals** (all nonce'd inline scripts in
  `templates/map/index.html.twig`):

| Global | Content | Audience |
|---|---|---|
| `CC_CATALOG_URL`, `CC_MAP_SRC` | catalog endpoint + digested map.js URL | all |
| `CC_FIELD_SCHEMA` | per-type display-field schema, localized per request (§6.2) | all |
| `CC_I18N` | every string map.js renders itself (`MapController::mapI18n()`); English fallbacks stay inline in map.js so it works standalone | all |
| `CC_PREFS` | `{bikes:[], styles:[]}` value-lists; `[]/[]` for anonymous (§4.4) | all |
| `MAPILLARY_TOKEN` | from `%env(MAPILLARY_TOKEN)%` via `twig.yaml` (§10) | all |
| `CC_RIDECHECK` | `{url, token}` — ride-check endpoint + stateless CSRF token | `ROLE_USER` block only |
| `CC_MY_AREA` | `{lat, lng, place, radiusKm, regionIds, countryCodes}` (any field may be `null`/empty when no base location is set) plus `{url, token}` for `POST /map/my-area` — feeds `scope.js`'s `myArea` kind (§4.5, region-scoping-design.md §7 Phase 4) | `ROLE_USER` block only |
| `CC_IS_CURATOR`, `CC_PENDING`, `CC_MOD_TOKEN` | pending-submission layer + decision CSRF token | curators with completed 2FA only (`MapController::map()` gates on `TwoFactorPolicy::requiresSetup()`) |

- **No preferences endpoint:** rider preferences and i18n ride the page render;
  only the catalog, history, best-of, community, confirmation, and ride-check
  data travel over fetch.
- **CSP:** `App\EventSubscriber\CspSubscriber` — `'unsafe-eval'` is added to
  `script-src` **only** on the `/map` route, solely because mapillary-js
  compiles filter expressions with `new Function()`; `connect-src` enumerates
  the map's external hosts — the host-by-feature table is owned by
  [security-architecture.md](security-architecture.md) §2.

## 3. Cacheable read endpoints

| Endpoint | Purpose | Caching |
|---|---|---|
| `GET /map/catalog.json` | whole served catalog, letters keyed (transitional, §7.1) | public, ETag, max-age 3600 |
| `GET /map/item/{id}/history` | per-item change log for the drawer's "Recent changes"; empty list (200) for never-edited items, never 404 | public, ETag, max-age 60 |
| `GET /map/best-of?season=&bike=` | ranked verified-route ids for the Curated facet (§4.2) | public, ETag, max-age 300 |

All three are exact-path `PUBLIC_ACCESS` in `security.yaml` (the scheb
lazy-firewall caching gotcha — see
[account-and-auth.md](account-and-auth.md) §5).

## 4. The rail

### 4.1 Layer toggles

- The rail lists the **A–K** catalog layers (letter + localized label + icon +
  colour), each individually toggleable, with a **`shown/total`** count that
  reflects the current mode and filters (`layerCounts()`). **Both parts are
  scope-aware:** `shown` applies mode/filters/scope, and `total` is scoped too —
  curated features gate on `inScope()` and coverage uses the server's per-scope
  count — so a region with no data for a letter reads `0/0`, never the global
  total (a region's rail never shows another region's counts). A
  select-all/deselect-all toggle sits above the list. **Default: all catalog
  layers on at load.** Layer labels come from the `item_type.*.label`
  translation keys via `CC_I18N.layers`, so the rail can never drift from the
  improve form / drawer wording.
- **L · Ride heatmap is deliberately NOT a catalog entry:** the generated rail
  lists A–K only; L appears as a separately labelled derived-overlay panel with
  its own On/Off toggle and season chips (§11).
- Curators additionally receive a **⚑ Pending review** layer
  (`CC_PENDING`-driven, red pins) — moderation behaviour is owned by
  [moderation-and-contribution.md](moderation-and-contribution.md).

### 4.2 View mode: Curated best-of ↔ Everything

- `mode ∈ {curated, all}` (button labels "Curated best-of" / "Everything").
  The axis is **votability**, not verification — see the funnel in
  [edit-items/README.md](edit-items/README.md#item-lifecycle-and-votability).
- **Experiential layers** (`layer.exp`: climbs, stays, scenic, history) filter
  to `f.cur` in Curated; utility layers always draw their confirmed pins, and
  their unverified OSM dots draw only in Everything (this gate changes under
  §12). **K routes** honour a server-computed best-of: Curated mode fetches
  `GET /map/best-of` for the active *(season, bike)* facet (`#boSeason` /
  `#boBike` selects, shown only in Curated), flags the returned ids `cur`, and
  filters to them. Membership only — the server's rank order is latent until a
  ranked-list UI consumes it. Facet switches are race-guarded (`_bestOfReq`
  token); on fetch failure Curated shows no picks rather than a stale set.
- A rider with **exactly one** saved bike preselects the Bike facet; multi-bike
  riders keep the neutral `all` (the facet is single-valued).
- **Which mode the map OPENS in (2026-07-27).** The global default is
  **Everything**, not Curated: Curated hides every non-curated experiential item,
  so on an under-curated region it showed a near-empty map behind a rail counting
  hundreds of places (the owner's "1488 where to sleep, 0/1488"). A region opens
  in Curated only once a moderator has flipped `region.curated_default`, and that
  toggle is **gated** on a readiness count — curated items on the experiential
  letters plus verified+voted best-of routes, against
  `map.curated_default_threshold` — so it cannot be set prematurely. Utility
  letters do not count towards readiness: they render in both modes. The gate
  lives on the curator **Regions** desk (`/moderate/regions`), scoped like every
  other desk. Load-time precedence: the rider's saved `users.default_map_mode`
  (profile, NOT localStorage — shared devices) → an anonymous visitor's own
  localStorage choice → the active region's flag → Everything. Resolved once at
  load; a later scope change never re-resolves.
  Design: [2026-07-27-map-view-mode-default-design.md](2026-07-27-map-view-mode-default-design.md).

### 4.3 Filter chips

- **Climb surface (`#sqf`) / traffic (`#trf`)**: legacy always-require
  semantics — a climb missing the attribute is hidden regardless of chip state.
- **Climb effort (`#effortf`) / stay accessibility (`#accessf`)**:
  **narrowing** semantics (`attrMatch()`) — with every chip on (default)
  nothing is filtered, including items with no value; deselecting any option
  also hides valueless items (they can't be confirmed to match). The
  accessibility filter applies to the stays dot layer (`setFilter`), the
  clustered confirmed pins, and the legend counts alike.
- **Discipline chips (`#disc`)**: re-based onto the 7 `RidingStyle` enum values
  (the enum is the chip contract — see
  [account-and-auth.md](account-and-auth.md)); preselected from the rider's
  saved styles; **visual-only** until the dataset carries style tags, honestly
  labelled as such in the template.

### 4.4 Preference prefilter

- Saved **bike types really filter the routes (K) layer** — routes are the only
  bike-tagged layer; nothing else is preference-filtered. Applies in **both**
  modes, composing with (never replacing) the mode/best-of filters.
- **Predicate (`prefMatch()`, unknown ≠ unsuitable):** visible iff
  `!f.bikeTypes || f.bikeTypes.length === 0 || overlap(f.bikeTypes, CC_PREFS.bikes)`.
  Only a *declared* non-overlap hides a route.
- **Never silent:** an active prefilter shows the dismissable rail chip
  `#prefFilter` ("Routes for your bikes", `aria-pressed` reflects state). The
  off state persists in `localStorage` key **`cc-pref-filter`**
  (`on`/`off`, default `on` when preferences exist). Anonymous visitors
  (`CC_PREFS.bikes` empty): zero behaviour change, chip group stays hidden.

### 4.5 Region / My-area scope

The rail's Region group (a per-registry list of named regions/countries plus
Everywhere) and its `scope.js` (`window.CCScope`) client model are the full
region-scoping design owned by region-scoping-design.md — this subsection
covers only the `myArea` scope kind (region-scoping-design.md §7 Phase 4),
the newest rung of that same ladder.

- **`myArea` scope kind.** A rider with a base location gets a **My-area**
  rail button, first entry in the Region group. Unlike `region`/`country`, its
  region set is *derived* (`BaseAreaResolver`, capped at 8) rather than
  curator-authored, so it can span more than one named region — the rail
  button itself carries no fixed boundary.
- **`myarea` token.** Serializes to the bare, literal string `'myarea'` in the
  URL and the `cc-scope` localStorage key — never coordinates. Deserializing
  `'myarea'` re-resolves the scope from its source of truth (below), so a
  shared/bookmarked `?scope=myarea` link always reflects the *opener's* area,
  not the sharer's.
- **Source of truth, logged-in vs anonymous.** Logged in: `window.CC_MY_AREA`
  (server-stored coarse point, §2). Logged out: an anonymous circle
  `{lat, lng, radiusKm}` (2 decimals, ~1 km) in `localStorage['cc-my-area']`,
  written by the map-centre "Set my area" pin drop (cold-start chip, below) — never a
  device-location prompt (owner decision). Anonymous region-set derivation is
  a registry-bbox ∩ circle-bbox intersection, nearest-centre-first, capped at
  8, computed client-side (no round trip).
- **Viewport/search framing uses the circle, not a region union.** `bbox()`
  and the Photon geocode params fit the myArea *circle*, not the union of its
  derived regions' boxes — a border rider's derived set can span two whole
  regions, and framing to their union would zoom out to both entire regions
  instead of the rider's actual area. The derived region set still governs
  **filtering** (best-of `region=<csv>`, coverage tile/search params); only
  the framing differs.
- **Rid-only, no country arm.** `coverageParams()`/`coverageTileFilter()` and
  the best-of region param never send a country code for a myArea scope
  (`countryCode` is always `null`) — Phase 5's country-polygon fallback
  doesn't exist yet to safely resolve a circle to a country, so myArea stays
  region-id-only on those arms until then.
- **Empty derived set is "in scope: nothing", not "no scope."** When a myArea
  scope's derived region set is empty, `coverageParams()` returns `rids: []`
  (an empty array) rather than `null` — a distinct sentinel from Everywhere's
  `rids: null` — so `map.js`'s `fetchCoverageCounts()`/`runCoverageSearch()`
  skip the request instead of building an unscoped query that would silently
  fall back to GLOBAL results (the leak-safe-hide rule of
  region-scoping-design.md §4/§9.1, extended to this client-side seam).
- **Widen ladder:** myArea → the single registry-known country among the
  derived `countryCodes` (exactly one such country, else straight to
  Everywhere — an ambiguous/border myArea has no single "wider" country) →
  Everywhere.
- **Cold-start chip:** a rider/anonymous visitor with no base location sees a
  dismissable "Set my area" chip driven from the current map centre
  (`map.set_my_area`); dismissal persists in localStorage
  (`cc-area-prompt-dismissed`). Setting it calls the myArea endpoint (logged
  in) or writes the anonymous circle (logged out), then activates the
  `myArea` scope immediately.
- **Pan-away nudge:** while a myArea scope is active, panning the map centre
  past **1.5× the radius** from the circle's centre surfaces a dismissable
  "Outside your area" nudge (`map.outside_area`) with a widen action
  (`CCScope.widen()`); it never auto-widens the map itself, and fires **once
  per page load** (re-dismissing doesn't re-arm until reload).
- **Out-of-scope town opens transiently widen (owner decision 2026-07-21):**
  town search is scope-exempt (a place is an explicit location choice), so
  opening a town whose coordinates fall **outside the current scope's bbox**
  transiently widens to Everywhere via the deep-link mechanism
  (`persist:false` — localStorage/URL keep the saved scope, which returns on
  the next plain load). Without this, the scope-exempt town drawer filled
  with nearby items while the scoped map rendered the same area empty — the
  worst case being a myArea scope with an **empty derived set** (base
  location outside every seeded region, e.g. a Dutch base today), where the
  whole map is leak-safe-hidden. Bbox containment is the deliberate
  approximation: an inside-bbox town already renders its surroundings, so no
  widen is needed there. Applies to every scope kind, not just myArea.
- **Default precedence (owner decision, region-scoping-design.md §9.1):** on load, `URL scope > myArea
  (if available) > localStorage`. My-area wins the default scope whenever a
  base location is set, overriding a stale localStorage scope — except an
  explicit shared URL scope, which always wins.
- **Country / multi-region scope dim mask (2026-07-22).** A country scope now
  greys the rest of the map instead of rendering with no visual boundary at
  all — the gap a second bordering country (the Netherlands) exposed once a
  scope could span more than one named region. `GET /map/scope/boundary`
  (`MapController`, new) takes the same scope params the coverage endpoints
  use (`rids` csv and/or `cc`) and returns a single GeoJSON Feature = the
  `ST_Union` of the matching `region.geom` rows (ETag + `max-age=3600`; 204
  when the scope resolves to no regions). For a **country** scope, `applyScope`
  fetches this union and draws the existing world-minus-shape dark mask +
  dashed outline (the same `region-mask`/`region-line` layers a single-region
  spotlight already builds). A **single named region** keeps drawing its own
  boundary (`GET /map/region/{slug}/boundary`, unchanged). **My-area** keeps
  its own soft ~64-vertex circle (`line-blur`) rather than switching to the
  union mask — the deliberately fuzzy edge is itself the anti-border message
  (map-and-search.md §4 above). **Everywhere** clears the mask entirely
  (`setSpotlight(null)`); no mask ever draws for an empty scope. Design +
  browser-verified results (NL union outline, single-province outline, no
  mask for Everywhere): [2026-07-22-coverage-scope-rendering-design.md](2026-07-22-coverage-scope-rendering-design.md) §B.
- **Cross-border scope chips rank by adjacency (2026-07-24).** The scope chips
  offered around a region (compass grid + linear list) include a *foreign*
  region ONLY when it shares a border with the active region — never by centroid
  distance. So a border region is offered its true cross-border neighbours
  (Groningen → Lower Saxony) while an interior region stays single-country
  (Utrecht stays all-Dutch), each by construction, not tuning. Adjacency is
  precomputed at catalog import into `region.adj` (`integer[]`, one
  `ST_Intersects` pass across all onboarded countries) and shipped inline in
  `CC_REGIONS`; `chipModel` (`scope-chips.js`) gates foreign chips on it. Supersedes the
  centroid ranking of
  [2026-07-23-cross-border-chips-design.md](2026-07-23-cross-border-chips-design.md).
  Design: [2026-07-24-region-adjacency-and-click-refinement-design.md](2026-07-24-region-adjacency-and-click-refinement-design.md) §2.
- **…and are ORDERED by polygon-edge distance (2026-07-27).** Within the pool
  adjacency has made eligible, regions sort by the distance from the anchor to
  the nearest point on the region itself — 0 when the anchor is inside it —
  rather than to its bbox centre. Centre distance misjudged anything large or
  oddly shaped: from Groningen, the region it borders (Lower Saxony, 26 km)
  ranked below one it does not (Bremen, 119 km), and a rider in Duisburg was
  offered a Dutch region ahead of the German one they were standing in. The
  geometry is a **ranking outline** precomputed at import into `region.outline`
  (`jsonb`, exterior rings simplified to 0.05°, flat `[lng,lat,…]`, ~18 kB for 32
  regions) and shipped inline in `CC_REGIONS`; real boundaries still come from
  `RegionBoundaryProvider`. Eligibility is still adjacency, so Utrecht stays
  all-Dutch. A region with no outline falls back to its bbox centre.
  Design: [2026-07-27-region-edge-distance-ranking-design.md](2026-07-27-region-edge-distance-ranking-design.md).
- **Map-click scope refinement (2026-07-24).** A map click resolves to its region
  by a synchronous bbox candidate pass; when 2+ region bboxes overlap the point,
  `CCScope.regionOfPointPrecise` fetches those candidates' polygons
  (`/map/region/{slug}/boundary`, cached) and runs a pure point-in-polygon test,
  so the click lands in the region actually under it, not the nearest bbox
  centre. A single candidate never fetches (the common case stays instant);
  inside no candidate polygon it falls back to nearest-centre. Design:
  [2026-07-24-region-adjacency-and-click-refinement-design.md](2026-07-24-region-adjacency-and-click-refinement-design.md) §3.
- **Three-tier region spotlight (2026-07-24).** For a single named-region scope,
  the active region's border-neighbours (`region.adj`) render at a **middle** dim
  tone — lighter than the fully-outside world (`0.13` vs `0.22`), darker than the
  clear active region — each **individually** outlined with a fainter dashed line
  than the active region's. Dark-mask holes come from the dissolved
  `ST_Union(active + adj)` (`/map/scope/boundary?rids=<active,adj>`, one clean
  blob so no two holes touch); the middle-tone fill + per-neighbour borders come
  from each neighbour's own `/map/region/{slug}/boundary`. Mask hole rings are
  forced clockwise (opposite the CCW world ring) so MapLibre never mis-classifies
  a same-wound hole as a solid dark wedge (an intermittent, zoom-out-only
  artifact otherwise). Country / My-area / Everywhere spotlights are unchanged.
  Design: [2026-07-24-region-adjacency-and-click-refinement-design.md](2026-07-24-region-adjacency-and-click-refinement-design.md) §8.

## 5. Layer rendering strategy

- **Point features (curated/confirmed)** render as **DOM markers**
  (`maplibregl.Marker` with a `.cc-pin` element), not canvas symbols — this is
  the accessibility contract: each pin gets `tabIndex=0`, `role="button"`, an
  `aria-label` of `name — headline`, Enter/Space activation, and focus shows
  the tooltip. Click handlers call `e.stopPropagation()` so the click never
  bleeds through to canvas layers underneath (which would open a second drawer
  and lose the history-fetch race).
- **Bulk-OSM pools** (services/scenic/history/stays/shelter/transit via the
  `OSM_BULK` table; water separately in `addWaterOsm()`) render as **canvas
  symbol layers** of small category discs minted at runtime
  (`miniIcon()`: category-colour disc + flat silhouette glyph — deliberately
  canvas-drawn because colour-emoji fonts can't be assumed installed). D · bike
  services picks a per-`serviceKind` glyph via a `match` expression
  (`shop ⚙ / station ⚒ / pump ⊕` — plain BMP symbols, not emoji; the kind
  contract lives in [osm-data-architecture.md](osm-data-architecture.md) §5).
  **Confirmed** points (`p.c`) are excluded from the dot layers and promoted to
  clustered DOM icon pins (`setupConfClusters()`: count bubbles at low zoom →
  leaf pins when spread; cluster/leaf reconciliation runs only on
  `moveend`/`idle`, never per animation frame).
- **A · Road surface** renders **consolidated**: ONE GeoJSON source for all
  segments + one shared cream casing layer + **one line layer per surface
  class** — MapLibre cannot data-drive `line-dasharray`, so dash/cap vary per
  class layer, not per feature. Style keys **primarily on `surface=`,
  secondarily on `smoothness=`** (avoiding CyclOSM's known bug that hides
  gravel under `smoothness=intermediate`). Class palette in `SURFACE_STYLE`
  (map.js): teal cycleway, slate paved, dashed ochre gravel, square slate-grey
  pavé dashes, dashed brown dirt, dotted dark-grey rock, and red dashes for
  `unverified` (no surface tag — "needs a tag"). Re-render is a single
  `setData`; click/hover listeners bind once per class layer and resolve the
  feature via `properties.idx`.
- **B · Climbs** with traced geometry draw a gradient-coloured line
  (`line-gradient` over `line-progress`, purple ramp `gradColor()`) plus a
  "steepest pitch" marker; the pin sits at the climb **foot** (first route
  vertex). The same purple ramp renders the 1–5 difficulty scale in the drawer
  (deliberately "climb-coloured").
- **K · Routes** draw in a **pre-blended lighter orange at full opacity**
  (`ROUTE_BASE_COLOR`) instead of a translucent line — translucent lines
  stacked where routes share a road read as random darker segments. The
  **selected** route gets full brand orange, a wider halo, and dims every
  sibling (`highlightRoute()`); selection survives `render()` rebuilds and is
  cleared on drawer close / non-route open.
- **Stacking rule** (bottom → top): route lines, climb lines, road-surface
  lines, Mapillary coverage — re-applied after any selection `moveLayer` so a
  highlighted route never buries the surface colours
  (`liftInfoLayersAboveRoutes()`).

## 6. Selection model: tooltip + drawer

### 6.1 Interaction contract

- **Hover / keyboard-focus** → lightweight tooltip (`name · headline`), hidden
  on map move. The tooltip is non-essential; the drawer is the source of truth.
- **Click / Enter** → the right-side detail drawer (bottom sheet ≤ 820 px,
  §6.6). Map stays visible and interactive. Dismissible via close button,
  Escape, and (mobile) scrim tap / flick. Focus moves into the panel on open
  (`preventScroll`, not onto the close button).
- **Selection halo + centring:** opening a point feature drops a pulsing halo
  at the feature's exact coordinates and calls `flyToPin()` — centre + zoom to
  ≥ 14 with a `[-150, 0]` offset so the desktop drawer doesn't cover the pin.
  The halo offset is anchor-aware: `[0,-16]` for bottom-anchored teardrop pins
  (confirmed/curated/pending — the halo rings the icon, not the ground point;
  climbs use the foot vertex), `[0,0]` for centred canvas dots and lines.
  Routes get the line highlight instead of a point halo. The halo persists
  while the drawer is open; `closeDrawer()` clears halo, route highlight, and
  any corrections overlay.
- **Coverage renders as a density heatmap at overview, individual icons from
  z9 — no clustering, phantom-free at every zoom.** A coverage POI's icon is
  drawn by its tile `<key>-<cc>-cov` layer (`minzoom 9`); the tiles carry every
  point complete at z11–14, so the z9–10 icons are the **thinned** sample that
  densifies to complete by z11 (the rail counts stay the exact total). At
  overview zoom (z6–~9), a `<key>-<cc>-heat` heatmap layer on the **same**
  source-layer (`maxzoom 9`) draws the region's coverage as a smooth density
  surface, built from the tile's thinned z6–10 sample (`coverage-provider.md`
  §4) — this fills the empty overview that the no-cluster z11 floor had left,
  with the rail's exact `/map/coverage/counts` still carrying the precise "how
  much" alongside it. The two layers **cross-fade at z9** (heat `maxzoom 9`,
  icon `minzoom 9`) so the actual spots are visible at the region-fit landing
  zoom. The heat colour ramp runs to a deep green at max density (a pale top
  stop punched white "holes" in a dense single-letter surface). Both are
  scope-filtered exactly per point (`covIconFilter()` for the icons,
  `covHeatFilter()` — scope only, no dedupe/accessibility narrow — for
  the heat), which is what eliminates the phantom bubbles a prior
  cluster-bubble design produced at region borders (a cluster rendered at
  its members' centroid, which could sit outside the scoped region): a
  heatmap has no centroid to leak, only in-scope points contribute density,
  so a soft feather at the region edge is honest rather than a false marker.
  Design:
  [2026-07-24-coverage-overview-heatmap-design.md](2026-07-24-coverage-overview-heatmap-design.md)
  (supersedes the z6–10-is-empty behaviour of
  [2026-07-24-coverage-no-cluster-design.md](2026-07-24-coverage-no-cluster-design.md)
  §2, which itself supersedes
  [2026-07-22-coverage-scope-rendering-design.md](2026-07-22-coverage-scope-rendering-design.md)
  §7); tile contract: [coverage-provider.md](coverage-provider.md) §4.
- **Selected coverage POI stays visible on zoom-out:** a coverage POI's icon is
  drawn only by its tile `<key>-<cc>-cov` layer, which the z9 minzoom hides
  on zoom-out — so zooming out past z9 with a coverage POI selected would
  leave the halo ringing empty space (the heatmap conveys density, not the
  selected feature itself, so it doesn't fill that gap). A single-feature
  GeoJSON overlay (`cov-sel` source + `cov-sel-icon` layer) redraws the
  *selected* POI's icon on top, independent of the tile minzoom, so it stays
  visible at every zoom (the halo then rings it). `showSelectedCoverageIcon()`
  sets it on open (mirroring the tile icon-image + size ramps);
  `openDrawer()`/`closeDrawer()` clear it.

### 6.2 Registry-driven attribute rows

`CatalogFormRegistry` is the **single source of truth** for which per-type
attributes the drawer shows; the hand-maintained map.js whitelist model is
permanently retired.

- **Delivery:** `App\Catalog\CatalogSchemaProvider::all()` walks the registry,
  keeps `CatalogField::$display === true` fields in `fields`-then-`addFields`
  order, resolves labels through the translator once at serialize time, and is
  injected as `window.CC_FIELD_SCHEMA = {A: […], …, K: […]}` — **localized per
  request, deliberately kept OUT of `catalog.json`** (that endpoint is
  locale-agnostic and HTTP-cached; never inflate the cached bulk payload with
  locale-varying data).
- **`display` flag semantics:** intake-only fields (the `name` title field,
  free-text `correction` textareas) are `display: false` and never render as
  drawer data rows; genuine note/"anything to add?" fields stay
  `display: true`.
- **Rendering (`schemaRows(letter, src, id, opts)`):** every declared field
  renders — **flat list, filled or empty, no collapsing** (maximum contribution
  invitation, an explicit product decision):
  - *value present* → a normal row; `multiselect` values render as chips;
    `url` kinds linkify through the same `safeHref`/`links` path as structural
    links (a plain row must never dedup away a linkified row).
  - *`rating` kind* → star glyphs. The kind is **registry-derived**
    (`CatalogSchemaProvider::renderKind()`: any select whose choices are
    exactly `['1'..'5']`), no hard-coded keys in map.js.
  - *unset, item has a real DB id* → a muted **"＋ add" prompt** linking
    `/improve?item=<id>&type=<letter>&field=<key>` (the edit-bridge;
    `field=` is honoured by the wizard as a scroll/focus hint — the wizard-side
    entry-point matrix is owned by
    [moderation-and-contribution.md](moderation-and-contribution.md)).
  - *`opts.fixed`* → an assumed default shown **as a value row** instead of an
    "add" prompt when unset (a stored value wins). Used for unmanned bike
    services (station/pump): the drawer states the implied
    "Opening hours · 24/7" read-only row rather than an add prompt — a stored
    value always wins, and the kind-aware form default (24/7 preselected,
    overridable) is owned by
    [edit-items/D-bike-services.md](edit-items/D-bike-services.md)
    (kind contract: [osm-data-architecture.md](osm-data-architecture.md) §5).
  - *`opts.skip`* → fields a builder renders structurally (e.g. routes'
    difficulty badge, water's structural type/potable rows) are excluded so
    they are never double-rendered.
- **Localized choice values:** each schema field may carry a
  `choices: {canonical stored value → localized label}` map. Stored values are
  **canonical English**; choices translate for display only. A merged
  `VALUE_TR` map serves values rendered outside `schemaRows` (headlines,
  difficulty badge, surface mix).
- **Structural/derived rows stay hand-authored** and are NOT registry
  attributes: Type, Town, Province, Listed, Source, status badges
  ("Proposed · ride it to verify"), the difficulty badge, routes' derived
  Surfaces breakdown (with its estimate method note). A **set** attribute row
  replaces a structural row of the same label (dedup-by-label); empty prompts
  never displace structural rows.
- **Accepted losses** (recorded product trade-offs): per-row `OSM` provenance
  tags on the surface drawer's Surface/Smoothness rows were dropped —
  provenance shows only on the Source line; route `season` renders as chips.

### 6.3 Drawer anatomy

Header: type chip (letter + localized layer label, layer colour), feature name,
"▲ Curated best-of" badge when `f.cur`. Body: photo (main image + thumbnail
strip, opening a slideshow lightbox with ‹ › buttons and ←/→ keys, per-photo
credit linking licence deed + source + author, N/M counter — media rules in
[edit-items/README.md](edit-items/README.md)); or, when the item has a DB id
and no photo, an **add-photo CTA** deep-linking the improve wizard; description;
difficulty scale; elevation profile (static SVG, §13.2 upgrades it); gradient
strip; the record rows (§6.2) with per-row method tags where present; freshness
block (state + last-confirmed) for safety/dynamic items; uploader line
(public profile link or "shared anonymously"); the **Source line** — the single
provenance surface, linkifying OpenStreetMap to the object's coordinate query
view and PIVOT to the Géoportail catalogue entry. `srcType` (the item's real
`ItemSource`) overrides the OSM-flavoured headline/source for rider-contributed
items served through bulk-OSM layers.

Footer actions are per-type:

| Feature | Actions |
|---|---|
| Item with DB id (non-K) | `✎ Edit this item` (`/improve?item=&name=&type=&lat=&lng=`) + `◎ Fix location` (same query + `fix=location`, **only when coordinates are known**) |
| Votable types (`CC_VOTABLE = {climbs, stays, scenic, history}`), curated | `▲ Vote in this round` |
| Confirmable utilities (`CC_CONFIRMABLE = {water, services, hazards, transit, shelter}`) with DB id | confirmation panel (potability / "still here?"), hydrated async — contract in [moderation-and-contribution.md](moderation-and-contribution.md) |
| K route | community panel (rode-it / typed vote / suggest-a-correction / `⤓ Download GPX` from `GET /routes/{id}.gpx`) — contract in [route-domain.md](route-domain.md); riders never get an edit link |
| Pending submission (curators) | moderation card (approve / needs-info / reject, A/R arm-then-Enter keyboard flow) — [moderation-and-contribution.md](moderation-and-contribution.md) |

**No id ⇒ no edit/add links**: the edit-bridge only ever binds to a real DB
item id — a name-slug guess is never a faithful target.

**Async hydration:** the drawer HTML lands synchronously; "Recent changes"
(`/map/item/{id}/history`), route community state, and confirmation counts are
fetched after, each race-guarded (a bumped request token drops stale
responses) and silent on fetch failure — enhancements never block the drawer.
Empty history renders nothing ("no changes yet" is silence, not a section).

### 6.4 Drawer accessibility

- Empty-state styles meet **WCAG AA ≥ 4.5:1** against the drawer ink
  (`--ink` #101E16): `.cc-d-rec li.empty .k` at `rgba(239,230,212,.6)`,
  `.cc-d-add` at `.62` (values in `web/assets/styles/map.css`). Empty-vs-filled
  state is carried by **row content** (a value vs a "＋ add" link), never by
  dimmed contrast/colour alone.
- Interactive drawer links get `:focus-visible` rings
  (`2px solid var(--trail)`).
- **`◎` is the shared location glyph** (drawer Fix-location and the wizard's
  change-location button) — unicode, not emoji (Chrome emoji rendering is
  unreliable; same precedent as the SVG flags decision).
- Drawer structural strings localize via `CC_I18N.d` (the `map.d_*` keys); all
  lookups keep English fallbacks.

### 6.5 Town / place card

`openPlace(name, meta)` renders a place card in the same drawer: place type
chip (City ◉ / Town ◎), optional blurb + Wikipedia link (hand-authored for the
`CITIES` constants; Photon hits pass just coordinates), and **"In the Commons
nearby · ≤ 5 km"** — every indexed item within 5 km, grouped by letter,
nearest-first within each group. **A · road-surface segments are excluded**
(corridor data would flood the card). Rather than zooming to the place, the
card **fits bounds over the place + all nearby items** (drawer-aware padding,
`maxZoom 13.5`) so hover-pulsed items are actually on screen; hovering a row
pulses an anchor-aware halo (`hlOff`, §6.1). A pulse over empty map in Curated
mode is intentional — it locates items whose dots the mode filter hides. Route
drawers link their towns (`Starts at` / `Towns on route`) to the same card.

### 6.6 Mobile snap sheet (≤ 820 px)

Three resting states: **peek** ≈ `7.5rem` (handle + title, map fully
interactive), **half** ≈ `50svh` (**every drawer-open path lands here**),
**full** = the sheet's natural height capped at `80svh` (`max-height` on
`.cc-drawer`; peek is the `--peek` custom property, half/full are the
`.open`/`.s-full` resting transforms — all in `web/assets/styles/map.css`).
Rules:

- Content scrolls **only at full** (`overflow: hidden` below); below full any
  vertical drag moves the sheet; at full a downward drag while
  `scrollTop === 0` grabs the sheet (scroll↔drag hand-off).
- Release snaps to the nearest state, velocity-weighted; a fast flick skips to
  the neighbouring state in its direction; a downward flick from peek — or
  dragging well past peek — dismisses. `touchcancel` re-settles on the last
  resting state. Drags start from the sheet's *actual rendered transform*
  (retracted-URL-bar-proof), and short drawers anchor at the bottom
  (`max(0px, …)` resting transforms).
- Scrim shows **only at full**; below full the map above the sheet stays
  interactive (tapping another item re-fills the drawer — accepted).
- The grab handle is a real `<button>` (localized `aria-label`): Enter/Space
  cycles half ↔ full; Escape closes.
- Desktop (> 820 px): side panel, none of this code engages. The map only
  `resize()`s at the 820 px layout flip.

## 7. Search

### 7.1 The unified item index — TRANSITIONAL

One deduplicated client-side **`ITEM_INDEX`** (built by `buildItemIndex()`,
map.js) covers every served item exactly once, across all pools: `CATALOG`
features (curated climbs/hazards/routes/pending) first, then PIVOT stays, then
every bulk-OSM pool **including water** (whose dot layer registers outside
`OSM_BULK` and must be appended explicitly). Both the search dropdown and
`nearbyItems()` consume it. Dedup, two passes:

1. **letter + id** — the deliberate PIVOT→OSM stays concat means concat'd
   features share ids; first entry wins.
2. **cross-source physical doubles** — same letter + normalized name
   (diacritics-folded slug) within **100 m** keep the earlier entry; build
   order makes that curated/PIVOT over a raw OSM import.

**Unnamed POIs** (most water taps, many shelters) index under their type label,
flagged `unnamed`: listed and highlightable in place cards and ride-check rows,
**excluded from the text-search dropdown** (nothing to text-match), and
**exempt from the name-proximity dedup** (two real taps 80 m apart share the
fallback label).

Entries carry `hlOff` (halo anchor offset, §6.1) and a `go()` opener; pending
entries carry the submission id so a moderation decision drops them from both
the index and the dropdown.

> **Transitional scope:** this whole-catalog-to-client model is superseded
> going forward by [coverage-provider.md](coverage-provider.md) — the local
> index shrinks to the curated pool and coverage search/nearby move to
> `/map/coverage/*` endpoints. The **surviving contracts** are the dedupe
> semantics, the unnamed-POI rules, the Photon policy (§7.2), the grouped
> presentation (§7.3), and the drawer/halo behaviour.

### 7.2 Any-town place search — Photon policy

- **Photon (komoot), never Nominatim, for type-ahead**: Nominatim's usage
  policy forbids autocomplete. (Nominatim is still used for the one-off region
  boundary fetch.) Photon host is in the CSP `connect-src`.
- Debounced (350 ms; the local index re-ranks at 150 ms — both `setTimeout`
  constants in the map.js search block), ≥ 3 chars, one in-flight request
  (stale ones aborted), bbox-biased to the region, filtered to place types
  (`place:city|town|village|hamlet|municipality`), capped at 6 with local
  quick-picks winning over their Photon twin.
- The hardcoded `CITIES` constants remain instant quick-picks (matched first,
  no network).
- **Failure mode: silent degradation** to index + quick-picks — no toast, no
  error state. (The muted "place search unavailable" row from the design was
  not shipped; degradation is fully silent — see Open questions.)
- **Shipped:** Photon results filter to
  `properties.countrycode === 'BE'` (precise gate; bbox stays the coarse
  pre-filter) to kill cross-border near-spellings; relaxing it for the
  worldwide flip is a recorded open flag (§12, landed with the
  coverage-provider work).

### 7.3 Dropdown presentation

Grouped and scrollable: **Places first** (local towns, then geocoded ones),
then items grouped by catalog letter A–K with colour chips, **total cap 30**
(`CAP` in map.js `runS()`). Prefix matches rank above substring matches. The
flat `sMatches` list preserves display order so keyboard navigation
(↓/↑/Enter/Escape, `role="combobox"`/`listbox`) is untouched by grouping.

**IME rule (hard-won):** `aria-expanded` is written **only on real open/close
transitions, never per keystroke** — mutating an attribute of the element being
IME-composed restarts composition on Android Chrome, re-anchoring the caret at
0 and reversing typed text ("spa" → "aps").

## 8. Deep links

Handled in the map `load` handler; all query-param based (no hash state — §13.1
is the pending permalink contract):

| Param | Behaviour |
|---|---|
| `?feature=<name>` | exact-name match over `CATALOG` features: activates the layer if hidden, opens the drawer, flies to the pin. The profile-card → map contract. (Coverage POIs become linkable via the coverage plan's index-then-endpoint fallback.) |
| `?pending=<id>` | curator deep link from the /moderate queue: activates the ⚑ layer, opens the submission drawer |
| `?route=<id>` | opens that K route **selected** (curator Routes desk link): currently force-switches to Everything so an un-voted route can render, then highlights + shows the curator corrections overlay. The force-switch is slated to become a reveal pin (§12). |

## 9. Ride-check ("what's along my GPX?")

Logged-in riders upload a GPX and see the catalog items inside a chosen
corridor of the track. **Read-only indication — the GPX is parsed in memory,
answered, and discarded; nothing is ever persisted**, and the UI carries a
persistent notice saying so (`ride_check.notice`, an explicit user
requirement).

- **Endpoint:** `POST /map/ride-check` (`RideCheckController::check()`).
  In-controller auth (clean 401 JSON, never a login redirect); stateless CSRF
  token id `ride-check` (`config/packages/csrf.yaml`), token injected by the
  template inside the `ROLE_USER` block; per-user sliding-window limiter
  `ride_check` (limits in the inventory:
  [security-architecture.md](security-architecture.md)). The rail control
  renders only for authenticated users (server-side Twig conditional).
- **Validation** (`App\Catalog\RideCheckService`): radius ∈
  `ALLOWED_RADII = {100, 250, 500, 1000}` m, default `DEFAULT_RADIUS = 250`;
  raw track length within `MIN_RAW_M = 500` m … `MAX_RAW_M = 400 km` (the
  route-domain cap). GPX parsing reuses the route-intake `GpxParser` guards;
  geometry is simplified via `TrackProcessor::simplify()`. **Deliberately no
  privacy trim** — the track is shown only to its uploader and never stored;
  trimming would drop matches near the rider's real start/end.
- **Corridor query idiom (hard-won, sub-second where the naive form took
  62 s):**
  `WITH track AS MATERIALIZED (…GeomFromGeoJSON…), corridor AS MATERIALIZED (ST_Buffer(track::geography, :radius)::geometry)`
  probed with **`ST_Intersects(i.geom, corridor)`** — `ST_DWithin(::geography)`
  cannot use the GIST index, and an inlined track CTE re-parses the GeoJSON per
  row per `ST_*` call. Letters **B–J only** (the SQL excludes A; K is absent
  because routes live in `recommended_route` and get their own overlap query).
  States gated by `ItemState::servedSqlTuple()`. Per match:
  `ST_Distance` (metres off-track) and
  `ST_LineLocatePoint(track, ST_ClosestPoint(…))` (fraction → km-along, the
  ordering key). Cap `MAX_PER_LETTER = 200` per letter with a `truncated` flag.
- **Coverage arm (open POIs along the ride)** — a parallel `coverage` result
  (`RideCheckService::corridorCoverage()`, design
  `2026-07-26-ride-check-coverage-design.md`) runs the *same* MATERIALIZED
  corridor over `coverage_poi`, limited to utility letters
  `COVERAGE_LETTERS = {C, D, G, H}` (water, bike services, transport, shelter).
  **Deduped against served curated items** on `(source_ref, letter)` — if a
  rider already curated an OSM entity it shows once, as the curated pick, never
  in both arms. `coverage_poi` and `item` are co-located on CC's own cluster, so
  the dedup stays a local join. Same grouping/ordering/`MAX_PER_LETTER` shape as
  the curated arm (shared `groupByLetter()`). Coverage items additionally carry
  their `ref`: `id` there is a `coverage_poi` row id and no endpoint accepts one,
  so the `ref` is what lets a result row be opened at all
  (`/map/coverage/poi/{ref}`). The curated arm addresses items by id and carries
  no `ref`.
- **Coverage rendering** (`web/assets/map/ride-check.js`) — the coverage arm gets
  its **own `ridecheck-cov` symbol overlay** along the track, drawn with the
  small coverage icons and the `cov-sel` size ramp, so curated (bigger spot pin)
  vs open coverage (smaller icon) stay visually distinct. The overlay is
  deliberately independent of the coverage tile layers' on/off state, the region
  scope and the view mode: an uploaded ride routinely leaves the rider's scope,
  so relying on the tiles would list refill points the rider cannot see. It is
  torn down by Clear together with the track. In the drawer, coverage is a
  separate section under its own heading with a provenance note, so uncurated
  OSM never reads as a verified Commons pick; rows call `openCoverageByRef()`.
- **Route-overlap "follows" floor:**
  `max(ROUTE_MIN_OVERLAP_BASE_M = 300, 2·radius + 100)` m of shared length —
  a fixed 300 m fails at larger radii, where a mere perpendicular crossing
  yields ~2×radius of overlap inside the buffer.
- **UI:** track draws as a distinct dashed dark overlay with drawer-aware
  `fitBounds`; results render in the **standard right-hand drawer** (town-card
  styling: per-letter group headers, "name — km 23.4 · 80 m off" rows, followed
  routes with shared km, Clear button). **Closing the drawer keeps the track
  overlay**; the rail status shows "X km · results · clear" to re-open or tear
  down. Radius change re-posts; a new file replaces the previous overlay.
  Ride-check owns the **`.cc-ride-*`** CSS namespace (it once collided with
  the route-community `.cc-rc-*`). Errors surface as translated inline
  messages.

## 10. Street-level imagery (Mapillary)

- **Token dormancy contract:** `MLY_ENABLED` only when `window.MAPILLARY_TOKEN`
  (injected from env `MAPILLARY_TOKEN` via `config/packages/twig.yaml`)
  matches `/^MLY\|/` and isn't the placeholder. Disabled ⇒ the `#ovStreet`
  control renders unavailable with an instructive hint and **zero Mapillary
  network calls fire**.
- **Coverage rendering:** Mapillary's public vector tiles — the `sequence`
  source-layer as lines and the `image` source-layer as dots, both in Mapillary
  green `#05CB63` (deliberately outside the earthy palette so it reads as
  imagery coverage, the Street-View-blue analogy). Hidden by default; inserted
  below pin markers, above ride/surface lines (§5 stacking).
- **Click → image:** the nearest rendered `image`-layer dot within a widening
  pixel search resolves the image id **straight from the tiles** — no Graph
  API on the click path (the Graph-API bbox resolver is retained as an unwired
  legacy fallback). A "you-are-here" camera marker (`.cc-pin`, `#05CB63`)
  drops at the image location and tracks the viewer's `image` event.
- **Viewer:** official mapillary-js in a bottom dock, **lazy-injected from
  unpkg on first open** (SRI-pinned, double-injection guard) so the map never
  pays for it. Dock slides up immediately with a loading state; ⤢ toggles
  fullscreen; ✕ closes and removes the marker. The dock owns the bottom edge on
  mobile (hides the filters peek).
- **Resizable dock:** top-edge grip (`#mlyGrip`, `role="separator"`), pointer
  drag + ↑/↓ keys (24 px steps) when focused, double-click resets to the CSS
  default; height clamped **[160 px, 85 vh]**, persisted per browser in
  `localStorage` **`cc-mly-dock-h`**; every change calls `viewer.resize()` (the
  canvas measures itself only at mount). Fullscreen stashes/restores the inline
  height and disables the grip.
- **Error behaviour:**

| Condition | Behaviour |
|---|---|
| No / placeholder token | control unavailable + hint; zero network calls |
| Vector tiles fail | MapLibre logs; rest of the map unaffected |
| No image dot near the click | dock message "Zoom in and click a green dot…" (`map.mly_zoom`) |
| mapillary-js CDN fails | dock closes; popup "Viewer failed to load." |

- **CSP companion:** `'unsafe-eval'` scoped to `/map` exists solely for
  mapillary-js (§2); the unpkg dependency is the accepted external exception,
  SRI-pinned like maplibre-gl.

## 11. L · Ride heatmap and the illustrative planner

- L is a **derived overlay** (never a catalog entry, never editable — see the
  A–L table in [edit-items/README.md](edit-items/README.md)): its own rail
  panel with On/Off + season chips (All/Spring/Summer/Autumn/Winter), default
  **Off**. Season chips set a layer `filter` on the per-point season tag; a
  chip selected before the layer exists is honoured on first build.
- The heatmap source/layer is built **lazily on the first On** — thousands of
  points allocated at load for a default-off layer was pure startup cost.
- Heat data is derived at build time from sample GPX (downsampled ~1 point /
  110 m, served as catalog.json's L layer); raw `.gpx` files never ship. The
  real anonymized-ingest heatmap (map-match-then-discard, k-anonymity) is
  unbuilt; its privacy contract lives in the public wiki data catalog and gets
  its own spec when built.
- The **"Plan from Spa" planner is openly faked**: distance chips pick the
  nearest sample loop by km, draw it, and open a drawer that carries the
  warning "⚠ Faked — the real planner stitches from the heatmap". The real
  planner and geolocation are explicitly deferred.
- **Ride privacy:** route/heat fixture tracks have their first and last
  ~350–750 m trimmed (`trimEnds()`, **seeded by route id** so the trim is
  deterministic per route — located-correction fractions are stored relative
  to the trimmed path). The canonical end-trim rule for real route intake is
  owned by [route-domain.md](route-domain.md).

## 12. Community tier

Approved 2026-07-15; implemented via
[coverage-provider.md](coverage-provider.md) (its §6 rebases these decisions
onto the tile/endpoint data source; the `verified` flag derives from real
canonical state — `CatalogProvider`'s `v:1`: verified state, a rider
confirmation, or official-registry provenance (Tourisme Wallonie PIVOT rows
count as verified — owner decision 2026-07-17) — never the simulated `c`
attribute).

- **1.** Search and the town card always read the full Commons; every index
  entry carries a `verified` flag; within each category verified/curated rows
  order first, then a tagged **community** subgroup. Town card: community
  subgroup capped (~3 rows) behind a "show all N …" expander; the same
  collapse ("+N more") is the mobile behaviour — one code path. Search rows
  get a dimmed `community` sub-tag; the 30-row cap stands.
- **2.** The map draws **unverified utility** items in BOTH modes: verified
  utilities keep the full type icon; unverified get a lighter **community
  marker** — a single CSS modifier (hollow / ~55 % opacity, same colour so the
  type still reads), no new icon set. Experiential layers stay best-of-only in
  Curated.
- **3.** Picking a non-drawn **experiential** item from search in Curated mode
  drops a single temporary **reveal pin** (community style + selection halo)
  and opens its drawer — never force-switches the map to Everything. Cleared on
  the next pick, drawer close, or mode change. The existing `openRouteById`
  force-switch (§8) must adopt the same pattern.
- **4.** Photon `countrycode === 'BE'` filter (§7.2).

## 13. Pending map enhancements — **Specified, pending implementation**

Approved 2026-06-26; deliberately deferred to the Symfony app (never built in
the HTML demo). Current map.js has no hash-state code and a static elevation
SVG.

### 13.1 Full-state permalink

- Hash grammar `#<zoom>/<lat>/<lng>&l=<letters>&m=<cur|all>&f=<slug>` —
  camera (zoom 2 dp, lat/lng 5 dp), active layers (`l=` sorted letters,
  **omitted only when all layers are active** so the common link stays short),
  mode (`m=` always written), open feature (`f=` omitted when no drawer).
- `history.replaceState` only — no back-button spam.
- Malformed hash / NaN camera / out-of-range zoom → ignore entirely, fall back
  to default, never throw on load. Unknown letters are skipped, valid ones
  kept; unknown `f=` still applies camera/layers. Applying state on load must
  not trigger a redundant write.
- Legacy `?feature=` keeps working (hash wins when both present and valid).

### 13.2 Interactive elevation

- For features with both an elevation array and a path: hover/scrub the
  profile drives a crosshair + `distance km · elevation m` readout + a map
  marker interpolated along the track by cumulative distance (a GeoJSON
  `elev-cursor` source + circle layer, not a DOM marker). **No camera movement
  on hover.**
- Keyboard: the chart is focusable; ←/→ step the samples with an `aria-live`
  announcement.
- Cleanup: crosshair and marker removed on `pointerleave`, feature switch, and
  drawer close — no orphaned cursor. Graceful degrade to chart-only when
  elevation exists without a path.

### 13.3 Per-item GPX export

- One-click GPX from the drawer that opens on a head unit, **with source
  attribution + the ODbL licence URL embedded in the GPX metadata**, and no
  button when the feature has no geometry (never an empty GPX). All text
  XML-escaped.
- **Shipped for routes** server-side (`GET /routes/{id}.gpx`,
  `App\Contribution\Gpx\GpxWriter` embeds the ODbL copyright block — see
  [route-domain.md](route-domain.md)). **Pending** for climbs (track) and POI
  waypoints (`<wpt>`).
- Bulk "export everything visible" is **explicitly rejected** — per-item only.

## 14. Basemap / geo-services stack (recorded direction)

Production stack choices recorded during the prototype and still steering the
app: MapLibre GL render; PMTiles-on-CDN basemap direction with OpenFreeMap as
the current keyless source; Copernicus GLO-30 / SRTM for elevation
(climb-provenance side owned by
[edit-items/B-climbs.md](edit-items/B-climbs.md)); **Photon** for type-ahead
place search with Nominatim only for non-interactive lookups (§7.2); OSRM for
the add-climb draw preview; optional Valhalla/GraphHopper for climb snapping.
The coverage tiles themselves are specified in
[coverage-provider.md](coverage-provider.md).

## 15. Open questions

- **"Place search unavailable" row:** the original town-search design called for a muted
  inline row when Photon degrades; the shipped code degrades fully silently
  (`runPhoton()` swallows all errors with no UI trace). Decide whether the row
  is still wanted or the silent behaviour is the contract.
- **Mapillary "No street-level imagery here."** (`map.mly_none`) is wired in
  `CC_I18N` but only reachable via the legacy Graph-API fallback, which no
  click path calls — dead-string cleanup vs re-wiring is undecided.
- **`?feature=` matches by exact name**, not id — two same-named features
  resolve to the last one scanned. Acceptable today (names are effectively
  unique in the served set); the coverage plan's ref-based deep links will
  supersede it.
- **Region boundary + default bounds are Wallonia-hardcoded** (`map.js`
  `addRegionBoundary('Wallonia')`, the boot `bounds`, and the Photon bbox) —
  worldwide readiness for the map shell has no owner yet beyond the coverage
  plan's Belgium-first staging.
- The **city blurbs in `CITIES`** are hand-authored English constants
  (untranslated, unsourced); production intent was Wikidata/Wikipedia-derived
  or community notes — unowned.
