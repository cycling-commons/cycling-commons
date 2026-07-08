# Edit spec — K · Quality rides (recommended routes)

- **Catalog layer:** K · Quality rides
- **Map depiction:** line, icon ★, colour #FF5A1F (brand orange); `unverified` routes carry a **"proposed"** badge
- **Editable:** **no — curator-only.** K is the deliberate exception to the every-type-has-an-edit-flow rule: a route is a *curated composition*, not an atomic map feature. Riders **propose**, **vote**, **confirm rides**, **download GPX**, and **suggest corrections** — they never edit route data. Design source of truth: [`../2026-07-08-route-domain-design.md`](../2026-07-08-route-domain-design.md).
- **Lifecycle:** route-specific state machine (NOT the shared item funnel): `submitted` (rider proposal) → curator desk approval → `unverified` ("proposed" on the map) → X independent **"I rode this"** confirmations → `verified` (votable) · plus `rejected` and `retired`. A configurable **per-region cap (~30 active routes)** bounds supply; a full region admits a new route only by retiring a weaker one.

## What it is

Curated ride recommendations — a ridden track plus editorial metadata
(difficulty, season, surface, note, bike-type suitability, accessibility).
Supply is rider-*seeded* (GPX proposal) but curator-*owned*: curators approve,
edit, and retire; the community's power is the **vote** (typed by season and
bike type) and the **ride-confirmation**, not the upload.

The historical model — "contributed GPX loops" editable via
`improve.html?item=ride` — is retired. It was mechanically broken (the
route-id/item-id collision, 2026-07-07 security review critical #1; `/improve`
now refuses `type=K`) and conceptually wrong (rider-editable route data is how
a region drowns in 1000+ unvetted routes).

## How a route is born

1. **Propose** (`/propose-route`, ROLE_USER, rate-limited): GPX upload +
   metadata form (the registry fields below). Server-side: strict GPX
   validation, **privacy trim** (below), simplification, distance/ascent
   computation, region resolution → `RecommendedRoute` state `submitted`.
2. **Desk review** (Routes queue in the moderation shell, ROLE_CURATOR):
   approve (cap permitting) / reject / retire-to-make-room.
3. **Ride-verification**: X riders (config, default 3) click "I rode this" →
   `verified`. v2 (future): optional GPX-proof — an uploaded ride is matched
   against sample points and **deleted immediately** after the check.
4. **Votes** (only on `verified` routes): "I recommend this as a
   [season] ride on [bike type]" — one per user per route per season.
   Best-of lists rank per (region, season, bike type).

## Location & search

A ride needs **no pin** — the (trimmed) GPX track sets the whole route.
"Starts at / towns on route" reverse-geocoding from the track remains future
work (route-domain spec §2 non-goals).

## Privacy — trim the ends

Unchanged commitment, now enforced at proposal ingest: the **first and last
~350–750 m of every proposed ride are dropped deterministically before
anything persists** — the untrimmed upload never touches storage, so a route
never reveals where its proposer started or finished. The drawer states this
("First & last ~N m trimmed", `[k-anon]`).

## Read view (drawer "current details")

- **Difficulty** — 1–5 scale rendering unchanged (climb-purple circles).
- Distance · Season · **Suitability by bike type** — Road / Gravel / MTB /
  E-bike / **Handbike** (handbike is a first-class type in votes and
  rankings) · Accessibility (gradient-limited) · Best direction.
- **Surfaces** — estimated from the A-layer mapped roads (`[auto]`, coverage
  disclosed) · declared **Dominant surface** when set.
- **State badge** — "proposed · ride it to verify" on `unverified`; verified
  routes render normally and are vote-eligible.
- Vote and ride counts.
- All metadata is curator-owned (`[curator]` provenance); rider signal shows
  as vote/ride counts, not editable fields.

## Rider actions (replaces the edit form)

| Action | Who | Effect |
|---|---|---|
| **Vote** (season + bike type) | ROLE_USER, `verified` routes only | `route_vote` row; unique per user/route/season |
| **"I rode this"** (bike type) | ROLE_USER, active routes | `route_ride` row; at threshold X → `verified` |
| **Download GPX** | public, active routes | `GET /routes/{id}.gpx` from the stored trimmed track |
| **Suggest a correction** | ROLE_USER | preset reason (wrong/broken track · trim a private start/end · duplicate · not actually rideable · other) + note → moderated `route_suggestion` |

## Curator form (proposal review + metadata edit)

The registry fields (proposal form and curator edit share them):

| Field | Control |
|---|---|
| Ride name | input |
| Difficulty | select (Gentle/Moderate/Hard/Very hard) |
| Best season | select (Spring/Summer/Autumn/Winter/Any) |
| Dominant surface | select (Asphalt/Mixed/Gravel) |
| Note for riders | textarea |
| Suitable bike types | multi-select (Road/Gravel/MTB/E-bike/Handbike) |
| Gradient-limited? | select (No/≤6%/≤9%) |
| Best direction | select (Clockwise/Counter-clockwise/Either) |

Every curator field change writes an append-only `route_change_history` row
(same provenance principle as item `change_history`, route-scoped table).
Track replacement is v1-out-of-scope: retire + re-propose.

## Implementation

- **Production (Symfony):** `RecommendedRoute` entity + purpose-built
  `route_vote` / `route_ride` / `route_suggestion` / `route_change_history`
  tables. GPX parse, trim, simplify, distance/ascent all in PHP (light
  tabular math — no Python pipeline involvement). The item
  `Submission`/`ModerationService` pipeline is **not** used for routes.
- **Demo-era artifacts** (shared `ride` registry entry in
  `atlas/demo/edit-items.js`, the six fixture loops) are historical; the
  Symfony `CatalogFormRegistry::for(QualityRides)` field set now backs the
  proposal + curator forms, not a rider improve form.
