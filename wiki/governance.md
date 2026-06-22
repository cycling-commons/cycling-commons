# Governance

The credibility of a commons rests on one question: *can the people who built it quietly enclose it
later?* If the answer is yes, no one else will build on it and no community will trust it — the exact
failure that befalls a shared resource when its steward later revokes the API and paywalls access. The Cycling Commons
is structured so the answer is **no**.

## Who stewards it

```
BikeCoders            the company — open-source steward (→ independent foundation)
   │
   ├── Cycling Commons   the open data initiative — ODbL, neutral, for everyone
   │
   └── Commercial apps   products built on the Commons, by BikeCoders and anyone else
```

- **BikeCoders** stewards the Commons today and seeds it from aggregate, anonymous activity.
- **The apps built on it** are commercial *citizens* of the Commons — they build on it and contribute
  back, exactly as Thunderforest's OpenCycleMap builds on OpenStreetMap. No app owns the data in any
  way it could later enclose, and the Commons carries no app's branding.
- The Commons is openly licensed and architecturally separate — its own schema, **its own accounts**,
  its own API and bulk exports — from day one.

## The path to independence

The Commons is incubated inside BikeCoders to solve the cold-start problem — early data comes from
aggregate activity on the apps built on it. But incubation is not ownership. The commitment, public from the
start:

1. **Open licence from day one** — ODbL. Already in force.
2. **Architectural separation from day one** — independent data, accounts, API, and bulk exports.
3. **Spin out into an independent foundation** (a Dutch *Stichting*, following established
   open-data foundation precedents) at a defined milestone — real external contributors, or a
   threshold of verified data.

The public pre-commitment plus the open licence is what makes "we will spin it out" believable rather
than a trap. The foundation is formalised when there is a community to govern — not before.

## Ostrom's design principles, applied

The Commons is a deliberate application of Elinor Ostrom's principles for governing common-pool
resources (see [Manifesto](manifesto.md)). Each maps to a concrete part of the system:

| Ostrom principle | In the Cycling Commons |
|------------------|------------------------|
| **1. Clearly defined boundaries** | Non-personal data only; the firm line between Commons and personal data (Manifesto §IV), drawn at the aggregate by [the sensing boundary](#the-sensing-boundary-how-activity-becomes-a-place-fact) |
| **2. Rules fit local conditions** | Curation is **per region**; the curated target `X` scales with local density |
| **3. Collective-choice (those affected make the rules)** | The seasonal **voting rounds** — riders rank their own regions |
| **4. Monitoring** | The **freshness model** — timestamps, reporter counts, confirm/decay |
| **5. Graduated sanctions** | Vote-integrity guards; contributor terms; escalating response to abuse |
| **6. Cheap conflict resolution** | Open, lightweight curator review and dispute flow |
| **7. Recognised right to organise** | The foundation structure; no external owner can override the community |
| **8. Nested enterprises** | Regions nested in countries; rider → curator → steward → foundation |

Together these cover the common failure modes of a shared data resource — spam, gaming, and enclosure.

## The sensing boundary: how activity becomes a place-fact

The Commons is seeded and kept fresh from *aggregate activity* (above) — which raises the obvious
question: how can "where riders go" feed a map that promises a person's movements **never enter the
Commons** (§I, §IV)? The answer is a hard boundary. The Commons never collects or holds movement; a
separate, **consented sensing layer** does, and only an irreversible *place-fact* ever crosses the
line.

**Two domains, one line between them.**

- **The sensing layer** — the apps, or a dedicated sensing service, each with its *own* opt-in consent
  and privacy policy. It collects movement from riders who agree, aggregates it, and discards the raw
  traces. This layer is **not** the Commons.
- **The Commons** — receives only the non-reversible aggregate: an attribute of a *place* ("this road
  carries heavy July traffic"), never a trace, never a per-rider row. Raw movement never enters it and
  is never persisted anywhere as a queryable dataset.

A seasonal heatmap is therefore stored as **a property of a road** — usage intensity by season — not
as a collection of rides. §I and §IV hold verbatim: the Commons is still the map, not the rider. It is
the same shape as §VIII — the Commons learns *that* a place is used and *when*, never *who*.

**What makes an aggregate safe to cross the line.** A place-fact is only a place-fact if it cannot be
turned back into a person. Every aggregate that enters the Commons must satisfy, at minimum:

- **Opt-in consent** at collection — never passive capture.
- **k-anonymity** — nothing is published for a cell or segment below *k* distinct contributors in its
  time bucket. A count of one is a person, not a place.
- **Endpoint trimming** — trip starts and ends are dropped, so home and workplace can't be inferred
  (the failure behind the 2018 Strava heatmap leak).
- **Spatiotemporal coarsening** — snapped to a grid and bucketed by season or week; never raw
  coordinates or timestamps.
- **Differential-privacy noise** on published aggregates wherever the guarantee needs to be formal.
- **Raw traces never persisted** — aggregation is ephemeral. There is no movement dataset to leak,
  subpoena, or quietly repurpose later. The promise is *"we never hold raw traces,"* not *"we
  anonymise them."*

Below the threshold a cell is simply **not published** — sparse is silent. Per-rider history, a "your
rides" view, or any social graph stay in the apps under their own terms and **never** enter the
Commons. This is Ostrom Principle 1 (clear boundaries) made literal: the line is drawn at the
aggregate, not at the trace.

## Independence in practice

The Commons is promoted from the apps built on it — self-promotion is fair, the Commons benefits from
the traffic — but it is **not** branded as any one app's project, ties its name to no single product
family, keeps its **own** contributor accounts (never a shared login with a commercial app), and is
governed for the whole cycling community, not one product's roadmap.
