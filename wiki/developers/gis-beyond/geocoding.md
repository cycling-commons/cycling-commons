<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Geocoding, both directions

Course 1 already introduced geocoding, right at the end of
[`spatial-questions.md`](../gis/spatial-questions.md): turning a place name into coordinates is a
text-matching problem with a spatial tiebreak, not really a spatial question at all. This chapter
is about the other direction, and about drawing its boundary precisely, because "this project never
reverse-geocodes" is true in one sense and false in another.

**This is a "we deliberately do not" chapter, with a boundary worth stating carefully.** Cycling
Commons calls exactly one external geocoder, Photon, and only ever name in, coordinates out. It never
sends a coordinate to anybody else's server and asks for a place name back. That is a design
decision, made on purpose and recorded in this project's own specs.

It is not the same as saying the app never turns a point into a place. It does, in PostGIS, against
its own `region` table, three times over: ride-check lists the regions an uploaded GPX passes
through (`RideCheckService::crossedRegions()`, `web/src/Catalog/RideCheckService.php`), the settings
page's base point becomes a set of regions and countries (`BaseAreaResolver::resolve()`,
`web/src/Service/BaseAreaResolver.php`), and every submission is assigned its containing region
(`SpatialResolver::resolve()`, `web/src/Contribution/SpatialResolver.php`). Each of those is a
containment or distance query over polygons this project already stores, with the answer level fixed
in advance: the region. What this project does not do is the open-ended version, "what is this
coordinate called", answered by an external service. The rest of this chapter explains what that
version would cost, and why the project avoids paying it.

| where | the call | what it answers |
|---|---|---|
| ride-check, on an uploaded GPX | `RideCheckService::crossedRegions()` | which regions this line passes through |
| the settings page's base point | `BaseAreaResolver::resolve()` | which regions and countries this point belongs to |
| every submission | `SpatialResolver::resolve()` | which region contains this point |

Read the third column, not the second. All three answer **at the region level**, fixed in advance,
because that is the level this project's own polygons are cut at. Reverse geocoding is the
open-ended version of the same question, where the answer level is whatever the service decides:
a street, a suburb, a city, a country, chosen by somebody else's idea of what is nearby. That
difference, not the direction of the arrow, is the reason one is cheap and correct here and the
other is not.

## Forward and reverse, defined

**Forward geocoding** takes a name — "Namur," "Rue de la Station," "Cote de la Redoute" — and returns
coordinates. **Reverse geocoding** takes coordinates and returns a name. They sound like mirror
images of the same lookup, and a lot of software treats them that way, offering both from one
service under one API. But they are not symmetric problems, and the asymmetry is worth
understanding before deciding whether to build the second one.

## Forward geocoding, in this project

Course 1's closing section already covered this project's forward-geocoding call in detail. There
is one geocoder, **Photon**, a free, keyless, OpenStreetMap-based service, and three browser callers:
the map's place search (`web/assets/map/search-ui.js`), the settings page's base-location field
(`web/assets/settings/base-location.js`), and the contribute wizard's place search
(`web/assets/contribute/improve.js`). None of the three needs a server-side proxy. The CSP
allow-list for external geocoders is exactly one entry long, and it is a name-in service:

<!-- CODE-FROM web/src/EventSubscriber/CspSubscriber.php -->
```php
'https://photon.komoot.io',
```

`docs/specs/map-and-search.md` §7.2 records the rule behind that single entry: Photon for
type-ahead, never **Nominatim**, whose usage policy forbids autocomplete and caps an application at
one request per second, a cap no code can honour when the calls come from thousands of riders'
browsers. The only way to respect it at scale is to need it zero times. The same section draws the
corollary for region outlines: the polygon a rider sees around a region comes from this project's
own `/map/region/{slug}/boundary` (`RegionBoundaryProvider`), never from a geocoder's answer, so
there is no second external host to allow.

The shape of every geocoder call is the same: a `/search`-style endpoint, a `q` (query) parameter
carrying a name, and a response carrying coordinates. Name in. Shape out. None of them takes a
coordinate and asks for a name back.

## Why reverse is genuinely harder

It would be easy to assume reverse geocoding is just forward geocoding run backwards — swap the
input and output and the same index answers both. It does not work that way. The two directions
have different hard parts.

Forward geocoding has one hard part: matching a messy string to a specific real place, tolerant of
misspellings and abbreviations, weighted toward whichever match is more prominent or more likely
given other context. Once a match is found, the "coordinates out" half is trivial — the matched
place already has a stored point or shape.

Reverse geocoding has no matching step to speak of — a pair of coordinates is exact, nothing fuzzy
about it — but it replaces that step with a much harder question: **which of several places
containing this point is the right answer?** A single coordinate sits inside a street, a
neighbourhood, a town, a province, a country, all at once, nested inside each other like a Russian
doll. "What place is at this point?" is not one containment question, it is a *choice* of which
level of that nesting to report, and the right level depends entirely on why the question was asked.
A route-planning app wants the town. A weather app wants the country and maybe the region. A parcel
courier wants the street and house number, if there even is one — plenty of coordinates, like a spot
in a forest or a field, sit outside every mapped street entirely and forcing a nearest-street answer
onto them is not a small rounding error, it is a wrong answer stated with false confidence.

There is a second difficulty layered on top of the first: administrative boundaries are drawn by
people, change over time, and are contested at the edges in several parts of the world. A coordinate
a few metres from a border can genuinely belong to either neighbour depending on which authority's
data is asked, and a reverse-geocoding service has to pick one and state it as fact. Forward
geocoding never faces this: the rider already knows what to call the place they typed, ambiguity and
all, and the geocoder's only job is to find a match for what was actually typed.

## Address normalisation

A related, equally general idea is **address normalisation**: taking a free-text address —
however it was typed, abbreviated, or ordered — and rewriting it into one comparable, structured
form (street, house number, postcode, city, country, each in its own field). It matters for both
directions of geocoding, but for different reasons. Forward geocoding normalises the *query* before
matching it, so "Rue de la Station 12, 5000 Namur" and "12 rue station namur" have a chance of
matching the same record. Reverse geocoding normalises the *answer*, because a raw administrative
record rarely comes back in the shape an application wants to display or store.

Cycling Commons has no address-normalisation code of its own, for the plain reason that it never
needs one: it only ever forward-geocodes a rider-typed place name through Photon, and Photon's own
matching already handles the fuzziness on the query side. There is no address field anywhere in this
project to normalise, and no reverse-geocode result to reshape into one.

## The decision, on the record

It would be easy to assume this project reverse-geocodes somewhere, because the map already shows a
label that looks exactly like the output of one. A rider who sets a base location in their settings
sees their scope described on the map as something like "Near Namur · 40 km." That reads like "take
my coordinates, look up the nearest named place, and print it" — the textbook definition of reverse
geocoding.

It is not that. The region-scoping work's phased implementation plan
records the actual decision: alongside the coarse coordinate and the search radius, the settings
form stores a fifth column, `base_place`, filled in once, at the exact moment a rider picks a town
from the forward-search results Photon already returned. The name was never looked up backwards —
it was captured forwards, at pick time, and stored from then on. Showing "Near Namur" later is a
column read, not a network call.

That single design choice is why this project can honestly claim it never reverse-geocodes a
*name*: every place name it displays next to a coordinate was typed or picked by a person first, at
the moment that coordinate was chosen, and stored rather than re-derived. What the base point does
get derived into, on the server, is a *scope*. `BaseAreaResolver::resolve()` asks PostGIS which
operational regions lie within the chosen radius (`ST_DWithin` on `geography`, the containing region
ranked first) and stores their ids and country codes in `base_region_ids` and `base_country_codes`
beside the point. That is a point-to-polygon query over this project's own data with the answer
level fixed in advance, which is exactly the part of reverse geocoding that is easy. The hard part,
choosing a name at the right level of the nesting, is the part the stored `base_place` sidesteps.
Storing the answer once, rather than asking a stranger's server for it on every page load, is the
same tradeoff the region backdrop makes: the outline comes from `RegionBoundaryProvider` and this
project's own `region` rows, not from a live lookup.

The one place this project's own specs admit the gap plainly is route start-towns. The map already
shows which towns a featured route passes through, but not by reverse-geocoding the track — by a
hardcoded, name-keyed table that only recognises the small handful of routes it lists by name. It
sits in `map.js`, inside the `CC_*` payload hand-off that chapter 8 of course 1 describes, rather
than in one of the map modules, because it decorates the seeded route payload before any layer sees
it:

<!-- CODE-FROM web/assets/map/map.js -->
```js
// Demo lookup keyed by ride name. No fallback — unknown routes omit town rows.
```

"No fallback" is the honest version of this chapter's whole argument in miniature. A rider-proposed
route has no hand-picked town name attached to it, and reverse-geocoding one from the raw track was
considered and explicitly rejected as a way to invent one: showing "Starts at: Spa" for a route
nobody ever told us starts at Spa is wrong data, not a rough guess. Rather than guess, the feature
simply does not show a town for a route it was never told one for. `docs/specs/route-domain.md` lists real reverse-geocoding from the track as recorded,
un-scheduled future work, precisely because doing it properly means confronting every question this
chapter just raised — which containing place, at which precision, with what fallback when the answer
is genuinely ambiguous — rather than papering over it with a lookup table that only covers the routes
somebody happened to type in by hand.

!!! info "A deliberate omission, not a missing feature"
    The application never calls a reverse-geocoding service. Every place name shown next to a
    coordinate in this project was captured once, from a rider's own forward search, at the moment
    they picked it, never derived backwards from the coordinate afterward. Point-to-region lookups
    do happen, but in PostGIS, against this project's own `region` table, as the introduction lists.
    The one caller of an external reverse endpoint in the repository is curator tooling, not the
    app: `tools/wikimedia/climb_audit.py` asks Nominatim `/reverse` for the settlement a climb side
    starts from, at audit time, one climb at a time, sleeping 1.1 s between calls to stay under
    Nominatim's published one-request-per-second limit. A script a curator runs by hand can honour
    that limit; a page in thousands of browsers cannot, which is the whole reason the app does not.

## Try it

!!! tip "Hands-on: one direction, called for real; the other, checked absent from the app"
    Call the exact Photon endpoint the settings page's base-location field builds
    (`base-location.js`'s `PH_BASE`), then check the whole tracked codebase for anything that calls a
    reverse endpoint, and see where the one hit lands.

    **This is the one exercise in either course that needs the internet.** Everything else runs
    against your own stack; this reaches out to `photon.komoot.io`, a third-party service this
    project does not run. Offline, or behind a proxy that blocks it, `curl` will fail with a
    connection or DNS error — that is the network, not a broken stack, and the second half of the
    exercise (a `git grep` over your own checkout) still works. The point being made is that forward
    geocoding is a *live call to somebody else's server*, so faking it locally would defeat it.

    <!-- CODE-ILLUSTRATIVE shell command; the exact PH_BASE query shape base-location.js and search-ui.js both build, called live against Photon's public API -->
    ```sh
    curl -s 'https://photon.komoot.io/api/?limit=6&osm_tag=place:city&osm_tag=place:town&osm_tag=place:village&osm_tag=place:hamlet&osm_tag=place:municipality&q=Namur' \
      | python3 -c "import json,sys; f=json.load(sys.stdin)['features'][0]; g=f['geometry']['coordinates']; print(f['properties']['name'], f['properties'].get('country'), g)"
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM network; sample output from a live call to Photon's public API -->
    ```text
    Namur België / Belgique / Belgien [4.8661892, 50.4665284]
    ```

    Name in — `Namur` — coordinates out: `[4.8661892, 50.4665284]`, `lng, lat`, in exactly the order
    course 1's [`pitfalls.md`](../gis/pitfalls.md) already warned matters. That is the only direction
    the app ever calls. Now grep the whole tracked tree for the other direction:

    <!-- CODE-ILLUSTRATIVE shell command against this repository's own tracked source -->
    ```sh
    git grep -n "/reverse" -- . ':!wiki'
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM any-install; sample output from this repository -->
    ```text
    tools/wikimedia/climb_audit.py:147:NOMINATIM = "https://nominatim.openstreetmap.org/reverse"
    ```

    One hit, and it is under `tools/`, the curator audit script the admonition above names. Narrow
    the same grep to the application and it goes quiet:

    <!-- CODE-ILLUSTRATIVE shell command restricted to the application tree -->
    ```sh
    git grep -n "/reverse" -- web
    ```

    <!-- CODE-ILLUSTRATIVE SAMPLE-FROM any-install; sample output from this repository; git grep prints nothing and exits non-zero when no line matches -->
    ```text
    (no output, exit status 1: nothing under web/ matches)
    ```

    Not one `/reverse` call, on Photon, Nominatim, or anything else, anywhere under `web/`, which is
    everything a rider's browser or the server runs. Forward geocoding is a real, working call you
    can make right now, against a real third-party service, and just did. Reverse geocoding of a name
    is not a call the app has: not hidden, not disabled, simply not written, exactly as the admonition
    above this exercise states. The point-to-region lookups the introduction lists are `ST_Contains`
    and `ST_DWithin` queries over this project's own tables, and course 1's
    [`spatial-questions.md`](../gis/spatial-questions.md) is where those live.
