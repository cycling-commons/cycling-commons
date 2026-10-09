# Data provider hierarchy and the provider registry

**Status: built.** The registry, the ranked keeper order, the generic
harvester, the curator desk, citations from the registry, and the pin grammar
with custody and evidence (§6.3a, §6.7) all run. The Dutch public
drinking-water taps (`rivm-drinkwater`, §11) were the first real-world test.

Related, and deliberately not merged into this document:

- [data-source-register.md](data-source-register.md) is the **policy** register:
  which sources we may use at all, and on what licence. It decides admission.
- This document is the **runtime** register: how an admitted source is stored,
  ranked, refreshed, cited and drawn. It decides behaviour.
- [catalog-data-model.md](catalog-data-model.md) §5 owns `item.source` and the
  duplicate guard's keeper order; this document defines the `authority` value
  and the registry rank that order reads (§4).

## 1. Why a registry

**1.1 A bucket named after its first member says nothing.** The first non-OSM
dataset was Tourisme Wallonie PIVOT, and a source value named after it could
not say what the value means for the next dataset.

**1.2 A hardcoded citation fits one provider.** A citation string in a
front-end constant fits one provider; the owner expects "lots and lots of these
external providers world wide". Every citation therefore comes from the
registry (§7).

**1.3 "External" or "provider" separates nothing**, and the owner said so:
OpenStreetMap, Wikidata and PIVOT are all external, all providers. **The axis
that does separate them is hierarchy**: how far a record's origin outranks
another when two of them describe one real place.

## 2. The name: `authority`

The value is **`authority`** (`ItemSource::Authority`), and the concept is the
**authority registry**.

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

The word is also nearly invisible in the interface. A rider sees **RIVM** or
**Tourisme Wallonie**, never "authority" (§7).

## 3. The registry

The table `data_provider` (`App\Provider\Entity\DataProvider`, created by
`Version20260904110000`), one row per dataset. Curator-maintained (§8).

| column | meaning |
| --- | --- |
| `id` | |
| `provider_key` | stable slug used in `source_ref` and in URLs, e.g. `rivm-drinkwater`, `wallonie-pivot`. Immutable once rows reference it. |
| `name` | what a rider sees: "RIVM", "Tourisme Wallonie". |
| `full_name` | the long form for the credits page. |
| `homepage` | where the citation links. |
| `licence`, `licence_code` | free text plus a code, e.g. `public-domain`, `cc-by-4.0`, `odbl`. The code also decides the credit's weight (§9.3). |
| `creator` | who made the dataset, when that is not the publisher. drinkwaterkaart.nl for the Dutch taps. Null when publisher and creator are the same. |
| `promoted` | false by default. Lifts a courtesy credit into a full row (§9.3). |
| `attribution` | the exact line we are obliged to show, when the licence names one. |
| `country_code` | null for worldwide. |
| `letters` | which catalogue letters this dataset fills. |
| `rank` | where it sits in the hierarchy (§4). |
| `community_edited` | false while nobody here has touched a row. Stored, read by nothing: custody is drawn on the border (§6.7), not by this flag. |
| `endpoint` | URL of the machine-readable source. |
| `endpoint_kind` | `wfs`, `geojson`, `csv`, `arcgis`. |
| `field_map` | JSON: which upstream field feeds which of our attributes. An entry is the upstream field name, or `{"from": <field>, "values": {<theirs>: <ours>}}` when the upstream values must land in one of our form vocabularies; a value the map does not name is dropped (`apply_field_map`). |
| `defaults` | JSON, per letter: what a harvest fills in where the provider's own data is silent, in that letter's form vocabulary, e.g. `{"B": {"cost": "Free"}}` (§5.2). Never `condition`. |
| `match_radius_m` | how close an upstream point must be to an OSM node to count as the same thing (§5). |
| `match_tags` | JSON object of OSM key to accepted values a counterpart must carry, e.g. `{"amenity": ["drinking_water", "water_point"]}` (§11). |
| `refresh_cadence` | how often the harvest should re-read it. |
| `last_run_at`, `last_count`, `last_error` | what the desk shows. |
| `enabled` | off means "keep the rows, stop refreshing". It controls the harvest and nothing else: a paused provider stays credited while any of its rows is served (§9). |
| `system` | true for seeded rows nobody may delete (§3.1). |
| `blurb_key`, `blurb` | the translatable sentence on `/credits` (§9.2). |
| `may_reclaim`, `reclaim_margin_days`, `survey_date_attribute` | custody take-back (§6.7.2). |

**How a row points at its provider: `item.provider_id`.** A nullable foreign
key, set exactly when `item.source` is `authority` and NULL for everything
else. `source_ref` cannot answer this: it is the harvest's own upsert key, and
the Wallonia refs (`fx:pivot:hotel-koru|ramillies`) name a bucket word rather
than a provider. Added by `Version20260904110000` alongside the table.

### 3.1 OpenStreetMap and Wikidata are registry rows too

They are seeded with `system = true`, cannot be deleted, and their `endpoint`
and `field_map` are ignored because their harvests are their own code. They live
in the registry so that **one table answers "who do we cite, and under what
licence"** for every row on the map. A credits page assembled from two places
drifts; this one has a single source (§9).

Their ranks are seeded (`osm` 100, `wikidata` 200) and are not curator-editable.

## 4. Hierarchy

The keeper order is:

`manual` > `user` > `scout` > **the registry's `rank`** > `auto`

`osm` (rank 100) and `wikidata` (rank 200) keep their positions as seeded
registry rows whose rank the desk refuses to move. An `authority` row takes
its own provider's rank through `item.provider_id`, so two providers holding
one place are ordered by the numbers on the Providers desk, and a provider
ranked below 100 loses to OpenStreetMap. A new authority is admitted somewhere
above `osm`, and the desk shows a curator exactly which existing datasets it
would outrank before they save.

`App\Provider\ProviderRank` is the one resolver: the rider sources sit past
`ProviderRegistry::RANK_MAX`, so no typed rank reaches them; `osm`, `wikidata`
and each provider read their registry row; an `authority` row with no provider
sits just above Wikidata; `auto` sits below 0. Both places that pick a keeper
read it, fresh for each sweep: `app:catalog:dedupe`, and the scan behind the
Data desk's duplicate findings (`CatalogScanner::duplicateGroups()`), whose Yes
keeps the ranked row. A rank saved on the desk decides the next run. The
import report's "outranks" hint (`DuplicateGuard::explain()`) compares sources
on the fixed `ItemSource::dedupeRank()` ladder; it decides nothing. Pinned by
`ProviderRankTest`.

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

### 4.1 Hiding the OSM pin

When an authority row outranks the OSM node for the same place, the OSM pin
disappears rather than sitting beside it. `item.osm_ref` is the join key to
`coverage_poi.ref`: `CoverageRepository` suppresses a coverage POI whose ref is
claimed by a served item, and the catalog payload's `refs` list carries every
served row's `osm_ref` twin for the client (coverage-provider.md §5, §6;
osm-data-architecture.md §8). The ingest's job is to **set `osm_ref`
correctly** (§5), and the existing join does the rest.

**Only a served row holds a node**, in the ingest as on the map. The ingest
skips a node only when a served row already holds it. Counting a submission
still waiting for a curator would let a rider's pending correction of an OSM
tap keep RIVM's record of the same tap from its node, and the map would show
three pins for one tap. The provider's row takes the node; approving the
rider's place retires that row (catalog-data-model.md §5a).

## 5. Ingest: match, then attach or insert

One generic harvester, configured per registry row. The fetch lives in
Python, because reading a geospatial service is squarely on the Python side of
the boundary.

**Two halves that meet at a file.** `pipeline/providers/`
fetches and normalises; `app:providers:harvest <key> <file>` matches and
writes. The file between them is the same shape `app:catalog:import` already
reads, which is what lets the tabular half be tested against a fixture instead
of against a publisher having a good day. The ingest is DRY by default: it
inserts into the catalogue riders read, so seeing the counts first is the
normal way to run it and `--write` is the deliberate second step.

**One command: `tools/provider-run.sh <key> [--write]`.**
The two halves run in two containers that share no filesystem, so the file
travels through the script: `providers.run --out -` writes the normalised
GeoJSON to standard output (its summary goes to standard error), the script
holds it in a private temporary directory, and `app:providers:harvest <key> -`
reads it on standard input. Holding it rather than piping straight through is
what keeps a failed fetch from reaching the ingest, where it would read as a
malformed file and land in `last_error` as the wrong cause. The script exits
non-zero with a message on any failure, and both halves refuse a paused
provider. A service that is up is used with `exec`; one that is not (the
pipeline on a worker host is a batch image with no resident process) runs the
command once with `run --rm --no-deps`. Where each half runs is a flag:

| Where | Command |
|---|---|
| Dev stack | `make provider-run KEY=rivm-drinkwater [WRITE=1]` (the script with `developers/docker/compose.yaml`, services `pipeline` and `app`, the ingest as `DEV_UID:DEV_GID`) |
| Staging, production | on the worker host as `deploy`, from its copy of the release: `tools/provider-run.sh --worker-dir /opt/workers/cyclingcommons-<env> <key> [--write]`, which means `compose.pipeline.yaml` service `pipeline` and `compose.compute.yaml` service `worker` in that directory; `--pipeline-compose`, `--pipeline-service`, `--app-compose`, `--app-service` and `--app-user` override one part each |

**No cache is cleared after a run, and none needs to be.** Every catalogue
table the payload reads carries a `catalog_change` trigger, so a written
harvest moves its regions' stamps at commit and the map's documents rebuild on
their next request (catalog-data-model.md §9.1); a dry run rolls back and moves
nothing. The one cache keyed on nothing the triggers see, the provider
citations, is dropped by the ingest itself after a write. Both are pinned by
`ProviderHarvestTest`.

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

**`wallonie-pivot` does not run on it, and moving it is not a refactor.**
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
   It is left in place and counted as `stale` in the run summary
   (`countVanished()`), because "the publisher dropped it" and "the publisher's
   export broke" look identical from here; its `imported_at` stops advancing
   (§6.7.6).

6. **A harvested row enters `unverified`**, like every row
   (catalog-data-model.md §5): verification is a rider standing there, never
   provenance, so it draws the dashed "?" pin until one does (owner:
   "according to the legend this should be the icon while not confirmed by one
   of our users"). The register's authority lives in its rank.
7. **Every row gets its region.** After the pass, the run stamps `item.region_id`
   for the provider's rows with the same smallest-area-wins rule the catalogue
   import applies (catalog-data-model.md §6). Under a region scope the map
   hides a served row that has none, and the OSM tap it replaced is hidden by
   the dedupe, so a row without a region would leave a scoped rider with no
   water at all.
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
   and leaves the geometry alone when `location` is in the history, so a
   refresh never undoes a rider's "Not there anymore", note, photo or
   relocation.
10. **A default fills a gap, never a value.** After the field map, and on a
   refresh after the merge of rule 9, the provider's defaults (§5.2) fill
   each attribute that is still absent or empty. A field a person changed is
   never a gap, even when they emptied it.

### 5.1 The match radius is a judgement, and it is per provider

For the Dutch taps, 69.6% of upstream points sit within 25 m of an OSM node and
78.4% within 250 m. The gap between those two numbers is mostly genuine
positional disagreement about the same tap, not different taps. A 25 m radius
under-matches and creates duplicate pins; 250 m over-matches and can swallow two
real taps at either end of a square. The starting value is **50 m**, and the
desk shows a curator the match counts at 25, 50, 100 and 250 m before they
commit, because this number cannot be guessed from a form.

### 5.2 Defaults: what is true of every record

Some facts are true of every record in a register, and for that reason are
not a column in it: every Dutch RIVM tap is a drinking tap, free,
bottle-friendly and shut against frost in winter. The registry row says them
once, in `data_provider.defaults` (owner 2026-10-01: "The harvester should be
able to set defaults for these fields. Only 'Still as mapped?' should be empty
by default; that must be confirmed by users").

**Field map versus defaults.** The field map carries what the provider's
DATA says, record by record. A default is what the provider's ROW says of
every record. Faking a constant through the field map (every value of an
upstream column mapped to the same answer) would drop it the day upstream adds
a new value. A field map entry always wins over a default: a default only
fills an attribute the record left empty.

**Shape: per letter.** `{"B": {"type": "Drinking tap", "cost": "Free"}}`. The
vocabulary is per letter (`type` is a different list on B and on Q), and one
row may fill several letters, so a flat map could not say which list a value
belongs to. A key for a letter the row does not fill is refused.

**The rules, all in `App\Provider\ProviderDefaults` and enforced by
`App\Provider\ProviderRegistry`:**

- A default is a choice a rider could make: only a single-choice field of the
  letter's edit form (`CatalogFormRegistry`), and only one of that field's own
  choices. Free text, website, links and multi-choice fields are refused: a
  default note would be the same sentence on thousands of places, and nobody
  can check that it is true of each.
- A default never claims somebody looked. `condition` ("Still as mapped?") is
  refused with its own message, and so is a hazard's `stillPresent`; only a
  rider standing there answers them (catalog-data-model.md §7).
- A default only fills a gap (§5 rule 10): never over the provider's own
  value, never over a value already stored on the row, never on a field a
  person changed.

The harvest applies them in PHP (`ProviderHarvest`, on insert and on
refresh), because the app owns the vocabulary they are validated against; the
Python normaliser only applies the field map. A change to a row's defaults is
recorded in `data_provider_change` like every other field, and takes effect
on the next refresh.

## 6. Drawing the map: who keeps the record, and whether anybody stood there

A rider reads two facts off a pin that came from a provider: who keeps the
record (custody, the border) and whether anybody has stood there on record
(evidence, the `?` badge). §6.7 is that grammar. The first proposal
(owner 2026-08-27) drew untouched authority pins in greyscale; it was not
adopted, because custody on the border spends no new channel and keeps
saturation free (§6.3, §6.5). Two conditions hold for every provider mark.

### 6.1 Colour is never the only signal

WCAG 1.4.1: a rider who cannot see the difference, or is in bright sun on a
phone, must still be told. The drawer says it in words, from the registry: the
publisher, its licence and the record's evidence. The pin is the fast signal,
the sentence is the real one.

### 6.2 A provider mark must not collide with the thing's own status

RIVM flags taps as out of order (`type = Storing`) and others as daytime-only.
A rider must never misread a provider mark as "broken". A broken or restricted
tap therefore carries its own state mark (§6.3a), independent of the border,
and that state wins the drawer's first line. This is the most valuable field
the Dutch dataset carries and OSM has no equivalent of it, so it may not be
lost to a styling rule.

### 6.3 The marker's channels are a fixed budget, and they are nearly spent

A marker can carry a limited number of independent signals before it stops being
readable. There are ten:

| Channel | Answers | State |
| --- | --- | --- |
| Shape (small disc vs teardrop) | which store the record lives in | taken |
| Fill colour | which category | taken |
| Glyph | which KIND, within the category (§6.3a) | taken |
| Border colour and style | custody (§6.7: small disc, dashed, solid paper) and pending moderation | taken |
| Ring | going stale, and selection | taken |
| Badge, top right | no dated witness on record (`?`, §6.7) | taken |
| Badge, top left | the thing's own STATE (§6.2, §6.3a) | taken |
| Saturation (greyscale) | nothing (§6.5) | free |
| Size | nothing | free |
| Cluster bubble | density | taken |

Two channels are left, saturation and size. That is the entire budget for
every dataset we add after this one, so it is not spent on a first-come basis.

**The badges' look.** The "?" (top right) is 15px on a full-size teardrop and shrinks with it (map-and-search.md). On an OSM disc
it is the tile icon's badge, 13 of the tile's 24 units, scaled with the disc
(map-and-search.md). On both, the badge's centre lies outside the pin's rim,
so it never covers the glyph; a badged tile icon is minted on a 32-unit box to
leave it that room; the red "!" and the clock
(top left) 17px, with the clock's drawing at 11px (`pins.css`); smaller, the
"?" and the clock are hard to read on a pin. The "?" is ochre on an ink disc
inside a thin paper ring, 8.5:1, centred by flex; ochre on the disc's own fill
vanishes on a pale pin and on the light basemap. The key page's `.cc-q` row
and the badge minted on tile icons (`drawBadge()` in `icons.js`) use the same
colours.

**Placement is not a channel.** Pins on one spot fan out
around it, each with a thin leader back to a dot on the true point
([map-and-search.md](map-and-search.md), Pins on one spot fan out). The
leader and the dot say only "this pin stands over there"; they carry nothing
about the place, so they spend none of the budget above.

### 6.3a The pin grammar (owner)

The owner, on being shown the water/food split: "water has potable yes/no,
still there, broken, only on time xy, closed during autumn winter. Bike
services has other kinds of traits, bike shop or Shimano self repair stand,
pump yes no etc. So apples and oranges." No single symbol grammar carries every
category's traits, so the grammar is this, and the owner accepted it the same
night:

> **Kind lives in the glyph. State is two shared badges. Everything else is
> drawer content.**

- **Kind** is per category and never compared across categories. Water &
  food: drinking tap (blue drop), tap not for drinking (the drop with a red
  bar across it, the red of the "!" badge), tap nobody has said anything about (the drop unfilled), food
  stop (fork and knife on the category disc), food stop that also gives water
  (the same with a small blue drop). Bike services: shop, repair stand, pump.
  Scenic views and history & culture: one glyph per kind on the category
  disc, one OSM tag per kind (a waterfall, a castle; [osm-data-architecture.md §5a](osm-data-architecture.md)).
  A place with no kind yet keeps the category glyph.
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
it. The tile carries `potable` as `yes` / `no` / absent, so "tagged no" and
"nobody said" stay apart, and `food` for the shop and eatery half
(coverage-provider.md §4).

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

### 6.5 Grey carries no meaning on water

Potability lives in the KIND glyph (§6.3a: a barred drop, an unfilled drop),
so grey means nothing on a water pin and saturation stays free. Whatever
claims it later carries one meaning only: two meanings on one channel are not
allowed.

### 6.6 The legend

Two decisions, owner 2026-08-27.

**The legend is generated, never hand-written.** A hand-maintained key drifts
from the code within a month. It reads from the same definitions the map styles
itself from: the layer table in `web/assets/map/catalog.js` for categories,
`KindIcons` for kinds, `styles/pins.css` for the pin grammar, and the registry
for citations.

**The legend lives in the app, one tap from the map.** It is the map's Key
rail panel and the `/map-key` page (map-and-search.md §4.7), both drawing the
real `.cc-pin` classes (§6.7.5). The line legend (`syncLegend()` in
`web/assets/map/panels.js`) shows only the line layers actually on the map;
the panel and both line keys are served `hidden` and shown once a line layer
is on, so nothing flashes on load (owner 2026-10-01).

**The layer panel is the category key.** Each row is the category's own swatch
beside its name, in the same colour and from the same icon set as the pin
(`ItemType::iconSet()` feeds both shapes, and the catalogue letter decides
which half of the panel a category lands in, A to M practical, N to Z
experiential). A rider matching a tile to a pin needs no explanation. The key
adds the other axis: the state and evidence signals of §6.3, which ride on top
of any category.

### 6.7 Custody and evidence: two axes, one mark each

**Owner ruling 2026-09-09, built.** The ladder (§6.7.7), the per-row upstream
sighting (§6.7.6), the marker grammar, the public API's trust envelope and
custody take-back (§6.7.2) all run. Custody is on the border, not on
saturation; §6.5's rule, one channel carrying one meaning, is what forces the
shape below.

A rider asks two different questions, and each gets its own signal:

- *Who keeps this record?* That is **custody**. It never says the record is worse.
- *Has anybody stood here?* That is **evidence**. It never says who owns it.

Mixing them on one mark would contradict itself (§6.7.5).

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

**There is no paper dot.** Absence of the `?` is the verified signal on every
tier, so our tier reads by the same rule as the provider tiers and a rider
learns one symbol, not two. That keeps a mark free in a budget §6.3 calls
nearly spent.

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

**Authority rows do not count toward readiness.** `CuratedReadiness` counts no
row for being authority-sourced: imported viewpoints, historic sites and sleep
spots that nobody here has rated are not curated content. (Counting them made
North Holland read 840 countable items where 15 were rider-backed.) A region
with 15 pieces of curated experiential content has 15, and the honest answer
is that it is not ready to open in Best of by default, which is what
`legend.tier_onboarded_desc` says out loud: "on the map, waiting for its first
verified entries".

The count is **not** to be restored by another route. Either the readiness gate
is redefined around what Best of actually ranks, or regions stay un-unlocked
until rider-backed content exists. Counting unrated imports would put a Best-of
view in front of riders built from places no rider chose.

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

Three settings on the registry row, all on the providers desk (§8): `may_reclaim` (off by default), `reclaim_margin_days` (1 to 365, 30
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
with no `?`. The same attribute is the published witness rung 8 reads for a
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

**As built.** The public half: the map payload carries `reclaimed`
(the survey date, `YYYY-MM-DD`) on a point only when the register took it
back, and the drawer body prints `map.d_provider_survey` from it. The private
half: `GET /items/{id}/mine` (`ItemConfirmationController::mine`) answers
`{confirmed_at}` for the signed-in rider's own newest drawer confirmation,
`private, no-store`; for an anonymous request it answers `{confirmed_at: null}`
before touching the database, and `ItemPersonalNoteTest` proves it with an id
that exists nowhere. `loadMine()` in `web/assets/map/drawer.js` is gated on the
signed-in marker, memoised per item id, and paints nothing on failure; it
prints `map.d_personal_reclaimed` when the rider's date is older than the
survey and `map.d_personal_confirmed` otherwise. Both sentences state the
rider's fact; neither subtracts it.

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

Four `legend.tier_*` key pairs, in five locales, describe
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

The rung that says *the register republished this record and
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

- Rung 5 is `imported_at` inside the freshness window.
- A vanished row simply stops advancing and drops out of rung 4 by itself, so
  upstream removal is a derived fact rather than a number that exists only in
  one run's log. `countVanished()` still reports the count for the desk; nothing
  marks or deletes the row (catalog-data-model.md §4: no auto-retire).

The other candidate fields answer different questions and stay as they are:
`data_provider.last_run_at` is per provider, not per row; `item.updated_at` is
touched by riders and curators too; `item.osm_checked_at` dates the
OpenStreetMap link, not the sighting.

Nothing about Best of waits on this (§6.7.0: the rungs never reach Best of).
What reads this field is rung 5 itself, and therefore how a specialty provider's
rows are drawn between harvests.

#### 6.7.7 The ladder

`App\Catalog\EvidenceRung::of()` holds twelve rungs in one pure function with
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
| 1 | gross provider, fossil claim: typed once, never dated | `?` | claimed |
| 2 | gross provider, live claim | `?` | claimed |
| 3 | our own row: a rider put it here and a curator accepted it, nobody has confirmed it yet | `?` | claimed |
| 4 | specialty provider, fossil claim | `?` | claimed |
| 5 | specialty provider, live claim | `?` | attested |
| 6 | specialty provider with a per-record operational status field (RIVM's `Storing`) | `?` | attested |
| 7 | a witness that aged out of the window; still a witness, so above every claim | `?` | attested |
| 8 | a published witness inside the window | none | attested |
| 9 | one rider inside the window, below `map.item_verify_threshold`; custody does not gate this rung | `?` | attested |
| 10 | the verified state, earned by `map.item_verify_threshold` riders (moderation-and-contribution.md §10.1) | none | minimum |
| 11 | the verified state, earned by one curator's word, below the threshold | none | minimum |
| 12 | the verified state, and five or more riders | none | high |

**`verifiedBy` is read, not reconstructed** (owner, 2026-09-10).
`ItemEvidenceResolver` takes the receipt from `item_confirmation.by_curator`
(moderation-and-contribution.md §10.1), so a curator's answer keeps naming that
curator however many riders confirm afterwards. The count still speaks where no
such row exists, which is what a row verified before the column existed and a
row an import promoted both look like: verified below the threshold means
something other than a rider tally earned it. Rung 11 is unaffected by either
reading, because it tests `verifiedBy === 'curator'` **and** `confirmations <
threshold`: once the threshold of riders has stood there too, the ladder reads
the stronger evidence and the row is rung 10 or 12.

Rung 3 is its own rung and never rung 1 (owner, 2026-09-10): a row a rider
chose to add and a curator accepted is a claim, but never a gross provider's,
and it is the one cell of the grid, solid paper with the `?`, that no other rung
draws.

**Rungs 10 to 12 follow the state and never a window.** "If the `?` mark is
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
carries the row's letter is specialty; any other provider row is gross; an
`osm` or `wikidata` row with no registry row of its own is gross until a person
changes it and ours from then on, still with its "?" until somebody stands
there (owner 2026-10-08: if we change anything on an OSM item it becomes our
own item). A change is either a `change_history` row on a field by someone
other than the system clock (a `state` row, the verification or an approval,
is none), or an approved submission (in Trash or not) by a person whose
`changes` hold a field whose `now` differs from its `was`. The second is how a
place a rider added from an OSM point counts: it is written at intake, and its
approval records only the `state`. A place taken from an OSM point with its
values as they were stays gross. Everything else (a rider's, Scout's or a
curator's own row) is ours. `imported_at` counts
as an upstream sighting only on a provider row.

**One resolver, two readers.** `ItemEvidenceResolver::selectSql()` names the
columns a query must add and `fromRow()` reads them; `CatalogProvider` serves
the result as `rung` and `custody` on every point and climb in the map payload,
and the public API publishes the same object as the trust envelope. The ladder
is never re-derived in SQL or in JavaScript.

**The grammar on the map, and the page that checks it.** `styles/pins.css` is
the one definition of the pin; the map, `/map-key` and the curator page
`/curator/markers` (`ROLE_CURATOR`, under More on the moderation bar) link it
rather than copying its rules. The DOM pin reads `custody` for its border class
(`dashed`, `disc`, or none for solid paper) and `rung` for its badge class (`q`)
through `pinClasses()` in `web/assets/map/icons.js`; the coverage symbol layer
mints every tile icon twice, plain and `-q`, and picks by the tile's `cd`
against `window.CC_WITNESS_CUTOFF`, the day `map.confirmation_stale_months`
before now (coverage-provider.md §4). The curator page draws all twelve rungs
on a dark and a light ground, so the grammar is checked by eye and not only
asserted.

What the eye check shows: twelve rungs collapse into six looks,
three borders by two badge states. Rungs 1, 2 and 7 draw alike, so do 4, 5, 6
and 9, and so do 10, 11 and 12. That is the design working, not a defect: the pin
answers two questions and only two, and the rung number with its receipt lives
in the drawer and the public API. A rider learns six marks, not twelve.

Inputs no writer fills yet, stated rather than hidden: rung 6 needs a registry
field naming which attribute carries operational state, and nothing produces
it. Rung 8 reads a provider row's `survey_date_attribute` (§6.7.2), and for
every other row `attributes.check_date`, which no import fills. Neither is a
gap in the ladder.

## 7. Citation

The bucket word is not a citation and is never shown. Every drawer line and
every credit is built from the registry row: `name`, `licence`, `homepage`,
`attribution`.

No provider string lives in the front end. The catalog payload carries a
`providers` map (`App\Provider\ProviderCitations`) and every authority
feature carries `pk`, its publisher's slug; `assets/map/drawer.js` looks the
citation up in what it was given, so a provider added at the desk is credited
with no deploy. The drawer's "Listed" row reads "Official registry entry" with
the publisher as the method, in all five catalogues. The payload carries every
credited provider, which includes a paused one whose rows are still served
(§9).

## 8. The curator desk

`/moderate/providers`, `ROLE_CURATOR`, under More on the moderation bar. One list, one form
per provider, following the desks that already exist rather than inventing a
shape (moderation-and-contribution.md §5).

What a curator can do: add a provider, edit its citation and licence fields, set
its rank and match radius, enable and disable it, run a refresh, and read the
last run's counts and errors.

**The source shows, read-only, with the run.** Every non-system row shows
what it fills (letters, country), the service and its kind, the WFS layer, the
field map without its underscore keys, the cadence, the last run's counts and
errors, and the refresh command, dry then written: one command (§5), printed
for the environment the desk is served from (`App\Provider\RefreshCommandLine`),
the Make target on a developer machine, the script with the environment's
`--worker-dir` on staging and production. There is no Run button: editing
those source fields, adding a row, and a Run button that queues the refresh
where the pipeline lives are open work in `docs/TODO.md`. One row is one
provider in one country for one or more letters: another country's register
for the same letter is another row with its own endpoint and field map, which
is why `country_code` and `letters` live on the row and not on the letter.

Every non-system row with letters shows a **Defaults** block (§5.2): per
letter it fills, the same choice fields as that letter's edit form, with the
same choices in the same words plus "(no default)", and without "Still as
mapped?". Saving goes through the registry, which refuses a field the form
does not have, a value outside the field's choices, and any default for
`condition`.

The row also carries the three custody settings of §6.7.2: whether the
provider may take a record back, its margin in days, and the attribute that
carries its survey date. All three are on the form, refused outside their band
by the registry like every other field, and recorded in the row's history when
they move.

`App\Provider\ProviderRegistry` is the only writer and the only place the rules
live. A rule enforced in the controller is a rule the next caller does not
have, so the controller validates nothing: it reads the form, hands it over,
and renders whatever refusal comes back. A refusal carries a catalogue KEY
rather than a sentence, so a curator reads it in their own language.

Adding a provider is not on the form. Every field a new row needs is editable
on an existing one, and a new row is seeded by migration.

What a curator **cannot** do, enforced server-side by `ProviderRegistry`:

- Delete or re-rank a `system` row (OSM, Wikidata).
- Delete a provider whose rows are on the map (pause it instead: that keeps
  the rows and stops the refreshing).
- Set a rank outside the band, which keeps every provider below the rider
  sources.
- Enable a provider with no `attribution` when its licence code requires one.
- Give "Still as mapped?" (`condition`) a default, or set a default outside
  the letter's own form choices (§5.2).

A wrong `field_map` on a national dataset is how a catalogue floods in one
run. The guard is the ingest's dry default: `tools/provider-run.sh` without
`--write` reports the counts and writes nothing (§5). A refresh that refuses
to insert more than a configured share of a country's rows without a second
confirmation is specified, not built.

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

`/credits` generates its data group from the registry, so adding a provider
does not mean editing a template in five languages. The hand-written rows for
things that are not datasets (software, fonts, basemap) stay as they are.

**The handle the credits page calls:**

```
credited_providers()      Twig function, App\Twig\ProviderCreditsExtension
```

It returns every credited `data_provider` row, each carrying
`name`, `full_name`, `homepage`, `licence`, `attribution` and whether the credit
is legally required or courtesy, ordered for display, split by what the
licence obliges (`App\Provider\LicenceObligation`, §9.3).

**Credited means serving, or paused with rows still served.**
Pausing a provider stops its refreshes and keeps its rows on the map (§3), and
a row a rider can still open still owes its publisher the credit its licence
asks for. So the credit follows the served rows, not the switch: a provider is
credited while it is enabled, and a paused one for as long as any row it
supplied is `unverified` or `verified` (`item.provider_id`, or `item.source`
for the system rows OpenStreetMap and Wikidata). A paused provider with
nothing served is credited nowhere. One predicate says it,
`ProviderCitations::creditedSql()`, read by both `credited_providers()` and
the map's citation payload (§7), so the drawer and `/credits` cannot disagree.
Pinned by `PausedProviderCreditTest`.

The datasets the registry carries have no hand-written row. The rows for
things that reach us on demand rather than on a schedule stay hand-written,
which is the line credits-page.md §8.8 draws.

`tools/credits/check_credits.py` reads `data-pkg` markers out of the template
to diff them against composer.json and friends. A generated row's marker is a
Twig expression, so the gate skips a marker carrying an expression, which
loses nothing: a generated row cannot drift from an installed dependency,
because it is not claiming one.

### 9.1 Module names

The module's names, in one place:

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
| One refresh, fetch to ingest | `tools/provider-run.sh`, `make provider-run` |
| The refresh command the desk prints | `App\Provider\RefreshCommandLine` |

`App\Provider` is a top-level module beside `App\Catalog`,
`App\Coverage` and `App\Messaging`, because it is owned by neither: the
catalog consumes its rows, the coverage cache is suppressed by them, and the
credits page and the map both cite it.

### 9.2 Language: facts come from the registry, prose stays translatable

Each provider's sentence on `/credits` is a translated message key
(`credits.osm_p`, `credits.overture_p`), so `/fr/credits` reads as French. A row
generated from a database table has one string, in one language, and would put
an English paragraph inside a French page for four of five locales. So a
credit row is two things.

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

Every seeded row (`osm`, `wikidata`, `wallonie-pivot` and the datasets
`Version20260904140000` moved into the registry) carries the message key the
template rendered before, so no wording changed in any language.

`credited_providers()` returns per row the facts as values plus a resolved
sentence: the translated `blurb_key` when the key resolves, else the `blurb`
as written. The template renders and does not choose.

**A curator's `blurb` shows in English on every locale.** The specified
route to five languages: register it with the in-site translation system as a
`translation_entry` whose `message_key` is synthetic, `provider.<key>.blurb`,
which fits the table's own rule, "identity is `message_key`, never display
wording" (translations.md §3.1). That needs the sync that projects
`messages.en.yaml` into `translation_entry` to project registry blurbs too;
overlays resolve by key and do not care where the English came from. It is not
built.

### 9.3 One row per provider does not survive a registry of hundreds

One row per provider and "lots and lots of these worldwide" cannot both hold.

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
provider change. A count of promoted rows on the desk, so the count cannot
creep unnoticed, is specified, not built: today the desk shows the flag only as
a checkbox on each provider's row.

**Publisher and creator are different fields.** `full_name` is who publishes.
`creator` (nullable) is who made the dataset, when that is somebody else. For
the Dutch taps: publisher RIVM, creator drinkwaterkaart.nl, registry the
Kadaster's Nationaal Georegister. A credit that names only the publisher credits
the pipe rather than the person.

## 10. The Wallonia rows

`Version20260904110000` seeded `data_provider` with `osm`, `wikidata` (both
`system`) and `wallonie-pivot`, and pointed the Wallonia rows at
`wallonie-pivot` as `source = 'authority'` (`item.source` is `varchar(10)`).
Their `source_ref` values, such as `fx:pivot:hotel-koru|ramillies`, are left
alone: they are upsert keys, not display strings, and rewriting them would
break the one thing they are for.

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

Field map: `beschrijvi` to the note, and `type` twice through a value map:
`Regulier, 24-7 open` / `Alleen overdag bereikbaar` to `availability` (Always /
Daytime only), `Storing` to `condition` (Out of order). Both are form fields on
letter B, so a rider can correct what the register says, and the clock badge
and the red `!` (§6.3a) read them. No town: the water form has no town field
and a tap has its point, so `plaats` is not read (`Version20261001230000`
removed the towns an earlier harvest wrote; owner 2026-10-01). What is true of
every tap in the register is said once on the row, as its defaults (§5.2,
`Version20261001220000`): `type` Drinking tap, `potable` Yes (public supply),
`seasonal` Frost-shut in winter, `bottleFill` Yes, `cost` Free. Being in the
national drinking-water register IS the potability answer; without it the map
would draw the "nobody said" drop on every RIVM tap.

`beschrijvi` feeds the NOTE, not the name: it runs to 254 characters and reads
as a paragraph, and `item.name` holds 200, so these rows carry no name, which
is honest because RIVM does not name its taps.

**The acceptance test.** Measured against the 2026-08-26 snapshot (3287
upstream points, 2744 OSM `amenity=drinking_water` nodes in NL): about **2418
attach** to an existing OSM node at 50 m, about **869 insert** as places OSM
does not have, and the remaining OSM taps stay exactly as they are. A run that
produces wildly different numbers means the matcher is wrong, not that the
data changed. A real run against the live service read 3287, attached 2429,
inserted 854, updated 4 (two upstream records rounding to one ref), left 0 to
a rider and found 9 contested.

**Two matcher rules that run established:**

- *One node, one claim.* Two items pointing at one `osm_ref` break the
  suppression that hides the raw pin, which assumes a single claimant. The
  nearest record takes the node and the next inserts unattached, which says "a
  real place we could not tie to a node" rather than a wrong tie (counted as
  `contested`).
- *A letter is not a kind.* Letter B in the Netherlands also holds toilets,
  cafés and shops, so matching by letter alone ties public taps to the café
  across the road. `data_provider.match_tags` narrows the counterpart: with
  `amenity=drinking_water` alone the run attaches 2416, which reproduces the
  2418 above; the configured value also accepts `water_point`, because OSM
  uses it for the same street tap often enough that excluding it inserts a
  second pin beside a mapped one (the +13 between 2416 and 2429).

**Running it** is one deliberate operator act, dry first:

```
make provider-run KEY=rivm-drinkwater            # dry
make provider-run KEY=rivm-drinkwater WRITE=1
```

**Licence discipline for this row.** The Public Domain Mark 1.0 statement covers
the RIVM publication, dataset 30660 on data.overheid.nl. It does **not** cover the GPX
download on drinkwaterkaart.nl, whose own page says "voor eigen gebruik". We
ingest the RIVM service and nothing else until its maintainer says otherwise.
The RIVM copy is refreshed twice a year and runs months behind the
drinkwaterkaart.nl maintainer's own list: that staleness is the price of the
clean licence, and it is the reason to talk to him
(see "Drinkwaterkaart.nl" in `docs/TODO.md`).

## 12. Open questions

- **Does an authority row's attached OSM twin keep contributing facts?** An
  attached row hides the OSM pin, but the OSM node may carry tags the authority
  lacks (`wheelchair`, `opening_hours`). Merging both into one drawer is
  attractive and is not specified here, because it needs a rule for what happens
  when they disagree.
- **What does `community_edited` mean exactly?** A photo? A confirmation? A
  field edit? The flag is stored and read by nothing (§3); a use for it needs
  the trigger list written first.
- **Per-country rank.** A provider may be authoritative in one country and not
  in the next. `rank` is one number today; whether it needs to vary by country
  is unanswered and deliberately deferred.
