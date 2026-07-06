# Climb Definition by Three Points — Design

> **Status:** Draft — for review before planning/implementation.
> **Date:** 2026-07-06
> **Branch target:** `symfony-base`
> **Context:** Phase C made climbs fully editable, but a climb is a *line* (foot → summit) with a gradient profile and a steepest ramp — and the edit flow only lets you drop a single point. This design lets a rider define/adjust a climb by three points, which also gives untraced imported climbs (e.g. Côte de Cherave) a real track.

## 1. Problem

- A climb's shape is `route` (the GPS track), `grad` (the gradient profile), and `steep` (`{at:[lat,lng], pct}`, the steepest ramp). These render the coloured climb line, the profile bars, and the "▲26%" marker.
- **Add-a-climb** already sets **foot + summit** (two markers) and routes between them. But it has **no steepest point**.
- **Editing** an existing climb only drops **one pin** (the generic "Locate" step) — you cannot set/adjust foot, summit, or steepest.
- Many imported climbs (Wikidata point-only) never got a track; there's no user-facing way to give them one.

## 2. Goal

Define/adjust a climb by three clearly-labelled points, in **both** the add-a-climb and edit flows:
- **① Foot (start)**, **② Summit (end)**, **③ Steepest**.
- Setting foot → summit auto-routes the road to produce `route` + `grad`.
- The steepest is auto-placed, user-adjustable, and **locks once moved** (no further auto-updates).

## 3. Design

### 3.1 The three points + icons
Markers must make start vs end unambiguous (both by icon and label):
- **① Foot** — green marker, label **"START · foot"**.
- **② Summit** — orange marker, label **"END · summit"**.
- **③ Steepest** — distinct warning marker (▲ with a red ring), label **"STEEPEST · <pct>"**.
The connecting track is drawn foot → summit (shows direction). Reuse the existing add-climb marker styling (foot green `#1C3A2A`, summit orange `#FF5A1F`) and extend it with the steepest marker + text labels.

### 3.2 Track generation — auto-route + auto/lockable steepest
- On **foot** then **summit** placed (or moved), auto-route the road between them using the **existing routing** (the road-tracing the Wallonia export uses server-side, and/or the client router the add-a-climb wizard already calls — the plan picks one and reuses it). This produces `route` (LineString) and `grad` (per-segment gradient profile).
- **Steepest, auto-placed:** derive the steepest ramp from `grad` (max-gradient segment) and place marker ③ there automatically, with its `pct`.
- **User override + lock:** the user can drag/re-tap the steepest marker any time. **Once moved, it is `manual` and never auto-updates again** for that climb — subsequent re-routes (from changing foot/summit) leave the steepest where the user put it, and its `pct` is taken from the nearest grad segment. While still auto, changing foot/summit re-detects and re-places it.
- If routing fails/unavailable, fall back to the straight foot→summit line (no grad); the steepest is then only settable manually. Surface the failure (don't silently produce a fake profile).

### 3.3 Data (no schema change)
Climb attributes already are `route`, `grad`, `steep`. Extend the `steep` value to carry the lock:
- `steep = {at:[lat,lng], pct:"26%", manual:true|false}` (`manual` defaults false = auto). The flag persists so a later edit knows not to auto-move it.
Everything else (`avgGradient`/`maxGradient`/`surface`/`famousFor`, etc.) is unchanged; `avgGradient`/`maxGradient` can be recomputed from the new `grad` on save (kept in sync with the track).

### 3.4 Edit flow (existing climb)
- Prefill: foot = `route[0]`, summit = `route[last]`, steepest = `steep.at` (with its `manual` flag); if the climb has no `route` (untraced import), start empty — the rider sets foot+summit to create one.
- The rider adjusts points → re-routes → the submission carries the new `route`/`grad`/`steep` (and recomputed avg/max) as an **Edit** submission through the normal moderation flow, so the change is visible in the item's change history.

### 3.5 Add-a-climb flow
- Keep the existing foot+summit+route behaviour; **add the steepest point** (auto-placed after routing, user-lockable) using the same component as the edit flow. Unify the two so there's one "three-point climb editor" used by both.

## 4. Non-goals
- No new BRouter infra (reuse what the export uses / the client routing already in add-climb).
- No multi-segment climbs, no elevation-source change, no schema migration.
- Not touching non-climb item types.

## 5. Open decisions (resolved 2026-07-06)
- **Scope:** both flows, all three points. ✓
- **Track:** auto-route foot→summit; steepest auto-placed, user-movable, **locks on manual move**. ✓

## 6. Acceptance criteria
- In BOTH add and edit, a rider can set/adjust foot, summit, and steepest, with markers clearly labelled start/end/steepest.
- Setting foot+summit produces a real routed track + gradient profile (or a surfaced failure, never a fake profile).
- The steepest is auto-placed, is draggable, and after a manual move never auto-updates (persisted via `steep.manual`).
- Editing an existing climb prefills its current points; submitting routes anew and flows through moderation + change history.
- An untraced climb (e.g. Côte de Cherave) can be given a real track via the edit flow.
- Before/after browser validation on a real climb (track line + profile + steepest marker render correctly).

## 7. Next step
On approval → `writing-plans` for a task-by-task implementation plan (unify the three-point editor, wire both flows, BRouter routing, steepest lock, edit prefill + submission), executed with per-task review + before/after validation.
