<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# From OpenStreetMap to our database

Chapter 5 ended with the fountain answered quickly, a rider's GPX file, a corridor, an index, an
answer in under a second. It did not ask where that answer's *raw material* came from in the first
place. Everything chapter 1 through 5 built on top of `coverage_poi` assumed the row already
existed. It did not always exist. Something had to put it there.

Here is how it really happened, and you can check every word of it. Somebody stood next to the
Pouhon La Sauvenière in Spa, or looked at an aerial photo of the spot, opened an OpenStreetMap
editor, dropped a point at `50.4851, 5.8983`, and typed `amenity=drinking_water`. Then they saved.
The node is [`node/6863042080`](https://www.openstreetmap.org/node/6863042080); open it and you will
find the spring itself, the tags this chapter is about, and the history of every edit anyone has
made to it. That single edit, one volunteer, one moment, is the fountain's actual origin as far as
any computer is concerned. There is no survey office, no national mapping agency, no company that
owns this fact. **OpenStreetMap (OSM)** is exactly that: a map of the whole world, built from
millions of small edits like this one, made by volunteers ("mappers") and merged into one shared,
freely reusable database.

Everything this chapter describes is downstream of that one edit. The Geofabrik file, the pipeline,
the `coverage_poi` row, the tile chapter 7 builds from it, the pixel chapter 8 draws, all of it is a
copy, at some remove, of a fact one person typed in on one day. Nothing in this chain re-verifies
that the fountain is really there. It is copying, not re-surveying, all the way down.

## The OSM data model

OSM describes the entire world with exactly three kinds of thing.

A **node** is a point: one latitude, one longitude, nothing else structural. Our fountain is a node.

A **way** is an ordered list of nodes, joined into a path, the same "ordered list of points" idea
chapter 2 ([`shapes.md`](shapes.md)) called a LineString. A road is a way. A way whose first and last
node are the same one is *closed*, and mappers use closed ways for areas too, a building outline, a
lake, the same way chapter 2's Polygon closes its ring.

A **relation** groups other elements, nodes, ways, or even other relations, into something bigger
than any one of them. A bus route made of several ways is a relation. A country's border, assembled
from many ways that each cover one short stretch, is a relation.

That is the whole model. Three primitives, and everything OSM has ever mapped, a fountain, a
motorway, a whole country, is built from some combination of them.

!!! note "Not in the Commons, yet"
    The coverage pipeline this chapter describes reads nodes and ways. It does not read relations,
    so a castle or a historic site mapped as a multipolygon relation is invisible to it. That is a
    known, named gap rather than an oversight, and course 2's
    [relations chapter](../gis-beyond/relations.md) is the whole account: which two code sites prove
    the gap, what it costs in objects, and what closing it would take.

<!-- UNANCHORED id=U60 type=absent concept="ingesting OSM relations (e.g. multipolygon buildings/areas) into coverage_poi" -->

## Tags are not a schema

A node, a way, or a relation is nearly empty by itself, a point, or a shape, with no meaning
attached. Meaning comes from **tags**: an open-ended list of `key=value` pairs any element can
carry. Our fountain's node carries `amenity=drinking_water`. A road might carry `highway=residential`
and `surface=asphalt`. A castle might carry `historic=castle`, `name=…`, `wikipedia=…`, and a dozen
more.

Here is the sentence this whole chapter, and much of this series, rests on: **`amenity=drinking_water`
is not enforced by any piece of software.** There is no OSM database schema that requires a node
tagged `amenity=drinking_water` to look a particular way, or that stops a mapper from typing
`amenity=drinkingwater` by mistake, or from tagging the exact same real-world fountain
`amenity=fountain` instead because that felt more natural to them that day. `amenity=drinking_water`
is a **convention**, a key and a value that a large number of mappers, over years, have agreed to
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
`NOT NULL` constraint and a known type. It is always **interpreting**, deciding which of the many
tags a real-world object might carry actually count, in this project, as evidence that the object is
a drinking fountain, or a bike shop, or a viewpoint. Everything in the rest of this chapter is that
interpretation step, made explicit and checkable, instead of left implicit and hoped-for.

## Getting the data

OpenStreetMap's whole planet, as one file, is enormous, every node, way, relation and tag on Earth.
Downloading and processing all of it just to find drinking fountains in Belgium would be enormously
wasteful, so nobody does that. Instead, **Geofabrik**, a long-running community mirror, continuously
republishes the planet cut into per-country and per-region slices, refreshed on its own schedule, as
downloadable files.

Those files are **PBF**, a compact binary encoding of OSM's node/way/relation/tag model, built for
size and fast reading rather than being human-readable the way the older plain-text OSM XML format
is. A regional PBF extract is a small fraction of the size of the equivalent XML.

The coverage pipeline's first step is exactly this download: `pipeline/coverage/run.py` (see also
`docs/specs/coverage-provider.md` §3 step 1) fetches the configured region's Geofabrik PBF,
MD5-verified against the mirror's own `.md5` file and skipped when the file is unchanged, or, in
tests and local development, takes a committed fixture PBF (`pipeline/tests/fixtures/mini.osm.pbf`)
instead, so the pipeline test suite never touches the network at all. `extract.py`, the next step,
starts from whichever file that produced and only runs `osmium`.

**Why a pre-made file, and not a live query?** Two live alternatives exist: the OSM edit API (built
for one small edit at a time, not bulk reads) and the public **Overpass API**, a shared community
service that answers ad-hoc bulk queries against OSM data. `docs/specs/osm-data-architecture.md` §5
states the governing rule plainly: **never call the public Overpass API on a user request.** That
rule is usually read as being about *serving*, not making a rider's map pan trigger a live query,
but it applies just as much to *harvesting*. Asking Overpass, a shared free resource meant for modest
ad-hoc queries, to answer "give me every matching object in an entire country" on a fixed weekly
schedule would put a large, predictable load on infrastructure this project does not run and does not
control, and it would make the weekly batch depend on that service being up at the moment it fires. A
Geofabrik extract is a file someone else has already cut and refreshed on their own timetable;
downloading one is cheap, reliable, and puts no query load on anyone.

## Selecting and narrowing

A downloaded regional extract still holds everything Geofabrik's slice contains, every road, every
building, every shop, every tag anyone ever attached to any of them. The pipeline narrows that down
in two separate steps, and they narrow two different things.

**Step one narrows *objects*.** `pipeline/coverage/extract.py::run_extract()` shells out to
`osmium tags-filter`, a command-line tool that keeps only the nodes and ways matching a given list of
`key=value` expressions and discards the rest. Which expressions to use comes from the shared
contract file, `pipeline/contract/coverage-contract.json`, loaded by
`pipeline/coverage/contract.py::load_contract()`: it defines eight catalogue letters, `B` water &
food, `C` public toilets, `D` bike services, `F` getting there, `G` shelter, `O` where to sleep,
`P` scenic views, `Q` history & culture, matching the point catalogue in `docs/specs/osm-data-architecture.md` §5, and
under each letter a list of exact `tag=value` rules. Counted directly from that file, there are 42
such rules ([the count is generated](../numbers.md), not typed here; for example `tourism=hotel`, `tourism=hostel`,
`tourism=camp_site`, … under letter `O` alone) built from 10 distinct tag keys. Letter `P` also carries two
rules that filter after the selection, a name or photo link and a bike way within 250 m
(`docs/specs/scenic-views.md` §2). `extract.py::selector_expressions()` turns every rule into an
`nw/key=value` osmium expression, `nw` for "node or way", tying back to the previous section's model,
and de-duplicates them:

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
own `key=value`, `nw/tourism=hotel`, `nw/amenity=drinking_water`, and so on, which is exactly the
argument list `run_extract()` then hands straight to the `osmium tags-filter` command line. One
deliberate wrinkle the function above says nothing about: `osmium tags-filter` also keeps a matched
way's member nodes even when those nodes carry no tags of their own, because without their
coordinates the way's shape, and, as the next section covers, its centroid, could not be computed
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

`tags` on the right is still the object's full dictionary, every key OSM happened to carry. The
comprehension keeps only the ones present in `self._stored_keys`, a `frozenset` built once from the
contract's `storedTagKeys` list, and throws the rest away before a `PoiRow` is ever constructed,
nothing outside that list ever reaches the database.

**Why keep so little.** `docs/specs/osm-data-architecture.md` §1 states the rule this trim exists to
satisfy directly: the coverage table is meant to be *"a narrow, well-defined subset"*, on both the
object axis and the tag-key axis, because a cache that keeps every tag of every catalogued object is
a bulk copy of OSM by omission, not by decision, exactly what that same principle forbids storing.
`docs/specs/coverage-provider.md` §2.1 puts a real number on what "by omission" costs here: without
the trim the cache holds **4,220 distinct tag keys** (a single memorial contributes 20 of them on its
own) while the code reads a few dozen. Measured across Belgium, the Netherlands and Germany
(375,078 rows at the time of that measurement), the trim shrinks the stored tags payload from 73 MB
to 35 MB, a 51.7% cut, and the whole row from 517 to 412 bytes on average. At the roughly
4.7-million-row planet-wide subset this project is sized against (the sizing comment above
`_SOURCE_DDL` in `pipeline/coverage/load.py`), that difference is not a rounding error: it is
hundreds of megabytes of tag payload, for keys nothing anywhere ever renders.

The 33 kept keys split into five groups, and they are easy to double-count:

- **10 selector keys**: `amenity`, `drinking_water`, `historic`, `man_made`, `railway`,
  `route`, `shelter_type`, `shop`, `tourism`, `waterway`, the same keys the 42 selector rules above
  are built from. They are kept because `pipeline/coverage/tiles.py`'s `_label_case` re-reads them
  at tile-build time to derive the type label a rider sees.
- **1 key no selector reads**: `natural`. It stays in the stored set because re-adding a dropped key
  later needs a full re-harvest of the planet.
- **17 more keys** the item drawer displays: `opening_hours`, `website`, `contact:website`, `url`,
  `phone`, `contact:phone`, `addr:city`, `addr:street`, `addr:housenumber`, `operator`,
  `description`, `wheelchair`, `fee`, `capacity`, and for scenic views `ele`, `direction` and
  `height` (a viewpoint's altitude, the way it faces, how far a waterfall drops).
- **4 media/reference keys**: `wikidata`, `wikipedia`, `image`, `wikimedia_commons`. Three of them
  are what a photo of the place can be found under, and the drawer serves them as the citation for
  a picture a rider is looking at (`coverage-provider.md` §7); `wikipedia` is stored and read by
  nothing yet, kept because re-adding a dropped key later needs a full re-harvest of the planet.
- **1 evidence key**: `check_date`, the date a mapper last stood in front of the thing and confirmed
  it. The drawer never prints it. `pipeline/coverage/tiles.py` reads it at tile-build time into a
  `cd` property, and a `cd` inside the freshness window is what lets a coverage pin claim a
  published witness rather than a bare copied claim. `data-priority.md` is where that ladder is set
  out.

10 + 1 + 17 + 4 + 1 = 33. The PHP side of this, `CoverageRepository::TAG_WHITELIST` in
`web/src/Coverage/CoverageRepository.php`, the exact set of keys the POI drawer is allowed to render,
lists **23** entries: the 17 display keys, three of the selector keys (`drinking_water`, `amenity` and
`shop`, because the pin's colour and the panel's wording have to agree on whether water is potable
and whether a place is a shop), and the three media keys. Two numbers, both correct, counting
overlapping things: 33 stored keys in total, 23 of which the drawer's whitelist names
(`web/tests/Catalog/CoverageContractTest.php` asserts `TAG_WHITELIST ⊆ storedTagKeys`, so the drawer
can never ask for a key the pipeline already threw away).

Three numbers, one table. A key can be in more than one set, which is exactly why 11 + 17 + 4 + 1
and 23 are both correct and describe different things.

| set | what it is for | how many |
|---|---|---|
| **selector** | decides whether an object is kept at all, and is re-read at tile-build time to derive the type label | 11 |
| **drawer** | `TAG_WHITELIST`: the keys the item panel is allowed to render | 23 |
| **tile-derived** | read into a short tile property rather than shown as text: `amenity`, `check_date`, `drinking_water`, `shop`, `wheelchair` | 5 |
| **stored** | the union, and the only thing the harvest actually keeps | 33 |

Three keys sit in all three sets (`amenity`, `drinking_water`, `shop`), because the pin's colour,
the panel's wording and the selector all have to agree about the same fact. One key, `wikipedia`, is
in none of them: it is stored and read by nothing yet, kept because re-adding a dropped key later
means re-harvesting the planet.

`name` is conspicuously not in that list of 33. It is not dropped; it is promoted to its own
dedicated `name` column (next section), so keeping it inside `tags` as well would just be storing the
same value twice.

`email` and `contact:email` are dropped outright, on purpose, and not for space reasons. Across the
same measured dataset, 98.6% of the rows carrying an email also carried a website or a phone, so a
rider loses no real way to reach a business by its absence, and 12.6% of the stored addresses sit on
free consumer providers, meaning they are private mailboxes rather than a business's own contact
address. Storing personal data that no page ever displays is liability without any benefit, and it
would sit awkwardly next to this project's position that the Commons *dataset* is non-personal.

## Landing it

<figure class="gis-fig">
<svg viewBox="0 0 640 420" role="img" aria-labelledby="f10-t f10-d" xmlns="http://www.w3.org/2000/svg"><title id="f10-t">The coverage pipeline, from Geofabrik file to database row</title><desc id="f10-d">Five stages joined by one-way arrows, wrapping onto two rows the way a line of text does. Row one, left to right: a box labelled Geofabrik .osm.pbf; an arrow into a box labelled extract.py, osmium tags-filter; an arrow into a box labelled parse.py, make rows, narrow tags. From that third box an arrow drops down and runs back left onto row two: a box labelled load.py, COPY and merge, then an arrow into a box labelled coverage_poi, PostGIS table. Every arrow points forward; not one returns to an earlier stage. Above the chain a heading reads: weekly batch, one region at a time, and below it, runs on a schedule, never on a rider's request.</desc><defs><marker id="gis-arrow-f10" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path class="gis-fill-accent" d="M 0 0 L 10 5 L 0 10 Z"/></marker></defs><text x="20" y="40">Weekly batch, one region at a time</text><text class="gis-label-sm" x="20" y="70">runs on a schedule, never on a rider's request</text><rect class="gis-box" rx="8" x="22" y="96" width="176" height="110"/><text class="gis-label-sm" x="110" y="143" text-anchor="middle">Geofabrik</text><text class="gis-label-mono" x="110" y="175" text-anchor="middle">.osm.pbf</text><line class="gis-accent" x1="200" y1="151" x2="230" y2="151" marker-end="url(#gis-arrow-f10)"/><rect class="gis-box" rx="8" x="232" y="96" width="176" height="110"/><text class="gis-label-mono" x="320" y="127" text-anchor="middle">extract.py</text><text class="gis-label-sm" x="320" y="159" text-anchor="middle">osmium</text><text class="gis-label-sm" x="320" y="189" text-anchor="middle">tags-filter</text><line class="gis-accent" x1="410" y1="151" x2="440" y2="151" marker-end="url(#gis-arrow-f10)"/><rect class="gis-box" rx="8" x="442" y="96" width="176" height="110"/><text class="gis-label-mono" x="530" y="127" text-anchor="middle">parse.py</text><text class="gis-label-sm" x="530" y="159" text-anchor="middle">make rows,</text><text class="gis-label-sm" x="530" y="189" text-anchor="middle">narrow tags</text><path class="gis-accent" d="M 530 206 L 530 246 L 165 246 L 165 286" marker-end="url(#gis-arrow-f10)"/><rect class="gis-box" rx="8" x="77" y="286" width="176" height="110"/><text class="gis-label-mono" x="165" y="333" text-anchor="middle">load.py</text><text class="gis-label-sm" x="165" y="365" text-anchor="middle">COPY + merge</text><line class="gis-accent" x1="255" y1="341" x2="285" y2="341" marker-end="url(#gis-arrow-f10)"/><rect class="gis-box" rx="8" x="287" y="286" width="276" height="110"/><text class="gis-label-mono" x="425" y="333" text-anchor="middle">coverage_poi</text><text class="gis-label-sm" x="425" y="365" text-anchor="middle">PostGIS table</text></svg>
<figcaption>One Geofabrik extract enters on the left; one <code>coverage_poi</code> row leaves on the
right. Every arrow points one way, and the whole chain runs on a schedule, never while a rider is
waiting for a page to load.</figcaption>
</figure>

The parsed, tag-trimmed rows from the previous section still need somewhere to live.
`pipeline/coverage/load.py::ensure_schema()` creates that place: a `coverage_poi` table (plus a small
`coverage_source` lookup table recording which Geofabrik extract each row came from), created
idempotently at the start of every run rather than through a Doctrine migration, a fact chapter 5
([`making-it-fast.md`](making-it-fast.md)) already used when it listed `coverage_poi`'s GiST indexes
as the ones living outside the Symfony migrations, because this whole table is owned by the Python
pipeline, not by the application.

Here is the actual DDL, trimmed to the columns this chapter has been building toward:

<!-- CODE-FROM pipeline/coverage/load.py -->
```sql
CREATE TABLE IF NOT EXISTS coverage_poi (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ref           varchar(160) NOT NULL,  -- 'node/6863042080' | 'way/…' = item.source_ref format
    letter        char(1)      NOT NULL,  -- B C D F G O P Q (osm-data-architecture.md §5)
    kind          varchar(16),            -- serviceKind for D (shop|station|pump), NULL otherwise
    name          varchar(255),           -- OSM name tag, NULL when unnamed
    geom          geometry(Point, 4326) NOT NULL, -- nodes as-is; ways centroid at load
    tags          jsonb        NOT NULL,  -- trimmed to contract storedTagKeys (parse.py), NOT the object's full tag set
    ...
    UNIQUE (ref, letter)                  -- one entity may carry two letters (matches item rule)
)
```

Two columns are worth lingering on, because they are the two ideas this chapter has spent the most
words on. `ref` is exactly `item.source_ref`'s own format, the join key the rest of the Commons uses
to recognise the same OSM object, and the reason "we reference OSM, we don't fork it" is more than a
slogan. `tags` says, right there in its own comment, that it is trimmed to the contract's
`storedTagKeys` and is *not* the object's full tag set, the same trim the dict comprehension above
performs, now visible as a constraint on the column that receives it. The rest of the row, `letter`,
`kind`, `name`, the upstream `osm_version`/`osm_ts`, and the `src_region_id` and `country_code` stamps,
let later chapters, and chapter 5's own index list, scope a query to one region or country cheaply.

One detail reaches back into chapter 2
([`shapes.md`](shapes.md)): `coverage_poi.geom` is declared `geometry(Point, 4326) NOT NULL`, always
a Point, never a LineString or a Polygon, no matter which OSM primitive the row came from. A node
keeps its own coordinates unchanged. A way, which chapter 2 would normally expect to become a
LineString or a Polygon, is instead reduced to a single Point: the plain mean of its member nodes'
coordinates, computed in `parse.py`'s `way()` handler (with the closing node of a closed ring dropped
first, so it does not bias the average). `pipeline/tests/test_parse.py::test_way_reduces_to_centroid`
pins exactly this behaviour. It is a deliberate simplification: `coverage_poi` only ever needs a
marker location for a POI, not a shape to draw, so every row, node or way alike, collapses to the
one kind of geometry the table actually asks for.

<figure class="gis-fig">
<svg viewBox="0 0 640 1010" role="img" aria-labelledby="f11-t f11-d" xmlns="http://www.w3.org/2000/svg"><title id="f11-t">Which of a node&#8217;s tags survive into the stored row</title><desc id="f11-d">On the left, the real OpenStreetMap node 6863042080, the Pouhon La Sauveniere in Spa, drawn as a filled dot with the nine tags it carries listed beneath it. The first five, amenity=drinking_water, drinking_water=yes, name=Pouhon La Sauveniere, natural=spring and wikidata=Q27959454, are in full ink and bracketed as kept: each is named in the contract&#8217;s storedTagKeys list of 33 keys. The last four, access=yes, intermittent=no, note=survey drinkable and water_characteristic=ferruginous, are struck through and greyed, and bracketed as dropped, not in the 33. The note tag is worth seeing go: it reads survey colon drinkable, which is one mapper's prose rather than a claim any program reads. Below the list an arrow, labelled parse.py keeps only the contract&#8217;s keys, leads down into the stored coverage_poi row: ref node/6863042080, letter B, name Pouhon La Sauveniere, geom POINT 5.8983 50.4851, and a tags column carrying only amenity, drinking_water, natural and wikidata. The struck-through tags reach neither the arrow nor the row. A note underneath records that name gets a column of its own and is never a stored tag.</desc><defs><marker id="gis-arrow-f11" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path class="gis-fill-accent" d="M 0 0 L 10 5 L 0 10 Z"/></marker></defs><text x="20" y="40">One real node, every tag OSM has</text><circle class="gis-ink gis-fill-accent" cx="34" cy="76" r="12"/><text class="gis-label-mono" x="58" y="85">node/6863042080</text><text class="gis-label-mono" x="58" y="140">amenity=drinking_water</text><text class="gis-label-mono" x="58" y="180">drinking_water=yes</text><text class="gis-label-mono" x="58" y="220">name=Pouhon La Sauvenière</text><text class="gis-label-mono" x="58" y="260">natural=spring</text><text class="gis-label-mono" x="58" y="300">wikidata=Q27959454</text><text class="gis-label-mono gis-fill-glacier" x="58" y="344">access=yes</text><text class="gis-label-mono gis-fill-glacier" x="58" y="384">intermittent=no</text><text class="gis-label-mono gis-fill-glacier" x="58" y="424">note=survey: drinkable</text><text class="gis-label-mono gis-fill-glacier" x="58" y="464">water_characteristic=ferruginous</text><line class="gis-muted" x1="54" y1="336" x2="186" y2="336"/><line class="gis-muted" x1="54" y1="376" x2="252" y2="376"/><line class="gis-muted" x1="54" y1="416" x2="344" y2="416"/><line class="gis-muted" x1="54" y1="456" x2="476" y2="456"/><path class="gis-accent" d="M 500 116 L 488 116 L 488 312 L 500 312"/><text class="gis-label-sm" x="512" y="206">kept:</text><text class="gis-label-sm" x="512" y="234">storedTagKeys</text><text class="gis-label-sm" x="512" y="262">33 keys</text><path class="gis-muted" d="M 500 320 L 488 320 L 488 476 L 500 476"/><text class="gis-label-sm" x="512" y="402">dropped:</text><text class="gis-label-sm" x="512" y="430">not in the 33</text><line class="gis-accent" x1="60" y1="490" x2="60" y2="456" marker-end="url(#gis-arrow-f11)"/><text class="gis-label-sm" x="88" y="430">parse.py keeps only the contract&#8217;s keys</text><rect class="gis-box" rx="8" x="20" y="470" width="600" height="452"/><text class="gis-label-sm" x="40" y="506">the stored coverage_poi row</text><line class="gis-muted" x1="20" y1="524" x2="620" y2="524"/><text class="gis-label-sm" x="40" y="560">ref</text><text class="gis-label-mono" x="180" y="560">node/6863042080</text><line class="gis-muted" x1="20" y1="578" x2="620" y2="578"/><text class="gis-label-sm" x="40" y="614">letter</text><text class="gis-label-mono" x="180" y="614">B</text><line class="gis-muted" x1="20" y1="632" x2="620" y2="632"/><text class="gis-label-sm" x="40" y="668">name</text><text class="gis-label-mono" x="180" y="668">Pouhon La Sauvenière</text><line class="gis-muted" x1="20" y1="686" x2="620" y2="686"/><text class="gis-label-sm" x="40" y="722">geom</text><text class="gis-label-mono" x="180" y="722">POINT(5.8983 50.4851)</text><line class="gis-muted" x1="20" y1="740" x2="620" y2="740"/><text class="gis-label-sm" x="40" y="776">tags</text><text class="gis-label-mono" x="180" y="776">{"amenity": "drinking_water",</text><text class="gis-label-mono" x="180" y="806">"drinking_water": "yes",</text><text class="gis-label-mono" x="180" y="836">"natural": "spring",</text><text class="gis-label-mono" x="180" y="866">"wikidata": "Q27959454"}</text><text class="gis-label-sm" x="20" y="990">name gets a column of its own, never a stored tag</text></svg>
<figcaption>What crosses from a node's full tag list into the stored row is a choice made once, at
parse time, not everything OpenStreetMap happens to have attached to this object, only the keys the
contract names. The struck-through tags were never on their way to being kept; dropping them is the
point of this step, not an accident of it. The <code>note</code> tag is the one to watch leave. It reads <code>survey: drinkable</code>, which looks like it settles something and settles nothing: it is one mapper&#8217;s prose, with no date, no author and no agreed meaning, and this project never reads it. Whether a water point is drinkable is decided by a named rule over named tags, never by a sentence that happens to contain the word.</figcaption>
</figure>

`load.py::load_region()` writes a region's rows in one transaction: `COPY` into a staging table,
decide by geometry which rows this extract actually owns, check the row count has not collapsed
suspiciously against the previous run (a truncated download must never silently wipe a region), then
merge the staged rows into `coverage_poi` as a delta. The merge is an upsert on `(ref, letter)`
guarded by `IS DISTINCT FROM`, so a row that has not changed is not rewritten at all, followed by a
delete scoped to this extract's own slice for the rows that have disappeared from it. One
transaction, so a reader never sees a half-loaded region. That mechanism is worth knowing about, but it is
plumbing this chapter does not need to unpack further, the fountain's own row is the point. It now
exists as an ordinary row in an ordinary PostGIS table: a Point in EPSG:4326 (chapter 1,
[`coordinates.md`](coordinates.md)), addressable by every technique chapters 3 through 5 already
taught, with a small, deliberately incomplete set of tags attached.

<figure class="gis-fig">
<svg viewBox="0 0 640 800" role="img" aria-labelledby="f16-t f16-d" xmlns="http://www.w3.org/2000/svg"><title id="f16-t">How one region&#8217;s rows are merged into coverage_poi</title><desc id="f16-d">A single downward flow with two side exits, all inside one transaction. At the top, parsed rows are COPYed into a temporary staging table. An arrow down into an ownership filter, which keeps only the rows whose nearest region belongs to this extract&#8217;s own country and drops the rest, with a side note that the neighbouring extract keeps those. An arrow down into a drift check, which compares the staged count against the previous good run and, if it has collapsed by more than forty percent, aborts and rolls everything back, leaving last week&#8217;s slice serving. That abort exits to the side. Otherwise the flow reaches the merge, which splits into two operations on coverage_poi: an upsert on the pair ref and letter, guarded by IS DISTINCT FROM so an unchanged row is not rewritten at all and costs no write, and a delete scoped to this extract&#8217;s own slice, removing rows that have disappeared from it. Both feed the coverage_poi table at the bottom. A bracket down the left side marks the whole diagram as one transaction, so any error anywhere rolls back the lot.</desc><defs><marker id="gis-arrow-f16" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="14" markerHeight="14" markerUnits="userSpaceOnUse" orient="auto-start-reverse"><path class="gis-fill-accent" d="M 0 0 L 10 5 L 0 10 Z"/></marker></defs><path class="gis-accent" d="M 40 60 L 24 60 L 24 700 L 40 700"/><text class="gis-label-sm" x="24" y="40">one transaction</text><text class="gis-label-sm" x="24" y="732">any error here rolls the whole thing back</text><rect class="gis-box" rx="8" x="150" y="60" width="340" height="76"/><text class="gis-label-mono" x="320" y="103" text-anchor="middle">COPY into coverage_poi_staging</text><line class="gis-accent" x1="320" y1="136" x2="320" y2="174" marker-end="url(#gis-arrow-f16)"/><rect class="gis-box" rx="8" x="150" y="176" width="340" height="96"/><text class="gis-label-mono" x="320" y="199" text-anchor="middle">ownership filter</text><text class="gis-label-sm" x="320" y="229" text-anchor="middle">nearest region wins:</text><text class="gis-label-sm" x="320" y="259" text-anchor="middle">keep only this extract's own country</text><path class="gis-muted" stroke-dasharray="5 5" d="M 490 224 L 560 224"/><text class="gis-label-sm" x="500" y="204">dropped:</text><text class="gis-label-sm" x="500" y="254">a neighbour</text><text class="gis-label-sm" x="500" y="284">owns them</text><line class="gis-accent" x1="320" y1="272" x2="320" y2="310" marker-end="url(#gis-arrow-f16)"/><rect class="gis-box" rx="8" x="150" y="312" width="340" height="96"/><text class="gis-label-mono" x="320" y="335" text-anchor="middle">drift check</text><text class="gis-label-sm" x="320" y="365" text-anchor="middle">staged count against the last good run</text><text class="gis-label-sm" x="320" y="395" text-anchor="middle">more than 40% down: abort</text><path class="gis-muted" stroke-dasharray="5 5" d="M 150 360 L 90 360 L 90 420"/><text class="gis-label-sm" x="20" y="450">abort:</text><text class="gis-label-sm" x="20" y="480">last week&#8217;s</text><text class="gis-label-sm" x="20" y="510">slice keeps</text><text class="gis-label-sm" x="20" y="540">serving</text><line class="gis-accent" x1="320" y1="408" x2="320" y2="440" marker-end="url(#gis-arrow-f16)"/><path class="gis-accent" d="M 320 440 L 320 456 M 232 456 L 452 456"/><line class="gis-accent" x1="232" y1="456" x2="232" y2="486" marker-end="url(#gis-arrow-f16)"/><line class="gis-accent" x1="452" y1="456" x2="452" y2="486" marker-end="url(#gis-arrow-f16)"/><rect class="gis-box" rx="8" x="140" y="488" width="190" height="120"/><text class="gis-label-mono" x="235" y="508" text-anchor="middle">UPSERT</text><text class="gis-label-sm" x="235" y="538" text-anchor="middle">on (ref, letter),</text><text class="gis-label-sm" x="235" y="568" text-anchor="middle">guarded by</text><text class="gis-label-sm" x="235" y="598" text-anchor="middle">IS DISTINCT FROM</text><rect class="gis-box" rx="8" x="356" y="488" width="190" height="120"/><text class="gis-label-mono" x="451" y="523" text-anchor="middle">DELETE</text><text class="gis-label-sm" x="451" y="553" text-anchor="middle">scoped to this</text><text class="gis-label-sm" x="451" y="583" text-anchor="middle">extract's own slice</text><text class="gis-label-sm" x="140" y="640">unchanged row:</text><text class="gis-label-sm" x="140" y="668">no write at all</text><text class="gis-label-sm" x="356" y="640">gone from the</text><text class="gis-label-sm" x="356" y="668">extract: removed</text><path class="gis-accent" d="M 232 608 L 232 684 L 300 684" marker-end="url(#gis-arrow-f16)"/><path class="gis-accent" d="M 452 608 L 452 684 L 384 684" marker-end="url(#gis-arrow-f16)"/><rect class="gis-box" rx="8" x="222" y="676" width="196" height="60"/><text class="gis-label-mono" x="320" y="712" text-anchor="middle">coverage_poi</text></svg>
<figcaption>One region’s rows, from the staging table to the live one, all inside a single
transaction. Two side exits: a row this extract does not own
leaves at the filter and a neighbour keeps it, and a suspiciously small run leaves at the drift
check with last week’s slice still serving. Nothing is deleted and re-inserted; an unchanged row
is not written at all.</figcaption>
</figure>

## Why copy at all

If a pipeline is always interpreting rather than simply reading, and a fair amount of what OSM knows
about an object never even reaches our table, why keep a copy at all instead of asking
OpenStreetMap itself, live, whenever a rider needs an answer?

`docs/specs/osm-data-architecture.md` gives the short answer, and it is worth reading in full rather
than restated at length here. In brief: this project **references** OpenStreetMap rather than
**forking** it, the fountain's row still carries its `ref` back to the real OSM object, it is never
presented as if it were our own independent survey, and `coverage_poi` exists purely to **serve
queries fast**, not to be any kind of authority on what is really at that spot. The authority stays
OpenStreetMap's; this table is a cache in front of it.

## What to carry into chapter 7

- OSM has three primitives (node, way, relation), and a free-form tag list is how any of them gets
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
- `coverage_poi` always stores a Point, never a LineString or Polygon, even for a way, a way
  collapses to the mean of its member nodes' coordinates.
- We copy OSM to serve queries fast, not to replace it as the authority on what is really there.

The fountain now has a row of its own, a Point, a handful of trimmed tags, a region and country
stamp. A browser still cannot be handed that row directly: chapter 7 (`tiles.md`) is where it becomes
part of a small file a map can actually fetch.

## Try it

!!! tip "Hands-on: watch the allow-list eat a real object's tags"
    The narrowing step this chapter describes happens at load time, so you cannot see "before" by
    querying the database, by the time a row exists, the trim has already run. What you *can* do is
    read the input and the output side by side, because both are in this repository. The input is
    one node in the OSM fixture `make course-data` runs through the real pipeline. Here it is, in
    full, eleven tags:

    <!-- CODE-FROM pipeline/tests/fixtures/mini.osm -->
    ```xml
      <node id="105" version="4" timestamp="2026-02-11T14:00:00Z" lat="50.2200" lon="5.0000">
        <tag k="historic" v="castle"/>
        <tag k="name" v="Château de Vêves"/>
        <tag k="website" v="https://chateau-veves.example"/>
        <tag k="wheelchair" v="limited"/>
        <tag k="wikidata" v="Q1857286"/>
        <tag k="image" v="https://commons.example/veves.jpg"/>
        <tag k="inscription" v="Anno 1230"/>
        <tag k="person:date_of_birth" v="1907-04-12"/>
        <tag k="email" v="info@chateau-veves.example"/>
        <tag k="addr:postcode" v="5561"/>
        <tag k="building" v="castle"/>
      </node>
    ```

    Now ask the database what survived. Selecting by `name`, not by `ref` or `id`, a real Geofabrik
    extract numbers its nodes differently, so `name` is the selector that keeps working when you
    later replace this fixture with a real country:

    <!-- CODE-ILLUSTRATIVE shell command against the dev stack's Postgres -->
    ```sh
    docker compose -f developers/docker/compose.yaml exec db psql -U cc -d cyclingcommons -c "
    SELECT letter, name,
      (SELECT jsonb_agg(k ORDER BY k) FROM jsonb_object_keys(tags) k) AS stored_keys
    FROM coverage_poi WHERE name = 'Château de Vêves';
    "
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM fresh-clone; sample output on a stack seeded by `make course-data` -->
    ```text
     letter |       name       |                        stored_keys
    --------+------------------+------------------------------------------------------------
     Q      | Château de Vêves | ["historic", "image", "website", "wheelchair", "wikidata"]
    (1 row)
    ```

    Eleven tags in, five stored. `inscription`, `person:date_of_birth`, `email`, `addr:postcode` and
    `building` are gone, none of them appears in
    `pipeline/contract/coverage-contract.json`'s `storedTagKeys`, the 33-key list `parse.py`'s
    `_stored_keys` filter checks every key against at load time. `name` is gone from `tags` too, but
    for a different reason: it is promoted to its own column, so keeping it in the blob as well would
    store it twice.

    That is the whole narrowing step, on one object, end to end: the left-hand side is a file you can
    open, the right-hand side is a row you just queried, and nothing between them is hidden. Run
    `make coverage-refresh` (chapter 5 covers what that costs) and the same query shape works on any
    real row, swap the name for one of your own, or drop the `WHERE` and add
    `ORDER BY jsonb_array_length(...)` to find the tag-richest object in the table.
