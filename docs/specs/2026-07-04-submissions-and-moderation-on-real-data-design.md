# Submissions & Moderation on Real Data — Design (Data-API Phase B)

- **Status:** draft — awaiting review
- **Date:** 2026-07-04
- **Related:** [`2026-07-03-catalog-data-model-and-import-design.md`](2026-07-03-catalog-data-model-and-import-design.md) §11 (phase B forward design), [`2026-07-02-map-based-moderation-design.md`](2026-07-02-map-based-moderation-design.md) §9 (field-level history requirement) + §13 (hardening bundle — this spec fires it), [`edit-items/README.md`](edit-items/README.md) (lifecycle funnel, provenance tags, change-history principle).

## 1. Problem

Phase A made the DB the source of truth for the *catalog* — but contributions still evaporate: `ContributionStubService` returns an honest receipt and persists nothing, and curators moderate the hard-coded `SampleQueue`. Every approve/reject is theatre. Phase B makes both real: submissions persist against the imported Wallonia catalog, the moderation queue reads from the DB, and curator decisions have observable effect on the map.

## 2. Goals / Non-goals

**Goals**

1. Real intake for the two flows that have UI today: **new** (add-climb → letter B) and **edit** (improve, bound to a real item via the map edit-bridge).
2. `submission` persistence replaces `SampleQueue` everywhere it's read: `/moderate`, the curator map pending layer (`CC_PENDING`), the drawer.
3. Decisions (**approve / reject / needs-info**) persist with an audit trail.
4. **Apply-on-approve with per-field change history** (the moderation-spec §9 requirement): approving an edit mutates `item.attributes` and writes one `change_history` row per field; approving a new item flips `submitted → unverified`.
5. The moderation spec **§13 hardening bundle**: drawer HTML-escape, redirect-after-POST filter preservation, `TYPES` relocation, `moderationToken()` reject-on-miss, Collator caching.
6. A minimal **"my contributions"** list on the account dashboard (the receipt's durable counterpart).

**Non-goals (deferred)**

- Hazard/photo **intake UI** (the schema supports the types; no upload/report flow yet — photo needs file storage).
- **Vote persistence** — voting is verification-gate machinery (phase C+); the vote flow keeps its honest stub.
- Importer **conflict filter / `osm_sync`** submissions (phase C, per catalog spec §11).
- Per-region curator Security Voter, notifications/messaging on needs-info, email digests.

## 3. Decision record: approve = apply + history

The catalog spec §11 deferred edit-application and the history table to phase C; the moderation spec §9 requires "a history entry per accepted change" the moment the stub is replaced. This spec follows §9 (full loop) because:

- a queue whose approvals do nothing observable un-teaches the curator behaviour we want to build;
- the was→now drawer diff should render from real history, not stored fiction;
- the history table was always designed append-only and time-partitionable "from its first migration" — building it now costs one migration, not a redesign.

Phase C retains: importer conflict filter, `osm_sync` queue entries, vote/verification mechanics.

## 4. Data model

Two tables, one migration, same conventions as phase A (bigint identity, `TIMESTAMP(0)`, jsonb, GiST where geometric, no SPDX header in migrations).

### 4.1 `submission`

| column | type | notes |
|---|---|---|
| `id` | bigint identity | |
| `type` | varchar(8) enum `SubmissionType`: `new` \| `edit` \| `hazard` \| `photo` | §13 "TYPES relocation" lands here; queue UI renders all four, intake produces `new`/`edit` only |
| `letter` | varchar(1) | catalog letter A–K |
| `item_id` | bigint NULL, FK → `item.id` | set for `edit` at submit; set for `new` when the item row is created (same transaction) |
| `user_id` | bigint FK → `users.id` | submitter |
| `status` | varchar(12) enum `SubmissionStatus`: `pending` \| `approved` \| `rejected` \| `needs_info` | |
| `title` | varchar(200) | drawer/queue display |
| `geom` | geometry(Point, 4326) | pending-pin location; GiST index |
| `country_code` | varchar(2) | resolved at submit (same spatial logic as import membership) |
| `region_id` | bigint NULL | resolved at submit (`ST_Contains` over `region`) |
| `changes` | jsonb | `{field: {"was": …, "now": …}}` — computed at submit against the item's current values; for `new`, the proposed field set under `now` with `was` null |
| `payload` | jsonb | raw validated form data (durable record of what was submitted) |
| `decision_note` | text NULL | curator note (needs-info reason, reject reason) |
| `decided_by` | bigint NULL FK → `users.id` | |
| `decided_at` | timestamp NULL | |
| `created_at` | timestamp | |

Indexes: `(status)`, `(country_code)`, `(region_id)`, `(item_id)`, GiST `(geom)`. The decision columns make the submission row itself the audit record — no separate decision table, and `admin_action_log` stays reserved for admin-on-user actions.

### 4.2 `change_history`

| column | type | notes |
|---|---|---|
| `id` | bigint identity | |
| `item_id` | bigint FK → `item.id` | |
| `submission_id` | bigint NULL FK → `submission.id` | NULL reserved for future curator direct-edits |
| `field` | varchar(80) | attribute key, `name`, or `state` |
| `old_value` | jsonb NULL | |
| `new_value` | jsonb NULL | |
| `changed_by` | bigint FK → `users.id` | the deciding curator |
| `changed_at` | timestamp | |

**Append-only** — no UPDATE/DELETE path exists in code. Index `(item_id, changed_at)`. Time-partitionable from day one: no cross-table FK *into* it, timestamp always set, id not referenced elsewhere.

### 4.3 Lifecycle

- **new**: intake creates `item` (state `submitted`, source `user`, `source_ref` `sub:<submission-id>`) + `submission` in one transaction. Approve → `item.state = unverified` + one history row (`field: state`). Reject → `item.state = rejected` (row kept, never served). Needs-info → no item change.
- **edit**: intake creates `submission` only. Approve → apply `changes` to `item.attributes` (and `name` if changed), one history row per field, `item.updated_at` bumps. If the item's current value differs from the recorded `was` (item changed since submit), the history row records the **actual current value** as `old_value` — history never lies. Reject / needs-info → no mutation.
- `submitted` and `rejected` items are excluded from the public catalog payload; `submitted` items feed the curator-only pending layer.

## 5. Services

- **`CatalogContributionService`** — implements the existing `ContributionStubInterface` (interface kept; stub implementation retired). `submit('new'|'improve', …)` persists per §4.3 and returns a real receipt (submission id, status URL). `submit('vote', …)` keeps the un-persisted receipt with an explicit comment (non-goal).
- **`SubmissionQueue`** — replaces `SampleQueue`: `filtered(country, region, type)`, `countries()`, `regions()` from the DB (pending + needs-info rows), Collator hoisted to a cached property (§13).
- **`ModerationService::decide(Submission, decision, User $curator, ?string $note)`** — transactional: status + decision columns, item mutation, history rows. The only write-path for decisions; `ModerateController::decide()` delegates to it.

## 6. Controllers & UI

- **`ContributeController`**: `add-climb` submits `new`; `improve` requires `?item=<id>` (the edit-bridge already sends `item`, `type`, `lat`, `lng`) — loads the item, prefills the registry-driven form with current attribute values, computes was/now server-side on submit. Bare `/improve` (no valid item): no more fake default editor — an explainer panel linking to the map ("pick a place to improve"). Receipt pages link the new dashboard list.
- **`ModerateController`**: queue from `SubmissionQueue`; non-AJAX decide branch becomes **redirect-after-POST preserving `country`/`region`/`type`** (§13); `TYPES` const deleted in favour of `SubmissionType` (§13).
- **`MapController`**: `CC_PENDING` built from pending submissions (shape stays `{id, type, letter, country, region, title, lat, lng, who, when, body, was, now}` — the SampleQueue shape is the de-facto contract with `map.js`).
- **`map.js`**: `buildRecord` pending branch HTML-escapes `title`/`body`/`was`/`now` (§13 — real stored-XSS surface now); `moderationToken()` rejects on selector miss (§13).
- **Account dashboard**: "My contributions" — title, type, status chip, date, decision note when present. Read-only list, newest first, no pagination until it hurts.
- **i18n**: new keys (statuses, receipt copy, improve explainer, dashboard list) in EN/FR/NL/DE, messages domain, path-prefix routing as everywhere.
- **Rate limiting**: sliding-window limiter on `submit` — 20 submissions/hour per user — via the already-installed `symfony/rate-limiter`; 429 with a translated message.

## 7. Security

- Intake `ROLE_USER`, decisions `ROLE_CURATOR` (unchanged); CSRF as-is (`_token` fetch pattern).
- Submissions are the first **user-authored content rendered to other users** (curators): client-side escape in the drawer (§13) + Twig autoescape on `/moderate` (already on) + length caps in form validation.
- No raw SQL from user input anywhere; spatial resolution via parameterised `ST_Contains`.

## 8. Testing

KernelTestCase + DAMA transactions, mirroring phase A:

- **Intake**: `new` creates item+submission atomically with resolved country/region; `edit` snapshots was/now against the live item; unbound improve is refused; rate limiter fires.
- **Decisions**: approve-new flips state + history row; approve-edit mutates attributes + one history row per field + stale-`was` correction; reject/needs-info mutate nothing; decision columns filled; ROLE gates enforced.
- **Queue**: filter semantics ported from `SampleQueueFilterTest` (which retires with `SampleQueue`); filter context survives the decide POST.
- **Serving**: `submitted`/`rejected` excluded from `/map/catalog.json`; pending payload present for curator, absent for rider.
- **History invariant**: no code path updates or deletes `change_history` rows.
- Final browser pass with `curator@map.test` / `rider@map.test`; Playwright only at acceptance (per project practice).

## 9. Rollout / retirements

- One migration (both tables). Dev + test DBs migrate as usual.
- Retired: `SampleQueue` (+ its fixtures + `SampleQueueFilterTest`), `ContributionStubService` (+ its test). `ContributionStubInterface` name kept this phase to limit churn; renaming to `ContributionInterface` can ride a later cleanup.
- Specs updated: catalog spec §11 phase-B row marked executed; moderation spec §13 items checked off.

## 10. Execution note (agentic experiment)

This is the first plan executed **with** the knowledge graph (`.understand-anything/`, auto-update on): every task dispatch carries a one-line pointer instructing the subagent to orient via the graph (query or read relevant nodes — never load the whole file). Plans 1+2 transcripts are the no-graph baseline; compare exploration tool-calls before first edit, read precision, re-reads, and net tokens per task.
