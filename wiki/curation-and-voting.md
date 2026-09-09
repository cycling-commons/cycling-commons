<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Curation & Voting

!!! warning "Part design, part built"
    This page is the intention for curation, and only part of it runs today. **Live:** the map's
    three view modes, and the whole route path (propose, curator review, *I rode this*
    verification, and seasonal recommend-votes in the drawer). **Design only:** the seasonal
    ballot rounds, and voting on climbs, stays, views and heritage. The sections below say which
    is which.

**Curation over completeness** is one of the things that set the Cycling Commons apart. It is an
important part of the Commons, not the whole of its intention: what the Commons is for, and the
principles under it, are in the [Manifesto](manifesto.md). The Commons doesn't try to list *every*
climb that exists. It surfaces **the ones worth your weekend**, as judged by the riders who know the
area. For things that are a matter of taste, the best-of is the point; an exhaustive dump helps no
one plan a ride.

## Two kinds of data, two strategies

The single organising principle of the Commons:

| | Subjective / experiential | Objective / utility |
|---|---|---|
| **Examples** | best climbs, bike-friendly stays, finest views, history & culture, top quality rides | road surface, water points, toilets, repair stations, hazards, bike shops |
| **Goal** | **curated**: the best, ranked | **complete**: as exhaustive as possible |
| **How** | **routes**: rode-it counts plus seasonal recommend-votes on the map drawer. Climbs, stays, views & heritage (design): riders vote, refreshed in rounds | one-tap reports; confirm & decay |
| **The value is** | the *ranking* | the *coverage* |

Voting makes no sense for a water tap: it's either there or it isn't, and you want them all. Ranking
is the whole point for a climb: you want the best ten, not all two hundred.

## Regions

Curation happens **per region**. A region is ordinary public geography: a country, or one of its
first-level subdivisions such as a province or a state. It is never a shape invented by an app built
on top of the Commons. An app may line its own shapes up with Commons regions. The Commons never
bends its regions to fit an app. That is what keeps the Commons useful to everyone, and tied to no
single product.

For each region the Commons surfaces a **best-of**. It is one of the map's three view modes:

- ***Best of***: the curated list, what this page is about.
- ***Confirmed***: everything in *Best of*, plus every place a rider or curator has checked.
- ***Everything***: every place the Commons holds. This is the default view.

The best-of covers:

- the best **climbs**
- the best **bike-friendly stays**
- the most scenic **views**
- the best **history & culture** to ride past
- the best **quality rides / routes** (routes' best-of is the map's season/bike Best-of ranking, not the seasonal ballot)
- (extensible: best café stops, best gravel, etc.)

So when you arrive somewhere new, you get a clear, opinionated picture of the best there is, instead
of drowning in data.

## The voting rounds

!!! note "Not live yet"
    The seasonal ballot rounds described in this section are design; they are
    planned to open after launch. The `/vote` page shows the design and records
    nothing. What *is* running today is the route path below: propose, curator
    review, rode-it verification, seasonal recommend-votes on the map drawer,
    which feeds the Best-of ranking without a ballot. Everything else in this
    page (the two strategies, regions, the backlog, integrity) describes
    shipped behaviour.

- **Riders vote** on the candidates in each region.
- **A fresh round opens each season.** Four rounds a year give the Commons a rhythm that follows the
  riding: a reason to come back as the season turns, and room for new entries to rise.
- **Rounds re-rank, they don't reset.** The standing list carries forward and votes shift it. A
  legendary climb is never wiped out by one low-turnout season; it just has to keep earning its place.
- **These rounds cover climbs, stays, views, and heritage.** Routes sit outside the seasonal ballot:
  they follow their own **propose, curator moderate, rode-it-verify, seasonal recommend-vote** flow
  on the map drawer, feeding the same season/bike Best-of ranking rather than a ballot round.

## Solving the cold start

Empty lists before a voting culture exists would kill the feature.

What runs today: a route's place in the Best-of comes from what riders do on the map, rode-it
confirmations plus recommend-votes, and nothing else. Nothing in the ranking is derived from usage.

The design for the other lists is to seed an initial ranking from **aggregate popularity** (which
roads see the most use, derived anonymously through
[the sensing boundary](governance.md#the-sensing-boundary-how-activity-becomes-a-place-fact)) and
then let votes layer *loved* on top of *used-a-lot*: two complementary signals, and a list that is
never blank on day one. That sensing layer is not built. The heatmap the demo map shows is seeded
sample data, not measured activity.

## The backlog: nothing is thrown away

The best-of is the **lede**, not the whole library. Every climb, view, and route beyond the
top list still lives in the Commons as a **backlog**, fully queryable for completists who want it
all. The Commons *ranks* data; it never *discards* it.

## Integrity

- **One rider, one vote** per candidate per round; guards against stacking.
- **`X` adapts to the region.** A flat province may have three climbs worth listing; the Alps have
  hundreds. The curated target scales with the density of genuinely good options.
- **Provenance without identity** (Manifesto §VIII): the Commons stores that a vote was cast and when,
  never a public record of who voted for what.
- **You can always see your own.** Your profile lists the route votes you've cast and the places
  you've confirmed (water potability, "still here?" checks), a private view for your eyes only, so
  "did I already back this?" never requires guessing.
