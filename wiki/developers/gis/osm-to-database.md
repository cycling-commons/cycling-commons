<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# From OpenStreetMap to our database

Chapter 5 ended with the fountain answered quickly — a rider's GPX file, a corridor, an index, an
answer in under a second. It did not ask where that answer's *raw material* came from in the first
place. Everything chapter 1 through 5 built on top of `coverage_poi` assumed the row already
existed. It did not always exist. Something had to put it there.

Here is how it really happened. One day, a person stood next to the fountain east of Spa, or looked
at an aerial photo of the spot, opened an OpenStreetMap editor, dropped a point at roughly
`50.4894, 5.8792`, and typed `amenity=drinking_water`. Then they saved. That single edit — one
volunteer, one moment — is the fountain's actual origin as far as any computer is concerned. There
is no survey office, no national mapping agency, no company that owns this fact. **OpenStreetMap
(OSM)** is exactly that: a map of the whole world, built from millions of small edits like this one,
made by volunteers ("mappers") and merged into one shared, freely reusable database.

Everything this chapter describes is downstream of that one edit. The Geofabrik file, the pipeline,
the `coverage_poi` row, the tile chapter 7 builds from it, the pixel chapter 8 draws — all of it is a
copy, at some remove, of a fact one person typed in on one day. Nothing in this chain re-verifies
that the fountain is really there. It is copying, not re-surveying, all the way down.

## The OSM data model

OSM describes the entire world with exactly three kinds of thing.

A **node** is a point: one latitude, one longitude, nothing else structural. Our fountain is a node.

A **way** is an ordered list of nodes, joined into a path — the same "ordered list of points" idea
chapter 2 ([`shapes.md`](shapes.md)) called a LineString. A road is a way. A way whose first and last
node are the same one is *closed*, and mappers use closed ways for areas too — a building outline, a
lake — the same way chapter 2's Polygon closes its ring.

A **relation** groups other elements — nodes, ways, or even other relations — into something bigger
than any one of them. A bus route made of several ways is a relation. A country's border, assembled
from many ways that each cover one short stretch, is a relation.

That is the whole model. Three primitives, and everything OSM has ever mapped — a fountain, a
motorway, a whole country — is built from some combination of them.

!!! note "Not in the Commons — yet"
    The coverage pipeline this chapter describes reads nodes and ways. It does not read relations.
    `pipeline/coverage/extract.py::selector_expressions()` builds its filter expressions with an
    `nw/` prefix — node and way, explicitly, nothing else — and `pipeline/coverage/parse.py`'s
    `_Collector` class defines a `node()` handler and a `way()` handler and no `relation()` handler at
    all. A castle or a historic site mapped as a multipolygon relation (roughly 1–3% of matching
    objects, by the pipeline's own estimate) is invisible to this pipeline today. It is a known,
    named gap — `docs/specs/coverage-provider.md`'s Open Questions call it an "approved fast-follow
    with no scheduled plan yet" — not an oversight nobody noticed.

<!-- UNANCHORED id=U60 type=absent concept="ingesting OSM relations (e.g. multipolygon buildings/areas) into coverage_poi" -->

## Tags are not a schema

A node, a way, or a relation is nearly empty by itself — a point, or a shape, with no meaning
attached. Meaning comes from **tags**: an open-ended list of `key=value` pairs any element can
carry. Our fountain's node carries `amenity=drinking_water`. A road might carry `highway=residential`
and `surface=asphalt`. A castle might carry `historic=castle`, `name=…`, `wikipedia=…`, and a dozen
more.

Here is the sentence this whole chapter, and much of this series, rests on: **`amenity=drinking_water`
is not enforced by any piece of software.** There is no OSM database schema that requires a node
tagged `amenity=drinking_water` to look a particular way, or that stops a mapper from typing
`amenity=drinkingwater` by mistake, or from tagging the exact same real-world fountain
`amenity=fountain` instead because that felt more natural to them that day. `amenity=drinking_water`
is a **convention** — a key and a value that a large number of mappers, over years, have agreed to
use for this kind of thing, written up on a wiki page, followed voluntarily. Nothing in OSM's own
software checks that any tag matches its documented meaning. Any node can carry any key with any
value, spelled however the person editing it happened to spell it.

Two consequences follow immediately, and they apply to every single piece of code anywhere that
reads OSM data, including all of the code cited in the rest of this chapter:

- **You cannot trust a tag to be present.** A drinking fountain with no `amenity` tag at all is not a
  contradiction, just an unmapped detail. Absence of a tag never means absence of the thing.
- **You cannot trust a tag to be spelled the way you expect**, or to mean the same thing in every
  country. A convention followed by millions of independent volunteers drifts: synonyms creep in,
  language and local practice differ, and a wiki-documented "correct" tag is a target mappers aim at,
  not a rule the software behind them enforces.

So a pipeline that reads OSM is never simply *reading* data the way it would read a column with a
`NOT NULL` constraint and a known type. It is always **interpreting** — deciding which of the many
tags a real-world object might carry actually count, in this project, as evidence that the object is
a drinking fountain, or a bike shop, or a viewpoint. Everything in the rest of this chapter is that
interpretation step, made explicit and checkable, instead of left implicit and hoped-for.

## Getting the data

OpenStreetMap's whole planet, as one file, is enormous — every node, way, relation and tag on Earth.
Downloading and processing all of it just to find drinking fountains in Belgium would be enormously
wasteful, so nobody does that. Instead, **Geofabrik**, a long-running community mirror, continuously
republishes the planet cut into per-country and per-region slices, refreshed on its own schedule, as
downloadable files.

Those files are **PBF** — a compact binary encoding of OSM's node/way/relation/tag model, built for
size and fast reading rather than being human-readable the way the older plain-text OSM XML format
is. A regional PBF extract is a small fraction of the size of the equivalent XML.

The coverage pipeline's first step is exactly this download: `pipeline/coverage/extract.py` (see also
`docs/specs/coverage-provider.md` §3 step 1) fetches the configured region's Geofabrik PBF (skipping
the download if the file is unchanged, checked by MD5) or, in tests and local development, reads a
committed fixture PBF (`pipeline/tests/fixtures/mini.osm.pbf`) instead, so the pipeline test suite
never touches the network at all.

**Why a pre-made file, and not a live query?** Two live alternatives exist: the OSM edit API (built
for one small edit at a time, not bulk reads) and the public **Overpass API**, a shared community
service that answers ad-hoc bulk queries against OSM data. `docs/specs/osm-data-architecture.md` §5
states the governing rule plainly: **never call the public Overpass API on a user request.** That
rule is usually read as being about *serving* — not making a rider's map pan trigger a live query —
but it applies just as much to *harvesting*. Asking Overpass, a shared free resource meant for modest
ad-hoc queries, to answer "give me every matching object in an entire country" on a fixed weekly
schedule would put a large, predictable load on infrastructure this project does not run and does not
control, and it would make the weekly batch depend on that service being up at the moment it fires. A
Geofabrik extract is a file someone else has already cut and refreshed on their own timetable;
downloading one is cheap, reliable, and puts no query load on anyone.

## Selecting and narrowing

A downloaded regional extract still holds everything Geofabrik's slice contains — every road, every
building, every shop, every tag anyone ever attached to any of them. The pipeline narrows that down
in two separate steps, and they narrow two different things.

**Step one narrows *objects*.** `pipeline/coverage/extract.py::run_extract()` shells out to
`osmium tags-filter`, a command-line tool that keeps only the nodes and ways matching a given list of
`key=value` expressions and discards the rest. Which expressions to use comes from the shared
contract file, `pipeline/contract/coverage-contract.json`, loaded by
`pipeline/coverage/contract.py::load_contract()`: it defines seven catalogue letters — `C` water &
food, `D` bike services, `E` where to sleep, `G` getting there, `H` shelter, `I` scenic views, `J`
history & culture — matching the point catalogue in `docs/specs/osm-data-architecture.md` §5, and
under each letter a list of exact `tag=value` rules. Counted directly from that file, there are 37
such rules (for example `tourism=hotel`, `tourism=hostel`, `tourism=camp_site`, … under letter `E`
alone) built from 9 distinct tag keys. `extract.py::selector_expressions()` turns every rule into an
`nw/key=value` osmium expression — `nw` for "node or way", tying back to the previous section's model
— and de-duplicates them:

<!-- CODE-FROM pipeline/coverage/extract.py -->
```python
def selector_expressions(contract: Contract) -> list[str]:
    """osmium tags-filter expressions, nodes + ways (decision B1), deduped in order."""
    exprs: list[str] = []
    for spec in contract.letters.values():
        for sel in spec.selectors:
            expr = f"nw/{sel.tag}"   # Selector.tag is already "key=value"
            if expr not in exprs:
                exprs.append(expr)
    return exprs
```

Read the middle of that loop literally: every selector rule becomes the string `nw/` followed by its
own `key=value` — `nw/tourism=hotel`, `nw/amenity=drinking_water`, and so on — which is exactly the
argument list `run_extract()` then hands straight to the `osmium tags-filter` command line. One
deliberate wrinkle the function above says nothing about: `osmium tags-filter` also keeps a matched
way's member nodes even when those nodes carry no tags of their own, because without their
coordinates the way's shape — and, as the next section covers, its centroid — could not be computed
at all.

**Step two narrows *tags*.** This is a separate decision from step one, and the two are easy to
conflate: `osmium tags-filter` selects whole objects, not individual keys, so a matched object still
arrives carrying *every* tag it has, not just the one that got it selected. A historic monument tagged
with a name, a Wikipedia link, a Wikidata id, an inscription, a material, a source note and half a
dozen more arrives with all of it. `pipeline/coverage/parse.py`'s `_Collector` class trims that down,
inside the same `_emit()` method that builds every `PoiRow`:

<!-- CODE-FROM pipeline/coverage/parse.py -->
```python
tags={k: v for k, v in tags.items() if k in self._stored_keys},
```

`tags` on the right is still the object's full dictionary — every key OSM happened to carry. The
comprehension keeps only the ones present in `self._stored_keys`, a `frozenset` built once from the
contract's `storedTagKeys` list, and throws the rest away before a `PoiRow` is ever constructed —
nothing outside that list ever reaches the database.

**Why keep so little.** `docs/specs/osm-data-architecture.md` §1 states the rule this trim exists to
satisfy directly: the coverage table is meant to be *"a narrow, well-defined subset"*, on both the
object axis and the tag-key axis, because a cache that keeps every tag of every catalogued object is
a bulk copy of OSM by omission, not by decision — exactly what that same principle forbids storing.
`docs/specs/coverage-provider.md` §2.1 puts a real number on what "by omission" cost here: before this
trim was added, the cache held **4,220 distinct tag keys** — a single memorial contributed 20 of them
on its own — while nothing anywhere in the codebase read more than **27**. Measured across the three
onboarded countries (Belgium, the Netherlands, Germany; 375,078 rows), applying the trim shrank the
stored tags payload from 73 MB to 35 MB — a 51.7% cut — and the whole row from 517 to 412 bytes on
average. At the roughly 4.7-million-row planet-wide subset this project is sized against (corrected
2026-07-23 from an earlier, wrong "100M+ rows" estimate — see the sizing comment above `_TABLE_DDL` in
`pipeline/coverage/load.py`), that difference is not a rounding error: it is hundreds of megabytes of
tag payload, for keys nothing anywhere ever renders.

The 27 kept keys split into three groups, and it is worth being precise about how they add up, because
it is easy to double-count:

- **9 selector keys** — `amenity`, `drinking_water`, `historic`, `natural`, `railway`,
  `shelter_type`, `shop`, `tourism`, `waterway` — the same keys the 37 selector rules above are built
  from. They are kept because `pipeline/coverage/tiles.py`'s `_label_case` re-reads them at tile-build
  time to derive the type label a rider sees.
- **14 more keys** the item drawer displays — `opening_hours`, `website`, `contact:website`, `url`,
  `phone`, `contact:phone`, `addr:city`, `addr:street`, `addr:housenumber`, `operator`, `description`,
  `wheelchair`, `fee`, `capacity`.
- **4 provisional media/reference keys** — `wikidata`, `wikipedia`, `image`, `wikimedia_commons` —
  kept even though nothing reads them yet, because re-adding a dropped key later needs a full
  re-harvest of the planet, and these are cheap to carry against that possibility.

9 + 14 + 4 = 27. The PHP side of this — `CoverageRepository::TAG_WHITELIST` in
`web/src/Coverage/CoverageRepository.php`, the exact set of keys the POI drawer is allowed to render —
lists **15** entries, one more than the "14 more keys" figure just above, because it also repeats
`drinking_water`, which the selector group already counts. Two numbers, both correct, counting
overlapping things: 27 stored keys in total, 15 of which the drawer's whitelist happens to name
(`web/tests/Catalog/CoverageContractTest.php` asserts `TAG_WHITELIST ⊆ storedTagKeys`, so the drawer
can never ask for a key the pipeline already threw away).

`name` is conspicuously not in that list of 27. It is not dropped — it is promoted to its own
dedicated `name` column (next section), so keeping it inside `tags` as well would just be storing the
same value twice.

`email` and `contact:email` are dropped outright, on purpose, and not for space reasons. Across the
same measured dataset, 98.6% of the rows carrying an email also carried a website or a phone, so a
rider loses no real way to reach a business by its absence — and 12.6% of the stored addresses sit on
free consumer providers, meaning they are private mailboxes rather than a business's own contact
address. Storing personal data that no page ever displays is liability without any benefit, and it
would sit awkwardly next to this project's position that the Commons *dataset* is non-personal.

## Landing it

<figure class="gis-fig gis-todo">
<p class="gis-todo-h">Figure F10 · not yet drawn</p>
<p><strong>Must make the reader see:</strong> the whole batch is one straight, one-way chain — a
downloaded file becomes a smaller file becomes parsed-and-trimmed rows becomes a database write, in
that order, with no step feeding back into an earlier one — and that the whole chain runs on a
schedule, offline, never while a rider is waiting on a request.</p>
<p><strong>Drawing brief:</strong> five boxes in a single horizontal row, `.gis-box` style (per the
house flowchart convention in `wiki/stylesheets/extra.css` point 8: `rx="8"`, ~56 units tall, ~14
units of horizontal padding either side of the label), joined left to right by four `gis-accent`
arrows using the shared `#gis-arrow-fN` marker. Box labels, kept short (640-unit viewBox, ~50
characters per full-width line): (1) "Geofabrik .osm.pbf"; (2) "extract.py" with a small sub-label
"osmium tags-filter"; (3) "parse.py" with sub-label "select + narrow tags"; (4) "load.py"; (5)
"coverage_poi (PostGIS)". A small label near box 1, set apart from the row (e.g. above it), reads
"weekly, per region" to mark the whole chain as a scheduled batch rather than a live request path.
`viewBox="0 0 640 140"` as the general guidance suggests for a five-box row.</p>
<figcaption>One Geofabrik extract enters on the left; one <code>coverage_poi</code> row leaves on the
right. Every arrow points one way, and the whole chain runs on a schedule, never while a rider is
waiting for a page to load.</figcaption>
</figure>
<!-- FIGURE-TODO id=F10 ch=6 -->

The parsed, tag-trimmed rows from the previous section still need somewhere to live.
`pipeline/coverage/load.py::ensure_schema()` creates that place: a `coverage_poi` table (plus a small
`coverage_source` lookup table recording which Geofabrik extract each row came from), created
idempotently at the start of every run rather than through a Doctrine migration — a fact chapter 5
([`making-it-fast.md`](making-it-fast.md)) already used when it listed `coverage_poi_geom_idx` as the
one GiST index living outside the Symfony migrations, because this whole table is owned by the Python
pipeline, not by the application.

Here is the actual DDL, trimmed to the columns this chapter has been building toward:

<!-- CODE-FROM pipeline/coverage/load.py -->
```sql
CREATE TABLE IF NOT EXISTS coverage_poi (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ref           varchar(160) NOT NULL,  -- 'node/61146471' | 'way/…' = item.source_ref format
    letter        char(1)      NOT NULL,  -- C D E G H I J (osm-data-architecture.md §5)
    kind          varchar(16),            -- serviceKind for D (shop|station|pump), NULL otherwise
    name          varchar(255),           -- OSM name tag, NULL when unnamed
    geom          geometry(Point, 4326) NOT NULL, -- nodes as-is; ways centroid at load
    tags          jsonb        NOT NULL,  -- trimmed to contract storedTagKeys (parse.py), NOT the object's full tag set
    ...
    UNIQUE (ref, letter)                  -- one entity may carry two letters (matches item rule)
)
```

Two columns are worth lingering on, because they are the two ideas this chapter has spent the most
words on. `ref` is exactly `item.source_ref`'s own format — the join key the rest of the Commons uses
to recognise the same OSM object, and the reason "we reference OSM, we don't fork it" is more than a
slogan. `tags` says, right there in its own comment, that it is trimmed to the contract's
`storedTagKeys` and is *not* the object's full tag set — the same trim the dict comprehension above
performs, now visible as a constraint on the column that receives it. The rest of the row — `letter`,
`kind`, `name`, the upstream `osm_version`/`osm_ts`, and the `src_region_id` and `country_code` stamps
— let later chapters, and chapter 5's own index list, scope a query to one region or country cheaply.

One detail worth pausing on, because it reaches back into chapter 2
([`shapes.md`](shapes.md)): `coverage_poi.geom` is declared `geometry(Point, 4326) NOT NULL` — always
a Point, never a LineString or a Polygon, no matter which OSM primitive the row came from. A node
keeps its own coordinates unchanged. A way — which chapter 2 would normally expect to become a
LineString or a Polygon — is instead reduced to a single Point: the plain mean of its member nodes'
coordinates, computed in `parse.py`'s `way()` handler (with the closing node of a closed ring dropped
first, so it does not bias the average). `pipeline/tests/test_parse.py::test_way_reduces_to_centroid`
pins exactly this behaviour. It is a deliberate simplification: `coverage_poi` only ever needs a
marker location for a POI, not a shape to draw, so every row — node or way alike — collapses to the
one kind of geometry the table actually asks for.

<figure class="gis-fig gis-todo">
<p class="gis-todo-h">Figure F11 · not yet drawn</p>
<p><strong>Must make the reader see:</strong> the tag trim from the previous section, made concrete
on one real object instead of described in the abstract — which exact tags survive onto the stored
row, and which are thrown away, so "narrow serve cache" stops being a phrase and becomes a picture of
a decision.</p>
<p><strong>Drawing brief:</strong> left side, one node (small filled circle, `gis-fill-accent`)
labelled with a `ref` like "node/61146471", with a stacked list of tag lines beside it in
`gis-label-mono`. Four lines kept, in ordinary `gis-ink` text: `amenity=drinking_water`,
`drinking_water=yes`, `name=…`, `wheelchair=yes`. Three more lines, plausible but dropped, shown
struck through or in `gis-muted`: e.g. `source=survey`, `fixme=…`, `check_date=2024-03-01`. One
`gis-accent` arrow (shared marker convention) crosses from the node to a table-row shape on the right
— a rectangle divided by thin `gis-muted` lines into cells labelled `ref`, `letter`, `name`, `geom`,
`tags{…}` — carrying only the four kept tags into its final `tags` cell. The struck-through lines
visually stop before the arrow; they do not cross it.</p>
<figcaption>What crosses from a node's full tag list into the stored row is a choice made once, at
parse time — not everything OpenStreetMap happens to have attached to this object, only the keys the
contract names. The struck-through tags were never on their way to being kept; dropping them is the
point of this step, not an accident of it.</figcaption>
</figure>
<!-- FIGURE-TODO id=F11 ch=6 -->

`load.py::load_region()` writes a region's rows in one transaction: `COPY` into a staging table,
check the new row count has not collapsed suspiciously against the previous run (a truncated download
must never silently wipe a region), then delete and re-insert that region's slice atomically, so a
reader never sees a half-loaded region mid-swap. That mechanism is worth knowing about, but it is
plumbing this chapter does not need to unpack further — the fountain's own row is the point. It now
exists as an ordinary row in an ordinary PostGIS table: a Point in EPSG:4326 (chapter 1,
[`coordinates.md`](coordinates.md)), addressable by every technique chapters 3 through 5 already
taught, with a small, deliberately incomplete set of tags attached.

## Why copy at all

If a pipeline is always interpreting rather than simply reading, and a fair amount of what OSM knows
about an object never even reaches our table, it is worth asking directly why this project keeps a
copy at all instead of just asking OpenStreetMap itself, live, whenever a rider needs an answer.

`docs/specs/osm-data-architecture.md` gives the short answer, and it is worth reading in full rather
than restated at length here. In brief: this project **references** OpenStreetMap rather than
**forking** it — the fountain's row still carries its `ref` back to the real OSM object, it is never
presented as if it were our own independent survey — and `coverage_poi` exists purely to **serve
queries fast**, not to be any kind of authority on what is really at that spot. The authority stays
OpenStreetMap's; this table is a cache in front of it.

## What to carry into chapter 7

- OSM has three primitives — node, way, relation — and a free-form tag list is how any of them gets
  meaning attached.
- Tags are a **convention**, not a schema. A pipeline reading OSM is always interpreting: deciding
  what counts, not simply reading what is guaranteed to be there.
- Geofabrik turns the whole planet into small, regularly refreshed, per-region **PBF** files, so the
  pipeline downloads one modest file instead of running a bulk query against shared, live
  infrastructure.
- Narrowing happens twice, on two different axes: `osmium tags-filter` narrows which **objects**
  survive; `parse.py`'s trim to `storedTagKeys` narrows which **tag keys** survive on them. The
  second step exists because the first one selects whole objects, tags and all.
- The narrow tag set is deliberate, and the scale that forced it is real: thousands of stray keys,
  hundreds of megabytes planet-wide, for data nothing ever renders.
- `coverage_poi` always stores a Point, never a LineString or Polygon, even for a way — a way
  collapses to the mean of its member nodes' coordinates.
- We copy OSM to serve queries fast, not to replace it as the authority on what is really there.

The fountain now has a row of its own — a Point, a handful of trimmed tags, a region and country
stamp. A browser still cannot be handed that row directly: chapter 7 (`tiles.md`) is where it becomes
part of a small file a map can actually fetch.

<!-- EXERCISE-SLOT ch=6 — hands-on box goes here (spec D5); do not remove -->
