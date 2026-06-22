# Curation & Voting

This is the heart of what makes the Cycling Commons different: **curation over completeness.** The
Commons doesn't try to list *every* climb that exists — it surfaces **the ones worth your weekend**,
as judged by the riders who know the area. For things that are a matter of taste, the best-of is the
point; an exhaustive dump helps no one plan a ride.

## Two kinds of data, two strategies

The single organising principle of the Commons:

| | Subjective / experiential | Objective / utility |
|---|---|---|
| **Examples** | best climbs, bike-friendly stays, finest views, history & culture, top quality rides | road surface, water points, toilets, repair stations, hazards, bike shops |
| **Goal** | **curated** — the best, ranked | **complete** — as exhaustive as possible |
| **How** | riders vote; refreshed in rounds | one-tap reports; confirm & decay |
| **The value is** | the *ranking* | the *coverage* |

Voting makes no sense for a water tap — it's either there or it isn't, and you want them all. Ranking
is the whole point for a climb — you want the best ten, not all two hundred.

## Regions

Curation happens **per region**. A region is standard public geography — a country, province, or
municipality — **not** a game shape from any app built on top. Keeping the Commons' regions neutral
and independent is deliberate: an app's game layer may *map onto* Commons regions, never the reverse.
That keeps the Commons usable by anyone, decoupled from any one product.

For each region the Commons surfaces a curated **best-of**:

- the best **climbs**
- the best **bike-friendly stays**
- the most scenic **views**
- the best **history & culture** to ride past
- the best **quality rides / routes**
- (extensible: best café stops, best gravel, etc.)

So when you arrive somewhere new, you get a clear, opinionated picture of the best there is — instead
of drowning in data.

## The voting rounds

- **Riders vote** on the candidates in each region.
- **A fresh round opens each season.** Four rounds a year give the Commons a rhythm that follows the
  riding — a reason to come back as the season turns, and room for new entries to rise.
- **Rounds re-rank, they don't reset.** The standing list carries forward and votes shift it. A
  legendary climb is never wiped out by one low-turnout season; it just has to keep earning its place.

## Solving the cold start: seed from popularity, refine by vote

Empty lists before a voting culture exists would kill the feature. So the Commons seeds the initial
ranking from **aggregate popularity** — which roads see the most use, derived anonymously from aggregate signals. Votes then layer *loved* on top of *used-a-lot*. Two complementary
signals, and the list is never blank on day one.

## The backlog: nothing is thrown away

The curated best-of is the **lede**, not the whole library. Every climb, view, and route beyond the
top list still lives in the Commons as a **backlog** — fully queryable for completists who want it
all. The Commons *ranks* data; it never *discards* it.

## Integrity

- **One rider, one vote** per candidate per round; guards against stacking.
- **`X` adapts to the region.** A flat province may have three climbs worth listing; the Alps have
  hundreds. The curated target scales with the density of genuinely good options.
- **Provenance without identity** (Manifesto §VIII): the Commons stores that a vote was cast and when,
  never a public record of who voted for what.
