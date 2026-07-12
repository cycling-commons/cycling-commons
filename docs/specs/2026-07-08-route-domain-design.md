# Route Domain v1 — Recommended Routes (K) — Design

- **Status:** draft — awaiting review
- **Date:** 2026-07-08
- **Related:** [`edit-items/K-quality-rides.md`](edit-items/K-quality-rides.md) (rewritten against this spec), [`edit-items/README.md`](edit-items/README.md) (lifecycle funnel — K now carves out), [`2026-07-03-catalog-data-model-and-import-design.md`](2026-07-03-catalog-data-model-and-import-design.md) §8 (route lifecycle), [`2026-07-04-submissions-and-moderation-on-real-data-design.md`](2026-07-04-submissions-and-moderation-on-real-data-design.md) (item pipeline — explicitly NOT reused here), [`2026-07-02-map-based-moderation-design.md`](2026-07-02-map-based-moderation-design.md) §8 (moderation shell — gains a Routes queue).

## 1. Problem

K (Quality rides) inherited the generic item editing model from the demo era: a
drawer "Edit this ride" link into `/improve`. That was wrong twice over. First,
mechanically: `recommended_route` has its own id sequence, so the link's
`?item=<routeId>` collided with unrelated `item` rows — the 2026-07-07 security
review's #1 critical (fixed: `/improve` now refuses `type=K` and type/letter
mismatches). Second, conceptually: a route is a **curated composition** — a
ridden track plus editorial metadata — not an atomic map feature a passer-by
corrects. Treating it as rider-editable invites the failure mode this design
exists to prevent: **a region drowning in 1000+ unvetted routes**, where the
map stops being a recommendation and becomes a dump.

This spec defines the route domain: how routes enter the system, who owns
them, how the community's voice works (votes and ride-confirmations, not
edits), and how volume stays bounded.

## 2. Goals / Non-goals

**Goals**

1. **Rider proposals**: authenticated riders propose a route (GPX upload +
   metadata form). Proposals are rate-limited and land as `RecommendedRoute`
   rows in state `submitted` — never directly on the map.
2. **Desk moderation**: a dedicated **Routes queue** in the moderation shell
   (sibling of the item queue, not part of it). Curators approve → `unverified`
   ("proposed" badge on the map), reject → `rejected`, retire → `retired`.
3. **Region cap**: a configurable ceiling (~30) on *active* routes
   (`unverified` + `verified`) per region. A full region admits a new route
   only by retiring a weaker one — the cap is the editorial forcing function.
4. **Ride-verification**: X independent riders clicking **"I rode this"**
   (with the bike type they used) upgrades `unverified → verified`. X is
   config, default 3 (the README threshold-tier spirit: experiential ~2–3).
5. **Typed seasonal votes**: a vote declares *season* and *bike type*
   ("I recommend this as a Spring ride on Gravel"). One vote per user per
   route per season. Votes are timestamped and cumulative in v1.
6. **Voting opens at `verified`.** On a proposed route the only rider action
   that counts toward the funnel is "I rode this" — no vote-stuffing un-ridden
   routes into best-of. (Same rule as the item funnel: verification is the
   gate that starts vote collection.)
7. **GPX download** for served routes — you can't ask riders to ride (and
   ride-verify) a track they can't get.
8. **Suggest a correction**: the rider-facing report channel on a route
   drawer. Preset reasons (wrong/broken track · trim a private start/end ·
   duplicate · not actually rideable — inherited from the old K spec) +
   free text, moderated in the Routes queue. Riders never edit route data.
9. **Best-of per (region, season, bike type)** computed from typed votes.
   Handbike is a first-class bike type: its list surfaces only routes declared
   handbike-suitable *and* voted by handbike riders.

**Non-goals (recorded future work)**

- **GPX-proof ride verification** (v2): an uploaded ride track is checked for
  approximate visits to N sample points along the route; on match the rode-it
  counter increments and the uploaded track is **deleted immediately** — no
  personal ride data is ever stored. Same counter as v1's button, stronger
  evidence.
- **Seasonal nomination windows** per region (once a region has traction):
  supply opens briefly each season, curators shortlist, community votes.
- **Annual vote reset/archival** — votes are timestamped from day one so
  "archive votes older than the current edition" is a later switch, not a
  schema change.
- "Starts at / towns on route" reverse-geocoding from the track.
- FIT-file proposals (GPX only in v1; convert client-side or externally).
- Curator track *replacement* UI (v1 workaround: retire + re-propose).
- The `/vote` page redesign — the drawer is v1's voting surface; the page's
  hardcoded Routes ballot tab is retired in phase 4 (the open Vote-nav
  decision stays open).

## 3. Decision record

| # | Decision | Rationale |
|---|---|---|
| D1 | **Purpose-built route pipeline** — no reuse of `Submission`/`ModerationService` | Desk-reviewing a route (look at the line, check the cap, compare the region's set) is a different job than approving a pin edit. The item pipeline is the app's most-tested path; absorbing a target it never modeled (GPX geometry, votes, caps) buys modest reuse for high regression risk. Votes/rides/caps need their own tables regardless. |
| D2 | **Proposals are `RecommendedRoute` rows in state `submitted`** — no proposal entity | `ItemState` already models the lifecycle (`submitted → unverified → verified`, `rejected`, `retired`) and `RecommendedRoute` already carries it. A separate proposal table would duplicate every field and add a promotion step for nothing. |
| D3 | **Riders never edit route data** | A route is the curator's editorial voice seeded by a rider's proposal. Rider signal arrives as votes, ride-confirmations, and moderated suggestions. This also permanently retires the id-collision bug class. |
| D4 | **Trim at ingest, store only the trimmed track** | Inherited hard requirement (old K spec "Privacy — trim the ends"): first/last ~350–750 m dropped, deterministically per route, before anything persists. The untrimmed upload never touches storage — proposals contain personal start/end fingerprints by construction. |
| D5 | **GPX parse + distance/ascent in Symfony (PHP)** | Per the Python/PHP boundary rule: XML parse + haversine cumsum + elevation gain is light tabular math, not geo/raster/routing work. No pipeline round-trip for a form submission. |
| D6 | **Bike types: `Road / Gravel / MTB / E-bike / Handbike`** | The user's four plus E-bike, which the registry and harvested data already carry. One shared enum for route suitability (multi-select), votes, and ride-confirmations. |
| D7 | **Voting opens at `verified`** (goal 6) | Funnel-consistent; prevents ranking un-ridden routes. |
| D8 | **Region cap over display cap** | Approve-freely-show-top-N confuses proposers (approved but invisible). A hard cap with retire-to-admit keeps the editorial choice explicit and the map honest. |
| D9 | **Curator route edits write append-only history** | Same provenance principle as items (`change_history`), but a route-scoped table (`route_change_history`) — routes are outside the item pipeline (D1), and `change_history.item_id` FKs to `item`. |

## 4. Domain model

### 4.1 `RecommendedRoute` (existing entity, extended)

Existing columns stay. Additions:

| Column | Type | Notes |
|---|---|---|
| `suitability` | jsonb (in `attributes`) | multi-select from the bike-type enum (D6). Already conventionally in `attributes.bikeTypes`; v1 normalizes the value set. |
| `proposed_by` | bigint FK → `app_user`, nullable | the proposing rider; NULL for imported routes. Provenance only — confers no rights (no owner-edit). |

Proposals set `source = ItemSource::User`, `source_ref = 'user:<uuid>'`
(unique, so harvest upserts keyed on `(source, source_ref)` can never clobber
them), `state = submitted`, `region_id` resolved at intake (same PostGIS
membership rule the importer uses).

### 4.2 New tables (all purpose-built, D1)

**`route_vote`** — the typed seasonal vote.

| Column | Type | Constraint |
|---|---|---|
| `id` | bigserial PK | |
| `route_id` | bigint FK → `recommended_route` | on delete cascade |
| `user_id` | bigint FK → `app_user` | on delete cascade |
| `season` | text enum: `spring/summer/autumn/winter` | voter picks; defaults to current |
| `bike_type` | text enum (D6) | the bike the voter recommends it for |
| `created_at` | timestamptz | enables the future annual reset |
| | | **UNIQUE (route_id, user_id, season)** |

**`route_ride`** — the "I rode this" confirmation.

| Column | Type | Constraint |
|---|---|---|
| `id` | bigserial PK | |
| `route_id` / `user_id` | FKs as above | |
| `bike_type` | text enum (D6) | |
| `created_at` | timestamptz | |
| | | **UNIQUE (route_id, user_id)** |

**`route_suggestion`** — the moderated correction channel.

| Column | Type | Constraint |
|---|---|---|
| `id` | bigserial PK | |
| `route_id` / `user_id` | FKs as above | |
| `reason` | text enum: `broken-track / trim-privacy / duplicate / not-rideable / other` | presets from the old K spec |
| `note` | text, length-capped | free text, HTML-escaped on render (moderation drawer XSS rule) |
| `status` | text enum: `pending / done / dismissed` | curator-resolved |
| `created_at` / `resolved_at` / `resolved_by` | | audit |

**`route_change_history`** (D9) — append-only: `route_id`, `field`,
`old_value`, `new_value`, `changed_by`, `created_at`. One row per curator
field edit; state transitions log `field = 'state'`.

> **Shipped-schema correction (2026-07-12):** the "FK → … on delete cascade"
> cells above record the original design intent, **not the shipped schema**.
> The executed migrations create `route_id`/`user_id` as **plain indexed
> bigints with no DB foreign keys** (house convention — matches
> `submission.user_id`), so these rows survive account deletion as anonymous
> data by decoupling, not cascade. The schema's first real `user_id` FK is
> planned for `user_message` only
> ([messages design](2026-07-12-moderation-feedback-and-messages-design.md) M10).

### 4.3 State machine (reuses `ItemState`, route semantics)

```
 submitted ──(curator approve, cap permitting)──▶ unverified ("proposed" badge)
     │                                                │
     └──(curator reject)──▶ rejected                  ├──(X route_ride rows)──▶ verified (votable)
                                                      │
                              retired ◀──(curator retire; frees a cap slot)──┘  (from unverified OR verified)
```

- **Active** (counts against the cap, served to the map) = `unverified` + `verified`.
- No OSM-import path creates route `unverified` rows silently verified —
  imports keep their existing behavior; this machine governs proposals.
- `verified` is sticky in v1 (no automatic demotion; curator retire is the exit).

## 5. Intake (phase 1)

1. **Entry point**: the contribute hub's existing "add a ride" card (slug
   `quality-rides`) repoints to `/propose-route` (`ROLE_USER`). The stubbed
   GPX locate mode in `improve.js` is retired.
2. **Form**: GPX file + the QualityRides registry metadata (name, difficulty,
   season, dominant surface, note, suitability multi-select (D6 — Handbike is
   one of its values, absorbing the old separate handbike field), and the
   gradient-limited accessibility field).
3. **GPX validation** (server-side, lessons from the 2026-07-07 review's
   ClimbGeometry findings): file ≤ 15 MB (raised from 2 MB, §15); ≥ 2 track points, ≤ 50 000 points
   pre-simplification; every coordinate range-checked (lat −90..90,
   lng −180..180, finite); track length ≥ 2 km and ≤ 400 km. Reject, never
   coerce.
4. **Processing** (Symfony, D5): parse → **privacy trim** (D4: drop first/last
   ~350–750 m, deterministic per route — seeded from the content hash) →
   simplify to a serving resolution (Douglas-Peucker, ~10 m tolerance) →
   compute `distance_m` (haversine cumsum) and `ascent_m` (positive elevation
   gain from GPX `<ele>` when present, else NULL) → resolve `region_id` →
   persist `state = submitted`.
5. **Rate limit**: dedicated limiter (default 3 proposals/day per user),
   same factory pattern as `contributionSubmitLimiter`.
6. **Receipt**: real persisted receipt ("proposal received — a curator will
   review it"), listed under "my contributions".

## 6. Desk moderation (phase 2)

New **Routes** section in the moderation shell (`ROLE_CURATOR`), separate
queue from item submissions:

- **Queue list**: pending proposals (+ pending `route_suggestion`s), oldest
  first, per-region filter mirroring the item queue's region scoping.
- **Detail view**: the trimmed track on a map, metadata, distance/ascent,
  proposer, and the region's current active routes (count vs cap, overlap
  eyeballing).
- **Decisions**: approve (blocked with an explicit "region full — retire
  first" message when at cap) / reject (with note) / retire an active route
  (frees a slot; requires note). All transitions → `route_change_history`.
- **Curator metadata edit**: registry-driven form on the detail view (same
  field definitions as the proposal form); every field change →
  `route_change_history` (D9). Track replacement is out of scope (v1:
  retire + re-propose).
- **Suggestions**: listed against their route; curator marks done/dismissed
  (and separately edits/retires as warranted).

Config (`route_domain.yaml` or parameters): `region_active_cap` (default 30),
`ride_verify_threshold` (default 3), rate-limit numbers. Config, not
constants — the README threshold principle.

## 7. Community loop (phase 3)

Route drawer (map.js), replacing the dead "Edit this ride" affordance:

- **Vote** — season + bike type picker, POST, one per user/route/season;
  shown only on `verified` routes (D7). Anonymous users see the CTA
  login-gated.
- **"I rode this"** — bike type picker, POST, once per user/route; shown on
  `unverified` + `verified`. At threshold X the route flips to `verified`
  (transition logged).
- **Download GPX** — `GET /routes/{id}.gpx`, public for active routes,
  404 otherwise. Generated from the stored (trimmed) geometry; ODbL
  attribution in the file header.
- **Suggest a correction** — reason preset + note, POST → `route_suggestion`.
- Badges: `unverified` renders "proposed · ride it to verify"; `verified`
  renders normally (and becomes vote-eligible).

All four actions are same-origin POSTs with CSRF tokens (GET-action lesson
from the security review) behind `ROLE_USER` (download excepted).

## 8. Rankings (phase 4)

- Best-of per `(region, season, bike_type)`: rank `verified` routes by vote
  count for that (season, bike type) pair; ties by recency of last vote.
  Computed in SQL at serve time (regions hold ≤ cap routes — no
  materialization needed at this scale).
- The map's Best-of mode consumes these lists for K; "Everything" shows all
  active routes with badges.
- The `/vote` page's hardcoded Routes ballot bucket is retired (pointer to
  the map drawer). Wiki (`curation-and-voting.md`, `data-catalog.md`) and the
  ~20 affected translation keys × 4 locales updated to the propose/vote/
  rode-it vocabulary.

## 9. Serving & API surface

- `CatalogProvider::routes()` adds: `state` (for badges), `suitability`,
  vote/ride counts (per-season/bike-type breakdown deferred to the drawer's
  detail fetch if payload size warrants), `proposedBy` display name only if
  the profile is public (existing uploader rule).
- New endpoints: `POST /routes/{id}/vote`, `POST /routes/{id}/rode-it`,
  `POST /routes/{id}/suggest`, `GET /routes/{id}.gpx`, `GET/POST
  /propose-route`, moderation shell routes under `/moderate/routes`.
- The 2FA-firewall exact-path `PUBLIC_ACCESS` note from phase A applies to
  the GPX download if it's to be cacheable.

## 10. Testing

Each phase lands test-first (TDD), following the existing WebTestCase
patterns (`ImproveBindingTest` style):

- **Intake**: valid GPX round-trip → `submitted` row with trimmed geometry
  (assert the stored track is shorter than the upload at both ends), computed
  distance/ascent, region resolved; each validation rejection path; rate
  limit; `source_ref` uniqueness.
- **Trim determinism**: same upload twice → identical stored geometry.
- **Moderation**: approve/reject/retire transitions + history rows; cap
  blocks approval at limit; retire frees the slot; suggestion resolution.
- **Community**: vote/rode-it uniqueness constraints; threshold flip to
  `verified` exactly at X; vote rejected on `unverified` (D7); GPX download
  content + 404 states; CSRF on all POSTs.
- **Rankings**: per-(region, season, bike type) ordering; handbike list
  excludes non-suitable routes.
- **Regression**: `/improve` keeps refusing `type=K` (already covered by
  `ImproveBindingTest`).

## 11. Delivery

Four phases, each its own implementation plan (writing-plans), sequenced:

1. **Intake + GPX download** (§5, download from §7 — download ships first so
   the map's existing routes gain it immediately).
2. **Desk moderation + cap** (§6).
3. **Community loop** (§7).
4. **Rankings + map surface + copy/wiki reconciliation** (§8, plus the
   translation/wiki sweep).

Spec reconciliation (this doc + `K-quality-rides.md` rewrite + README
carve-out + nine annotation passes) lands with this design, ahead of phase 1.

## 12. Phase-1 execution notes (2026-07-08)

Phase 1 (§11.1) shipped on `symfony-base` (17 commits, 298 tests, all gates
green; final whole-branch review: ready to merge). Decisions made during
execution, binding on later phases:

- **Attribute contract**: proposals store `dominantSurface` (not `surface` —
  the plan's original key contradicted the served-route/registry vocabulary);
  `difficulty` is stored as the rider-vocabulary string with shape-tolerant
  map rendering (imports keep `{score, label}`) — vocabulary harmonization is
  phase-2 registry work; `bikeTypes` is stored as a **list** per D6, with the
  drawer tolerant of both list and legacy string until phase-2 normalization.
- **Limiter semantics (deliberate)**: the 3/day limiter consumes before any
  validation (bounds parse/simplify cost per rider); rate-limited responses
  are flash + 200 (house pattern, matches add-climb) — not 429.
- **Validator i18n**: `framework.validation.translation_domain: messages`
  app-wide; every user-facing constraint carries an explicit message key
  (built-in default messages are no longer relied on anywhere in `src/Form`).

**Phase-2 carry-ins** (recorded residuals, non-blocking):

1. `TrackProcessor::simplify` worst case is still quadratic CPU for
   adversarial >5 m-spaced zigzags (crash-free, authenticated, rate-limited);
   add a hard iteration budget with clean reject.
2. Difficulty vocabulary harmonization (registry 4-label select vs drawer 1–5
   scale vs import `{score,label}`) belongs to the phase-2 curator/registry
   work, together with the D6 `bikeTypes` multi-select normalization. The
   per-segment surface breakdown itself shipped 2026-07-08 as `SurfaceProfiler`
   (A-layer intersect, served as an estimate with disclosed coverage — replaces
   the always-"Unknown" filler row); only harmonizing the declared
   `dominantSurface` vocabulary (Asphalt/Mixed/Gravel) with the A-layer's richer
   surface set remains phase-2.
3. Marginal untranslated edges: File-constraint php.ini-level upload errors,
   CSRF-failure copy, and pre-existing hardcoded-English auth/settings form
   messages (pre-date this work, all locales).

**Phase-2 carry-in extension (2026-07-09):** two of the phase-2 carry-ins above
were extended past their original scope, on request, after phase 2 shipped:

4. **P2-D2 (bikeTypes) extended:** `BikeType` gained three more first-class
   hardware types — `Recumbent`, `Trike`, `Tandem` — alongside the original
   Road/Gravel/MTB/E-bike/Handbike five. The shape stays a `list<BikeType>`
   per D6; `'Any'`'s hardcoded expansion (`Road`/`Gravel`/`MTB`/`E-bike`)
   still excludes all four specialty types, unchanged.
5. **P2-D3 (dominantSurface) extended:** the A-layer's curator-facing
   `surface` choices gained real `Dirt` and `Rock` categories, replacing the
   single vague `Ground` bucket the harvester had also been mislabeling
   `"Unpaved"` (a pre-existing label mismatch between the harvester and
   `SurfaceVocabulary::BUCKETS`, fixed as part of this rename). The
   **declared, rider-facing `dominantSurface` vocabulary stays exactly
   3-way** (Asphalt/Mixed/Gravel, still no migration) — `Dirt`/`Rock` fold
   into the coarse `Gravel` bucket for `SurfaceVocabulary::suggestFromProfile()`,
   same as `Fine gravel` already did. No stored data needed migrating for
   either change: the importer validates attribute *keys*, never *values*
   (confirmed by reading `AttributeVocabulary::assertValid()`), so a renamed
   or added vocabulary string requires no backfill. (Note: because the
   importer's `source_ref` upsert key embeds the surface-label slug, the
   `Unpaved`→`Dirt` rename orphaned the 9 prior `Unpaved` A-layer rows in the
   dev DB rather than overwriting them; they were removed by hand after
   re-import — a pre-existing importer-idempotency gap on label renames, not a
   data-model change.)

## 13. Phase-3 (community loop) design decisions (2026-07-10)

Pre-execution decisions pinning the residuals §7 left open, binding on the
phase-3 implementation plan. Resolved in a brainstorm against the built phase-1/2
state (intake, moderation, GPX download, `RouteSuggestion`/`RouteChangeHistory`
entities, and the `route.ride_verify_threshold`/`region_active_cap` config all
present; `route_vote`/`route_ride` and every rider-facing POST still unbuilt).

- **P3-D1 — verification flip excludes the proposer.** The `unverified →
  verified` flip fires when
  `COUNT(DISTINCT route_ride.user_id WHERE user_id <> recommended_route.proposed_by) >= route.ride_verify_threshold`
  (default 3). The proposer may still log their own "I rode this" — it is stored
  and shown — but it never counts toward their own route's threshold. This is
  the operative reading of §7's "X *independent* riders". `proposed_by` NULL
  (imported routes) degenerates to plain distinct-user counting, but imports
  never enter `unverified` via this machine (§4.3), so the exclusion is a no-op
  there.

- **P3-D2 — the flip is recomputed synchronously in the rode-it handler** and,
  on crossing the threshold, calls the same `RouteModerationService` state
  transition path curators use, writing one `route_change_history` row
  (`field = 'state'`, `unverified → verified`). Because `RouteChangeHistory.changed_by`
  is non-null (`private int`), the community flip attributes `changed_by` to the
  **tipping rider's user id** — the rider whose ride crossed the threshold —
  rather than widening the column to nullable. Factual (their ride caused the
  transition) and schema-neutral.

- **P3-D3 — dynamic community numbers come from a single on-drawer-open fetch,
  not `catalog.json`.** The cacheable bulk payload (phase-A `PUBLIC_ACCESS`
  endpoint) stays unchanged — no ride/vote counts, no per-user state, no
  per-write cache invalidation. Opening a route drawer fires one authenticated,
  uncached `GET /routes/{id}/community` returning
  `{ rideCount, threshold, iRode, voteCount, iVotedThisSeason }`. Per-user state
  (`iRode`, `iVotedThisSeason`) is inherently uncacheable, so the aggregates ride
  along in the same call rather than splitting the fetch. **Aggregate-only in
  phase 3** — the per-`(season, bike_type)` breakdown stays deferred to phase-4
  rankings (§8, §9).

- **P3-D4 — suggest-a-correction gets a dedicated rate limiter; vote and rode-it
  do not.** `vote` (UNIQUE `route_id, user_id, season`) and `rode-it` (UNIQUE
  `route_id, user_id`) are self-bounding per route, so a resubmit is an
  idempotent no-op (flash + 200, house pattern). `route_suggestion` has no
  uniqueness and each pending row is a curator task, so it is the flood vector:
  a `route_suggest` limiter (default **5/day/user**, `sliding_window`, dedicated
  filesystem cache pool swapped to `array` under `when@test` — mirroring the
  `route_propose` limiter in `config/packages/rate_limiter.yaml`). Rate-limited
  suggests are flash + 200, matching intake.

- **P3-D5 — endpoint guards.** All three writes are `ROLE_USER` + same-origin
  CSRF; anonymous users get the existing login-gated CTA. `rode-it` is accepted
  on `unverified` + `verified`; `vote` on `verified` only (D7); `suggest` on any
  active (`unverified` + `verified`) route. Any other state → 404 (routes not in
  `ItemState::SERVED` aren't served, so the drawer never opens for them; the
  guard is defence-in-depth). `GET /routes/{id}/community` requires auth (it
  carries per-user state).

- **P3-D6 — current-season default** is computed server-side from the request
  month on a Northern-hemisphere calendar (the harvested data is Wallonia):
  Spring Mar–May, Summer Jun–Aug, Autumn Sep–Nov, Winter Dec–Feb. The voter may
  override the season in the vote picker; the default only pre-selects it.

- **P3-D7 — drawer becomes a community panel.** The current lone "⤓ Download
  GPX" action block (`map.js`) is replaced by: rode-it button (→ "✓ You rode
  this" when `iRode`) with progress copy ("2 of 3 rides to verify") on
  `unverified`; season+bike vote picker (→ "✓ Voted this season") on `verified`;
  suggest-a-correction reason+note form; and the retained GPX download. The
  existing `unverified` badge (§7, `map.js:609`) stays.

**Phase-4 carry-in (recorded):** the `/routes/{id}/community` response is
aggregate-only by design (P3-D3). Phase 4's best-of ranking needs the
per-`(season, bike_type)` vote breakdown; it will extend this endpoint (or add a
ranking query) rather than change phase-3's contract.

### Phase-3 execution note (2026-07-10)

Phase 3 (§7 community loop) shipped on `symfony-base` (10 commits,
`869d257..<spec-note>`; base `2c75431`). Full gate green: **382 tests / 1683
assertions**, phpstan + psalm + php-cs-fixer + SPDX + licenses + translation
parity all clean. Dev DB migrated (`route_ride`, `route_vote`). Delivered per
P3-D1…D7: `Season` enum; `route_ride`/`route_vote` tables; `RouteCommunityService`
(`snapshot` + `recordRide`/`recordVote`/`recordSuggestion`);
`RouteCommunityController` (`GET /routes/{id}/community` + `POST .../rode-it|vote|
suggest`); the `route_suggest` limiter; the drawer community panel.

Confirmed as specified: the verification flip counts distinct riders **excluding
the proposer** and fires exactly at the threshold (traced at all boundaries in
review); `catalog.json`/`CatalogProvider` is **byte-unchanged** (P3-D3 — all
dynamic/per-user data comes from the new authenticated endpoint); anonymous API
hits return a clean **401** (in-controller `requireUser()`, not a login redirect).

Decisions/fixes during execution (binding on later phases):

1. **Auth = in-controller 401, not `#[IsGranted]`.** The community endpoints are
   a JSON API, so `requireUser()` throws a 401 (`isGranted('ROLE_USER')` also
   excludes 2FA-in-progress) rather than letting the form-login firewall
   302-redirect a fetch client. No `access_control` entry (lazy firewall).
2. **CSRF via one stateless token.** `route-community` added to
   `stateless_token_ids`; the `GET /community` response carries the token the
   three POSTs reuse (`_token`) — same cookie-tied mechanism the map moderation
   flow uses.
3. **HTTP enum values are backing values.** Ride/vote `bike_type` must be the
   `BikeType` backing strings (`MTB`, `E-bike`, …), not case names; season the
   `Season` backing strings.
4. **Rate-limiter tests can't loop HTTP.** The `route_suggest`/`route_propose`
   test cache pools are `ArrayAdapter` tagged `kernel.reset` and are wiped on
   each top-level request boot, so a limiter counter cannot accumulate across
   separate `WebTestCase` HTTP calls. Exhaust the limit via direct service
   calls, then one HTTP request for the over-limit case (the
   `RouteProposalServiceTest` pattern). Production uses the filesystem pool —
   unaffected.
5. **Drawer `state` plumbing.** The served route feature object had to carry
   `state` (`state: r.state` from `catalog.json`, which `CatalogProvider`
   already serves) for the panel's vote-block/progress gating; it was previously
   only used transiently.

**Phase-3 fast-follows (recorded, non-blocking):** (a) the anonymous drawer mute
is CSS-only (`pointer-events:none`), not the DOM `disabled` attribute — a
keyboard/AT polish gap (the POST no-ops without a token, so non-exploitable);
(b) a rode-it flip to `verified` toasts + updates `dataset.state` but doesn't
inject the vote block into the live DOM (needs a drawer reopen); (c) the flip's
`route_change_history` attribution to the tipping rider — now asserted on
`changed_by` in the flip test (closed in the final-review pass); (d) two
simultaneous threshold-crossing rode-it POSTs can each log a `route_change_history`
`state` row (the flip has no lock/guard) — audit-log noise only, the state stays
correctly `verified` (idempotent `setState`); add a transition guard if the
history desk ever surfaces duplicates.

## 14. Phase-4 (rankings / best-of) design decisions (2026-07-10)

Pre-execution decisions pinning §8, resolved in a brainstorm against the shipped
phase-3 state (route_vote table + typed votes live; the map's Curated mode serves
K routes with `cur:false` hardcoded — i.e. Curated shows *no* routes today, so §8
is what makes best-of real). One combined plan covers the ranking feature **and**
the `/vote`-page + wiki + translation reconciliation sweep.

- **P4-D1 — full faceting via a dedicated endpoint.** `GET /map/best-of?season=<s>&bike=<b>[&region=<id>]`
  (public, same access tier as `catalog.json`; `season` defaults to the current
  Northern-hemisphere season, `bike` defaults to `all`). Returns the ranked list
  of route ids (+ rank) for that facet. `catalog.json`/`CatalogProvider` stays
  **unchanged** (it already serves every active K route with `state`); the map's
  Curated mode calls this endpoint and flags the returned routes `cur:true`
  (ordered), hiding the rest. Same "don't inflate the cached bulk payload"
  principle as P3-D3.

- **P4-D2 — ranking SQL (serve-time, no materialization — regions hold ≤ cap
  routes).** Candidates = `verified` routes (region-scoped when `region` given).
  Match count = `route_vote` rows for the facet: a specific bike counts votes
  `WHERE season=:s AND bike_type=:b`; `bike=all` counts all votes `WHERE season=:s`
  regardless of bike_type. `ORDER BY match_count DESC, MAX(route_vote.created_at)
  DESC` (ties broken by most-recent matching vote).

- **P4-D3 — best-of lists only *voted* routes (≥1 matching vote).** A verified
  route with zero matching votes for the facet does NOT appear in Curated (it
  still shows in "Everything"). Curated renders a friendly empty state when a
  facet has no picks yet ("No <Season> · <Bike> picks yet — vote to surface
  one"). Keeps "best-of" meaningful.

- **P4-D4 — the declared-suitability gate covers the four specialty types.**
  Best-of for `Handbike`, `Recumbent`, `Trike`, or `Tandem` additionally requires
  the route's `attributes.bikeTypes` to contain that type (a physical-fit safety
  gate — extends goal 9's handbike rule to the physically-similar hardware,
  consistent with `'Any'` already excluding these). The general types
  (`Road/Gravel/MTB/E-bike`) and `all` rank by matching votes alone — a vote is
  itself the signal, declared suitability does not filter them.

- **P4-D5 — map Curated mode gains season + bike pickers** (default current
  season / All bikes). Changing a facet re-fetches and re-flags `cur`; the
  hardcoded "Curated best-of · Summer 2026" subtitle becomes dynamic
  (`<Season> · <Bike>`). "Everything" mode is unchanged (all active routes with
  badges).

- **P4-D6 — reconciliation sweep (same plan).** Retire the `/vote` page's stubbed
  **routes** ballot tab (routes now vote via the map drawer, phase 3) — replace
  it with a pointer to the map; leave the other category tabs and the broader
  Vote-nav decision untouched (out of scope). Update the wiki
  (`curation-and-voting.md`, `data-catalog.md`) and the ~20 affected translation
  keys × 4 locales to the propose / vote / rode-it vocabulary (strict parity gate).

### Phase-4 execution note (2026-07-10)

Phase 4 (§8 rankings + reconciliation sweep) shipped on `symfony-base` (base
`7576819`; 8 implementation commits + this note). **No migrations** — pure serving
+ UI + copy over the existing `route_vote`/`recommended_route` tables. Full gate
green: **391 tests / 1704 assertions**, phpstan + psalm + php-cs-fixer + SPDX +
licenses + translation parity (1174 keys) all clean. Delivered per P4-D1…D6:
`RouteRankingService` (facet-ranking SQL) + `BikeType::isSpecialty()`; public
`GET /map/best-of`; the map's Curated season/bike pickers + `cur` filtering +
dynamic subtitle + empty state; the `/vote` routes-tab retirement; the wiki +
translation sweep.

Confirmed as specified: `catalog.json`/`CatalogProvider` **byte-unchanged**
(P4-D1 — best-of is a separate endpoint); the ranking SQL excludes zero-vote
routes and gates the four specialty types by declared `bikeTypes` (P4-D3/D4,
verified in `RouteRankingServiceTest`).

Decisions/fixes during execution:

1. **The K line layer had to start honouring `cur`.** K (`experience`) is
   `exp:false`, so before phase 4 `cur` was inert for routes and every route drew
   in both modes. Curated filtering required a K-specific branch
   (`layer.key==='experience' ? (mode==='all'||f.cur) : …`) in **both** the render
   loop **and** `featureVisible()` — the latter feeds the per-layer legend count,
   which would otherwise report the wrong "shown" total (caught in review; the
   count now reads 0/N in an empty Curated, N/N in Everything).
2. **The stray `/vote` "Vote in this round" CTA** (`map.js`, gated on `f.cur`) was
   dead for routes only because routes were always `cur:false`; once best-of flags
   them `cur:true` it would surface next to the drawer's real community panel, so
   it is suppressed for the `experience` layer.
3. **No region-id state exists in the map JS** (single hardcoded Wallonia), so the
   map omits `region` from the best-of call; the endpoint still accepts it for a
   future multi-region UI.
4. **Rail is dark** (`--ink`) — the facet pickers use the dark-surface field
   tokens (cream field / ink text), matching the drawer's `.cc-rc select`.
5. A **test-only DI override** was briefly needed for `RouteRankingService`
   (unconsumed private services are container-pruned in the test env); removed once
   `MapController::bestOf` gave it a real consumer.

**Data note:** no served route declares `bikeTypes` and there are no verified/voted
routes in the harvested set yet, so best-of lists (especially specialty) are
legitimately empty until rider proposals + votes accumulate — the empty state
(P4-D3) is the expected view meanwhile; the ranking itself is proven by the
integration tests against seeded data.

**Phase-4 fast-follows (recorded, non-blocking):** (a) the best-of response is
consumed membership-only by the map (`applyBestOf`) — the SQL `ORDER BY` rank is
latent until a ranked-list UI honours it (and the response encodes rank by array
position, no explicit `rank` field yet); (b) the "no materialization — regions
hold ≤ cap" guarantee (P4-D2) only holds *with* a `region` filter; the map omits
`region` (single Wallonia region), so today's query aggregates across all regions
with no `LIMIT` — when the multi-region UI lands, either require `region` or add a
top-N cap; (c) the map-shell chrome (season/bike labels, the dynamic subtitle) is
hardcoded English, consistent with the pre-existing untranslated map shell — map
i18n stays on the backlog.

## 15. Proposal / edit form UX refinements (2026-07-11)

Post-phase-4 clarity passes on the route proposal + curator edit forms (rider
feedback). Not a phase — small, ongoing UI/vocabulary fixes recorded here.

- **`gradientLimited` reworded, values unchanged.** The field read "Gradient-
  limited?" with options `No / ≤6% / ≤9%`, which was ambiguous about direction.
  Now labelled **"Gradient cap (accessibility)"** with display options **"No cap /
  Whole route ≤ 6% / Whole route ≤ 9%"** + a hint ("only set a cap if the whole
  route stays under it — for riders who need gentle gradients, e.g. handbikes").
  The **stored values stay `No`/`≤6%`/`≤9%`** — display-only change, no migration.
- **"Suitable bike types" is *designed-for*, not *doable*.** Confirmed semantic:
  a route rideable on MTB but built for road excludes MTB. A hint now states it
  ("the bike types this route is designed for — not just what can physically ride
  it"), consistent with best-of's specialty-type suitability gate (P4-D4).
- **`season` becomes a multi-select; "Any" retired.** "Best season" was a single
  select including "Any"; it is now a **multi-select** (Spring/Summer/Autumn/
  Winter, no "Any" — selecting all four is the new "any"), mirroring the
  `bikeTypes` multi-select shape (phase-2 P2-D2). Stored as a `list<string>` in
  `attributes.season`; the drawer + curator edit tolerate the legacy scalar
  string that imported/harvested routes still carry (no backfill), exactly as
  `bikeTypes` does.
- **Form dropdowns no longer stretch full-width** (`.field select` capped at
  `max-width: 22rem` across the proposal, curator-edit-adjacent, improve,
  add-climb, and settings forms).
- **GPX upload limit raised 2 MB → 15 MB.** `GpxParser::MAX_BYTES`, the
  `ProposeRouteType` `File` maxSize, and the copy all move to 15 MB, and the app
  container's php `upload_max_filesize`/`post_max_size` are lifted above the
  defaults (16M/20M in `web/Dockerfile` — **needs a container rebuild to take
  effect in dev**). GPX XML is verbose (extensions, metadata), so a larger byte
  ceiling mostly admits richer files; **only the simplified lat/lng track is ever
  stored** (trim → simplify before persist), so DB size is unaffected. The
  `≤ 50 000` pre-simplification point cap is **unchanged** — very dense tracks are
  still rejected by density, not size.
- **`dominantSurface` now offers the full surface vocabulary** (Asphalt,
  Concrete, Paving stones, Sett — pavé, Compacted, Fine gravel, Gravel, Dirt,
  Rock) instead of the coarse Asphalt/Mixed/Gravel — **superseding P2-D3's
  "rider-facing vocabulary stays 3-way."** The list is now the shared
  `SurfaceVocabulary::DECLARABLE` constant (one source for the A-layer curator
  `surface` field and the route `dominantSurface` field). `SurfaceVocabulary::BUCKETS`
  still folds these to the coarse buckets for the measured-vs-declared
  reconciliation hint, so that hint now compares a coarse suggestion against a
  specific declaration (a known minor mismatch — aligning the hint to the
  specific vocabulary is a recorded follow-up). Legacy `Mixed` values from the
  old vocabulary display as-is; no backfill (the importer validates attribute
  keys, not values).

## 16. Located corrections — segments on a route_suggestion (design, 2026-07-11, approved)

Extends §7's "suggest a correction": a rider can mark **which stretch(es)** of
the route their correction is about, and the curator sees those stretches on the
map. Brainstormed + **user-approved**; V1 scope below. Builds on the phase-3
`route_suggestion` table + the phase-4 `?route=<id>` map deep-link.

### Decisions (S1–S5)

- **S1 — pick by clicking the route line.** The drawer's "Suggest a correction"
  gains a **"Mark the part(s) on the map"** button: it minimises the drawer,
  highlights the route, and enters a picking mode where each click on the route
  line drops a numbered marker **snapped to the nearest point on the line**.
  Consecutive points pair into stretches (1→2, 3→4, …). A small toolbar: **Undo**
  (drop last point) · **Clear** · **Done**. Done returns to the form; **Send**
  posts `reason` + `note` + the marked stretches. Reason stays required; **marking
  is optional** (0 stretches = a general comment, still valid).

- **S2 — moderator sees ALL pending corrections on the route.** Not just the one
  clicked — the map shows every unresolved correction's stretches at once.

- **S3 — colour per correction + a side list.** Each pending correction renders in
  its own colour, stretches drawn in that colour with numbered endpoints; a side
  panel lists the corrections (colour swatch · reason · note) and clicking one
  zooms/focuses its stretches. **Curator-gated**, **pending** corrections only.

- **S4 — stored as along-route fractions.** `route_suggestion.segments` (jsonb,
  nullable) = `list<{start: float, end: float}>`, each value a position **0–1
  along the served (trimmed, simplified) route geometry**. Fractions (not raw
  lat/lng) so the map can both place the numbered markers (interpolate along the
  geometry) and highlight the stretch between them (slice the geometry between the
  two fractions). Assumes the stored geometry is stable — true in v1 (no curator
  track replacement).

- **S5 — the moderator view rides the existing `?route=<id>` deep-link.** When the
  viewer is a curator, the map fetches the route's pending corrections + segments
  from a **curator-only endpoint** and renders S3; non-curators get nothing extra.
  (The moderation desk's "Open on the map" link already points at `?route=<id>`.)

### V1 scope / additions

- **In:** rider marking (snapped click-to-pick, multiple stretches, undo/clear);
  optional segments on `route_suggestion`; curator-only pending-corrections
  endpoint; colour-per-correction map render + side list + click-to-focus; a
  "N stretches" indicator on the pending-corrections desk row.
- **Out (recorded future work):** curator editing/drawing segments; showing
  **resolved** corrections' segments (history); GPX-proof; segment robustness
  across a future curator track replacement (fractions would need re-projection).

### Execution note (2026-07-12)

Shipped on `symfony-base` (6 tasks + review fixes, `e8007c4..<spec-note>`). Full
gate green: **397 tests / 1721 assertions**, phpstan + psalm + php-cs-fixer +
SPDX + licenses + translation parity (1179 keys) all clean. Dev DB migrated
(`route_suggestion.segments`). Delivered S1–S5 + the "N stretches" desk indicator;
`catalog.json`/`CatalogProvider` byte-unchanged (corrections are a separate
curator-only endpoint). Live-verified: rider marks stretches on the route line
(snapped, numbered, pairs, undo/clear/done) and Send persists them; curator
opening `/map?route=<id>` sees all pending corrections colour-per-correction with
numbered endpoints + a side list (click-to-focus); non-curators see nothing.

Decisions/fixes during execution:

1. **Segments stored as JSONB objects `{start,end}`; Postgres reorders the keys.**
   `{start,end}` round-trips as `{end,start}` (values correct, key order not
   preserved) — consumers access by key so it's transparent, but **tests must
   assert values order-agnostically** (extract `[start,end]` or ksort), not
   `assertSame` on the raw structure.
2. **Segment order is normalized client-side (S1 fix).** A rider can click a
   stretch's two endpoints in either order; `pickSegments()` now emits
   `start = min, end = max` (matching `sliceByFrac`'s swap), so the server's
   `0 ≤ start ≤ end ≤ 1` validation never 422s on a reverse-order pick.
3. **The picking mode guards the drawer-open.** Clicking the route line while
   `_pick` is active must NOT open/switch the drawer (the layer click handler
   would otherwise fire alongside the point-drop) — guarded via an early return
   in `openDrawer` when `_pick` is set; `startPicking` has a re-entrancy guard and
   the minimised drawer is `pointer-events:none`; `closeDrawer` cancels an
   in-progress pick and calls `clearCorrections()`.
4. **The moderator overlay rides the phase-4 `?route=<id>` deep-link** and a new
   curator-only `GET /routes/{id}/corrections` (pending only). A non-403 fetch
   error would not clear a prior overlay (cleanup only runs on success) — a
   non-issue today since `openRouteById` fires once per page load; harden if
   route-switching-without-reload is ever added.
5. **Final-review fix: the route-path trim is now seeded from `r.id` (not the
   list index `i`)**, so `f.geom.path` stays deterministic per route for the
   life of the geometry and stored correction fractions stay stable when the
   served route set changes (e.g. another route rejected shifting indices).

## 17. Correction outcomes — rider notifications, moderator messaging, retention (design, 2026-07-12)

User-approved direction, recorded ahead of implementation. **NOT built — no plan
yet; specs only.** Extends §16: what happens *after* a curator resolves a
correction, and how the platform talks back to the rider who filed it.

### Decisions (N1–N7)

- **N1 — outcome notifications on the rider's dashboard.** Resolving a
  correction notifies the rider who submitted it, by appending a message to
  their dashboard: **Dismiss** → a message informing them of the dismissal;
  **Done** (approved/actioned) → a **thank-you** message. Same mechanism for
  both outcomes.
- **N2 — a new message class/entity** carries these (nothing like it exists in
  the app yet — this is new infrastructure, not an extension of an existing
  table). Shape (to be finalised in the plan): recipient user id, kind
  (`correction_dismissed` / `correction_done` / `curator_message` / …), body,
  sender (system vs a curator id), related refs (route id, suggestion id),
  `created_at`, `read_at`. The account dashboard gains a **Messages** surface
  listing them.
- **N3 — notification bulb in the header.** An unread-messages indicator near
  the account chip (every logged-in surface); clicking it goes to the user's
  Messages.
- **N4 — moderator → rider messaging.** The Routes desk gains a way for a
  curator to send the rider a **personal message**: to request more
  information about a correction, or to add a personal note accompanying a
  dismissal/approval. Lands in the same Messages channel (kind
  `curator_message`, sender = the curator).
- **N5 — email is a later delivery channel (v2).** The same outcomes will
  additionally send an email; the dashboard message records stay the source of
  truth — email is added on top, not instead.
- **N6 — dismissed corrections are retained 3 months, then garbage-collected.**
  A dismissed `route_suggestion` remains in the system for 3 months (context
  for appeals/patterns), after which an **automatic garbage collector** (a
  scheduled command) removes it.
- **N7 — a dedicated Trash action for spam/abuse.** Distinct from Dismiss: for
  spam or worse, the curator can **Trash** a correction — the record (its text
  and any future attachments/images) is **deleted immediately and
  permanently**; no 3-month retention, because we do not want to keep such
  content on the system at all. Trash sends no thank-you/dismissal message.

### Open points (deliberately left to the implementation plan)

- Retention for **Done** rows (N6 specifies dismissed only; done rows currently
  live forever — decide whether they join the GC).
- Whether the message content is i18n'd (per-recipient locale) or plain text as
  written by the curator/system.
- Bulb-count semantics (unread only vs total) and read-marking UX.
- Message retention/expiry (do old read messages ever get GC'd?).
- Whether Trash notifies the submitter at all (likely not — don't feed spam).

### Relation to the item pipeline

The item (A–J) moderation pipeline has adjacent machinery (decision notes, a
`needs_info` decision, receipts under "my contributions") but **no user-facing
notification/messaging system**. A reconciliation analysis of what the item
side already covers, what it lacks, and what it does better precedes any item-
spec update (user decides the item-side direction after reviewing it).

### Reconciliation outcome (2026-07-12, user-approved)

The sweep ran; the user approved generalising N1–N7 into **one shared system for
all contribution channels**, now owned by
[`2026-07-12-moderation-feedback-and-messages-design.md`](2026-07-12-moderation-feedback-and-messages-design.md)
(M1–M12). That document supersedes this section as the system design; §17
remains the route-side origin record. Route-side scope changes it brings:

- **Route-proposal decisions are now in scope too** (approve/reject/retire write
  the proposer a message — today the curator's note never reaches them at all),
  not just corrections.
- N4's curator messaging gains a **rider reply path** (via the item side's
  needs-info loop, generalised).
- N6's GC runner: phase 1 is lazy filtering + an opportunistic sweep (no new
  infra); a real scheduler arrives with N5's email queue (one messenger/scheduler
  investment for both).
- N7 Trash adopts the admin desk's audit-before-delete + POST/CSRF/guardrail
  hardening; GDPR message cleanup is DB `ON DELETE CASCADE` (the deletion-hook
  path is bypassed by admin account removal — verified asymmetry).
- The **map page must gain the account chip (or a minimal authenticated
  indicator wired to the same partial)** so the N3 bulb reaches the map (today
  `/map` renders no chip and links Account → login even when signed in).

## 18. Moderation-desk review fixes (2026-07-12 frontend-review warnings)

Small correctness/i18n/a11y fixes to the Routes desk from the 2026-07-12
frontend review's warning batch (the criticals live in
[`2026-07-12-frontend-review-criticals-fixes.md`](2026-07-12-frontend-review-criticals-fixes.md)):

- **W55 — edit form CSRF unified.** The detail page's `route_edit` form used
  `csrf_protection: false` plus a hand-rendered top-level `_token` input that
  `edit()` validated manually. The form now uses its own CSRF (default token id
  = form name, rendered by `form_end()` as `route_edit[_token]`); `edit()`
  validates that scoped token and the manual hidden input is gone.
- **W56 — no auto-submit filter (WCAG 3.2.2).** The region `<select>` lost its
  `onchange="this.form.submit()"`; the previously noscript-only Apply button is
  now always shown.
- **W29/W54 — i18n.** `… m ascent` became `moderate_routes.stat_ascent`
  (`%m%` param), and the raw `route.state` enum on detail now renders through
  the existing `account.route_state_*` keys.
