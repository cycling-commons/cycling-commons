# Contributing

The Commons grows one small fact at a time. Nobody fills in a form — a rider adds a water tap, a
gradient, a "closed" flag, and the map gets a little better. Richness accretes from many tiny
contributions, the way OpenStreetMap was built.

## Ways to contribute

| Method | What it is |
|--------|-----------|
| **[tap]** | a one-tap report in the moment — "water here", "road closed", "great view" |
| **[edit]** | a structured attribute edit — climb metrics, a café's opening hours |
| **[vote]** | ranking a region's best climbs, stays, views, heritage, and routes (see [Curation & voting](curation-and-voting.md)) |
| **[auto]** | derived automatically and anonymously from aggregate signals — nothing personal |

Every field is **optional and additive**. Required fields kill contribution; optional fields let the
Commons grow one tap at a time. See the full taxonomy in the [Data catalog](data-catalog.md).

## Keeping perishable data honest (the freshness model)

Hazards and closures rot if they never expire. Dynamic data therefore carries a lifecycle:

- **Timestamp + reporter count** on every report — when, and how many independent riders.
- **Confidence from confirmations** — one report is *unconfirmed*; several independent ones are
  *confirmed*. The state is shown, never hidden.
- **Decay by type** — a pothole persists for months, a "closed for an event" expires in days. After
  expiry an item is hidden (not deleted) pending re-confirmation.
- **Auto-clear from aggregate use** — if riders keep passing through a spot flagged "closed", that's evidence
  it reopened; [auto] data downgrades a stale [tap] report.
- **One-tap confirm / dispute** — passing a flagged spot, a rider gets a light "still there? yes / gone"
  prompt that feeds confidence.

## Giving back to OpenStreetMap

Because the Commons is ODbL — the same licence as OSM — durable infrastructure facts can flow back
upstream. When you add an OSM-appropriate fact OSM is missing (a water tap, a repair station, a wrong
surface), the Commons can offer a one-tap *"add to OpenStreetMap too?"* — posted as **your own** OSM
edit. Curated, attributed, human-reviewed; never a bulk firehose (that gets reverted and resented).

What flows back to OSM: durable facts (water, repair stations, bike shops, surface, cycleways,
barriers). What stays in the Commons: the game, subjective ratings, ephemeral hazards, and anything
personal. OSM wants lasting facts, not your weather or your leaderboard.

## How edits are reviewed

The Commons is contribution-first but not unguarded. Curators (a community role, not an owner class)
review flagged edits and seed initial regional lists; conflicts are resolved openly and cheaply. This
is Ostrom's monitoring-and-conflict-resolution in practice — see [Governance](governance.md).

## Contributing to *this repo*

Documentation and the site live in this repository. Propose changes by pull request — the docs are
themselves a commons. Substantive design decisions belong in the wiki so there is a single source of
truth; please don't fork a topic into a second document.
