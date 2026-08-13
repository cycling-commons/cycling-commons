<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Edit spec — A · Road surface

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** A · Road surface
- **Map depiction:** line styled by surface class, icon ▰, colour #4E8C84. Rendered **above** ride/climb
  lines so the surface (e.g. a gravel sector along a ride) reads on top.
- **Edit-item id:** `road-surface` in `atlas/demo/edit-items.js`
- **Editable:** yes · Frontend demo · 2026-06-18
- **Lifecycle:** *utility / coverage* — verified (≥ X community confirmations) then shown; **never votable, never best-of** (value is completeness). Lives in **Everything** mode. See [README — lifecycle & votability](README.md#item-lifecycle-and-votability).

## What it is
Real road/path segments (RAVeL cycleway, forest gravel, pavé/sett) shown as lines coloured and patterned by surface, so riders can pick the right bike for the terrain.

## Location (add mode)
A surface entry is a **segment**, not a point: the first action is to tap the **start**, then the **end**.
(Contrast: most types drop a single pin; rides need none.)

**The line between them follows the road** (2026-08-12). The two pins are snapped
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

## Correcting or confirming OSM's answer (built 2026-08-12)

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
empty Surface dropdown asks the rider to retype what the drawer just told them,
and reads as "we know nothing here" (owner-reported 2026-08-12). The difference
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

## Names, road type, and what we are allowed to assume (2026-08-12)

**The tile carries the way's `name`.** The basemap had been printing "Rue du
Puits Saint-Martin" under our line while the drawer said "Paved · asphalt" and
the wizard asked the rider to type a name we already had. The drawer now
headlines with the street name and the class becomes its subtitle; the improve
link carries `&name=`, and the wizard opens with it filled in. Composition
happens at RENDER time, never in the tile: `name` is the OSM tag verbatim and
the class comes from the locale dictionary, so a Dutch rider reads
"Rue Saint-Géry · Verhard · asfalt". Gluing them upstream would freeze one
English word into a name field for good. Cost: the Belgium artifact went from
43 MB to **53.9 MB** (418,304 ways) — names are ~25%, and unnamed ways omit the
key entirely rather than carrying an empty string.

### One artifact, three countries, and no seam at the border (2026-08-12)

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

**The "needs recording" arm stopped being "every untagged way" on 2026-08-12,
and the reason is editorial before it is about bytes.** It was every untagged
way, and it cost as much as the entire classified skin (894,217 ways, 119 MB).
Measured against our own tagged data for the same countries, most of that was
asking riders to confirm what is already known — of Belgian ways somebody HAS
tagged, these are the shares that turned out unpaved:

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
classes cut the arm to 29 % of its size and made every line in it a road where
riding actually settles something. The set is contract data
(`surface.todo.highways`), so widening it later is a one-line change plus a
rebuild — no code, no client release.

**The arm is ROUTE-AWARE since 2026-08-13** (owner decision 2026-08-12; plan:
`docs/plans/handoffs/2026-08-12-routes-layer-and-surface-quality.md`). The
class gate above has a second gate beside it, a union not a hierarchy: *an
untagged way that carries a signed route or node network is homework whatever
its highway class, because a rider will ride it BECAUSE it is signed.* The
Zuiderdijk is the worked example — nine untagged tertiary/unclassified ways
carrying LF-ZZ plus two rcn segments, drawing as a hole in the skin until this
rule. The way-id sets come from the routes extractor
(`pipeline/coverage/routes.py`, per-region `routes_<slug>_wayids.txt` in the
workdir), which is why `coverage.run --routes` runs before `--surface` when
rebuilding both. A brief 2026-08-12 stopgap that added `tertiary` + `cycleway`
to `todo.highways` was withdrawn the same night: class was the wrong key, and
it would have bought back most of the bytes the measurement above saved. The
gap grid counts route homework too, so the squares and the lines keep
answering the same question.

**Surface QUALITY has its own channel since 2026-08-13** (owner shape,
2026-08-12). The classified arm's features carry `sm` (raw OSM `smoothness`,
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

**So the per-country extract is cached** (2026-08-12), which is the half worth
caching: an osmium pass plus a full node-location walk over a national PBF,
against a tiling run that reads GeoJSONL already on disk. A country is
re-extracted only when its `.geojsonl` is older than the PBF it came from **or
older than the tile contract** — the contract matters as much as the data,
because adding a highway type or a surface class changes what *should* be in the
extract while leaving the PBF untouched, and a stale file would then be tiled as
if it were current. An empty extract (what a killed run leaves behind) is never
reused: it would publish a country with no roads and no error.
`COVERAGE_FORCE_EXTRACT=1` is the hatch for a pipeline change no timestamp can
show. Measured on a BE+LU rebuild: both extracts reused, only the tiling ran.

### Why the names stay in the tile, measured (2026-08-12)

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

**Road type is now editable.** The drawer has always shown OSM's `highway` tag
and the form had no counterpart, which makes a read-only row feel like a locked
door. The form offers six kinds a rider can tell apart from the saddle;
`App\Catalog\RoadType` owns the mapping and `RoadTypeContractTest` keeps the
map module's copy identical. **`unclassified` is not offered**: it is a British
road CLASS meaning "a public road below tertiary", and every rider outside
mapping reads it as "nobody classified this", so offering it would collect
confident wrong answers. The drawer shows both — `Residential street ·
living_street` — because a rider following the link back to OSM needs OSM's own
word.

### Assumptions are shown, marked, and never stored

The old harvester wrote `traffic` from a constant keyed on surface class:
`Open road` on everything that was not a cycleway, `Car-free` on everything that
was. That is worse than an empty field, because it is an empty field wearing a
fact's clothes.

What replaces it is an inference from `highway` — a residential street really is
quieter than a secondary road — under three rules:

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

**The course fixture stopped exporting them too** (2026-08-12). `traffic` and
`smoothness` are dropped in `tools/wallonia/export.py`, not fixed in
`atlas/demo/surface-data.js`: the fixture is harvested output, and hand-editing
it is how a re-harvest silently undoes the fix. The 351 imported demo rows were
deleted the same day, so the catalog's A layer now starts empty and fills only
with what riders submit.

**Legend affordances** (2026-08-12): each class row carries a ✓ when shown and
loses it when filtered out, because "these seven rows are buttons" was something
a rider had to discover by clicking. **Study mode is gated on the surface skin
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

## Confirmation: "as described", since 2026-08-13

A surface item is confirmable like every other letter, by the same correction
that brought climbs in ("could it vanish" is the wrong test): a rider who rode
the stretch is exactly who can vouch for it, and the drawer's status line was
saying "not confirmed yet" to a second rider with no way to answer
(owner-reported). The stance is the ordinary `exists` record; only the WORDS
differ — the panel asks *"Is it as described?"* with *"✓ As I rode it"*
(`stanceKind: accuracy`, ItemConfirmationController → community.js), because a
road rarely leaves. The gone/closed condition row and the OSM one-tap row stay
off for A: a stretch is not a place that vanishes, closures ride the
`seasonalClosure` field, and the tile lines keep their own confirm flow. A
drawer confirmation counts toward the `v` derivation as usual, so a confirmed
stretch surfaces in Confirmed mode.

Refined the same evening (owner): the panel is an explicit **yes/no pair**
("✓ Yes — as I rode it", filled orange as the button people should see, and
"✗ No — not as described", `ConfirmationStance::NotAsDescribed`). The "no"
never verifies (a warning is not a vouching, same rule as `not_potable`) and
may carry an optional **why** (≤500 chars, `item_confirmation.note`,
migration `Version20260813210000`): free text FOR THE CURATORS — shown back
only in the curator-only `notes` block of the confirmations payload, never
rendered publicly (one-way-to-moderate). And the moderation drawer gained
**Approve & confirm** — a tick on the decision that also records the
curator's own confirmation, which verifies outright through the existing
weighted-by-who-pressed-it rule ("I know these roads by hand"); offered for
every letter whose stances include `exists` (not water, not K).

## Read view (drawer "current details")
- Surface
- Smoothness
- Width
- Traffic

## Edit form  (`improve.html?item=road-surface`)
### Fix details
| Field | Control | Provenance |
|---|---|---|
| Surface | select(Asphalt / Concrete / Paving stones / Sett — pavé / Compacted / Fine gravel / Gravel / Dirt / Rock) | `[OSM]` |
| Smoothness | select(Excellent / Good / Intermediate / Bad / Very bad) | `[OSM]` |
| Width (m) | input | `[OSM]` |
| Road type | select(Main road / Local road / Residential street / Farm or forest track / Path or trail / Cycleway) | `[OSM]` |
| Traffic | select(Quiet / Moderate / Busy / Car-free) | `[edit]` |
| Segregated from cars? | select(Unknown / Yes / No) | `[OSM]` |
| Note | textarea | `[edit]` |

### Add missing  (type-specific)
| Field | Control | Provenance |
|---|---|---|
| Lit at night? | select(Unknown / Yes / No) | `[edit]` |
| Seasonal closure? | select(None / Winter / Forestry work) | `[edit]` |

**Three vocabulary/layout corrections, 2026-08-12 (owner review):**

- **`Car-free`, not `Car-free (RAVeL)`.** RAVeL is Wallonia's brand for its
  greenway network, and this layer serves twelve countries. It was also a second
  spelling of a value the harvester already writes as plain `Car-free` (132 rows
  against 1), so the dropdown now agrees with the data. Same reasoning retires
  `Cycleway · RAVeL` as the *displayed* legend and drawer label — it is
  `Cycleway` now, in five locales. The harvester's stored `Cycleway · RAVeL`
  label stays mapped in `SurfaceVocabulary::BUCKETS`: stored values are history,
  display is not.
- **`Forestry work`, not `Forestry`.** The bare noun does not say what closes
  the road. It is the logging season.
- **Segregated sits beside Traffic**, not in "Add missing". They are one
  question asked twice, and on a narrow screen they were pages apart. Which pane
  a field lives in is presentation only — both merge into one flat attribute set
  on submit — so nothing about storage moved with it.

`Version20260812010000` carries the two renames across existing rows and
undecided submissions. It reverses only the closure rename: merging two traffic
spellings into one is a one-way door, and a `down()` that renamed all 133 rows
back would corrupt the 132 that never carried the parenthetical.

### Report a problem
- Wrong surface · Surface changed (resurfaced) · Blocked / impassable · Wrong location

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
and the map styles (`web/assets/map/map.js`, `SURFACE_STYLE` keyed by surface
class: cycleway teal · paved slate · gravel ochre · pavé slate-grey · dirt brown ·
rock grey · unverified red dashes — "unverified" = OSM has no `surface=` tag,
an invitation to tag it).

Rendering is **one line layer per surface class** (over a single consolidated
GeoJSON source + one shared casing layer) because MapLibre cannot data-drive
`line-dasharray`/`line-cap` per feature within a layer — the dash pattern that
distinguishes gravel/pavé/dirt from solid paved must live at the layer level.
Line width scales from the `width=` tag.

**The class (`cls`) is derived at serve time when a row lacks one**
(2026-08-13): only harvested rows ever stored a `cls` attribute, so a
rider-materialized segment (the confirm/correct flow stores the form
vocabulary — `surface: 'Asphalt'` — and nothing else) drew in the grey
`other` fallback instead of its class colour. `CatalogProvider::
surfaceSegments()` now fills a missing `cls` from
`SurfaceVocabulary::TO_TILE_CLASS` (the stored-label → map-class inverse of
`TILE_CLASS`, covering both the declarable vocabulary and the harvester-only
spellings); a stored `cls` is never second-guessed, and an unknown label
still falls through to `other` rather than being guessed.

## Implementation
- **Demo:** registry entry `road-surface` in `atlas/demo/edit-items.js` (hand-picked fixture data); segment geometry in `atlas/demo/surface-data.js`.
- **Production:** sourced via the `tools/wallonia` harvest today; region-bbox bulk harvesting is
  superseded going forward ([../catalog-data-model.md](../catalog-data-model.md) §12) and A is
  deliberately excluded from the coverage artifact
  ([../coverage-provider.md](../coverage-provider.md) §4 — corridor line data, its own future
  decision). Rendering: one MapLibre line sub-layer per surface class (solid = paved,
  dashed = gravel, dotted = rough), line width from `width=`.
