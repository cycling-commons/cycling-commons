<!-- SPDX-License-Identifier: AGPL-3.0-only -->

# Edit spec — R · Quality rides (recommended routes)

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

- **Catalog layer:** R · Quality rides
- **Map depiction:** line, icon ★, colour #FF5A1F (brand orange); `unverified` routes carry a **"proposed"** badge
- **Editable:** **no, curator-only.** R is the deliberate exception to the every-type-has-an-edit-flow rule: a route is a *curated composition*, not an atomic map feature. Once a route is proposed nobody edits it but a curator, on the Routes desk. Riders **propose**, **vote**, **confirm rides**, **download GPX**, **add photos** (`/propose-route?route=<id>`, a moderated photo correction, [`../route-domain.md`](../route-domain.md) §4.5) and **ask for a detail to be corrected** from the drawer's correction box (§7.1), which a curator then applies. Design source of truth: [`../route-domain.md`](../route-domain.md).
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
now refuses `type=R`) and conceptually wrong (rider-editable route data is how
a region drowns in 1000+ unvetted routes).

## How a route is born

1. **Propose** (`/propose-route`, ROLE_USER, rate-limited): GPX upload +
   metadata form (the registry fields below). Server-side: strict GPX
   validation, **privacy trim** (below), simplification, distance/ascent
   computation, region resolution → `RecommendedRoute` state `submitted`.
2. **Desk review** (Routes queue in the moderation shell, ROLE_CURATOR):
   approve (cap permitting) / reject / retire-to-make-room.
3. **Ride-verification**: X *independent* riders (config, default 3) click "I
   rode this" → `verified`. Independent means distinct users **other than the
   proposer** — a proposer's own "I rode this" is recorded and shown but never
   counts toward their own route's threshold. v2 (future): optional GPX-proof —
   an uploaded ride is matched against sample points and **deleted immediately**
   after the check.
4. **Votes** (only on `verified` routes): "I recommend this as a
   [season] ride on [bike type]" — one per user per route per season.
   Best-of lists rank per (region, season, bike type).

## Location & search

A ride needs **no pin** — the (trimmed) GPX track sets the whole route.
"Starts at / towns on route" reverse-geocoding from the track remains future
work ([`../route-domain.md`](../route-domain.md) §11 — specified, pending
implementation).

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

**The map offers a rider no edit affordance for R.** The route drawer draws no
"Edit this item" and no per-field "＋ add" row
(`IMPROVABLE_LETTER` in `drawer.js` `schemaRows()`, map-and-search.md §6.2),
and a route's photo prompt goes to `/propose-route?route=<id>`
(`add-photo.js`). Until 2026-09-16 the empty registry rows DID draw "＋ add",
pointing at `/improve?item=<route id>&type=R&field=<name>`; all eight landed
on the no-target page, because `/improve` refuses `type=R` (above).
Owner-reported on route 111's Gradient-limited row. An old link of that shape
now says "Routes are not edited here" rather than "Pick a place to improve"
(moderation-and-contribution.md §1.4).

**A route's metadata is set on the desk.** The curator form in the
next-but-one section carries all eight fields, so every one of them can be
changed after a route is proposed. `RouteModerateController::detail()` builds
it from `RouteEditType`, whose widgets come from `RouteMetadataFields`, the
same definitions the proposal form builds from, so a curator's value carries
the proposal form's wording, options and validation. A rider asks for a
detail to be corrected from the drawer's correction box; marking it done
applies it through this same gate, credited to the rider who asked (§7.1).

## Rider actions (replaces the edit form)

| Action | Who | Effect |
|---|---|---|
| **Vote** (season + bike type) | ROLE_USER, `verified` routes only | `route_vote` row; unique per user/route/season |
| **"I rode this"** (bike type) | ROLE_USER, active routes | `route_ride` row; at threshold X → `verified` |
| **Download GPX** | public, active routes | `GET /routes/{id}.gpx` from the stored trimmed track |
| **Suggest a correction** | ROLE_USER | preset reason (wrong/broken track · trim a private start/end · duplicate · not actually rideable · other) + note, optionally locating the affected stretch(es) on the map ([`../route-domain.md`](../route-domain.md) §7) → moderated `route_suggestion` |

Proposal decisions and correction resolutions feed the shared moderation-feedback
system ([`../moderation-and-contribution.md`](../moderation-and-contribution.md) §7):
the rider gets a dashboard message on approve/reject/retire and on done/dismissed,
dismissed corrections are retained 3 months then GC'd, and spam can be Trashed
(immediate hard delete).

## Curator form (proposal review + metadata edit)

The registry fields (proposal form and curator edit share them; canonical
vocabularies and stored shapes: [`../route-domain.md`](../route-domain.md) §9).
`RouteMetadata` is the one definition of the set (which fields exist, each
one's vocabulary, and the shape its value is stored in), read by the proposal
form, the curator form and the drawer's field schema alike:

| Field | Control |
|---|---|
| Ride name | input |
| Difficulty | select (Easy/Moderate/Challenging/Hard/Very hard) |
| Best season | multi-select (Spring/Summer/Autumn/Winter) — no "Any"; all four selected is the "any" |
| Dominant surface | select — the 9-value `SurfaceVocabulary::DECLARABLE` set (Asphalt/Concrete/Paving stones/Sett — pavé/Compacted/Fine gravel/Gravel/Dirt/Rock) |
| Note for riders | textarea |
| Suitable bike types | multi-select — all 8 `BikeType` values (Road/Gravel/MTB/E-bike/Handbike/Recumbent/Trike/Tandem) |
| Gradient-limited? | select (No/≤6%/≤9%) |
| Best direction | select (Clockwise/Counter-clockwise/Either) |

Both forms (the first proposal and the curator's desk form) offer the same
eight fields. The only difference the rules force is what is required: a first
proposal asks for a difficulty and a dominant surface, the desk form takes the
route as it is. A field with
no value shows empty rather than a guessed default, and saving it empty
**clears the attribute**: an absent key, never a blank, so "nobody has said"
and "somebody said nothing" stay one state.

`RouteMetadata::canonical()` is the single intake gate for both forms: it
canonicalizes the value (difficulty to `{score,label}`, bike types
deduplicated) and refuses anything outside the vocabulary, so a value a
curator sets is indistinguishable from one the proposer set. The registry is
also the whole editable surface: `editMetadata()` rejects any field outside
it, so no request can write an arbitrary key into `attributes`.

Every curator field change writes an append-only `route_change_history` row
with the curator as its author (same provenance principle as item
`change_history`, route-scoped table); a rename files under `name`, a cleared
field records its old value against a null. A field that comes back unchanged
is never snapshotted. Track replacement is v1-out-of-scope: retire +
re-propose.

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
