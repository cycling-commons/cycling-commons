<!-- SPDX-License-Identifier: CC-BY-SA-4.0 -->

# Governance

The credibility of a commons rests on one question: *can the people who built it quietly enclose it
later?* If yes, nobody will trust it and nobody will build on it. This is how shared resources die:
the steward who runs the API revokes it one day and puts it behind a paywall. The Cycling Commons is
built so the answer is **no**: what has been released cannot be taken private at all, code and data
alike. The only enclosure still open to a steward is stopping the flow of new data, and that cannot
be done quietly.

Two licences do the first part of the work, and this page is straight about where each one stops:

- ODbL covers the *data*, and only data already published. It cannot force anyone to keep
  publishing. A bad-faith owner could close the API and the bulk exports together; every copy
  already released would stay free, but new data would stop arriving.
- The platform code is free software under the
  [GNU AGPL v3](https://github.com/cycling-commons/cycling-commons/blob/main/LICENSE)
  (`AGPL-3.0-only`). Anyone may take it, run it, change it, and run a rival service on it. What the
  AGPL asks in return is exactly the thing that matters here: whoever distributes the software, or
  runs a modified version as a network service other people use, has to offer those people the
  Corresponding Source. Competition is allowed. Going private is not.

That closes the oldest hole in a project like this, the steward who quietly takes the software in
house. It does not close every hole. Nobody can put this code behind a wall any more, but a steward
can still stop *feeding* the pool, and no licence ever written compels someone to keep working. So
the rest of the protection is practical. Each measure makes enclosure slower, costlier and more
visible:

- **Published data stays free forever.** ODbL is irrevocable for every copy already released.
- **Published code stays free forever, and so does everything built on it.** The AGPL grant on a
  released version cannot be withdrawn, and nobody downstream may add a restriction on top of it. If
  a future owner relicensed, it would bind future versions only: the last AGPL release would still
  be there to fork, and every hosted derivative of it would still owe its users the source.
- **Exports on a schedule, not on request.** Anyone can take a complete snapshot at any time.
  *(Designed, not live yet; this is the first gap to close.)*
- **Mirrors.** The apps built on the Commons depend on it, so each has a business reason to copy
  every export, the way OpenStreetMap's planet files are mirrored by many independent parties. When
  many parties hold the data, closing the source freezes growth; it cannot kill the commons.
- **A fork needs nobody's permission.** The code, the schema and the exports together are enough to
  stand the whole thing up somewhere else. The one thing a fork may not take is the name, the logo
  and the wordmark: those are reserved, under
  [TRADEMARK.md](https://github.com/cycling-commons/cycling-commons/blob/main/TRADEMARK.md), by the
  clause AGPL v3 section 7(e) sets aside for it. A fork picks its own name. Everything it needs to
  *run* is already in the grant.

The honest answer to the opening question is therefore: neither the code nor the data can be taken
private, and past that, enclosure can be made slow, expensive and public. Never impossible. The word
that matters is *quietly*.

## Who stewards it

<!-- CODE-ILLUSTRATIVE org-structure diagram, hand-written -->
```
BikeCoders            the company: open-data steward (→ independent foundation)
   │
   ├── Cycling Commons   the open data initiative: ODbL, neutral, for everyone
   │
   └── Commercial apps   products built on the Commons, by BikeCoders and anyone else
```

- **BikeCoders** stewards the Commons today. The design also has it seed the Commons from aggregate,
  anonymous activity; that sensing layer is not built (see
  [the sensing boundary](#the-sensing-boundary-how-activity-becomes-a-place-fact)).
- **The apps built on it** are commercial *citizens* of the Commons: they build on it and contribute
  back, exactly as Thunderforest's OpenCycleMap builds on OpenStreetMap. No app owns the data in any
  way it could later enclose, and the Commons carries no app's branding.
- The Commons is openly licensed and architecturally separate from day one: its own schema, **its own
  accounts**, its own read API (live in an early, two-endpoint form). Bulk exports are part of the
  design, not yet live.

## The path to independence

The Commons is incubated inside BikeCoders to solve the cold-start problem. Today its data comes from
open sources (OpenStreetMap and the other open datasets the site lists) plus what riders add; the
design adds aggregate activity from the apps built on it as a further seed. But incubation is not
ownership. The commitment, public from the start:

1. **Open licences from day one**: ODbL for the data, AGPL-3.0 for the code. In force. The code
   was source-available (PolyForm Shield) until September 2026, and the public history says so
   plainly.
2. **Architectural separation from day one**: independent data, accounts and API. Bulk exports are
   part of the same design.
3. **Spin out into an independent foundation** (following established open-data foundation
   precedents) at a defined, checkable milestone, whichever comes first: **50 external contributors
   with a merged contribution**, or **25,000 rider-verified data points**. A milestone that cannot be
   checked ("when there is a real community") is not a commitment, so the trigger is a number, not a
   feeling.
4. **The handover includes the code, and the licence has already done that part.** A data-only exit
   would not be a real exit: a community that inherits a dataset it cannot run has inherited very
   little. The perpetual, royalty-free right to run, modify and redistribute the serving stack is
   not a promise waiting to be kept: `AGPL-3.0-only` grants it today, to the foundation and to
   everyone else on the same terms. What the spin-out still has to move by
   hand is everything the AGPL does not reach: the domain, the name, the logo and the wordmark (see
   [TRADEMARK.md](https://github.com/cycling-commons/cycling-commons/blob/main/TRADEMARK.md), which
   already names the stichting as where the marks are going), the accounts, and operational
   custody of the running service.

The public pre-commitment plus the open licence is what makes "we will spin it out" credible rather
than hollow. The foundation is formalised when there is a community to govern, not before. Of
everything on this page, shipping the bulk exports would raise credibility most: they are what make
mirroring possible, and mirrors are what make enclosure expensive. They are not live yet.

## Ostrom's design principles, applied

The Commons is a deliberate application of Elinor Ostrom's principles for governing common-pool
resources (see [Manifesto](manifesto.md)), as extended to shared data by the knowledge-commons
literature, chiefly Hess & Ostrom, *Understanding Knowledge as a Commons* (2007). Each maps to a
concrete part of the system, with a status column so the gap between commitment and achievement stays
visible:

| Ostrom principle | In the Cycling Commons | Status |
|------------------|------------------------|--------|
| **1. Clearly defined boundaries** | Non-personal data only; the firm line between Commons and personal data (Manifesto §I), drawn at the aggregate by [the sensing boundary](#the-sensing-boundary-how-activity-becomes-a-place-fact) | *shipped* |
| **2. Rules fit local conditions** | Curation is **per region**; the curated target `X` scales with local density | *shipped* |
| **3. Collective-choice (those affected make the rules)** | The seasonal **voting rounds**: riders rank their own regions. *(Rounds are design, planned to open after launch; the shipped half is routes, which riders verify by riding and rank by seasonal recommend-vote. See [Curation & Voting](curation-and-voting.md))* | *partly shipped* |
| **4. Monitoring** | The **freshness model**: timestamps, reporter counts, confirm/decay | *shipped* |
| **5. Graduated sanctions** | Escalating consequences for rule-breaking (warn → restrict → remove), sized to severity and repeat offence rather than a first-strike ban. Aimed at abuse: spam, vote-rigging, vandalism. [Pre-committed below](#principles-5-and-6-pre-committed) | *aspirational* (ladder written, untested) |
| **6. Cheap conflict resolution** | A fast, low-cost way to settle *good-faith* disagreements (a contested edit, a curation call) without escalation or cost. Adjudication between members, not punishment. [Pre-committed below](#principles-5-and-6-pre-committed) | *aspirational* (path defined, unused) |
| **7. Recognised right to organise** | The community's right to self-govern is recognised from outside: the foundation gives it legal standing independent of BikeCoders, and the exit right is real on both halves at once, ODbL on the data and AGPL-3.0-only on the code, so a community that disagreed with the steward could leave with a working system and no upstream could deny it *(what remains is the foundation itself: see [The path to independence](#the-path-to-independence))* | *partly shipped* (exit right in force on data and code, foundation pending) |
| **8. Nested enterprises** | Self-governing regions nested under a coordinating core: rider → regional curators → core stewardship → foundation (see [Regional governance](#regional-governance-subsidiarity-not-hierarchy)) | *design* (the structure regions grow into) |

Two caveats keep this honest. First, Ostrom derived her principles from institutions that had *already
endured*; used here they run the other way, as a compass for building rather than a report of
achievement: the status column is the ledger of the distance still to travel. Second, principle 1 is
bent from its original meaning: Ostrom meant the boundaries of the *user community and the resource*,
while the row above leans on the privacy boundary. Both matter, and for a data commons the question
"what may enter the pool at all" is simply the one it cannot get wrong. One attribution, too: the bulk
of today's data descends from OpenStreetMap, itself a working commons of exactly this lineage. The
Commons inherits that layer rather than governing it; the principles above apply to the curation and
contributions added on top.

Together these cover the common failure modes of a shared data resource: spam, gaming, and enclosure.

## Principles 5 and 6, pre-committed

Principles 5 and 6 are the two that most distinguish a governed commons from a maintainer's project,
and the easiest to get wrong, because rules improvised mid-dispute carry the biases of that dispute.
So the mechanics are written down *now*, while there is no conflict to bias them. The community can
amend them later through the ordinary collective-choice process (#3); until it exists, these are the
defaults the steward commits to.

**The escalation ladder (principle 5)**, sized to severity and repeat offence, never a first-strike
ban:

1. **Warn**, for a first minor violation (spammy edit, an aggressive comment): a private note pointing at
   the rule. No public shaming.
2. **Restrict**, for a repeat or serious offence: temporary loss of the capability abused (recommend-votes,
   curation rights), never of access to the open data itself.
3. **Remove**, for persistent abuse (vote-rigging, vandalism at scale): account removal, contributions
   redacted where feasible, OSM-style.

Two rules bind the ladder itself: **no retroactive punishment** (conduct is judged by the rules in
force when it happened), and **every appeal is heard**: the appeal is the first step of the
adjudication path below, not a favour.

**The adjudication path (principle 6)**. Good-faith disagreements (a contested edit, a curation call)
settle at the lowest competent level, in order:

1. **Between the members involved**: reversion plus a short comment. Most disputes should die here,
   for free.
2. **The regional curator(s)**: if the editors still disagree, the region's curator makes the call,
   and it is recorded, one line saying why, so the next dispute starts from precedent instead of zero.
3. **The core team, as backstop only**: for disputes a region cannot resolve, or that span regions.
   The core team settles *process*; it does not overrule a region's *judgment* (see
   [Regional governance](#regional-governance-subsidiarity-not-hierarchy)).

Every ruling lands in a small public log. That log is what keeps adjudication cheap and honest: it
makes precedent visible, lets regions learn from each other's calls, and is the raw material the
future foundation will need. And it is the answer to "who monitors the monitors", available before the
first monitor is needed: the appeal, the log, and eventually the foundation's community.

## Regional governance: subsidiarity, not hierarchy

The aim is for each region to govern its own map (its curators, its seasonal rounds, its own sense of
what the region's best climbs, finest views, and top rides are) nested under a core that keeps the
regions interoperable. That is principle #8 (nested enterprises) made real: governance, not merely a
data hierarchy.

The line to hold is **subsidiarity**: decisions sit at the lowest competent level, and the higher level
only handles what the lower one can't. "Supervision to keep regions aligned" can quietly collapse the
nesting back into hierarchy, and if it does, the Commons loses the very property that makes it a
commons. So the division of labour we aim for:

- **Core team's job = coordination, not taste.** Shared schema, data standards, anti-abuse /
  vote-integrity, cross-region *consistency of process*, and being the backstop for disputes a region
  can't resolve. That is legitimate "supervision."
- **Region's job = the actual map.** What counts as this region's best climb, finest view, or top ride
  stays local, and the core team does **not** override it. The moment "alignment" means the core team
  can overrule a region's *judgment*, it is not a nested commons any more; it is a company with regional
  moderators.

"Keep aligned" therefore means aligned **standards**, not aligned **opinions**. Most regions won't have
a curator community at the start; this is the structure the Commons grows into as local communities
form.

## The sensing boundary: how activity becomes a place-fact

!!! note "Design, not built"
    Nothing in this section is running. The Commons collects no movement data today, no app feeds
    it aggregate activity, and the heatmap on the demo map is seeded sample data. This section is
    the rule any such layer would have to meet before it could exist.

The design has the Commons seeded and kept fresh from *aggregate activity* (above), which raises the
obvious question: how can "where riders go" feed a map that promises a person's movements **never
enter the Commons** (§I)? The answer is a hard boundary. The Commons never collects or holds
movement; a separate, **consented sensing layer** would, and only an irreversible *place-fact* ever
crosses the line.

**Two domains, one line between them.**

- **The sensing layer**: the apps, or a dedicated sensing service, each with its *own* opt-in consent
  and privacy policy. It collects movement from riders who agree, aggregates it, and discards the raw
  traces. This layer is **not** the Commons.
- **The Commons** receives only the non-reversible aggregate: an attribute of a *place* ("this road
  carries heavy July traffic"), never a trace, never a per-rider row. Raw movement never enters it and
  is never persisted anywhere as a queryable dataset.

A seasonal heatmap is therefore stored as **a property of a road** (usage intensity by season), not
as a collection of rides. §I holds verbatim: the Commons is still the map, not the rider. It is
the same shape as §VIII: the Commons learns *that* a place is used and *when*, never *who*.

**What makes an aggregate safe to cross the line.** A place-fact is only a place-fact if it cannot be
turned back into a person. Every aggregate that enters the Commons must satisfy, at minimum:

- **Opt-in consent** at collection, never passive capture.
- **k-anonymity**: nothing is published for a cell or segment below *k* distinct contributors in its
  time bucket. A count of one is a person, not a place.
- **Endpoint trimming**: trip starts and ends are dropped, so home and workplace can't be inferred
  (the failure behind the 2018 Strava heatmap leak).
- **Spatiotemporal coarsening**: snapped to a grid and bucketed by season or week; never raw
  coordinates or timestamps.
- **Differential-privacy noise** on published aggregates wherever the guarantee needs to be formal.
- **Raw traces never persisted**: aggregation is ephemeral. There is no movement dataset to leak,
  subpoena, or quietly repurpose later. The promise is *"we never hold raw traces,"* not *"we
  anonymise them."*

Below the threshold a cell is simply **not published**: sparse is silent. Per-rider history, a "your
rides" view, or any social graph stay in the apps under their own terms and **never** enter the
Commons. This is Ostrom Principle 1 (clear boundaries) made literal: the line is drawn at the
aggregate, not at the trace.

## Independence in practice

The Commons is promoted from the apps built on it (self-promotion is fair, the Commons benefits from
the traffic) but it is **not** branded as any one app's project, ties its name to no single product
family, keeps its **own** contributor accounts (never a shared login with a commercial app), and is
governed for the whole cycling community, not one product's roadmap.
