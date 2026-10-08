<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Specs: how this directory works

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

Every document in this directory is an official spec: the developer reference
and the contract that tests build against. The specs are versionless and kept
current. When the code and a spec disagree, one of them is a bug, and the fix
lands in both in the same change.

## One canonical spec is deliberately not published

`operations.md` is canonical, and it is **gitignored** (owner, 2026-09-20), so
other specs and a few source comments reference a file that is not in this
repository. That is intentional, not rot.

It is the one spec that is mostly about the *hosts* rather than the software:
production topology, which tenant shares the database, how a deploy reaches each
frontend, the timer schedule, the log windows. No hostname and no credential is
in it, and nothing in it is a secret on its own, but together it is a shape, and
it is of no use to somebody reading the code.

**Nothing a reader is owed went with it.** What the service retains, for how
long, and what it holds about a person is in `/privacy` on the live site and in
[`privacy-notice.md`](privacy-notice.md), which both ship and which are the
authoritative versions. `dev-environment.md` describes the stack a contributor
actually runs.

Revisit when there is a separate infrastructure repository to hold it.

## The canonical set

- `osm-data-architecture.md`: the OSM relationship: data categories, tag
  catalogue, materialize-on-edit lifecycle, legal posture, API policy.
- `api-strategy.md`: the business/access posture of the public API: free bulk
  export vs. metered API, pricing tiers, why commercial consumers are safe.
- `public-api.md`: the public API endpoint contract and integration guide:
  data served, vector-tile + REST transports, auth/metering, versioning, a
  worked consumer example (the endpoint contract osm-data-architecture.md §7 and
  api-strategy.md defer to).
- `public-api-personal-data-boundary.md`: how the public API is kept away
  from account data: Postgres role/grants, dedicated connection, deptrac
  fence, contract tests.
- `edit-items/`: per-type contribution/edit contracts (letters A-M practical,
  N-Z experiential) and the shared contribution contract
  (`edit-items/README.md`).
- `catalog-data-model.md`: the running catalog schema and data contracts.
- `moderation-and-contribution.md`: the contribution-to-decision lifecycle.
- `route-domain.md`: the R route domain in depth.
- `climb-elevation.md`: how a climb's length, gain, gradients and profile are
  MEASURED (elevation source chain, binning, the steepest-ramp window) and how
  the profile chart is drawn. Nobody types a gradient.
- `map-and-search.md`: the map/search UX contract
  (delegated to by osm-data-architecture.md §8).
- `account-and-auth.md`: identity, roles, 2FA, admin desk, profiles.
- `security-architecture.md`: CSP, sanitizer, CSRF, rate-limiter inventory.
- `coverage-provider.md`: the buildable coverage-provider contract.
- `coverage-runs-admin.md`: `/admin/coverage-runs`: what the nightly coverage
  harvest did, run by run.
- `data-provider-hierarchy.md`: which source wins for a place: the provider
  registry (`data_provider`), custody and the evidence ladder a pin draws, and
  how an authority's harvest reaches the map.
- `data-source-register.md`: every candidate upstream source per catalog
  letter, with its licence, an Ingest/Reference/Ask/No verdict and the evidence
  behind it. The supply side; `wiki/landscape.md` is the product side.
- `scenic-views.md`: what counts as a scenic view (letter P) and how coverage
  points, catalog items, a rider's new place and a photo each meet the rules.
- `photo-uploads.md`: the product contract for rider photos: consent,
  moderation, takedown, disposal.
- `media-storage-architecture.md`: how those photos are stored, scanned,
  addressed and served underneath `photo-uploads.md`.
- `scout-bundle.md`: the ride bundle a Scout phone app exports and
  `/scout/review` opens: a FIT file plus photos and notes.
- `traffic-measurements.md`: traffic from a rider's bike radar: road pieces,
  the per-road summary Scout's ride review builds in the browser, the
  encrypted waiting room and plain totals on the server, and the curator
  comparison at `/moderate/traffic`.
- `contact-and-support.md`: `/contact`, `/report-bug`, `/known-issues`, the
  bug button and the desks that read them.
- `content-reports.md`: `/report/{type}/{id}`, the curator desk that answers
  it and the three report emails (DSA notice and action).
- `credits-page.md`: what `/credits` promises, its three tiers of credit, the
  `data-pkg` marker contract, and the gate that stops the page drifting away
  from the dependencies it names.
- `roadmap-and-changelog.md`: `/roadmap`, `/whats-new` and the Atom feed from
  one list, why the version needs a git tag and nothing else, and the updates
  opt-in with its signed one-click unsubscribe.
- `blog.md`: `/blog`, its posts, the Atom feeds and the admin screen behind
  them.
- `form-errors.md`: how a form tells a rider the submission failed: the shared
  form-errors partial and the scan that stops a new form dropping its errors,
  every validation message as a catalogue key rather than an English sentence,
  and the measured contrast of the three error surfaces.
- `site-directory.md`: `/pages` drawn as a touring map with a list toggle; one
  data structure feeds both drawings; the road vocabulary.
- `privacy-notice.md`: where every claim on `/privacy` is true, the two "who
  sees my data" tables and the CSP list that keeps the second one complete, the
  cookie table, and why account deletion is immediate.
- `page-caching.md`: which public pages a shared cache may hold, and what
  keeps the others uncacheable.
- `legal-sources.md`: where every law this project cites actually is; specs,
  wiki and site copy link here.
- `dev-environment.md`: dev stack, platform decisions, conventions (locale
  routing and YAML parity; in-site translation proposals live in
  [translations.md](translations.md)).
- `translations.md`: catalogue overlays, in-site proposals, translate mode,
  curator desk, and the dev-only DeepL draft tool.
- `system-configuration.md`: the runtime-editable settings: the registry, the
  two value types, the `system_setting` table, and the admin page that writes
  it. Editorial thresholds plus the operational dials an owner may need to
  turn mid-incident (the auto-withhold budgets, the alert recipients).
- `operations.md`: canonical but not published (see above).

## Rules

1. **The specs here are leading.** A design decision that ships is written
   into the owning spec in the same change. A note, plan or design draft kept
   elsewhere is never the place a binding decision lives, and specs never
   point at one.
2. **One owner per fact.** Specs cross-link instead of restating.
   The wiki owns the public "why" (principles, taxonomy, governance); specs own
   the "what/how" (schemas, state machines, endpoints, invariants).
3. **Tests cite specs.** Test names and comments reference
   `<spec>.md §heading`. Numeric thresholds are stated as config keys in the
   spec; tests assert against config, not literals.
4. **Cross-doc section references are doc-qualified** everywhere (specs, code
   comments, commit messages): `osm-data-architecture.md §5`, never a bare
   `§5` when pointing into *another* document. Within a doc's own body, a bare
   `§N` refers to that same doc.
5. **Pending contracts go into the spec immediately**, marked
   *"specified, pending implementation"*: the canonical set is what tests
   build against, including approved-but-unbuilt contracts.
6. **Ops flags stay out.** Pending prod migrations, token setup and go-live
   gates are not spec content; design decisions are.
7. **Keep section numbers stable.** Code, tests and other specs cite them.
   Before renumbering or renaming a spec, grep the whole repository for the
   old reference and repoint every match in the same change.
