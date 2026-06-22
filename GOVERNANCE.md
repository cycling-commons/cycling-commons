# Governance

Cycling Commons is an open commons of non-personal cycling data. The one question that
decides whether anyone trusts a commons is: *can the people who built it quietly enclose it
later?* This project is structured so the answer is **no** — and this file records the
commitments that make that answer believable.

This is the summary. The full governance model — Ostrom's design principles applied, the
sensing boundary that keeps personal data out, and independence in practice — lives in
**[`wiki/governance.md`](wiki/governance.md)** (published at `wiki.cyclingcommons.org`).

## Who stewards it

```
BikeCoders            the company — open-source steward (→ independent foundation)
   │
   ├── Cycling Commons   the open data initiative — ODbL, neutral, for everyone
   │
   └── Commercial apps   products built on the Commons, by BikeCoders and anyone else
```

**BikeCoders** ([bikecoders.life](https://bikecoders.life)) stewards the Commons today.
Commercial apps are *citizens* of the Commons — they build on it and contribute back, exactly
as OpenCycleMap builds on OpenStreetMap. No app owns the data in any way it could later enclose,
and the Commons carries no app's branding.

## The commitments

1. **Open data from day one** — data under [ODbL](licenses/COMMONS-DATA-LICENSE.md), media
   under [CC BY-SA 4.0](licenses/COMMONS-MEDIA-LICENSE.md); the platform code is
   [source-available](LICENSE) under PolyForm Shield (use it for anything except a competing
   product). Already in force.
2. **Architectural separation from day one** — the Commons keeps its own schema, accounts, API,
   and bulk exports, independent of any app built on it.
3. **Spin-out to an independent foundation** — a Dutch *Stichting*, formalised at a defined
   milestone (real external contributors, or a threshold of verified data). The public
   pre-commitment plus the open licence is what makes "we will spin it out" credible rather than
   a trap.

## Scope of this project

The repository name is the full project name — **Cycling Commons** — not the name of any single
layer, because this repo carries the project-wide licensing and governance for the whole commons.
The map layer ("Atlas") is the first layer; future non-geo layers join it under the same umbrella
and the same commitments above.

## Contributing & conduct

- How to take part: [`wiki/contributing.md`](wiki/contributing.md)
- The principles behind it all: [`wiki/manifesto.md`](wiki/manifesto.md), grounded in Ostrom's
  *Governing the Commons*
- Contributor licensing terms (inbound grant, warranty): [`licenses/COMMONS-TERMS-CLAUSE.md`](licenses/COMMONS-TERMS-CLAUSE.md)
