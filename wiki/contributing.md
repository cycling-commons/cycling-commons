<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Contributing

The Commons grows one small fact at a time. Confirming what is already on the map ("still here?",
"the water is drinkable") is a single tap, and so is backing a route you have ridden. Adding
something new (a water tap, a gradient, a "closed" flag) takes a short form, never a long one. Either
way the map grows in small pieces, the way OpenStreetMap was built.

## Ways to contribute

| Method | What it is |
|--------|-----------|
| **[tap]** | a one-tap report in the moment: "water here", "road closed", "great view" |
| **[edit]** | a structured attribute edit: climb metrics, a café's opening hours |
| **[vote]** | routes: once a curator accepts a proposed route and riders confirm they rode it, a seasonal recommend-vote on the map drawer ranks it. The ballot for a region's best climbs, stays, views, and heritage is design (see [Curation & voting](curation-and-voting.md)) |
| **[auto]** | design: facts derived anonymously from aggregate signals, nothing personal. Nothing of the kind is collected today; see [the sensing boundary](governance.md#the-sensing-boundary-how-activity-becomes-a-place-fact) |

A **[tap]** does not have to happen at a screen. [Scout](scout.md) records one
on your bike computer as you ride past, writes it into your own ride file, and
lets you decide at home which of them to send. The tags that arrive that way go
through exactly the review below: the point of collecting a fact differently is
not to judge it differently.

Every field is **optional and additive**. Required fields kill contribution; optional fields let the
Commons grow one tap at a time. See the full taxonomy in the [Data catalog](data-catalog.md).

## Keeping perishable data honest (the freshness model)

Hazards and closures rot if they never expire. Dynamic data therefore carries a lifecycle:

- **Timestamp + reporter count** on every report: when, and how many independent riders.
- **Confidence from confirmations**: one report is *unconfirmed*; several independent ones are
  *confirmed*. The state is shown, never hidden.
- **Ageing, then stale.** A confirmation reads as *fresh*, then *ageing*, then *stale* after one
  site-wide window (a curator-adjustable number of months, the same for every type). A stale item
  stays on the map with its state shown, waiting for the next rider to confirm it.
- **Closures expire.** A road closure is the one type with a lifetime of its own: the reporter says
  how long it is likely to last (today, days, weeks, months, or unknown, and unknown is bounded, not
  forever). Past that window the closure comes off the map, never deleted, and the expiry is
  written to the item's history.
- **Auto-clear from aggregate use** is design, not built. The idea: riders who keep passing through a
  spot flagged "closed" are evidence it reopened, and [auto] data downgrades the stale [tap] report.
  No movement data is collected today, so nothing downgrades a report except a rider.
- **One-tap confirm.** A place's drawer asks *Is this still here?* with a single *Still here* answer
  that feeds confidence. Places drawn from OpenStreetMap carry *Out of order*, *Closed* and *Not there
  anymore* instead; those go to a curator for review rather than changing the map directly.

## Giving back to OpenStreetMap

The Commons **data** is ODbL, the same licence OSM uses, so durable infrastructure facts can flow
back upstream legally by construction. Everything else in the project travels under its own terms,
and the whole split sits in one place below, because only the first row can move when a fact
moves:

| What | Licence |
|------|---------|
| **Data**: the catalog itself, places, climbs, routes, attributes | ODbL 1.0 |
| **Media**: photos and video | CC BY-SA 4.0 |
| **Code and interface**: the application, the pipeline, the tooling | AGPL-3.0-only |
| **UI translations** submitted through the site | AGPL-3.0-only, the same as the interface they are part of |
| **Wiki prose**, this page included | CC BY-SA 4.0 |
| **The name, the logo and the wordmark** | reserved, not open: see [TRADEMARK.md](https://github.com/cycling-commons/cycling-commons/blob/main/TRADEMARK.md) |

Photographs do not belong in OSM anyway, and the code licence has no bearing on the data in either
direction. All six are set out on the [licensing page](https://cyclingcommons.org/licenses).

The intention is that when you add a fact OSM is missing (a water tap, a repair station, a wrong
surface) the Commons offers to carry it over as **your own** OSM edit: attributed to you, in your
words, never a bulk firehose from a project account (that gets reverted and resented).

**It is not built, and the shape is not settled.** Which of our fields even map is a real question.
`surface` translates, our `traffic` field has no OSM equivalent, and a rider's note maps to nothing,
and a give-back that posts our vocabulary into OSM tags would be worse than none. So the promise here
is the direction, not a button that exists today.

What flows back to OSM: durable facts (water, repair stations, bike shops, surface, cycleways,
barriers). What stays in the Commons: the game, subjective ratings, ephemeral hazards, and anything
personal. OSM wants lasting facts, not your weather or your leaderboard.

## How edits are reviewed

The Commons is contribution-first but not unguarded. Every submission, whether a new place, an edit,
a Scout tag or a proposed route, enters the same review queue, where curators (a community role, not
an owner class) accept or decline it for the regions they cover. The lists a region starts with are
system defaults, held by no curator account. Conflicts are resolved openly and cheaply. This is
Ostrom's monitoring-and-conflict-resolution in practice; see [Governance](governance.md).

## Contributing to *this repo*

Documentation and the site live in this repository. Propose changes by pull request; the docs are
themselves a commons. Substantive design decisions belong in the wiki so there is a single source of
truth; please don't fork a topic into a second document.

Every commit needs a **Developer Certificate of Origin** sign-off. `git commit -s` adds the
`Signed-off-by` line, and a CI check turns a pull request away without one. Signing off says that you
wrote the change or otherwise have the right to submit it, and that you are content for it to go out
under the project's licence, `AGPL-3.0-only` for code and CC BY-SA 4.0 for this wiki. It is not a
copyright assignment. You keep the copyright in what you wrote and grant a non-exclusive licence to
use it; nobody here is asked to sign their work over to anyone.
