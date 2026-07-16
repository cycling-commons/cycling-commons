<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->
> **Consolidated into** moderation-and-contribution.md (community confirmation loop: per-type stance table, one-switchable-stance uniqueness, GET/POST endpoint contract with clean-401 + stateless CSRF, CC_VOTABLE/CC_CONFIRMABLE gating; cross-referenced from edit-items/README.md) and map-and-search.md (drawer panel behaviour) **(2026-07-16).** This dated working doc is sweepable; the canonical docs above are the source of truth.

# Utility confirmations — potability & "still here?" (2026-07-13)

## Problem

The map drawer showed a generic **"▲ Vote in this round"** link on every
confirmed non-route point, including drinking water and every other utility.
General utilities are **not votable** (only climbs, stays, viewpoints, history
and routes are — `ItemType::isVotable()`). Utilities should instead be
**confirmed**: drinking water needs a community judgement on whether it is
potable, other point utilities a plain "is this still here?" confirmation.

## Model

- **Confirmable types** (`ItemType::confirmationStances()`):
  - Water (C) → `Potable` / `NotPotable`.
  - Services (D), Hazards (F), Getting-there (G), Shelter (H) → `Exists`.
  - Road-surface (A) and every votable type → none (they vote, or are measured).
- One stance **per rider per item** (`item_confirmation`, UNIQUE `(item_id,
  user_id)`), **switchable** — flipping potable ↔ not potable never
  double-counts.
- **Counts are public; recording needs an account.** `GET
  /items/{id}/confirmations` is `PUBLIC_ACCESS` (tallies + `stanceKind`, plus
  the viewer's own stance + a CSRF token only when logged in). `POST
  /items/{id}/confirm` requires a `ROLE_USER` (clean **401**, not a login
  redirect) + stateless CSRF. Votable or unserved items **404**.

## Surfaces

- `ConfirmationStance` enum, `ItemConfirmation` entity + migration
  `Version20260713120000`.
- `ItemConfirmationService` (record + public snapshot),
  `ItemConfirmationController` (mirrors the route community loop).
- Drawer (`assets/map/map.js`): the vote CTA is gated on `CC_VOTABLE`; a
  confirmation panel is hydrated async on drawer-open for `CC_CONFIRMABLE`
  layers — water shows both tallies, utilities a single confirm; anonymous
  viewers see the counts and a "Log in to confirm" prompt.

## Execution note (2026-07-13, symfony-base, NOT pushed)

Landed in two commits (`7d21a80` backend, `e0f9889` frontend). 15 new tests
(service + controller), full suite green. Verified end-to-end on the dev app
(anon GET public tallies, votable climb 404, anon POST 401). The
`item_confirmation` migration was applied to both the test and dev databases;
**prod still needs it** on deploy.
