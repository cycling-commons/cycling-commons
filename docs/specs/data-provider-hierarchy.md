# Data provider hierarchy and the provider registry

**Status: specified 2026-08-27 (owner). Phases 1-5 built 2026-09-04; phase 6
not built.** The Dutch public drinking-water taps are its first real-world
test, not a separate task.

Phase 1 landed the registry table, the seeded rows, the `pivot` to `authority`
rename and the sweep of §12; the ranks reproduce the old ladder, so nothing
reordered. Phase 2 moved every citation onto it: the map drawer reads the
provider map the catalog payload delivers, and `/credits` generates its data
group from the table. Both hardcoded provider strings are gone.

Related, and deliberately not merged into this document:

- [data-source-register.md](data-source-register.md) is the **policy** register:
  which sources we may use at all, and on what licence. It decides admission.
- This document is the **runtime** register: how an admitted source is stored,
  ranked, refreshed, cited and drawn. It decides behaviour.
- [catalog-data-model.md](catalog-data-model.md) §5 owns `item.source` and the
  duplicate guard's keeper order. This document changes both and says how.

## 1. The problem, in three parts

**1.1 The bucket is named after its first member.** `ItemSource::Pivot` exists
because the first non-OSM dataset we ingested was Geoportail Wallonie PIVOT.
There are 150 such rows in the catalog today. The name says nothing about what
the value means, and `wiki/data-priority.md` has to explain it in a sentence
that starts "Today that is".

**1.2 The provider is hardcoded.** The citation a rider sees is a string in
`web/assets/map/i18n.js`: `pivot:'Tourisme Wallonie (CC-BY)'`. One provider fits
in a constant. The second one does not, and the owner expects "lots and lots of
these external providers world wide".

**1.3 Renaming it "external" or "provider" does not help**, and the owner said
so: OpenStreetMap, Wikidata and PIVOT are all external, all providers. Neither
word separates anything. **The axis that does separate them is hierarchy**: how
far a record's origin outranks another when two of them describe one real place.

## 2. The name: `authority`

The proposed value is **`authority`**, and the concept is the **authority
registry**.

It is chosen for the reason it outranks OpenStreetMap, which is the only reason
this bucket exists as a rank at all: the body that installs, licenses or
registers the thing knows it better than a passer-by who mapped it. RIVM
publishes the tap register because the water companies that fit the taps report
to it. Tourisme Wallonie publishes the accommodation register because a
Belgian hotel is legally obliged to be in it.

That also gives the admission test a name. A dataset earns the `authority` rank
when its publisher is the body of record for the thing being mapped. A dataset
that is merely somebody else's good map does not, and is either an OSM-grade
crowd source or not ingested at all.

**This is the one naming call to confirm before anything is built.** Everything
below works with a different word; nothing below works with a word that means
"external".

The word is also nearly invisible in the interface. A rider sees **RIVM** or
**Tourisme Wallonie**, never "authority" (§7).

## 3. The registry

A new table, `data_provider`, one row per dataset. Curator-maintained (§8).

| column | meaning |
| --- | --- |
| `id` | |
| `key` | stable slug used in `source_ref` and in URLs, e.g. `rivm-drinkwater`, `wallonie-pivot`. Immutable once rows reference it. |
| `name` | what a rider sees: "RIVM", "Tourisme Wallonie". |
| `full_name` | the long form for the credits page. |
| `homepage` | where the citation links. |
| `licence` | free text plus a code, e.g. `public-domain`, `cc-by-4.0`, `odbl`. The code also decides the credit's weight (§9.3). |
| `creator` | who made the dataset, when that is not the publisher. drinkwaterkaart.nl for the Dutch taps. Null when publisher and creator are the same. |
| `promoted` | false by default. Lifts a courtesy credit into a full row (§9.3). |
| `attribution` | the exact line we are obliged to show, when the licence names one. |
| `country_code` | null for worldwide. |
| `letters` | which catalogue letters this dataset fills. |
| `rank` | where it sits in the hierarchy (§4). |
| `community_edited` | false while nobody here has touched a row (§6, the grey pins). |
| `endpoint` | URL of the machine-readable source. |
| `endpoint_kind` | `wfs`, `geojson`, `csv`, `arcgis`. |
| `field_map` | JSON: which upstream field feeds which of our attributes. An entry is the upstream field name, or `{"from": <field>, "values": {<theirs>: <ours>}}` when the upstream values must land in one of our form vocabularies; a value the map does not name is dropped (`apply_field_map`). |
| `match_radius_m` | how close an upstream point must be to an OSM node to count as the same thing (§5). |
| `refresh_cadence` | how often the harvest should re-read it. |
| `last_run_at`, `last_count`, `last_error` | what the desk shows. |
| `enabled` | off means "keep the rows, stop refreshing". |
| `system` | true for seeded rows nobody may delete (§3.1). |

**How a row points at its provider: `item.provider_id`.** A nullable foreign
key, set exactly when `item.source` is `authority` and NULL for everything
else. `source_ref` cannot answer this: it is the harvest's own upsert key, and
the historical Wallonia refs (`fx:pivot:hotel-koru|ramillies`) name the bucket
that no longer exists rather than a provider. Added by the phase 1 migration
alongside the table.

### 3.1 OpenStreetMap and Wikidata are registry rows too

They are seeded with `system = true`, cannot be deleted, and their `endpoint`
and `field_map` are ignored because their harvests are their own code. They live
in the registry so that **one table answers "who do we cite, and under what
licence"** for every row on the map. A credits page assembled from two places
drifts; this one has a single source (§9).

Their ranks are seeded to today's values and are not curator-editable.

## 4. Hierarchy, and what changes about it

Today the keeper order is a hardcoded ladder in `ItemSource::dedupeRank()`:
`manual` > `user` > `scout` > `pivot` > `wikidata` > `osm` > `auto`.

It becomes:

`manual` > `user` > `scout` > **the registry's `rank`** > `auto`

`osm` (rank 100) and `wikidata` (rank 200) keep their present positions as
seeded registry rows, so nothing about existing behaviour moves. A new authority
is admitted somewhere above `osm`, and the desk shows a curator exactly which
existing datasets it would outrank before they save.

Three rules that do not change and must be restated here because a registry
makes them easy to break:

1. **Rider rows always win.** `manual`, `user` and `scout` sit above every
   registry row and are not expressible as one. A curator cannot give a provider
   a rank that outranks a rider's own contribution.
2. **The order is bookkeeping, not quality** (catalog-data-model.md §5a). It
   answers "which of these two records of one place is ours to keep", never
   "which is better".
3. **`auto` stays at the bottom.** A derived row is deleted and rebuilt
   wholesale and may never displace anything.

### 4.1 Hiding the OSM pin: already built

When an authority row outranks the OSM node for the same place, the OSM pin must
disappear rather than sit beside it. **That mechanism exists.** `item.osm_ref`
is the join key to `coverage_poi.ref`, and `CoverageRepository` already
suppresses a coverage POI whose ref is claimed by a served item
(coverage-provider.md §5, osm-data-architecture.md §8). It was built in
2026-08-25 for exactly this failure, where retiring an OSM twin let the raw pin
come back beside the kept row.

So the ingest's job is not to invent suppression. It is to **set `osm_ref`
correctly** (§5), and the existing join does the rest.

## 5. Ingest: match, then attach or insert

One generic harvester, configured per registry row, replacing the
provider-specific script (`tools/wallonia/pivot.py` becomes a registry row plus
a field map). It lives in Python, because reading a geospatial service and
reprojecting is squarely on the Python side of the boundary.

**Built 2026-09-04, in two halves that meet at a file.** `pipeline/providers/`
fetches and normalises; `app:providers:harvest <key> <file>` matches and
writes. The file between them is the same shape `app:catalog:import` already
reads, which is what lets the tabular half be tested against a fixture instead
of against a publisher having a good day. The ingest is DRY by default: it
inserts into the catalogue riders read, so seeing the counts first is the
normal way to run it and `--write` is the deliberate second step.

**Deviation, deliberate: we do not reproject.** We ask the service for
`srsName=EPSG:4326` and refuse anything that comes back outside WGS84 bounds.
The publisher's own transform is more authoritative than one applied to their
data from outside, and it keeps a projection library out of the pipeline
image. A service that ignores `srsName` fails loudly, which is the case
`wiki/developers/gis-beyond/reprojection.md` warns about: unreprojected
EPSG:28992 metres read as degrees land in the hundreds of thousands.

**Four refusals, all of them a fetch that went wrong rather than data:** a
coordinate outside WGS84 bounds, a feature carrying a letter the registry says
this provider does not fill (a field map pointed at the wrong layer is how one
click floods a catalogue), an empty result (an empty fetch is not an empty
publisher), and a paused provider (paused means keep the rows, stop
refreshing).

**`wallonie-pivot` has NOT been moved onto it, and that is not a refactor.**
Its rows come from a committed fixture export (`tools/wallonia/export.py`),
not from a live service, so pointing it at the Géoportail WFS would change
which upstream we ingest from. That is a policy question for
[data-source-register.md](data-source-register.md), not a code move. What the
harvester's genericity rests on instead is that it holds no provider-specific
code at all: the endpoint, the layer, the field map, the letters, the match
radius and the id field all come from the row.

For each upstream feature:

1. **Reproject** to EPSG:4326 if needed. RIVM serves EPSG:28992 geometry with
   `latitude`/`longitude` attributes alongside; prefer the geometry and treat
   the attributes as a cross-check, the lesson of
   `wiki/developers/gis-beyond/reprojection.md`.
2. **Compute the upsert key.** `source_ref` is `<key>:<upstream id>` when the
   upstream has a stable id. **RIVM has none**: its feature ids are positional
   (`rivm_drinkwaterkranen_actueel.1`) and a republish can renumber every one of
   them. Where there is no stable id, the ref is derived from rounded
   coordinates, e.g. `rivm-drinkwater:52.570650,4.745706`. This is recorded per
   provider, because it decides what happens when a tap moves: a moved tap with
   a coordinate-derived ref is a delete plus an insert, not an update.
3. **Look for an OSM counterpart** within `match_radius_m` in the coverage
   cache, restricted to the provider's letters.
   - **Found: attach.** Write the item row with `osm_ref` set to that OSM ref.
     The OSM pin is then suppressed by §4.1 and the authority row is what a
     rider sees, carrying facts OSM does not have.
   - **Not found: insert.** Write the item row with `osm_ref` NULL and
     `osm_checked_at` set, which is the existing tri-state "we looked and there
     is nothing" (catalog-data-model.md, `osm_ref IS NULL` vs never-checked).
4. **Never touch a rider row.** If the matched item is `manual`, `user` or
   `scout`, the authority record is dropped for that place. Rule 1 of §4.
5. **Removals.** A feature that vanishes upstream does not delete the item row.
   It is marked stale and raised on the Data desk, because "the publisher
   dropped it" and "the publisher's export broke" look identical from here.

6. **A harvested row enters `unverified`**, like every row
   (catalog-data-model.md §5): verification is a rider standing there, never
   provenance, so it draws the dashed "?" pin until one does. The register's
   authority lives in its rank. The harvester wrote `verified` until
   2026-09-06, which gave 3283 RIVM taps the plain pin (owner: "according to
   the legend this should be the icon while not confirmed by one of our
   users").
7. **Every row gets its region.** After the pass, the run stamps `item.region_id`
   for the provider's rows with the same smallest-area-wins rule the catalogue
   import applies (catalog-data-model.md §6). Added 2026-09-05, when the first
   RIVM run produced 3283 rows with no region: under a region scope the map
   hides a served row that has none, and the OSM tap it replaced is hidden by
   the dedupe, so a Dutch rider scoped to their province saw no water at all.
8. **A point that moved a little is the same row.** A register with no stable
   id (RIVM) keys a row by its coordinates to six decimals, so any GPS shift
   is a new key. When a run meets a key nobody has, it first looks for this
   provider's rows the run has not seen within `MOVE_RADIUS_M` (100 m, owner
   2026-09-05) and re-keys the nearest one instead of inserting a twin and
   counting the old row stale. Beyond 100 m two taps are two taps. Counted as
   `moved`.
9. **A re-import never overwrites what a person changed.** Rule 1 of §4, at
   the field level: the fields a row's `change_history` names were touched by
   a person, and the update fills in around them. Upstream fills every other
   mapped field, keeps fields a person added that upstream does not carry,
   and leaves the geometry alone when `location` is in the history. Before
   2026-09-05 the update replaced the whole attributes object and the
   geometry, so the second RIVM run would have undone every rider's "Not
   there anymore", note, photo and relocation.

### 5.1 The match radius is a judgement, and it is per provider

For the Dutch taps, 69.6% of upstream points sit within 25 m of an OSM node and
78.4% within 250 m. The gap between those two numbers is mostly genuine
positional disagreement about the same tap, not different taps. A 25 m radius
under-matches and creates duplicate pins; 250 m over-matches and can swallow two
real taps at either end of a square. The starting value is **50 m**, and the
desk shows a curator the match counts at 25, 50, 100 and 250 m before they
commit, because this number cannot be guessed from a form.

## 6. Drawing the map: grey until somebody here touches it

Owner's rule, 2026-08-27: a pin whose record nobody in this community has
touched draws in **greyscale**; the moment a rider or curator adds to it, it
draws in **full colour**. OpenStreetMap keeps its existing small icon set, so
the map reads as three tiers: OSM's own icons, greyscale authority pins, full
colour community pins.

Two conditions, both required.

### 6.1 Colour is never the only signal

WCAG 1.4.1: a rider who cannot see the
difference, or is in bright sun on a phone, must still be told. The drawer says
it in words, from the registry: "RIVM, public domain. Nobody here has added to
this record yet." Greyscale is the fast signal, the sentence is the real one.

### 6.2 Grey must not collide with the thing's own status

RIVM flags **68 taps
as out of order right now** (`type = Storing`) and 152 as daytime-only. If grey
means "external" and grey also reads as "broken", a rider is misinformed about
the one fact they came for. A broken or restricted tap therefore carries its own
mark, independent of the pin's colour, and that mark wins the drawer's first
line. This is the single most valuable field the Dutch dataset carries and OSM
has no equivalent of it, so it may not be lost to a styling rule.

### 6.3 The marker's channels are a fixed budget, and they are nearly spent

A marker can carry a limited number of independent signals before it stops being
readable. There are ten:

| Channel | Answers | State |
| --- | --- | --- |
| Shape (small disc vs teardrop) | which store the record lives in | taken |
| Fill colour | which category | taken |
| Glyph | which KIND, within the category (§6.3a) | taken (2026-09-04) |
| Border colour and style | our handling: pending moderation, community-added | taken |
| Ring | going stale, and selection | taken |
| Badge, top right | unconfirmed (`?`), verified (paper dot) | taken |
| Badge, top left | the thing's own STATE (§6.2, §6.3a) | taken (2026-09-04) |
| Saturation (greyscale) | who maintains the record (§6) | proposed, **free since §6.5 was settled** |
| Size | nothing | free |
| Cluster bubble | density | taken |

Two channels are left, saturation and size. That is the entire budget for
every dataset we add after this one, so it is not spent on a first-come basis.

### 6.3a The pin grammar (owner, 2026-09-04)

The owner, on being shown the water/food split: "water has potable yes/no,
still there, broken, only on time xy, closed during autumn winter. Bike
services has other kinds of traits, bike shop or Shimano self repair stand,
pump yes no etc. So apples and oranges." No single symbol grammar carries every
category's traits, so the grammar is this, and the owner accepted it the same
night:

> **Kind lives in the glyph. State is two shared badges. Everything else is
> drawer content.**

- **Kind** is per category and never compared across categories. Water &
  food: drinking tap (blue drop), tap not for drinking (the drop with a bar
  across it), tap nobody has said anything about (the drop unfilled), food
  stop (fork and knife on the category disc), food stop that also gives water
  (the same with a small blue drop). Bike services: shop, repair stand, pump.
  A non-potable tap is a different KIND of thing, not a broken one, which is
  why potability lives here and not in a colour. Colour is never the only
  signal: every water kind differs in shape.
- **State** is the only thing shared, and it means the same in every
  category. Top-left badge: a red `!` for "not usable right now" (a rider's
  `condition` of Out of order or Closed) and an ink clock for "there, but not
  always": letter B's `availability` of Daytime only or Ask or behind a gate,
  and its `seasonal` closure ONLY in the months it is shut (frost-shut:
  November to March; summer only: October to April). The badge answers "can I
  use it now" (§6.4), and every Dutch register tap is frost-shut in winter, so
  a year-round clock would mark all 3283 of them and mean nothing. A rider
  learns two marks once.
- Everything else is a drawer row or a filter facet, per §6.4.

**The definitions live once.** `App\Catalog\KindIcons::set()` is the kind
registry: per letter, per kind, either a list of 24-box paths (with `@cat`
and `@ink` colour tokens the renderer resolves) or a text glyph. The map
mints its tile icons from it on a canvas (`icons.js mintKindIcons()`, images
`kind-<letter>-<kind>`), the DOM pins draw it as inline SVG (`kindSvg()`),
and both legends render it through `partials/_kind_icon.html.twig`
(`cc_kind_icons()`). The rule that maps a record's facts to a kind is
`waterKind()` in `icons.js`, shared by the tile paint, the selected-icon
overlay, the DOM pin and the drawer; the rule for state is `stateOf()` beside
it. The tile carries `potable` as `yes` / `no` / absent (it was a boolean,
which folded "tagged no" into "nobody said") and `food` for the shop and
eatery half.

**What the water fix cost, in rows (2026-09-04):** of letter B's 375,252,
the water half is 195,336 implied-drinkable, 38,843 tagged yes, 7,221 tagged
no and 14,599 water points nobody tagged; the food half is 119,113 plain and
140 that also claim drinking water.

### 6.4 The rule that rations it

> **The pin answers one question: is this worth looking at?
> The drawer answers what it is.**

A fact earns a pin channel only when it changes whether a rider **goes there
now**. "Out of order" passes. "Wheelchair accessible", "has a photo", "costs
money", "closed in winter", "I saved this" do not: they are drawer content and
filter facets. Without this rule every new provider arrives asking for a badge,
and the map is unreadable within a year of doing this well.

A provider cannot buy a channel. `data_provider` has no styling column beyond
`community_edited` (§3), and adding one is a change to this document, not a
configuration.

### 6.5 Blocking decision: grey already means something on water. SETTLED 2026-09-04

Until 2026-09-04 a grey drop meant "nobody tagged whether this is drinkable"
(`water-drop-unk`), and if greyscale also came to mean "an authority record
nobody here has touched" the two meanings would land on the same pins. Water
is where they collide, and water is the first dataset being imported.

**Settled by §6.3a, a third way:** potability moved into the KIND glyph (a
barred drop, an unfilled drop), so grey no longer means anything on water.
The grey drop image is gone. Saturation is free again and may carry
provenance when §6 is built; it costs no badge and forces nothing into the
drawer. What stays not allowed is shipping two meanings on one channel.

### 6.6 The legend

Two decisions, owner 2026-08-27.

**The legend is generated, never hand-written.** A hand-maintained key drifts
from the code within a month, the same failure the hardcoded citation string in
§1.2 already demonstrates. It reads from the same definitions the map styles
itself from: the layer table in `web/assets/map/catalog.js` for categories, and
the registry for provider tiers and citations.

**The legend lives in the app, one tap from the map.** A rider looking at a grey
pin needs the key there, not in a document. The existing legend
(`templates/map/index.html.twig`, `syncLegend()` in `web/assets/map/panels.js`)
explains **lines only**, surface and routes, and appears when a line layer is
on. Every pin variation is undocumented today. The panel extends to cover pins,
and keeps its existing behaviour of showing only what is actually on the map, so
a rider is never asked to read six meanings for symbols they cannot see.

**Half the legend already exists and nobody calls it one.** The layer panel is
the category key: each row is the category's own swatch beside its name, in the
same colour and from the same icon set as the pin
(`ItemType::iconSet()` feeds both shapes, and the catalogue letter decides which
half of the panel a category lands in, A to M practical, N to Z experiential).
A rider matching a tile to a pin needs no explanation.

What has never been explained is the other axis: the **state** signals of §6.3,
which ride on top of any category. That is what the legend has to add, and it is
why the panel is where it belongs rather than in a separate overlay.

A rendered inventory of every current and proposed marker, taken from the code
on 2026-08-27, is at
<https://claude.ai/code/artifact/f30a81e1-ea8e-4d1d-841f-b72474b0ada6>. It is a
snapshot for review, not a source of truth: when the legend is generated, that
is the source of truth.

### 6.7 Custody and evidence: two axes, one mark each

**Specified 2026-09-09 (owner ruling). The ladder (§6.7.7) and the per-row
upstream sighting (§6.7.6) are built as of 2026-09-10; the marker grammar, the
API envelope and custody take-back are pending.** Supersedes the
greyscale proposal in §6: custody moves to the border, not to saturation. §6.5's
rule is what forces the shape below, one channel carrying one meaning.

A rider asks two different questions and the map had been answering them with
one muddled signal:

- *Who keeps this record?* That is **custody**. It never says the record is worse.
- *Has anybody stood here?* That is **evidence**. It never says who owns it.

Mixing them is what produced the contradiction §6.7.5 records.

**Axis 1, the border, answers custody.**

| Border | Custody |
| --- | --- |
| Small disc | a **gross** provider: no scope registered for this category and region. OpenStreetMap is the fallback member of this tier. |
| Dashed | a **specialty** provider: a `data_provider` row scoped to this category and region. |
| Solid paper | ours: this community keeps the record. |

**Specialty is a registry fact, never a judgement.** A provider is specialty for
a (category, region) pair because its registry row carries that scope, not
because anyone rated it. Nothing is decided at render time, so the tier cannot
drift with opinion and a new provider inherits its tier the moment its row is
written. This is the same discipline moderation-and-contribution.md §10.1 applies
to the verification threshold, for the same reason: a signal that a human grades
case by case stops being comparable.

**Axis 2, the badge, answers evidence, and it has exactly one mark.** `?`
present: no dated witness is on record. `?` absent: a dated witness is on record.
That is the whole vocabulary.

**The paper dot is removed.** Until this ruling `verified` wore a second mark, so
our tier read by a different rule from the provider tiers and a rider had two
symbols to learn rather than one. Absence of the `?` is the verified signal on
every tier. That hands a mark back to a budget §6.3 calls nearly spent.

Three borders by two badge states, and one sentence explains the whole grid:
**the `?` means nobody has stood here on record.**

| Border | with `?` | without `?` |
| --- | --- | --- |
| Small disc | nobody has stood here | the source published a dated survey |
| Dashed | the specialty provider publishes no dated survey | the specialty provider publishes a dated survey |
| Solid paper | no rider has confirmed it yet | `map.item_verify_threshold` riders have (moderation-and-contribution.md §10.1) |

**Small disc without a `?` is not a theoretical cell.** 21,541 of the 366,887
`amenity=drinking_water` objects in OpenStreetMap carry `check_date` (5.9%,
taginfo, 2026-09-09). Those have a dated witness and lose the badge on the same
rule as everyone else. Reading a provider's own freshness tag instead of
ignoring it is the reciprocity of §6.7.2 pointed upstream.

#### 6.7.0 The rungs are utility. They never reach Best of

Owner ruling 2026-09-09, and the boundary the rest of §6.7 depends on.

Two different questions, two different layers, and they must not be joined:

- **Is this thing here, and is that current?** Utility. Every category has it,
  the experiential ones included: a viewpoint can be felled, a hotel closes, a
  route is withdrawn. This is what the rungs answer, and all they ever answer is
  **how the thing is presented**: which border, whether a `?` rides on it, and
  whether it is drawn at all.
- **Is this thing worth riding to?** Emotional value, and it is a layer on top.
  It is settled by rider votes, never by evidence of existence. Best of is that
  layer.

So no rung, however high, puts anything into Best of. A scenic view confirmed by
fifty riders is fifty riders saying *it is there*. Not one of them said it was
good. Reading a high rung as quality would let a well-surveyed car park outrank a
col, which is the failure this boundary exists to prevent.

`CuratedReadiness` already draws the same line from the other side: its docblock
reads "Utility layers do not count, they render in both modes", and its `BLOCKS`
list holds only the experiential letters A, N, O, P, Q and the R routes. Water
taps were never in that count and must never enter it.

**What this means for the 2026-09-09 collapse.** Removing `OR i.source =
'authority'` from `CuratedReadiness` took North Holland from 840 countable items
to 15. Those 825 rows were authority-sourced rows in the experiential letters:
imported viewpoints, historic sites and sleep spots that nobody here had rated.
The count did not break. It stopped lying. A region that has 15 pieces of curated
experiential content has 15, and the honest answer is that it is not ready to
open in Best of by default, which is exactly what `legend.tier_onboarded_desc`
already says out loud: "on the map, waiting for its first verified entries".

The fix is therefore **not** to restore the count by another route. Either the
readiness gate is redefined around what Best of actually ranks, or regions stay
un-unlocked until rider-backed content exists. Restoring 825 unrated imports
would put a Best-of view in front of riders built from places no rider chose.

#### 6.7.1 Dashed is a source signal, not a verdict

A dashed pin says *somebody else keeps this*, never *this is worse*. The
distinction is load bearing: it is what lets the drawer link out honestly instead
of apologetically. Where a specialty provider holds better material for a region
than we do, the drawer names them and links to them. It does not paraphrase them
and keep the rider.

That is the rule wiki/data-catalog.md already sets for licensing, "signposted, a
link out and no copy", applied to the interface instead of the licence. Provide
the best data and you get the rider; never stand between a rider and somebody who
has better.

#### 6.7.2 Custody moves both ways

Ours is not a terminal state.

- A provider row **becomes ours** when `map.item_verify_threshold` riders vouch
  for it (moderation-and-contribution.md §10.1). Provenance still verifies
  nothing; riders do.
- Ours **returns to dashed** when the provider's dated survey is newer than our
  newest confirmation, and only then. A provider that publishes no dates can
  never take custody back, which is the same reciprocity rule again: a provider
  earns standing by showing its receipts, not by being official.

Two brakes, both required. Custody flips only at harvest, never per request, so a
pin cannot change between page loads. And the provider must be newer by a
configured margin rather than by a day, or a pin bounces on every harvest.

Whether a given provider may take custody back at all is a per-provider setting
on its registry row. It is a judgement about that organisation's field operation,
not a property of the data model, so it belongs beside the provider and not in
the rule.

**Rider evidence is never cache.** Custody bounces; `item_confirmation` rows
accumulate forever and keep weighing in on the tally. A harvest may change a
row's custody, its attributes and its freshness. It may never delete a
confirmation. Read §6.7.4 with this sentence attached, or a later harvest will
clear rider work in the name of a refresh.

**Built 2026-09-10.** Three settings on the registry row, all on the providers
desk (§8): `may_reclaim` (off by default), `reclaim_margin_days` (1 to 365, 30
by default) and `survey_date_attribute`, the harvested attribute that carries
the provider's per-record survey date. `ProviderHarvest::reclaim()` runs on
every row the export still carries and writes `item.custody_reclaimed_at` when
all of these hold: the row is verified, the provider may reclaim, the named
attribute holds a full `YYYY-MM-DD`, and that date is newer than our newest
vouching confirmation by at least the margin. What gets written is the survey
date itself, by the provider's own clock, so `ItemEvidenceResolver` reads
custody as the provider's from that date until a confirmation newer than it
lands, and as ours again the moment one does. The state, the rung and the
confirmations never move with custody: a reclaimed verified tap draws dashed
with no `?`. The same attribute is the published witness rung 7 reads for a
provider row. The harvest summary counts `custody reclaimed`. No harvest path
deletes an `item_confirmation` row, and `CustodyReclaimTest` asserts the count
before and after; the one deleter in `web/src/` is the curator's purge command,
which removes the item itself and everything hanging off it.

#### 6.7.3 The drawer says both, and one half of it is personal

When custody returns to a provider the drawer states both facts and subtracts
neither:

> You confirmed this on 12 May. The register published a newer survey on 3 September.

Never "external data replaced yours". A rider whose confirmation appears to
vanish is a rider who stops confirming, and it has not in fact vanished (§6.7.2).

That makes half the drawer user bound, which a shared cache cannot hold. The
split:

- The public half, the provider's survey date included, stays in the cached
  drawer body.
- The **one sentence** beginning "You" is a separate fragment, fetched
  asynchronously and served `private, no-store`.
- Anonymous visitors hold no confirmations, so the fragment renders nothing and
  is never requested. The common case pays nothing.
- The client memoises it per item id, so reopening a drawer costs no request.
- It degrades: if the fragment fails the drawer is still correct, only shorter.
  Facts never sit behind a spinner.

**The trap this must not spring.** The personal fragment has to be the only route
that touches the session. If the drawer body route reads the user in order to
decide whether to show the line, the body stops being cacheable and the split has
bought nothing. That is the "Security touches session" blocker already recorded
against the page-caching work.

#### 6.7.4 The model: a provider is a warm-up cache

A provider row is a head start, not an answer. It puts a pin on the map so a
region is not empty on its first day, and it says plainly that somebody else
keeps the record. Riders convert it into a record this community keeps, one
confirmation at a time.

The metaphor carries two consequences, both deliberate. A cache is refreshable,
so a re-harvest may correct or retire a provider row. And a cache is not the
truth, so the `?` stays on it until a dated witness exists, whoever produced that
witness. Rider evidence sits outside the cache and survives every refresh
(§6.7.2).

#### 6.7.5 The legend gives a register one answer

Built 2026-09-10. Four `legend.tier_*` key pairs, in five locales, describe
the grammar and nothing else: `tier_baseline` (small disc, a gross provider),
`tier_external` (dashed, a specialty provider), `tier_ours` (solid paper), and
`tier_unconfirmed` (the `?` badge, on any of the three). A public register is
a small disc when it is gross and dashed when it is specialty, and its badge
depends only on whether a witness is on record. The key rail in
`templates/map/index.html.twig` and the page `templates/pages/map_key.html.twig`
draw the four rows with the real `.cc-pin` classes from `styles/pins.css`, and
`MapKeyTest` fails the build if either names the paper dot or carries a copied
pin rule. No mark on the key is planned any more: everything it shows, the map
draws.

#### 6.7.6 `imported_at` is the per-row upstream sighting

Built 2026-09-10. The rung that says *the register republished this record and
did not retract it* needs a per-row date, and `item.imported_at` is that date,
read as "last seen in an upstream export".

`ProviderHarvest::apply()` writes it on every row the export carries, on all
three paths:

| Path | Where it is stamped |
| --- | --- |
| insert | the `INSERT` in `insertRow()` |
| update | the `UPDATE` in `updateRow()`, beside `updated_at` |
| seen but left alone (a rider pin sits at the spot, so the loop skipped the row) | `stampSeen()`, one `UPDATE ... WHERE source_ref IN (:seen)` over the run's whole seen set, before `countVanished()` |

Two things fall out with no extra column:

- Rung 4 is `imported_at` inside the freshness window.
- A vanished row simply stops advancing and drops out of rung 4 by itself, so
  upstream removal is a derived fact rather than a number that exists only in
  one run's log. `countVanished()` still reports the count for the desk; nothing
  marks or deletes the row (catalog-data-model.md §4: no auto-retire).

The other candidate fields answer different questions and stay as they are:
`data_provider.last_run_at` is per provider, not per row; `item.updated_at` is
touched by riders and curators too; `item.osm_checked_at` dates the
OpenStreetMap link, not the sighting.

Nothing about Best of waits on this (§6.7.0: the rungs never reach Best of).
What reads this field is rung 4 itself, and therefore how a specialty provider's
rows are drawn between harvests.

#### 6.7.7 The ladder

Built 2026-09-10 as `App\Catalog\EvidenceRung::of()`, one pure function with
no container and no database, so the map, the public API, the curator marker
page and the wiki table can all call it and get one answer. Its inputs are the
custody tier (`App\Catalog\CustodyTier`: `gross`, `specialty`, `ours`), the
last upstream sighting (§6.7.6), the count and newest date of the row's
vouching `item_confirmation` rows (`ConfirmationStance::vouching()`, drawer
only, the same rows the state flip tallies), a published witness date (an
OpenStreetMap `check_date`, a register's dated survey), the clock, the window
(`map.confirmation_stale_months`), the threshold (`map.item_verify_threshold`)
and the receipt behind a verified state (`riders`, `curator` or none).

Ordered by three tie-breaks in this order: the **kind** of evidence, then how
**many**, then how **recent**. Three kinds, and they are not close. A fossil
claim was typed once and carries no interpretable date. A live claim is a source
that republished this record inside the window without retracting it. A witness
is a human at the point on a known date.

| Rung | Evidence | Badge | Grade |
| --- | --- | --- | --- |
| 1 | a fossil claim: a gross provider's row, or our own row nobody confirmed | `?` | claimed |
| 2 | gross provider, live claim | `?` | claimed |
| 3 | specialty provider, fossil claim | `?` | claimed |
| 4 | specialty provider, live claim | `?` | attested |
| 5 | specialty provider with a per-record operational status field (RIVM's `Storing`) | `?` | attested |
| 6 | a witness that aged out of the window; still a witness, so above every claim | `?` | attested |
| 7 | a published witness inside the window | none | attested |
| 8 | one rider inside the window, below `map.item_verify_threshold`; custody does not gate this rung | `?` | attested |
| 9 | the verified state, earned by `map.item_verify_threshold` riders (moderation-and-contribution.md §10.1) | none | minimum |
| 10 | the verified state, earned by one curator's word, below the threshold | none | minimum |
| 11 | the verified state, and five or more riders | none | high |

**Rungs 9 to 11 follow the state and never a window.** "If the `?` mark is
gone, it has verified state" (owner, 2026-09-09) is one rule read in both
directions: a verified row never gets its badge back, whatever the age of its
confirmations. The stale ring (moderation-and-contribution.md §10.1a) is the
freshness signal, the badge is the witness signal, and the two never trade
places. A raised threshold does not unverify a row for the same reason.

The badge column is `EvidenceRung::showsQuestionBadge()`: the `?` is on exactly
the rungs with no witness on record, which is the one sentence of §6.7. The
grade column is `EvidenceRung::grade()`, the coarse word the public API
publishes ahead of the receipt that produced it.

**Custody is read, never judged.** `ItemEvidenceResolver` derives it from the
row and the registry, in this order: a verified row is ours (the riders, or a
curator, took it at the threshold); a provider row whose `data_provider.letters`
carries the row's letter is specialty; any other provider row, and any
`osm` or `wikidata` row with no registry row of its own, is gross; everything
else (a rider's, Scout's or a curator's own row) is ours. `imported_at` counts
as an upstream sighting only on a provider row.

**One resolver, two readers.** `ItemEvidenceResolver::selectSql()` names the
columns a query must add and `fromRow()` reads them; `CatalogProvider` serves
the result as `rung` and `custody` on every point and climb in the map payload,
and the public API publishes the same object as the trust envelope. The ladder
is never re-derived in SQL or in JavaScript.

**The grammar on the map, and the page that checks it.** `styles/pins.css` is
the one definition of the pin; the map, `/map-key` and the curator page
`/curator/markers` (`ROLE_CURATOR`, on the moderation bar as Markers) link it
rather than copying its rules. The DOM pin reads `custody` for its border class
(`dashed`, `disc`, or none for solid paper) and `rung` for its badge class (`q`)
through `pinClasses()` in `web/assets/map/icons.js`; the coverage symbol layer
mints every tile icon twice, plain and `-q`, and picks by the tile's `cd`
against `window.CC_WITNESS_CUTOFF`, the day `map.confirmation_stale_months`
before now (coverage-provider.md §4). The curator page draws all eleven rungs
on a dark and a light ground, so the grammar is checked by eye and not only
asserted.

What the eye check found, 2026-09-10: eleven rungs collapse into six looks,
three borders by two badge states. Rungs 1, 2 and 6 draw alike, so do 3 and 4,
and so do 9, 10 and 11. That is the design working, not a defect: the pin
answers two questions and only two, and the rung number with its receipt lives
in the drawer and the public API. A rider learns six marks, not eleven.

Two rungs the function cannot return yet, stated rather than hidden: rung 5
needs a registry field naming which attribute carries operational state, and
nothing today stores a published witness date for a served row (rung 7 reads
`attributes.check_date`, which no import fills yet). Neither is a gap in the
ladder; both are inputs no writer fills today.

## 7. Citation

The bucket word is not a citation and is never shown. Every drawer line and
every credit is built from the registry row: `name`, `licence`, `homepage`,
`attribution`.

This removes `pivot:'Tourisme Wallonie (CC-BY)'` from
`web/assets/map/i18n.js`. The map receives the provider's citation with the
feature, or resolves it from a small registry payload delivered with the
catalog, rather than holding a table of providers in a front-end constant.

## 8. The curator desk

`/moderate/providers`, `ROLE_CURATOR`, on the moderation bar. One list, one form
per provider, following the desks that already exist rather than inventing a
shape (moderation-and-contribution.md §5).

What a curator can do: add a provider, edit its citation and licence fields, set
its rank and match radius, enable and disable it, run a refresh, and read the
last run's counts and errors.

**Built 2026-09-04**, minus the refresh: there is no harvester to run until §5
exists (phase 4), and a button that cannot do anything is worse than no button.
The last run's counts and errors already render, so the field is ready for it.

**2026-09-05: the source shows, read-only, with the run.** The owner ran the
first real harvest and asked where the source is set and how it runs. Every
non-system row now shows what it fills (letters, country), the service and
its kind, the WFS layer, the field map without its underscore keys, the
cadence, and the exact commands of a refresh in order, dry then `--write`.
Editing those fields, adding a row, and a Run button that crosses the two
containers are filed in docs/TODO.md ("Opened 2026-09-05"). One row is one
provider in one country for one or more letters: another country's register
for the same letter is another row with its own endpoint and field map, which
is why `country_code` and `letters` live on the row and not on the letter.

Since 2026-09-10 the row also carries the three custody settings of §6.7.2:
whether the provider may take a record back, its margin in days, and the
attribute that carries its survey date. All three are on the form, refused
outside their band by the registry like every other field, and recorded in the
row's history when they move.

`App\Provider\ProviderRegistry` is the only writer and the only place the rules
live. A rule enforced in the controller is a rule the next caller does not
have, so the controller validates nothing: it reads the form, hands it over,
and renders whatever refusal comes back. A refusal carries a catalogue KEY
rather than a sentence, so a curator reads it in their own language.

Adding a provider is not on the form yet either. Every field a new row needs is
editable on an existing one, and the shape of "new" belongs with the harvester
that gives a new row something to do.

What a curator **cannot** do, enforced server-side:

- Delete or re-rank a `system` row (OSM, Wikidata).
- Set a rank above the rider sources.
- Enable a provider with no `attribution` when its licence code requires one.
- Run a refresh that would insert more than a configured share of a country's
  existing rows for that letter without a second confirmation. A wrong
  `field_map` on a national dataset is how you flood a catalogue in one click.

Every save and every run is recorded, because a provider's rank decides what
riders see and that is a moderation act. `change_history` could not carry it:
that table is item-scoped (`item_id NOT NULL`). So `data_provider_change`
follows the same shape one table over, append-only, the way
`media_moderation_event` does for photos: one row per FIELD that actually
moved, with who moved it and when. A save that changed nothing writes nothing;
a trail of no-ops is a trail nobody reads.

Licence admission stays a **policy** decision in
[data-source-register.md](data-source-register.md). The desk records the answer;
it does not replace the register's judgement.

## 9. The credits page

`/credits` today lists providers as hand-written rows. Rows for registry
providers are generated from it, so adding the eleventh provider does not mean
editing a template in five languages. The hand-written rows for things that are
not datasets (software, fonts, basemap) stay as they are.

**The handle the credits page calls**, named here so it can be built against
before it exists:

```
credited_providers()      Twig function, App\Twig\ProviderCreditsExtension
```

It returns every enabled `data_provider` row that owes a credit, each carrying
`name`, `full_name`, `homepage`, `licence`, `attribution` and whether the credit
is legally required or courtesy, ordered for display. The template renders one
`.crow` per entry. Nothing else on the page changes.

**Built 2026-09-04.** The contract holds: a provider added to the registry
appears on `/credits` with nobody editing a template, and the hand-written rows
for the datasets the registry now carries are gone. The rows for things that
reach us on demand rather than on a schedule stay hand-written, which is the
line credits-page.md §8.8 draws.

One thing the gate had to learn. `tools/credits/check_credits.py` reads
`data-pkg` markers out of the template to diff them against composer.json and
friends; a generated row's marker is a Twig expression there and read
literally it looked like a package called `p.key`. The gate now skips a marker
carrying an expression, which loses nothing: a generated row cannot drift from
an installed dependency, because it is not claiming one.

### 9.1 Module names

Fixed here so four different pieces of work can target the same names.

| Thing | Name |
| --- | --- |
| Table | `data_provider` |
| Entity | `App\Provider\Entity\DataProvider` |
| Registry service (read, write, validate) | `App\Provider\ProviderRegistry` |
| Rank resolution for the duplicate guard | `App\Provider\ProviderRank` |
| Curator desk controller | `App\Controller\ModerateProvidersController` |
| Route | `moderate_providers` at `/moderate/providers` |
| Credits Twig function | `credited_providers()`, `App\Twig\ProviderCreditsExtension` |
| Map citation payload | `App\Provider\ProviderCitations` |
| Harvester (fetch and reproject) | `pipeline/providers/` |
| Harvest entry point | `app:providers:harvest` |

`App\Provider` is a new top-level module beside `App\Catalog`,
`App\Coverage` and `App\Messaging`, because it is owned by neither: the
catalog consumes its rows, the coverage cache is suppressed by them, and the
credits page and the map both cite it.

### 9.2 Language: facts come from the registry, prose stays translatable

Raised by the credits work, 2026-08-27, and it is the one thing that would have
broken on landing. Today each provider's sentence is a translated message key
(`credits.osm_p`, `credits.overture_p`), so `/fr/credits` reads as French. A row
generated from a database table has one string, in one language, and would put
an English paragraph inside a French page for four of five locales.

The fix is to stop treating a credit row as one thing. It is two.

**Facts, from the registry, never translated:**
`name`, `full_name`, `homepage`, `licence` code and label, `attribution`,
release year. A licence name is not prose. The `attribution` line especially
**must never be translated**: it is the exact wording a licence obliges us to
show, and the credits page already marks such rows as required rather than
courtesy.

**Prose, one sentence per provider, translated:** what *we* use the dataset for.
That sentence is copy about us, not about them, and it is the only translatable
part of the row.

Two columns carry it, and the renderer prefers the first:

| column | for | behaviour |
| --- | --- | --- |
| `blurb_key` | the seeded rows and anything added in git | a message key, e.g. `credits.osm_p`. Rendered with `trans`, so **every existing translation survives untouched.** |
| `blurb` | rows a curator adds at `/moderate/providers` | free English text, held in the registry |

`osm`, `wikidata` and `wallonie-pivot` are seeded with their existing keys, so
the migration of §10 changes no wording in any language and deletes no
translation.

**A curator cannot write Spanish, so the site translates it the way it already
translates everything else.** A `blurb` is registered with the in-site
translation system as a `translation_entry` whose `message_key` is synthetic:
`provider.<key>.blurb`. That fits the table's own rule, "identity is
`message_key`, never display wording" (translations.md §3.1), and needs one
change: the sync that projects `messages.en.yaml` into `translation_entry` also
projects registry blurbs. Everything downstream is unchanged, because overlays
resolve by key and do not care where the English came from.

Until a translation is approved the English shows, which is exactly what the
overlay system already does for a new YAML key. A provider added on Tuesday is
therefore correct in English immediately, and correct in five languages as soon
as the queue clears, with no deploy either time.

**What `credited_providers()` returns per row is therefore:** the facts as
values, plus a resolved sentence, already translated by the time the template
sees it. The template renders and does not choose.

### 9.3 One row per provider does not survive a registry of hundreds

Raised by the credits work, 2026-08-27, and accepted: §9's "one `.crow` per
entry" and "lots and lots of these worldwide" cannot both hold.

The page already solves this for software, in two weights. Notable dependencies
get a row and a sentence; the long tail is a comma-separated run of linked
names, and 42 packages fit in five lines.

**The split is decided by the licence, not by taste.** That is the part worth
keeping:

| The licence says | Weight | Why |
| --- | --- | --- |
| an attribution notice is **required** (ODbL, CC BY, CC BY-SA) | its own `.crow` with the required marker | a mandated text has to be *displayed*. A bare name in a comma run does not display it. |
| nothing is owed (Public Domain Mark, CC0) | one linked name in the comma run | naming them is decency, not obligation, and a link names them |

The required set stays small by its nature, so the page survives a registry of
hundreds without anyone deciding what is important.

`data_provider` therefore stores no "prominence" column. The weight is derived
from `licence`, the same code §8 already uses to refuse enabling a provider that
owes an attribution and has none. A curator cannot promote a row by preferring
it.

**One deliberate exception, and it is bounded.** The licence sets the floor, not
the ceiling. A `promoted` flag, default false, lifts a courtesy provider into a
full row. It exists because the owner asked for exactly this case: the Dutch tap
register is public domain and therefore owes nothing, yet its **creator**
deserves naming, and a comma run cannot carry that sentence. Promotion is an
explicit act on the desk, recorded in moderation history like every other
provider change, and the desk shows how many promoted rows exist so the count
cannot creep unnoticed.

**Publisher and creator are different fields.** `full_name` is who publishes.
`creator` (nullable) is who made the dataset, when that is somebody else. For
the Dutch taps: publisher RIVM, creator drinkwaterkaart.nl, registry the
Kadaster's Nationaal Georegister. A credit that names only the publisher credits
the pipe rather than the person, which is the mistake the current hand-written
row makes.

## 10. Migration of the existing 150 rows

1. `ALTER`: no schema change to `item.source`, which is `varchar(10)`;
   `authority` is nine characters.
2. Seed `data_provider` with `osm`, `wikidata` (both `system`), and
   `wallonie-pivot`.
3. `UPDATE item SET source = 'authority' WHERE source = 'pivot'`, and point those
   rows at the `wallonie-pivot` registry row.
4. Existing `source_ref` values such as `fx:pivot:hotel-koru|ramillies` are
   **left alone**. They are historical upsert keys, not display strings, and
   rewriting them would break the one thing they are for.
5. `ItemSource::Pivot` is removed in the same commit as the migration, so the
   enum and the data never disagree. Everything reading it is listed in §12.

## 11. First real-world test: the Dutch drinking-water taps

Registry row:

| field | value |
| --- | --- |
| `key` | `rivm-drinkwater` |
| `name` | RIVM |
| `full_name` | Openbare drinkwaterkranen, RIVM / Atlas Leefomgeving |
| `homepage` | https://www.atlasleefomgeving.nl/openbare-drinkwaterpunten-0 |
| `licence` | Public Domain Mark 1.0, `http://creativecommons.org/publicdomain/mark/1.0/deed.nl`. The record's use limitation reads "geen beperkingen"; data.overheid.nl dataset 30660 lists it as publiek domein. |
| `country_code` | NL |
| `letters` | B |
| `endpoint` | https://data.rivm.nl/geo/alo/wfs |
| `endpoint_kind` | `wfs` (layer `alo:rivm_drinkwaterkranen_actueel`) |
| `match_radius_m` | 50 |
| `refresh_cadence` | twice yearly, matching the publisher |

Field map: `beschrijvi` to the note, `plaats` to the town, and `type` twice
through a value map (2026-09-05): `Regulier, 24-7 open` / `Alleen overdag
bereikbaar` to `availability` (Always / Daytime only), `Storing` to
`condition` (Out of order). Both are form fields on letter B, so a rider can
correct what the register says. And `potable` is `Yes (public supply)` for
every `type` the register uses (`Version20260905010000`): being in the
national drinking-water register IS the answer, and without it the map drew
the "nobody said" drop on every RIVM tap. What is true of every tap in the
register is said once on the row too (`Version20260905030000`, owner
2026-09-05): `cost` Free, `bottleFill` Yes, `seasonal` Frost-shut in winter.

Measured against the harvest on 2026-08-26 (3287 upstream points, 2744 OSM
`amenity=drinking_water` nodes in NL):

- about **2418 attach** to an existing OSM node at 50 m,
- about **869 insert** as places OSM does not have,
- **229 OSM taps** have no upstream counterpart and stay exactly as they are.

Those numbers are the acceptance test. A run that produces wildly different ones
means the matcher is wrong, not that the data changed.

**Run for real on 2026-09-04, against the live service and the dev catalogue.**
The fetch returned **3287 features, 0 refused**, which is the 2026-08-26 count
exactly. The ingest, dry:

| | count |
|---|---|
| read | 3287 |
| attach to an OSM node | **2429** |
| insert with no counterpart | **854** |
| update (two upstream records rounding to one ref) | 4 |
| left to a rider | 0 |
| contested (a node a nearer record already holds) | 9 |

**The first run found two defects, and both are fixed rather than accepted.**

*One node, one claim.* The matcher attached the nearest node within the radius
without asking whether another record already held it, so 10 nodes out of 2515
were claimed by two taps each. Two items pointing at one `osm_ref` breaks the
suppression that hides the raw pin: it assumes a single claimant. The nearest
record now takes the node and the next inserts unattached, which says "a real
place we could not tie to a node" rather than a wrong tie.

*A letter is not a kind.* §5 said "restricted to the provider's letters", and
letter B holds **7024** rows in the Netherlands: 2744 `amenity=drinking_water`,
137 `water_point`, 150 toilets, 35 cafés, 15 fast food and 3910 carrying no
`amenity` at all. Matching by letter alone tied public taps to the café across
the road and put the attach count 4% above the number measured for taps alone.
`data_provider.match_tags` narrows it, and the numbers say the narrowing is
right: with `amenity=drinking_water` alone the run attaches **2416**, which
reproduces the 2418 above almost exactly. The configured value also accepts
`water_point`, because OSM uses it for the same street tap often enough that
excluding it inserts a second pin beside a mapped one; that is the +13 between
2416 and 2429.

The third number is NOT reproduced and the difference is understood: 229 was
counted against the 2744-node snapshot of 2026-08-26, and the dev coverage
cache has been re-harvested since. Nothing in it is a matcher question.

**The row is seeded PAUSED, and no rows have been written.** `/credits` lists
every enabled provider, and until a harvest has run there is not one RIVM row
on the map; naming them would be a claim about the future on a page whose job
is to be true, which is why the Georegister row is commented out. Enabling it
and running with `--write` is one deliberate operator act:

```
make provider-fetch   key=rivm-drinkwater out=/tmp/rivm.json
make provider-harvest key=rivm-drinkwater file=/tmp/rivm.json          # dry
make provider-harvest key=rivm-drinkwater file=/tmp/rivm.json write=1
```

**Two mapping decisions worth knowing.** `beschrijvi` feeds the NOTE, not the
name: it runs to 254 characters and reads as a paragraph, and `item.name` holds
200, so these rows carry no name, which is honest because RIVM does not name
its taps. RIVM's own `type` (`Regulier, 24-7 open` / `Alleen overdag
bereikbaar` / `Storing`) is dropped for now, because letter B has no field that
means availability and filling an attribute with no form field behind it would
break the rule that anything filled in for a rider must be editable by one. It
goes in when B gains an availability field.

**Filled since 2026-09-05.** Letter B gained the `availability` field
(Unknown / Always / Daytime only / Ask or behind a gate,
docs/specs/edit-items/B-water-food.md), so the register's `type` now lands
through a value map: 24-7 and daytime to `availability`, `Storing` to
`condition`. `Version20260905000000` rewrites the RIVM row's field map. The
clock badge (§6.3a) reads `availability` beside `seasonal`, and the red `!`
reads `condition`, so the 152 daytime-only and 68 broken taps show as such the
day the row is enabled and harvested.

**Licence discipline for this row.** The Public Domain Mark 1.0 statement covers
the RIVM publication, dataset 30660 on data.overheid.nl. It does **not** cover the GPX
download on drinkwaterkaart.nl, whose own page says "voor eigen gebruik". We
ingest the RIVM service and nothing else until its maintainer says otherwise.
The RIVM copy is refreshed twice a year and currently carries November 2025 data,
so it runs roughly eight months behind him: that staleness is the price of the
clean licence, and it is the reason to talk to him
(see "Drinkwaterkaart.nl" in `docs/TODO.md`).

## 12. Everything that reads `pivot` today

The rename is mechanical but wide. Listed so the work can be checked off rather
than discovered:

- `web/src/Catalog/ItemSource.php` (the enum and `dedupeRank()`)
- `web/src/Catalog/Entity/Item.php` (docblock example)
- `web/src/Coverage/CoverageRepository.php` (docblock example)
- `web/assets/map/i18n.js`, `catalog-load.js`, `places.js`, `item-index.js`
- `web/templates/pages/credits.html.twig`, `licenses.html.twig`
- `web/tests/Catalog/ItemSourceRankTest.php`, `CatalogProviderTest.php`,
  `DuplicateGuardTest.php`, `web/tests/Command/DedupePlacesCommandTest.php`
- `tools/wallonia/pivot.py`, `tools/wallonia/export.py`, `atlas/demo/stays-pivot.js`
- `docs/specs/catalog-data-model.md` (§5, §5a), `coverage-provider.md`,
  `data-source-register.md`
- `wiki/data-priority.md`, `wiki/developers/gis-beyond/reprojection.md`
- the five `web/translations/messages.*.yaml` catalogs

## 13. Phases

1. **Registry and rename.** ✅ Built 2026-09-04. Table, seeded rows, the
   migration of §10 plus the `item.provider_id` link of §3, the enum change,
   and the doc and wiki sweep of §12. No behaviour change a rider can see: the
   seeded ranks reproduce the old ladder, and `dedupeRank()` still returns one
   fixed step for every authority. Reading the rank from the registry (§4) is
   deliberately NOT in this phase; it lands with the harvester, when there is
   a second authority for it to order.
2. **Citation from the registry.** Drawer and credits read the table. The
   hardcoded string goes.
   - **The drawer half is built (2026-09-04).** The catalog payload carries a
     `providers` map (`App\Provider\ProviderCitations`) and every authority
     feature carries `pk`, its publisher's slug. `assets/map/drawer.js` looks
     the citation up in what it was given, so a provider added at the desk is
     credited with no deploy. `authority:'Tourisme Wallonie (CC-BY)'` is gone
     from `assets/map/i18n.js`, and the "Listed" row's wording no longer names
     one publisher: it reads "Official registry entry" with the publisher as
     the method, in all five catalogues.
   - **The credits half is built too (2026-09-04).** `credited_providers()`
     (`App\Twig\ProviderCreditsExtension`) generates the data group, split by
     what the licence obliges (`App\Provider\LicenceObligation`): a notice
     owed gets its own row with the required marker, a notice owed to nobody
     gets a linked name in the comma run. The five datasets the page named by
     hand became registry rows in `Version20260904140000`, each taking the
     message key the template already rendered, so no wording changed in any
     language. The parked Georegister and Drinkwaterkaart rows stay parked:
     seeding them would put them back on the page.
3. **The curator desk.** ✅ Built 2026-09-04. §8, minus the refresh button and
   the add-a-provider form, both of which wait on the harvester below. The four
   server-side refusals are enforced in `ProviderRegistry` and tested: a system
   row cannot be deleted or re-ranked, a rank must sit inside the band, a
   provider that owes an attribution cannot be served without one, and a
   provider whose rows are on the map cannot be deleted at all (pause it: that
   keeps the rows and stops the refreshing).
4. **The generic harvester.** ✅ Built 2026-09-04. §5, in two halves meeting at
   a normalised file: `pipeline/providers/` fetches, `app:providers:harvest`
   matches and writes. `wallonie-pivot` was NOT moved onto it: its rows come
   from a committed fixture export rather than a live service, so pointing it
   at a WFS is a data-source decision for the register, not a refactor.
5. **The Dutch taps.** ✅ Built 2026-09-04. §11, and it WAS the intended path:
   a registry row plus a field map, no new code. Its first real run found the
   two matcher defects recorded above; the acceptance numbers hold once they
   are fixed. Seeded paused. **Enabled by the owner on 2026-09-05 and
   harvested on dev the same evening:** 3287 read, 2429 attached, 854
   inserted, 9 contested, 0 refused; 152 daytime-only and 68 out of order
   through the value map. That run found that the client dedupe list
   (`CatalogProvider::curatedRefs()`) shipped only `source='osm'` refs, so
   every attached tap drew twice; it now ships the `osm_ref` twins too, the
   same key CoverageRepository joins on (§4.1).
6. **The pin styling and the legend.** §6, last, because it touches every layer
   and wants the other five settled first. §6.5 is a blocking decision inside
   this phase: the grey collision on water is resolved before a pixel changes.
   The generated legend panel (§6.6) ships with it, not after it, because a new
   marker tier with no key is how a map stops being readable.

## 14. Open questions

- **Does an authority row's attached OSM twin keep contributing facts?** An
  attached row hides the OSM pin, but the OSM node may carry tags the authority
  lacks (`wheelchair`, `opening_hours`). Merging both into one drawer is
  attractive and is not specified here, because it needs a rule for what happens
  when they disagree.
- **What does "community_edited" mean exactly?** A photo? A confirmation? A
  field edit? The flag decides pin colour, so the trigger list must be written
  before §6 is built.
- **Per-country rank.** A provider may be authoritative in one country and not
  in the next. `rank` is one number today; whether it needs to vary by country
  is unanswered and deliberately deferred.
