<!-- SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0 -->

# Moderation & Contribution

**Status:** canonical reference · **Audience:** contributors to Cycling Commons

This document owns the contribution intake and moderation machinery for catalog
items: the `/improve` wizard, the `SubmissionDraft` validation boundary, the
`submission` / `change_history` persistence contract, apply-on-approve
semantics, the moderation surfaces and their gating, the user-messages feedback
system (M1–M12), retention and Trash, moderator area scoping, the utility
confirmation loop, and country-interest/curator-application signup. Sibling
documents own the surrounding contracts:

- [edit-items/README.md](edit-items/README.md) — per-type edit contracts, media
  & consent rules, the lifecycle/votability funnel, verification threshold (X).
- [osm-data-architecture.md](osm-data-architecture.md) — materialize-on-edit
  (osm-data-architecture.md §6) and how the pipeline here becomes the boundary
  crossing for OSM-backed items.
- [route-domain.md](route-domain.md) — the K carve-out: route proposals,
  corrections and votes never enter the item pipeline; `/improve` refuses
  `type=K`. Route moderation reuses only the shared systems defined here
  (messages, Trash, retention, moderator areas).
- [catalog-data-model.md](catalog-data-model.md) — the `item` table, states,
  identity and serving.
- [account-and-auth.md](account-and-auth.md) — `ROLE_CURATOR`, mandatory 2FA
  for elevated roles, admin desk patterns.
- [security-architecture.md](security-architecture.md) — stateless CSRF,
  escaping rules, sanitizer.

---

## 1. The `/improve` contribution wizard

`/improve` is a single-column 4-step wizard (`web/templates/contribute/improve.html.twig`,
`web/assets/contribute/improve.js`) for adding and editing items: **1 Locate →
2 Details → 3 Photos & video → 4 Review & submit**. Step 2 renders the type's
*Fix-details* + *Add-missing* registry fields (`App\Form\ImproveType` over
`CatalogFormRegistry`); step 3's media/consent contract is owned by
[edit-items/README.md](edit-items/README.md).

### 1.1 Entry-point matrix

The map drawer builds these URLs (`web/assets/map/map.js`) and `improve.js`
interprets them — a real cross-file contract:

| From (drawer) | Link params | Step 1 (LOCATE) behaviour |
|---|---|---|
| "+ add" on an empty field | `item`, `type`, `field=<key>` | **Skipped** — wizard starts on step 2, step-1 stepper chip hidden, the matching field scrolled to and focused |
| "✎ Edit this item" | `item`, `type`, `name`, `lat`, `lng` | **Compact confirm-map** (point types only): view-only reassurance, Next enabled without interaction, expandable via "◎ Change location" |
| "◎ Fix location" | as edit + `fix=location` | **Expanded editor** directly — pin pre-placed and repositionable, search visible |
| "Add a new place" | `mode=add` | Full locate editor (pin / two-tap segment per `LocationMode`) |

**mode=add server side (restored 2026-07-30).** The unbound-explainer change
(`a6c8cdd`) had silently killed this row's server half: the controller only
looked for a bound `item`, so every /contribute card landed on the explainer.
Restored as: `ContributeController::addPlace()` renders the wizard
(`ImproveType` `add_mode: true` — which injects a **required name** into the
details pane, since several field sets carry none) for every type except B
(dedicated /add-climb) and K (/propose-route);
`CatalogContributionService::submitAdd()` persists it as a NewItem submission
(§3.3), with the two drawn endpoints of a segment-located type stored as the
`segment` attribute (added to `AttributeVocabulary` `EXTRAS['A']`). A stale
deep link carrying junk `item` + `mode=add` degrades to the add wizard, not
the explainer (ImproveTest::testDeepLinkWithItemAndModeReturns200).
Covered end-to-end by `AddPlaceFlowTest`.

**?ref= arm — materialize-on-edit (2026-07-30, same wizard).** The coverage
drawer's edit link for an uncurated OSM POI opens
`/improve?ref=<node|way/id>&type=<slug>`: the add wizard with the POI's name
prefilled and location given; submit is the `'add'` intake with `_osm_ref`
threaded through (re-read from the query string, never a form field), which
mints the item carrying the ref (osm-data-architecture.md §6). An
already-SERVED ref 302s to the bound item edit; a Submitted twin is rejected
at intake (`already_materialized`); unknown/malformed refs degrade to the
explainer. Covered by `MaterializeFlowTest`.

The mode gate in `improve.js`:
`LOCATE = (ADD || hasCoords || RELOCATE) ? locationMode : 'off'`, where
`RELOCATE` is `fix=location` and `hasCoords` means valid `lat`+`lng` params.

- **`field=<key>` contract:** the key is guarded to an alphabetic token and
  matched against both `improve[details][<key>]` and `improve[extras][<key>]`
  (registry fields are nested under the `details`/`extras` sub-forms). Unknown
  key: no-op, wizard simply starts at step 2.
- **Compact confirm-map:** pan/zoom allowed, pin **not** repositionable until
  explicitly expanded; once expanded it stays expanded for the session. Sizing
  is CSS state on `#wmap` (improve template inline styles): full map
  `clamp(420px,74vh,720px)`, `#wmap.confirm` `clamp(280px,40vh,420px)`.
- **"◎" is the shared location glyph** in the drawer and the wizard — unicode,
  not emoji (Chrome emoji rendering precedent). The drawer's "Fix location"
  label is localized via the `CC_I18N` drawer bundle (`D.fixLocation`), like
  every other drawer string.
- The "Fix location" link renders only when the item has coordinates *and* a
  real DB id (same guard as the edit link).

### 1.2 Gating and search

- **Step 1 is the only gated step**: Next is disabled until `WZ.loc` is set —
  a pin placed (point), both endpoints placed (segment), or the location known
  from the entry point. Steps 2–4 are never gated; step 4's Next becomes
  "Submit for review →".
- **Place search is keyless Photon** (`photon.komoot.io`, called client-side
  from `improve.js`), debounced **320 ms**. Mandatory offline fallback: on
  fetch failure the results dropdown shows "Search unavailable — tap the map
  instead" and map-tap keeps working — the geocoder is never load-bearing.
- The Back/Next bar is a sticky bottom row (`.navrow`, `position: sticky`) so
  the confirm control stays reachable under a tall map.

### 1.3 Segment carrier

Segment-located types (road surface, `LocationMode::Segment`) POST their drawn
endpoints through a **hidden `segment` field** added by `ImproveType`: the
wizard writes `{"a":[lng,lat],"b":[lng,lat]}` JSON when both taps are placed
and clears it on reset/incomplete. The value lands in `Submission::payload`.
The drawn endpoints must never live only in JS memory — that was a real
data-loss bug class this contract closes.

### 1.4 Edit binding and refusals

`ContributeController::improve()` binds the edit flow to a real item:

- The `item` query param must be a digit string resolving to an item in an
  editable state; a slug, missing id, or unknown id falls through to the
  **unbound explainer** (`improve.unbound_*` keys, CTA to the map) — never a
  fake default editor.
- A `type`/letter mismatch between the param and the resolved item also falls
  through to the explainer (editing the wrong item is worse than editing none).
- **`type=K` is always refused** (route/item id-collision guard): route ids
  live in `recommended_route`, a separate sequence from `item`, so resolving a
  K id against the item table would bind an unrelated item. Riders interact
  with routes via the community loop ([route-domain.md](route-domain.md)).
- The form is prefilled with the item's current `name` + attribute values;
  was → now is computed server-side at submit (§3.2), never trusted from the
  client.

## 2. Intake boundary: `SubmissionDraft`, validate twice

One envelope DTO, not eleven: `App\Contribution\SubmissionDraft` carries
`ItemType $type`, `title`, `lat`/`lng`, `array $attributes` (registry-keyed),
`?int $itemId`, `?string $note`. Per-letter field shape stays registry data
(`CatalogFormRegistry`), not classes. Constraints on the DTO:

- `title`: `NotBlank`, `Length(max: 200)`,
  `NoSuspiciousCharacters(locales: [en, fr, nl, de])`, plus a
  `Regex(/\p{Cf}/u, match: false)` that closes the lone-zero-width-character
  gap ICU's spoof checker leaves open (the same guard is centralized in
  `CatalogFieldConstraints` for registry text fields).
- `lat`/`lng`: `Range(±90 / ±180)`; `note`: `Length(max: 2000)`.
- Class-level `#[ValidAttributes]` validates the attribute map against
  `AttributeVocabulary` — the same vocabulary the importer enforces, so intake
  and import can never diverge.

**Validate twice, define once:** forms validate on submit (UX);
`CatalogContributionService::submitDraft()` re-validates the DTO via
`ValidatorInterface`. The service-side pass is the only validation a future
JSON API path would get, so nothing may rely on form-only checks.

**Rate limiting** (thresholds are config, not literals — TDD asserts against
these keys):

| Limiter | Policy | Current value | Where |
|---|---|---|---|
| `contribution_submit` | sliding window, per user | 20 / hour | `web/config/packages/rate_limiter.yaml` |
| `route_propose` / `route_suggest` / `ride_check` | own limiters | full inventory: [security-architecture.md](security-architecture.md) | same file; contract owned by [route-domain.md](route-domain.md) |

A 429 surfaces as a translated message (`contribute.error.rate_limited`). Note
an ordering asymmetry that is deliberate per channel: item intake consumes a
limiter token **before** validating the draft
(`CatalogContributionService::submitDraft()`), while the route-suggest channel
runs its cheap validation before consuming quota (a 422 must not cost a token
there — expensive GPX work still consumes first; see
[route-domain.md](route-domain.md)).

Geometry and jurisdiction are resolved **at submit**: `SpatialResolver`
derives `country_code` + `region_id` with the same parameterised spatial
containment logic as import. For edits on non-point items, a representative
point (first coordinate of the item's geometry) anchors the submission pin.

**What intake does not accept — `'vote'` is a deliberate non-goal.**
`CatalogContributionService::submit()` routes exactly two kinds:
`'climb'` → a new-item submission, `'improve'` → an edit submission. Every
other kind falls to a `default` arm that **persists nothing** and returns a
`ContributionReceipt` with `persisted: false` and a throwaway `CC-<random>`
reference. That arm is live, not dead code: `POST /vote`
(`ContributeController::vote()`, gated `ROLE_USER`) sends `'vote'` through it
on every submission.

This is intended. Voting is verification-gate machinery — a rider signalling
that a place or route is real — and that funnel is owned elsewhere
(edit-items/README.md for items, [route-domain.md](route-domain.md) §6 for
routes). It is not catalog intake, so it produces no `submission` row and no
curator task. The vote page states this plainly rather than implying a
recorded tally (`vote.receipt.stub_note`: "this is a preview — votes are
queued for review and not yet persisted to a live tally").

**Do not "fix" the `default` arm by persisting a submission for `'vote'`.**
Wiring voting to a real tally means building it on the verification gate, and
then `/vote` should stop calling this service at all.

## 3. Submission persistence

### 3.1 `submission` (entity `App\Catalog\Entity\Submission`)

| column | type | notes |
|---|---|---|
| `id` | bigint identity | receipt ref is `SUB-<id>` |
| `type` | varchar(8), enum `SubmissionType` | `new` \| `edit` \| `hazard` \| `photo`; queue renders all four, intake produces `new`/`edit` only (§8) |
| `letter` | varchar(1) | effective range A–J (K bypasses this table) |
| `item_id` | bigint NULL | set for `edit` at submit; set for `new` when the item row is created in the same transaction |
| `user_id` | bigint | submitter — deliberately **no FK** (survives account deletion as anonymous data; see §5.6) |
| `status` | varchar(12), enum `SubmissionStatus` | `pending` \| `approved` \| `rejected` \| `needs_info` |
| `title` | varchar(200) | queue/drawer display |
| `geom` | geometry(Geometry, 4326) | pending-pin location — always a Point in practice |
| `country_code` / `region_id` | varchar(2) / bigint NULL | resolved at submit (§2) |
| `changes` | jsonb | `{field: {"was": …, "now": …}}` (§3.2) |
| `payload` | jsonb | raw validated form data, durable record of what was submitted (includes the segment carrier, climb geometry, non-registry form fields) |
| `decision_note` / `decided_by` / `decided_at` | text/bigint/timestamp, NULL | decision columns live **on the row** — the submission row is the institutional audit record; no separate decision table. `admin_action_log` stays reserved for admin-on-user actions |
| `created_at` | timestamp | |

Indexes on `status`, `country_code`, `region_id`, `item_id`, `user_id`, plus
a GIST index on `geom` (`idx_submission_geom`).

### 3.2 The `changes` contract

- Computed **at submit against the item's then-current values**; only fields
  whose proposed value differs are recorded — unchanged fields never appear.
- Empties (`''`/`null`/`[]`) are normalised to `null`, so clearing a prefilled
  field is recorded as a removal (`was → null`), while an always-empty field
  records no phantom change.
- The `name` pseudo-field compares against `Item::name`
  (`Item::NAME_FIELD`), not the attribute map.
- For `new` submissions, `changes` is the proposed field set as
  `{field: {was: null, now: …}}`.

### 3.3 New-item intake

`new` intake creates the `item` row (state `submitted`, source `user`,
`source_ref` = `sub:<submission-id>`) and the submission **in one
transaction**. `submitted` and `rejected` items are excluded from the public
catalog payload (serving contract:
[catalog-data-model.md](catalog-data-model.md) §4, §9); `submitted` items feed
only the curator pending layer (§6).

## 4. Apply-on-approve and change history

`App\Moderation\ModerationService::decide()` is **the only write path for
decisions** — one transaction with a pessimistic row lock (two curators racing
on the same submission: the second sees the decided status and gets
`AlreadyDecidedException`). Semantics per decision:

- **approve, `new`** → `item.state` flips `submitted → unverified`; one
  `change_history` row (`field: state`).
- **approve, `edit`** → each `changes` field is applied to
  `item.attributes` (or `Item::name`); a `now` of `null` **removes** the
  attribute; one history row per actually-applied field; `item.updated_at`
  bumps. **History never lies:** the history row's `old_value` is the item's
  *actual current value at apply time*, never the submitter's possibly-stale
  `was` snapshot; a field whose current value already equals `now` is skipped
  entirely. An edit whose target item has vanished fails loudly (transaction
  rolls back) rather than silently approving with no effect.
- **reject** → `submission.status = rejected`; for `new` submissions the item
  row flips to `rejected` but is **kept** (never served) until retention GC
  (§8). Rejecting an `edit` never touches the item.
- **needs_info** → mutates nothing on the item; the submission leaves the map
  layer but stays on the queue (§6.2).

Every decision also fills `decision_note`/`decided_by`/`decided_at` and writes
the submitter's outcome message inside the same transaction (§7.2). Decisions
require `ROLE_CURATOR` (access-control rule `^(/(fr|nl|de))?/moderate` →
`ROLE_CURATOR` in `web/config/packages/security.yaml` — locale-prefixed) and
pass the moderator area guard (§9).

### 4.1 `change_history` (entity `App\Catalog\Entity\ChangeHistory`)

| column | type |
|---|---|
| `id` | bigint identity |
| `item_id` | bigint |
| `submission_id` | bigint NULL (NULL reserved for future curator direct-edits) |
| `field` | varchar(80) — attribute key, `name`, or `state` |
| `old_value` / `new_value` | jsonb NULL |
| `changed_by` | bigint (the deciding curator) |
| `changed_at` | timestamp |

**Append-only invariant:** no UPDATE or DELETE code path exists for this table
(tested invariant — a suite check, not just convention). No FK points *into*
it and `changed_at` is always set, so it is time-partitionable from day one.
It is the durable record behind the `[edit]` provenance tag, the drawer's
was → now diff and public history panel (`ChangeHistoryView`), and the
"last edited" freshness line. Routes have their own `route_change_history`
([route-domain.md](route-domain.md)); the two never mix.

## 5. Moderation surfaces

### 5.1 Decisions live off the queue lists

A curator must see the item in place before deciding:

- **Item submissions are decided ONLY in the map drawer** — the queue row
  links "View on map" → `/map?pending=<id>`, which flies to and selects the
  pending pin; approve/needs-info/reject live in the drawer's moderate block.
- **Route proposals are decided ONLY on their detail page**
  (`/moderate/routes/{id}` — [route-domain.md](route-domain.md)).
- The queue list pages render **no decision forms**
  (`ModerateController::renderQueue()`); `POST /moderate/decide` remains the
  single decision endpoint, content-negotiated: JSON for the drawer's AJAX
  POST (`X-Requested-With` / `Accept: application/json`), redirect-after-POST
  preserving the curator's active `country`/`region`/`type` filters for the
  HTML path.

### 5.2 `/moderate` — filterable world overview

The queue (`App\Moderation\SubmissionQueue`) lists `pending` + `needs_info`
submissions, filterable by country / region / type via query params. Filters
are **presentational**; the authorization boundary is moderator-area scoping
(§9), applied to rows, counts, and filter dropdown options alike. The row
shape returned by `SubmissionQueue` is a deliberate shared view-model consumed
by both `moderate/index.html.twig` and `map.js` (as `CC_PENDING` JSON):
`{id, itemId, type, letter, country, region, title, lat, lng, who, when,
body, was, now, riderReply}` — `who` is the stable pseudonym
`RiderPseudonym::for()` (`rider#<hash4>`), `was`/`now` are the server-joined
diff strings. Contributor identity is never exposed to curators beyond the
pseudonym.

### 5.3 Curator payload gating on `/map`

`/map` stays public. `MapController::map()` emits the pending payload
(`window.CC_PENDING`, `window.CC_IS_CURATOR`, `window.CC_MOD_TOKEN`) **only**
when *both* hold:

1. `is_granted('ROLE_CURATOR')`, and
2. mandatory 2FA is complete — `!TwoFactorPolicy::requiresSetup($user)`. `/map`
   is on the 2FA-setup enforcer's bypass list (public, cacheable), so without
   this second gate a setup-pending curator would receive un-vetted submission
   data before finishing 2FA.

For anyone else the page source contains **zero** pending data — gating is by
absence, backed by the decision endpoint's own `ROLE_CURATOR` firewall rule.
The `?pending=<id>` deep link silently no-ops for non-curators.

### 5.4 Drawer decision UX

- `window.CC_MOD_TOKEN = csrf_token('submit')` — `submit` is the app-wide
  form token id ([security-architecture.md](security-architecture.md)), so
  the drawer POSTs the same token the queue form family uses; CSRF is never
  disabled. The drawer rejects if the token is somehow absent (fail loudly,
  not a confusing 4xx).
- One **optional note** may accompany ANY decision, including approve;
  approve-with-empty-note is the fast path. Note length: `Length(max: 2000)`
  on `ModerationDecisionType`.
- Keyboard: **A**/**R** *arm* the decision (ring on the button, focus moves to
  the note); **Enter** in the note sends it. Never instant-submit. Mouse
  clicks submit immediately.
- Pending pins are red-bordered teardrops (`#D92D20`, ⏳ icon) with a
  curator-only legend entry, on by default; tooltip = type · submitter
  pseudonym · age. The colour is deliberately outside the votability palette —
  pending items are not on the trust axis (moderation is the pre-public
  spam/abuse/duplicate gate, per the funnel in
  [edit-items/README.md](edit-items/README.md)).
- All submission-derived strings are HTML-escaped client-side in the drawer
  (first user-authored content rendered to other users).

### 5.5 Map layer vs queue visibility

`SubmissionQueue::pendingForMap()` serves **strictly `pending`** rows —
needs-info pins are hidden from the map until the rider answers (§7.3), while
the queue list shows both `pending` and `needs_info`.

### 5.6 User-keyed rows and account deletion

`submission.user_id` (like `route_vote`/`route_ride`/`route_suggestion`
user ids) is a plain no-FK bigint by house convention: contributed data
survives account deletion as anonymous rows, by decoupling rather than
cascade. The single documented exception is `user_message` (§7.6).

## 6. Trash — immediate hard delete for spam

`ModerationService::trashSubmission()` (POST `/moderate/trash`, CSRF token id
`moderate-trash`):

- Permanent hard delete, **any status is legal** for item submissions (route
  proposals carry their own submitted/rejected-only guardrail —
  [route-domain.md](route-domain.md)).
- **Audit-before-delete, content-free**: an `AdminActionLogger` row with the
  `TrashActions` constant + `SUB-<id> · type=<x>` only — unvetted rider text
  (titles, bodies) never enters the immutable log.
- **No message is sent** to the submitter — trashing never feeds spam.
- No retention window; Trash exists precisely because policy-violating content
  must not be kept 3 months (also the "policy violation" edge in
  osm-data-architecture.md §6).

## 7. Messages — the moderation feedback system (M1–M12)

### 7.0 What the M-codes mean

`M1`–`M12` are the twelve decisions that define this system. Code comments
and the section headings below cite them by number, so this table is what
those citations resolve to. Not all of them live in §7: Trash (M9) and
retention (M8) have their own sections. Everything here is built except
M7 (email) and M8's phase-2 scheduled runner, which are specified and
pending, and M12, which is deliberately out of scope — see §7.8.

| Code | Decision | Where |
|---|---|---|
| M1 | One `UserMessage` entity carries all moderation feedback. It is the recipient's inbox copy, **not** the institutional audit trail — that stays on `Submission.decisionNote`/`decidedBy`/`decidedAt` and `route_change_history`, which survive account deletion. | §7.1 |
| M2 | Every decision on every channel writes a message inside the decision's own transaction, so the pair is atomic and duplicate-proof. | §7.2 |
| M3 | Riders read their messages on a Messages page, reachable from the account shell's tab row. Shipped as its own route (`/messages`, mark-all-read on view), not as a `?tab=` panel inside `/profile` as originally designed. | §7.3, [account-and-auth.md](account-and-auth.md) §8 |
| M4 | Unread bulb on the shared account chip, server-rendered once per page load. No polling. | §7.5 |
| M5 | The map page carries the account chip too, so the bulb reaches the biggest logged-in surface. | §7.5 |
| M6 | Curator → rider messaging, and the rider's reply to a needs-info request, which flips the submission back to `pending`. Both directions stay pseudonymous. | §7.3, §7.4 |
| M6a | The curator → rider direction specifically (`curator_message`). | §7.4 |
| M7 | Email as a later delivery channel on top of messages. **Specified, pending implementation.** | §7.8 |
| M8 | Retention: dismissed/rejected contributions are kept `moderation.retention_months` (3), then collected. Phase 1 is lazy filtering plus an opportunistic sweep; the scheduled runner is phase 2. | §8, §7.8 |
| M9 | Trash: immediate permanent hard delete for spam or abuse. No retention, no message sent, and a content-free audit row written before the delete. | §6 |
| M10 | `user_message.user_id` carries a real `ON DELETE CASCADE` FK — the schema's only user FK, and a documented exception to "contributed data is anonymised, never cascade-deleted". Messages are correspondence *to* a person, not contributed content. | §7.6, §5.6 |
| M11 | Message and note bodies have an explicit length cap (`MessageService::BODY_TEXT_MAX_LENGTH`, 2000) and are HTML-escaped on every render. | §7.2, §7.4 |
| M12 | Account lock/ban is **not** part of this system. Trash handles the content; the account is the admin desk's job. | §7.8 |

### 7.1 `UserMessage` (entity `App\Messaging\Entity\UserMessage`) — M1

Columns: `user_id` (recipient), `kind` (enum `UserMessageKind`), `sender`
(`system` \| `curator` \| `rider`), `sender_id` (NULL for system), `channel`
(`submission` \| `route` \| `correction`), `ref_id`, `ref_label` (the human
receipt, e.g. `SUB-42` or a route name), `body_key` + `body_params`
(translation key + params for system messages), `body_text` (curator note /
free-form body, verbatim), `created_at`, `read_at`. Index on
`(user_id, read_at)` backs the unread count.

**Inbox, not audit:** the message is the recipient's cascade-away inbox copy.
The institutional record of who decided what and why stays where it survives
account deletion: the submission row's decision columns (§3.1) and
`route_change_history`. Neither direction ever exposes a display name —
`sender` is a role string, `sender_id` an id for audit only; riders appear as
`rider#hash`.

Kinds: `submission_approved` / `submission_rejected` / `submission_needs_info`
/ `route_approved` / `route_rejected` / `route_retired` / `correction_done` /
`correction_dismissed` / `curator_message` / `rider_reply`.

### 7.2 Every decision writes a message, in-transaction — M2

`MessageService::sendSystem()` **persists without flushing** and is called
from inside the decision's `wrapInTransaction` closure, so the message commits
atomically with the status flip; the already-decided guards make the pair
duplicate-proof (at most one message per outcome). If the recipient's account
is already gone (`users` existence check), `sendSystem` returns `null` rather
than violating the FK — the decision itself is never lost.

System bodies render via translation keys (`messages.body.<kind>`) in the
*viewer's* locale; the curator's note, when present, rides along in
`body_text` and renders verbatim (escaped). Body cap:
`MessageService::BODY_TEXT_MAX_LENGTH` (`web/src/Messaging/MessageService.php`),
currently **2000** characters — shared by notes, curator messages and replies
(M11).

### 7.3 Needs-info reply loop — M6

- The Messages page (`/messages`, mark-all-read on view) renders a reply form
  on `submission_needs_info` messages whose submission is still in
  `needs_info` status and still has a live deciding curator.
- `POST /messages/{id}/reply` (CSRF `message-reply`, recipient-ownership
  checked): writes a `rider_reply` message **to the deciding curator**
  (`decided_by`) and flips the submission `NeedsInfo → Pending` in the same
  transaction, re-entering the queue. Stale cases (already re-decided, curator
  account gone) flash `messages.reply_too_late` instead.
- The latest rider reply surfaces on the queue row for every curator
  (`SubmissionQueue` joins the newest `sender='rider'` message per
  submission).

### 7.4 Curator → rider messages — M6a

`POST /moderate/message` (`ModerateMessageController`, `ROLE_CURATOR`
firewall, CSRF `moderate-message`) is the one endpoint for a free-form desk
message on any channel; a `channel` discriminator (`submission` / `route` /
`correction`) selects the referenced row and the **recipient is always
resolved server-side** from it, never trusted from the request. The target's
region must be in the curator's moderation scope (§9). Redirect-back trusts
only the Referer's *path*, and only when it points into `/moderate` (open
redirect guard). Imported routes (no proposer) and dangling author ids resolve
to "no recipient".

### 7.5 Unread bulb — M4/M5

Server-rendered per page load by a Twig extension (one COUNT query) inside the
shared `_account_chip.html.twig` partial — **no polling**, consistent with the
no-fetch header architecture. The map rail carries the chip, so the bulb
reaches the largest logged-in surface.

### 7.6 The FK exception — M10

`user_message.user_id` carries the schema's only real user FK, `ON DELETE
CASCADE` (migration `Version20260712150000`): messages are correspondence *to*
the person, not contributed catalog content, and a DB-level cascade covers
both account-deletion paths (self-service hook chain *and* the admin desk's
`removeAccount`, which bypasses deletion hooks). This is a documented
exception to the "contributed data is anonymised, never cascade-deleted" rule
(§5.6); a practical consequence is that decision-path test fixtures must
persist real users — fabricated ids violate the FK the moment a decision
writes a message.

### 7.7 Map links per kind

Messages link to their subject only when it is publicly on the map:
`submission_approved` → `/map?feature=<refLabel>`; `route_approved` and
`correction_done` → `/map?route=<refId>`. Rejected / retired / needs-info
kinds carry no link (subject not publicly visible).

### 7.8 Out of scope here

**M7 email delivery** and the **M8 phase-2 scheduled GC runner** are
**specified, pending implementation** — deferred together as one
messenger/scheduler infrastructure investment (messages remain the record;
email is a delivery channel on top, rendered per `User.locale`). **M12
account lock/ban** is explicitly *not* part of this system: Trash handles the
content, the account is the admin desk's job
([account-and-auth.md](account-and-auth.md)).

## 8. Retention and garbage collection — M8 phase 1

| Key | Value | Where |
|---|---|---|
| `moderation.retention_months` | 3 | `web/config/packages/moderation.yaml` (wired via `web/config/services.yaml`) |
| opportunistic-sweep throttle | 3600 s | `RetentionService::CACHE_TTL_SECONDS`, `web/src/Moderation/RetentionService.php` |

`RetentionService` deletes rows past the cutoff for exactly two kinds:
**rejected item submissions** (`decided_at < cutoff`) and **dismissed route
corrections** (`resolved_at < cutoff`). Three runners, no scheduler yet:

1. **Lazy point-of-use filtering** — reads exclude expired rows regardless of
   whether a sweep ever ran (e.g. `ProfileController`'s contributions list
   filters rejected-past-cutoff rows in the query). This is the correctness
   layer; sweeps are hygiene.
2. **Opportunistic sweep** on moderation desk renders — fire-and-forget,
   cache-throttled, never throws (a failed sweep must not break the desk).
3. **`app:moderation:gc`** — idempotent standalone console/cron entry point.

Deliberately **not** swept (open decisions, §13): rejected
`recommended_route` rows, never-answered `needs_info` submissions, and
approved rows.

## 9. Moderator areas — region/country scoping

### 9.1 Model

`moderator_area` (entity `App\Moderation\Entity\ModeratorArea`): `user_id`
(FK → `users`, ON DELETE CASCADE) plus **exactly one of** `region_id`
(FK → `region`, ON DELETE CASCADE) or `country_code` (VARCHAR(2), ISO 3166-1
alpha-2), enforced by the DB CHECK constraint
`(region_id IS NULL) <> (country_code IS NULL)` (migration
`Version20260714210000`); unique over `(user_id, region_id, country_code)` —
created `NULLS NOT DISTINCT`, without which PG would permit duplicate rows
through the NULL column.
A user's scope is the union of their rows. Rows are storable for any user but
inert without `ROLE_CURATOR`.

### 9.2 Scope semantics

- **Unassigned = global**: a curator with zero rows moderates everything
  (rollout-safe, right for small teams). `ROLE_ADMIN` is always global
  regardless of rows.
- **NULL-region items are in scope for every curator** — deliberate, so
  outside-all-regions submissions never fall through the cracks.
- The in-scope rule exists exactly twice, as verified twins:
  `ModerationScope::sqlFragment()` (parameterised SQL used by both queues;
  omitted entirely when global; empty lists bind impossible sentinels so the
  `IN ()` clauses stay valid; the table alias is allowlist-guarded because it
  is interpolated) and `ModerationScopeProvider::allowsRegion()` (the PHP
  write-guard predicate). Rule: `region_id IS NULL` OR `region_id ∈ regionIds`
  OR the region's `country_code ∈ countryCodes` (codes uppercased on both
  sides).

### 9.3 Enforcement — filtering AND hard guards

Hidden queue rows are not a security boundary. Scoping applies to:

- **Reads**: `SubmissionQueue` (rows, `pendingForMap` / `CC_PENDING`, totals,
  badge counts, filter dropdowns) and `RouteQueue` likewise.
- **Writes (403 out of scope, `OutOfScopeException`)**: `/moderate/decide`,
  `/moderate/trash`, every route moderation write
  (approve/reject/retire/save, trash, correction done/dismiss), and
  `/moderate/message` (the referenced row's region). The route detail page
  itself 403s out of scope. The 403-reveals-existence trade-off is accepted as
  consistent, documented semantics.
- **Visible scope**: the moderation shell label always shows the actor's scope
  — assigned area names via `ModerationScopeProvider::describe()`, or "All
  areas" (`account.mod_scope_all`).

### 9.4 Assignment

An audited support-desk action, not an EasyAdmin CRUD:
`UserAdminService::setModeratorAreas()` validates every region id and country
code against the reference tables, replaces the target's rows, and writes the
`moderator_areas` audit entry (resulting set as the note) in **one
transaction** — the same pattern as every other admin desk mutation
([account-and-auth.md](account-and-auth.md)).

## 10. Utility confirmations — potability & "still here?"

Utilities are **confirmed, never voted** — the complement of the votability
funnel in [edit-items/README.md](edit-items/README.md).

### 10.1 Stance model

`ItemType::confirmationStances()` (`web/src/Catalog/ItemType.php`) is the
source of truth:

| Type | Stances (`ConfirmationStance`) | `stanceKind` |
|---|---|---|
| C · Water & food | `potable` / `not_potable` | `potability` |
| D · Services, F · Hazards, G · Getting there, H · Shelter | `exists` | `existence` |
| A · Road surface, all votable types | none — they vote, or are measured | — |

`ItemType::isConfirmable()` = "has stances". One stance **per rider per item**
(`item_confirmation`, UNIQUE `(item_id, user_id)`, tally index
`(item_id, stance)`), **switchable** — flipping potable ↔ not-potable updates
the row and never double-counts (`ItemConfirmationService::record()` rejects
stances the item's type does not offer).

### 10.2 Endpoint contract (`ItemConfirmationController`)

- `GET /items/{id}/confirmations` — `PUBLIC_ACCESS` (explicit rule in
  `security.yaml`, for cacheability past the lazy 2FA firewall): public
  tallies keyed by the offered stances, `total`, `stanceKind`; the viewer's
  own `mine` stance and a CSRF token (**token id `item-confirm`**) only when
  authenticated.
- `POST /items/{id}/confirm` — requires `ROLE_USER` with a **clean 401**
  (never a login redirect) + CSRF (token id `item-confirm` — **not** in
  `stateless_token_ids`, so session-backed as built; the deviation and its
  resolution options are recorded in
  [security-architecture.md](security-architecture.md) §5.1/§8);
  unknown/unoffered stance → 422.
- Votable or unserved items **404** on both endpoints (served =
  `ItemState::SERVED`, serving contract:
  [catalog-data-model.md](catalog-data-model.md) §4, §9; mirrors the route
  community-loop API pattern).

### 10.3 Drawer surface

The generic vote CTA is gated to votable layers (`CC_VOTABLE`), the
confirmation panel to confirmable layers (`CC_CONFIRMABLE`) — both are layer
key sets in `map.js` mirroring `ItemType::isVotable()`/`isConfirmable()`. The
panel hydrates async on drawer-open; water shows both tallies, other utilities
a single confirm; anonymous viewers see the counts plus a "Log in to confirm"
prompt (counts public, recording gated).

## 11. Curator applications — the two doors an empty map needs

Every country is empty at launch, so `/join/{cc}`
(`App\Controller\JoinCountryController`, `IS_AUTHENTICATED_FULLY`) is the one
page both signals funnel through: "I want it here" and "I'd curate it". One
route, two states, decided **server-side by whether the country already has
any `region` rows** — never by the client-submitted form — because a country
with no region has nowhere to anchor a submission and nothing to scope a
curator to.

The map rail's one-line invite (`#emptyScopeInvite`,
`web/assets/map/panels.js`) links here with the country pre-filled whenever
the active scope's curated count is zero. That count is a faithful reduction
of `featureVisible()`'s curated branch (render.js): only features on
experiential layers (`l.key === 'experience'` or `l.exp`) with `f.cur` set
count; utility layers (C/D/F/G/H) never carry a `cur` flag and can neither
suppress nor trigger the invite, so a stray hazard report or an unverified
route upload never silently hides it. This holds independent of the rider's
own Curated/Everything view toggle, and is computed once per map load.

### 11.1 `CountryInterest` — the demand signal

Entity `App\Community\Entity\CountryInterest`, table `country_interest`:

| column | type | notes |
|---|---|---|
| `id` | bigint identity | |
| `user_id` | bigint | no FK (house convention, §5.6) |
| `country_code` | varchar(2) | ISO 3166-1 alpha-2 |
| `willing_to_curate` | boolean, default false | the contact list for onboarding |
| `note` | varchar(280), nullable | hardened per §11.3 |
| `created_at` / `updated_at` | timestamp | |

`UNIQUE (user_id, country_code)`. `CountryInterestService::record()` upserts:
re-submitting for a country already on file updates `willing_to_curate`/`note`
and bumps `updated_at` rather than creating a second row.

### 11.2 `CuratorApplication` — the supply signal

Entity `App\Community\Entity\CuratorApplication`, table `curator_application`:

| column | type | notes |
|---|---|---|
| `id` | bigint identity | |
| `user_id` | bigint | no FK |
| `country_code` | varchar(2) | must already have `region` rows (`CuratorApplicationService::countryIsOnboarded()`) |
| `requested_region_id` | bigint, nullable | null = whole country; set = one division, validated to belong to `country_code` |
| `osm_username` | varchar(64), nullable | charset-checked before use (`OsmUserVerifier::isWellFormed()`) |
| `osm_verified_at` | timestamp, nullable | set only when the OSM lookup was *reachable* — null means unchecked or unreachable, never "checked and empty" |
| `osm_exists` | boolean, nullable — **tri-state** | null = never checked or OSM unreachable; true = handle found; false = checked and confirmed absent. Absence and unknown are deliberately two different values: collapsing them would let a fabricated handle render as verified on the review screen |
| `osm_changeset_count` | int, nullable | set only when `osm_exists = true` — a count is meaningless for a handle that doesn't exist |
| `about` | text | hardened per §11.3, cap 1200 chars — the only **required** form field |
| `social_url` | varchar(255), nullable | optional "where can we find you online" link (2026-07-30). Scheme-less input gets `https://` prefixed (people paste `instagram.com/handle`); after that the scheme must be http(s) and the whole thing a `FILTER_VALIDATE_URL`-valid URL ≤ 255 chars, else `social_url_invalid`. The allow-listed scheme is the XSS boundary: the review screen renders it as a clickable `target="_blank" rel="noopener noreferrer nofollow"` link. Reviewer-only, never public |
| `status` | varchar(12), enum `CuratorApplicationStatus` | `pending` / `approved` / `declined` / `withdrawn` (`withdrawn` has no UI path yet) |
| `decided_by` / `decided_at` / `decision_note` | bigint / timestamp / text, nullable | mirrors `Submission`'s decision columns (§3.1) |
| `created_at` | timestamp | |

Partial unique index `uniq_curator_application_pending (user_id, country_code)
WHERE status = 'pending'` is the concurrency guard for "one pending
application per person per country" — `CuratorApplicationService::hasPending()`
is the friendly pre-check (a clean error before a flush); the index is the
invariant that holds under a race, since Doctrine's ORM mapping cannot express
a partial index.

**Evidence lives in the submission table, not on this row.**
`CuratorApplicationService::evidenceFor()` counts the applicant's submissions
by `user_id` + `country_code` **at review time** (`total`, `approved`
`WHERE status = 'approved'`), so the review screen always reflects current
standing — filing more submissions between applying and being reviewed changes
what the reviewer sees. Nothing is copied or snapshotted onto
`CuratorApplication` at submit. The form's `join.evidence_hint` copy
deliberately does NOT state this mechanic (owner decision, 2026-07-30):
telling applicants their map edits "speak for them" read as a contribution
prerequisite, which decision 3 explicitly rejects. The copy now leads with
"you don't need to have added anything before applying", invites motivation
and prior experience (the `join.about_label` question), and only mentions
that existing contributions are gladly looked at. The reviewer-side
evidence pane is unchanged.

**Applicant-facing status (2026-07-30).** The profile dashboard's
Contributions pane closes with a **Curator applications** section
(`ProfileController`, [account-and-auth.md](account-and-auth.md) dashboard
section): the user's own applications with status pills
(pending/approved/declined/withdrawn), requested scope (region label or
"whole country") and the reviewer's `decision_note`; when none exist, a door
to the regions directory. Combined with the login target-path continuity
([account-and-auth.md](account-and-auth.md) §2 — an anonymous `/join/{cc}`
visit survives the register→verify→login detour), "where did my application
go?" always has an answer: either the form was reached and the profile shows
the row, or it never was and the profile shows the door.

### 11.3 Free-text hardening — `PublicNoteFilter`

Both `CountryInterest.note` (cap `PublicNoteFilter::MAX_NOTE` = 280) and
`CuratorApplication.about` (cap `MAX_ABOUT` = 1200) pass through
`App\Community\PublicNoteFilter::clean()` before storage. Neither field is
ever rendered on a public page — reviewer-only, escaped on render.
`clean()`, in order:

1. Unicode-normalises (NFC) so composed/decomposed duplicates cannot evade
   uniqueness.
2. Strips zero-width and bidirectional-override characters — the legacy
   embedding/override block (`\x{202A}`–`\x{202E}`) and the modern isolate
   controls (`\x{2066}`–`\x{2069}`), closing the Trojan-Source spoofing class.
3. Collapses whitespace runs and trims.
4. Rejects (`InvalidNoteException`) any remaining control character.
5. Rejects links: `scheme://…`, `mailto:` only when followed by an
   `@`-payload, `tel:` only when followed by `+`/digits, bare `www.`, and bare
   `domain.tld` — narrow enough that ordinary prose like "News:local closed"
   or "word:word" survives, because the pattern requires the shape of an
   actual link, not just a colon.
6. Rejects a length overflow **after** cleaning, not before — so a note that
   was invisible characters only is correctly judged empty/too-short by what
   survives, not by its raw length.

### 11.4 Review surface — `/admin/curator-applications`

A purpose-built `#[AdminRoute]` page
(`Admin\DashboardController::curatorApplications()`,
`admin/curator_applications.html.twig`), `ROLE_ADMIN`, following the
system-configuration.md precedent rather than an EasyAdmin CRUD: the reviewer
needs a person, their track record, their OSM standing and their words side by
side for one judgement, not sortable rows.

Per pending application: display name/email, email-verified badge, account
age, the evidence counts (§11.2), the requested scope (region name or "whole
country"), the OSM line (unverified / `<n> changesets` when found / "not found
on OSM" when `osm_exists === false` — the tri-state rendered faithfully, so a
fabricated handle never shows as verified), and the `about` text (escaped).
One decision form per row: `approve` or `decline`, an optional note, CSRF
token id `curator-applications` (session-backed — [security-architecture.md](security-architecture.md)
§5.3).

### 11.5 Approve/decline lifecycle

`CuratorApplicationService::approve()`/`decline()` are the only write paths,
each gated by an `assertPending()` guard — a `\DomainException` on an
already-decided application. The same check runs twice: the service enforces
it regardless of caller, and the controller checks status before dispatching
too, so a stale second tab or a double-submit flashes
`admin.curator.already_decided` rather than reprocessing.

`approve()` is **one transaction, all or nothing**
(`EntityManagerInterface::wrapInTransaction()`):

1. `UserAdminService::grantCurator()` — adds `ROLE_CURATOR`, and writes its
   own `grant_curator` audit row. Double audit granularity is intended: the
   role grant and the application decision are two separately meaningful
   audit facts, not one collapsed into the other.
2. A `ModeratorArea` is persisted via the ORM (not raw SQL), scoped to
   `requested_region_id` when set, or to `country_code` when the request was
   for the whole country — matching `ModeratorArea`'s CHECK constraint of
   exactly one of the two (§9.1). Flushed **inside** the still-open
   transaction, not deferred to commit, so a `uniq_moderator_area` collision —
   a second approval racing this one — rolls back the role grant with it,
   rather than leaving the applicant holding `ROLE_CURATOR` with the
   application stuck `pending` forever.
3. The application flips to `approved`, stamping `decided_by`/`decided_at`/
   `decision_note`.
4. An `AdminActionLogger` row (`curator_application.approve`).
5. A `join.message.approved` system message.

`decline()` is transactional over steps 3–5 only (no role/area writes):
status flip, an `AdminActionLogger` row (`curator_application.decline`), and a
`join.message.declined` message.

Both messages go through `MessageService::sendSystem()` inside the
transaction — the house pattern also used by `ModerationService::decide()`
(§7.2) — on **channel `curator_app`**: the natural `curator_application` name
overflows `user_message.channel`'s `varchar(12)`, so the abbreviated form is
the value actually stored.

**Approval scopes rather than promotes**: a Dutch applicant's `ModeratorArea`
is the Netherlands, never global curator. Granting stays admin-only
([account-and-auth.md](account-and-auth.md) §6.1), designed to extend to
trusted moderators later, not built.

## 12. Specified, pending implementation

- **M7 email delivery + M8 phase-2 scheduler** (§7.8, §8) — one
  messenger/scheduler investment for both.
- **M12 account lock/ban** — separate admin-desk work; recorded, unbuilt.
- **Hazard / photo intake UI** — `SubmissionType::Hazard`/`Photo` are
  queue-renderable but have no intake flow (photo needs file storage);
  `ModerationService` refuses to approve them until an apply path exists.
- **Pending route proposals on the curator map** — routes are decided on the
  detail page only (§5.1); serving them onto the map like item submissions is
  an accepted follow-up.
- **Materialize-on-edit** (osm-data-architecture.md §6) — this pipeline is the
  designated machinery; the coverage-side trigger is not yet built
  ([coverage-provider.md](coverage-provider.md)).

## 13. Open questions

- **Confirmations vs the verification threshold (X):** whether
  `item_confirmation` tallies are the counter feeding the
  Unverified → Verified gate in
  [edit-items/README.md](edit-items/README.md#verification-threshold-x) or a
  parallel freshness signal. Nothing in the code consumes the tallies for
  state transitions today; the funnel wiring is undecided.
- **Rejected route-proposal GC:** joining the 3-month sweep means deleting
  catalog rows whose `source_ref` provenance assumed permanence and orphaning
  their `route_change_history` rows — needs an M9-style content-free snapshot
  or a tombstone before inclusion.
- **Retention for never-answered `needs_info` rows** (live forever today —
  auto-reject after N months?) and for **approved** rows.
- **Read-message expiry** (do old read messages ever GC?).
- **GDPR story for free-text bodies on no-FK user rows** (correction bodies
  specifically): anonymised-by-decoupling is the deliberate default (§5.6),
  but whether authored *text* should join a deletion hook is unconfirmed.
- **`/map?feature=<refLabel>` on approved-submission messages:** `ref_label`
  for the submission channel is the `SUB-<id>` receipt, not the item name the
  map's `?feature=` lookup matches — whether this deep link resolves for all
  approved-submission messages is unverified.
