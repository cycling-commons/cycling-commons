<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Region Ballots — "Rank this region's best" (design)

**Status:** working spec, approved design · **Date:** 2026-07-30 ·
**Branch:** `symfony-base`

The `/vote` page today is a demo stub: hardcoded Wallonia candidates in
`web/assets/contribute/vote.js`, submissions routed through the
`ContributionStub` which deliberately persists nothing. This spec makes the
seasonal region ballot real: a new domain of region-scoped, ranked,
per-category ballots over catalog items, with live standings, a
"region's best" block on region pages, and an honest privacy model.

The route domain's typed votes and best-of rankings
([route-domain.md](route-domain.md) §6, §8) are a **separate, existing
system** and stay untouched; the vote page links to route rankings instead
of growing a fifth tab.

---

## 1. Decisions (owner-approved 2026-07-30)

1. **Purpose — full loop.** Ballots produce per-round standings that order
   the candidate list and the "% this round" bars live on the vote page,
   and surface as a "region's best" block on `/regions/{slug}`.
2. **Rounds — auto seasonal.** Meteorological quarters, global, derived
   from the calendar. No admin surface, no stored round entity.
3. **Region scope — default + switch.** `/vote` defaults to the rider's
   base-location region; explicit region in the URL (`/vote/{slug}`);
   any rider may vote in any operational region.
4. **Scoring — approval % with rank tiebreak.** The displayed number is
   the ranking metric.
5. **Candidates — B/E/I/J, map-served states.** Four tabs as in the demo;
   candidates follow the `/map` visibility contract (unverified +
   verified). No K tab.
6. **Ballot life — carry forward, edit anytime.** One current ballot per
   rider/region/category; at season rollover it keeps counting until
   re-ranked ("re-ranked, never reset").
7. **Storage — append-only versions.** Every submit is an immutable
   ballot version; no scheduler, no snapshot jobs.
8. **Deletion — past rounds keep the vote (owner correction).** Account
   deletion anonymizes ballots rather than erasing them: rounds closed
   before deletion keep the (now anonymous) ballot; the round open at
   deletion and all later rounds exclude it. See §5.

## 2. Rounds — pure date math

A `Season` value object (no persistence) computes everything from a date:

- Spring = Mar 1 – May 31 · Summer = Jun 1 – Aug 31 ·
  Autumn = Sep 1 – Nov 30 · Winter = Dec 1 – Feb 28/29.
- Winter is labelled by December's year: "Winter 2026" runs Dec 2026 –
  Feb 2027.
- Stable round key `2026-summer` (year + season slug); label via
  translations (`vote.round.summer` etc.); `closesAt()` is the first
  instant of the next season (UTC); "closes in N days" is the whole-day
  ceiling to that instant.
- API: `Season::current(\DateTimeImmutable $now)`, `->key()`,
  `->labelParams()`, `->closesAt()`, `->contains(\DateTimeImmutable)`.

Rounds are global and identical for every region. UTC everywhere; the
±hours of boundary skew at season turnover are irrelevant at this cadence.

## 3. Data model — append-only ballot versions

Two new tables (one Doctrine migration):

```
region_ballot
  id            BIGSERIAL PK
  user_id       BIGINT NULL      -- FK user, SET NULL on anonymization
  voter_key     UUID NOT NULL    -- opaque per-user surrogate, see §5
  region_id     INT NOT NULL     -- FK region
  category      CHAR(1) NOT NULL -- catalog letter: B | E | I | J
  submitted_at  TIMESTAMPTZ NOT NULL
  anonymized_at TIMESTAMPTZ NULL -- stamped on account deletion
  INDEX (voter_key, region_id, category, submitted_at)
  INDEX (region_id, category)

region_ballot_pick
  ballot_id  BIGINT NOT NULL  -- FK region_ballot, ON DELETE CASCADE
  position   SMALLINT NOT NULL -- 1..10
  item_id    BIGINT NOT NULL  -- FK item
  UNIQUE (ballot_id, position)
  UNIQUE (ballot_id, item_id)
```

- **Every submit appends** a new `region_ballot` + its picks. Nothing is
  ever updated in place (except the two anonymization columns).
- **Current ballot** for (rider, region, category) = the row with the
  greatest `submitted_at` (id as final tiebreak).
- **An empty version** (zero picks) is a valid submit and means "withdraw
  my ballot in this category".
- `voter_key` is a random UUID minted once per user on their first ballot
  submit ever, then copied onto each of their subsequent versions (read
  from any existing row). It exists so aggregation can group versions by
  voter after `user_id` is gone.

## 4. Scoring and standings

For a round R, per (region, category):

1. Take each voter's **latest version** with
   `submitted_at <= R.closesAt()` (carry-forward is automatic).
2. Count a voter only if `anonymized_at IS NULL OR anonymized_at >
   R.closesAt()` (§5).
3. Drop voters whose latest version is empty.
4. **Approval share** per item = ballots containing the item ÷ counted
   non-empty ballots, as a percentage.
5. Order: approval share desc, then **mean ballot position** asc, then
   item name asc.
6. Items no longer in a publicly-served state (`unverified`/`verified`)
   are filtered out of standings at read time.

For the **open round**, `R.closesAt()` is in the future, so step 1 simply
takes each voter's current ballot; step 2 reduces to
`anonymized_at IS NULL`. One SQL shape (`DISTINCT ON (voter_key)` inner
query + join to picks + `GROUP BY item`) serves both live and historical
reads. Computed live per request; the public endpoint carries a short
`Cache-Control` (60 s), same posture as the route bulk endpoint
([route-domain.md](route-domain.md) §6.3).

## 5. Privacy and account deletion

- **Ballot contents are private to the voter.** The only surface that
  shows *what* was ranked is the owner's profile (§9). Everywhere else,
  only aggregates appear. This matches the on-page promise: "The Commons
  records that you voted and when — never a public record of what you
  ranked."
- **Account deletion** (a `UserDeletionHookInterface` implementation):
  for all the user's ballot rows set `user_id = NULL`,
  `anonymized_at = now()`. Rows and picks are kept. No user → voter_key
  mapping survives (the mapping only ever existed on the rows
  themselves), so retained rows are anonymous: picks, region, category,
  timestamps, random key.
- **Effect on standings** (owner decision): every round that closed
  before the deletion still counts the ballot; the round open at deletion
  and all future rounds exclude it — enforced by the
  `anonymized_at > R.closesAt()` predicate in §4 step 2, which also keeps
  a carried-forward older version from leaking back in after the open
  round closes.

## 6. Candidates and validation

- Candidate pool for (region, category letter L):
  `item.region_id = region AND item.letter = L AND item.state IN
  (unverified, verified)` — exactly the `/map` visibility contract
  ([catalog-data-model.md](catalog-data-model.md) §4).
- Submit validation: per category ≤ 10 picks, all distinct, every pick in
  the candidate pool at submit time. Any violation rejects that
  category's ballot with a field-level error; nothing partial is written.
- Standings re-filter by state at read time (§4), so a later
  retirement/rejection silently drops an item from results without
  touching ballots.

## 7. Page, routing, region resolution

- **`GET /vote/{slug}`** (locale-prefixed via `LocalePrefix::PATHS`, slug
  pattern as `region_detail`): the ballot page for one operational
  region. Slug resolution mirrors `/regions/{slug}` — operational regions
  only; remember the L2-infrastructure-rows gotcha
  ([2026-07-30-dynamic-region-pages-design.md](2026-07-30-dynamic-region-pages-design.md)):
  every new region query must filter-or-exempt those rows. Unknown or
  non-operational slug → 404.
- **`GET /vote`**: with a base-location region set → 302 to that region's
  page; otherwise renders a region picker (operational regions grouped by
  country, reusing `RegionDirectoryProvider`). Keeps route name `vote` so
  existing nav/links stay valid; `nav_active: 'vote'`.
- **Publicly viewable.** Candidates and standings render logged-out; the
  ballot panel shows a login CTA instead of Add/Submit controls.
  Voting requires `ROLE_USER`.
- Four tabs (Climbs `B`, Stays `E`, Views `I`, Heritage `J`); tab
  switching is client-side over a per-category payload. A short note under
  the tabs links routes to the existing route best-of.
- Region switcher on the page header links back to the picker.
- Candidate rows: name, type-appropriate meta line (from item attributes),
  "% this round" bar once the region+category meets the display threshold
  (§8) — below it, an honest "too few ballots yet" state instead of fake
  bars.

## 8. API endpoints

Route-domain §6 JSON-API patterns (same auth/CSRF/caching posture):

- **`GET /api/vote/{slug}`** — public, cacheable (60 s). Round metadata
  (key, label params, closes-in), and per category: the candidate list
  with current-round standing (approval %, ballot count) ordered per §4.
  Needs the exact-path `PUBLIC_ACCESS` firewall entry (2FA-firewall
  gotcha, [route-domain.md](route-domain.md) §6.3).
- **`GET /api/vote/{slug}/mine`** — `ROLE_USER`, no-store. The caller's
  current ballot per category (ordered item ids), so reopening the page
  shows the ballot as it stands.
- **`POST /api/vote/{slug}`** — `ROLE_USER`, CSRF-protected
  (header token pattern as `RouteCommunityController`). Body: per
  category an ordered array of item ids; only categories present in the
  body are written (each as one new version — including explicit `[]`
  for withdraw). Response: per-category ok/error + fresh standings.
  Rate-limited like other contribution posts.
- The old `VoteType` form, the hidden-field sync, and the
  `ContributionStub` vote branch are **retired**; `vote.js` is rewritten
  fetch-based (esc-helpers kept). The stub's receipt screen is replaced
  by an in-page confirmation state (ballot saved · round · privacy note).

## 9. Surfaces

- **`/regions/{slug}` — "The region's best".** Top 5 per category from
  the open round, only when that region+category has ≥ N counted ballots;
  N is a new runtime-editable system-config editorial threshold
  (`vote.min_ballots_display`, default 3, alongside the existing six).
  Below threshold the block shows a "be among the first to rank this
  region" CTA; either way a CTA links to `/vote/{slug}`.
- **Profile votes pane.** Extends the existing private pane
  (`ProfileController`): current region ballots (region, category, pick
  count, last re-ranked, link to `/vote/{slug}`) listed alongside route
  votes. Owner-only, as today.
- **Nav.** `Vote` entry unchanged.

## 10. Testing

- **Unit:** `Season` math (boundaries incl. Winter year-label and leap
  Feb, key/closesAt/contains); standings service against fixtures —
  approval math, mean-position tiebreak, carry-forward via backdated
  versions, empty-version withdrawal, anonymization predicate (closed
  round keeps / open round drops), retired-item exclusion.
- **Functional:** region resolution (`/vote` redirect vs picker, 404s,
  L2 rows excluded); public GET logged-out; `mine`/POST auth gates;
  CSRF; validation failures (11 picks, duplicate, foreign-region item,
  wrong-category item); submit → new version appended, standings move;
  re-submit replaces; profile pane rows; region-page block threshold
  gating; deletion hook anonymizes and drops from open round.
- Suite stays green (868 baseline before this work).

## 11. i18n

All new copy in EN/FR/NL/DE `messages` domains: tabs, round labels,
closes-in, ballot panel, privacy note, picker, region-best block,
profile-pane labels, error messages. Existing `vote.*` keys are reused
where copy is unchanged, stale stub keys removed.

## 12. Out of scope

- Route (K) voting UI changes — already served by the route domain.
- Notifications/emails about round turnover (no scheduler yet).
- Closed-round archive pages ("Summer 2026 results") — the data model
  supports them by construction; page deferred until wanted.
