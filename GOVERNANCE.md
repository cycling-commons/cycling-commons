# Governance

Cycling Commons is an open commons of non-personal cycling data. The one question that
decides whether anyone trusts a commons is: *can the people who built it quietly enclose it
later?* This project is structured so the answer is **no**, and this file records the
commitments that make that answer believable.

This is the summary. The full governance model lives in
**[`wiki/governance.md`](wiki/governance.md)** (published at `wiki.cyclingcommons.org`):
Ostrom's design principles applied, the sensing boundary that keeps personal data out, and
independence in practice.

## Who stewards it

```
BikeCoders            the company: open-data steward (→ independent stichting)
   │
   ├── Cycling Commons   the open data initiative: ODbL, neutral, for everyone
   │
   └── Commercial apps   products built on the Commons, by BikeCoders and anyone else
```

**BikeCoders** ([bikecoders.life](https://bikecoders.life)) stewards the Commons today.
Commercial apps are *citizens* of the Commons: they build on it and contribute back, exactly
as OpenCycleMap builds on OpenStreetMap. No app owns the data in any way it could later enclose,
and the Commons carries no app's branding.

## The commitments

1. **Free software and open data from day one:** data under
   [ODbL](licenses/COMMONS-DATA-LICENSE.md), media under
   [CC BY-SA 4.0](licenses/COMMONS-MEDIA-LICENSE.md), and the platform code under the
   [GNU AGPL v3](LICENSE) (`AGPL-3.0-only`). Anyone may run the code, study it, change it and
   share it, for any purpose, including a commercial one. The one thing the licence asks back is
   the source: a changed version has to carry the same freedoms, and running it as a service is
   not a way around that, because section 13 says the people using it over a network must be
   offered the Corresponding Source of the version they are using. So the code cannot be closed
   later, not by us and not by anyone who takes it. The name, the logo and the wordmark stay with
   the project, so a fork rides under its own name: see [`TRADEMARK.md`](TRADEMARK.md). Already
   in force.
2. **Architectural separation from day one:** the Commons keeps its own schema, accounts, API,
   and bulk exports, independent of any app built on it.
3. **Spin-out to an independent foundation:** a Dutch stichting, formalised at a defined
   milestone (real external contributors, or a threshold of verified data) and expected within
   about a year. It takes over stewardship, the copyright BikeCoders holds, and the designation
   of the proxy under section 14 of the AGPL. The public pre-commitment plus the open licence is
   what makes "we will spin it out" credible rather than a trap.

## Scope of this project

The repository name is the full project name, **Cycling Commons**, not the name of any single
layer, because this repo carries the project-wide licensing and governance for the whole commons.
The map layer ("Atlas") is the first layer; future non-geo layers join it under the same umbrella
and the same commitments above.

## Contributing & conduct

- How to take part: [`wiki/contributing.md`](wiki/contributing.md)
- The principles behind it all: [`wiki/manifesto.md`](wiki/manifesto.md), grounded in Ostrom's
  *Governing the Commons*
- Contributor licensing terms (inbound grant, warranty): [`licenses/COMMONS-TERMS-CLAUSE.md`](licenses/COMMONS-TERMS-CLAUSE.md)
- Sign your commits off with `git commit -s`. Contributions come in under the Developer
  Certificate of Origin: you keep your own copyright and license the work in, and nothing is
  assigned to BikeCoders or to the stichting that follows it.
