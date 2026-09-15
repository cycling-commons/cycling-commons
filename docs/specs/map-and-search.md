<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Map & Search

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

This is the map/search presentation contract that
[osm-data-architecture.md](osm-data-architecture.md) §8 delegates to: how the
full Commons is surfaced on `/map` — the icon rail and its drawer, the
tooltip/drawer selection
model, layer rendering, search, deep links, ride-check, street-level imagery —
and which of those contracts are approved but not yet built. It consumes the
data model unchanged; it never defines data semantics.

**Ownership boundaries.** Schema/identity/serving of catalog data:
[catalog-data-model.md](catalog-data-model.md). Per-type edit contracts and the
lifecycle/votability funnel: [edit-items/README.md](edit-items/README.md).
Submission/moderation/confirmation machinery:
[moderation-and-contribution.md](moderation-and-contribution.md). The R route
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

1. **The map is the showcase; the Layers panel is the catalog index.** Its
   controls expose the full lettered taxonomy as individually toggleable layers
   with live counts. Search, the view mode (Best of / Confirmed / Everything), and the filter chips
   *compose* to define the visible feature set — that panel is a navigable index
   of the Commons, not a settings panel.
2. **Display toggle ≠ discoverability.** The view mode governs
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
   the surface where it appears: the ride heatmap and planner are labelled
   illustrative/faked (§11), route surface breakdowns carry an
   "estimate · N% of route mapped" method note, best-of is derived (never
   hand-set — funnel in
   [edit-items/README.md](edit-items/README.md#item-lifecycle-and-votability)).
5. **Never break on missing externals.** Every third-party dependency (basemap,
   Photon, Mapillary) degrades silently to a working map. The list is shorter
   than it was: the region boundary and the editor's road snapping are served
   by us now (`RegionBoundaryProvider`, `RouteSnapper`), so neither is an
   external at all. Same for empty catalog pools: `catalog-load.js` boots `map.js` even
   when the catalog fetch fails.

## 2. Map shell and boot contract

- **Stack:** MapLibre GL JS **6.8.0**, vendored same-origin and undigested at
  `web/public/lib/maplibre-gl/6.8.0/` (three `.mjs` files plus the stylesheet;
  see [security-architecture.md §2.5](security-architecture.md) for why it sits
  outside AssetMapper and what nginx has to know about `.mjs`). OpenFreeMap
  `liberty` basemap style, attribution control
  `© OpenStreetMap contributors · ODbL`.
  Optional Esri World Imagery satellite base (hidden by default, `#baseSeg`
  Map/Satellite toggle; its terms are an open item —
  `Dated/2026-08-09-esri-imagery-terms.md`). The region boundary renders as a
  spotlight mask + dashed outline; it is decorative — failure only logs. It
  comes from **our own** `/map/region/{slug}/boundary`
  (`RegionBoundaryProvider`, simplified and cacheable), not from Nominatim:
  that fetch was both an external dependency and a usage-policy problem at
  any real traffic.
- **Getting MapLibre onto the page.** v6 ships ES modules only, so there is no
  `maplibregl` global to load: one inline `<script type="module">` in the
  template imports the library and assigns `window.maplibregl`, which every map
  module reads. That module is deferred, so `catalog-load.js` (which builds the
  map instance while evaluating) is `type="module"` too, and runs after it. The
  head preloads both halves of the library (`maplibre-gl.mjs` and the
  `maplibre-gl-shared.mjs` it imports) with `modulepreload`, because the second
  is otherwise only discovered once the first has been parsed.
- **The import map must come before every module script AND every
  `modulepreload`, and that is a hard constraint, not a preference.** A browser
  honours an import map only while no module load has been triggered yet. A
  `<script type="module">` triggers one. So does a
  `<link rel="modulepreload">`, which is why the whole preload block on this
  page sits below `importmap()` and not up in the preamble where a preload
  would normally go. Either one above it makes the map be discarded. On this page the map is the only thing that resolves the rewritten
  relative imports AssetMapper emits, so losing it means `/assets/map/i18n.js`
  and its siblings are fetched at paths that exist nowhere, 404 into
  `index.php`, and return HTML the browser refuses on MIME type: no map at all.
  Firefox does exactly this; Chromium happened to survive the same page, so a
  green browser check is not evidence. It caught us twice on 2026-09-09: once
  with the boot module above `importmap()`, and again after that was fixed, with
  two MapLibre `modulepreload` links left in the preamble. The MapLibre boot
  module and its preloads therefore both sit **below** `importmap()`, beside
  `catalog-load.js` and the rest of the preload block.
  `tests/Smoke/ImportMapOrderTest.php` pins both orderings, and the preload half
  exists because the first version of that test checked only script tags and
  passed over the second bug.
- **No WebGL2, no map.** v6 dropped WebGL1 and now **throws**
  `GPUInitializationError` from the `Map` constructor where v5 returned a map
  that silently never painted. `catalog-load.js` catches it, puts
  `map.gpu_unsupported` in the map area (`.map-gpu-error`) and does not inject
  `map.js`; the contribution wizard does the same in its own box and carries on
  without a map, so a browser with no GPU costs a rider the pin, not the
  contribution.
- **Boot sequence** (`catalog-load.js`): fetch `window.CC_CATALOG_URL`
  (`GET /map/catalog.json`, `MapController::catalog()`, public, ETag,
  `max-age 3600`) → expose the layers as the `window.CC_*` globals → apply the
  stays merge (PIVOT features tagged `src='pivot'` and appended once to the OSM
  stays pool — a deliberate merge driving the Tourisme-Wallonie attribution
  branch and a single dot-render path) → inject `map.js`
  (`window.CC_MAP_SRC`). `map.js` **execution** waits on the catalog; its
  **download** does not — the template preloads it so both requests are in
  flight together. Catalog-fetch failure still boots the map (empty pools).
  The preload and `window.CC_CATALOG_URL` are **one Twig value**
  (`catalog_url`), because a preload only counts when the URL matches the
  request exactly: while the preload named the bare path and the fetch carried
  the `?v=` tag, the browser downloaded the catalog twice (1 MB gzipped each
  time) and then warned that the preload went unused. Fixed 2026-09-09 and
  pinned by `tests/Smoke/MapPageTest.php::testMapBootsFromCatalogEndpoint`.
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
| `CC_MY_AREA` | `{lat, lng, place, radiusKm, regionIds, countryCodes}` (any field may be `null`/empty when no base location is set) plus `{url, token}` for `POST /map/my-area` — feeds `scope.js`'s `myArea` kind (§4.5, map-and-search.md §4.5 Phase 4) | `ROLE_USER` block only |
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
| `GET /map/catalog.json` | whole served catalog, letters keyed (transitional, §7.1) | public, ETag, max-age 3600, **URL-versioned** |
| `GET /map/item/{id}/history` | per-item change log for the drawer's "Recent changes"; empty list (200) for never-edited items, never 404 | public, ETag, max-age 60 |
| `GET /map/best-of?season=&bike=` | ranked verified-route ids for the Curated facet (§4.2) | public, ETag, max-age 300 |

All three are exact-path `PUBLIC_ACCESS` in `security.yaml` (the scheb
lazy-firewall caching gotcha — see
[account-and-auth.md](account-and-auth.md) §5).

**The one planned exception, and the shape it has to take**
(specified 2026-09-09, pending implementation). When a provider takes custody of
an item back, the drawer owes the rider a sentence about their own confirmation
(data-provider-hierarchy.md §6.7.3). That sentence is user bound and a shared
cache cannot hold it, so it does not join the drawer body. It is a separate
fragment, served `private, no-store`, requested only for a signed-in rider,
memoised client-side per item id, and skipped entirely for anonymous visitors,
who hold no confirmations. The drawer body stays public and cacheable.

That fragment must be the **only** route that touches the session. A drawer-body
route that reads the user in order to decide whether to render the line stops
being cacheable and the split buys nothing, which is the "Security touches
session" blocker recorded against the page-caching work. It degrades too: if the
fragment fails the drawer is still correct, only shorter.

**catalog.json is fetched through a versioned URL** (2026-08-13): /map embeds
`CC_CATALOG_URL = /map/catalog.json?v=<tag>` where the tag
(`CatalogProvider::versionTag()`) hashes the feeding tables' row counts +
latest change (`item`, `item_confirmation`, `recommended_route`) plus the
build version. The hour-long max-age is the deliberate critical-path
optimisation; the tag is what keeps it honest — without it, a rider whose
submission was just approved reloaded into the pre-approval payload and
watched their contribution "disappear" until the cache expired
(owner-reported, the Zuiderdijk approval). Row counts are in the hash because
a takedown deletes without moving any timestamp; the build version is in it
because a deploy can change what the same rows serialize to.

An **already-open map tab hot-refreshes on tab return** (2026-08-13): the
versioned URL keeps fresh page loads honest, but a moderator's loop is
approve-in-the-desk-tab → switch back to the open map, and that tab never
refetched anything. catalog-load.js keeps the boot ETag and revalidates on
`visibilitychange`/`focus` (throttled); a 304 costs headers, a change
re-assigns the CC_* globals and calls `window.__ccApplyCatalog` (registered
by map.js): `populateCatalogLayers()` re-maps climbs, routes, hazards and
the surface features from the new variables, `refreshPools()` (osm-pools.js)
swaps every pool's data, re-seeds its cluster source and drops the on-screen
markers so they are minted again from the new properties, the search index
is rebuilt, the tile dedupe filters re-apply, and `render()` runs. Until
2026-09-08 only the surface re-mapped and every other letter waited for a
reload: a stand approved on the desk kept its old icon and its old drawer in
the open tab (owner: "it should invalidate the old drawer and icon cache").

## 4. The shell: icon rail and drawer

Replaced the always-open 340px filter rail on 2026-08-20 (owner-approved from
a clickable prototype). Everything the rail used to hold is still here and
still driven by the same modules; what changed is that the map gets the room
by default and a rider asks for one section at a time.

### 4.0 Rail, drawer, corner

- **The rail** is 48px on the left edge, at every screen width. Top: the brand
  mark. Then one icon per rider section, in this order: **Search & region**
  (magnifier), **Layers & filters** (layer stack), **Ride tools** (bicycle).
  After a spring and a hairline: the **theme** sun/moon (§4.6), a **≡** button
  whose flyout carries the site nav and the language menu, and the **account
  avatar**, which keeps its unread bulb visible on the rail rather than hiding
  it inside the flyout. The active icon is trail orange with a 3px orange notch
  on the rail's edge; tooltips are mono uppercase, to the right of the button.
- **The drawer** is 320px, between the rail and the map, and shows **one**
  section at a time. The same icon again, the ✕ in its header, or Escape closes
  it. It ships **closed**: the map is the hero. `shell.js` owns all of this and
  exposes no global; no other module opens or closes a panel. Escape defers to
  anything nearer that owns the key - the search dropdown inside the drawer,
  and the feature drawer, lightbox and climb profile over the map. Because the
  drawer is a flex sibling of the canvas, `map.resize()` runs after the 180ms
  width transition (plus a timer, since `prefers-reduced-motion` fires no
  `transitionend`).
- **Three sections, and why they are three.**
  - **Search & region** is one panel because a region is where you search
    (owner: "search is in a region so those 2 must be combined"). The heading
    names the active scope, the results follow, the widen ladder is the last
    results row (§4.5), and the region grid and My area sit below them.
  - **Layers & filters** is one panel because view mode is a filter too (owner:
    "people will not understand why they are missing data"). Order: view mode,
    the layer list, MAP OVERLAYS, then FILTERS under mono sub-headers. FILTERS
    leads with the **Best-of season and bike** facets, which used to sit in
    the View mode band: they narrow harder than any chip under them, and up
    there nobody found them - the map subtitle said "Best of · Summer · Road"
    while the only bike control a rider could see was the profile chip
    (2026-08-20). They stay hidden outside Best of, the only mode they change
    anything in. The heatmap's own season chips stay LAST in the block, as far
    from them as it allows: two controls called "season" must never sit side by
    side.

    Both are **multi-select chip rows**, not single-value dropdowns (owner
    2026-08-20: "no multiple select option as in my profile"). They open on
    the season we are in today and on EVERY bike the rider's profile carries,
    and both are `.f-optin`: a ticked chip narrows, so their widest state is
    EMPTY and the server reads an empty list as the whole vocabulary
    (route-domain.md §8.1). Marking them `.f-match` would make the pill's Show
    all tick every season and every bike, the opposite of widest, which is why
    the class is pinned by a test. Show all is also the one reset in this block
    that must reach the SERVER, because the ranking is computed there.

    **A filter only appears when its layer can be filtered** (owner 2026-08-20:
    "still find the filter too crowded"). Climb surface, traffic and effort
    describe climbs; stay accessibility describes stays. Each pair of header
    and chips lives in a `.fsub[data-layer]` wrapper, and
    `panels.js syncFilterGroups()` hides it whenever that layer is switched off
    or holds nothing in the current scope - the rule the legend and the overlay
    rows already follow. Gated on `total`, NEVER on `shown`: `shown` already
    has the chips applied, so a rider who filtered a layer down to nothing
    would watch the filter that did it disappear, with no way back.
- **The panels label, they do not lecture** (owner 2026-08-20: "no need for
  all those explanations. people know how filters work"). Sub-headers name the
  facet and stop. The one place that still explains itself is view mode, whose
  three words are product vocabulary rather than a filter mechanic.
  - **Ride tools** holds ride check, scout, contribute and the places count.
    The low-zoom/curator-scope hint stays ON the map instead, beside the zoom
    controls - it has to be readable while a rider is zooming, and the drawer
    is closed by default. Beside, not above: stacked, it covered the z-level
    badge that shares MapLibre's bottom-left corner.
- **An overlay row says whether it is on.** The two MAP OVERLAYS rows carry
  the same right-hand state column every layer row uses for its `shown/total`.
  Without it they were a dimmed swatch beside a dimmed name with nothing on the
  right, which reads as disabled rather than off (owner-reported 2026-08-20):
  in that list the number is what says a row is alive.
- **Filter transparency, always on the map.** When a chip filter narrows the
  catalog, a pill at the bottom of the map says so and offers a one-tap **Show
  all**, and the Layers icon wears an orange dot. Both are visible whether or
  not the drawer is open, which is the point: a rider notices data is missing
  while looking at the MAP. The count is a real tally from the render pass
  (`render.js` `hiddenByFilters()`), incremented at the moment the chips - and
  only the chips - exclude a feature. Mode, scope and switched-off layers hide
  things too and are labelled loudly elsewhere; counting them here would make
  the pill noise on any scoped map. Where the narrowing happens inside the
  coverage tiles there is nothing to walk, so the pill says the map is narrowed
  rather than claiming a zero it has not verified. **Show all** reads the
  markup's own `.f-match` / `.f-optin` declaration, so a filter group added
  later resets without anybody editing the reset.
- **The bottom-left corner is the map's own controls** (`initMapControls`,
  map-init.js), bottom to top: zoom in and out, **Locate me**, and the live zoom
  badge. The same controls at every screen width. The corner sits above the
  attribution (`.maplibregl-ctrl-bottom-left{z-index:3}`, map.css): on a narrow
  map the open attribution spans the whole bottom edge and used to cover the
  buttons until the rider folded it.
- **Locate me** (owner decision 2026-09-15) is MapLibre's `GeolocateControl`
  with `trackUserLocation:false`. One tap asks the browser for permission the
  first time, reads the position once, flies there (zoom capped at 15) and
  leaves a blue dot with its accuracy circle; there is no continuous tracking.
  **The position never leaves the browser**: it goes from
  `navigator.geolocation` to the camera and the dot, and no request carries it
  (privacy-notice.md section 2 records the claim and the copy that makes it).
  The site allows geolocation for its own origin only
  (`Permissions-Policy: geolocation=(self)`, security-architecture.md §2.1).
  - **Where the dot lands.** With no feature drawer open, the middle of the
    map. With one open (and not folded), the same free part a clicked spot uses
    (`pinOffset()`, §6.1): left of the desktop drawer, or the middle of the half
    above the phone sheet. `locateOffset()` in util.js decides; the control
    reads it through a `padding` getter on `fitBoundsOptions`, because MapLibre
    copies those options at the moment it moves the camera. Padding, not
    `offset`: MapLibre's `fitBounds` applies an offset twice (once computing the
    camera, again in the `flyTo` it hands that camera to) and padding once
    (`offsetAsPadding()`).
  - **A lookup that does not work is said in words**, as a map toast
    (`locateErrorMessage()`): a refused permission (browser code 1, including a
    site blocked in settings) says the site is blocked and to allow it in the
    browser settings, then reload (`map.locate_denied`); an unavailable
    position or a timeout says it could not be found (`map.locate_failed`).
    After a refusal MapLibre greys the button with the title "Location not
    available".
  - **Labels** come through the map's `locale` option (`CC_I18N.mapUi`, set in
    catalog-load.js), under MapLibre's own keys:
    `GeolocateControl.FindMyLocation` (`map.locate_me`, "Show my location") and
    `GeolocateControl.LocationNotAvailable` (`map.locate_unavailable`).
- **The top-right corner is two glass icon buttons:** base map (a flyout with
  Map / Satellite; the flyout markup is a **sibling** of the button, never a
  child, because a nested `<button>` is un-nested by the browser) and
  street-level. With no Esri key the whole picker hides, not just the segment,
  so its button can never open an empty menu - and that decision reads the KEY
  (`satelliteConfigured()`, map-init.js), never `map.getLayer('satellite')`:
  the layer is added inside `map.on('load')` and the chrome is built before
  that fires, so the layer question always answered "no" and the picker hid
  itself even where satellite worked (2026-08-20). The Surfaces and Cycle-routes
  toggles left this corner: they are layers, and they live with the layers.
- **Zebra bands.** Every `.grp` in a panel is a full-bleed band, every second
  one on `rgb(var(--chrome-fg) / .05)`, with a 1px `rgb(var(--chrome-fg) / .14)`
  hairline between consecutive groups - so a long panel reads as stacked blocks
  rather than one column.
- **Phones (≤820px): the same behaviour, not a second layout** (owner
  2026-08-20). The rail stays where it is; the drawer stops taking layout room
  and slides OVER the map, capped at `min(320px, 85vw)` so the map is never
  fully covered. The street-level dock hides the drawer while it is up. The
  earlier phone re-skin - rail folded into a top bar, filters folded into a
  swipe-up bottom sheet - is deleted: two layouts meant two sets of rules to
  keep true, and the sheet was the half nobody could find. The FEATURE drawer's
  own bottom sheet (§6.6) is untouched.
- **Both themes by construction.** Every rule in the shell reads a `--chrome-*`
  token (§4.6); a test pins that the section hardcodes no brand literal and
  that chrome text never drops below alpha `.65`.
- **Element ids are load-bearing.** `#mode`, `#layers`, `#bestFacets`,
  `#scopeChips`, `#searchTitle`, `#search`, `#searchRes`, `#count`,
  `#zoomHint`, `#baseSeg`, `#ovStreet`, `#ovSurface`, `#ovRoutes` and the chip
  group ids are bound by `panels.js`, `scope-ui.js`, `scope-header.js`,
  `search-ui.js`, `mapillary.js` and `render.js`. The shell refactor MOVED
  them; renaming one breaks its module silently.
  `tests/js/rail-shell.test.cjs` pins each panel's ownership and that no id is
  rendered twice, which is the failure a markup move actually produces.
- **Not built:** there is no GeoJSON export control. The design sketch asked
  for one; nothing in the app exports GeoJSON, so it would have been a button
  that does nothing. Owner call 2026-08-20: leave it out until the export
  itself exists (the ODbL credit has to travel inside the file).

### 4.1 Layer toggles

- The Layers panel lists the catalog layers (localized label + icon + colour), each
  individually toggleable, with a **`shown/total`** count that
  reflects the current mode and filters (`layerCounts()`). **Both parts are
  scope-aware:** `shown` applies mode/filters/scope, and `total` is scoped too —
  curated features gate on `inScope()` and coverage uses the server's per-scope
  count — so a region with no data for a letter reads `0/0`, never the global
  total (a region's list never shows another region's counts). A
  select-all/deselect-all toggle sits above the list. **Default: all catalog
  layers on at load.** Layer labels come from the `item_type.*.label`
  translation keys via `CC_I18N.layers`, so the list can never drift from the
  improve form / drawer wording.
- **The layer list is grouped, and the grouping is the split riders already know**
  (2026-08-02): *Practical · full coverage* (the utilities), then *Emotional ·
  voted by riders* (the votable layers), then, for curators only, *Moderation*
  (the pending-review overlay, which is not a category). The two headings use
  the same vocabulary as the contribute hub's Practical/Emotional sections, so
  one split describes the catalogue everywhere. Membership comes from
  `layer.votable`, which mirrors `ItemType::isVotable()`. It is **not** `exp`:
  `exp` decides what Curated mode hides, and the two disagree on both road
  surface (utility, but filters to curated) and R (votable, but special-cased
  by key). Within a group, `CATALOG`'s own order carries through; that array's
  order stays the **draw** order (`render.js` walks it, so later entries stack
  above earlier ones) and must not be reshuffled for display reasons.
- **No catalog letter appears anywhere a rider reads a category** (2026-08-02
  owner decision). Letters are storage identifiers — `?type=`, coverage tiles,
  `coverage_poi.letter`, the public API — and they stay there. They are gone
  from the layer rows, the drawer type chip, search-result badges, the
  ride-check group badges, the contribute hub cards and the improve/propose
  eyebrows; each of those shows the category's **icon** on its colour swatch
  instead. The change was forced by the grouping: sorting the list A–Z put
  Public toilets (then letter M) last (far from Water & food, the row it belongs beside)
  and Climbs (then letter B) above every utility, and once the list is ordered for humans
  the letters read as a broken sequence (at the time A, C, M, D…) — which is exactly what
  an identifier looks like when it is used as an ordinal.
- **A pin's position is `pinPoint()` (util.js), not `featurePoint()`.** They
  answer different questions and disagree on climbs: the stored anchor is the
  summit, the pin is drawn at the foot (`route[0]`). The rule lived only inside
  render.js, so the curator's pending-review pin — built from the submission's
  copy of the item anchor — landed on Côte de la Redoute's summit, 9 m from an
  unrelated monument, and "Review on the map" highlighted the monument while
  the climb's real pin sat unmarked at the other end of the line
  (2026-08-03). Both readers now share the helper. A `new` submission keeps its
  own point: there is no item yet, and that point is the only record of where
  the place is.
- **Layer stacking is decided in exactly one place**, `liftInfoLayersAboveRoutes()`
  (render.js). It moves each named layer to the top in turn, so the call order
  *is* the z-order, bottom to top: **route lines → road surfaces → climb lines →
  Mapillary**. Climbs sit above surfaces (2026-08-03, owner): they were lifted
  first and so ended up underneath, and an 8 px teal surface line swallowed the
  climb it describes — on La Redoute only a sliver of the gradient line showed
  at the edges. A climb is a named thing a rider came to look at; a surface
  segment is a property of the road beneath it, and the narrower climb line
  still leaves the surface colour visible on both sides. **Do not fix stacking
  at draw time**: this function runs at the end of every render *and* after
  every selection move, so a `moveLayer` in `drawClimbLine` is silently
  overridden a moment later (tried and reverted the same day).
- **The ride heatmap (no letter) is deliberately NOT a catalog entry:** the generated layer
  list holds the lettered types only; the heatmap is a sub-header inside the FILTERS block with its own
  On/Off toggle and season chips (§11).
- Curators additionally receive a **⚑ Pending review** layer
  (`CC_PENDING`-driven, red pins) — moderation behaviour is owned by
  [moderation-and-contribution.md](moderation-and-contribution.md).

### 4.2 View mode: Best of · Confirmed · Everything

- `mode ∈ {curated, confirmed, all}` (button labels "Best of" / "Confirmed" /
  "Everything"). The wire token for the first stays `curated` — it has been the
  DOM contract since the HTML demo, and renaming a live contract to match a
  label is the tail wagging the dog.
- **Three rungs of human endorsement** (owner decision 2026-08-12). The map had
  two settings and a gap between them: the editor's best-of, or every import
  nobody has checked. Confirmed is the question most riders actually have —
  *show me what somebody has vouched for*.

  | Mode | Draws |
  |---|---|
  | Best of | the curated picks (`f.cur`) on experiential layers; utilities as before |
  | Confirmed | anything carrying `f.v` (a rider confirmation, or a curator's verification) **plus** `f.cur` — a true superset of Best of |
  | Everything | the whole catalog, including reference coverage |

  `f.v` is the server's own "somebody vouched" flag (`CatalogProvider`), the same
  one the drawer reads for its "?" badge, so a place cannot be confirmed in one
  and questioned in the other. **Reference coverage stays out of Confirmed**: it
  is OpenStreetMap as it stands, which by definition nobody here has vouched for,
  and a mode meaning "someone checked this" cannot carry the one layer where
  nobody has. Best of keeps showing utility coverage dimmed — finding water was
  never an editorial judgement.
- **A lifted mode is never the rider's mode.** A deep link (§8) and a ride-check
  row (§9) may lift the mode to show one place, always `persist:false`. A lift
  from a loaded ride is also undone: Clear puts back the mode from before the
  ride's first lift, unless the rider picked a mode themselves while the ride was
  loaded, in which case their pick stays (`createRideModeMemo()`,
  ride-places.js).
- **The subtitle under the scope name follows the mode** (`updateSubtitle` in
  `panels.js`): Best of reads "Best of · season · bikes", Confirmed reads
  "Confirmed · places somebody checked" (`map.sub_confirmed`), Everything reads
  "Everything · full catalog". A transient lift (§8, §9) updates it too.
- **It is called "Best of", not "Curated best-of"** (owner 2026-08-12): Confirmed
  is curated too — a curator verifying a place *is* curation — so putting the
  word on one rung claimed a difference that is not there.
- The first rung's axis is **votability**, not verification — see the funnel in
  [edit-items/README.md](edit-items/README.md#item-lifecycle-and-votability).
- **Experiential layers** (`layer.exp`: climbs, stays, scenic, history) filter
  to `f.cur` in Curated. The curated pool pins (`osm-pools.js` `poolVisible`,
  our own items clustered per layer) ask the same `modeShows()` as every other
  pin, so a verified place that nobody picked draws in Confirmed, not in Best
  of (owner 2026-09-15, Veteranenmonument); utility layers always draw their confirmed pins, and
  their unverified OSM dots draw only in Everything (this gate changes under
  §12). **R routes** honour a server-computed best-of: Curated mode fetches
  `GET /map/best-of` for the active *(season, bike)* facet (`#boSeason` /
  `#boBike` selects, shown only in Curated), flags the returned ids `cur`, and
  filters to them. Membership only — the server's rank order is latent until a
  ranked-list UI consumes it. Facet switches are race-guarded (`_bestOfReq`
  token); on fetch failure Curated shows no picks rather than a stale set.
- A rider with **exactly one** saved bike preselects the Bike facet; multi-bike
  riders keep the neutral `all` (the facet is single-valued).
**NAMING: the mode is called "Best of" to riders, `curated` to the code.**
Riders never see the word *Curated* as a mode name in any locale: it is
**Best of** (en), **Best-of** (fr/nl/de), **Lo mejor** (es), from
`map.curated` / `moderate_regions.mode_curated`. The identifier stays
`curated` everywhere it is an identifier: the `default_map_mode` column value,
the `?mode=` parameter, the `data-m="curated"` attribute, the translation KEYS
and the readiness settings (`map.curated_default_*`). This spec says "Curated
mode" for the code path; that is the identifier, not the label.

Do not "fix" one to match the other in either direction, and beware two words
that look like the same rename and are not:
- **`regions.status_curated`, "Curated"** is a region's **stewardship**
  status, meaning *a curator looks after this region*. Nothing to do with the
  map mode.
- **"curated picks"** is content a curator marked, which is what the readiness
  gate counts. Also not the mode.

A half-finished rename was found on 2026-08-14: the map said Best of while the
Regions desk, the region-status legend, the admin settings labels and the
add-climb journey diagram (gone with the wizard on 2026-08-25) still said
Curated / Sélection / Selectie / Auswahl / Curado, so one product had two names
for one thing in five languages. Fixed across all five.

- **Which mode the map OPENS in.** The global default is
  **Everything**, not Curated: Curated hides every non-curated experiential item,
  so on an under-curated region it showed a near-empty map behind a panel counting
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
  load; a later scope change never re-resolves. `MapViewMode::Confirmed` joins
  the stored preference (`users.default_map_mode`) and the toggle's persistence
  endpoint; no migration — the column stores the enum's string value.
- **Confirmed will look thin until riders fill it, and that is honest.**
  Measured in NL on 2026-08-12: 203 catalog features, 13 with any human
  endorsement, everything else reference coverage. Unlike the Curated trap
  above, an empty Confirmed is a true statement about the data rather than a
  filter hiding data that exists — and it is the one screen that gives a rider a
  reason to press Confirm. If it ever needs widening, the open alternative is
  "in the Commons" (our catalog items, confirmed or not), which shows something
  on day one but stops meaning *someone checked this*.
- **What `/regions` says about a region — two axes, never one.** The public
  directory answers two questions side by side, in two legend columns:

  - **How much it holds** — `onboarded` → `growing` (anything verified) →
    `established` (`curated_default`). The top rung belongs on this ladder
    because riders climb it: the desk only lets a moderator set the flag once
    the region passes the readiness count above, so it is earned rather than
    declared.
  - **Who looks after it** — `curated` with its own curator, `countrywide`
    when only a country-scoped moderator covers it, else nothing.

  These were a single ladder derived from `curated_default`, and the legend
  then glossed it as "a curator maintains this region" — which that flag does
  not mean. The conflation makes true things unsayable in both directions: a
  busy region with nobody looking after it, and a curated region with nothing
  in it yet. Wallonia is the worked example — growing **and** curated.

  Country-scoped cover is reported as cover, not as nothing: one moderator for
  NL really does look after all twelve provinces. It is reported as *distinct*
  from local cover, because a region can have its own curators alongside the
  country's and somebody who rides there sees what a country-wide view never
  will. Same three-state model as the profile page's curating invitation
  ([account-and-auth.md](account-and-auth.md) §9).

  **On a region's own page the people come first, then their reach.** "Who
  looks after it" is a row of chips, not a sentence: a curator's name is a
  thing you click, so it is a chip linking to `/riders/{uuid}`, and each name
  carries its own **reach** chip beside it - *This region* or *Country-wide* -
  because reach is a property of the person, not of the region. A curator
  without a public profile is still shown, as an unlinked *A curator*: that
  somebody looks after this region is not theirs to withhold, only their name
  is. The sentence that survives is the one about what is **missing**, and it
  is shown only for a region short of its own curator; country-wide cover says
  the vacancy out loud ("a curator is covering the whole country, we are
  actively looking for regional curators"), because that state is where the
  page most needs a volunteer.

  **The way in points at the region, not merely at its country.** The CTA
  carries `?region=<slug>` to `/join/{cc}`, which preselects that row in the
  application's scope picker and titles the page *Help curate <region>*. The
  picker had always been there and nothing ever pointed at a row in it, so a
  rider clicking "do you want to join?" under North Holland's name arrived at a
  country page headed "Nobody is curating Netherlands yet" - discouraging,
  usually untrue (the country may have a curator; it is the *region* that is
  short of one), and leaving them to find their region again in a list of
  twelve. The slug is validated against the operational regions already fetched
  for that country, so a foreign or non-operational id falls back to the
  whole-country default rather than being trusted.

  **On a region's own page the three states are sentences, not glossary
  entries.** Since 2026-09-08 `/regions` has two views, chips Globe then List,
  and a screen 900px or wider opens on the globe, a phone on the list (owner:
  "should open on the globe page if not mobile"),
  (owner: "a map version where you select the country on a world map"; the
  flat map was a chip for an hour and was removed: "remove the map option").
  The globe: `assets/pages/country-globe.js` (shared with `/coverage`'s globe view,
  [coverage-provider.md §9.1](coverage-provider.md)) loads the vendored MapLibre
  only when asked, closer on /regions (`data-zoom="2.8"`, so a small country is
  a target a pointer can hit), and draws one shape per country from `GET /regions/outlines.json`
  (`PageController::regionOutlines()`: PostGIS unions each country's stored
  `region.outline` rings, about a second for all nineteen, kept a day in the
  app cache keyed on the region table's last change; on the public cache list
  with an ETag; the full geometries took 36 seconds for five). Countries, not
  regions (owner: "just the countries"); a click opens that country's tab in
  the list below. Since the night of 2026-09-08 the basemap is made in the
  shared module `assets/pages/country-globe.js`, not fetched: one colour for
  the land, the sea, admin-2 borders, no names, no relief (owner: "more
  simple, rest of the world one colour"), from the same planet vector tiles.
  Raising each country by its item count was tried the same night and
  reverted within the hour (owner: "raised effect is too much, revert to
  normal flatland"; the blocks also hid the pointer's target). The coverage
  globe shares the basemap. MapLibre's globe projection, framed close on Europe, or on
  a signed-in rider's base when they have one (owner: "turn the globe already
  to their home base"; the page is private for them, so nothing personal is
  cached); no zoom (no buttons, wheel or pinch; owner: "without a zoom
  option"); on the page's own paper with no frame; a spinner from the click
  until the first idle frame, so the flat map never shows first.
  The list: one grid per continent, three columns from 900px, and a tab strip
  (owner: "regions must open full width as some sort of tab"): the count chip
  under a country is the tab, its regions are one panel across the full row,
  placed by the script on the grid row under that country's, one country open
  at a time. The panel says whose it is twice, a notch under the open chip and
  the country's flag and name as its first line, and fades and slides in and
  out (none under prefers-reduced-motion). Inside, a grid of equal cards, each
  two lines, the name and the numbers on the first and the two tags on the
  second (owner: "a bit messy with all different sizes and alignments"; a
  four-column card squeezed the name out first); the numbers
  are one count, verified items and routes together as "N community items",
  and the area (owner: "just count different things into X community
  items"). With
  scripting off every panel shows in place. The key (the two-axis legend,
  maturity and stewardship) sits under the list since 2026-09-09, not above
  the globe (owner: "the explanations must go to the bottom"). The curator
  application link on
  each country row is an icon, a person with a plus, quiet grey and orange on
  hover, named by title and aria-label (owner: the text was "getting too
  much attention" nineteen times over). `/regions` is a directory and wants fragments a reader scans down
  a column; `/regions/{slug}` is about one place and wants a line that answers
  the question it is under. So the page has its own `regions.steward_line_*`
  copy rather than reusing the legend's `status_*_desc`, and the state itself
  is a **chip** ahead of it (`COUNTRY-WIDE`), not the sentence's first clause
  — as a clause it read like a definition of a term the reader had not been
  given. The `countrywide` line says the vacancy out loud ("a curator is
  covering the whole country, we are actively looking for local curators"),
  because that state is the one where the page most needs a volunteer.
  Curators are **named** where their profile is public
  (`RegionDirectoryProvider::curators`), behind a `Looked after by` label: a
  bare name trailing the sentence read as part of it. Every state short of
  `curated` ends in the same invitation, linking to `join_country`.

  **An encyclopedic lead under the hero (built 2026-08-16, owner idea
  2026-08-14).** A region page with three verified items has little to say;
  a short Wikipedia lead gives it context. The licence weigh decided the
  shape: Wikidata descriptions were rejected (CC0 but one terse line),
  **images are skipped deliberately** (the silhouette already fills that
  role, and summary thumbnails carry per-file licences nothing verifies),
  and the text is the local Wikipedia's REST-summary `extract`, VERBATIM -
  an unedited excerpt keeps CC BY-SA 4.0 reuse simple: the page renders the
  attribution (article link + licence link) from the SAME stored entry as
  the text, and `/credits` carries the site-wide Wikipedia row.
  **Build time, never runtime**: `tools/wikimedia/region_context.py` matches
  regions to Wikidata by ISO 3166-2 (`P300`), follows sitelinks per locale,
  and writes the reviewable `tools/wikimedia/out/region-context.json`;
  `app:regions:import-context` loads it into `region.context` (JSONB,
  `Version20260816020000`) and refuses any extract arriving without its
  citation. Render falls back locale -> en -> section absent.
  `RegionDirectoryProvider::region()` reads the column outside `BASE_SELECT`
  so the directory list never pays for text blobs. Pinned by
  `ImportRegionContextCommandTest` and `RegionsPagesTest`.

  **A curator can override that lead (built 2026-08-16, owner same day).** The
  harvest is a starting point, not the last word: a curator who knows the
  region should be able to replace it, or fill a language Wikipedia has no
  article in. The override lives in its OWN column, `region.context_curated`
  (JSONB, `Version20260816120000`), NOT inside `region.context` - the importer
  replaces `context` wholesale on every run, so an override kept there would
  live exactly until the next harvest. A second column is the same "store only
  our additions beside the upstream copy" shape the catalog uses for OSM, and
  it survives `app:regions:import-context` by construction rather than by a
  guard somebody has to remember (the `change_history` shield in `ItemUpsert`
  is the precedent it deliberately does not need).

  Shape per locale: `{text, derived, userId, at}`. **`derived` is the licence
  question and the reason this is not one text column.** A Wikipedia extract is
  CC BY-SA 4.0, so what the curator did to it decides what the page must say:
  an ADAPTED text keeps the citation (the licence follows a derivative) and
  the page adds `region.about_source_adapted`, which states the text was
  changed; an ORIGINAL text carries no Wikipedia credit at all and renders
  `region.about_source_curator` instead. Crediting a source for words that did
  not come from it is the worse of the two errors, so a `derived` claim is
  honoured only where there IS an article to adapt - the reader's locale, or
  English, since translating the English lead is itself a derivative work.
  Where neither exists the claim is dropped rather than pointed at nothing.

  `RegionLead::resolve()` owns the whole decision, and its order is not the
  obvious one: a curator lead in the READER's locale wins, but a Wikipedia
  article in the reader's locale beats a curator lead written in a language
  they may not read; English is the last resort on both sides in the same
  pairing. Emptying every box removes the override entirely (the column goes
  back to NULL and the harvest returns by itself), so undoing needs no
  re-import. Edited on its own page, `/moderate/regions/{slug}/about`, linked
  from each region card on the Regions desk and gated by moderator areas,
  re-checked on the POST like every other moderation write; the page quotes
  the harvested text per language so the curator judges against the real
  article. Leads are capped at 1,200 characters and an overlong one is
  refused, not truncated. Pinned by `RegionAboutTextTest`.

  **The hero opens with the same three figures as `/coverage`**, in the same
  order and under the same labels: reference items on file, verified items,
  routes. A region page that opened with only the last two made a region
  holding thousands of reference items look like it held three, and asked a
  reader moving between the two pages to learn the vocabulary twice. The total
  is summed from the per-letter counts the by-kind block already fetches, not
  queried again, and is zero when the pipeline's `coverage_poi` is absent.

  **"What riders find here" reports two numbers per kind, never their sum.**
  `N in the Commons` is what somebody has stood at and a curator approved;
  `N on the map` is what OpenStreetMap knows is there and the coverage layer
  draws underneath. Showing only the first told a reader that Wallonia holds
  2 water points where the map draws 1,650. They are not added together:
  *checked* and *known about* is the distinction the whole Best of / Confirmed
  / Everything ladder rests on. The sentence defining the two terms marks them
  up as `<code>`, in the message rather than the template, because the terms
  are different words in each of the five locales; it renders through `|rich`,
  whose sanitiser allow-lists `<code>` and nothing that can carry script.

- **The hint under the switch describes the mode that is ON (2026-08-31).** It
  used to be one paragraph naming all three in sequence, sitting under a
  three-way toggle, so whichever mode a rider was in they had to find their own
  sentence inside a description of two others. The three strings ride on the
  element as `data-curated` / `data-confirmed` / `data-all`, keyed by the same
  values the buttons carry, and `panels.js` copies the matching one into the
  text: no client i18n plumbing and no second vocabulary to drift. Each sentence
  was rewritten to stand alone, because "Confirmed ADDS every place somebody has
  checked" has nothing to add to once the other two are hidden. Pinned by
  `view-mode-hint.test.cjs`.

### 4.2b Basemap labels follow the site language

The place names baked into the **basemap** — countries, states, cities, streets —
are the vector tiles', not ours, and OpenFreeMap's `liberty` style hardcodes
English on all twenty of its name layers:
`["coalesce", ["get","name_en"], ["get","name"]]`. So a German reader saw
*LOWER SAXONY* and *Cologne* on the map itself, while every label the app owns
(scope chip, header, search title) was already correct.

`localiseBasemapLabels()` (`web/assets/map/map-init.js`, called from the entry's
`map.on('load')`) rewrites those layers' `text-field` to prefer the reader's
language: `name:<loc>` → `name_<loc>` → `name` (the LOCAL name — falling back to
English would put a German reader back where they started) → `name:latin`. The
non-latin dual-script branch is preserved, with the language preference applied
to its latin half.

No tile change is needed: measured on the live source, `name:de` is present on
396 of 400 place features, `name:fr` on 387, `name:nl` on 380. Layers are
selected by *mentioning* `name`, not by matching the exact expression, so the
three road-shield layers (which read `ref`) are left alone and a future style
tweak is still caught. Guarded by a `map-smoke.js` checkpoint that asserts every
name layer asks for `name:<document lang>`.

### 4.3 Filter chips

**Freshness is gone; the other four are back** (2026-08-02, same day). The
planner and the Freshness chips were removed permanently — Freshness was never
wired to anything and `f.freshness` is produced by no server path. The other
four returned once the reason they looked broken was fixed:

| Group | Backing field | State |
|---|---|---|
| Climb surface `#sqf` | `Climbs.sq` | Live. Chips now list **all five** registry values; the fifth was missing (see below). |
| Climb traffic `#trf` | `Climbs.tr` | Live. 15/15 seeded climbs carry `tr`. |
| Climb effort `#effortf` | `Climbs.effort` | Live. 5/15 carry a value; narrowing semantics make that honest. |
| Stay accessibility `#accessf` | `WhereToSleep.accessibility` | Live, and the field is now **multi-select** — see below. |

- **`accessibility` is a multi-select** (`FieldKind::MultiSelect`, list<string>).
  A stay is routinely step-free *and* handbike-friendly, and one-of-these
  forced the rider to drop the rest — the very fact a rider who needs one of
  them is searching for. `Unknown` went with the single select: nothing ticked
  already means "not stated", and it cannot coexist with a real value.
  `attrMatch()` is array-aware and matches on **any** overlap: filtering for
  handbike-friendly returns every stay that is handbike-friendly, not only the
  ones that are *nothing else*. The coverage tile prop `acc` stays a single
  string (it is derived from OSM `wheelchair=yes` in
  `pipeline/coverage/tiles.py`), and the MapLibre `in` expression over it is
  unaffected.
- **All four now share `attrMatch()`'s narrowing semantics.** With every chip
  on nothing is hidden, including items with no value; deselecting any option
  also hides valueless items, which cannot be confirmed to match. sq/tr used to
  "always require" a matching value instead, and that was a **climb-eating
  bug**: `CatalogFormRegistry` offers five `sq` values and the chips listed
  four, so a climb edited to **"Broken / loose"** did not merely fail the
  filter — it vanished from the map with every chip lit, and so did any climb
  predating the attribute. Adding a value to the registry now means adding it
  to `ALL_SURF`/`ALL_TRAF` in `render.js` **and** to the chips in
  `map/index.html.twig`; the comment on those Sets says so.

- **A missing chip group means NO filter, never an empty selection.**
  `chipSet(id)` returns `null` when the container is absent and every reader
  treats that as pass-through. Kept even though all four groups are back on
  the panel: it is what makes removing a group from the template a safe,
  one-file edit rather than a way to empty a layer.
- The accessibility filter applies to the stays dot layer (`setFilter`), the
  clustered confirmed pins, and the legend counts alike.
- **Shown anyway: one place a ride row opened** (owner decision 2026-09-15).
  The chips are the rider's own and a ride row never changes them, but a row
  that opens a place the chips hide must not ring an empty spot. That one place
  is exempt while its drawer is up: `createShownAnyway()` (filters.js) holds at
  most one `letter:id` (`placeKey()`), and every renderer that applies a chip
  reads it: `featureVisible()` through `chipsPass()` (the preference prefilter
  on routes, the three climb facets) and the stays pool pin through
  `poolChipsPass()` (accessibility, osm-pools.js). Same drawing path as any
  other pin, no extra marker. Released when the drawer opens a different place
  or closes (`openDrawer` / `closeDrawer`), when the ride summary comes back, and
  on Clear. The exempted place is not counted in the pill's tally while it is
  drawn. Coverage tile icons need no exemption: a coverage point opened by ref
  keeps its icon through the `cov-sel` overlay, which no chip filters.
- **Road surface (A) is an OVERLAY, not a data layer (2026-08-31).** It had a
  row in Data layers *and* a Surfaces switch in Map overlays, one word apart, in
  two different groups. Worse than confusing: they were not independent. The OSM
  skin hides itself wherever a catalog item exists for that way
  (`CC_CURATED_REFS`), so any rule that dropped our line while leaving the skin
  hidden did not fall back to OSM. It left the road blank and the basemap showed
  through, which is what a rider saw as a road "going orange" after a third
  confirmation.

  One control now, the overlay switch, which drives the skin and our items
  together so the pair can never be half on (`catalog.js` `overlay: true`, and
  `catalogUtility()` excludes overlays). And our items honour **neither the
  view-mode rungs nor the region scope**, because the skin honours neither: that
  agreement is the whole fix. Two absent gates rather than a second dedupe list,
  because a list computed on the client could only name the way each segment is
  filed under and never the ways it spans, trading a vanishing road for a
  doubled one. Pinned by `surface-is-an-overlay.test.cjs`.

  **Select-all reads the ROWS, not the catalogue** (`catalogRows()`). Walking
  every layer would switch the surface items on while the overlay switch still
  read Off, which is the half-on state this change exists to make impossible,
  reached by the one control never meant to touch it; it also made the label
  lie, since an invisible row could never be "all on".

  The letter stays. `A` is still the catalog type, the API type, the
  contribution type and what the coverage page counts; only the map's control
  moved.
- **A surface segment names who filed it (2026-08-31).** The segment shape
  served in `CC_SURFACE` carried no contributor, so a road a rider had described
  showed the OSM citation and nobody's name, while every other layer credited
  its author. `CatalogProvider::surfaceSegments()` now carries `by`/`byName`/
  `byUuid` on the same terms as `mapRow()`: named only with a public profile,
  fail-closed to anonymous, never a leaked name. OSM remains the source of the
  LINE, which `srcType` and the provenance line under the name still say; it was
  never the source of the values.
- **Discipline chips (`#disc`): RETIRED (2026-08-03).** They were re-based onto
  the 7 `RidingStyle` values and preselected from the rider's saved styles, but
  they never filtered anything, because no server path tags an item with a
  riding style — the same shape as Freshness above, and given the same answer
  one day later. Being honestly labelled in the template was not enough: a
  control that toggles is a control that promises, and the promise was empty.

  Nothing that worked was lost. `RidingStyle` remains the vocabulary a rider
  saves in their profile and remains the contract these chips get rebuilt on —
  when the DATA carries style tags, not before. Removing the group was the
  one-file edit `chipSet(id) === null` was designed to make safe; the template,
  the `#disc` CSS, the `PREFS.styles` preselect in `panels.js` and the seven
  `map.disc_*` catalogue keys went with it.

- **The same call, the same day, on `/add-climb`'s "Targeted audience" chips.**
  *(History: the wizard itself was retired on 2026-08-25; climbs are added on
  `/improve`, whose `ImproveType` has no audience field either.)*
  They were worse than the map's: not only did they filter nothing, *nothing
  submitted them* — `AddClimbType` had no audience field and
  `CatalogContributionService::CLIMB_FIELDS` mapped no such key — while the
  wizard's review step listed the ticked ones back as though a curator would
  receive them. Their labels were also the last untranslated strings on that
  page. The gradient guidance they used to drive stays, as one static line: it
  is advice for whoever is describing a climb, and it already names the handbike
  ceiling the conditional version spelled out. (That static line went with
  the wizard on 2026-08-25.)

  Bringing either set back means giving items a real audience attribute in
  `CatalogFormRegistry`, which is a vocabulary decision — the add-climb chips
  mixed riding STYLES with bike TYPES, and the codebase keeps those apart
  (`RidingStyle` vs `BikeType`). That is the owner's to make.

### 4.3a The climb profile strip

The drawer's gradient bars and their caption are a placeholder for the profile
chart specified in [climb-elevation.md](climb-elevation.md): unlabelled bars of
proportional width, with no axis and no elevation silhouette. Two rules already
hold and are the reason it reads honestly in the meantime:

- **The numbers beside the bars are the item's own stated avg/max**, not values
  recomputed from the bars. Recomputing gave the same drawer two different
  answers — the attribute rows read `8.4%` while the caption read `~7%`
  (2026-08-04).
- **The bars use `step`, not `interpolate`**, so the climb line on the map has
  the same hard band edges the bars do, at `i/n` boundaries — each sample IS a
  slice of the climb, not a point on it.

### 4.4 Preference prefilter

- Saved **bike types really filter the routes (R) layer** — routes are the only
  bike-tagged layer; nothing else is preference-filtered. Applies in **both**
  modes, composing with (never replacing) the mode/best-of filters.
- **Predicate (`prefMatch()`, unknown ≠ unsuitable):** visible iff
  `!f.bikeTypes || f.bikeTypes.length === 0 || overlap(f.bikeTypes, CC_PREFS.bikes)`.
  Only a *declared* non-overlap hides a route.
- **Never silent:** an active prefilter shows the dismissable chip
  `#prefFilter` ("Routes for your bikes", `aria-pressed` reflects state). The
  off state persists in `localStorage` key **`cc-pref-filter`**
  (`on`/`off`, default `on` when preferences exist). Anonymous visitors
  (`CC_PREFS.bikes` empty): zero behaviour change, chip group stays hidden.

### 4.5 Region / My-area scope

The Search & region panel's Region group (a per-registry list of named
regions/countries plus
Everywhere) and its `scope.js` (`window.CCScope`) client model are the full
region-scoping design owned by map-and-search.md §4.5 — this subsection
covers only the `myArea` scope kind (map-and-search.md §4.5 Phase 4),
the newest rung of that same ladder.

- **`myArea` scope kind.** A rider with a base location gets a **My-area**
  button, first entry in the Region group. Unlike `region`/`country`, its
  region set is *derived* (`BaseAreaResolver`, capped at 8) rather than
  curator-authored, so it can span more than one named region — the panel
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
  device-location prompt. Anonymous region-set derivation is
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
  map-and-search.md §4.5, extended to this client-side seam).
- **Widen ladder:** myArea → the single registry-known country among the
  derived `countryCodes` (exactly one such country, else nothing wider: an
  ambiguous/border myArea has no single "wider" country); region → its
  country. **A country is the top** (owner, 2026-09-06). Everywhere left
  the ladder, the rail and the deep links: drawing every item on Earth made
  the browser sluggish, and the one unscoped server call behind it took
  26.6 s (coverage-provider.md §11). `nextWider()` answers null at the top
  and `canWiden()` follows it.
- **Everywhere is a search reach, not a scope** (owner, 2026-09-06: "only
  everywhere in search"). The search box's last row reads "Search in {next
  rung} instead" while there is a rung, and "Search everywhere" at the top.
  That row widens THIS search only (`search-ui.js` `_worldwide`): the item
  index stops filtering on `inScope()`, the coverage lookup and Photon go
  unscoped, and the scope itself does not move. Picking a hit found that way
  first sets the scope to the hit's country (`CCScope.countryAt()`, the
  registry's region under the point), then opens it. Closing the search
  drops the reach. `CCScope.setEverywhere()` and the `everywhere` kind stay
  in the model for that use; `deserialize('everywhere')` returns null, so an
  old `?scope=everywhere` link or stored token falls through to the default.
- **Deep links follow their target to its country.** `widenForDeepLink(ll)`
  takes the target's [lat, lng], finds the registry region under it and sets
  that country transiently (`persist:false`); a target outside every
  onboarded region leaves the scope alone. Rides crossing a border keep every
  crossed region in one region set (`ride-scope.js`), never Everywhere.
- **Cold-start chip:** a rider/anonymous visitor with no base location sees a
  dismissable "Set my area" chip driven from the current map centre
  (`map.set_my_area`); dismissal persists in localStorage
  (`cc-area-prompt-dismissed`). Setting it calls the myArea endpoint (logged
  in) or writes the anonymous circle (logged out), then activates the
  `myArea` scope immediately.
- **Pan-away nudge:** one chip (`.cc-area-nudge`, `scope-ui.js`
  `initAreaNudge()`), two arms, because the rider is asking the same question
  ("why is there nothing here?") from two kinds of scope. It never auto-widens;
  the rider taps. A dismissal lasts **until the scope changes**, which re-arms
  it (a dismissal answers "not for THIS scope", and a scope the rider never saw
  must not inherit it).
  - **myArea:** panning the map centre past **1.5× the radius** from the
    circle's centre surfaces "Outside your area" (`map.outside_area`) with a
    widen action to the **next** rung (`CCScope.widen()`).
  - **Named scope (region/country):** when the viewport bbox stops
    **intersecting** `CCScope.bbox()` at all, the chip reads
    `map.scope_miss` ("Only showing {area}") with a one-tap
    `map.scope_miss_go` ("Show {area}", filled with the label of the country
    under the map centre, `CCScope.countryAt()`) that calls
    `CCScope.setCountry()`; over open sea, or already in that country, the
    chip stays hidden (2026-09-06; it used to offer Everywhere).
    **The message names the filter, not the place** (owner 2026-08-15). It
    first read "Nothing here in Free State", which is a claim about the
    *region* and a false one: the map is blank because the scope is drawing one
    region, not because the region the rider is looking at holds nothing. Say
    what the map is doing; that is both true and the thing the rider can act
    on.
    Intersection, **not** "is the centre outside": if the two boxes do not
    overlap then nothing in scope can be on screen, which is the condition the
    chip is answering; a centre test would fire with half the scope still
    visible and stay silent in a bbox corner with no data near it. Everywhere cannot miss, so it is excluded; myArea has
    its own arm above. Evaluated on `moveend` **and** once on `idle`, because a
    deep link can land outside the saved scope with no move ever happening.
    Antimeridian: the comparison is `CCScope.bboxOverlaps()`, never an inline
    test (§4.5a).

  *Why it exists:* coverage and catalog layers are scope-filtered, correctly and
  deliberately, so panning to South Africa under a Netherlands scope drew an
  empty map reading `0 places shown` and nothing naming the cause.
  It read as broken data and cost a real dig from the inside (2026-08-14).
- **Out-of-scope town opens transiently widen:**
  town search is scope-exempt (a place is an explicit location choice), so
  opening a town whose coordinates fall **outside the current scope's bbox**
  transiently moves the scope to the town's country via the deep-link mechanism
  (`persist:false` — localStorage/URL keep the saved scope, which returns on
  the next plain load). Without this, the scope-exempt town drawer filled
  with nearby items while the scoped map rendered the same area empty — the
  worst case being a myArea scope with an **empty derived set** (base
  location outside every seeded region, e.g. a Dutch base today), where the
  whole map is leak-safe-hidden. Bbox containment is the deliberate
  approximation: an inside-bbox town already renders its surroundings, so no
  widen is needed there. Applies to every scope kind, not just myArea.
- **Default precedence (map-and-search.md §4.5):** on load, `URL scope > myArea
  (if available) > localStorage`. My-area wins the default scope whenever a
  base location is set, overriding a stale localStorage scope — except an
  explicit shared URL scope, which always wins.
- **Country / multi-region scope dim mask.** A country scope now
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
  mask for Everywhere).
- **Cross-border scope chips rank by adjacency.** The scope chips
  offered around a region (compass grid + linear list) include a *foreign*
  region ONLY when it shares a border with the active region — never by centroid
  distance. So a border region is offered its true cross-border neighbours
  (Groningen → Lower Saxony) while an interior region stays single-country
  (Utrecht stays all-Dutch), each by construction, not tuning. Adjacency is
  precomputed at catalog import into `region.adj` (`integer[]`, one
  `ST_Intersects` pass across all onboarded countries) and shipped inline in
  `CC_REGIONS`; `chipModel` (`scope-chips.js`) gates foreign chips on it.
  Supersedes an earlier centroid-distance ranking.
- **…and are ORDERED by polygon-edge distance.** Within the pool
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
- **Map-click scope refinement.** A map click resolves to its region
  by a synchronous bbox candidate pass; when 2+ region bboxes overlap the point,
  `CCScope.regionOfPointPrecise` fetches those candidates' polygons
  (`/map/region/{slug}/boundary`, cached) and runs a pure point-in-polygon test,
  so the click lands in the region actually under it, not the nearest bbox
  centre. A single candidate never fetches (the common case stays instant);
  inside no candidate polygon it falls back to nearest-centre.
- **Three-tier region spotlight.** For a single named-region scope,
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

  **`region.adj` must hold operational regions only** (catalog-data-model.md
  §2.4). The clear hole is punched through `active + adj`, so one non-scope id
  in that list is not a cosmetic error: the first recompute paired every region
  with every polygon it intersects, which includes its own level-2 country
  outline, and selecting North Holland cleared the whole Netherlands while the
  neighbour tier it was meant to show stayed invisible (owner 2026-08-24).
  `ImportCatalogCommand::adjacencySql()` now applies the operational predicate
  to **both** sides (a country outline gets an empty list, which is the truth
  about a row that is not a scope), `Version20260824120000` backfills deployed
  databases with that same SQL, and `RegionRegistryProvider` drops any adj id it
  is not itself shipping — so a stale row cannot reach the client either.

#### 4.5a Boxes that cross the antimeridian

A scope box whose **west value is greater than its east value** crosses ±180°
and is read the long way round. That is the GeoJSON convention
(RFC 7946 §5.2), `RegionRegistryProvider` emits it, and `CCScope` is the only
thing allowed to interpret it.

It is not hypothetical. No single region we ship crosses the seam, but **New
Zealand's country scope is the union of seventeen regions** running from
Southland at 166.4°E to the Chatham Islands at 175.8°W. Taking the minimum west
and the maximum east of those, which is what the union used to do, produced

    [-176.9, -47.3, 178.6, -34.4]     355.5° wide

a box containing every point on Earth. Nothing errored. New Zealand simply
became everywhere: town search sent Photon a worldwide box, the
"you are looking outside your filter" chip could never fire because the box
always overlapped, and `regionOfPoint()` would hand a rider in Belgium a New
Zealand region, because a box that contains everything also wins on centre
distance. The same shape waits for the United States the day Alaska is
onboarded. Fixed 2026-09-09; the same scope now reads

    [166.4, -47.3, -175.8, -34.4]     17.7° wide

**The rule: never compare a box by hand.** `b[0] <= lng && lng <= b[2]` is false
almost everywhere a crossing region actually is, and was true everywhere else.
`CCScope` exposes the questions instead, and `scope-ui.js` and `places.js` ask
them rather than reaching into the array:

| Ask | For |
|---|---|
| `CCScope.bboxHasPoint(lng, lat)` | is this point in the active scope |
| `CCScope.bboxOverlaps([w,s,e,n])` | does this viewport meet the active scope |
| `CCScope.bbox()` | the scope box, which may cross |
| `CCScope.scopeCenter()` | a centre that lands inside its own box, not on the far meridian |

Two things fall out of it. **Photon is never handed a crossing box**: it reads
`minLon,minLat,maxLon,maxLat` and has no notion of going round the back, so the
bbox is dropped for a wrapping scope and `countrycode` carries the filter.
And **the union keeps a crossing crossing**: `bboxUnion()` compares the width
each way and takes the shorter, which is what turns those seventeen regions
into 17.7° instead of 355.5°.

On the server, the crossing is detected by a **raw longitude span wider than
180°**, not by comparing the shifted and unshifted spans. `ST_ShiftLongitude`
adds 360 to a negative longitude and the result cannot hold the original
mantissa, so every western-hemisphere region comes back about 1e-14 narrower;
the span test says "crossing" for Madrid and Asturias too. It happens to map
back to the same numbers for them, so that answer was right by luck rather than
by reasoning. Only a box reaching from one edge of the seam to the other is
wider than 180°.

### 4.6 Chrome theme: dark and light

The map page's chrome (icon rail, drawer, legend, panels, popups) ships in two
themes. Dark is the default and is the look the page has always had; light
inverts the ground: paper (`--paper`) carries the chrome, ink (`--ink`)
carries the text, trail orange stays the accent. The basemap tiles are the
same in both themes: liberty is a light style already, so only the chrome
changes.

Mechanism, one attribute end to end:

- **Tokens.** `map.css` defines chrome tokens (`--chrome-bg`, `--chrome-fg`,
  `--chrome-fg-solid`, `--chrome-glass`, `--chrome-head`, plus lift/well/field
  grounds and deep-on-cream partners for every pale-on-dark accent) in
  `:root` with the dark values, and redefines them under
  `html[data-map-theme="light"]`. No chrome rule reads the brand literals
  directly anymore; a rule that does stays dark in light mode
  (pinned by `tests/js/map-theme.test.cjs`). The nav wordmark is the one
  asset swap: `brand/logo-nav-light.svg` (ink letterforms) replaces the
  cream-lettered `logo-nav.svg` via `content:url()` in light mode, because
  an image cannot follow CSS tokens. Constant surfaces stay constant
  by reading the brand tokens, not the chrome tokens: on-map badges
  (steepest/summit), toast, tooltip, scrims, the lightbox and the
  paper-panelled climb profile.
- **Contrast floor.** Chrome TEXT set from the fg token never drops below
  alpha `.65` - the lowest value that keeps AA's 4.5:1 on both grounds
  (a `.5` alpha measures ~4.4:1 dark and ~3.2:1 light). `color:` declarations
  only; borders and backgrounds are decorative, and off/disabled rows dim via
  `opacity`, which is a control state, not body copy. Scrollbar thumbs keep a
  `.55` floor (components need 3:1). Pinned by `tests/js/map-theme.test.cjs`.
- **Toggle.** The sun/moon button in the rail's bottom cluster
  (`assets/map/theme.js`) flips the attribute. Its glyph and label name the theme a press will GIVE
  you (`map.theme_to_light` / `map.theme_to_dark`).
- **Persistence.** Same profile-over-localStorage rule as the view mode
  (§4.2): a logged-in rider's choice POSTs to `/map/theme`
  (`MapThemeController`, stateless CSRF id `map-theme`, `users.map_theme`,
  `MapTheme` enum: `dark` | `light`) and follows them across devices; an
  anonymous visitor's choice stays in localStorage (`cc:mapTheme`). The
  choice is also editable on the settings page (`SettingsType::mapTheme`).
- **First paint.** The server renders the attribute into `<html>`
  (`data-map-theme`), so a rider's stored choice never flashes dark. For
  anonymous visitors the server always renders `dark` and a tiny inline head
  script promotes their localStorage choice before the stylesheets paint;
  the script is emitted only in the visitor branch, so a shared device's
  localStorage can never override a logged-in rider's profile value.

### 4.7 Map key (2026-09-01)

Since 2026-09-09 every type draws its own one-colour icon
(`ItemType::svgPath()` is never null): the emoji glyphs painted themselves
in the platform font's colours, and the tent came out green and yellow on
the landing page (owner: "no coloured icons"). Quality rides are a route
winding across the land, a ribbon, not a star (owner: "a slinger line").
The glyph stays as the text fallback only. The map rail's layer rows draw
the same paths through `layerGlyph()` in `assets/map/icons.js`, so the
route row shows the ribbon and not the old star. On `/map-key` the category
tiles draw their hairlines from each tile's own shadow, so an empty slot at
a row's end stays page-coloured, and the group headings sit at the site's
kicker size.

The marks stack four independent signals on one shape, and the key says so in
two places, sized to their audience:

- **The Key rail panel** (`data-panel="key"`, `#p-key`, title
  `map.rail_key`). The quick reference while riding: the four grammar rows
  (data-provider-hierarchy.md §6.7: small disc for a gross provider, dashed
  for a specialty provider, solid paper for ours, and the `?` badge for
  nobody-has-stood-here, on any border)
  and the state marks (stale ring, cluster bubble, look-here ring). LIVE
  MARKS ONLY: the panel never shows a mark the map does not draw. The
  pending-border row is moderation chrome, gated by `pending_is_curator`
  from the controller (never `is_granted()`, per §"the 2FA policy applies in
  exactly one place"). Swatches reuse the real `.cc-pin` / `.cc-cluster` /
  `.cc-highlight` classes so the key cannot drift from the map. The panel
  links to the full page. Since 2026-09-04 it also carries a **Kinds** group
  generated from `cc_kind_icons()` (one row per registry kind, `.mk-kind`
  with `data-kind="<letter>:<kind>"`) and the two live state badges (`!`
  and the clock, `.mk-state-warn` / `.mk-state-hours`).
- **The `/map-key` page** (`PageController::mapKey`,
  `LocalizedPath::MAP_KEY`, `pages/map_key.html.twig`, `legend.*` strings,
  all five locales, slug localised per locale). The full story: the four
  axes (shape = disc or teardrop, fill = category, border = who keeps the
  record plus the moderation and stale states, badge = a fact that survives
  colour blindness), the four grammar rows, the kinds per category
  (generated from the registry, see below), state marks, per-place fact
  badges, the category table and every line paint. Every mark on the page is
  drawn by the map; nothing on it is planned, and `MapKeyTest` fails the
  build on a planned tag or on the words "paper dot". Both keys link
  `styles/pins.css`, the one definition of the pin, and never copy its rules.

**Basemap furniture (2026-09-10).** The liberty style's four POI layers ask
the OpenFreeMap sprite for an image named after each point's OSM class, and
the sprite lacks most classes: one console warning per class, nothing drawn.
`App\Catalog\BasemapIcons::set()` is the one registry of the classes a rider
reads in passing (bollard, gate, bicycle_parking, cycle_barrier), each a
monochrome drawing with a paper halo in the 24-box. `map-init.js` answers
MapLibre's `styleimagemissing` event: a registry class is minted from its
paths at 18px, every other missing name gets one blank 1x1 image, so the
console stays quiet and the basemap draws exactly what it drew before plus
the four. Both keys list them under "From the basemap" through
`partials/_basemap_icon.html.twig`, generated from the same registry. They
are not catalogue items: no pin, no drawer, no confirmation, never on the
ladder. Making them items would put thousands of bollards on the evidence
ladder for nothing.

The one category-colour table on the website lives in this template and
mirrors `catalog.js`; a colour change lands in both in the same commit. The
glyphs come from `cc_type_icons()` (`ItemType::iconSet()`), THE icon set.
Line-legend strings reuse the on-map key's `map.legend_*` ids rather than
duplicating them.

**The pin grammar (owner 2026-09-04, data-provider-hierarchy.md §6.3a):
kind lives in the glyph, state is two shared badges, everything else is
drawer content.** `App\Catalog\KindIcons::set()` is the one definition of
every kind glyph; the map mints tile icons from it (`icons.js`), the DOM pins
draw it inline, and both keys render it through
`partials/_kind_icon.html.twig`, so a letter-B pin never shows the 💧 and
the keys cannot drift. Water & food kinds: drinking tap (blue drop), not for
drinking (barred drop), nothing said (unfilled drop), food stop (fork and
knife on the category disc), food stop with water (plus a small drop). Bike
services: shop, repair stand, pump. The state badges sit top-left, opposite
the `?`: red `!` = not usable right now (`condition`
Out of order or Closed), ink clock = there, but not always (`seasonal`
Summer only or Frost-shut in winter). `waterKind()` and `stateOf()` in
`icons.js` are the two rules, shared by every renderer.

Tier semantics pinned here (ruled 2026-09-01, amended 2026-09-04 and
2026-09-09): grey FILL no longer means anything on water, potability having
moved into the kind glyph (the grey drop is gone). The border answers
custody and the badge answers evidence, one mark each
(data-provider-hierarchy.md §6.7): a register with a registry scope for the
letter draws dashed, one without draws as the small disc, and either loses
its `?` only when a witness is on record. Dashed borders are MONOCHROME
(also ruled 2026-09-01, replacing the ochre dash: it did not read on a busy
basemap): ink dashes over a white halo, legible on any ground, light or
dark, without spending a colour. There is no keeper tier and no paper dot.

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
  `moveend`/`idle`, never per animation frame). **While a ride check is
  loaded (§9) the places it lists never cluster:** `setListedPlaces()` hands
  the pools a set of `letter:id`, `splitPool()` (`ride-places.js`) keeps those
  features out of the clustered source, and `updateConfMarkers()` draws each
  through the same `confLeafPin()` leaf code, still judged by `poolVisible()`
  (scope and view mode) and the stays accessibility rule. Layer counts read
  the pool's own features, not the source, so they do not move. Clear hands
  back an empty set.
- **A · Road surface** renders **consolidated**: ONE GeoJSON source for all
  segments + one shared cream casing layer + **one line layer per surface
  class** — MapLibre cannot data-drive `line-dasharray`, so dash/cap vary per
  class layer, not per feature. Style keys **primarily on `surface=`,
  secondarily on `smoothness=`** (avoiding CyclOSM's known bug that hides
  gravel under `smoothness=intermediate`). Class palette in `SURFACE_STYLE`
  (map.js): purple cycleway, slate paved, dashed ochre gravel, square slate-grey
  pavé dashes, dashed brown dirt, dotted dark-grey rock, and red dashes for
  `unverified` (no surface tag — "needs a tag"). Re-render is a single
  `setData`; click/hover listeners bind once per class layer and resolve the
  feature via `properties.idx`.
- **A · the OSM surface skin** is a *second* A layer and a different thing: the
  curated source above is our own items, this one is every surfaced way OSM
  knows, served from its own **vector-tile artifact with zero database rows**
  (`web/assets/map/surface-tiles.js`, off by default behind the ▰ Surfaces
  control). It is deliberately not in `coverage_poi`: that index is for points,
  and lines need neither SQL nor dedupe, which is what makes country-scale
  surface data cheap.

  **It is deliberately NOT scope-clipped, and it has a zoom floor instead**
  (owner 2026-08-20). Clipping it to the active region would be the wrong
  trade: a rider planning a Wallonia ride into Germany wants the German roads
  without re-scoping the map, and the skin is reference context, like the
  basemap. The real cost was weight. The artifact is built z8-13 with
  tippecanoe's `--no-tile-size-limit`, so a low-zoom tile carries every
  classified way under it: measured over Wallonia one tile is 1119 KB at z8,
  455 KB at z9, 93 KB at z10, 41 KB at z11. A screen is roughly sixteen tiles
  at any zoom, which made the default region view about 16 MB. The class
  layers therefore carry `CLASSIFIED_MIN_ZOOM = 10`, which stops MapLibre
  requesting the z8/z9 tiles at all, costs nothing legible (the layer's own
  paint is 0.6 px at half opacity at z8) and matches the existing division of
  labour: the gaps grid answers the planning-zoom question, roads answer the
  riding-zoom one.

  **Below the floor the CONTROL says so, not the map.** A control a rider just
  pressed must never leave the map unchanged and silent, but the answer has to
  appear where the press happened: it was tried in the map's own corner hint
  first, and nobody read it (owner 2026-08-20: "nobody is gone see that"). Two
  channels, both at the control. The Surfaces row's state column takes a third
  value beside On and Off - "Zoom in", in the accent so it reads as a prompt
  rather than a count - and it follows the ZOOM as well as the press, so a
  rider who zooms out with the skin already on gets the same answer without
  touching anything. The press itself also raises a toast, once. The map's
  corner hint keeps out of it and goes back to the pending-review line, which
  the surface message had been displacing.

  **The build carries the same floor** (`surface.minZoom: 10` in
  `pipeline/contract/coverage-contract.json`, raised from 8 on 2026-08-20), so
  the z8/z9 tiles are not produced at all rather than produced and never
  fetched. A cross-language test pins the contract floor to the client's
  `CLASSIFIED_MIN_ZOOM`: a client floor above the build's would fetch nothing
  in the gap, and a build floor above the client's would have the client asking
  for tiles that do not exist, which fails silently. **The next republish
  applies it** - the artifact on the bucket is still the z8-13 one.

  `SURFACE_STYLE` is reused verbatim, so a tile line and a
  curated item of the same class are the same colour — ours simply draws on
  top. The class set is the pipeline's
  (`pipeline/contract/coverage-contract.json` `surface.classes`), one layer per
  class per country, filtered on `cls`. Contribution loop:
  [edit-items/A-road-surface.md](edit-items/A-road-surface.md).
- **The legend is the filter for it.** Each of the seven key rows is a button
  with `aria-pressed`; toggling one hides that class on *both* the curated and
  the tile layers, because a rider filtering to "gravel" means gravel, not
  gravel-from-one-source. Dash gaps in the key are **transparent**, not cream:
  the curated layer has a white casing and the tile layer has none, so a painted
  gap made the key disagree with the map it was explaining.
- **Quality ticks** (owner shape, 2026-08-12): surface QUALITY finally has a
  visual channel of its own — short coloured dashes drawn over the class lines
  (`surfq-*` layers in `surface-tiles.js`), green → amber → red from the OSM
  `smoothness` the tiles ship as `sm`, z13+ only, and **only where smoothness
  is recorded** (the layer filters on `has sm` — no tick means nobody has
  said, the same honesty rule as the red dotted line). Pattern is a free
  channel: it stacks on the class colour instead of competing with it. The
  tone map's keys are the contract's `surface.quality.values` (all eight OSM
  values; the drawer collapses them to the form's five for display), pinned by
  `surface-quality.test.cjs`. The legend explains the ticks in one non-filter
  note row (`.skey-note`); hiding a class hides its ticks via the layer
  filter, not a legend row of their own. **Curated items get the same ticks**
  (2026-08-14): the rider-recorded smoothness rides the consolidated A source
  as `sm` and draws as `surface-q` in `render.js` (five form values, same
  palette — `CURATED_SM_TONE`, kept in step with `SM_TONE` by hand because
  surface-tiles imports render). A rider who had just recorded a road as
  Excellent saw no ticks on it while the legend promised them "where
  recorded" (owner-reported). Unlike the skin's thin light lines, the curated
  lines are wide and dark, so the curated ticks carry a cream casing tick
  underneath (`surface-q-case`, dash scaled by the width ratio so the two
  patterns stay in step) — an excellent-green tick on the paved slate is
  invisible without it. The tile skin's ticks got the same casing the same
  day (`surfq-case-<cc>`, id kept under the `surfq-` prefix so
  applyClassVisibility toggles and re-filters it with the ticks).
- **Class lines are SOLID; the dash channel belongs to quality**
  (owner 2026-08-14): once ticks stitched over the lines, gravel's own ochre
  dashes and an amber quality tick were two dash patterns fighting on one
  line — and "smooth gravel vs rough gravel" is exactly what the ticks
  exist to say. `SURFACE_STYLE` now carries colour only for
  paved/gravel/pave/dirt/rock (curated layer, tile skin and the legend
  swatches all read it); `unverified` keeps its red dash, because that dash
  IS its meaning and it never draws ticks.
- **The tile lines dedupe against curated refs** (2026-08-13), the same rule
  the coverage points have always had: a way already answered as one of our A
  items is filtered out of the classified skin, the to-do arm and the quality
  ticks (`surfDedupeFilter`, re-applied on every toggle since the refs arrive
  with the catalog fetch). Before this, a rider's approved asphalt stretch
  kept its red "Surface not recorded" dashes under the green curated line —
  the map contradicting itself about a road somebody had just answered.
- **A tile-line click hands the wizard the WHOLE way** (2026-08-13): vector
  tiles clip geometry at tile borders, so the clicked feature is only one
  tile's fragment — pins built from it covered part of the road, and a
  boundary sliver produced a wizard with no line at all. `fullWayEnds`
  (surface-tiles.js) reassembles the way from every loaded tile via
  `querySourceFeatures` and takes the farthest-apart endpoint pair; the
  corridor click uses the same helper.
- **The curator ghost layer of gone places** (2026-08-13): an item approved
  as "Not there anymore" is hidden from the public payload for good (its row
  and the reporter's submission mapping stay; its OSM ref stays claimed), but
  hidden-everywhere answered removal and not RETURN — a rebuilt tap could
  never be found to reactivate. `CatalogProvider::goneForMap()` hands
  curators these as `window.CC_GONE` (same curator-only channel as
  CC_PENDING, scoped like the pending queue); the map shows them as a
  "Removed places" moderation-group layer, off by default. Reactivation is
  the ordinary edit form — the drawer's edit link types itself per feature
  (`f.letter`) — setting the condition back, through the one moderation
  pipeline.
- **The cycle-route network layer** (`web/assets/map/routes-tiles.js`, behind
  the ⤳ Routes control, off by default) draws signed `route=bicycle`/`route=mtb`
  corridors plus knooppunt number badges from their own PMTiles artifact
  (`routes_<cc>` line layers + `knoop_<cc>` point layers —
  [coverage-provider.md](coverage-provider.md) §4). The benchmark is
  OpenCycleMap and the brief is **readable by default, detail on demand**:
  three visual families (national icn/ncn rose-red, regional rcn/lcn/other
  purple — the OpenCycleMap associations; a first cut had regional in blue and
  it read as grey-green over polder fields), wide translucent corridor lines
  so the basemap road stays legible inside them, and number badges from z12
  with symbol collision doing the culling (a first cut held them to z13 and
  read as "there are no knooppunt numbers" at planning zoom). Clicking a corridor
  opens the surface drawer for that **way** — the layer exists to close the
  loop with the surface skin (a signed way with no recorded surface is exactly
  the road worth asking about), so the improve action is the ordinary
  `/improve?ref=way/NNN&type=road-surface` bridge, with both wizard pins
  pre-placed on the clicked stretch. Knooppunt badges open an info-only drawer
  (a junction number is not a road; there is no surface to offer a wizard
  for). Network styling keys and badge zoom are pinned to the contract by
  `routes-zooms.test.cjs`. It stacks above the surface skin (the corridor is
  the headline the toggle asks for) and below everything curated.
- **Study mode** (the toggle inside the legend, where it belongs — it is about
  reading these classes) drops the basemap to a flat light-grey and hides
  satellite and street-level with it, leaving only the surface lines. Turning it
  off restores whatever the style had, rather than forcing everything visible.
  **It is gated on the surface skin being on**: with the layer off it would strip
  the basemap to show nothing, and a rider landing on a blank page cannot tell
  whether the feature is broken or the layer is missing. The control disables
  with the layer and switches off with it.
- **A key for the BASE MAP's own road colours was built and removed the same
  day (2026-08-15).** It explained liberty's importance-palette (motorway #fc8,
  through-roads #fea, white-over-grey minors), grew zoom badges and a live
  "you are below that zoom" line when it turned out those colours barely exist
  at planning zoom - and was then withdrawn, because the diagnosis moved: the
  problem is not that the base palette is unexplained, it is that THREE road
  colour systems share one canvas (liberty's importance colours, the thin OSM
  surface skin, the thick curated lines - the curated casing is 8 px at every
  zoom over liberty's 2.5 px residential fill at z14, layer slot ~614 over
  ~45-55, so ours buries theirs wherever we have data). The owner's direction,
  recorded in docs/TODO.md ("Surfaces view: blank the base map's roads - one
  colour system"), is to hide liberty's road linework while the skin is on and
  offer a toggle to bring the full road system back; the key only makes sense
  as part of that build, so it waits for it.
- **`unverified` is labelled "Surface not recorded"**, not "unverified" — the
  class means OSM records no `surface` tag there, and riders are precisely who
  *verifies* things, so the old word claimed the opposite of what it meant
  (owner-reported 2026-08-12).
- **The "needs recording" arm is served** (2026-08-12), and it is governed by
  **the legend row**, not by a control of its own. Every line in it is a road
  nobody has recorded a surface for — a road somebody could go and record — so
  it is the **contribution view**: "then people will know what to tag and extend
  the map knowledge" (owner). It is a class like the other six and behaves like
  one: ticking *Surface not recorded* shows it, unticking hides it.
  It happens to live in a **second artifact**, because a vector tile is fetched
  whole and folding these ways into the classified tiles would make every
  surface tile larger for every rider. That is a fact about storage, and a rider
  filtering the key should not have to know it — so the arm is *mounted lazily*,
  only when the skin is on and that row is ticked. Drawn thinner and fainter
  than the classified arm: it is a to-do list, not an answer.
- **It is not every untagged road, and the difference is editorial**
  (2026-08-12). It was, and that cost as much as the entire classified skin
  (Belgium: 42 MB against 43). Measured against our own tagged data, a Belgian
  road somebody HAS tagged is unpaved 0.1 % of the time when it is `primary`,
  0.5 % `secondary`, 2.5 % `tertiary`, 0.0 % `cycleway` and 7.2 %
  `residential` — and mappers tag the surprising road first, so an *untagged*
  one of those is safer still. Drawing them as homework asked riders to go and
  confirm asphalt. The arm now carries only the classes where nobody can predict
  the answer — `track` (88 % unpaved when tagged), `path` (a coin flip) and
  rural `unclassified` lanes — which took the Benelux arm from 119 MB to
  **34.8 MB** and made the prompt sharper rather than weaker. The set lives in
  the contract (`surface.todo.highways`), not in the client.
- **Below z11 the same question is answered by a grid, not by roads**
  (2026-08-12). `surface-gaps.pmtiles` carries one square per ~6 km (a z12 tile)
  with the kilometres of unrecorded to-do network inside it, its share of that
  cell's network, and a road count: **0.6 MB for the Benelux against 34.8 MB of
  lines**. It is the *planning* half of "what still needs recording" — a rider
  choosing where to point a Scout ride needs to see which part of the map is
  dark, not 400,000 line geometries they cannot read at that zoom. Shading is
  the **share**, not the absolute kilometres, so a dense city cell does not
  out-shout the empty countryside that actually needs surveying.
  The grid stops at exactly the zoom the lines
  start (contract `gaps.maxZoom` == `todo.minZoom`, pinned on both sides by
  `web/tests/js/surface-zooms.test.cjs`). **The grid is opt-in since
  2026-08-14** (`#skeyGaps`, below Study mode in the legend, shown only while
  the skin is on): arriving with the skin, the squares tinted whole regions
  pink at planning zoom — and translucent red over blue water reads PURPLE,
  a colour with no legend row of its own. It is a contributor's
  question ("where is recording needed?"), not a rider's, so it waits behind
  a toggle whose label says what the squares mean. The *Surface not recorded*
  legend row still gates it too: that class off hides the unrecorded arm in
  every form, lines and squares alike.
  Clicking a square opens a drawer with the numbers and **no "improve this"
  bridge** — a square is 6 km of countryside, not a road, and the honest next
  step is to go and ride it rather than to invent an answer for a road you have
  not seen.
- **N · Climbs** with traced geometry draw a gradient-coloured line
  (`line-gradient` over `line-progress`, purple ramp `gradColor()`) plus a
  "steepest pitch" marker; the pin sits at the climb **foot** (first route
  vertex). The same purple ramp renders the 1–5 difficulty scale in the drawer
  (deliberately "climb-coloured").
- **R · Routes** draw in a **pre-blended lighter orange at full opacity**
  (`ROUTE_BASE_COLOR`) instead of a translucent line — translucent lines
  stacked where routes share a road read as random darker segments. The
  **selected** route gets full brand orange, a wider halo, and dims every
  sibling (`highlightRoute()`); selection survives `render()` rebuilds and is
  cleared on drawer close / non-route open.
- **Stacking rule** (bottom → top): route lines, climb lines, road-surface
  lines, Mapillary coverage — re-applied after any selection `moveLayer` so a
  highlighted route never buries the surface colours
  (`liftInfoLayersAboveRoutes()`).

### The pending layer is exempt from the region scope (2026-08-12)

A curator's pending layer is a **work queue**, not a view of a region. It is
already scoped server-side to their own moderation area
(`SubmissionQueue::pendingForMap` + `ModerationScope`), and running it through
the map's region gate as well meant a curator whose map happened to be scoped
elsewhere read "Pending review 0/0" and concluded there was nothing to do — the
submissions were only findable by arriving from the desk, whose `?pending=`
link widens the scope to Everywhere as a side effect (owner-reported).

Two scopes for one question is one too many, and the server's is the one with
authority. `featureVisible()` returns true for `pendingLayer` before any gate,
and `layerCounts()` counts its whole set.

**And the map says so** (2026-08-14), in the words of whoever is looking.
TWO audiences see this layer: a curator gets their moderation area's whole
queue, a rider gets their own undecided submissions and nothing else
(MapController). The on-map hint has a sentence for each, and the orange
**curator-mode border answers to `CC_IS_CURATOR`, never to the layer being on
screen** - a rider's own pending pins put it there too, and a plain account
was wearing the border (owner-reported 2026-08-20).

The cost of that exemption is that
scoping the map to one region while a queue sits in another looks exactly like
a scope leak: the owner read it as one, scoped to Free State with sixteen
pending submissions in North Holland. Both readings cannot be right, and the
2026-08-12 decision is the one to keep - re-scoping the queue is what produced
"Pending review 0/0" in the first place. So the on-map zoom hint names it
instead: *"Pending review follows your moderation areas, not the map scope"*,
shown while the layer is on, holds something, and the map is scoped narrower
than Everywhere. Same slot as the coverage zoom hints
([coverage-provider.md](coverage-provider.md)), and it takes precedence over
them - a rider who is also a curator is more likely to be confused by pins in
the wrong country than by an empty one.

**And the map wears it.** `#map` takes `.cc-curator-mode` under the same
condition, drawing an orange ring inset around the canvas: the hint explains the
pins, the ring says which mode you are in without reading anything (owner
2026-08-14). Inset `box-shadow`, never a real border - a border resizes the
canvas and makes MapLibre re-measure on every toggle.

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
  ≥ 14 with an offset so the drawer doesn't cover the pin (`pinOffset()` in
  `util.js`): `[-150, 0]` beside the desktop drawer, and on a phone (the 820px
  layout flip) straight up into the middle of the half above the bottom sheet,
  which opens at half the screen.
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
  densifies to complete by z11 (the layer counts stay the exact total). At
  overview zoom (z6–~9), a `<key>-<cc>-heat` heatmap layer on the **same**
  source-layer (`maxzoom 9`) draws the region's coverage as a smooth density
  surface, built from the tile's thinned z6–10 sample (`coverage-provider.md`
  §4) — this fills the empty overview that the no-cluster z11 floor had left,
  with the exact `/map/coverage/counts` still carrying the precise "how
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
  The heatmap supersedes a z6–10-is-empty stage, which itself replaced the
  cluster bubbles; tile contract: [coverage-provider.md](coverage-provider.md) §4.
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
  injected as `window.CC_FIELD_SCHEMA = {A: […], …, R: […]}` — **localized per
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
"▲ Best of" badge when `f.cur`. Body: photo (main image + thumbnail
strip, opening a slideshow lightbox with ‹ › buttons and ←/→ keys, per-photo
credit linking licence deed + source + author, N/M counter — media rules in
[edit-items/README.md](edit-items/README.md)). The main image sits in a 4:3
frame: a wide photo fills it, and a photo taller than it is wide (`is-tall`,
set by `drawer.js` once the image has loaded) is shown whole on a quiet ground
instead of being cropped, so a statue keeps its head (owner 2026-09-15); or, when the item has a DB id
and no photo, an **add-photo CTA** deep-linking the improve wizard; description;
difficulty scale; elevation profile (static SVG, §13.2 upgrades it); gradient
strip; the record rows (§6.2) with per-row method tags where present, a long
unbroken value such as a web address wrapping inside the drawer; freshness
block (state + last-confirmed) for safety/dynamic items; uploader line
(public profile link or "shared anonymously"); the **Source line** — the single
provenance surface, linkifying OpenStreetMap to the object's coordinate query
view and PIVOT to the Géoportail catalogue entry. `srcType` (the item's real
`ItemSource`) overrides the OSM-flavoured headline/source for rider-contributed
items served through bulk-OSM layers.

**Rider-contributed is a set, not a pair.** `isRiderSource()` (i18n.js) owns it:
`user`, `manual` and `scout`. The test used to be written inline as
`srcType==='user'||srcType==='manual'` in four separate places, every one of
which omitted `scout` — the provenance a tag dropped while riding actually
carries. The owner's own scenic addition therefore credited *OpenStreetMap
(tourism=viewpoint / natural=peak / waterway=waterfall)*: the per-layer OSM
fallback, on a place OSM had never heard of (reported 2026-08-14). Harvested and
derived rows (`osm`/`pivot`/`wikidata`/`auto`) keep their real citation.

**In the set is not the same as sharing the label.** `manual` is a row the
project seeded by hand (`SeedManualCatalogCommand`); it belongs in
`isRiderSource()` so it never falls back to an OSM citation, but until
2026-08-16 it also borrowed `user`'s label, so the Furka Pass read *Source ·
Rider-contributed* - a false claim, since no rider added it (owner-reported the
night the CH climbs were recomputed). `manual` now has its own label,
`d_src_manual` "Hand-curated", in five locales. Seeded rows join to no
submission, so they carry no `by` key and never claim *shared anonymously*
either; nothing needs assigning to a curator account for the line to be
honest. `web/tests/js/source-labels.test.cjs` pins the whole chain: every
`ItemSource` case has its own `SOURCE_LABELS` entry, `manual` reads
`D.srcManual` and never `D.srcRider`, and the key exists in controller wiring
and all five locale files.

**The person IS the source; how it reached us is the line beneath.** The Source
line reads *Source · XanderK*, the name itself being the profile link, with the
provenance citation ("tagged while riding, with Scout") dropped to a quieter
line under it. They were two lines making two competing claims - *shared by X*
above *source: Scout* - when between them they answer one question: a rider
added this, and this is how it arrived. A row with nobody to name (anything
harvested) keeps the citation on the Source line itself, where it has always
been; a rider without a public profile reads *Source · shared anonymously*.

**Who added it is a line of its own.** A point item has no creator column - the
person is on the submission that minted it - so `CatalogProvider::itemRows()`
joins the earliest `type='new'` submission (via the indexed
`submission.item_id`, never by parsing `source_ref`) and serves `by` / `byName`
/ `byUuid`. `public_profile` is the gate and it fails closed: a rider who has
not made their profile public still yields `by: 0`, so the drawer says *shared
anonymously* rather than nothing at all - the contribution is still a rider's,
and saying so without naming them is what the flag is for. Harvested rows join
to nothing and carry no key; OSM did not share anything with us. Before this,
an addition tagged while riding read as though it had arrived from nowhere
(owner-reported 2026-08-14). The profile link is `/riders/{uuid}`: display names
stopped being unique on 2026-07-31, so the old `/profile?u=<slug>` was never a
way to find a person - it went to the *reader's* own account page. The
bulk-OSM drawer builders (`osmDrawer`, `waterDrawer`) construct their own
object, so they must copy these keys over explicitly, as they do `srcType`.

Both builders credit an authority row's publisher through the payload's
provider map (`providerOf`, `providerSource`; data-provider-hierarchy.md §7):
the headline's origin word, the Type row's method, a "Listed · Official
registry entry" row and the Source line. `waterDrawer` joined on 2026-09-05,
when the first RIVM tap opened read "Drinking water, OSM" twice. A register
row that has no name (RIVM names none) also shows its `town`, so a rider who
finds nothing there has a handle to report it by, beside the share link that
carries the CC-row's id. The Verify row ("cross-check with the regional
utility") is advice for an OSM tap nobody vouches for and is dropped on an
authority row: the register IS the utility's answer.

**Scout is a link.** The provenance label for `scout` ends in the product name,
and it points at `/scout`, the page that explains what it is. Gated on the
item's `srcType`, not on the word appearing in the citation string: a free-text
attribution that happens to contain "Scout" is not a reference to ours. All five
locales keep the brand name untranslated, so one pattern covers them.

**Share sits in the drawer header**, beside the type chip, not on the source
line where it first landed. It is the **icon alone** - the share glyph means the
same thing on every phone a rider owns, and the word beside it made a second
chip competing with the type chip. Icon-only moves the label to `aria-label`
(and `title`), with the `<svg>` `aria-hidden` so the button announces once. Sharing is something a rider decides the moment
they recognise the place, and the source line is below the photo, the
description and every record row — a scroll away from the name being shared on
any drawer with content. It stays out of the footer action row, which is for
verbs that change data. It copies `?item=<id>`, then `?ref=<osm ref>` for a
coverage POI with no catalog id, and `?feature=<name>` only when neither id
exists. All three are read on load by map.js, which widens the scope first so
the link opens for a recipient whose saved scope is elsewhere.

`?ref=` exists because the name is not an identifier. Most OSM scenic views
carry no `name` at all, so the drawer titles them by type and every one of them
is called "Viewpoint": `?feature=Viewpoint` was a single link shared for
thousands of separate places, and it opened whichever the search returned
first. The ref is unique, it is already on the tile, and the guard that accepts
it (`osmRefUrl` / `OSM_REF` in `osm-tags.js`) is deliberately the same
`node|way` shape the detail route requires, so a link that can be shared is
always a link that opens again. Reported by the owner, 2026-08-24.

The **slug** exists because an id is unique and unreadable, and a shared link
is read before it is clicked. It is decoration only: `shareQuery` appends it,
the parsers throw it away, and nothing downstream ever sees it. So it cannot
drift out of date, and it cannot change which place opens.

Which name gets slugged is the one judgement call. `f.name` is not it: an OSM
point with no `name` tag is *titled* by its category, so slugging `f.name`
would mint `?ref=node/2348266912/viewpoint` and present our own word as the
mapper's. The drawers therefore set `shareName` only when `p.n` is present, and
`shareQuery` falls back to `f.name` only for a feature with a DB id, whose name
is its own. A name in a script the slug cannot carry (`東京`, `Δελφοί`) folds to
empty and the link is simply the bare id, never a row of hyphens. Owner ask,
2026-08-24.

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

**The heading opens the full log.** In the drawer each change is clamped to two
lines, which is right there - one long description edit would otherwise push
everything below it off the screen - but clamped is not the same as
unavailable: a rider reading *"View from 'Zuiderdijk' left The Markermeer
and…"* cannot tell what was actually changed (owner-reported 2026-08-14). So
"Recent changes" stops being a label and becomes the control it already looked
like, opening a native `<dialog>` with every change in full and nothing
clamped. Native, so Esc, focus trapping and the backdrop are the platform's
rather than ours; rebuilt on each open from the rows last rendered, so it costs
no second fetch and cannot show a stale log. It carries `margin:auto`
explicitly — a modal `<dialog>` is centred by that UA rule, and the `*{margin:0}`
reset at the top of `map.css` had been taking it away and pinning the panel to
the top left.

Every change, in the dialog and in the drawer list alike, uses the **moderator
cards' from-to treatment** (`.cc-mod-was` / `.cc-mod-now`): the old value struck
through and quiet, the new one in the site orange. One vocabulary for "this
became that", wherever a reader meets it.

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

**What Wikipedia and Wikidata know, fetched on first open (2026-09-07).**
Owner: "the first time a town is shown in the drawer we see a spinner and it
fetches text and images from wiki", the way the coverage photo already does,
"and of course cycling related knowledge for that town". The "community notes,
none yet" placeholder that stood there since the demo is gone; nothing was ever
behind it. A Photon hit now carries the element ref Photon named
(`osm_type`+`osm_id`, `search-ui.js`), and a card opened from one shows a
spinner slot that polls `GET /map/town/{node|way|relation}/{id}?lang=&lat=&lng=`
(`TownController`, no-store, the coverage photo's poll and admission budgets).
The first reader claims a `town_summary` row for (ref, language) and queues
`ResolveTownSummary`; its handler makes four server-side, identified requests:

1. OpenStreetMap, the element's `wikidata` tag (`OsmElementApi`). No tag: an
   answered, empty row. Kept, so nobody asks twice.
2. Wikidata `wbgetentities`, the page titles in the reader's language and
   English (`CommonsApi::entities()`).
3. Wikipedia REST `page/summary`, the first paragraph, plain text, clipped at
   a sentence near 700 characters (`CommonsApi::pageSummary()`). The reader's
   Wikipedia wins when it has the page; English is the fallback;
   `page_lang` records which was served. Text is CC BY-SA 4.0 and the card
   says so beside the "Wikipedia ↗" link.
4. Two Wikidata claims, `wbgetclaims` each (`CommonsApi::townFacts()`, owner
   2026-09-08): inception (P571) and the newest dated population (P1082, by
   its point-in-time qualifier; a preferred-rank claim wins a tie; an undated
   count, or one older than `CommonsApi::POPULATION_MAX_AGE_YEARS` (25), is
   dropped). Shown as a two-line list above the paragraph, "Founded · c. 1200"
   and "Inhabitants · 565,039 (2024)", each line only when the sources have
   it; Antwerp has no inception anywhere. Precision travels with the
   year: century and decade precisions (7, 8) read "c.", finer ones read the
   year, and a negative year reads "BC" in the reader's language.
4a. **The inhabitants line falls back to Wikipedia's infobox**
   (`CommonsApi::infoboxPopulation()`), on the article the reader is already
   being shown, and only when Wikidata returned no count at all.

   **Measured before it was built** (2026-09-12). A survey of 100
   OpenStreetMap places carrying a `wikidata` tag, ten per country across ten
   countries on five continents, stratified two cities / four towns / four
   villages:

   | source | all 100 | villages |
   |---|---|---|
   | Wikidata, as the card used it | 66% | 47% |
   | with Wikipedia filling the gap | **89%** | **82%** |
   | GeoNames instead of Wikipedia | 76% | 55% |

   Three findings decided the shape. First, **the 25-year gate is not the
   problem**: only 3 places in 100 were blocked by it, while 30 had no P1082
   at all, so the gate stays. Second, **GeoNames was rejected**: it barely
   moves villages, and it disagreed with Wikipedia on a third of the places
   where both had a number, its snapshot not being refreshed per
   municipality. Third, **Wikidata must keep winning when it has an answer**,
   because the two sources do not always count the same thing: Rwandan
   districts and their namesake towns appear under one name, 319,141 against
   82,797, and a fallback that could overrule the structured value would put a
   district's headcount on a village card.

   Eight of the hundred have no population in any source, half of them in
   Rwanda, where only 34 places in the whole country carry a Wikidata link.
   Zwaag, the village this was reported against, is one of the eight: its
   Dutch article has no infobox at all, so nothing here rescues it. National
   statistics offices are still the source that would
   (docs/plans/2026-09-08-town-knowledge-sources.md).

   The read is section 0 of the rendered article, not the wikitext: de
   holds its number in a `Metadaten Einwohnerzahl` template and nl and ja pull
   theirs from Wikidata, so only the rendered table has the resolved figure in
   every language. Three shapes it must survive, each of which broke an
   earlier reader: a label that stacks a count and a density over one cell
   (nl), `Kaufkraft je Einwohner` sitting above `Einwohner` (de), and a
   `Population (2021)` header whose number is on the `• Total` row beneath it
   (en). Failure costs the card its inhabitants line and nothing else.

4b. **The recommended routes that pass through the town** (`TownRoutes::near()`,
   known issue 2026-09-06: "the Westfriese Omringdijk for Hoorn, the Great
   Divide for Banff"). Ours, not Wikidata's: the race list below says what
   happened here, this says what a rider can ride from here, and finding it
   needs geometry rather than a claim. Served rows within
   `TownRoutes::THROUGH_M` (1 km) of the town's OpenStreetMap node, nearest
   first, six at most, each linking to `/map?route=<id>`.

   Read fresh on every open, never cached into `town_summary`: that row is
   keyed by (ref, language) and settled once, which is right for a Wikipedia
   paragraph and wrong for a layer riders add to, where a route proposed today
   would wait behind a cache with no expiry. One index scan on a table of
   thousands. The radius is measured from the node at the town's centre, so it
   is loose enough to take in a bypass along the edge and tight enough to stop
   before the next village.

5. The Wikidata query service, once: every cycling race or route that starts
   (P1427), finishes (P1444) or passes (P2825) here, grouped by the race its
   editions are instances of and kept only when that race is a kind of cycling
   race, which is what drops the generic "plain stage" rows. An edition that
   is part of a bigger one (P361) groups under that one's race instead, when
   that is a stage race, a Grand Tour, a world championship or a national
   championship: the men's, women's and under-23 road races of the 2021
   Worlds are one line, counted as one edition, and a Tour de France stage
   reads "Tour de France, a stage starts here" (owner: "you can combine
   these, they are all the same year"). A season series such as the UCI World
   Tour is not such a parent, so a Tour of Flanders stays itself. Every discipline
   counts: the sport is any subclass of cycle sport (Q53121), so road, gravel,
   mountain bike, cyclo-cross, track and BMX all qualify (owner: "not only road
   cycling"). Signed cycling routes (Q102307360) and mountain biking routes
   (Q71716093) that name the place list as themselves, though few carry start
   or end points on Wikidata: the Great Divide has none, so Banff shows no
   route yet. Labels and links come from one more `wbgetentities` batch. The
   card shows up to eight, newest last edition first, as "Tour of Flanders,
   starts here, 21 editions, last 2025". This arm failing costs the town its
   list, not its paragraph; the query service is the slowest of the four
   (7 s for Antwerp on 2026-09-07, 45 s cap).

The photo is the P18 path every scenic POI takes (`ResolveWikidataImage`,
then Commons, then our own storage, never a hotlink): the handler claims the
QID when it has a continent, and the endpoint reports the photo's state beside
the text, so the paragraph shows as soon as it is there and the picture lands a
few seconds later through a second bounded poll (`watchJson` in
`commons-photo.js`, the generalised coverage-photo poll).

**Report it, and write over it (owner 2026-09-08: "there should be an
exclamation mark so people could report the text").** The card's credit line
ends in a small "!" that opens the one report door, `/report/town/node-59518`
(`ReportTarget::Town`, id = the element, never a language: the report is
about the town). It lands on the reports desk like every other kind, and the
desk's "open target" link goes to the curator's pen, `/moderate/town/{type}/{id}`
(`ModerateTownController`): one box per language, the fetched paragraph shown
above it, save per language. Saving calls `TownSummaryRepository::overrideText()`:
the row becomes answered, `edited_by`/`edited_at` are set, and from then on it
is LOCAL, "we can't connect to online anymore": the fetch never claims an
answered row, and any future refresh sweep must skip `edited_at IS NOT NULL`.
The endpoint carries `edited: true` and the card's credit reads "Edited by our
curators, after Wikipedia CC BY-SA 4.0" (a rewrite of CC BY-SA text keeps its
attribution; the Wikipedia link stays). Riders do not edit directly: the "!"
is their pen, and a curator writes. Pinned by `ModerateTownControllerTest`.

The five hand-written
`CITIES` blurbs keep precedence: a card with `meta.info` never polls. No page,
no races, no photo are answers, recorded; only a source that did not reply
releases the claim so a later reader asks again. Pinned by
`ResolveTownSummaryHandlerTest`, `TownControllerTest`, and the `watchJson`
cases in `tests/js/commons-photo.test.mjs`. Not built: routes through a town
from our own routes layer, which needs geometry, not Wikidata.

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
  policy forbids autocomplete. **And nothing else calls Nominatim either** —
  as of 2026-08-09 the codebase makes zero Nominatim requests. The region
  boundary moved to our own endpoint, and the add-climb wizard's last call
  (which geocoded the hardcoded string "Wallonia" on every page load, wrong on
  a worldwide wizard) was deleted rather than proxied (the wizard itself
  followed on 2026-08-25; `improve.js` never made that call). Distributed browser
  calls could never have honoured a per-application rate cap; the only way to
  respect the policy at scale was to need it zero times. Photon's host is in
  the CSP `connect-src`; Nominatim's is not, because there is nothing to
  allow.
- Debounced (350 ms; the local index re-ranks at 150 ms — both `setTimeout`
  constants in the map.js search block), ≥ 3 chars, one in-flight request
  (stale ones aborted), bbox-biased to the region, filtered to place types
  (`place:city|town|village|hamlet|municipality`), capped at 6 with local
  quick-picks winning over their Photon twin.
- **Names in the reader's language** (owner-reported 2026-09-07: Dutch UI,
  "Antwerp" in the list). Photon takes `lang`, and speaks only `default`,
  `de`, `en` and `fr`; `util.js photonLang()` maps the page language to one
  of those, and every language Photon lacks (nl, es) gets `default`, the
  place's own `name` tag, which is "Antwerpen" for a Dutch reader and
  "Anvers" for a French one. The town card, its Wikipedia lookup and the
  drawer title all carry that name. Pinned by `tests/js/photon-lang.test.mjs`.
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
then items grouped by catalog letter (A–G, N–R) with colour chips, **total cap 30**
(`CAP` in map.js `runS()`). Prefix matches rank above substring matches. The
flat `sMatches` list preserves display order so keyboard navigation
(↓/↑/Enter/Escape, `role="combobox"`/`listbox`) is untouched by grouping.

**IME rule (hard-won):** `aria-expanded` is written **only on real open/close
transitions, never per keystroke** — mutating an attribute of the element being
IME-composed restarts composition on Android Chrome, re-anchoring the caret at
0 and reversing typed text ("spa" → "aps").

### 7.4 A pasted coordinate pair (2026-09-12)

Right-click on the map copies the spot as `52.367612, 5.239157`
(`initCoordPopup`, map-init.js). The search box reads that same text back, so
the two halves of one gesture meet: copy a point, paste it anywhere, get back
to it.

- **One parser, no second copy.** `web/assets/contribute/coords.js` registers
  `window.Cc.parseLatLng` / `formatLatLng`; the map page loads that same
  classic script (as it already does `contribute/media-upload.js`) and
  `search-ui.js` reads it off `window.Cc` at call time. Accepted forms are the
  wizard's (moderation-and-contribution.md, place-search bullet): `lat, lng`,
  space- or `;`-separated, `52.3676°N 5.2392°E`, `N52.3676 E5.2392`, and
  `geo:` / `@` prefixes. Latitude must be −90..90 and longitude −180..180.
- **The order written is the order read.** A bare pair is latitude first. A
  lat/lng swap is never guessed, because `5.239157, 52.367612` is a real point
  at sea and a wrong guess drops the rider in another country. Only a
  hemisphere letter may reorder the pair.
- **A pair never reaches the network.** `runPhoton()` and
  `runCoverageSearch()` return early on a parsed pair and abort whatever is
  still in flight, so a name lookup started one keystroke earlier cannot land
  its towns on top of the point.
- **Presentation.** One row in its own `Coordinates` group at the very top,
  above Scopes, labelled with the six-decimal normalized pair and
  "Go to this point" (`d_coordinates`, `d_go_to_point`). The row carries no
  community tier tag, and the widen / "Search everywhere" row is suppressed: a
  wider reach cannot add a hit to a point that is already exact.
- **The card says "Coordinates", not "Town".** `openPlace()` takes
  `meta.point`, which swaps the badge glyph and colour; the colour itself is
  `COORD_COLOR` in util.js, read by both the search row and the card so the two
  cannot drift apart.
- **Picking it is `openPlace()`**, the same opener a geocoded town uses: the
  camera flies there, the place card lists what the Commons holds nearby, and a
  point outside the current scope widens transiently (`persist:false`, §4.5).
  The rider named the coordinate, so it is never filtered away by scope.

Pinned by `tests/js/coord-search.test.cjs`.

## 8. Deep links

Handled in the map `load` handler; all query-param based (no hash state — §13.1
is the pending permalink contract):

| Param | Behaviour |
|---|---|
| `?feature=<name>` | exact-name match over `CATALOG` features: activates the layer if hidden, opens the drawer, flies to the pin. The profile-card → map contract. Falls back to one unscoped coverage search when the local index misses. |
| `?ref=<osm ref>` | one coverage POI by its OSM id (`node/462149319`, `way/…`). Resolved by a single `/map/coverage/poi/{osmType}/{osmId}` call, which is the only thing that knows the letter and the coordinates; widens the scope on its own hit, like `?feature=`. |
| `?item=<id>` | one catalog item by DB id: the desk's "what did I approve" link and the drawer's share link. Opens the drawer, flies to the pin. **Lifts the view mode** when the rider's own mode would not draw the target: to the lowest rung that does (Confirmed before Everything), for this visit only, never persisted, with a toast naming both modes (`liftModeFor`, panels.js; the rung rule is `modeShows` in filters.js, the same predicate `featureVisible` reads). `?feature=` and `?route=` lift the same way. Owner decision 2026-08-25: a curator approved a climb, opened the link in their own Best of mode, and found the halo over an empty map, because the climb was Verified but not a pick. |

**Both id params may carry a readable tail:** `?item=482/cote-de-wanne`, `?ref=node/462149319/roche-aux-faucons`. The id is everything before the first `/` after it (`idFromShare` / `refFromShare` in `share-links.js`); the slug is discarded on read. A renamed place, a hand-trimmed link and every bare-id link already sent out all open the same point. Slashes stay unencoded in the query value, because a `%2F` in the middle defeats the reason the slug is there.
| `?pending=<id>` | curator deep link from the /moderate queue: activates the ⚑ layer, opens the submission drawer |
| `?route=<id>` | opens that R route **selected** (curator Routes desk link): lifts the view mode like `?item=` (above), then highlights + shows the curator corrections overlay. The reveal pin (§12) stays as the fallback when the target is still not drawn. |

The same lift applies to a ride-check row (§9), commons places and followed routes alike: opening a listed place the rider's mode hides lifts to the lowest rung that draws it, exactly as `?item=` does. Clear undoes a ride's lift (§4.2).

**A coverage point opened by ref names its type like a click does.** `?ref=`, a coverage search hit and a ride-check coverage row open the drawer from the detail response, which carries tags but not the tile's `t` label, so the Type row fell back to the layer name ("Getting there" for the Enkhuizen - Medemblik ferry, owner-reported 2026-09-15). `paintCoverageDetail()` (coverage.js) then reads `t` for that `ref` out of the coverage tiles (`readTileType()`, `tileTypeLabel()` in osm-tags.js) and re-renders the drawer body with it: "Ferry", "Train station". Tiles are only fetched for a source a visible layer uses, and the point's layer is often off or hidden by the mode, so the lookup adds a zero-radius, filtered circle layer per candidate source-layer (the point's country and `zz`, `coverageSourceLayers()`) until it ends. It waits for the source's `sourcedata` events, never a timer, and ends on a hit, when a newer drawer render supersedes it, or when the map goes idle with no hit (the rider moved on before the tile arrived; the drawer keeps the layer name).

### 8.1 "Add a climb here": the map is a starting point, not only a reader

*(owner asked 2026-08-14: "how do we add a new climb via the map as a normal
user". The answer was that you cannot. Every other letter had a bridge: a
surface line has click-to-Edit, an item has "Edit this item", a coverage POI
opens the wizard with its ref and geometry seeded, while a climb had only the
`/contribute` link in the map-top box. A rider had to leave, find the wizard,
and then re-locate the climb from scratch in its own small map having just been
looking straight at it.)*

- **Where:** a `.grp.cc-addclimb` block in the Ride tools panel, under ride-check,
  inside the same `ROLE_USER` gate. The target, `/improve`, is `ROLE_USER`, and
  a link that lands on a login wall is worse than no link; anonymous riders
  reach it through `/contribute`, which lists it.
- **Target (since 2026-08-25):** `/improve?type=climbs&mode=add&lat=&lng=&z=`,
  the climb add arm of the one contribution form
  ([edit-items/N-climbs.md](edit-items/N-climbs.md)). The dedicated `/add-climb`
  wizard was retired that day; `/add-climb?lat=&lng=&z=` still answers, as a
  **301** to the same target, so old links and bookmarks keep working.
- **What travels:** the **camera only**. `panels.js` `initAddClimbHere()`
  rewrites the href on every `move`, keeping the link's own query
  (`type`, `mode`) and setting `lat`/`lng`/`z`, coordinates rounded to
  5 decimals (about a metre). Rewritten on move rather than captured at load,
  because the rider pans while deciding.
- **What does NOT travel:** the foot pin. Seeding it from the map centre was
  the tempting version and it is wrong twice: one point cannot say which end of
  the climb it is, and a pin the rider did not place is a claim they did not
  make. This is the same rule the wizard's own paste-a-coordinate path already
  follows.
- **Server side:** validation lives in the redirect.
  `ContributeController::addClimb()` (the `/add-climb` route) forwards `lat`,
  `lng` and `z` only when latitude/longitude are numeric and in range; junk
  drops both (a junk link degrades to the wizard's own default centre, never to
  a broken map), and zoom is **clamped** to 3..18 rather than rejected, because
  a bad zoom in an otherwise good link should not throw the coordinates away.
  `improve.js` honours `?lat=&lng=&z=` on its side (the same range check, `z`
  clamped to 3..18 again) and opens the map at that view. Until 2026-08-25 this
  was `ContributeController::startView()` returning `{lat, lng, zoom}` or `null`
  into `window.CC_CLIMB_VIEW` for `add-climb.js`; both are gone.

## 9. Ride-check ("what's along my GPX?")

Anyone uploads a GPX and sees the catalog items inside a chosen corridor of
the track — no account needed. It is the one tool that answers a question
before a rider has any reason to trust us, so it is the wrong place to ask
for a sign-up first. **Read-only indication — the GPX is parsed in memory,
answered, and discarded; nothing is ever persisted**, and the UI carries a
persistent notice saying so (`ride_check.notice`, an explicit user
requirement).

- **Endpoint:** `POST /map/ride-check` (`RideCheckController::check()`).
  Open to anonymous callers; stateless CSRF token id `ride-check`
  (`config/packages/csrf.yaml`). Two sliding-window limiters, never sharing a
  budget: `ride_check` at 20/day keyed `user-<id>` when signed in, and
  `ride_check_anon` at 5/day keyed on a salted hash of the caller's address
  (`RideCheckController::anonKey()`) when not. The anonymous refusal names the
  limit, why it exists and that an account raises it — a bare 429 teaches the
  visitor nothing. Limits in the inventory:
  [security-architecture.md](security-architecture.md).
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
  row per `ST_*` call. Letters **B–G and N–Q only** (the SQL excludes A; R is absent
  because routes live in `recommended_route` and get their own overlap query).
  States gated by `ItemState::servedSqlTuple()`. Per match:
  `ST_Distance` (metres off-track) and
  `ST_LineLocatePoint(track, ST_ClosestPoint(…))` (fraction → km-along, the
  ordering key). Cap `MAX_PER_LETTER = 200` per letter with a `truncated` flag.
- **Coverage arm (open POIs along the ride)** — a parallel `coverage` result
  (`RideCheckService::corridorCoverage()`) runs the *same* MATERIALIZED
  corridor over `coverage_poi`, limited to utility letters
  `COVERAGE_LETTERS = {B, D, F, G}` (water, bike services, transport, shelter).
  **Minus every point a served item claims**, by the one claim rule the map's
  tile dedupe uses (`App\Catalog\ClaimedOsmRefs`, which
  `CatalogProvider::curatedRefs()` also reads): a served item claims a ref
  through its `source_ref` (an OSM-materialized row that is more than an
  untouched import), a way its segment spans, or its `osm_ref` (the OSM twin of
  an authority row, e.g. a registry tap). A place shows once, in the commons
  arm, never in both. `coverage_poi` and `item` are co-located on CC's own
  cluster, so the claim stays a local subquery.
- **The commons arm lists what the map payload serves:** besides the served
  states, `corridorGroups()` drops an untouched OSM import
  (`CoverageRetirement`, its point is the coverage tile's) and a row reported
  gone (`GoneRows`), the same exclusions as `CatalogProvider::itemRows()`. Same grouping/ordering/`MAX_PER_LETTER` shape as
  the curated arm (shared `groupByLetter()`). Coverage items additionally carry
  their `ref`: `id` there is a `coverage_poi` row id and no endpoint accepts one,
  so the `ref` is what lets a result row be opened at all
  (`/map/coverage/poi/{ref}`). The curated arm addresses items by id and carries
  no `ref`.
- **One drawing path** (owner decision 2026-09-15). `ride-check.js` draws
  only the track (`ridecheck`, `ridecheck-case`) and steers the normal map;
  every place it lists is drawn by the map's own renderers, under the map's
  own rules (scope, view mode, OSM-twin dedupe), and opened by the map's own
  click handlers.
  - **Commons rows.** A pool place is a leaf pin for as long as the ride is
    loaded (§5, `setListedPlaces()`), so none folds into a count bubble along
    the track. Hover rings the place's own spot (`highlightAt`), on the pin body
    (`[0,-16]`) only when a bottom-anchored pin is really drawn there
    (`poolPinDrawn()` for pools, `featureVisible()` for other layers,
    `ringOffset()` in `ride-places.js`), otherwise on the exact point. Click
    lifts the view mode with the deep-link rule (§8, `liftModeFor` and
    `modeToShow`: the lowest rung that draws the place, this visit only, with
    the toast; nothing moves when the current mode already draws it), then
    opens the place through its index entry, which turns its layer on.
  - **Coverage rows.** A listed point is the normal coverage tile icon. Hover
    rings its exact spot. Click turns its layer on, lifts the view mode the
    same way (a coverage point carries no confirmation, so `modeShows` judges
    it as `{}`: Confirmed lifts to Everything, Best of and Everything already
    draw utility coverage), and opens it through `openCoverageByRef()`, whose
    `cov-sel` overlay keeps the icon drawn at every zoom. There is no ride
    overlay of icons: in a mode that hides coverage the rows are a list until
    one is opened.
  - **A place the rider's own filter chips hide is shown anyway** (owner
    decision 2026-09-15): that one place, drawn by its normal renderer, for as
    long as its drawer is up, with the toast "Shown anyway · your filter hides
    this place" (`d_toast_shown_anyway`). The chips do not change. When the mode
    lifts too, one toast carries both reasons ("Shown in Confirmed · Best of
    hides this place · your filter hides it too", `d_toast_filter_too`). The
    rule and its release are in §4.3 (`openListed()` in ride-check.js,
    `showPlaceAnyway()` in render.js). Followed-route rows open the same way.
  - In the drawer, coverage is a separate section under its own heading with a
    provenance note, so uncurated OSM never reads as a verified Commons pick.
  - **Clear** removes the track, releases the listed set (the pools cluster
    every place again) and the place shown anyway, and restores the rider's
    view mode and scope. The mode goes back to the one from before the ride's
    first lift; a mode the rider picked while the ride was loaded stays
    (`createRideModeMemo()`, §4.2). Neither restore is persisted.
- **The ride sets the scope while it is loaded** (owner 2026-08-23) —
  `check()` also answers **`regions`**: every *operational* region the track
  intersects (`RideCheckService::crossedRegions()`, `OperationalRegions`
  predicate so level-2 country outlines never appear), ordered by where the
  track first enters each. `assets/map/ride-scope.js::rideScopeFor()` turns
  that list into a scope, and `ride-check.js` applies it **before** its own
  `fitBounds`, so the ride's framing still wins:
  - one or more regions in **one country** → a `region` scope holding **all**
    of them. §4.5's scope is a set of region ids, so a ride across three
    provinces keeps every kilometre inside the scope rather than picking a
    winner and leaving the last stretch unscoped.
  - regions in **two or more countries** → the same `region` scope holding
    every crossed region, with no single `countryCode`. A region scope is a set
    of ids and may span a border, so the ride stays whole without widening to
    Everywhere.
  - **no regions** (a ride outside every onboarded country) → the rider's scope
    is left exactly as it was; there is nothing better to move it to.

  **Never persisted** (`set(..., {persist:false})`): Clear puts the rider's own
  scope back, and a new session starts from theirs, not from a ride they once
  looked at. The move is **said out loud** — the scope header gains
  `· from your ride` (`map.rc_scope_from_ride`), and a multi-region scope reads
  `Vaucluse +2` rather than naming the first region as if it were the whole
  scope (`CCScope.label()`). One caveat carried knowingly: restoring through
  `set()` retires `isDefault()`, so a rider who had never chosen a scope is
  treated as having one after their first ride-check.
- **Route-overlap "follows" floor:**
  `max(ROUTE_MIN_OVERLAP_BASE_M = 300, 2·radius + 100)` m of shared length —
  a fixed 300 m fails at larger radii, where a mere perpendicular crossing
  yields ~2×radius of overlap inside the buffer.
- **UI:** track draws as a distinct dashed dark overlay with drawer-aware
  `fitBounds`; results render in the **standard right-hand drawer** (town-card
  styling: per-letter group headers, "name — km 23.4 · 80 m off" rows, followed
  routes with shared km, Clear button). **Closing the drawer keeps the track
  overlay**; the panel status shows "X km · results · clear" to re-open or tear
  down. While a ride is loaded, every place drawer (opened from a row or by
  clicking a pin or icon on the map) starts with a **‹ Ride summary** button
  that brings the summary back (`setDrawerReturn` in `drawer.js`, drawn by
  `renderDrawerBody`, `d_ride_summary`); Clear removes it (owner 2026-09-15).
  Radius change re-posts; a new file replaces the previous overlay.
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
| mapillary-js fails to load | dock closes; popup "Viewer failed to load." |

- **CSP companion:** `'unsafe-eval'` scoped to `/map` exists solely for
  mapillary-js (§2). It is vendored same-origin like every other library
  (security-architecture.md §2.5), so there is no CDN in the policy; the
  `'unsafe-eval'` grant is what remains, and it is scoped to the one page.

## 11. Ride heatmap (no letter) and the illustrative planner

**The heatmap is HIDDEN as of 2026-08-31**, and the planner's code is DELETED.
The rest of this section describes what is behind the comments, because putting
the heatmap back is uncommenting two blocks and nothing else.

- **Hidden until there are rides to draw (owner 2026-08-31).** With a handful of
  contributed routes a heatmap does not read as a thin feature, it reads as a
  wrong one: a few riders' habits drawn as if they were where people ride, which
  is a claim the data cannot support and the kind a rider would plan around. Two
  Twig comments, not deletions: the heading, the On/Off toggle (`#heattoggle`)
  and the season chips (`#season`) in `map/index.html.twig`, and the whole
  **derived** category on the contribute hub, heading included, because the
  heatmap was the only card in it and a heading over an empty grid reads as
  broken rather than as coming. Nothing else changed: the layer, the season
  facet and the scope filter all still work, and everything that reads those
  elements does so through `querySelectorAll` or a null check, so their absence
  is a no-op. Every string stays in all five catalogues. What it needs before it
  returns is in docs/TODO.md, and the first item is the hard one: somebody has
  to pick the number of routes that counts as enough, defensibly, because that
  threshold is partly a privacy question.
- The ride heatmap is a **derived overlay** (never a catalog entry, never editable,
  and it carries no catalogue letter — see the items table in
  [edit-items/README.md](edit-items/README.md)): its own
  panel with On/Off + season chips (All/Spring/Summer/Autumn/Winter), default
  **Off**. Season chips set a layer `filter` on the per-point season tag; a
  chip selected before the layer exists is honoured on first build.
- The heatmap source/layer is built **lazily on the first On** — thousands of
  points allocated at load for a default-off layer was pure startup cost.
- Heat data is derived at build time from sample GPX (downsampled ~1 point /
  110 m, served as the map's heat layer); raw `.gpx` files never ship. The
  real anonymized-ingest heatmap (map-match-then-discard, k-anonymity) is
  unbuilt; its privacy contract lives in the public wiki data catalog and gets
  its own spec when built.
- The **"Plan from Spa" planner is off the map chrome as of 2026-08-02** (owner
  decision; §4.3's chip groups came back the same day, the planner did not). It was openly faked: distance chips
  picked the nearest sample loop by km, drew it, and opened a drawer carrying
  the warning "⚠ Faked — the real planner stitches from the heatmap" — honest,
  but a control that looks like a planner and is not one. The real planner, and
  starting it from the rider's position, remain deferred; Locate me (§4.0) only
  centres the map.
- **The planner's code was deleted on 2026-08-31.** For four weeks after the
  control went, `planner.js` was still imported, still called, still
  `modulepreload`ed on every map view, and still shipped ten translated strings
  in five languages for a drawer nobody could open. `initPlanner()` queried
  `#planner .chip`, got an empty list from an element that is not in the markup,
  and did nothing. `scope-ui.js` still listed two layer ids nothing creates.
  Fifty-three lines of module, one preload, two dead ids and fifty catalogue
  entries, all removed; each of the ten strings was checked to be used by that
  module alone. The reasoning was already written here and in the map template's
  own comment, so this only finished the job the 2026-08-02 decision started.
  The frozen pre-Symfony demo at `atlas/demo/map.html` keeps its own copy, which
  is what a frozen snapshot is for.
- **Ride privacy:** route/heat fixture tracks have their first and last
  ~350–750 m trimmed (`trimEnds()`, **seeded by route id** so the trim is
  deterministic per route — located-correction fractions are stored relative
  to the trimmed path). The canonical end-trim rule for real route intake is
  owned by [route-domain.md](route-domain.md).

## 12. Community tier

**The marker grammar this tier is drawn with is specified in
data-provider-hierarchy.md §6.7** (owner ruling 2026-09-09, built
2026-09-10): the border answers custody (small disc for a gross provider,
dashed for a specialty provider, solid for ours) and the badge answers evidence
(`?` means nobody has stood here on record). The paper dot that marked verified
is removed there, because absence of the `?` already says it on every tier.

Approved 2026-07-15; implemented via
[coverage-provider.md](coverage-provider.md) (its §6 rebases these decisions
onto the tile/endpoint data source; the `verified` flag derives from real
canonical state: `CatalogProvider`'s `v:1` is `state = 'verified'` and nothing
else, never the simulated `c` attribute). **One definition of verified**, ruled
2026-09-09 ("we must draw 1 line else everything gets too confusing"): the map,
the public API (`PublicItemsProvider`) and the Best-of readiness gate
(`CuratedReadiness`) all read that one column, so a `?` disappearing from a pin
means the record itself was promoted and a curator sees the same thing a rider
does. Before this, three queries each carried their own extra clause and a
single confirmation drew a full pin over an Unverified row. How that state is
earned is `ItemConfirmationService`'s question, not this one, and is specified
in moderation-and-contribution.md §10. An authority row (a Tourisme Wallonie stay, a RIVM tap) wears the
dashed "?" community pin until a rider confirms it: the register's authority is
its rank (data-provider-hierarchy.md §4), not a dot, and the harvester writes
new rows `unverified` (its §5 rule 6). Ruled 2026-09-06, the night the first
RIVM harvest drew 3283 taps nobody here had seen with the plain pin; pinned by
`CatalogProviderTest::testAuthorityRowsAreNotVerifiedByProvenanceAlone`.

**Three tiers, the pin legend's three, and the same three in every list**
(owner, 2026-09-06: "it is our community that confirms; it confirms OSM or
RIVM or PIVOT and at that moment it becomes our community"). "community" is a
row our community has CONFIRMED (`verified`), whatever put it there first;
"unconfirmed" is a row nobody here has checked yet (`verified === false`, a
rider's or a register's); "OSM" is the imported baseline the catalogue does
not hold. The legend's middle pin is therefore headed "Unconfirmed", not
"Community, unconfirmed". A provider is a source, not a tier: the record
panel names it. For that to hold, the item index carries UNNAMED items too
(name = the layer's label, `unnamed` set, off text search): before, an
unnamed registry tap reached the nearby list only through its OpenStreetMap
twin, at the twin's position (up to `OsmLinker::TIGHT_M` off, so the hover
highlight missed the pin) with no tier at all. Every leaf pin is a
bottom-anchored teardrop, confirmed or not, so the highlight offset is the
pin body's for both.

**An OpenStreetMap row is tagged OSM, never community** (owner, 2026-09-06:
"Bakkerij Otten is said to be community but it is OSM"). In the nearby list
and the search results, "community" is a rider's work not yet confirmed
(`verified === false`, or a rider source); a coverage row the catalogue does
not claim wears "OSM" instead. Both sit after the confirmed rows and share
the capped second tier, since both are the crowd's, but they are not the same
crowd. And a scope hit in the search stays inside the country on the map
unless the Everywhere reach is on: a search in the Netherlands does not
surface a French region.

**`state` never reaches a rider in our words.** The item history renders the
`state` field as **Status**, and its values as what they mean to a rider —
`submitted` → "waiting for review", `unverified` → "on the map, not confirmed
yet", `verified` → "confirmed by riders", `rejected` → "not accepted". The API
keeps the raw values; only the drawer translates them. A history row is a
record of what changed and stays historic: what is true *now* is the
confirmation panel above it, which counts live.

**Once a rider has answered, the confirmation panel is one line.** The heading
drops the question ("Drinking water · 2 riders confirmed", not "Is the water
drinkable?"), and the only control is **change my answer**. What they answered,
where they answered it, and the stance buttons all sit behind that toggle. A
rider who has already answered came to look at the place: their own answer
restated over two live buttons is noise on top of it, and reads as being asked
again.

**A name deep-link must resolve the CURATED item, never its OSM twin.**
`resolveLocalFeature()` searches three places, in order: `CATALOG[].features`,
the PIVOT stays collection, and — since 2026-08-02 — the curated **pools**
(`osmLayers[key].data`, the letters served as feature collections). The pools
were the gap: a fountain's features live there and never in
`CATALOG[].features`, so `/map?feature=<name>` resolved nothing locally and
fell through to `openCoverageFeatureByName()`, which opens the tile-derived
OpenStreetMap record. A rider following "view it on the map" from their own
approved contribution therefore landed on a plain OSM point with none of their
name, photos or attributes on it, and reasonably concluded the submission had
been lost. `openPoolFeature()` opens the same drawer the pin's own click
builds, so deep link, search hit and pin click all show one record.

**One counted confirmation is the whole threshold.** A single
`item_confirmation` row flips a community dot to a full pin — there is no
"three riders" rule for items (routes have one, `routes.ride_verify_threshold`;
items do not). Rider-facing copy must say what actually happens: the
contribute wizard's lifecycle paragraph says "the first rider who confirms it
is really there turns it into a full pin", and anything promising "a few of
them" would be a promise the code does not keep. `source = 'form'` rows are
excluded from the derivation — that is the submitter's own answer, and
counting it would let a rider verify their own contribution
(moderation-and-contribution.md §6.3).

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
  the next pick, drawer close, or mode change. **Deep links are the exception**
  (owner decision 2026-08-25): a shared or desk link is an explicit ask to see
  one place, so `?item=` / `?feature=` / `?route=` lift the mode to the lowest
  rung that draws it, transiently and with a toast (§8). A search pick inside a
  session keeps the reveal pin, because the rider chose the mode a moment ago.
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
[edit-items/N-climbs.md](edit-items/N-climbs.md)); **Photon** for type-ahead
place search, and **no Nominatim at all** (§7.2); **our own Valhalla**, via
`POST /contribute/route` → `RouteSnapper`, for the climb editor's draw preview
(on `/improve`; the `/add-climb` wizard that first used it was retired 2026-08-25) —
the public OSRM demo server it used to call is gone from the CSP, and the
proxy keeps OSRM's response shape so the editor's parsing was untouched.
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
  resolve to the last one scanned. Superseded for coverage POIs by `?ref=`
  (map-and-search.md §8, 2026-08-24), which the share button now prefers;
  `?feature=` stays for catalog features and for links already in the wild.
- **Region boundary + default bounds are Wallonia-hardcoded** (`map.js`
  `addRegionBoundary('Wallonia')`, the boot `bounds`, and the Photon bbox) —
  worldwide readiness for the map shell has no owner yet beyond the coverage
  plan's Belgium-first staging.
- The **city blurbs in `CITIES`** are hand-authored English constants
  (untranslated, unsourced) for five Wallonia towns. Every other town gets the
  Wikipedia paragraph, photo and race list fetched on first open (§6.5,
  2026-09-07); the five could be dropped in favour of the same path.
