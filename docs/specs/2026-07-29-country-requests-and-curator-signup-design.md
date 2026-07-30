<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Country requests and curator signup — design

> Consolidated into moderation-and-contribution.md, account-and-auth.md and
> security-architecture.md (2026-07-29).

**Status:** design, pending implementation · **Owner decisions:** 2026-07-29
· **Audience:** contributors to Cycling Commons

## 1. Why now

On production at launch, **every country is empty**. The four onboarded
countries (BE, NL, DE, LU) carry coverage POIs but no genuine curation —
Belgium's ~1,581 "curated" items are fixture/harvest data that stays on staging
and is dropped on production (docs/TODO.md). The other 245 countries have
nothing at all: no regions, no coverage, no items.

So the first thing most visitors will meet is an empty map. Today that dead-ends.
The map already tells them *what* is missing (the rail reads "0 places shown");
it offers them nothing to *do* about it. This design adds the two doors an
empty map should have:

- **"I want to use Cycling Commons here"** — a demand signal, so the next
  country to onboard is chosen from evidence rather than a hunch.
- **"I'd like to curate here"** — a supply signal, which is the harder and more
  valuable one, because a country without a curator stays empty however many
  people want it.

The design problem is not collecting the clicks. It is knowing that the person
behind them is real and serious, without imposing identity checks that no
volunteer mapping project should ask for.

## 2. Owner decisions (2026-07-29)

| # | Decision | Rationale |
|---|---|---|
| 1 | **Both signals require a verified account.** | Registration + email verification + the existing rate limiters and audit log already exist. Roughly nine tenths of the bot problem is solved by machinery already built, with nothing new to maintain. |
| 2 | **Both empty states get an offer, with different wording.** | A country that is not onboarded needs "please add it". A country that is onboarded but uncurated needs "be the first curator" — asking to *add* a country that already exists reads as broken. |
| 3 | **Seriousness is judged from three pieces of evidence — filed submissions, an OSM handle, and a short note about the person — weighed by a human, not enforced as a gate.** The local specifics live in the submissions (§8), not in prose. | An account alone proves a person; it does not prove they will do the work. These three are cheap to give, expensive to fake, and readable in under a minute. |
| 4 | **The owner is the bootstrap.** He is world moderator, reviews the evidence, approves the contributions it produces, and grants the seat. | Removes the cold-start deadlock: a prerequisite that nobody can satisfy on day one is not a filter, it is a wall. |
| 5 | **Granting curator stays an admin-only action**, designed so the permission can extend to trusted moderators later. | Matches how curator promotion works today (`UserAdminService::setRole`, audit-logged). No new authority model until there are moderators to trust with it. |
| 6 | **Claims enter the existing submission pipeline; no parallel evidence channel.** | Nothing is public until a moderator approves it, so the queue is already the safe place for unverified claims. Reviewing an application therefore improves the map even when the answer is no. |
| 7 | **The map shows the invitation; a page holds the forms.** | Catches people at the moment of disappointment without putting form handling, validation and four-locale copy back into the map bundle. |
| 8 | **Onboarding a country auto-generates all its regions.** | Manual per-country region work does not scale to 245 countries. |
| 9 | **Region depth is per country, not universal.** Most countries stop at state/province; the USA needs state → county, and possibly finer. | Depth is a property of a country's administrative reality, not of our schema. |

## 3. What the model already supports

Verified against the running code and dev database on 2026-07-29, before any
design decision was taken on it.

**Country-as-region already exists.** Luxembourg is a single region row at
`admin_level = 2`, `iso_code = LU`, covering the whole country. BE, DE and NL
are `admin_level = 4` subdivisions. The "the country itself is the parent
region" idea is therefore the Luxembourg pattern, not a new concept.

**Overlapping levels already resolve most-specific-first.** Every path that
stamps a point to a region takes the *smallest containing polygon*:

| path | mechanism |
|---|---|
| `Contribution\RegionResolver::resolve()` | `ORDER BY r.area_km2 ASC NULLS LAST, r.id ASC LIMIT 1` |
| `ImportCatalogCommand` (items, routes, heat) | `SELECT DISTINCT ON (…) … ORDER BY …, r.area_km2 ASC NULLS LAST, r.id ASC` |
| `SeedManualCatalogCommand` | same `DISTINCT ON` + area ordering |

A point in Barcelona, with both a level-2 Spain region and a level-4 Catalonia
region present, lands in Catalonia. A point in a gap Catalonia does not cover
lands in Spain. This is already deterministic and already commented as such.

**Therefore: no `parent_id`, and no schema change for hierarchy.** Arbitrary
depth (country → state → county → municipality) works today because "which
region is this point in" is answered geometrically by area, not by walking a
tree. A parent link would only buy a cheap "list the children of X" for UI, and
that is derivable (`country_code` + higher `admin_level` + `ST_Within`). If it
ever becomes a measured performance problem, add a materialised `parent_id`
then — not before.

A reference implementation in a sibling project of the owner's uses a fixed
two-tier model (division → subdivision, with a free-text type label). Cycling
Commons should **not** copy that shape: a fixed tier count cannot express "most
countries stop at level 4 but the USA goes to level 6", whereas
`admin_level` + containment already does, per country, with no migration.

**Scope for a curator is already expressible.** `ModeratorArea` can point at a
level-2 country region or a level-4 subdivision, so "the whole country, or one
division?" needs no new concept — only a field on the application to record what
was asked for.

## 4. The staging problem, and what it forces

A country with **no region at all** has nothing to anchor a submission to, and
245 countries are in that state. That single fact orders the whole flow:

1. **Request** — a tick against a country code. Needs no region; all 249
   countries already exist in the World bundle. Available immediately, everywhere.
2. **Onboard** — the owner runs the existing playbook (`tools/divisions/`),
   which generates the country's regions. Only now can anyone file anything there.
3. **Apply to curate** — with submissions as evidence. Requires step 2.
4. **Scope and grant** — the owner asks whole-country or a division, creates the
   matching `ModeratorArea`, grants `ROLE_CURATOR`.

**Consequence:** "I'd help curate" on a *not-yet-onboarded* country cannot be a
real application — there is nothing to evidence and nothing to scope. It is a
**flag on the request**, and that flag is the contact list to work from when the
country is onboarded. The full application belongs to step 3.

## 5. Data model

Two records, because they have different lifecycles: one is a counter, the other
is a workflow item.

### 5.1 `CountryInterest`

| field | type | notes |
|---|---|---|
| `id` | bigint | |
| `userId` | bigint | FK to `users` |
| `countryCode` | char(2) | ISO 3166-1 alpha-2, validated against the World bundle |
| `willingToCurate` | boolean | the contact list for step 2 |
| `note` | varchar, nullable | short, optional; §7 hardening applies |
| `createdAt` | datetime_immutable | |

`UNIQUE (userId, countryCode)` — one per person per country. Re-submitting
updates `willingToCurate` and `note` rather than creating a second row.

Read as a count per country, ordered by interest, with the willing-to-curate
subset shown separately: a country with 40 interested riders and no volunteer is
a different proposition from one with 3 interested riders and 2 volunteers.

### 5.2 `CuratorApplication`

| field | type | notes |
|---|---|---|
| `id` | bigint | |
| `userId` | bigint | FK to `users` |
| `countryCode` | char(2) | must be onboarded (§4) |
| `requestedRegionId` | bigint, nullable | null = whole country; set = one division |
| `osmUsername` | varchar(64), nullable | validated per §6 |
| `osmVerifiedAt` | datetime_immutable, nullable | null = unverified/unknown |
| `osmChangesetCount` | int, nullable | as reported at verification time |
| `about` | text | short; §7 hardening applies |
| `status` | enum | `pending` / `approved` / `declined` / `withdrawn` |
| `decidedBy` / `decidedAt` / `decisionNote` | | mirrors `Submission`'s decision fields |
| `createdAt` | datetime_immutable | |

At most **one `pending` application per user per country** (partial unique
index). A declined applicant may re-apply; the history stays.

**Evidence is not copied into this row.** The applicant's submissions are found
by `userId` + `countryCode` at review time, so the review screen always shows
their current state rather than a stale snapshot taken at submit.

## 6. The OSM handle

Optional, and never a requirement — plenty of serious local riders have no OSM
account. When given, it is the single cheapest strong signal available: a public,
checkable track record of exactly the kind of work being volunteered for.

- **Charset-validated before use.** OSM display names are constrained; the value
  is validated against that character set and length-capped before it is ever
  put in a URL.
- **Looked up server-side** against the public OSM API for existence and
  changeset count. Result stored in `osmVerifiedAt` / `osmChangesetCount`.
- **Best-effort, never blocking.** A short timeout; if OSM is slow, rate-limits
  us, or is down, the application still submits and the review screen shows
  *unverified* rather than a false negative. Same silent-degradation rule the
  coverage manifest and the Photon geocoder already follow
  (coverage-provider.md §6).
- **Cached and rate-limited**, so a form cannot be used to hammer a third party.

A high changeset count is not a qualification and a zero count is not a
disqualification; it is one line of evidence among three.

## 7. The free-text fields — hardening

Two fields accept prose from a stranger: `CountryInterest.note` and
`CuratorApplication.about`. Both are reviewer-only and **never rendered on any
public page**, which bounds the blast radius of anything hostile to the owner's
own screen. On top of that:

| rule | why |
|---|---|
| Server-enforced length cap | a bounded field cannot carry a payload |
| Plain text only; **rendered escaped, never as HTML** | stored XSS in a reviewer's session is the exact class already fixed for pending submissions (security-architecture.md) |
| **URLs rejected** at validation | nobody needs a link to explain why they know an area, and links are the entire payload of most spam |
| Zero-width and bidirectional-override characters stripped | the standard trick for smuggling text past a human reviewer |
| Unicode normalisation before storage | prevents visually identical duplicates evading the uniqueness rules |
| Stored raw, escaped at render | the audit trail keeps what was actually sent |

The *claims* about what is wrong on the map do **not** go in these fields. They
go through the contribute wizard as normal submissions (§8), which is what keeps
this field short enough to be safe.

## 8. Evidence goes through the existing pipeline

The application does not carry a prose list of what is wrong. The applicant
files those observations as **normal submissions** through the contribute
wizard, and the review screen surfaces them.

This is the point of decision 6: pending submissions are invisible to the public
until approved, so the queue is already the right home for unverified claims.
Sending them anywhere else would duplicate the moderation machinery and split
the owner's attention across two inboxes.

It also changes what the application *is*: self-evidencing rather than
self-describing. Someone who has filed three accurate submissions in a province
has demonstrated more than any paragraph could claim, and the act of reviewing
them improves the map whether or not the seat is granted.

The `about` field is left to do the one job prose is good at: who they are and
why this area.

## 9. Review and approval

A plain `#[AdminRoute]` page, following the system-configuration.md precedent
(a typed, purpose-built page — not an EasyAdmin CRUD over a table).

Per application, the reviewer sees:

- **Person** — display name, email-verified, account age, public-profile state.
- **Track record** — submission counts, total and approved, scoped to the
  country and overall.
- **Locality** — their base location (if set) against the country applied for.
  A self-reported signal, but a consistent one.
- **OSM** — handle, verified state, changeset count, or *unverified*.
- **Their words** — the `about` text, escaped.
- **Their evidence** — their submissions in this country with current status,
  linking into the normal moderation desk.

Actions: **approve** (grants `ROLE_CURATOR` *and* creates the `ModeratorArea`
for the requested scope), **decline** with a note, both written to
`AdminActionLogger` with the before/after role state. The applicant is notified
through the existing messaging system either way — a decline that arrives as
silence teaches people not to volunteer.

**Approval scopes rather than promotes.** A Dutch applicant curates the
Netherlands. Global curator remains something granted deliberately, not a side
effect of a form.

## 10. Surfaces

### 10.1 The map

When the active scope has zero curated items, the rail shows **one line**:
*"Nothing curated here yet — help start it."*, linking to the page in §10.2 with
the country pre-filled.

- No form, no POST, no new module in the map bundle.
- The zero-curated condition is the count the rail already computes and the
  Curated-readiness gate already consumes; nothing new is calculated.
- At launch this appears on effectively every country. That is honest, and it is
  the accurate description of the map on day one.

### 10.2 The page

One route, two states, driven by whether the country is onboarded:

| state | headline | form |
|---|---|---|
| **Not onboarded** (245) | "Cycling Commons isn't in *Spain* yet" | request + *"I'd help curate here"* checkbox |
| **Onboarded, uncurated** | "Nobody is curating *Gelderland* yet" | curator application (§5.2) |
| **Onboarded, curated** | not offered | — |

Reuses or sits beside `/regions` and `/coverage`, which already exist as public
pages. Four locales, like every other user-facing surface.

## 11. Abuse control, in one place

| layer | mechanism |
|---|---|
| Identity | verified account required for both actions (decision 1) |
| Uniqueness | one interest per user per country; one pending application per user per country |
| Rate | two new sliding-window limiters, `country_interest` and `curator_application`, following the `rate_limiter.yaml` pattern (security-architecture.md) |
| Content | §7 hardening on both free-text fields |
| Exposure | neither field is ever rendered publicly |
| Third party | OSM lookups cached, rate-limited, timeout-bounded |
| Audit | every grant and decline through `AdminActionLogger` |

**Deliberately not built:** a captcha. Verified account + rate limits + a human
reviewer is proportionate at this scale, and a captcha stops nobody who has
already registered and verified an email address. Revisit only if evidence of
real abuse appears.

## 12. The two remaining questions, and the recommendation on each

> **Decided by the owner (2026-07-30): both recommendations adopted as
> written.** 12.1 — onboarding runs at levels 2 + 4 by default, finer levels
> only per country on evidence. 12.2 — interest counts stay owner-only; no
> public or admin counter is built for v1.

Both were recorded as **recommendations pending the owner's confirmation**, not
as settled decisions. Neither blocked the implementation of this design.

### 12.1 Region generation depth per country

Decision 8 settles that onboarding auto-generates regions; decision 9 settles
that depth varies by country. What remains is what decides the depth.

**Recommendation: onboard at levels 2 + 4 by default, and add finer levels only
per country, on evidence.**

- **Level 2 always**, because §4 step 3 depends on it: without the country
  polygon there is nowhere to anchor a submission, and the whole evidence path
  collapses for that country.
- **Level 4 by default**, because it is what every onboarded country already
  uses (BE 3, NL 12, DE 16) and it is the granularity the scope rail, the
  cross-border chips and `ModeratorArea` are all tuned for.
- **Level 6 and finer only when a country needs it**, driven by a per-country
  setting in the onboarding playbook rather than by schema. The USA is the first
  case: ~3,000 counties is a different order of magnitude from 16 Bundesländer,
  and it lands on the coverage tiles, the chip ranking and the region-scoping
  queries all at once. That deserves its own measured round, not a default that
  quietly applies it everywhere.

Depth therefore stays a property of the onboarding run, not of the model — which
is exactly what §3 makes possible, since containment already resolves any mix of
levels without a schema change.

**Out of scope here.** This design needs only the guarantee that the level-2
region exists.

### 12.2 Interest visibility

**Recommendation: owner-only for v1.**

A public counter is motivating when the numbers are good and discouraging when
they are not, and on a map with no data yet most numbers will be small. It also
becomes a target: a visible ranking is worth gaming, and the whole point of the
signal is that it is honest input to the owner's onboarding decision.

Publishing it later is a one-line change once real numbers exist and the ones
worth showing can be chosen. Going the other way — retracting a public number —
is not.

## 13. Follow-up: the public onboarding spec

Once this is built and has been through real use, the country-onboarding story
needs a **public-facing spec** — the document a stranger reads to understand how
a country goes from "not on the map" to "curated", and what is expected of the
person who volunteers.

Deliberately after the build, not before (owner, 2026-07-29): the honest version
of that document is written from what actually happened — which evidence turned
out to matter, how long a review really takes, what applicants ask that the form
did not anticipate. Writing it first would publish a guess.

It belongs in the **wiki** rather than `docs/specs/` when it lands: the wiki owns
the public "why" (principles, taxonomy, governance) and specs own the "what/how"
(docs/specs/README.md rule 2). The internal contract stays here; the invitation
goes there.

## 14. Out of scope

- Auto-triggering onboarding from an interest threshold. The owner decides which
  country is next; the counts inform, they do not act.
- Converting the `about` text into submissions automatically (§8 — the wizard
  is the path).
- Extending grant authority to moderators (decision 5 — designed for, not built).
- Any identity verification beyond a confirmed email address.
