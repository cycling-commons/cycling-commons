# Moderation Feedback & User Messages — Common Design (all contribution channels)

- **Status:** design approved (user, 2026-07-12) — **NOT built**; no implementation plan yet.
- **Date:** 2026-07-12
- **Applies to:** **every** contribution channel, present and future — item submissions
  (A–J, the `/improve` + add-flows pipeline), route proposals (K, propose-route), and
  route corrections (K, the drawer's suggest-a-correction). Per-type edit-item specs
  (`edit-items/*.md`) inherit this document; they only note deviations.
- **Related:** [`2026-07-08-route-domain-design.md`](2026-07-08-route-domain-design.md) §17
  (the route-side origin of this design — N1–N7 are generalised here),
  [`2026-07-04-submissions-and-moderation-on-real-data-design.md`](2026-07-04-submissions-and-moderation-on-real-data-design.md)
  (the item pipeline this extends), [`edit-items/README.md`](edit-items/README.md)
  ("Common to every type"), [`2026-07-01-admin-panel-account-support-design.md`](2026-07-01-admin-panel-account-support-design.md)
  (audit/guardrail patterns Trash reuses).

## 1. Problem

Today the feedback loop after a moderation decision is pull-only at best and absent
at worst (2026-07-12 reconciliation sweep, all refs verified in code):

- **Item submissions (A–J):** the submitter *can* see status + the curator's decision
  note — but only by spontaneously visiting `/profile`. No notification of any kind.
- **Route proposals (K):** the proposer sees state only; the curator's reject/retire
  note lands in `route_change_history` (audit) and **never reaches the proposer**.
- **Route corrections (K):** the rider **cannot see their correction at all** after
  posting — no list, no outcome, nothing; `resolveSuggestion` notifies nobody.
- **`needs_info` (items) dead-ends:** the request-note reaches the rider's profile,
  but there is no way to answer, no NeedsInfo→Pending transition, and no notice that
  the request happened. A later approve/reject **overwrites** the needs-info note
  (single mutable field, no history). The note textarea is length-unconstrained.
- **No retention policy anywhere:** rejected/dismissed/needs-info rows live forever.
- **No hard-delete for spam:** the worst content can only be "rejected" and then
  lingers permanently (also on the submitter's profile list).

One shared system fixes all channels at once instead of three divergent ones.

## 2. Decision record (M1–M12)

| # | Decision |
|---|---|
| M1 | **One `UserMessage` entity** (new — nothing like it exists) carries all moderation feedback: recipient user id, `kind`, body, sender (`system` or a curator id — never exposed as a name in either direction), related refs (channel + row id + the human receipt ref e.g. `SUB-42` / `CC-R00023`), `created_at`, `read_at`. **Append-only, and it is the recipient's inbox copy — NOT the institutional audit trail** (it cascades away with the account, M10). The curator-side durable record of who decided what and why stays where it survives account deletion: `Submission.decisionNote`/`decidedBy`/`decidedAt` and `route_change_history`. What M1 fixes for the rider is delivery: each decision's note reaches them as its own message, so the item side's mutable-`decisionNote` overwrite no longer loses the *rider-facing* copy of an earlier needs-info note. |
| M2 | **Every decision on every channel writes a message, inside the decision's transaction** (atomic + duplicate-proof, riding the existing `wrapInTransaction` + already-decided guards). Outcomes: approve/done → **thank-you**; reject/dismiss → informing message; needs-info → request message. Kinds: `submission_approved/rejected/needs_info`, `route_approved/rejected/retired`, `correction_done/dismissed`, `curator_message`. |
| M3 | **Messages surface on the account dashboard** — a Messages tab in the account shell (deep-linkable as `/profile?tab=messages` via the existing `?tab=` mechanism), listing messages newest-first with read-marking. |
| M4 | **Unread bulb on the account chip.** Rendered **inside** the shared `_account_chip.html.twig` partial (one insertion point → every base-extending surface), server-rendered per page load via a small Twig extension (one COUNT query; **no polling** — consistent with the app's no-fetch header architecture). Visual language: the existing `.cc-cluster` count-bubble treatment. Clicking goes to Messages. |
| M5 | **The map page gets the account chip.** `/map` is a standalone document whose rail hardcodes "Account → login" even when authenticated — the bulb must reach the platform's biggest logged-in surface, so the map header gains the chip (or a minimal authenticated indicator wired to the same partial). |
| M6 | **Curator → rider messaging (both directions bounded).** A curator can send a personal message from any desk row (request more info, or a note accompanying any decision) → `kind: curator_message`. The **rider can answer a needs-info request**: answering attaches their reply (a message on the same thread refs) and flips the submission **NeedsInfo → Pending** so it re-enters the queue — closing today's dead-end. Identities stay pseudonymous in both directions (`rider#hash` convention). Reply bodies get the same length constraints as notes (see M11). |
| M7 | **Email is a later delivery channel (v2)** on top of messages (which stay the record). Requires `symfony/messenger` (async sends + retry) and recipient-locale rendering (`User.locale` exists, unused today; only 1 of 3 current email templates is translated). Deferred together with M8's scheduler. |
| M8 | **Retention: dismissed/rejected contributions are kept 3 months, then garbage-collected.** Applies to: dismissed route corrections, rejected item submissions, rejected route proposals *(see Open points for the proposal-row caveat)*. **Runner, phase 1: no new infrastructure** — (a) *lazy filtering*: every read excludes rows past retention regardless of whether a sweep ran (the house point-of-use-expiry pattern), plus (b) an *opportunistic sweep* piggybacked on desk visits (the reset-password bundle precedent). A real scheduled runner (`symfony/scheduler` + messenger worker) is **phase 2, added together with M7's email queue** — one infra investment for both. The GC command itself follows the thin-command/fat-service house pattern, idempotent and safe to re-run. |
| M9 | **Trash — immediate, permanent hard delete for spam/abuse — on every channel** (corrections and item submissions; route proposals via the same action on the desk). No 3-month retention: we do not keep such texts/images at all. **No message is sent** (don't feed spam). Hardening copied from the admin desk: `displayIf`-gated button, POST-only + CSRF, guardrail-exception → flash, and an **audit-before-delete** content-free log row (action/who/when + channel/ref snapshot, never the content) so the deletion itself is provable. |
| M10 | **GDPR cleanup via DB `ON DELETE CASCADE`** on `user_message.user_id` — chosen over a `UserDeletionHookInterface` implementation because the admin-side `removeAccount` **bypasses** the deletion hooks (verified asymmetry); a DB-level cascade covers both deletion paths identically. (The hook pattern remains right for anonymise-don't-delete cases; messages are personal correspondence and simply go.) Note this would be the schema's **first real `user_id` foreign key** — today `submission`/`route_vote`/`route_ride`/`route_suggestion.user_id` are plain bigints with no FK (house convention), so those rows survive account deletion as anonymous data by decoupling, not cascade; they are outside M10's scope (see §6). This is a documented exception to the admin-panel spec's "contributed data is anonymised, never cascade-deleted" rule: messages are correspondence *to* the person, not contributed catalog content. |
| M11 | **Hygiene fixes folded in:** curator note/message bodies get an explicit length constraint (the current moderation-note textarea has none, and unbounded text reaches the rider's dashboard); message bodies are HTML-escaped on render everywhere (drawer XSS rule). |
| M12 | **Account lock/ban for spammer accounts is OUT of this design** — recorded as separate admin-desk work (today: unlock-only; `remove_account` is gated to self-requested deletions; no proactive lock/ban exists). Trash handles the *content*; the *account* is the admin desk's job. |

## 3. What each channel inherits (summary table)

| Channel | Outcome messages (M2) | needs-info + reply (M6) | 3-month GC (M8) | Trash (M9) |
|---|---|---|---|---|
| Item submissions A–J | approve / reject / needs_info | yes — reply flips back to Pending | rejected rows | yes |
| Route proposals K | approve / reject / retire (note finally reaches the proposer) | via curator_message | rejected rows *(open point)* | yes |
| Route corrections K | done (thank-you) / dismissed | via curator_message | dismissed rows | yes |

All three also inherit: the Messages tab (M3), the bulb (M4/M5), pseudonymity and
receipt-ref conventions (M1/M6), and the later email channel (M7).

## 4. Implementation patterns to copy (from the 2026-07-12 sweep — verified in code)

- **Atomic decision+message**: write the `UserMessage` inside the same
  `wrapInTransaction` as the status flip; the existing already-decided guards
  (`AlreadyDecidedException` → 409; resolveSuggestion's equivalent) make the pair
  duplicate-proof by construction.
- **Append-only trail**: the route side's `RouteChangeHistory` note pattern, not the
  item side's mutable `decisionNote` field.
- **Refs**: the human receipt formats (`SUB-<id>`, `CC-R%05d`) as the user-facing
  reference inside message bodies; raw ids only in the structured columns.
- **Trash hardening**: `UserAdminService::removeAccount`'s audit-before-delete with
  snapshot (AdminActionLog, SET-NULL FK) + `UserCrudController`'s POST/CSRF/guardrail
  trio + EasyAdmin generic delete stays disabled.
- **Bulb**: `.cc-cluster` visuals (note: that class lives in the map-scoped
  `map.css` today — the bulb needs its own shared rule borrowing the treatment,
  not the class as-is); count via an auto-registered Twig extension (the
  `LocaleExtension` structural template); `nav-menus.js` data-attribute contract if
  the bulb ever opens a mini-dropdown; the chip partial's responsive behaviour is
  already solved (avatar-only collapse ≤560px — attach the bulb to the avatar circle).
- **Messages tab**: `account.js` `?tab=` deep-linking; the profile's existing
  status-pill + note row rendering as the message-row visual; the
  `MyContributionsTest` ownership-scoping test shape.
- **Retention insurance**: point-of-use expiry filtering everywhere (correct even
  with no/flaky sweep), the reset-password bundle's opportunistic-GC precedent.

## 5. Testing (headlines for the eventual plan)

- Message written atomically with each decision kind, per channel; no message on Trash.
- Reply to needs-info flips NeedsInfo → Pending exactly once (race-guarded) and
  re-queues; reply length-constrained.
- Bulb count = unread only; read-marking clears it; cascade on account deletion
  (both deletion paths).
- GC: lazy filter excludes >3-month rows even without a sweep; sweep idempotent;
  Trash leaves the content-free audit row and nothing else.
- Escaping of message bodies on every render surface; translation parity for all new
  keys (messages surface/tab/status copy — 4 locales).

## 6. Open points (for the implementation plan)

- **Rejected route-proposal rows** (`recommended_route.state = rejected`): joining
  the 3-month GC means physically deleting catalog rows whose `source_ref`
  uniqueness/provenance story assumed permanence — confirm no importer/upsert
  interaction before including them; corrections + item submissions carry no such
  coupling. Additionally, GC-ing a route row orphans its `route_change_history`
  rows (no FK, so no error — but the D9 append-only audit becomes unresolvable
  for that route): resolve via an M9-style content-free audit snapshot at GC
  time, or a route tombstone.
- **Retention for never-answered `needs_info` rows** — §1 notes they live forever
  today, but M8 covers only rejected/dismissed; decide whether unanswered
  needs-info submissions expire (e.g. auto-reject after N months → then M8 applies).
- **Other user-keyed rows on account deletion**: `submission`, `route_vote`,
  `route_ride`, `route_suggestion` carry plain no-FK `user_id`s and survive
  account deletion as anonymous rows (decoupling, not cascade) — deliberate for
  contributed data, but outside M10's scope; the plan should confirm this is the
  intended GDPR story for correction *bodies* specifically (free text a user
  wrote), or add them to a deletion hook.
- Retention for **Done/approved** rows (unaffected by M8 as specified — decide
  whether they ever expire).
- Message retention (do old *read* messages expire?), bulb semantics beyond
  unread-count, message i18n (system messages via translation keys per recipient
  locale vs curator free text as-written — likely: system kinds translated, curator
  bodies verbatim).
- Whether the reply-to-needs-info affordance lives on the profile row, the Messages
  tab, or both.
- Phasing: M1–M4 + M6 + M11 are the core; M5 (map chip) can ride any map-touching
  phase; M8 phase-1 (lazy+opportunistic) with the core; M7 + M8 phase-2
  (messenger/scheduler) as their own infra phase.

## 7. Execution note — core phase shipped (2026-07-13)

The core (M1–M6, M8 phase‑1, M9–M11) shipped on `symfony-base`
(`abbc1d9..<spec-note>`, subagent-driven, ~17 commits incl. review fixes). Full
gate green: **473 tests / 2081 assertions**, phpstan + psalm + php-cs-fixer +
SPDX + licenses + translation parity (1238 keys ×4) clean. Dev DB migrated
(`user_message`). Still deferred as designed: **M7 email + M8 phase‑2
scheduler** (own infra plan), **M12 lock/ban**, rejected-route-proposal GC.

Delivered: `UserMessage` (+kind enum, the schema's **first real `user_id` FK**,
`ON DELETE CASCADE`, cascade-tested at the DB level) · `MessageService`
(system sends persist-without-flush and ride the decision transactions) ·
outcome messages on all three channels (items approve/reject/needs-info; route
proposals approve/reject/retire — the note finally reaches the proposer;
corrections done/dismissed) · `/messages` shell page (mark-read-on-view) ·
unread bulb in the shared chip (server-rendered count, Twig extension) · the
map rail gains the chip · curator→rider desk messages (one endpoint, recipient
resolved server-side) · needs-info reply loop (re-queues NeedsInfo→Pending;
reply surfaces on the queue row for every curator) · retention phase‑1
(`moderation.retention_months: 3`; lazy profile filter with NULL-safe
predicate + hourly-throttled opportunistic sweep on desk visits +
`app:moderation:gc`) · Trash (kind-pinned deletes, server-side proposal
guardrail submitted/rejected-only, audit-first content-free, no message).

Decisions/fixes during execution (binding):

1. **The new FK ripples into tests**: any decision-path test seeding a
   fabricated `user_id`/`proposed_by` now FK-violates when the decision writes
   a message — fixtures must persist real users (done across 5 test files).
2. **Plan gap fixed**: a new entity namespace needs a `doctrine.yaml` mapping
   block (`Messaging` mirrors World/Catalog).
3. **Validation before the limiter** on suggest notes (a 422 must not cost a
   daily-quota token — cheap checks only; GPX-style expensive work still
   consumes first).
4. **Desks now render flashes** — pre-existing gap: desk controllers set
   success/danger flashes no template displayed; fixed shell-wide (this was
   the new features' only feedback channel).
5. **Trash audit is uniformly content-free** — the proposal note logs
   `route <id> state=<x>` only (a submitted proposal's *name* is unvetted
   rider text and must not enter the immutable log).
6. i18n reviews caught and fixed: DE "dismissed" is `verworfen` (matches the
   desk's `Verwerfen`), FR stays plain-form (no mid-dot inclusive forms).

**Recorded lows (non-blocking, from reviews):** double-reply/guardrail
check-then-act races match the existing house pattern (harmless duplicates at
worst; site-wide hardening candidate) · no index on
`user_message(channel,ref_id,sender)` for the queue's reply join (fine at
current volume) · trashing a *rejected* proposal orphans its
`route_change_history` rows (no FK; rejected proposals are also unreachable in
the detail UI today due to its pre-existing 404 rule) · desk-message tests
don't cover nonexistent-id on two channels (code-inspected safe) · a live
visual pass of the map-rail chip/bulb is still owed (browser was locked by a
parallel session; all behaviour is WebTest-covered through the real kernel).

## UI feedback pass (2026-07-14)

Message-rider + Trash blocks: split over two columns (`.mod-2col` grid,
stacking again under 700px); buttons back to natural size (`align-self:
flex-end`, no more full-width); the trash warning's lead sentence
("Permanently delete this as spam?") renders dark red — the `moderate.
trash.confirm` key split into `confirm_head`/`confirm_rest` in all four
locales. Applies to the submissions queue card, the routes-desk list and
the route detail page (the per-correction trash keeps its inline layout).
- Messages now link to their subject when it is publicly on the map
  (2026-07-14, 50791d6): `submission_approved` → `?feature=<refLabel>`;
  `route_approved` / `correction_done` → `?route=<refId>`. Rejected /
  retired / needs-info kinds carry no link (subject not publicly visible).
  Key `messages.view_on_map`, 4 locales.
