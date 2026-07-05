# Contribution Front-End Reconciliation — Design (Phase C)

> **Status:** Approved 2026-07-05 (decisions D1–D4 recorded in §5). W1 shipping first; W2/W3 to be planned next. W4 + ratings/reviews deferred.
> **Date:** 2026-07-05
> **Branch target:** `symfony-base`
> **Predecessors:** Phase A (catalog data model + Wallonia import), Phase B (submissions & moderation on real data — the intake→queue→moderate→apply-with-history *engine*).

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

## 4. Workstreams

### W1 — Review-step faithfulness (small, no product decision)
`renderReview()` in `improve.js` (and the equivalent in `add-climb.js`) iterates the actual detail/extra form widgets and lists each field's label + entered value, in addition to Type/Location/Media. Empty fields omitted. This is a faithful mirror of the POST payload; it needs no product decision and can ship first as a quick correctness win.

### W2 — Form ↔ drawer reconciliation (per item type)
Audit all 11 item types. For each: (a) extend the registry form so every *editable* attribute the drawer displays is editable; (b) reclassify each hardcoded drawer row as either a real attribute (promote into `item.attributes` + the form) or a derived/removed value; (c) render the drawer from attributes + provenance, not literals. Climbs and bike-services are the worked examples; the rest follow the same audit.

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
- **D3 — Hazards → DEFER.** Stay display-only for now.
- **D4 — Ratings / personal reviews → DEFER** (see §6).

## 6. Ratings & personal reviews — deferred (recommended)

You asked whether user rating + personal review are worth adding. Recommendation: **not now — park them as a later "community signal" phase.** Rationale:
- **Voting already covers ranking.** The verification-gate + best-of voting system provides the community "which is good" signal.
- **Aggregate ratings are cheap later.** Some POIs already carry an aggregate `r` field (currently simulated). A future *aggregate* star rating could slot in without changing the dataset's character.
- **Personal reviews are a much bigger commitment.** Free-text, per-user, per-item reviews mean a new entity plus its own moderation, spam/abuse handling, and display surface — and they shift the catalog from a **non-personal** open dataset (ODbL) toward user-generated personal content, which touches the platform's privacy posture (today the *dataset* is non-personal; only *accounts* hold personal data). That's a product + privacy decision, not a UI tweak.

So: keep Phase C focused on making the existing create/edit/curated system faithful and complete. Ratings (aggregate) and personal reviews are a separate, later, decision-gated phase.

## 7. Non-goals
- Net-new community features (ratings, reviews) — deferred per §6.
- Redesigning the map or the moderation semantics (both work; unchanged).
- Changing the import pipeline for OSM/PIVOT data.

## 8. Acceptance criteria
- **W1 (shipped):** the wizard's Review step lists every non-empty detail/extra field with its entered value; the wizard header names the exact item being edited on every step.
- **W2:** for each item type, the edit form can edit every attribute the drawer presents as data; no drawer row is a per-item literal; a round-trip edit (submit → approve) updates every such field with history.
- **W3:** `/map/catalog.json` and the map contain zero hardcoded `CATALOG` demo features; every visible pin has a DB `item` (tagged `source='manual'`) and an edit button; the seeder is repeatable and idempotent.
- **W4:** resolved per D3 (deferred).
- **W5:** after an approved edit, the item's change log is visible in the UI (field, old→new, who, when), newest first, and is easy to spot.
- **W6:** every layer/item type renders provenance consistently; user/manual contributions and approved edits are legibly attributed.

## 9. Next step
On approval (and D1–D4 answered), a task-by-task implementation plan follows in `docs/plans/`, executed with the same subagent-driven review rigor as Phase B. W1 can be split out and shipped immediately as a standalone correctness fix if you want a fast win before the larger reconciliation.
