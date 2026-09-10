<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Specs — how this directory works

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

This directory holds two kinds of documents. The distinction is load-bearing:
tooling, reviews, and tests treat them differently. Only the canonical tier is
committed — working docs stay local.

## The two tiers

| Tier | Filename | Role | Lifetime |
|------|----------|------|----------|
| **Canonical** | date-less (`route-domain.md`, `edit-items/…`) | The official contract set: developer reference, and the base TDD builds against. Versionless, kept current. | Permanent, committed |
| **Working** | dated (`Dated/YYYY-MM-DD-<topic>-design.md`) | Design docs for one round of work: options, rationale, execution notes. | Local-only (gitignored); swept into `Dated/` after consolidation |

**Nothing binding may live only in a dated file once its design has shipped.**

## The canonical set

- `osm-data-architecture.md` — the OSM relationship: data categories, tag
  catalogue, materialize-on-edit lifecycle, legal posture, API policy.
- `api-strategy.md` — the business/access posture of the public API: free bulk
  export vs. metered API, pricing tiers, why commercial consumers are safe.
- `public-api.md` — the public API endpoint contract and integration guide:
  data served, vector-tile + REST transports, auth/metering, versioning, a
  worked consumer example (the endpoint contract osm-data-architecture.md §7 and
  api-strategy.md defer to).
- `edit-items/` — per-type contribution/edit contracts (letters A–M practical, N–Z experiential) and the shared
  contribution contract (`edit-items/README.md`).
- `catalog-data-model.md` — the running catalog schema and data contracts.
- `moderation-and-contribution.md` — the contribution-to-decision lifecycle.
- `route-domain.md` — the R route domain in depth.
- `climb-elevation.md` — how a climb's length, gain, gradients and profile are
  MEASURED (elevation source chain, binning, the steepest-ramp window) and how
  the profile chart is drawn. Nobody types a gradient.
- `map-and-search.md` — the map/search UX contract
  (delegated to by osm-data-architecture.md §8).
- `account-and-auth.md` — identity, roles, 2FA, admin desk, profiles.
- `security-architecture.md` — CSP, sanitizer, CSRF, rate-limiter inventory.
- `public-api-personal-data-boundary.md` — how the public API is kept away
  from account data: Postgres role/grants, dedicated connection, deptrac
  fence, contract tests.
- `coverage-provider.md` — the buildable coverage-provider contract.
- `data-source-register.md` — every candidate upstream source per catalog
  letter, with its licence, an Ingest/Reference/Ask/No verdict and the evidence
  behind it. The supply side; `wiki/landscape.md` is the product side.
- `credits-page.md` (**implemented**): what `/credits` promises, its three
  tiers of credit, the `data-pkg` marker contract, and the gate that stops the
  page drifting away from the dependencies it names.
- `roadmap-and-changelog.md` (**implemented**): /roadmap, /changelog and the
  Atom feed from one list, why the version needs a git tag and nothing else, and
  the release-notes opt-in with its signed one-click unsubscribe.
- `form-errors.md` (**implemented**): how a form tells a rider the submission
  failed. The form-level errors every template used to drop, the shared partial
  and the scan that stops a new form dropping them again, every validation
  message as a catalogue key rather than an English sentence, and the measured
  contrast of the three error surfaces.
- `site-directory.md` (**implemented**): `/pages` drawn as a touring map with a
  list toggle; one data structure feeds both drawings; the road vocabulary.
- `privacy-notice.md` (**implemented**): where every claim on `/privacy` is
  true, the two "who sees my data" tables and the CSP list that keeps the
  second one complete, the cookie table, and the account grace period that
  never existed.
- `dev-environment.md` — dev stack, platform decisions, conventions
  (locale routing and YAML parity; in-site translation proposals live in
  [translations.md](translations.md)).
- `system-configuration.md` — the runtime-editable settings: the registry, the
  two value types, the `system_setting` table, and the admin page that writes
  it. Editorial thresholds plus the operational dials an owner may need to
  turn mid-incident (the auto-withhold budgets, the alert recipients).
- `translations.md` — **implemented** — catalogue overlays, in-site proposals,
  curator desk, and the ops `app:translations:overlay-delete` revert command.

## Rules

1. **Consolidate-on-execute.** When a dated design's execution completes, the
   same session moves its surviving binding decisions into the owning canonical
   doc and stamps the dated file (`> Consolidated into <doc>.md (<date>).`).
   Only stamped files (or files stamped `retired, nothing survives`) may be
   swept — moved into the local `Dated/` folder and removed from the repo.
2. **One owner per fact.** Canonical docs cross-link instead of restating.
   The wiki owns the public "why" (principles, taxonomy, governance); specs own
   the "what/how" (schemas, state machines, endpoints, invariants).
3. **TDD cites canonical docs only.** Test names and comments reference
   `<canonical>.md §heading`, never a dated filename. Numeric thresholds are
   stated as config keys in the spec; tests assert against config, not
   literals.
4. **Cross-doc section references are doc-qualified** everywhere (specs, code
   comments, commit messages): `osm-data-architecture.md §5`, never a bare
   `§5` when pointing into *another* document. Within a doc's own body, a bare
   `§N` refers to that same doc — the established practice across the
   canonical set.
5. **Pending contracts go canonical immediately**, marked
   *“specified, pending implementation”* — the canonical set is what TDD
   builds against, including approved-but-unbuilt contracts.
6. **Ops flags stay out.** Pending prod migrations, token setup, go-live gates
   live in `docs/TODO.md`; design decisions never do.
7. **Sweep guard.** Before sweeping a dated spec out of the tracked tree, grep
   the whole repo for its filename; repoint anything that matches first —
   past hits have surfaced in docs, tests, tooling, and code comments alike.
