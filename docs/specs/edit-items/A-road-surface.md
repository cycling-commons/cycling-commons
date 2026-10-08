<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Edit spec — A · Road surface

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** A · Road surface
- **Map depiction:** line styled by surface class, icon `ItemType::svgPath()` (▰ text fallback), colour #4E8C84. Rendered **above** ride/climb
  lines so the surface (e.g. a gravel sector along a ride) reads on top.
- **Editable:** yes · the `/improve` wizard in segment mode (add, improve, confirm or correct an OSM way)
- **Lifecycle:** *utility / coverage* — verified (≥ X community confirmations) then shown; **never votable, never best-of** (value is completeness). Lives in **Everything** mode. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## What it is
Real road/path segments (RAVeL cycleway, forest gravel, pavé/sett) shown as lines coloured and patterned by surface, so riders can pick the right bike for the terrain.

## Location (add mode)
A surface entry is a **segment**, not a point: the first action is to tap the **start**, then the **end**.
(Contrast: most types drop a single pin; rides need none.)

**The line between them follows the road** (owner 2026-08-12). The two pins are snapped
with the same bicycle router the climb editor uses — `/contribute/route` → our
Valhalla — and the path it returns is what is drawn and what is stored. A
straight chord between two taps crosses fields, houses and the wrong bank of a
river; on a map that reads as a mistake, because it is one. Measured on the
hairpins above Francorchamps, the road wanders **563 m** from the chord.

Stored as `attributes.segment = {a, b, line?}`: `a`/`b` are where the rider
pointed, `line` is the road. `line` is **optional by design** — no route, no
router, or an older client all mean the chord, which is a worse shape but never
a wrong one, and the rider is told the straight line stayed rather than being
shown a road we did not find. The **item** geometry is the `line` when present.
Dragging either pin re-snaps (sequence-guarded, so a slow earlier answer cannot
land after a newer drag).

Server-side `line` is bounded, because it is otherwise a channel for drawing any
shape at all under a name and surface somebody chose: ≤ 3,000 points, every pair
a real coordinate, and **both ends within 1 km of the pin they snapped from**.
A segment may follow an actual ride — the demo includes a **gravel descent traced along the Spa · Sankt
Vith loop** near its end, so a ride's real road type can be recorded.

## Correcting or confirming OSM's answer

Most A segments a rider sees are not ours: they are the **OSM surface skin**, a
tile layer of every surfaced way OSM knows
([map-and-search.md](../map-and-search.md) §5). Clicking one opens the drawer
with the class, the raw `highway` value and a link to the way on OSM, plus two
actions that are the **same submission** one decision apart:

| Action | What it does |
|---|---|
| ✎ Edit this item | opens `/improve?ref=way/NNN&type=A&sa=…&sb=…&surface=<tile class>` — the add wizard in segment mode, on the map step |
| ✓ This is correct | the same URL plus `&confirm=1`, which opens on the **details** instead |

**Both prefill the surface.** What OSM says about the way is a *current detail*,
and the "Fix details" step exists to show current details — opening it with an
empty Surface dropdown would ask the rider to retype what the drawer just told
them (owner 2026-08-12). The difference
between the two actions is therefore not the data, it is the step: confirming
means the location is right too, so that step is skipped. The skip happens only
*after* the pins are placed — jumping to the details with an empty segment field
would fail at submit, which is the worst place to discover it.

There is no separate "agree with OSM" store. Confirming **mints our own A
item** carrying `way/NNN` as `source_ref` (materialize-on-edit,
[osm-data-architecture.md](../osm-data-architecture.md) §6); from then on the
ordinary one-tap item confirmation applies to that item, and the region's
moderators decide the submission exactly as they decide any other. Approved, our
line draws on top of the tile line; the tile stays underneath as OSM's answer.

**The wizard opens knowing where the stretch is.** The clicked way already has
ends, so `sa`/`sb` place both pins on it, draggable — the rider adjusts rather
than re-taps from a blank map. A wrong-but-close start beats an empty one.

## Run-chaining: one click prefills the whole unrecorded run

OSM splits a road wherever any tag changes, so a 16 km dijk is dozens of ways,
one of them 30 m, and a rider who does not know the pins can be dragged gives
up on a 30 m prefill (owner 2026-08-13). So a **to-do click prefills the whole
unrecorded run**: every same-named neighbour way, chained end to end
(`unrecordedRunEnds` in `web/assets/map/surface-tiles.js`).

- **The name is the join key** — no name, no chain (single-way fallback).
- **Endpoint proximity is the chain**: BFS from the clicked way over each
  candidate way's outermost endpoint pair, ~35 m tolerance (tile quantization
  means junction nodes rarely share exact coordinates).
- **The road-type group is the honest boundary.** `highway` values map to
  groups (main / local / residential / track / path / cycleway); the chain only
  crosses ways in the *same* group. Where a dijk road continues as a same-named
  cycleway the chain **breaks on purpose** (owner decision 2026-08-13): that
  part is car-free and deserves its own item with its own answers. tertiary and
  unclassified are both "a local road" and do chain.
- **Only unrecorded ways chain** — candidates come from the to-do source, so
  the run stops where somebody has already answered. A classified (skin) click
  keeps its own way (`fullWayEnds`).

**Every covered red dash retires, not just the clicked one.** The run's way
refs ride the wizard URL (`&srefs=way/…,way/…`, capped at 120), the controller
re-validates them (pattern `way/\d+`, dedupe, cap — malformed entries are
*dropped*, never fatal: they only retire dashes), and the materialized item
stores them as `attributes.waysSpanned` (allow-listed in `AttributeVocabulary`
— nobody types it, but it must survive). `CatalogProvider::curatedRefs()`
unions these spanned refs into the catalog's `refs` list, so the to-do dash of
every way the item covers stops contradicting the answer. Pinned by
`CatalogContributionServiceTest` (storage + validation + refs union).

**Seeing what you selected.** Opening an A drawer highlights the segment's own
path on the map (orange halo under the class line — `showSurfaceSelection` in
`web/assets/map/render.js`; cleared on drawer close), and the drawer's record
leads with a **Length** row (great-circle over the drawn path, rider's unit).
The wizard's readout, after a prefill, says the stretch is prefilled and that
**either pin can be dragged** to cover more or less of the road.

**With no prefill it asks for the START.** The Locate step's markup is
rendered with `improve.step1.readout_initial`, the *point* wording, so in
segment mode the wizard sets `readout_segment_start` at init, and
`readout_segment_end` after the first tap (`syncLoc()`). Both prefill paths
write their own readout on `load`.

## The wizard follows the road, not the router

A router answers the question it is asked: *fastest a→b*. On a long stretch
that is not the road the rider is pointing at: a single router call along the
Zuiderdijk reroutes the middle onto the parallel road under the dijk (owner
2026-08-13). These mechanisms keep the line on the road, all in
`web/assets/contribute/improve.js`:

**1 · Geometry-seeded prefill.** A map click that opens the wizard already
knows the road's shape: the run's tile fragments are stitched per way
(`stitchParts`) and walked end to end (`walkRun`) in
`web/assets/map/surface-tiles.js`, and the assembled path travels to the
wizard via `sessionStorage` (`ccSegSeed` — hundreds of vertices, too many for
the URL). When the seed's endpoints match the `sa`/`sb` the wizard opened
with (~20 m) and the seed is fresh (15 min), the drawn line IS the tile
geometry (leg src `seed`) and the router is never asked. When the walk
succeeds, `ends` and the spanned refs come *from the walked line*, so on a
fork (two same-named parallel roads) the item spans exactly the arm it draws.
Classified (skin) clicks get the same seed for their single way
(`fullWayLine`). A stale, mismatched or missing seed falls back to the
router.

**2 · Control points, and legs.** Right-click on the drawn line pins a
**control point** (owner design 2026-08-13): a small round handle, draggable
like the pins, right-click again to remove. There is no long-press touch
gesture (owner 2026-08-14): a gesture that works only sometimes teaches riders
the feature is flaky; touch uses the mode button below.

**3 · Grabbing the line itself** (owner 2026-08-31: "I do not drag the marker,
I want to drag the road"). Press the drawn line, drag to the road it should
follow, let go. A tap beside the line would not do it, because
`rightClickAt()` gives up beyond 35px of the line already drawn, and a parallel
road is always further than that.

Underneath it is the same two steps, deliberately: the grab calls
`rightClickAt()` to insert the control at the vertex grabbed (quietly, since the
toast belongs to a deliberate add and not to a point about to move), then runs
the marker's own drag-end work. One path, so a grabbed line and a dragged pin
can never re-route differently.

- **8px to grab, against the tap's 35.** You have to be ON the road you are
  dragging, or a press meant for the map starts reshaping it.
- **`dragPan` is disabled for the duration**, or the map slides out from under
  the drag.
- **The cursor turns to `grab` over the line**, because a thing that can be
  dragged has to look like one.
- **Mouse only.** Touch keeps the mode button below, which is what the help line
  describes and what works without a hover to hint at grabbing.

The help line leads with the drag and keeps the control point as the way to
pin a bend, which is the order a rider needs them in.

**Touch route: the "Add a point" MODE BUTTON** (owner 2026-08-16):
discoverable, works with any pointer, and never fights MapLibre for a
gesture. `#wzAddPt` sits beside
the help line, disabled until both pins are placed. Pressed (aria-pressed,
wearing the line's own colour like the Hide-line peek button), it arms the
mode: a tap on the line pins a control point through the SAME
`rightClickAt()` path as the pointer route, a tap on an existing point
removes it (the marker element sits above the canvas, so that tap never
reaches the map handler), and pressing the button again disarms. Two rules
carry the safety: while armed, a tap that MISSES the line does nothing -
falling through to `placeAt()` would let one stray thumb wipe the whole
shaped stretch - and losing the line (Reset, re-placing pins, the compact
confirm view) disarms and disables the button via `syncAddPt()` on every
`drawSeg()`. The help line swaps to `improve.step1.ctrl_point_armed` while
armed, so the active mode explains itself. Structural pins:
`web/tests/js/wizard-addpoint.test.cjs`. The stretch is
a list of **legs** between waypoints (start pin · control points · end
pin); each leg holds its own line (`seed`/`route`/chord). A drag recalculates
**only the legs touching the dragged point** — everything the rider already
shaped stays put. Removing a control point joins its two legs' lines as they
are (no re-route). The DOM `contextmenu` event is used, not MapLibre's
map-level one (the library withholds it behind right-drag-rotate
bookkeeping), with an 8 px guard so a right-drag's release never drops a
point, and the click must land within 35 px of the line. Because this is the
one gesture nobody discovers on their own, segment mode shows a **static help
line under the map** (owner request 2026-08-14): set a control point with
right-click, everything up to it keeps its shape on a drag, the same
gesture on the point removes it — always visible, not a toast that is
gone before it is needed (`improve.step1.ctrl_point_help`).

**4 · Trim, not re-route.** Dragging an endpoint to a position still on its
leg's existing line (~30 m) **trims** the line to that point and snaps the
pin onto it — covering less of a prefilled run costs zero router calls and
keeps the exact shape. Only a drag *off* the line asks the router, and only
for that leg.

The submitted `segment.line` is the joined legs, capped under the server's
3000-point limit (client caps at 2900; the seed itself is capped at 1200 at
assembly). The server contract is `a`, `b`, `line`; control points are a
client-side editing tool and are not stored. Undo snapshots
pins, control points and legs together.

**Editing an existing A item shows its stretch** (owner 2026-08-14).
`CC_ITEM.segment` carries the stored attribute, and the wizard opens with both pins placed and
the stored line drawn as a `seed` leg — the stored attribute's *own values*,
so an untouched edit reposts exactly what is stored, `INITIAL_GEOM` is
re-snapshotted after the hydrate, and no phantom geometry change is ever
recorded (pinned by `CatalogContributionServiceTest`). The other half is the
server: `submitImprove` merges the `segment` hidden field into the diff the
way it merges the climb shape (letter-gated to A), and on approve
`ModerationService::applyEdit`
**rebuilds the item's LineString** from the new segment (line when present,
a→b chord otherwise; pinned by `ImproveBindingTest`), because the map draws
from geom, and applying only the attribute would keep showing the old road. Probe note: the wizard map exposes
`window.__ccWizMap` (same convention as the map page's `__ccMap`), and
real-input events do not reach the wizard canvas under Playwright — verify
listeners with synthetic DOM events, real feel in a real browser.

## Names, road type, and what we are allowed to assume

**The tile carries the way's `name`.** The drawer headlines with the street
name and the class is its subtitle; the improve
link carries `&name=`, and the wizard opens with it filled in. Composition
happens at RENDER time, never in the tile: `name` is the OSM tag verbatim and
the class comes from the locale dictionary, so a Dutch rider reads
"Rue Saint-Géry · Verhard · asfalt". Gluing them upstream would freeze one
English word into a name field for good. Cost: the Belgium artifact went from
43 MB to **53.9 MB** (418,304 ways) — names are ~25%, and unnamed ways omit the
key entirely rather than carrying an empty string.

### One artifact, three countries, and no seam at the border

Belgium, the Netherlands and Luxembourg are built into **one** PMTiles archive
with a source layer per country (`surface_be`, `surface_nl`, `surface_lu`), the
same shape the coverage tiles use:

| | ways | |
|---|---|---|
| Belgium | 418,304 | |
| Netherlands | 796,815 | |
| Luxembourg | 50,916 | |
| **classified artifact** | **1,266,035** | **152.4 MB** |
| "needs recording" arm (same three) | 414,774 | 34.8 MB |
| gap grid (same three, 2,163 cells) | — | 0.6 MB |

**The "needs recording" arm is not "every untagged way"** (owner 2026-08-12),
and the reason is editorial before it is about bytes: every untagged way would
cost as much as the entire classified skin, and most of it would ask riders to
confirm what is already known. Of Belgian ways somebody HAS tagged, these are
the shares that turn out unpaved:

| highway | unpaved when tagged | in the to-do arm? |
|---|---|---|
| `track` | 88.2 % | **yes** |
| `path` | 50.1 % | **yes** |
| `unclassified` | 6.8 % | **yes** — the rural lane question |
| `living_street` | 20.0 % | no (owner call: not interesting to ride) |
| `residential` | 7.2 % | no |
| `tertiary` | 2.5 % | no |
| `secondary` | 0.5 % | no |
| `primary` | 0.1 % | no |
| `cycleway` | 0.0 % | no |

Mappers tag the surprising road first, so an *untagged* primary is even more
certainly asphalt than that 0.1 % suggests. Keeping only the unpredictable
classes makes every line in the arm a road where riding actually settles
something. The set is contract data
(`surface.todo.highways`), so widening it later is a one-line change plus a
rebuild — no code, no client release.

**The arm is ROUTE-AWARE** (owner decision 2026-08-12). The class gate above
has a second gate beside it, a union not a hierarchy: *an untagged way that
carries a signed route or node network is homework whatever its highway class,
because a rider will ride it BECAUSE it is signed.* The Zuiderdijk is the
worked example: untagged tertiary/unclassified ways carrying LF-ZZ plus rcn
segments. The way-id sets come from the routes extractor
(`pipeline/coverage/routes.py`, per-region `routes_<slug>_wayids.txt` in the
workdir), which is why `coverage.run --routes` runs before `--surface` when
rebuilding both. Widening `todo.highways` by class instead (adding `tertiary`
and `cycleway`) is the wrong key and would buy back most of the bytes the
measurement above saves. The gap grid counts route homework too, so the
squares and the lines answer the same question.

**Surface QUALITY has its own channel** (owner 2026-08-12). The classified arm's features carry `sm` (raw OSM `smoothness`,
gated on contract `surface.quality.values` — an unlisted value is dropped at
extract time, never guessed into a bucket) and `mtb` (`mtb:scale`), both
omitted when absent. The client draws short coloured ticks over the class
lines (green → amber → red, z13+, `surfq-*` layers), **only where `sm`
exists** — no tick means nobody has said, the same honesty rule as the red
dotted line — and the legend explains them in a note row that is not a filter.
The drawer shows the value in the form's five-word vocabulary (OSM's eight
values collapse for display only; the raw tag stays visible when the collapse
was lossy) so the word a rider reads is the word the contribute form offers.
This does NOT change the styling rule below: smoothness is still never a
styling *key* — the class colour stays `surface=`-keyed, and the ticks are an
annotation stacked on top.

**The gap grid answers the same question for planning.** Per z12 cell (~6 km):
kilometres of unrecorded to-do network, its share of that cell's network, and a
road count. Kilometres rather than ways, because a way is an arbitrary unit — a
rural track runs unbroken for 3 km while a village lane is split at every
junction, so counting ways would make dense villages look like more work than
the gravel network around them. A cell where everything is recorded is omitted
rather than shipped as zero: a square drawn over finished work reads as "there
is something to do here", which is the one thing the layer must never say.

**Border crossings are not a problem, and it was worth checking rather than
assuming.** Verified at Baarle-Hertog/Nassau — the most tangled border in
Europe, where Belgian enclaves sit inside Dutch ones: 588 Belgian and 746 Dutch
lines render together in one viewport, from both source layers, with no gap
along the line. Geofabrik's extracts overlap slightly at borders, so a road that
crosses is complete in both, and the worst case is a double-drawn metre rather
than a missing kilometre.

**The archive is not extendible.** PMTiles is a single immutable file with its
directory written in one pass, so adding a country means re-running tippecanoe
over all of them — there is no append.

**So the per-country extract is cached**, which is the half worth
caching: an osmium pass plus a full node-location walk over a national PBF,
against a tiling run that reads GeoJSONL already on disk. A country is
re-extracted only when its `.geojsonl` is older than the PBF it came from **or
older than the tile contract** — the contract matters as much as the data,
because adding a highway type or a surface class changes what *should* be in the
extract while leaving the PBF untouched, and a stale file would then be tiled as
if it were current. An empty extract (what a killed run leaves behind) is never
reused: it would publish a country with no roads and no error.
`COVERAGE_FORCE_EXTRACT=1` is the hatch for a pipeline change no timestamp can
show.

### Why the names stay in the tile, measured

The alternative considered was a name table on our side, fetched when a rider
opens the drawer, keeping the tile lean. Measured before deciding, on the
Belgium extract:

| | |
|---|---|
| ways carrying a `name` tag | **78.6%** (157,259 of a 200,000-line sample) |
| name share of the raw GeoJSONL | **6.1%** |
| artifact, before → after | 43 MB → **53.9 MB** (+25%) |

PMTiles are gzipped per tile, so 53.9 MB is already the compressed figure. The
names cost more in the tile than in the raw data (25% against 6%) because vector
tiles delta-encode geometry into small integers and compress it hard, while
strings mostly do not.

**The 25% is nonetheless the wrong number to optimise**, and that is the
argument: nobody downloads the artifact. Tiles are fetched by range request, a
handful per viewport, so the cost a rider actually pays is the names *in the
tiles they look at* — and those are exactly the streets whose names they want.

A name table would trade that for a table of hundreds of millions of rows
worldwide, a request per drawer open, and a database on the one pipeline that
was designed to need none — `coverage_poi` is the point index precisely because
lines need neither SQL nor dedupe, which is what makes country-scale surface
data cheap. Copying OSM's names into our database also cuts against
`osm-data-architecture.md`'s rule that we reference upstream rather than
duplicate it.

**Worth doing instead, if the size ever bites:** carry `name` only at the top
zoom levels. The drawer opens at z14+ and the name is dead weight at z8, so the
saving is most of the 25% with no behaviour change. Not built — it is a
tippecanoe filter and a re-harvest, and the current figure is comfortable.

**Road type is editable.** The drawer shows OSM's `highway` tag, and a
read-only row would feel like a locked door, so the form offers six kinds a rider can tell apart from the saddle;
`App\Catalog\RoadType` owns the mapping and `RoadTypeContractTest` keeps the
map module's copy identical. **`unclassified` is not offered**: it is a British
road CLASS meaning "a public road below tertiary", and every rider outside
mapping reads it as "nobody classified this", so offering it would collect
confident wrong answers. The drawer shows both — `Residential street ·
living_street` — because a rider following the link back to OSM needs OSM's own
word.

### Assumptions are shown, marked, and never stored

A `traffic` value written from a constant keyed on surface class would be worse
than an empty field: an empty field wearing a fact's clothes. The map instead
infers traffic from `highway` (a residential street really is quieter than a
secondary road) under three rules:

1. **It is rendered as an assumption.** An ochre `!` beside the value, next to
   (never instead of) the glacier-green `[OSM]` badge that means "OSM said so".
   Hover reveals the reasoning on a pointer; **tap toggles it on a touch
   screen**, because a tooltip nobody can open is a tooltip that lies.
2. **The reasoning travels with the value**, so a number can never appear
   without the sentence that justifies it: *"Not measured — worked out from the
   map: OpenStreetMap calls this a residential or living street, which normally
   means local traffic only. Nobody has confirmed it. Ride it and tell us."*
   Five locales.
3. **It is never stored and never prefilled into the form.** This is the load-
   bearing one. A prefilled assumption that a rider submits without touching
   becomes a rider's *claim*, and a moderator then reads a guess in the same
   typeface as an observation. Facts prefill (the surface class, the name);
   inferences do not. That is also why moderator views need no special case —
   an assumption can never reach them.

`assumedTraffic()` in `web/assets/map/surface-tiles.js` holds the rules;
unknown highway values return null rather than a nearest guess.

**The course fixture exports no assumptions either.** `traffic` and
`smoothness` are dropped in `tools/wallonia/export.py`, not fixed in
`atlas/demo/surface-data.js`: the fixture is harvested output, and hand-editing
it is how a re-harvest silently undoes the fix.

**Legend affordances**: each class row carries a ✓ when shown and loses it when
filtered out, so a rider sees the rows are buttons. **Study mode is gated on the surface skin
being on** — stripping the basemap with no surface lines to study is a blank
page, so the control disables with the layer and switches off with it.

**Not every class can be confirmed.** `cycleway` says what a way *is*, not what
it is made of, and `unverified` is the absence of a claim; neither offers the
confirm action. The other five map to a declarable label in
`SurfaceVocabulary::TILE_CLASS` (`paved`→Asphalt, `gravel`→Gravel,
`pave`→Sett — pavé, `dirt`→Dirt, `rock`→Rock — coarser than the dropdown by
design, since one colour stands for a family of OSM values). The map states the
same five in `CONFIRMABLE_CLASSES`; `SurfaceConfirmClassContractTest` fails if
the two lists ever drift.

Segment-located types never resolve through `coverage_poi` — it is the point
index, and surface lines never enter PostGIS. The submission keeps a point (its
start, which is what resolves the region); the **item** gets the LineString.

## Confirmation: "as described"

A surface item is confirmable like every other letter ("could it vanish" is
the wrong test): a rider who rode the stretch is exactly who can vouch for it
(owner 2026-08-13). The stance is the ordinary `exists` record; only the WORDS
differ — the panel asks *"Is it as described?"* with *"✓ As I rode it"*
(`stanceKind: accuracy`, ItemConfirmationController → community.js), because a
road rarely leaves. The gone/closed condition row and the OSM one-tap row stay
off for A: a stretch is not a place that vanishes, closures ride the
`seasonalClosure` field, and the tile lines keep their own confirm flow. A
drawer confirmation counts toward the `v` derivation as usual, so a confirmed
stretch surfaces in Confirmed mode.

The panel is an explicit **yes/no pair** (owner 2026-08-13): "✓ Yes" filled
orange as the button people should see, and "✗ No"
(`ConfirmationStance::NotAsDescribed`, stance column widened to 20, migration
`Version20260813210000`). The "no" never verifies (a warning is not a
vouching, same rule as `not_potable`) and carries NO comment field (owner
decision): what changed belongs in the EDIT FORM, the one moderation pipeline,
so a negative answer shows a pointer to "✎ Edit this item" instead of opening
a second free-text entry point to the curators. The moderation drawer carries
**Approve & confirm**: a tick on the decision that also records the curator's own
confirmation, which verifies outright through the existing
weighted-by-who-pressed-it rule ("I know these roads by hand"); offered for
every letter whose stances include `exists` (not water, not R).

The public change history names an actor by display name only when their
profile is public, else by their stable pseudonym (`RiderPseudonym`, e.g.
`rider#k7m2x9qp`), the moderator who flipped a status included
(`ChangeHistoryView`).

## Read view (drawer "current details")
- Surface
- Smoothness
- Width
- Traffic

## Edit form
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Surface | select(Asphalt / Concrete / Paving stones / Sett — pavé / Compacted / Fine gravel / Gravel / Dirt / Rock) | `[OSM]` |
| Road type | select(Main road / Local road / Residential street / Farm or forest track / Path or trail / Cycleway) | `[OSM]` |
| Smoothness | select(Excellent / Good / Intermediate / Bad / Very bad) | `[OSM]` |
| Traffic | select(Quiet / Moderate / Busy / Car-free) | `[edit]` |
| Segregated from cars? | select(Unknown / Yes / No) | `[OSM]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Lit at night? | select(Unknown / Yes / No) | `[edit]` |
| Seasonal closure? | select(None / Winter / Forestry work) | `[edit]` |
| Width (m) | input | `[OSM]` |
| Note | textarea | `[edit]` |

**Vocabulary and layout** (owner review 2026-08-12):

- **`Car-free`, not a regional brand.** RAVeL is Wallonia's brand for its
  greenway network, and this layer serves many countries, so the value is
  plain `Car-free`, and the legend and drawer say `Cycleway`, in five locales.
  The harvester's stored `Cycleway · RAVeL` label stays mapped in
  `SurfaceVocabulary::BUCKETS`: stored values are kept, display is not.
- **`Forestry work`, not `Forestry`.** The bare noun does not say what closes
  the road. It is the logging season.
- **Segregated sits beside Traffic**, not in "Add missing": they are one
  question asked twice. Which pane a field lives in is presentation only (both
  merge into one flat attribute set on submit).

Migration `Version20260812010000` moved existing rows and undecided submissions
onto these spellings.

### Report a problem
Not built: per-type reasons are not offered. A rider reports a place through the content report ([../content-reports.md](../content-reports.md)).

### Add a photo
Available on this type (CC BY-SA 4.0).
Location metadata (EXIF GPS) is stripped from uploaded photos before storage — the Commons maps places, not riders.

## Styling is keyed on `surface=`, not `smoothness=`

The A layer's classification and rendering key **primarily on the OSM `surface=`
tag** (with `highway=` only as a fallback for untagged ways); `smoothness=` is a
displayed/editable attribute row but **never a styling key**. This is deliberate:
keying style on smoothness reproduces CyclOSM's known failure mode where a gravel
way tagged `smoothness=intermediate` renders as if paved — the surface disappears
under the smoothness value. Verified in the harvest classifier
(`tools/wallonia/route_surfaces.py`, `_cls()`: "the actual `surface=` tag wins")
and the map styles (`SURFACE_STYLE` in `web/assets/map/render.js`, shared with the
tile layer in `web/assets/map/surface-tiles.js`, keyed by surface class: cycleway teal · paved slate · gravel ochre · pavé slate-grey · dirt brown ·
rock grey · unverified red dashes — "unverified" = OSM has no `surface=` tag,
an invitation to tag it).

Rendering is **one line layer per surface class** (over a single consolidated
GeoJSON source + one shared casing layer) because MapLibre cannot data-drive
`line-dasharray`/`line-cap` per feature within a layer — the dash pattern that
distinguishes gravel/pavé/dirt from solid paved must live at the layer level.
Line width scales from the `width=` tag.

**The class (`cls`) is derived at serve time when a row lacks one.** Only
harvested rows store a `cls` attribute; a rider-materialized segment stores the
form vocabulary (`surface: 'Asphalt'`) and nothing else. `CatalogProvider::
surfaceSegments()` fills a missing `cls` from
`SurfaceVocabulary::TO_TILE_CLASS` (the stored-label → map-class inverse of
`TILE_CLASS`, covering both the declarable vocabulary and the harvester-only
spellings); a stored `cls` is never second-guessed, and an unknown label
still falls through to `other` rather than being guessed.

## Implementation
- **OSM surface skin:** built by `pipeline/coverage/surface.py` (`coverage.run --surface`) straight
  from the Geofabrik extracts into per-country surface PMTiles (classified and to-do arms, plus the
  world gaps grid), with zero database rows; it stays out of the points coverage artifact
  ([../coverage-provider.md](../coverage-provider.md) §4).
- **A items** are the curated corridors drawn on top of the skin, served through the catalog region
  slices: rider materializations from the confirm/correct flow above, and the fixture segments
  (`atlas/demo/surface-data.js`) that `tools/wallonia/export.py` exports for the catalog import. There is
  no region-bbox bulk harvest of A items ([../catalog-data-model.md](../catalog-data-model.md) §12).
- **Rendering:** one MapLibre line sub-layer per surface class (solid = paved, dashed = gravel,
  dotted = rough), line width from `width=`.
