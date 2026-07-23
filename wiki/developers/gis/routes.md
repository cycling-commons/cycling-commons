<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Lines that mean something

Every chapter so far has followed one drinking-water fountain in Wallonia. It is still there, and
it is still a Point — chapter 2 ([`shapes.md`](shapes.md)) fixed that. This chapter steps off the
fountain on purpose, because a fountain cannot answer the question this chapter is about.

A ride is not a point. It is a **LineString** — chapter 2 already introduced the word — running
from wherever a rider clipped in to wherever they stopped. A line can be asked questions a point
never could: how long is it, how much does it climb, and — the one this chapter is really about —
what does the ground under it actually look like? A point either sits on a mapped surface or it
does not. A 40 km route crosses dozens of separately-mapped road stretches, some surfaced, some
not, some not mapped at all, and "what surface is this route" stops being a lookup and becomes a
measurement with its own error bars. That is the whole chapter: how this project takes that
measurement, and why it tells you, honestly, how much of the measurement it can even vouch for.

## GPX is a list of points

Before a ride is a LineString in a database, it is a **GPX file** — an XML format that bike
computers, phone apps, and GPS watches all export. Strip away the XML ceremony and a GPX track is
an ordered list of `<trkpt lat="…" lon="…">` elements, usually each with a nested `<ele>` (the
recorded elevation) and often a `<time>`. This project's own parser —
`web/src/Contribution/Gpx/GpxParser.php`, `GpxParser::parse()` — reads exactly `lat`, `lon`, and
`ele` off every `<trkpt>` it finds and nothing else; it never looks at `<time>` at all, because
nothing downstream needs it.

Turning that into the LineString this project stores is almost entirely a *subtraction*, not a
transformation. `RouteProposalService::propose()` (`web/src/Contribution/RouteProposalService.php`)
takes the parsed `[lat, lng, ele|null]` triples, keeps only the first two numbers, and flips their
order:

<!-- CODE-FROM web/src/Contribution/RouteProposalService.php -->
```php
$coords = array_map(
    static fn (array $p): array => [$p[1], $p[0]],
    $this->processor->simplify($trimmed),
);
```

`[lat, lng]` becomes `[lng, lat]` — the same longitude-first flip chapter 1
([`coordinates.md`](coordinates.md)) warned about, happening in this codebase's own route intake
path. The elevation comes along for one more calculation (below) and then it is dropped for good:
`recommended_route.geom` never
stores an elevation, only the flattened `[lng, lat]` path. "Becoming a LineString" really is just
"throw away everything except where it was."

## Derived numbers are choices

Two numbers get attached to every proposed route before it is ever saved: `distance_m` and
`ascent_m`. They sound like plain facts read off the track. They are not. Each one is the output
of a decision somebody made about how to calculate it, and a different decision gives a different
number from the *same* GPS trace.

Both of them are measured on the **trimmed** track, and that word means something specific here.
Before anything is calculated or stored, `TrackProcessor::trim()` cuts between 350 and 750 metres
off *each end* of the uploaded track — the **privacy end-trim** (route-domain.md §4.3), so that a
stored route never reveals where its proposer actually started or finished. How much comes off is
derived from a hash of the upload's own bytes, which makes it deterministic for a given file but not
guessable from the result. Nothing downstream ever sees the untrimmed track; the untrimmed upload
never reaches storage at all. Note that trimming is not the same operation as *thinning*, which
happens later and is a different word for a different thing — the distinction matters for `ascent_m`
below.

**Distance is the easy one, and it is still a choice.** `TrackProcessor::distanceM()`
(`web/src/Contribution/Gpx/TrackProcessor.php`) walks the trimmed track and sums the great-circle
distance between every consecutive pair of points. That per-pair distance is the **haversine
formula**, in `TrackProcessor::haversineM()`: the standard way to get the distance between two
`[lat, lng]` pairs measured *along the surface* of a sphere, rather than straight through it. The
sphere it assumes is a single fixed radius, `EARTH_RADIUS_M`, 6,371,000 metres — not the ellipsoid
that `::geography` uses in SQL (chapter 3, [`metres-vs-degrees.md`](metres-vs-degrees.md)), which is
one more small choice made here in passing, and a defensible one at the scale of a bike ride. That
sum depends on how many points the track has: a denser GPS trace produces a slightly longer sum
than a sparser one, because more short zig-zags get counted individually instead of averaged away.
The choice here is nearly invisible because GPS traces are dense enough that it barely moves the
answer — but it is still a choice, made once, and
baked into every stored `distance_m`.

**Ascent is where the choice actually shows.** `TrackProcessor::ascentM()` does the obvious naive
thing: walk the same trimmed points in order, and for every step where elevation went up, add the
difference to a running total.

<!-- CODE-FROM web/src/Contribution/Gpx/TrackProcessor.php -->
```php
if ($i > 0 && $points[$i][2] > $points[$i - 1][2]) {
    $gain += $points[$i][2] - $points[$i - 1][2];
}
```

Notice what this *doesn't* do: it doesn't smooth, it doesn't ignore small wobbles, it doesn't
require a rise to clear any minimum before it counts. GPS and barometric elevation readings are
noisy by several metres in either direction, point to point, even on dead-flat ground. Sum every
uphill wobble on a noisy signal and you are, in part, counting noise as climbing — and this method
counts every one of them, on the full-density track, because `RouteProposalService::propose()`
calls it on the **trimmed** points — ends cut off, every remaining point still there — *before*
those points get **thinned** down for serving (route-domain.md §4.2, step 5, ahead of the
Douglas–Peucker simplification in step 6). Two similar-sounding words, two different operations, and
`ascent_m` lands between them. A denser track has more wobbles to sum, so — like distance, but far
more visibly — a different recording density can hand back a different `ascent_m` for a ride that
climbed exactly the same hill.

<!-- UNANCHORED id=U90 type=general concept="elevation-gain smoothing (minimum-threshold accumulation)" -->

None of that makes the naive sum wrong. It makes it *a* choice, out of several reasonable ones —
some tools only count a rise once it clears a small threshold (a metre or two), precisely to filter
that wobble out, at the cost of slightly under-counting real short, sharp climbs. This project's
choice is on the record, in the four lines above, and that is the point of this whole section: a
derived spatial number is never simply "the" answer. It is one defensible way of reading the raw
data, and the value of writing it down in the open — rather than treating `ascent_m` as some kind
of ground truth — is that anyone reading the code can see exactly which one this project took.

## Attribution by buffer

"What surface is this route" is the same kind of question as ascent — not a lookup, a
measurement — and this project answers it with a technique called **buffer-based attribution**.
The idea: draw a corridor a fixed width either side of the route, find every separately-mapped road
segment that falls inside that corridor, and total up the metres per surface. Whichever surfaces
have the most mapped metres nearby are, most likely, the surfaces the ride actually crosses.

`web/src/Catalog/SurfaceProfiler.php` is that technique, in full. The corridor width is
`SurfaceProfiler::BUFFER_M`, a constant fixed at 25 metres. Its first query is close to English
read aloud: take every served, surface-tagged item on the **A layer** (`item.letter = 'A'`, "Road
surface" — the same OpenStreetMap-derived layer this project already stores for its own reasons)
within `ST_DWithin(…, 25)` of the route, cast everything to `::geography` so 25 means 25 real
metres rather than 25 degrees (chapter 3, [`metres-vs-degrees.md`](metres-vs-degrees.md), is the
reason that cast has to be there at all), intersect each one with the buffered route, sum the
intersected length per surface, and group by `attributes->>'surface'`.

One filter matters as much as the buffer itself: rows tagged `Surface unverified` are excluded
from that query outright, and from everything the rest of this chapter describes. A segment
someone mapped without recording a usable surface says nothing about what is actually underfoot,
so it is dropped before it can shift the total either way — a segment that says nothing should not
get a vote.

This runs once at intake — `RouteProposalService::propose()` calls
`SurfaceProfiler::profile()` on the freshly built geometry and, when it finds anything, stores the
result as `attributes.surfaces` — an attribute route-domain.md §9 lists as a profile object and
marks "derived, never user-supplied", which is to say no rider or curator can type a value into it.
It also reruns wholesale whenever the underlying A-layer map data changes:
`SurfaceProfiler::recomputeAll()`, driven by `RouteSurfacesCommand` (`app:catalog:route-surfaces`)
or automatically after a harvest import, so a route's surface estimate stays current with the map
under it rather than freezing at the moment it was proposed. A curator desk also has the rider's
own `dominantSurface` guess sitting next to this derived figure (route-domain.md §9) — one
declared, one measured, shown side by side rather than merged into one number.

## Two numbers, measured on different sides

`SurfaceProfiler::profile()` doesn't return one number. It returns two, `parts` and `covered`, and
the reason it returns two rather than one is the actual lesson of this chapter: **they are
deliberately measured on different sides of the same buffer**, so that one of them can never
quietly lie by exceeding 100%.

**`parts`** is measured **segment-side**. For each surface, it is the metres of *mapped segment*
inside the buffer, divided by the *total mapped metres* found near the route — not the route's own
length. If two separate contributors mapped the same stretch of road twice, both copies get
counted: the numerator for that surface goes up, but so does the shared denominator, by exactly
the same amount. Parallel or duplicate mapping inflates both sides of the fraction equally, which
is exactly why a share can never end up above 100% no matter how much redundant mapping sits near
the route. (There is a second, smaller piece of care here too: only the top four surfaces by
length are kept, and their percentages are rounded by largest-remainder rather than independently —
independent rounding on near-equal shares can push a total just over 100 by itself, so kept shares
are floored first and the leftover integer points handed to the largest fractional remainders. It
is a rounding detail, not the headline lesson, but it is there specifically to protect the same
guarantee.)

**`covered`** is measured **route-side**, against the opposite of a sum: the *union* of all those
same mapped segments, flattened into one merged shape first (`ST_Union`), then buffered once. The
question `covered` asks is "how much of the route falls inside that single merged buffer" — and
because the numerator there is a piece of the route's own length and the denominator is the whole
route's length, a portion can never be larger than the whole it is a portion of, no matter how many
overlapping segments contributed to the union. Duplicate mapping cannot push `covered` past 100%
even once, because by the time the union is taken, mapping the same stretch of road twice looks
identical to mapping it once.

That is the whole reason `covered` exists: it is the **"how much of this route is even mapped"**
honesty figure, shown next to the surface estimate rather than folded into it. A route with
`parts: [{"surface": "Asphalt", "pct": 90}]` and `covered: 12` is not lying — it is saying "of the
little bit of this route we found anything mapped near at all, 90% of that little bit was
asphalt, and that little bit was 12% of the ride." Read `parts` alone and you would think the
route is confidently asphalt. Read `covered` alongside it and you know exactly how much confidence
that "confidently" deserves.

<figure class="gis-fig"><svg viewBox="0 0 640 520" role="img" aria-labelledby="f16-t f16-d" xmlns="http://www.w3.org/2000/svg"><title id="f16-t">SurfaceProfiler's two measurements, parts and covered, taken on the same route</title><desc id="f16-d">Two side-by-side panels sharing one wavy route line, drawn identically in both, with small dots marking its start and end. The left panel is labelled parts, segment-side. Along the route, two short thick stretches are coloured to represent mapped road segments: one orange labelled asphalt 55 percent, one rust-coloured labelled gravel 45 percent. Between and around these coloured stretches the route is left as a thin plain line, meaning no mapped segment was found there at all. Below the panel, two separate bracket bars sit under only the two coloured stretches, joined by a small plus sign, labelled "sum = mapped metres" — the percentages are shares of only the coloured metres; the plain gaps do not enter the sum on either side of the fraction. A small legend below identifies the orange swatch as asphalt and the rust swatch as gravel. The right panel is labelled covered, route-side. It shows the exact same route and the exact same two mapped stretches, but here they are highlighted in a single green colour rather than coloured by surface, captioned "covered = 50 percent", and the remaining stretches are left as the same thin plain line as before. Below this panel a single unbroken bracket bar spans the entire route from start to end, labelled "= whole route length". A legend identifies the green highlight as covered and a short plain line as not covered. The figure's point is the contrast between the two bracket bars: the left one only ever spans the mapped stretches, so parts is a share of what is mapped and can reach 100 percent of that; the right one always spans the whole route regardless of how much is mapped, so covered is a share of the whole route and can never be pushed past 100 percent by overlapping or duplicate mapping.</desc><text x="320" y="30" text-anchor="middle">One route, two denominators</text><text class="gis-label-sm" x="320" y="58" text-anchor="middle">the same mapped segments, counted two ways</text><text class="gis-label-mono" x="160" y="92" text-anchor="middle">parts</text><text class="gis-label-sm" x="160" y="116" text-anchor="middle">segment-side</text><text class="gis-label-mono" x="480" y="92" text-anchor="middle">covered</text><text class="gis-label-sm" x="480" y="116" text-anchor="middle">route-side</text><rect class="gis-muted" x="20" y="136" width="280" height="260"/><rect class="gis-muted" x="340" y="136" width="280" height="260"/><path class="gis-ink" stroke-width="2" d="M 40 336 Q 70 186 100 196 Q 130 326 160 336 Q 190 176 220 196 Q 250 266 280 296"/><path class="gis-accent" stroke-width="6" d="M 40 336 Q 70 186 100 196"/><path class="gis-clay" stroke-width="6" d="M 160 336 Q 190 176 220 196"/><circle class="gis-fill-ink" cx="40" cy="336" r="5"/><circle class="gis-fill-ink" cx="280" cy="296" r="5"/><text class="gis-label-sm gis-halo" x="40" y="356" text-anchor="start">start</text><text class="gis-label-sm gis-halo" x="280" y="318" text-anchor="end">end</text><text class="gis-label-sm gis-halo" x="90" y="175" text-anchor="middle">asphalt 55%</text><text class="gis-label-sm gis-halo" x="226" y="175" text-anchor="middle">gravel 45%</text><path class="gis-ink" stroke-width="2" d="M 360 336 Q 390 186 420 196 Q 450 326 480 336 Q 510 176 540 196 Q 570 266 600 296"/><path class="gis-spruce" stroke-width="6" d="M 360 336 Q 390 186 420 196"/><path class="gis-spruce" stroke-width="6" d="M 480 336 Q 510 176 540 196"/><circle class="gis-fill-ink" cx="360" cy="336" r="5"/><circle class="gis-fill-ink" cx="600" cy="296" r="5"/><text class="gis-label-sm gis-halo" x="360" y="356" text-anchor="start">start</text><text class="gis-label-sm gis-halo" x="600" y="318" text-anchor="end">end</text><text class="gis-label-sm gis-halo" x="480" y="175" text-anchor="middle">covered = 50%</text><line class="gis-ink" stroke-width="1.5" x1="40" y1="410" x2="100" y2="410"/><line class="gis-ink" stroke-width="1.5" x1="40" y1="404" x2="40" y2="416"/><line class="gis-ink" stroke-width="1.5" x1="100" y1="404" x2="100" y2="416"/><line class="gis-ink" stroke-width="1.5" x1="160" y1="410" x2="220" y2="410"/><line class="gis-ink" stroke-width="1.5" x1="160" y1="404" x2="160" y2="416"/><line class="gis-ink" stroke-width="1.5" x1="220" y1="404" x2="220" y2="416"/><line class="gis-ink" stroke-width="1.5" x1="124" y1="410" x2="136" y2="410"/><line class="gis-ink" stroke-width="1.5" x1="130" y1="404" x2="130" y2="416"/><text class="gis-label-sm" x="130" y="436" text-anchor="middle">sum = mapped metres</text><line class="gis-ink" stroke-width="1.5" x1="360" y1="410" x2="600" y2="410"/><line class="gis-ink" stroke-width="1.5" x1="360" y1="404" x2="360" y2="416"/><line class="gis-ink" stroke-width="1.5" x1="600" y1="404" x2="600" y2="416"/><text class="gis-label-sm" x="480" y="436" text-anchor="middle">= whole route length</text><rect class="gis-ink gis-fill-accent" x="60" y="462" width="18" height="18"/><text class="gis-label-sm" x="84" y="475" text-anchor="start">asphalt</text><rect class="gis-ink gis-fill-clay" x="180" y="462" width="18" height="18"/><text class="gis-label-sm" x="204" y="475" text-anchor="start">gravel</text><rect class="gis-ink gis-fill-spruce" x="380" y="462" width="18" height="18"/><text class="gis-label-sm" x="404" y="475" text-anchor="start">covered</text><line class="gis-ink" stroke-width="2" x1="480" y1="471" x2="498" y2="471"/><text class="gis-label-sm" x="504" y="475" text-anchor="start">not covered</text></svg><figcaption>The numbers shown (55% / 45% / 50%) are illustrative, not a real route. What matters is the two bracket bars: on the left, the bracket only ever spans the coloured, mapped stretches — <code>parts</code> is a share of mapped metres. On the right, the bracket always spans the entire route, mapped or not — <code>covered</code> is a share of the whole route. Measuring on different sides like this is deliberate, so that neither figure can quietly exceed 100%, and so a route with barely anything mapped near it says so honestly through a low <code>covered</code>, rather than reporting a confident-looking surface split with no disclosed error bar.</figcaption></figure>

## Routing is a different problem

Everything above is about a route that already exists. "Find me a route from A to B" is a
different kind of problem entirely: not a spatial query over stored geometry, but **graph search**
over a network of road segments with a cost attached to each one (distance, surface, steepness),
looking for the cheapest path through it. This project does not implement that search.

!!! note "Not in the Commons — yet"
    Turn-by-turn route computation between two points is not wired into the application. Valhalla,
    an open-source routing engine, exists in the dev stack as an opt-in Docker Compose profile
    (`profiles: ["routing"]`, the `valhalla` service in `developers/docker/compose.yaml`) that a
    developer can start locally against a downloaded tile set — but nothing under `web/src/` calls
    it today.

<!-- UNANCHORED id=U91 type=absent concept="turn-by-turn route computation (A to B) via a routing engine" -->

## What to carry into chapter 10

- A ride is stored as a LineString the same way any GeoJSON LineString is — chapter 2 already
  covered the shape. What is new here is that *lines carry derived numbers a point never needs*.
- `distance_m` and `ascent_m` are not facts read off the GPS trace, they are choices about how to
  read it. This project's own `TrackProcessor::ascentM()` makes its choice in the open: sum every
  uphill step, on the full-density track, with no smoothing.
- "What surface is this route" is answered by buffering the route (`SurfaceProfiler::BUFFER_M`,
  25 m) and totalling the mapped road metres inside it, per surface — excluding anything tagged
  `Surface unverified`, because a segment that says nothing should not vote.
- `parts` (segment-side, over total mapped metres) and `covered` (route-side, over the route's own
  length against a flattened union) are measured on deliberately different sides of the same
  buffer, so neither one can quietly exceed 100% — and `covered` doubles as the honest "how much of
  this is even mapped" figure shown next to the estimate.
- The general lesson travels well beyond routes: any derived spatial number is a choice among
  several defensible ones, and that choice should be visible to whoever reads the result, not
  buried inside a function that looks like it is reporting a fact.
- Routing between two points is a graph-search problem this project hands to Valhalla, opt-in, not
  something it builds itself.

<!-- EXERCISE-SLOT ch=9 — hands-on box goes here (spec D5); do not remove -->
