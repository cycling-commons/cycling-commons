<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Specs — how this directory works

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

This directory holds two kinds of documents. The distinction is load-bearing:
tooling, reviews, and tests treat them differently.

## The two tiers

| Tier | Filename | Role | Lifetime |
|------|----------|------|----------|
| **Canonical** | date-less (`route-domain.md`, `edit-items/…`) | The official contract set: developer reference, and the base TDD builds against. Versionless, kept current. | Permanent |
| **Working** | dated (`YYYY-MM-DD-<topic>-design.md`) | Design docs for one round of work: options, rationale, execution notes. | Sweepable after consolidation |

**Nothing binding may live only in a dated file once its design has shipped.**

## The canonical set

- `osm-data-architecture.md` — the OSM relationship: data categories, tag
  catalogue, materialize-on-edit lifecycle, legal posture, API policy.
- `edit-items/` — per-type contribution/edit contracts (A–L) and the shared
  contribution contract (`edit-items/README.md`).
- `catalog-data-model.md` — the running catalog schema and data contracts.
- `moderation-and-contribution.md` — the contribution-to-decision lifecycle.
- `route-domain.md` — the K route domain in depth.
- `map-and-search.md` — the map/search UX contract
  (delegated to by osm-data-architecture.md §8).
- `account-and-auth.md` — identity, roles, 2FA, admin desk, profiles.
- `security-architecture.md` — CSP, sanitizer, CSRF, rate-limiter inventory.
- `coverage-provider.md` — the buildable coverage-provider contract.
- `dev-environment.md` — dev stack, platform decisions, conventions.

## Rules

1. **Consolidate-on-execute.** When a dated design's execution completes, the
   same session moves its surviving binding decisions into the owning canonical
   doc and stamps the dated file (`> Consolidated into <doc>.md (<date>).`).
   Only stamped files (or files stamped `retired, nothing survives`) may be
   swept.
2. **One owner per fact.** Canonical docs cross-link instead of restating.
   The wiki owns the public "why" (principles, taxonomy, governance); specs own
   the "what/how" (schemas, state machines, endpoints, invariants).
3. **TDD cites canonical docs only.** Test names and comments reference
   `<canonical>.md §heading`, never a dated filename. Numeric thresholds are
   stated as config keys in the spec; tests assert against config, not
   literals.
4. **Section references are doc-qualified** everywhere (specs, code comments,
   commit messages): `osm-data-architecture.md §5`, never a bare `§5`.
5. **Pending contracts go canonical immediately**, marked
   *“specified, pending implementation”* — the canonical set is what TDD
   builds against, including approved-but-unbuilt contracts.
6. **Ops flags stay out.** Pending prod migrations, token setup, go-live gates
   live in `docs/TODO.md`; design decisions never do.
7. **Sweep guard.** Before deleting a dated spec, grep the canonical set and
   `web/tests` for its filename; repoint anything that matches first.
