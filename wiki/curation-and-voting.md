<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Curation & Voting

!!! warning "Built, switched on after launch"
    The map's three view modes and the route path (propose, curator review, *I rode this*
    verification) are live. The season ballot described below is built and stays switched off
    until after launch: until then `/vote` shows the lists without vote buttons and `/best`
    shows a simulated result.

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
| **How** | riders rank a top 5 per list for next season; routes also count *I rode this* | one-tap reports; confirm & decay |
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
- the best **quality rides / routes** (on the same season ballot; a route list can be narrowed to one bike)
- (extensible: best café stops, best gravel, etc.)

So when you arrive somewhere new, you get a clear, opinionated picture of the best there is, instead
of drowning in data.

## The voting rounds

- **One list per region, season and kind.** Climbs, routes, scenic views, history & culture and
  where to sleep each get their own list in every region, for every riding season. Spring 2027 is
  one round, spring 2028 the next. South of the equator the seasons are the other way round.
- **You vote for next season.** The ballot open in autumn fills the winter list. Voting closes
  when winter starts, and the winter list is published that day and shown all winter. While a
  ballot is open no page shows any count, so nobody can read the standings and bet on them.
- **A ranked top 5 per list.** Each rider picks five places in a list, at most one vote per place,
  and puts them in order: the first choice gets 10 points, then 7, 5, 3 and 1.
  Picks and their order can change until the rider submits the ballot. A route vote also says
  which bike it was ridden on, so "best on a handbike" is a list of its own.
- **Submit makes it count.** With all five picks in place the rider submits the ballot. Only a
  submitted ballot counts, and it is final for that season. Picks never submitted count for
  nothing and are deleted when voting closes.
- **Who votes.** A confirmed email address, an account at least 14 days old, and one thing done
  on the map: a confirmed place, a ridden route, or a contribution sent in (one that was turned down
  does not count). With few voters, one fake account would otherwise decide a list.
- **A ranking needs 5 voters.** Below that the list says "No ranking yet" and shows the places
  riders confirmed most.
- **Last year's top 3 count a little less.** In the same season a year later their points count
  x0.75, so the list changes over the years while a clearly loved place can still win.
- **Close calls share a place.** Less than one point apart is too close to call; the place that won
  less often before is listed first.
- **Every season starts empty.** Votes count for one season of one year only. When voting for a
  season closes its result is stored, and it does not change afterwards.
- **No numbers in public.** The lists show places and how close each is to first, never how many
  votes or voters they had.

## Solving the cold start

Empty lists before a voting culture exists would kill the feature.

A list with fewer than five voters does not pretend to have a winner. It shows the places riders
confirmed most (newest first where nothing is confirmed yet), so a region can show a climbs ranking while its places to sleep still say "No ranking yet".
Nothing in a ranking is derived from usage, and there are no curators' picks to fall back on.
Seeding a list from anonymous aggregate popularity, through
[the sensing boundary](governance.md#the-sensing-boundary-how-activity-becomes-a-place-fact),
is a later idea: that sensing layer is not built, and the heatmap the demo map shows is sample
data, not measured activity.

## The backlog: nothing is thrown away

The best-of is the **lede**, not the whole library. Every climb, view, and route beyond the
top list still lives in the Commons as a **backlog**, fully queryable for completists who want it
all. The Commons *ranks* data; it never *discards* it.

## Integrity

- **Five votes per rider per list**, at most one per place; the database itself refuses a sixth
  that arrives at the same moment.
- **`X` adapts to the region.** A flat province may have three climbs worth listing; the Alps have
  hundreds. The curated target scales with the density of genuinely good options.
- **Provenance without identity** (Manifesto §VIII): the Commons stores that a vote was cast and when,
  never a public record of who voted for what.
- **You can always see your own.** Your profile lists the season votes you've cast (with the round
  and, for a route, the bike) and the places you've confirmed, a private view for your eyes only,
  so "did I already back this?" never requires guessing. When you delete your account your votes
  go with it; a season that has already ended keeps its totals, which do not say who voted.
