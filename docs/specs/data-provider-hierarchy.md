# Data provider hierarchy and the provider registry

**Status: specified 2026-08-27 (owner). Phases 1-4 built 2026-09-04; phases 5
and 6 not built.** The Dutch public drinking-water taps are its first real-world
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
| `field_map` | JSON: which upstream field feeds which of our attributes. |
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
readable. There are nine, and five were already spent before this document
proposed anything:

| Channel | Answers | State |
| --- | --- | --- |
| Shape (small disc vs teardrop) | which store the record lives in | taken |
| Fill colour | which category | taken |
| Border colour and style | our handling: pending moderation, community-added | taken |
| Ring | going stale, and selection | taken |
| Badge, top right | unconfirmed (`?`) | taken |
| Saturation (greyscale) | who maintains the record (§6) | **proposed here** |
| Badge, top left | the thing's own status (§6.2) | **proposed here** |
| Size | nothing | free |
| Cluster bubble | density | taken |

After the two proposals in §6, **one channel is left**. That is the entire
budget for every dataset we add after this one, so it is not spent on a
first-come basis.

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

### 6.5 Blocking decision: grey already means something on water

**This must be settled before §6 or §11 is built.** On water points a grey drop
already means "nobody tagged whether this is drinkable"
(`water-drop-unk`, `web/assets/map/icons.js`, coverage-provider.md §4). If
greyscale also comes to mean "an authority record nobody here has touched", the
two meanings land on the same pins. Water is where they collide, and water is
the first dataset being imported, so this is not hypothetical.

Two ways out, and one has to be chosen:

1. Give the untouched-authority tier a treatment other than greyscale, leaving
   potability where it is.
2. Move potability onto a badge and let saturation mean provenance alone. This
   costs the last free channel by §6.3's count, or forces potability into the
   drawer.

Neither is obviously right. What is not allowed is shipping both meanings.

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

Field map: `beschrijvi` to the description, `plaats` to the town, `type` to a
status attribute (`Regulier, 24-7 open` / `Alleen overdag bereikbaar` /
`Storing`).

Measured against the harvest on 2026-08-26 (3287 upstream points, 2744 OSM
`amenity=drinking_water` nodes in NL):

- about **2418 attach** to an existing OSM node at 50 m,
- about **869 insert** as places OSM does not have,
- **229 OSM taps** have no upstream counterpart and stay exactly as they are.

Those numbers are the acceptance test. A run that produces wildly different ones
means the matcher is wrong, not that the data changed.

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
5. **The Dutch taps.** §11. The first dataset added by the intended path.
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
