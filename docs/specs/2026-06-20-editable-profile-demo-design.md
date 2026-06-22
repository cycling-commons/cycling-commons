# Spec — Editable profile: interface & flow (prototype)

**Date:** 2026-06-20
**Status:** Approved (design), ready to build
**Surfaces:** `atlas/demo/profile.html` (edit), `atlas/demo/settings.html` (new), reuses `atlas/demo/improve.html` + `atlas/demo/edit-items.js`

## Problem

`atlas/demo/profile.html` is read-only. It renders a contributor's identity, stats, and
contributions from a static `PROFILES` fixture, with no way to edit anything and no
visible path into editing a contribution. There is no example of what the
profile-editing experience looks like.

## Goal

Make the **full profile-edit interface and flow visible and navigable** in the
prototype. A contributor can see and move through the complete editing experience.
This is a UI/flow demo — it does **not** need real persistence.

## Non-goals (explicitly out of scope)

- Real persistence — no localStorage overlay, no backend. Save is a mock toast,
  matching `improve.html`'s "demo, nothing is stored" convention.
- Auth / owner detection (no `cc_me`); the demo simply shows the edit affordance.
- Avatar photo upload (initials only), notification preferences, a real
  contribution-status/review backend.

## Principles

- **Full pages, zero overlays.** No slide-overs, drawers, or modals for the edit
  flow. Full-page navigation "just works" on mobile and aligns with the
  `feat/mobile-responsive` workstream.
- **Reuse, don't rebuild.** Contribution editing routes to the existing
  `improve.html?item=<id>` editor (driven by `edit-items.js`); the profile only
  links into it.

## Flow

1. `profile.html?u=<slug>` shows an **"Edit profile"** button in the header.
2. Click → **`settings.html?u=<slug>`** — the full-page editor, pre-filled from the
   `PROFILES` fixture (default `hanne-v` when no/unknown slug).
3. The editor shows every section (below), with interactive inputs.
4. **Save** → toast *"Saved — demo, changes aren't stored"* → return to
   `profile.html?u=<slug>`. **Cancel** / back → return without a toast.
5. On the profile, each **contribution row** shows a **status pill** and an
   **Edit → `improve.html?item=<id>`** link, exposing that flow too.

## `settings.html` — sections & fields

Pre-filled from the selected profile fixture. Styling mirrors `improve.html`
(`.field`, `.submit-row`, `.btn`, `.cc-toast`) and the shared design tokens.

- **Identity** — Display name (text) · Region (text) · Contributing since (text/year)
  · Bio (textarea, ~160 chars). Avatar initials shown, derived from the name
  (read-only label; no upload).
- **Privacy** — "Public profile" master toggle (default on) · **per-contribution
  visibility**: the profile's contributions listed, each with a show/hide toggle.
- **Links** — Strava URL · Website URL · OSM username (plain text inputs; no
  validation needed for the demo).
- **Preferences** — "Show stats publicly" toggle.

All inputs are interactive but discard on save (mock). All rendered user-derived
text stays HTML-escaped via the existing `esc()` helper pattern.

## `profile.html` — changes

- Add an **"Edit profile"** button in the header → `settings.html?u=<slug>`.
- Extend each fixture contribution with `item` (edit id) and `status`
  (`'approved'` | `'pending'`).
- Each contribution card renders:
  - a **status pill** — APPROVED (spruce/green) or PENDING (ochre), matching the
    site's status styling;
  - an **Edit** action → `improve.html?item=<item>` (the existing item editor);
  - the existing **View** deep-link → `map.html?feature=<title>` is retained.

## Components / files

- `atlas/demo/settings.html` — **new.** Full-page profile editor; inline script only for
  prefill from the fixture and the mock-save toast + redirect.
- `atlas/demo/profile.html` — Edit-profile button, contribution `item`/`status` fields,
  status pills, Edit links.
- No new shared JS module is required (the data layer was dropped with persistence).

## Error handling / a11y

- Save is a mock; the only failure surface is none. Toast reuses `.cc-toast`.
- Keyboard: the editor is a normal page (native tab order); `a11y.js` applies.
- All dynamic text HTML-escaped.

## Acceptance criteria (manual / Playwright)

1. `profile.html` shows an **Edit profile** button linking to `settings.html`.
2. `settings.html` renders all four sections **pre-filled**; inputs are editable/toggleable.
3. **Save** shows the toast and returns to `profile.html`.
4. Each contribution row shows a **status pill** and an **Edit** link to
   `improve.html?item=<id>` that opens the existing editor.
5. No overlays anywhere; both pages render cleanly at mobile widths.

## Future work (not this pass)

Real persistence, authentication/owner detection, avatar photo upload, notification
preferences, and a real contribution review/status backend.
