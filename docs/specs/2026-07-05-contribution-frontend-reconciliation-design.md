> **Consolidated into** edit-items/README.md (P1-P4 registry-derivation contract, W5 visible change history, W6 uniform provenance, D4 ratings-deferred rationale), the per-type edit-items/ docs (D2 vocabulary sync) and catalog-data-model.md (ItemSource::Manual seeding rule) **(2026-07-16).** This dated working doc is sweepable; the canonical docs above are the source of truth.

# Contribution Front-End Reconciliation — Design (Phase C)

> **Status:** Approved 2026-07-05 (decisions D1–D4 recorded in §5). W1/W2/W3/W5/W6 shipped 2026-07-05 (see §8). W4 + ratings/reviews deferred.
> **Date:** 2026-07-05
> **Branch target:** `symfony-base`
> **Predecessors:** Phase A (catalog data model + Wallonia import), Phase B (submissions & moderation on real data — the intake→queue→moderate→apply-with-history *engine*).

> **Route-domain carve-out (2026-07-08):** Recommended routes (K) have left the item contribution/edit model this spec assumes. Per the [route-domain design](2026-07-08-route-domain-design.md), a route is a curator-owned `recommended_route` composition — riders never edit route data; supply arrives as GPX proposals (state `submitted`) through a dedicated Routes moderation queue, and community signal arrives as typed seasonal votes, "I rode this" confirmations, and moderated suggestions. The K improve-form fields W2 shipped (C2-T7) are retired and `/improve` now refuses `type=K`. Everything item-scoped here (letters B–J) stands unchanged.

## 1. Why this exists

Phase B made the contribution **engine** real: a signed-in rider can submit a new item or an edit, it persists, a curator moderates it from a DB-backed queue, and approval applies the change to the live catalog with append-only per-field history. That machinery works and is tested.

But three separate defects surfaced during hands-on review, and they share one root cause:

1. **The edit form is incomplete versus the drawer.** For a climb (letter B) the drawer shows *Length, Famous for, Road quality, Bike type, Handbike*, but the registry edit form exposes only `name, surface, avgGradient, maxGradient, correction` (+ `waterOnClimb, hairpins, shade`). Editing a real DB climb (e.g. item 3131, Côte d'Ereffe) can't touch half of what's displayed.
2. **The wizard's "Review & submit" step doesn't echo what you entered.** `web/assets/contribute/improve.js` `renderReview()` (lines 303–323) hardcodes exactly three rows — Type, Location, Media — and never iterates the detail/extra fields the rider filled in. Same shape in `add-climb.js`.
3. **~24 hand-authored "hero" pins live only in `map.js`, not the database.** The showcase climbs (Côte de la Redoute, Mur de Huy, Côte de Stockeu), the "Cyclist-friendly gîte · Amblève valley", and ~20 others are baked into the `CATALOG` literal (`web/assets/map/map.js` lines 396–807). They have no `item` row, so no edit button and no path through moderation.

**Root cause:** the contribution *front-end* — the multi-step wizard, the drawer field lists, the review summary — and the demo content all predate the registry/DB model and were **never reconciled** with it. Phase B built the backend and the minimal edit-bridge (item → prefill); it did not rebuild the prototype UI or migrate the demo content. This spec closes that gap so *create / edit / curated* is faithful end-to-end.

### Also present (related)
- **Hardcoded display decoration.** Some drawer rows aren't per-item data at all — e.g. `map.js` lines 1068–1069 emit `Bike type: Every type` and `Handbike: ⚠ Steep — challenging for handbikes` identically for *every* climb. They exist only to make the demo look rich.
- **Hazards (F) have no intake path.** The type is queue-renderable but not collectable (spec §B non-goal); there is no form and no DB hazard rows.

## 2. Concrete evidence

| Symptom | Location | Note |
|---|---|---|
| Review shows only Type/Location/Media | `web/assets/contribute/improve.js:303–323` | `renderReview()` never reads `form.details` / `form.extras` widgets |
| Climb form ≠ drawer | `web/src/Catalog/CatalogFormRegistry.php:46–59` vs `web/assets/map/map.js` climb drawer | form has 5+3 fields; drawer shows more, incl. hardcoded rows |
| Hardcoded drawer rows | `web/assets/map/map.js:1067–1069` | `Bike type` / `Handbike` are literals, same for all climbs |
| Demo pins not in DB | `web/assets/map/map.js:396–807` `CATALOG` literals | ~24 features across B(5) C(5) D(4) E(1) F(1) G(1) H(4) I(2) J(1) |
| Hazards not collectable | no `ItemType::Hazards` intake wiring | display-only today |

## 3. Design principles

- **P1 — One source of truth.** The catalog registry (`CatalogFormRegistry`) + `item.attributes` define an item type's editable shape. Forms, drawers, and the review step all derive from it. No field is invented in a template or in `map.js`.
- **P2 — No hardcoded per-item display.** Every drawer row is either (a) a stored attribute rendered with its provenance, or (b) a clearly-labelled *derived* value (e.g. computed gradient profile). Decorative constants are removed.
- **P3 — The review echoes the submission.** The wizard's final step shows exactly the fields that will be POSTed, with their entered values.
- **P4 — Everything on the curated map is a real item.** Content shown to riders is a DB `item` (submittable, editable, moderatable). Demo content becomes seed data, not code.

> **Superseded for K (2026-07-08):** P1 and P4 no longer hold for recommended routes — a route shown to riders is a `recommended_route` row (not an `item`), has no rider-editable registry shape, and is curator-owned; of P4's triad only "submittable" survives, reshaped as a GPX proposal landing in state `submitted` ([route-domain design](2026-07-08-route-domain-design.md)).

## 4. Workstreams

### W1 — Review-step faithfulness (small, no product decision)
`renderReview()` in `improve.js` (and the equivalent in `add-climb.js`) iterates the actual detail/extra form widgets and lists each field's label + entered value, in addition to Type/Location/Media. Empty fields omitted. This is a faithful mirror of the POST payload; it needs no product decision and can ship first as a quick correctness win.

### W2 — Form ↔ drawer reconciliation (per item type)
Audit all 11 item types. For each: (a) extend the registry form so every *editable* attribute the drawer displays is editable; (b) reclassify each hardcoded drawer row as either a real attribute (promote into `item.attributes` + the form) or a derived/removed value; (c) render the drawer from attributes + provenance, not literals. Climbs and bike-services are the worked examples; the rest follow the same audit.

> **Superseded for K (2026-07-08):** K drops out of this audit — the K improve-form binding shipped under it (C2-T7) is retired, `/improve` refuses `type=K`, and route metadata is edited only by curators in the dedicated Routes queue ([route-domain design](2026-07-08-route-domain-design.md)).

### W3 — Demo-content migration
Move the ~24 hand-authored pins into the database as real `item` rows via a repeatable seeder (their content already exists in `map.js`, so this is extraction + mapping to the registry attribute schema, not invention — though a few demo-only labels like "Famous for" / "Approach" need a home per **D2**). Then retire the hardcoded `CATALOG` features so the map is 100% DB-served. A dedicated `source` tag (e.g. `seed`) keeps them distinguishable from OSM/PIVOT imports and from user submissions.

### W4 — Hazards intake (decision-gated)
Either wire an intake form + flow for `ItemType::Hazards` so hazards become collectable/editable like other types, or keep them explicitly display-only. Gated on **D3**.

### W5 — Change history is visible (NEW, requested 2026-07-05)
Phase B records every applied change in `change_history` (append-only, per-field old→new, who, when), but **nothing surfaces it** — a rider/curator can't see what changed. Add a per-item change log to the UI: for a given item, show its history (field, old value → new value, who [anonymised rider hash], when), newest first. Placement: at minimum in the item drawer (a "Recent changes" section) and/or a dedicated item-history view; also visible to the contributor for their own edits. Read-only; sourced from `change_history` via a small read model/endpoint. Acceptance: after an approved edit, the item's change log shows that field's old→new with attribution and timestamp, and it is easy to spot at a glance.

### W6 — Source/provenance is always clear (NEW, requested 2026-07-05)
Every item and, where meaningful, every displayed fact must clearly show **where it came from** — OSM, Tourisme Wallonie/PIVOT, pipeline-derived (`auto`), a one-tap confirmation, or a rider edit/manual add. Provenance already exists in the data (per-attribute `method` tags + a source line) but is applied inconsistently and is absent for user/manual sources. Make it uniform across all layers and item types, and make user/manual contributions (incl. the W3-migrated pins and any approved edit) legibly attributed. This pairs naturally with W5 (history = *who changed what*; provenance = *where a fact came from*).

## 5. Decisions (resolved 2026-07-05)

- **D1 — Demo pin migration → SEED as `source='manual'`.** Migrate the ~24 pins with their exact hand-authored values, tagged `source='manual'` — i.e. exactly as if a rider had added them by hand (real items, in the normal `submitted`→moderated→served lifecycle or seeded straight to `unverified`/`verified` as appropriate). Requires an `ItemSource::Manual` case (or equivalent). None dropped.
- **D2 — Demo-only display fields → PROMOTE + REPLACE with difficulty/suitability attributes.**
  - *Famous for* and *Approach* → promote to real optional attributes (editable, per item).
  - The constant filler (`Bike type: Every type`, `Handbike: ⚠ Steep…`) → **replace** with real, per-item, **filterable difficulty/suitability attributes** framed as *what the ride/place is like*, not *who it's good for*: e.g. steepness/effort warnings ("very steep", "tough"), road-surface quality ("bad surface"), accessibility ("disability-friendly stay", handbike-friendly). These become genuine attributes riders can **filter** on — not decoration. Exact vocabulary per item type to be defined in the plan (climbs: steepness/effort/surface; stays: accessibility; etc.).
  - *See also (2026-07-08):* for K, suitability keeps this filterable-attribute framing as a bike-type multi-select (Road/Gravel/MTB/E-bike/Handbike) but becomes curator-maintained, with rider signal arriving as bike-typed votes and ride-confirmations instead of registry edits ([route-domain design](2026-07-08-route-domain-design.md)).
- **D3 — Hazards → DEFER.** Stay display-only for now.
- **D4 — Ratings / personal reviews → DEFER** (see §6).

## 6. Ratings & personal reviews — deferred (recommended)

You asked whether user rating + personal review are worth adding. Recommendation: **not now — park them as a later "community signal" phase.** Rationale:
- **Voting already covers ranking.** The verification-gate + best-of voting system provides the community "which is good" signal.
- **Aggregate ratings are cheap later.** Some POIs already carry an aggregate `r` field (currently simulated). A future *aggregate* star rating could slot in without changing the dataset's character.
- **Personal reviews are a much bigger commitment.** Free-text, per-user, per-item reviews mean a new entity plus its own moderation, spam/abuse handling, and display surface — and they shift the catalog from a **non-personal** open dataset (ODbL) toward user-generated personal content, which touches the platform's privacy posture (today the *dataset* is non-personal; only *accounts* hold personal data). That's a product + privacy decision, not a UI tweak.

> *See also (2026-07-08):* the [route-domain design](2026-07-08-route-domain-design.md) concretizes the voting rationale for K (typed seasonal `route_vote`, gated on verification via "I rode this") and adds `route_suggestion` — a moderated, private correction channel feeding curator edits, not the published per-item review surface D4 defers.

So: keep Phase C focused on making the existing create/edit/curated system faithful and complete. Ratings (aggregate) and personal reviews are a separate, later, decision-gated phase.

## 7. Non-goals
- Net-new community features (ratings, reviews) — deferred per §6.
- Redesigning the map or the moderation semantics (both work; unchanged).
  - *See also (2026-07-08):* item moderation semantics do stay unchanged, but the moderation shell has since gained a dedicated Routes queue beside the item pipeline ([route-domain design](2026-07-08-route-domain-design.md)).
- Changing the import pipeline for OSM/PIVOT data.

## 8. Acceptance criteria
- **W1 — DONE:** the wizard's Review step lists every non-empty detail/extra field with its entered value; the wizard header names the exact item being edited on every step.
- **W2 — DONE (C2-T5..T8):** for each item type, the edit form can edit every attribute the drawer presents as data; no drawer row is a per-item literal; a round-trip edit (submit → approve) updates every such field with history. Vocabulary (effort/roadQuality/traffic/famousFor/approach/accessibility) and the matching map filters shipped alongside the form↔drawer reconciliation.
  > **No longer true for K (2026-07-08):** the route drawer's "Edit this ride" → `/improve?type=K` link is dead and the shipped K round-trip is retired; riders act on routes via vote / "I rode this" / GPX download / suggest-a-correction instead ([route-domain design](2026-07-08-route-domain-design.md)).
- **W3 — DONE (C3-T9/T10), EXCEPT hazards (F):** `/map/catalog.json` and the map contain zero hardcoded `CATALOG` demo features for every letter *except* F; every other visible pin has a DB `item` (tagged `source='manual'`) and an edit button; the seeder is repeatable and idempotent (and, as of C4-T11, collision-safe — see the follow-up note below). Letter F (hazards) has no serving path in `CatalogProvider`/map.js, so its one demo pin ("Exposed crosswind · Hautes Fagnes") deliberately stays hardcoded — tracked as a W4 follow-up, not a W3 gap.
- **W4 — DEFERRED (D3):** hazards intake (letter F) is out of scope for this phase; no serving path exists yet for `source='manual'` hazard rows.
- **W5 — DONE (C1-T2/T3):** after an approved edit, the item's change log is visible in the UI (field, old→new, who, when), newest first, and is easy to spot.
- **W6 — DONE (C1-T4):** every layer/item type renders provenance consistently; user/manual contributions and approved edits are legibly attributed.

### Shipped 2026-07-05
All of the above (W1–W3, W5, W6; W4 deferred) landed on `symfony-base` in commit range `fa4d197..8bae43d`, plus the C4-T11 dedup fix/gate/closeout commit that follows this note. Phase C is functionally complete except the explicitly deferred hazards workstream (W4).

### Known follow-ups (not blocking Phase C closeout)
- **Hazards (F) have no serving path.** The single hardcoded hazard pin ("Exposed crosswind · Hautes Fagnes") and any future hazard intake both remain deferred (W4/D3) until `CatalogProvider`/map.js gain a real hazard layer.
- **Curator moderation drawer doesn't yet show target-item history.** `SubmissionQueue` isn't threaded with an `item_id`, so a curator reviewing a pending edit can't see the target item's prior change log inline — only the diff being proposed. Needs `item_id` threading on the queue row before it can link out to the W5 history view.
- **Pre-existing OSM-vs-OSM "Signal de Botrange" duplicate.** Two `osm`-sourced rows (ids 3353 and 3338) both represent Signal de Botrange — a harvest-side data issue predating and outside Phase C (the manual-seeding dedup fixed in C4-T11 only addressed `manual` vs non-`manual` collisions, not duplicate rows within the same source).

## 9. Next step
On approval (and D1–D4 answered), a task-by-task implementation plan follows in `docs/plans/`, executed with the same subagent-driven review rigor as Phase B. W1 can be split out and shipped immediately as a standalone correctness fix if you want a fast win before the larger reconciliation.
