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
   ClimbGeometry findings): file ≤ 2 MB; ≥ 2 track points, ≤ 50 000 points
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
